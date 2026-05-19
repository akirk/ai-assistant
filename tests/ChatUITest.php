<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Chat_UI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname(__DIR__) . '/includes/class-chat-ui.php';

/**
 * Unit tests for Chat_UI welcome tip rule matching.
 */
class ChatUITest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_test_filters'] = [];
        $_SERVER['REQUEST_URI'] = '/my-apps/?tab=one';
    }

    protected function tearDown(): void {
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_welcome_tip_rules_match_first_url_component_only(): void {
        $this->add_test_filter('ai_assistant_welcome_tip_rules', function ($rules) {
            $rules['example/one'] = [
                'path' => '/my-apps/abc/',
                'message' => 'Second matching tip',
                'priority' => 20,
            ];
            $rules['example/two'] = [
                'url_component' => 'my-apps',
                'message' => 'First matching tip',
                'priority' => 10,
            ];
            $rules['example/nope'] = [
                'url_component' => 'my-apps-other',
                'message' => 'Non-matching tip',
                'priority' => 1,
            ];

            return $rules;
        });

        $this->assertSame(
            ['First matching tip', 'Second matching tip'],
            $this->get_welcome_tips()
        );
    }

    public function test_welcome_tip_rules_are_deduped_limited_and_trimmed(): void {
        $_SERVER['REQUEST_URI'] = '/my-apps/abc/';

        $this->add_test_filter('ai_assistant_welcome_tip_rules', function ($rules) {
            $rules['example/one'] = [
                'url_component' => 'my-apps',
                'message' => 'Repeat tip',
                'priority' => 10,
            ];
            $rules['example/duplicate'] = [
                'url_component' => 'my-apps',
                'message' => 'Repeat tip',
                'priority' => 20,
            ];
            $rules['example/long'] = [
                'url_component' => 'my-apps',
                'message' => '123456789012345',
                'priority' => 30,
            ];

            return $rules;
        });
        $this->add_test_filter('ai_assistant_welcome_tip_limit', fn() => 2);
        $this->add_test_filter('ai_assistant_welcome_tip_max_length', fn() => 10);

        $this->assertSame(
            ['Repeat tip', '1234567...'],
            $this->get_welcome_tips()
        );
    }

    private function get_welcome_tips(): array {
        $chat_ui = new Chat_UI();
        $reflection = new ReflectionClass($chat_ui);
        $method = $reflection->getMethod('get_welcome_tips');
        $method->setAccessible(true);

        return $method->invoke($chat_ui);
    }

    private function add_test_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 2): void {
        $GLOBALS['wp_test_filters'][$tag][$priority][] = [
            'callback' => $callback,
            'accepted_args' => $accepted_args,
        ];
    }
}
