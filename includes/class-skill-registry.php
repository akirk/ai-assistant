<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Discovers skill documents and filters out skills whose runtime dependencies
 * are not available.
 */
class Skill_Registry {

    public static function get_skills_directory(): string {
        return dirname(__DIR__) . '/skills/';
    }

    public static function parse_frontmatter(string $content): array {
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
            'body'        => $body,
        ];
    }

    public static function is_available(array $frontmatter): bool {
        $required_class = trim((string) ($frontmatter['requires_class'] ?? ''));

        if ($required_class === '') {
            return true;
        }

        return class_exists(ltrim($required_class, '\\'));
    }

    public static function get_available_skills(string $category = ''): array {
        $skills_dir = self::get_skills_directory();

        if (!is_dir($skills_dir)) {
            return [];
        }

        $files = glob($skills_dir . '*.md');
        if (empty($files)) {
            return [];
        }

        $skills = [];

        foreach ($files as $file) {
            $document = self::read_skill_file($file);
            if ($document === null) {
                continue;
            }

            $skill_category = $document['category'];
            if (!empty($category) && $skill_category !== $category) {
                continue;
            }

            unset($document['content']);
            $skills[] = $document;
        }

        return $skills;
    }

    public static function get_available_skill_documents(): array {
        $skills_dir = self::get_skills_directory();

        if (!is_dir($skills_dir)) {
            return [];
        }

        $files = glob($skills_dir . '*.md');
        if (empty($files)) {
            return [];
        }

        $documents = [];
        foreach ($files as $file) {
            $document = self::read_skill_file($file);
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public static function get_skill(string $skill_id): ?array {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $skill_id)) {
            return null;
        }

        return self::read_skill_file(self::get_skills_directory() . $skill_id . '.md');
    }

    private static function read_skill_file(string $file): ?array {
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $parsed = self::parse_frontmatter($content);
        $frontmatter = $parsed['frontmatter'];

        if (!self::is_available($frontmatter)) {
            return null;
        }

        $skill_id = basename($file, '.md');

        return [
            'id'          => $skill_id,
            'title'       => $frontmatter['title'] ?? $skill_id,
            'description' => $frontmatter['description'] ?? '',
            'category'    => $frontmatter['category'] ?? 'general',
            'content'     => trim($parsed['body']),
        ];
    }
}
