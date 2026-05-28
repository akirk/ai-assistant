<?php
namespace AI_Assistant\Tests;

require_once dirname(__DIR__) . '/includes/class-changes-admin.php';

use AI_Assistant\Changes_Admin;
use AI_Assistant\Git_Tracker_Manager;
use PHPUnit\Framework\TestCase;

class ChangesAdminRenderTest extends TestCase {

    private array $previous_get;
    private array $previous_post;

    protected function setUp(): void {
        $this->previous_get = $_GET;
        $this->previous_post = $_POST;
    }

    protected function tearDown(): void {
        $_GET = $this->previous_get;
        $_POST = $this->previous_post;
    }

    public function test_overview_links_to_plugin_pages_without_rendering_file_details_or_paths(): void {
        $_GET = [];

        $html = $this->render_admin($this->plugin_fixture());

        $this->assertStringContainsString('class="ai-plugin-index"', $html);
        $this->assertStringNotContainsString('ai-changes-wrap-detail', $html);
        $this->assertStringContainsString('Alpha Plugin', $html);
        $this->assertStringContainsString('plugin=plugins%2Falpha', $html);
        $this->assertStringContainsString('Review Changes', $html);
        $this->assertStringNotContainsString('<span class="ai-plugin-path">plugins/alpha/</span>', $html);
        $this->assertStringNotContainsString('id="ai-clear-history"', $html);
        $this->assertStringNotContainsString('Clear History', $html);
        $this->assertStringNotContainsString('alpha.php', $html);
        $this->assertStringNotContainsString('class="ai-changes-plugin-detail"', $html);
    }

