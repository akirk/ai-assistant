<?php
namespace AI_Assistant;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridge between WordPress 7.0 Connectors API and our browser-direct JS client.
 *
 * Reads provider configuration (models, provider types, endpoints) from the
 * core php-ai-client registry and exposes safe metadata for wp_localize_script.
 * Cloud API keys stay server-side and are used by LLM_Proxy.
 */
class Connectors_Bridge {

    /**
     * Provider ID → generation endpoint path.
     * These paths are appended to the provider's base URL via $class::url($path).
     */
    private const PROVIDER_GENERATION_PATHS = [
        'anthropic' => 'messages',
        'openai'    => 'chat/completions',
        'google'    => 'models',
    ];

    /**
     * Provider IDs we know how to call from the browser.
     * Providers not in this list are surfaced as metadata only (no API key passed).
     */
    private const SUPPORTED_BROWSER_PROVIDERS = [
        'anthropic',
        'openai',
    ];

    /**
     * Check whether the WordPress 7.0 Connectors API is available.
     */
    public static function is_available(): bool {
        return class_exists(AiClient::class);
    }

    /**
     * Get all provider configuration for the JS client.
     *
     * Returns an array suitable for wp_localize_script with:
     *   - source: 'connectors'
     *   - available: keyed by provider ID, each with name, type, endpoint, models
     *   - hasLocal: whether a server-type provider (e.g. Ollama) is configured
     */
    public function get_providers_config(): array {
        if (!self::is_available()) {
            return ['source' => 'legacy'];
        }

        $registry = AiClient::defaultRegistry();
        $provider_ids = $registry->getRegisteredProviderIds();
        $can_prompt = current_user_can('prompt_ai')
            || current_user_can('ai_assistant_full')
            || current_user_can('ai_assistant_read_only')
            || current_user_can('ai_assistant_chat_only');

        $available = [];
        $has_local = false;
        $current_user = wp_get_current_user();
        $debug = [
            'registered_ids'  => $provider_ids,
            'can_prompt'      => $can_prompt,
            'can_prompt_ai'   => current_user_can('prompt_ai'),
            'can_full'        => current_user_can('ai_assistant_full'),
            'can_read_only'   => current_user_can('ai_assistant_read_only'),
            'can_chat_only'   => current_user_can('ai_assistant_chat_only'),
            'user_id'         => $current_user->ID,
            'user_roles'      => $current_user->roles,
            'providers'       => [],
        ];

        foreach ($provider_ids as $id) {
            $provider_debug = ['id' => $id];

            $is_configured = $registry->isProviderConfigured($id);
            $provider_debug['isConfigured'] = $is_configured;

            try {
                $class_name = $registry->getProviderClassName($id);
                $provider_debug['className'] = $class_name;
            } catch (\Throwable $e) {
                $provider_debug['classNameError'] = $e->getMessage();
                $debug['providers'][] = $provider_debug;
                continue;
            }

            try {
                $meta = $class_name::metadata();
                $provider_debug['name'] = $meta->getName();
                $provider_debug['type'] = (string) $meta->getType();
                $provider_debug['authMethod'] = $meta->getAuthenticationMethod() ? (string) $meta->getAuthenticationMethod() : null;
            } catch (\Throwable $e) {
                $provider_debug['metadataError'] = $e->getMessage();
                $debug['providers'][] = $provider_debug;
                continue;
            }

            // Check whether this provider actually has a key value set.
            // A provider that uses API key auth but has no key configured is treated as
            // server-type (local/key-free), since WordPress may classify local Ollama as 'cloud'.
            $has_api_key_value = false;
            try {
                $auth = $registry->getProviderRequestAuthentication($id);
                $provider_debug['authClass'] = $auth ? get_class($auth) : null;
                $has_api_key_value = ($auth instanceof ApiKeyRequestAuthentication) && (bool) $auth->getApiKey();
                $provider_debug['hasApiKey'] = $has_api_key_value;
            } catch (\Throwable $e) {
                $provider_debug['authError'] = $e->getMessage();
            }

            $type = (string) $meta->getType();

            // Treat key-free non-browser providers as server-type. This catches local
            // providers like Ollama if WordPress classifies them as cloud, without
            // misrouting known cloud providers that simply have no API key configured.
            $effective_type = (
                $type === 'server' ||
                (!$has_api_key_value && !in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true))
            ) ? 'server' : $type;
            $provider_debug['effectiveType'] = $effective_type;

            // Local/server-type providers don't need an API key — skip the isConfigured check
            // for them since WordPress returns false when no key is set even for key-free providers.
            if (!$is_configured && $effective_type !== 'server') {
                $provider_debug['skipped'] = 'isProviderConfigured() returned false';
                $debug['providers'][] = $provider_debug;
                continue;
            }

            if ($effective_type === 'server') {
                $has_local = true;
            }

            // Build model list
            $models = [];
            try {
                $directory = $class_name::modelMetadataDirectory();
                foreach ($directory->listModelMetadata() as $model_meta) {
                    $capabilities = array_map('strval', $model_meta->getSupportedCapabilities());
                    // Only include models that support text generation
                    if (!in_array('text_generation', $capabilities, true)) {
                        continue;
                    }
                    $models[] = [
                        'id'           => $model_meta->getId(),
                        'name'         => $model_meta->getName() ?: $model_meta->getId(),
                        'capabilities' => $capabilities,
                    ];
                }
            } catch (\Throwable $e) {
                // Model listing may fail if provider is misconfigured
            }

            // Get endpoint URL
            $endpoint = '';
            if (in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true) && isset(self::PROVIDER_GENERATION_PATHS[$id])) {
                try {
                    $endpoint = $class_name::url(self::PROVIDER_GENERATION_PATHS[$id]);
                } catch (\Throwable $e) {
                    // url() may not be available on all provider classes
                }
            }
            // For server-type providers, try to get the base URL for browser-direct use
            if ($effective_type === 'server' && !$endpoint) {
                try {
                    $endpoint = $class_name::url('');
                } catch (\Throwable $e) {
                    // Fall back — JS will use auto-detection
                }
                // Clean trailing slash from base URL
                $endpoint = rtrim($endpoint, '/');
            }

            $server_side_auth = $can_prompt && $has_api_key_value && in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true);

            $provider_debug['endpoint'] = $endpoint;
            $provider_debug['serverSideAuth'] = $server_side_auth;
            $provider_debug['modelCount'] = count($models);
            $browser_supported = in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true) || $effective_type === 'server';

            $provider_debug['browserSupported'] = $browser_supported;
            $provider_debug['included'] = true;
            $debug['providers'][] = $provider_debug;

            $available[$id] = [
                'name'     => $meta->getName(),
                'type'     => $effective_type,
                'endpoint' => $endpoint,
                'apiKey'   => '',
                'serverSideAuth' => $server_side_auth,
                'proxySupported' => $server_side_auth,
                'models'   => $models,
                'browserSupported' => $browser_supported,
            ];
        }

        return [
            'source'    => 'connectors',
            'available' => $available,
            'hasLocal'  => $has_local,
            'debug'     => $debug,
        ];
    }
}
