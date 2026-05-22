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
    }

    protected function tearDown(): void {
        $this->deleteIfExists($this->secretPath());
        $this->deleteIfExists(WP_CONTENT_DIR . '/secret-link.php');
        $this->deleteIfExists(WP_CONTENT_DIR . '/visible-secret-test.php');
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
}
