<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shows a small runtime badge when a rendered plugin is checked out to an
 * older AI Changes commit.
 */
class Plugin_Checkout_Badge {

    private Git_Tracker_Manager $git_tracker_manager;
    private ?array $current_checkout_status = null;
    private bool $badge_rendered = false;
    private bool $style_rendered = false;

    public function __construct(Git_Tracker_Manager $git_tracker_manager) {
        $this->git_tracker_manager = $git_tracker_manager;

        add_action('wp_app_before_render', [$this, 'capture_wp_app_template'], 10, 2);
        add_action('wp_app_body_open', [$this, 'render_badge'], 5);
        add_action('wp_footer', [$this, 'render_badge'], 5);
        add_action('admin_footer', [$this, 'render_admin_badge'], 5);
    }

    public function capture_wp_app_template($template_path, $route_data = []): void {
        if (!$this->can_view_badge()) {
            return;
        }

        $status = $this->get_checkout_status_for_file((string) $template_path);
        if ($status !== null) {
            $this->current_checkout_status = $status;
        }
    }

    public function render_admin_badge(): void {
        if (!$this->can_view_badge()) {
            return;
        }

        $status = $this->resolve_current_admin_checkout_status();
        if ($status !== null) {
            $this->current_checkout_status = $status;
        }

        $this->render_badge();
    }

    public function render_badge(): void {
        if ($this->badge_rendered || empty($this->current_checkout_status)) {
            return;
        }

        $status = $this->current_checkout_status;
        $plugin_name = $status['name'] ?? __('Plugin', 'ai-assistant');
        $commit_message = trim((string) ($status['commit_message'] ?? ''));
        $title = $commit_message !== ''
            ? sprintf(
                __('%s is not current. Checked out change: %s', 'ai-assistant'),
                $plugin_name,
                $commit_message
            )
            : sprintf(
                __('%s is checked out and is not showing the latest tracked state.', 'ai-assistant'),
                $plugin_name
            );

        $this->render_style();
        ?>
        <div class="ai-assistant-checkout-badge" role="status" aria-label="<?php echo esc_attr($title); ?>" title="<?php echo esc_attr($title); ?>" data-ai-plugin="<?php echo esc_attr($status['relative_root'] ?? ''); ?>">
            <span class="ai-assistant-checkout-badge-label"><?php esc_html_e('Not current', 'ai-assistant'); ?></span>
        </div>
        <?php
        $this->badge_rendered = true;
    }

    private function render_style(): void {
        if ($this->style_rendered) {
            return;
        }

        ?>
        <style id="ai-assistant-checkout-badge-style">
            .ai-assistant-checkout-badge {
                position: fixed;
                bottom: 16px;
                right: 16px;
                z-index: 1000000;
                box-sizing: border-box;
                padding: 4px 8px;
                border: 1px solid #dba617;
                border-radius: 4px;
                background: #fff8e5;
                color: #5f4100;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.10);
                opacity: 0.82;
                font: 11px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: 0;
            }
            .ai-assistant-checkout-badge:hover,
            .ai-assistant-checkout-badge:focus-within {
                opacity: 1;
            }
            .ai-assistant-checkout-badge-label {
                font-weight: 700;
                color: inherit;
                text-transform: uppercase;
            }
            @media screen and (max-width: 782px) {
                .ai-assistant-checkout-badge {
                    bottom: 12px;
                    right: 12px;
                }
            }
        </style>
        <?php
        $this->style_rendered = true;
    }

    private function can_view_badge(): bool {
        return !function_exists('current_user_can') || current_user_can('manage_options');
    }

    private function resolve_current_admin_checkout_status(): ?array {
        global $hook_suffix;

        $hook = is_string($hook_suffix ?? null) ? $hook_suffix : '';
        if ($hook === '' && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if (is_object($screen) && !empty($screen->id)) {
                $hook = (string) $screen->id;
            }
        }

        if ($hook === '') {
            return null;
        }

        return $this->get_checkout_status_from_admin_hook($hook);
    }

