<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executes the read-only and WordPress data tools provided by the base plugin.
 *
 * File mutation, plugin installation, raw PHP, Git tracking, and WpApp
 * scaffolding live in the optional Patch Assistant companion plugin.
 */
class Executor {

    private $tools;

    public function __construct(Tools $tools, $git_tracker_manager = null) {
        $this->tools = $tools;
    }

    /**
     * Delegate filesystem work to an optional extension.
     */
    public function execute_file_tool(string $tool_name, array $arguments, ?int $conversation_id = null): array {
        $result = apply_filters('ai_assistant_execute_file_tool', null, $tool_name, $arguments, $conversation_id, $this);
        if ($result === null) {
            throw new \Exception('Filesystem tools require the Patch Assistant companion plugin.');
        }
        return $result;
    }

    public function execute_tool(string $tool_name, array $arguments, string $permission = 'full', ?int $conversation_id = null) {
        $read_only = [
            'db_query', 'get_plugins', 'get_themes', 'environment_info',
            'list_abilities', 'get_ability', 'list_skills', 'get_skill',
            'ability', 'skill',
        ];
        $read_only = apply_filters('ai_assistant_read_only_tool_names', $read_only, $tool_name, $arguments);

        if ($permission === 'chat_only') {
            throw new \Exception("Tool execution not allowed with chat-only permission");
        }

        if ($permission === 'read_only' && !in_array($tool_name, $read_only, true)) {
            throw new \Exception("Tool '$tool_name' requires full access permission");
        }

        if (!$this->has_tool_permission($tool_name, $arguments)) {
            throw new \Exception("Tool '$tool_name' is not enabled. Enable it in AI Assistant → Settings → Tool Permissions.");
        }

        $extension_result = apply_filters(
            'ai_assistant_execute_tool',
            null,
            $tool_name,
            $arguments,
            $permission,
            $conversation_id,
            $this
        );
        if ($extension_result !== null) {
            return $extension_result;
        }

        switch ($tool_name) {
            case 'read_file':
            case 'find':
            case 'list_directory':
            case 'search_files':
            case 'search_content':
            case 'write_file':
            case 'edit_file':
            case 'delete_file':
                return $this->execute_file_tool($tool_name, $arguments, $conversation_id);
            case 'db_query':
                return $this->db_query((string) ($arguments['sql'] ?? ''));
            case 'get_plugins':
                return $this->get_plugins();
            case 'get_themes':
                return $this->get_themes();
            case 'environment_info':
                return $this->get_environment_info(!empty($arguments['include_inactive']));
            case 'ability':
                return $this->execute_ability_tool($arguments);
            case 'list_abilities':
                return $this->list_abilities((string) ($arguments['category'] ?? ''));
            case 'get_ability':
                return $this->get_ability((string) ($arguments['ability'] ?? ''));
            case 'execute_ability':
                return $this->execute_ability((string) ($arguments['ability'] ?? ''), $arguments['arguments'] ?? []);
            case 'skill':
                return $this->execute_skill_tool($arguments);
            case 'list_skills':
                return Skill_Registry::get_available_skills((string) ($arguments['category'] ?? ''));
            case 'get_skill':
                return Skill_Registry::get_skill((string) ($arguments['skill'] ?? '')) ?: ['error' => 'Skill not found'];
            default:
                throw new \Exception("Unknown tool: $tool_name");
        }
    }

    private function has_tool_permission(string $tool_name, array $arguments): bool {
        if ($tool_name === 'ability') {
            $tool_name = ['list' => 'list_abilities', 'get' => 'get_ability', 'execute' => 'execute_ability'][$arguments['action'] ?? 'list'] ?? 'list_abilities';
        } elseif ($tool_name === 'skill') {
            $tool_name = ($arguments['action'] ?? 'list') === 'get' ? 'get_skill' : 'list_skills';
        }
        return current_user_can('ai_assistant_tool_' . $tool_name);
    }

