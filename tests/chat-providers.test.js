const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadProvidersMixin(extraContext) {
    const aiAssistant = {};
    const context = {
        window: { aiAssistant },
        aiAssistantProviders: { available: {} },
        aiAssistantConfig: {},
        TextDecoder,
        URL,
        URLSearchParams,
        requestAnimationFrame(callback) {
            callback();
        },
        jQuery: {
            extend(target, ...sources) {
                return Object.assign(target, ...sources);
            }
        },
        console
    };
    Object.assign(context, extraContext || {});

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-providers.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return aiAssistant;
}

function createInputJQuery(initialValue) {
    const state = { value: initialValue || '' };

    function jQueryStub(selector) {
        if (selector !== '#ai-assistant-input') {
            return {
                val() {
                    return '';
                }
            };
        }

        return {
            val(value) {
                if (arguments.length === 0) {
                    return state.value;
                }
                state.value = value;
                return this;
            }
        };
    }

    jQueryStub.extend = function(target, source) {
        return Object.assign(target, source);
    };

    return { jQueryStub, state };
}

describe('Anthropic message repair', function() {
    it('inserts an unavailable tool_result before later user text when a tool_use was left unresolved', function() {
        const assistant = loadProvidersMixin();
        const repaired = assistant.repairAnthropicMessages([
            { role: 'user', content: 'Please update a file.' },
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_1', name: 'write_file', input: { path: 'demo.txt' } }
                ]
            },
            { role: 'user', content: 'Actually, keep going.' }
        ]);

        assert.equal(repaired.repaired, true);
        assert.equal(repaired.messages.length, 4);
        assert.equal(repaired.messages[2].role, 'user');
        assert.equal(repaired.messages[2].content.length, 1);
        assert.equal(repaired.messages[2].content[0].tool_use_id, 'toolu_1');
        assert.equal(repaired.messages[2].content[0].is_error, true);
        assert.equal(JSON.parse(repaired.messages[2].content[0].content).skipped, undefined);
        assert.equal(repaired.messages[3].content, 'Actually, keep going.');
    });

    it('leaves a valid assistant tool_use followed by matching tool_result unchanged', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_1', name: 'read_file', input: { path: 'demo.txt' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_1', content: '{"ok":true}' }
                ]
            }
        ];

        const repaired = assistant.repairAnthropicMessages(messages);

        assert.equal(repaired.repaired, false);
        assert.equal(JSON.stringify(repaired.messages), JSON.stringify(messages));
    });

    it('removes orphan tool_result blocks that are not immediately after a matching tool_use', function() {
        const assistant = loadProvidersMixin();
        const repaired = assistant.repairAnthropicMessages([
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'missing', content: '{"ok":true}' },
                    { type: 'text', text: 'Continue.' }
                ]
            }
        ]);

        assert.equal(repaired.repaired, true);
        assert.equal(JSON.stringify(repaired.messages), JSON.stringify([
            {
                role: 'user',
                content: [
                    { type: 'text', text: 'Continue.' }
                ]
            }
        ]));
    });

    it('repairs a next user message when text appears before the required tool_result', function() {
        const assistant = loadProvidersMixin();
        const repaired = assistant.repairAnthropicMessages([
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_1', name: 'write_file', input: { path: 'demo.txt' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'text', text: 'Do this too.' },
                    { type: 'tool_result', tool_use_id: 'toolu_1', content: '{"ok":true}' }
                ]
            }
        ]);

        assert.equal(repaired.repaired, true);
        assert.equal(repaired.messages[1].content[0].type, 'tool_result');
        assert.equal(repaired.messages[1].content[0].tool_use_id, 'toolu_1');
        assert.equal(repaired.messages[2].content.length, 1);
        assert.equal(repaired.messages[2].content[0].text, 'Do this too.');
    });

    it('moves a later real tool_result before assistant follow-up content instead of marking it unavailable', function() {
        const assistant = loadProvidersMixin();
        const repaired = assistant.repairAnthropicMessages([
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_1', name: 'ability', input: { action: 'execute' } }
                ]
            },
            { role: 'assistant', content: 'Background set.' },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_1', content: '{"success":true}' }
                ]
            }
        ]);

        assert.equal(repaired.repaired, true);
        assert.equal(repaired.messages[1].role, 'user');
        assert.equal(repaired.messages[1].content[0].tool_use_id, 'toolu_1');
        assert.equal(repaired.messages[1].content[0].content, '{"success":true}');
        assert.equal(repaired.messages[1].content[0].is_error, undefined);
        assert.equal(repaired.messages[2].role, 'assistant');
        assert.equal(repaired.messages[2].content, 'Background set.');
        assert.equal(repaired.messages.length, 3);
    });

    it('does not send Anthropic requests that end with assistant content', async function() {
        let fetchCalled = false;
        const assistant = Object.assign(loadProvidersMixin({
            fetch() {
                fetchCalled = true;
                throw new Error('fetch should not be called');
            }
        }), {
            messages: [
                { role: 'user', content: 'Set the background.' },
                { role: 'assistant', content: 'Background set.' }
            ],
            systemPrompt: '',
            conversationModel: 'claude-sonnet-4-5',
            abortController: null,
            loadingState: null,
            getModel() {
                return 'claude-sonnet-4-5';
            },
            getApiKey() {
                return 'test-key';
            },
            getProviderEndpoint() {
                return 'https://example.test/anthropic';
            },
            getTools() {
                return [];
            },
            setLoading(value) {
                this.loadingState = value;
            },
            updateTokenCount() {},
            autoSaveConversation() {},
            addMessage() {}
        });

        await assistant.callAnthropic();

        assert.equal(fetchCalled, false);
        assert.equal(assistant.loadingState, false);
    });
});

