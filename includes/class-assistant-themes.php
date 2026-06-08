<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Internal presentation theme registry and file resolver.
 */
class Assistant_Themes {
    public const OPTION = 'ai_assistant_theme';
    public const DEFAULT_THEME = 'admin-classic';

    /**
     * @var array<string,array<string,mixed>>|null
     */
    private ?array $themes = null;

    public function get_current_theme_id(): string {
        $theme_id = sanitize_key((string) get_option(self::OPTION, self::DEFAULT_THEME));

        if (!$this->theme_exists($theme_id)) {
            return self::DEFAULT_THEME;
        }

        return $theme_id;
    }

    public function get_current_theme(): array {
        return $this->get_theme($this->get_current_theme_id());
    }

    public function get_theme(string $theme_id): array {
        $themes = $this->get_themes();
        $theme_id = sanitize_key($theme_id);

        return $themes[$theme_id] ?? $themes[self::DEFAULT_THEME];
    }

    public function theme_exists(string $theme_id): bool {
        $theme_id = sanitize_key($theme_id);
        $themes = $this->get_themes();

        return isset($themes[$theme_id]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function get_themes(): array {
        if ($this->themes !== null) {
            return $this->themes;
        }

        $themes = [];
        $theme_files = glob(AI_ASSISTANT_PLUGIN_DIR . 'themes/*/theme.php') ?: [];

        foreach ($theme_files as $theme_file) {
            $theme = include $theme_file;
            if (!is_array($theme)) {
                continue;
            }

            $theme_id = sanitize_key((string) ($theme['id'] ?? basename(dirname($theme_file))));
            if ($theme_id === '') {
                continue;
            }

            $theme['id'] = $theme_id;
            $theme['directory'] = dirname($theme_file);
            $theme['directory_url'] = AI_ASSISTANT_PLUGIN_URL . 'themes/' . basename(dirname($theme_file)) . '/';
            $theme['label'] = (string) ($theme['label'] ?? $theme_id);
            $theme['description'] = (string) ($theme['description'] ?? '');
            $theme['fallback'] = sanitize_key((string) ($theme['fallback'] ?? ''));
            $theme['placement'] = sanitize_key((string) ($theme['placement'] ?? 'auto'));
            $theme['styles'] = $this->normalize_file_list($theme['styles'] ?? []);
            $theme['scripts'] = $this->normalize_file_list($theme['scripts'] ?? []);

            $themes[$theme_id] = $theme;
        }

        /**
         * Filters plugin-internal assistant presentation themes.
         *
         * Themes are resolved from directories inside this plugin. This filter is
         * for plugin-owned registration and tests, not active WordPress theme
         * template overrides.
         *
         * @param array<string,array<string,mixed>> $themes Registered themes.
         */
        $themes = apply_filters('ai_assistant_themes', $themes);
        $themes = is_array($themes) ? $themes : [];

        if (empty($themes[self::DEFAULT_THEME])) {
            $themes[self::DEFAULT_THEME] = [
                'id' => self::DEFAULT_THEME,
                'label' => __('Admin Classic', 'ai-assistant'),
                'description' => '',
                'fallback' => '',
                'placement' => 'auto',
                'directory' => AI_ASSISTANT_PLUGIN_DIR . 'themes/admin-classic',
                'directory_url' => AI_ASSISTANT_PLUGIN_URL . 'themes/admin-classic/',
                'styles' => ['style.css'],
                'scripts' => ['script.js'],
            ];
        }

        $this->themes = $themes;
        return $this->themes;
    }

    public function locate_file(string $theme_id, string $relative_path): string {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        if ($relative_path === '' || strpos($relative_path, '..') !== false) {
            return '';
        }

        foreach ($this->get_theme_chain($theme_id) as $theme) {
            $directory = rtrim((string) ($theme['directory'] ?? ''), '/\\');
            if ($directory === '') {
                continue;
            }

            $path = $directory . '/' . $relative_path;
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    public function locate_url(string $theme_id, string $relative_path): string {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        if ($relative_path === '' || strpos($relative_path, '..') !== false) {
            return '';
        }

        foreach ($this->get_theme_chain($theme_id) as $theme) {
            $file = rtrim((string) ($theme['directory'] ?? ''), '/\\') . '/' . $relative_path;
            if (is_file($file)) {
                return rtrim((string) ($theme['directory_url'] ?? ''), '/') . '/' . $relative_path;
            }
        }

        return '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_theme_chain(string $theme_id): array {
        $themes = $this->get_themes();
        $chain = [];
        $seen = [];
        $current = sanitize_key($theme_id);

        while ($current !== '' && isset($themes[$current]) && empty($seen[$current])) {
            $seen[$current] = true;
            $chain[] = $themes[$current];
            $current = sanitize_key((string) ($themes[$current]['fallback'] ?? ''));
        }

        if ($theme_id !== self::DEFAULT_THEME && isset($themes[self::DEFAULT_THEME]) && empty($seen[self::DEFAULT_THEME])) {
            $chain[] = $themes[self::DEFAULT_THEME];
        }

        return $chain;
    }

    /**
     * @param mixed $files
     * @return array<int,string>
     */
    private function normalize_file_list($files): array {
        $normalized = [];

        foreach ((array) $files as $file) {
            $file = ltrim(str_replace('\\', '/', (string) $file), '/');
            if ($file === '' || strpos($file, '..') !== false) {
                continue;
            }

            $normalized[] = $file;
        }

        return array_values(array_unique($normalized));
    }
}
