<?php
namespace AI_Assistant\Tests;

use AI_Assistant\API_Handler;
use AI_Assistant\Executor;
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

    public static function assistantRoles(): array {
        return [
            'full access' => [['ai_assistant_full' => true], true],
            'read-only access' => [['ai_assistant_read_only' => true], true],
            'chat-only access' => [['ai_assistant_chat_only' => true], false],
            'no assistant role' => [[], false],
        ];
    }

    /** @dataProvider assistantRoles */
    public function test_tool_api_enforces_assistant_role(array $capabilities, bool $allowed): void {
        $GLOBALS['wp_test_capabilities'] = $capabilities;
        $_POST = ['tool' => 'get_plugins', 'arguments' => '{}'];
        $tools = new Tools();
        $handler = new API_Handler($tools, new Executor($tools));

        try {
            $handler->handle_execute_tool();
            $this->fail('Expected the JSON response to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame($allowed ? 'wp_send_json_success' : 'wp_send_json_error', $e->getMessage());
        }

        $response = $GLOBALS['wp_test_json_response'];
        $this->assertSame($allowed, $response['success']);
        if ($allowed) {
            $this->assertSame('hello.php', $response['data']['plugins'][0]['file']);
            $this->assertSame('Hello Dolly', $response['data']['plugins'][0]['name']);
            $this->assertSame(2, $response['data']['total']);
        } else {
            $this->assertSame(['message' => 'Tool execution not allowed'], $response['data']);
        }
    }

    public function test_read_only_tool_api_preserves_executor_write_restriction(): void {
        $GLOBALS['wp_test_capabilities'] = ['ai_assistant_read_only' => true];
        $_POST = ['tool' => 'write_file', 'arguments' => '{}'];
        $tools = new Tools();
        $handler = new API_Handler($tools, new Executor($tools));

        try {
            $handler->handle_execute_tool();
            $this->fail('Expected the JSON error to stop execution');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_send_json_error', $e->getMessage());
        }

        $this->assertFalse($GLOBALS['wp_test_json_response']['success']);
        $this->assertSame(
            ['message' => "Tool 'write_file' requires full access permission"],
            $GLOBALS['wp_test_json_response']['data']
        );
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

        $tools = new Tools();
        $handler = new API_Handler($tools, new Executor($tools));

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
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => true,
                    'instructions' => 'Use only when writing demo data.',
                ],
            ],
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

        $tools = new Tools();
        $handler = new API_Handler($tools, new Executor($tools));

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
        $this->assertFalse($data['readonly']);
        $this->assertTrue($data['destructive']);
        $this->assertSame('Use only when writing demo data.', $data['instructions']);
        $this->assertSame([
            'readonly' => false,
            'destructive' => true,
            'instructions' => 'Use only when writing demo data.',
        ], $data['annotations']);
        $this->assertTrue($data['has_schema']);
        $this->assertSame('title', $data['parameters'][0]['name']);
        $this->assertTrue($data['parameters'][0]['required']);
    }

    public function test_handle_get_ability_details_rejects_missing_ability(): void {
        $_POST = [
            'ability' => 'demo/missing',
        ];

        $tools = new Tools();
        $handler = new API_Handler($tools, new Executor($tools));

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
