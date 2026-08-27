<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers AI Assistant's WpApp scaffolding ability bridge.
 */
class Wp_App_Abilities {

    private ?Git_Tracker_Manager $git_tracker_manager;
    private bool $category_registered = false;
    private bool $abilities_registered = false;
    private bool $category_deferred = false;
    private bool $abilities_deferred = false;

    public function __construct(?Git_Tracker_Manager $git_tracker_manager = null) {
        $this->git_tracker_manager = $git_tracker_manager;

        $this->add_init_safe_action('wp_abilities_api_categories_init', 'register_category');
        $this->add_init_safe_action('wp_abilities_api_init', 'register_abilities');

        add_filter('ai_assistant_ability_domains', [$this, 'register_ability_domain']);
    }

    private function add_init_safe_action(string $hook, string $method): void {
        if (function_exists('did_action') && did_action($hook)) {
            $this->$method();
            return;
        }

        add_action($hook, [$this, $method]);
    }

    private function is_init_or_later(): bool {
        if (!function_exists('did_action')) {
            return true;
        }

        if (did_action('init')) {
            return true;
        }

        return function_exists('doing_action') && doing_action('init');
    }

    public function register_category(): void {
        if ($this->category_registered) {
            return;
        }

        if (!$this->is_init_or_later()) {
            if (!$this->category_deferred) {
                add_action('init', [$this, 'register_category'], 0);
                $this->category_deferred = true;
            }
            return;
        }

        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category('create-wp-app', [
            'label'       => __('Create WpApp', 'ai-assistant'),
            'description' => __('Scaffold WordPress app plugins powered by WpApp.', 'ai-assistant'),
        ]);

        $this->category_registered = true;
    }

    public function register_abilities(): void {
        if ($this->abilities_registered) {
            return;
        }

        if (!$this->is_init_or_later()) {
            if (!$this->abilities_deferred) {
                add_action('init', [$this, 'register_abilities'], 0);
                $this->abilities_deferred = true;
            }
            return;
        }

        if (!function_exists('wp_register_ability')) {
            return;
        }

        if (!$this->has_create_wp_app_dependency()) {
            return;
        }

        wp_register_ability('ai/create-wp-app', [
            'label'               => __('Create WpApp Plugin', 'ai-assistant'),
            'description'         => __('Creates a self-contained WordPress plugin powered by WpApp under wp-content/plugins. The generated app includes its own WpApp dependency copy and Composer-lite autoloader, so Composer is not required inside WordPress Playground.', 'ai-assistant'),
            'category'            => 'create-wp-app',
            'input_schema'        => $this->get_input_schema(),
            'output_schema'       => $this->get_output_schema(),
            'execute_callback'    => [$this, 'scaffold_app'],
            'permission_callback' => [$this, 'can_scaffold_app'],
            'meta'                => [
                'annotations'  => [
                    'readonly'     => false,
                    'destructive'  => true,
                    'instructions' => 'Use this whenever the user asks to create a wp app, WordPress app, WpApp app, or app plugin. Use this ability instead of manually writing files when creating WpApp-powered WordPress apps. Pass a slug and any user-provided naming details. The ability chooses the target plugin directory and no-Composer dependency mode. After scaffolding and any requested follow-up work are finished, call navigate with the returned app URL and concise link_text to offer opening the generated app.',
                ],
                'show_in_rest' => true,
            ],
        ]);

        if ($this->has_convert_to_wp_app_dependency()) {
            wp_register_ability('ai/convert-to-wp-app', [
                'label'               => __('Convert App to WpApp Plugin', 'ai-assistant'),
                'description'         => __('Turns an existing single-page HTML app into a self-contained WordPress plugin powered by WpApp under wp-content/plugins. Pass the full index.html and any additional files inline; the app gets its own URL, WpApp head/body integration and a bundled WpApp runtime, so Composer is not required.', 'ai-assistant'),
                'category'            => 'create-wp-app',
                'input_schema'        => $this->get_convert_input_schema(),
                'output_schema'       => $this->get_output_schema(),
                'execute_callback'    => [$this, 'convert_app'],
                'permission_callback' => [$this, 'can_scaffold_app'],
                'meta'                => [
                    'annotations'  => [
                        'readonly'     => false,
                        'destructive'  => true,
                        'instructions' => 'Use this instead of create-wp-app when the app already exists as HTML, for example an app built in this conversation that the user wants on their WordPress site. Pass the complete index.html as index_html and every other file it references relatively (scripts, styles, images) in files. Do not write the files with file tools first. Keep external CDN URLs as they are; only relative URLs are rewritten. After conversion, call navigate with the returned app URL and concise link_text to offer opening the app.',
                    ],
                    'show_in_rest' => true,
                ],
            ]);
        }

        $this->abilities_registered = true;
    }

