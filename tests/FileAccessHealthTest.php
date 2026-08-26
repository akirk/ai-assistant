<?php
namespace AI_Assistant\Tests;

use AI_Assistant\File_Access_Health;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the writable-files Site Health check.
 */
class FileAccessHealthTest extends TestCase {

    private string $root;

    protected function setUp(): void {
        $this->root = WP_CONTENT_DIR . '/health-test';
        $this->removeDirectory($this->root);
        mkdir($this->root . '/plugins/open-plugin', 0755, true);
        mkdir($this->root . '/plugins/locked-plugin/inc', 0755, true);
        mkdir($this->root . '/themes/open-theme', 0755, true);
        file_put_contents($this->root . '/plugins/open-plugin/open-plugin.php', "<?php\n");
        file_put_contents($this->root . '/plugins/locked-plugin/locked-plugin.php', "<?php\n");
        file_put_contents($this->root . '/plugins/locked-plugin/inc/helper.php', "<?php\n");
        file_put_contents($this->root . '/themes/open-theme/style.css', "/* */\n");
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->root);
    }

    private function health(): File_Access_Health {
        return new File_Access_Health($this->root, $this->root . '/plugins', $this->root . '/themes');
    }

    public function test_all_writable_is_good(): void {
        $data = $this->health()->check();
        $this->assertSame([], $data['roots']);
        $this->assertSame([], $data['unwritable']);
        $this->assertSame(3, $data['checked']);

        $result = $this->health()->run_test();
        $this->assertSame('good', $result['status']);
        $this->assertSame(File_Access_Health::TEST_ID, $result['test']);
    }

    public function test_unwritable_file_is_reported_with_owner(): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to everything.');
        }

        chmod($this->root . '/plugins/locked-plugin/inc/helper.php', 0444);

        $data = $this->health()->check();
        $this->assertSame([], $data['roots']);
        $this->assertCount(1, $data['unwritable']);
        $this->assertSame('plugins/locked-plugin', $data['unwritable'][0]['path']);
        $this->assertSame('plugin', $data['unwritable'][0]['type']);
        $this->assertSame('plugins/locked-plugin/inc/helper.php', $data['unwritable'][0]['example']);
        $this->assertNotSame('', $data['unwritable'][0]['owner']);

        $result = $this->health()->run_test();
        $this->assertSame('recommended', $result['status']);
        $this->assertStringContainsString('plugins/locked-plugin', $result['description']);
        $this->assertStringContainsString('<details><summary>1 plugin or theme is not writable</summary>', $result['description']);
        $this->assertStringContainsString('ai-assistant-settings', $result['actions']);
    }

    public function test_git_objects_are_ignored(): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to everything.');
        }

        mkdir($this->root . '/plugins/open-plugin/.git/objects/ab', 0755, true);
        file_put_contents($this->root . '/plugins/open-plugin/.git/objects/ab/cdef', 'blob');
        chmod($this->root . '/plugins/open-plugin/.git/objects/ab/cdef', 0444);

        $data = $this->health()->check();
        $this->assertSame([], $data['unwritable']);
    }

    public function test_read_only_file_owned_by_process_user_is_explained(): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to everything.');
        }

        chmod($this->root . '/plugins/locked-plugin/locked-plugin.php', 0444);

        $result = $this->health()->run_test();
        $this->assertStringContainsString('read-only permissions', $result['description']);
        $this->assertStringContainsString('plugins/locked-plugin/locked-plugin.php', $result['description']);
        $this->assertStringNotContainsString('owned by', $result['description']);
    }

    public function test_unwritable_container_is_reported(): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to everything.');
        }

        chmod($this->root . '/themes', 0555);
        try {
            $data = $this->health()->check();
        } finally {
            chmod($this->root . '/themes', 0755);
        }

        $this->assertSame(['themes'], $data['roots']);
        $this->assertSame([], $data['unwritable']);
    }

    public function test_site_status_test_registered_only_when_enabled(): void {
        $GLOBALS['wp_test_options'] = [];
        $tests = $this->health()->add_site_status_test(['direct' => []]);
        $this->assertArrayHasKey(File_Access_Health::TEST_ID, $tests['direct']);
        $this->assertIsCallable($tests['direct'][File_Access_Health::TEST_ID]['test']);
    }

    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        chmod($dir, 0755);
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                chmod($path, 0644);
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
