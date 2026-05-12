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
        add_action('wp_ajax_ai_assistant_get_skill', [$this, 'ajax_get_skill']);
        add_action('wp_ajax_ai_assistant_toggle_auto_approve_ability', [$this, 'ajax_toggle_auto_approve_ability']);
        add_action('wp_ajax_ai_assistant_toggle_auto_approve_rest_api', [$this, 'ajax_toggle_auto_approve_rest_api']);
        add_action('load-tools_page_ai-conversations', [$this, 'add_help_tabs']);
        add_action('load-settings_page_ai-assistant-settings', [$this, 'add_help_tabs']);
        add_filter('map_meta_cap', [$this, 'map_tool_cap'], 10, 3);
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

        $skills_dir = plugin_dir_path(__DIR__) . 'skills/';
        $skill_file = $skills_dir . $skill_id . '.md';

        if (!file_exists($skill_file)) {
            wp_send_json_error(['message' => 'Skill not found']);
        }

        $content = file_get_contents($skill_file);
        if ($content === false) {
            wp_send_json_error(['message' => 'Failed to read skill']);
        }

        $frontmatter = [];
        $body = $content;

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            foreach (explode("\n", $matches[1]) as $line) {
                if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                    $frontmatter[$kv[1]] = trim($kv[2], '"\'');
                }
            }
            $body = $matches[2];
        }

        wp_send_json_success([
            'id' => $skill_id,
            'title' => $frontmatter['title'] ?? $skill_id,
            'content' => $body,
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
        $conversation_id = isset($_GET['conversation']) ? intval($_GET['conversation']) : 0;
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
        <div class="wrap ai-assistant-page">
            <div class="ai-chat-layout">
                <!-- Sidebar -->
                <div class="ai-chat-sidebar">
                    <div class="ai-sidebar-header">
                        <button type="button" id="ai-assistant-new-chat" class="button button-primary">
                            + <?php esc_html_e('New Chat', 'ai-assistant'); ?>
                        </button>
                    </div>
                    <div class="ai-sidebar-conversations" id="ai-sidebar-conversations">
                        <div class="ai-sidebar-loading"><?php esc_html_e('Loading...', 'ai-assistant'); ?></div>
                    </div>
                    <div class="ai-sidebar-footer">
                        <a href="<?php echo esc_url($settings_url); ?>" class="ai-sidebar-link">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php esc_html_e('Settings', 'ai-assistant'); ?>
                        </a>
                    </div>
                </div>

                <!-- Main Chat Area -->
                <div class="ai-chat-main">
                    <div class="ai-chat-main-header">
                        <button type="button" class="ai-sidebar-toggle" id="ai-sidebar-toggle">
                            <span class="dashicons dashicons-menu"></span> <?php esc_html_e('Chats', 'ai-assistant'); ?>
                        </button>
                        <div class="ai-header-actions">
                            <span id="ai-token-count" class="ai-token-count" title="<?php esc_attr_e('Estimated token usage', 'ai-assistant'); ?>">0 tokens</span>
                            <span class="ai-header-sep">|</span>
                            <button type="button" id="ai-assistant-summarize" class="ai-header-btn" title="<?php esc_attr_e('Generate conversation summary', 'ai-assistant'); ?>" style="display: none;">
                                <span class="dashicons dashicons-media-text"></span>
                            </button>
                            <label class="ai-yolo-label" title="<?php esc_attr_e('Skip confirmation prompts for destructive actions', 'ai-assistant'); ?>"><input type="checkbox" id="ai-assistant-yolo"> YOLO Mode</label>
                        </div>
                    </div>
                    <div class="ai-assistant-chat-container">
                        <div id="ai-assistant-messages"></div>
                        <div class="ai-assistant-drop-zone" aria-hidden="true">
                            <div class="ai-assistant-drop-zone-inner"><?php esc_html_e('Drop files to attach', 'ai-assistant'); ?></div>
                        </div>
                        <div id="ai-assistant-loading" style="display: none;">
                            <div class="ai-loading-dots"><span></span><span></span><span></span></div>
                        </div>
                        <div id="ai-assistant-pending-actions"></div>
                        <div id="ai-assistant-attachments" class="ai-assistant-attachments"></div>
                        <div class="ai-assistant-input-area">
                            <input type="file" id="ai-assistant-file-input" multiple hidden>
                            <button type="button" id="ai-assistant-attach" class="button" title="<?php esc_attr_e('Attach files', 'ai-assistant'); ?>">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-8.49 8.49a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.19 9.19a2 2 0 01-2.83-2.83l8.49-8.49"/></svg>
                            </button>
                            <textarea id="ai-assistant-input" placeholder="<?php esc_attr_e('Ask me anything about your WordPress site...', 'ai-assistant'); ?>" rows="3"></textarea>
                            <button type="button" id="ai-assistant-send" class="button button-primary"><?php esc_html_e('Send', 'ai-assistant'); ?></button>
                            <button type="button" id="ai-assistant-stop" class="button" style="display: none;" title="<?php esc_attr_e('Stop generation', 'ai-assistant'); ?>">
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            var aiAssistantPageConfig = {
                conversationId: <?php echo intval($conversation_id); ?>,
                isFullPage: true
            };
            jQuery(document).ready(function($) {
                $('#ai-sidebar-toggle').on('click', function() {
                    $('.ai-chat-sidebar').toggleClass('mobile-visible');
                });
                // Hide sidebar when conversation is selected on mobile
                $(document).on('click', '.ai-conv-item', function() {
                    if (window.innerWidth <= 782) {
                        $('.ai-chat-sidebar').removeClass('mobile-visible');
                    }
                });
            });
        </script>
        <?php
    }

    public function map_tool_cap($caps, $cap, $user_id) {
        if (strpos($cap, 'ai_assistant_tool_') !== 0) {
            return $caps;
        }
        if (ai_assistant_is_playground()) {
            return ['exist'];
        }

        $tool_name = substr($cap, strlen('ai_assistant_tool_'));
        $enabled = get_option('ai_assistant_enabled_tools', $this->get_default_enabled_tools());

        if (!in_array($tool_name, (array) $enabled, true)) {
            return ['do_not_allow'];
        }

        if (user_can($user_id, 'ai_assistant_full')) {
            return ['exist'];
        }

        // read_only users get non-dangerous tools only
        if (user_can($user_id, 'ai_assistant_read_only')) {
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
        return [
            'read_file', 'list_directory', 'search_files', 'search_content', 'db_query',
            'rest_api', 'environment_info', 'get_plugins', 'get_themes',
            'list_abilities', 'get_ability', 'execute_ability', 'navigate', 'get_page_html',
            'pick_image', 'summarize_conversation', 'list_skills', 'get_skill',
        ];
    }

    public function get_all_tools_with_meta() {
        return [
            'read_file'             => ['label' => 'Read File',             'group' => 'File Reading',    'dangerous' => false],
            'list_directory'        => ['label' => 'List Directory',        'group' => 'File Reading',    'dangerous' => false],
            'search_files'          => ['label' => 'Search Files',          'group' => 'File Reading',    'dangerous' => false],
            'search_content'        => ['label' => 'Search Content',        'group' => 'File Reading',    'dangerous' => false],
            'write_file'            => ['label' => 'Write File',            'group' => 'File Writing',    'dangerous' => true],
            'edit_file'             => ['label' => 'Edit File',             'group' => 'File Writing',    'dangerous' => true],
            'delete_file'           => ['label' => 'Delete File',           'group' => 'File Writing',    'dangerous' => true],
            'db_query'              => ['label' => 'DB Query',              'group' => 'Database',        'dangerous' => false],
            'environment_info'      => ['label' => 'Environment Info',      'group' => 'WordPress',       'dangerous' => false],
            'get_plugins'           => ['label' => 'Get Plugins',           'group' => 'WordPress',       'dangerous' => false],
            'get_themes'            => ['label' => 'Get Themes',            'group' => 'WordPress',       'dangerous' => false],
            'install_plugin'        => ['label' => 'Install Plugin',        'group' => 'WordPress',       'dangerous' => true],
            'rest_api'              => ['label' => 'REST API',              'group' => 'WordPress',       'dangerous' => false],
            'run_php'               => ['label' => 'Run PHP',               'group' => 'Code Execution',  'dangerous' => true],
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
                return array_values(array_map('sanitize_text_field', (array) $value));
            },
            'default' => [],
        ]);

        register_setting('ai_assistant_settings', 'ai_assistant_auto_approved_rest_apis', [
            'type' => 'array',
            'sanitize_callback' => function($value) {
                return array_values(array_map('sanitize_text_field', (array) $value));
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
                    <input type="password" id="ai_anthropic_key" class="regular-text ai-localstorage-setting" data-setting="anthropicApiKey" placeholder="sk-ant-..." autocomplete="new-password">
                    <button type="button" class="button ai-test-connection" data-provider="anthropic"><?php esc_html_e('Test Connection', 'ai-assistant'); ?></button>
                    <span class="ai-connection-status"></span>
                </td>
            </tr>
            <tr class="ai-provider-row" data-provider="openai">
                <th scope="row"><label for="ai_openai_key"><?php esc_html_e('OpenAI API Key', 'ai-assistant'); ?></label></th>
                <td>
                    <input type="password" id="ai_openai_key" class="regular-text ai-localstorage-setting" data-setting="openaiApiKey" placeholder="sk-..." autocomplete="new-password">
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

            // Load settings into form
            function loadSettings() {
                $('.ai-localstorage-setting').each(function() {
                    var $el = $(this);
                    var key = $el.data('setting');
                    var value = getSetting(key);
                    if (value) {
                        $el.val(value);
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
                var currentModel = getSetting('model');
                var currentSumModel = getSetting('summarizationModel');

                $modelSelect.html('<option value=""><?php echo esc_js(__('Loading...', 'ai-assistant')); ?></option>');

                var models = [];

                if (provider === 'anthropic') {
                    var apiKey = $('#ai_anthropic_key').val() || getSetting('anthropicApiKey');
                    if (apiKey) {
                        models = await fetchAnthropicModels(apiKey);
                    }
                } else if (provider === 'openai') {
                    var apiKey = $('#ai_openai_key').val() || getSetting('openaiApiKey');
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
                    models.forEach(function(m) {
                        var selected = m.id === currentModel ? 'selected' : '';
                        $modelSelect.append('<option value="' + m.id + '" ' + selected + '>' + m.name + '</option>');
                        var sumSelected = m.id === currentSumModel ? 'selected' : '';
                        $sumSelect.append('<option value="' + m.id + '" ' + sumSelected + '>' + m.name + '</option>');
                    });
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
                    var apiKey = $('#ai_anthropic_key').val();
                    if (!apiKey) { $status.text('<?php echo esc_js(__('Enter API key first', 'ai-assistant')); ?>').addClass('error'); return; }
                    try {
                        var response = await fetch('https://api.anthropic.com/v1/models', {
                            headers: { 'x-api-key': apiKey, 'anthropic-version': '2023-06-01', 'anthropic-dangerous-direct-browser-access': 'true' }
                        });
                        success = response.ok;
                        message = success ? '<?php echo esc_js(__('Connected!', 'ai-assistant')); ?>' : '<?php echo esc_js(__('Invalid API key', 'ai-assistant')); ?>';
                    } catch (e) { message = e.message; }
                } else if (provider === 'openai') {
                    var apiKey = $('#ai_openai_key').val();
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

            // Reload models when API key changes
            $('#ai_anthropic_key, #ai_openai_key, #ai_local_endpoint').on('change', function() { loadModels(); });

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
                if ((!empty($provider['apiKey']) || $provider['type'] === 'server') && !empty($provider['models'])) {
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
                    esc_html__('LLM providers are managed through %s. Drag to reorder — the first available provider will be used.', 'ai-assistant'),
                    '<a href="' . esc_url($connectors_url) . '">' . esc_html__('WordPress Connectors', 'ai-assistant') . '</a>'
                );
                ?>
            </p>
            <?php if ($configured_count > 0) : ?>
                <ul id="ai-provider-priority" class="ai-provider-priority">
                </ul>
                <p class="description"><?php esc_html_e('Priority order is stored per-browser.', 'ai-assistant'); ?>
                <?php if ($config['hasLocal'] ?? false) : ?>
                    <?php esc_html_e('Local providers connect directly from your browser.', 'ai-assistant'); ?>
                <?php endif; ?>
                </p>
                <style>
                    .ai-provider-priority { max-width: 500px; margin: 10px 0; padding: 0; list-style: none; }
                    .ai-provider-priority li {
                        display: flex; align-items: center; gap: 8px;
                        padding: 8px 12px; margin: 4px 0;
                        background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
                        cursor: grab; user-select: none;
                    }
                    .ai-provider-priority li:active { cursor: grabbing; }
                    .ai-provider-priority li.dragging { opacity: 0.5; }
                    .ai-provider-priority .ai-priority-handle { color: #999; }
                    .ai-provider-priority .ai-priority-name { flex: 1; font-weight: 500; }
                    .ai-provider-priority .ai-priority-models { color: #646970; font-size: 13px; }
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
                    var providers = <?php echo wp_json_encode(array_map(function($id, $p) {
                        return [
                            'id' => $id,
                            'name' => $p['name'],
                            'type' => $p['type'],
                            'modelCount' => count($p['models']),
                            'available' => (!empty($p['apiKey']) || $p['type'] === 'server') && !empty($p['models']),
                        ];
                    }, array_keys($config['available']), array_values($config['available']))); ?>;

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
                            $list.append(
                                '<li data-provider="' + p.id + '">' +
                                '<span class="ai-priority-handle">&#9776;</span>' +
                                '<span class="ai-priority-name">' + $('<span>').text(p.name).html() + '</span>' +
                                '<span class="ai-priority-models">' + modelsText + '</span>' +
                                '<span class="ai-priority-status ' + statusClass + '">' + statusText + '</span>' +
                                '</li>'
                            );
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

                    // Drag-and-drop reordering
                    var dragEl = null;
                    $list.on('dragstart', 'li', function(e) {
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
                    $list.children('li').attr('draggable', 'true');
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
        if (ai_assistant_is_playground()) {
            echo '<div class="ai-collapsible-content" data-section="tools">';
            echo '<p>' . esc_html__('All tools are automatically enabled in Playground.', 'ai-assistant') . '</p>';
            echo '</div>';
            return;
        }

        $all_tools = $this->get_all_tools_with_meta();
        $enabled = (array) get_option('ai_assistant_enabled_tools', $this->get_default_enabled_tools());

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
            <p><?php esc_html_e('Choose which tools the AI can use. ⚠ tools can modify files, run code, or install plugins.', 'ai-assistant'); ?></p>
            <input type="hidden" name="ai_assistant_enabled_tools" value="">
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
                    <label class="ai-tool-group-header">
                        <input type="checkbox" class="ai-group-toggle"
                               <?php checked($all_checked); ?>
                               <?php if ($some_checked) echo 'data-indeterminate="1"'; ?>>
                        <span><?php esc_html_e('Enable all tools in this tab', 'ai-assistant'); ?></span>
                    </label>
                    <div class="ai-tool-group-items">
                        <?php foreach ($tools as $name => $meta) : ?>
                        <label class="ai-tool-item">
                            <input type="checkbox"
                                   name="ai_assistant_enabled_tools[]"
                                   value="<?php echo esc_attr($name); ?>"
                                   <?php checked(in_array($name, $enabled, true)); ?>>
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
                            <p class="description" style="margin:4px 0 8px"><?php esc_html_e('Read-only abilities run without confirmation. Other abilities require approval unless checked below.', 'ai-assistant'); ?></p>
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
                                        $annotations = Ability_Annotations::get($ability);
                                        $readonly    = $annotations['readonly'] && !$annotations['destructive'];
                                        $destructive = $annotations['destructive'];
                                        $input_schema = $this->get_ability_input_schema($ability);
                                        if (is_object($ability)) {
                                            $ability_id  = method_exists($ability, 'get_name')        ? $ability->get_name()        : ($ability->name ?? $id);
                                            $label       = method_exists($ability, 'get_label')       ? $ability->get_label()       : ($ability->label ?? $ability_id);
                                            $description = method_exists($ability, 'get_description') ? $ability->get_description() : ($ability->description ?? '');
                                            $category    = method_exists($ability, 'get_category')    ? $ability->get_category()    : ($ability->category ?? '');
                                        } else {
                                            $ability_id  = $id;
                                            $label       = $ability['label'] ?? $ability['name'] ?? $id;
                                            $description = $ability['description'] ?? '';
                                            $category    = $ability['category'] ?? '';
                                        }
                                        $by_cat[$category][] = compact('ability_id', 'label', 'description', 'input_schema', 'readonly', 'destructive');
                                    }
                                    ?>
	                                    <div class="ai-abilities-list">
	                                            <?php foreach ($by_cat as $category => $cat_abilities) : ?>
	                                            <?php if ($category) : ?>
	                                            <div class="ai-ability-category"><?php echo esc_html($category); ?></div>
                                            <?php endif; ?>
                                            <?php foreach ($cat_abilities as $a) :
                                                $is_approved = in_array($a['ability_id'], $auto_approved, true);
                                                $details_payload = $this->get_ability_details_payload($a, $is_approved);
                                            ?>
                                            <div class="ai-tool-sub-item ai-ability-row">
                                                <input type="checkbox"
                                                       name="ai_assistant_auto_approved_abilities[]"
                                                       value="<?php echo esc_attr($a['ability_id']); ?>"
                                                       <?php checked($a['readonly'] || $is_approved); ?>
                                                       <?php disabled($a['readonly']); ?>>
                                                <button type="button"
                                                        class="ai-ability-select"
                                                        data-ability-details="<?php echo esc_attr($this->encode_json($details_payload)); ?>">
                                                    <code><?php echo esc_html($a['ability_id']); ?></code>
                                                    <?php if ($a['readonly']) : ?>
                                                        <span class="ai-ability-badge ai-ability-badge-readonly"><?php esc_html_e('Read-only', 'ai-assistant'); ?></span>
                                                    <?php elseif ($a['destructive']) : ?>
                                                        <span class="ai-ability-badge ai-ability-badge-destructive"><?php esc_html_e('Destructive', 'ai-assistant'); ?></span>
                                                    <?php elseif ($is_approved) : ?>
                                                        <span class="ai-ability-badge ai-ability-badge-approved"><?php esc_html_e('Always approved', 'ai-assistant'); ?></span>
                                                    <?php endif; ?>
                                                </button>
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
            // Sync group toggle with children
            $('.ai-group-toggle').each(function() {
                if ($(this).data('indeterminate')) this.indeterminate = true;
            });
            $('.ai-group-toggle').on('change', function() {
                var checked = this.checked;
                $(this).closest('.ai-tool-group').find('.ai-tool-group-items input[type=checkbox]').prop('checked', checked);
                this.indeterminate = false;
            });
            // Update group toggle when child changes
            $(document).on('change', '.ai-tool-group-items input[type=checkbox]', function() {
                var $group = $(this).closest('.ai-tool-group');
                var $children = $group.find('.ai-tool-group-items > label > input[type=checkbox]');
                var checkedCount = $children.filter(':checked').length;
                var $toggle = $group.find('.ai-group-toggle')[0];
                $toggle.checked = checkedCount === $children.length;
                $toggle.indeterminate = checkedCount > 0 && checkedCount < $children.length;
            });
            // Toggle sub-items visibility
            $(document).on('change', 'input[name="ai_assistant_enabled_tools[]"][value="execute_ability"]', function() {
                $(this).closest('.ai-tool-item').next('.ai-tool-sub-items').toggle(this.checked);
            });
            $(document).on('change', 'input[name="ai_assistant_enabled_tools[]"][value="rest_api"]', function() {
                $(this).closest('.ai-tool-item').next('.ai-tool-sub-items').toggle(this.checked);
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
                if (details.readonly) {
                    return renderAbilityBadge('<?php echo esc_js(__('Read-only', 'ai-assistant')); ?>', 'ai-ability-badge-readonly');
                }
                if (details.destructive) {
                    return renderAbilityBadge('<?php echo esc_js(__('Destructive', 'ai-assistant')); ?>', 'ai-ability-badge-destructive');
                }
                if (details.approved) {
                    return renderAbilityBadge('<?php echo esc_js(__('Always approved', 'ai-assistant')); ?>', 'ai-ability-badge-approved');
                }
                return '';
            }

            function renderAbilityInfo($button) {
                var details = parseAbilityDetails($button);
                var $layout = $button.closest('.ai-tool-tab-panel');
                var $panel = $layout.find('.ai-ability-info-panel');
                var parameters = Array.isArray(details.parameters) ? details.parameters : [];
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

                html += '<div class="ai-ability-params-heading"><?php echo esc_js(__('Parameters', 'ai-assistant')); ?></div>';
                if (!details.has_schema) {
                    html += '<p class="description ai-ability-no-params"><?php echo esc_js(__('No parameter schema available.', 'ai-assistant')); ?></p>';
                } else if (!parameters.length) {
                    html += '<p class="description ai-ability-no-params"><?php echo esc_js(__('No parameters.', 'ai-assistant')); ?></p>';
                } else {
                    html += '<div class="ai-ability-param-list">';
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
                }

                if (details.raw_schema) {
                    html += '<details class="ai-ability-raw-schema">';
                    html += '<summary><?php echo esc_js(__('Raw input schema', 'ai-assistant')); ?></summary>';
                    html += '<pre>' + escapeHtml(details.raw_schema) + '</pre>';
                    html += '</details>';
                }

                $layout.find('.ai-ability-select').removeClass('selected').removeAttr('aria-current');
                $button.addClass('selected').attr('aria-current', 'true');
                $panel.html(html);
            }

            $(document).on('click', '.ai-ability-select', function() {
                renderAbilityInfo($(this));
            });
        });
        </script>
        <?php
    }

    private function get_ability_input_schema($ability): ?array {
        $schema = null;
        $has_schema = false;

        if (is_object($ability)) {
            if (method_exists($ability, 'get_input_schema')) {
                try {
                    $schema = $ability->get_input_schema();
                    $has_schema = true;
                } catch (\Throwable $e) {
                    return null;
                }
            } elseif (isset($ability->input_schema)) {
                $schema = $ability->input_schema;
                $has_schema = true;
            } elseif (isset($ability->parameters)) {
                $schema = $ability->parameters;
                $has_schema = true;
            }
        } elseif (is_array($ability)) {
            if (array_key_exists('input_schema', $ability)) {
                $schema = $ability['input_schema'];
                $has_schema = true;
            } elseif (array_key_exists('parameters', $ability)) {
                $schema = $ability['parameters'];
                $has_schema = true;
            }
        }

        if (!$has_schema) {
            return null;
        }

        return is_array($schema) ? $schema : [];
    }

    private function get_ability_details_payload(array $ability, bool $is_approved): array {
        $schema = $ability['input_schema'];

        return [
            'id'          => (string) $ability['ability_id'],
            'label'       => (string) $ability['label'],
            'description' => (string) $ability['description'],
            'readonly'    => (bool) $ability['readonly'],
            'destructive' => (bool) $ability['destructive'],
            'approved'    => $is_approved,
            'has_schema'  => $schema !== null,
            'parameters'  => $schema !== null ? $this->get_schema_parameters($schema) : [],
            'raw_schema'  => $schema !== null && !empty($schema) ? $this->encode_json($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
        ];
    }

    private function get_schema_parameters(array $schema): array {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return [];
        }

        $required = [];
        foreach ((array) ($schema['required'] ?? []) as $required_name) {
            if (is_scalar($required_name)) {
                $required[(string) $required_name] = true;
            }
        }
        $parameters = [];

        foreach ($properties as $name => $property_schema) {
            $property_schema = is_array($property_schema) ? $property_schema : [];
            $parameters[] = [
                'name'        => (string) $name,
                'type'        => $this->get_schema_type_label($property_schema),
                'description' => (string) ($property_schema['description'] ?? ''),
                'required'    => isset($required[(string) $name]),
                'notes'       => $this->get_schema_notes($property_schema),
            ];
        }

        return $parameters;
    }

    private function get_schema_type_label(array $schema): string {
        $type = $schema['type'] ?? '';
        if (is_array($type)) {
            $type = implode('|', array_map('strval', $type));
        }

        if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            return 'array<' . $this->get_schema_type_label($schema['items']) . '>';
        }

        if (!$type && isset($schema['enum'])) {
            return 'enum';
        }

        return $type ? (string) $type : 'any';
    }

    private function get_schema_notes(array $schema): array {
        $notes = [];

        if (!empty($schema['enum']) && is_array($schema['enum'])) {
            $notes[] = sprintf(
                /* translators: %s: comma-separated enum values */
                __('Allowed: %s', 'ai-assistant'),
                implode(', ', array_map([$this, 'format_schema_value'], $schema['enum']))
            );
        }

        if (array_key_exists('default', $schema)) {
            $notes[] = sprintf(
                /* translators: %s: JSON schema default value */
                __('Default: %s', 'ai-assistant'),
                $this->format_schema_value($schema['default'])
            );
        }

        foreach (['format', 'pattern', 'minimum', 'maximum', 'minLength', 'maxLength'] as $key) {
            if (isset($schema[$key]) && is_scalar($schema[$key])) {
                $notes[] = sprintf('%s: %s', $key, (string) $schema[$key]);
            }
        }

        if (!empty($schema['properties']) && is_array($schema['properties'])) {
            $notes[] = sprintf(
                /* translators: %s: comma-separated nested field names */
                __('Fields: %s', 'ai-assistant'),
                implode(', ', array_map('strval', array_keys($schema['properties'])))
            );
        }

        return $notes;
    }

    private function format_schema_value($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $this->encode_json($value);
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
        $show_on_frontend = get_option('ai_assistant_show_on_frontend', '0');
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
                box-shadow: 0 0 0 1px #2271b1;
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
            .ai-tool-group-header {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 0 0 8px;
                padding: 0 0 9px;
                border-bottom: 1px solid #dcdcde;
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
                align-items: flex-start;
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
            .ai-ability-select:hover,
            .ai-ability-select:focus,
            .ai-ability-select.selected {
                background: #f6f7f7;
                border-color: #dcdcde;
                outline: none;
            }
            .ai-ability-select:focus {
                box-shadow: 0 0 0 1px #2271b1;
            }
            .ai-ability-select code,
            .ai-ability-info-header code,
            .ai-ability-param code {
                overflow-wrap: anywhere;
            }
            .ai-ability-info-panel {
                background: transparent;
                border-left: 1px solid #dcdcde;
                box-shadow: none;
                min-height: 120px;
                overflow: auto;
                padding: 0 0 0 14px;
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
            .ai-ability-raw-schema {
                margin-top: 6px;
            }
            .ai-ability-raw-schema summary {
                color: #646970;
                cursor: pointer;
                font-size: 11px;
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
            .ai-ability-badge-approved {
                background: #eaf2ff;
                color: #135e96;
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
                }
            }
        </style>

        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post" id="ai-assistant-settings-form">
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
                    var value = $el.val();
                    var storageKey = 'aiAssistant_' + key;
                    if (value) {
                        localStorage.setItem(storageKey, value);
                    } else {
                        localStorage.removeItem(storageKey);
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
     * Get skills help tab content
     */
    private function get_skills_help_content(): string {
        $skills_dir = plugin_dir_path(__DIR__) . 'skills/';

        if (!is_dir($skills_dir)) {
            return '<p>' . __('No skills available. Add .md files to the skills/ directory.', 'ai-assistant') . '</p>';
        }

        $files = glob($skills_dir . '*.md');
        if (empty($files)) {
            return '<p>' . __('No skills available. Add .md files to the skills/ directory.', 'ai-assistant') . '</p>';
        }

        $skills_by_category = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $frontmatter = [];
            if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
                foreach (explode("\n", $matches[1]) as $line) {
                    if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                        $frontmatter[$kv[1]] = trim($kv[2], '"\'');
                    }
                }
            }

            $skill_id = basename($file, '.md');
            $category = $frontmatter['category'] ?? 'general';

            $skills_by_category[$category][] = [
                'id' => $skill_id,
                'title' => $frontmatter['title'] ?? $skill_id,
                'description' => $frontmatter['description'] ?? '',
            ];
        }

        ksort($skills_by_category);

        $nonce = wp_create_nonce('ai_assistant_skills');

        $html = '<style>
            .ai-skill-accordion { margin: 0; padding: 0; list-style: none; }
            .ai-skill-item { border: 1px solid #ddd; margin-bottom: -1px; }
            .ai-skill-header { display: block; padding: 10px 12px; background: #f9f9f9; cursor: pointer; text-decoration: none; color: inherit; }
            .ai-skill-header:hover { background: #f0f0f0; }
            .ai-skill-header:focus { outline: 2px solid #2271b1; outline-offset: -2px; }
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
        $enabled_tools = $this->get_user_enabled_tools();

        $prompt .= <<<'PROMPT'


TOOL USAGE RULES:

If the user describes something they are seeing on the page, references UI elements, or asks about content visible on screen, use get_page_html to see what they're looking at.

PROMPT;

        if (!empty($ability_domains)) {
            $prompt .= "The following topics are handled by plugin abilities. For these, ALWAYS use the ability tool — never db_query, find, or run_php:\n";
            foreach ($ability_domains as $slug => $keywords) {
                $prompt .= "- $slug: $keywords\n";
            }
            $prompt .= "\n";
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
- The edit_file tool takes an array of {search, replace} pairs - each search string must be unique in the file
- If an edit_file operation fails (string not found or not unique), use read_file to see the current content and retry

PLUGIN CREATION:
- Always create plugins in their own subdirectory (e.g., plugins/my-plugin-mywp/my-plugin-mywp.php), NEVER as single files directly in the plugins folder
- When creating new plugins, always use the suffix "-mywp" for the plugin slug (e.g., "gallery-mywp", "contact-form-mywp")
- This prevents conflicts with plugins in the WordPress.org directory

IMPORTANT: For any destructive operations (file deletion, database modification, file overwriting), the user will be asked to confirm before execution. Be clear about what changes you're proposing.

Always explain what you're about to do before using tools.
PROMPT;

        $prompt .= $this->load_skills();

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
     * Load all skill files from the skills directory
     */
    private function load_skills() {
        $skills_dir = dirname(__DIR__) . '/skills';
        if (!is_dir($skills_dir)) {
            return '';
        }

        $skill_files = glob($skills_dir . '/*.md');
        if (empty($skill_files)) {
            return '';
        }

        $skills_content = "\n\n=== SKILLS ===\nThe following skill documents contain important guidance. Follow them carefully.\n";

        foreach ($skill_files as $file) {
            $content = file_get_contents($file);
            $content = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
            $skill_name = basename($file, '.md');
            $skills_content .= "\n--- SKILL: {$skill_name} ---\n{$content}\n";
        }

        return $skills_content;
    }
}
