<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Wp_App_Abilities;
use AI_Assistant\Git_Tracker_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ai/convert-to-wp-app ability.
 */
class ConvertToWpAppAbilityTest extends TestCase {

    private string $plugins_dir;

    private const INDEX_HTML = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Counter</title>
<link rel="stylesheet" href="css/style.css">
<script src="https://cdn.example.com/lib.js"></script>
</head>
<body>
<main id="app"><button>+1</button></main>
<img src="./logo.png" alt="">
<script src="app.js"></script>
</body>
</html>
HTML;

    protected function setUp(): void {
        $this->plugins_dir = WP_PLUGIN_DIR;
        $this->removeDirectory($this->plugins_dir . '/counter-mywp');
        $GLOBALS['wp_test_capabilities'] = [];
        unset($GLOBALS['wp_test_activate_plugin_result']);
        if (!is_dir(dirname(__DIR__) . '/vendor/akirk/wp-app')) {
            $this->markTestSkipped('akirk/wp-app is not installed.');
        }
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->plugins_dir . '/counter-mywp');
        unset($GLOBALS['wp_test_activate_plugin_result']);
    }

    public function test_convert_app_writes_self_contained_plugin(): void {
        $abilities = new Wp_App_Abilities();
        $result = $abilities->convert_app([
            'slug'       => 'counter',
            'index_html' => self::INDEX_HTML,
            'files'      => [
                'app.js'        => "document.querySelector('button').onclick = () => {};",
                'css/style.css' => 'body { margin: 0; }',
                'logo.png'      => ['base64' => base64_encode("\x89PNG\r\n")],
            ],
            'activate'   => false,
        ]);

        $this->assertIsArray($result, is_object($result) ? $result->get_error_message() : '');
        $this->assertSame('counter-mywp', $result['plugin_slug']);
        $this->assertSame('http://localhost/counter-mywp/', $result['url']);
        $this->assertFalse($result['activated']);

        $dir = $this->plugins_dir . '/counter-mywp';
        $this->assertFileExists($dir . '/counter-mywp.php');
        $this->assertFileExists($dir . '/vendor/autoload.php');
        $this->assertFileExists($dir . '/vendor/akirk/wp-app/src/class-wpapp.php');
        $this->assertFileExists($dir . '/app/index.html');
        $this->assertFileExists($dir . '/app/app.js');
        $this->assertFileExists($dir . '/app/css/style.css');
        $this->assertSame("\x89PNG\r\n", file_get_contents($dir . '/app/logo.png'));

        $plugin = file_get_contents($dir . '/counter-mywp.php');
        $this->assertStringContainsString('Plugin Name: Counter', $plugin);
        $this->assertStringContainsString("'counter-mywp'", $plugin);

        $template = file_get_contents($dir . '/templates/index.php');
        $this->assertStringContainsString("<title><?php wp_app_the_title( 'Counter' ); ?></title>", $template);
        $this->assertStringNotContainsString('<title>Counter</title>', $template);
        $this->assertStringContainsString("\$asset_url( 'app.js' )", $template);
        $this->assertStringContainsString("\$asset_url( 'css/style.css' )", $template);
        $this->assertStringContainsString("\$asset_url( 'logo.png' )", $template);
        $this->assertStringContainsString('https://cdn.example.com/lib.js', $template);
        $this->assertStringContainsString('<?php wp_app_head(); ?>', $template);
        $this->assertStringContainsString('<?php wp_app_body_open(); ?>', $template);
        $this->assertStringContainsString('<main id="app"><button>+1</button></main>', $template);

        $this->assertContains('templates/index.php', $result['created_files']);
        $this->assertContains('app/app.js', $result['created_files']);
        $this->assertCount(0, glob(sys_get_temp_dir() . '/convert-to-wp-app-counter-mywp-*'));
    }

    public function test_convert_app_rejects_missing_or_partial_html(): void {
        $abilities = new Wp_App_Abilities();

        $result = $abilities->convert_app(['slug' => 'counter', 'activate' => false]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_index_html', $result->get_error_code());

        $result = $abilities->convert_app(['slug' => 'counter', 'index_html' => '<div>fragment</div>', 'activate' => false]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_index_html', $result->get_error_code());
        $this->assertDirectoryDoesNotExist($this->plugins_dir . '/counter-mywp');
    }

    public function test_convert_app_rejects_bundler_dev_entry(): void {
        $abilities = new Wp_App_Abilities();
        $result = $abilities->convert_app([
            'slug'       => 'counter',
            'index_html' => '<html><head></head><body><script type="module" src="/src/main.tsx"></script></body></html>',
            'activate'   => false,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_deployable', $result->get_error_code());
        $this->assertDirectoryDoesNotExist($this->plugins_dir . '/counter-mywp');
    }

    public function test_convert_app_rejects_path_traversal_in_files(): void {
        $abilities = new Wp_App_Abilities();
        foreach (['../evil.php', '/etc/passwd', '.git/config', 'index.html', 'a/../b.js'] as $path) {
            $result = $abilities->convert_app([
                'slug'       => 'counter',
                'index_html' => self::INDEX_HTML,
                'files'      => [$path => 'x'],
                'activate'   => false,
            ]);
            $this->assertInstanceOf(\WP_Error::class, $result, $path);
            $this->assertSame('invalid_file_path', $result->get_error_code(), $path);
        }
        $this->assertDirectoryDoesNotExist($this->plugins_dir . '/counter-mywp');
    }

    public function test_existing_plugin_requires_overwrite(): void {
        mkdir($this->plugins_dir . '/counter-mywp', 0755, true);
        file_put_contents($this->plugins_dir . '/counter-mywp/stale.txt', 'old');

        $abilities = new Wp_App_Abilities();
        $result = $abilities->convert_app(['slug' => 'counter', 'index_html' => self::INDEX_HTML, 'activate' => false]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('plugin_exists', $result->get_error_code());

        $result = $abilities->convert_app(['slug' => 'counter', 'index_html' => self::INDEX_HTML, 'activate' => false, 'overwrite' => true]);
        $this->assertIsArray($result);
        $this->assertFileDoesNotExist($this->plugins_dir . '/counter-mywp/stale.txt');
        $this->assertFileExists($this->plugins_dir . '/counter-mywp/counter-mywp.php');
    }

    public function test_convert_app_tracks_created_files(): void {
        $tracker_manager = new Git_Tracker_Manager();
        $abilities = new Wp_App_Abilities($tracker_manager);
        $result = $abilities->convert_app([
            'slug'        => 'counter',
            'plugin_name' => 'Counter',
            'index_html'  => self::INDEX_HTML,
            'files'       => ['app.js' => '// js'],
            'activate'    => false,
        ]);

        $this->assertIsArray($result);
        $changes = $tracker_manager->get_all_changes_by_plugin();
        $this->assertArrayHasKey('plugins/counter-mywp', $changes);
        $paths = array_column($changes['plugins/counter-mywp']['files'], 'path');
        $this->assertContains('plugins/counter-mywp/templates/index.php', $paths);
        $this->assertContains('plugins/counter-mywp/app/app.js', $paths);
        $this->assertSame('Convert Counter into a WpApp plugin', $changes['plugins/counter-mywp']['commits'][0]['message']);
    }

    public function test_convert_app_reports_failed_activation(): void {
        $GLOBALS['wp_test_activate_plugin_result'] = new \WP_Error('sandbox_failed', 'Fatal error during sandboxed activation.');

        $abilities = new Wp_App_Abilities();
        $result = $abilities->convert_app(['slug' => 'counter', 'index_html' => self::INDEX_HTML]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('activation_failed', $result->get_error_code());
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
