<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings page and option management
 */
class Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);
        add_action('wp_ajax_ai_assistant_get_skill', [$this, 'ajax_get_skill']);
        add_action('wp_ajax_ai_assistant_toggle_auto_approve_ability', [$this, 'ajax_toggle_auto_approve_ability']);
        add_action('wp_ajax_ai_assistant_toggle_auto_approve_rest_api', [$this, 'ajax_toggle_auto_approve_rest_api']);
        add_action('wp_ajax_ai_assistant_sample_readonly_ability', [$this, 'ajax_sample_readonly_ability']);
        add_action('load-tools_page_ai-conversations', [$this, 'add_help_tabs']);
        add_action('load-settings_page_ai-assistant-settings', [$this, 'add_help_tabs']);
        add_filter('map_meta_cap', [$this, 'map_tool_cap'], 10, 3);
    }

    public function enqueue_settings_assets(string $hook): void {
        if ($hook !== 'settings_page_ai-assistant-settings') {
            return;
        }

        wp_enqueue_style('wp-codemirror');
        wp_enqueue_script('wp-codemirror');
    }

    public function ajax_get_skill() {
        check_ajax_referer('ai_assistant_skills', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $skill_id = sanitize_file_name($_POST['skill'] ?? '');
        if (empty($skill_id)) {
            wp_send_json_error(['message' => 'Skill ID is required']);
        }

        $skill = Skill_Registry::get_skill($skill_id);
        if ($skill === null) {
            wp_send_json_error(['message' => 'Skill not found']);
        }

        wp_send_json_success([
            'id' => $skill_id,
            'title' => $skill['title'],
            'content' => $skill['content'],
        ]);
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        // Add to Tools menu
        add_management_page(
            __('AI Conversations', 'ai-assistant'),
            __('AI Conversations', 'ai-assistant'),
            'edit_posts',
            'ai-conversations',
            [$this, 'render_chat_page']
        );

        // Settings under Settings menu
        add_options_page(
            __('AI Assistant Settings', 'ai-assistant'),
            __('AI Assistant', 'ai-assistant'),
            'manage_options',
            'ai-assistant-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Add help tabs to the chat page
     */
    public function add_help_tabs() {
        $screen = get_current_screen();

        $screen->add_help_tab([
            'id'      => 'ai-assistant-overview',
            'title'   => __('Overview', 'ai-assistant'),
            'content' => '<p>' . __('The AI Assistant helps you manage your WordPress site through natural language conversation. Ask questions, request changes, or get help with content.', 'ai-assistant') . '</p>'
                       . '<p>' . __('Your conversation history is saved automatically and can be accessed from the sidebar.', 'ai-assistant') . '</p>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-assistant-capabilities',
            'title'   => __('Capabilities', 'ai-assistant'),
            'content' => '<p>' . __('The AI Assistant can help you with:', 'ai-assistant') . '</p>'
                       . '<ul>'
                       . '<li>' . __('<strong>Content</strong> - Create, edit, and manage posts and pages', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Media</strong> - Upload and organize images and files', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Settings</strong> - View and modify site configuration', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Plugins</strong> - Get information about installed plugins', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Database</strong> - Query data (read-only unless you have full access)', 'ai-assistant') . '</li>'
                       . '</ul>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-assistant-tools',
            'title'   => __('Available Tools', 'ai-assistant'),
            'content' => '<p>' . __('The AI Assistant has access to the following tools:', 'ai-assistant') . '</p>'
                       . '<ul id="ai-tools-list"><li><em>Loading...</em></li></ul>'
                       . '<script>
                           jQuery(function($) {
                               function populateTools() {
                                   if (typeof window.aiAssistant !== "undefined" && window.aiAssistant.getTools) {
                                       var tools = window.aiAssistant.getTools();
                                       var $list = $("#ai-tools-list");
                                       $list.empty();
                                       tools.forEach(function(tool) {
                                           $list.append("<li><code>" + tool.name + "</code> - " + $("<div>").text(tool.description).html() + "</li>");
                                       });
                                   } else {
                                       setTimeout(populateTools, 100);
                                   }
                               }
                               populateTools();
                           });
                       </script>',
        ]);

        $skills_content = $this->get_skills_help_content();
        $screen->add_help_tab([
            'id'      => 'ai-assistant-skills',
            'title'   => __('Available Skills', 'ai-assistant'),
            'content' => $skills_content,
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-assistant-yolo',
            'title'   => __('YOLO Mode', 'ai-assistant'),
            'content' => '<p>' . __('When YOLO Mode is enabled, the assistant will execute actions without asking for confirmation. Use with caution.', 'ai-assistant') . '</p>'
                       . '<p>' . __('With YOLO Mode disabled (default), you will be prompted to approve any changes before they are made.', 'ai-assistant') . '</p>',
        ]);

        $system_prompt = $this->get_system_prompt();
        $screen->add_help_tab([
            'id'      => 'ai-assistant-system-prompt',
            'title'   => __('System Prompt', 'ai-assistant'),
            'content' => '<p>' . __('The following system prompt is sent to the AI with each conversation:', 'ai-assistant') . '</p>'
                       . '<pre style="white-space: pre-wrap; background: #f6f7f7; padding: 10px; border-radius: 4px; max-height: 400px; overflow-y: auto;">' . esc_html($system_prompt) . '</pre>',
        ]);

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', 'ai-assistant') . '</strong></p>'
            . '<p><a href="' . esc_url(admin_url('options-general.php?page=ai-assistant-settings')) . '">' . __('Plugin Settings', 'ai-assistant') . '</a></p>'
        );
    }

    /**
     * Render the dedicated chat page
     */
    public function render_chat_page() {
        $conversation_id = Conversations_App::get_request_conversation_id();
        $settings_url = admin_url('options-general.php?page=ai-assistant-settings');
        ?>
        <style>
            #wpbody-content { padding-bottom: 0; }
            #wpfooter { display: none; }
            .ai-assistant-page {
                height: calc(100vh - 68px);
                margin: 0 20px 0 0;
                padding: 8px 0 8px 8px;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
            }
            .ai-chat-layout { flex: 1; min-height: 0; }
            .ai-assistant-chat-container { border: none; border-radius: 0; }
            @media screen and (max-width: 782px) {
                .ai-assistant-page {
                    height: calc(100vh - 82px);
                    margin-right: 10px;
                    padding: 8px 0;
                }
            }
        </style>
        <?php
        Conversations_App::render_chat_shell([
            'conversation_id' => $conversation_id,
            'settings_url' => $settings_url,
            'container_class' => 'wrap ai-assistant-page',
        ]);
    }

    public function map_tool_cap($caps, $cap, $user_id) {
        if (strpos($cap, 'ai_assistant_tool_') !== 0) {
            return $caps;
        }

        $has_full_access = user_can($user_id, 'ai_assistant_full');
        $has_read_only_access = user_can($user_id, 'ai_assistant_read_only');

        if (!$has_full_access && !$has_read_only_access) {
            return ['do_not_allow'];
        }

        if (ai_assistant_is_playground()) {
            return ['exist'];
        }

        $tool_name = substr($cap, strlen('ai_assistant_tool_'));
        $enabled = get_option('ai_assistant_enabled_tools', $this->get_default_enabled_tools());

        if (!in_array($tool_name, (array) $enabled, true)) {
            return ['do_not_allow'];
        }

        if ($has_full_access) {
            return ['exist'];
        }

        // read_only users get non-dangerous tools only
        if ($has_read_only_access) {
            $all_meta = $this->get_all_tools_with_meta();
            if (isset($all_meta[$tool_name]) && !$all_meta[$tool_name]['dangerous']) {
                return ['exist'];
            }
        }

        return ['do_not_allow'];
    }

    public function get_default_enabled_tools() {
        return self::default_enabled_tools();
    }

    public static function default_enabled_tools(): array {
        $tools = [
            'read_file', 'list_directory', 'search_files', 'search_content', 'db_query',
            'rest_api', 'environment_info', 'get_plugins', 'get_themes',
            'list_abilities', 'get_ability', 'execute_ability', 'navigate', 'get_page_html',
            'pick_image', 'summarize_conversation', 'list_skills', 'get_skill',
        ];

        /**
         * Filters the default enabled tools for new installations.
         *
         * High-risk extension tools should usually register metadata without
         * enabling themselves by default.
         *
         * @param array<int,string> $tools Default enabled tool names.
         */
        return array_values(array_unique(array_filter((array) apply_filters('ai_assistant_default_enabled_tools', $tools))));
    }

    public function get_all_tools_with_meta() {
        $tools = [
            'read_file'             => ['label' => 'Read File',             'group' => 'File Reading',    'dangerous' => false],
            'list_directory'        => ['label' => 'List Directory',        'group' => 'File Reading',    'dangerous' => false],
            'search_files'          => ['label' => 'Search Files',          'group' => 'File Reading',    'dangerous' => false],
            'search_content'        => ['label' => 'Search Content',        'group' => 'File Reading',    'dangerous' => false],
            'db_query'              => ['label' => 'DB Query',              'group' => 'Database',        'dangerous' => false],
            'environment_info'      => ['label' => 'Environment Info',      'group' => 'WordPress',       'dangerous' => false],
            'get_plugins'           => ['label' => 'Get Plugins',           'group' => 'WordPress',       'dangerous' => false],
            'get_themes'            => ['label' => 'Get Themes',            'group' => 'WordPress',       'dangerous' => false],
            'rest_api'              => ['label' => 'REST API',              'group' => 'WordPress',       'dangerous' => false],
            'list_abilities'        => ['label' => 'List Abilities',        'group' => 'Abilities',       'dangerous' => false],
            'get_ability'           => ['label' => 'Get Ability',           'group' => 'Abilities',       'dangerous' => false],
            'execute_ability'       => ['label' => 'Execute Ability',       'group' => 'Abilities',       'dangerous' => true],
            'navigate'              => ['label' => 'Navigate',              'group' => 'Navigation & UI', 'dangerous' => false],
            'get_page_html'         => ['label' => 'Get Page HTML',         'group' => 'Navigation & UI', 'dangerous' => false],
            'pick_image'            => ['label' => 'Pick Image',            'group' => 'Media',           'dangerous' => false],
            'summarize_conversation'=> ['label' => 'Summarize Conversation','group' => 'Conversation',    'dangerous' => false],
            'list_skills'           => ['label' => 'List Skills',           'group' => 'Conversation',    'dangerous' => false],
            'get_skill'             => ['label' => 'Get Skill',             'group' => 'Conversation',    'dangerous' => false],
        ];

        /**
         * Filters tool metadata used by permissions UI and capability mapping.
         *
         * @param array<string,array{label:string,group:string,dangerous:bool}> $tools Tool metadata keyed by tool name.
         */
        return $this->sort_tool_meta($this->normalize_tool_meta(apply_filters('ai_assistant_tool_meta', $tools)));
    }

    public function get_destructive_tools(): array {
        $tools = [];
        foreach ($this->get_all_tools_with_meta() as $name => $meta) {
            if (!empty($meta['dangerous'])) {
                $tools[] = $name;
            }
        }

        return $tools;
    }

    public function get_client_tool_definitions(): array {
        $definitions = apply_filters('ai_assistant_client_tool_definitions', []);
        return is_array($definitions) ? array_values($definitions) : [];
    }

    public function get_file_endpoint_tools(): array {
        $tools = [
            'read_file',
            'find',
            'list_directory',
            'search_files',
            'search_content',
        ];

        return array_values(array_unique(array_filter((array) apply_filters('ai_assistant_file_endpoint_tools', $tools))));
    }

    private function normalize_tool_meta($tools): array {
        if (!is_array($tools)) {
            return [];
        }

        $normalized = [];
        foreach ($tools as $name => $meta) {
            if (!is_string($name) || $name === '' || !is_array($meta)) {
                continue;
            }

            $normalized[$name] = [
                'label'     => (string) ($meta['label'] ?? $name),
                'group'     => (string) ($meta['group'] ?? 'Other'),
                'dangerous' => !empty($meta['dangerous']),
            ];
        }

        return $normalized;
    }

    private function sort_tool_meta(array $tools): array {
        if (empty($tools)) {
            return $tools;
        }

        $group_order = [
            'File Reading'    => 10,
            'File Writing'    => 20,
            'Code Execution'  => 30,
            'Database'        => 40,
            'WordPress'       => 50,
            'Abilities'       => 60,
            'Navigation & UI' => 70,
            'Media'           => 80,
            'Conversation'    => 90,
        ];

        /**
         * Filters the display order for tool permission tabs.
         *
         * Lower numbers appear first. Unknown groups keep their relative
         * registration order after known groups.
         *
         * @param array<string,int> $group_order Group label => priority.
         */
        $group_order = apply_filters('ai_assistant_tool_group_order', $group_order);
        $group_order = is_array($group_order) ? $group_order : [];

        $tool_order = [
            'read_file'              => 10,
            'list_directory'         => 20,
            'search_files'           => 30,
            'search_content'         => 40,
            'write_file'             => 10,
            'edit_file'              => 20,
            'delete_file'            => 30,
            'run_php'                => 10,
            'db_query'               => 10,
            'environment_info'       => 10,
            'get_plugins'            => 20,
            'get_themes'             => 30,
            'install_plugin'         => 40,
            'rest_api'               => 50,
            'list_abilities'         => 10,
            'get_ability'            => 20,
            'execute_ability'        => 30,
            'navigate'               => 10,
            'get_page_html'          => 20,
            'pick_image'             => 10,
            'summarize_conversation' => 10,
            'list_skills'            => 20,
            'get_skill'              => 30,
        ];

        /**
         * Filters the display order for tools within permission tabs.
         *
         * Lower numbers appear first within a group. Unknown tools keep their
         * relative registration order after known tools in that group.
         *
         * @param array<string,int> $tool_order Tool name => priority.
         */
        $tool_order = apply_filters('ai_assistant_tool_order', $tool_order);
        $tool_order = is_array($tool_order) ? $tool_order : [];

        $indexed = [];
        $index = 0;
        foreach ($tools as $name => $meta) {
            $indexed[$name] = [
                'name'  => $name,
                'meta'  => $meta,
                'index' => $index++,
            ];
        }

        uasort($indexed, function($left, $right) use ($group_order, $tool_order) {
            $left_group = (string) ($left['meta']['group'] ?? '');
            $right_group = (string) ($right['meta']['group'] ?? '');
            $left_group_priority = isset($group_order[$left_group]) ? (int) $group_order[$left_group] : PHP_INT_MAX;
            $right_group_priority = isset($group_order[$right_group]) ? (int) $group_order[$right_group] : PHP_INT_MAX;

            if ($left_group_priority !== $right_group_priority) {
                return $left_group_priority <=> $right_group_priority;
            }

            if ($left_group !== $right_group) {
                return $left['index'] <=> $right['index'];
            }

            $left_name = (string) ($left['name'] ?? '');
            $right_name = (string) ($right['name'] ?? '');
            $left_tool_priority = isset($tool_order[$left_name]) ? (int) $tool_order[$left_name] : PHP_INT_MAX;
            $right_tool_priority = isset($tool_order[$right_name]) ? (int) $tool_order[$right_name] : PHP_INT_MAX;

            if ($left_tool_priority !== $right_tool_priority) {
                return $left_tool_priority <=> $right_tool_priority;
            }

            return $left['index'] <=> $right['index'];
        });

        $sorted = [];
        foreach ($indexed as $name => $entry) {
            $sorted[$name] = $entry['meta'];
        }

        return $sorted;
    }

    public function get_user_enabled_tools() {
        $all = array_keys($this->get_all_tools_with_meta());
        return array_values(array_filter($all, fn($t) => current_user_can('ai_assistant_tool_' . $t)));
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Display settings (only server-side setting now)
        register_setting('ai_assistant_settings', 'ai_assistant_show_on_frontend', [
            'type' => 'string',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '';
            },
            'default' => '1',
        ]);

        // Provider section (localStorage-based, rendered via callback)
        add_settings_section(
            'ai_assistant_provider_section',
            __('LLM Provider Settings', 'ai-assistant'),
            [$this, 'provider_section_callback'],
            'ai-assistant-settings'
        );

        // Capabilities section (read-only display)
        add_settings_section(
            'ai_assistant_permissions_section',
            __('Role Capabilities', 'ai-assistant'),
            [$this, 'permissions_section_callback'],
            'ai-assistant-settings'
        );

        register_setting('ai_assistant_settings', 'ai_assistant_enabled_tools', [
            'type' => 'array',
            'sanitize_callback' => function($value) {
                $all = array_keys($this->get_all_tools_with_meta());
                return array_values(array_intersect((array) $value, $all));
            },
            'default' => $this->get_default_enabled_tools(),
        ]);

        add_settings_section(
            'ai_assistant_tools_section',
            __('Tool Permissions', 'ai-assistant'),
            [$this, 'tools_section_callback'],
            'ai-assistant-settings'
        );

        register_setting('ai_assistant_settings', 'ai_assistant_auto_approved_abilities', [
            'type' => 'array',
            'sanitize_callback' => function($value) {
                $values = array_map('sanitize_text_field', (array) $value);
                $values = array_filter($values, fn($item) => $item !== '');
                return array_values(array_unique($values));
            },
            'default' => [],
        ]);

        register_setting('ai_assistant_settings', 'ai_assistant_auto_approved_rest_apis', [
            'type' => 'array',
            'sanitize_callback' => function($value) {
                $values = array_map('sanitize_text_field', (array) $value);
                $values = array_filter($values, fn($item) => $item !== '');
                return array_values(array_unique($values));
            },
            'default' => [],
        ]);
    }

    /**
     * Provider section - Connectors or localStorage-based settings
     */
    public function provider_section_callback() {
        if (Connectors_Bridge::is_available()) {
            $this->render_connectors_notice();
            return;
        }
        ?>
        <p><?php esc_html_e('These settings are stored in your browser and not on the server. API keys never leave your device.', 'ai-assistant'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ai_provider"><?php esc_html_e('Provider', 'ai-assistant'); ?></label></th>
                <td>
                    <select id="ai_provider" class="ai-localstorage-setting" data-setting="provider">
                        <option value="anthropic"><?php esc_html_e('Anthropic (Claude)', 'ai-assistant'); ?></option>
                        <option value="openai"><?php esc_html_e('OpenAI (ChatGPT)', 'ai-assistant'); ?></option>
                        <option value="local"><?php esc_html_e('Local LLM (Ollama/LM Studio)', 'ai-assistant'); ?></option>
                    </select>
                </td>
            </tr>
            <tr class="ai-provider-row" data-provider="anthropic">
                <th scope="row"><label for="ai_anthropic_key"><?php esc_html_e('Anthropic API Key', 'ai-assistant'); ?></label></th>
                <td>
                    <input type="text" id="ai_anthropic_key" class="regular-text ai-localstorage-setting ai-api-key-input" data-setting="anthropicApiKey" placeholder="sk-ant-..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                    <button type="button" class="button ai-test-connection" data-provider="anthropic"><?php esc_html_e('Test Connection', 'ai-assistant'); ?></button>
                    <span class="ai-connection-status"></span>
                </td>
            </tr>
            <tr class="ai-provider-row" data-provider="openai">
                <th scope="row"><label for="ai_openai_key"><?php esc_html_e('OpenAI API Key', 'ai-assistant'); ?></label></th>
                <td>
                    <input type="text" id="ai_openai_key" class="regular-text ai-localstorage-setting ai-api-key-input" data-setting="openaiApiKey" placeholder="sk-..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                    <button type="button" class="button ai-test-connection" data-provider="openai"><?php esc_html_e('Test Connection', 'ai-assistant'); ?></button>
                    <span class="ai-connection-status"></span>
                </td>
            </tr>
            <tr class="ai-provider-row" data-provider="local">
                <th scope="row"><label for="ai_local_endpoint"><?php esc_html_e('Local LLM Endpoint', 'ai-assistant'); ?></label></th>
                <td>
                    <input type="url" id="ai_local_endpoint" class="regular-text ai-localstorage-setting" data-setting="localEndpoint" placeholder="http://localhost:11434">
                    <button type="button" class="button ai-test-connection" data-provider="local"><?php esc_html_e('Test Connection', 'ai-assistant'); ?></button>
                    <span class="ai-connection-status"></span>
                    <p class="description"><?php esc_html_e('Ollama default: localhost:11434, LM Studio: localhost:1234', 'ai-assistant'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ai_model"><?php esc_html_e('Model', 'ai-assistant'); ?></label></th>
                <td>
                    <select id="ai_model" class="ai-localstorage-setting" data-setting="model">
                        <option value=""><?php esc_html_e('Select a model...', 'ai-assistant'); ?></option>
                    </select>
                    <button type="button" class="button" id="ai-refresh-models"><?php esc_html_e('Refresh Models', 'ai-assistant'); ?></button>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ai_summarization_model"><?php esc_html_e('Summarization Model', 'ai-assistant'); ?></label></th>
                <td>
                    <select id="ai_summarization_model" class="ai-localstorage-setting" data-setting="summarizationModel">
                        <option value=""><?php esc_html_e('Same as chat model', 'ai-assistant'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Optional: Use a faster/cheaper model for conversation summaries.', 'ai-assistant'); ?></p>
                </td>
            </tr>
        </table>

        <style>
            .ai-provider-row { display: none; }
            .ai-provider-row.active { display: table-row; }
            .ai-api-key-input {
                -webkit-text-security: disc;
            }
            .ai-connection-status { margin-left: 10px; }
            .ai-connection-status.success { color: green; }
            .ai-connection-status.error { color: red; }
            .ai-connection-status {
                margin-left: 0;
            }
        </style>

        <script>
        jQuery(function($) {
            var STORAGE_PREFIX = 'aiAssistant_';
            var DEFAULT_MODELS = {
                anthropic: 'claude-sonnet-4-6',
                openai: 'gpt-5.5'
            };
            var PREFERRED_MODELS = {
                anthropic: [
                    'claude-sonnet-4-6',
                    'claude-sonnet-4-5-20250929',
                    'claude-sonnet-4-20250514'
                ],
                openai: [
                    'gpt-5.5',
                    'gpt-5.4',
                    'gpt-5.4-mini',
                    'gpt-5.1',
                    'gpt-5',
                    'gpt-4.1',
                    'gpt-4o'
                ]
            };

            function getSetting(key) {
                return localStorage.getItem(STORAGE_PREFIX + key) || '';
            }

            function setSetting(key, value) {
                if (value) {
                    localStorage.setItem(STORAGE_PREFIX + key, value);
                } else {
                    localStorage.removeItem(STORAGE_PREFIX + key);
                }
            }

            function normalizeModelId(model) {
                return String(model || '').trim();
            }

            function getModelSettingKey(provider) {
                provider = normalizeModelId(provider);
                return provider ? 'model_' + provider : 'model';
            }

            function isOpenAIModelId(model) {
                model = normalizeModelId(model);
                return /^gpt-\d/.test(model) ||
                    /^o\d/.test(model) ||
                    model.indexOf('chatgpt-') === 0 ||
                    model.indexOf('computer-use-preview') === 0 ||
                    /^ft:(gpt-\d|o\d)/.test(model);
            }

            function isAnthropicModelId(model) {
                return normalizeModelId(model).indexOf('claude-') === 0;
            }

            function isModelCompatibleWithProvider(provider, model) {
                provider = normalizeModelId(provider);
                model = normalizeModelId(model);
                if (!model) return false;

                if (provider === 'anthropic') {
                    return isAnthropicModelId(model);
                }

                if (provider === 'openai') {
                    return isOpenAIModelId(model);
                }

                if (provider === 'local') {
                    return !isAnthropicModelId(model) && !isOpenAIModelId(model);
                }

                return true;
            }

            function getStoredModel(provider) {
                var providerModel = getSetting(getModelSettingKey(provider));
                if (providerModel) {
                    return providerModel;
                }

                if (provider === 'local') {
                    var localModel = getSetting('localModel');
                    if (localModel) {
                        return localModel;
                    }
                }

                var legacyModel = getSetting('model');
                return isModelCompatibleWithProvider(provider, legacyModel) ? legacyModel : '';
            }

            function hasModel(models, id) {
                id = normalizeModelId(id);
                return (models || []).some(function(model) {
                    return normalizeModelId(model.id) === id;
                });
            }

            function getRecommendedModel(provider, models) {
                models = models || [];
                var preferred = PREFERRED_MODELS[provider] || [];

                for (var i = 0; i < preferred.length; i++) {
                    if (hasModel(models, preferred[i])) {
                        return preferred[i];
                    }
                }

                if (DEFAULT_MODELS[provider] && hasModel(models, DEFAULT_MODELS[provider])) {
                    return DEFAULT_MODELS[provider];
                }

                return models.length > 0 ? models[0].id : (DEFAULT_MODELS[provider] || '');
            }

            function persistModelSelection(provider, model) {
                setSetting(getModelSettingKey(provider), model);
                setSetting('model', model);
                if (provider === 'local') {
                    setSetting('localModel', model);
                }
            }

            function isApiKeyInput($el) {
                return $el.hasClass('ai-api-key-input');
            }

            function getFieldValue($el) {
                if (isApiKeyInput($el)) {
                    var actualValue = $el.data('actualValue');
                    return typeof actualValue === 'undefined' ? $el.val() : actualValue;
                }
                return $el.val();
            }

            function maskApiKey(value) {
                return value ? new Array(Math.min(value.length, 32) + 1).join('*') : '';
            }

            function setApiKeyInputValue($el, value) {
                value = value || '';
                $el.data('actualValue', value);

                if ($el.is(':focus')) {
                    $el.val(value).removeClass('ai-api-key-masked');
                    return;
                }

                $el.val(maskApiKey(value)).toggleClass('ai-api-key-masked', !!value);
            }

            // Load settings into form
            function loadSettings() {
                $('.ai-localstorage-setting').each(function() {
                    var $el = $(this);
                    var key = $el.data('setting');
                    var value = getSetting(key);
                    if (value) {
                        if (isApiKeyInput($el)) {
                            setApiKeyInputValue($el, value);
                        } else {
                            $el.val(value);
                        }
                    }
                });
                updateProviderVisibility();
                loadModels();
            }

            // Show/hide provider-specific rows
            function updateProviderVisibility() {
                var provider = $('#ai_provider').val();
                $('.ai-provider-row').removeClass('active');
                $('.ai-provider-row[data-provider="' + provider + '"]').addClass('active');
            }

            $('#ai_provider').on('change', function() {
                updateProviderVisibility();
                loadModels();
            });

            // Load models based on provider
            async function loadModels() {
                var provider = $('#ai_provider').val();
                var $modelSelect = $('#ai_model');
                var $sumSelect = $('#ai_summarization_model');
                var currentModel = getStoredModel(provider);
                var currentSumModel = getSetting('summarizationModel');

                $modelSelect.html('<option value=""><?php echo esc_js(__('Loading...', 'ai-assistant')); ?></option>');

                var models = [];

                if (provider === 'anthropic') {
                    var apiKey = getFieldValue($('#ai_anthropic_key')) || getSetting('anthropicApiKey');
                    if (apiKey) {
                        models = await fetchAnthropicModels(apiKey);
                    }
                } else if (provider === 'openai') {
                    var apiKey = getFieldValue($('#ai_openai_key')) || getSetting('openaiApiKey');
                    if (apiKey) {
                        models = await fetchOpenAIModels(apiKey);
                    }
                } else if (provider === 'local') {
                    var userEndpoint = $('#ai_local_endpoint').val();
                    var endpoint = userEndpoint || getSetting('localEndpoint');
                    var $endpointInput = $('#ai_local_endpoint');
                    var $status = $endpointInput.siblings('.ai-connection-status');

                    // Try current endpoint first
                    if (endpoint) {
                        models = await fetchLocalModels(endpoint);
                    }

                    // If no models found, auto-detect only when user hasn't set a custom endpoint
                    if ((!models || models.length === 0) && !userEndpoint) {
                        $status.text('<?php echo esc_js(__('Auto-detecting...', 'ai-assistant')); ?>').removeClass('success error');
                        var detected = await autoDetectLocalEndpoint();
                        if (detected) {
                            $endpointInput.val(detected.url);
                            setSetting('localEndpoint', detected.url);
                            $status.text('<?php echo esc_js(__('Found', 'ai-assistant')); ?> ' + detected.name + '!').addClass('success');
                            models = await fetchLocalModels(detected.url);
                        } else {
                            $status.text('<?php echo esc_js(__('No local LLM found', 'ai-assistant')); ?>').addClass('error');
                        }
                    }
                }

                $modelSelect.empty().append('<option value=""><?php echo esc_js(__('Select a model...', 'ai-assistant')); ?></option>');
                $sumSelect.empty().append('<option value=""><?php echo esc_js(__('Same as chat model', 'ai-assistant')); ?></option>');

                if (models && models.length > 0) {
                    var selectedModel = currentModel && hasModel(models, currentModel)
                        ? currentModel
                        : getRecommendedModel(provider, models);
                    var usedRecommendation = currentModel !== selectedModel;
                    models.forEach(function(m) {
                        var modelName = m.name;
                        if (m.id === selectedModel && usedRecommendation) {
                            modelName += ' - <?php echo esc_js(__('Recommended', 'ai-assistant')); ?>';
                        }
                        $('<option>')
                            .val(m.id)
                            .text(modelName)
                            .prop('selected', m.id === selectedModel)
                            .appendTo($modelSelect);
                        $('<option>')
                            .val(m.id)
                            .text(m.name)
                            .prop('selected', m.id === currentSumModel)
                            .appendTo($sumSelect);
                    });
                    if (selectedModel) {
                        persistModelSelection(provider, selectedModel);
                    }
                } else if (provider !== 'local') {
                    $modelSelect.html('<option value=""><?php echo esc_js(__('Enter API key first', 'ai-assistant')); ?></option>');
                } else {
                    $modelSelect.html('<option value=""><?php echo esc_js(__('Could not connect to local server', 'ai-assistant')); ?></option>');
                }
            }

            async function fetchAnthropicModels(apiKey) {
                try {
                    var response = await fetch('https://api.anthropic.com/v1/models?limit=100', {
                        headers: {
                            'x-api-key': apiKey,
                            'anthropic-version': '2023-06-01',
                            'anthropic-dangerous-direct-browser-access': 'true'
                        }
                    });
                    if (response.ok) {
                        var data = await response.json();
                        return (data.data || []).map(function(m) {
                            return {id: m.id, name: m.display_name || m.id};
                        });
                    }
                } catch (e) {}
                return [];
            }

            async function fetchOpenAIModels(apiKey) {
                try {
                    var response = await fetch('https://api.openai.com/v1/models', {
                        headers: { 'Authorization': 'Bearer ' + apiKey }
                    });
                    if (response.ok) {
                        var data = await response.json();
                        return (data.data || [])
                            .filter(function(m) { return m.id.indexOf('gpt-') === 0 && m.id.indexOf('instruct') === -1; })
                            .map(function(m) { return {id: m.id, name: m.id}; })
                            .sort(function(a, b) { return a.id.localeCompare(b.id); });
                    }
                } catch (e) {}
                return [];
            }

            function normalizeEndpoint(endpoint) {
                endpoint = endpoint.trim().replace(/\/$/, '');
                if (endpoint && !endpoint.match(/^https?:\/\//)) {
                    endpoint = 'http://' + endpoint;
                }
                return endpoint;
            }

            async function fetchLocalModels(endpoint) {
                endpoint = normalizeEndpoint(endpoint);
                if (!endpoint) return [];
                // Try OpenAI-compatible endpoint first (works with LM Studio, Ollama, etc.)
                try {
                    var response = await fetch(endpoint + '/v1/models');
                    if (response.ok) {
                        var data = await response.json();
                        if (data.data && data.data.length > 0) {
                            return data.data.map(function(m) { return {id: m.id, name: m.id}; });
                        }
                    }
                } catch (e) {}
                // Fall back to Ollama-specific endpoint
                try {
                    var response = await fetch(endpoint + '/api/tags');
                    if (response.ok) {
                        var data = await response.json();
                        if (data.models && data.models.length > 0) {
                            return data.models.map(function(m) { return {id: m.name, name: m.name}; });
                        }
                    }
                } catch (e) {}
                return [];
            }

            // Auto-detect local LLM endpoint
            async function autoDetectLocalEndpoint() {
                var endpoints = [
                    { url: 'http://localhost:11434', name: 'Ollama', test: '/api/tags' },
                    { url: 'http://127.0.0.1:11434', name: 'Ollama', test: '/api/tags' },
                    { url: 'http://localhost:1234', name: 'LM Studio', test: '/v1/models' },
                    { url: 'http://127.0.0.1:1234', name: 'LM Studio', test: '/v1/models' }
                ];

                for (var i = 0; i < endpoints.length; i++) {
                    var ep = endpoints[i];
                    try {
                        var controller = new AbortController();
                        var timeout = setTimeout(function() { controller.abort(); }, 2000);
                        var response = await fetch(ep.url + ep.test, { signal: controller.signal });
                        clearTimeout(timeout);
                        if (response.ok) {
                            return { url: ep.url, name: ep.name };
                        }
                    } catch (e) {}
                }
                return null;
            }

            // Test connection
            $('.ai-test-connection').on('click', async function() {
                var $btn = $(this);
                var $status = $btn.siblings('.ai-connection-status');
                var provider = $btn.data('provider');

                $status.text('<?php echo esc_js(__('Testing...', 'ai-assistant')); ?>').removeClass('success error');

                var success = false;
                var message = '';

                if (provider === 'anthropic') {
                    var apiKey = getFieldValue($('#ai_anthropic_key'));
                    if (!apiKey) { $status.text('<?php echo esc_js(__('Enter API key first', 'ai-assistant')); ?>').addClass('error'); return; }
                    try {
                        var response = await fetch('https://api.anthropic.com/v1/models', {
                            headers: { 'x-api-key': apiKey, 'anthropic-version': '2023-06-01', 'anthropic-dangerous-direct-browser-access': 'true' }
                        });
                        success = response.ok;
                        message = success ? '<?php echo esc_js(__('Connected!', 'ai-assistant')); ?>' : '<?php echo esc_js(__('Invalid API key', 'ai-assistant')); ?>';
                    } catch (e) { message = e.message; }
                } else if (provider === 'openai') {
                    var apiKey = getFieldValue($('#ai_openai_key'));
                    if (!apiKey) { $status.text('<?php echo esc_js(__('Enter API key first', 'ai-assistant')); ?>').addClass('error'); return; }
                    try {
                        var response = await fetch('https://api.openai.com/v1/models', {
                            headers: { 'Authorization': 'Bearer ' + apiKey }
                        });
                        success = response.ok;
                        message = success ? '<?php echo esc_js(__('Connected!', 'ai-assistant')); ?>' : '<?php echo esc_js(__('Invalid API key', 'ai-assistant')); ?>';
                    } catch (e) { message = e.message; }
                } else if (provider === 'local') {
                    var endpoint = normalizeEndpoint($('#ai_local_endpoint').val() || 'localhost:11434');
                    var serverType = '';
                    // Try Ollama endpoint
                    try {
                        var response = await fetch(endpoint + '/api/tags');
                        if (response.ok) {
                            var data = await response.json();
                            if (data.models) {
                                success = true;
                                serverType = 'Ollama';
                            }
                        }
                    } catch (e) {}
                    // Try OpenAI-compatible endpoint (LM Studio, etc.)
                    if (!success) {
                        try {
                            var response = await fetch(endpoint + '/v1/models');
                            if (response.ok) {
                                var data = await response.json();
                                if (data.data) {
                                    success = true;
                                    serverType = 'LM Studio';
                                }
                            }
                        } catch (e) { console.error('Local LLM connection test failed (LM Studio):', e); }
                    }
                    // If both failed, check if it's a CORS issue vs server not running
                    var isCors = false;
                    if (!success) {
                        try {
                            await fetch(endpoint, { mode: 'no-cors' });
                            // Chrome: opaque response means server is reachable but blocking CORS
                            isCors = true;
                        } catch (e) {
                            // Firefox rejects no-cors fetches even when CORS is the issue.
                            // Fall back to origin check — less precise, but better than no hint.
                            try {
                                isCors = new URL(endpoint).origin !== window.location.origin;
                            } catch (urlError) {}
                        }
                    }
                    if (success) {
                        message = '<?php echo esc_js(__('Connected to', 'ai-assistant')); ?> ' + serverType + '!';
                    } else if (isCors) {
                        message = '<strong><?php echo esc_js(__('Could not connect.', 'ai-assistant')); ?></strong> '
                            + '<?php echo esc_js(__('Your origin can\'t be accessed. To enable cross-origin requests follow', 'ai-assistant')); ?> '
                            + '<a href="https://docs.ollama.com/faq#how-can-i-allow-additional-web-origins-to-access-ollama" target="_blank"><?php echo esc_js(__('these instructions for Ollama', 'ai-assistant')); ?></a>, '
                            + '<?php echo esc_js(__('or', 'ai-assistant')); ?> '
                            + '<a href="https://lmstudio.ai/docs/developer/core/server/settings#settings-information" target="_blank"><?php echo esc_js(__('these instructions for LM Studio', 'ai-assistant')); ?></a>.';
                    } else {
                        message = '<strong><?php echo esc_js(__('Could not connect.', 'ai-assistant')); ?></strong> <?php echo esc_js(__('Check the URL and ensure your local LLM server is running.', 'ai-assistant')); ?>';
                    }
                }

                $status.html(message).addClass(success ? 'success' : 'error');
                if (success) { loadModels(); }
            });

            // Refresh models button
            $('#ai-refresh-models').on('click', function() { loadModels(); });

            $('#ai_model').on('change', function() {
                persistModelSelection($('#ai_provider').val(), $(this).val());
            });

            // Reload models when API key changes
            $('#ai_anthropic_key, #ai_openai_key, #ai_local_endpoint').on('change', function() { loadModels(); });

            $('.ai-api-key-input')
                .on('focus', function() {
                    var $el = $(this);
                    $el.val($el.data('actualValue') || '').removeClass('ai-api-key-masked');
                })
                .on('input', function() {
                    var $el = $(this);
                    if (!$el.hasClass('ai-api-key-masked')) {
                        $el.data('actualValue', $el.val());
                    }
                })
                .on('blur', function() {
                    var $el = $(this);
                    setApiKeyInputValue($el, $el.val());
                });

            // Initialize
            loadSettings();
        });
        </script>
        <?php
    }

    /**
     * Render notice when WordPress 7.0 Connectors API is available.
     */
    private function render_connectors_notice() {
        $connectors_url = admin_url('options-connectors.php');
        $bridge = ai_assistant()->connectors_bridge();
        $config = $bridge ? $bridge->get_providers_config() : ['available' => []];
        $configured_count = 0;
        if (!empty($config['available'])) {
            foreach ($config['available'] as $provider) {
                if ((!empty($provider['serverSideAuth']) || $provider['type'] === 'server') && !empty($provider['models'])) {
                    $configured_count++;
                }
            }
        }
        ?>
        <div class="ai-connectors-notice">
            <p>
                <?php
                printf(
                    /* translators: %s: link to Connectors settings page */
                    esc_html__('LLM providers are managed through %s. Drag to reorder, then choose the model to use for each provider.', 'ai-assistant'),
                    '<a href="' . esc_url($connectors_url) . '">' . esc_html__('WordPress Connectors', 'ai-assistant') . '</a>'
                );
                ?>
            </p>
            <?php if ($configured_count > 0) : ?>
                <ul id="ai-provider-priority" class="ai-provider-priority">
                </ul>
                <p class="description"><?php esc_html_e('Priority order and model choices are stored per-browser.', 'ai-assistant'); ?>
                <?php if ($config['hasLocal'] ?? false) : ?>
                    <?php esc_html_e('Local providers connect directly from your browser.', 'ai-assistant'); ?>
                <?php endif; ?>
                </p>
                <style>
                    .ai-provider-priority { max-width: 760px; margin: 10px 0; padding: 0; list-style: none; }
                    .ai-provider-priority li {
                        display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px;
                        padding: 8px 12px; margin: 4px 0;
                        background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
                        cursor: grab; user-select: none;
                    }
                    .ai-provider-priority li:active { cursor: grabbing; }
                    .ai-provider-priority li.dragging { opacity: 0.5; }
                    .ai-provider-priority .ai-priority-handle { color: #999; }
                    .ai-provider-priority .ai-priority-main {
                        display: flex; align-items: center; gap: 8px;
                        flex: 1 1 230px; min-width: 200px;
                    }
                    .ai-provider-priority .ai-priority-name { font-weight: 500; }
                    .ai-provider-priority .ai-priority-models { color: #646970; font-size: 13px; }
                    .ai-provider-priority .ai-priority-model-control {
                        flex: 0 1 340px; margin: 0;
                    }
                    .ai-provider-priority .ai-priority-model-select {
                        width: 100%; max-width: 340px;
                    }
                    .ai-provider-priority .ai-priority-status {
                        font-size: 12px; padding: 1px 6px;
                        border-radius: 3px;
                    }
                    .ai-priority-status.available { color: #00a32a; background: #edfaef; }
                    .ai-priority-status.unavailable { color: #996800; background: #fcf0e3; }
                </style>
                <script>
                jQuery(function($) {
                    var STORAGE_PREFIX = 'aiAssistant_';
                    var DEFAULT_MODELS = {
                        anthropic: 'claude-sonnet-4-6',
                        openai: 'gpt-5.5'
                    };
                    var PREFERRED_MODELS = {
                        anthropic: [
                            'claude-sonnet-4-6',
                            'claude-sonnet-4-5-20250929',
                            'claude-sonnet-4-20250514'
                        ],
                        openai: [
                            'gpt-5.5',
                            'gpt-5.4',
                            'gpt-5.4-mini',
                            'gpt-5.1',
                            'gpt-5',
                            'gpt-4.1',
                            'gpt-4o'
                        ]
                    };
                    var providers = <?php echo wp_json_encode(array_map(function($id, $p) {
                        return [
                            'id' => $id,
                            'name' => $p['name'],
                            'type' => $p['type'],
                            'models' => array_values((array) ($p['models'] ?? [])),
                            'modelCount' => count((array) ($p['models'] ?? [])),
                            'available' => (!empty($p['serverSideAuth']) || $p['type'] === 'server') && !empty($p['models']),
                        ];
                    }, array_keys($config['available']), array_values($config['available']))); ?>;

                    function normalizeModelId(model) {
                        return String(model || '').trim();
                    }

                    function getSetting(key) {
                        try {
                            return localStorage.getItem(STORAGE_PREFIX + key) || '';
                        } catch (e) {
                            return '';
                        }
                    }

                    function setSetting(key, value) {
                        try {
                            if (value) {
                                localStorage.setItem(STORAGE_PREFIX + key, value);
                            } else {
                                localStorage.removeItem(STORAGE_PREFIX + key);
                            }
                        } catch (e) {}
                    }

                    function getModelSettingKey(provider) {
                        provider = normalizeModelId(provider);
                        return provider ? 'model_' + provider : 'model';
                    }

                    function hasModel(models, id) {
                        id = normalizeModelId(id);
                        if (!id || !Array.isArray(models)) return false;

                        return models.some(function(model) {
                            return normalizeModelId(model && model.id) === id;
                        });
                    }

                    function getRecommendedModel(provider, models) {
                        models = Array.isArray(models) ? models : [];
                        var preferred = PREFERRED_MODELS[provider] || [];

                        for (var i = 0; i < preferred.length; i++) {
                            if (hasModel(models, preferred[i])) {
                                return preferred[i];
                            }
                        }

                        if (DEFAULT_MODELS[provider] && hasModel(models, DEFAULT_MODELS[provider])) {
                            return DEFAULT_MODELS[provider];
                        }

                        return models.length > 0 ? normalizeModelId(models[0].id) : (DEFAULT_MODELS[provider] || '');
                    }

                    function getStoredModel(provider) {
                        var models = provider.models || [];
                        var providerModel = getSetting(getModelSettingKey(provider.id));
                        if (hasModel(models, providerModel)) {
                            return providerModel;
                        }

                        if (provider.id === 'local') {
                            var localModel = getSetting('localModel');
                            if (hasModel(models, localModel)) {
                                return localModel;
                            }
                        }

                        var legacyModel = getSetting('model');
                        if (hasModel(models, legacyModel)) {
                            return legacyModel;
                        }

                        return getRecommendedModel(provider.id, models);
                    }

                    function persistModelSelection(provider, model) {
                        setSetting(getModelSettingKey(provider), model);
                        setSetting('model', model);
                        if (provider === 'local') {
                            setSetting('localModel', model);
                        }
                    }

                    // Load saved priority
                    var savedPriority = null;
                    try {
                        var stored = localStorage.getItem(STORAGE_PREFIX + 'providerPriority');
                        if (stored) savedPriority = JSON.parse(stored);
                    } catch (e) {}

                    // Sort providers by saved priority
                    if (savedPriority && Array.isArray(savedPriority)) {
                        providers.sort(function(a, b) {
                            var ai = savedPriority.indexOf(a.id);
                            var bi = savedPriority.indexOf(b.id);
                            if (ai === -1) ai = 999;
                            if (bi === -1) bi = 999;
                            return ai - bi;
                        });
                    }

                    var $list = $('#ai-provider-priority');

                    function render() {
                        $list.empty();
                        providers.forEach(function(p) {
                            var statusClass = p.available ? 'available' : 'unavailable';
                            var statusText;
                            if (p.modelCount === 0) {
                                statusText = '<?php echo esc_js(__('no models', 'ai-assistant')); ?>';
                            } else if (!p.available) {
                                statusText = '<?php echo esc_js(__('no key', 'ai-assistant')); ?>';
                            } else if (p.type === 'server') {
                                statusText = '<?php echo esc_js(__('local', 'ai-assistant')); ?>';
                            } else {
                                statusText = '<?php echo esc_js(__('ready', 'ai-assistant')); ?>';
                            }
                            var modelsText = p.modelCount === 1
                                ? '<?php echo esc_js(__('1 model', 'ai-assistant')); ?>'
                                : p.modelCount + ' <?php echo esc_js(__('models', 'ai-assistant')); ?>';
                            var selectedModel = getStoredModel(p);
                            var $li = $('<li>').attr('data-provider', p.id).attr('draggable', 'true');
                            var $main = $('<span>').addClass('ai-priority-main');
                            var $modelControl = $('<label>').addClass('ai-priority-model-control');
                            var $modelSelect = $('<select>')
                                .addClass('ai-priority-model-select')
                                .attr('data-provider', p.id)
                                .prop('disabled', !p.available || p.modelCount === 0);

                            $main
                                .append($('<span>').addClass('ai-priority-handle').html('&#9776;'))
                                .append($('<span>').addClass('ai-priority-name').text(p.name))
                                .append($('<span>').addClass('ai-priority-models').text(modelsText))
                                .append($('<span>').addClass('ai-priority-status ' + statusClass).text(statusText));

                            $modelControl.append(
                                $('<span>').addClass('screen-reader-text').text('<?php echo esc_js(__('Model', 'ai-assistant')); ?>: ' + p.name)
                            );

                            if (p.modelCount === 0) {
                                $('<option>')
                                    .val('')
                                    .text('<?php echo esc_js(__('No models available', 'ai-assistant')); ?>')
                                    .appendTo($modelSelect);
                            } else {
                                p.models.forEach(function(model) {
                                    var id = normalizeModelId(model.id);
                                    $('<option>')
                                        .val(id)
                                        .text(model.name || id)
                                        .prop('selected', id === selectedModel)
                                        .appendTo($modelSelect);
                                });
                            }

                            $modelControl.append($modelSelect);
                            $li.append($main).append($modelControl);
                            $list.append($li);
                        });
                    }

                    function savePriority() {
                        var order = [];
                        $list.children('li').each(function() {
                            order.push($(this).data('provider'));
                        });
                        localStorage.setItem(STORAGE_PREFIX + 'providerPriority', JSON.stringify(order));
                        // Clear the direct provider override so priority takes effect
                        localStorage.removeItem(STORAGE_PREFIX + 'provider');
                    }

                    render();

                    $list.on('change', '.ai-priority-model-select', function() {
                        persistModelSelection($(this).data('provider'), $(this).val());
                    });

                    // Drag-and-drop reordering
                    var dragEl = null;
                    $list.on('dragstart', 'li', function(e) {
                        if ($(e.target).is('select, option')) {
                            return false;
                        }
                        dragEl = this;
                        $(this).addClass('dragging');
                        e.originalEvent.dataTransfer.effectAllowed = 'move';
                    });
                    $list.on('dragend', 'li', function() {
                        $(this).removeClass('dragging');
                        dragEl = null;
                    });
                    $list.on('dragover', 'li', function(e) {
                        e.preventDefault();
                        e.originalEvent.dataTransfer.dropEffect = 'move';
                        var $target = $(this);
                        if (dragEl && this !== dragEl) {
                            var $dragging = $(dragEl);
                            var targetRect = this.getBoundingClientRect();
                            var midY = targetRect.top + targetRect.height / 2;
                            if (e.originalEvent.clientY < midY) {
                                $dragging.insertBefore($target);
                            } else {
                                $dragging.insertAfter($target);
                            }
                        }
                    });
                    $list.on('drop', 'li', function(e) {
                        e.preventDefault();
                        savePriority();
                    });
                });
                </script>
            <?php else : ?>
                <?php
                $registered_count = count($config['available']);
                $browser_supported = array_filter($config['available'], function($p) {
                    return !empty($p['browserSupported']);
                });
                ?>
                <?php if ($registered_count > 0 && count($browser_supported) > 0) : ?>
                    <p class="description">
                        <?php
                        printf(
                            esc_html__('%1$d provider plugin(s) found but none have API keys configured. Visit %2$s to enter your credentials.', 'ai-assistant'),
                            $registered_count,
                            '<a href="' . esc_url($connectors_url) . '">' . esc_html__('Settings &rarr; Connectors', 'ai-assistant') . '</a>'
                        );
                        ?>
                    </p>
                <?php elseif ($registered_count > 0) : ?>
                    <p class="description">
                        <?php
                        printf(
                            esc_html__('Provider plugins are installed but none are supported for browser-direct calls yet. Visit %s to check your configuration.', 'ai-assistant'),
                            '<a href="' . esc_url($connectors_url) . '">' . esc_html__('Settings &rarr; Connectors', 'ai-assistant') . '</a>'
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p class="description">
                        <?php
                        printf(
                            esc_html__('No AI provider plugins are installed. Visit %s to install and configure a provider (e.g. Anthropic, OpenAI).', 'ai-assistant'),
                            '<a href="' . esc_url($connectors_url) . '">' . esc_html__('Settings &rarr; Connectors', 'ai-assistant') . '</a>'
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (current_user_can('manage_options') && !empty($config['debug'])) : ?>
                <details style="margin-top: 1em;">
                    <summary style="cursor: pointer; color: #646970;"><?php esc_html_e('Debug: Connectors Bridge', 'ai-assistant'); ?></summary>
                    <pre style="background: #f0f0f1; padding: 10px; margin-top: 5px; font-size: 12px; overflow: auto; max-height: 400px;"><?php echo esc_html(wp_json_encode($config['debug'], JSON_PRETTY_PRINT)); ?></pre>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    public function tools_section_callback() {
        $is_playground = ai_assistant_is_playground();

        $all_tools = $this->get_all_tools_with_meta();
        $stored_enabled = (array) get_option('ai_assistant_enabled_tools', $this->get_default_enabled_tools());
        $enabled = $is_playground
            ? array_keys($all_tools)
            : $stored_enabled;

        $by_group = [];
        foreach ($all_tools as $name => $meta) {
            $by_group[$meta['group']][$name] = $meta;
        }
        $group_tabs = [];
        foreach (array_keys($by_group) as $group) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($group)), '-');
            $group_tabs[$group] = 'ai-tool-tab-' . ($slug ?: 'group');
        }
        $first_group = key($by_group);
        ?>
        <div class="ai-collapsible-content" data-section="tools">
            <?php if ($is_playground) : ?>
            <p><?php esc_html_e('All tools are automatically enabled in Playground. The controls below are read-only so you can inspect registered tools, abilities, and schemas.', 'ai-assistant'); ?></p>
            <?php foreach ($stored_enabled as $tool_name) : ?>
            <input type="hidden" name="ai_assistant_enabled_tools[]" value="<?php echo esc_attr($tool_name); ?>">
            <?php endforeach; ?>
            <?php else : ?>
            <p><?php esc_html_e('Choose which tools the AI can use. ⚠ tools can modify files, run code, or install plugins.', 'ai-assistant'); ?></p>
            <input type="hidden" name="ai_assistant_enabled_tools" value="">
            <?php endif; ?>
            <div class="ai-tool-tree">
                <div class="ai-tool-tabs" role="tablist" aria-label="<?php esc_attr_e('Tool permission groups', 'ai-assistant'); ?>">
                    <?php foreach ($group_tabs as $group => $tab_id) :
                        $is_active = $group === $first_group;
                    ?>
                    <button type="button"
                            id="<?php echo esc_attr($tab_id . '-button'); ?>"
                            class="<?php echo esc_attr('ai-tool-tab' . ($is_active ? ' active' : '')); ?>"
                            role="tab"
                            aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                            aria-controls="<?php echo esc_attr($tab_id); ?>"
                            tabindex="<?php echo $is_active ? '0' : '-1'; ?>"
                            data-tool-tab="<?php echo esc_attr($tab_id); ?>">
                        <?php echo esc_html($group); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="ai-tool-tab-panels">
                <?php foreach ($by_group as $group => $tools) :
                    $group_names = array_keys($tools);
                    $all_checked = count(array_filter($group_names, fn($n) => in_array($n, $enabled, true))) === count($group_names);
                    $some_checked = !$all_checked && count(array_filter($group_names, fn($n) => in_array($n, $enabled, true))) > 0;
                    $tab_id = $group_tabs[$group];
                    $is_active = $group === $first_group;
                ?>
                <div id="<?php echo esc_attr($tab_id); ?>"
                     class="<?php echo esc_attr('ai-tool-tab-panel' . ($is_active ? ' active' : '')); ?>"
	                     role="tabpanel"
	                     aria-labelledby="<?php echo esc_attr($tab_id . '-button'); ?>"
	                     <?php if (!$is_active) echo 'hidden'; ?>>
                <?php if ($group === 'Abilities') : ?>
                <div class="ai-abilities-tab-layout">
                    <div class="ai-abilities-main">
                <?php endif; ?>
                <div class="<?php echo esc_attr('ai-tool-group' . ($group === 'Abilities' ? ' ai-tool-group-abilities' : '')); ?>">
                    <div class="ai-tool-group-header-row">
                        <label class="ai-tool-group-header">
                            <input type="checkbox" class="ai-group-toggle"
                                   <?php checked($all_checked); ?>
                                   <?php disabled($is_playground); ?>
                                   <?php if ($some_checked) echo 'data-indeterminate="1"'; ?>>
                            <span><?php echo esc_html($group === 'Abilities' ? __('Enable all ability tool actions', 'ai-assistant') : __('Enable all tools in this tab', 'ai-assistant')); ?></span>
                        </label>
                    </div>
                    <div class="ai-tool-group-items">
                        <?php foreach ($tools as $name => $meta) : ?>
                        <label class="ai-tool-item">
                            <input type="checkbox"
                                   name="ai_assistant_enabled_tools[]"
                                   value="<?php echo esc_attr($name); ?>"
                                   <?php checked(in_array($name, $enabled, true)); ?>
                                   <?php disabled($is_playground); ?>>
                            <code><?php echo esc_html($name); ?></code>
                            <?php if ($meta['dangerous']) : ?>
                                <span title="<?php esc_attr_e('Dangerous: can modify data or execute code', 'ai-assistant'); ?>">⚠</span>
                            <?php endif; ?>
                        </label>
                        <?php if ($name === 'rest_api') :
                            $auto_approved_rest = $this->get_auto_approved_rest_apis();
                            $hidden = !in_array('rest_api', $enabled, true) ? ' style="display:none"' : '';
                            // Group approved patterns by method
                            $by_method = [];
                            foreach ($auto_approved_rest as $pattern) {
                                $parts = explode(' ', $pattern, 2);
                                if (count($parts) === 2) {
                                    $by_method[$parts[0]][] = $parts[1];
                                }
                            }
                            ?>
                        <div class="ai-tool-sub-items"<?php echo $hidden; ?>>
                            <p class="description ai-tool-sub-note"><?php esc_html_e('GET and OPTIONS requests do not ask for approval. Saved write routes below are auto-approved; uncheck a route and save to restore approve prompts.', 'ai-assistant'); ?></p>
                            <input type="hidden" name="ai_assistant_auto_approved_rest_apis" value="">
                            <?php foreach (['GET', 'OPTIONS'] as $safe_method) : ?>
                            <div class="ai-ability-category"><?php echo esc_html($safe_method); ?></div>
                            <div class="ai-tool-sub-item">
                                <input type="checkbox" disabled checked>
                                <span class="description"><?php esc_html_e('Always allowed', 'ai-assistant'); ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) : ?>
                            <div class="ai-ability-category"><?php echo esc_html($method); ?></div>
                            <?php if (!empty($by_method[$method])) : ?>
                                <?php foreach ($by_method[$method] as $path) :
                                    $pattern = $method . ' ' . $path; ?>
                                <div class="ai-tool-sub-item">
                                    <input type="checkbox"
                                           name="ai_assistant_auto_approved_rest_apis[]"
                                           value="<?php echo esc_attr($pattern); ?>"
                                           checked>
                                    <code><?php echo esc_html($path); ?></code>
                                    <span class="description"><?php esc_html_e('Auto-approve', 'ai-assistant'); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($name === 'execute_ability') :
                            $auto_approved = $this->get_auto_approved_abilities();
                            $hidden = !in_array('execute_ability', $enabled, true) ? ' style="display:none"' : ''; ?>
                        <div class="ai-tool-sub-items"<?php echo $hidden; ?>>
                            <p class="description ai-tool-sub-note"><?php esc_html_e('These are not visibility controls. When List Abilities is enabled, the AI can discover all registered abilities. Auto-approve only decides whether execution skips the approve prompt.', 'ai-assistant'); ?></p>
                            <input type="hidden" name="ai_assistant_auto_approved_abilities" value="">
                            <?php if (!function_exists('wp_get_abilities')) : ?>
                            <p class="description" style="margin:4px 0"><?php esc_html_e('No abilities available — requires WordPress 6.9+ with the Abilities API.', 'ai-assistant'); ?></p>
                            <?php else :
                                $abilities = wp_get_abilities();
                                if (empty($abilities)) : ?>
                                <p class="description" style="margin:4px 0"><?php esc_html_e('No abilities registered yet. Abilities are exposed by plugins using the WordPress Abilities API.', 'ai-assistant'); ?></p>
                                <?php else :
                                    // Group by category
                                    $by_cat = [];
                                    foreach ($abilities as $id => $ability) {
                                        $ability_id = Ability_Annotations::get_ability_id($id, $ability);
                                        $details_payload = Ability_Annotations::get_details(
                                            $id,
                                            $ability,
                                            in_array($ability_id, $auto_approved, true)
                                        );
                                        $details_payload['ability_id'] = $details_payload['id'];
                                        $by_cat[$details_payload['category']][] = $details_payload;
                                    }
                                    ?>
	                                    <div class="ai-abilities-list">
	                                            <?php foreach ($by_cat as $category => $cat_abilities) : ?>
	                                            <?php if ($category) : ?>
	                                            <div class="ai-ability-category"><?php echo esc_html($category); ?></div>
                                            <?php endif; ?>
                                            <?php foreach ($cat_abilities as $a) :
                                                $is_approved = !empty($a['approved']);
                                            ?>
                                            <div class="ai-tool-sub-item ai-ability-row">
                                                <button type="button"
                                                        class="ai-ability-select"
                                                        data-ability-details="<?php echo esc_attr($this->encode_json($a)); ?>">
                                                    <code><?php echo esc_html($a['ability_id']); ?></code>
                                                    <?php if ($a['readonly']) : ?>
                                                        <span class="ai-ability-badge ai-ability-badge-readonly"><?php esc_html_e('Read-only', 'ai-assistant'); ?></span>
                                                    <?php else : ?>
                                                    <?php if ($a['destructive']) : ?>
                                                        <span class="ai-ability-badge ai-ability-badge-destructive"><?php esc_html_e('Destructive', 'ai-assistant'); ?></span>
                                                    <?php endif; ?>
                                                    <?php endif; ?>
                                                </button>
                                                <?php if ($a['readonly']) : ?>
                                                    <span class="description ai-ability-auto-approve-note"><?php esc_html_e('No prompt', 'ai-assistant'); ?></span>
                                                <?php else : ?>
                                                    <label class="ai-ability-auto-approve-control">
                                                        <input type="checkbox"
                                                               class="ai-ability-auto-approve-input"
                                                               name="ai_assistant_auto_approved_abilities[]"
                                                               value="<?php echo esc_attr($a['ability_id']); ?>"
                                                               <?php checked($is_approved); ?>>
                                                        <span><?php esc_html_e('Auto-approve', 'ai-assistant'); ?></span>
                                                    </label>
                                                <?php endif; ?>
                                            </div>
	                                            <?php endforeach; ?>
	                                            <?php endforeach; ?>
	                                    </div>
	                                <?php endif; ?>
	                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
	                    </div>
	                </div>
                <?php if ($group === 'Abilities') : ?>
                    </div>
                    <aside class="ai-ability-info-panel" aria-live="polite">
                        <div class="ai-ability-info-empty">
                            <?php esc_html_e('Select an ability to view its description and parameters.', 'ai-assistant'); ?>
                        </div>
                    </aside>
                </div>
                <?php endif; ?>
	                </div>
	                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <script>
        jQuery(function($) {
            var abilitySampleConfig = {
                ajaxUrl: <?php echo $this->encode_json(admin_url('admin-ajax.php')); ?>,
                nonce: <?php echo $this->encode_json(wp_create_nonce('ai_assistant_sample_ability')); ?>,
                sampleLabel: '<?php echo esc_js(__('View', 'ai-assistant')); ?>',
                loadingLabel: '<?php echo esc_js(__('Loading...', 'ai-assistant')); ?>'
            };

            // Sync group toggle with children
            $('.ai-group-toggle').each(function() {
                if ($(this).data('indeterminate')) this.indeterminate = true;
            });

            function syncToolSubItems($scope) {
                $scope.find('input[name="ai_assistant_enabled_tools[]"][value="execute_ability"], input[name="ai_assistant_enabled_tools[]"][value="rest_api"]').each(function() {
                    $(this).closest('.ai-tool-item').next('.ai-tool-sub-items').toggle(this.checked);
                });
            }

            function updateGroupToggle($group) {
                var $children = $group.find('.ai-tool-group-items > label > input[type=checkbox]');
                var checkedCount = $children.filter(':checked').length;
                var $toggle = $group.find('.ai-group-toggle')[0];

                if (!$toggle) {
                    return;
                }

                $toggle.checked = checkedCount === $children.length;
                $toggle.indeterminate = checkedCount > 0 && checkedCount < $children.length;
            }

            function updateGroupControls($group) {
                updateGroupToggle($group);
            }

            $('.ai-group-toggle').on('change', function() {
                var checked = this.checked;
                var $group = $(this).closest('.ai-tool-group');
                $group.find('.ai-tool-group-items > label > input[type=checkbox]').prop('checked', checked);
                syncToolSubItems($group);
                this.indeterminate = false;
            });
            // Update group toggle when child changes
            $(document).on('change', '.ai-tool-group-items input[type=checkbox]', function() {
                updateGroupControls($(this).closest('.ai-tool-group'));
            });
            // Toggle sub-items visibility
            $(document).on('change', 'input[name="ai_assistant_enabled_tools[]"][value="execute_ability"]', function() {
                syncToolSubItems($(this).closest('.ai-tool-group'));
            });
            $(document).on('change', 'input[name="ai_assistant_enabled_tools[]"][value="rest_api"]', function() {
                syncToolSubItems($(this).closest('.ai-tool-group'));
            });

            var toolTabStorageKey = 'aiAssistant_settings_tool_permissions_tab';

            function activateToolTab($tab, remember) {
                var target = $tab.attr('data-tool-tab');
                var $tree = $tab.closest('.ai-tool-tree');
                if (!target || !$tree.length) {
                    return;
                }

                $tree.find('.ai-tool-tab').removeClass('active').attr({
                    'aria-selected': 'false',
                    'tabindex': '-1'
                });
                $tab.addClass('active').attr({
                    'aria-selected': 'true',
                    'tabindex': '0'
                });

                $tree.find('.ai-tool-tab-panel').removeClass('active').attr('hidden', true);
                $tree.find('#' + target).addClass('active').removeAttr('hidden');

                if (remember) {
                    localStorage.setItem(toolTabStorageKey, target);
                }
            }

            $('.ai-tool-tree').each(function() {
                var $tree = $(this);
                var savedTab = localStorage.getItem(toolTabStorageKey);
                var $saved = savedTab ? $tree.find('.ai-tool-tab[data-tool-tab="' + savedTab + '"]') : $();
                if ($saved.length) {
                    activateToolTab($saved, false);
                }
            });

            $(document).on('click', '.ai-tool-tab', function() {
                activateToolTab($(this), true);
            });

            $(document).on('keydown', '.ai-tool-tab', function(e) {
                var keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
                if (keys.indexOf(e.key) === -1) {
                    return;
                }

                e.preventDefault();
                var $tabs = $(this).closest('.ai-tool-tabs').find('.ai-tool-tab');
                var index = $tabs.index(this);
                if (e.key === 'Home') {
                    index = 0;
                } else if (e.key === 'End') {
                    index = $tabs.length - 1;
                } else if (e.key === 'ArrowLeft') {
                    index = index <= 0 ? $tabs.length - 1 : index - 1;
                } else {
                    index = index >= $tabs.length - 1 ? 0 : index + 1;
                }

                activateToolTab($tabs.eq(index), true);
                $tabs.eq(index).trigger('focus');
            });

            function escapeHtml(value) {
                return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
            }

            function parseAbilityDetails($button) {
                try {
                    return JSON.parse($button.attr('data-ability-details') || '{}');
                } catch (e) {
                    return {};
                }
            }

            function renderAbilityBadge(text, className) {
                return '<span class="ai-ability-badge ' + className + '">' + escapeHtml(text) + '</span>';
            }

            function renderAbilityBadges(details) {
                var badges = '';

                if (details.readonly) {
                    badges += renderAbilityBadge('<?php echo esc_js(__('Read-only', 'ai-assistant')); ?>', 'ai-ability-badge-readonly');
                } else if (details.destructive) {
                    badges += renderAbilityBadge('<?php echo esc_js(__('Destructive', 'ai-assistant')); ?>', 'ai-ability-badge-destructive');
                }

                return badges;
            }

            function updateAbilityApprovalState($input) {
                var $row = $input.closest('.ai-ability-row');
                var $button = $row.find('.ai-ability-select');
                var details = parseAbilityDetails($button);

                details.approved = $input.is(':checked');
                $button.attr('data-ability-details', JSON.stringify(details));
                $button.find('.ai-ability-badge').remove();
                $button.append(renderAbilityBadges(details));

                if ($button.hasClass('selected')) {
                    renderAbilityInfo($button);
                }
            }

            function isJsonSampleType(type) {
                type = String(type || '').toLowerCase();
                return type.indexOf('object') !== -1 || type.indexOf('array') === 0;
            }

            function renderSampleField(parameter) {
                var name = parameter.name || '';
                var type = String(parameter.type || 'string').toLowerCase();
                var required = !!parameter.required;
                var html = '';

                html += '<label class="ai-ability-sample-field">';
                html += '<span class="ai-ability-sample-label"><code>' + escapeHtml(name) + '</code>';
                if (required) {
                    html += '<span class="ai-ability-param-required"><?php echo esc_js(__('Required', 'ai-assistant')); ?></span>';
                }
                html += '</span>';

                if (type === 'boolean') {
                    html += '<span class="ai-ability-sample-checkbox">';
                    html += '<input type="checkbox" class="ai-ability-sample-input" data-parameter="' + escapeHtml(name) + '" data-type="' + escapeHtml(type) + '" data-required="' + (required ? '1' : '0') + '">';
                    html += '<span><?php echo esc_js(__('True', 'ai-assistant')); ?></span>';
                    html += '</span>';
                } else if (type === 'integer' || type === 'number') {
                    html += '<input type="number" class="regular-text ai-ability-sample-input" step="' + (type === 'integer' ? '1' : 'any') + '" data-parameter="' + escapeHtml(name) + '" data-type="' + escapeHtml(type) + '" data-required="' + (required ? '1' : '0') + '">';
                } else if (isJsonSampleType(type)) {
                    html += '<textarea class="large-text code ai-ability-sample-input" rows="3" placeholder="' + (type.indexOf('array') === 0 ? '[]' : '{}') + '" data-parameter="' + escapeHtml(name) + '" data-type="' + escapeHtml(type) + '" data-required="' + (required ? '1' : '0') + '"></textarea>';
                } else {
                    html += '<input type="text" class="regular-text ai-ability-sample-input" data-parameter="' + escapeHtml(name) + '" data-type="' + escapeHtml(type) + '" data-required="' + (required ? '1' : '0') + '">';
                }

                html += '</label>';
                return html;
            }

            function hasRequiredParameters(parameters) {
                return parameters.some(function(parameter) {
                    return !!parameter.required;
                });
            }

            function renderParameterList(parameters) {
                var html = '<div class="ai-ability-param-list">';

                parameters.forEach(function(parameter) {
                    html += '<div class="ai-ability-param">';
                    html += '<div class="ai-ability-param-head">';
                    html += '<code>' + escapeHtml(parameter.name || '') + '</code>';
                    html += '<span class="ai-ability-param-type">' + escapeHtml(parameter.type || 'any') + '</span>';
                    if (parameter.required) {
                        html += '<span class="ai-ability-param-required"><?php echo esc_js(__('Required', 'ai-assistant')); ?></span>';
                    }
                    html += '</div>';
                    if (parameter.description) {
                        html += '<div class="description ai-ability-param-description">' + escapeHtml(parameter.description) + '</div>';
                    }
                    if (Array.isArray(parameter.notes) && parameter.notes.length) {
                        html += '<div class="ai-ability-param-notes">' + escapeHtml(parameter.notes.join('; ')) + '</div>';
                    }
                    html += '</div>';
                });

                html += '</div>';
                return html;
            }

            function renderSampleFields(parameters) {
                var html = '<div class="ai-ability-sample-fields">';

                parameters.forEach(function(parameter) {
                    html += renderSampleField(parameter);
                });

                html += '</div>';
                return html;
            }

            function renderAbilityInstructions(details) {
                var annotations = details.annotations || {};
                var instructions = typeof annotations.instructions === 'string' ? annotations.instructions : (details.instructions || '');
                var html = '';

                if (!instructions) {
                    return '';
                }

                html += '<div class="ai-ability-instructions">';
                html += '<div class="ai-ability-params-heading"><?php echo esc_js(__('Instructions for the AI Assistant', 'ai-assistant')); ?></div>';
                html += '<p class="description ai-ability-instructions-text">' + escapeHtml(instructions) + '</p>';
                html += '</div>';

                return html;
            }

            function renderAbilityInfo($button) {
                var details = parseAbilityDetails($button);
                var $layout = $button.closest('.ai-tool-tab-panel');
                var $panel = $layout.find('.ai-ability-info-panel');
                var parameters = Array.isArray(details.parameters) ? details.parameters : [];
                var hasRequiredParams = hasRequiredParameters(parameters);
                var html = '';

                html += '<div class="ai-ability-info-header">';
                html += '<code>' + escapeHtml(details.id || '') + '</code>';
                html += renderAbilityBadges(details);
                html += '</div>';

                if (details.label && details.label !== details.id) {
                    html += '<div class="ai-ability-info-label">' + escapeHtml(details.label) + '</div>';
                }

                if (details.description) {
                    html += '<p class="description ai-ability-info-description">' + escapeHtml(details.description) + '</p>';
                } else {
                    html += '<p class="description ai-ability-info-description"><?php echo esc_js(__('No description provided.', 'ai-assistant')); ?></p>';
                }

                html += renderAbilityInstructions(details);

                if (!details.has_schema) {
                    html += '<div class="ai-ability-params-heading"><?php echo esc_js(__('Parameters', 'ai-assistant')); ?></div>';
                    html += '<p class="description ai-ability-no-params"><?php echo esc_js(__('No parameter schema available.', 'ai-assistant')); ?></p>';
                } else if (!parameters.length) {
                    html += '<div class="ai-ability-params-heading"><?php echo esc_js(__('Parameters', 'ai-assistant')); ?></div>';
                    html += '<p class="description ai-ability-no-params"><?php echo esc_js(__('No parameters.', 'ai-assistant')); ?></p>';
                } else {
                    html += '<details class="ai-ability-param-details" open>';
                    html += '<summary class="ai-ability-params-heading"><?php echo esc_js(__('Parameters', 'ai-assistant')); ?></summary>';
                    html += renderParameterList(parameters);
                    html += '</details>';
                }

                if (details.raw_schema) {
                    html += '<details class="ai-ability-raw-schema">';
                    html += '<summary><?php echo esc_js(__('Raw input schema', 'ai-assistant')); ?></summary>';
                    html += '<pre class="ai-ability-code" data-code-mode="json">' + escapeHtml(details.raw_schema) + '</pre>';
                    html += '</details>';
                }

                if (details.readonly) {
                    html += '<div class="ai-ability-sample" data-ability="' + escapeHtml(details.id || '') + '" data-auto-sample="' + (!hasRequiredParams ? '1' : '0') + '">';
                    if (parameters.length) {
                        html += '<details class="ai-ability-sample-params"' + (hasRequiredParams ? ' open' : '') + '>';
                        html += '<summary><?php echo esc_js(__('Change parameters', 'ai-assistant')); ?></summary>';
                        html += '<div class="ai-ability-sample-row">';
                        html += '<div class="ai-ability-sample-inline">';
                        html += renderSampleFields(parameters);
                        html += '</div>';
                        html += '<div class="ai-ability-sample-actions">';
                        html += '<button type="button" class="button button-small ai-ability-sample-button" data-ability="' + escapeHtml(details.id || '') + '">' + abilitySampleConfig.sampleLabel + '</button>';
                        html += '</div>';
                        html += '</div>';
                        html += '</details>';
                    }
                    html += '<div class="ai-ability-sample-output" aria-live="polite"></div>';
                    html += '</div>';
                }

                $layout.find('.ai-ability-select').removeClass('selected').removeAttr('aria-current');
                $button.addClass('selected').attr('aria-current', 'true');
                $panel.html(html).scrollTop(0);
                highlightCodeBlocks($panel);
                $panel.find('.ai-ability-sample[data-auto-sample="1"]').each(function() {
                    loadAbilitySample($(this), {}, null);
                });
            }

            $(document).on('click', '.ai-ability-select', function() {
                renderAbilityInfo($(this));
            });

            $(document).on('change', '.ai-ability-auto-approve-input', function() {
                updateAbilityApprovalState($(this));
            });

            function formatSampleValue(value) {
                if (typeof value === 'string') {
                    return value;
                }

                try {
                    return JSON.stringify(value, null, 2);
                } catch (e) {
                    return String(value);
                }
            }

            function detectCodeMode(value, code) {
                var trimmed;

                if (typeof value !== 'string') {
                    return 'json';
                }

                trimmed = String(code || '').trim();
                if (!trimmed) {
                    return '';
                }

                if ((trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length - 1) === '}') ||
                    (trimmed.charAt(0) === '[' && trimmed.charAt(trimmed.length - 1) === ']')) {
                    try {
                        JSON.parse(trimmed);
                        return 'json';
                    } catch (e) {}
                }

                return '';
            }

            function highlightCode($pre, code, modeName) {
                $pre.text(code);

                if (!modeName || !window.wp || !wp.CodeMirror || !wp.CodeMirror.runMode) {
                    return;
                }

                try {
                    var modeConfig = modeName === 'json' ? {name: 'javascript', json: true} : modeName;
                    var mode = wp.CodeMirror.getMode({}, modeConfig);
                    $pre.empty().addClass('cm-s-default');
                    wp.CodeMirror.runMode(code, mode, $pre[0]);
                } catch (e) {
                    $pre.text(code);
                }
            }

            function highlightCodeBlocks($scope) {
                $scope.find('.ai-ability-code').each(function() {
                    var $pre = $(this);

                    if ($pre.attr('data-highlighted') === '1') {
                        return;
                    }

                    highlightCode($pre, $pre.text(), $pre.attr('data-code-mode') || '');
                    $pre.attr('data-highlighted', '1');
                });
            }

            function getAjaxErrorMessage(response, fallback) {
                if (response && response.data && response.data.message) {
                    return response.data.message;
                }

                return fallback;
            }

            function renderSampleError($output, message) {
                $output.html('<p class="description ai-ability-sample-error">' + escapeHtml(message) + '</p>');
            }

            function renderSampleResult($output, data) {
                var value = data && Object.prototype.hasOwnProperty.call(data, 'result') ? data.result : data;
                var code = formatSampleValue(value);
                var mode = detectCodeMode(value, code);
                $output.html('<pre class="ai-ability-code"></pre>');
                highlightCode($output.find('pre'), code, mode);
            }

            function loadAbilitySample($sample, sampleArguments, $button) {
                var abilityId = $sample.attr('data-ability') || ($button ? $button.attr('data-ability') : '') || '';
                var $output = $sample.find('.ai-ability-sample-output');

                if (!abilityId) {
                    renderSampleError($output, '<?php echo esc_js(__('Missing ability ID.', 'ai-assistant')); ?>');
                    return;
                }

                if ($button && $button.length) {
                    $button.prop('disabled', true).text(abilitySampleConfig.loadingLabel);
                }
                $output.html('<p class="description">' + abilitySampleConfig.loadingLabel + '</p>');

                $.post(abilitySampleConfig.ajaxUrl, {
                    action: 'ai_assistant_sample_readonly_ability',
                    _wpnonce: abilitySampleConfig.nonce,
                    ability: abilityId,
                    arguments: JSON.stringify(sampleArguments || {})
                }).done(function(response) {
                    if (!response || !response.success) {
                        renderSampleError($output, getAjaxErrorMessage(response, '<?php echo esc_js(__('Unable to load sample output.', 'ai-assistant')); ?>'));
                        return;
                    }

                    renderSampleResult($output, response.data);
                }).fail(function(xhr) {
                    var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                    renderSampleError($output, getAjaxErrorMessage(response, '<?php echo esc_js(__('Unable to load sample output.', 'ai-assistant')); ?>'));
                }).always(function() {
                    if ($button && $button.length) {
                        $button.prop('disabled', false).text(abilitySampleConfig.sampleLabel);
                    }
                });
            }

            function collectSampleArguments($sample) {
                var args = {};
                var errors = [];

                $sample.find('.ai-ability-sample-input').each(function() {
                    var $field = $(this);
                    var name = String($field.attr('data-parameter') || '');
                    var type = String($field.attr('data-type') || '').toLowerCase();
                    var required = $field.attr('data-required') === '1';
                    var raw;
                    var value;

                    if (!name) {
                        return;
                    }

                    if ($field.attr('type') === 'checkbox') {
                        if (required || $field.prop('checked')) {
                            args[name] = $field.prop('checked');
                        }
                        return;
                    }

                    raw = String($field.val() || '').trim();
                    if (raw === '') {
                        if (required) {
                            errors.push(name + ' <?php echo esc_js(__('is required.', 'ai-assistant')); ?>');
                        }
                        return;
                    }

                    if (type === 'integer' || type === 'number') {
                        value = Number(raw);
                        if (!isFinite(value) || (type === 'integer' && Math.floor(value) !== value)) {
                            errors.push(name + ' <?php echo esc_js(__('must be a valid number.', 'ai-assistant')); ?>');
                            return;
                        }
                    } else if (isJsonSampleType(type)) {
                        try {
                            value = JSON.parse(raw);
                        } catch (e) {
                            errors.push(name + ' <?php echo esc_js(__('must be valid JSON.', 'ai-assistant')); ?>');
                            return;
                        }
                        if (type.indexOf('array') === 0 && !Array.isArray(value)) {
                            errors.push(name + ' <?php echo esc_js(__('must be a JSON array.', 'ai-assistant')); ?>');
                            return;
                        }
                        if (type.indexOf('object') !== -1 && (value === null || Array.isArray(value) || typeof value !== 'object')) {
                            errors.push(name + ' <?php echo esc_js(__('must be a JSON object.', 'ai-assistant')); ?>');
                            return;
                        }
                    } else {
                        value = raw;
                    }

                    args[name] = value;
                });

                if (errors.length) {
                    throw new Error(errors.join(' '));
                }

                return args;
            }

            $(document).on('click', '.ai-ability-sample-button', function() {
                var $button = $(this);
                var $sample = $button.closest('.ai-ability-sample');
                var $output = $sample.find('.ai-ability-sample-output');
                var sampleArguments;

                try {
                    sampleArguments = collectSampleArguments($sample);
                } catch (e) {
                    renderSampleError($output, e.message || '<?php echo esc_js(__('Check the sample input fields.', 'ai-assistant')); ?>');
                    return;
                }

                loadAbilitySample($sample, sampleArguments, $button);
            });
        });
        </script>
        <?php
    }

    private function encode_json($value, int $flags = 0): string {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value, $flags) : json_encode($value, $flags);
        return is_string($json) ? $json : '';
    }

    /**
     * Permissions section - read-only display of capabilities
     */
    public function permissions_section_callback() {
        $roles = wp_roles()->roles;
        $caps = [
            'ai_assistant_full' => __('Full Access', 'ai-assistant'),
            'ai_assistant_read_only' => __('Read Only', 'ai-assistant'),
            'ai_assistant_chat_only' => __('Chat Only', 'ai-assistant'),
        ];
        ?>
        <div class="ai-collapsible-content" data-section="permissions">
            <p><?php esc_html_e('AI Assistant access is controlled via WordPress capabilities. The following shows the current capability assigned to each role.', 'ai-assistant'); ?></p>
            <table class="wp-list-table widefat fixed striped" style="max-width: 500px;">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Role', 'ai-assistant'); ?></th>
                        <th scope="col"><?php esc_html_e('Capability', 'ai-assistant'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role_slug => $role_data) :
                        $role_obj = get_role($role_slug);
                        $current_cap = __('No Access', 'ai-assistant');
                        if ($role_obj) {
                            foreach ($caps as $cap => $label) {
                                if ($role_obj->has_cap($cap)) {
                                    $current_cap = $label;
                                    break;
                                }
                            }
                        }
                    ?>
                        <tr>
                            <td><?php echo esc_html($role_data['name']); ?></td>
                            <td><?php echo esc_html($current_cap); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                <?php esc_html_e('Full Access: All enabled tools including file writes, PHP execution, and plugin installation. Read Only: Non-dangerous enabled tools plus abilities annotated read-only; REST API write requests are additionally enforced by WordPress permissions. Chat Only: No tool execution. Tool permissions above apply as a further restriction to all roles.', 'ai-assistant'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('To change capabilities, use a role management plugin or add code to assign ai_assistant_full, ai_assistant_read_only, or ai_assistant_chat_only capabilities.', 'ai-assistant'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Display section description
     */
    public function display_section_callback() {
        echo '<p>' . esc_html__('Configure where the AI Assistant appears.', 'ai-assistant') . '</p>';
    }

    /**
     * Frontend access checkbox field
     */
    public function frontend_field_callback() {
        $show_on_frontend = get_option('ai_assistant_show_on_frontend', '1');
        ?>
        <label>
            <input type="checkbox"
                   name="ai_assistant_show_on_frontend"
                   value="1"
                   <?php checked($show_on_frontend, '1'); ?>>
            <?php esc_html_e('Show AI Assistant on the frontend', 'ai-assistant'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, logged-in users with access will see the AI Assistant button on the frontend of your site.', 'ai-assistant'); ?>
        </p>
        <?php
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <style>
            <?php echo Admin_Colors::get_current_scheme_css('.wrap'); ?>
            .ai-tool-tree {
                max-width: 1100px;
            }
            .ai-tool-tabs {
                align-items: flex-end;
                border-bottom: 1px solid #c3c4c7;
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
                margin: 0;
            }
            .ai-tool-tab {
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                border-radius: 3px 3px 0 0;
                color: #2c3338;
                cursor: pointer;
                font: inherit;
                margin: 0 0 -1px;
                padding: 7px 10px;
            }
            .ai-tool-tab:hover,
            .ai-tool-tab:focus {
                background: #fff;
                color: #1d2327;
                outline: none;
            }
            .ai-tool-tab:focus {
                box-shadow: 0 0 0 1px var(--ai-assistant-accent);
            }
            .ai-tool-tab.active {
                background: #fff;
                border-bottom-color: #fff;
                color: #1d2327;
                font-weight: 600;
            }
            .ai-tool-tab-panel[hidden] {
                display: none;
            }
            .ai-tool-tab-panels {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-top: none;
                max-width: 1100px;
                padding: 12px;
            }
            .ai-tool-group {
                margin: 0;
                max-width: 520px;
                overflow: visible;
            }
            .ai-tool-group-abilities {
                max-width: 1100px;
            }
            .ai-tool-group-header-row {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
                margin: 0 0 8px;
                padding: 0 0 9px;
                border-bottom: 1px solid #dcdcde;
            }
            .ai-tool-group-header {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 0;
                padding: 0;
                color: #50575e;
                cursor: pointer;
                font-size: 12px;
            }
            .ai-tool-group-header:hover {
                color: #1d2327;
            }
            .ai-tool-group-items {
                display: flex;
                flex-direction: column;
                gap: 3px;
                padding: 0;
            }
            .ai-tool-item {
                display: flex;
                align-items: baseline;
                gap: 6px;
                cursor: pointer;
                padding: 2px 0;
            }
            .ai-tool-item input[type=checkbox] {
                flex-shrink: 0;
                margin-top: 1px;
            }
            .ai-tool-item .description {
                color: #787c82;
                font-size: 12px;
            }
            .ai-tool-sub-items {
                margin-left: 20px;
                padding: 4px 0 4px 10px;
                border-left: 2px solid #dcdcde;
            }
            .ai-tool-sub-note {
                margin: 4px 0 8px;
                max-width: 760px;
            }
            .ai-tool-sub-item {
                display: flex;
                align-items: baseline;
                gap: 6px;
                padding: 2px 0;
            }
            .ai-tool-sub-item input[type=checkbox] {
                flex-shrink: 0;
                margin-top: 1px;
            }
            .ai-abilities-tab-layout {
                display: grid;
                grid-template-columns: minmax(280px, 1fr) minmax(320px, 420px);
                gap: 0;
                align-items: stretch;
                min-height: 100%;
            }
            .ai-abilities-main {
                min-width: 0;
                padding-right: 14px;
            }
            .ai-abilities-list {
                min-width: 0;
            }
            .ai-ability-row {
                align-items: center;
                gap: 10px;
            }
            .ai-ability-select {
                align-items: center;
                background: transparent;
                border: 1px solid transparent;
                border-radius: 3px;
                color: inherit;
                cursor: pointer;
                display: flex;
                flex: 1;
                flex-wrap: wrap;
                font: inherit;
                gap: 4px;
                line-height: inherit;
                min-width: 0;
                padding: 2px 4px;
                text-align: left;
            }
            .ai-ability-auto-approve-control {
                align-items: center;
                color: #50575e;
                display: inline-flex;
                flex-shrink: 0;
                font-size: 12px;
                gap: 5px;
                margin-left: auto;
                white-space: nowrap;
            }
            .ai-ability-auto-approve-note {
                flex-shrink: 0;
                margin-left: auto;
                white-space: nowrap;
            }
            .ai-ability-select:hover,
            .ai-ability-select:focus,
            .ai-ability-select.selected {
                background: #f6f7f7;
                border-color: #dcdcde;
                outline: none;
            }
            .ai-ability-select:focus {
                box-shadow: 0 0 0 1px var(--ai-assistant-accent);
            }
            .ai-ability-select code,
            .ai-ability-info-header code,
            .ai-ability-param code {
                overflow-wrap: anywhere;
            }
            .ai-ability-info-panel {
                align-self: start;
                background: transparent;
                border-left: 1px solid #dcdcde;
                box-shadow: none;
                max-height: calc(100vh - 72px);
                min-height: 120px;
                overflow: auto;
                padding: 0 0 0 14px;
                position: sticky;
                top: 48px;
            }
            .ai-ability-info-empty {
                color: #646970;
                font-size: 12px;
            }
            .ai-ability-info-header {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                margin-bottom: 5px;
            }
            .ai-ability-info-label {
                color: #1d2327;
                font-size: 13px;
                font-weight: 600;
                margin-bottom: 6px;
            }
            .ai-ability-info-description {
                font-size: 12px;
                margin: 0 0 10px;
            }
            .ai-ability-params-heading {
                color: #646970;
                font-size: 10px;
                font-weight: 600;
                letter-spacing: 0;
                margin: 2px 0 4px;
                text-transform: uppercase;
            }
            .ai-ability-no-params {
                margin: 0;
            }
            .ai-ability-param-list {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .ai-ability-param {
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 3px;
                padding: 5px 6px;
            }
            .ai-ability-param-head {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 5px;
            }
            .ai-ability-param-type,
            .ai-ability-param-required {
                border-radius: 3px;
                font-size: 10px;
                line-height: 1.4;
                padding: 1px 5px;
            }
            .ai-ability-param-type {
                background: #f0f0f1;
                color: #50575e;
            }
            .ai-ability-param-required {
                background: #fcf0e3;
                color: #8a5400;
                font-weight: 600;
            }
            .ai-ability-param-description,
            .ai-ability-param-notes {
                color: #646970;
                font-size: 11px;
                margin-top: 3px;
            }
            .ai-ability-instructions {
                margin: 0 0 10px;
            }
            .ai-ability-instructions-text {
                background: #fff;
                margin: 0;
                padding: 6px 8px;
                white-space: pre-wrap;
            }
            .ai-ability-raw-schema {
                margin-top: 6px;
            }
            .ai-ability-param-details,
            .ai-ability-sample-params {
                margin: 2px 0 8px;
            }
            .ai-ability-param-details summary,
            .ai-ability-raw-schema summary,
            .ai-ability-sample-params summary {
                color: #646970;
                cursor: pointer;
                font-size: 11px;
            }
            .ai-ability-param-details .ai-ability-param-list,
            .ai-ability-sample-params .ai-ability-sample-fields {
                margin-top: 5px;
            }
            .ai-ability-param-details summary.ai-ability-params-heading {
                display: list-item;
                font-size: 10px;
                margin: 2px 0 4px;
            }
            .ai-ability-raw-schema pre {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 3px;
                font-size: 11px;
                margin: 4px 0 0;
                max-height: 220px;
                overflow: auto;
                padding: 6px;
                white-space: pre-wrap;
            }
            .ai-ability-sample {
                border-top: 1px solid #dcdcde;
                margin-top: 10px;
                padding-top: 10px;
            }
            .ai-ability-sample-row {
                align-items: flex-start;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .ai-ability-sample-inline,
            .ai-ability-sample-params {
                flex: 1 1 220px;
                margin: 0;
                min-width: 180px;
            }
            .ai-ability-sample-inline .ai-ability-params-heading {
                margin-top: 0;
            }
            .ai-ability-sample-fields {
                display: flex;
                flex-direction: column;
                gap: 7px;
                margin-bottom: 10px;
            }
            .ai-ability-sample-row .ai-ability-sample-fields {
                margin-bottom: 0;
            }
            .ai-ability-sample-field {
                display: block;
            }
            .ai-ability-sample-label {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                margin-bottom: 3px;
            }
            .ai-ability-sample-field input[type=text],
            .ai-ability-sample-field input[type=number],
            .ai-ability-sample-field textarea {
                box-sizing: border-box;
                margin: 0;
                max-width: 100%;
                width: 100%;
            }
            .ai-ability-sample-checkbox {
                align-items: center;
                display: inline-flex;
                gap: 5px;
            }
            .ai-ability-sample-actions {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                flex: 0 0 auto;
            }
            .ai-ability-sample-output {
                margin-top: 7px;
            }
            .ai-ability-sample-output pre {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 3px;
                font-size: 11px;
                margin: 0;
                max-height: 260px;
                overflow: auto;
                padding: 6px;
                white-space: pre-wrap;
            }
            .ai-ability-sample-error {
                color: #b32d2e;
                margin: 0;
            }
            .ai-ability-badge {
                border-radius: 3px;
                font-size: 10px;
                font-weight: 600;
                line-height: 1.4;
                padding: 1px 5px;
                white-space: nowrap;
            }
            .ai-ability-badge-readonly {
                background: #e7f5ee;
                color: #008a20;
            }
            .ai-ability-badge-destructive {
                background: #fcf0f1;
                color: #b32d2e;
            }
            .ai-ability-category {
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0;
                color: #787c82;
                margin: 6px 0 2px;
            }
            .ai-ability-category:first-child {
                margin-top: 2px;
            }
            .ai-collapsible-section h2 {
                cursor: pointer;
                user-select: none;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .ai-collapsible-section h2::before {
                content: '\f345';
                font-family: dashicons;
                font-size: 20px;
                transition: transform 0.2s;
            }
            .ai-collapsible-section.expanded h2::before {
                transform: rotate(90deg);
            }
            .ai-collapsible-section .ai-collapsible-content {
                overflow: hidden;
                max-height: 0;
                opacity: 0;
                transition: max-height 0.3s ease-out, opacity 0.2s ease-out;
            }
            .ai-collapsible-section.expanded .ai-collapsible-content {
                opacity: 1;
            }
            @media screen and (max-width: 960px) {
                .ai-abilities-tab-layout {
                    grid-template-columns: 1fr;
                }
                .ai-abilities-main {
                    padding-right: 0;
                }
                .ai-ability-info-panel {
                    margin-top: 12px;
                    max-height: none;
                    overflow: visible;
                    position: static;
                }
            }
        </style>

        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post" id="ai-assistant-settings-form" autocomplete="off">
                <?php
                settings_fields('ai_assistant_settings');
                do_settings_sections('ai-assistant-settings');
                ?>

                <div class="ai-collapsible-section" data-section="display">
                    <h2><?php esc_html_e('Display Settings', 'ai-assistant'); ?></h2>
                    <div class="ai-collapsible-content">
                        <?php $this->display_section_callback(); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Frontend Access', 'ai-assistant'); ?></th>
                                <td><?php $this->frontend_field_callback(); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>

        <script>
        jQuery(function($) {
            // Save localStorage settings before form submits
            $('#ai-assistant-settings-form').on('submit', function() {
                $('.ai-localstorage-setting').each(function() {
                    var $el = $(this);
                    var key = $el.data('setting');
                    var value;
                    if ($el.hasClass('ai-api-key-input')) {
                        var actualValue = $el.data('actualValue');
                        value = typeof actualValue === 'undefined' ? $el.val() : actualValue;
                    } else {
                        value = $el.val();
                    }
                    var storageKey = 'aiAssistant_' + key;
                    if (value) {
                        localStorage.setItem(storageKey, value);
                    } else {
                        localStorage.removeItem(storageKey);
                    }

                    if (key === 'model') {
                        var provider = $('#ai_provider').val();
                        if (provider) {
                            var providerModelKey = 'aiAssistant_model_' + provider;
                            if (value) {
                                localStorage.setItem(providerModelKey, value);
                                if (provider === 'local') {
                                    localStorage.setItem('aiAssistant_localModel', value);
                                }
                            } else {
                                localStorage.removeItem(providerModelKey);
                                if (provider === 'local') {
                                    localStorage.removeItem('aiAssistant_localModel');
                                }
                            }
                        }
                    }
                });
            });

            // Wrap settings sections in collapsible containers
            ['permissions', 'tools'].forEach(function(section) {
                var $content = $('.ai-collapsible-content[data-section="' + section + '"]');
                if ($content.length) {
                    var $h2 = $content.prev('h2');
                    if ($h2.length) {
                        var $wrapper = $('<div class="ai-collapsible-section" data-section="' + section + '"></div>');
                        $h2.before($wrapper);
                        $wrapper.append($h2).append($content);
                    }
                }
            });

            function getSectionContent($section) {
                return $section.children('.ai-collapsible-content');
            }

            function expandSection($section, remember, animate) {
                var $content = getSectionContent($section);
                $section.addClass('expanded');

                if (animate === false) {
                    $content.css({
                        'max-height': 'none',
                        'overflow': 'visible'
                    });
                } else {
                    $content.css({
                        'max-height': $content[0].scrollHeight + 'px',
                        'overflow': 'hidden'
                    });
                    window.setTimeout(function() {
                        if ($section.hasClass('expanded')) {
                            $content.css({
                                'max-height': 'none',
                                'overflow': 'visible'
                            });
                        }
                    }, 320);
                }

                if (remember) {
                    localStorage.setItem('aiAssistant_settings_' + $section.data('section') + '_expanded', '1');
                }
            }

            function collapseSection($section) {
                var $content = getSectionContent($section);
                var sectionKey = 'aiAssistant_settings_' + $section.data('section') + '_expanded';

                $content.css({
                    'max-height': $content[0].scrollHeight + 'px',
                    'overflow': 'hidden'
                });
                $content[0].offsetHeight;
                $section.removeClass('expanded');
                $content.css('max-height', '');
                localStorage.removeItem(sectionKey);
            }

            // Collapsible toggle behavior
            $(document).on('click', '.ai-collapsible-section h2', function() {
                var $section = $(this).closest('.ai-collapsible-section');

                if ($section.hasClass('expanded')) {
                    collapseSection($section);
                } else {
                    expandSection($section, true, true);
                }
            });

            // Restore expanded state from localStorage
            $('.ai-collapsible-section').each(function() {
                var $section = $(this);
                var sectionKey = 'aiAssistant_settings_' + $section.data('section') + '_expanded';

                if (localStorage.getItem(sectionKey) === '1') {
                    expandSection($section, false, false);
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Get user's permission level based on WordPress capabilities
     */
    public function get_user_permission_level($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return 'none';
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return 'none';
        }

        if (user_can($user_id, 'ai_assistant_full')) {
            return 'full';
        }
        if (user_can($user_id, 'ai_assistant_read_only')) {
            return 'read_only';
        }
        if (user_can($user_id, 'ai_assistant_chat_only')) {
            return 'chat_only';
        }

        return 'none';
    }

    /**
     * Get page-specific CSS selector hints for the current admin screen
     */
    private function get_page_selector_hints(): string {
        global $pagenow;
        $pagenow = is_string($pagenow) ? $pagenow : '';
        $hints = [];

        // Get current screen if available
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';

        // Plugin pages
        if ($pagenow === 'plugins.php') {
            $hints[] = '- .wp-list-table.plugins: Table of installed plugins';
            $hints[] = '- tr.active: Currently active plugins';
            $hints[] = '- tr.inactive: Currently inactive plugins';
            $hints[] = '- .plugin-title strong: Plugin names';
        } elseif ($pagenow === 'plugin-install.php') {
            $hints[] = '- .plugin-card: Each available plugin in the directory';
            $hints[] = '- .plugin-card .name: Plugin name';
            $hints[] = '- .plugin-card .desc: Plugin description';
            $hints[] = '- .plugin-action-buttons: Install/activate buttons';
        }

        // Posts/Pages
        elseif ($pagenow === 'edit.php') {
            $post_type = $_GET['post_type'] ?? 'post';
            $hints[] = "- .wp-list-table.posts: Table of {$post_type}s";
            $hints[] = '- .row-title: Post/page titles';
            $hints[] = '- .column-date: Publication dates';
            $hints[] = '- tr.status-publish: Published items';
            $hints[] = '- tr.status-draft: Draft items';
        } elseif ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
            $hints[] = '- #title, #post-title-0: Post title field';
            $hints[] = '- #content, .editor-styles-wrapper: Post content area';
            $hints[] = '- #categorydiv: Categories metabox';
            $hints[] = '- #tagsdiv-post_tag: Tags metabox';
        }

        // Media
        elseif ($pagenow === 'upload.php') {
            $hints[] = '- .wp-list-table.media: Media library table view';
            $hints[] = '- .attachments-browser: Media grid view';
            $hints[] = '- .attachment: Individual media items';
        }

        // Users
        elseif ($pagenow === 'users.php') {
            $hints[] = '- .wp-list-table.users: Users table';
            $hints[] = '- .column-username: Usernames';
            $hints[] = '- .column-role: User roles';
        } elseif ($pagenow === 'user-edit.php' || $pagenow === 'profile.php') {
            $hints[] = '- #your-profile: User profile form';
            $hints[] = '- #email: User email field';
            $hints[] = '- #role: User role selector';
        }

        // Themes
        elseif ($pagenow === 'themes.php') {
            if (isset($_GET['page'])) {
                $hints[] = '- #customize-controls: Customizer panel';
            } else {
                $hints[] = '- .themes: Theme grid';
                $hints[] = '- .theme: Individual theme cards';
                $hints[] = '- .theme.active: Currently active theme';
            }
        }

        // Settings
        elseif ($pagenow === 'options-general.php' || strpos($pagenow, 'options-') === 0) {
            $hints[] = '- .form-table: Settings form';
            $hints[] = '- .form-table th: Setting labels';
            $hints[] = '- .form-table td: Setting inputs';
        }

        // Comments
        elseif ($pagenow === 'edit-comments.php') {
            $hints[] = '- .wp-list-table.comments: Comments table';
            $hints[] = '- .comment-author: Comment author info';
            $hints[] = '- .comment-body: Comment content';
        }

        // Dashboard
        elseif ($pagenow === 'index.php' && $screen_id === 'dashboard') {
            $hints[] = '- #dashboard-widgets: Dashboard widget area';
            $hints[] = '- .postbox: Individual dashboard widgets';
            $hints[] = '- #welcome-panel: Welcome panel (if visible)';
        }

        // Menus
        elseif ($pagenow === 'nav-menus.php') {
            $hints[] = '- #menu-to-edit: Current menu items';
            $hints[] = '- .menu-item: Individual menu items';
            $hints[] = '- #menu-settings-column: Available menu items';
        }

        // Widgets
        elseif ($pagenow === 'widgets.php') {
            $hints[] = '- #widgets-right: Widget areas/sidebars';
            $hints[] = '- #available-widgets: Available widgets';
            $hints[] = '- .widget: Individual widgets';
        }

        // Frontend (non-admin)
        if (!is_admin()) {
            $hints[] = '- article, .post, .hentry: Blog posts';
            $hints[] = '- .entry-title: Post titles';
            $hints[] = '- .entry-content: Post content';
            $hints[] = '- .widget-area, .sidebar: Sidebar widgets';
            $hints[] = '- .site-header, header: Site header';
            $hints[] = '- .site-footer, footer: Site footer';
            $hints[] = '- .nav-menu, .main-navigation: Navigation menus';
        }

        return implode("\n", $hints);
    }

    /**
     * Add compact runtime instructions for browser-based Playground environments.
     */
    private function get_playground_context_prompt(string $site_url): string {
        if (!ai_assistant_is_playground()) {
            return '';
        }

        $prompt = "\n\nPLAYGROUND:\n";
        $prompt .= "- Browser-based WordPress; do not promise inbound/public reachability.\n";

        if (wp_parse_url($site_url, PHP_URL_HOST) === 'my.wordpress.net') {
            $prompt .= "- my.wordpress.net is My WordPress: a personal software home for private apps, data, memories, reading, relationships, and reshapeable workflows.\n";
            $prompt .= "- Public/inbound features need hosted WordPress; for now users can get there by exporting backups and importing them at a host.\n";
        }

        $prompt .= "- For \"what can I do\", inspect plugins/abilities as useful and suggest concrete personal workflows this install supports.\n";

        return $prompt;
    }

    /**
     * Get skills help tab content
     */
    private function get_skills_help_content(): string {
        $skills_dir = plugin_dir_path(__DIR__) . 'skills/';

        if (!is_dir($skills_dir)) {
            return '<p>' . __('No skills available. Add .md files to the skills/ directory.', 'ai-assistant') . '</p>';
        }

        $skills_by_category = [];

        foreach (Skill_Registry::get_available_skills() as $skill) {
            $skills_by_category[$skill['category']][] = $skill;
        }

        if (empty($skills_by_category)) {
            return '<p>' . __('No skills available. Add .md files to the skills/ directory.', 'ai-assistant') . '</p>';
        }

        ksort($skills_by_category);

        $nonce = wp_create_nonce('ai_assistant_skills');

        $html = '<style>
            .ai-skill-accordion { margin: 0; padding: 0; list-style: none; }
            .ai-skill-item { border: 1px solid #ddd; margin-bottom: -1px; }
            .ai-skill-header { display: block; padding: 10px 12px; background: #f9f9f9; cursor: pointer; text-decoration: none; color: inherit; }
            .ai-skill-header:hover { background: #f0f0f0; }
            .ai-skill-header:focus { outline: 2px solid var(--ai-assistant-accent, var(--wp-admin-theme-color)); outline-offset: -2px; }
            .ai-skill-header .dashicons { float: right; color: #666; transition: transform 0.2s; }
            .ai-skill-item.open .ai-skill-header .dashicons { transform: rotate(180deg); }
            .ai-skill-header code { background: #e0e0e0; padding: 2px 6px; border-radius: 3px; }
            .ai-skill-header .ai-skill-title { margin-left: 8px; color: #333; }
            .ai-skill-body { display: none; padding: 12px; background: #fff; border-top: 1px solid #ddd; }
            .ai-skill-body pre { white-space: pre-wrap; margin: 0; max-height: 300px; overflow-y: auto; font-size: 12px; line-height: 1.5; }
            .ai-skill-body .loading { color: #666; font-style: italic; }
            .ai-skill-desc { display: block; font-size: 12px; color: #666; margin-top: 4px; }
        </style>';

        $html .= '<p>' . __('Skills are specialized knowledge documents the AI can load on-demand. Click a skill to view its content:', 'ai-assistant') . '</p>';

        foreach ($skills_by_category as $category => $skills) {
            $html .= '<h4 style="margin: 1em 0 0.5em; text-transform: capitalize;">' . esc_html($category) . '</h4>';
            $html .= '<ul class="ai-skill-accordion">';
            foreach ($skills as $skill) {
                $html .= '<li class="ai-skill-item" data-skill="' . esc_attr($skill['id']) . '">';
                $html .= '<a href="#" class="ai-skill-header">';
                $html .= '<span class="dashicons dashicons-arrow-down-alt2"></span>';
                $html .= '<code>' . esc_html($skill['id']) . '</code>';
                if ($skill['title'] !== $skill['id']) {
                    $html .= '<span class="ai-skill-title">' . esc_html($skill['title']) . '</span>';
                }
                if ($skill['description']) {
                    $html .= '<span class="ai-skill-desc">' . esc_html($skill['description']) . '</span>';
                }
                $html .= '</a>';
                $html .= '<div class="ai-skill-body"><pre class="loading">Click to load...</pre></div>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<script>
        jQuery(function($) {
            var nonce = ' . json_encode($nonce) . ';
            var ajaxUrl = ' . json_encode(admin_url('admin-ajax.php')) . ';
            var loadedSkills = {};

            $(".ai-skill-header").on("click", function(e) {
                e.preventDefault();
                var $item = $(this).closest(".ai-skill-item");
                var $body = $item.find(".ai-skill-body");
                var $pre = $body.find("pre");
                var skill = $item.data("skill");
                var isOpen = $item.hasClass("open");

                if (isOpen) {
                    $body.slideUp(150);
                    $item.removeClass("open");
                } else {
                    $body.slideDown(150);
                    $item.addClass("open");

                    if (!loadedSkills[skill]) {
                        $pre.addClass("loading").text("Loading...");
                        $.post(ajaxUrl, {
                            action: "ai_assistant_get_skill",
                            skill: skill,
                            _wpnonce: nonce
                        }, function(response) {
                            if (response.success) {
                                $pre.removeClass("loading").text(response.data.content);
                                loadedSkills[skill] = true;
                            } else {
                                $pre.removeClass("loading").text("Error: " + response.data.message);
                            }
                        }).fail(function() {
                            $pre.removeClass("loading").text("Failed to load skill");
                        });
                    }
                }
            });
        });
        </script>';

        return $html;
    }

    /**
     * Get the system prompt for the AI assistant
     */
    public function get_system_prompt() {
        $current_path = $_SERVER['REQUEST_URI'] ?? '/';
        // Strip the site's base path (e.g., /scope:default) from the URI
        $site_path = wp_parse_url(home_url(), PHP_URL_PATH);
        if ($site_path && $site_path !== '/' && strpos($current_path, $site_path) === 0) {
            $current_path = substr($current_path, strlen($site_path)) ?: '/';
        }
        $page_hints = $this->get_page_selector_hints();
        $current_user = wp_get_current_user();
        $enabled_tools = $this->get_user_enabled_tools();
        $wp_info = [
            'siteUrl' => get_site_url(),
            'currentPath' => $current_path,
            'wpVersion' => get_bloginfo('version'),
            'theme' => get_template(),
            'phpVersion' => phpversion(),
            'userDisplayName' => $current_user->display_name,
        ];

        $prompt = <<<PROMPT
You are the AI Assistant integrated into WordPress. You help users manage and modify their WordPress installation.

Current WordPress Information:
- Site URL: {$wp_info['siteUrl']}
- Current Page: {$wp_info['currentPath']}
- WordPress Version: {$wp_info['wpVersion']}
- Active Theme: {$wp_info['theme']}
- PHP Version: {$wp_info['phpVersion']}
- User: {$wp_info['userDisplayName']}
PROMPT;

        if ($page_hints) {
            $prompt .= "\n\nPAGE STRUCTURE (useful CSS selectors for get_page_html on this page):\n" . $page_hints;
        }

        $prompt .= $this->get_playground_context_prompt($wp_info['siteUrl']);

        /**
         * Filter the ability domains injected into the AI system prompt.
         *
         * Returns an associative array mapping a plugin slug to a comma-separated list
         * of topic keywords. The AI is instructed to always use the `ability` tool for
         * these topics instead of generic tools like `run_php`, `find`, or `db_query`.
         *
         * Example:
         * ```php
         * add_filter( 'ai_assistant_ability_domains', function ( $domains ) {
         *     $domains['my-plugin'] = 'invoices, billing, payments, clients';
         *     return $domains;
         * } );
         * ```
         *
         * @param array<string,string> $domains Map of plugin slug => comma-separated topic keywords.
         * @return array<string,string> The filtered domains map.
         */
        $ability_domains = apply_filters('ai_assistant_ability_domains', []);

        $prompt .= <<<'PROMPT'


TOOL USAGE RULES:

If the user describes something they are seeing on the page, references UI elements, or asks about content visible on screen, use get_page_html to see what they're looking at.

NAVIGATION SUGGESTIONS: When you have finished creating or updating something that has a useful WordPress admin or frontend URL, offer the user a direct link by calling navigate with the final URL and concise link_text. Call navigate only after all requested creation, editing, and checking is complete; do not use it as a progress step or before the destination exists. The tool shows a clickable suggestion to the user instead of opening the page automatically.

IMAGE PICKING: Call pick_image for one image at a time only. Do not issue multiple pick_image tool calls in the same assistant response because each call asks the user to make an interactive choice and parallel pickers are confusing. Wait for the selected image result, then decide whether another image is actually needed.

PROMPT;

        if (!empty($ability_domains)) {
            $prompt .= "The following topics are handled by plugin abilities. For these, ALWAYS use the ability tool — never db_query, find, or run_php:\n";
            foreach ($ability_domains as $slug => $keywords) {
                $prompt .= "- $slug: $keywords\n";
            }
            $prompt .= "These slugs are ability categories/domains, not executable ability IDs. First call ability with action \"list\" and the matching category, then action \"get\" for the exact ability ID before executing.\n\n";
        }

        $prompt .= "For any other plugin-specific data or actions, check abilities first (ability action:list) before reaching for db_query or run_php.\n";

        if (in_array('run_php', $enabled_tools, true)) {
            $prompt .= "For native WordPress data or actions with no matching ability or REST route, use run_php with standard WordPress functions.\n";
        }

        $prompt .= "Only use db_query for custom reporting or cross-table queries that no ability covers.\n";

        $prompt .= <<<'PROMPT'

POST/PAGE DRAFTS: create actual drafts via REST (/wp/v2/posts or /wp/v2/pages, status "draft") when tools allow; use run_php/wp_insert_post only if REST cannot. Never publish/overwrite or use db_query unless asked; use block-editor HTML and report title, ID, edit URL.

FILE EDITING RULES:
- Use write_file ONLY for creating NEW files
- Use edit_file for modifying EXISTING files - it uses search/replace operations which is more efficient and easier to review
- The edit_file tool takes a real JSON array of {search, replace} objects, not a string containing JSON, markdown, XML, or tool-call tags
- Each edit_file search string must be exact and unique in the current file
- If an edit_file operation fails (string not found or not unique), use read_file to see the current content and retry

EMERGENCY PLUGIN DISABLING:
- AI Assistant may emergency-disable a plugin after plugin edits or activation break WordPress. This inserts a reversible guard at the top of the plugin main file: AI_ASSISTANT_EMERGENCY_DISABLED.
- When a user asks why a plugin was disabled or to get it working again, inspect the plugin main file and relevant changed files with read_file/find before editing.
- Fix the plugin code before removing an emergency guard. Removing the guard first can immediately fatal WordPress again.
- After a fix, verify the plugin is active and WordPress still loads. If environment_info without inactive plugins does not list the plugin, call environment_info with include_inactive true or get_plugins before claiming it is working.
- If WordPress-backed tools fail after plugin file edits, continue with direct file tools and explain that the plugin may have been emergency-disabled for recovery.

PLUGIN CREATION:
- For app-like plugins with their own UI, route, dashboard, workflow, logged-in experience, or standalone interface, load skill "wp-app" before acting.
- For ordinary/manual WordPress plugins with no app-style UI and no matching ability, load skill "plugin-creation" before writing files.
- Skill instructions override these brief routing notes.

IMPORTANT: For any destructive operations (file deletion, database modification, file overwriting), the user will be asked to confirm before execution. Be clear about what changes you're proposing.

Always explain what you're about to do before using tools.
PROMPT;

        $prompt .= $this->get_skills_prompt_summary($enabled_tools);

        return $prompt;
    }

    public function get_auto_approved_abilities(): array {
        return (array) get_option('ai_assistant_auto_approved_abilities', []);
    }

    public function get_auto_approved_rest_apis(): array {
        return (array) get_option('ai_assistant_auto_approved_rest_apis', []);
    }

    public function ajax_toggle_auto_approve_ability() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $ability = sanitize_text_field($_POST['ability'] ?? '');
        $approved = !empty($_POST['approved']);

        if (empty($ability)) {
            wp_send_json_error(['message' => 'Missing ability identifier']);
        }

        $auto_approved = $this->get_auto_approved_abilities();

        if ($approved) {
            if (!in_array($ability, $auto_approved, true)) {
                $auto_approved[] = $ability;
            }
        } else {
            $auto_approved = array_values(array_filter($auto_approved, fn($a) => $a !== $ability));
        }

        update_option('ai_assistant_auto_approved_abilities', $auto_approved);
        wp_send_json_success(['autoApprovedAbilities' => $auto_approved]);
    }

    public function ajax_sample_readonly_ability() {
        check_ajax_referer('ai_assistant_sample_ability', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $ability_id = sanitize_text_field(wp_unslash($_POST['ability'] ?? ''));
        if (empty($ability_id)) {
            wp_send_json_error([
                'code'    => 'ability_missing',
                'message' => 'Missing ability identifier',
            ], 400);
        }

        if (!function_exists('wp_get_ability')) {
            wp_send_json_error([
                'code'    => 'abilities_api_unavailable',
                'message' => 'Abilities API not available. WordPress 6.9+ with the Abilities API is required.',
            ], 400);
        }

        $ability = wp_get_ability($ability_id);
        if ($ability === null) {
            wp_send_json_error([
                'code'    => 'ability_not_found',
                'message' => 'Ability not found: ' . $ability_id,
            ], 404);
        }

        $details = Ability_Annotations::get_details($ability_id, $ability);
        if (empty($details['readonly']) || !empty($details['destructive'])) {
            wp_send_json_error([
                'code'    => 'ability_not_readonly',
                'message' => 'Only read-only abilities can be sampled from settings.',
            ], 403);
        }

        if (!is_object($ability) || !method_exists($ability, 'execute')) {
            wp_send_json_error([
                'code'    => 'ability_not_executable',
                'message' => 'This ability cannot be sampled because it is not executable.',
            ], 400);
        }

        $arguments_json = wp_unslash($_POST['arguments'] ?? '{}');
        if (!is_string($arguments_json)) {
            wp_send_json_error([
                'code'    => 'invalid_sample_arguments',
                'message' => 'Sample arguments must be a JSON object.',
            ], 400);
        }

        $arguments_json = $arguments_json === '' ? '{}' : $arguments_json;
        $sample_arguments_object = json_decode($arguments_json);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($sample_arguments_object)) {
            wp_send_json_error([
                'code'    => 'invalid_sample_arguments',
                'message' => 'Sample arguments must be a valid JSON object.',
            ], 400);
        }
        $sample_arguments = json_decode($arguments_json, true);

        $missing_required = [];
        foreach ((array) ($details['parameters'] ?? []) as $parameter) {
            $name = (string) ($parameter['name'] ?? '');
            if (!empty($parameter['required']) && $name !== '' && !array_key_exists($name, $sample_arguments)) {
                $missing_required[] = $name;
            }
        }

        if (!empty($missing_required)) {
            wp_send_json_error([
                'code'    => 'sample_arguments_required',
                'message' => 'Sample output requires arguments for: ' . implode(', ', $missing_required),
            ], 400);
        }

        $input = !empty($details['has_schema']) ? $sample_arguments : null;

        try {
            $result = $ability->execute($input);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            wp_send_json_error([
                'code'    => 'ability_sample_failed',
                'message' => $message ?: 'Ability sample failed.',
            ], 500);
        }

        if (is_wp_error($result)) {
            wp_send_json_error([
                'code'    => 'ability_sample_failed',
                'message' => 'Ability sample failed: ' . $result->get_error_message(),
            ], 500);
        }

        $response = [
            'ability' => $ability_id,
            'input'   => $input,
            'result'  => $result,
        ];

        $instructions = apply_filters('ai_assistant_ability_instructions', '', $ability_id, (array) $sample_arguments, $result);
        if ($instructions) {
            $response['_instructions'] = $instructions;
        }

        wp_send_json_success($response);
    }

    public function ajax_toggle_auto_approve_rest_api() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $pattern = sanitize_text_field($_POST['pattern'] ?? '');
        $approved = !empty($_POST['approved']);

        if (empty($pattern)) {
            wp_send_json_error(['message' => 'Missing pattern']);
        }

        $auto_approved = $this->get_auto_approved_rest_apis();

        if ($approved) {
            if (!in_array($pattern, $auto_approved, true)) {
                $auto_approved[] = $pattern;
            }
        } else {
            $auto_approved = array_values(array_filter($auto_approved, fn($p) => $p !== $pattern));
        }

        update_option('ai_assistant_auto_approved_rest_apis', $auto_approved);
        wp_send_json_success(['autoApprovedRestApis' => $auto_approved]);
    }

    /**
     * Add compact skill discovery guidance to the system prompt.
     *
     * Full skill contents are intentionally loaded on demand via the skill tool
     * so every request does not pay the context cost for every skill document.
     */
    private function get_skills_prompt_summary(array $enabled_tools): string {
        $can_list_skills = in_array('list_skills', $enabled_tools, true);
        $can_get_skills = in_array('get_skill', $enabled_tools, true);

        if (!$can_list_skills && !$can_get_skills) {
            return '';
        }

        $skills = Skill_Registry::get_available_skills();
        if (empty($skills)) {
            return '';
        }

        $skills_content = "\n\n=== SKILLS ===\n";

        if ($can_get_skills) {
            $skills_content .= "Specialized skill documents are available on demand through the skill tool. The full skill contents are not included in this prompt. When a task matches a listed skill, call skill with action \"get\" before implementing.";
            if ($can_list_skills) {
                $skills_content .= " Use action \"list\" to discover or filter skills.";
            }
        } else {
            $skills_content .= "Specialized skill documents exist, but full skill loading is not available with the current tool permissions.";
        }

        $routed_plugin_skills = [
            'wp-app',
            'plugin-creation',
        ];

        $skills_content .= "\n\nAvailable skills:\n";

        foreach ($skills as $skill) {
            if (in_array($skill['id'], $routed_plugin_skills, true)) {
                continue;
            }
            $description = $skill['description'] ? ' - ' . $skill['description'] : '';
            $skills_content .= "- {$skill['id']} ({$skill['category']}): {$skill['title']}{$description}\n";
        }

        if ($can_get_skills) {
            $skills_content .= "- wp-app, plugin-creation (plugin creation): use \"wp-app\" for app-like WordPress plugins; use \"plugin-creation\" for ordinary/manual WordPress plugins with no app-style UI and no matching ability.\n";
        }

        return $skills_content;
    }
}
