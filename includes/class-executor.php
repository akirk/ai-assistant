<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tool Executor - Handles execution of AI tools
 */
class Executor {

    private $tools;
    private $file_tools;

    public function __construct(Tools $tools, ?Git_Tracker_Manager $git_tracker_manager = null) {
        $this->tools = $tools;
        $this->file_tools = new File_Tool_Executor(WP_CONTENT_DIR, $git_tracker_manager);
    }

    /**
     * Execute a tool
     *
     * @param string $tool_name Tool name
     * @param array $arguments Tool arguments
     * @param string $permission User permission level
     * @param int|null $conversation_id Optional conversation ID for tracking
     * @return mixed Tool result
     */
    public function execute_tool(string $tool_name, array $arguments, string $permission = 'full', ?int $conversation_id = null) {
        // Validate permission
        $read_only_tools = [
            'read_file', 'list_directory', 'search_files', 'search_content',
            'db_query', 'get_plugins', 'get_themes',
            'list_abilities', 'get_ability', 'list_skills', 'get_skill',
            'find', 'environment_info',
        ];

        // Consolidated 'ability' tool: read-only for list/get, full for execute
        if ($tool_name === 'ability') {
            $action = $arguments['action'] ?? '';
            if ($action !== 'execute') {
                $read_only_tools[] = 'ability';
            }
        }

        // Consolidated 'skill' tool is always read-only
        if ($tool_name === 'skill') {
            $read_only_tools[] = 'skill';
        }

        if ($permission === 'read_only' && !in_array($tool_name, $read_only_tools)) {
            throw new \Exception("Tool '$tool_name' requires full access permission");
        }

        if ($permission === 'chat_only') {
            throw new \Exception("Tool execution not allowed with chat-only permission");
        }

        // For consolidated tools, check component permissions
        if (!$this->check_tool_permission($tool_name, $arguments)) {
            throw new \Exception(
                "Tool '$tool_name' is not enabled. Enable it in AI Assistant → Settings → Tool Permissions."
            );
        }

        // Execute the tool
        switch ($tool_name) {
            // File operations
            case 'read_file':
            case 'write_file':
            case 'edit_file':
            case 'delete_file':
            case 'find':
            case 'list_directory':
            case 'search_files':
            case 'search_content':
                return $this->file_tools->execute($tool_name, $arguments, $conversation_id);

            // Database operations
            case 'db_query':
                return $this->db_query($this->get_string_arg($arguments, 'sql', $tool_name));

            // WordPress operations
            case 'get_plugins':
                return $this->get_plugins();
            case 'get_themes':
                return $this->get_themes();
            case 'install_plugin':
                $slug = $this->get_string_arg($arguments, 'slug', $tool_name);
                $activate = isset($arguments['activate']) ? (bool) $arguments['activate'] : false;
                return $this->install_plugin($slug, $activate);
            case 'run_php':
                return $this->run_php($this->get_string_arg($arguments, 'code', $tool_name));
            case 'navigate':
                return $this->navigate($this->get_string_arg($arguments, 'url', $tool_name));

            // Environment info
            case 'environment_info':
                return $this->get_environment_info(!empty($arguments['include_inactive']));

            // Consolidated ability tool (replaces list_abilities, get_ability, execute_ability)
            case 'ability':
                return $this->execute_ability_consolidated($arguments);

            // Legacy ability tool names
            case 'list_abilities':
                return $this->list_abilities($this->get_string_arg($arguments, 'category', $tool_name, ''));
            case 'get_ability':
                return $this->get_ability($this->get_string_arg($arguments, 'ability', $tool_name));
            case 'execute_ability':
                $ability = $this->get_string_arg($arguments, 'ability', $tool_name);
                $ability_args = $arguments['arguments'] ?? [];
                return $this->execute_ability($ability, $ability_args);

            // Consolidated skill tool (replaces list_skills, get_skill)
            case 'skill':
                return $this->execute_skill_consolidated($arguments);

            // Legacy skill tool names
            case 'list_skills':
                return $this->list_skills($this->get_string_arg($arguments, 'category', $tool_name, ''));
            case 'get_skill':
                return $this->get_skill($this->get_string_arg($arguments, 'skill', $tool_name));

            default:
                throw new \Exception("Unknown tool: $tool_name");
        }
    }

