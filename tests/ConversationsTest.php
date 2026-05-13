<?php
namespace AI_Assistant\Tests;

use AI_Assistant\Conversations;
use PHPUnit\Framework\TestCase;

class ConversationsTest extends TestCase {

    private Conversations $conversations;

    protected function setUp(): void {
        $GLOBALS['wp_test_filters']['ai_assistant_conversation_export_formats'] = [];
        $GLOBALS['wp_test_filters']['ai_assistant_conversation_export_shrink_tool_calls'] = [];
        $this->conversations = new Conversations();
    }

    public function test_export_formats_for_config_exposes_default_formats_without_callbacks(): void {
        $formats = $this->conversations->get_export_formats_for_config();

        $this->assertContains('markdown', array_column($formats, 'format'));
        $this->assertContains('html', array_column($formats, 'format'));
        $this->assertContains('json', array_column($formats, 'format'));

        foreach ($formats as $format) {
            $this->assertArrayHasKey('label', $format);
            $this->assertArrayHasKey('extension', $format);
            $this->assertArrayNotHasKey('callback', $format);
        }
    }

    public function test_markdown_export_includes_metadata_summary_and_messages(): void {
        $conversation = $this->sampleConversation();
        $conversation['include_tool_calls'] = true;
        $markdown = $this->conversations->export_conversation_as_markdown($conversation, [
            'extension' => 'md',
            'mime' => 'text/markdown',
        ]);

        $this->assertStringContainsString('# Test Conversation', $markdown);
        $this->assertStringContainsString('- Conversation ID: 123', $markdown);
        $this->assertStringContainsString('## Summary', $markdown);
        $this->assertStringContainsString('Short summary.', $markdown);
        $this->assertStringContainsString('### User', $markdown);
        $this->assertStringContainsString('Please inspect this.', $markdown);
        $this->assertStringContainsString('[Tool: read_file]', $markdown);
        $this->assertStringContainsString('read_file content omitted from export', $markdown);
        $this->assertStringNotContainsString('secret file contents', $markdown);
        $this->assertStringNotContainsString('toolu_01', $markdown);
    }

    public function test_markdown_export_omits_tool_calls_by_default(): void {
        $markdown = $this->conversations->export_conversation_as_markdown($this->sampleConversation(), [
            'extension' => 'md',
            'mime' => 'text/markdown',
        ]);

        $this->assertStringContainsString('I will check the file.', $markdown);
        $this->assertStringNotContainsString('[Tool: read_file]', $markdown);
        $this->assertStringNotContainsString('secret file contents', $markdown);
    }

    public function test_html_export_returns_standalone_document(): void {
        $html = $this->conversations->export_conversation_as_html($this->sampleConversation(), [
            'extension' => 'html',
            'mime' => 'text/html',
        ]);

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('<title>Test Conversation</title>', $html);
        $this->assertStringContainsString('Short summary.', $html);
        $this->assertStringContainsString('Please inspect this.', $html);
        $this->assertStringNotContainsString('[Tool: read_file]', $html);
    }

    public function test_json_export_returns_raw_download_payload(): void {
        $payload = $this->conversations->export_conversation_as_json($this->sampleConversation(), [
            'extension' => 'json',
            'mime' => 'application/json',
        ]);

        $this->assertSame('application/json', $payload['mime']);
        $this->assertStringEndsWith('.json', $payload['filename']);
        $this->assertStringContainsString('secret file contents', $payload['content']);

        $decoded = json_decode($payload['content'], true);
        $this->assertSame('ai-assistant-conversation-export/v1', $decoded['schema']);
        $this->assertSame(123, $decoded['conversation']['id']);
    }

    private function sampleConversation(): array {
        return [
            'id' => 123,
            'title' => 'Test Conversation',
            'summary' => 'Short summary.',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Please inspect this.',
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'I will check the file.'],
                        ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'read_file', 'input' => ['path' => 'plugin.php']],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => 'toolu_01',
                            'content' => '{"path":"plugin.php","content":"secret file contents"}',
                        ],
                    ],
                ],
            ],
            'message_count' => 3,
            'provider' => 'openai',
            'model' => 'gpt-test',
            'created' => '2026-05-13 10:00:00',
            'modified' => '2026-05-13 10:05:00',
            'author_id' => 1,
        ];
    }
}
