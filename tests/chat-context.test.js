const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadContextMixin(config) {
    const aiAssistant = {
        messages: [],
        systemPrompt: 'system',
        createStoredMessage(role, content) {
            return { role, content, _ts: Date.now() };
        },
        estimateTokensForMessages(messages) {
            return Math.ceil(JSON.stringify(messages).length / 4);
        },
        generateActiveContextSummary() {
            return Promise.resolve('Older work summary.');
        },
        showToolProgress() {},
        setToolCardState() {}
    };
    const context = {
        window: { aiAssistant },
        aiAssistantConfig: {
            contextCompaction: Object.assign({
                enabled: true,
                triggerTokens: 20,
                recentMessages: 4,
                maxSummaryWords: 200
            }, config || {})
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
        path.join(__dirname, '../assets/js/chat-context.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    aiAssistant.generateActiveContextSummary = function() {
        return Promise.resolve('Older work summary.');
    };
    return aiAssistant;
}

describe('context compaction', function() {
    it('builds compact request messages without mutating the saved transcript', async function() {
        const assistant = loadContextMixin();
        const messages = [];
        for (let i = 0; i < 12; i++) {
            messages.push({ role: i % 2 === 0 ? 'user' : 'assistant', content: 'message ' + i + ' ' + 'x'.repeat(600), _ts: i + 1 });
        }

        const originalJson = JSON.stringify(messages);
        const result = await assistant.maybeCompactProviderMessages('openai', messages);

        assert.equal(result.compacted, true);
        assert.equal(JSON.stringify(messages), originalJson);
        assert.equal(result.messages[0].role, 'user');
        assert.match(result.messages[0].content, /Prior conversation summary/);
        assert.match(result.messages[0].content, /Older work summary/);
        assert.ok(result.messages.length < messages.length);
    });

    it('does not compact when disabled', async function() {
        const assistant = loadContextMixin({ enabled: false });
        const messages = Array.from({ length: 12 }, (_, index) => ({
            role: index % 2 === 0 ? 'user' : 'assistant',
            content: 'message ' + index
        }));

        const result = await assistant.maybeCompactProviderMessages('openai', messages);

        assert.equal(result.compacted, false);
        assert.equal(result.messages, messages);
    });

    it('does not start a compacted tail with a tool result', function() {
        const assistant = loadContextMixin();

        assert.equal(assistant.isSafeCompactionTailStart({ role: 'tool', content: '{}' }), false);
        assert.equal(assistant.isSafeCompactionTailStart({
            role: 'user',
            content: [{ type: 'tool_result', tool_use_id: 'tool-1', content: '{}' }]
        }), false);
        assert.equal(assistant.isSafeCompactionTailStart({ role: 'user', content: 'Continue.' }), true);
    });
});
