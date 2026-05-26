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
    private ?string $current_rendered_root = null;
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

        $root = $this->get_root_for_absolute_file((string) $template_path);
        if ($root === null) {
            return;
        }

        $this->current_rendered_root = $root;
        $status = $this->get_checkout_status_for_root($root);
        if ($status !== null) {
            $this->current_checkout_status = $status;
        }
    }

    public function get_current_ai_changes_metadata(): ?array {
        if (!$this->can_view_badge()) {
            return null;
        }

        if ($this->current_rendered_root !== null) {
            $metadata = $this->get_ai_changes_metadata_for_root($this->current_rendered_root);
            if ($metadata !== null) {
                return $metadata;
            }
        }

        $admin_root = $this->resolve_current_admin_root();
        return $admin_root !== null ? $this->get_ai_changes_metadata_for_root($admin_root) : null;
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
        $links = isset($status['links']) && is_array($status['links']) ? $status['links'] : [];
        $version_log = isset($status['version_log']) && is_array($status['version_log']) ? $status['version_log'] : [];
        $overview_link = $this->get_overview_link_from_links($links);
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
                <?php if (!empty($version_log)): ?>
                <div class="ai-assistant-checkout-badge-log" aria-label="<?php esc_attr_e('Version history', 'ai-assistant'); ?>">
                    <?php foreach ($version_log as $row): ?>
                    <?php
                    $row_key = isset($row['key']) ? (string) $row['key'] : '';
                    $row_label = isset($row['label']) ? (string) $row['label'] : '';
                    $row_message = isset($row['message_excerpt']) ? (string) $row['message_excerpt'] : (string) ($row['message'] ?? '');
                    $row_time = isset($row['time_ago']) ? (string) $row['time_ago'] : '';
                    $row_url = isset($row['url']) ? (string) $row['url'] : '';
                    $row_classes = ['ai-assistant-checkout-badge-log-row'];
                    if (!empty($row['is_current'])) {
                        $row_classes[] = 'is-current';
                    }
                    if (!empty($row['is_unavailable'])) {
                        $row_classes[] = 'is-unavailable';
                    }
                    $row_class = implode(' ', $row_classes);
                    ?>
                    <?php if ($row_url !== ''): ?>
                    <a class="<?php echo esc_attr($row_class); ?>" href="<?php echo esc_url($row_url); ?>" data-version-row="<?php echo esc_attr($row_key); ?>">
                    <?php else: ?>
                    <div class="<?php echo esc_attr($row_class); ?>" data-version-row="<?php echo esc_attr($row_key); ?>">
                    <?php endif; ?>
                        <span class="ai-assistant-checkout-badge-log-label"><?php echo esc_html($row_label); ?></span>
                        <span class="ai-assistant-checkout-badge-log-message"><?php echo esc_html($row_message); ?></span>
                        <?php if ($row_time !== ''): ?>
                        <span class="ai-assistant-checkout-badge-log-time"><?php echo esc_html($row_time); ?></span>
                        <?php endif; ?>
                    <?php if ($row_url !== ''): ?>
                    </a>
                    <?php else: ?>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($overview_link['url'])): ?>
                <div class="ai-assistant-checkout-badge-actions">
                    <a class="ai-assistant-checkout-badge-link" href="<?php echo esc_url($overview_link['url']); ?>"><?php echo esc_html($overview_link['label'] ?? __('Overview', 'ai-assistant')); ?></a>
                </div>
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
            .ai-assistant-checkout-badge-log {
                display: grid;
                gap: 1px;
                margin-top: 8px;
                overflow: hidden;
                border: 1px solid #ead38d;
                border-radius: 4px;
                background: #ead38d;
            }
            .ai-assistant-checkout-badge-log-row {
                display: grid;
                grid-template-columns: 58px minmax(0, 1fr) auto;
                align-items: center;
                gap: 7px;
                min-height: 24px;
                padding: 4px 7px;
                background: #fffdf6;
                color: #3c434a;
                text-decoration: none;
            }
            .ai-assistant-checkout-badge-log-row:hover,
            .ai-assistant-checkout-badge-log-row:focus {
                background: #fff8e5;
                color: #2c3338;
                text-decoration: none;
            }
            .ai-assistant-checkout-badge-log-row.is-current {
                background: #fff4d1;
                font-weight: 700;
            }
            .ai-assistant-checkout-badge-log-row.is-unavailable {
                color: #806000;
                opacity: 0.72;
            }
            .ai-assistant-checkout-badge-log-label {
                color: #806000;
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
            }
            .ai-assistant-checkout-badge-log-message {
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .ai-assistant-checkout-badge-log-time {
                color: #806000;
                font-size: 10px;
                white-space: nowrap;
            }
            .ai-assistant-checkout-badge-actions {
                display: flex;
                justify-content: flex-end;
                margin-top: 7px;
            }
            .ai-assistant-checkout-badge-link {
                display: inline-block;
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

        $root = $this->get_root_from_admin_hook($hook);
        return $root !== null ? $this->get_checkout_status_for_root($root) : null;
    }

    private function resolve_current_admin_root(): ?string {
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

        return $this->get_root_from_admin_hook($hook);
    }

    private function get_root_from_admin_hook(string $hook): ?string {
        global $wp_filter;

        if (empty($wp_filter[$hook])) {
            return null;
        }

        foreach ($this->get_callbacks_from_hook($wp_filter[$hook]) as $callback) {
            $file = $this->get_callback_file($callback);
            if ($file === null) {
                continue;
            }

            $root = $this->get_root_for_absolute_file($file);
            if ($root !== null) {
                return $root;
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

    private function get_ai_changes_metadata_for_root(string $root): ?array {
        $tracker = $this->git_tracker_manager->get_or_create_tracker($root);
        if (!$tracker->has_ai_changes()) {
            return null;
        }

        $relative_root = $this->get_relative_root($root);

        return [
            'root' => $relative_root,
            'type' => strpos($relative_root, 'themes/') === 0 ? 'theme' : 'plugin',
            'url' => function_exists('admin_url')
                ? admin_url('tools.php?page=ai-changes&plugin=' . rawurlencode($relative_root))
                : '',
            'open_in_current_window' => true,
            'version_log' => $this->get_ai_changes_version_log($tracker, $relative_root),
            'links' => $this->get_ai_changes_links($relative_root),
        ];
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
            'version_log' => $this->get_ai_changes_version_log($tracker, $relative_root),
            'links' => $this->get_ai_changes_links($relative_root),
            'latest_sha' => $latest_sha,
        ];
    }

    private function get_ai_changes_version_log(Git_Tracker $tracker, string $relative_root): array {
        $commits = $tracker->get_recent_commits(100);
        if (empty($commits)) {
            return [];
        }

        $current_sha = $tracker->get_checked_out_commit();
        if ($current_sha === null) {
            $current_sha = $commits[0]['sha'] ?? null;
        }

        $current_index = null;
        foreach ($commits as $index => $commit) {
            if (($commit['sha'] ?? '') === $current_sha) {
                $current_index = $index;
                break;
            }
        }

        $current_commit = $current_index !== null
            ? $commits[$current_index]
            : ($current_sha ? $tracker->get_commit_summary($current_sha) : null);

        return [
            $this->get_version_log_row(
                'next',
                __('Next', 'ai-assistant'),
                $current_index !== null && $current_index > 0 ? $commits[$current_index - 1] : null,
                $relative_root,
                false,
                __('No newer version', 'ai-assistant')
            ),
            $this->get_version_log_row(
                'current',
                __('Current', 'ai-assistant'),
                $current_commit,
                $relative_root,
                true,
                __('Current version unavailable', 'ai-assistant')
            ),
            $this->get_version_log_row(
                'previous',
                __('Previous', 'ai-assistant'),
                $current_index !== null && isset($commits[$current_index + 1]) ? $commits[$current_index + 1] : null,
                $relative_root,
                false,
                __('No previous version', 'ai-assistant')
            ),
        ];
    }

    private function get_version_log_row(
        string $key,
        string $label,
        ?array $commit,
        string $relative_root,
        bool $is_current,
        string $empty_message
    ): array {
        if (empty($commit['sha'])) {
            return [
                'key' => $key,
                'label' => $label,
                'message' => $empty_message,
                'message_excerpt' => $empty_message,
                'time_ago' => '',
                'is_current' => $is_current,
                'is_unavailable' => true,
            ];
        }

        $message = (string) ($commit['message'] ?? '');
        $timestamp = isset($commit['timestamp']) ? (int) $commit['timestamp'] : null;
        $row = [
            'key' => $key,
            'label' => $label,
            'sha' => (string) $commit['sha'],
            'message' => $message,
            'message_excerpt' => $this->get_message_excerpt($message),
            'timestamp' => $timestamp,
            'time_ago' => $this->format_relative_time($timestamp),
            'is_current' => $is_current,
            'is_unavailable' => false,
            'open_in_current_window' => true,
        ];

        if (!$is_current) {
            $row['url'] = $this->get_checkout_url($relative_root, (string) $commit['sha']);
        }

        return $row;
    }

    private function get_ai_changes_links(string $relative_root): array {
        $links = [];
        $overview_url = $this->get_overview_url($relative_root);
        if ($overview_url !== '') {
            $links[] = [
                'key' => 'overview',
                'label' => __('Overview', 'ai-assistant'),
                'url' => $overview_url,
                'open_in_current_window' => true,
            ];
        }

        return array_values(array_filter($links, function($link) {
            return !empty($link['url']);
        }));
    }

    private function get_overview_link_from_links(array $links): array {
        foreach ($links as $link) {
            if (($link['key'] ?? '') === 'overview' && !empty($link['url'])) {
                return $link;
            }
        }

        return [];
    }

    private function get_overview_url(string $relative_root): string {
        return function_exists('admin_url')
            ? admin_url('tools.php?page=ai-changes&plugin=' . rawurlencode($relative_root))
            : '';
    }

    private function get_checkout_url(string $relative_root, string $sha): string {
        if (!function_exists('admin_url') || !function_exists('wp_nonce_url')) {
            return '';
        }

        $url = admin_url(
            'admin.php?action=ai_assistant_checkout_version'
            . '&plugin_path=' . rawurlencode($relative_root)
            . '&sha=' . rawurlencode($sha)
            . '&redirect_to=' . rawurlencode($this->get_current_request_url())
        );

        return wp_nonce_url($url, 'ai_assistant_checkout_version_' . $relative_root . '_' . $sha);
    }

    private function get_current_request_url(): string {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        return function_exists('home_url') ? home_url($request_uri) : (string) $request_uri;
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
