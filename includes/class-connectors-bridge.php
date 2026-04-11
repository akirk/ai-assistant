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
 * Reads provider configuration (API keys, models, endpoints) from the core
 * php-ai-client registry and exposes it for wp_localize_script so the JS
 * client can make direct streaming calls to provider APIs.
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
     *   - available: keyed by provider ID, each with name, type, endpoint, apiKey, models
     *   - hasLocal: whether a server-type provider (e.g. Ollama) is configured
     *
     * Only users with prompt_ai capability receive API keys.
     */
    public function get_providers_config(): array {
        if (!self::is_available()) {
            return ['source' => 'legacy'];
        }

        $registry = AiClient::defaultRegistry();
        $provider_ids = $registry->getRegisteredProviderIds();
        $can_prompt = current_user_can('prompt_ai') || current_user_can('ai_assistant_full');

        $available = [];
        $has_local = false;
        $debug = [
            'registered_ids'  => $provider_ids,
            'can_prompt'      => $can_prompt,
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

            // Check auth availability regardless of isConfigured
            try {
                $auth = $registry->getProviderRequestAuthentication($id);
                $provider_debug['authClass'] = $auth ? get_class($auth) : null;
                $provider_debug['hasApiKey'] = ($auth instanceof ApiKeyRequestAuthentication) ? (bool) $auth->getApiKey() : false;
            } catch (\Throwable $e) {
                $provider_debug['authError'] = $e->getMessage();
            }

            if (!$is_configured) {
                $provider_debug['skipped'] = 'isProviderConfigured() returned false';
                $debug['providers'][] = $provider_debug;
                continue;
            }

            $type = (string) $meta->getType();

            // Flag server-type providers (e.g. Ollama) — JS handles these via browser-direct local detection
            if ($type === 'server') {
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

            // Get endpoint URL for browser-supported providers
            $endpoint = '';
            if (in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true) && isset(self::PROVIDER_GENERATION_PATHS[$id])) {
                try {
                    $endpoint = $class_name::url(self::PROVIDER_GENERATION_PATHS[$id]);
                } catch (\Throwable $e) {
                    // url() may not be available on all provider classes
                }
            }

            // Get API key only for users with appropriate capability and browser-supported providers
            $api_key = '';
            if ($can_prompt && in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true)) {
                try {
                    $auth = $registry->getProviderRequestAuthentication($id);
                    if ($auth instanceof ApiKeyRequestAuthentication) {
                        $api_key = $auth->getApiKey();
                    }
                } catch (\Throwable $e) {
                    // Auth may not be set
                }
            }

            $provider_debug['endpoint'] = $endpoint;
            $provider_debug['modelCount'] = count($models);
            $provider_debug['browserSupported'] = in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true);
            $provider_debug['included'] = true;
            $debug['providers'][] = $provider_debug;

            $available[$id] = [
                'name'     => $meta->getName(),
                'type'     => $type,
                'endpoint' => $endpoint,
                'apiKey'   => $api_key,
                'models'   => $models,
                'browserSupported' => in_array($id, self::SUPPORTED_BROWSER_PROVIDERS, true),
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
