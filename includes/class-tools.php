<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Tool Definitions
 */
class Tools {

    /**
     * Get all available tools
     */
    public function get_all_tools(): array {
        $tools = array_merge(
            $this->get_file_tools(),
            $this->get_database_tools(),
            $this->get_wordpress_tools(),
            $this->get_abilities_tools(),
            $this->get_skill_tools()
        );

        /**
         * Filters tool definitions exposed by the PHP registry.
         *
         * Extension modules can add tools here while keeping their execution and
         * client-side schemas in separate files.
         *
         * @param array<int,array<string,mixed>> $tools Tool definitions.
         * @param Tools                         $this  Tool registry instance.
         */
        return $this->dedupe_tool_definitions(apply_filters('ai_assistant_tool_definitions', $tools, $this));
    }

    /**
     * Get read-only tools
     */
    public function get_read_only_tools(): array {
        $tools = [
            $this->tool_read_file(),
            $this->tool_list_directory(),
            $this->tool_search_files(),
            $this->tool_search_content(),
            $this->tool_db_query(),
            $this->tool_get_plugins(),
            $this->tool_get_themes(),
            $this->tool_list_abilities(),
            $this->tool_get_ability(),
            $this->tool_pick_image(),
            $this->tool_list_skills(),
            $this->tool_get_skill(),
        ];

        /**
         * Filters read-only tool definitions.
         *
         * @param array<int,array<string,mixed>> $tools Read-only tool definitions.
         * @param Tools                         $this  Tool registry instance.
         */
        return $this->dedupe_tool_definitions(apply_filters('ai_assistant_read_only_tool_definitions', $tools, $this));
    }

    /**
     * Get file operation tools
     */
    private function get_file_tools(): array {
        return [
            $this->tool_read_file(),
            $this->tool_list_directory(),
            $this->tool_search_files(),
            $this->tool_search_content(),
        ];
    }

    /**
     * Get database tools
     */
    private function get_database_tools(): array {
        return [
            $this->tool_db_query(),
        ];
    }

    /**
     * Get WordPress-specific tools
     */
    private function get_wordpress_tools(): array {
        return [
            $this->tool_get_plugins(),
            $this->tool_get_themes(),
            $this->tool_navigate(),
            $this->tool_get_page_html(),
            $this->tool_pick_image(),
        ];
    }

    private function dedupe_tool_definitions($tools): array {
        if (!is_array($tools)) {
            return [];
        }

        $deduped = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || empty($tool['name']) || !is_string($tool['name'])) {
                continue;
            }

