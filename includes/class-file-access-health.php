<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site Health test for the file tools: which plugins and themes the PHP
 * process can actually write to.
 *
 * Outside agents connected through MCP have no way to notice that a plugin is
 * owned by a different user until a write fails, so this surfaces it up front.
 * The test is registered with Site Health only while File Access is enabled;
 * the same result is rendered on the File Access settings tab.
 */
class File_Access_Health {

    public const TEST_ID = 'ai_assistant_file_access';

    /** Stop scanning a single plugin or theme after this many entries. */
    private const MAX_ENTRIES_PER_ROOT = 5000;

    /** Directories the file tools never edit; git keeps objects read-only by design. */
    private const SKIPPED_DIRECTORIES = ['.git', 'node_modules'];

    private string $wp_content_dir;
    private string $plugin_dir;
    private string $theme_dir;

    public function __construct(?string $wp_content_dir = null, ?string $plugin_dir = null, ?string $theme_dir = null) {
        $this->wp_content_dir = rtrim($wp_content_dir ?? WP_CONTENT_DIR, '/\\');
        $this->plugin_dir = rtrim($plugin_dir ?? WP_PLUGIN_DIR, '/\\');
        $this->theme_dir = rtrim($theme_dir ?? get_theme_root(), '/\\');
    }

    public function register(): void {
        if (!File_Abilities::is_enabled()) {
            return;
        }

        add_filter('site_status_tests', [$this, 'add_site_status_test']);
    }

    public function add_site_status_test($tests) {
        if (!is_array($tests)) {
            return $tests;
        }

        $tests['direct'][self::TEST_ID] = [
            'label' => __('AI Assistant file access', 'ai-assistant'),
            'test'  => [$this, 'run_test'],
        ];

        return $tests;
    }

    /**
     * Inspect wp-content for locations the PHP process cannot write to.
     *
     * @return array {
     *     @type string   $process_user  User PHP runs as, or '' when unknown.
     *     @type string[] $roots         Unwritable container directories (relative to wp-content).
     *     @type array[]  $unwritable    Plugins/themes with at least one unwritable entry:
     *                                   [path, type, owner, example].
     *     @type int      $checked       Number of plugins and themes inspected.
     * }
     */
    public function check(): array {
        $result = [
            'process_user' => $this->get_process_user(),
            'roots'        => [],
            'unwritable'   => [],
            'checked'      => 0,
        ];

        foreach (['plugins' => $this->plugin_dir, 'themes' => $this->theme_dir] as $type => $container) {
            if (!is_dir($container)) {
                continue;
            }

            if (!is_writable($container)) {
                $result['roots'][] = $this->relative($container);
            }

            $dirs = glob($container . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $dir) {
                $result['checked']++;
                $blocked = $this->find_unwritable_entry($dir);
                if ($blocked === null) {
                    continue;
                }

                $result['unwritable'][] = [
                    'path'    => $this->relative($dir),
                    'type'    => rtrim($type, 's'),
                    'owner'   => $this->get_owner($blocked),
                    'example' => $this->relative($blocked),
                ];
            }
        }

        return $result;
    }

    /**
     * Site Health result, also rendered on the File Access tab.
     */
    public function run_test(): array {
        $data = $this->check();
        $settings_url = admin_url('options-general.php?page=ai-assistant-settings#file-access');

        $result = [
            'label'       => __('AI Assistant can write to all plugins and themes', 'ai-assistant'),
            'status'      => 'good',
            'badge'       => [
                'label' => __('AI Assistant', 'ai-assistant'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html(sprintf(
                /* translators: %d: number of plugins and themes */
                _n('All %d plugin and theme directory is writable by the web server, so file tools and MCP clients can edit them.', 'All %d plugin and theme directories are writable by the web server, so file tools and MCP clients can edit them.', $data['checked'], 'ai-assistant'),
                $data['checked']
            )) . '</p>',
            'actions'     => '',
            'test'        => self::TEST_ID,
        ];

        if (empty($data['roots']) && empty($data['unwritable'])) {
            return $result;
        }

        $result['label'] = __('Some plugins or themes are not writable by AI Assistant', 'ai-assistant');
        $result['status'] = 'recommended';

        $process_user = $data['process_user'];
        $intro = $process_user !== ''
            ? sprintf(
                /* translators: %s: system user name */
                __('PHP runs as %s. File tools and MCP clients can only change files that user may write to.', 'ai-assistant'),
                '<code>' . esc_html($process_user) . '</code>'
            )
            : esc_html__('File tools and MCP clients can only change files the web server user may write to.', 'ai-assistant');

        $items = [];
        foreach ($data['roots'] as $root) {
            $items[] = '<li>' . sprintf(
                /* translators: %s: directory path */
                esc_html__('%s is not writable, so new plugins or themes cannot be created there.', 'ai-assistant'),
                '<code>' . esc_html($root) . '</code>'
            ) . '</li>';
        }
        foreach ($data['unwritable'] as $entry) {
            if ($entry['owner'] !== '' && $entry['owner'] !== $process_user) {
                $detail = sprintf(
                    /* translators: %s: system user name */
                    esc_html__('owned by %s', 'ai-assistant'),
                    '<code>' . esc_html($entry['owner']) . '</code>'
                );
            } else {
                $detail = sprintf(
                    /* translators: %s: file path */
                    esc_html__('read-only permissions, e.g. %s', 'ai-assistant'),
                    '<code>' . esc_html($entry['example']) . '</code>'
                );
            }
            $items[] = '<li><code>' . esc_html($entry['path']) . '</code> &mdash; ' . $detail . '</li>';
        }

        $result['description'] = '<p>' . $intro . '</p><ul>' . implode('', $items) . '</ul><p>'
            . esc_html__('Change the owner or permissions of these directories so the web server user can write to them, or leave them read-only if they should not be edited.', 'ai-assistant')
            . '</p>';
        $result['actions'] = sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url($settings_url),
            esc_html__('File Access settings', 'ai-assistant')
        );

        return $result;
    }

    /**
     * Walk a plugin or theme and return the first entry PHP cannot write to.
     */
    private function find_unwritable_entry(string $dir): ?string {
        if (!is_writable($dir)) {
            return $dir;
        }

        try {
            $filtered = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $entry): bool {
                    return !($entry->isDir() && in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true));
                }
            );
            $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::SELF_FIRST);
        } catch (\Throwable $e) {
            return $dir;
        }

        $count = 0;
        foreach ($iterator as $entry) {
            if (++$count > self::MAX_ENTRIES_PER_ROOT) {
                break;
            }
            $path = $entry->getPathname();
            if (!is_writable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function relative(string $path): string {
        if (strpos($path, $this->wp_content_dir . '/') === 0) {
            return substr($path, strlen($this->wp_content_dir) + 1);
        }

        return $path;
    }

    private function get_owner(string $path): string {
        $uid = @fileowner($path);
        if ($uid === false) {
            return '';
        }

        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);
            if (is_array($info) && !empty($info['name'])) {
                return $info['name'];
            }
        }

        return (string) $uid;
    }

    private function get_process_user(): string {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return $info['name'];
            }
        }

        $user = @get_current_user();
        return is_string($user) ? $user : '';
    }
}
