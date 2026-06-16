<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a diagnostic report for third-party AI Assistant integrations.
 */
class Integration_Inspector {

    public function inspect(string $plugin_slug, array $options = []): array {
        $plugin_slug = sanitize_key($plugin_slug);
        $plugin = $this->find_plugin($plugin_slug);
        $domains = $this->get_ability_domains();
        $abilities = $this->get_plugin_abilities($plugin_slug);
        $tips = $this->get_welcome_tips($plugin_slug, $options['url_component'] ?? $plugin_slug);
        $export_formats = $this->get_export_formats();

        $warnings = $this->get_warnings($plugin_slug, $plugin, $domains, $abilities);

        return [
            'plugin' => [
                'slug' => $plugin_slug,
                'file' => $plugin['file'] ?? '',
                'name' => $plugin['name'] ?? '',
                'active' => (bool) ($plugin['active'] ?? false),
                'found' => !empty($plugin),
            ],
            'abilities' => $abilities,
            'ability_domains' => array_key_exists($plugin_slug, $domains) ? [
                $plugin_slug => $domains[$plugin_slug],
            ] : [],
            'system_prompt_section' => self::get_ability_routing_prompt(
                array_key_exists($plugin_slug, $domains) ? [$plugin_slug => $domains[$plugin_slug]] : []
            ),
            'welcome_tips' => $tips,
            'conversation_export_formats' => $export_formats,
            'warnings' => $warnings,
        ];
    }

    public static function get_ability_routing_prompt(array $ability_domains): string {
        if (empty($ability_domains)) {
            return '';
        }

        $prompt = "ABILITY ROUTING: Plugin abilities are higher-level WordPress actions exposed by plugins. When a user request matches a known ability topic, use the ability workflow first because it preserves the plugin's own validation, permissions, and domain logic. Generic tools like db_query and find are fallback tools only after ability discovery shows no suitable capability.\n\n";
        $prompt .= "The following topics are handled by plugin abilities. For these, ALWAYS use the ability tool - never db_query or find:\n";
        foreach ($ability_domains as $slug => $keywords) {
            $prompt .= "- {$slug}: {$keywords}\n";
        }
        $prompt .= "These slugs are ability categories/domains, not executable ability IDs. First call ability with action \"list\" and the matching category, then action \"get\" for the exact ability ID before executing.\n\n";

        return $prompt;
    }

    private function find_plugin(string $plugin_slug): array {
        if (!function_exists('get_plugins')) {
            return [];
        }

        if (!function_exists('is_plugin_active') && defined('ABSPATH')) {
            $plugin_php = ABSPATH . 'wp-admin/includes/plugin.php';
            if (file_exists($plugin_php)) {
                require_once $plugin_php;
            }
        }

        foreach ((array) get_plugins() as $file => $data) {
            $file_slug = $this->plugin_file_to_slug((string) $file);
            if ($plugin_slug !== $file_slug && $plugin_slug !== sanitize_key((string) $file)) {
                continue;
            }

            return [
                'file' => (string) $file,
                'name' => (string) ($data['Name'] ?? $file),
                'active' => $this->is_plugin_active((string) $file),
            ];
        }

        return [];
    }

    private function plugin_file_to_slug(string $file): string {
        if (strpos($file, '/') !== false) {
            return sanitize_key(strtok($file, '/'));
        }

        return sanitize_key(preg_replace('/\.php$/', '', basename($file)));
    }

    private function is_plugin_active(string $file): bool {
        if (function_exists('is_plugin_active')) {
            return (bool) is_plugin_active($file);
        }

        return in_array($file, (array) get_option('active_plugins', []), true);
    }

    private function get_ability_domains(): array {
        $domains = apply_filters('ai_assistant_ability_domains', []);
        if (!is_array($domains)) {
            return [];
        }

        $normalized = [];
        foreach ($domains as $slug => $keywords) {
            $slug = sanitize_key($slug);
            if ($slug === '') {
                continue;
            }

            $normalized[$slug] = is_scalar($keywords) ? trim((string) $keywords) : '';
        }

        return $normalized;
    }

