<?php
namespace AI_Assistant\Tests;

use AI_Assistant\File_Abilities;
use AI_Assistant\File_Tool_Executor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the MCP-facing file abilities.
 */
class FileAbilitiesTest extends TestCase {

    private string $plugin_dir;

    protected function setUp(): void {
        $this->plugin_dir = WP_PLUGIN_DIR . '/file-abilities-test';
        $this->removeDirectory($this->plugin_dir);
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_capabilities'] = [];
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->plugin_dir);
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_capabilities'] = [];
    }

    public function test_disabled_by_default(): void {
        $this->assertFalse(File_Abilities::is_enabled());
        $this->assertSame([], File_Abilities::get_exposed_tools());
    }

    public function test_enabled_requires_an_exposed_tool(): void {
        $GLOBALS['wp_test_options'][File_Abilities::OPTION] = ['edit_file', 'bogus'];

        $this->assertTrue(File_Abilities::is_enabled());
        $this->assertSame(['edit_file'], File_Abilities::get_exposed_tools());
        $this->assertTrue(File_Abilities::is_tool_exposed('edit_file'));
        $this->assertFalse(File_Abilities::is_tool_exposed('write_file'));
        $this->assertTrue(File_Abilities::exposes_write_tools());

        $GLOBALS['wp_test_options'][File_Abilities::OPTION] = ['read_file'];
        $this->assertFalse(File_Abilities::exposes_write_tools());

        $GLOBALS['wp_test_options'][File_Abilities::OPTION] = [];
        $this->assertFalse(File_Abilities::is_enabled());
    }

    public function test_ability_for_tool(): void {
        $this->assertSame('ai/read-file', File_Abilities::get_ability_for_tool('read_file'));
        $this->assertSame('ai/find', File_Abilities::get_ability_for_tool('search_files'));
        $this->assertSame('ai/delete-file', File_Abilities::get_ability_for_tool('delete_file'));
        $this->assertNull(File_Abilities::get_ability_for_tool('run_php'));
    }

    public function test_only_abilities_with_exposed_tools_are_registered(): void {
        $GLOBALS['wp_test_options'][File_Abilities::OPTION] = ['search_files', 'edit_file'];

        $exposed = array_keys((new File_Abilities())->get_exposed_definitions());
        $this->assertSame(['ai/find', 'ai/edit-file'], $exposed);
    }

    public function test_definitions_cover_file_tools_with_annotations(): void {
        $definitions = (new File_Abilities())->get_definitions();

        $this->assertSame(
            ['ai/read-file', 'ai/find', 'ai/write-file', 'ai/edit-file', 'ai/delete-file'],
            array_keys($definitions)
        );

        foreach ($definitions as $name => $definition) {
            $this->assertSame(File_Abilities::CATEGORY, $definition['category'], $name);
            $this->assertSame('object', $definition['input_schema']['type'], $name);
            $this->assertTrue($definition['meta']['show_in_rest'], $name);
            $this->assertTrue($definition['meta']['mcp']['public'], $name);
            $this->assertIsCallable($definition['execute_callback'], $name);
            $this->assertIsCallable($definition['permission_callback'], $name);
        }

        $this->assertTrue($definitions['ai/read-file']['meta']['annotations']['readonly']);
        $this->assertFalse($definitions['ai/read-file']['meta']['annotations']['destructive']);
        $this->assertFalse($definitions['ai/write-file']['meta']['annotations']['readonly']);
        $this->assertTrue($definitions['ai/write-file']['meta']['annotations']['destructive']);
    }

    public function test_permission_maps_to_tool_capabilities(): void {
        $GLOBALS['wp_test_options'][File_Abilities::OPTION] = File_Abilities::all_tools();
        $abilities = new File_Abilities();

        $GLOBALS['wp_test_capabilities'] = ['ai_assistant_tool_read_file' => false];
        $this->assertFalse($abilities->can_execute('read_file', []));

        $GLOBALS['wp_test_capabilities'] = ['ai_assistant_tool_read_file' => true, 'ai_assistant_tool_write_file' => false];
        $this->assertTrue($abilities->can_execute('read_file', []));
        $this->assertFalse($abilities->can_execute('write_file', []));

        $GLOBALS['wp_test_capabilities'] = [
            'ai_assistant_tool_search_content' => true,
            'ai_assistant_tool_search_files' => false,
            'ai_assistant_tool_list_directory' => false,
        ];
        $this->assertTrue($abilities->can_execute('find', ['text' => 'foo']));
        $this->assertFalse($abilities->can_execute('find', ['glob' => '*.php']));
        $this->assertFalse($abilities->can_execute('find', ['path' => 'plugins']));

        // Capability alone is not enough: the sub-tool must be switched on for MCP.
        $GLOBALS['wp_test_options'][File_Abilities::OPTION] = ['search_files'];
        $GLOBALS['wp_test_capabilities'] = [];
        $this->assertFalse($abilities->can_execute('find', ['text' => 'foo']));
        $this->assertTrue($abilities->can_execute('find', ['glob' => '*.php']));
        $this->assertFalse($abilities->can_execute('read_file', []));
    }

    public function test_write_edit_read_delete_round_trip(): void {
        $abilities = new File_Abilities(null, new File_Tool_Executor(WP_CONTENT_DIR, null));
        $path = 'plugins/file-abilities-test/file-abilities-test.php';

        $written = $abilities->execute('write_file', [
            'path' => $path,
            'content' => "<?php\n// Plugin Name: File Abilities Test\nfunction file_abilities_test() { return 1; }\n",
            'reason' => 'create',
        ]);
        $this->assertIsArray($written);
        $this->assertSame('created', $written['action']);
        $this->assertFileExists($this->plugin_dir . '/file-abilities-test.php');

        $edited = $abilities->execute('edit_file', [
            'path' => $path,
            'edits' => [['search' => 'return 1;', 'replace' => 'return 2;']],
            'reason' => 'edit',
        ]);
        $this->assertIsArray($edited);
        $this->assertSame(1, $edited['edits_applied']);
        $this->assertSame(0, $edited['edits_failed']);

        $read = $abilities->execute('read_file', ['path' => $path]);
        $this->assertIsArray($read);
        $this->assertStringContainsString('return 2;', $read['content']);

        $found = $abilities->execute('find', ['path' => 'plugins/file-abilities-test', 'text' => 'return 2;']);
        $this->assertIsArray($found);

        $deleted = $abilities->execute('delete_file', ['path' => $path, 'reason' => 'cleanup']);
        $this->assertIsArray($deleted);
        $this->assertSame('deleted', $deleted['action']);
        $this->assertFileDoesNotExist($this->plugin_dir . '/file-abilities-test.php');
    }

    public function test_executor_failures_become_wp_error(): void {
        $abilities = new File_Abilities(null, new File_Tool_Executor(WP_CONTENT_DIR, null));

        $result = $abilities->execute('read_file', ['path' => '../wp-config.php']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ai_assistant_file_tool_failed', $result->get_error_code());

        $result = $abilities->execute('write_file', [
            'path' => 'plugins/file-abilities-test/broken.php',
            'content' => "<?php\nfunction (",
            'reason' => 'broken',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