describe('Pending approval send guard', function() {
    it('does not call a provider while tool approvals are pending', function() {
        const assistant = Object.assign(loadProvidersMixin(), {
            pendingActions: [{ id: 'toolu_1' }],
            conversationProvider: 'anthropic',
            setLoadingCalled: null,
            modalShown: false,
            providerCalled: false,
            setLoading(value) {
                this.setLoadingCalled = value;
            },
            showToolApprovalModal() {
                this.modalShown = true;
            },
            callAnthropic() {
                this.providerCalled = true;
            }
        });

        assistant.callLLM();

        assert.equal(assistant.providerCalled, false);
        assert.equal(assistant.modalShown, true);
        assert.equal(assistant.setLoadingCalled, false);
    });

    it('does not call a provider while an approval preflight is pending', function() {
        const assistant = Object.assign(loadProvidersMixin(), {
            pendingActions: [],
            pendingToolChecks: 1,
            conversationProvider: 'anthropic',
            setLoadingCalled: null,
            providerCalled: false,
            setLoading(value) {
                this.setLoadingCalled = value;
            },
            callAnthropic() {
                this.providerCalled = true;
            }
        });

        assistant.callLLM();

        assert.equal(assistant.providerCalled, false);
        assert.equal(assistant.setLoadingCalled, false);
        assert.equal(assistant.pendingToolChecks, 1);
    });
});

describe('Local LLM requests', function() {
    it('does not send a request when no local model is selected', async function() {
        let fetchCalled = false;
        const messages = [];
        const assistant = Object.assign(loadProvidersMixin({
            fetch() {
                fetchCalled = true;
                throw new Error('fetch should not be called');
            }
        }), {
            conversationModel: '',
            getModel() {
                return '';
            },
            getLocalEndpoint() {
                return 'http://localhost:11434';
            },
            addMessage(role, content) {
                messages.push({ role, content });
            },
            setLoading(value) {
                this.loadingState = value;
            }
        });

        await assistant.callLocalLLM();

        assert.equal(fetchCalled, false);
        assert.equal(assistant.loadingState, false);
        assert.equal(messages[0].role, 'error');
        assert.match(messages[0].content, /No local model selected/);
    });

    it('extracts tagged local model thinking from streamed content', async function() {
        let requestPayload = null;
        const replies = [];
        const thinkingBlocks = [];
        const assistant = Object.assign(loadProvidersMixin({
            fetch(url, options) {
                requestPayload = JSON.parse(options.body);
                return Promise.resolve({ ok: true });
            }
        }), {
            messages: [{ role: 'user', content: 'Hello' }],
            systemPrompt: 'System',
            conversationModel: 'qwen-test',
            abortController: null,
            getModel() {
                return 'fallback-model';
            },
            getLocalEndpoint() {
                return 'http://localhost:11434';
            },
            getToolsOpenAI() {
                return [];
            },
            async *readSSEStream() {
                yield { choices: [{ delta: { content: '<think>Inspect data' } }] };
                yield { choices: [{ delta: { content: ' first</think>Here is the answer.' } }] };
            },
            startReply() {
                const reply = {
                    removed: false,
                    content: '',
                    remove() {
                        this.removed = true;
                    }
                };
                replies.push(reply);
                return reply;
            },
            updateReply(reply, text) {
                reply.content = text;
            },
            finalizeReply(reply) {
                reply.finalized = true;
            },
            startThinking(options) {
                const block = {
                    options,
                    content: '',
                    remove() {
                        this.removed = true;
                    }
                };
                thinkingBlocks.push(block);
                return block;
            },
            updateThinking(block, text) {
                block.content = text;
            },
            finalizeThinking(block, durationMs) {
                block.finalized = true;
                block.durationMs = durationMs;
            },
            updateTokenCount() {},
            autoSaveConversation() {},
            sendQueuedMessagesIfAvailable() {
                return false;
            },
            setLoading(value) {
                this.loadingState = value;
            }
        });

        await assistant.callLocalLLM();

        assert.equal(requestPayload.model, 'qwen-test');
        assert.equal(thinkingBlocks.length, 1);
        assert.equal(thinkingBlocks[0].options.expanded, true);
        assert.equal(thinkingBlocks[0].content, 'Inspect data first');
        assert.equal(thinkingBlocks[0].finalized, true);
        assert.equal(assistant.messages[1].role, 'assistant');
        assert.equal(assistant.messages[1].content, 'Here is the answer.');
        assert.equal(assistant.messages[1]._thinking, 'Inspect data first');
        assert.equal(replies[replies.length - 1].content, 'Here is the answer.');
    });

    it('parses complete and partial reasoning tags without leaking tag fragments', function() {
        const assistant = loadProvidersMixin();

        assert.equal(
            JSON.stringify(assistant.extractReasoningFromContent('<think>Plan</think>Answer')),
            JSON.stringify({ content: 'Answer', thinking: 'Plan', thinkingOpen: false })
        );
        assert.equal(
            JSON.stringify(assistant.extractReasoningFromContent('[THINK]Plan')),
            JSON.stringify({ content: '', thinking: 'Plan', thinkingOpen: true })
        );
        assert.equal(
            JSON.stringify(assistant.extractReasoningFromContent('<|channel>thought\nInspect data\n<channel|>Answer')),
            JSON.stringify({ content: 'Answer', thinking: 'Inspect data', thinkingOpen: false })
        );
        assert.equal(
            JSON.stringify(assistant.extractReasoningFromContent('<|channel>thought\n<channel|>Answer')),
            JSON.stringify({ content: 'Answer', thinking: '', thinkingOpen: false })
        );
        assert.equal(
            JSON.stringify(assistant.extractReasoningFromContent('<|chan')),
            JSON.stringify({ content: '', thinking: '', thinkingOpen: false })
        );
        assert.equal(
            JSON.stringify(assistant.extractReasoningFromContent('<thi')),
            JSON.stringify({ content: '', thinking: '', thinkingOpen: false })
        );
    });
});

