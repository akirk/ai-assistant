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
        add_action('wp_ajax_ai_assistant_get_providers_config', [$this, 'ajax_get_providers_config']);

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

        wp_enqueue_style('dashicons');

        wp_enqueue_style(
            'ai-assistant-chat',
            AI_ASSISTANT_PLUGIN_URL . 'assets/css/chat.css',
            ['wp-codemirror'],
            AI_ASSISTANT_VERSION
        );
        wp_add_inline_style(
            'ai-assistant-chat',
            $this->get_color_css()
        );

        wp_enqueue_script('wp-codemirror');

        do_action('wp_ai_provider_browser_status_scripts');
        $this->enqueue_client_abilities_assets();

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
            ['ai-assistant-chat-core', 'ai-assistant-chat-settings', 'ai-assistant-chat-files', 'wp-date'],
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
     * Enqueue the client-side Abilities API only when Core registered it.
     *
     * WP 6.9 COMPAT: When the minimum supported WordPress version always
     * includes @wordpress/abilities and @wordpress/core-abilities, drop the
     * support check and enqueue the bridge module unconditionally.
     *
     * WordPress 6.9 has the PHP/REST Abilities API but may not ship the
     * client packages. In that case the existing AJAX executor remains the
     * compatibility path.
     */
    private function enqueue_client_abilities_assets(): void {
        if (!$this->has_client_abilities_support()) {
            return;
        }

        wp_enqueue_script_module(
            'ai-assistant-client-abilities',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/client-abilities.js',
            [
                [
                    'id'     => '@wordpress/core-abilities',
                    'import' => 'dynamic',
                ],
                [
                    'id'     => '@wordpress/abilities',
                    'import' => 'dynamic',
                ],
            ],
            AI_ASSISTANT_VERSION
        );
    }

    private function has_client_abilities_support(): bool {
        if (!function_exists('wp_enqueue_script_module') || !function_exists('wp_script_modules')) {
            return false;
        }

        $script_modules = wp_script_modules();
        if (!is_object($script_modules) || !method_exists($script_modules, 'get_registered')) {
            return false;
        }

        return (bool) $script_modules->get_registered('@wordpress/abilities') &&
            (bool) $script_modules->get_registered('@wordpress/core-abilities');
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
                'aiAssistantProviders' => $this->get_providers_config(true),
                'aiAssistantBootstrap' => $this->get_bootstrap_config(true),
            ],
            'styles' => $assets['styles'],
            'inlineStyles' => $assets['inlineStyles'],
            'scripts' => $assets['scripts'],
        ]);
    }

    /**
     * AJAX: Return the full provider config after the assistant is opened.
     */
    public function ajax_get_providers_config() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!$this->user_has_access()) {
            wp_send_json_error([
                'errorMessage' => __('Sorry, you are not allowed to use AI Assistant.', 'ai-assistant'),
            ], 403);
        }

        wp_send_json_success([
            'providers' => $this->get_providers_config(true),
        ]);
    }

    /**
     * Get the localized chat runtime config.
     */
    private function get_chat_config() {
        $settings = ai_assistant()->settings();
        $current_user = wp_get_current_user();
        $welcome_tip_context = $this->get_welcome_tip_context();
        $current_ai_changes = $this->get_current_ai_changes_metadata();
        $system_prompt = $this->add_current_ai_changes_prompt_context(
            $settings->get_system_prompt(),
            $current_ai_changes
        );

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_assistant_chat'),
            'fileToolsUrl' => AI_ASSISTANT_PLUGIN_URL . 'file-tools.php',
            'fileToolsToken' => File_Tool_Auth::create_token(
                $settings->get_user_permission_level(),
                $settings->get_user_enabled_tools(),
                get_current_user_id()
            ),
            'toolDefinitions' => $settings->get_client_tool_definitions(),
            'destructiveTools' => $settings->get_destructive_tools(),
            'fileEndpointTools' => $settings->get_file_endpoint_tools(),
            'userPermission' => $settings->get_user_permission_level(),
            'enabledTools' => $settings->get_user_enabled_tools(),
            'autoApprovedAbilities' => ai_assistant_is_playground() ? '*' : $settings->get_auto_approved_abilities(),
            'readonlyAbilities' => Ability_Annotations::get_readonly_ability_ids(),
            'autoApprovedRestApis' => $settings->get_auto_approved_rest_apis(),
            'settingsUrl' => admin_url('options-general.php?page=ai-assistant-settings'),
            'aiChangesUrl' => admin_url('tools.php?page=ai-changes'),
            'conversationExportUrl' => admin_url('admin-post.php?action=ai_assistant_export_conversation'),
            'conversationExportFormats' => ai_assistant()->conversations()->get_export_formats_for_config(),
            'adminUrl' => admin_url(),
            'homeUrl' => home_url(),
            'restApiUrl' => rest_url(),
            'defaultAutoApproveMode' => $this->should_default_auto_approve_mode(),
            'restApiNonce' => wp_create_nonce('wp_rest'),
            // WP 6.9 COMPAT: Remove fallbackToServer once client abilities are required.
            'clientAbilities' => [
                'enabled' => $this->has_client_abilities_support(),
                'fallbackToServer' => true,
            ],
            'userDisplayName' => $current_user->display_name,
            'dateTime' => [
                'dateFormat' => get_option('date_format'),
                'timeFormat' => get_option('time_format'),
                'dateTimeFormat' => trim(get_option('date_format') . ' ' . get_option('time_format')),
            ],
            'welcomeTips' => $this->get_welcome_tips($welcome_tip_context),
            'urlComponent' => $welcome_tip_context['url_component'],
            'currentAiChanges' => $current_ai_changes,
            /**
             * Filters the maximum text attachment size that may be inserted into
             * the conversation as direct private context.
             *
             * Larger browser-side text attachments are offered as compact previews
             * instead of being inserted in full.
             *
             * @param int $bytes Maximum direct-insert size in bytes. Default 131072.
             */
            'maxClientFileBytes' => (int) apply_filters('ai_assistant_client_file_context_bytes', 128 * 1024),
            /**
             * Filters the target preview size for compacted browser-side text
             * attachments.
             *
             * @param int $bytes Compact preview target size in bytes. Default 32768.
             */
            'compactClientFileBytes' => (int) apply_filters('ai_assistant_client_file_compact_bytes', 32 * 1024),
            /**
             * Filters the maximum serialized size for one tool result sent to an
             * LLM provider or saved in conversation history.
             *
             * Oversized tool results are deterministically compacted and may be
             * reopened from the active session cache with inspect_tool_result.
             *
             * @param int $chars Maximum serialized tool result size in characters. Default 32768.
             */
            'maxToolResultChars' => (int) apply_filters('ai_assistant_tool_result_max_chars', 32 * 1024),
            /**
             * Filters the maximum string field size kept inside compacted tool
             * results.
             *
             * Longer string values are truncated with context metadata before the
             * result is sent to the provider.
             *
             * @param int $chars Maximum string field size in characters. Default 8192.
             */
            'maxToolResultStringChars' => (int) apply_filters('ai_assistant_tool_result_string_max_chars', 8 * 1024),
            /**
             * Filters the maximum number of array items retained when compacting
             * tool results.
             *
             * @param int $items Maximum retained array items. Default 40.
             */
            'maxToolResultArrayItems' => (int) apply_filters('ai_assistant_tool_result_array_max_items', 40),
            /**
             * Filters the approximate serialized request budget used before an LLM
             * provider call.
             *
             * If compacted messages still exceed this local budget, older history
             * may be trimmed from the outgoing request while the saved conversation
             * remains intact.
             *
             * @param int $chars Maximum provider request size in characters. Default 163840.
             */
            'maxProviderRequestChars' => (int) apply_filters('ai_assistant_provider_request_max_chars', 160 * 1024),
            /**
             * Filters whether resolved older compacted or oversized tool results
             * are pruned from future provider requests.
             *
             * Small full results, such as loaded skills, ability schemas, and
             * concise errors, are retained as working context. The latest unconsumed
             * tool result is always kept so the model can react to it once.
             *
             * @param bool $prune Whether to prune stale large tool results. Default true.
             */
            'pruneStaleToolResults' => (bool) apply_filters('ai_assistant_prune_stale_tool_results', true),
            /**
             * Filters how many raw tool results are kept in the active browser
             * session for inspect_tool_result.
             *
             * Cached raw results are not persisted into conversation storage; they
             * let the assistant inspect narrow slices of compacted large results
             * without sending the full payload to the provider.
             *
             * @param int $limit Maximum cached tool results. Default 20.
             */
            'toolResultCacheLimit' => (int) apply_filters('ai_assistant_tool_result_cache_limit', 20),
            'maxMediaUploadBytes' => (int) wp_max_upload_size(),
            'toolRoundLimits' => [
                /**
                 * Filters the default maximum number of consecutive tool-call rounds
                 * before the assistant stops the loop.
                 *
                 * @param int $rounds Maximum default tool-call rounds. Default 25.
                 */
                'default' => (int) apply_filters('ai_assistant_tool_round_limit_default', 25),
                /**
                 * Filters the maximum number of consecutive tool-call rounds for
                 * coding workflows.
                 *
                 * Coding workflows often need more read/edit/verify iterations than
                 * ordinary site questions.
                 *
                 * @param int $rounds Maximum coding tool-call rounds. Default 50.
                 */
                'coding' => (int) apply_filters('ai_assistant_tool_round_limit_coding', 50),
                /**
                 * Filters the maximum number of consecutive failed tool rounds before
                 * the assistant stops retrying.
                 *
                 * @param int $rounds Maximum consecutive failed tool rounds. Default 3.
                 */
                'consecutiveFailures' => (int) apply_filters('ai_assistant_tool_round_limit_consecutive_failures', 3),
            ],
            'systemPrompt' => $system_prompt,
            /**
             * Filters ability domain keywords exposed to the browser runtime.
             *
             * These domains help the assistant route plugin-specific topics to the
             * `ability` tool instead of generic file or database inspection.
             *
             * @param array<string,string> $domains Map of plugin slug => comma-separated topic keywords.
             */
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

    private function get_current_ai_changes_metadata(): ?array {
        $assistant = function_exists('ai_assistant') ? ai_assistant() : null;
        if (!is_object($assistant) || !method_exists($assistant, 'plugin_checkout_badge')) {
            return null;
        }

        $badge = $assistant->plugin_checkout_badge();
        if (!is_object($badge) || !method_exists($badge, 'get_current_ai_changes_metadata')) {
            return null;
        }

        $metadata = $badge->get_current_ai_changes_metadata();
        return is_array($metadata) ? $metadata : null;
    }

    private function add_current_ai_changes_prompt_context(string $system_prompt, ?array $metadata): string {
        if (empty($metadata['root']) || empty($metadata['url'])) {
            return $system_prompt;
        }

        $root = (string) $metadata['root'];
        $url = (string) $metadata['url'];

        $prompt = $system_prompt . "\n\nCURRENT PAGE FILE CHANGES:\n"
            . "- The plugin/theme rendering the current window has tracked AI file changes: {$root}.\n"
            . "- When it is useful to review those changes, you may call navigate with url \"{$url}\" and link_text \"View changed files\". The link should be offered for this current window.\n";

        return $prompt;
    }

    /**
     * Get URL-matched plugin tips to show before the default welcome message.
     */
    private function get_welcome_tips($context = null) {
        $context = is_array($context) ? $context : $this->get_welcome_tip_context();

        /**
         * Filter welcome tips keyed by first URL path component.
         *
         * Top-level keys should be actual route components the integrating plugin
         * renders. A key of `my-apps` matches `/my-apps/`, `/my-apps/?tab=one`, and
         * `/my-apps/item/`, but not `/my-apps-other/`. Values may be a single tip
         * string or an array of tip strings.
         *
         * Keep tips broad and user-facing. Route-specific or dynamic behavior belongs
         * in ability descriptions, annotations, and post-execution instructions.
         *
         * Example:
         * ```php
         * add_filter( 'ai_assistant_welcome_tips', function ( $tips ) {
         *     $tips['cookbook'] = [
         *         'Ask me to make a recipe vegan, low carb, or gluten-free.',
         *         'Missing an ingredient? Ask me for substitutions.',
         *     ];
         *     return $tips;
         * } );
         * ```
         *
         * @param array<string,string|array<int|string,string>> $tips Tips keyed by URL component.
         * @param array<string,mixed>              $context Current UI context.
         * @return array<string,string|array<int|string,string>> Filtered tips.
         */
        $tips_by_component = apply_filters('ai_assistant_welcome_tips', [], $context);

        if (!is_array($tips_by_component) || empty($context['url_component'])) {
            return [];
        }

        $candidates = [];
        $seen_messages = [];

        foreach ($tips_by_component as $component => $component_tips) {
            if ($this->normalize_welcome_tip_component_key($component) !== $context['url_component']) {
                continue;
            }

            if (is_array($component_tips) && $this->is_welcome_tip_object_shape($component_tips)) {
                continue;
            }

            $component_tips = is_array($component_tips) ? $component_tips : [$component_tips];
            foreach ($component_tips as $tip) {
                $message = $this->normalize_welcome_tip_message($tip);
                if ($message === '') {
                    continue;
                }

                $message_key = strtolower(preg_replace('/\s+/', ' ', $message));
                if (isset($seen_messages[$message_key])) {
                    continue;
                }

                $seen_messages[$message_key] = true;
                $candidates[] = [
                    'message' => $message,
                ];
            }
        }

        $limit = (int) apply_filters('ai_assistant_welcome_tip_limit', 2, $context);
        $limit = max(0, $limit);
        $max_length = (int) apply_filters('ai_assistant_welcome_tip_max_length', 280, $context);
        $max_length = max(0, $max_length);

        $tips = [];
        foreach ($candidates as $candidate) {
            if (count($tips) >= $limit) {
                break;
            }

            $tips[] = $this->limit_welcome_tip_message($candidate['message'], $max_length);
        }

        return $tips;
    }

    /**
     * Build context used to match welcome tips.
     */
    private function get_welcome_tip_context() {
        $screen_id = '';
        if (is_admin() && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen) {
                $screen_id = $screen->id;
            }
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = $this->normalize_welcome_tip_path($request_uri);

        return [
            'user_id' => get_current_user_id(),
            'is_admin' => is_admin(),
            'screen_id' => $screen_id,
            'path' => $path,
            'url_component' => $this->get_first_url_component($path),
        ];
    }

    /**
     * Normalize a URL or path to a site-relative path.
     */
    private function normalize_welcome_tip_path($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '/';
        }

        $path = wp_parse_url($value, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = $value;
        }

        $path = '/' . ltrim($path, '/');
        $site_path = wp_parse_url(home_url(), PHP_URL_PATH);
        if (is_string($site_path) && $site_path !== '' && $site_path !== '/') {
            $site_path = '/' . trim($site_path, '/');
            if ($path === $site_path) {
                $path = '/';
            } elseif (strpos($path, $site_path . '/') === 0) {
                $path = substr($path, strlen($site_path));
            }
        }

        return $path ?: '/';
    }

    /**
     * Get the first path component from a URL, path, or component string.
     */
    private function get_first_url_component($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '/') !== false || strpos($value, '?') !== false || strpos($value, '://') !== false) {
            $value = $this->normalize_welcome_tip_path($value);
        }

        $value = trim($value, "/ \t\n\r\0\x0B");
        if ($value === '') {
            return '';
        }

        $parts = explode('/', $value);
        return strtolower(rawurldecode($parts[0]));
    }

    /**
     * Normalize a welcome tip array key. Keys must be URL components, not paths.
     */
    private function normalize_welcome_tip_component_key($key) {
        $key = trim((string) $key);
        if ($key === '' || strpos($key, '/') !== false || strpos($key, '?') !== false || strpos($key, '://') !== false) {
            return '';
        }

        return $this->get_first_url_component($key);
    }

    /**
     * Detect the removed object-style tip shape so its fields are not rendered.
     */
    private function is_welcome_tip_object_shape(array $tips) {
        foreach (['message', 'tip', 'url_component', 'component', 'path', 'url', 'priority'] as $key) {
            if (array_key_exists($key, $tips)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a tip message.
     */
    private function normalize_welcome_tip_message($message) {
        if (!is_scalar($message)) {
            return '';
        }

        return trim((string) $message);
    }

    /**
     * Limit tip length so careless integrations cannot dominate the welcome area.
     */
    private function limit_welcome_tip_message($message, $max_length) {
        if ($max_length <= 0) {
            return $message;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($message) <= $max_length) {
                return $message;
            }

            if ($max_length <= 3) {
                return mb_substr($message, 0, $max_length);
            }

            return rtrim(mb_substr($message, 0, $max_length - 3)) . '...';
        }

        if (strlen($message) <= $max_length) {
            return $message;
        }

        if ($max_length <= 3) {
            return substr($message, 0, $max_length);
        }

        return rtrim(substr($message, 0, $max_length - 3)) . '...';
    }

    /**
     * Get provider config for the chat runtime.
     */
    private function get_providers_config(?bool $resolve_models = null) {
        $bridge = ai_assistant()->connectors_bridge();
        if (!$bridge) {
            return ['source' => 'legacy'];
        }

        if ($resolve_models === null) {
            $resolve_models = !$this->should_defer_initial_provider_config();
        }

        $providers_config = $bridge->get_providers_config($resolve_models);
        $providers_config['connectorsUrl'] = admin_url('options-connectors.php');
        return $providers_config;
    }

    /**
     * Closed latch panels should not enumerate remote/local models during page render.
     */
    private function should_defer_initial_provider_config(): bool {
        return !$this->is_full_page_conversation_request();
    }

    /**
     * Full-page conversation views are already open and need complete provider data.
     */
    private function is_full_page_conversation_request(): bool {
        if (is_admin() && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'tools_page_ai-conversations') {
                return true;
            }
        }

        $path = trim($this->normalize_welcome_tip_path($_SERVER['REQUEST_URI'] ?? '/'), '/');
        return $path === Conversations_App::URL_PATH || strpos($path, Conversations_App::URL_PATH . '/') === 0;
    }

    /**
     * Get the bootstrap config used by both normal loads and AJAX installs.
     */
    private function get_bootstrap_config($render_latch = null) {
        if ($render_latch === null) {
            $render_latch = $this->should_render_latch();
        }

        $history_url = class_exists('\WpApp\WpApp')
            ? Conversations_App::get_url()
            : admin_url('tools.php?page=ai-conversations');

        return [
            'deferInit' => true,
            'renderLatch' => (bool) $render_latch,
            'urls' => [
                'history' => $history_url,
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
                'tokenCountTitle' => __('Token usage', 'ai-assistant'),
                'dropFiles' => __('Drop files to attach', 'ai-assistant'),
                'scrollBottom' => __('Scroll to bottom', 'ai-assistant'),
                'attachFiles' => __('Attach files', 'ai-assistant'),
                'stopGeneration' => __('Stop generation', 'ai-assistant'),
                'resizePanel' => __('Resize AI Assistant', 'ai-assistant'),
                'resizePanelTitle' => __('Drag to resize AI Assistant. Double-click to fit the window.', 'ai-assistant'),
                'autoApproveMode' => __('Auto-approve', 'ai-assistant'),
                'autoApproveTitle' => __('Automatically approve destructive actions without confirmation', 'ai-assistant'),
            ],
        ];
    }

    /**
     * Whether destructive actions should be auto-approved by default.
     */
    private function should_default_auto_approve_mode(): bool {
        return wp_parse_url(home_url(), PHP_URL_HOST) === 'my.wordpress.net';
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
                    'css' => $this->get_color_css(),
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
     * Get color custom properties for admin and isolated frontend shells.
     */
    private function get_color_css() {
        return Admin_Colors::get_current_scheme_css(':root, body, #ai-assistant-wrap, .ai-assistant-standalone-wrap, .ai-assistant-page, .ai-assistant-chat-container');
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
