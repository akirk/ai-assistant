<?php

use AI_Assistant\Admin_Colors;
use PHPUnit\Framework\TestCase;

final class AdminColorsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['wp_test_user_options'] = [];
        $GLOBALS['_wp_admin_css_colors'] = [];
    }

    public function test_custom_scheme_uses_wordpress_admin_theme_variables_with_current_scheme_fallbacks(): void {
        $GLOBALS['wp_test_user_options']['admin_color'] = 'white-accent';
        $GLOBALS['_wp_admin_css_colors']['white-accent'] = (object) [
            'colors' => ['#f6f7f7', '#ffffff'],
        ];

        $css = Admin_Colors::get_current_scheme_css('.ai-test');

        $this->assertStringContainsString('--ai-assistant-accent: var(--wp-admin-theme-color, #ffffff);', $css);
        $this->assertStringContainsString('--ai-assistant-accent-rgb: var(--wp-admin-theme-color--rgb, 255, 255, 255);', $css);
        $this->assertStringContainsString('--ai-assistant-accent-hover: var(--wp-admin-theme-color-darker-10, #e6e6e6);', $css);
        $this->assertStringNotContainsString('accent-contrast', $css);
    }

    public function test_frontend_shell_css_uses_current_scheme_values_directly(): void {
        $GLOBALS['wp_test_user_options']['admin_color'] = 'modern';

        $css = Admin_Colors::get_current_scheme_css('.ai-test', false);

        $this->assertStringContainsString('--ai-assistant-accent: #3858e9;', $css);
        $this->assertStringContainsString('--ai-assistant-accent-rgb: 56, 88, 233;', $css);
        $this->assertStringContainsString('--ai-assistant-accent-hover: #2145e6;', $css);
        $this->assertStringContainsString('--ai-assistant-accent-active: #183ad6;', $css);
        $this->assertStringNotContainsString('--wp-admin-theme-color', $css);
    }

    public function test_default_scheme_uses_wordpress_admin_theme_variables_with_fallbacks(): void {
        $GLOBALS['wp_test_user_options']['admin_color'] = 'fresh';

        $css = Admin_Colors::get_current_scheme_css('.ai-test');

        $this->assertStringContainsString('--ai-assistant-accent: var(--wp-admin-theme-color, #2271b1);', $css);
        $this->assertStringContainsString('--ai-assistant-accent-hover: var(--wp-admin-theme-color-darker-10, #135e96);', $css);
        $this->assertStringContainsString('--ai-assistant-accent-active: var(--wp-admin-theme-color-darker-20, #0a4b78);', $css);
    }
}
