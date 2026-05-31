<?php

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
class AI_Assistant_Dev_Tools {

    public static function init(): void {
        add_filter('ai_assistant_tool_definitions', [self::class, 'register_tool_definitions'], 10, 2);
        add_filter('ai_assistant_tool_meta', [self::class, 'register_tool_meta']);
        add_filter('ai_assistant_client_tool_definitions', [self::class, 'register_client_tool_definitions']);
        add_filter('ai_assistant_file_endpoint_tools', [self::class, 'register_file_endpoint_tools']);
        add_filter('ai_assistant_execute_tool', [self::class, 'execute_tool'], 10, 6);
        add_filter('ai_assistant_system_prompt', [self::class, 'register_system_prompt'], 10, 4);
    }

    public static function register_tool_definitions(array $tools): array {
        return array_merge($tools, [
            self::tool_write_file(),
            self::tool_edit_file(),
            self::tool_delete_file(),
            self::tool_install_plugin(),
            self::tool_run_php(),
        ]);
    }

    public static function register_tool_meta(array $tools): array {
        return array_merge($tools, [
            'write_file'     => ['label' => 'Write File',      'group' => 'File Writing',   'dangerous' => true],
            'edit_file'      => ['label' => 'Edit File',       'group' => 'File Writing',   'dangerous' => true],
            'delete_file'    => ['label' => 'Delete File',     'group' => 'File Writing',   'dangerous' => true],
            'install_plugin' => ['label' => 'Install Plugin',  'group' => 'WordPress',      'dangerous' => true],
            'run_php'        => ['label' => 'Run PHP',         'group' => 'Code Execution', 'dangerous' => true],
        ]);
    }

