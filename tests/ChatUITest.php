<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Chat_UI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname(__DIR__) . '/includes/class-chat-ui.php';

/**
 * Unit tests for Chat_UI welcome tip matching.
 */
class ChatUITest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_test_filters'] = [];
        $GLOBALS['wp_test_site_url'] = 'http://localhost';
        $_SERVER['REQUEST_URI'] = '/my-apps/?tab=one';
    }

    protected function tearDown(): void {
        unset($GLOBALS['wp_test_site_url']);
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_welcome_tips_match_first_url_component_only(): void {
        $this->add_test_filter('ai_assistant_welcome_tips', function ($tips) {
            $tips['my-apps'] = [
                'First matching tip',
                'Second matching tip',
            ];
            $tips['my-apps-other'] = ['Non-matching tip'];
            $tips['my-apps/legacy-id'] = [
                'url_component' => 'my-apps',
                'message' => 'Legacy object should not leak',
            ];

            return $tips;
        });

        $this->assertSame(
            ['First matching tip', 'Second matching tip'],
            $this->get_welcome_tips()
        );
    }

    public function test_welcome_tips_are_deduped_limited_and_trimmed(): void {
        $_SERVER['REQUEST_URI'] = '/my-apps/abc/';

        $this->add_test_filter('ai_assistant_welcome_tips', function ($tips) {
            $tips['my-apps'] = [
                'Repeat tip',
                'Repeat tip',
                '123456789012345',
            ];

            return $tips;
        });
        $this->add_test_filter('ai_assistant_welcome_tip_limit', fn() => 2);
        $this->add_test_filter('ai_assistant_welcome_tip_max_length', fn() => 10);

        $this->assertSame(
            ['Repeat tip', '1234567...'],
            $this->get_welcome_tips()
        );
    }

    public function test_welcome_tips_ignore_removed_object_shape(): void {
        $this->add_test_filter('ai_assistant_welcome_tips', function ($tips) {
            $tips['my-apps'] = [
                'url_component' => 'my-apps',
                'message' => 'Legacy object should not render',
                'priority' => 10,
            ];

            return $tips;
        });

        $this->assertSame([], $this->get_welcome_tips());
    }

    public function test_current_ai_changes_prompt_context_mentions_review_link(): void {
        $chat_ui = new Chat_UI();
        $reflection = new ReflectionClass($chat_ui);
        $method = $reflection->getMethod('add_current_ai_changes_prompt_context');
        $method->setAccessible(true);

        $prompt = $method->invoke($chat_ui, 'Base prompt', [
            'root' => 'plugins/example',
            'url' => 'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fexample',
            'links' => [
                [
                    'label' => 'Overview',
                    'url' => 'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fexample',
                ],
            ],
        ]);

        $this->assertStringContainsString('CURRENT PAGE FILE CHANGES', $prompt);
        $this->assertStringContainsString('plugins/example', $prompt);
        $this->assertStringContainsString('View changed files', $prompt);
        $this->assertStringContainsString('current window', $prompt);
        $this->assertStringNotContainsString('Current-window version log rows', $prompt);
        $this->assertStringNotContainsString('Overview:', $prompt);
    }

    public function test_auto_approve_defaults_on_for_my_wordpress(): void {
        $GLOBALS['wp_test_site_url'] = 'https://my.wordpress.net/scope:default';

        $this->assertTrue($this->should_default_auto_approve_mode());
    }

    public function test_auto_approve_defaults_off_outside_my_wordpress(): void {
        $GLOBALS['wp_test_site_url'] = 'https://playground.wordpress.net/scope:default';

        $this->assertFalse($this->should_default_auto_approve_mode());
    }

    public function test_provider_config_is_deferred_outside_full_page_conversations(): void {
        $_SERVER['REQUEST_URI'] = '/my-apps/';

        $this->assertTrue($this->should_defer_initial_provider_config());
    }

    public function test_provider_config_is_not_deferred_on_full_page_conversations(): void {
        $_SERVER['REQUEST_URI'] = '/ai-assistant/conversations/?conversation=123';

        $this->assertFalse($this->should_defer_initial_provider_config());
    }

    private function get_welcome_tips(): array {
        $chat_ui = new Chat_UI();
        $reflection = new ReflectionClass($chat_ui);
        $method = $reflection->getMethod('get_welcome_tips');
        $method->setAccessible(true);

        return $method->invoke($chat_ui);
    }

    private function should_default_auto_approve_mode(): bool {
        $chat_ui = new Chat_UI();
        $reflection = new ReflectionClass($chat_ui);
        $method = $reflection->getMethod('should_default_auto_approve_mode');
        $method->setAccessible(true);

        return $method->invoke($chat_ui);
    }

    private function should_defer_initial_provider_config(): bool {
        $chat_ui = new Chat_UI();
        $reflection = new ReflectionClass($chat_ui);
        $method = $reflection->getMethod('should_defer_initial_provider_config');
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
