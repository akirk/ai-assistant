<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Conversations_App;
use PHPUnit\Framework\TestCase;

class ConversationsAppTest extends TestCase {

    protected function tearDown(): void {
        unset($_GET['conversation'], $_GET['conversation_id']);
    }

    public function test_request_conversation_id_accepts_conversation_param(): void {
        $_GET['conversation'] = '42';

        $this->assertSame(42, Conversations_App::get_request_conversation_id());
    }

    public function test_request_conversation_id_accepts_legacy_conversation_id_param(): void {
        $_GET['conversation_id'] = '84';

        $this->assertSame(84, Conversations_App::get_request_conversation_id());
    }

    public function test_conversation_url_points_to_frontend_app_route(): void {
        $this->assertSame(
            'http://localhost/ai-assistant/conversations/?conversation=42',
            Conversations_App::get_conversation_url(42)
        );
    }
}