    public function test_plugin_parameter_renders_only_the_selected_plugin_detail(): void {
        $_GET = ['plugin' => 'plugins/alpha'];

        $html = $this->render_admin($this->plugin_fixture());

        $this->assertStringContainsString('AI Changes: Alpha Plugin', $html);
        $this->assertStringContainsString('ai-changes-wrap-detail', $html);
        $this->assertStringContainsString('All plugins', $html);
        $this->assertStringContainsString('Download ZIP', $html);
        $this->assertStringContainsString('class="ai-changes-plugin-detail"', $html);
        $this->assertStringContainsString('class="ai-plugin-files ai-changes-panel"', $html);
        $this->assertStringContainsString('alpha.php', $html);
        $this->assertStringContainsString('class="button button-small ai-lint-files"', $html);
        $this->assertStringContainsString('Check PHP syntax', $html);
        $this->assertStringNotContainsString('class="ai-plugin-detail-bar"', $html);
        $this->assertStringNotContainsString('class="ai-plugin-detail-path"', $html);
        $this->assertStringNotContainsString('<span class="ai-plugin-detail-path">plugins/alpha/</span>', $html);
        $this->assertStringNotContainsString('class="ai-plugin-header"', $html);
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

    public function test_plugin_detail_renders_commit_message_edit_button(): void {
        $_GET = ['plugin' => 'plugins/alpha'];

        $fixture = $this->plugin_fixture();
        $fixture['plugins/alpha']['commits'] = [
            [
                'sha' => '1111111111111111111111111111111111111111',
                'short_sha' => '1111111',
                'message' => 'Old commit text',
                'conversation_id' => null,
                'timestamp' => time(),
                'date' => date('Y-m-d H:i:s'),
                'is_latest' => true,
                'is_checked_out' => false,
            ],
        ];

        $html = $this->render_admin($fixture);

        $this->assertStringContainsString('class="button-link ai-edit-commit-message"', $html);
        $this->assertStringContainsString('data-sha="1111111111111111111111111111111111111111"', $html);
        $this->assertStringContainsString('class="regular-text ai-commit-message-input"', $html);
        $this->assertStringContainsString('value="Old commit text"', $html);
        $this->assertStringContainsString('Double-click to edit commit message', $html);
        $this->assertStringContainsString('Edit commit message', $html);
    }

    public function test_plugin_detail_renders_combine_button_inside_commit_message_editor(): void {
        $_GET = ['plugin' => 'plugins/alpha'];

        $fixture = $this->plugin_fixture();
        $fixture['plugins/alpha']['commits'] = [
            [
                'sha' => '2222222222222222222222222222222222222222',
                'short_sha' => '2222222',
                'parent' => '1111111111111111111111111111111111111111',
                'message' => 'Second commit',
                'conversation_id' => null,
                'timestamp' => time(),
                'date' => date('Y-m-d H:i:s'),
                'is_latest' => true,
                'is_checked_out' => false,
                'can_combine_with_parent' => true,
            ],
            [
                'sha' => '1111111111111111111111111111111111111111',
                'short_sha' => '1111111',
                'parent' => null,
                'message' => 'First commit',
                'conversation_id' => null,
                'timestamp' => time(),
                'date' => date('Y-m-d H:i:s'),
                'is_latest' => false,
                'is_checked_out' => false,
                'can_combine_with_parent' => false,
            ],
        ];

        $html = $this->render_admin($fixture);

        $this->assertStringContainsString('class="ai-combine-commit-option"', $html);
        $this->assertStringContainsString('class="ai-combine-commit-checkbox"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('data-sha="2222222222222222222222222222222222222222"', $html);
        $this->assertStringContainsString('Combine this commit with the commit below it when saving', $html);
        $this->assertStringContainsString('Combine this commit with the previous commit (1111111) and use this message as the new commit message.', $html);
        $this->assertStringContainsString('class="button-link ai-use-previous-commit-message"', $html);
        $this->assertStringContainsString('data-message="First commit"', $html);
        $this->assertStringContainsString('Use previous message', $html);
        $this->assertStringNotContainsString('dashicons-arrow-down-alt', $html);
    }

    public function test_plugin_detail_renders_commits_before_files_in_separate_panels(): void {
        $_GET = ['plugin' => 'plugins/alpha'];

        $fixture = $this->plugin_fixture();
        $fixture['plugins/alpha']['commits'] = [
            [
                'sha' => '1111111111111111111111111111111111111111',
                'short_sha' => '1111111',
                'message' => 'Tracked change',
                'conversation_id' => null,
                'timestamp' => time(),
                'date' => date('Y-m-d H:i:s'),
                'is_latest' => true,
                'is_checked_out' => false,
            ],
        ];

        $html = $this->render_admin($fixture);

        $this->assertStringContainsString('class="ai-plugin-files ai-changes-panel"', $html);
        $this->assertStringContainsString('class="ai-plugin-commits ai-changes-panel"', $html);
        $this->assertLessThan(
            strpos($html, 'class="ai-plugin-files ai-changes-panel"'),
            strpos($html, 'class="ai-plugin-commits ai-changes-panel"')
        );
    }

    public function test_plugin_detail_renders_multiple_change_badges_for_one_file(): void {
        $_GET = ['plugin' => 'plugins/alpha'];

        $fixture = $this->plugin_fixture();
        $fixture['plugins/alpha']['files'][0]['change_type'] = 'created';
        $fixture['plugins/alpha']['files'][0]['change_types'] = ['created', 'modified'];

        $html = $this->render_admin($fixture);

        $this->assertStringContainsString('class="ai-changes-file-badges"', $html);
        $this->assertStringContainsString('ai-changes-type-created', $html);
        $this->assertStringContainsString('ai-changes-type-modified', $html);
        $this->assertStringContainsString('data-preview-type="content"', $html);
        $this->assertStringContainsString('Created', $html);
        $this->assertStringContainsString('Changed', $html);
    }

    public function test_unknown_plugin_parameter_keeps_overview_with_warning(): void {
        $_GET = ['plugin' => 'plugins/missing'];

        $html = $this->render_admin($this->plugin_fixture());

        $this->assertStringContainsString('No AI changes found for plugins/missing.', $html);
        $this->assertStringContainsString('class="ai-plugin-index"', $html);
        $this->assertStringContainsString('Alpha Plugin', $html);
        $this->assertStringNotContainsString('alpha.php', $html);
    }

    public function test_ajax_generate_diff_returns_content_for_created_file_preview(): void {
        $_POST = ['file_paths' => ['plugins/alpha/new.php']];

        $admin = new Changes_Admin(new class extends Git_Tracker_Manager {
            public function is_created_file(string $path): bool {
                return $path === 'plugins/alpha/new.php';
            }

            public function get_current_content(string $path): ?string {
                return "<?php\n// created\n";
            }

            public function generate_diff(array $file_paths): string {
                return 'unexpected diff';
            }
        });

        try {
            $admin->ajax_generate_diff();
            $this->fail('Expected wp_send_json_success to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_success', $e->getMessage());
        }

        $this->assertTrue($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame('content', $GLOBALS['wp_test_json_response']['data']['type']);
        $this->assertSame("<?php\n// created\n", $GLOBALS['wp_test_json_response']['data']['content']);
        $this->assertSame('plugins/alpha/new.php', $GLOBALS['wp_test_json_response']['data']['path']);
    }

    public function test_ajax_get_file_content_returns_created_file_content(): void {
        $_POST = ['file_path' => 'plugins/alpha/new.json'];

        $admin = new Changes_Admin(new class extends Git_Tracker_Manager {
            public function is_created_file(string $path): bool {
                return $path === 'plugins/alpha/new.json';
            }

            public function get_current_content(string $path): ?string {
                return '{"ok":true}';
            }
        });

        try {
            $admin->ajax_get_file_content();
            $this->fail('Expected wp_send_json_success to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_success', $e->getMessage());
        }

        $this->assertTrue($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame('content', $GLOBALS['wp_test_json_response']['data']['type']);
        $this->assertSame('{"ok":true}', $GLOBALS['wp_test_json_response']['data']['content']);
        $this->assertSame('plugins/alpha/new.json', $GLOBALS['wp_test_json_response']['data']['path']);
    }

    public function test_ajax_combine_commit_passes_edited_message(): void {
        $_POST = [
            'plugin_path' => 'plugins/alpha',
            'sha' => '2222222222222222222222222222222222222222',
            'message' => 'Edited combined message',
        ];

        $manager = new class extends Git_Tracker_Manager {
            public ?string $plugin_path = null;
            public ?string $sha = null;
            public ?string $message = null;

            public function combine_commit_with_parent(string $plugin_path, string $sha, ?string $message = null): array {
                $this->plugin_path = $plugin_path;
                $this->sha = $sha;
                $this->message = $message;

                return [
                    'success' => true,
                    'old_sha' => $sha,
                    'parent_sha' => '1111111111111111111111111111111111111111',
                    'new_sha' => '3333333333333333333333333333333333333333',
                    'head_sha' => '3333333333333333333333333333333333333333',
                    'errors' => [],
                ];
            }
        };

        $admin = new Changes_Admin($manager);

        try {
            $admin->ajax_combine_commit();
            $this->fail('Expected wp_send_json_success to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_success', $e->getMessage());
        }

        $this->assertTrue($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame('plugins/alpha', $manager->plugin_path);
        $this->assertSame('2222222222222222222222222222222222222222', $manager->sha);
        $this->assertSame('Edited combined message', $manager->message);
    }

    private function render_admin(array $plugins): string {
        $admin = new Changes_Admin(new class($plugins) extends Git_Tracker_Manager {
            private array $plugins;

            public function __construct(array $plugins) {
                $this->plugins = $plugins;
            }

            public function get_all_changes_by_plugin(): array {
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
}
