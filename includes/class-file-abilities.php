<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exposes the wp-content file tools as WordPress Abilities so outside agents can
 * use them through an MCP server such as the MCP Adapter plugin.
 *
 * The abilities are only registered when the "Expose file tools to MCP clients"
 * setting is enabled. They are not meant for the in-browser assistant, which has
 * its own file tools; the built-in ability list hides this category.
 *
 * Every ability wraps File_Tool_Executor, so the wp-content sandbox, PHP lint
 * before writes, secret-file blocking and AI Changes tracking apply unchanged.
 * Permissions reuse the per-tool ai_assistant_tool_* capabilities, so the Tool
 * Permissions settings and the full/read-only roles govern MCP access too.
 */
class File_Abilities {

    public const OPTION = 'ai_assistant_mcp_file_abilities';
    public const CATEGORY = 'ai-assistant-files';

    private ?Git_Tracker_Manager $git_tracker_manager;
    private ?File_Tool_Executor $executor = null;
    private bool $category_registered = false;
    private bool $abilities_registered = false;
    private bool $category_deferred = false;
    private bool $abilities_deferred = false;

    public function __construct(?Git_Tracker_Manager $git_tracker_manager = null, ?File_Tool_Executor $executor = null) {
        $this->git_tracker_manager = $git_tracker_manager;
        $this->executor = $executor;

        if (!self::is_enabled()) {
            return;
        }

        $this->add_init_safe_action('wp_abilities_api_categories_init', 'register_category');
        $this->add_init_safe_action('wp_abilities_api_init', 'register_abilities');
    }

    /**
     * Enabled only when the option is on and an MCP server plugin can expose the
     * abilities; without one they would have no consumer.
     */
    public static function is_enabled(): bool {
        return self::has_mcp_server() && (string) get_option(self::OPTION, '') === '1';
    }

    public static function has_mcp_server(): bool {
        return class_exists('\\WP\\MCP\\Core\\McpAdapter');
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

        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('AI Assistant Files', 'ai-assistant'),
            'description' => __('Read, search, create, edit and delete files inside wp-content, with AI Changes tracking.', 'ai-assistant'),
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

        foreach ($this->get_definitions() as $name => $definition) {
            wp_register_ability($name, $definition);
        }

        $this->abilities_registered = true;
    }

