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
}
