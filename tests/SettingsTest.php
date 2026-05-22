<?php
namespace AI_Assistant\Tests;

use PHPUnit\Framework\TestCase;
use AI_Assistant\Settings;

/**
 * Unit tests for per-tool capability methods on the Settings class
 */
class SettingsTest extends TestCase {

    private Settings $settings;

    protected function setUp(): void {
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_capabilities'] = [];
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_is_playground'] = false;
        $GLOBALS['wp_test_abilities'] = [];
        $GLOBALS['wp_test_json_response'] = null;
        $GLOBALS['wp_test_site_url'] = 'http://localhost';
        $_POST = [];
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'], $_SERVER['REQUEST_URI']);

        new \AI_Assistant\Dev_Tools();
        $this->settings = new Settings();
    }

    protected function tearDown(): void {
        $_POST = [];
        $GLOBALS['wp_test_site_url'] = 'http://localhost';
    }

    // ===== get_default_enabled_tools =====

    public function test_default_enabled_tools_contains_safe_tools(): void {
        $defaults = $this->settings->get_default_enabled_tools();

        $expected_safe = [
            'read_file', 'list_directory', 'search_files', 'search_content',
            'db_query', 'environment_info', 'get_plugins', 'get_themes', 'list_abilities', 'get_ability',
            'get_page_html', 'pick_image', 'summarize_conversation', 'list_skills', 'get_skill', 'navigate',
        ];

        foreach ($expected_safe as $tool) {
            $this->assertContains($tool, $defaults, "Expected safe tool '$tool' in defaults");
        }
    }

    public function test_default_enabled_tools_excludes_dangerous_tools(): void {
        $defaults = $this->settings->get_default_enabled_tools();

        $dangerous = ['run_php', 'write_file', 'edit_file', 'delete_file', 'install_plugin'];

        foreach ($dangerous as $tool) {
            $this->assertNotContains($tool, $defaults, "Dangerous tool '$tool' should not be in defaults");
        }
    }

    // ===== get_all_tools_with_meta =====

    public function test_all_tools_with_meta_returns_array(): void {
        $meta = $this->settings->get_all_tools_with_meta();
        $this->assertIsArray($meta);
        $this->assertNotEmpty($meta);
    }

    public function test_all_tools_with_meta_has_required_keys(): void {
        foreach ($this->settings->get_all_tools_with_meta() as $name => $data) {
            $this->assertArrayHasKey('label', $data, "Tool '$name' missing 'label'");
            $this->assertArrayHasKey('group', $data, "Tool '$name' missing 'group'");
            $this->assertArrayHasKey('dangerous', $data, "Tool '$name' missing 'dangerous'");
        }
    }

    public function test_all_tools_marks_dangerous_tools_correctly(): void {
        $meta = $this->settings->get_all_tools_with_meta();

        foreach (['run_php', 'write_file', 'edit_file', 'delete_file', 'install_plugin', 'execute_ability'] as $tool) {
            $this->assertArrayHasKey($tool, $meta);
            $this->assertTrue($meta[$tool]['dangerous'], "Tool '$tool' should be marked dangerous");
        }
    }

    public function test_all_tools_marks_safe_tools_correctly(): void {
        $meta = $this->settings->get_all_tools_with_meta();

        foreach (['read_file', 'list_directory', 'search_files', 'db_query', 'get_plugins', 'navigate', 'pick_image'] as $tool) {
            $this->assertArrayHasKey($tool, $meta);
            $this->assertFalse($meta[$tool]['dangerous'], "Tool '$tool' should not be marked dangerous");
        }
    }

    public function test_all_tools_includes_all_expected_tools(): void {
        $keys = array_keys($this->settings->get_all_tools_with_meta());

        $expected = [
            'read_file', 'list_directory', 'search_files', 'search_content',
            'write_file', 'edit_file', 'delete_file',
            'db_query', 'environment_info', 'get_plugins', 'get_themes', 'install_plugin', 'run_php',
            'list_abilities', 'get_ability', 'execute_ability',
            'navigate', 'get_page_html',
            'pick_image', 'summarize_conversation', 'list_skills', 'get_skill',
        ];

        foreach ($expected as $tool) {
            $this->assertContains($tool, $keys, "Expected tool '$tool' in get_all_tools_with_meta()");
        }
    }