            $deduped[$tool['name']] = $tool;
        }

        return array_values($deduped);
    }

    // ===== FILE TOOLS =====

    private function tool_read_file(): array {
        return [
            'name' => 'read_file',
            'description' => 'Read the contents of a file within wp-content directory. Large files may be returned in chunks.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins/my-plugin/file.php")',
                    ],
                    'offset' => [
                        'type' => 'number',
                        'description' => 'Byte offset to start reading from. Omit for the beginning.',
                    ],
                    'max_length' => [
                        'type' => 'number',
                        'description' => 'Maximum bytes to return. Use smaller chunks for large files.',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    private function tool_list_directory(): array {
        return [
            'name' => 'list_directory',
            'description' => 'List files and directories within a directory in wp-content',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Relative path from wp-content (e.g., "plugins" or "themes/theme-name")',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    private function tool_search_files(): array {
        return [
            'name' => 'search_files',
            'description' => 'Search for files matching a glob pattern within wp-content',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'pattern' => [
                        'type' => 'string',
                        'description' => 'Glob pattern (e.g., "plugins/*/*.php" or "themes/**/*.css")',
                    ],
                ],
                'required' => ['pattern'],
            ],
        ];
    }

    private function tool_search_content(): array {
        return [
            'name' => 'search_content',
            'description' => 'Search for text content within files in wp-content',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'needle' => [
                        'type' => 'string',
                        'description' => 'The text to search for',
                    ],
                    'directory' => [
                        'type' => 'string',
                        'description' => 'Directory to search in (relative to wp-content), default is entire wp-content',
                    ],
                    'file_pattern' => [
                        'type' => 'string',
                        'description' => 'File extension filter (e.g., "*.php")',
                    ],
                ],
                'required' => ['needle'],
            ],
        ];
    }

    // ===== DATABASE TOOLS =====

    private function tool_db_query(): array {
        return [
            'name' => 'db_query',
            'description' => 'Execute a read-only query on the WordPress database. SELECT, DESCRIBE, and SHOW queries are allowed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'sql' => [
                        'type' => 'string',
                        'description' => 'The SQL query to execute (SELECT, DESCRIBE, or SHOW). Use {prefix} as placeholder for table prefix.',
                    ],
                ],
                'required' => ['sql'],
            ],
        ];
    }

    // ===== WORDPRESS TOOLS =====

    private function tool_get_plugins(): array {
        return [
            'name' => 'get_plugins',
            'description' => 'List all installed WordPress plugins with title, description, version, author, and status',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
        ];
    }

    private function tool_get_themes(): array {
        return [
            'name' => 'get_themes',
            'description' => 'List all installed WordPress themes',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
        ];
    }

    private function tool_navigate(): array {
        return [
            'name' => 'navigate',
            'description' => 'Suggest that the user open a URL within the WordPress site. Use this to offer a clickable link to specific admin pages, posts, or frontend pages after the requested work is finished. The URL must be within the current WordPress site. This does not open the page automatically.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The URL to navigate to. Can be a full URL (must start with the site\'s home URL) or a relative path (e.g., "/wp-admin/edit.php" or "/sample-page/").',
                    ],
                    'link_text' => [
                        'type' => 'string',
                        'description' => 'Short clickable link text to display to the user (e.g., "Open the draft page" or "View the new plugin").',
                    ],
                ],
                'required' => ['url', 'link_text'],
            ],
        ];
    }

    private function tool_get_page_html(): array {
        return [
            'name' => 'get_page_html',
            'description' => 'Get the HTML content of elements on the current page the user is viewing. Use this to understand what the user is seeing, inspect page structure, or help debug frontend issues. Returns the outer HTML of matched elements.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'selector' => [
                        'type' => 'string',
                        'description' => 'CSS selector to query (e.g., "#main-content", ".entry-title", "article", "body"). Use "body" to get the full page content.',
                    ],
                    'max_length' => [
                        'type' => 'number',
                        'description' => 'Maximum characters to return per element (default: 5000). Use a smaller value for large pages.',
                    ],
                ],
                'required' => ['selector'],
            ],
        ];
    }

    private function tool_pick_image(): array {
        return [
            'name' => 'pick_image',
            'description' => 'Ask the user to choose or upload an image and return the selected image details. Use one image per call. Do not call this tool multiple times in the same response; wait for the selection before requesting another image.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Initial search.',
                    ],
                    'purpose' => [
                        'type' => 'string',
                        'description' => 'Image use.',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    // ===== ABILITIES API TOOLS =====

    private function get_abilities_tools(): array {
        return [
            $this->tool_list_abilities(),
            $this->tool_get_ability(),
            $this->tool_execute_ability(),
        ];
    }

    private function tool_list_abilities(): array {
        return [
            'name' => 'list_abilities',
            'description' => 'List all available WordPress abilities (from plugins, themes, and core). Returns ability names and brief descriptions. Use get_ability to fetch full details for a specific ability before executing.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional category to filter abilities (e.g., "content", "media", "users")',
                    ],
                ],
            ],
        ];
    }

    private function tool_get_ability(): array {
        return [
            'name' => 'get_ability',
            'description' => 'Get full details of a specific WordPress ability including its parameters schema, permissions, and usage information. Call this before execute_ability to understand what arguments are needed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'ability' => [
                        'type' => 'string',
                        'description' => 'The ability identifier (e.g., "core/create-post", "woocommerce/add-to-cart")',
                    ],
                ],
                'required' => ['ability'],
            ],
        ];
    }

    private function tool_execute_ability(): array {
        return [
            'name' => 'execute_ability',
            'description' => 'Execute a WordPress ability with the given arguments. Use get_ability first to understand required parameters.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'ability' => [
                        'type' => 'string',
                        'description' => 'The ability identifier to execute',
                    ],
                    'arguments' => [
                        'type' => 'object',
                        'description' => 'Arguments to pass to the ability (schema varies by ability)',
                    ],
                ],
                'required' => ['ability'],
            ],
        ];
    }

    // ===== SKILL TOOLS =====

    private function get_skill_tools(): array {
        return [
            $this->tool_list_skills(),
            $this->tool_get_skill(),
        ];
    }

    private function tool_list_skills(): array {
        return [
            'name' => 'list_skills',
            'description' => 'List available skills (specialized knowledge documents). Skills provide guidance on WordPress development patterns, best practices, and how-to information. Use get_skill to load the full content of a skill.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional category to filter skills (e.g., "blocks", "api", "theme")',
                    ],
                ],
            ],
        ];
    }

    private function tool_get_skill(): array {
        return [
            'name' => 'get_skill',
            'description' => 'Load a skill document containing specialized knowledge. Use list_skills first to see available skills. Skills provide detailed guidance, code examples, and best practices for specific WordPress development topics.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'skill' => [
                        'type' => 'string',
                        'description' => 'The skill identifier (e.g., "blocks-no-build", "custom-post-types")',
                    ],
                ],
                'required' => ['skill'],
            ],
        ];
    }
}
