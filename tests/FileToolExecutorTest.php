<?php
namespace AI_Assistant\Tests;

use AI_Assistant\File_Tool_Executor;
use PHPUnit\Framework\TestCase;

class FileToolExecutorTest extends TestCase {

    private File_Tool_Executor $executor;

    protected function setUp(): void {
        $this->executor = new File_Tool_Executor(WP_CONTENT_DIR, null);
        $this->deleteIfExists($this->secretPath());
        $this->deleteIfExists(WP_CONTENT_DIR . '/secret-link.php');
        $this->deleteIfExists(WP_CONTENT_DIR . '/visible-secret-test.php');
        $this->deleteIfExists(WP_CONTENT_DIR . '/large-read-test.txt');
        $this->deleteIfExists(WP_CONTENT_DIR . '/search-window-test.php');
        $this->deleteDirectoryIfExists(WP_PLUGIN_DIR . '/ai-changes-meta-test');
    }

    protected function tearDown(): void {
        $this->deleteIfExists($this->secretPath());
        $this->deleteIfExists(WP_CONTENT_DIR . '/secret-link.php');
        $this->deleteIfExists(WP_CONTENT_DIR . '/visible-secret-test.php');
        $this->deleteIfExists(WP_CONTENT_DIR . '/large-read-test.txt');
        $this->deleteIfExists(WP_CONTENT_DIR . '/search-window-test.php');
        $this->deleteDirectoryIfExists(WP_PLUGIN_DIR . '/ai-changes-meta-test');
    }

    public function test_read_file_rejects_file_tool_signing_secret(): void {
        $this->createSecretFile();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File tool signing secret cannot be read');

        $this->executor->execute('read_file', [
            'path' => '.ai-assistant-file-tools-secret.php',
        ]);
    }

    public function test_read_file_rejects_symlink_to_file_tool_signing_secret(): void {
        $this->createSecretFile();

        $link_path = WP_CONTENT_DIR . '/secret-link.php';
        if (!symlink($this->secretPath(), $link_path)) {
            $this->markTestSkipped('Unable to create symlink in test environment.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File tool signing secret cannot be read');

        $this->executor->execute('read_file', [
            'path' => 'secret-link.php',
        ]);
    }

    public function test_search_content_skips_file_tool_signing_secret(): void {
        $this->createSecretFile();

        $result = $this->executor->execute('search_content', [
            'needle'       => 'test-signing-secret',
            'directory'    => '',
            'file_pattern' => '.ai-assistant-file-tools-secret.php',
        ]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_search_content_still_returns_normal_files(): void {
        file_put_contents(WP_CONTENT_DIR . '/visible-secret-test.php', "<?php\n// test-signing-secret\n");

        $result = $this->executor->execute('search_content', [
            'needle'       => 'test-signing-secret',
            'directory'    => '',
            'file_pattern' => 'visible-secret-test.php',
        ]);

        $this->assertSame(1, $result['count']);
        $this->assertSame('visible-secret-test.php', $result['matches'][0]['path']);
    }

    public function test_file_tools_report_ai_changes_metadata_server_side(): void {
        $manager = new \AI_Assistant\Git_Tracker_Manager();
        $executor = new File_Tool_Executor(WP_CONTENT_DIR, $manager);
        $path = 'plugins/ai-changes-meta-test/meta.php';

        $write_result = $executor->execute('write_file', [
            'path' => $path,
            'content' => "<?php\n// changed\n",
            'reason' => 'Create metadata test plugin file',
        ]);

        $this->assertArrayHasKey('ai_changes', $write_result);
        $this->assertSame('plugins/ai-changes-meta-test', $write_result['ai_changes']['root']);
        $this->assertSame('plugin', $write_result['ai_changes']['type']);

        $read_result = $executor->execute('read_file', [
            'path' => $path,
        ]);

        $this->assertArrayHasKey('ai_changes', $read_result);
        $this->assertSame('plugins/ai-changes-meta-test', $read_result['ai_changes']['root']);
    }

    public function test_read_file_omits_ai_changes_metadata_without_tracked_changes(): void {
        $manager = new \AI_Assistant\Git_Tracker_Manager();
        $executor = new File_Tool_Executor(WP_CONTENT_DIR, $manager);
        $dir = WP_PLUGIN_DIR . '/ai-changes-meta-test';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/meta.php', "<?php\n// unchanged\n");

        $result = $executor->execute('read_file', [
            'path' => 'plugins/ai-changes-meta-test/meta.php',
        ]);

        $this->assertArrayNotHasKey('ai_changes', $result);
    }

    public function test_read_file_can_return_requested_chunk(): void {
        $content = '0123456789abcdefghijklmnopqrstuvwxyz';
        file_put_contents(WP_CONTENT_DIR . '/large-read-test.txt', $content);

        $result = $this->executor->execute('read_file', [
            'path'       => 'large-read-test.txt',
            'offset'     => 10,
            'max_length' => 8,
        ]);

        $this->assertSame('abcdefgh', $result['content']);
        $this->assertSame(strlen($content), $result['size']);
        $this->assertSame(10, $result['offset']);
        $this->assertSame(8, $result['returned_bytes']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(18, $result['next_offset']);
    }

    public function test_read_file_can_return_search_window(): void {
        file_put_contents(WP_CONTENT_DIR . '/search-window-test.php', implode("\n", [
            '<?php',
            'function first() {',
            '    return 1;',
            '}',
            '',
            'function target_function() {',
            '    $value = 2;',
            '    return $value;',
            '}',
            '',
            'function last() {',
            '    return 3;',
            '}',
        ]));

        $result = $this->executor->execute('read_file', [
            'path'         => 'search-window-test.php',
            'search'       => 'function target_function',
            'before_lines' => 1,
            'after_lines'  => 2,
        ]);

        $this->assertTrue($result['match_found']);
        $this->assertSame(6, $result['match_line']);
        $this->assertSame(5, $result['line_start']);
        $this->assertSame(8, $result['line_end']);
        $this->assertStringContainsString('function target_function()', $result['content']);
        $this->assertStringContainsString('return $value;', $result['content']);
        $this->assertStringNotContainsString('function first()', $result['content']);
        $this->assertFalse($result['truncated']);
    }

    private function createSecretFile(): void {
        file_put_contents($this->secretPath(), "<?php\nreturn 'test-signing-secret';\n");
    }

    private function secretPath(): string {
        return WP_CONTENT_DIR . '/.ai-assistant-file-tools-secret.php';
    }

    private function deleteIfExists(string $path): void {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        }
    }

    private function deleteDirectoryIfExists(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectoryIfExists($path) : unlink($path);
        }

        rmdir($dir);
    }
}
