<?php
namespace AI_Assistant;

if (!defined('ABSPATH') && !defined('AI_ASSISTANT_FILE_TOOLS_ENDPOINT')) {
    exit;
}

/**
 * Manages Git_Tracker instances for multiple plugins/themes.
 *
 * Each plugin/theme gets its own .git directory. This class determines
 * which tracker to use based on file paths and aggregates data across
 * all trackers for the UI.
 */
class Git_Tracker_Manager {

    private array $trackers = [];
    private array $external_trackers = [];

    /**
     * Get the Git_Tracker for a given file path.
     * Creates a new tracker if one doesn't exist yet.
     *
     * @param string $path Path relative to wp-content (e.g., "plugins/my-plugin/file.php")
     * @return Git_Tracker|null Returns null if path is not within a plugin/theme
     */
    public function get_tracker_for_path(string $path): ?Git_Tracker {
        $root = $this->get_root_for_path($path);
        if ($root === null) {
            return null;
        }

        return $this->get_or_create_tracker($root);
    }

    /**
     * Get the plugin/theme root directory for a path.
     *
     * @param string $path Path relative to wp-content
     * @return string|null Absolute path to plugin/theme root, or null if not in a plugin/theme
     */
    public function get_root_for_path(string $path): ?string {
        $path = ltrim($path, '/');
        $parts = explode('/', $path);

        if (count($parts) < 2) {
            return null;
        }

        $type = $parts[0];
        $name = $parts[1];

        // Return the root path based on path structure
        // Don't require directory to exist - it may have just been created
        if ($type === 'plugins') {
            return WP_PLUGIN_DIR . '/' . $name;
        } elseif ($type === 'themes') {
            return get_theme_root() . '/' . $name;
        }

        return null;
    }

    /**
     * Get or create a tracker for a specific root directory.
     *
     * @param string $root Absolute path to plugin/theme directory
     * @return Git_Tracker
     */
    public function get_or_create_tracker(string $root): Git_Tracker {
        $root = rtrim($root, '/');

        if (!isset($this->trackers[$root])) {
            $this->trackers[$root] = new Git_Tracker($root);
        }

        return $this->trackers[$root];
    }

    public function get_or_create_external_tracker(string $root): External_Git_Tracker {
        $root = rtrim($root, '/');

        if (!isset($this->external_trackers[$root])) {
            $this->external_trackers[$root] = new External_Git_Tracker($root);
        }

        return $this->external_trackers[$root];
    }

    /**
     * Get all active trackers (those with .git directories).
     *
     * @return Git_Tracker[]
     */
    /**
     * Get trackers that have AI changes (ai-changes branch exists).
     * This excludes plugins with .git from Playground that haven't been modified.
     */
    public function get_active_trackers(): array {
        $this->discover_trackers();

        $active = [];
        foreach ($this->trackers as $root => $tracker) {
            if ($tracker->has_ai_changes()) {
                $active[$root] = $tracker;
            }
        }

        return $active;
    }

    /**
     * Discover all plugins/themes that have .git directories.
     */
    private function discover_trackers(): void {
        // Check all plugins
        $plugin_dirs = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR);
        if ($plugin_dirs) {
            foreach ($plugin_dirs as $dir) {
                if (is_dir($dir . '/.git')) {
                    $this->get_or_create_tracker($dir);
                }
            }
        }

