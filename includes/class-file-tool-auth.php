<?php
namespace AI_Assistant;

if (!defined('ABSPATH') && !defined('AI_ASSISTANT_FILE_TOOLS_ENDPOINT')) {
    exit;
}

/**
 * Out-of-band file tool authentication.
 *
 * The direct file tool endpoint intentionally does not bootstrap WordPress, so it cannot
 * use cookies, nonces, roles, or current_user_can(). Instead, WordPress mints a
 * signed token while the chat UI is loaded and the direct endpoint validates it
 * later without bootstrapping WordPress.
 */
class File_Tool_Auth {

    private const TOKEN_TTL = 43200; // 12 hours.
    private const TOKEN_VERSION = 1;

    public static function create_token(string $permission, array $enabled_tools, ?int $user_id = null): string {
        $secret = self::get_or_create_secret();
        if ($secret === '') {
            return '';
        }

        $now = time();
        $payload = [
            'version'       => self::TOKEN_VERSION,
            'iat'           => $now,
            'exp'           => $now + self::TOKEN_TTL,
            'permission'    => $permission,
            'enabled_tools' => array_values(array_unique(array_map('strval', $enabled_tools))),
            'user_id'       => $user_id,
        ];

        $payload_json = json_encode($payload);
        if ($payload_json === false) {
            return '';
        }

        $payload_encoded = self::base64url_encode($payload_json);
        $signature = self::sign($payload_encoded, $secret);

        return $payload_encoded . '.' . $signature;
    }

    public static function validate_token(string $token): array {
        $token = trim($token);
        if ($token === '') {
            throw new \Exception('File tool token is required');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new \Exception('Invalid file tool token format');
        }

        [$payload_encoded, $signature] = $parts;
        $secret = self::read_secret();
        if ($secret === '') {
            throw new \Exception('File tool endpoint is not initialized');
        }

        $expected = self::sign($payload_encoded, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new \Exception('Invalid file tool token signature');
        }

        $payload_json = self::base64url_decode($payload_encoded);
        $payload = json_decode($payload_json, true);
        if (!is_array($payload)) {
            throw new \Exception('Invalid file tool token payload');
        }

        if (($payload['version'] ?? null) !== self::TOKEN_VERSION) {
            throw new \Exception('Unsupported file tool token version');
        }

        if (empty($payload['exp']) || (int) $payload['exp'] < time()) {
            throw new \Exception('File tool token has expired');
        }

        return $payload;
    }

    public static function can_execute_tool(string $tool_name, array $arguments, array $token_payload): bool {
        $permission = (string) ($token_payload['permission'] ?? 'none');
        if ($permission !== 'full' && $permission !== 'read_only') {
            return false;
        }

        $read_only_tools = [
            'read_file',
            'list_directory',
            'search_files',
            'search_content',
            'find',
        ];

        if ($permission === 'read_only' && !in_array($tool_name, $read_only_tools, true)) {
            return false;
        }

        return self::is_tool_enabled($tool_name, $arguments, (array) ($token_payload['enabled_tools'] ?? []));
    }

    private static function is_tool_enabled(string $tool_name, array $arguments, array $enabled_tools): bool {
        if (in_array($tool_name, $enabled_tools, true)) {
            return true;
        }

        if ($tool_name === 'find') {
            $text = $arguments['text'] ?? '';
            $glob = $arguments['glob'] ?? '';
            if ($text !== '') {
                return in_array('search_content', $enabled_tools, true);
            }
            if ($glob !== '') {
                return in_array('search_files', $enabled_tools, true);
            }
            return in_array('list_directory', $enabled_tools, true);
        }

        return false;
    }

    private static function get_or_create_secret(): string {
        $secret = self::read_secret();
        if ($secret !== '') {
            return $secret;
        }

        $path = self::secret_path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return '';
        }

        try {
            $secret = base64_encode(random_bytes(32));
        } catch (\Throwable $e) {
            return '';
        }

        $contents = "<?php\nreturn " . var_export($secret, true) . ";\n";
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            return '';
        }

        @chmod($path, 0600);

        return $secret;
    }

    private static function read_secret(): string {
        $path = self::secret_path();
        if (!is_file($path)) {
            return '';
        }

        $secret = include $path;
        return is_string($secret) && strlen($secret) >= 32 ? $secret : '';
    }

    private static function secret_path(): string {
        return rtrim(self::wp_content_dir(), '/\\') . '/.ai-assistant-file-tools-secret.php';
    }

    private static function wp_content_dir(): string {
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR;
        }
        if (defined('AI_ASSISTANT_FILE_TOOLS_WP_CONTENT_DIR')) {
            return AI_ASSISTANT_FILE_TOOLS_WP_CONTENT_DIR;
        }

        return dirname(__DIR__, 3);
    }

    private static function sign(string $payload_encoded, string $secret): string {
        return self::base64url_encode(hash_hmac('sha256', $payload_encoded, $secret, true));
    }

    private static function base64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64url_decode(string $value): string {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \Exception('Invalid base64url data');
        }

        return $decoded;
    }
}