    public function test_all_tools_orders_extension_groups_with_related_core_groups(): void {
        $meta = $this->settings->get_all_tools_with_meta();
        $keys = array_keys($meta);
        $groups = array_values(array_unique(array_column($meta, 'group')));

        $this->assertLessThan(array_search('Database', $groups, true), array_search('File Writing', $groups, true));
        $this->assertLessThan(array_search('Database', $groups, true), array_search('Code Execution', $groups, true));
        $this->assertSame(
            ['read_file', 'list_directory', 'search_files', 'search_content', 'write_file', 'edit_file', 'delete_file'],
            array_values(array_intersect($keys, ['read_file', 'list_directory', 'search_files', 'search_content', 'write_file', 'edit_file', 'delete_file']))
        );
        $this->assertLessThan(array_search('rest_api', $keys, true), array_search('install_plugin', $keys, true));
    }

    // ===== map_tool_cap =====

    public function test_map_tool_cap_ignores_non_tool_caps(): void {
        $original = ['edit_posts'];
        $result = $this->settings->map_tool_cap($original, 'edit_posts', 1);
        $this->assertEquals($original, $result);
    }

    public function test_map_tool_cap_denies_user_without_ai_assistant_full(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = false;

        $result = $this->settings->map_tool_cap([], 'ai_assistant_tool_read_file', 1);
        $this->assertEquals(['do_not_allow'], $result);
    }

    public function test_map_tool_cap_allows_all_tools_in_playground(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_is_playground'] = true;

        // Even a dangerous/disabled tool resolves to exist in Playground
        $result = $this->settings->map_tool_cap([], 'ai_assistant_tool_run_php', 1);
        $this->assertEquals(['exist'], $result);
    }

    public function test_map_tool_cap_allows_enabled_tool_on_hosted(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_options']['ai_assistant_enabled_tools'] = ['read_file', 'write_file'];

        $result = $this->settings->map_tool_cap([], 'ai_assistant_tool_read_file', 1);
        $this->assertEquals(['exist'], $result);
    }

    public function test_map_tool_cap_denies_disabled_tool_on_hosted(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_options']['ai_assistant_enabled_tools'] = ['read_file'];

        $result = $this->settings->map_tool_cap([], 'ai_assistant_tool_run_php', 1);
        $this->assertEquals(['do_not_allow'], $result);
    }

    public function test_map_tool_cap_uses_defaults_when_option_not_set(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        // No ai_assistant_enabled_tools option set → falls back to defaults

        // read_file is in defaults → should be allowed
        $result = $this->settings->map_tool_cap([], 'ai_assistant_tool_read_file', 1);
        $this->assertEquals(['exist'], $result);

        // run_php is NOT in defaults → should be denied
        $result = $this->settings->map_tool_cap([], 'ai_assistant_tool_run_php', 1);
        $this->assertEquals(['do_not_allow'], $result);
    }

    public function test_map_tool_cap_playground_skips_enabled_check(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_is_playground'] = true;
        $GLOBALS['wp_test_options']['ai_assistant_enabled_tools'] = []; // nothing enabled

        // Should still allow everything in Playground regardless of enabled list
        $result = $this->settings->map_tool_cap([], 'ai_assistant_tool_write_file', 1);
        $this->assertEquals(['exist'], $result);
    }

    // ===== get_user_enabled_tools =====

