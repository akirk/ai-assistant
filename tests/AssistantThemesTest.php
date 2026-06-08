<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Assistant_Themes;
use PHPUnit\Framework\TestCase;

final class AssistantThemesTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_filters'] = [];
    }

    public function test_current_theme_defaults_to_admin_classic(): void {
        $themes = new Assistant_Themes();

        $this->assertSame('admin-classic', $themes->get_current_theme_id());
        $this->assertTrue($themes->theme_exists('admin-classic'));
    }

    public function test_unknown_current_theme_falls_back_to_admin_classic(): void {
        $GLOBALS['wp_test_options'][Assistant_Themes::OPTION] = 'missing-theme';

        $themes = new Assistant_Themes();

        $this->assertSame('admin-classic', $themes->get_current_theme_id());
    }

    public function test_theme_file_lookup_falls_back_to_admin_classic(): void {
        $this->add_test_filter('ai_assistant_themes', function(array $themes): array {
            $themes['floating-button'] = [
                'id' => 'floating-button',
                'label' => 'Floating Button',
                'description' => '',
                'fallback' => 'admin-classic',
                'placement' => 'floating',
                'directory' => AI_ASSISTANT_PLUGIN_DIR . 'themes/floating-button',
                'directory_url' => AI_ASSISTANT_PLUGIN_URL . 'themes/floating-button/',
                'styles' => ['style.css'],
                'scripts' => ['script.js'],
            ];

            return $themes;
        });

        $themes = new Assistant_Themes();

        $this->assertStringEndsWith('/themes/floating-button/style.css', $themes->locate_file('floating-button', 'style.css'));
        $this->assertStringEndsWith('/themes/admin-classic/script.js', $themes->locate_url('floating-button', 'script.js'));
    }

    private function add_test_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): void {
        $GLOBALS['wp_test_filters'][$tag][$priority][] = [
            'callback' => $callback,
            'accepted_args' => $accepted_args,
        ];
    }
}