describe('provider proxy requests', function() {
    it('sends connector-backed providers through the AJAX proxy with a form JSON body', async function() {
        const calls = [];
        const payload = {
            model: 'claude-test',
            system: 'Check for "recipe" posts.\nUse abilities first.',
            messages: [{ role: 'user', content: 'did any of my friends post a recipe?' }]
        };
        const assistant = loadProvidersMixin({
            aiAssistantProviders: {
                available: {
                    anthropic: {
                        proxySupported: true,
                        serverSideAuth: true
                    }
                }
            },
            aiAssistantConfig: {
                ajaxUrl: 'http://example.test/wp-admin/admin-ajax.php?existing=1',
                nonce: 'nonce value'
            },
            fetch(url, options) {
                calls.push({ url, options });
                return Promise.resolve({ ok: true });
            }
        });
        assistant.isConnectorsMode = function() {
            return true;
        };

        await assistant.fetchLLMProvider(
            'anthropic',
            'https://api.anthropic.com/v1/messages',
            { 'Content-Type': 'application/json' },
            payload
        );

        assert.equal(calls.length, 1);
        assert.equal(
            calls[0].url,
            'http://example.test/wp-admin/admin-ajax.php?existing=1&action=ai_assistant_llm_proxy&_wpnonce=nonce%20value&provider=anthropic'
        );
        assert.equal(calls[0].options.method, 'POST');
        assert.equal(calls[0].options.credentials, 'same-origin');
        assert.equal(calls[0].options.headers, undefined);
        assert.equal(calls[0].options.body.get('body'), JSON.stringify(payload));
    });
});

