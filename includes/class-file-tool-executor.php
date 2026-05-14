<?php
namespace AI_Assistant;

if (!defined('ABSPATH') && !defined('AI_ASSISTANT_FILE_TOOLS_ENDPOINT')) {
    exit;
}

/**
 * File tools that can run without WordPress bootstrap.
 */
class File_Tool_Executor {

    private string $wp_content_path;
    private ?Git_Tracker_Manager $git_tracker_manager;

    public function __construct(?string $wp_content_path = null, ?Git_Tracker_Manager $git_tracker_manager = null) {
        if ($wp_content_path === null) {
            if (defined('WP_CONTENT_DIR')) {
                $wp_content_path = WP_CONTENT_DIR;
            } elseif (defined('AI_ASSISTANT_FILE_TOOLS_WP_CONTENT_DIR')) {
                $wp_content_path = AI_ASSISTANT_FILE_TOOLS_WP_CONTENT_DIR;
            } else {
                $wp_content_path = dirname(__DIR__, 3);
            }
        }

        $this->wp_content_path = rtrim($wp_content_path, '/\\');
        $this->git_tracker_manager = $git_tracker_manager;
    }

    public function execute(string $tool_name, array $arguments, ?int $conversation_id = null): array {
        switch ($tool_name) {
            case 'read_file':
                return $this->read_file($this->get_string_arg($arguments, 'path', $tool_name));
            case 'write_file':
                return $this->write_file(
                    $this->get_string_arg($arguments, 'path', $tool_name),
                    $this->get_content_arg($arguments, 'content', $tool_name),
                    $this->get_string_arg($arguments, 'reason', $tool_name, ''),
                    $conversation_id
                );
            case 'edit_file':
                return $this->edit_file(
                    $this->get_string_arg($arguments, 'path', $tool_name),
                    $this->get_edits_arg($arguments),
                    $this->get_string_arg($arguments, 'reason', $tool_name, ''),
                    $conversation_id
                );
            case 'delete_file':
                return $this->delete_file(
                    $this->get_string_arg($arguments, 'path', $tool_name),
                    $this->get_string_arg($arguments, 'reason', $tool_name, ''),
                    $conversation_id
                );
            case 'find':
                return $this->execute_find($arguments);
            case 'emergency_deactivate_plugin':
                return $this->emergency_deactivate_plugin(
                    $this->get_string_arg($arguments, 'plugin_slug', $tool_name, ''),
                    $this->get_string_arg($arguments, 'plugin_file', $tool_name, ''),
                    $this->get_string_arg($arguments, 'reason', $tool_name, 'Emergency deactivate plugin')
                );
            case 'list_directory':
                return $this->list_directory($this->get_string_arg($arguments, 'path', $tool_name));
            case 'search_files':
                return $this->search_files($this->get_string_arg($arguments, 'pattern', $tool_name));
            case 'search_content':
                return $this->search_content(
                    $this->get_string_arg($arguments, 'needle', $tool_name),
                    $this->get_string_arg($arguments, 'directory', $tool_name, ''),
                    $this->get_string_arg($arguments, 'file_pattern', $tool_name, '*.php')
                );
            default:
                throw new \Exception("File tool endpoint cannot execute tool: $tool_name");
        }
    }

    private function execute_find(array $arguments): array {
        $text = isset($arguments['text']) ? (string) $arguments['text'] : '';
        $glob = isset($arguments['glob']) ? (string) $arguments['glob'] : '';
        $path = isset($arguments['path']) ? (string) $arguments['path'] : '';

        if ($text !== '') {
            return $this->search_content($text, $path, $arguments['file_pattern'] ?? '*.php');
        }

        if ($glob !== '') {
            return $this->search_files($glob);
        }

        return $this->list_directory($path !== '' ? $path : '.');
    }

