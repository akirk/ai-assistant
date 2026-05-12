const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function createStorage(initial) {
    const values = new Map(Object.entries(initial || {}));

    return {
        getItem(key) {
            return values.has(key) ? values.get(key) : null;
        },
        setItem(key, value) {
            values.set(key, String(value));
        },
        removeItem(key) {
            values.delete(key);
        },
        values
    };
}

function createJQuery(input) {
    function $(selector) {
        assert.strictEqual(selector, '#ai-assistant-input');

        return {
            0: input,
            length: 1,
            val(value) {
                if (arguments.length === 0) {
                    return input.value;
                }

                input.value = value;
                return this;
            },
            trigger(event) {
                input.triggered.push(event);
                return this;
            }
        };
    }

    $.extend = function(target, source) {
        return Object.assign(target, source);
    };

    return $;
}

function loadConversationMixin(initialStorage) {
    const input = {
        value: '',
        selectionStart: 0,
        selectionEnd: 0,
        triggered: []
    };
    const aiAssistant = {
        draftHistory: [],
        draftHistoryIndex: -1,
        draftHistoryDraft: '',
        draftHistoryKey: 'aiAssistant_draftHistory',
        draftHistoryMax: 10
    };
    const storage = createStorage(initialStorage);
    const context = {
        window: { aiAssistant },
        jQuery: createJQuery(input),
        localStorage: storage,
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-conversations.js'),
        'utf8'
    );
    vm.runInContext(source, context);

    return { assistant: aiAssistant, input, storage };
}

describe('draft history', function() {
    it('loads at most the last ten saved user messages', function() {
        const saved = Array.from({ length: 12 }, function(_, index) {
            return 'message ' + index;
        });
        const { assistant, storage } = loadConversationMixin({
            aiAssistant_draftHistory: JSON.stringify(saved)
        });

        assistant.loadDraftHistory();

        assert.deepStrictEqual(Array.from(assistant.draftHistory), saved.slice(0, 10));
        assert.deepStrictEqual(
            JSON.parse(storage.getItem('aiAssistant_draftHistory')),
            saved.slice(0, 10)
        );
    });

    it('uses Up to browse older messages and Down to return to the draft', function() {
        const { assistant, input } = loadConversationMixin();
        assistant.draftHistory = ['newest', 'older', 'oldest'];
        input.value = 'unsent draft';

        assistant.navigateDraftHistory(1);
        assert.strictEqual(input.value, 'newest');
        assert.strictEqual(assistant.draftHistoryIndex, 0);
        assert.strictEqual(input.selectionStart, 'newest'.length);
        assert.strictEqual(input.selectionEnd, 'newest'.length);

        assistant.navigateDraftHistory(1);
        assert.strictEqual(input.value, 'older');
        assert.strictEqual(assistant.draftHistoryIndex, 1);

        assistant.navigateDraftHistory(-1);
        assert.strictEqual(input.value, 'newest');
        assert.strictEqual(assistant.draftHistoryIndex, 0);

        assistant.navigateDraftHistory(-1);
        assert.strictEqual(input.value, 'unsent draft');
        assert.strictEqual(assistant.draftHistoryIndex, -1);
    });
});
