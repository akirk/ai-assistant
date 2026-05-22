<?php
namespace AI_Assistant\Tests;

use AI_Assistant\LLM_Proxy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__) . '/includes/class-llm-proxy.php';

class LLMProxyTest extends TestCase {

    private LLM_Proxy $proxy;

    protected function setUp(): void {
        $this->proxy = new LLM_Proxy();
        $GLOBALS['wp_test_is_playground'] = false;
        $_GET = [];
        $_POST = [];
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_is_playground'] = false;
        $_GET = [];
        $_POST = [];
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
    }

    public function test_form_json_field_keeps_already_valid_json_unchanged(): void {
        $json = wp_json_encode([
            'system' => "Check for \"recipe\" posts.\nUse abilities first.",
        ]);

        $this->assertSame($json, $this->invokePrivate('normalize_form_json_field', [$json]));
    }

    public function test_form_json_field_unslashes_wordpress_slashed_json(): void {
        $json = wp_json_encode([
            'system' => "Check for \"recipe\" posts.\nUse abilities first.",
        ]);
        $slashed = addslashes($json);

        $this->assertSame($json, $this->invokePrivate('normalize_form_json_field', [$slashed]));
    }

    public function test_provider_body_encodes_json_envelope_payload(): void {
        $payload = [
            'model'    => 'claude-test',
            'messages' => [
                ['role' => 'user', 'content' => 'did any of my friends post a recipe?'],
            ],
        ];

        $raw_body = $this->invokePrivate('normalize_provider_body', [$payload]);

        $this->assertIsString($raw_body);
        $this->assertSame($payload, json_decode($raw_body, true));
    }

    public function test_proxy_input_accepts_provider_from_query_and_body_from_post(): void {
        $payload = ['model' => 'claude-test'];
        $_GET['provider'] = 'anthropic';
        $_POST['body'] = wp_json_encode($payload);

        $input = $this->invokePrivate('get_proxy_input');

        $this->assertSame('anthropic', $input['provider']);
        $this->assertSame($payload, json_decode($input['body'], true));
    }

    public function test_proxy_skips_curl_in_playground(): void {
        $GLOBALS['wp_test_is_playground'] = true;

        $this->assertFalse($this->invokePrivate('should_use_curl'));
    }

    public function test_body_description_only_previews_readable_stream_sentinel(): void {
        $stream_body = $this->invokePrivate('describe_body', ['[object ReadableStream]']);
        $json_body = $this->invokePrivate('describe_body', [wp_json_encode(['system' => 'secret'])]);

        $this->assertSame('[object ReadableStream]', $stream_body['preview']);
        $this->assertArrayNotHasKey('preview', $json_body);
        $this->assertSame(strlen(wp_json_encode(['system' => 'secret'])), $json_body['length']);
    }

    private function invokePrivate(string $method, array $args = []) {
        $reflection = new ReflectionMethod($this->proxy, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->proxy, $args);
    }
}
