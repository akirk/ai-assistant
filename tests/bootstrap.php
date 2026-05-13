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
$GLOBALS['wp_test_capabilities'] = [];
$GLOBALS['wp_test_is_playground'] = false;
$GLOBALS['wp_test_abilities'] = [];
$GLOBALS['wp_test_filters'] = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['wp_test_options'][$option] ?? $default;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($cap) {
        // Default true so existing executor tests keep passing unchanged
        return $GLOBALS['wp_test_capabilities'][$cap] ?? true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return is_scalar($value) ? trim((string) $value) : '';
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
    function wp_send_json_error($data = null) {
        $GLOBALS['wp_test_json_response'] = [
            'success' => false,
            'data' => $data,
        ];
        throw new \RuntimeException('wp_send_json_error');
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        $GLOBALS['wp_test_json_response'] = [
            'success' => true,
            'data' => $data,
        ];
        throw new \RuntimeException('wp_send_json_success');
    }
}

if (!function_exists('user_can')) {
    function user_can($user_id, $cap) {
        return $GLOBALS['wp_test_capabilities'][$cap] ?? true;
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
if (!function_exists('get_site_url'))   { function get_site_url() { return 'http://localhost'; } }
if (!function_exists('site_url'))       { function site_url() { return 'http://localhost'; } }
if (!function_exists('home_url'))       { function home_url($path = '') { return 'http://localhost' . $path; } }
if (!function_exists('wp_parse_url'))   { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
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
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
            'ai-assistant/ai-assistant.php' => ['Name' => 'AI Assistant', 'Version' => '1.0'],
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
if (!function_exists('is_wp_error'))         { function is_wp_error($thing) { return $thing instanceof WP_Error; } }

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
require_once $plugin_dir . '/includes/class-file-tool-executor.php';
require_once $plugin_dir . '/includes/class-executor.php';
require_once $plugin_dir . '/includes/class-api-handler.php';
require_once $plugin_dir . '/includes/class-git-tracker.php';
require_once $plugin_dir . '/includes/class-git-tracker-manager.php';
require_once $plugin_dir . '/includes/class-conversations.php';
require_once $plugin_dir . '/includes/class-settings.php';
require_once $plugin_dir . '/includes/class-wp-app-abilities.php';