    /**
     * Check tool permission, handling consolidated tool name mapping.
     */
    private function check_tool_permission(string $tool_name, array $arguments): bool {
        // Consolidated tools map to component permissions
        switch ($tool_name) {
            case 'find':
                $text = $arguments['text'] ?? '';
                $glob = $arguments['glob'] ?? '';
                if ($text) {
                    return current_user_can('ai_assistant_tool_search_content');
                }
                if ($glob) {
                    return current_user_can('ai_assistant_tool_search_files');
                }
                return current_user_can('ai_assistant_tool_list_directory');

            case 'ability':
                $action = $arguments['action'] ?? 'list';
                $map = ['list' => 'list_abilities', 'get' => 'get_ability', 'execute' => 'execute_ability'];
                $cap = $map[$action] ?? 'list_abilities';
                return current_user_can('ai_assistant_tool_' . $cap);

            case 'skill':
                $action = $arguments['action'] ?? 'list';
                $cap = $action === 'get' ? 'get_skill' : 'list_skills';
                return current_user_can('ai_assistant_tool_' . $cap);

            case 'environment_info':
                // Read-only, allow if user can use any tool
                return current_user_can('ai_assistant_tool_read_file') ||
                       current_user_can('ai_assistant_tool_run_php');

            default:
                return current_user_can('ai_assistant_tool_' . $tool_name);
        }
    }

    /**
     * Execute the consolidated 'ability' tool.
     */
    private function execute_ability_consolidated(array $arguments) {
        $action = $arguments['action'] ?? 'list';

        switch ($action) {
            case 'list':
                return $this->list_abilities($arguments['category'] ?? '');
            case 'get':
                if (empty($arguments['ability'])) {
                    throw new \Exception("ability tool with action 'get' requires 'ability' argument");
                }
                return $this->get_ability($arguments['ability']);
            case 'execute':
                if (empty($arguments['ability'])) {
                    throw new \Exception("ability tool with action 'execute' requires 'ability' argument");
                }
                return $this->execute_ability($arguments['ability'], $arguments['arguments'] ?? []);
            default:
                throw new \Exception("Unknown ability action: $action");
        }
    }

    /**
     * Execute the consolidated 'skill' tool.
     */
    private function execute_skill_consolidated(array $arguments) {
        $action = $arguments['action'] ?? 'list';

        switch ($action) {
            case 'list':
                return $this->list_skills($arguments['category'] ?? '');
            case 'get':
                if (empty($arguments['skill'])) {
                    throw new \Exception("skill tool with action 'get' requires 'skill' argument");
                }
                return $this->get_skill($arguments['skill']);
            default:
                throw new \Exception("Unknown skill action: $action");
        }
    }