    private function get_checkout_status_from_admin_hook(string $hook): ?array {
        global $wp_filter;

        if (empty($wp_filter[$hook])) {
            return null;
        }

        foreach ($this->get_callbacks_from_hook($wp_filter[$hook]) as $callback) {
            $file = $this->get_callback_file($callback);
            if ($file === null) {
                continue;
            }

            $status = $this->get_checkout_status_for_file($file);
            if ($status !== null) {
                return $status;
            }
        }

        return null;
    }

    private function get_callbacks_from_hook($hook): array {
        if (is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks)) {
            $callbacks_by_priority = $hook->callbacks;
        } elseif (is_array($hook)) {
            $callbacks_by_priority = $hook;
        } else {
            return [];
        }

        $callbacks = [];
        foreach ($callbacks_by_priority as $entries) {
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && array_key_exists('function', $entry)) {
                    $callbacks[] = $entry['function'];
                } elseif (is_callable($entry)) {
                    $callbacks[] = $entry;
                }
            }
        }

        return $callbacks;
    }

    private function get_callback_file($callback): ?string {
        try {
            if ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                [$class, $method] = explode('::', $callback, 2);
                $reflection = new \ReflectionMethod($class, $method);
            } elseif (is_string($callback) && function_exists($callback)) {
                $reflection = new \ReflectionFunction($callback);
            } elseif (is_array($callback) && count($callback) >= 2) {
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
            } else {
                return null;
            }
        } catch (\ReflectionException $e) {
            return null;
        }

        $file = $reflection->getFileName();
        return is_string($file) && $file !== '' ? $file : null;
    }

    private function get_checkout_status_for_file(string $file): ?array {
        $root = $this->get_root_for_absolute_file($file);
        if ($root === null) {
            return null;
        }

        return $this->get_checkout_status_for_root($root);
    }

    private function get_checkout_status_for_root(string $root): ?array {
        $tracker = $this->git_tracker_manager->get_or_create_tracker($root);
        if (!$tracker->has_ai_changes()) {
            return null;
        }

        $checked_out_sha = $tracker->get_checked_out_commit();
        if ($checked_out_sha === null) {
            return null;
        }

        $commits = $tracker->get_recent_commits(1);
        $latest_sha = $commits[0]['sha'] ?? null;
        if ($latest_sha && $checked_out_sha === $latest_sha) {
            return null;
        }

        $commit = $tracker->get_commit_summary($checked_out_sha);

        return [
            'name' => $tracker->get_name(),
            'root' => $root,
            'relative_root' => $this->get_relative_root($root),
            'checked_out_sha' => $checked_out_sha,
            'commit_message' => $commit['message'] ?? '',
            'latest_sha' => $latest_sha,
        ];
    }

    private function get_root_for_absolute_file(string $file): ?string {
        $real_file = realpath($file);
        if (!$real_file) {
            return null;
        }

        foreach ($this->get_content_roots() as $base) {
            $real_base = realpath($base);
            if (!$real_base) {
                continue;
            }

            $base_prefix = rtrim($real_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (strpos($real_file, $base_prefix) !== 0) {
                continue;
            }

            $relative = substr($real_file, strlen($base_prefix));
            $parts = explode(DIRECTORY_SEPARATOR, $relative);
            if (empty($parts[0])) {
                continue;
            }

            return $base_prefix . $parts[0];
        }

        return null;
    }

    private function get_relative_root(string $root): string {
        $real_root = realpath($root) ?: $root;
        $plugin_dir = defined('WP_PLUGIN_DIR') ? realpath(WP_PLUGIN_DIR) : false;
        if ($plugin_dir && strpos($real_root, rtrim($plugin_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0) {
            return 'plugins/' . basename($real_root);
        }

        $theme_root = function_exists('get_theme_root') ? realpath(get_theme_root()) : false;
        if ($theme_root && strpos($real_root, rtrim($theme_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0) {
            return 'themes/' . basename($real_root);
        }

        return basename($real_root);
    }

    private function get_content_roots(): array {
        $roots = [];
        if (defined('WP_PLUGIN_DIR')) {
            $roots[] = WP_PLUGIN_DIR;
        }
        if (function_exists('get_theme_root')) {
            $roots[] = get_theme_root();
        }

        return $roots;
    }
}