    private function db_query(string $sql): array {
        global $wpdb;
        $sql = trim($sql);
        $first = strtoupper(strtok($sql, " \t\n\r"));
        if (!in_array($first, ['SELECT', 'DESCRIBE', 'DESC', 'SHOW'], true)) {
            throw new \Exception('Only read-only database queries are allowed.');
        }
        $sql = str_replace('{prefix}', $wpdb->prefix, $sql);
        $results = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) {
            throw new \Exception('Database error: ' . $wpdb->last_error);
        }
        return ['query' => $sql, 'rows' => $results, 'count' => count($results)];
    }

    private function get_plugins(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $plugins[] = [
                'file' => $file,
                'name' => $data['Name'] ?? '',
                'version' => $data['Version'] ?? '',
                'description' => $data['Description'] ?? '',
                'author' => $data['Author'] ?? '',
                'active' => in_array($file, $active, true),
            ];
        }
        return ['plugins' => $plugins, 'total' => count($plugins), 'active_count' => count($active)];
    }

    private function get_themes(): array {
        $active = get_stylesheet();
        $themes = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $themes[] = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'description' => $theme->get('Description'),
                'author' => $theme->get('Author'),
                'active' => $slug === $active,
            ];
        }
        return ['themes' => $themes, 'total' => count($themes), 'active' => $active];
    }

    private function get_environment_info(bool $include_inactive): array {
        $plugin_data = function_exists('get_plugins') ? get_plugins() : [];
        $active_plugins = (array) get_option('active_plugins', []);
        $plugins = [];
        $inactive = [];
        foreach ($plugin_data as $file => $data) {
            $parts = explode('/', $file);
            $key = count($parts) > 1 ? $parts[0] : pathinfo($file, PATHINFO_FILENAME);
            $entry = [
                'file' => $file,
                'title' => $data['Name'] ?? '',
                'description' => $data['Description'] ?? '',
                'version' => $data['Version'] ?? '',
            ];
            if (in_array($file, $active_plugins, true)) {
                $plugins[$key] = $entry;
            } elseif ($include_inactive) {
                $inactive[$key] = $entry;
            }
        }

        $environment = [
            'wp' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'theme' => wp_get_theme()->get_template(),
            'plugins' => $plugins,
            'include_inactive' => $include_inactive,
        ];
        if ($include_inactive) {
            $environment['inactive'] = $inactive;
        }
        return $environment;
    }

    private function execute_ability_tool(array $arguments): array {
        $action = $arguments['action'] ?? 'list';
        if ($action === 'list') return $this->list_abilities((string) ($arguments['category'] ?? ''));
        if ($action === 'get') return $this->get_ability((string) ($arguments['ability'] ?? ''));
        if ($action === 'execute') return $this->execute_ability((string) ($arguments['ability'] ?? ''), $arguments['arguments'] ?? []);
        throw new \Exception('Unknown ability action: ' . $action);
    }

    private function list_abilities(string $category = ''): array {
        if (!function_exists('wp_get_abilities')) return ['abilities' => [], 'count' => 0];
        $result = [];
        foreach (wp_get_abilities() as $id => $ability) {
            $name = is_object($ability) && method_exists($ability, 'get_name') ? $ability->get_name() : (is_object($ability) ? ($ability->name ?? $id) : $id);
            $cat = is_object($ability) && method_exists($ability, 'get_category') ? $ability->get_category() : (is_object($ability) ? ($ability->category ?? '') : ($ability['category'] ?? ''));
            if ($category !== '' && stripos((string) $cat, $category) === false && stripos((string) $name, $category) === false) continue;
            $details = Ability_Annotations::get_details($id, $ability);
            $result[] = [
                'id' => $details['id'],
                'category' => $details['category'],
                'annotations' => $details['annotations'],
                'has_schema' => $details['has_schema'],
                'input_schema' => $details['input_schema'],
                'parameters' => $details['parameters'],
            ];
        }
        return ['abilities' => $result, 'count' => count($result), 'filter' => $category ?: null];
    }

    private function get_ability(string $id): array {
        if (!function_exists('wp_get_ability') || !$id) throw new \Exception('Ability not found: ' . $id);
        $ability = wp_get_ability($id);
        if ($ability === null) throw new \Exception('Ability not found: ' . $id);
        $details = Ability_Annotations::get_details($id, $ability);
        return [
            'id' => $details['id'],
            'name' => $details['label'],
            'description' => $details['description'],
            'category' => $details['category'],
            'annotations' => $details['annotations'],
            'has_schema' => $details['has_schema'],
            'input_schema' => $details['input_schema'],
            'parameters' => $details['parameters'],
            'raw_schema' => $details['raw_schema'],
        ];
    }

    private function execute_ability(string $id, $arguments): array {
        if (!function_exists('wp_get_ability') || !$id) throw new \Exception('Ability not found: ' . $id);
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $trimmed_arguments = trim($arguments);
            if (!is_array($decoded) && substr($trimmed_arguments, -2) === '}}') {
                $decoded = json_decode(rtrim($trimmed_arguments, '}') . '}', true);
            }
            if (!is_array($decoded)) {
                throw new \Exception("ability requires 'arguments' to be an object or valid JSON object");
            }
            $arguments = $decoded;
        }
        $ability = wp_get_ability($id);
        if ($ability === null || !method_exists($ability, 'execute')) throw new \Exception('Ability not executable: ' . $id);
        $result = $ability->execute($arguments);
        if (is_wp_error($result)) throw new \Exception($result->get_error_message());
        return ['ability' => $id, 'result' => $result];
    }

    private function execute_skill_tool(array $arguments): array {
        return ($arguments['action'] ?? 'list') === 'get'
            ? (Skill_Registry::get_skill((string) ($arguments['skill'] ?? '')) ?: ['error' => 'Skill not found'])
            : ['skills' => Skill_Registry::get_available_skills((string) ($arguments['category'] ?? ''))];
    }
}