    private function get_string_arg(array $args, string $name, string $tool, ?string $default = null): string {
        if (!isset($args[$name])) {
            if ($default !== null) {
                return $default;
            }
            throw new \Exception("$tool requires '$name' argument");
        }

        $value = $args[$name];
        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    private function get_content_arg(array $args, string $name, string $tool): string {
        if (!isset($args[$name])) {
            throw new \Exception("$tool requires '$name' argument");
        }

        $value = $args[$name];
        if (!is_string($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    private function get_edits_arg(array $args): array {
        if (!isset($args['edits'])) {
            throw new \Exception("edit_file requires 'edits' argument");
        }

        $edits = $args['edits'];
        if (is_string($edits)) {
            $decoded = json_decode($edits, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $edits = $decoded;
            }
        }

        if (is_array($edits) && isset($edits['search']) && isset($edits['replace'])) {
            $edits = [$edits];
        }

        if (is_array($edits) && isset($edits[0]) && is_array($edits[0])) {
            foreach ($edits as $index => $edit) {
                if (!is_array($edit) || !array_key_exists('search', $edit) || !array_key_exists('replace', $edit)) {
                    throw new \Exception("edit_file 'edits' must be an array of {search, replace} objects");
                }
                $edits[$index]['search'] = (string) $edit['search'];
                $edits[$index]['replace'] = (string) $edit['replace'];
            }

            return $edits;
        }

        throw new \Exception("edit_file 'edits' must be an array of {search, replace} objects");
    }

    private function resolve_path(string $relative_path): string {
        $relative_path = ltrim($relative_path, '/\\');

        if ($relative_path === '') {
            throw new \Exception('Path cannot be empty');
        }

        $full_path = $this->wp_content_path . '/' . $relative_path;
        $real_path = realpath(dirname($full_path));

        if ($real_path === false) {
            $parent = dirname($full_path);
            while (!file_exists($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }
            $real_path = realpath($parent);
        }

        $wp_content_real = realpath($this->wp_content_path);
        if ($real_path === false || $wp_content_real === false) {
            throw new \Exception("Access denied: Cannot resolve path '$relative_path'");
        }

        if (strpos($real_path, $wp_content_real) !== 0) {
            throw new \Exception("Access denied: Path '$relative_path' is outside wp-content directory");
        }

        return $full_path;
    }

    private function validate_glob_pattern(string $pattern): string {
        $pattern = ltrim($pattern, '/\\');

        if ($pattern === '') {
            throw new \Exception('Pattern cannot be empty');
        }

        if (strpos($pattern, "\0") !== false || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $pattern)) {
            throw new \Exception('Access denied: Pattern cannot contain path traversal');
        }

        return $pattern;
    }

    private function is_php_file(string $path): bool {
        return (bool) preg_match('/\.php$/i', $path);
    }

    private function lint_php_content(string $content): array {
        $previous_error_reporting = error_reporting(0);

        set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            token_get_all($content, TOKEN_PARSE);
            return ['valid' => true];
        } catch (\ParseError $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
            ];
        } finally {
            restore_error_handler();
            error_reporting($previous_error_reporting);
        }
    }

    private function read_file(string $path): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("File not found: $path");
        }

        if (!is_readable($full_path)) {
            throw new \Exception("File not readable: $path");
        }

        $content = file_get_contents($full_path);
        if ($content === false) {
            throw new \Exception("Failed to read file: $path");
        }

        return [
            'path'     => $path,
            'content'  => $content,
            'size'     => filesize($full_path),
            'modified' => date('Y-m-d H:i:s', filemtime($full_path)),
        ];
    }

    private function write_file(string $path, string $content, string $reason, ?int $conversation_id = null): array {
        $path_parts = explode('/', ltrim($path, '/'));
        if (count($path_parts) === 2 && $this->is_php_file($path)) {
            $type = $path_parts[0];
            if ($type === 'plugins' || $type === 'themes') {
                $name = pathinfo($path_parts[1], PATHINFO_FILENAME);
                throw new \Exception(
                    "Cannot create single-file {$type}. Create a subdirectory instead: " .
                    "{$type}/{$name}/{$path_parts[1]}"
                );
            }
        }

        $full_path = $this->resolve_path($path);
        $dir = dirname($full_path);
        if (!file_exists($dir) && !mkdir($dir, 0755, true)) {
            throw new \Exception('Failed to create directory: ' . dirname($path));
        }

        $existed = file_exists($full_path);
        $old_content = $existed ? file_get_contents($full_path) : null;

        if ($this->is_php_file($path)) {
            $lint_result = $this->lint_php_content($content);
            if (!$lint_result['valid']) {
                $error_msg = 'PHP syntax error: ' . ($lint_result['error'] ?? 'Unknown error');
                if (isset($lint_result['line'])) {
                    $error_msg .= ' on line ' . $lint_result['line'];
                }
                throw new \Exception($error_msg);
            }
        }

        if (file_put_contents($full_path, $content) === false) {
            throw new \Exception("Failed to write file: $path");
        }

        if ($this->git_tracker_manager) {
            $this->git_tracker_manager->track_change($path, $existed ? 'modified' : 'created', $old_content, $reason, $conversation_id);
        }

        return [
            'path'          => $path,
            'action'        => $existed ? 'updated' : 'created',
            'size'          => strlen($content),
            'previous_size' => $old_content !== null ? strlen($old_content) : null,
        ];
    }

