<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes WordPress Ability annotations across object and array shapes.
 */
class Ability_Annotations {

    public static function get($ability): array {
        $annotations = [];

        if (is_object($ability)) {
            $meta = [];
            if (method_exists($ability, 'get_meta')) {
                $meta = $ability->get_meta() ?? [];
            } elseif (isset($ability->meta) && is_array($ability->meta)) {
                $meta = $ability->meta;
            }
            if (!is_array($meta)) {
                $meta = [];
            }

            $annotations = $meta['annotations'] ?? [];
            if (empty($annotations) && isset($ability->annotations) && is_array($ability->annotations)) {
                $annotations = $ability->annotations;
            }
        } elseif (is_array($ability)) {
            $annotations = $ability['meta']['annotations'] ?? $ability['annotations'] ?? [];
        }
        if (!is_array($annotations)) {
            $annotations = [];
        }

        return [
            'readonly'     => self::to_bool($annotations['readonly'] ?? false),
            'destructive'  => self::to_bool($annotations['destructive'] ?? false),
            'instructions' => (string) ($annotations['instructions'] ?? ''),
        ];
    }

    public static function get_for_id(string $ability_id): array {
        if (!function_exists('wp_get_ability')) {
            return self::defaults();
        }

        $ability = wp_get_ability($ability_id);
        if ($ability === null) {
            return self::defaults();
        }

        return self::get($ability);
    }

    public static function is_readonly_execution(string $ability_id): bool {
        $annotations = self::get_for_id($ability_id);
        return $annotations['readonly'] && !$annotations['destructive'];
    }

    public static function get_readonly_ability_ids(): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $ids = [];
        foreach (wp_get_abilities() as $id => $ability) {
            $annotations = self::get($ability);
            if ($annotations['readonly'] && !$annotations['destructive']) {
                $ids[] = self::get_ability_id($id, $ability);
            }
        }

