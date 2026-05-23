<?php

use AI_Assistant\Admin_Colors;
use PHPUnit\Framework\TestCase;

final class AdminColorsTest extends TestCase {

    public function test_css_uses_runtime_admin_color_tokens_with_expected_fallbacks(): void {
        $css = Admin_Colors::get_current_scheme_css('.ai-test');

        $this->assertStringContainsString('--ai-assistant-accent: var(--wp-app-admin-color-primary, var(--wp-admin-theme-color));', $css);
        $this->assertStringContainsString('--ai-assistant-on-accent: var(--wp-app-color-on-primary, #fff);', $css);
        $this->assertStringContainsString('--ai-assistant-accent-hover: var(--wp-app-admin-color-primary-hover, var(--wp-app-admin-color-primary, var(--wp-admin-theme-color-darker-10, var(--ai-assistant-accent))));', $css);
        $this->assertStringContainsString('--ai-assistant-accent-active: var(--wp-app-admin-color-primary-active, var(--wp-app-admin-color-primary, var(--wp-admin-theme-color-darker-20, var(--ai-assistant-accent-hover))));', $css);
    }

    public function test_empty_selector_returns_empty_css(): void {
        $this->assertSame('', Admin_Colors::get_current_scheme_css('   '));
    }

    public function test_stylesheets_do_not_hardcode_colors_in_assistant_custom_properties(): void {
        foreach (['chat.css', 'changes.css'] as $file) {
            $css = file_get_contents(dirname(__DIR__) . '/assets/css/' . $file);
            $this->assertIsString($css);

            preg_match_all('/^\s*--ai-assistant-[\w-]+:\s*[^;]+;/m', $css, $matches);

            foreach ($matches[0] as $definition) {
                if (str_contains($definition, '--ai-assistant-on-accent:')) {
                    $this->assertStringContainsString('var(--wp-app-color-on-primary, #fff)', $definition, $file . ': ' . $definition);
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i', $definition, $file . ': ' . $definition);
            }
        }
    }
}
