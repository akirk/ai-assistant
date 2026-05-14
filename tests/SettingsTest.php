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
        $GLOBALS['wp_test_is_playground'] = false;

        $this->settings = new Settings();
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

    public function test_system_prompt_advertises_my_wordpress_skill(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_full'] = true;

        $prompt = $this->settings->get_system_prompt();

        $this->assertStringContainsString('my-wordpress (context): Using My WordPress', $prompt);
        $this->assertStringContainsString('personal WordPress can be used for', $prompt);
        $this->assertStringContainsString('inspect installed plugins before recommending uses', $prompt);
        $this->assertStringContainsString('my.wordpress.net', $prompt);
    }
}