describe('provider request message sanitization', function() {
    it('strips private underscore metadata before sending messages to providers', function() {
        const assistant = loadProvidersMixin();

        const sanitized = assistant.sanitizeMessages([
            { role: 'user', content: 'Hello' },
            {
                role: 'assistant',
                content: 'Hi',
                _ts: 1790000000000,
                _thinking: 'private reasoning text',
                _usage: {
                    source: 'provider',
                    input_tokens: 12,
                    output_tokens: 3
                },
                _private: 'kept out of provider payloads'
            }
        ]);

        assert.strictEqual(sanitized.length, 2);
        assert.strictEqual(Object.prototype.hasOwnProperty.call(sanitized[1], '_usage'), false);
        assert.strictEqual(Object.prototype.hasOwnProperty.call(sanitized[1], '_ts'), false);
        assert.strictEqual(Object.prototype.hasOwnProperty.call(sanitized[1], '_thinking'), false);
        assert.strictEqual(Object.prototype.hasOwnProperty.call(sanitized[1], '_private'), false);
    });

    it('adds millisecond epoch timestamps to stored messages', function() {
        const assistant = loadProvidersMixin();
        const message = assistant.createStoredMessage('user', 'Hello');

        assert.strictEqual(message.role, 'user');
        assert.strictEqual(message.content, 'Hello');
        assert.strictEqual(typeof message._ts, 'number');
        assert.ok(message._ts > 0);
    });

    it('compacts oversized tool result content before provider requests', function() {
        const assistant = loadProvidersMixin({
            aiAssistantConfig: {
                maxToolResultChars: 4096,
                maxToolResultStringChars: 1024,
                maxToolResultArrayItems: 5
            }
        });
        const originalContent = 'x'.repeat(12000);

        const content = assistant.stringifyToolResultForProvider({
            path: 'plugins/example/large.php',
            content: originalContent,
            size: originalContent.length
        });
        const parsed = JSON.parse(content);

        assert.ok(content.length < originalContent.length);
        assert.match(parsed.content, /truncated/);
        assert.equal(parsed.path, 'plugins/example/large.php');
        assert.equal(parsed.size, originalContent.length);
    });

    it('omits duplicate large strings before truncating tool results', function() {
        const assistant = loadProvidersMixin({
            aiAssistantConfig: {
                maxToolResultChars: 32768,
                maxToolResultStringChars: 8192,
                maxToolResultArrayItems: 5
            }
        });
        const html = '<div>' + 'Vienna '.repeat(4000) + '</div>';

        const content = assistant.stringifyToolResultForProvider({
            ability: 'wordopedia/get-saved-article',
            success: true,
            result: {
                article: {
                    summary: 'Wien is the capital of Austria.',
                    content: html,
                    html: html
                }
            }
        });
        const parsed = JSON.parse(content);

        assert.match(parsed.result.article.content, /truncated|file content truncated/);
        assert.match(parsed.result.article.html, /Duplicate of result.result.article.content omitted/);
        assert.ok(content.length < html.length);
    });

    it('compacts oversized stored tool results without stripping private metadata', function() {
        const assistant = loadProvidersMixin({
            aiAssistantConfig: {
                maxToolResultChars: 4096,
                maxToolResultStringChars: 1024
            }
        });
        const message = {
            role: 'user',
            _ts: 1790000000000,
            content: [
                {
                    type: 'tool_result',
                    tool_use_id: 'toolu_large',
                    content: JSON.stringify({
                        content: 'x'.repeat(50000),
                        size: 50000
                    })
                }
            ]
        };
        const originalLength = message.content[0].content.length;

        const compacted = assistant.compactProviderMessageForStorage(message);
        const parsed = JSON.parse(compacted.content[0].content);

        assert.equal(compacted._ts, 1790000000000);
        assert.ok(compacted.content[0].content.length < originalLength);
        assert.match(parsed.content, /truncated/);
    });

    it('keeps stale small failed Anthropic tool call pairs as useful context', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            { role: 'user', content: 'Try the operation.' },
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_failed', name: 'ability', input: { action: 'execute' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_failed', content: '{"error":"No article found"}' }
                ]
            },
            { role: 'assistant', content: 'That article was not available.' },
            { role: 'user', content: 'Try something else.' }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'anthropic');
        const serialized = JSON.stringify(compacted);

        assert.ok(serialized.includes('toolu_failed'));
        assert.ok(serialized.includes('No article found'));
        assert.equal(compacted[compacted.length - 1].content, 'Try something else.');
    });

    it('drops stale compacted Anthropic tool call pairs from future provider context', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            { role: 'user', content: 'Read this data.' },
            {
                role: 'assistant',
                content: [
                    { type: 'text', text: 'I will inspect it.' },
                    { type: 'tool_use', id: 'toolu_success', name: 'ability', input: { action: 'execute' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_success', content: '{"_truncated":true,"preview":"large payload"}' }
                ]
            },
            { role: 'assistant', content: 'The data says the value is large payload.' },
            { role: 'user', content: 'Continue.' }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'anthropic');
        const serialized = JSON.stringify(compacted);

        assert.ok(!serialized.includes('toolu_success'));
        assert.ok(!serialized.includes('"large payload"'));
        assert.ok(serialized.includes('I will inspect it.'));
        assert.ok(serialized.includes('The data says the value is large payload.'));
    });

    it('keeps stale skill results so the model does not reload the same skill each turn', function() {
        const assistant = loadProvidersMixin();
        const skillContent = JSON.stringify({
            id: 'wp-app',
            title: 'Create a WordPress App Plugin',
            content: 'Use WpApp for app-like plugins. '.repeat(120) +
                '\n\n[... truncated 1580 chars ...]\n\n' +
                'Use ai_assistant_ability_instructions for follow-up context.'
        });
        const messages = [
            { role: 'user', content: 'Build an app.' },
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_skill', name: 'skill', input: { action: 'get', skill: 'wp-app' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_skill', content: skillContent }
                ]
            },
            { role: 'assistant', content: 'I will use the wp-app pattern.' },
            { role: 'user', content: 'Continue.' }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'anthropic');
        const serialized = JSON.stringify(compacted);

        assert.ok(serialized.includes('toolu_skill'));
        assert.ok(serialized.includes('Create a WordPress App Plugin'));
    });

    it('keeps stale ability discovery results so schemas are not repeatedly requested', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            { role: 'user', content: 'Create an app.' },
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_ability_get', name: 'ability', input: { action: 'get', ability: 'ai/create-wp-app' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_ability_get', content: '{"id":"ai/create-wp-app","parameters":{"slug":{"type":"string"}}}' }
                ]
            },
            { role: 'assistant', content: 'I have the schema.' },
            { role: 'user', content: 'Continue.' }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'anthropic');
        const serialized = JSON.stringify(compacted);

        assert.ok(serialized.includes('toolu_ability_get'));
        assert.ok(serialized.includes('ai/create-wp-app'));
    });

    it('keeps the latest failed Anthropic tool result so the model can react once', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            { role: 'user', content: 'Try the operation.' },
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_current', name: 'ability', input: { action: 'execute' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_current', is_error: true, content: '{"error":"No article found"}' }
                ]
            }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'anthropic');
        const serialized = JSON.stringify(compacted);

        assert.ok(serialized.includes('toolu_current'));
        assert.ok(serialized.includes('No article found'));
    });

    it('keeps the latest successful Anthropic tool result until it has been consumed', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            { role: 'user', content: 'Read this data.' },
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_current_success', name: 'ability', input: { action: 'execute' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_current_success', content: '{"result":{"value":"fresh payload"}}' }
                ]
            }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'anthropic');
        const serialized = JSON.stringify(compacted);

        assert.ok(serialized.includes('toolu_current_success'));
        assert.ok(serialized.includes('fresh payload'));
    });

    it('keeps stale small failed OpenAI tool calls as useful context', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            { role: 'user', content: 'Try the operation.' },
            {
                role: 'assistant',
                content: null,
                tool_calls: [
                    {
                        id: 'call_failed',
                        type: 'function',
                        function: { name: 'ability', arguments: '{"action":"execute"}' }
                    }
                ]
            },
            { role: 'tool', tool_call_id: 'call_failed', content: '{"error":"No article found"}' },
            { role: 'assistant', content: 'That article was not available.' },
            { role: 'user', content: 'Try something else.' }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'openai');
        const serialized = JSON.stringify(compacted);

        assert.ok(serialized.includes('call_failed'));
        assert.ok(serialized.includes('No article found'));
        assert.equal(compacted[compacted.length - 1].content, 'Try something else.');
    });

    it('drops stale compacted OpenAI tool calls from future provider context', function() {
        const assistant = loadProvidersMixin();
        const messages = [
            { role: 'user', content: 'Read this data.' },
            {
                role: 'assistant',
                content: 'I will inspect it.',
                tool_calls: [
                    {
                        id: 'call_success',
                        type: 'function',
                        function: { name: 'ability', arguments: '{"action":"execute"}' }
                    }
                ]
            },
            { role: 'tool', tool_call_id: 'call_success', content: '{"_truncated":true,"preview":"large payload"}' },
            { role: 'assistant', content: 'The data says the value is large payload.' },
            { role: 'user', content: 'Continue.' }
        ];

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'openai');
        const serialized = JSON.stringify(compacted);

        assert.ok(!serialized.includes('call_success'));
        assert.ok(!serialized.includes('"large payload"'));
        assert.ok(serialized.includes('I will inspect it.'));
        assert.ok(serialized.includes('The data says the value is large payload.'));
    });

    it('trims oldest request messages when compacted history still exceeds the provider budget', function() {
        const assistant = loadProvidersMixin({
            aiAssistantConfig: {
                maxToolResultChars: 4096,
                maxToolResultStringChars: 1024,
                maxProviderRequestChars: 32768
            }
        });
        assistant.systemPrompt = 'System';

        const messages = [];
        for (let i = 0; i < 30; i++) {
            messages.push({ role: 'user', content: 'message ' + i + ' ' + 'x'.repeat(5000) });
            messages.push({ role: 'assistant', content: 'response ' + i });
        }
        messages.push({ role: 'user', content: 'latest request' });

        const compacted = assistant.compactProviderMessagesForRequest(messages, 'anthropic');

        assert.equal(compacted[0].role, 'user');
        assert.match(compacted[0].content, /Earlier conversation messages were omitted/);
        assert.equal(compacted[compacted.length - 1].content, 'latest request');
        assert.ok(assistant.estimateProviderRequestChars(compacted) <= 32768);
    });

    it('uses stricter compaction limits for provider recovery requests', function() {
        const assistant = loadProvidersMixin({
            aiAssistantConfig: {
                maxToolResultChars: 32768,
                maxToolResultStringChars: 8192,
                maxProviderRequestChars: 65536
            }
        });
        assistant.systemPrompt = 'System';
        const largeResult = JSON.stringify({
            result: {
                article: {
                    content: 'x'.repeat(50000)
                }
            }
        });
        const messages = [
            {
                role: 'assistant',
                content: [
                    { type: 'tool_use', id: 'toolu_large', name: 'ability', input: { action: 'execute' } }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'toolu_large', content: largeResult }
                ]
            }
        ];

        const normal = assistant.compactProviderMessagesForRequest(messages, 'anthropic');
        const recovery = assistant.compactProviderMessagesForRequest(messages, 'anthropic', 'recovery');
        const normalLength = JSON.stringify(normal).length;
        const recoveryLength = JSON.stringify(recovery).length;
        const normalResult = JSON.parse(normal[1].content[0].content);
        const recoveryResult = JSON.parse(recovery[1].content[0].content);

        assert.ok(recoveryLength < normalLength);
        assert.ok(recoveryResult.result.article.content.length < normalResult.result.article.content.length);
    });
});

