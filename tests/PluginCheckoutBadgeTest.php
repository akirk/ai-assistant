<?php

use PHPUnit\Framework\TestCase;
use AI_Assistant\Git_Tracker;
use AI_Assistant\Git_Tracker_Manager;
use AI_Assistant\Plugin_Checkout_Badge;

class PluginCheckoutBadgeTest extends TestCase {

    private array $plugin_dirs = [];

    protected function tearDown(): void {
        foreach ($this->plugin_dirs as $dir) {
            $this->removeDirectory($dir);
        }

        unset($GLOBALS['hook_suffix'], $GLOBALS['wp_filter']);
        $this->plugin_dirs = [];
    }

    public function test_wp_app_template_renders_badge_for_checked_out_plugin(): void {
        [$manager, $checked_out_sha, $template_path] = $this->createCheckedOutPlugin('badge-demo');

        $badge = new Plugin_Checkout_Badge($manager);
        $badge->capture_wp_app_template($template_path);
        $metadata = $badge->get_current_ai_changes_metadata();

        ob_start();
        $badge->render_badge();
        $html = ob_get_clean();

        $this->assertSame('plugins/badge-demo', $metadata['root']);
        $this->assertSame('plugin', $metadata['type']);
        $this->assertTrue($metadata['open_in_current_window']);
        $this->assertSame('http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fbadge-demo', $metadata['url']);
        $this->assertSame(['overview'], array_column($metadata['links'], 'key'));
        $this->assertSame(['next', 'current', 'previous'], array_column($metadata['version_log'], 'key'));
        $this->assertStringContainsString('action=ai_assistant_checkout_version', $metadata['version_log'][0]['url']);
        $this->assertArrayNotHasKey('url', $metadata['version_log'][1]);
        $this->assertStringContainsString('action=ai_assistant_checkout_version', $metadata['version_log'][2]['url']);
        $this->assertStringContainsString('ai-assistant-checkout-badge', $html);
        $this->assertStringContainsString('<details class="ai-assistant-checkout-badge"', $html);
        $this->assertStringContainsString('Old Version:', $html);
        $this->assertStringContainsString('ai-assistant-checkout-badge-message">Middle checked out change message...', $html);
        $this->assertStringContainsString('just now', $html);
        $this->assertStringContainsString('Badge Demo', $html);
        $this->assertStringContainsString('Middle checked out change message with more words', $html);
        $this->assertStringContainsString('ai-assistant-checkout-badge-log', $html);
        $this->assertStringContainsString('Next', $html);
        $this->assertStringContainsString('Current', $html);
        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('Latest change message with more...', $html);
        $this->assertStringContainsString('First older change message with...', $html);
        $this->assertStringContainsString('Overview', $html);
        $this->assertStringContainsString('action=ai_assistant_checkout_version', $html);
        $this->assertStringContainsString('tools.php?page=ai-changes&plugin=plugins%2Fbadge-demo', $html);
        $this->assertStringNotContainsString('Not current', $html);
        $this->assertStringNotContainsString(' title=', $html);
        $this->assertStringNotContainsString(substr($checked_out_sha, 0, 7), $html);
        $this->assertStringContainsString('data-ai-plugin="plugins/badge-demo"', $html);
    }

    public function test_wp_app_template_does_not_render_badge_for_current_plugin(): void {
        [$manager, $template_path] = $this->createCurrentPlugin('badge-current');

        $badge = new Plugin_Checkout_Badge($manager);
        $badge->capture_wp_app_template($template_path);

        ob_start();
        $badge->render_badge();
        $html = ob_get_clean();

        $this->assertSame('', trim($html));
    }

