<?php
/**
 * PHPUnit bootstrap file
 *
 * Sets up WordPress stubs and loads the plugin classes for testing.
 * No Composer autoloader required - classes are loaded manually.
 */

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-test-' . getmypid());
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

// Create test directory structure
if (!is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0755, true);
}
if (!is_dir(WP_PLUGIN_DIR)) {
    mkdir(WP_PLUGIN_DIR, 0755, true);
}

// WordPress function stubs for testing
$GLOBALS['wp_test_options'] = [];
$GLOBALS['wp_test_user_options'] = [];
$GLOBALS['wp_test_capabilities'] = [];
$GLOBALS['wp_test_is_playground'] = false;
$GLOBALS['wp_test_abilities'] = [];
$GLOBALS['wp_test_filters'] = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['wp_test_options'][$option] ?? $default;
    }
}

if (!function_exists('get_user_option')) {
    function get_user_option($option, $user = 0, $deprecated = '') {
        return $GLOBALS['wp_test_user_options'][$option] ?? false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($cap) {
        // Default true so existing executor tests keep passing unchanged
        return ai_assistant_test_user_can($cap);
    }
}

if (!function_exists('ai_assistant_test_user_can')) {
    function ai_assistant_test_user_can($cap) {
        if (array_key_exists($cap, $GLOBALS['wp_test_capabilities'])) {
            return $GLOBALS['wp_test_capabilities'][$cap];
        }

        $access_caps = [
            'ai_assistant_full',
            'ai_assistant_read_only',
            'ai_assistant_chat_only',
        ];

        if (in_array($cap, $access_caps, true)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_scalar($value) ? stripslashes((string) $value) : $value;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $filename);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($content, $allowed_html, $allowed_protocols = []) {
        $allowed_protocols = $allowed_protocols ?: ['http', 'https', 'mailto'];

        return preg_replace_callback('/<\/?([a-z0-9]+)([^>]*)>/i', function($matches) use ($allowed_html, $allowed_protocols) {
            $tag = strtolower($matches[1]);
            if (!isset($allowed_html[$tag])) {
                return '';
            }

            if (strpos($matches[0], '</') === 0) {
                return '</' . $tag . '>';
            }

            $attrs = '';
            preg_match_all('/\s+([a-z0-9_-]+)(?:=(["\'])(.*?)\2)?/i', $matches[2], $attr_matches, PREG_SET_ORDER);
            foreach ($attr_matches as $attr_match) {
                $name = strtolower($attr_match[1]);
                $allowed_attrs = $allowed_html[$tag] ?: [];
                if (!is_array($allowed_attrs) || !array_key_exists($name, $allowed_attrs)) {
                    continue;
                }

                $value = $attr_match[3] ?? '';
                if ($name === 'href') {
                    $decoded = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $decoded, $protocol_match) && !in_array(strtolower($protocol_match[1]), $allowed_protocols, true)) {
                        continue;
                    }
                }

                $attrs .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }

            return '<' . $tag . $attrs . '>';
        }, (string) $content);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer() {
        return true;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        $GLOBALS['wp_test_json_response'] = [
            'success' => false,
            'data' => $data,
            'status_code' => $status_code,
        ];
        throw new \RuntimeException('wp_send_json_error');
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        $GLOBALS['wp_test_json_response'] = [
            'success' => true,
            'data' => $data,
            'status_code' => $status_code,
        ];
        throw new \RuntimeException('wp_send_json_success');
    }
}

if (!function_exists('user_can')) {
    function user_can($user_id, $cap) {
        return ai_assistant_test_user_can($cap);
    }
}

if (!function_exists('ai_assistant_is_playground')) {
    function ai_assistant_is_playground(): bool {
        return $GLOBALS['wp_test_is_playground'] ?? false;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        $GLOBALS['wp_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return trailingslashit(dirname($file));
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('get_theme_root')) {
    function get_theme_root() {
        return WP_CONTENT_DIR . '/themes';
    }
}

// Create themes directory
if (!is_dir(WP_CONTENT_DIR . '/themes')) {
    mkdir(WP_CONTENT_DIR . '/themes', 0755, true);
}

// WordPress function stubs needed for environment_info tool
if (!function_exists('get_bloginfo'))   { function get_bloginfo($show = '') { return '6.7'; } }
if (!function_exists('get_site_url'))   { function get_site_url() { return $GLOBALS['wp_test_site_url'] ?? 'http://localhost'; } }
if (!function_exists('site_url'))       { function site_url() { return 'http://localhost'; } }
if (!function_exists('home_url'))       { function home_url($path = '') { return rtrim($GLOBALS['wp_test_site_url'] ?? 'http://localhost', '/') . $path; } }
if (!function_exists('wp_parse_url'))   { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('is_admin'))       { function is_admin() { return true; } }
if (!function_exists('get_template'))   { function get_template() { return 'twentytwentyfive'; } }
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() {
        return (object) ['display_name' => 'Test User'];
    }
}
if (!function_exists('is_multisite'))   { function is_multisite() { return false; } }
if (!function_exists('wp_get_theme'))   {
    function wp_get_theme() {
        return new class {
            public function get($key) {
                $map = ['Name' => 'Twenty Twenty-Five', 'Version' => '1.0'];
                return $map[$key] ?? '';
            }
            public function get_template() { return 'twentytwentyfive'; }
            public function parent() { return false; }
        };
    }
}
if (!function_exists('get_plugins'))    {
    function get_plugins() {
        return [
            'hello.php' => [
                'Name' => 'Hello Dolly',
                'Description' => 'This is not just a plugin, it symbolizes the hope and enthusiasm of an entire generation.',
                'Version' => '1.7.2',
            ],
            'ai-assistant/ai-assistant.php' => [
                'Name' => 'AI Assistant',
                'Description' => 'AI-powered chat interface for WordPress.',
                'Version' => '1.0',
            ],
        ];
    }
}

if (!function_exists('wp_get_ability')) {
    function wp_get_ability($ability_id) {
        return $GLOBALS['wp_test_abilities'][$ability_id] ?? null;
    }
}

if (!function_exists('wp_get_abilities')) {
    function wp_get_abilities() {
        return $GLOBALS['wp_test_abilities'] ?? [];
    }
}

// WordPress hook/admin stubs needed to load Settings class
if (!function_exists('add_action'))          { function add_action()          {} }
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
        $supported = [
            'ai_assistant_conversation_export_formats',
            'ai_assistant_conversation_export_shrink_tool_calls',
            'ai_assistant_tool_definitions',
            'ai_assistant_read_only_tool_definitions',
            'ai_assistant_read_only_tool_names',
            'ai_assistant_execute_tool',
            'ai_assistant_tool_meta',
            'ai_assistant_tool_group_order',
            'ai_assistant_tool_order',
            'ai_assistant_default_enabled_tools',
            'ai_assistant_client_tool_definitions',
            'ai_assistant_file_endpoint_tools',
            'ai_assistant_system_prompt',
        ];
        if (!in_array($tag, $supported, true)) {
            return true;
        }
        $GLOBALS['wp_test_filters'][$tag][$priority][] = [
            'callback' => $callback,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        if (empty($GLOBALS['wp_test_filters'][$tag])) {
            return $value;
        }
        ksort($GLOBALS['wp_test_filters'][$tag]);
        foreach ($GLOBALS['wp_test_filters'][$tag] as $callbacks) {
            foreach ($callbacks as $entry) {
                $call_args = array_slice(array_merge([$value], $args), 0, $entry['accepted_args']);
                $value = call_user_func_array($entry['callback'], $call_args);
            }
        }
        return $value;
    }
}
if (!function_exists('add_management_page')) { function add_management_page() {} }
if (!function_exists('add_options_page'))    { function add_options_page()    {} }
if (!function_exists('register_setting'))    { function register_setting()    {} }
if (!function_exists('add_settings_section')){ function add_settings_section(){} }
if (!function_exists('add_settings_field'))  { function add_settings_field()  {} }
if (!function_exists('__'))                  { function __($t, $d = '') { return $t; } }
if (!function_exists('_n'))                  { function _n($single, $plural, $number, $domain = '') { return (int) $number === 1 ? $single : $plural; } }
if (!function_exists('esc_html__'))          { function esc_html__($text, $domain = '') { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_html'))            { function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_html_e'))          { function esc_html_e($text, $domain = '') { echo esc_html($text); } }
if (!function_exists('esc_attr'))            { function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_attr_e'))          { function esc_attr_e($text, $domain = '') { echo esc_attr($text); } }
if (!function_exists('esc_url'))             { function esc_url($url) { return (string) $url; } }
if (!function_exists('admin_url'))           { function admin_url($path = '') { return 'http://example.test/wp-admin/' . ltrim($path, '/'); } }
if (!function_exists('wp_nonce_url'))        { function wp_nonce_url($url, $action = -1) { return $url . (strpos($url, '?') === false ? '?' : '&') . '_wpnonce=test'; } }
if (!function_exists('is_wp_error'))         { function is_wp_error($thing) { return $thing instanceof WP_Error; } }
if (!function_exists('date_i18n'))           { function date_i18n($format, $timestamp = false) { return date($format, $timestamp ?: time()); } }

if (!function_exists('activate_plugin')) {
    function activate_plugin($plugin, $redirect = '', $network_wide = false, $silent = false) {
        if (isset($GLOBALS['wp_test_activate_plugin_result'])) {
            return $GLOBALS['wp_test_activate_plugin_result'];
        }

        return null;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;

        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }
    }
}

// Manual class loading (no Composer autoloader)
$plugin_dir = dirname(__DIR__);
$vendor_autoload = $plugin_dir . '/vendor/autoload.php';
if (file_exists($vendor_autoload)) {
    require_once $vendor_autoload;
}

require_once $plugin_dir . '/includes/class-tools.php';
require_once $plugin_dir . '/includes/class-ability-annotations.php';
require_once $plugin_dir . '/includes/class-skill-registry.php';
require_once $plugin_dir . '/includes/class-file-tool-auth.php';
require_once $plugin_dir . '/includes/class-emergency-plugin-guard.php';
require_once $plugin_dir . '/includes/class-file-tool-executor.php';
require_once $plugin_dir . '/includes/class-plugin-recovery-admin.php';
require_once $plugin_dir . '/includes/class-executor.php';
require_once $plugin_dir . '/dev-tools.php';
require_once $plugin_dir . '/includes/class-api-handler.php';
require_once $plugin_dir . '/includes/class-git-tracker.php';
require_once $plugin_dir . '/includes/class-git-tracker-manager.php';
require_once $plugin_dir . '/includes/class-admin-colors.php';
require_once $plugin_dir . '/includes/class-conversations.php';
require_once $plugin_dir . '/includes/class-conversations-app.php';
require_once $plugin_dir . '/includes/class-settings.php';
require_once $plugin_dir . '/includes/class-wp-app-abilities.php';
