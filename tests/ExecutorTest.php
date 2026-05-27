<?php
namespace AI_Assistant\Tests;

use PHPUnit\Framework\TestCase;
use AI_Assistant\Tools;
use AI_Assistant\Executor;

/**
 * Unit tests for the Executor class
 */
class ExecutorTest extends TestCase {

    private Tools $tools;
    private Executor $executor;
    private string $test_dir;

    protected function setUp(): void {
        $this->tools = new Tools();
        $this->executor = new Executor($this->tools);
        $this->test_dir = WP_CONTENT_DIR;

        // All tool capabilities granted by default
        $GLOBALS['wp_test_capabilities'] = [];
        $GLOBALS['wp_test_is_playground'] = false;
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_abilities'] = [];

        \AI_Assistant_Dev_Tools::init();

        // Ensure clean test environment
        $this->cleanTestDirectory();
        $this->createTestStructure();
    }

    protected function tearDown(): void {
        $this->cleanTestDirectory();
    }

    private function cleanTestDirectory(): void {
        $plugins_dir = $this->test_dir . '/plugins';
        if (is_dir($plugins_dir)) {
            $this->recursiveDelete($plugins_dir);
        }
        mkdir($plugins_dir, 0755, true);
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTestStructure(): void {
        // Create test plugin directories and files
        $plugins = [
            'test-plugin' => [
                'test-plugin.php' => '<?php /* Plugin Name: Test Plugin */',
                'includes/helper.php' => '<?php function test_helper() {}',
            ],
            'another-plugin' => [
                'another-plugin.php' => '<?php /* Plugin Name: Another Plugin */',
                'lib/utils.php' => '<?php class Utils {}',
            ],
        ];

        foreach ($plugins as $plugin_name => $files) {
            $plugin_dir = $this->test_dir . '/plugins/' . $plugin_name;
            mkdir($plugin_dir, 0755, true);
            foreach ($files as $file => $content) {
                $file_path = $plugin_dir . '/' . $file;
                $file_dir = dirname($file_path);
                if (!is_dir($file_dir)) {
                    mkdir($file_dir, 0755, true);
                }
                file_put_contents($file_path, $content);
            }
        }
    }

    // ===== SEARCH FILES TESTS =====

    public function test_search_files_with_valid_pattern(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/*/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('pattern', $result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertEquals('plugins/*/*.php', $result['pattern']);
        $this->assertGreaterThanOrEqual(2, $result['count']);
    }

    public function test_search_files_with_no_matches(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/nonexistent-plugin/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['matches']);
    }

    public function test_search_files_with_nested_pattern(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/test-plugin/**/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
    }

    public function test_search_files_with_specific_plugin_pattern(): void {
        // This is the pattern that was causing issues: plugins/hello-dolly/*.php
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/test-plugin/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals('plugins/test-plugin/test-plugin.php', $result['matches'][0]['path']);
    }

    public function test_search_files_returns_file_metadata(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/test-plugin/*.php',
        ]);

        $this->assertNotEmpty($result['matches']);
        $match = $result['matches'][0];
        $this->assertArrayHasKey('path', $match);
        $this->assertArrayHasKey('type', $match);
        $this->assertArrayHasKey('size', $match);
        $this->assertEquals('file', $match['type']);
    }

    public function test_search_files_missing_pattern_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("search_files requires 'pattern' argument");

        $this->executor->execute_tool('search_files', []);
    }

    // ===== WRITE FILE TESTS =====

    public function test_write_file_creates_new_file(): void {
        $result = $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/new-file.php',
            'content' => '<?php echo "Hello";',
            'reason' => 'Test creating new file',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('created', $result['action']);
        $this->assertEquals('plugins/test-plugin/new-file.php', $result['path']);
        $this->assertNull($result['previous_size']);

        // Verify file exists
        $this->assertFileExists($this->test_dir . '/plugins/test-plugin/new-file.php');
    }

    public function test_write_file_updates_existing_file(): void {
        $result = $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'content' => '<?php /* Updated Plugin */',
            'reason' => 'Test updating file',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('updated', $result['action']);
        $this->assertNotNull($result['previous_size']);
    }

    public function test_write_file_missing_content_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("write_file requires 'content' argument");

