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
        $this->removeDirectory($this->plugins_dir . '/sample-app-mywp');
        $GLOBALS['wp_test_capabilities'] = [];
        unset($GLOBALS['wp_test_activate_plugin_result']);
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->plugins_dir . '/sample-app');
        $this->removeDirectory($this->plugins_dir . '/sample-app-mywp');
        unset($GLOBALS['wp_test_activate_plugin_result']);
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
            'url_path' => 'sample-app',
            'setup_type' => 'minimal',
            'activate' => false,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('sample-app-mywp', $result['plugin_slug']);
        $this->assertSame('sample-app-mywp', $result['url_path']);
        $this->assertSame('http://localhost/sample-app-mywp/', $result['url']);
        $this->assertFalse($result['activated']);
        $this->assertFileExists($this->plugins_dir . '/sample-app-mywp/sample-app-mywp.php');
        $this->assertFileExists($this->plugins_dir . '/sample-app-mywp/vendor/autoload.php');
        $this->assertFileExists($this->plugins_dir . '/sample-app-mywp/vendor/akirk/wp-app/composer.json');
        $this->assertContains('sample-app-mywp.php', $result['created_files']);
        $this->assertContains('vendor/autoload.php', $result['created_files']);
    }

    public function test_existing_plugin_without_overwrite_returns_error(): void {
        mkdir($this->plugins_dir . '/sample-app-mywp', 0755, true);

        $abilities = new Wp_App_Abilities();
        $result = $abilities->scaffold_app([
            'slug' => 'sample-app',
            'activate' => false,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('plugin_exists', $result->get_error_code());
    }

    public function test_scaffold_app_does_not_duplicate_mywp_suffix(): void {
        if (!class_exists('\Akirk\CreateWpApp\Scaffolder')) {
            $this->markTestSkipped('akirk/create-wp-app is not installed.');
        }

        $abilities = new Wp_App_Abilities();
        $result = $abilities->scaffold_app([
            'slug' => 'sample-app-mywp',
            'url_path' => 'tools/sample-app-mywp',
            'setup_type' => 'minimal',
            'activate' => false,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('sample-app-mywp', $result['plugin_slug']);
        $this->assertSame('tools/sample-app-mywp', $result['url_path']);
        $plugin_file = $this->plugins_dir . '/sample-app-mywp/sample-app-mywp.php';
        $this->assertFileExists($plugin_file);
        $this->assertStringContainsString('Plugin Name: Sample App', file_get_contents($plugin_file));
        $this->assertStringNotContainsString('Sample App Mywp', file_get_contents($plugin_file));
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
        $this->assertArrayHasKey('plugins/sample-app-mywp', $changes);

        $paths = array_column($changes['plugins/sample-app-mywp']['files'], 'path');
        $this->assertContains('plugins/sample-app-mywp/sample-app-mywp.php', $paths);
        $this->assertContains('plugins/sample-app-mywp/vendor/autoload.php', $paths);
        $this->assertFileExists($this->plugins_dir . '/sample-app-mywp/.git/refs/heads/ai-changes');
    }

    public function test_scaffold_app_returns_error_when_sandboxed_activation_fails(): void {
        if (!class_exists('\Akirk\CreateWpApp\Scaffolder')) {
            $this->markTestSkipped('akirk/create-wp-app is not installed.');
        }

        $GLOBALS['wp_test_activate_plugin_result'] = new \WP_Error('sandbox_failed', 'Fatal error during sandboxed activation.');

        $abilities = new Wp_App_Abilities();
        $result = $abilities->scaffold_app([
            'slug' => 'sample-app',
            'plugin_name' => 'Sample App',
            'namespace' => 'SampleApp',
            'setup_type' => 'minimal',
            'activate' => true,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('activation_failed', $result->get_error_code());
        $this->assertStringContainsString('Plugin scaffolded but activation failed', $result->get_error_message());
        $this->assertStringContainsString('Fatal error during sandboxed activation.', $result->get_error_message());
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
