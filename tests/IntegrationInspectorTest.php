<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Integration_Inspector;
use PHPUnit\Framework\TestCase;

class IntegrationInspectorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_abilities'] = [];
        $GLOBALS['wp_test_plugins'] = [];
        $GLOBALS['wp_test_site_url'] = 'http://example.test';
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_plugins'] = [];
        $GLOBALS['wp_test_site_url'] = 'http://localhost';
    }

    public function test_inspect_reports_plugin_prompt_tips_and_abilities(): void {
        $GLOBALS['wp_test_plugins']['memex/memex.php'] = [
            'Name' => 'Memex',
            'Description' => 'Personal knowledge base.',
            'Version' => '1.0.0',
        ];
        $GLOBALS['wp_test_options']['active_plugins'] = ['memex/memex.php'];

        add_filter('ai_assistant_ability_domains', function ($domains) {
            $domains['memex'] = 'notes, bookmarks, excerpts';
            return $domains;
        });

        add_filter('ai_assistant_welcome_tips', function ($tips) {
            $tips['memex'] = [
                'Summarize the selected note.',
                'Find related bookmarks.',
            ];
            return $tips;
        }, 10, 2);

        $GLOBALS['wp_test_abilities']['memex/list-notes'] = [
            'label' => 'List Notes',
            'description' => 'Lists saved notes.',
            'category' => 'memex',
            'input_schema' => [
                'type' => 'object',
                'properties' => [],
            ],
            'output_schema' => [
                'type' => 'object',
            ],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'instructions' => 'Show note titles with links.',
                ],
            ],
        ];

        $report = (new Integration_Inspector())->inspect('memex', [
            'url_component' => 'memex',
        ]);

        $this->assertTrue($report['plugin']['found']);
        $this->assertTrue($report['plugin']['active']);
        $this->assertSame('memex/memex.php', $report['plugin']['file']);
        $this->assertSame('notes, bookmarks, excerpts', $report['ability_domains']['memex']);
        $this->assertStringContainsString('- memex: notes, bookmarks, excerpts', $report['system_prompt_section']);
        $this->assertSame(['Summarize the selected note.', 'Find related bookmarks.'], $report['welcome_tips']);
        $this->assertCount(1, $report['abilities']);
        $this->assertSame('memex/list-notes', $report['abilities'][0]['id']);
        $this->assertTrue($report['abilities'][0]['readonly']);
        $this->assertTrue($report['abilities'][0]['has_input_schema']);
        $this->assertTrue($report['abilities'][0]['has_output_schema']);
        $this->assertTrue($report['abilities'][0]['has_instructions']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_inspect_does_not_default_welcome_tips_to_plugin_slug(): void {
        $GLOBALS['wp_test_plugins']['memex/memex.php'] = [
            'Name' => 'Memex',
            'Description' => 'Personal knowledge base.',
            'Version' => '1.0.0',
        ];

        add_filter('ai_assistant_welcome_tips', function ($tips) {
            $tips['memex'] = 'Summarize the selected note.';
            return $tips;
        }, 10, 2);

        $report = (new Integration_Inspector())->inspect('memex');

        $this->assertSame([], $report['welcome_tips']);
    }

    public function test_get_active_plugin_slugs_returns_active_plugins(): void {
        $GLOBALS['wp_test_plugins']['memex/memex.php'] = [
            'Name' => 'Memex',
            'Description' => 'Personal knowledge base.',
            'Version' => '1.0.0',
        ];
        $GLOBALS['wp_test_plugins']['cookbook/cookbook.php'] = [
            'Name' => 'Cookbook',
            'Description' => 'Recipe manager.',
            'Version' => '1.0.0',
        ];
        $GLOBALS['wp_test_options']['active_plugins'] = [
            'memex/memex.php',
            'ai-assistant/ai-assistant.php',
        ];

        $slugs = (new Integration_Inspector())->get_active_plugin_slugs();

        $this->assertSame(['ai-assistant', 'memex'], $slugs);
    }

    public function test_inspect_warns_about_missing_integration_pieces(): void {
        $GLOBALS['wp_test_plugins']['memex/memex.php'] = [
            'Name' => 'Memex',
            'Description' => 'Personal knowledge base.',
            'Version' => '1.0.0',
        ];

        $GLOBALS['wp_test_abilities']['memex/create-note'] = [
            'label' => 'Create Note',
            'description' => '',
            'category' => 'memex',
        ];

        $report = (new Integration_Inspector())->inspect('memex');

        $this->assertFalse($report['plugin']['active']);
        $this->assertSame([], $report['ability_domains']);
        $this->assertSame('', $report['system_prompt_section']);
        $this->assertContains("Plugin 'memex' is installed but not active.", $report['warnings']);
        $this->assertContains("No ai_assistant_ability_domains entry registered for 'memex'.", $report['warnings']);
        $this->assertContains('memex/create-note has no description.', $report['warnings']);
        $this->assertContains('memex/create-note has no input schema.', $report['warnings']);
        $this->assertContains('memex/create-note has no output schema.', $report['warnings']);
        $this->assertContains('memex/create-note has no annotation instructions.', $report['warnings']);
    }
}