        $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/file.php',
        ]);
    }

    public function test_write_file_missing_path_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("write_file requires 'path' argument");

        $this->executor->execute_file_tool('write_file', [
            'content' => '<?php echo "test";',
        ]);
    }

    public function test_write_file_creates_directories(): void {
        $result = $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/deep/nested/dir/file.php',
            'content' => '<?php',
            'reason' => 'Test creating nested directories',
        ]);

        $this->assertEquals('created', $result['action']);
        $this->assertFileExists($this->test_dir . '/plugins/test-plugin/deep/nested/dir/file.php');
    }

    public function test_write_file_with_empty_content(): void {
        $result = $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/empty.php',
            'content' => '',
            'reason' => 'Test empty file',
        ]);

        $this->assertEquals('created', $result['action']);
        $this->assertEquals(0, $result['size']);
    }

    public function test_write_file_with_array_content_converts_to_json(): void {
        $result = $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/data.json',
            'content' => ['key' => 'value', 'nested' => ['a' => 1]],
            'reason' => 'Test JSON conversion',
        ]);

        $this->assertEquals('created', $result['action']);
        $content = file_get_contents($this->test_dir . '/plugins/test-plugin/data.json');
        $decoded = json_decode($content, true);
        $this->assertEquals('value', $decoded['key']);
    }

    // ===== READ FILE TESTS =====

    public function test_read_file_returns_content(): void {
        $result = $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('modified', $result);
        $this->assertStringContainsString('Plugin Name: Test Plugin', $result['content']);
    }

    public function test_read_file_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->executor->execute_tool('read_file', [
            'path' => 'plugins/nonexistent/file.php',
        ]);
    }

    public function test_read_file_missing_path_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("read_file requires 'path' argument");

        $this->executor->execute_tool('read_file', []);
    }

    // ===== EDIT FILE TESTS =====

    public function test_edit_file_applies_single_edit(): void {
        $result = $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => [
                ['search' => 'Test Plugin', 'replace' => 'Modified Plugin'],
            ],
            'reason' => 'Test editing file',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['edits_applied']);
        $this->assertEquals(0, $result['edits_failed']);

        // Verify the edit was applied
        $content = file_get_contents($this->test_dir . '/plugins/test-plugin/test-plugin.php');
        $this->assertStringContainsString('Modified Plugin', $content);
    }

    public function test_edit_file_fails_when_search_not_found(): void {
        $result = $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => [
                ['search' => 'nonexistent string', 'replace' => 'replacement'],
            ],
            'reason' => 'Test failed edit',
        ]);

        $this->assertEquals(0, $result['edits_applied']);
        $this->assertEquals(1, $result['edits_failed']);
        $this->assertEquals('Search string not found', $result['failed'][0]['reason']);
    }

    public function test_edit_file_missing_edits_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("edit_file requires 'edits' argument");

        $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);
    }

    public function test_edit_file_edits_must_be_array(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("edit_file 'edits' must be an array");

        $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => 'not an array',
        ]);
    }

    public function test_edit_file_accepts_json_encoded_edits_array(): void {
        $result = $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => json_encode([
                [
                    'search' => 'Test Plugin',
                    'replace' => 'JSON Encoded Plugin',
                ],
            ]),
            'reason' => 'Test JSON encoded edits',
        ]);

        $this->assertEquals(1, $result['edits_applied']);

        $content = file_get_contents($this->test_dir . '/plugins/test-plugin/test-plugin.php');
        $this->assertStringContainsString('JSON Encoded Plugin', $content);
    }

    public function test_edit_file_rejects_json_encoded_edits_with_trailing_tool_markup(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("edit_file 'edits' must be an array");

        $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => json_encode([
                [
                    'search' => 'Test Plugin',
                    'replace' => 'Should Not Apply',
                ],
            ]) . "\n</invoke>",
            'reason' => 'Test malformed JSON encoded edits',
        ]);
    }

    public function test_edit_file_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/nonexistent/file.php',
            'edits' => [['search' => 'a', 'replace' => 'b']],
            'reason' => 'Test file not found',
        ]);
    }

    // ===== DELETE FILE TESTS =====

    public function test_delete_file_removes_file(): void {
        // Create a file to delete
        $file_path = $this->test_dir . '/plugins/test-plugin/to-delete.php';
        file_put_contents($file_path, '<?php');

        $result = $this->executor->execute_file_tool('delete_file', [
            'path' => 'plugins/test-plugin/to-delete.php',
            'reason' => 'Test deleting file',
        ]);

        $this->assertEquals('deleted', $result['action']);
        $this->assertFileDoesNotExist($file_path);
    }

    public function test_delete_file_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->executor->execute_file_tool('delete_file', [
            'path' => 'plugins/nonexistent/file.php',
            'reason' => 'Test file not found',
        ]);
    }

    public function test_emergency_deactivate_plugin_guards_plugin_main_file(): void {
        $file_tools = new \AI_Assistant\File_Tool_Executor($this->test_dir);

        $result = $file_tools->execute('emergency_deactivate_plugin', [
            'plugin_slug' => 'test-plugin',
            'reason' => 'Recover from activation fatal',
        ]);

        $this->assertSame('emergency_guarded', $result['action']);
        $this->assertSame('test-plugin', $result['plugin_slug']);
        $this->assertSame('test-plugin/test-plugin.php', $result['plugin_file']);
        $this->assertSame('plugins/test-plugin/test-plugin.php', $result['guarded_path']);
        $this->assertDirectoryExists($this->test_dir . '/plugins/test-plugin');

        $content = file_get_contents($this->test_dir . '/plugins/test-plugin/test-plugin.php');
        $this->assertStringStartsWith(\AI_Assistant\Emergency_Plugin_Guard::PREFIX, $content);
        $this->assertStringContainsString('Plugin Name: Test Plugin', $content);
    }

    public function test_plugin_recovery_admin_renders_guarded_plugin_row_notice(): void {
        $main_file = $this->test_dir . '/plugins/test-plugin/test-plugin.php';
        \AI_Assistant\Emergency_Plugin_Guard::add_guard_to_file($main_file);

        $admin = new \AI_Assistant\Plugin_Recovery_Admin();

        ob_start();
        $admin->render_guarded_plugin_row('test-plugin/test-plugin.php', ['Name' => 'Test Plugin'], 'active');
        $output = ob_get_clean();

        $this->assertStringContainsString('ai-assistant-emergency-disabled', $output);
        $this->assertStringContainsString('Test Plugin was emergency-disabled', $output);
        $this->assertStringContainsString('ai_assistant_restore_plugin_guard', $output);
        $this->assertStringContainsString('ai_assistant_deactivate_guarded_plugin', $output);
    }

    public function test_emergency_deactivate_plugin_refuses_ai_assistant(): void {
        $file_tools = new \AI_Assistant\File_Tool_Executor($this->test_dir);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Refusing to emergency deactivate AI Assistant');

        $file_tools->execute('emergency_deactivate_plugin', [
            'plugin_slug' => 'ai-assistant',
        ]);
    }

    public function test_file_tool_auth_allows_emergency_deactivate_with_edit_file_enabled(): void {
        $this->assertTrue(\AI_Assistant\File_Tool_Auth::can_execute_tool(
            'emergency_deactivate_plugin',
            ['plugin_slug' => 'test-plugin'],
            [
                'permission' => 'full',
                'enabled_tools' => ['edit_file'],
            ]
        ));

        $this->assertFalse(\AI_Assistant\File_Tool_Auth::can_execute_tool(
            'emergency_deactivate_plugin',
            ['plugin_slug' => 'test-plugin'],
            [
                'permission' => 'read_only',
                'enabled_tools' => ['edit_file'],
            ]
        ));
    }

    // ===== LIST DIRECTORY TESTS =====

    public function test_list_directory_returns_items(): void {
        $result = $this->executor->execute_tool('list_directory', [
            'path' => 'plugins/test-plugin',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThan(0, $result['count']);
    }

    public function test_list_directory_not_found_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Directory not found');

        $this->executor->execute_tool('list_directory', [
            'path' => 'plugins/nonexistent',
        ]);
    }

    public function test_list_directory_on_file_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Not a directory');

        $this->executor->execute_tool('list_directory', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);
    }

    // ===== SEARCH CONTENT TESTS =====

    public function test_search_content_finds_matches(): void {
        $result = $this->executor->execute_tool('search_content', [
            'needle' => 'Plugin Name',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThan(0, $result['count']);
    }

    public function test_search_content_with_directory_filter(): void {
        $result = $this->executor->execute_tool('search_content', [
            'needle' => 'Plugin Name',
            'directory' => 'plugins/test-plugin',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['count']);
    }

    public function test_search_content_missing_needle_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("search_content requires 'needle' argument");

        $this->executor->execute_tool('search_content', []);
    }

    // ===== PERMISSION TESTS =====

    public function test_read_only_permission_allows_read_file(): void {
        $result = $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ], 'read_only');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
    }

    public function test_read_only_permission_allows_search_files(): void {
        $result = $this->executor->execute_tool('search_files', [
            'pattern' => 'plugins/*/*.php',
        ], 'read_only');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
    }

    public function test_read_only_permission_blocks_write_file(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'write_file' requires full access permission");

        $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/file.php',
            'content' => '<?php',
        ], 'read_only');
    }

    public function test_read_only_permission_blocks_edit_file(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'edit_file' requires full access permission");

        $this->executor->execute_tool('edit_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
            'edits' => [['search' => 'a', 'replace' => 'b']],
        ], 'read_only');
    }

    public function test_read_only_permission_blocks_delete_file(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'delete_file' requires full access permission");

        $this->executor->execute_tool('delete_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ], 'read_only');
    }

    public function test_chat_only_permission_blocks_all_tools(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tool execution not allowed with chat-only permission');

        $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ], 'chat_only');
    }

    public function test_read_only_permission_allows_readonly_ability_execution(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_execute_ability'] = false;
        $GLOBALS['wp_test_abilities']['demo/read'] = $this->createAbility(true);

        $result = $this->executor->execute_tool('ability', [
            'action' => 'execute',
            'ability' => 'demo/read',
            'arguments' => ['id' => 123],
        ], 'read_only');

        $this->assertTrue($result['success']);
        $this->assertEquals('demo/read', $result['ability']);
        $this->assertEquals(['input' => ['id' => 123]], $result['result']);
    }

    public function test_read_only_permission_blocks_write_ability_execution(): void {
        $GLOBALS['wp_test_abilities']['demo/write'] = $this->createAbility(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'ability' requires full access permission");

        $this->executor->execute_tool('ability', [
            'action' => 'execute',
            'ability' => 'demo/write',
        ], 'read_only');
    }

    public function test_read_only_ability_execution_respects_tool_enabled_option(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_execute_ability'] = false;
        $GLOBALS['wp_test_options']['ai_assistant_enabled_tools'] = ['list_abilities', 'get_ability'];
        $GLOBALS['wp_test_abilities']['demo/read'] = $this->createAbility(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'ability' is not enabled");

        $this->executor->execute_tool('ability', [
            'action' => 'execute',
            'ability' => 'demo/read',
        ], 'read_only');
    }

    public function test_consolidated_ability_decodes_stringified_arguments(): void {
        $GLOBALS['wp_test_abilities']['demo/write'] = $this->createAbility(false);

        $result = $this->executor->execute_tool('ability', [
            'action' => 'execute',
            'ability' => 'demo/write',
            'arguments' => '{"title":"Bacon Jam","servings":4}',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals([
            'input' => [
                'title' => 'Bacon Jam',
                'servings' => 4,
            ],
        ], $result['result']);
    }

    public function test_legacy_execute_ability_decodes_stringified_arguments(): void {
        $GLOBALS['wp_test_abilities']['demo/write'] = $this->createAbility(false);

        $result = $this->executor->execute_tool('execute_ability', [
            'ability' => 'demo/write',
            'arguments' => '{"title":"Bacon Jam"}',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(['input' => ['title' => 'Bacon Jam']], $result['result']);
    }

    public function test_ability_execution_preserves_empty_schema_input(): void {
        $GLOBALS['wp_test_abilities']['demo/read'] = $this->createAbility(true);

        $result = $this->executor->execute_tool('ability', [
            'action' => 'execute',
            'ability' => 'demo/read',
            'arguments' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(['input' => []], $result['result']);
    }

    public function test_ability_rejects_invalid_stringified_arguments(): void {
        $GLOBALS['wp_test_abilities']['demo/write'] = $this->createAbility(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("ability requires 'arguments' to be an object or valid JSON object");

        $this->executor->execute_tool('ability', [
            'action' => 'execute',
            'ability' => 'demo/write',
            'arguments' => '{"title":',
        ]);
    }

    // ===== PATH SECURITY TESTS =====

    public function test_path_traversal_blocked(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Access denied');

        $this->executor->execute_tool('read_file', [
            'path' => '../../../etc/passwd',
        ]);
    }

    public function test_empty_path_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Path cannot be empty');

        $this->executor->execute_tool('read_file', [
            'path' => '',
        ]);
    }

    // ===== UNKNOWN TOOL TEST =====

    public function test_unknown_tool_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown tool: fake_tool');

        $this->executor->execute_tool('fake_tool', []);
    }

    public function test_mutating_file_tools_do_not_execute_through_ajax_executor(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown tool: write_file');

        $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/ajax-fallback.php',
            'content' => '<?php',
            'reason' => 'Should use direct file endpoint',
        ]);
    }

    // ===== PER-TOOL CAPABILITY TESTS =====

    public function test_tool_blocked_when_capability_denied(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_read_file'] = false;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'read_file' is not enabled");

        $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);
    }

    public function test_tool_allowed_when_capability_granted(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_read_file'] = true;

        $result = $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);

        $this->assertArrayHasKey('content', $result);
    }

    public function test_dangerous_tool_blocked_when_capability_denied(): void {
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_run_php'] = false;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'run_php' is not enabled");
        $this->expectExceptionMessage('Tool Permissions');

        $this->executor->execute_tool('run_php', ['code' => 'return 1;']);
    }

    public function test_capability_check_runs_before_permission_check(): void {
        // Capability denied takes precedence over read_only permission check
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_write_file'] = false;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'write_file' is not enabled");

        $this->executor->execute_tool('write_file', [
            'path' => 'plugins/test-plugin/file.php',
            'content' => '<?php',
            'reason' => 'test',
        ], 'full');
    }

    public function test_each_tool_has_independent_capability(): void {
        // read_file denied, write_file allowed
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_read_file'] = false;
        $GLOBALS['wp_test_capabilities']['ai_assistant_tool_write_file'] = true;

        // write_file should pass the capability check, then fail because mutating
        // file tools are routed through the direct file endpoint instead of AJAX.
        try {
            $this->executor->execute_tool('write_file', [
                'path' => 'plugins/test-plugin/cap-test.php',
                'content' => '<?php',
                'reason' => 'test',
            ]);
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('is not enabled', $e->getMessage());
        }

        // read_file should fail with capability error
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tool 'read_file' is not enabled");

        $this->executor->execute_tool('read_file', [
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);
    }

    // ===== PHP LINT VALIDATION TESTS =====

    public function test_write_file_with_valid_php_succeeds(): void {
        $result = $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/valid.php',
            'content' => '<?php function hello() { return "world"; }',
            'reason' => 'Test valid PHP',
        ]);

        $this->assertEquals('created', $result['action']);
        $this->assertFileExists($this->test_dir . '/plugins/test-plugin/valid.php');
    }

    public function test_write_file_with_invalid_php_throws_exception(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PHP syntax error');

        $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/invalid.php',
            'content' => '<?php function hello( { return "world"; }',
            'reason' => 'Test invalid PHP',
        ]);
    }

    public function test_write_file_with_invalid_php_does_not_create_file(): void {
        $file_path = $this->test_dir . '/plugins/test-plugin/invalid-not-created.php';

        try {
            $this->executor->execute_file_tool('write_file', [
                'path' => 'plugins/test-plugin/invalid-not-created.php',
                'content' => '<?php class Broken {',
                'reason' => 'Test invalid PHP not created',
            ]);
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertFileDoesNotExist($file_path);
    }

    public function test_write_file_with_non_php_file_skips_lint(): void {
        $result = $this->executor->execute_file_tool('write_file', [
            'path' => 'plugins/test-plugin/data.txt',
            'content' => '<?php this is not valid php but its a txt file',
            'reason' => 'Test non-PHP file',
        ]);

        $this->assertEquals('created', $result['action']);
        $this->assertFileExists($this->test_dir . '/plugins/test-plugin/data.txt');
    }

    public function test_edit_file_with_valid_php_result_succeeds(): void {
        // Create a valid PHP file first
        file_put_contents(
            $this->test_dir . '/plugins/test-plugin/to-edit.php',
            '<?php function old_name() { return true; }'
        );

        $result = $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/to-edit.php',
            'edits' => [
                ['search' => 'old_name', 'replace' => 'new_name'],
            ],
            'reason' => 'Test valid edit',
        ]);

        $this->assertEquals(1, $result['edits_applied']);
        $content = file_get_contents($this->test_dir . '/plugins/test-plugin/to-edit.php');
        $this->assertStringContainsString('new_name', $content);
    }

    public function test_edit_file_with_invalid_php_result_throws_exception(): void {
        // Create a valid PHP file first
        file_put_contents(
            $this->test_dir . '/plugins/test-plugin/to-break.php',
            '<?php function valid() { return true; }'
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PHP syntax error');

        $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/to-break.php',
            'edits' => [
                ['search' => '{ return true; }', 'replace' => '{ return true;'],
            ],
            'reason' => 'Test breaking edit',
        ]);
    }

    public function test_edit_file_with_invalid_php_result_preserves_original(): void {
        $file_path = $this->test_dir . '/plugins/test-plugin/to-preserve.php';
        $original_content = '<?php function valid() { return true; }';
        file_put_contents($file_path, $original_content);

        try {
            $this->executor->execute_file_tool('edit_file', [
                'path' => 'plugins/test-plugin/to-preserve.php',
                'edits' => [
                    ['search' => '{ return true; }', 'replace' => '{ return true;'],
                ],
                'reason' => 'Test preserving original',
            ]);
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals($original_content, file_get_contents($file_path));
    }

    public function test_edit_file_on_non_php_file_skips_lint(): void {
        // Create a text file
        file_put_contents(
            $this->test_dir . '/plugins/test-plugin/config.txt',
            'setting=value'
        );

        $result = $this->executor->execute_file_tool('edit_file', [
            'path' => 'plugins/test-plugin/config.txt',
            'edits' => [
                ['search' => 'value', 'replace' => '<?php invalid {'],
            ],
            'reason' => 'Test non-PHP edit',
        ]);

        $this->assertEquals(1, $result['edits_applied']);
    }

    // ===== CONSOLIDATED 'find' TOOL TESTS =====

    public function test_find_list_directory(): void {
        $result = $this->executor->execute_tool('find', [
            'path' => 'plugins/test-plugin',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThan(0, $result['count']);
    }

    public function test_find_search_files_by_glob(): void {
        $result = $this->executor->execute_tool('find', [
            'glob' => 'plugins/*/*.php',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThanOrEqual(2, $result['count']);
    }

    public function test_find_search_files_uses_path_as_glob_base(): void {
        $templates_dir = $this->test_dir . '/plugins/test-plugin/templates';
        mkdir($templates_dir, 0755, true);
        file_put_contents($templates_dir . '/single.php', '<?php // Template');
        file_put_contents($this->test_dir . '/plugins/another-plugin/single.php', '<?php // Other');

        $result = $this->executor->execute_tool('find', [
            'path' => 'plugins/test-plugin/templates',
            'glob' => '*.php',
        ]);

        $this->assertSame('plugins/test-plugin/templates/*.php', $result['pattern']);
        $this->assertSame('plugins/test-plugin/templates', $result['directory']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('plugins/test-plugin/templates/single.php', $result['matches'][0]['path']);

        $nested = $this->executor->execute_tool('find', [
            'path' => 'plugins/test-plugin',
            'glob' => 'templates/*.php',
        ]);

        $this->assertSame('plugins/test-plugin/templates/*.php', $nested['pattern']);
        $this->assertSame(1, $nested['count']);
        $this->assertSame('plugins/test-plugin/templates/single.php', $nested['matches'][0]['path']);
    }

    public function test_find_search_content(): void {
        $result = $this->executor->execute_tool('find', [
            'text' => 'Plugin Name',
            'path' => 'plugins/test-plugin',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertEquals(1, $result['count']);
    }

    public function test_find_search_content_accepts_file_path(): void {
        $result = $this->executor->execute_tool('find', [
            'text' => 'Plugin Name',
            'path' => 'plugins/test-plugin/test-plugin.php',
        ]);

        $this->assertSame('snippets', $result['mode']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('plugins/test-plugin/test-plugin.php', $result['matches'][0]['path']);
        $this->assertSame(1, $result['matches'][0]['matches'][0]['line']);

        $paths = $this->executor->execute_tool('find', [
            'text' => 'Plugin Name',
            'path' => 'plugins/test-plugin/test-plugin.php',
            'mode' => 'paths',
        ]);

        $this->assertSame('paths', $paths['mode']);
        $this->assertSame(1, $paths['count']);
        $this->assertSame('plugins/test-plugin/test-plugin.php', $paths['matches'][0]['path']);
        $this->assertArrayNotHasKey('matches', $paths['matches'][0]);
    }

    public function test_find_search_content_paths_mode_returns_more_files_without_snippets(): void {
        $dir = $this->test_dir . '/plugins/test-plugin/many';
        mkdir($dir, 0755, true);

        for ($i = 1; $i <= 60; $i++) {
            file_put_contents(sprintf('%s/match-%02d.php', $dir, $i), "<?php\n// BroadNeedle\n");
        }

        $result = $this->executor->execute_tool('find', [
            'text' => 'BroadNeedle',
            'path' => 'plugins/test-plugin/many',
            'file_pattern' => '*.php',
            'mode' => 'paths',
        ]);

        $this->assertSame('paths', $result['mode']);
        $this->assertSame(200, $result['max_results']);
        $this->assertSame(60, $result['count']);
        $this->assertFalse($result['truncated']);
        $this->assertArrayHasKey('path', $result['matches'][0]);
        $this->assertArrayNotHasKey('matches', $result['matches'][0]);

        $limited = $this->executor->execute_tool('find', [
            'text' => 'BroadNeedle',
            'path' => 'plugins/test-plugin/many',
            'file_pattern' => '*.php',
            'mode' => 'paths',
            'max_results' => 3,
        ]);

        $this->assertSame(3, $limited['max_results']);
        $this->assertSame(3, $limited['count']);
        $this->assertTrue($limited['truncated']);
    }

    public function test_find_defaults_to_list_directory(): void {
        $result = $this->executor->execute_tool('find', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
    }

    public function test_find_read_only_permission(): void {
        $result = $this->executor->execute_tool('find', [
            'path' => 'plugins/test-plugin',
        ], 'read_only');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
    }

    // ===== CONSOLIDATED 'environment_info' TOOL TESTS =====

    public function test_environment_info_returns_data(): void {
        $GLOBALS['wp_test_options']['active_plugins'] = ['ai-assistant/ai-assistant.php'];

        $result = $this->executor->execute_tool('environment_info', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('wp', $result);
        $this->assertArrayHasKey('php', $result);
        $this->assertArrayHasKey('theme', $result);
        $this->assertArrayHasKey('plugins', $result);
        $this->assertSame('AI Assistant', $result['plugins']['ai-assistant']['title']);
        $this->assertSame('AI-powered chat interface for WordPress.', $result['plugins']['ai-assistant']['description']);
        $this->assertArrayNotHasKey('inactive', $result);
    }

    public function test_environment_info_can_include_inactive_plugins(): void {
        $result = $this->executor->execute_tool('environment_info', [
            'include_inactive' => true,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('inactive', $result);
        $this->assertSame('Hello Dolly', $result['inactive']['hello']['title']);
        $this->assertStringContainsString('hope and enthusiasm', $result['inactive']['hello']['description']);
    }

    public function test_environment_info_read_only_permission(): void {
        $result = $this->executor->execute_tool('environment_info', [], 'read_only');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('php', $result);
    }

    public function test_write_file_lint_error_includes_line_number(): void {
        try {
            $this->executor->execute_file_tool('write_file', [
                'path' => 'plugins/test-plugin/error-line.php',
                'content' => "<?php\nfunction valid() {}\nfunction broken( {\n",
                'reason' => 'Test line number',
            ]);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('PHP syntax error', $e->getMessage());
            $this->assertStringContainsString('line', $e->getMessage());
        }
    }

    private function createAbility(bool $readonly, bool $destructive = false): object {
        return new class($readonly, $destructive) {
            private bool $readonly;
            private bool $destructive;

            public function __construct(bool $readonly, bool $destructive) {
                $this->readonly = $readonly;
                $this->destructive = $destructive;
            }

            public function get_input_schema(): array {
                return ['type' => 'object'];
            }

            public function execute($input) {
                return ['input' => $input];
            }

            public function get_meta(): array {
                return [
                    'annotations' => [
                        'readonly' => $this->readonly,
                        'destructive' => $this->destructive,
                    ],
                ];
            }
        };
    }
}
