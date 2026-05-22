<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers high-risk development tools through extension hooks.
 *
 * This module intentionally behaves like an extractable companion plugin: core
 * exposes hooks, and this file contributes tool definitions, UI metadata,
 * client schemas, file endpoint access, and execution handlers.
 */
class Dev_Tools {

    public function __construct() {
        add_filter('ai_assistant_tool_definitions', [$this, 'register_tool_definitions'], 10, 2);
        add_filter('ai_assistant_tool_meta', [$this, 'register_tool_meta']);
        add_filter('ai_assistant_client_tool_definitions', [$this, 'register_client_tool_definitions']);
        add_filter('ai_assistant_file_endpoint_tools', [$this, 'register_file_endpoint_tools']);
        add_filter('ai_assistant_execute_tool', [$this, 'execute_tool'], 10, 6);
    }

    public function register_tool_definitions(array $tools): array {
        return array_merge($tools, [
            $this->tool_write_file(),
            $this->tool_edit_file(),
            $this->tool_delete_file(),
            $this->tool_install_plugin(),
            $this->tool_run_php(),
        ]);
    }

    public function register_tool_meta(array $tools): array {
        return array_merge($tools, [
            'write_file'     => ['label' => 'Write File',      'group' => 'File Writing',   'dangerous' => true],
            'edit_file'      => ['label' => 'Edit File',       'group' => 'File Writing',   'dangerous' => true],
            'delete_file'    => ['label' => 'Delete File',     'group' => 'File Writing',   'dangerous' => true],
            'install_plugin' => ['label' => 'Install Plugin',  'group' => 'WordPress',      'dangerous' => true],
            'run_php'        => ['label' => 'Run PHP',         'group' => 'Code Execution', 'dangerous' => true],
        ]);
    }

    public function register_client_tool_definitions(array $tools): array {
        return array_merge($tools, [
            [
                'name' => 'write_file',
                'description' => 'Create a new file in wp-content. Use edit_file for existing files.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Relative path from wp-content'],
                        'content' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['path', 'content', 'reason'],
                ],
            ],
            [
                'name' => 'edit_file',
                'description' => 'Edit a file via search/replace operations. The edits parameter must be an array of objects, not a JSON string or tagged text. Each search string must be exact and unique in the current file.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Relative path from wp-content'],
                        'edits' => [
                            'type' => 'array',
                            'description' => 'Real JSON array of edit objects. Do not pass a string, markdown, XML, or </invoke> tags.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'search' => ['type' => 'string', 'description' => 'Exact unique current-file text to replace'],
                                    'replace' => ['type' => 'string', 'description' => 'Replacement text'],
                                ],
                                'required' => ['search', 'replace'],
                            ],
                        ],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['path', 'edits', 'reason'],
                ],
            ],
            [
                'name' => 'delete_file',
                'description' => 'Delete a file in wp-content',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Relative path from wp-content'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['path', 'reason'],
                ],
            ],
            [
                'name' => 'run_php',
                'description' => 'Execute PHP in WordPress. Prefer rest_api for post/page drafts. No <?php tag. Return a value.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string'],
                    ],
                    'required' => ['code'],
                ],
            ],
            [
                'name' => 'install_plugin',
                'description' => 'Install a plugin from wordpress.org by slug.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string'],
                        'activate' => ['type' => 'boolean'],
                    ],
                    'required' => ['slug'],
                ],
            ],
        ]);
    }

    public function register_file_endpoint_tools(array $tools): array {
        return array_values(array_unique(array_merge($tools, [
            'write_file',
            'edit_file',
            'delete_file',
        ])));
    }

    public function execute_tool($result, string $tool_name, array $arguments, string $permission, ?int $conversation_id, Executor $executor) {
        if ($result !== null) {
            return $result;
        }

        switch ($tool_name) {
            case 'install_plugin':
                $slug = $this->get_string_arg($arguments, 'slug', $tool_name);
                $activate = isset($arguments['activate']) ? (bool) $arguments['activate'] : false;
                return $this->install_plugin($slug, $activate);
            case 'run_php':
                return $this->run_php($this->get_string_arg($arguments, 'code', $tool_name));
        }

        return null;
    }

    private function tool_write_file(): array {
        return [
            'name' => 'write_file',
            'description' => 'Write or overwrite a file within wp-content directory. Use this only for creating NEW files. For modifying existing files, use edit_file instead.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The content to write to the file',
                    ],
                ],
                'required' => ['path', 'content'],
            ],
        ];
    }

    private function tool_edit_file(): array {
        return [
            'name' => 'edit_file',
            'description' => 'Edit an existing file by applying search and replace operations. More efficient than write_file for making targeted changes. Each edit finds a unique string and replaces it.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")',
                    ],
                    'edits' => [
                        'type' => 'array',
                        'description' => 'Array of edit operations to apply in order',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'search' => [
                                    'type' => 'string',
                                    'description' => 'The exact string to find (must be unique in the file)',
                                ],
                                'replace' => [
                                    'type' => 'string',
                                    'description' => 'The string to replace it with',
                                ],
                            ],
                            'required' => ['search', 'replace'],
                        ],
                    ],
                ],
                'required' => ['path', 'edits'],
            ],
        ];
    }

    private function tool_delete_file(): array {
        return [
            'name' => 'delete_file',
            'description' => 'Delete a file within wp-content directory',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    private function tool_install_plugin(): array {
        return [
            'name' => 'install_plugin',
            'description' => 'Install a plugin from the WordPress.org plugin directory. The slug is typically the plugin URL path on wordpress.org (e.g., wordpress.org/plugins/contact-form-7 -> slug is "contact-form-7").',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'The plugin slug from wordpress.org (e.g., "akismet", "contact-form-7", "woocommerce")',
                    ],
                    'activate' => [
                        'type' => 'boolean',
                        'description' => 'Whether to activate the plugin after installation (default: false)',
                    ],
                ],
                'required' => ['slug'],
            ],
        ];
    }

    private function tool_run_php(): array {
        return [
            'name' => 'run_php',
            'description' => 'Execute PHP code in the WordPress environment. Prefer rest_api for post/page drafts. Use this to call WordPress functions like wp_insert_post(), wp_update_post(), get_option(), update_option(), WP_Query, etc. The code runs with full WordPress context available.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'code' => [
                        'type' => 'string',
                        'description' => 'PHP code to execute. Do not include <?php tags. The code should return a value that will be sent back as the result.',
                    ],
                ],
                'required' => ['code'],
            ],
        ];
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

    private function install_plugin(string $slug, bool $activate = false): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $installed_plugins = get_plugins();
        foreach ($installed_plugins as $plugin_file => $plugin_data) {
            if (strpos($plugin_file, $slug . '/') === 0 || $plugin_file === $slug . '.php') {
                $is_active = is_plugin_active($plugin_file);

                if ($activate && !$is_active) {
                    $result = activate_plugin($plugin_file);
                    if (is_wp_error($result)) {
                        throw new \Exception('Plugin already installed but WordPress sandboxed activation failed: ' . $result->get_error_message());
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

        $plugin_file = $upgrader->plugin_info();

        if ($activate && $plugin_file) {
            $activate_result = activate_plugin($plugin_file);
            if (is_wp_error($activate_result)) {
                throw new \Exception("Plugin '{$slug}' installed successfully but WordPress sandboxed activation failed: " . $activate_result->get_error_message());
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
}
