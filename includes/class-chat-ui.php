<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chat UI - Integrates with WordPress screen-meta (like Help/Screen Options tabs)
 */
class Chat_UI {

    public function __construct() {
        // Admin hooks
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ai_assistant_bootstrap', [$this, 'ajax_bootstrap']);

        // Frontend hooks (only if enabled in settings).
        // Priority 1000 runs after frameworks like wp-app that dequeue
        // non-whitelisted assets at priority 999.
        if ($this->is_frontend_enabled()) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 1000);
        }
    }

    /**
     * Check if frontend display is enabled
     */
    private function is_frontend_enabled() {
        return get_option('ai_assistant_show_on_frontend', '1') === '1';
    }

    /**
     * Enqueue CSS and JavaScript
     */
    public function enqueue_assets() {
        if (!$this->user_has_access()) {
            return;
        }

        wp_enqueue_style(
            'ai-assistant-chat',
            AI_ASSISTANT_PLUGIN_URL . 'assets/css/chat.css',
            ['wp-codemirror'],
            AI_ASSISTANT_VERSION
        );
        wp_add_inline_style(
            'ai-assistant-chat',
            Admin_Colors::get_current_scheme_css(':root, body, #ai-assistant-wrap, .ai-assistant-standalone-wrap, .ai-assistant-page, .ai-assistant-chat-container')
        );

        wp_enqueue_script('wp-codemirror');

        wp_enqueue_script(
            'ai-assistant-chat-core',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-core.js',
            ['jquery'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-settings',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-settings.js',
            ['ai-assistant-chat-core'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-tools',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-tools.js',
            ['ai-assistant-chat-core'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-files',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-files.js',
            ['ai-assistant-chat-core'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-providers',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-providers.js',
            ['ai-assistant-chat-core', 'ai-assistant-chat-settings', 'ai-assistant-chat-files'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-execution',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-execution.js',
            ['ai-assistant-chat-core'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-ui',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-ui.js',
            ['ai-assistant-chat-core', 'ai-assistant-chat-settings', 'ai-assistant-chat-files'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-conversations',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-conversations.js',
            ['ai-assistant-chat-core', 'ai-assistant-chat-ui', 'ai-assistant-chat-providers'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_enqueue_script(
            'ai-assistant-chat-bootstrap',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-bootstrap.js',
            [
                'ai-assistant-chat-conversations',
                'ai-assistant-chat-tools',
                'ai-assistant-chat-execution',
                'ai-assistant-chat-providers',
            ],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_localize_script('ai-assistant-chat-core', 'aiAssistantConfig', $this->get_chat_config());
        wp_localize_script('ai-assistant-chat-core', 'aiAssistantProviders', $this->get_providers_config());
        wp_localize_script('ai-assistant-chat-core', 'aiAssistantBootstrap', $this->get_bootstrap_config());
    }

    /**
     * AJAX: Return everything needed to display the assistant without a reload.
     */
    public function ajax_bootstrap() {
        check_ajax_referer('ai_assistant_bootstrap', '_wpnonce');

        if (!$this->user_has_access()) {
            wp_send_json_error([
                'errorMessage' => __('Sorry, you are not allowed to use AI Assistant.', 'ai-assistant'),
            ], 403);
        }

        $assets = $this->get_bootstrap_assets();

        wp_send_json_success([
            'globals' => [
                'aiAssistantConfig' => $this->get_chat_config(),
                'aiAssistantProviders' => $this->get_providers_config(),
                'aiAssistantBootstrap' => $this->get_bootstrap_config(true),
            ],
            'styles' => $assets['styles'],
            'inlineStyles' => $assets['inlineStyles'],
            'scripts' => $assets['scripts'],
        ]);
    }

    /**
     * Get the localized chat runtime config.
     */
    private function get_chat_config() {
        $settings = ai_assistant()->settings();
        $current_user = wp_get_current_user();

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_assistant_chat'),
            'fileToolsUrl' => AI_ASSISTANT_PLUGIN_URL . 'file-tools.php',
            'fileToolsToken' => File_Tool_Auth::create_token(
                $settings->get_user_permission_level(),
                $settings->get_user_enabled_tools(),
                get_current_user_id()
            ),
            'userPermission' => $settings->get_user_permission_level(),
            'enabledTools' => $settings->get_user_enabled_tools(),
            'autoApprovedAbilities' => $settings->get_auto_approved_abilities(),
            'readonlyAbilities' => Ability_Annotations::get_readonly_ability_ids(),
            'autoApprovedRestApis' => $settings->get_auto_approved_rest_apis(),
            'settingsUrl' => admin_url('options-general.php?page=ai-assistant-settings'),
            'conversationExportUrl' => admin_url('admin-post.php?action=ai_assistant_export_conversation'),
            'conversationExportFormats' => ai_assistant()->conversations()->get_export_formats_for_config(),
            'adminUrl' => admin_url(),
            'homeUrl' => home_url(),
            'restApiUrl' => rest_url(),
            'restApiNonce' => wp_create_nonce('wp_rest'),
            'userDisplayName' => $current_user->display_name,
            'maxClientFileBytes' => (int) apply_filters('ai_assistant_client_file_context_bytes', 128 * 1024),
            'compactClientFileBytes' => (int) apply_filters('ai_assistant_client_file_compact_bytes', 32 * 1024),
            'maxMediaUploadBytes' => (int) wp_max_upload_size(),
            'toolRoundLimits' => [
                'default' => (int) apply_filters('ai_assistant_tool_round_limit_default', 25),
                'coding' => (int) apply_filters('ai_assistant_tool_round_limit_coding', 50),
                'consecutiveFailures' => (int) apply_filters('ai_assistant_tool_round_limit_consecutive_failures', 3),
            ],
            'systemPrompt' => $settings->get_system_prompt(),
            'abilityDomains' => apply_filters('ai_assistant_ability_domains', []),
            'strings' => [
                'placeholder' => __('Ask me anything about your WordPress site...', 'ai-assistant'),
                'send' => __('Send', 'ai-assistant'),
                'thinking' => __('Thinking...', 'ai-assistant'),
                'error' => __('An error occurred. Please try again.', 'ai-assistant'),
                'confirmTitle' => __('Confirm Action', 'ai-assistant'),
                'confirm' => __('Confirm', 'ai-assistant'),
                'cancel' => __('Cancel', 'ai-assistant'),
                'bulkConfirmTitle' => __('Approve Actions', 'ai-assistant'),
                'approveAll' => __('Approve All', 'ai-assistant'),
                'skipAll' => __('Skip All', 'ai-assistant'),
                'newChat' => __('New Chat', 'ai-assistant'),
                'close' => __('Close', 'ai-assistant'),
            ],
        ];
    }

    /**
     * Get provider config for the chat runtime.
     */
    private function get_providers_config() {
        $bridge = ai_assistant()->connectors_bridge();
        if (!$bridge) {
            return ['source' => 'legacy'];
        }

        $providers_config = $bridge->get_providers_config();
        $providers_config['connectorsUrl'] = admin_url('options-connectors.php');
        return $providers_config;
    }

    /**
     * Get the bootstrap config used by both normal loads and AJAX installs.
     */
    private function get_bootstrap_config($render_latch = null) {
        if ($render_latch === null) {
            $render_latch = $this->should_render_latch();
        }

        return [
            'deferInit' => true,
            'renderLatch' => (bool) $render_latch,
            'urls' => [
                'history' => admin_url('tools.php?page=ai-conversations'),
                'settings' => admin_url('options-general.php?page=ai-assistant-settings'),
            ],
            'strings' => [
                'buttonText' => __('AI Assistant', 'ai-assistant'),
                'title' => __('AI Assistant', 'ai-assistant'),
                'newChat' => __('New Chat', 'ai-assistant'),
                'history' => __('Conversations', 'ai-assistant'),
                'settings' => __('Settings', 'ai-assistant'),
                'send' => __('Send', 'ai-assistant'),
                'placeholder' => __('Ask me anything about your WordPress site...', 'ai-assistant'),
                'ariaLabel' => __('AI Assistant Tab', 'ai-assistant'),
                'tokenCountTitle' => __('Estimated token usage', 'ai-assistant'),
                'dropFiles' => __('Drop files to attach', 'ai-assistant'),
                'scrollBottom' => __('Scroll to bottom', 'ai-assistant'),
                'attachFiles' => __('Attach files', 'ai-assistant'),
                'stopGeneration' => __('Stop generation', 'ai-assistant'),
                'expand' => __('Expand', 'ai-assistant'),
                'yoloMode' => __('YOLO Mode', 'ai-assistant'),
                'yoloTitle' => __('Skip confirmation prompts for destructive actions', 'ai-assistant'),
            ],
        ];
    }

    /**
     * Get the asset manifest used for after-install bootstrapping.
     */
    private function get_bootstrap_assets() {
        return [
            'styles' => [
                [
                    'id' => 'ai-assistant-chat-css',
                    'href' => AI_ASSISTANT_PLUGIN_URL . 'assets/css/chat.css',
                    'version' => AI_ASSISTANT_VERSION,
                ],
            ],
            'inlineStyles' => [
                [
                    'id' => 'ai-assistant-chat-colors',
                    'css' => Admin_Colors::get_current_scheme_css(':root, body, #ai-assistant-wrap, .ai-assistant-standalone-wrap, .ai-assistant-page, .ai-assistant-chat-container'),
                ],
            ],
            'scripts' => [
                [
                    'id' => 'jquery-core',
                    'src' => includes_url('js/jquery/jquery.min.js'),
                    'global' => 'jQuery',
                ],
                [
                    'id' => 'ai-assistant-chat-core',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-core.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-settings',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-settings.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-tools',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-tools.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-files',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-files.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-providers',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-providers.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-execution',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-execution.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-ui',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-ui.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-conversations',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-conversations.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
                [
                    'id' => 'ai-assistant-chat-bootstrap',
                    'src' => AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat-bootstrap.js',
                    'version' => AI_ASSISTANT_VERSION,
                ],
            ],
        ];
    }

    /**
     * Determine whether this request should render the latch/panel.
     */
    private function should_render_latch() {
        if (!is_admin()) {
            return true;
        }

        if (!function_exists('get_current_screen')) {
            return true;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return true;
        }

        if ($screen->id === 'tools_page_ai-conversations') {
            return false;
        }

        if (method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return false;
        }

        return true;
    }

    /**
     * Check if current user has access to AI Assistant
     */
    private function user_has_access() {
        return current_user_can('ai_assistant_full')
            || current_user_can('ai_assistant_read_only')
            || current_user_can('ai_assistant_chat_only');
    }
}