    /**
     * Ability definitions keyed by ability name.
     */
    public function get_definitions(): array {
        $path = [
            'type'        => 'string',
            'description' => 'Path relative to wp-content, e.g. "plugins/my-plugin/my-plugin.php".',
        ];
        $reason = [
            'type'        => 'string',
            'description' => 'Short explanation of the change; recorded in the AI Changes log.',
        ];

        return [
            'ai/read-file' => $this->definition(
                __('Read File', 'ai-assistant'),
                'Read one file inside wp-content. Use search with before_lines/after_lines for a targeted snippet, or offset/max_length for byte chunks of large files.',
                [
                    'type'                 => 'object',
                    'properties'           => [
                        'path'         => $path,
                        'offset'       => ['type' => 'integer', 'description' => 'Byte offset to start reading from.'],
                        'max_length'   => ['type' => 'integer', 'description' => 'Maximum bytes to return (up to 262144).'],
                        'search'       => ['type' => 'string', 'description' => 'Exact text to locate before returning a line window.'],
                        'before_lines' => ['type' => 'integer', 'description' => 'Lines to include before the search match.'],
                        'after_lines'  => ['type' => 'integer', 'description' => 'Lines to include after the search match.'],
                        'occurrence'   => ['type' => 'integer', 'description' => '1-based match occurrence when search appears multiple times.'],
                    ],
                    'required'             => ['path'],
                    'additionalProperties' => false,
                ],
                'read_file',
                true
            ),
            'ai/find' => $this->definition(
                __('Find Files or Content', 'ai-assistant'),
                'List a directory, find files by glob, or search file contents inside wp-content. Provide text for a content search, glob for a filename search, or only path to list a directory.',
                [
                    'type'                 => 'object',
                    'properties'           => [
                        'path'         => ['type' => 'string', 'description' => 'Directory or file relative to wp-content. Defaults to wp-content.'],
                        'glob'         => ['type' => 'string', 'description' => 'Filename glob pattern, relative to path.'],
                        'text'         => ['type' => 'string', 'description' => 'Text to search for in file contents.'],
                        'file_pattern' => ['type' => 'string', 'description' => 'File filter for text search, default "*.php".'],
                        'mode'         => ['type' => 'string', 'enum' => ['snippets', 'paths'], 'description' => 'Text search output: matching lines or file paths only.'],
                        'max_results'  => ['type' => 'integer', 'description' => 'Result cap for text search.'],
                    ],
                    'additionalProperties' => false,
                ],
                'find',
                true
            ),
            'ai/write-file' => $this->definition(
                __('Write File', 'ai-assistant'),
                'Create a new file inside wp-content, creating parent directories as needed. PHP files are syntax-checked before they are written. Use ai/edit-file for existing files. New plugins must live in their own directory (plugins/my-plugin/my-plugin.php).',
                [
                    'type'                 => 'object',
                    'properties'           => [
                        'path'    => $path,
                        'content' => ['type' => 'string', 'description' => 'Full file content.'],
                        'reason'  => $reason,
                    ],
                    'required'             => ['path', 'content', 'reason'],
                    'additionalProperties' => false,
                ],
                'write_file',
                false
            ),
            'ai/edit-file' => $this->definition(
                __('Edit File', 'ai-assistant'),
                'Edit an existing file inside wp-content with search/replace operations. Each search string must match exactly once in the current file. PHP files are syntax-checked before the edit is saved.',
                [
                    'type'                 => 'object',
                    'properties'           => [
                        'path'   => $path,
                        'edits'  => [
                            'type'        => 'array',
                            'description' => 'Ordered list of edits.',
                            'minItems'    => 1,
                            'items'       => [
                                'type'                 => 'object',
                                'properties'           => [
                                    'search'  => ['type' => 'string', 'description' => 'Exact, unique text currently in the file.'],
                                    'replace' => ['type' => 'string', 'description' => 'Replacement text.'],
                                ],
                                'required'             => ['search', 'replace'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'reason' => $reason,
                    ],
                    'required'             => ['path', 'edits', 'reason'],
                    'additionalProperties' => false,
                ],
                'edit_file',
                false
            ),
            'ai/delete-file' => $this->definition(
                __('Delete File', 'ai-assistant'),
                'Delete a file or directory inside wp-content.',
                [
                    'type'                 => 'object',
                    'properties'           => [
                        'path'   => $path,
                        'reason' => $reason,
                    ],
                    'required'             => ['path', 'reason'],
                    'additionalProperties' => false,
                ],
                'delete_file',
                false
            ),
        ];
    }

    private function definition(string $label, string $description, array $input_schema, string $tool_name, bool $readonly): array {
        return [
            'label'               => $label,
            'description'         => $description,
            'category'            => self::CATEGORY,
            'input_schema'        => $input_schema,
            'execute_callback'    => function ($input) use ($tool_name) {
                return $this->execute($tool_name, $input);
            },
            'permission_callback' => function ($input) use ($tool_name) {
                return $this->can_execute($tool_name, $input);
            },
            'meta'                => [
                'annotations'  => [
                    'readonly'    => $readonly,
                    'destructive' => !$readonly,
                    'idempotent'  => $readonly,
                ],
                'show_in_rest' => true,
                'mcp'          => [
                    'public' => true,
                ],
            ],
        ];
    }

    /**
     * Map a tool to the ai_assistant_tool_* capability that guards it, mirroring
     * File_Tool_Auth::is_tool_enabled().
     */
    public function can_execute(string $tool_name, $input): bool {
        $input = is_array($input) ? $input : [];

        switch ($tool_name) {
            case 'find':
                if (isset($input['text']) && (string) $input['text'] !== '') {
                    $tool_name = 'search_content';
                } elseif (isset($input['glob']) && (string) $input['glob'] !== '') {
                    $tool_name = 'search_files';
                } else {
                    $tool_name = 'list_directory';
                }
                break;
        }

        return current_user_can('ai_assistant_tool_' . $tool_name);
    }

    /**
     * @return array|\WP_Error
     */
    public function execute(string $tool_name, $input) {
        $input = is_array($input) ? $input : [];

        try {
            return $this->get_executor()->execute($tool_name, $input);
        } catch (\Throwable $e) {
            return new \WP_Error('ai_assistant_file_tool_failed', $e->getMessage());
        }
    }

    private function get_executor(): File_Tool_Executor {
        if ($this->executor === null) {
            $this->executor = new File_Tool_Executor(WP_CONTENT_DIR, $this->git_tracker_manager);
        }

        return $this->executor;
    }
}