    /**
     * Get environment info (plugins, themes, WordPress version, etc.)
     */
    private function get_environment_info(bool $include_inactive = false): array {
        $theme = wp_get_theme();
        $info = [
            'wp'      => get_bloginfo('version'),
            'php'     => PHP_VERSION,
            'theme'   => $theme->get_template(),
            'plugins' => [],
        ];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins  = get_plugins();
        $active_slugs = get_option('active_plugins', []);

        foreach ($all_plugins as $file => $data) {
            $slug = dirname($file) === '.' ? basename($file, '.php') : dirname($file);
            if (in_array($file, $active_slugs)) {
                $info['plugins'][$slug] = $data['Name'];
            } elseif ($include_inactive) {
                $info['inactive'][$slug] = $data['Name'];
            }
        }

        return $info;
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

    // ===== DATABASE OPERATIONS =====

    private function db_query(string $sql): array {
        global $wpdb;

        // Security: Only allow read-only queries
        $sql = trim($sql);
        $first_word = strtoupper(strtok($sql, " \t\n\r"));
        if (!in_array($first_word, ['SELECT', 'DESCRIBE', 'DESC', 'SHOW'], true)) {
            throw new \Exception("Only SELECT, DESCRIBE, and SHOW queries are allowed with db_query. Use run_php for modifications.");
        }

        // Restrict SHOW to table-related forms only (block SHOW VARIABLES, SHOW STATUS, etc.)
        if ($first_word === 'SHOW') {
            $second_word = strtoupper(strtok(" \t\n\r"));
            if (!in_array($second_word, ['TABLES', 'COLUMNS', 'INDEX', 'INDEXES', 'KEYS', 'CREATE', 'FULL'], true)) {
                throw new \Exception("SHOW is restricted to table-related queries (SHOW TABLES, SHOW COLUMNS, SHOW INDEX, SHOW CREATE TABLE).");
            }
        }

        // Replace {prefix} placeholder
        $sql = str_replace('{prefix}', $wpdb->prefix, $sql);

        $wpdb->suppress_errors(true);
        $results = $wpdb->get_results($sql, ARRAY_A);
        $wpdb->suppress_errors(false);

        if ($wpdb->last_error) {
            throw new \Exception("Database error: " . $wpdb->last_error);
        }

        // Truncate large fields to avoid flooding the context window.
        // post_content on ai_conversation posts is base64-encoded JSON and can be enormous.
        $max_field_length = 500;
        $results = array_map(function($row) use ($max_field_length) {
            $is_ai_conversation = isset($row['post_type']) && $row['post_type'] === 'ai_conversation';
            foreach ($row as $key => $value) {
                if (!is_string($value)) continue;
                if ($is_ai_conversation && $key === 'post_content') {
                    $row[$key] = '[ai_conversation post_content omitted — use summarize_conversation tool instead]';
                } elseif (strlen($value) > $max_field_length) {
                    $row[$key] = substr($value, 0, $max_field_length) . '… [truncated]';
                }
            }
            return $row;
        }, $results);

        return [
            'query' => $sql,
            'rows' => $results,
            'count' => count($results),
        ];
    }

    // ===== WORDPRESS OPERATIONS =====

    private function get_plugins(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        $plugins = [];
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugins[] = [
                'file' => $plugin_file,
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'description' => $plugin_data['Description'],
                'author' => $plugin_data['Author'],
                'active' => in_array($plugin_file, $active_plugins),
            ];
        }

        return [
            'plugins' => $plugins,
            'total' => count($plugins),
            'active_count' => count($active_plugins),
        ];
    }

    private function get_themes(): array {
        $all_themes = wp_get_themes();
        $active_theme = get_stylesheet();

        $themes = [];
        foreach ($all_themes as $theme_slug => $theme) {
            $themes[] = [
                'slug' => $theme_slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'description' => $theme->get('Description'),
                'author' => $theme->get('Author'),
                'active' => $theme_slug === $active_theme,
            ];
        }

        return [
            'themes' => $themes,
            'total' => count($themes),
            'active' => $active_theme,
        ];
    }

    private function install_plugin(string $slug, bool $activate = false): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Check if plugin is already installed
        $installed_plugins = get_plugins();
        foreach ($installed_plugins as $plugin_file => $plugin_data) {
            if (strpos($plugin_file, $slug . '/') === 0 || $plugin_file === $slug . '.php') {
                $is_active = is_plugin_active($plugin_file);

                if ($activate && !$is_active) {
                    $result = activate_plugin($plugin_file);
                    if (is_wp_error($result)) {
                        throw new \Exception('Plugin already installed but activation failed: ' . $result->get_error_message());
                    }
                    return [
                        'status' => 'activated',
                        'message' => "Plugin '{$slug}' was already installed and has been activated.",
                        'plugin_file' => $plugin_file,
                    ];
                }

                return [
                    'status' => 'already_installed',
                    'message' => "Plugin '{$slug}' is already installed" . ($is_active ? ' and active' : ' but not active') . ".",
                    'plugin_file' => $plugin_file,
                    'active' => $is_active,
                ];
            }
        }

