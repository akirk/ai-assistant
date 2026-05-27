<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WpApp-powered frontend conversation screen.
 */
class Conversations_App {

    const URL_PATH = 'ai-assistant/conversations';

    private $app = null;

    public function __construct() {
        if (!class_exists('\WpApp\WpApp')) {
            return;
        }

        $this->app = new \WpApp\WpApp(
            AI_ASSISTANT_PLUGIN_DIR . 'templates/conversations',
            self::URL_PATH,
            [
                'app_name' => 'AI Assistant',
                'require_capability' => 'edit_posts',
                'admin_bar_app_link' => false,
                'my_apps' => false,
            ]
        );
        $this->app->init();
    }

    public function is_available(): bool {
        return $this->app instanceof \WpApp\WpApp;
    }

    public static function get_url(): string {
        if (!class_exists('\WpApp\WpApp')) {
            return admin_url('tools.php?page=ai-conversations');
        }

        return home_url('/' . self::URL_PATH . '/');
    }

    public static function get_conversation_url($conversation_id): string {
        $url = self::get_url();
        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . 'conversation=' . intval($conversation_id);
    }

    public static function get_request_conversation_id(): int {
        if (isset($_GET['conversation'])) {
            return intval($_GET['conversation']);
        }

        if (isset($_GET['conversation_id'])) {
            return intval($_GET['conversation_id']);
        }

        return 0;
    }

    public static function render_chat_shell(array $args = []): void {
        $conversation_id = isset($args['conversation_id'])
            ? intval($args['conversation_id'])
            : self::get_request_conversation_id();
        $settings_url = isset($args['settings_url'])
            ? (string) $args['settings_url']
            : admin_url('options-general.php?page=ai-assistant-settings');
        $container_class = isset($args['container_class'])
            ? (string) $args['container_class']
            : 'ai-assistant-page';
        ?>
        <div class="<?php echo esc_attr($container_class); ?>">
            <div class="ai-chat-layout">
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

                <div class="ai-chat-main">
                    <div class="ai-chat-main-header">
                        <button type="button" class="ai-sidebar-toggle" id="ai-sidebar-toggle">
                            <span class="dashicons dashicons-menu"></span> <?php esc_html_e('Chats', 'ai-assistant'); ?>
                        </button>
                        <div class="ai-header-actions ai-conversation-header-actions">
                            <div id="ai-token-count" class="ai-token-count" tabindex="0" aria-label="<?php esc_attr_e('Token usage', 'ai-assistant'); ?>">0 tokens</div>
                            <button type="button" id="ai-assistant-summarize" class="ai-header-btn" title="<?php esc_attr_e('Generate conversation summary', 'ai-assistant'); ?>" style="display: none;">
                                <span class="dashicons dashicons-media-text"></span>
                            </button>
                            <span class="ai-header-right-actions">
                                <label class="ai-auto-approve-label" title="<?php esc_attr_e('Automatically approve destructive actions without confirmation', 'ai-assistant'); ?>"><input type="checkbox" id="ai-assistant-auto-approve"> <?php esc_html_e('Auto-approve', 'ai-assistant'); ?></label>
                                <span class="ai-export-menu-wrap">
                                    <button type="button" id="ai-assistant-export" class="ai-header-btn ai-export-toggle" title="<?php esc_attr_e('Export conversation', 'ai-assistant'); ?>" aria-haspopup="true" aria-expanded="false">
                                        <span class="dashicons dashicons-download"></span>
                                    </button>
                                    <span id="ai-assistant-export-menu" class="ai-export-menu" role="menu" hidden></span>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="ai-assistant-chat-container">
                        <div id="ai-assistant-messages"></div>
                        <div class="ai-assistant-drop-zone" aria-hidden="true">
                            <div class="ai-assistant-drop-zone-inner"><?php esc_html_e('Drop files to attach', 'ai-assistant'); ?></div>
                        </div>
                        <div id="ai-assistant-loading" style="display: none;">
                            <div class="ai-loading-dots"><span></span><span></span><span></span></div>
                            <div class="ai-loading-status" aria-live="polite" style="display: none;"></div>
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
            var aiAssistantPageConfig = window.aiAssistantPageConfig = {
                conversationId: <?php echo intval($conversation_id); ?>,
                isFullPage: true
            };
            document.addEventListener('DOMContentLoaded', function() {
                var toggle = document.getElementById('ai-sidebar-toggle');
                var sidebar = document.querySelector('.ai-chat-sidebar');
                if (toggle && sidebar) {
                    toggle.addEventListener('click', function() {
                        sidebar.classList.toggle('mobile-visible');
                    });
                }

                document.addEventListener('click', function(event) {
                    if (window.innerWidth > 782 || !sidebar) {
                        return;
                    }
                    if (event.target.closest('.ai-conv-item')) {
                        sidebar.classList.remove('mobile-visible');
                    }
                });
            });
        </script>
        <?php
    }
}