describe('provider context recovery', function() {
    function errorResponse(status, message) {
        return {
            ok: false,
            status,
            json() {
                return Promise.resolve({ error: { message } });
            }
        };
    }

    it('classifies provider context and auth errors conservatively', function() {
        const assistant = loadProvidersMixin();

        assert.strictEqual(
            assistant.isProviderContextRecoverableError(
                'anthropic',
                429,
                "This request would exceed your organization's rate limit of 2,000,000 input tokens per minute. Reduce the prompt length."
            ),
            true
        );
        assert.strictEqual(
            assistant.isProviderContextRecoverableError('openai', 400, 'This model has a maximum context length of 128000 tokens.'),
            true
        );
        assert.strictEqual(
            assistant.isProviderContextRecoverableError('openai', 401, 'Incorrect API key provided.'),
            false
        );
    });

    it('retries Anthropic once with stricter local compaction after an input-token rate limit', async function() {
        const payloads = [];
        const assistant = Object.assign(loadProvidersMixin(), {
            messages: [
                {
                    role: 'assistant',
                    content: [
                        { type: 'tool_use', id: 'toolu_large', name: 'ability', input: { action: 'execute' } }
                    ]
                },
                {
                    role: 'user',
                    content: [
                        {
                            type: 'tool_result',
                            tool_use_id: 'toolu_large',
                            content: JSON.stringify({ result: { article: { content: 'x'.repeat(50000) } } })
                        }
                    ]
                }
            ],
            systemPrompt: 'System',
            conversationModel: 'claude-test',
            abortController: null,
            getModel() {
                return 'fallback-model';
            },
            getApiKey() {
                return 'test-key';
            },
            getProviderEndpoint() {
                return 'https://example.test/anthropic';
            },
            getTools() {
                return [];
            },
            fetchLLMProvider(provider, endpoint, headers, requestPayload) {
                payloads.push(requestPayload);
                if (payloads.length === 1) {
                    return Promise.resolve(errorResponse(
                        429,
                        "This request would exceed your organization's rate limit of 2,000,000 input tokens per minute. Reduce the prompt length."
                    ));
                }
                return Promise.resolve({ ok: true });
            },
            async *readSSEStream() {
                yield { type: 'content_block_start', content_block: { type: 'text' } };
                yield { type: 'content_block_delta', delta: { type: 'text_delta', text: 'Recovered.' } };
                yield { type: 'content_block_stop' };
                yield { type: 'message_delta', delta: { stop_reason: 'end_turn' } };
            },
            startReply() {
                return { remove() {} };
            },
            updateReply() {},
            finalizeReply() {},
            updateTokenCount() {},
            autoSaveConversation() {},
            hideToolProgress() {},
            sendQueuedMessagesIfAvailable() {
                return false;
            },
            setLoading(value) {
                this.loadingState = value;
            },
            addMessage() {}
        });

        await assistant.callAnthropic();

        assert.strictEqual(payloads.length, 2);
        assert.ok(JSON.stringify(payloads[1].messages).length < JSON.stringify(payloads[0].messages).length);
        assert.strictEqual(assistant.messages[assistant.messages.length - 1].content[0].text, 'Recovered.');
        assert.strictEqual(assistant.loadingState, false);
    });
});

