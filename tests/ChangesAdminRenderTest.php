<?php
namespace AI_Assistant\Tests;

require_once dirname(__DIR__) . '/includes/class-changes-admin.php';

use AI_Assistant\Changes_Admin;
use AI_Assistant\Git_Tracker_Manager;
use PHPUnit\Framework\TestCase;

class ChangesAdminRenderTest extends TestCase {

    private array $previous_get;

    protected function setUp(): void {
        $this->previous_get = $_GET;
    }

    protected function tearDown(): void {
        $_GET = $this->previous_get;
    }

    public function test_overview_links_to_plugin_pages_without_rendering_file_details_or_paths(): void {
        $_GET = [];

        $html = $this->render_admin($this->plugin_fixture());

        $this->assertStringContainsString('class="ai-plugin-index"', $html);
        $this->assertStringContainsString('Alpha Plugin', $html);
        $this->assertStringContainsString('plugin=plugins%2Falpha', $html);
        $this->assertStringContainsString('Review Changes', $html);
        $this->assertStringNotContainsString('<span class="ai-plugin-path">plugins/alpha/</span>', $html);
        $this->assertStringNotContainsString('id="ai-clear-history"', $html);
        $this->assertStringNotContainsString('Clear History', $html);
        $this->assertStringNotContainsString('alpha.php', $html);
        $this->assertStringNotContainsString('class="ai-plugin-card ai-plugin-card-detail"', $html);
    }

    public function test_plugin_parameter_renders_only_the_selected_plugin_detail(): void {
        $_GET = ['plugin' => 'plugins/alpha'];

        $html = $this->render_admin($this->plugin_fixture());

        $this->assertStringContainsString('AI Changes: Alpha Plugin', $html);
        $this->assertStringContainsString('All plugins', $html);
        $this->assertStringContainsString('class="ai-plugin-card ai-plugin-card-detail"', $html);
        $this->assertStringContainsString('plugins/alpha/', $html);
        $this->assertStringContainsString('alpha.php', $html);
        $this->assertStringNotContainsString('ai-plugin-checkbox', $html);
        $this->assertStringNotContainsString('ai-file-checkbox', $html);
        $this->assertStringNotContainsString('id="ai-select-all"', $html);
        $this->assertStringNotContainsString('id="ai-clear-selection"', $html);
        $this->assertStringNotContainsString('ai-revert-file', $html);
        $this->assertStringNotContainsString('ai-reapply-file', $html);
        $this->assertStringNotContainsString('ai-revert-plugin', $html);
        $this->assertStringNotContainsString('id="ai-diff-preview"', $html);
        $this->assertStringNotContainsString('Beta Plugin', $html);
        $this->assertStringNotContainsString('beta.php', $html);
    }

    public function test_unknown_plugin_parameter_keeps_overview_with_warning(): void {
        $_GET = ['plugin' => 'plugins/missing'];

        $html = $this->render_admin($this->plugin_fixture());

        $this->assertStringContainsString('No AI changes found for plugins/missing.', $html);
        $this->assertStringContainsString('class="ai-plugin-index"', $html);
        $this->assertStringContainsString('Alpha Plugin', $html);
        $this->assertStringNotContainsString('alpha.php', $html);
    }

    public function test_external_git_overview_uses_lightweight_history_card(): void {
        $_GET = [];

        $html = $this->render_admin($this->external_git_fixture());

        $this->assertStringContainsString('External Plugin', $html);
        $this->assertStringContainsString('External Git', $html);
        $this->assertStringContainsString('Review History', $html);
        $this->assertStringContainsString('history on detail page', $html);
        $this->assertStringNotContainsString('Download ZIP', $html);
        $this->assertStringNotContainsString('external.php', $html);
    }

    public function test_external_git_detail_renders_history_without_file_actions(): void {
        $_GET = ['plugin' => 'plugins/external'];

        $html = $this->render_admin($this->external_git_fixture(true));

        $this->assertStringContainsString('AI Changes: External Plugin', $html);
        $this->assertStringContainsString('External Git', $html);
        $this->assertStringContainsString('12 tracked files, 1 commit', $html);
        $this->assertStringContainsString('main', $html);
        $this->assertStringContainsString('Git debug', $html);
        $this->assertStringContainsString('This repository was not created by AI Assistant.', $html);
        $this->assertStringNotContainsString('Download ZIP', $html);
        $this->assertStringNotContainsString('Changed Files', $html);
        $this->assertStringNotContainsString('ai-file-preview-toggle', $html);
    }

    private function render_admin(array $plugins): string {
        $admin = new Changes_Admin(new class($plugins) extends Git_Tracker_Manager {
            private array $plugins;

            public function __construct(array $plugins) {
                $this->plugins = $plugins;
            }

            public function get_all_repositories_for_changes_page(string $detail_path = ''): array {
                return $this->plugins;
            }
        });

        ob_start();
        $admin->render_page();
        return ob_get_clean();
    }

    private function plugin_fixture(): array {
        return [
            'plugins/alpha' => [
                'name' => 'Alpha Plugin',
                'file_count' => 1,
                'commit_count' => 1,
                'commits' => [],
                'files' => [
                    [
                        'path' => 'plugins/alpha/alpha.php',
                        'relative_path' => 'alpha.php',
                        'change_type' => 'modified',
                        'is_reverted' => false,
                    ],
                ],
            ],
            'plugins/beta' => [
                'name' => 'Beta Plugin',
                'file_count' => 1,
                'commit_count' => 1,
                'commits' => [],
                'files' => [
                    [
                        'path' => 'plugins/beta/beta.php',
                        'relative_path' => 'beta.php',
                        'change_type' => 'created',
                        'is_reverted' => false,
                    ],
                ],
            ],
        ];
    }

    private function external_git_fixture(bool $detail = false): array {
        return [
            'plugins/external' => [
                'name' => 'External Plugin',
                'file_count' => 0,
                'tracked_file_count' => $detail ? 12 : null,
                'commit_count' => $detail ? 1 : null,
                'commits' => $detail ? [
                    [
                        'sha' => '1234567890abcdef',
                        'short_sha' => '1234567',
                        'message' => 'Initial external commit',
                        'conversation_id' => null,
                        'timestamp' => time(),
                        'date' => '2026-05-26 12:00:00',
                        'is_latest' => true,
                        'is_checked_out' => false,
                        'refs' => ['HEAD', 'main'],
                    ],
                ] : [],
                'files' => $detail ? [
                    [
                        'path' => 'plugins/external/external.php',
                        'relative_path' => 'external.php',
                        'change_type' => 'modified',
                        'is_reverted' => false,
                    ],
                ] : [],
                'checked_out_sha' => null,
                'is_external_git' => true,
                'supports_file_actions' => false,
                'status_message' => null,
                'debug' => $detail ? ['debug_version' => 'test'] : [],
            ],
        ];
    }
}
