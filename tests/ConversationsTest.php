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
        $this->assertStringContainsString('- Messages: 2', $markdown);
        $this->assertStringContainsString('- Tool calls: 1', $markdown);
        $this->assertStringContainsString('- Author: Ada Lovelace', $markdown);
        $this->assertStringContainsString('## Summary', $markdown);
        $this->assertStringContainsString('Short summary.', $markdown);
        $this->assertStringContainsString('### Ada Lovelace', $markdown);
        $this->assertStringNotContainsString('### User', $markdown);
        $this->assertStringContainsString('### Tool result', $markdown);
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
        $this->assertStringContainsString('- Messages: 2', $markdown);
        $this->assertStringContainsString('- Tool calls: 1', $markdown);
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
        $this->assertStringContainsString('<main class="ai-export-shell">', $html);
        $this->assertStringContainsString('<div id="ai-assistant-messages" class="ai-export-messages">', $html);
        $this->assertStringContainsString('<dt>Messages</dt><dd>2</dd>', $html);
        $this->assertStringContainsString('<dt>Tool calls</dt><dd>1</dd>', $html);
        $this->assertStringContainsString('<dt>Author</dt><dd>Ada Lovelace</dd>', $html);
        $this->assertStringContainsString('<dt>Created</dt><dd>May 13, 2026 at 10:00 AM</dd>', $html);
        $this->assertStringNotContainsString('<dt>Modified</dt>', $html);
        $this->assertStringContainsString('<section class="ai-message ai-message-user" aria-label="Ada Lovelace">', $html);
        $this->assertStringContainsString('<span class="ai-message-role">Ada Lovelace</span>', $html);
        $this->assertStringContainsString('<div class="ai-message-content"><p>Please inspect this.</p>', $html);
        $this->assertStringContainsString('<section class="ai-message ai-message-assistant" aria-label="Assistant">', $html);
        $this->assertStringContainsString('Short summary.', $html);
        $this->assertStringContainsString('Please inspect this.', $html);
        $this->assertStringContainsString('<h2>Export Heading</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>emphasis</em>', $html);
        $this->assertStringContainsString('<code>inline</code>', $html);
        $this->assertStringContainsString('<a href="https://example.com/docs">docs</a>', $html);
        $this->assertStringContainsString('<pre><code class="language-php">echo &quot;safe&quot;;</code></pre>', $html);
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
        $this->assertSame('Ada Lovelace', $decoded['conversation']['author_display_name']);
        $this->assertArrayNotHasKey('system_prompt', $decoded['conversation']);
        $this->assertSame('system', $decoded['conversation']['messages'][0]['role']);
        $this->assertSame('Stored system prompt.', $decoded['conversation']['messages'][0]['content']);
        $this->assertSame('user', $decoded['conversation']['messages'][1]['role']);
    }

    public function test_prepare_conversation_messages_for_export_adds_only_distinct_markdown_and_html(): void {
        $conversation = $this->sampleConversation();
        $conversation['include_tool_calls'] = true;

        $prepared = $this->conversations->prepare_conversation_messages_for_export($conversation, [
            'extension' => 'html',
            'mime' => 'text/html',
        ]);

        $this->assertArrayNotHasKey('markdown', $prepared['messages'][0]);
        $this->assertStringContainsString('<h2>Export Heading</h2>', $prepared['messages'][0]['html']);
        $this->assertStringContainsString('[Tool: read_file]', $prepared['messages'][1]['markdown']);
        $this->assertStringNotContainsString('toolu_01', $prepared['messages'][1]['markdown']);
        $this->assertStringContainsString('read_file content omitted from export', $prepared['messages'][2]['markdown']);
        $this->assertStringNotContainsString('secret file contents', $prepared['messages'][2]['markdown']);
        $this->assertStringContainsString('secret file contents', $prepared['messages'][2]['content'][0]['content']);
    }

    public function test_json_export_removes_prepared_representations_from_messages(): void {
        $conversation = $this->sampleConversation();
        $conversation['include_tool_calls'] = true;
        $prepared = $this->conversations->prepare_conversation_messages_for_export($conversation, [
            'extension' => 'json',
            'mime' => 'application/json',
        ]);

        $payload = $this->conversations->export_conversation_as_json($prepared, [
            'extension' => 'json',
            'mime' => 'application/json',
        ]);
        $decoded = json_decode($payload['content'], true);

        $this->assertArrayNotHasKey('markdown', $decoded['conversation']['messages'][1]);
        $this->assertArrayNotHasKey('html', $decoded['conversation']['messages'][1]);
        $this->assertArrayNotHasKey('markdown', $decoded['conversation']['messages'][2]);
        $this->assertArrayNotHasKey('html', $decoded['conversation']['messages'][2]);
        $this->assertStringNotContainsString('<h2>Export Heading</h2>', $payload['content']);
        $this->assertStringNotContainsString('[Tool: read_file]', $payload['content']);
        $this->assertStringContainsString('secret file contents', $payload['content']);
    }

    private function sampleConversation(): array {
        return [
            'id' => 123,
            'title' => 'Test Conversation',
            'summary' => 'Short summary.',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "Please inspect this.\n\n# Export Heading\n\nUse **bold**, *emphasis*, `inline`, and [docs](https://example.com/docs).\n\n```php\necho \"safe\";\n```",
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
            'system_prompt' => 'Stored system prompt.',
            'created' => '2026-05-13 10:00:00',
            'modified' => '2026-05-13 10:05:00',
            'author_id' => 1,
            'author_display_name' => 'Ada Lovelace',
        ];
    }
}