    public function test_admin_page_callback_renders_badge_for_checked_out_plugin(): void {
        [$manager, $checked_out_sha] = $this->createCheckedOutPlugin('badge-admin');
        $callback = $this->createAdminCallback('badge-admin');

        $GLOBALS['hook_suffix'] = 'toplevel_page_badge-admin';
        $GLOBALS['wp_filter'] = [
            'toplevel_page_badge-admin' => [
                10 => [
                    'ai_assistant_test_callback' => [
                        'function' => $callback,
                        'accepted_args' => 0,
                    ],
                ],
            ],
        ];

        $badge = new Plugin_Checkout_Badge($manager);
        $metadata = $badge->get_current_ai_changes_metadata();

        ob_start();
        $badge->render_admin_badge();
        $html = ob_get_clean();

        $this->assertSame('plugins/badge-admin', $metadata['root']);
        $this->assertSame('http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fbadge-admin', $metadata['url']);
        $this->assertSame(['overview'], array_column($metadata['links'], 'key'));
        $this->assertSame(['next', 'current', 'previous'], array_column($metadata['version_log'], 'key'));
        $this->assertStringContainsString('ai-assistant-checkout-badge', $html);
        $this->assertStringContainsString('Badge Admin', $html);
        $this->assertStringContainsString('Old Version:', $html);
        $this->assertStringContainsString('Middle checked out change message with more words', $html);
        $this->assertStringContainsString('ai-assistant-checkout-badge-log', $html);
        $this->assertStringContainsString('Next', $html);
        $this->assertStringContainsString('Current', $html);
        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('Overview', $html);
        $this->assertStringContainsString('tools.php?page=ai-changes&plugin=plugins%2Fbadge-admin', $html);
        $this->assertStringNotContainsString('Not current', $html);
        $this->assertStringNotContainsString(' title=', $html);
        $this->assertStringNotContainsString(substr($checked_out_sha, 0, 7), $html);
    }

    private function createCheckedOutPlugin(string $slug): array {
        [$manager, $tracker, $template_path] = $this->createPlugin($slug);
        $main_file = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
        $relative_main_file = $slug . '.php';
        $original = $this->pluginHeader($slug) . "\n// original\n";

        file_put_contents($main_file, $this->pluginHeader($slug) . "\n// version 2\n");
        $tracker->track_change($relative_main_file, 'modified', $original, 'First older change message with more words');

        file_put_contents($main_file, $this->pluginHeader($slug) . "\n// version 3\n");
        $tracker->track_change($relative_main_file, 'modified', $original, 'Middle checked out change message with more words');
        $checked_out_sha = $tracker->get_recent_commits()[0]['sha'];

        file_put_contents($main_file, $this->pluginHeader($slug) . "\n// version 4\n");
        $tracker->track_change($relative_main_file, 'modified', $original, 'Latest change message with more words');
        $tracker->checkout_commit($checked_out_sha);

        return [$manager, $checked_out_sha, $template_path];
    }

    private function createCurrentPlugin(string $slug): array {
        [$manager, $tracker, $template_path] = $this->createPlugin($slug);
        $main_file = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
        $relative_main_file = $slug . '.php';
        $original = $this->pluginHeader($slug) . "\n// original\n";

        file_put_contents($main_file, $this->pluginHeader($slug) . "\n// current\n");
        $tracker->track_change($relative_main_file, 'modified', $original, 'Current');

        return [$manager, $template_path];
    }

    private function createPlugin(string $slug): array {
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
        $this->removeDirectory($plugin_dir);
        mkdir($plugin_dir . '/templates', 0755, true);
        $this->plugin_dirs[] = $plugin_dir;

        file_put_contents($plugin_dir . '/' . $slug . '.php', $this->pluginHeader($slug) . "\n// original\n");
        $template_path = $plugin_dir . '/templates/index.php';
        file_put_contents($template_path, '<div>App</div>');

        $tracker = new Git_Tracker($plugin_dir);
        $manager = new Git_Tracker_Manager();

        return [$manager, $tracker, $template_path];
    }

    private function createAdminCallback(string $slug): string {
        $function_name = 'ai_assistant_checkout_badge_admin_' . str_replace('.', '_', uniqid('', true));
        $file = WP_PLUGIN_DIR . '/' . $slug . '/admin-page.php';
        file_put_contents($file, "<?php\nfunction {$function_name}() {}\n");
        require $file;

        return $function_name;
    }

    private function pluginHeader(string $slug): string {
        $name = ucwords(str_replace('-', ' ', $slug));
        return "<?php\n/*\nPlugin Name: {$name}\n*/";
    }

    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
