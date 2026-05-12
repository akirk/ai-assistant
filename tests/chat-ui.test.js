const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadUiMixin() {
    const aiAssistant = {};
    const context = {
        window: { aiAssistant },
        jQuery: {
            extend(target, source) {
                return Object.assign(target, source);
            }
        },
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-ui.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return aiAssistant;
}

describe('REST API tool card descriptions', function() {
    it('extracts method and path from streamed rest_api arguments', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.extractPartialDescription(
                'rest_api',
                '{"method":"POST","path":"/wp/v2/posts"'
            ),
            'POST /wp/v2/posts'
        );
    });

    it('waits for both method and path before describing rest_api arguments', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.extractPartialDescription('rest_api', '{"method":"POST"'),
            null
        );
    });
});
