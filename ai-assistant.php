<?php
/**
 * Plugin Name: AI Assistant
 * Plugin URI: https://github.com/akirk/ai-assistant
 * Description: AI-powered chat interface for WordPress. Bring your own key or use a local LLM.
 * Version: 1.1.0
 * Author: Alex Kirk
 * Author URI: https://alex.kirk.at
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-assistant
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safety check: Only run in WordPress Playground environment
 */
function ai_assistant_is_playground(): bool {
    $is_wasm = isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'PHP.wasm') !== false;
    $is_playground_path = strpos(ABSPATH, '/wordpress') !== false;
    $has_playground_function = function_exists('post_message_to_js');

    return $is_wasm && $is_playground_path && $has_playground_function;
}

define('AI_ASSISTANT_VERSION', '1.1.0');
define('AI_ASSISTANT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_ASSISTANT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AI_ASSISTANT_PLUGIN_BASENAME', plugin_basename(__FILE__));

$ai_assistant_vendor_autoload = AI_ASSISTANT_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($ai_assistant_vendor_autoload)) {
    require_once $ai_assistant_vendor_autoload;
}

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'AI_Assistant\\';
    $base_dir = AI_ASSISTANT_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Optional high-risk development tools. Comment this line to disable file
// mutation, plugin installation, and raw PHP execution tools.
require_once AI_ASSISTANT_PLUGIN_DIR . 'dev-tools.php';

/**
 * Main plugin class
 */
final class AI_Assistant {

    private static $instance = null;

    private $settings;
    private $chat_ui;
    private $api_handler;
    private $tools;
    private $executor;
    private $conversations;
    private $git_tracker_manager;
    private $plugin_downloads;
    private $changes_admin;
    private $plugin_recovery_admin;
    private $plugin_checkout_badge;
    private $connectors_bridge;
    private $llm_proxy;
    private $wp_app_abilities;
    private $conversations_app;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init']);
        add_action('init', [$this, 'register_my_apps_integration'], 0);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        // Initialize Connectors bridge (WordPress 7.0+)
        if (AI_Assistant\Connectors_Bridge::is_available()) {
            $this->connectors_bridge = new AI_Assistant\Connectors_Bridge();
        }

        // Initialize components
        $this->settings = new AI_Assistant\Settings();
        $this->tools = new AI_Assistant\Tools();
        $this->git_tracker_manager = new AI_Assistant\Git_Tracker_Manager();
        $this->executor = new AI_Assistant\Executor($this->tools, $this->git_tracker_manager);
        $this->conversations = new AI_Assistant\Conversations();
        $this->chat_ui = new AI_Assistant\Chat_UI();
        $this->llm_proxy = new AI_Assistant\LLM_Proxy();
        $this->api_handler = new AI_Assistant\API_Handler($this->tools, $this->executor);
        $this->plugin_downloads = new AI_Assistant\Plugin_Downloads($this->git_tracker_manager);
        $this->changes_admin = new AI_Assistant\Changes_Admin($this->git_tracker_manager);
        $this->plugin_recovery_admin = new AI_Assistant\Plugin_Recovery_Admin();
        $this->plugin_checkout_badge = new AI_Assistant\Plugin_Checkout_Badge($this->git_tracker_manager);
        $this->conversations_app = new AI_Assistant\Conversations_App();
        $this->wp_app_abilities = new AI_Assistant\Wp_App_Abilities($this->git_tracker_manager);
    }

    /**
     * Register integrations that expose translated visible labels.
     */
    public function register_my_apps_integration() {
        add_filter('my_apps_plugins', [$this, 'register_my_apps_icon']);
    }

    /**
     * Register AI Assistant with the My Apps launcher.
     */
    public function register_my_apps_icon($apps) {
        if (!is_array($apps)) {
            return $apps;
        }

        if (
            !current_user_can('edit_posts')
            || (
                !current_user_can('ai_assistant_full')
                && !current_user_can('ai_assistant_read_only')
                && !current_user_can('ai_assistant_chat_only')
            )
        ) {
            return $apps;
        }

        $apps['ai-assistant'] = [
            'name' => __('AI Assistant', 'ai-assistant'),
            'dashicon' => 'dashicons-format-chat',
            'url' => AI_Assistant\Conversations_App::get_url(),
        ];

        return $apps;
    }

    /**
     * Get conversations instance
     */
    public function conversations() {
        return $this->conversations;
    }

    /**
     * Get conversations app instance
     */
    public function conversations_app() {
        return $this->conversations_app;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        $this->register_capabilities();

        // Clean up old options that are no longer used
        delete_option('ai_assistant_encryption_key');
        delete_option('ai_assistant_role_permissions');
        delete_option('ai_assistant_modified_plugins');
        delete_option('ai_assistant_provider');
        delete_option('ai_assistant_model');
        delete_option('ai_assistant_anthropic_api_key');
        delete_option('ai_assistant_openai_api_key');
        delete_option('ai_assistant_local_endpoint');
        delete_option('ai_assistant_local_model');
        delete_option('ai_assistant_summarization_model');

        update_option('wp_app_flush_rewrite_rules', true);
    }

    /**
     * Register AI Assistant capabilities to WordPress roles
     */
    private function register_capabilities() {
        $role_caps = [
            'administrator' => 'ai_assistant_full',
            'editor' => 'ai_assistant_read_only',
            'author' => 'ai_assistant_chat_only',
            'contributor' => 'ai_assistant_chat_only',
        ];

        foreach ($role_caps as $role_name => $cap) {
            $role = get_role($role_name);
            if ($role) {
                // Remove any existing AI assistant caps first
                $role->remove_cap('ai_assistant_full');
                $role->remove_cap('ai_assistant_read_only');
                $role->remove_cap('ai_assistant_chat_only');
                // Add the appropriate cap
                $role->add_cap($cap);
            }
        }

        // Ensure subscriber has no AI caps
        $subscriber = get_role('subscriber');
        if ($subscriber) {
            $subscriber->remove_cap('ai_assistant_full');
            $subscriber->remove_cap('ai_assistant_read_only');
            $subscriber->remove_cap('ai_assistant_chat_only');
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Cleanup if needed
    }

    /**
     * Get Connectors bridge instance (null on WP < 7.0)
     */
    public function connectors_bridge() {
        return $this->connectors_bridge;
    }

    /**
     * Get settings instance
     */
    public function settings() {
        return $this->settings;
    }

    /**
     * Get tools instance
     */
    public function tools() {
        return $this->tools;
    }

    /**
     * Get executor instance
     */
    public function executor() {
        return $this->executor;
    }
}

/**
 * Returns the main instance of AI_Assistant
 */
function ai_assistant() {
    return AI_Assistant::instance();
}

// Initialize
ai_assistant();
