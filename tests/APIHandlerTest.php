<?php
namespace AI_Assistant\Tests;

use AI_Assistant\API_Handler;
use AI_Assistant\Tools;
use PHPUnit\Framework\TestCase;

class APIHandlerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_test_capabilities'] = ['ai_assistant_full' => true];
        $GLOBALS['wp_test_json_response'] = null;
        $GLOBALS['wp_test_abilities'] = [];
        $GLOBALS['wp_test_options'] = [];
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

    public function test_handle_get_ability_details_returns_registered_ability(): void {
        $_POST = [
            'ability' => 'demo/write',
        ];
        $GLOBALS['wp_test_options']['ai_assistant_auto_approved_abilities'] = ['demo/write'];
        $GLOBALS['wp_test_abilities']['demo/write'] = [
            'label' => 'Demo Write',
            'description' => 'Writes demo data',
            'category' => 'demo',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Title to write',
                    ],
                ],
                'required' => ['title'],
            ],
        ];

        $handler = new API_Handler(new Tools(), new class {
            public function execute_tool($tool_name, array $arguments, string $permission = 'full', ?int $conversation_id = null) {
                throw new \RuntimeException('Executor should not be called');
            }
        });

        try {
            $handler->handle_get_ability_details();
            $this->fail('Expected wp_send_json_success to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_success', $e->getMessage());
        }

        $this->assertTrue($GLOBALS['wp_test_json_response']['success']);
        $data = $GLOBALS['wp_test_json_response']['data'];
        $this->assertSame('demo/write', $data['id']);
        $this->assertSame('Demo Write', $data['label']);
        $this->assertTrue($data['approved']);
        $this->assertTrue($data['has_schema']);
        $this->assertSame('title', $data['parameters'][0]['name']);
        $this->assertTrue($data['parameters'][0]['required']);
    }

    public function test_handle_get_ability_details_rejects_missing_ability(): void {
        $_POST = [
            'ability' => 'demo/missing',
        ];

        $handler = new API_Handler(new Tools(), new class {
            public function execute_tool($tool_name, array $arguments, string $permission = 'full', ?int $conversation_id = null) {
                throw new \RuntimeException('Executor should not be called');
            }
        });

        try {
            $handler->handle_get_ability_details();
            $this->fail('Expected wp_send_json_error to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_error', $e->getMessage());
        }

        $this->assertFalse($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(404, $GLOBALS['wp_test_json_response']['status_code']);
        $this->assertSame('ability_not_found', $GLOBALS['wp_test_json_response']['data']['code']);
        $this->assertSame(
            'Ability not found: demo/missing',
            $GLOBALS['wp_test_json_response']['data']['message']
        );
    }
}
