<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI for plugins that were guarded by emergency recovery.
 */
class Plugin_Recovery_Admin {

    public function __construct() {
        add_action('after_plugin_row', [$this, 'render_guarded_plugin_row'], 10, 3);
        add_action('admin_action_ai_assistant_restore_plugin_guard', [$this, 'handle_restore_guard']);
        add_action('admin_action_ai_assistant_deactivate_guarded_plugin', [$this, 'handle_deactivate_guarded_plugin']);
    }

    public function render_guarded_plugin_row(string $plugin_file, array $plugin_data, string $status): void {
        $path = $this->plugin_file_path($plugin_file);
        if (!Emergency_Plugin_Guard::is_guarded_file($path)) {
            return;
        }

        $restore_url = wp_nonce_url(
            admin_url('admin.php?action=ai_assistant_restore_plugin_guard&plugin=' . rawurlencode($plugin_file)),
            $this->nonce_action($plugin_file)
        );
        $deactivate_url = wp_nonce_url(
            admin_url('admin.php?action=ai_assistant_deactivate_guarded_plugin&plugin=' . rawurlencode($plugin_file)),
            $this->nonce_action($plugin_file)
        );
        $plugin_name = $plugin_data['Name'] ?? $plugin_file;

        printf(
            '<tr class="plugin-update-tr ai-assistant-emergency-disabled"><td colspan="%d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%s %s %s</p></div></td></tr>',
            $this->column_count(),
            esc_html(sprintf(__('%s was emergency-disabled by AI Assistant after WordPress failed to load. The plugin file is still present, but its code returns before running.', 'ai-assistant'), $plugin_name)),
            sprintf(
                '<a href="%s">%s</a>',
                esc_url($restore_url),
                esc_html__('Restore and keep active', 'ai-assistant')
            ),
            sprintf(
                '<a href="%s">%s</a>',
                esc_url($deactivate_url),
                esc_html__('Deactivate plugin', 'ai-assistant')
            )
        );
    }

    public function handle_restore_guard(): void {
        $plugin_file = $this->requested_plugin_file();
        $this->verify_request($plugin_file);

        $path = $this->plugin_file_path($plugin_file);
        if (!Emergency_Plugin_Guard::remove_guard_from_file($path)) {
            wp_die(__('The plugin emergency guard could not be removed.', 'ai-assistant'));
        }

        wp_safe_redirect(add_query_arg('ai_assistant_recovery', 'restored', admin_url('plugins.php')));
        exit;
    }

    public function handle_deactivate_guarded_plugin(): void {
        $plugin_file = $this->requested_plugin_file();
        $this->verify_request($plugin_file);

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($plugin_file, false, is_multisite());

        $path = $this->plugin_file_path($plugin_file);
        if (Emergency_Plugin_Guard::is_guarded_file($path)) {
            Emergency_Plugin_Guard::remove_guard_from_file($path);
        }

        wp_safe_redirect(add_query_arg('ai_assistant_recovery', 'deactivated', admin_url('plugins.php')));
        exit;
    }

    private function verify_request(string $plugin_file): void {
        if (!current_user_can('activate_plugins')) {
            wp_die(__('You do not have permission to recover plugins.', 'ai-assistant'));
        }

        check_admin_referer($this->nonce_action($plugin_file));
    }

    private function requested_plugin_file(): string {
        $plugin_file = isset($_GET['plugin']) ? wp_unslash($_GET['plugin']) : '';
        if (!is_string($plugin_file)) {
            wp_die(__('Invalid plugin file.', 'ai-assistant'));
        }

        $plugin_file = ltrim(str_replace('\\', '/', $plugin_file), '/');
        if (
            $plugin_file === '' ||
            strpos($plugin_file, "\0") !== false ||
            preg_match('#(^|/)\.\.(/|$)#', $plugin_file) ||
            substr_count($plugin_file, '/') > 1 ||
            !preg_match('/\.php$/i', $plugin_file)
        ) {
            wp_die(__('Invalid plugin file.', 'ai-assistant'));
        }

        return $plugin_file;
    }

    private function plugin_file_path(string $plugin_file): string {
        return WP_PLUGIN_DIR . '/' . ltrim(str_replace('\\', '/', $plugin_file), '/');
    }

    private function nonce_action(string $plugin_file): string {
        return 'ai_assistant_plugin_guard_' . $plugin_file;
    }

    private function column_count(): int {
        global $wp_list_table;

        if (is_object($wp_list_table) && method_exists($wp_list_table, 'get_column_count')) {
            return max(1, (int) $wp_list_table->get_column_count());
        }

        return 4;
    }
}
