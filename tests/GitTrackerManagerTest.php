<?php
namespace AI_Assistant\Tests;

use PHPUnit\Framework\TestCase;
use AI_Assistant\Git_Tracker;
use AI_Assistant\Git_Tracker_Manager;

/**
 * Unit tests for the Git_Tracker_Manager class
 *
 * Tests how the AI Changes page discovers and handles plugins/themes
 * with pre-existing .git directories.
 */
class GitTrackerManagerTest extends TestCase {

    private Git_Tracker_Manager $manager;
    private string $plugin1_dir;
    private string $plugin2_dir;
    private string $theme_dir;

    protected function setUp(): void {
        $this->manager = new Git_Tracker_Manager();

        $this->plugin1_dir = WP_PLUGIN_DIR . '/test-plugin-1';
        $this->plugin2_dir = WP_PLUGIN_DIR . '/test-plugin-2';
        $this->theme_dir = get_theme_root() . '/test-theme';

        // Create test directories
        foreach ([$this->plugin1_dir, $this->plugin2_dir, $this->theme_dir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    protected function tearDown(): void {
        foreach ([$this->plugin1_dir, $this->plugin2_dir, $this->theme_dir] as $dir) {
            $this->recursiveDelete($dir);
        }
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createGitDir(string $plugin_dir, bool $with_ai_changes = false): void {
        $git_dir = $plugin_dir . '/.git';
        mkdir($git_dir, 0755, true);
        mkdir($git_dir . '/objects', 0755, true);
        mkdir($git_dir . '/refs/heads', 0755, true);
        file_put_contents($git_dir . '/HEAD', "ref: refs/heads/main\n");

        if ($with_ai_changes) {
            file_put_contents($git_dir . '/refs/heads/ai-changes', "abc123\n");
        }
    }

    // -------------------------------------------------------------------------
    // Discovery tests
    // -------------------------------------------------------------------------

    public function test_get_active_trackers_ignores_plugin_without_git(): void {
        // Plugin without .git should not be discovered
        $trackers = $this->manager->get_active_trackers();

        $this->assertArrayNotHasKey($this->plugin1_dir, $trackers);
    }

    public function test_get_active_trackers_ignores_plugin_with_git_but_no_ai_changes(): void {
        // Plugin with .git but no ai-changes branch (like from Playground)
        $this->createGitDir($this->plugin1_dir, false);

        $trackers = $this->manager->get_active_trackers();

        $this->assertArrayNotHasKey($this->plugin1_dir, $trackers);
    }

    public function test_get_active_trackers_includes_plugin_with_ai_changes_branch(): void {
        // Plugin with ai-changes branch should be included
        $this->createGitDir($this->plugin1_dir, true);

        $trackers = $this->manager->get_active_trackers();

        $this->assertArrayHasKey($this->plugin1_dir, $trackers);
    }

    public function test_get_active_trackers_discovers_multiple_plugins(): void {
        $this->createGitDir($this->plugin1_dir, true);
        $this->createGitDir($this->plugin2_dir, true);

        $trackers = $this->manager->get_active_trackers();

        $this->assertArrayHasKey($this->plugin1_dir, $trackers);
        $this->assertArrayHasKey($this->plugin2_dir, $trackers);
    }

    public function test_get_active_trackers_discovers_themes(): void {
        $this->createGitDir($this->theme_dir, true);

        $trackers = $this->manager->get_active_trackers();

        $this->assertArrayHasKey($this->theme_dir, $trackers);
    }

    public function test_mixed_plugins_only_ai_changes_ones_are_active(): void {
        // Plugin 1: has .git with ai-changes (should appear)
        $this->createGitDir($this->plugin1_dir, true);

        // Plugin 2: has .git without ai-changes (should NOT appear)
        $this->createGitDir($this->plugin2_dir, false);

        $trackers = $this->manager->get_active_trackers();

        $this->assertArrayHasKey($this->plugin1_dir, $trackers);
        $this->assertArrayNotHasKey($this->plugin2_dir, $trackers);
    }

    // -------------------------------------------------------------------------
    // Broken .git directory tests
    // -------------------------------------------------------------------------

    public function test_broken_git_missing_refs_directory(): void {
        $git_dir = $this->plugin1_dir . '/.git';
        mkdir($git_dir, 0755, true);
        // Don't create refs/heads - simulating corrupt/incomplete git

        $trackers = $this->manager->get_active_trackers();

        // Should not crash, should not include this plugin
        $this->assertArrayNotHasKey($this->plugin1_dir, $trackers);
    }

    public function test_broken_git_empty_git_directory(): void {
        $git_dir = $this->plugin1_dir . '/.git';
        mkdir($git_dir, 0755, true);
        // Completely empty .git

        $trackers = $this->manager->get_active_trackers();

        $this->assertArrayNotHasKey($this->plugin1_dir, $trackers);
    }

    public function test_broken_git_ai_changes_ref_is_empty_file(): void {
        $git_dir = $this->plugin1_dir . '/.git';
        mkdir($git_dir, 0755, true);
        mkdir($git_dir . '/refs/heads', 0755, true);
        file_put_contents($git_dir . '/refs/heads/ai-changes', '');

        $trackers = $this->manager->get_active_trackers();

        // Still has ai-changes file, so will be discovered
        $this->assertArrayHasKey($this->plugin1_dir, $trackers);
    }

    // -------------------------------------------------------------------------
    // Track change with pre-existing git tests
    // -------------------------------------------------------------------------

    public function test_track_change_on_plugin_with_preexisting_git(): void {
        $this->createGitDir($this->plugin1_dir, false);

        // Before tracking: no ai-changes, so not active
        $trackers = $this->manager->get_active_trackers();
        $this->assertArrayNotHasKey($this->plugin1_dir, $trackers);

        // Track a change
        $test_file = $this->plugin1_dir . '/test.txt';
        file_put_contents($test_file, 'modified');

        $this->manager->track_change('plugins/test-plugin-1/test.txt', 'modified', 'original', 'Test');

        // After tracking: should have ai-changes and be active
        $manager2 = new Git_Tracker_Manager(); // fresh instance to re-discover
        $trackers = $manager2->get_active_trackers();
        $this->assertArrayHasKey($this->plugin1_dir, $trackers);
    }

    public function test_has_changes_returns_false_for_git_without_ai_changes(): void {
        $this->createGitDir($this->plugin1_dir, false);

        $this->assertFalse($this->manager->has_changes());
    }

    public function test_has_changes_returns_true_after_tracking(): void {
        $test_file = $this->plugin1_dir . '/test.txt';
        file_put_contents($test_file, 'modified');

        $this->manager->track_change('plugins/test-plugin-1/test.txt', 'modified', 'original', 'Test');

        $this->assertTrue($this->manager->has_changes());
    }

    public function test_get_ai_changes_metadata_for_path_requires_ai_changes_branch(): void {
        $this->createGitDir($this->plugin1_dir, false);

        $this->assertNull($this->manager->get_ai_changes_metadata_for_path('plugins/test-plugin-1/test.txt'));
    }

    public function test_get_ai_changes_metadata_for_path_returns_root_and_url(): void {
        $test_file = $this->plugin1_dir . '/test.txt';
        file_put_contents($test_file, 'modified');

        $this->manager->track_change('plugins/test-plugin-1/test.txt', 'modified', 'original', 'Test');

        $metadata = $this->manager->get_ai_changes_metadata_for_path('plugins/test-plugin-1/test.txt');

        $this->assertIsArray($metadata);
        $this->assertSame('plugins/test-plugin-1', $metadata['root']);
        $this->assertSame('plugin', $metadata['type']);
        $this->assertSame(
            'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Ftest-plugin-1',
            $metadata['url']
        );
    }

    public function test_created_file_content_helpers_use_wp_content_relative_paths(): void {
        $test_file = $this->plugin1_dir . '/new.txt';
        file_put_contents($test_file, "created content\n");

        $this->manager->track_change('plugins/test-plugin-1/new.txt', 'created', null, 'Created file');

        $this->assertTrue($this->manager->is_created_file('plugins/test-plugin-1/new.txt'));
        $this->assertFalse($this->manager->is_created_file('plugins/test-plugin-1/missing.txt'));
        $this->assertSame("created content\n", $this->manager->get_current_content('plugins/test-plugin-1/new.txt'));
    }

    public function test_update_commit_message_updates_plugin_commit(): void {
        $test_file = $this->plugin1_dir . '/test.txt';
        file_put_contents($test_file, 'modified');

        $this->manager->track_change('plugins/test-plugin-1/test.txt', 'modified', 'original', 'Old text');

        $changes = $this->manager->get_all_changes_by_plugin();
        $old_sha = $changes['plugins/test-plugin-1']['commits'][0]['sha'];

        $result = $this->manager->update_commit_message('plugins/test-plugin-1', $old_sha, 'New text');

        $this->assertTrue($result['success']);

        $updated_changes = $this->manager->get_all_changes_by_plugin();
        $this->assertSame('New text', $updated_changes['plugins/test-plugin-1']['commits'][0]['message']);
        $this->assertNotSame($old_sha, $updated_changes['plugins/test-plugin-1']['commits'][0]['sha']);
    }

    // -------------------------------------------------------------------------
    // Path resolution tests
    // -------------------------------------------------------------------------

    public function test_get_tracker_for_path_returns_correct_tracker(): void {
        $tracker = $this->manager->get_tracker_for_path('plugins/test-plugin-1/includes/file.php');

        $this->assertNotNull($tracker);
        $this->assertEquals($this->plugin1_dir, $tracker->get_work_tree());
    }

    public function test_get_tracker_for_theme_path(): void {
        $tracker = $this->manager->get_tracker_for_path('themes/test-theme/style.css');

        $this->assertNotNull($tracker);
        $this->assertEquals($this->theme_dir, $tracker->get_work_tree());
    }

    public function test_get_tracker_returns_null_for_invalid_path(): void {
        $tracker = $this->manager->get_tracker_for_path('invalid/path.php');

        $this->assertNull($tracker);
    }

    public function test_get_tracker_returns_null_for_root_file(): void {
        $tracker = $this->manager->get_tracker_for_path('plugins');

        $this->assertNull($tracker);
    }
}