    private function edit_file(string $path, array $edits, string $reason, ?int $conversation_id = null): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("File not found: $path");
        }

        $content = file_get_contents($full_path);
        if ($content === false) {
            throw new \Exception("Failed to read file: $path");
        }

        $original_content = $content;
        $applied = [];
        $failed = [];

        foreach ($edits as $index => $edit) {
            $search = $edit['search'] ?? '';
            $replace = $edit['replace'] ?? '';

            if ($search === '') {
                $failed[] = ['index' => $index, 'reason' => 'Empty search string'];
                continue;
            }

            $count = substr_count($content, $search);
            if ($count === 0) {
                $failed[] = ['index' => $index, 'reason' => 'Search string not found', 'search' => substr($search, 0, 50)];
                continue;
            }
            if ($count > 1) {
                $failed[] = ['index' => $index, 'reason' => "Search string found $count times (must be unique)", 'search' => substr($search, 0, 50)];
                continue;
            }

            $content = str_replace($search, $replace, $content);
            $applied[] = ['index' => $index, 'search_length' => strlen($search), 'replace_length' => strlen($replace)];
        }

        if (count($applied) > 0) {
            if ($this->is_php_file($path)) {
                $lint_result = $this->lint_php_content($content);
                if (!$lint_result['valid']) {
                    $error_msg = 'PHP syntax error after edits: ' . ($lint_result['error'] ?? 'Unknown error');
                    if (isset($lint_result['line'])) {
                        $error_msg .= ' on line ' . $lint_result['line'];
                    }
                    throw new \Exception($error_msg);
                }
            }

            if (file_put_contents($full_path, $content) === false) {
                throw new \Exception("Failed to write file: $path");
            }

            if ($this->git_tracker_manager) {
                $this->git_tracker_manager->track_change($path, 'modified', $original_content, $reason, $conversation_id);
            }
        }

        return [
            'path'          => $path,
            'edits_applied' => count($applied),
            'edits_failed'  => count($failed),
            'applied'       => $applied,
            'failed'        => $failed,
            'original_size' => strlen($original_content),
            'new_size'      => strlen($content),
        ];
    }

    private function delete_file(string $path, string $reason, ?int $conversation_id = null): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("File not found: $path");
        }

        $original_content = null;
        if (!is_dir($full_path)) {
            $original_content = file_get_contents($full_path);
        }

        if (is_dir($full_path)) {
            $this->delete_directory_recursive($full_path);
        } elseif (!unlink($full_path)) {
            throw new \Exception("Failed to delete file: $path");
        }

        if ($this->git_tracker_manager && $original_content !== null) {
            $this->git_tracker_manager->track_change($path, 'deleted', $original_content !== false ? $original_content : null, $reason, $conversation_id);
        }

        return [
            'path'   => $path,
            'action' => 'deleted',
        ];
    }

    private function delete_directory_recursive(string $dir): void {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory_recursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function emergency_deactivate_plugin(string $plugin_slug, string $plugin_file, string $reason): array {
        $plugin_slug = $this->normalize_plugin_slug($plugin_slug);
        $plugin_file = ltrim(str_replace('\\', '/', $plugin_file), '/');

        if ($plugin_file !== '') {
            if (
                strpos($plugin_file, "\0") !== false ||
                preg_match('#(^|/)\.\.(/|$)#', $plugin_file) ||
                substr_count($plugin_file, '/') > 1
            ) {
                throw new \Exception('Invalid plugin file');
            }

            if (strpos($plugin_file, '/') !== false) {
                $plugin_slug = $this->normalize_plugin_slug(strtok($plugin_file, '/'));
            } elseif ($plugin_slug === '') {
                $plugin_slug = $this->normalize_plugin_slug(pathinfo($plugin_file, PATHINFO_FILENAME));
            }
        }

        if ($plugin_slug === '') {
            throw new \Exception('A valid plugin slug or plugin file is required');
        }

        if ($plugin_slug === 'ai-assistant') {
            throw new \Exception('Refusing to emergency deactivate AI Assistant');
        }

        $main_file = $this->find_plugin_main_file($plugin_slug, $plugin_file);
        $full_path = $this->resolve_path('plugins/' . $main_file);

        if (!is_file($full_path)) {
            throw new \Exception("Plugin main file not found: plugins/$main_file");
        }

        if (!Emergency_Plugin_Guard::add_guard_to_file($full_path)) {
            throw new \Exception("Failed to emergency guard plugin: plugins/$main_file");
        }

        return [
            'action'       => 'emergency_guarded',
            'plugin_slug'  => $plugin_slug,
            'plugin_file'  => $main_file,
            'guarded_path' => 'plugins/' . $main_file,
            'reason'       => $reason,
            'recover_hint' => 'Fix the plugin files, then restore or deactivate the emergency-disabled plugin from the WordPress Plugins screen.',
        ];
    }

    private function find_plugin_main_file(string $plugin_slug, string $plugin_file): string {
        if ($plugin_file !== '') {
            $relative_path = 'plugins/' . $plugin_file;
            $full_path = $this->resolve_path($relative_path);
            if (is_file($full_path)) {
                return $plugin_file;
            }
        }

        $candidate = $plugin_slug . '/' . $plugin_slug . '.php';
        $candidate_path = $this->resolve_path('plugins/' . $candidate);
        if (is_file($candidate_path)) {
            return $candidate;
        }

        $plugin_dir = $this->resolve_path('plugins/' . $plugin_slug);
        if (!is_dir($plugin_dir)) {
            $single_file = $plugin_slug . '.php';
            $single_file_path = $this->resolve_path('plugins/' . $single_file);
            if (is_file($single_file_path)) {
                return $single_file;
            }

            throw new \Exception("Plugin path not found: plugins/$plugin_slug");
        }

        $entries = scandir($plugin_dir);
        if ($entries === false) {
            throw new \Exception("Failed to inspect plugin directory: plugins/$plugin_slug");
        }

        foreach ($entries as $entry) {
            if (!$this->is_php_file($entry)) {
                continue;
            }

            $path = $plugin_dir . '/' . $entry;
            if ($this->file_has_plugin_header($path)) {
                return $plugin_slug . '/' . $entry;
            }
        }

        throw new \Exception("Plugin main file not found for: $plugin_slug");
    }

    private function file_has_plugin_header(string $path): bool {
        $content = file_get_contents($path, false, null, 0, 8192);
        if ($content === false) {
            return false;
        }

        return (bool) preg_match('/Plugin Name\s*:/i', $content);
    }

    private function normalize_plugin_slug(string $slug): string {
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) ? $slug : '';
    }

    private function list_directory(string $path): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("Directory not found: $path");
        }

        if (!is_dir($full_path)) {
            throw new \Exception("Not a directory: $path");
        }

        $items = [];
        $entries = scandir($full_path);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entry_path = $full_path . '/' . $entry;
            $items[] = [
                'name'     => $entry,
                'type'     => is_dir($entry_path) ? 'directory' : 'file',
                'size'     => is_file($entry_path) ? filesize($entry_path) : null,
                'modified' => date('Y-m-d H:i:s', filemtime($entry_path)),
            ];
        }

        return [
            'path'  => $path,
            'items' => $items,
            'count' => count($items),
        ];
    }

    private function search_files(string $pattern): array {
        $pattern = $this->validate_glob_pattern($pattern);
        $files = glob($this->wp_content_path . '/' . $pattern);
        $results = [];

        if ($files !== false) {
            $wp_content_real = realpath($this->wp_content_path);
            foreach ($files as $file) {
                $real_file = realpath($file);
                if ($wp_content_real === false || $real_file === false || strpos($real_file, $wp_content_real) !== 0) {
                    continue;
                }

                $relative = str_replace($this->wp_content_path . '/', '', $file);
                $results[] = [
                    'path' => $relative,
                    'type' => is_dir($file) ? 'directory' : 'file',
                    'size' => is_file($file) ? filesize($file) : null,
                ];
            }
        }

        return [
            'pattern' => $pattern,
            'matches' => $results,
            'count'   => count($results),
        ];
    }

    private function search_content(string $needle, string $directory = '', string $file_pattern = '*.php'): array {
        $this->validate_glob_pattern($file_pattern);
        $search_path = $this->wp_content_path;
        if ($directory !== '') {
            $search_path = $this->resolve_path($directory);
        }

        $results = [];
        $this->search_content_recursive($search_path, $needle, $file_pattern, $results);

        return [
            'needle'    => $needle,
            'directory' => $directory !== '' ? $directory : 'wp-content',
            'matches'   => $results,
            'count'     => count($results),
        ];
    }

    private function search_content_recursive(string $dir, string $needle, string $pattern, array &$results, int $limit = 50): void {
        if (count($results) >= $limit || !is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/' . $pattern);
        if ($files !== false) {
            foreach ($files as $file) {
                if (count($results) >= $limit) {
                    return;
                }

                if (!is_file($file)) {
                    continue;
                }

                $content = file_get_contents($file);
                if ($content === false || stripos($content, $needle) === false) {
                    continue;
                }

                $lines = explode("\n", $content);
                $matching_lines = [];
                foreach ($lines as $line_num => $line) {
                    if (stripos($line, $needle) !== false) {
                        $matching_lines[] = [
                            'line'    => $line_num + 1,
                            'content' => trim(substr($line, 0, 200)),
                        ];
                    }
                }

                $results[] = [
                    'path'    => str_replace($this->wp_content_path . '/', '', $file),
                    'matches' => array_slice($matching_lines, 0, 5),
                ];
            }
        }

        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        if ($subdirs === false) {
            return;
        }

        foreach ($subdirs as $subdir) {
            if (count($results) >= $limit) {
                return;
            }

            $basename = basename($subdir);
            if ($basename === 'vendor' || $basename === 'node_modules') {
                continue;
            }

            $this->search_content_recursive($subdir, $needle, $pattern, $results, $limit);
        }
    }
}