    public static function register_client_tool_definitions(array $tools): array {
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

    public static function register_file_endpoint_tools(array $tools): array {
        return array_values(array_unique(array_merge($tools, [
            'write_file',
            'edit_file',
            'delete_file',
        ])));
    }

    public static function register_system_prompt(string $prompt, array $enabled_tools, array $wp_info = [], ?\AI_Assistant\Settings $settings = null): string {
        $has_run_php = in_array('run_php', $enabled_tools, true);
        $has_write_file = in_array('write_file', $enabled_tools, true);
        $has_edit_file = in_array('edit_file', $enabled_tools, true);
        $has_delete_file = in_array('delete_file', $enabled_tools, true);
        $file_mutation_tools = [
            'write_file'  => 'Write File',
            'edit_file'   => 'Edit File',
            'delete_file' => 'Delete File',
        ];
        $enabled_file_mutation_tools = array_values(array_filter(
            array_keys($file_mutation_tools),
            fn($tool_name) => in_array($tool_name, $enabled_tools, true)
        ));
        $disabled_file_mutation_tools = array_values(array_diff(array_keys($file_mutation_tools), $enabled_file_mutation_tools));

        if ($has_run_php) {
            $prompt .= "\nFor plugin-specific data or actions, check abilities first (ability action:list) before reaching for run_php.\n";
            $prompt .= "For native WordPress data or actions with no matching ability or REST route, use run_php with standard WordPress functions.\n";
            $prompt .= "POST/PAGE DRAFT FALLBACK: use run_php/wp_insert_post only if REST cannot create the requested draft.\n";
        }

        if (empty($enabled_file_mutation_tools)) {
            $prompt .= <<<'PROMPT'


FILE EDITING TOOLS ARE DISABLED:
- You cannot create, modify, overwrite, or delete files in wp-content.
- Do not call write_file, edit_file, delete_file, or invent file-writing tools or abilities.
- The ability tool can only execute exact ability IDs returned by ability:list/get; do not use guessed abilities to write files.
- If the user asks for plugin or theme file changes, explain that file editing tools are disabled. Tell them a site admin can enable Write File and/or Edit File in AI Assistant > Settings > Tool Permissions. Delete File is only needed for file removal.
PROMPT;
        } else {
            $prompt .= "\n\nFILE TOOL AVAILABILITY:\n";
            $prompt .= '- Enabled file mutation tools: ' . implode(', ', $enabled_file_mutation_tools) . "\n";
            if (!empty($disabled_file_mutation_tools)) {
                $disabled_labels = array_map(
                    fn($tool_name) => $file_mutation_tools[$tool_name] . ' (' . $tool_name . ')',
                    $disabled_file_mutation_tools
                );
                $prompt .= '- Disabled file mutation tools: ' . implode(', ', $disabled_labels) . ". Do not call disabled file tools or invent equivalent abilities. If one is needed, tell the user a site admin can enable it in AI Assistant > Settings > Tool Permissions.\n";
            }

            $prompt .= "\nFILE EDITING RULES:\n";
            if ($has_write_file) {
                $prompt .= "- Use write_file ONLY for creating NEW files.\n";
            } else {
                $prompt .= "- Creating new files is unavailable because write_file is disabled. Tell the user a site admin can enable Write File if new files are needed.\n";
            }

            if ($has_edit_file) {
                $prompt .= "- Use edit_file for modifying EXISTING files - it uses search/replace operations which is more efficient and easier to review.\n";
                $prompt .= "- The edit_file tool takes a real JSON array of {search, replace} objects, not a string containing JSON, markdown, XML, or tool-call tags.\n";
                $prompt .= "- Before edit_file, use read_file for the current file/range unless exact current content is already in this turn; older tool output may be pruned.\n";
                $prompt .= "- If read_file is truncated, continue with offset=next_offset until you have the needed range.\n";
                $prompt .= "- Each edit_file search string must be exact and unique in the current file.\n";
                $prompt .= "- If an edit_file operation fails (string not found or not unique), use read_file to see the current content and retry.\n";
            } else {
                $prompt .= "- Modifying existing files is unavailable because edit_file is disabled. Do not use write_file to overwrite existing files; tell the user a site admin can enable Edit File.\n";
            }

            if ($has_delete_file) {
                $prompt .= "- Use delete_file only when the user explicitly asks to remove a file.\n";
            } else {
                $prompt .= "- Deleting files is unavailable because delete_file is disabled. Tell the user a site admin can enable Delete File if removal is needed.\n";
            }

            $prompt .= <<<'PROMPT'


EMERGENCY PLUGIN DISABLING:
- AI Assistant may emergency-disable a plugin after plugin edits or activation break WordPress. This inserts a reversible guard at the top of the plugin main file: AI_ASSISTANT_EMERGENCY_DISABLED.
- When a user asks why a plugin was disabled or to get it working again, inspect the plugin main file and relevant changed files with read_file/find before editing.
- Fix the plugin code before removing an emergency guard. Removing the guard first can immediately fatal WordPress again.
- After a fix, verify the plugin is active and WordPress still loads. If environment_info without inactive plugins does not list the plugin, call environment_info with include_inactive true or get_plugins before claiming it is working.
- If WordPress-backed tools fail after plugin file edits, continue with enabled direct file tools and explain that the plugin may have been emergency-disabled for recovery.
PROMPT;
        }

        return $prompt;
    }

    public static function execute_tool($result, string $tool_name, array $arguments, string $permission, ?int $conversation_id, \AI_Assistant\Executor $executor) {
        if ($result !== null) {
            return $result;
        }

        switch ($tool_name) {
            case 'install_plugin':
                $slug = self::get_string_arg($arguments, 'slug', $tool_name);
                $activate = isset($arguments['activate']) ? (bool) $arguments['activate'] : false;
                return self::install_plugin($slug, $activate);
            case 'run_php':
                return self::run_php(self::get_string_arg($arguments, 'code', $tool_name));
        }

        return null;
    }

    private static function tool_write_file(): array {
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

    private static function tool_edit_file(): array {
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

    private static function tool_delete_file(): array {
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

    private static function tool_install_plugin(): array {
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

    private static function tool_run_php(): array {
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

    private static function get_string_arg(array $args, string $name, string $tool, ?string $default = null): string {
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

    private static function install_plugin(string $slug, bool $activate = false): array {
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

    private static function run_php(string $code): array {
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

AI_Assistant_Dev_Tools::init();
