<?php
namespace AI_Assistant\Tests;

use AI_Assistant\API_Handler;
use AI_Assistant\Tools;
use PHPUnit\Framework\TestCase;

class APIHandlerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_test_capabilities'] = ['ai_assistant_full' => true];
        $GLOBALS['wp_test_json_response'] = null;
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
    }

    public function test_handle_execute_tool_catches_type_errors_as_json_error(): void {
        $_POST = [
            'tool' => 'ability',
            'arguments' => '{}',
            'conversation_id' => '21',
        ];

        $handler = new API_Handler(new Tools(), new class {
            public function execute_tool($tool_name, array $arguments, string $permission = 'full', ?int $conversation_id = null) {
                throw new \TypeError('Ability arguments must be an array');
            }
        });

        try {
            $handler->handle_execute_tool();
            $this->fail('Expected wp_send_json_error to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_error', $e->getMessage());
        }

        $this->assertFalse($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(
            'Ability arguments must be an array',
            $GLOBALS['wp_test_json_response']['data']['message']
        );
    }

    public function test_handle_execute_tool_rejects_non_object_arguments_json(): void {
        $_POST = [
            'tool' => 'ability',
            'arguments' => '"not an object"',
        ];

        $handler = new API_Handler(new Tools(), new class {
            public function execute_tool($tool_name, array $arguments, string $permission = 'full', ?int $conversation_id = null) {
                throw new \RuntimeException('Executor should not be called');
            }
        });

        try {
            $handler->handle_execute_tool();
            $this->fail('Expected wp_send_json_error to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_error', $e->getMessage());
        }

        $this->assertFalse($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(
            'Tool arguments must be a JSON object',
            $GLOBALS['wp_test_json_response']['data']['message']
        );
    }
}
