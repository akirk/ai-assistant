const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadCore(options) {
    options = options || {};

    function jQueryStub() {
        return {
            ready() {},
            trigger() {}
        };
    }
    jQueryStub.extend = function(target, source) {
        return Object.assign(target, source);
    };

    const context = {
        window: {
            aiAssistantToolCallbacks: options.queuedCallbacks || [],
            aiAssistantConfig: options.aiAssistantConfig || {}
        },
        aiAssistantConfig: options.aiAssistantConfig || {},
        document: options.document || {},
        jQuery: jQueryStub,
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-core.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return context.window.aiAssistant;
}

function createElement(tagName, attrs, children) {
    attrs = attrs || {};
    children = children || {};

    return {
        tagName: tagName.toUpperCase(),
        id: attrs.id || '',
        hidden: !!attrs.hidden,
        textContent: attrs.textContent || '',
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : '';
        },
        hasAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attrs, name);
        },
        querySelector(selector) {
            return children[selector] || null;
        }
    };
}

function createDocument(elements, labelledElements) {
    labelledElements = labelledElements || {};
    return {
        querySelectorAll() {
            return elements;
        },
        getElementById(id) {
            return labelledElements[id] || null;
        }
    };
}

describe('accessible page selector hints', function() {
    it('adds named content regions to the system prompt and selector config', function() {
        const heading = createElement('h2', { textContent: 'Selected note' });
        const selectedNote = createElement('section', {
            id: 'selected-note',
            'aria-labelledby': 'selected-note-heading',
            'data-ai-assistant-important': ''
        });
        const table = createElement('table', {}, {
            caption: createElement('caption', { textContent: 'Open invoices' })
        });
        const document = createDocument(
            [table, selectedNote],
            { 'selected-note-heading': heading }
        );
        const config = {
            systemPrompt: 'Base prompt.',
            pageSelectorHints: '- .existing: Existing server hint'
        };
        const assistant = loadCore({ document, aiAssistantConfig: config });

        assistant.buildSystemPrompt();

        assert.match(assistant.systemPrompt, /CURRENT PAGE CONTENT REGIONS/);
        assert.match(assistant.systemPrompt, /Selected note: #selected-note/);
        assert.match(assistant.systemPrompt, /Open invoices: table/);
        assert.ok(
            assistant.systemPrompt.indexOf('Selected note') < assistant.systemPrompt.indexOf('Open invoices'),
            'important regions should be listed first'
        );
        assert.match(config.pageSelectorHints, /\.existing/);
        assert.match(config.pageSelectorHints, /Selected note: #selected-note/);
    });

    it('ignores hidden or unnamed regions', function() {
        const hidden = createElement('section', {
            id: 'hidden-region',
            'aria-label': 'Hidden region',
            hidden: true
        });
        const unnamed = createElement('section', { id: 'unnamed-region' });
        const visible = createElement('section', {
            id: 'visible-region',
            'aria-label': 'Visible region'
        });
        const assistant = loadCore({
            document: createDocument([hidden, unnamed, visible]),
            aiAssistantConfig: { systemPrompt: 'Base prompt.' }
        });

        const hints = assistant.getAccessiblePageSelectorHints();

        assert.match(hints, /Visible region: #visible-region/);
        assert.doesNotMatch(hints, /Hidden region/);
        assert.doesNotMatch(hints, /unnamed-region/);
    });
});

describe('tool call callback API', function() {
    it('runs callbacks that match completed ability executions', function() {
        const assistant = loadCore();
        const calls = [];

        assistant.onToolCall(
            { ability: 'my-apps/set-background-color', success: true },
            function(context) {
                calls.push(context);
            }
        );

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'execute',
                ability: 'my-apps/set-background-color',
                arguments: { color: '#112233' }
            },
            result: { ok: true },
            success: true
        }, 'anthropic');

        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0].tool, 'ability');
        assert.strictEqual(calls[0].arguments.arguments.color, '#112233');
        assert.deepStrictEqual(calls[0].result, { ok: true });
    });

    it('does not run ability shorthand callbacks for schema lookups', function() {
        const assistant = loadCore();
        let count = 0;

        assistant.onToolCall({ ability: 'my-apps/set-background-color' }, function() {
            count++;
        });

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'get',
                ability: 'my-apps/set-background-color'
            },
            result: { name: 'my-apps/set-background-color' },
            success: true
        }, 'anthropic');

        assert.strictEqual(count, 0);
    });

    it('matches nested argument subsets', function() {
        const assistant = loadCore();
        let matched = false;

        assistant.onToolCall({
            tool: 'ability',
            arguments: {
                action: 'execute',
                arguments: { color: '#112233' }
            }
        }, function() {
            matched = true;
        });

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'execute',
                ability: 'my-apps/set-background-color',
                arguments: {
                    color: '#112233',
                    source: 'assistant'
                }
            },
            result: { ok: true },
            success: true
        }, 'anthropic');

        assert.strictEqual(matched, true);
    });

    it('supports unregistering callbacks', function() {
        const assistant = loadCore();
        let count = 0;

        const unregister = assistant.onToolCall('read_file', function() {
            count++;
        });
        unregister();

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'read_file',
            input: { path: 'plugins/demo/demo.php' },
            result: { content: '<?php' },
            success: true
        }, 'anthropic');

        assert.strictEqual(count, 0);
    });

    it('replays callbacks queued before chat-core loads', function() {
        const calls = [];
        const assistant = loadCore({
            queuedCallbacks: [
                {
                    criteria: { ability: 'my-apps/set-background-color' },
                    callback: function(context) {
                        calls.push(context.id);
                    }
                }
            ]
        });

        assistant.notifyToolCallCallbacks({
            id: 'tool-1',
            name: 'ability',
            input: {
                action: 'execute',
                ability: 'my-apps/set-background-color'
            },
            result: { ok: true },
            success: true
        }, 'anthropic');

        assert.deepStrictEqual(calls, ['tool-1']);
    });
});