    public function test_get_user_enabled_tools_returns_tools_user_can_access(): void {
        // current_user_can returns true by default → all tools enabled
        $tools = $this->settings->get_user_enabled_tools();
        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);
        $this->assertContains('read_file', $tools);
    }

    public function test_get_user_enabled_tools_excludes_denied_tools(): void {
        // Directly set per-tool caps — simulates what map_tool_cap produces via WP filter
        foreach (array_keys($this->settings->get_all_tools_with_meta()) as $tool) {
            $GLOBALS['wp_test_capabilities']['ai_assistant_tool_' . $tool] =
                in_array($tool, ['read_file', 'list_directory'], true);
        }

        $tools = $this->settings->get_user_enabled_tools();

        $this->assertContains('read_file', $tools);
        $this->assertContains('list_directory', $tools);
        $this->assertNotContains('run_php', $tools);
        $this->assertNotContains('write_file', $tools);
    }

    public function test_get_user_enabled_tools_returns_empty_when_no_full_cap(): void {
        // Deny every per-tool cap — simulates map_tool_cap returning do_not_allow for all
        foreach (array_keys($this->settings->get_all_tools_with_meta()) as $tool) {
            $GLOBALS['wp_test_capabilities']['ai_assistant_tool_' . $tool] = false;
        }

        $tools = $this->settings->get_user_enabled_tools();
        $this->assertEmpty($tools);
    }

    public function test_get_user_enabled_tools_returns_all_in_playground(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_is_playground'] = true;

        $tools = $this->settings->get_user_enabled_tools();
        $all = array_keys($this->settings->get_all_tools_with_meta());

        foreach ($all as $tool) {
            $this->assertContains($tool, $tools, "Expected '$tool' enabled in Playground");
        }
    }

    public function test_system_prompt_guides_post_draft_creation(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('POST/PAGE DRAFTS: create actual drafts via REST', $prompt);
        $this->assertStringContainsString('/wp/v2/posts', $prompt);
        $this->assertStringContainsString('status "draft"', $prompt);
        $this->assertStringContainsString('Never publish/overwrite or use db_query', $prompt);
        $this->assertStringContainsString('report title, ID, edit URL.', $prompt);
    }

    public function test_system_prompt_distinguishes_ability_domains_from_ids(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_filters']['ai_assistant_ability_domains'] = [
            10 => [
                [
                    'callback' => static function(array $domains): array {
                        $domains['create-wp-app'] = 'wp app, app plugin';
                        return $domains;
                    },
                    'accepted_args' => 1,
                ],
            ],
        ];

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('- create-wp-app: wp app, app plugin', $prompt);
        $this->assertStringContainsString('These slugs are ability categories/domains, not executable ability IDs.', $prompt);
        $this->assertStringContainsString('First call ability with action "list" and the matching category', $prompt);
        $this->assertStringContainsString('then action "get" for the exact ability ID before executing', $prompt);
    }

    public function test_system_prompt_routes_plugin_creation_to_skills(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('load skill "wp-app" before acting', $prompt);
        $this->assertStringContainsString('load skill "plugin-creation" before writing files', $prompt);
        $this->assertStringContainsString('Skill instructions override these brief routing notes.', $prompt);
        $this->assertStringNotContainsString('Manual fallback only: use the suffix "-mywp"', $prompt);
    }

    public function test_skills_prompt_uses_single_plugin_creation_skills_line(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('Available skills:', $prompt);
        $this->assertStringContainsString('- wp-app, plugin-creation (plugin creation): use "wp-app" for app-like WordPress plugins; use "plugin-creation"', $prompt);
        $this->assertStringNotContainsString('- plugin-creation (plugins):', $prompt);
        $this->assertStringNotContainsString('- wp-app (apps):', $prompt);
    }

    public function test_system_prompt_advertises_my_wordpress_skill(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('my-wordpress (context): Using My WordPress', $prompt);
        $this->assertStringContainsString('personal WordPress can be used for', $prompt);
        $this->assertStringContainsString('inspect installed plugins before recommending uses', $prompt);
        $this->assertStringContainsString('my.wordpress.net', $prompt);
    }

    public function test_system_prompt_includes_compact_playground_context(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_is_playground'] = true;
        $GLOBALS['wp_test_site_url'] = 'https://playground.wordpress.net/scope:default';

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('PLAYGROUND:', $prompt);
        $this->assertStringContainsString('Browser-based WordPress', $prompt);
        $this->assertStringContainsString('do not promise inbound/public reachability', $prompt);
        $this->assertStringContainsString('For "what can I do"', $prompt);
        $this->assertStringNotContainsString('my.wordpress.net is My WordPress', $prompt);
    }

    public function test_system_prompt_includes_my_wordpress_reachability_context(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;
        $GLOBALS['wp_test_is_playground'] = true;
        $GLOBALS['wp_test_site_url'] = 'https://my.wordpress.net/scope:default';

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('my.wordpress.net is My WordPress', $prompt);
        $this->assertStringContainsString('a personal software home', $prompt);
        $this->assertStringContainsString('reshapeable workflows', $prompt);
        $this->assertStringContainsString('Public/inbound features', $prompt);
        $this->assertStringContainsString('need hosted WordPress', $prompt);
        $this->assertStringContainsString('exporting backups and importing them at a host', $prompt);
        $this->assertStringContainsString('inspect plugins/abilities as useful', $prompt);
    }

    public function test_sample_readonly_ability_returns_output(): void {
        $_POST = [
            'ability' => 'demo/read',
        ];
        $GLOBALS['wp_test_capabilities']['manage_options'] = true;
        $GLOBALS['wp_test_abilities']['demo/read'] = $this->createSampleAbility(true, false, [
            'items' => [
                ['id' => 1, 'title' => 'First item'],
            ],
        ]);

        try {
            $this->settings->ajax_sample_readonly_ability();
            $this->fail('Expected wp_send_json_success to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_success', $e->getMessage());
        }

        $this->assertTrue($GLOBALS['wp_test_json_response']['success']);
        $data = $GLOBALS['wp_test_json_response']['data'];
        $this->assertSame('demo/read', $data['ability']);
        $this->assertSame([], $data['input']);
        $this->assertSame('First item', $data['result']['items'][0]['title']);
    }

    public function test_sample_readonly_ability_rejects_non_readonly_ability(): void {
        $_POST = [
            'ability' => 'demo/write',
        ];
        $GLOBALS['wp_test_capabilities']['manage_options'] = true;
        $GLOBALS['wp_test_abilities']['demo/write'] = $this->createSampleAbility(false, false, ['ok' => true]);

        try {
            $this->settings->ajax_sample_readonly_ability();
            $this->fail('Expected wp_send_json_error to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_error', $e->getMessage());
        }

        $this->assertFalse($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(403, $GLOBALS['wp_test_json_response']['status_code']);
        $this->assertSame('ability_not_readonly', $GLOBALS['wp_test_json_response']['data']['code']);
    }

    public function test_sample_readonly_ability_accepts_user_arguments(): void {
        $_POST = [
            'ability' => 'demo/read',
            'arguments' => '{"id":123}',
        ];
        $GLOBALS['wp_test_capabilities']['manage_options'] = true;
        $GLOBALS['wp_test_abilities']['demo/read'] = $this->createSampleAbility(true, false, ['ok' => true], [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ]);

        try {
            $this->settings->ajax_sample_readonly_ability();
            $this->fail('Expected wp_send_json_success to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_success', $e->getMessage());
        }

        $this->assertTrue($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(['id' => 123], $GLOBALS['wp_test_json_response']['data']['input']);
    }

    public function test_sample_readonly_ability_requires_user_arguments(): void {
        $_POST = [
            'ability' => 'demo/read',
            'arguments' => '{}',
        ];
        $GLOBALS['wp_test_capabilities']['manage_options'] = true;
        $GLOBALS['wp_test_abilities']['demo/read'] = $this->createSampleAbility(true, false, ['ok' => true], [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ]);

        try {
            $this->settings->ajax_sample_readonly_ability();
            $this->fail('Expected wp_send_json_error to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_error', $e->getMessage());
        }

        $this->assertFalse($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(400, $GLOBALS['wp_test_json_response']['status_code']);
        $this->assertSame('sample_arguments_required', $GLOBALS['wp_test_json_response']['data']['code']);
        $this->assertSame('Sample output requires arguments for: id', $GLOBALS['wp_test_json_response']['data']['message']);
    }

    public function test_sample_readonly_ability_rejects_non_object_arguments(): void {
        $_POST = [
            'ability' => 'demo/read',
            'arguments' => '[]',
        ];
        $GLOBALS['wp_test_capabilities']['manage_options'] = true;
        $GLOBALS['wp_test_abilities']['demo/read'] = $this->createSampleAbility(true, false, ['ok' => true]);

        try {
            $this->settings->ajax_sample_readonly_ability();
            $this->fail('Expected wp_send_json_error to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_error', $e->getMessage());
        }

        $this->assertFalse($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(400, $GLOBALS['wp_test_json_response']['status_code']);
        $this->assertSame('invalid_sample_arguments', $GLOBALS['wp_test_json_response']['data']['code']);
    }

    private function createSampleAbility(bool $readonly, bool $destructive, array $result, array $input_schema = ['type' => 'object']): object {
        return new class($readonly, $destructive, $result, $input_schema) {
            private bool $readonly;
            private bool $destructive;
            private array $result;
            private array $input_schema;

            public function __construct(bool $readonly, bool $destructive, array $result, array $input_schema) {
                $this->readonly = $readonly;
                $this->destructive = $destructive;
                $this->result = $result;
                $this->input_schema = $input_schema;
            }

            public function get_label(): string {
                return 'Demo Ability';
            }

            public function get_description(): string {
                return 'Returns demo data.';
            }

            public function get_category(): string {
                return 'demo';
            }

            public function get_input_schema(): array {
                return $this->input_schema;
            }

            public function get_meta(): array {
                return [
                    'annotations' => [
                        'readonly'    => $this->readonly,
                        'destructive' => $this->destructive,
                    ],
                ];
            }

            public function execute($input) {
                return $this->result;
            }
        };
    }
}
