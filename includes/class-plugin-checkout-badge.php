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
        $message_excerpt = $this->get_message_excerpt($commit_message);
        $time_ago = $this->format_relative_time(isset($status['commit_timestamp']) ? (int) $status['commit_timestamp'] : null);
        $ai_changes_url = (string) ($status['ai_changes_url'] ?? '');
        $label = $commit_message !== ''
            ? sprintf(
                __('Viewing checked-out change for %s: %s', 'ai-assistant'),
                $plugin_name,
                $commit_message
            )
            : sprintf(
                __('Viewing a checked-out change for %s.', 'ai-assistant'),
                $plugin_name
            );

        $this->render_style();
        ?>
        <details class="ai-assistant-checkout-badge" data-ai-plugin="<?php echo esc_attr($status['relative_root'] ?? ''); ?>">
            <summary class="ai-assistant-checkout-badge-summary" aria-label="<?php echo esc_attr($label); ?>">
                <span class="ai-assistant-checkout-badge-dot" aria-hidden="true"></span>
                <span class="ai-assistant-checkout-badge-prefix"><?php esc_html_e('Old Version:', 'ai-assistant'); ?></span>
                <span class="ai-assistant-checkout-badge-message"><?php echo esc_html($message_excerpt); ?></span>
                <?php if ($time_ago !== ''): ?>
                <span class="ai-assistant-checkout-badge-time"><?php echo esc_html($time_ago); ?></span>
                <?php endif; ?>
            </summary>
            <div class="ai-assistant-checkout-badge-panel" role="status">
                <div class="ai-assistant-checkout-badge-kicker"><?php esc_html_e('Old Version', 'ai-assistant'); ?></div>
                <div class="ai-assistant-checkout-badge-plugin"><?php echo esc_html($plugin_name); ?></div>
                <?php if ($commit_message !== ''): ?>
                <div class="ai-assistant-checkout-badge-full-message"><?php echo esc_html($commit_message); ?></div>
                <?php endif; ?>
                <?php if ($time_ago !== ''): ?>
                <div class="ai-assistant-checkout-badge-meta"><?php echo esc_html(sprintf(__('Committed %s', 'ai-assistant'), $time_ago)); ?></div>
                <?php endif; ?>
                <?php if ($ai_changes_url !== ''): ?>
                <a class="ai-assistant-checkout-badge-link" href="<?php echo esc_url($ai_changes_url); ?>"><?php esc_html_e('Checkout another version', 'ai-assistant'); ?></a>
                <?php endif; ?>
            </div>
        </details>
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
                max-width: min(320px, calc(100vw - 32px));
                box-sizing: border-box;
                border: 1px solid #dba617;
                border-radius: 4px;
                background: #fff8e5;
                color: #5f4100;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.10);
                opacity: 0.82;
                font: 11px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: 0;
            }
            .ai-assistant-checkout-badge[open] {
                opacity: 1;
            }
            .ai-assistant-checkout-badge:hover,
            .ai-assistant-checkout-badge:focus-within {
                opacity: 1;
            }
            .ai-assistant-checkout-badge-summary {
                display: flex;
                align-items: center;
                gap: 6px;
                max-width: min(260px, calc(100vw - 32px));
                box-sizing: border-box;
                padding: 4px 8px;
                cursor: pointer;
                list-style: none;
            }
            .ai-assistant-checkout-badge-summary::-webkit-details-marker {
                display: none;
            }
            .ai-assistant-checkout-badge-dot {
                width: 6px;
                height: 6px;
                flex: 0 0 auto;
                border-radius: 50%;
                background: #dba617;
            }
            .ai-assistant-checkout-badge-prefix {
                flex: 0 0 auto;
                font-weight: 700;
                color: #5f4100;
            }
            .ai-assistant-checkout-badge-message {
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-weight: 600;
                color: inherit;
            }
            .ai-assistant-checkout-badge-time {
                flex: 0 0 auto;
                color: #806000;
            }
            .ai-assistant-checkout-badge-panel {
                width: min(320px, calc(100vw - 32px));
                box-sizing: border-box;
                padding: 0 10px 9px;
            }
            .ai-assistant-checkout-badge-kicker {
                margin: 2px 0 3px;
                color: #806000;
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
            }
            .ai-assistant-checkout-badge-plugin {
                margin-bottom: 4px;
                color: #2c3338;
                font-weight: 700;
            }
            .ai-assistant-checkout-badge-full-message {
                color: #3c434a;
                overflow-wrap: anywhere;
            }
            .ai-assistant-checkout-badge-meta {
                margin-top: 5px;
                color: #806000;
            }
            .ai-assistant-checkout-badge-link {
                display: inline-block;
                margin-top: 7px;
                color: #5f4100;
                font-weight: 700;
                text-decoration: underline;
            }
            .ai-assistant-checkout-badge-link:hover,
            .ai-assistant-checkout-badge-link:focus {
                color: #2c3338;
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
        $relative_root = $this->get_relative_root($root);

        return [
            'name' => $tracker->get_name(),
            'root' => $root,
            'relative_root' => $relative_root,
            'checked_out_sha' => $checked_out_sha,
            'commit_message' => $commit['message'] ?? '',
            'commit_timestamp' => $commit['timestamp'] ?? null,
            'ai_changes_url' => function_exists('admin_url')
                ? admin_url('tools.php?page=ai-changes&plugin=' . rawurlencode($relative_root))
                : '',
            'latest_sha' => $latest_sha,
        ];
    }

    private function get_message_excerpt(string $message): string {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if ($message === '') {
            return __('Checked-out change', 'ai-assistant');
        }

        $words = preg_split('/\s+/', $message);
        if (!is_array($words) || count($words) <= 5) {
            return $message;
        }

        return implode(' ', array_slice($words, 0, 5)) . '...';
    }

    private function format_relative_time(?int $timestamp): string {
        if (!$timestamp) {
            return '';
        }

        $diff = max(0, time() - $timestamp);
        if ($diff < 60) {
            return __('just now', 'ai-assistant');
        }

        if ($diff < 3600) {
            return sprintf(__('%d min ago', 'ai-assistant'), (int) floor($diff / 60));
        }

        if ($diff < 86400) {
            return sprintf(__('%d hr ago', 'ai-assistant'), (int) floor($diff / 3600));
        }

        if ($diff < 604800) {
            return sprintf(__('%d d ago', 'ai-assistant'), (int) floor($diff / 86400));
        }

        return function_exists('date_i18n') ? date_i18n('M j', $timestamp) : date('M j', $timestamp);
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
