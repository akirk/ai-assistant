<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin color scheme helpers for plugin UI accents.
 */
class Admin_Colors {

    private const CORE_SCHEME_COLORS = [
        'fresh' => [
            'accent' => '#2271b1',
            'accent_rgb' => '34, 113, 177',
            'hover' => '#135e96',
            'active' => '#0a4b78',
        ],
        'light' => [
            'accent' => '#0085ba',
            'accent_rgb' => '0, 133, 186',
            'hover' => '#0073a1',
            'active' => '#006187',
        ],
        'modern' => [
            'accent' => '#3858e9',
            'accent_rgb' => '56, 88, 233',
            'hover' => '#2145e6',
            'active' => '#183ad6',
        ],
        'blue' => [
            'accent' => '#096484',
            'accent_rgb' => '9, 100, 132',
            'hover' => '#07526c',
            'active' => '#064054',
        ],
        'coffee' => [
            'accent' => '#46403c',
            'accent_rgb' => '70, 64, 60',
            'hover' => '#383330',
            'active' => '#2b2724',
        ],
        'ectoplasm' => [
            'accent' => '#523f6d',
            'accent_rgb' => '82, 63, 109',
            'hover' => '#46365d',
            'active' => '#3a2c4d',
        ],
        'midnight' => [
            'accent' => '#e14d43',
            'accent_rgb' => '225, 77, 67',
            'hover' => '#dd382d',
            'active' => '#d02c21',
        ],
        'ocean' => [
            'accent' => '#627c83',
            'accent_rgb' => '98, 124, 131',
            'hover' => '#576e74',
            'active' => '#4c6066',
        ],
        'sunrise' => [
            'accent' => '#dd823b',
            'accent_rgb' => '221, 130, 59',
            'hover' => '#d97426',
            'active' => '#c36922',
        ],
    ];

    public static function get_current_scheme_css(string $selector, bool $use_admin_css_variables = true): string {
        $colors = self::get_current_scheme_colors();
        $selector = trim($selector);

        if ($selector === '') {
            return '';
        }

        $accent = $use_admin_css_variables
            ? sprintf('var(--wp-admin-theme-color, %s)', $colors['accent'])
            : $colors['accent'];
        $accent_rgb = $use_admin_css_variables
            ? sprintf('var(--wp-admin-theme-color--rgb, %s)', $colors['accent_rgb'])
            : $colors['accent_rgb'];
        $hover = $use_admin_css_variables
            ? sprintf('var(--wp-admin-theme-color-darker-10, %s)', $colors['hover'])
            : $colors['hover'];
        $active = $use_admin_css_variables
            ? sprintf('var(--wp-admin-theme-color-darker-20, %s)', $colors['active'])
            : $colors['active'];

        return sprintf(
            "%s {\n" .
            "    --ai-assistant-accent: %s;\n" .
            "    --ai-assistant-accent-rgb: %s;\n" .
            "    --ai-assistant-accent-hover: %s;\n" .
            "    --ai-assistant-accent-active: %s;\n" .
            "}\n",
            $selector,
            $accent,
            $accent_rgb,
            $hover,
            $active
        );
    }

    private static function get_current_scheme_colors(): array {
        $scheme = sanitize_key((string) get_user_option('admin_color'));

        if ($scheme === '') {
            $scheme = 'fresh';
        }

        if (isset(self::CORE_SCHEME_COLORS[$scheme])) {
            return self::CORE_SCHEME_COLORS[$scheme];
        }

        return self::get_registered_scheme_colors($scheme);
    }

    private static function get_registered_scheme_colors(string $scheme): array {
        global $_wp_admin_css_colors;

        $fallback = self::CORE_SCHEME_COLORS['fresh'];

        if (
            empty($_wp_admin_css_colors[$scheme])
            || empty($_wp_admin_css_colors[$scheme]->colors)
            || !is_array($_wp_admin_css_colors[$scheme]->colors)
        ) {
            return $fallback;
        }

        $palette = array_values(array_filter($_wp_admin_css_colors[$scheme]->colors, 'is_string'));
        $accent = self::sanitize_hex_color($palette[1] ?? $palette[0] ?? '');

        if ($accent === '') {
            return $fallback;
        }

        return [
            'accent' => $accent,
            'accent_rgb' => self::hex_to_rgb_string($accent),
            'hover' => self::darken_hex($accent, 10),
            'active' => self::darken_hex($accent, 20),
        ];
    }

    private static function sanitize_hex_color(string $color): string {
        $color = trim($color);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtolower($color);
        }

        if (preg_match('/^#[0-9a-fA-F]{3}$/', $color)) {
            return strtolower(sprintf(
                '#%s%s%s%s%s%s',
                $color[1],
                $color[1],
                $color[2],
                $color[2],
                $color[3],
                $color[3]
            ));
        }

        return '';
    }

    private static function hex_to_rgb_string(string $color): string {
        $color = ltrim($color, '#');

        return sprintf(
            '%d, %d, %d',
            hexdec(substr($color, 0, 2)),
            hexdec(substr($color, 2, 2)),
            hexdec(substr($color, 4, 2))
        );
    }

    private static function darken_hex(string $color, int $amount): string {
        $color = ltrim($color, '#');
        $factor = max(0, min(100, 100 - $amount)) / 100;

        return sprintf(
            '#%02x%02x%02x',
            (int) round(hexdec(substr($color, 0, 2)) * $factor),
            (int) round(hexdec(substr($color, 2, 2)) * $factor),
            (int) round(hexdec(substr($color, 4, 2)) * $factor)
        );
    }
}
