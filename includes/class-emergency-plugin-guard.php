<?php
namespace AI_Assistant;

if (!defined('ABSPATH') && !defined('AI_ASSISTANT_FILE_TOOLS_ENDPOINT')) {
    exit;
}

/**
 * Reversible guard inserted into plugin main files during emergency recovery.
 */
class Emergency_Plugin_Guard {

    public const MARKER = 'AI_ASSISTANT_EMERGENCY_DISABLED';
    public const PREFIX = "<?php /* AI_ASSISTANT_EMERGENCY_DISABLED */ return; __halt_compiler(); ?>\n";

    public static function is_guarded_content(string $content): bool {
        return strpos($content, self::PREFIX) === 0;
    }

    public static function add_guard_to_content(string $content): string {
        if (self::is_guarded_content($content)) {
            return $content;
        }

        return self::PREFIX . $content;
    }

    public static function remove_guard_from_content(string $content): string {
        if (!self::is_guarded_content($content)) {
            return $content;
        }

        return substr($content, strlen(self::PREFIX));
    }

    public static function is_guarded_file(string $path): bool {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $prefix = fread($handle, strlen(self::PREFIX));
        fclose($handle);

        return $prefix === self::PREFIX;
    }

    public static function add_guard_to_file(string $path): bool {
        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        if (self::is_guarded_content($content)) {
            return true;
        }

        return file_put_contents($path, self::add_guard_to_content($content), LOCK_EX) !== false;
    }

    public static function remove_guard_from_file(string $path): bool {
        $content = file_get_contents($path);
        if ($content === false || !self::is_guarded_content($content)) {
            return false;
        }

        return file_put_contents($path, self::remove_guard_from_content($content), LOCK_EX) !== false;
    }
}