describe('provider token usage capture', function() {
    it('omits Anthropic prompt caching by default', async function() {
        let payload = null;
        const assistant = Object.assign(loadProvidersMixin(), {
            messages: [{ role: 'user', content: 'Hello' }],
            systemPrompt: 'System',
            conversationModel: 'claude-test',
            abortController: null,
            getModel() {
                return 'fallback-model';
            },
            getApiKey() {
                return 'test-key';
            },
            getProviderEndpoint() {
                return 'https://example.test/v1/messages';
            },
            getTools() {
                return [];
            },
            fetchLLMProvider(provider, endpoint, headers, requestPayload) {
                payload = requestPayload;
                return Promise.resolve({ ok: true });
            },
            async *readSSEStream() {
                yield { type: 'content_block_start', content_block: { type: 'text', text: '' } };
                yield { type: 'content_block_delta', delta: { type: 'text_delta', text: 'Hi' } };
                yield { type: 'content_block_stop' };
                yield { type: 'message_stop' };
            },
            startReply() {
                return { remove() {} };
            },
            updateReply() {},
            finalizeReply() {},
            updateTokenCount() {},
            autoSaveConversation() {},
            processToolCalls() {},
            sendQueuedMessagesIfAvailable() {
                return false;
            },
            setLoading() {}
        });

        await assistant.callAnthropic();

        assert.strictEqual(payload.cache_control, undefined);
    });

    it('enables Anthropic prompt caching when configured', async function() {
        let payload = null;
        let attached = null;
        const assistant = Object.assign(loadProvidersMixin(), {
            messages: [{ role: 'user', content: 'Hello' }],
            systemPrompt: 'System',
            conversationModel: 'claude-test',
            abortController: null,
            getModel() {
                return 'fallback-model';
            },
            getApiKey() {
                return 'test-key';
            },
            getProviderEndpoint() {
                return 'https://example.test/v1/messages';
            },
            isAnthropicPromptCacheEnabled() {
                return true;
            },
            getTools() {
                return [];
            },
            fetchLLMProvider(provider, endpoint, headers, requestPayload) {
                payload = requestPayload;
                return Promise.resolve({ ok: true });
            },
            async *readSSEStream() {
                yield {
                    type: 'message_start',
                    message: {
                        usage: {
                            input_tokens: 4,
                            cache_creation_input_tokens: 10,
                            cache_read_input_tokens: 20,
                            output_tokens: 0
                        }
                    }
                };
                yield { type: 'content_block_start', content_block: { type: 'text', text: '' } };
                yield { type: 'content_block_delta', delta: { type: 'text_delta', text: 'Hi' } };
                yield { type: 'content_block_stop' };
                yield { type: 'message_stop' };
            },
            startReply() {
                return { remove() {} };
            },
            updateReply() {},
            finalizeReply() {},
            updateTokenCount() {},
            autoSaveConversation() {},
            processToolCalls() {},
            sendQueuedMessagesIfAvailable() {
                return false;
            },
            setLoading() {},
            attachTokenUsageToAssistantMessage(message, provider, model, usage) {
                attached = { message, provider, model, usage };
                message._usage = usage;
            }
        });

        await assistant.callAnthropic();

        assert.strictEqual(payload.cache_control.type, 'ephemeral');
        assert.strictEqual(attached.provider, 'anthropic');
        assert.strictEqual(attached.model, 'claude-test');
        assert.strictEqual(attached.usage.cache_creation_input_tokens, 10);
        assert.strictEqual(attached.usage.cache_read_input_tokens, 20);
    });

    it('requests and stores OpenAI streaming usage on assistant messages', async function() {
        let payload = null;
        let attached = null;
        const assistant = Object.assign(loadProvidersMixin(), {
            messages: [{ role: 'user', content: 'Hello' }],
            systemPrompt: 'System',
            conversationModel: 'gpt-test',
            conversationId: 123,
            abortController: null,
            getModel() {
                return 'fallback-model';
            },
            getApiKey() {
                return 'test-key';
            },
            getProviderEndpoint() {
                return 'https://example.test/v1/chat/completions';
            },
            getToolsOpenAI() {
                return [];
            },
            fetchLLMProvider(provider, endpoint, headers, requestPayload) {
                payload = requestPayload;
                return Promise.resolve({ ok: true });
            },
            async *readSSEStream() {
                yield { choices: [{ delta: { content: 'Hi' } }] };
                yield {
                    choices: [],
                    usage: {
                        prompt_tokens: 11,
                        completion_tokens: 3,
                        total_tokens: 14
                    }
                };
            },
            startReply() {
                return { remove() {} };
            },
            updateReply() {},
            finalizeReply() {},
            updateTokenCount() {},
            autoSaveConversation() {},
            processToolCalls() {},
            sendQueuedMessagesIfAvailable() {
                return false;
            },
            setLoading() {},
            attachTokenUsageToAssistantMessage(message, provider, model, usage) {
                attached = { message, provider, model, usage };
                message._usage = usage;
            }
        });

        await assistant.callOpenAI();

        assert.strictEqual(payload.stream_options.include_usage, true);
        assert.strictEqual(payload.prompt_cache_key, 'ai-assistant-openai-conversation-123');
        assert.strictEqual(attached.provider, 'openai');
        assert.strictEqual(attached.model, 'gpt-test');
        assert.strictEqual(attached.usage.prompt_tokens, 11);
        assert.strictEqual(attached.usage.completion_tokens, 3);
        assert.strictEqual(attached.usage.total_tokens, 14);
        assert.strictEqual(assistant.messages[1]._usage.prompt_tokens, 11);
    });
});