    private function get_plugin_abilities(string $plugin_slug): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $abilities = [];
        foreach ((array) wp_get_abilities() as $id => $ability) {
            $details = Ability_Annotations::get_details($id, $ability);
            if (!$this->ability_matches_plugin($plugin_slug, $details)) {
                continue;
            }

            $abilities[] = [
                'id' => $details['id'],
                'label' => $details['label'],
                'description' => $details['description'],
                'category' => $details['category'],
                'readonly' => (bool) $details['readonly'],
                'destructive' => (bool) $details['destructive'],
                'has_input_schema' => (bool) $details['has_schema'],
                'has_output_schema' => $this->has_output_schema($ability),
                'has_instructions' => trim((string) $details['instructions']) !== '',
            ];
        }

        usort($abilities, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        return $abilities;
    }

    private function ability_matches_plugin(string $plugin_slug, array $details): bool {
        $id = (string) ($details['id'] ?? '');
        $category = sanitize_key((string) ($details['category'] ?? ''));

        return strpos($id, $plugin_slug . '/') === 0 || $category === $plugin_slug;
    }

    private function has_output_schema($ability): bool {
        if (is_object($ability)) {
            if (method_exists($ability, 'get_output_schema')) {
                try {
                    return is_array($ability->get_output_schema());
                } catch (\Throwable $e) {
                    return false;
                }
            }

            return isset($ability->output_schema) && is_array($ability->output_schema);
        }

        return is_array($ability) && isset($ability['output_schema']) && is_array($ability['output_schema']);
    }

    private function get_welcome_tips(string $plugin_slug, string $url_component): array {
        $context = [
            'url' => home_url('/' . trim($url_component, '/') . '/'),
            'path' => '/' . trim($url_component, '/') . '/',
            'url_component' => sanitize_key($url_component),
        ];

        $tips_by_component = apply_filters('ai_assistant_welcome_tips', [], $context);
        if (!is_array($tips_by_component)) {
            return [];
        }

        $tips = [];
        foreach ($tips_by_component as $component => $component_tips) {
            if (sanitize_key((string) $component) !== $plugin_slug && sanitize_key((string) $component) !== $context['url_component']) {
                continue;
            }

            $component_tips = is_array($component_tips) ? $component_tips : [$component_tips];
            foreach ($component_tips as $tip) {
                if (!is_scalar($tip)) {
                    continue;
                }

                $tip = trim(preg_replace('/\s+/', ' ', (string) $tip));
                if ($tip !== '') {
                    $tips[] = $tip;
                }
            }
        }

        return array_values(array_unique($tips));
    }

    private function get_export_formats(): array {
        $formats = apply_filters('ai_assistant_conversation_export_formats', [], null);
        if (!is_array($formats)) {
            return [];
        }

        $normalized = [];
        foreach ($formats as $slug => $format) {
            if (!is_array($format)) {
                continue;
            }

            $normalized[] = [
                'format' => sanitize_key((string) $slug),
                'label' => (string) ($format['label'] ?? $slug),
                'extension' => (string) ($format['extension'] ?? $slug),
                'mime' => (string) ($format['mime'] ?? 'text/plain'),
            ];
        }

        return $normalized;
    }

    private function get_warnings(string $plugin_slug, array $plugin, array $domains, array $abilities): array {
        $warnings = [];

        if (empty($plugin)) {
            $warnings[] = "Plugin '{$plugin_slug}' was not found in the installed plugin list.";
        } elseif (empty($plugin['active'])) {
            $warnings[] = "Plugin '{$plugin_slug}' is installed but not active.";
        }

        if (empty($abilities)) {
            $warnings[] = "No abilities found for namespace or category '{$plugin_slug}'.";
        }

        if (!array_key_exists($plugin_slug, $domains)) {
            $warnings[] = "No ai_assistant_ability_domains entry registered for '{$plugin_slug}'.";
        }

        foreach ($abilities as $ability) {
            if (empty($ability['description'])) {
                $warnings[] = "{$ability['id']} has no description.";
            }
            if (empty($ability['has_input_schema'])) {
                $warnings[] = "{$ability['id']} has no input schema.";
            }
            if (empty($ability['has_output_schema'])) {
                $warnings[] = "{$ability['id']} has no output schema.";
            }
            if (empty($ability['has_instructions'])) {
                $warnings[] = "{$ability['id']} has no annotation instructions.";
            }
        }

        return $warnings;
    }
}
