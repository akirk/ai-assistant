<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Wp_App_Abilities;
use AI_Assistant\Git_Tracker_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the WpApp scaffolding ability bridge.
 */
class WpAppAbilitiesTest extends TestCase {

    private string $plugins_dir;

    protected function setUp(): void {
        $this->plugins_dir = WP_PLUGIN_DIR;
        $this->removeDirectory($this->plugins_dir . '/sample-app');
        $GLOBALS['wp_test_capabilities'] = [];
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->plugins_dir . '/sample-app');
    }

    public function test_scaffold_app_creates_composerless_wp_app(): void {
        if (!class_exists('\Akirk\CreateWpApp\Scaffolder')) {
            $this->markTestSkipped('akirk/create-wp-app is not installed.');
        }

        $abilities = new Wp_App_Abilities();
        $result = $abilities->scaffold_app([
            'slug' => 'sample-app',
            'plugin_name' => 'Sample App',
            'namespace' => 'SampleApp',
            'setup_type' => 'minimal',
            'activate' => false,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('sample-app', $result['plugin_slug']);
        $this->assertFalse($result['activated']);
        $this->assertFileExists($this->plugins_dir . '/sample-app/sample-app.php');
        $this->assertFileExists($this->plugins_dir . '/sample-app/vendor/autoload.php');
        $this->assertFileExists($this->plugins_dir . '/sample-app/vendor/akirk/wp-app/composer.json');
        $this->assertContains('sample-app.php', $result['created_files']);
        $this->assertContains('vendor/autoload.php', $result['created_files']);
    }

    public function test_existing_plugin_without_overwrite_returns_error(): void {
        mkdir($this->plugins_dir . '/sample-app', 0755, true);

        $abilities = new Wp_App_Abilities();
        $result = $abilities->scaffold_app([
            'slug' => 'sample-app',
            'activate' => false,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('plugin_exists', $result->get_error_code());
    }

    public function test_scaffold_app_tracks_created_files_when_tracker_available(): void {
        if (!class_exists('\Akirk\CreateWpApp\Scaffolder')) {
            $this->markTestSkipped('akirk/create-wp-app is not installed.');
        }

        $tracker_manager = new Git_Tracker_Manager();
        $abilities = new Wp_App_Abilities($tracker_manager);
        $result = $abilities->scaffold_app([
            'slug' => 'sample-app',
            'plugin_name' => 'Sample App',
            'namespace' => 'SampleApp',
            'setup_type' => 'minimal',
            'activate' => false,
        ]);

        $this->assertIsArray($result);
        $this->assertNotContains('.git/HEAD', $result['created_files']);

        $changes = $tracker_manager->get_all_changes_by_plugin();
        $this->assertArrayHasKey('plugins/sample-app', $changes);

        $paths = array_column($changes['plugins/sample-app']['files'], 'path');
        $this->assertContains('plugins/sample-app/sample-app.php', $paths);
        $this->assertContains('plugins/sample-app/vendor/autoload.php', $paths);
        $this->assertFileExists($this->plugins_dir . '/sample-app/.git/refs/heads/ai-changes');
    }

    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
