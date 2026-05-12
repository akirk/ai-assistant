<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers AI Assistant's WpApp scaffolding ability bridge.
 */
class Wp_App_Abilities {

    public function __construct() {
        if (function_exists('did_action') && did_action('wp_abilities_api_categories_init')) {
            $this->register_category();
        } else {
            add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        }

        if (function_exists('did_action') && did_action('wp_abilities_api_init')) {
            $this->register_abilities();
        } else {
            add_action('wp_abilities_api_init', [$this, 'register_abilities']);
        }

        add_filter('ai_assistant_ability_domains', [$this, 'register_ability_domain']);
    }

    public function register_category(): void {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category('create-wp-app', [
            'label'       => __('Create WpApp', 'ai-assistant'),
            'description' => __('Scaffold WordPress app plugins powered by WpApp.', 'ai-assistant'),
        ]);
    }

    public function register_abilities(): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        if (!class_exists('\Akirk\CreateWpApp\Scaffolder')) {
            return;
        }

        wp_register_ability('create-wp-app/scaffold', [
            'label'               => __('Create WpApp Plugin', 'ai-assistant'),
            'description'         => __('Creates a self-contained WordPress plugin powered by WpApp under wp-content/plugins. Use this whenever the user asks to create a wp app, WordPress app, WpApp app, or app plugin. The generated app includes its own WpApp dependency copy and Composer-lite autoloader, so Composer is not required inside WordPress Playground.', 'ai-assistant'),
            'category'            => 'create-wp-app',
            'input_schema'        => $this->get_input_schema(),
            'output_schema'       => $this->get_output_schema(),
            'execute_callback'    => [$this, 'scaffold_app'],
            'permission_callback' => [$this, 'can_scaffold_app'],
            'meta'                => [
                'annotations'  => [
                    'readonly'     => false,
                    'destructive'  => false,
                    'idempotent'   => false,
                    'instructions' => 'Use this ability instead of manually writing files when creating WpApp-powered WordPress apps. Pass a slug and any user-provided naming details. The ability chooses the target plugin directory and no-Composer dependency mode.',
                ],
                'show_in_rest' => false,
            ],
        ]);
    }

    public function register_ability_domain(array $domains): array {
        $domains['create-wp-app'] = 'wp app, wordpress app, wpapp, WpApp, app plugin, create wp app, scaffold app';
        return $domains;
    }

    public function can_scaffold_app(): bool {
        return current_user_can('activate_plugins') || current_user_can('install_plugins') || current_user_can('manage_options');
    }

    public function scaffold_app($input) {
        if (!class_exists('\Akirk\CreateWpApp\Scaffolder')) {
            return $this->error('missing_dependency', 'The akirk/create-wp-app dependency is not loaded.');
        }

        $input = is_array($input) ? $input : [];
        $slug = $this->normalize_slug($input['slug'] ?? '');
        if ($slug === '') {
            return $this->error('missing_slug', 'A valid plugin slug is required.');
        }

        $plugins_dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'plugins';
        $target_dir = $plugins_dir . DIRECTORY_SEPARATOR . $slug;
        $plugin_file = $slug . '.php';
        $overwrite = !empty($input['overwrite']);

        if (is_dir($target_dir) && !$overwrite) {
            return $this->error('plugin_exists', "The plugin directory already exists: {$slug}");
        }

        $plugin_name = $this->string_arg($input, 'plugin_name', \Akirk\CreateWpApp\Scaffolder::slug_to_title($slug));

        try {
            $result = \Akirk\CreateWpApp\Scaffolder::create([
                'slug'            => $slug,
                'plugin_name'     => $plugin_name,
                'namespace'       => $this->string_arg($input, 'namespace', \Akirk\CreateWpApp\Scaffolder::to_namespace($plugin_name)),
                'author'          => $this->string_arg($input, 'author', ''),
                'url_path'        => $this->normalize_url_path($input['url_path'] ?? $slug),
                'setup_type'      => $this->normalize_setup_type($input['setup_type'] ?? 'minimal'),
                'target_dir'      => $target_dir,
                'overwrite'       => $overwrite,
                'dependency_mode' => 'copy',
                'autoload_mode'   => 'polyfill',
                'wp_app_source_dir' => $this->get_wp_app_source_dir(),
            ]);
        } catch (\Throwable $e) {
            return $this->error('scaffold_failed', $e->getMessage());
        }

        $activated = false;
        $warnings = [];
        if (!empty($input['activate'])) {
            $activation = $this->activate_plugin($slug . '/' . $plugin_file);
            $activated = $activation['activated'];
            $warnings = array_merge($warnings, $activation['warnings']);
        }

        $url_path = $result['config']['url_path'] ?? $slug;

        return [
            'plugin_dir'   => $target_dir,
            'plugin_file'  => $target_dir . DIRECTORY_SEPARATOR . $plugin_file,
            'plugin_slug'  => $slug,
            'url_path'     => $url_path,
            'url'          => function_exists('home_url') ? home_url('/' . trim($url_path, '/') . '/') : '/' . trim($url_path, '/') . '/',
            'activated'    => $activated,
            'created_files'=> $this->relative_created_files($target_dir),
            'messages'     => $result['messages'] ?? [],
            'warnings'     => $warnings,
        ];
    }

    private function get_input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Plugin slug and directory basename, e.g. my-app.',
                    'pattern'     => '^[a-z0-9][a-z0-9-]*$',
                ],
                'plugin_name' => [
                    'type'        => 'string',
                    'description' => 'Human-readable plugin name. Defaults to title case from slug.',
                ],
                'namespace' => [
                    'type'        => 'string',
                    'description' => 'PHP namespace for full setup classes. Defaults to PascalCase from plugin name.',
                ],
                'author' => [
                    'type'        => 'string',
                    'description' => 'Optional plugin author display name.',
                ],
                'url_path' => [
                    'type'        => 'string',
                    'description' => 'URL path where the app should be mounted. Defaults to slug.',
                ],
                'setup_type' => [
                    'type'        => 'string',
                    'enum'        => ['minimal', 'full'],
                    'description' => 'Use minimal for simple apps, full for a BaseApp class structure.',
                    'default'     => 'minimal',
                ],
                'activate' => [
                    'type'        => 'boolean',
                    'description' => 'Whether to activate the generated plugin after scaffolding.',
                    'default'     => true,
                ],
                'overwrite' => [
                    'type'        => 'boolean',
                    'description' => 'Whether to overwrite an existing plugin directory with the same slug.',
                    'default'     => false,
                ],
            ],
            'required'             => ['slug'],
            'additionalProperties' => false,
        ];
    }

    private function get_output_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'plugin_dir'    => ['type' => 'string'],
                'plugin_file'   => ['type' => 'string'],
                'plugin_slug'   => ['type' => 'string'],
                'url_path'      => ['type' => 'string'],
                'url'           => ['type' => 'string'],
                'activated'     => ['type' => 'boolean'],
                'created_files' => ['type' => 'array', 'items' => ['type' => 'string']],
                'messages'      => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings'      => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'additionalProperties' => false,
        ];
    }

    private function normalize_slug(string $slug): string {
        $slug = strtolower(trim($slug));
        $slug = str_replace('_', '-', $slug);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
        $slug = trim((string) $slug, '-');
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) ? $slug : '';
    }

    private function normalize_url_path($path): string {
        $path = strtolower(trim((string) $path));
        $path = trim($path, '/');
        $path = preg_replace('/[^a-z0-9\/-]+/', '-', $path);
        $path = preg_replace('#/+#', '/', (string) $path);
        return trim((string) $path, '/-') ?: 'app';
    }

    private function normalize_setup_type($setup_type): string {
        return in_array($setup_type, ['full', '2'], true) ? 'full' : 'minimal';
    }

    private function string_arg(array $input, string $key, string $default): string {
        if (!isset($input[$key])) {
            return $default;
        }

        $value = trim((string) $input[$key]);
        return $value !== '' ? $value : $default;
    }

    private function activate_plugin(string $plugin): array {
        $warnings = [];

        if (!function_exists('activate_plugin')) {
            $plugin_admin = trailingslashit(ABSPATH) . 'wp-admin/includes/plugin.php';
            if (file_exists($plugin_admin)) {
                require_once $plugin_admin;
            }
        }

        if (!function_exists('activate_plugin')) {
            return [
                'activated' => false,
                'warnings'  => ['Could not activate the plugin because activate_plugin() is unavailable.'],
            ];
        }

        $result = activate_plugin($plugin);
        if (is_wp_error($result)) {
            $warnings[] = $result->get_error_message();
            return [
                'activated' => false,
                'warnings'  => $warnings,
            ];
        }

        return [
            'activated' => true,
            'warnings'  => $warnings,
        ];
    }

    private function get_wp_app_source_dir(): ?string {
        $candidates = [];

        if (defined('AI_ASSISTANT_PLUGIN_DIR')) {
            $candidates[] = AI_ASSISTANT_PLUGIN_DIR . 'vendor/akirk/wp-app';
        }

        $candidates[] = dirname(__DIR__) . '/vendor/akirk/wp-app';

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function relative_created_files(string $target_dir): array {
        if (!is_dir($target_dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target_dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($target_dir) + 1));
        }

        sort($files);
        return $files;
    }

    private function error(string $code, string $message) {
        if (class_exists('\WP_Error')) {
            return new \WP_Error($code, $message);
        }

        return [
            'error' => $code,
            'message' => $message,
        ];
    }
}
