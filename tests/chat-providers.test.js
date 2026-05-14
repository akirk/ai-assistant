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
            extend(target, source) {
                return Object.assign(target, source);
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
        assert.equal(JSON.stringify(assistant.messages[1]), JSON.stringify({
            role: 'user',
            content: 'Next request.'
        }));
    });
});