        // Get plugin info from wordpress.org
        $api = plugins_api('plugin_information', [
            'slug' => $slug,
            'fields' => [
                'sections' => false,
                'short_description' => true,
            ],
        ]);

        if (is_wp_error($api)) {
            throw new \Exception("Plugin '{$slug}' not found on wordpress.org: " . $api->get_error_message());
        }

        // Install the plugin
        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            throw new \Exception('Installation failed: ' . $result->get_error_message());
        }

        if ($result === false) {
            $errors = $skin->get_errors();
            if (is_wp_error($errors) && $errors->has_errors()) {
                throw new \Exception('Installation failed: ' . $errors->get_error_message());
            }
            throw new \Exception('Installation failed for unknown reason.');
        }

        // Find the installed plugin file
        $plugin_file = $upgrader->plugin_info();

        // Activate if requested
        if ($activate && $plugin_file) {
            $activate_result = activate_plugin($plugin_file);
            if (is_wp_error($activate_result)) {
                return [
                    'status' => 'installed',
                    'message' => "Plugin '{$slug}' installed successfully but activation failed: " . $activate_result->get_error_message(),
                    'plugin_file' => $plugin_file,
                    'active' => false,
                ];
            }
            return [
                'status' => 'installed_and_activated',
                'message' => "Plugin '{$slug}' installed and activated successfully.",
                'plugin_file' => $plugin_file,
                'active' => true,
            ];
        }

        return [
            'status' => 'installed',
            'message' => "Plugin '{$slug}' installed successfully.",
            'plugin_file' => $plugin_file,
            'active' => false,
        ];
    }

    private function run_php(string $code): array {
        ob_start();
        $error = null;
        $result = null;

        try {
            $result = eval($code);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $output = ob_get_clean();

        if ($error !== null) {
            throw new \Exception("PHP error: $error");
        }

        return [
            'result' => $result,
            'output' => $output,
        ];
    }

    private function navigate(string $url): array {
        $home_url = home_url();
        $validated_url = null;

        // Handle relative URLs
        if (strpos($url, '/') === 0) {
            $validated_url = home_url($url);
        } elseif (strpos($url, $home_url) === 0) {
            $validated_url = $url;
        } else {
            throw new \Exception("Invalid URL: The URL must be within the WordPress site (must start with '$home_url' or be a relative path starting with '/')");
        }

        // Block ThickBox/iframe URLs that won't have AI assistant access
        if (strpos($validated_url, 'TB_iframe=true') !== false ||
            strpos($validated_url, 'tab=plugin-information') !== false) {
            throw new \Exception("Cannot navigate to modal/iframe URLs (like plugin information popups) as the AI assistant won't be available there. Try navigating to the main plugin page instead.");
        }

        return [
            'url' => $validated_url,
            'action' => 'navigate',
            'message' => 'Ready to navigate to: ' . $validated_url,
        ];
    }

    // ===== ABILITIES API OPERATIONS =====

    private function list_abilities(string $category = ''): array {
        if (!function_exists('wp_get_abilities')) {
            return [
                'error' => 'Abilities API not available',
                'message' => 'WordPress 6.9+ with the Abilities API is required',
                'abilities' => [],
            ];
        }

        $abilities = wp_get_abilities();

        if (!empty($category)) {
            $get_cat = function($ability) {
                return is_object($ability) ? ($ability->category ?? '') : ($ability['category'] ?? '');
            };
            $get_id = function($id, $ability) {
                return is_object($ability) ? ($ability->name ?? $id) : $id;
            };
            $get_label = function($id, $ability) {
                if (is_object($ability)) return $ability->label ?? $ability->name ?? $id;
                return $ability['label'] ?? $ability['name'] ?? $id;
            };

            // 1. Exact category match
            $exact = array_filter($abilities, function($ability) use ($category, $get_cat) {
                return $get_cat($ability) === $category;
            });
            if (!empty($exact)) {
                $abilities = $exact;
            } else {
                // 2. Substring category match — guard against empty $cat (stripos('crm','') === 0, not false)
                $by_cat = array_filter($abilities, function($ability) use ($category, $get_cat) {
                    $cat = $get_cat($ability);
                    return !empty($cat) && (stripos($cat, $category) !== false || stripos($category, $cat) !== false);
                });
                if (!empty($by_cat)) {
                    $abilities = $by_cat;
                } else {
                    // 3. Substring match against ability ID and label as last resort
                    $abilities = array_filter($abilities, function($ability, $id) use ($category, $get_id, $get_label) {
                        $ability_id = $get_id($id, $ability);
                        $label = $get_label($id, $ability);
                        return stripos((string) $ability_id, $category) !== false || stripos($label, $category) !== false;
                    }, ARRAY_FILTER_USE_BOTH);
                }
            }
        }

        $result = [];
        foreach ($abilities as $id => $ability) {
            if (is_object($ability)) {
                $result[] = [
                    'id'          => method_exists($ability, 'get_name')        ? $ability->get_name()        : ($ability->name ?? $id),
                    'name'        => method_exists($ability, 'get_label')       ? $ability->get_label()       : ($ability->label ?? $ability->name ?? $id),
                    'description' => method_exists($ability, 'get_description') ? $ability->get_description() : ($ability->description ?? ''),
                    'category'    => method_exists($ability, 'get_category')    ? $ability->get_category()    : ($ability->category ?? 'uncategorized'),
                ];
            } else {
                $result[] = [
                    'id'          => $id,
                    'name'        => $ability['label'] ?? $ability['name'] ?? $id,
                    'description' => $ability['description'] ?? '',
                    'category'    => $ability['category'] ?? 'uncategorized',
                ];
            }
        }

        return [
            'abilities' => $result,
            'count' => count($result),
            'filter' => $category ?: null,
        ];
    }

    private function get_ability(string $ability_id): array {
        if (!function_exists('wp_get_ability')) {
            return [
                'error' => 'Abilities API not available',
                'message' => 'WordPress 6.9+ with the Abilities API is required',
            ];
        }

        $ability = wp_get_ability($ability_id);

        if ($ability === null) {
            throw new \Exception("Ability not found: $ability_id");
        }

        if (is_object($ability) && method_exists($ability, 'get_input_schema')) {
            $meta = $ability->get_meta() ?? [];
            return [
                'id' => $ability->get_name(),
                'name' => $ability->get_label(),
                'description' => $ability->get_description(),
                'category' => $ability->get_category(),
                'input_schema' => $ability->get_input_schema(),
                'output_schema' => $ability->get_output_schema(),
                'instructions' => $meta['annotations']['instructions'] ?? '',
            ];
        }

        return [
            'id' => $ability_id,
            'name' => $ability['name'] ?? $ability_id,
            'description' => $ability['description'] ?? '',
            'category' => $ability['category'] ?? 'uncategorized',
            'input_schema' => $ability['input_schema'] ?? [],
            'output_schema' => $ability['output_schema'] ?? [],
            'instructions' => $ability['meta']['annotations']['instructions'] ?? '',
        ];
    }

    private function execute_ability(string $ability_id, array $arguments = []): array {
        if (!function_exists('wp_get_ability')) {
            return [
                'error' => 'Abilities API not available',
                'message' => 'WordPress 6.9+ with the Abilities API is required',
            ];
        }

        $ability = wp_get_ability($ability_id);
        if ($ability === null) {
            throw new \Exception("Ability not found: $ability_id");
        }

        $input = ($ability->get_input_schema() !== null && !empty($arguments)) ? $arguments : null;
        $result = $ability->execute($input);

        if (is_wp_error($result)) {
            throw new \Exception("Ability execution failed: " . $result->get_error_message());
        }

        $response = [
            'ability' => $ability_id,
            'success' => true,
            'result'  => $result,
        ];

        /**
         * Filter the instructions injected into the AI context after an ability executes.
         *
         * Use this to tell the AI how to present or act on the result it just received —
         * for example, which fields to render as links, how to format numbers, or what
         * follow-up actions to suggest.
         *
         * Example:
         * ```php
         * add_filter( 'ai_assistant_ability_instructions', function ( $instructions, $ability_id, $args, $result ) {
         *     if ( 'my-plugin/get-invoice' === $ability_id && ! empty( $result ) ) {
         *         $instructions = 'Present the invoice total in bold. Link the invoice number using the url field.';
         *     }
         *     return $instructions;
         * }, 10, 4 );
         * ```
         *
         * @param string $instructions Instructions to inject (empty string by default).
         * @param string $ability_id   The ID of the ability that was just executed, e.g. `my-plugin/get-invoice`.
         * @param array  $arguments    The arguments passed to the ability by the AI.
         * @param mixed  $result       The value returned by the ability's execute_callback.
         * @return string Instructions string, or empty string for no instructions.
         */
        $instructions = apply_filters('ai_assistant_ability_instructions', '', $ability_id, $arguments, $result);
        if ($instructions) {
            $response['_instructions'] = $instructions;
        }

        return $response;
    }

    // ===== SKILLS OPERATIONS =====

    private function get_skills_directory(): string {
        return plugin_dir_path(__DIR__) . 'skills/';
    }

    private function parse_frontmatter(string $content): array {
        $frontmatter = [];
        $body = $content;

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            $yaml_content = $matches[1];
            $body = $matches[2];

            foreach (explode("\n", $yaml_content) as $line) {
                if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                    $frontmatter[$kv[1]] = trim($kv[2], '"\'');
                }
            }
        }

        return [
            'frontmatter' => $frontmatter,
            'body' => $body,
        ];
    }

    private function list_skills(string $category = ''): array {
        $skills_dir = $this->get_skills_directory();

        if (!is_dir($skills_dir)) {
            return [
                'skills' => [],
                'count' => 0,
                'message' => 'No skills directory found',
            ];
        }

        $files = glob($skills_dir . '*.md');
        $skills = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $parsed = $this->parse_frontmatter($content);
            $fm = $parsed['frontmatter'];

            $skill_id = basename($file, '.md');
            $skill_category = $fm['category'] ?? 'general';

            if (!empty($category) && $skill_category !== $category) {
                continue;
            }

            $skills[] = [
                'id' => $skill_id,
                'title' => $fm['title'] ?? $skill_id,
                'description' => $fm['description'] ?? '',
                'category' => $skill_category,
            ];
        }

        return [
            'skills' => $skills,
            'count' => count($skills),
            'filter' => $category ?: null,
        ];
    }

    private function get_skill(string $skill_id): array {
        $skills_dir = $this->get_skills_directory();
        $skill_file = $skills_dir . $skill_id . '.md';

        if (!file_exists($skill_file)) {
            throw new \Exception("Skill not found: $skill_id. Use list_skills to see available skills.");
        }

        $content = file_get_contents($skill_file);
        if ($content === false) {
            throw new \Exception("Failed to read skill: $skill_id");
        }

        $parsed = $this->parse_frontmatter($content);

        return [
            'id' => $skill_id,
            'title' => $parsed['frontmatter']['title'] ?? $skill_id,
            'description' => $parsed['frontmatter']['description'] ?? '',
            'category' => $parsed['frontmatter']['category'] ?? 'general',
            'content' => trim($parsed['body']),
        ];
    }
}