describe('queued user messages', function() {
    it('queues input instead of starting a second request while loading', function() {
        const input = createInputJQuery('Please do this next.');
        const assistant = Object.assign(loadProvidersMixin({
            jQuery: input.jQueryStub
        }), {
            isLoading: true,
            pendingAttachments: [],
            isUploadingFiles: false,
            queuedMessages: [],
            providerCalled: false,
            isProviderConfigured() {
                return true;
            },
            addToDraftHistory(message) {
                this.lastDraftHistoryMessage = message;
            },
            clearDraft() {
                this.draftCleared = true;
            },
            updateSendButton() {},
            updateLoadingStatus() {},
            callLLM() {
                this.providerCalled = true;
            }
        });

        assistant.sendMessage();

        assert.equal(assistant.providerCalled, false);
        assert.equal(assistant.queuedMessages.length, 1);
        assert.equal(assistant.queuedMessages[0].content, 'Please do this next.');
        assert.equal(typeof assistant.queuedMessages[0].queuedAt, 'number');
        assert.equal(input.state.value, '');
        assert.equal(assistant.lastDraftHistoryMessage, 'Please do this next.');
        assert.equal(assistant.draftCleared, true);
    });

    it('appends queued text to the Anthropic tool-result turn before continuing', function() {
        const assistant = Object.assign(loadProvidersMixin(), {
            queuedMessages: [
                { content: 'Also update the heading.' },
                { content: 'Then summarize the change.' }
            ],
            messages: [
                {
                    role: 'user',
                    content: [
                        { type: 'tool_result', tool_use_id: 'toolu_1', content: '{"ok":true}' }
                    ]
                }
            ],
            displayed: [],
            addMessage(role, content) {
                this.displayed.push({ role, content });
            },
            updateSendButton() {},
            updateLoadingStatus() {},
            updateTokenCount() {},
            updateExportButton() {}
        });

        const flushed = assistant.flushQueuedMessages('anthropic', {
            appendToLastToolResultMessage: true
        });

        assert.equal(flushed, true);
        assert.equal(assistant.queuedMessages.length, 0);
        assert.equal(assistant.messages.length, 1);
        assert.equal(typeof assistant.messages[0]._ts, 'number');
        assert.equal(JSON.stringify(assistant.messages[0].content.slice(1)), JSON.stringify([
            { type: 'text', text: 'Also update the heading.' },
            { type: 'text', text: 'Then summarize the change.' }
        ]));
        assert.equal(JSON.stringify(assistant.displayed), JSON.stringify([
            { role: 'user', content: 'Also update the heading.' },
            { role: 'user', content: 'Then summarize the change.' }
        ]));
    });

    it('continues with queued messages instead of stopping after a text-only response', function() {
        let saved = false;
        let called = false;
        const assistant = Object.assign(loadProvidersMixin(), {
            queuedMessages: [{ content: 'Next request.' }],
            messages: [{ role: 'assistant', content: 'Done.' }],
            toolCallRounds: 5,
            addMessage() {},
            updateSendButton() {},
            updateLoadingStatus() {},
            updateTokenCount() {},
            updateExportButton() {},
            autoSaveConversation() {
                saved = true;
            },
            callLLM() {
                called = true;
            }
        });

        const sent = assistant.sendQueuedMessagesIfAvailable('openai');

        assert.equal(sent, true);
        assert.equal(saved, true);
        assert.equal(called, true);
        assert.equal(assistant.toolCallRounds, 0);
        assert.equal(assistant.messages[1].role, 'user');
        assert.equal(assistant.messages[1].content, 'Next request.');
        assert.equal(typeof assistant.messages[1]._ts, 'number');
    });
});
