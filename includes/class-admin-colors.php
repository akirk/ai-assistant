<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin color token helpers for plugin UI accents.
 */
class Admin_Colors {

    public static function get_current_scheme_css(string $selector): string {
        $selector = trim($selector);

        if ($selector === '') {
            return '';
        }

        return sprintf(
            "%s {\n" .
            "    --ai-assistant-accent: var(--wp-app-admin-color-primary, var(--wp-admin-theme-color));\n" .
            "    --ai-assistant-on-accent: var(--wp-app-color-on-primary, #fff);\n" .
            "    --ai-assistant-accent-hover: var(--wp-app-admin-color-primary-hover, var(--wp-app-admin-color-primary, var(--wp-admin-theme-color-darker-10, var(--ai-assistant-accent))));\n" .
            "    --ai-assistant-accent-active: var(--wp-app-admin-color-primary-active, var(--wp-app-admin-color-primary, var(--wp-admin-theme-color-darker-20, var(--ai-assistant-accent-hover))));\n" .
            "}\n",
            $selector
        );
    }
}