describe('conversation preloading', function() {
    it('loads the most recent conversation once for non-full-page panels', function() {
        const assistant = loadCore();
        let loadCount = 0;

        assistant.isFullPage = false;
        assistant.loadMostRecentConversation = function() {
            loadCount++;
        };

        assistant.preloadMostRecentConversation();
        assistant.preloadMostRecentConversation();

        assert.strictEqual(loadCount, 1);
        assert.strictEqual(assistant.conversationPreloaded, true);
    });

    it('does not preload conversations on the full-page UI', function() {
        const assistant = loadCore();
        let loadCount = 0;

        assistant.isFullPage = true;
        assistant.loadMostRecentConversation = function() {
            loadCount++;
        };

        assistant.preloadMostRecentConversation();

        assert.strictEqual(loadCount, 0);
        assert.strictEqual(assistant.conversationPreloaded, false);
    });
});

describe('assistant panel resizing', function() {
    function createPanel(height) {
        const state = {
            height: height,
            classes: new Set(),
            attrs: {}
        };

        const container = {
            length: 1,
            0: {
                style: {
                    setProperty(name, value) {
                        if (name === '--ai-assistant-chat-height') {
                            state.height = parseFloat(value);
                        }
                    },
                    getPropertyValue(name) {
                        return name === '--ai-assistant-chat-height' ? state.height + 'px' : '';
                    }
                }
            },
            find(selector) {
                return selector === '#ai-assistant-expand'
                    ? {
                        length: 1,
                        attr(attrs) {
                            state.attrs = Object.assign(state.attrs, attrs);
                        }
                    }
                    : { length: 0 };
            },
            toggleClass(className, enabled) {
                if (enabled) {
                    state.classes.add(className);
                } else {
                    state.classes.delete(className);
                }
                return this;
            }
        };

        return { container, state };
    }

    it('toggles between maximum height and the previous non-maximum height', function() {
        const assistant = loadCore();
        const panel = createPanel(420);

        assistant.getAssistantPanelResizeLimits = function() {
            return { min: 300, max: 900 };
        };
        assistant.getAssistantPanelDefaultHeight = function() {
            return 380;
        };
        assistant.scrollToBottom = function() {};

        assistant.toggleAssistantPanelMax(panel.container);

        assert.strictEqual(panel.state.height, 900);
        assert.strictEqual(panel.state.classes.has('expanded'), true);
        assert.strictEqual(assistant.assistantPanelPreviousHeight, 420);

        assistant.toggleAssistantPanelMax(panel.container);

        assert.strictEqual(panel.state.height, 420);
        assert.strictEqual(panel.state.classes.has('expanded'), false);
        assert.strictEqual(panel.state.attrs['aria-valuenow'], 420);
    });
});