        return array_values(array_unique($ids));
    }

    public static function get_destructive_ability_ids(): array {
        return self::get_ids_by_annotation('destructive');
    }

    private static function get_ids_by_annotation(string $annotation): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $ids = [];
        foreach (wp_get_abilities() as $id => $ability) {
            $annotations = self::get($ability);
            if (empty($annotations[$annotation])) {
                continue;
            }

            $ids[] = self::get_ability_id($id, $ability);
        }

        return array_values(array_unique($ids));
    }

    public static function get_ability_id($id, $ability): string {
        if (is_object($ability)) {
            return (string) (method_exists($ability, 'get_name') ? $ability->get_name() : ($ability->name ?? $id));
        }

        return (string) $id;
    }

    public static function get_details($id, $ability, bool $is_approved = false): array {
        $annotations = self::get($ability);
        $schema = self::get_input_schema($ability);
        $ability_id = self::get_ability_id($id, $ability);

        if (is_object($ability)) {
            $label = method_exists($ability, 'get_label') ? $ability->get_label() : ($ability->label ?? $ability_id);
            $description = method_exists($ability, 'get_description') ? $ability->get_description() : ($ability->description ?? '');
            $category = method_exists($ability, 'get_category') ? $ability->get_category() : ($ability->category ?? '');
        } else {
            $label = $ability['label'] ?? $ability['name'] ?? $ability_id;
            $description = $ability['description'] ?? '';
            $category = $ability['category'] ?? '';
        }

        return [
            'id'          => (string) $ability_id,
            'label'       => (string) $label,
            'description' => (string) $description,
            'category'    => (string) $category,
            'readonly'    => $annotations['readonly'] && !$annotations['destructive'],
            'destructive' => $annotations['destructive'],
            'approved'    => $is_approved,
            'has_schema'  => $schema !== null,
            'parameters'  => $schema !== null ? self::get_schema_parameters($schema) : [],
            'raw_schema'  => $schema !== null && !empty($schema) ? self::encode_json($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
        ];
    }

    public static function get_details_for_id(string $ability_id, bool $is_approved = false): ?array {
        if (!function_exists('wp_get_ability')) {
            return null;
        }

        $ability = wp_get_ability($ability_id);
        if ($ability === null) {
            return null;
        }

        return self::get_details($ability_id, $ability, $is_approved);
    }

    private static function defaults(): array {
        return [
            'readonly'     => false,
            'destructive'  => false,
            'instructions' => '',
        ];
    }

    private static function to_bool($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private static function get_input_schema($ability): ?array {
        $schema = null;
        $has_schema = false;

        if (is_object($ability)) {
            if (method_exists($ability, 'get_input_schema')) {
                try {
                    $schema = $ability->get_input_schema();
                    $has_schema = true;
                } catch (\Throwable $e) {
                    return null;
                }
            } elseif (isset($ability->input_schema)) {
                $schema = $ability->input_schema;
                $has_schema = true;
            } elseif (isset($ability->parameters)) {
                $schema = $ability->parameters;
                $has_schema = true;
            }
        } elseif (is_array($ability)) {
            if (array_key_exists('input_schema', $ability)) {
                $schema = $ability['input_schema'];
                $has_schema = true;
            } elseif (array_key_exists('parameters', $ability)) {
                $schema = $ability['parameters'];
                $has_schema = true;
            }
        }

        if (!$has_schema) {
            return null;
        }

        return is_array($schema) ? $schema : [];
    }

    private static function get_schema_parameters(array $schema): array {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return [];
        }

        $required = [];
        foreach ((array) ($schema['required'] ?? []) as $required_name) {
            if (is_scalar($required_name)) {
                $required[(string) $required_name] = true;
            }
        }

        $parameters = [];
        foreach ($properties as $name => $property_schema) {
            $property_schema = is_array($property_schema) ? $property_schema : [];
            $parameters[] = [
                'name'        => (string) $name,
                'type'        => self::get_schema_type_label($property_schema),
                'description' => (string) ($property_schema['description'] ?? ''),
                'required'    => isset($required[(string) $name]),
                'notes'       => self::get_schema_notes($property_schema),
            ];
        }

        return $parameters;
    }

    private static function get_schema_type_label(array $schema): string {
        $type = $schema['type'] ?? '';
        if (is_array($type)) {
            $type = implode('|', array_map('strval', $type));
        }

        if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            return 'array<' . self::get_schema_type_label($schema['items']) . '>';
        }

        if (!$type && isset($schema['enum'])) {
            return 'enum';
        }

        return $type ? (string) $type : 'any';
    }

    private static function get_schema_notes(array $schema): array {
        $notes = [];

        if (!empty($schema['enum']) && is_array($schema['enum'])) {
            $notes[] = sprintf(
                __('Allowed: %s', 'ai-assistant'),
                implode(', ', array_map(function($value) {
                    return self::format_schema_value($value);
                }, $schema['enum']))
            );
        }

        if (array_key_exists('default', $schema)) {
            $notes[] = sprintf(
                __('Default: %s', 'ai-assistant'),
                self::format_schema_value($schema['default'])
            );
        }

        foreach (['format', 'pattern', 'minimum', 'maximum', 'minLength', 'maxLength'] as $key) {
            if (isset($schema[$key]) && is_scalar($schema[$key])) {
                $notes[] = sprintf('%s: %s', $key, (string) $schema[$key]);
            }
        }

        if (!empty($schema['properties']) && is_array($schema['properties'])) {
            $notes[] = sprintf(
                __('Fields: %s', 'ai-assistant'),
                implode(', ', array_map('strval', array_keys($schema['properties'])))
            );
        }

        return $notes;
    }

    private static function format_schema_value($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return self::encode_json($value);
    }

    private static function encode_json($value, int $flags = 0): string {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value, $flags) : json_encode($value, $flags);
        return is_string($json) ? $json : '';
    }
}
