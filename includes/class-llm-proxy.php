<?php
namespace AI_Assistant;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Narrow proxy for Connector-backed cloud LLM requests.
 *
 * JavaScript still owns provider payloads, streaming parsing, and tool loops.
 * PHP only resolves the configured Connector endpoint/authentication and
 * forwards the JSON request body to a small allowlist of cloud providers.
 */
class LLM_Proxy {

    private const MAX_BODY_BYTES = 8 * 1024 * 1024;

    private const PROVIDER_GENERATION_PATHS = [
        'anthropic' => 'messages',
        'openai'    => 'chat/completions',
    ];

    public function __construct() {
        add_action('wp_ajax_ai_assistant_llm_proxy', [$this, 'handle_proxy']);
    }

    public function handle_proxy(): void {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!$this->user_can_prompt()) {
            $this->send_json_error('AI Assistant access not allowed', 403);
        }

        $this->enforce_rate_limit();

        if (!Connectors_Bridge::is_available()) {
            $this->send_json_error('WordPress Connectors are not available.', 400);
        }

        $provider = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        if (!isset(self::PROVIDER_GENERATION_PATHS[$provider])) {
            $this->send_json_error('Unsupported proxy provider.', 400);
        }

        $raw_body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';
        if (!is_string($raw_body) || $raw_body === '') {
            $this->send_json_error('Missing provider request body.', 400);
        }

        if (strlen($raw_body) > self::MAX_BODY_BYTES) {
            $this->send_json_error('Provider request body is too large.', 413);
        }

        $payload = json_decode($raw_body);
        if (!is_object($payload) || json_last_error() !== JSON_ERROR_NONE) {
            $this->send_json_error('Invalid provider request JSON.', 400);
        }

        $request = $this->get_provider_request($provider);
        if (is_wp_error($request)) {
            $this->send_json_error($request->get_error_message(), 400);
        }

        $this->proxy_request($request['url'], $request['headers'], $raw_body);
    }

    private function user_can_prompt(): bool {
        return current_user_can('ai_assistant_full')
            || current_user_can('ai_assistant_read_only')
            || current_user_can('ai_assistant_chat_only')
            || current_user_can('prompt_ai');
    }

    private function enforce_rate_limit(): void {
        $user_id = get_current_user_id();
        if (!$user_id) {
            $this->send_json_error('AI Assistant access not allowed', 403);
        }

        /**
         * Filters the per-user LLM proxy request limit per minute.
         *
         * Return 0 or a negative value to disable this guard.
         */
        $limit = (int) apply_filters('ai_assistant_llm_proxy_rate_limit_per_minute', 60, $user_id);
        if ($limit <= 0) {
            return;
        }

        $key = 'ai_assistant_llm_proxy_rl_' . $user_id;
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            $this->send_json_error('Rate limit exceeded. Please wait before sending another request.', 429);
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    }

    /**
     * Resolve the Connector endpoint and provider auth without exposing either to JS.
     */
    private function get_provider_request(string $provider) {
        try {
            $registry = AiClient::defaultRegistry();
            if (!$registry->isProviderConfigured($provider)) {
                return new \WP_Error('provider_not_configured', 'The selected provider is not configured.');
            }

            $class_name = $registry->getProviderClassName($provider);
            if (!is_string($class_name) || !class_exists($class_name) || !method_exists($class_name, 'url')) {
                return new \WP_Error('provider_endpoint_unavailable', 'The selected provider endpoint is unavailable.');
            }

            $auth = $registry->getProviderRequestAuthentication($provider);
            if (!$auth instanceof ApiKeyRequestAuthentication || !$auth->getApiKey()) {
                return new \WP_Error('provider_auth_unavailable', 'The selected provider does not have server-side API key authentication configured.');
            }

            $headers = [
                'Content-Type: application/json',
            ];

            if ($provider === 'anthropic') {
                $headers[] = 'x-api-key: ' . $auth->getApiKey();
                $headers[] = 'anthropic-version: 2023-06-01';
            } elseif ($provider === 'openai') {
                $headers[] = 'Authorization: Bearer ' . $auth->getApiKey();
            }

            return [
                'url'     => $class_name::url(self::PROVIDER_GENERATION_PATHS[$provider]),
                'headers' => $headers,
            ];
        } catch (\Throwable $e) {
            return new \WP_Error('provider_resolution_failed', 'Could not resolve the selected provider.');
        }
    }

    private function proxy_request(string $url, array $headers, string $body): void {
        if (function_exists('curl_init')) {
            $this->proxy_with_curl($url, $headers, $body);
            return;
        }

        $this->proxy_with_wp_http($url, $headers, $body);
    }

    /**
     * Stream upstream bytes directly to the browser so existing JS SSE parsing stays intact.
     */
    private function proxy_with_curl(string $url, array $headers, string $body): void {
        $status_code = 502;
        $content_type = 'application/json; charset=utf-8';
        $headers_sent_to_browser = false;

        $this->clear_output_buffers();
        ignore_user_abort(false);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1024);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header_line) use (&$status_code, &$content_type) {
            $line = trim($header_line);
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $line, $matches)) {
                $status_code = (int) $matches[1];
            } elseif (stripos($line, 'content-type:') === 0) {
                $content_type = trim(substr($line, strlen('content-type:')));
            }
            return strlen($header_line);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$status_code, &$content_type, &$headers_sent_to_browser) {
            if (!$headers_sent_to_browser) {
                $this->send_proxy_headers($status_code, $content_type);
                $headers_sent_to_browser = true;
            }

            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            flush();

            if (connection_aborted()) {
                return 0;
            }

            return strlen($chunk);
        });

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false && !$headers_sent_to_browser) {
            $this->send_json_error($error ?: 'Provider request failed.', 502);
        }

        if (!$headers_sent_to_browser) {
            $this->send_proxy_headers($status_code, $content_type);
        }

        exit;
    }

    /**
     * Buffered fallback for hosts without cURL. This preserves response shape,
     * but token streaming will arrive after the upstream request completes.
     */
    private function proxy_with_wp_http(string $url, array $headers, string $body): void {
        $header_map = [];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $header_map[trim($parts[0])] = trim($parts[1]);
            }
        }

        $response = wp_remote_post($url, [
            'timeout'     => 300,
            'redirection' => 0,
            'headers'     => $header_map,
            'body'        => $body,
        ]);

        if (is_wp_error($response)) {
            $this->send_json_error($response->get_error_message(), 502);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $this->send_proxy_headers($status ?: 502, $content_type ?: 'application/json; charset=utf-8');

        echo wp_remote_retrieve_body($response); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private function send_proxy_headers(int $status_code, string $content_type): void {
        if (headers_sent()) {
            return;
        }

        if ($status_code < 100 || $status_code > 599) {
            $status_code = 502;
        }

        $content_type = trim(preg_replace('/[\r\n].*/', '', $content_type));
        if ($content_type === '') {
            $content_type = 'application/json; charset=utf-8';
        }

        status_header($status_code);
        header('Content-Type: ' . $content_type);
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    private function send_json_error(string $message, int $status_code): void {
        if (!headers_sent()) {
            status_header($status_code);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo wp_json_encode([
            'error' => [
                'message' => $message,
            ],
        ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private function clear_output_buffers(): void {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        @ini_set('zlib.output_compression', '0'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged

        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                break;
            }
        }
    }
}