    public function register_ability_domain(array $domains): array {
        if (!$this->has_create_wp_app_dependency()) {
            return $domains;
        }

        $domains['create-wp-app'] = 'wp app, wordpress app, wpapp, WpApp, app plugin, create wp app, scaffold app, convert app, publish app, install app, html app';
        return $domains;
    }

    public function can_scaffold_app(): bool {
        return current_user_can('activate_plugins') || current_user_can('install_plugins') || current_user_can('manage_options');
    }

    public function scaffold_app($input) {
        if (!$this->has_create_wp_app_dependency()) {
            return $this->error('missing_dependency', 'The akirk/create-wp-app dependency is not loaded.');
        }

        $input = is_array($input) ? $input : [];
        $base_slug = $this->normalize_slug($input['slug'] ?? '');
        if ($base_slug === '') {
            return $this->error('missing_slug', 'A valid plugin slug is required.');
        }
        $slug = $this->ensure_mywp_suffix($base_slug);
        $display_slug = $this->strip_mywp_suffix($base_slug);

        $plugins_dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'plugins';
        $target_dir = $plugins_dir . DIRECTORY_SEPARATOR . $slug;
        $plugin_file = $slug . '.php';
        $overwrite = !empty($input['overwrite']);
        $target_existed = is_dir($target_dir);

        if ($target_existed && !$overwrite) {
            return $this->error('plugin_exists', "The plugin directory already exists: {$slug}");
        }

        $plugin_name = $this->string_arg($input, 'plugin_name', \Akirk\CreateWpApp\Scaffolder::slug_to_title($display_slug));
        $url_path = $this->normalize_mywp_url_path($input['url_path'] ?? $slug, $slug);

        try {
            $result = \Akirk\CreateWpApp\Scaffolder::create([
                'slug'            => $slug,
                'plugin_name'     => $plugin_name,
                'namespace'       => $this->string_arg($input, 'namespace', \Akirk\CreateWpApp\Scaffolder::to_namespace($plugin_name)),
                'author'          => $this->string_arg($input, 'author', ''),
                'url_path'        => $url_path,
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
            if (!$activated) {
                $message = !empty($warnings)
                    ? implode(' ', $warnings)
                    : 'WordPress sandboxed activation did not complete.';
                return $this->error('activation_failed', 'Plugin scaffolded but activation failed: ' . $message);
            }
        }

        $url_path = $result['config']['url_path'] ?? $slug;
        $created_files = $this->relative_created_files($target_dir);
        if (!$target_existed) {
            $this->track_created_files($slug, $created_files, $plugin_name);
        }

        $response = [
            'plugin_dir'   => $target_dir,
            'plugin_file'  => $target_dir . DIRECTORY_SEPARATOR . $plugin_file,
            'plugin_slug'  => $slug,
            'url_path'     => $url_path,
            'url'          => function_exists('home_url') ? home_url('/' . trim($url_path, '/') . '/') : '/' . trim($url_path, '/') . '/',
            'activated'    => $activated,
            'created_files'=> $created_files,
            'messages'     => $result['messages'] ?? [],
            'warnings'     => $warnings,
        ];

        if ($this->git_tracker_manager !== null) {
            $ai_changes = $this->git_tracker_manager->get_ai_changes_metadata_for_path('plugins/' . $slug . '/' . $plugin_file);
            if ($ai_changes !== null) {
                $response['ai_changes'] = $ai_changes;
            }
        }

        return $response;
    }

    /**
     * Converts an inline single-page HTML app into a WpApp plugin.
     *
     * @param array $input index_html, optional files map, slug, naming and flags.
     * @return array|\WP_Error
     */
    public function convert_app($input) {
        if (!$this->has_convert_to_wp_app_dependency()) {
            return $this->error('missing_dependency', 'The convert-to-wp-app library or the akirk/wp-app runtime is not available.');
        }

        $input = is_array($input) ? $input : [];
        $base_slug = $this->normalize_slug((string) ($input['slug'] ?? ''));
        if ($base_slug === '') {
            return $this->error('missing_slug', 'A valid plugin slug is required.');
        }
        $slug = $this->ensure_mywp_suffix($base_slug);
        $display_slug = $this->strip_mywp_suffix($base_slug);

        $index_html = isset($input['index_html']) && is_string($input['index_html']) ? $input['index_html'] : '';
        if (trim($index_html) === '') {
            return $this->error('missing_index_html', 'index_html must contain the complete HTML of the app.');
        }
        if (!preg_match('/<body\b/i', $index_html)) {
            return $this->error('invalid_index_html', 'index_html must be a complete HTML document with a <body> element.');
        }

        $files = $this->normalize_inline_files($input['files'] ?? []);
        if ($files instanceof \WP_Error || (is_array($files) && isset($files['error']))) {
            return $files;
        }

        $plugins_dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'plugins';
        $target_dir = $plugins_dir . DIRECTORY_SEPARATOR . $slug;
        $plugin_file = $slug . '.php';
        $overwrite = !empty($input['overwrite']);
        $target_existed = is_dir($target_dir);

        if ($target_existed && !$overwrite) {
            return $this->error('plugin_exists', "The plugin directory already exists: {$slug}");
        }

        $plugin_name = $this->string_arg($input, 'plugin_name', convert_to_wp_app_title($display_slug));
        $url_path = $this->normalize_mywp_url_path($input['url_path'] ?? $slug, $slug);
        $author = $this->string_arg($input, 'author', '');

        $staging_dir = $this->create_staging_dir($slug);
        if ($staging_dir === null) {
            return $this->error('staging_failed', 'Could not create a temporary directory for the app files.');
        }

        try {
            file_put_contents($staging_dir . '/index.html', $index_html);
            foreach ($files as $path => $content) {
                $full = $staging_dir . '/' . $path;
                convert_to_wp_app_mkdir(dirname($full));
                file_put_contents($full, $content);
            }

            if (!convert_to_wp_app_is_deployable_index($staging_dir . '/index.html')) {
                return $this->error('not_deployable', 'index_html looks like a bundler development entry (it references /src/ or %PUBLIC_URL%). Build the app first and pass the built index.html.');
            }

            if ($target_existed) {
                convert_to_wp_app_remove_directory($target_dir);
            }
            convert_to_wp_app_mkdir($target_dir);

            $asset_dir = $target_dir . '/app';
            convert_to_wp_app_copy_directory($staging_dir, $asset_dir);

            $template = convert_to_wp_app_create_template($index_html, $slug);
            convert_to_wp_app_mkdir($target_dir . '/templates');
            file_put_contents($target_dir . '/templates/index.php', $template);

            convert_to_wp_app_copy_wp_app_runtime($this->get_wp_app_source_dir(), $target_dir);
            file_put_contents($target_dir . '/vendor/autoload.php', convert_to_wp_app_autoload_php());
            file_put_contents($target_dir . '/' . $plugin_file, convert_to_wp_app_plugin_php($slug, $plugin_name, $url_path, $author));
        } catch (\Throwable $e) {
            return $this->error('convert_failed', $e->getMessage());
        } finally {
            convert_to_wp_app_remove_directory($staging_dir);
        }

        $activated = false;
        $warnings = [];
        if (!isset($input['activate']) || !empty($input['activate'])) {
            $activation = $this->activate_plugin($slug . '/' . $plugin_file);
            $activated = $activation['activated'];
            $warnings = array_merge($warnings, $activation['warnings']);
            if (!$activated) {
                $message = !empty($warnings)
                    ? implode(' ', $warnings)
                    : 'WordPress sandboxed activation did not complete.';
                return $this->error('activation_failed', 'Plugin converted but activation failed: ' . $message);
            }
            if (function_exists('flush_rewrite_rules')) {
                flush_rewrite_rules();
            }
        }

        $created_files = $this->relative_created_files($target_dir);
        if (!$target_existed) {
            $this->track_created_files($slug, $created_files, $plugin_name, sprintf('Convert %s into a WpApp plugin', $plugin_name));
        }

        $response = [
            'plugin_dir'    => $target_dir,
            'plugin_file'   => $target_dir . DIRECTORY_SEPARATOR . $plugin_file,
            'plugin_slug'   => $slug,
            'url_path'      => $url_path,
            'url'           => function_exists('home_url') ? home_url('/' . trim($url_path, '/') . '/') : '/' . trim($url_path, '/') . '/',
            'activated'     => $activated,
            'created_files' => $created_files,
            'messages'      => [sprintf('Converted %d file(s) into the %s plugin.', count($files) + 1, $slug)],
            'warnings'      => $warnings,
        ];

        if ($this->git_tracker_manager !== null) {
            $ai_changes = $this->git_tracker_manager->get_ai_changes_metadata_for_path('plugins/' . $slug . '/' . $plugin_file);
            if ($ai_changes !== null) {
                $response['ai_changes'] = $ai_changes;
            }
        }

        return $response;
    }

    /**
     * Validates the inline files map and decodes base64 entries.
     *
     * @return array<string,string>|\WP_Error|array
     */
    private function normalize_inline_files($files) {
        if ($files === null || $files === '' || $files === []) {
            return [];
        }
        if (!is_array($files)) {
            return $this->error('invalid_files', 'files must be an object mapping relative paths to contents.');
        }

        $normalized = [];
        foreach ($files as $path => $content) {
            $path = str_replace('\\', '/', trim((string) $path));
            $path = (string) preg_replace('#/+#', '/', $path);
            $segments = explode('/', $path);
            $invalid = $path === ''
                || $path[0] === '/'
                || preg_match('/^[a-z]:/i', $path)
                || $path === 'index.html'
                || strpos($path, "\0") !== false
                || in_array('..', $segments, true)
                || in_array('', $segments, true)
                || in_array('.', $segments, true)
                || $segments[0] === '.git';
            if ($invalid) {
                return $this->error('invalid_file_path', "Invalid file path in files: {$path}");
            }

            if (is_array($content)) {
                if (!isset($content['base64']) || !is_string($content['base64'])) {
                    return $this->error('invalid_file_content', "Binary entries need a base64 key: {$path}");
                }
                $decoded = base64_decode($content['base64'], true);
                if ($decoded === false) {
                    return $this->error('invalid_file_content', "Could not decode base64 content: {$path}");
                }
                $content = $decoded;
            } elseif (!is_string($content)) {
                return $this->error('invalid_file_content', "File contents must be a string or a {\"base64\": ...} object: {$path}");
            }

            $normalized[$path] = $content;
        }

        return $normalized;
    }

    private function create_staging_dir(string $slug): ?string {
        $base = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $base = rtrim(str_replace('\\', '/', (string) $base), '/');
        if ($base === '' || !is_dir($base) || !is_writable($base)) {
            $base = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/upgrade';
        }

        for ($i = 0; $i < 5; $i++) {
            $dir = $base . '/convert-to-wp-app-' . $slug . '-' . bin2hex(random_bytes(4));
            if (!is_dir($dir) && @mkdir($dir, 0700, true)) {
                return $dir;
            }
        }

        return null;
    }

    private function has_convert_to_wp_app_dependency(): bool {
        if (!function_exists('convert_to_wp_app_create_template')) {
            $lib = dirname(__DIR__) . '/includes/convert-to-wp-app/playground-convert.php';
            if (file_exists($lib)) {
                require_once $lib;
            }
        }

        return function_exists('convert_to_wp_app_create_template') && $this->get_wp_app_source_dir() !== null;
    }

    private function get_convert_input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Plugin slug and directory basename for the app, e.g. timetable. Do not include the generic word app unless the user named it that way. A single -mywp suffix is appended automatically when missing.',
                    'pattern'     => '^[a-z0-9][a-z0-9-]*$',
                ],
                'index_html' => [
                    'type'        => 'string',
                    'description' => 'The complete index.html of the app, including <head> and <body>. Its <title> is replaced by the WordPress app title; relative src/href URLs are rewritten to the plugin asset directory.',
                ],
                'files' => [
                    'type'        => 'object',
                    'description' => 'Additional files referenced by index.html, keyed by relative path (e.g. "app.js", "css/style.css"). Values are the text content, or {"base64": "..."} for binary files such as images.',
                    'additionalProperties' => [
                        'oneOf' => [
                            ['type' => 'string'],
                            [
                                'type'                 => 'object',
                                'properties'           => ['base64' => ['type' => 'string']],
                                'required'             => ['base64'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ],
                'plugin_name' => [
                    'type'        => 'string',
                    'description' => 'Human-readable plugin and app name. Defaults to title case from slug.',
                ],
                'author' => [
                    'type'        => 'string',
                    'description' => 'Optional plugin author display name.',
                ],
                'url_path' => [
                    'type'        => 'string',
                    'description' => 'URL path where the app should be mounted. Defaults to slug. A single -mywp suffix is appended to the final path segment when missing.',
                ],
                'activate' => [
                    'type'        => 'boolean',
                    'description' => 'Whether to activate the generated plugin after conversion.',
                    'default'     => true,
                ],
                'overwrite' => [
                    'type'        => 'boolean',
                    'description' => 'Whether to replace an existing plugin directory with the same slug.',
                    'default'     => false,
                ],
            ],
            'required'             => ['slug', 'index_html'],
            'additionalProperties' => false,
        ];
    }

    private function get_input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Plugin slug and directory basename for the product/domain, e.g. timetable. Do not include the generic word app or use an -app suffix unless the user explicitly named the product that way. A single -mywp suffix is appended automatically when missing.',
                    'pattern'     => '^[a-z0-9][a-z0-9-]*$',
                ],
                'plugin_name' => [
                    'type'        => 'string',
                    'description' => 'Human-readable plugin name. Defaults to title case from slug. Do not add the generic word App unless the user explicitly named the product that way.',
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
                    'description' => 'URL path where the app should be mounted. Defaults to slug. A single -mywp suffix is appended to the final path segment when missing.',
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

    private function ensure_mywp_suffix(string $slug): string {
        if ($slug === '' || substr($slug, -5) === '-mywp') {
            return $slug;
        }

        return $slug . '-mywp';
    }

    private function strip_mywp_suffix(string $slug): string {
        if (substr($slug, -5) !== '-mywp') {
            return $slug;
        }

        return substr($slug, 0, -5);
    }

    private function has_create_wp_app_dependency(): bool {
        return class_exists('\Akirk\CreateWpApp\Scaffolder');
    }

    private function normalize_url_path($path): string {
        $path = strtolower(trim((string) $path));
        $path = trim($path, '/');
        $path = preg_replace('/[^a-z0-9\/-]+/', '-', $path);
        $path = preg_replace('#/+#', '/', (string) $path);
        return trim((string) $path, '/-') ?: 'app';
    }

    private function normalize_mywp_url_path($path, string $fallback): string {
        $path = is_scalar($path) && trim((string) $path) !== '' ? $path : $fallback;
        $path = $this->normalize_url_path($path);
        $segments = explode('/', $path);
        $last = array_pop($segments);
        $segments[] = $this->ensure_mywp_suffix((string) $last);

        return implode('/', $segments);
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

        // WordPress core runs plugin_sandbox_scrape() inside activate_plugin()
        // before adding the plugin to active_plugins. Surface that result as the
        // ability result instead of returning a successful scaffold with an
        // inactive plugin when sandboxed activation fails.
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

    private function track_created_files(string $slug, array $created_files, string $plugin_name, ?string $reason = null): void {
        if ($this->git_tracker_manager === null || empty($created_files)) {
            return;
        }

        $reason = $reason ?? sprintf('Scaffold %s WpApp plugin', $plugin_name);
        $changes = [];

        foreach ($created_files as $file) {
            $file = ltrim(str_replace('\\', '/', (string) $file), '/');
            if ($file === '' || strpos($file, "\0") !== false) {
                continue;
            }

            $changes[] = [
                'path' => 'plugins/' . $slug . '/' . $file,
                'change_type' => 'created',
                'original_content' => null,
            ];
        }

        if (!empty($changes)) {
            $this->git_tracker_manager->track_changes($changes, $reason);
        }
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