        // Check all themes
        $theme_dirs = glob(get_theme_root() . '/*', GLOB_ONLYDIR);
        if ($theme_dirs) {
            foreach ($theme_dirs as $dir) {
                if (is_dir($dir . '/.git')) {
                    $this->get_or_create_tracker($dir);
                }
            }
        }
    }

    /**
     * Get all changes across all plugins/themes, grouped by plugin.
     *
     * @return array
     */
    public function get_all_changes_by_plugin(): array {
        $trackers = $this->get_active_trackers();
        $result = [];

        foreach ($trackers as $root => $tracker) {
            $info = $tracker->get_changes_info();
            if (!empty($info) && $info['file_count'] > 0) {
                $relative_root = $this->get_relative_path($root);

                // Prefix file paths with plugin path for wp-content-relative paths
                foreach ($info['files'] as &$file) {
                    $file['path'] = $relative_root . '/' . $file['path'];
                }
                unset($file);

                $info['path'] = $relative_root;
                $info['work_tree'] = $root;
                $result[$relative_root] = $info;
            }
        }

        return $result;
    }

    /**
     * Get repositories for the AI Changes page.
     *
     * AI-managed repositories keep the existing file-action structure. Ordinary
     * external Git repositories are included as read-only history cards with
     * checkout support.
     */
    public function get_all_repositories_for_changes_page(string $detail_path = ''): array {
        $this->discover_trackers();
        $detail_path = trim($detail_path, '/');

        $result = $this->get_all_changes_by_plugin();
        foreach ($this->trackers as $root => $tracker) {
            $relative_root = $this->get_relative_path($root);
            if (isset($result[$relative_root]) || $tracker->has_ai_changes()) {
                continue;
            }

            if (!$this->is_wp_app_repository_root($root, $relative_root)) {
                continue;
            }

            $external = $this->get_or_create_external_tracker($root);
            if (!$external->is_active()) {
                continue;
            }

            $info = $relative_root === $detail_path
                ? $external->get_info()
                : $external->get_summary_info();
            $info['path'] = $relative_root;
            $info['work_tree'] = $root;
            $result[$relative_root] = $info;
        }

        ksort($result);
        return $result;
    }

    private function is_wp_app_repository_root(string $root, string $relative_root): bool {
        if (strpos($relative_root, 'plugins/') !== 0) {
            return false;
        }

        if (is_dir($root . '/vendor/akirk/wp-app')) {
            return true;
        }

        if ($this->composer_requires_wp_app($root . '/composer.json')) {
            return true;
        }

        foreach ($this->get_plugin_header_candidates($root) as $file) {
            $content = file_get_contents($file, false, null, 0, 65536);
            if ($content === false) {
                continue;
            }

            if (
                stripos($content, 'WpApp') !== false &&
                (
                    strpos($content, 'WpApp\\') !== false ||
                    strpos($content, '\\WpApp\\') !== false ||
                    strpos($content, 'wp_app_') !== false ||
                    stripos($content, 'powered by WpApp') !== false
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function composer_requires_wp_app(string $composer_path): bool {
        if (!is_file($composer_path)) {
            return false;
        }

        $content = file_get_contents($composer_path, false, null, 0, 65536);
        if ($content === false) {
            return false;
        }

        $composer = json_decode($content, true);
        if (!is_array($composer)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $key) {
            if (isset($composer[$key]) && is_array($composer[$key]) && array_key_exists('akirk/wp-app', $composer[$key])) {
                return true;
            }
        }

        return false;
    }

    private function get_plugin_header_candidates(string $root): array {
        $candidates = [];
        $main_file = $root . '/' . basename($root) . '.php';
        if (is_file($main_file)) {
            $candidates[] = $main_file;
        }

        $php_files = glob($root . '/*.php');
        if ($php_files) {
            foreach ($php_files as $file) {
                if (!in_array($file, $candidates, true)) {
                    $candidates[] = $file;
                }
            }
        }

        return $candidates;
    }

    /**
     * Get all changes across all plugins/themes, grouped by directory.
     *
     * @return array
     */
    public function get_all_changes_by_directory(): array {
        $trackers = $this->get_active_trackers();
        $result = [];

        foreach ($trackers as $root => $tracker) {
            $relative_root = $this->get_relative_path($root);
            $dirs = $tracker->get_changes_by_directory();

            foreach ($dirs as $dir => $data) {
                $full_dir = $relative_root . ($dir ? '/' . $dir : '');
                $result[$full_dir] = $data;
                $result[$full_dir]['path'] = $full_dir;

                // Update file paths to be relative to wp-content
                foreach ($result[$full_dir]['files'] as &$file) {
                    $file['path'] = $relative_root . '/' . $file['path'];
                }
            }
        }

        return $result;
    }

    /**
     * Check if any plugin/theme has tracked changes.
     *
     * @return bool
     */
    public function has_changes(): bool {
        $trackers = $this->get_active_trackers();

        foreach ($trackers as $tracker) {
            if ($tracker->has_changes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get AI Changes metadata for a wp-content-relative path.
     *
     * Returns null unless the path belongs to a plugin/theme tracker with an
     * ai-changes branch, which keeps read-only file tools from advertising the
     * changes page for untouched plugins.
     *
     * @param string $path Path relative to wp-content.
     * @return array<string,string>|null
     */
    public function get_ai_changes_metadata_for_path(string $path): ?array {
        $root = $this->get_root_for_path($path);
        if ($root === null) {
            return null;
        }

        $tracker = $this->get_or_create_tracker($root);
        if (!$tracker->has_ai_changes()) {
            return null;
        }

        $relative_root = $this->get_relative_path($root);
        $metadata = [
            'root' => $relative_root,
            'type' => strpos($relative_root, 'themes/') === 0 ? 'theme' : 'plugin',
        ];

        if (function_exists('admin_url')) {
            $metadata['url'] = admin_url('tools.php?page=ai-changes&plugin=' . rawurlencode($relative_root));
        }

        return $metadata;
    }

    /**
     * Generate diff for files across all trackers.
     *
     * @param array $file_paths Paths relative to wp-content
     * @return string
     */
    public function generate_diff(array $file_paths): string {
        // Group files by their tracker
        $by_tracker = [];
        foreach ($file_paths as $path) {
            $root = $this->get_root_for_path($path);
            if ($root === null) {
                continue;
            }

            if (!isset($by_tracker[$root])) {
                $by_tracker[$root] = [];
            }

            // Convert to path relative to plugin root
            $relative = $this->path_relative_to_root($path, $root);
            $by_tracker[$root][] = $relative;
        }

        $diffs = [];
        foreach ($by_tracker as $root => $paths) {
            $tracker = $this->get_or_create_tracker($root);
            $diff = $tracker->generate_diff($paths);
            if ($diff) {
                $diffs[] = $diff;
            }
        }

        return implode("\n", $diffs);
    }

    /**
     * Get original content of a file.
     *
     * @param string $path Path relative to wp-content
     * @return string|null
     */
    public function get_original_content(string $path): ?string {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return null;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->get_original_content($relative);
    }

    /**
     * Revert a file to its original state.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function revert_file(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->revert_file($relative);
    }

    /**
     * Reapply changes to a file.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function reapply_file(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->reapply_file($relative);
    }

    /**
     * Check if a file is reverted.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function is_reverted(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->is_reverted($relative);
    }

    /**
     * Check if a file is tracked.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function is_tracked(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->is_tracked($relative);
    }

    /**
     * Get commit log from a specific plugin/theme.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_commit_log(string $plugin_path, int $limit = 20, int $offset = 0): array {
        $tracker = $this->get_repository_tracker($plugin_path);
        if ($tracker === null) {
            return ['commits' => [], 'has_more' => false];
        }

        return $tracker->get_commit_log($limit, $offset);
    }

    public function get_commit_summary(string $plugin_path, string $sha): ?array {
        $tracker = $this->get_repository_tracker($plugin_path);
        if ($tracker === null || !method_exists($tracker, 'get_commit_summary')) {
            return null;
        }

        return $tracker->get_commit_summary($sha);
    }

    /**
     * Get diff for a specific commit.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param string $sha Commit SHA
     * @return string
     */
    public function get_commit_diff(string $plugin_path, string $sha): string {
        $tracker = $this->get_repository_tracker($plugin_path);
        if ($tracker === null) {
            return '';
        }

        return $tracker->get_commit_diff($sha);
    }

    /**
     * Check out all tracked files to a specific commit.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param string $sha Commit SHA
     * @return array
     */
    public function checkout_commit(string $plugin_path, string $sha): array {
        $tracker = $this->get_repository_tracker($plugin_path);
        if ($tracker === null) {
            return ['success' => false, 'errors' => ['Invalid plugin path']];
        }

        return $tracker->checkout_commit($sha);
    }

    /**
     * Backward-compatible alias for older callers.
     */
    public function revert_to_commit(string $plugin_path, string $sha): array {
        return $this->checkout_commit($plugin_path, $sha);
    }

    /**
     * Build standalone git for a plugin/theme for ZIP export.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param string $target_dir Directory to create .git in
     * @return bool
     */
    public function build_standalone_git(string $plugin_path, string $target_dir): bool {
        $root = $this->get_root_for_path($plugin_path . '/dummy');
        if ($root === null) {
            return false;
        }

        $tracker = $this->get_or_create_tracker($root);
        return $tracker->build_standalone_git($target_dir);
    }

    /**
     * Track a file change.
     *
     * @param string $path Path relative to wp-content
     * @param string $change_type 'created', 'modified', or 'deleted'
     * @param string|null $original_content Original content (for modified/deleted)
     * @param string $reason Reason for the change
     * @param int|null $conversation_id Conversation ID
     * @return bool
     */
    public function track_change(string $path, string $change_type, ?string $original_content = null, string $reason = '', ?int $conversation_id = null): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->track_change($relative, $change_type, $original_content, $reason, $conversation_id);
    }

    /**
     * Track multiple file changes, grouped by plugin/theme tracker.
     *
     * @param array $changes Each change requires path and change_type, with original_content for modified/deleted files.
     * @param string $reason Reason for the change
     * @param int|null $conversation_id Conversation ID
     * @return int Number of valid changes accepted for tracking
     */
    public function track_changes(array $changes, string $reason = '', ?int $conversation_id = null): int {
        $by_tracker = [];

        foreach ($changes as $change) {
            if (!is_array($change) || empty($change['path']) || empty($change['change_type'])) {
                continue;
            }

            $path = (string) $change['path'];
            $tracker = $this->get_tracker_for_path($path);
            if ($tracker === null) {
                continue;
            }

            $root = $tracker->get_work_tree();
            if (!isset($by_tracker[$root])) {
                $by_tracker[$root] = [
                    'tracker' => $tracker,
                    'changes' => [],
                ];
            }

            $by_tracker[$root]['changes'][] = [
                'path' => $this->path_relative_to_tracker($path, $tracker),
                'change_type' => (string) $change['change_type'],
                'original_content' => $change['original_content'] ?? null,
            ];
        }

        $tracked = 0;
        foreach ($by_tracker as $group) {
            $tracked += $group['tracker']->track_changes($group['changes'], $reason, $conversation_id);
        }

        return $tracked;
    }

    /**
     * Get wp-content relative path for a root directory.
     *
     * @param string $root Absolute path
     * @return string Path like "plugins/my-plugin"
     */
    private function get_relative_path(string $root): string {
        if (strpos($root, WP_PLUGIN_DIR) === 0) {
            return 'plugins/' . basename($root);
        }
        if (strpos($root, get_theme_root()) === 0) {
            return 'themes/' . basename($root);
        }
        return basename($root);
    }

    /**
     * Convert a wp-content relative path to a tracker-relative path.
     *
     * @param string $path Path relative to wp-content
     * @param Git_Tracker $tracker
     * @return string Path relative to tracker's work_tree
     */
    private function path_relative_to_tracker(string $path, Git_Tracker $tracker): string {
        $root = $tracker->get_work_tree();
        return $this->path_relative_to_root($path, $root);
    }

    /**
     * Convert a wp-content relative path to a root-relative path.
     *
     * @param string $path Path like "plugins/my-plugin/includes/foo.php"
     * @param string $root Absolute root path
     * @return string Path like "includes/foo.php"
     */
    private function path_relative_to_root(string $path, string $root): string {
        $path = ltrim($path, '/');
        $parts = explode('/', $path);

        // Remove the first two parts (type/name)
        if (count($parts) > 2) {
            return implode('/', array_slice($parts, 2));
        }

        return '';
    }

    private function get_repository_tracker(string $plugin_path) {
        $root = $this->get_root_for_path($plugin_path . '/dummy');
        if ($root === null || !is_dir($root . '/.git')) {
            return null;
        }

        $tracker = $this->get_or_create_tracker($root);
        if ($tracker->has_ai_changes()) {
            return $tracker;
        }

        return $this->get_or_create_external_tracker($root);
    }
}
