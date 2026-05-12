const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadProvidersMixin() {
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

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-providers.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return aiAssistant;
}

describe('Anthropic message repair', function() {
    it('inserts a skipped tool_result before later user text when a tool_use was left unresolved', function() {
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
});
