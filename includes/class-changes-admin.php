<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Changes_Admin {

    private $git_tracker_manager;
    private $executor;

    public function __construct(Git_Tracker_Manager $git_tracker_manager) {
        $this->git_tracker_manager = $git_tracker_manager;
        $this->executor = new Executor(new Tools(), $git_tracker_manager);
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('load-tools_page_ai-changes', [$this, 'add_help_tabs']);
        add_action('wp_ajax_ai_assistant_get_changes', [$this, 'ajax_get_changes']);
        add_action('wp_ajax_ai_assistant_get_changes_by_plugin', [$this, 'ajax_get_changes_by_plugin']);
        add_action('wp_ajax_ai_assistant_generate_diff', [$this, 'ajax_generate_diff']);
        add_action('wp_ajax_ai_assistant_get_file_content', [$this, 'ajax_get_file_content']);
        add_action('wp_ajax_ai_assistant_apply_patch', [$this, 'ajax_apply_patch']);
        add_action('wp_ajax_ai_assistant_revert_file', [$this, 'ajax_revert_file']);
        add_action('wp_ajax_ai_assistant_reapply_file', [$this, 'ajax_reapply_file']);
        add_action('wp_ajax_ai_assistant_revert_files', [$this, 'ajax_revert_files']);
        add_action('wp_ajax_ai_assistant_lint_php', [$this, 'ajax_lint_php']);
        add_action('wp_ajax_ai_assistant_get_commit_log', [$this, 'ajax_get_commit_log']);
        add_action('wp_ajax_ai_assistant_get_commit_diff', [$this, 'ajax_get_commit_diff']);
        add_action('wp_ajax_ai_assistant_update_commit_message', [$this, 'ajax_update_commit_message']);
        add_action('wp_ajax_ai_assistant_checkout_commit', [$this, 'ajax_checkout_commit']);
        add_action('wp_ajax_ai_assistant_revert_to_commit', [$this, 'ajax_checkout_commit']);
        add_action('admin_action_ai_assistant_checkout_version', [$this, 'handle_checkout_version']);
        add_action('admin_action_ai_assistant_download_diff', [$this, 'handle_diff_download']);
    }

    public function add_admin_page(): void {
        add_management_page(
            __('AI Changes', 'ai-assistant'),
            __('AI Changes', 'ai-assistant'),
            'manage_options',
            'ai-changes',
            [$this, 'render_page']
        );
    }

    private function format_time_ago(?int $timestamp): string {
        if (!$timestamp) {
            return '';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('just now', 'ai-assistant');
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return sprintf(_n('%d min ago', '%d mins ago', $mins, 'ai-assistant'), $mins);
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, 'ai-assistant'), $hours);
        }
        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);
            return sprintf(_n('%d day ago', '%d days ago', $days, 'ai-assistant'), $days);
        }

        return date_i18n('M j', $timestamp);
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'tools_page_ai-changes') {
            return;
        }

        wp_enqueue_style(
            'ai-assistant-changes',
            AI_ASSISTANT_PLUGIN_URL . 'assets/css/changes.css',
            ['wp-codemirror'],
            AI_ASSISTANT_VERSION
        );
        wp_add_inline_style(
            'ai-assistant-changes',
            Admin_Colors::get_current_scheme_css(':root, body, .ai-changes-wrap')
        );

        wp_enqueue_script('wp-codemirror');

        wp_enqueue_script(
            'ai-assistant-changes',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/changes.js',
            ['jquery', 'wp-codemirror'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_localize_script('ai-assistant-changes', 'aiChanges', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_assistant_changes'),
            'strings' => [
                'importing' => __('Importing...', 'ai-assistant'),
                'importSuccess' => __('Patch applied successfully! %d file(s) modified.', 'ai-assistant'),
                'importError' => __('Failed to apply patch.', 'ai-assistant'),
                'checkPhpSyntax' => __('Check PHP syntax', 'ai-assistant'),
                'checkingPhpSyntax' => __('Checking...', 'ai-assistant'),
                'noPhpFiles' => __('No PHP files found.', 'ai-assistant'),
                'syntaxChecked' => __('PHP syntax OK.', 'ai-assistant'),
                'syntaxError' => __('Syntax Error', 'ai-assistant'),
                'syntaxErrorsFound' => __('%d syntax error(s).', 'ai-assistant'),
                'syntaxCheckFailed' => __('Syntax check failed', 'ai-assistant'),
                'syntaxOk' => __('Syntax OK', 'ai-assistant'),
                'checkingOutCommit' => __('Checking out...', 'ai-assistant'),
                'checkoutCommitError' => __('Failed to check out commit.', 'ai-assistant'),
                'loading' => __('Loading...', 'ai-assistant'),
                'loadMore' => __('Load more', 'ai-assistant'),
                'latest' => __('latest', 'ai-assistant'),
                'checkedOut' => __('checked out', 'ai-assistant'),
                'checkout' => __('Checkout', 'ai-assistant'),
                'checkoutCommitTitle' => __('Check out files from this commit', 'ai-assistant'),
                'editCommitMessage' => __('Edit commit message', 'ai-assistant'),
                'commitMessageLabel' => __('Commit message', 'ai-assistant'),
                'commitMessageEditHint' => __('Double-click to edit commit message', 'ai-assistant'),
                'saveCommitMessage' => __('Save', 'ai-assistant'),
                'cancelCommitMessage' => __('Cancel', 'ai-assistant'),
                'updatingCommitMessage' => __('Saving...', 'ai-assistant'),
                'updateCommitMessageError' => __('Failed to update commit message.', 'ai-assistant'),
                'justNow' => __('just now', 'ai-assistant'),
                'noCommits' => __('No commits yet', 'ai-assistant'),
                'viewConversation' => __('View conversation', 'ai-assistant'),
                'expandFilePreview' => __('Expand preview', 'ai-assistant'),
                'collapseFilePreview' => __('Limit preview height', 'ai-assistant'),
            ],
        ]);
    }

    public function add_help_tabs(): void {
        $screen = get_current_screen();

        $screen->add_help_tab([
            'id'      => 'ai-changes-overview',
            'title'   => __('Overview', 'ai-assistant'),
            'content' => '<p>' . __('The AI Changes page tracks file modifications made by the AI Assistant. The overview links to a separate page for each plugin or theme with tracked changes.', 'ai-assistant') . '</p>'
                       . '<p>' . __('Use the plugin or theme page to review changed files, inspect commit diffs, and check out a previous commit state.', 'ai-assistant') . '</p>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-changes-actions',
            'title'   => __('Actions', 'ai-assistant'),
            'content' => '<p>' . __('Available actions:', 'ai-assistant') . '</p>'
                       . '<ul>'
                       . '<li><strong>' . __('Import Patch', 'ai-assistant') . '</strong> - ' . __('Upload a .patch, .diff, or .txt file to apply changes to your files.', 'ai-assistant') . '</li>'
                       . '<li><strong>' . __('Download ZIP', 'ai-assistant') . '</strong> - ' . __('Download the plugin or theme with its git history.', 'ai-assistant') . '</li>'
                       . '<li><strong>' . __('Checkout Commit', 'ai-assistant') . '</strong> - ' . __('Check out all tracked files from a previous commit state.', 'ai-assistant') . '</li>'
                       . '</ul>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-changes-diff',
            'title'   => __('Diff Preview', 'ai-assistant'),
            'content' => '<p>' . __('Click the arrow (▶) next to any file or commit to preview its diff inline.', 'ai-assistant') . '</p>'
                       . '<p>' . __('The diff shows:', 'ai-assistant') . '</p>'
                       . '<ul>'
                       . '<li><span style="color: #22863a;">+ Green lines</span> - ' . __('Added content', 'ai-assistant') . '</li>'
                       . '<li><span style="color: #cb2431;">- Red lines</span> - ' . __('Removed content', 'ai-assistant') . '</li>'
                       . '</ul>',
        ]);

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', 'ai-assistant') . '</strong></p>'
            . '<p><a href="' . esc_url(Conversations_App::get_url()) . '">' . __('AI Conversations', 'ai-assistant') . '</a></p>'
            . '<p><a href="' . esc_url(admin_url('options-general.php?page=ai-assistant-settings')) . '">' . __('Plugin Settings', 'ai-assistant') . '</a></p>'
        );
    }

    public function render_page(): void {
        $plugins = $this->git_tracker_manager->get_all_changes_by_plugin();
        $selected_plugin_path = $this->get_requested_plugin_path();
        $selected_plugin = $selected_plugin_path !== '' && isset($plugins[$selected_plugin_path])
            ? $plugins[$selected_plugin_path]
            : null;
        ?>
        <div class="wrap ai-changes-wrap<?php echo $selected_plugin ? ' ai-changes-wrap-detail' : ''; ?>">
            <?php if ($selected_plugin): ?>
                <h1>
                    <?php echo esc_html(sprintf(__('AI Changes: %s', 'ai-assistant'), $selected_plugin['name'])); ?>
                    <a href="<?php echo esc_url($this->get_all_changes_url()); ?>" class="page-title-action"><?php esc_html_e('All plugins', 'ai-assistant'); ?></a>
                    <a href="<?php echo esc_url($this->get_plugin_download_url($selected_plugin_path)); ?>" class="page-title-action" title="<?php esc_attr_e('Download as ZIP with git history', 'ai-assistant'); ?>">
                        <?php esc_html_e('Download ZIP', 'ai-assistant'); ?>
                    </a>
                </h1>

                <p class="description">
                    <?php esc_html_e('Review tracked files, commits, and diffs. ZIP downloads include the .git directory, so you can inspect the history with Git tools, add local commits, or push the repository to GitHub while retaining the commit history.', 'ai-assistant'); ?>
                </p>

                <?php $this->render_plugin_detail($selected_plugin_path, $selected_plugin); ?>
            <?php else: ?>
                <h1><?php esc_html_e('AI Changes', 'ai-assistant'); ?></h1>

                <p class="description">
                    <?php esc_html_e('Track and export changes made by the AI assistant. Choose a plugin or theme to review its files, commits, and conversation references.', 'ai-assistant'); ?>
                </p>

                <?php if ($selected_plugin_path !== ''): ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html(sprintf(__('No AI changes found for %s.', 'ai-assistant'), $selected_plugin_path)); ?></p>
                </div>
                <?php endif; ?>

                <?php $this->render_plugins_index($plugins); ?>
            <?php endif; ?>

            <?php $this->render_import_section(); ?>
        </div>
        <?php
    }

    private function get_requested_plugin_path(): string {
        if (!isset($_GET['plugin']) || !is_string($_GET['plugin'])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash($_GET['plugin'])), '/');
    }

    private function get_all_changes_url(): string {
        return admin_url('tools.php?page=ai-changes');
    }

    private function get_plugin_page_url(string $plugin_path): string {
        return admin_url('tools.php?page=ai-changes&plugin=' . rawurlencode($plugin_path));
    }

    private function get_plugin_download_url(string $plugin_path): string {
        return wp_nonce_url(
            admin_url('admin.php?action=ai_assistant_download_plugin&path=' . urlencode($plugin_path)),
            'ai_assistant_download_' . $plugin_path
        );
    }

    private function render_plugins_index(array $plugins): void {
        if (empty($plugins)) {
            ?>
            <div class="ai-changes-empty">
                <p><?php esc_html_e('No AI changes tracked yet.', 'ai-assistant'); ?></p>
                <p class="description"><?php esc_html_e('When the assistant modifies plugin or theme files, they will appear here.', 'ai-assistant'); ?></p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="ai-plugin-index">
            <?php foreach ($plugins as $plugin_path => $plugin):
                $file_count = (int) ($plugin['file_count'] ?? 0);
                $commit_count = (int) ($plugin['commit_count'] ?? 0);
                $latest_commit = !empty($plugin['commits'][0]) ? $plugin['commits'][0] : null;
            ?>
            <div class="ai-plugin-index-card">
                <div class="ai-plugin-index-main">
                    <a class="ai-plugin-index-name" href="<?php echo esc_url($this->get_plugin_page_url($plugin_path)); ?>">
                        <?php echo esc_html($plugin['name']); ?>
                    </a>
                    <?php if ($latest_commit): ?>
                    <span class="ai-plugin-index-latest">
                        <?php echo esc_html(sprintf(__('Latest: %s', 'ai-assistant'), $latest_commit['message'])); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <span class="ai-plugin-stats">
                    <?php echo esc_html($file_count); ?> <?php echo $file_count === 1 ? esc_html__('file', 'ai-assistant') : esc_html__('files', 'ai-assistant'); ?>,
                    <?php echo esc_html($commit_count); ?> <?php echo $commit_count === 1 ? esc_html__('commit', 'ai-assistant') : esc_html__('commits', 'ai-assistant'); ?>
                </span>
                <div class="ai-plugin-index-actions">
                    <a href="<?php echo esc_url($this->get_plugin_page_url($plugin_path)); ?>" class="button button-small">
                        <?php esc_html_e('Review Changes', 'ai-assistant'); ?>
                    </a>
                    <a href="<?php echo esc_url($this->get_plugin_download_url($plugin_path)); ?>" class="button button-small" title="<?php esc_attr_e('Download as ZIP with git history', 'ai-assistant'); ?>">
                        <?php esc_html_e('Download ZIP', 'ai-assistant'); ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_plugin_detail(string $plugin_path, array $plugin): void {
        ?>
        <div class="ai-changes-plugin-detail" data-plugin="<?php echo esc_attr($plugin_path); ?>">
            <?php $this->render_plugin_commits($plugin); ?>
            <?php $this->render_plugin_files($plugin_path, $plugin); ?>
        </div>
        <?php
    }

    private function render_plugin_commits(array $plugin): void {
        if (empty($plugin['commits'])) {
            return;
        }

        $has_checked_out_commit = !empty($plugin['checked_out_sha']);
        $commit_count = count($plugin['commits']);
        ?>
        <section class="ai-plugin-commits ai-changes-panel">
            <div class="ai-changes-panel-header">
                <h2><?php esc_html_e('Recent commits', 'ai-assistant'); ?></h2>
                <span class="ai-changes-panel-count">
                    <?php echo esc_html($commit_count); ?> <?php echo $commit_count === 1 ? esc_html__('commit', 'ai-assistant') : esc_html__('commits', 'ai-assistant'); ?>
                </span>
            </div>
            <?php foreach ($plugin['commits'] as $commit):
                $commit_row_classes = ['ai-commit-row'];
                if (!empty($commit['is_checked_out'])) {
                    $commit_row_classes[] = 'ai-commit-checked-out';
                } elseif (!empty($commit['is_latest'])) {
                    $commit_row_classes[] = 'ai-commit-latest';
                }
            ?>
            <div class="ai-commit-entry" data-sha="<?php echo esc_attr($commit['sha']); ?>">
                <div class="<?php echo esc_attr(implode(' ', $commit_row_classes)); ?>">
                    <div class="ai-commit-row-top">
                        <button type="button" class="ai-commit-diff-toggle" data-sha="<?php echo esc_attr($commit['sha']); ?>" title="<?php esc_attr_e('Preview diff', 'ai-assistant'); ?>">▶</button>
                        <span class="ai-commit-sha" role="button" tabindex="0" title="<?php esc_attr_e('Preview diff', 'ai-assistant'); ?>"><?php echo esc_html($commit['short_sha']); ?></span>
                        <span class="ai-commit-message"
                              title="<?php esc_attr_e('Double-click to edit commit message', 'ai-assistant'); ?>"
                              tabindex="0"><?php echo esc_html($commit['message']); ?></span>
                        <span class="ai-commit-message-editor" hidden>
                            <input type="text"
                                   class="regular-text ai-commit-message-input"
                                   value="<?php echo esc_attr($commit['message']); ?>"
                                   aria-label="<?php esc_attr_e('Commit message', 'ai-assistant'); ?>">
                            <button type="button" class="button button-primary button-small ai-save-commit-message">
                                <?php esc_html_e('Save', 'ai-assistant'); ?>
                            </button>
                            <button type="button" class="button button-small ai-cancel-commit-message">
                                <?php esc_html_e('Cancel', 'ai-assistant'); ?>
                            </button>
                        </span>
                        <button type="button"
                                class="button-link ai-edit-commit-message"
                                data-sha="<?php echo esc_attr($commit['sha']); ?>"
                                title="<?php esc_attr_e('Edit commit message', 'ai-assistant'); ?>">
                            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                            <span class="screen-reader-text"><?php esc_html_e('Edit commit message', 'ai-assistant'); ?></span>
                        </button>
                        <?php if (!empty($commit['conversation_id'])): ?>
                        <a href="<?php echo esc_url(Conversations_App::get_conversation_url($commit['conversation_id'])); ?>"
                           class="ai-commit-conversation"
                           data-id="<?php echo esc_attr($commit['conversation_id']); ?>"
                           title="<?php esc_attr_e('View conversation', 'ai-assistant'); ?>">
                            Conv #<?php echo esc_html($commit['conversation_id']); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="ai-commit-row-bottom">
                        <span class="ai-commit-date" title="<?php echo esc_attr($commit['date']); ?>"><?php echo esc_html($this->format_time_ago($commit['timestamp'])); ?></span>
                        <?php if (!empty($commit['is_latest'])): ?>
                        <span class="ai-commit-label"><?php esc_html_e('latest', 'ai-assistant'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($commit['is_checked_out'])): ?>
                        <span class="ai-commit-label ai-commit-label-checked-out"><?php esc_html_e('checked out', 'ai-assistant'); ?></span>
                        <?php endif; ?>
                        <?php if (empty($commit['is_checked_out']) && ($has_checked_out_commit || empty($commit['is_latest']))): ?>
                        <button type="button" class="button button-small ai-checkout-commit" data-sha="<?php echo esc_attr($commit['sha']); ?>" title="<?php esc_attr_e('Check out files from this commit', 'ai-assistant'); ?>">
                            <?php esc_html_e('Checkout', 'ai-assistant'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ai-commit-diff-preview" data-sha="<?php echo esc_attr($commit['sha']); ?>" style="display: none;">
                    <pre><code></code></pre>
                </div>
                <button
                    type="button"
                    class="ai-preview-height-toggle ai-commit-preview-height-toggle"
                    aria-expanded="false"
                    style="display: none;"
                >
                    <span class="ai-preview-height-arrow" aria-hidden="true">▼</span>
                    <span class="ai-preview-height-label"><?php esc_html_e('Expand preview', 'ai-assistant'); ?></span>
                </button>
            </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    private function render_plugin_files(string $plugin_path, array $plugin): void {
        $file_count = isset($plugin['files']) && is_array($plugin['files']) ? count($plugin['files']) : 0;
        ?>
        <section class="ai-plugin-files ai-changes-panel">
            <div class="ai-changes-panel-header">
                <h2><?php esc_html_e('Changed files', 'ai-assistant'); ?></h2>
                <span class="ai-changes-panel-count">
                    <?php echo esc_html($file_count); ?> <?php echo $file_count === 1 ? esc_html__('file', 'ai-assistant') : esc_html__('files', 'ai-assistant'); ?>
                </span>
            </div>
            <?php foreach ($plugin['files'] as $file):
                $change_types = isset($file['change_types']) && is_array($file['change_types'])
                    ? array_values(array_unique(array_filter($file['change_types'], 'is_string')))
                    : [(string) ($file['change_type'] ?? '')];
                $preview_type = in_array('created', $change_types, true) ? 'content' : 'diff';
            ?>
            <div class="ai-changes-file" data-preview-type="<?php echo esc_attr($preview_type); ?>">
                <div class="ai-changes-file-row">
                    <button type="button" class="ai-file-preview-toggle" data-path="<?php echo esc_attr($file['path']); ?>" title="<?php esc_attr_e('Preview diff', 'ai-assistant'); ?>">▶</button>
                    <span class="ai-changes-file-path"><?php echo esc_html($file['relative_path'] ?: basename($file['path'])); ?></span>
                    <span class="ai-lint-status" data-path="<?php echo esc_attr($file['path']); ?>"></span>
                    <span class="ai-changes-file-badges">
                        <?php foreach ($change_types as $change_type):
                            if ($change_type === '') {
                                continue;
                            }
                        ?>
                        <span class="ai-changes-type ai-changes-type-<?php echo esc_attr($change_type); ?>">
                            <?php echo esc_html($this->get_change_type_label($change_type)); ?>
                        </span>
                        <?php endforeach; ?>
                        <?php if (!empty($file['is_reverted'])): ?>
                        <span class="ai-changes-type ai-changes-type-reverted">
                            <?php echo esc_html($this->get_change_type_label('reverted')); ?>
                        </span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="ai-file-inline-preview" data-path="<?php echo esc_attr($file['path']); ?>" style="display: none;">
                    <pre><code></code></pre>
                </div>
                <button
                    type="button"
                    class="ai-preview-height-toggle ai-file-preview-height-toggle"
                    data-path="<?php echo esc_attr($file['path']); ?>"
                    aria-expanded="false"
                    style="display: none;"
                >
                    <span class="ai-preview-height-arrow" aria-hidden="true">▼</span>
                    <span class="ai-preview-height-label"><?php esc_html_e('Expand preview', 'ai-assistant'); ?></span>
                </button>
            </div>
            <?php endforeach; ?>
            <div class="ai-plugin-files-actions">
                <button type="button" class="button button-small ai-lint-files">
                    <?php esc_html_e('Check PHP syntax', 'ai-assistant'); ?>
                </button>
                <span class="ai-lint-summary" role="status" aria-live="polite"></span>
            </div>
        </section>
        <?php
    }

    private function get_change_type_label(string $change_type): string {
        switch ($change_type) {
            case 'created':
                return __('Created', 'ai-assistant');
            case 'modified':
                return __('Changed', 'ai-assistant');
            case 'deleted':
                return __('Deleted', 'ai-assistant');
            case 'reverted':
                return __('Reverted', 'ai-assistant');
            default:
                return ucfirst($change_type);
        }
    }

    private function render_import_section(): void {
        ?>
        <div class="ai-import-section">
            <h2><?php esc_html_e('Import Patch', 'ai-assistant'); ?></h2>
            <p class="description">
                <?php esc_html_e('Apply a patch file to modify files in your wp-content directory. Supports unified diff format (.patch, .diff, or .txt files).', 'ai-assistant'); ?>
            </p>
            <input type="file" id="ai-patch-file" accept=".patch,.diff,.txt" style="display:none;">
            <button type="button" class="button" id="ai-import-patch">
                <?php esc_html_e('Choose Patch File...', 'ai-assistant'); ?>
            </button>
        </div>
        <?php
    }

    public function ajax_get_changes(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $directories = $this->git_tracker_manager->get_all_changes_by_directory();
        wp_send_json_success(['directories' => $directories]);
    }

    public function ajax_get_changes_by_plugin(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $plugins = $this->git_tracker_manager->get_all_changes_by_plugin();
        wp_send_json_success(['plugins' => $plugins]);
    }

    public function ajax_generate_diff(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_paths = isset($_POST['file_paths']) ? array_map('sanitize_text_field', (array) $_POST['file_paths']) : [];

        if (empty($file_paths)) {
            wp_send_json_error(['message' => 'No files selected']);
        }

        if (count($file_paths) === 1 && $this->git_tracker_manager->is_created_file($file_paths[0])) {
            $content = $this->git_tracker_manager->get_current_content($file_paths[0]);
            if ($content !== null) {
                wp_send_json_success([
                    'type' => 'content',
                    'content' => $content,
                    'path' => $file_paths[0],
                ]);
            }
        }

        $diff = $this->git_tracker_manager->generate_diff($file_paths);
        wp_send_json_success([
            'type' => 'diff',
            'diff' => $diff,
        ]);
    }

    public function ajax_get_file_content(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if ($file_path === '') {
            wp_send_json_error(['message' => 'No file specified']);
        }

        if (!$this->git_tracker_manager->is_created_file($file_path)) {
            wp_send_json_error(['message' => 'File is not tracked as created']);
        }

        $content = $this->git_tracker_manager->get_current_content($file_path);
        if ($content === null) {
            wp_send_json_error(['message' => 'Failed to read file']);
        }

        wp_send_json_success([
            'type' => 'content',
            'content' => $content,
            'path' => $file_path,
        ]);
    }

    public function ajax_apply_patch(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        if (empty($_FILES['patch_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }

        $file = $_FILES['patch_file'];
        $patch_content = file_get_contents($file['tmp_name']);

        if ($patch_content === false) {
            wp_send_json_error(['message' => 'Failed to read file']);
        }

        try {
            $operations = $this->parse_patch($patch_content);

            if (empty($operations)) {
                wp_send_json_error(['message' => 'No valid operations found in patch']);
            }

            $modified = 0;
            foreach ($operations as $op) {
                $this->executor->execute_tool($op['tool'], $op['arguments']);
                $modified++;
            }

            wp_send_json_success([
                'modified' => $modified,
                'message' => sprintf(__('%d file(s) modified.', 'ai-assistant'), $modified),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_revert_file(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if (empty($file_path)) {
            wp_send_json_error(['message' => 'No file specified']);
        }

        if ($this->git_tracker_manager->is_reverted($file_path)) {
            wp_send_json_error(['message' => 'File already reverted']);
        }

        try {
            if ($this->git_tracker_manager->revert_file($file_path)) {
                wp_send_json_success([
                    'message' => __('File reverted successfully.', 'ai-assistant'),
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to revert file']);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_reapply_file(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if (empty($file_path)) {
            wp_send_json_error(['message' => 'No file specified']);
        }

        if (!$this->git_tracker_manager->is_reverted($file_path)) {
            wp_send_json_error(['message' => 'File is not reverted']);
        }

        try {
            if ($this->git_tracker_manager->reapply_file($file_path)) {
                wp_send_json_success([
                    'message' => __('File changes reapplied successfully.', 'ai-assistant'),
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to reapply changes']);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_revert_files(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_paths = isset($_POST['file_paths']) ? array_map('sanitize_text_field', (array) $_POST['file_paths']) : [];

        if (empty($file_paths)) {
            wp_send_json_error(['message' => 'No files specified']);
        }

        $reverted = [];
        $errors = [];

        foreach ($file_paths as $file_path) {
            if ($this->git_tracker_manager->is_reverted($file_path)) {
                continue;
            }

            try {
                if ($this->git_tracker_manager->revert_file($file_path)) {
                    $reverted[] = $file_path;
                } else {
                    $errors[] = sprintf(__('Failed to revert: %s', 'ai-assistant'), $file_path);
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        wp_send_json_success([
            'reverted' => $reverted,
            'errors' => $errors,
            'message' => sprintf(__('%d file(s) reverted.', 'ai-assistant'), count($reverted)),
        ]);
    }

    public function ajax_lint_php(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if (empty($file_path)) {
            wp_send_json_error(['message' => 'No file specified']);
        }

        if (!preg_match('/\.php$/i', $file_path)) {
            wp_send_json_success(['valid' => true, 'is_php' => false]);
        }

        $full_path = WP_CONTENT_DIR . '/' . $file_path;

        if (!file_exists($full_path)) {
            wp_send_json_success(['valid' => true, 'is_php' => true, 'message' => 'File does not exist']);
        }

        $content = file_get_contents($full_path);
        $result = $this->lint_php_content($content);

        wp_send_json_success([
            'valid' => $result['valid'],
            'is_php' => true,
            'error' => $result['error'] ?? null,
            'line' => $result['line'] ?? null,
        ]);
    }

    public function lint_php_content(string $content): array {
        $previous_error_reporting = error_reporting(0);

        set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            token_get_all($content, TOKEN_PARSE);
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return ['valid' => true];
        } catch (\ParseError $e) {
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        } catch (\ErrorException $e) {
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        } catch (\Throwable $e) {
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        }
    }

    public function ajax_get_commit_log(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $plugin_path = isset($_POST['plugin_path']) ? sanitize_text_field($_POST['plugin_path']) : '';
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;

        if (empty($plugin_path)) {
            wp_send_json_error(['message' => 'No plugin path specified']);
        }

        $result = $this->git_tracker_manager->get_commit_log($plugin_path, $limit, $offset);
        wp_send_json_success($result);
    }

    public function ajax_get_commit_diff(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $plugin_path = isset($_POST['plugin_path']) ? sanitize_text_field($_POST['plugin_path']) : '';
        $sha = isset($_POST['sha']) ? sanitize_text_field($_POST['sha']) : '';

        if (empty($plugin_path)) {
            wp_send_json_error(['message' => 'No plugin path specified']);
        }

        if (empty($sha)) {
            wp_send_json_error(['message' => 'No commit SHA specified']);
        }

        $diff = $this->git_tracker_manager->get_commit_diff($plugin_path, $sha);
        wp_send_json_success(['diff' => $diff]);
    }

    public function ajax_update_commit_message(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $plugin_path = isset($_POST['plugin_path']) ? sanitize_text_field(wp_unslash($_POST['plugin_path'])) : '';
        $sha = isset($_POST['sha']) ? sanitize_text_field(wp_unslash($_POST['sha'])) : '';
        $message = isset($_POST['message']) ? trim(sanitize_text_field(wp_unslash($_POST['message']))) : '';

        if (empty($plugin_path)) {
            wp_send_json_error(['message' => 'No plugin path specified']);
        }

        if (empty($sha)) {
            wp_send_json_error(['message' => 'No commit SHA specified']);
        }

        if ($message === '') {
            wp_send_json_error(['message' => 'Commit message cannot be empty']);
        }

        $result = $this->git_tracker_manager->update_commit_message($plugin_path, $sha, $message);

        if (!empty($result['success'])) {
            wp_send_json_success([
                'old_sha' => $result['old_sha'] ?? $sha,
                'new_sha' => $result['new_sha'] ?? null,
                'head_sha' => $result['head_sha'] ?? null,
                'message' => $message,
            ]);
        }

        wp_send_json_error([
            'message' => !empty($result['errors']) ? implode(', ', $result['errors']) : __('Failed to update commit message.', 'ai-assistant'),
        ]);
    }

    public function ajax_checkout_commit(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $plugin_path = isset($_POST['plugin_path']) ? sanitize_text_field($_POST['plugin_path']) : '';
        $sha = isset($_POST['sha']) ? sanitize_text_field($_POST['sha']) : '';

        if (empty($plugin_path)) {
            wp_send_json_error(['message' => 'No plugin path specified']);
        }

        if (empty($sha)) {
            wp_send_json_error(['message' => 'No commit SHA specified']);
        }

        $result = $this->git_tracker_manager->checkout_commit($plugin_path, $sha);

        if ($result['success']) {
            wp_send_json_success([
                'checked_out' => $result['checked_out'],
                'checked_out_sha' => $result['checked_out_sha'] ?? null,
                'previous_head' => $result['previous_head'] ?? null,
                'message' => sprintf(__('%d file(s) checked out from commit state.', 'ai-assistant'), count($result['checked_out'])),
            ]);
        } else {
            wp_send_json_error([
                'message' => implode(', ', $result['errors']),
            ]);
        }
    }

    public function handle_checkout_version(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'ai-assistant'));
        }

        $plugin_path = isset($_GET['plugin_path']) ? sanitize_text_field(wp_unslash($_GET['plugin_path'])) : '';
        $sha = isset($_GET['sha']) ? sanitize_text_field(wp_unslash($_GET['sha'])) : '';
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if ($plugin_path === '' || $sha === '') {
            wp_die(__('Missing checkout target.', 'ai-assistant'));
        }

        if (!wp_verify_nonce($nonce, 'ai_assistant_checkout_version_' . $plugin_path . '_' . $sha)) {
            wp_die(__('Security check failed.', 'ai-assistant'));
        }

        $result = $this->git_tracker_manager->checkout_commit($plugin_path, $sha);
        if (empty($result['success'])) {
            $errors = !empty($result['errors']) && is_array($result['errors'])
                ? implode(', ', $result['errors'])
                : __('Unable to check out version.', 'ai-assistant');
            wp_die(esc_html($errors));
        }

        $fallback = admin_url('tools.php?page=ai-changes&plugin=' . rawurlencode($plugin_path));
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : $fallback;
        if ($redirect_to === '') {
            $redirect_to = $fallback;
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    private function parse_patch(string $patch): array {
        $operations = [];
        $blocks = preg_split('/^diff --git /m', $patch);

        foreach ($blocks as $block) {
            if (empty(trim($block))) {
                continue;
            }

            $block = 'diff --git ' . $block;
            $op = $this->parse_diff_block($block);
            if ($op) {
                $operations[] = $op;
            }
        }

        return $operations;
    }

    private function parse_diff_block(string $block): ?array {
        $lines = explode("\n", $block);

        // Extract path from "diff --git a/path b/path"
        if (!preg_match('/^diff --git a\/(.+) b\/(.+)$/', $lines[0], $matches)) {
            return null;
        }
        $path = $matches[2];

        $is_new_file = strpos($block, 'new file mode') !== false;
        $is_deleted = strpos($block, 'deleted file mode') !== false;

        if ($is_deleted) {
            return [
                'tool' => 'delete_file',
                'arguments' => ['path' => $path],
            ];
        }

        if ($is_new_file) {
            $content = $this->extract_new_file_content($lines);
            return [
                'tool' => 'write_file',
                'arguments' => [
                    'path' => $path,
                    'content' => $content,
                ],
            ];
        }

        // Modified file - need to apply hunks
        $full_path = WP_CONTENT_DIR . '/' . $path;
        if (!file_exists($full_path)) {
            return null;
        }

        $original = file_get_contents($full_path);
        $new_content = $this->apply_hunks($original, $lines);

        if ($new_content === null) {
            return null;
        }

        return [
            'tool' => 'write_file',
            'arguments' => [
                'path' => $path,
                'content' => $new_content,
            ],
        ];
    }

    private function extract_new_file_content(array $lines): string {
        $content_lines = [];
        $in_content = false;

        foreach ($lines as $line) {
            if (strpos($line, '@@') === 0) {
                $in_content = true;
                continue;
            }
            if ($in_content && isset($line[0]) && $line[0] === '+') {
                $content_lines[] = substr($line, 1);
            }
        }

        return implode("\n", $content_lines);
    }

    private function apply_hunks(string $original, array $diff_lines): ?string {
        $original_lines = explode("\n", $original);
        $result = $original_lines;
        $offset = 0;

        $hunks = $this->extract_hunks($diff_lines);

        foreach ($hunks as $hunk) {
            $start_line = $hunk['old_start'] - 1 + $offset;
            $old_count = $hunk['old_count'];

            // Remove old lines and insert new ones
            array_splice($result, $start_line, $old_count, $hunk['new_lines']);

            // Adjust offset for subsequent hunks
            $offset += count($hunk['new_lines']) - $old_count;
        }

        return implode("\n", $result);
    }

    private function extract_hunks(array $lines): array {
        $hunks = [];
        $current_hunk = null;

        foreach ($lines as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                if ($current_hunk) {
                    $hunks[] = $current_hunk;
                }
                $current_hunk = [
                    'old_start' => (int) $matches[1],
                    'old_count' => isset($matches[2]) ? (int) $matches[2] : 1,
                    'new_start' => (int) $matches[3],
                    'new_count' => isset($matches[4]) ? (int) $matches[4] : 1,
                    'new_lines' => [],
                ];
                continue;
            }

            if ($current_hunk === null) {
                continue;
            }

            if (isset($line[0])) {
                if ($line[0] === '+') {
                    $current_hunk['new_lines'][] = substr($line, 1);
                } elseif ($line[0] === ' ') {
                    $current_hunk['new_lines'][] = substr($line, 1);
                }
                // Lines starting with '-' are removed (not added to new_lines)
            }
        }

        if ($current_hunk) {
            $hunks[] = $current_hunk;
        }

        return $hunks;
    }

    public function handle_diff_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'ai-assistant'));
        }

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ai_assistant_download_diff')) {
            wp_die(__('Security check failed.', 'ai-assistant'));
        }

        $file_paths = isset($_GET['file_paths']) ? array_map('sanitize_text_field', explode(',', $_GET['file_paths'])) : [];

        if (empty($file_paths)) {
            wp_die(__('No files selected.', 'ai-assistant'));
        }

        $diff = $this->git_tracker_manager->generate_diff($file_paths);
        $filename = 'ai-changes-' . date('Y-m-d-His') . '.patch';

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($diff));
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $diff;
        exit;
    }
}
