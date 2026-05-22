<?php
namespace AI_Assistant\Tests;

use AI_Assistant\File_Tool_Auth;
use PHPUnit\Framework\TestCase;

class FileToolAuthTest extends TestCase {

    protected function setUp(): void {
        $this->deleteSecretFile();
    }

    protected function tearDown(): void {
        $this->deleteSecretFile();
    }

    public function test_create_and_validate_token_round_trip(): void {
        $token = File_Tool_Auth::create_token('full', ['read_file', 'write_file'], 123);
        $payload = File_Tool_Auth::validate_token($token);

        $this->assertSame('full', $payload['permission']);
        $this->assertSame(['read_file', 'write_file'], $payload['enabled_tools']);
        $this->assertSame(123, $payload['user_id']);
    }

    public function test_file_tool_tokens_expire_after_fifteen_minutes(): void {
        $token = File_Tool_Auth::create_token('full', ['read_file', 'write_file'], 1);
        $payload = $this->decodeTokenPayload($token);

        $this->assertSame(900, $payload['exp'] - $payload['iat']);
    }

    public function test_validate_token_rejects_tampered_payload(): void {
        $token = File_Tool_Auth::create_token('read_only', ['read_file'], 123);
        [$payload_encoded, $signature] = explode('.', $token, 2);
        $payload = $this->base64urlDecodeJson($payload_encoded);
        $payload['permission'] = 'full';

        $tampered_token = $this->base64urlEncode(json_encode($payload)) . '.' . $signature;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid file tool token signature');

        File_Tool_Auth::validate_token($tampered_token);
    }

    public function test_validate_token_rejects_expired_token(): void {
        File_Tool_Auth::create_token('read_only', ['read_file'], 123);
        $secret = include WP_CONTENT_DIR . '/.ai-assistant-file-tools-secret.php';
        $payload = [
            'version'       => 1,
            'iat'           => time() - 1200,
            'exp'           => time() - 300,
            'permission'    => 'read_only',
            'enabled_tools' => ['read_file'],
            'user_id'       => 123,
        ];
        $payload_encoded = $this->base64urlEncode(json_encode($payload));
        $signature = $this->base64urlEncode(hash_hmac('sha256', $payload_encoded, $secret, true));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File tool token has expired');

        File_Tool_Auth::validate_token($payload_encoded . '.' . $signature);
    }

    public function test_read_only_token_cannot_execute_write_file(): void {
        $token = File_Tool_Auth::create_token('read_only', ['read_file', 'write_file'], 123);
        $payload = File_Tool_Auth::validate_token($token);

        $this->assertTrue(File_Tool_Auth::can_execute_tool('read_file', ['path' => 'plugins/test.php'], $payload));
        $this->assertFalse(File_Tool_Auth::can_execute_tool('write_file', ['path' => 'plugins/test.php'], $payload));
    }

    public function test_full_token_still_requires_tool_to_be_enabled(): void {
        $token = File_Tool_Auth::create_token('full', ['read_file'], 123);
        $payload = File_Tool_Auth::validate_token($token);

        $this->assertTrue(File_Tool_Auth::can_execute_tool('read_file', ['path' => 'plugins/test.php'], $payload));
        $this->assertFalse(File_Tool_Auth::can_execute_tool('write_file', ['path' => 'plugins/test.php'], $payload));
    }

    private function decodeTokenPayload(string $token): array {
        [$payload] = explode('.', $token, 2);
        return $this->base64urlDecodeJson($payload);
    }

    private function base64urlDecodeJson(string $payload): array {
        $padding = strlen($payload) % 4;
        if ($padding) {
            $payload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        $this->assertIsString($decoded);

        $data = json_decode($decoded, true);
        $this->assertIsArray($data);

        return $data;
    }

    private function base64urlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function deleteSecretFile(): void {
        $path = WP_CONTENT_DIR . '/.ai-assistant-file-tools-secret.php';
        if (is_file($path)) {
            unlink($path);
        }
    }
}
