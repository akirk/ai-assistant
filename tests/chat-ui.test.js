const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadUiMixin(config, globals) {
    const aiAssistant = {};
    globals = globals || {};
    const jQuery = globals.jQuery || {
        extend(target, ...sources) {
            return Object.assign(target, ...sources);
        }
    };
    if (!jQuery.extend) {
        jQuery.extend = function(target, ...sources) {
            return Object.assign(target, ...sources);
        };
    }
    const windowGlobals = {
        aiAssistant,
        aiAssistantConfig: config || {}
    };
    if (globals.aiAssistantProviders) {
        windowGlobals.aiAssistantProviders = globals.aiAssistantProviders;
    }
    const context = {
        window: windowGlobals,
        aiAssistantConfig: config || {},
        aiAssistantProviders: globals.aiAssistantProviders,
        wp: globals.wp,
        fetch: globals.fetch || fetch,
        URL,
        jQuery,
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

function createTokenCounterDom() {
    const counter = {
        htmlContent: '',
        attrs: {},
        classes: new Set()
    };

    function wrapper(element) {
        return {
            length: element ? 1 : 0,
            html(value) {
                if (!element) return value === undefined ? undefined : this;
                if (value === undefined) return element.htmlContent;
                element.htmlContent = String(value);
                return this;
            },
            removeAttr(name) {
                if (element) delete element.attrs[name];
                return this;
            },
            attr(name, value) {
                if (!element) return value === undefined ? undefined : this;
                if (value === undefined) return element.attrs[name];
                element.attrs[name] = String(value);
                return this;
            },
            removeClass(classNames) {
                if (element) {
                    String(classNames || '').split(/\s+/).filter(Boolean).forEach(className => {
                        element.classes.delete(className);
                    });
                }
                return this;
            },
            addClass(className) {
                if (element) element.classes.add(className);
                return this;
            }
        };
    }

    function $(selector) {
        return wrapper(selector === '#ai-token-count' ? counter : null);
    }

    $.extend = function(target, ...sources) {
        return Object.assign(target, ...sources);
    };

    return { $, counter };
}

function createToolCardsDom() {
    class Element {
        constructor(tagName, attrs, text) {
            this.tagName = tagName;
            this.attrs = Object.assign({}, attrs || {});
            this.classes = new Set();
            this.children = [];
            this.parent = null;
            this.textContent = text || '';
            this.styles = {};

            if (this.attrs.class) {
                this.attrs.class.split(/\s+/).filter(Boolean).forEach(className => {
                    this.classes.add(className);
                });
            }
        }
    }

    const byId = {};
    const chatContainer = new Element('div', { class: 'ai-assistant-chat-container' });
    const messages = new Element('div', { id: 'ai-assistant-messages' });
    messages.parent = chatContainer;
    chatContainer.children.push(messages);
    byId['ai-assistant-messages'] = messages;

    function register(element) {
        if (element.attrs.id) {
            byId[element.attrs.id] = element;
        }
        element.children.forEach(register);
    }

    function matches(element, selector) {
        selector = selector.trim();
        if (!selector) return false;
        const classAttrMatch = selector.match(/^\.([a-z0-9_-]+)\[([^=]+)="([^"]*)"\]$/i);
        if (classAttrMatch) {
            return element.classes.has(classAttrMatch[1]) &&
                element.attrs[classAttrMatch[2]] === classAttrMatch[3];
        }
        if (selector[0] === '.') {
            return element.classes.has(selector.slice(1));
        }
        if (selector[0] === '#') {
            return element.attrs.id === selector.slice(1);
        }
        return element.tagName === selector.toLowerCase();
    }

    function findAll(root, selector) {
        const selectors = selector.split(',').map(item => item.trim()).filter(Boolean);
        const results = [];

        function visit(element) {
            if (selectors.some(item => matches(element, item))) {
                results.push(element);
            }
            element.children.forEach(visit);
        }

        root.children.forEach(visit);
        return results;
    }

    function detach(element) {
        if (!element.parent) return;
        element.parent.children = element.parent.children.filter(child => child !== element);
        element.parent = null;
    }

    function wrapper(elements) {
        elements = elements || [];
        return {
            length: elements.length,
            elements,
            append(childWrapper) {
                if (typeof childWrapper === 'string') {
                    elements.forEach(parent => {
                        parent.textContent += childWrapper;
                    });
                    return this;
                }
                const children = childWrapper && childWrapper.elements ? childWrapper.elements : [];
                elements.forEach(parent => {
                    children.forEach(child => {
                        detach(child);
                        child.parent = parent;
                        parent.children.push(child);
                        register(child);
                    });
                });
                return this;
            },
            empty() {
                elements.forEach(element => {
                    element.children.forEach(child => {
                        child.parent = null;
                    });
                    element.children = [];
                    element.textContent = '';
                });
                return this;
            },
            attr(name, value) {
                if (value === undefined) {
                    return elements[0] ? elements[0].attrs[name] : undefined;
                }
                elements.forEach(element => {
                    element.attrs[name] = String(value);
                    if (name === 'id') {
                        byId[String(value)] = element;
                    }
                    if (name === 'class') {
                        element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
                    }
                });
                return this;
            },
            prop(name, value) {
                if (value === undefined) {
                    return elements[0] ? elements[0][name] : undefined;
                }
                elements.forEach(element => {
                    element[name] = value;
                });
                return this;
            },
            css(name, value) {
                if (value === undefined) {
                    return elements[0] ? elements[0].styles[name] : undefined;
                }
                elements.forEach(element => {
                    element.styles[name] = String(value);
                });
                return this;
            },
            removeAttr(name) {
                elements.forEach(element => {
                    delete element.attrs[name];
                });
                return this;
            },
            find(selector) {
                if (selector.indexOf('> ') === 0) {
                    const childSelector = selector.slice(2).trim();
                    return wrapper(elements.flatMap(element => element.children.filter(child => matches(child, childSelector))));
                }
                return wrapper(elements.flatMap(element => findAll(element, selector)));
            },
            children(selector) {
                let children = elements.flatMap(element => element.children);
                if (selector) {
                    children = children.filter(child => matches(child, selector));
                }
                return wrapper(children);
            },
            each(callback) {
                elements.forEach((element, index) => {
                    callback.call(element, index, element);
                });
                return this;
            },
            text(value) {
                if (value === undefined) {
                    return elements.map(element => element.textContent).join('');
                }
                elements.forEach(element => {
                    element.textContent = String(value);
                });
                return this;
            },
            addClass(classNames) {
                elements.forEach(element => {
                    String(classNames || '').split(/\s+/).filter(Boolean).forEach(className => {
                        element.classes.add(className);
                    });
                    element.attrs.class = Array.from(element.classes).join(' ');
                });
                return this;
            },
            removeClass(classNames) {
                elements.forEach(element => {
                    String(classNames || '').split(/\s+/).filter(Boolean).forEach(className => {
                        element.classes.delete(className);
                    });
                    element.attrs.class = Array.from(element.classes).join(' ');
                });
                return this;
            },
            toggleClass(className, enabled) {
                elements.forEach(element => {
                    if (enabled) {
                        element.classes.add(className);
                    } else {
                        element.classes.delete(className);
                    }
                    element.attrs.class = Array.from(element.classes).join(' ');
                });
                return this;
            },
            hasClass(className) {
                return !!(elements[0] && elements[0].classes.has(className));
            },
            last() {
                return wrapper(elements.length ? [elements[elements.length - 1]] : []);
            },
            outerHeight() {
                return elements[0] && elements[0].offsetHeight ? elements[0].offsetHeight : 0;
            },
            is(selector) {
                if (selector === ':visible') {
                    return !!elements[0] && elements[0].hidden !== true;
                }
                if (selector === ':last-child') {
                    const element = elements[0];
                    return !!(element && element.parent && element.parent.children[element.parent.children.length - 1] === element);
                }
                if (elements[0] && matches(elements[0], selector)) {
                    return true;
                }
                return false;
            },
            data(key, value) {
                if (!elements[0]) {
                    return value === undefined ? undefined : this;
                }
                elements.forEach(element => {
                    element.dataStore = element.dataStore || {};
                    if (value !== undefined) {
                        element.dataStore[key] = value;
                    }
                });
                return value === undefined ? elements[0].dataStore && elements[0].dataStore[key] : this;
            },
            removeData(key) {
                elements.forEach(element => {
                    if (element.dataStore) {
                        delete element.dataStore[key];
                    }
                });
                return this;
            },
            on() {
                return this;
            },
            off() {
                return this;
            }
        };
    }

    function createElementFromHtml(html) {
        const tagMatch = html.match(/^<([a-z0-9-]+)/i);
        const tagName = tagMatch ? tagMatch[1].toLowerCase() : 'div';
        const open = /\sopen(?:[>\s])/.test(html);
        const idMatch = html.match(/id="([^"]+)"/);
        const groupIdMatch = html.match(/data-tool-group-id="([^"]+)"/);
        const classMatch = html.match(/class="([^"]+)"/);
        const ariaLiveMatch = html.match(/aria-live="([^"]+)"/);
        const attrs = {
            id: idMatch ? idMatch[1] : undefined,
            class: classMatch ? classMatch[1] : undefined,
            'data-tool-group-id': groupIdMatch ? groupIdMatch[1] : undefined
        };
        if (ariaLiveMatch) {
            attrs['aria-live'] = ariaLiveMatch[1];
        }
        const element = new Element(tagName, attrs);
        const summaryClassMatch = html.match(/<summary[^>]*class="([^"]+)"/);
        const summaryTextMatch = html.match(/<summary[^>]*>(.*?)<\/summary>/);
        if (open) {
            element.attrs.open = '';
        }
        if (summaryTextMatch || summaryClassMatch) {
            const summary = new Element('summary', {
                class: summaryClassMatch ? summaryClassMatch[1] : ''
            }, summaryTextMatch ? summaryTextMatch[1] : '');
            summary.parent = element;
            element.children.push(summary);
        }
        register(element);
        return element;
    }

    function jQueryStub(selector) {
        if (selector instanceof Element) {
            return wrapper([selector]);
        }
        if (typeof selector === 'string' && selector[0] === '<') {
            return wrapper([createElementFromHtml(selector)]);
        }
        if (selector === '#ai-assistant-messages') {
            return wrapper([messages]);
        }
        if (selector === '.ai-assistant-chat-container') {
            return wrapper([chatContainer]);
        }
        if (typeof selector === 'string' && selector[0] === '#') {
            const element = byId[selector.slice(1)];
            return wrapper(element ? [element] : []);
        }
        if (typeof selector === 'string') {
            return wrapper(findAll(chatContainer, selector));
        }
        return wrapper([]);
    }

    jQueryStub.extend = function(target, ...sources) {
        return Object.assign(target, ...sources);
    };

    return {
        $: jQueryStub,
        get container() {
            return byId['ai-assistant-tool-cards'];
        },
        get group() {
            return messages.children.find(child => child.classes.has('ai-tool-cards-group'));
        },
        get summary() {
            const container = byId['ai-assistant-tool-cards'] ||
                messages.children.find(child => child.classes.has('ai-tool-cards-group'));
            return container && container.children[0];
        },
        get messages() {
            return messages;
        },
        get chatContainer() {
            return chatContainer;
        },
        getById(id) {
            return byId[id];
        }
    };
}

function imageResponse(body, type) {
    const blob = new Blob([body], { type });
    return {
        ok: true,
        status: 200,
        headers: {
            get(name) {
                return name.toLowerCase() === 'content-type' ? type : '';
            }
        },
        blob() {
            return Promise.resolve(blob);
        }
    };
}

describe('live tool card behavior', function() {
    it('keeps live tool cards in a summarized group when all tools finish', function() {
        const dom = createToolCardsDom();
        const assistant = loadUiMixin({}, { jQuery: dom.$ });
        assistant.escapeAttribute = value => String(value);

        const group = assistant.getToolCardsContainer().elements[0];
        assistant.toolCardsState = {
            'tool-1': {
                name: 'read_file',
                state: 'completed'
            }
        };

        assistant.updateToolCardsSummary();

        assert.strictEqual(group.tagName, 'details');
        assert.strictEqual(dom.summary.textContent, '1 tool: read_file - complete');
        assert.strictEqual(group.classes.has('ai-tool-cards-complete'), true);
    });

    it('reuses the current live tool card group with one summary', function() {
        const dom = createToolCardsDom();
        const assistant = loadUiMixin({}, { jQuery: dom.$ });
        assistant.escapeAttribute = value => String(value);

        const first = assistant.getToolCardsContainer().elements[0];
        assistant.getToolCardsContainer();

        assert.strictEqual(assistant.getToolCardsContainer().elements[0], first);
        assert.strictEqual(dom.messages.children.filter(child => child.classes.has('ai-tool-cards-group')).length, 1);
        assert.strictEqual(dom.summary.tagName, 'summary');
    });

    it('counts repeated tool names in group labels', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.toolGroupLabel([
                { name: 'read_file' },
                { name: 'read_file' },
                { name: 'find' }
            ]),
            '3 tools: read_file x2, find'
        );
    });
});

describe('file read context pruning', function() {
    function collectReadIds(messages) {
        const ids = [];
        messages.forEach(message => {
            if (!Array.isArray(message.content)) return;
            message.content.forEach(block => {
                if (block.type === 'tool_use' && block.name === 'read_file') {
                    ids.push(block.id);
                }
            });
        });
        return ids;
    }

    it('keeps different read_file chunks for the same path', function() {
        const assistant = loadUiMixin();
        assistant.messages = [
            {
                role: 'assistant',
                content: [
                    {
                        type: 'tool_use',
                        id: 'old-start',
                        name: 'read_file',
                        input: { path: 'plugins/demo/demo.php' }
                    }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'old-start', content: '{}' }
                ]
            },
            {
                role: 'assistant',
                content: [
                    {
                        type: 'tool_use',
                        id: 'old-offset',
                        name: 'read_file',
                        input: { path: 'plugins/demo/demo.php', offset: 65536 }
                    }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'old-offset', content: '{}' }
                ]
            }
        ];

        assistant.deduplicateFileReads([
            {
                id: 'new-offset',
                name: 'read_file',
                input: { path: 'plugins/demo/demo.php', offset: 131072 },
                result: { path: 'plugins/demo/demo.php' },
                success: true
            }
        ]);

        assert.deepStrictEqual(
            collectReadIds(assistant.messages),
            ['old-start', 'old-offset']
        );
    });

    it('deduplicates only matching read_file request windows', function() {
        const assistant = loadUiMixin();
        assistant.messages = [
            {
                role: 'assistant',
                content: [
                    {
                        type: 'tool_use',
                        id: 'old-start',
                        name: 'read_file',
                        input: { path: 'plugins/demo/demo.php' }
                    }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'old-start', content: '{}' }
                ]
            },
            {
                role: 'assistant',
                content: [
                    {
                        type: 'tool_use',
                        id: 'old-offset',
                        name: 'read_file',
                        input: { path: 'plugins/demo/demo.php', offset: 65536 }
                    }
                ]
            },
            {
                role: 'user',
                content: [
                    { type: 'tool_result', tool_use_id: 'old-offset', content: '{}' }
                ]
            }
        ];

        assistant.deduplicateFileReads([
            {
                id: 'new-offset',
                name: 'read_file',
                input: { path: 'plugins/demo/demo.php', offset: 65536 },
                result: { path: 'plugins/demo/demo.php' },
                success: true
            }
        ]);

        assert.deepStrictEqual(
            collectReadIds(assistant.messages),
            ['old-start']
        );
        assert.strictEqual(
            assistant.messages.some(message => Array.isArray(message.content) && message.content.some(block => block.tool_use_id === 'old-offset')),
            false
        );
    });
});

describe('partial tool descriptions', function() {
    it('passes read_file range parameters from partial JSON into the description formatter', function() {
        const assistant = loadUiMixin();
        let captured = null;
        assistant.getActionDescription = function(toolName, args) {
            captured = { toolName, args };
            return 'formatted read';
        };

        assert.strictEqual(
            assistant.extractPartialDescription(
                'read_file',
                '{"path":"plugins/demo/demo.php","offset":65536,"max_length":8192'
            ),
            'formatted read'
        );
        assert.deepStrictEqual(JSON.parse(JSON.stringify(captured)), {
            toolName: 'read_file',
            args: {
                path: 'plugins/demo/demo.php',
                offset: 65536,
                max_length: 8192
            }
        });
    });

    it('passes find path and glob from partial JSON into the description formatter', function() {
        const assistant = loadUiMixin();
        let captured = null;
        assistant.getActionDescription = function(toolName, args) {
            captured = { toolName, args };
            return 'formatted find';
        };

        assert.strictEqual(
            assistant.extractPartialDescription(
                'find',
                '{"glob":"*","path":"plugins/two"'
            ),
            'formatted find'
        );
        assert.deepStrictEqual(JSON.parse(JSON.stringify(captured)), {
            toolName: 'find',
            args: {
                path: 'plugins/two',
                glob: '*'
            }
        });
    });
});

describe('welcome message', function() {
    it('blends configured tips into the assistant welcome message', function() {
        const assistant = loadUiMixin({
            homeUrl: 'http://example.test',
            welcomeTips: [
                'Tip: Ask me to make a recipe vegan or low carb.',
                'Missing an ingredient? Ask me for substitutions.'
            ]
        });

        const message = assistant.buildWelcomeMessage();

        assert.match(message, /Hello! I'm your AI Assistant/);
        assert.match(message, /A few tips for this area of your WordPress:/);
        assert.match(message, /- Ask me to make a recipe vegan or low carb\./);
        assert.match(message, /- Missing an ingredient\? Ask me for substitutions\./);
        assert.doesNotMatch(message, /- Tip:/);
    });

    it('uses a My WordPress-specific welcome intro on my.wordpress.net', function() {
        const assistant = loadUiMixin({
            homeUrl: 'https://my.wordpress.net/scope:default',
            welcomeTips: []
        });

        const message = assistant.buildWelcomeMessage();

        assert.match(message, /shape My WordPress into your personal software home/);
        assert.doesNotMatch(message, /manage your WordPress installation - read and modify files/);
    });

    it('uses Connector provider names in the model info line', function() {
        const assistant = loadUiMixin({}, {
            aiAssistantProviders: {
                available: {
                    lmstudio: {
                        name: 'LM Studio'
                    }
                }
            }
        });

        assert.strictEqual(assistant.getProviderName('lmstudio'), 'LM Studio');
    });

    it('uses model display names in the model info line', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getModelInfoDisplayName('anthropic', 'claude-sonnet-4-6', [
                { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' }
            ]),
            'Claude Sonnet 4.6'
        );
    });
});

describe('token usage accounting', function() {
    it('summarizes context tokens separately from cumulative input and output usage', function() {
        const assistant = loadUiMixin();
        assistant.systemPrompt = '1234';
        assistant.messages = [
            { role: 'user', content: '12345678' },
            {
                role: 'assistant',
                content: '1234',
                _usage: {
                    version: 1,
                    source: 'provider',
                    provider: 'openai',
                    model: 'gpt-test',
                    input_tokens: 10,
                    output_tokens: 2,
                    total_tokens: 12
                }
            },
            { role: 'user', content: '1234' },
            { role: 'assistant', content: '12345678' }
        ];

        const usage = assistant.getTokenUsageSummary();

        assert.strictEqual(Object.prototype.hasOwnProperty.call(usage, 'context_tokens'), false);
        assert.strictEqual(usage.input_tokens, 15);
        assert.strictEqual(usage.output_tokens, 4);
        assert.strictEqual(usage.total_tokens, 19);
        assert.strictEqual(usage.source, 'mixed');
    });

    it('includes subagent usage and estimates saved follow-up context tokens', function() {
        const assistant = loadUiMixin();
        assistant.messages = [
            {
                role: 'assistant',
                content: null,
                tool_calls: [{
                    id: 'delegate-parent',
                    type: 'function',
                    function: {
                        name: 'delegate',
                        arguments: '{}'
                    }
                }],
                _usage: {
                    version: 1,
                    source: 'provider',
                    input_tokens: 10,
                    output_tokens: 2,
                    total_tokens: 12
                },
                _subagent_usage: {
                    version: 1,
                    source: 'provider',
                    input_tokens: 30,
                    output_tokens: 8,
                    total_tokens: 38
                }
            },
            { role: 'tool', tool_call_id: 'delegate-parent', content: '{"summary":"short report"}' },
            {
                role: 'assistant',
                content: 'Done',
                _usage: {
                    version: 1,
                    source: 'provider',
                    input_tokens: 20,
                    output_tokens: 4,
                    total_tokens: 24
                }
            }
        ];

        const usage = assistant.getTokenUsageSummary();

        assert.strictEqual(usage.input_tokens, 60);
        assert.strictEqual(usage.output_tokens, 14);
        assert.strictEqual(usage.total_tokens, 74);
        assert.strictEqual(usage.subagent_input_tokens, 30);
        assert.strictEqual(usage.subagent_output_tokens, 8);
        assert.strictEqual(usage.subagent_total_tokens, 38);
        assert.strictEqual(usage.subagent_saved_tokens, 38);
        assert.strictEqual(usage.subagent_source, 'provider');
    });

    it('normalizes provider usage details for OpenAI-style responses', function() {
        const assistant = loadUiMixin();

        const usage = assistant.normalizeTokenUsage({
            prompt_tokens: 100,
            completion_tokens: 20,
            total_tokens: 120,
            prompt_tokens_details: {
                cached_tokens: 40
            },
            completion_tokens_details: {
                reasoning_tokens: 7
            }
        }, 'openai', 'gpt-test', 'provider');

        assert.strictEqual(usage.input_tokens, 100);
        assert.strictEqual(usage.output_tokens, 20);
        assert.strictEqual(usage.total_tokens, 120);
        assert.strictEqual(usage.cached_input_tokens, 40);
        assert.strictEqual(usage.cache_creation_input_tokens, 0);
        assert.strictEqual(usage.cache_read_input_tokens, 40);
        assert.strictEqual(usage.reasoning_output_tokens, 7);
        assert.strictEqual(usage.source, 'provider');
    });

    it('normalizes Anthropic cache read and cache creation usage separately', function() {
        const assistant = loadUiMixin();

        const usage = assistant.normalizeTokenUsage({
            input_tokens: 50,
            cache_creation_input_tokens: 1000,
            cache_read_input_tokens: 90000,
            output_tokens: 25,
            output_tokens_details: {
                thinking_tokens: 5
            }
        }, 'anthropic', 'claude-test', 'provider');

        assert.strictEqual(usage.input_tokens, 91050);
        assert.strictEqual(usage.output_tokens, 25);
        assert.strictEqual(usage.total_tokens, 91075);
        assert.strictEqual(usage.cached_input_tokens, 91000);
        assert.strictEqual(usage.cache_creation_input_tokens, 1000);
        assert.strictEqual(usage.cache_read_input_tokens, 90000);
        assert.strictEqual(usage.reasoning_output_tokens, 5);
        assert.strictEqual(usage.raw_usage.cache_read_input_tokens, 90000);
    });

    it('creates fallback input estimates when provider usage is unavailable', function() {
        const assistant = loadUiMixin();
        assistant.systemPrompt = '1234';
        assistant.messages = [{ role: 'user', content: '12345678' }];
        assistant.conversationProvider = 'openai';

        const fallback = assistant.createEstimatedTokenUsage(
            { role: 'assistant', content: '1234' },
            'openai',
            'gpt-test',
            [{ role: 'system', content: '1234' }, { role: 'user', content: '12345678' }]
        );

        assert.strictEqual(fallback.input_tokens, assistant.estimateTokensForMessages([
            { role: 'system', content: '1234' },
            { role: 'user', content: '12345678' }
        ], false));
    });

    it('labels token usage as sent to and received from the AI', function() {
        const dom = createTokenCounterDom();
        const assistant = loadUiMixin({}, { jQuery: dom.$ });
        assistant.messages = [
            {
                role: 'assistant',
                content: 'Done',
                _usage: {
                    version: 1,
                    source: 'provider',
                    input_tokens: 17,
                    output_tokens: 4,
                    total_tokens: 21,
                    cache_creation_input_tokens: 2,
                    cache_read_input_tokens: 3,
                    cached_input_tokens: 5
                }
            }
        ];

        assistant.updateTokenCount();

        assert.match(dom.counter.htmlContent, /18 fresh tokens/);
        assert.match(dom.counter.htmlContent, /Fresh sent and received/);
        assert.match(dom.counter.htmlContent, /Total processed/);
        assert.match(dom.counter.htmlContent, /Sent to AI \(input\)/);
        assert.match(dom.counter.htmlContent, /Read from cache/);
        assert.match(dom.counter.htmlContent, /Written to cache/);
        assert.match(dom.counter.htmlContent, /Received from AI \(output\)/);
        assert.match(dom.counter.attrs['aria-label'], /18 fresh tokens/);
        assert.match(dom.counter.attrs['aria-label'], /Sent to AI 17, received from AI 4, read from cache 3/);
    });

    it('shows subagent token usage and savings in the token tooltip', function() {
        const dom = createTokenCounterDom();
        const assistant = loadUiMixin({}, { jQuery: dom.$ });
        assistant.messages = [
            {
                role: 'assistant',
                content: null,
                tool_calls: [{ id: 'delegate-parent', type: 'function', function: { name: 'delegate', arguments: '{}' } }],
                _usage: {
                    version: 1,
                    source: 'provider',
                    input_tokens: 10,
                    output_tokens: 2,
                    total_tokens: 12
                },
                _subagent_usage: {
                    version: 1,
                    source: 'provider',
                    input_tokens: 30,
                    output_tokens: 8,
                    total_tokens: 38
                }
            },
            {
                role: 'assistant',
                content: 'Done',
                _usage: {
                    version: 1,
                    source: 'provider',
                    input_tokens: 20,
                    output_tokens: 4,
                    total_tokens: 24
                }
            }
        ];

        assistant.updateTokenCount();

        assert.match(dom.counter.htmlContent, /ai-token-section-title">Subagents/);
        assert.match(dom.counter.htmlContent, /Subagents[\s\S]*Of which subagents/);
        assert.match(dom.counter.htmlContent, /Of which subagents/);
        assert.match(dom.counter.htmlContent, /Saved through subagents/);
        assert.match(dom.counter.attrs['aria-label'], /subagents used 38/);
        assert.match(dom.counter.attrs['aria-label'], /saved through subagents 38/);
    });
});

describe('model lifecycle notices', function() {
    it('renders a warning when the active model has a replacement', function() {
        const assistant = loadUiMixin({ settingsUrl: 'http://example.test/settings' });
        const messages = [];

        assistant.getModelUpgradeInfo = function() {
            return {
                severity: 'warning',
                status: 'deprecated',
                replacement: 'claude-sonnet-4-6',
                replacementName: 'Claude Sonnet 4.6',
                retirement: 'June 15, 2026'
            };
        };
        assistant.addMessage = function(role, content, extraClass) {
            messages.push({ role, content, extraClass });
        };

        assistant.showModelUpgradeNotice('anthropic', 'claude-sonnet-4-20250514');

        assert.strictEqual(messages.length, 1);
        assert.strictEqual(messages[0].role, 'system');
        assert.strictEqual(messages[0].extraClass, 'ai-model-warning');
        assert.match(messages[0].content, /deprecated/);
        assert.match(messages[0].content, /claude-sonnet-4-6/);
        assert.match(messages[0].content, /June 15, 2026/);
        assert.match(messages[0].content, /Settings/);
    });

    it('renders a note when a newer model exists but the active model is not outdated', function() {
        const assistant = loadUiMixin({ settingsUrl: 'http://example.test/settings' });
        const messages = [];

        assistant.getModelUpgradeInfo = function() {
            return {
                severity: 'note',
                status: 'newer_available',
                replacement: 'claude-sonnet-4-6',
                replacementName: 'Claude Sonnet 4.6'
            };
        };
        assistant.addMessage = function(role, content, extraClass) {
            messages.push({ role, content, extraClass });
        };

        assistant.showModelUpgradeNotice('anthropic', 'claude-sonnet-4-5-20250929');

        assert.strictEqual(messages.length, 1);
        assert.strictEqual(messages[0].role, 'system');
        assert.strictEqual(messages[0].extraClass, 'ai-model-note');
        assert.match(messages[0].content, /Model note/);
        assert.match(messages[0].content, /Claude Sonnet 4\.6/);
        assert.doesNotMatch(messages[0].content, /warning/i);
    });

    it('does not render a model lifecycle message when no replacement is available', function() {
        const assistant = loadUiMixin();
        let count = 0;

        assistant.getModelUpgradeInfo = function() {
            return null;
        };
        assistant.addMessage = function() {
            count++;
        };

        assistant.showModelUpgradeNotice('anthropic', 'claude-sonnet-4-6');

        assert.strictEqual(count, 0);
    });
});

describe('pick_image media upload helpers', function() {
    it('builds a safe filename from selected image metadata', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getPickedImageFileName({
                title: 'File:Summer Background.jpeg',
                url: 'https://images.example.test/source.png?size=large'
            }, 'image/png'),
            'summer-background.png'
        );

        assert.strictEqual(
            assistant.getPickedImageFileName({ title: '東京', url: '' }, 'image/webp'),
            'openverse-image.webp'
        );
    });

    it('infers image MIME types from URLs only when the extension is clear', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getPickedImageMimeType('https://images.example.test/source.jpg', 'application/octet-stream'),
            'image/jpeg'
        );
        assert.strictEqual(
            assistant.getPickedImageMimeType('https://images.example.test/download', 'application/octet-stream'),
            ''
        );
    });

    it('shortens image source URLs to readable domains', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getShortImageSourceDomain('https://images.example.test/source.jpg'),
            'example.test'
        );
        assert.strictEqual(
            assistant.getShortImageSourceDomain('https://cdn.assets.example.co.uk/file.png'),
            'example.co.uk'
        );
        assert.strictEqual(
            assistant.getShortImageSourceDomain(''),
            'source'
        );
    });

    it('builds upload data from a dropped image file', async function() {
        const assistant = loadUiMixin({ maxMediaUploadBytes: 1024 });
        const file = {
            name: 'My Vacation.PNG',
            type: 'image/png',
            size: 64
        };

        const fileData = await assistant.getPickedImageFileData(file);

        assert.strictEqual(fileData.blob, file);
        assert.strictEqual(fileData.contentType, 'image/png');
        assert.strictEqual(fileData.filename, 'my-vacation.png');
        assert.strictEqual(assistant.getPickedImageFileTitle(file), 'My Vacation');
    });

    it('builds uploaded image results with attachment_id and local URL', function() {
        const assistant = loadUiMixin();
        const result = assistant.buildPickedImageResult({
            url: 'https://cdn.example.test/remote.jpg',
            thumbnail: 'https://cdn.example.test/thumb.jpg',
            title: 'Selected image',
            creator: 'Creator',
            license: 'CC0',
            attribution: 'Selected image - by Creator - CC0'
        }, {
            id: 123,
            source_url: 'http://example.test/wp-content/uploads/image.jpg',
            link: 'http://example.test/image/',
            mime_type: 'image/jpeg',
            media_type: 'image',
            media_details: {
                sizes: {
                    thumbnail: {
                        source_url: 'http://example.test/wp-content/uploads/image-150x150.jpg'
                    }
                }
            }
        });

        assert.strictEqual(result.attachment_id, 123);
        assert.strictEqual(result.id, 123);
        assert.strictEqual(result.url, 'http://example.test/wp-content/uploads/image.jpg');
        assert.strictEqual(result.remote_url, 'https://cdn.example.test/remote.jpg');
        assert.strictEqual(result.thumbnail, 'http://example.test/wp-content/uploads/image-150x150.jpg');
        assert.strictEqual(result.uploaded, true);
        assert.strictEqual(result.external, false);
    });

    it('offers remote URL after upload failure', async function() {
        const assistant = loadUiMixin();
        const image = {
            url: 'https://cdn.example.test/remote.jpg',
            title: 'Selected image'
        };

        assistant.fetchPickedImageBlob = function() {
            return Promise.reject(new Error('Failed to fetch'));
        };

        const fallback = await assistant.preparePickedImageSelection(image, {});
        assert.strictEqual(fallback.success, false);
        assert.strictEqual(fallback.can_use_external, true);
        assert.strictEqual(fallback.selection.url, image.url);
        assert.strictEqual(fallback.selection.source_url, image.url);
        assert.strictEqual(fallback.selection.external, true);
        assert.strictEqual(fallback.selection.upload_failed, true);
    });

    it('falls back to the Openverse thumbnail when the source image cannot be fetched', async function() {
        const sourceUrl = 'https://cdn.stocksnap.io/img-thumbs/960w/XNVBVXO3B7.jpg';
        const thumbnailUrl = 'https://api.openverse.org/v1/images/abc/thumb/';
        const fetches = [];
        const statuses = [];
        const assistant = loadUiMixin({}, {
            fetch(url) {
                fetches.push(url);
                if (url === sourceUrl) {
                    return Promise.resolve({
                        ok: false,
                        status: 403,
                        headers: {
                            get() {
                                return 'text/html';
                            }
                        },
                        blob() {
                            return Promise.resolve(new Blob([]));
                        }
                    });
                }
                return Promise.resolve(imageResponse('thumbnail image', 'application/octet-stream'));
            }
        });

        const fileData = await assistant.fetchPickedImageBlob({
            url: sourceUrl,
            thumbnail: thumbnailUrl,
            title: 'Tree Bark Photo'
        }, null, function(message) {
            statuses.push(message);
        });

        assert.deepStrictEqual(fetches, [sourceUrl, thumbnailUrl]);
        assert.strictEqual(fileData.contentType, 'image/jpeg');
        assert.strictEqual(fileData.filename, 'tree-bark-photo.jpg');
        assert.strictEqual(fileData.sourceUrl, thumbnailUrl);
        assert.strictEqual(fileData.usedThumbnailFallback, true);
        assert.ok(statuses.includes('Full image unavailable. Trying preview image...'));
    });

    it('marks uploaded thumbnail fallbacks as low resolution', async function() {
        const assistant = loadUiMixin();

        assistant.fetchPickedImageBlob = function() {
            return Promise.resolve({
                blob: {},
                filename: 'preview.jpg',
                contentType: 'image/jpeg',
                usedThumbnailFallback: true
            });
        };
        assistant.uploadPickedImageToMediaLibrary = function() {
            return Promise.resolve({
                id: 987,
                source_url: 'http://example.test/wp-content/uploads/preview.jpg'
            });
        };

        const result = await assistant.preparePickedImageSelection({
            url: 'https://cdn.stocksnap.io/source.jpg',
            thumbnail: 'https://api.openverse.org/v1/images/abc/thumb/',
            title: 'Preview'
        }, {});

        assert.strictEqual(result.success, true);
        assert.strictEqual(result.selection.low_resolution, true);
        assert.strictEqual(result.selection.uploaded_from_thumbnail, true);
        assert.strictEqual(result.selection.note, 'low resolution image');
    });

    it('uploads a dropped image file through the Media Library path', async function() {
        const assistant = loadUiMixin();
        const signal = { aborted: false };
        const statuses = [];
        const file = {
            name: 'Dropped Photo.jpg',
            type: 'image/jpeg',
            size: 128
        };
        let uploadedFileData = null;
        let uploadedImage = null;
        let uploadSignal = null;

        assistant.uploadPickedImageToMediaLibrary = function(fileData, image, passedSignal) {
            uploadedFileData = fileData;
            uploadedImage = image;
            uploadSignal = passedSignal;
            return Promise.resolve({
                id: 654,
                source_url: 'http://example.test/wp-content/uploads/dropped-photo.jpg',
                media_details: {
                    sizes: {
                        thumbnail: {
                            source_url: 'http://example.test/wp-content/uploads/dropped-photo-150x150.jpg'
                        }
                    }
                }
            });
        };

        const result = await assistant.preparePickedImageFileSelection(file, function(message) {
            statuses.push(message);
        }, signal);

        assert.strictEqual(result.success, true);
        assert.strictEqual(result.selection.attachment_id, 654);
        assert.strictEqual(result.selection.url, 'http://example.test/wp-content/uploads/dropped-photo.jpg');
        assert.strictEqual(result.selection.remote_url, '');
        assert.strictEqual(result.selection.title, 'Dropped Photo');
        assert.strictEqual(uploadedFileData.blob, file);
        assert.strictEqual(uploadedFileData.filename, 'dropped-photo.jpg');
        assert.strictEqual(uploadedImage.source, 'local_upload');
        assert.strictEqual(uploadSignal, signal);
        assert.ok(statuses.includes('Uploading dropped image to Media Library...'));
    });

    it('passes abort signals through image fetch and media upload', async function() {
        const assistant = loadUiMixin();
        const signal = { aborted: false };
        let fetchSignal = null;
        let uploadSignal = null;

        assistant.fetchPickedImageBlob = function(image, passedSignal) {
            fetchSignal = passedSignal;
            return Promise.resolve({
                blob: {},
                filename: 'selected-image.jpg',
                contentType: 'image/jpeg'
            });
        };
        assistant.uploadPickedImageToMediaLibrary = function(fileData, image, passedSignal) {
            uploadSignal = passedSignal;
            return Promise.resolve({
                id: 321,
                source_url: 'http://example.test/wp-content/uploads/selected-image.jpg'
            });
        };

        const result = await assistant.preparePickedImageSelection({
            url: 'https://cdn.example.test/remote.jpg',
            title: 'Selected image'
        }, {}, null, signal);

        assert.strictEqual(result.success, true);
        assert.strictEqual(result.selection.attachment_id, 321);
        assert.strictEqual(fetchSignal, signal);
        assert.strictEqual(uploadSignal, signal);
    });
});

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

    it('extracts inspect_tool_result descriptions without exposing the tool call id', function() {
        const assistant = loadUiMixin();

        const description = assistant.extractPartialDescription(
            'inspect_tool_result',
            '{"tool_use_id":"toolu_secret_123","path":"result.article.content","search":"Gemeindebezirke"'
        );

        assert.strictEqual(
            description,
            'Inspect cached result: result.article.content around "Gemeindebezirke"'
        );
        assert.doesNotMatch(description, /toolu_secret_123/);
    });
});

describe('tool result display', function() {
    it('renders read_file results as file content with the file language', function() {
        const assistant = loadUiMixin();
        assistant.getLanguageFromPath = function(path) {
            return path.endsWith('.php') ? 'php' : null;
        };

        const display = assistant.getToolResultDisplay('read_file', {
            path: 'plugins/example/example.php',
            content: "<?php\necho 'Hello';\n",
            size: 22
        });

        assert.strictEqual(display.text, "<?php\necho 'Hello';\n");
        assert.strictEqual(display.language, 'php');
        assert.strictEqual(display.label, 'Content');
    });

    it('renders inspected tool result content without exposing the tool call id', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('inspect_tool_result', {
            tool_use_id: 'toolu_secret_123',
            tool: 'ability',
            path: 'result.article.content',
            search: 'Gemeindebezirke',
            match_found: true,
            content: '<h2>Gemeindebezirke</h2>\n<p>Bezirk 1</p>'
        });

        assert.strictEqual(display.text, '<h2>Gemeindebezirke</h2>\n<p>Bezirk 1</p>');
        assert.strictEqual(display.label, 'Inspected match');
        assert.doesNotMatch(display.text, /toolu_secret_123/);
    });

    it('renders compacted inspected result previews instead of wrapper instructions', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('inspect_tool_result', {
            _truncated: true,
            tool_use_id: 'toolu_secret_123',
            path: 'sessions',
            type: 'json',
            content_preview: 'Playground session details',
            instruction: 'This inspect_tool_result response was compacted.'
        });

        assert.strictEqual(display.text, 'Playground session details');
        assert.strictEqual(display.label, 'Inspected preview');
        assert.doesNotMatch(display.text, /toolu_secret_123/);
        assert.doesNotMatch(display.text, /compacted/);
    });

    it('renders compacted ability summaries even when result is omitted', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('ability', {
            _truncated: true,
            ability: 'wordcamp-companion/get-schedule',
            success: true,
            type: 'object',
            keys: ['ability', 'success', 'event_url', 'sessions'],
            returned_to_llm_truncated: true,
            inspect_tool_result: {
                tool_use_id: 'toolu_secret_123',
                instruction: 'Use inspect_tool_result for slices.'
            }
        });

        assert.strictEqual(display.language, 'json');
        assert.strictEqual(display.label, 'Result');
        assert.match(display.text, /wordcamp-companion\/get-schedule/);
        assert.match(display.text, /returned_to_llm_truncated/);
        assert.doesNotMatch(display.text, /undefined/);
    });

    it('renders inspected structured values as the value only', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('inspect_tool_result', {
            tool_use_id: 'toolu_secret_123',
            tool: 'ability',
            path: 'result.meta',
            type: 'object',
            value: { title: 'Wien', sections: 12 }
        });

        assert.strictEqual(display.language, 'json');
        assert.match(display.text, /"title": "Wien"/);
        assert.doesNotMatch(display.text, /toolu_secret_123/);
        assert.strictEqual(display.label, 'Inspected value');
    });

    it('marks structured tool results as JSON', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('db_query', {
            query: 'SELECT option_name FROM wp_options',
            rows: [{ option_name: 'siteurl' }],
            count: 1
        });

        assert.strictEqual(display.language, 'json');
        assert.match(display.text, /"rows"/);
        assert.match(display.text, /"siteurl"/);
    });

    it('detects JSON returned as a string', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('run_php', {
            output: '{"name":"Ada","active":true}'
        });

        assert.strictEqual(display.language, 'json');
        assert.strictEqual(display.text, '{"name":"Ada","active":true}');
    });

    it('renders delegate results with subagent details', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('delegate', {
            task_type: 'codebase_investigation',
            summary: 'Summary: found the relevant code.',
            available_tools: ['find', 'read_file'],
            max_rounds: 6,
            tool_calls: [
                {
                    name: 'find',
                    input: { text: 'needle', mode: 'paths' },
                    success: true
                }
            ],
            sources: [
                { type: 'file', label: 'plugins/example/app.php' }
            ],
            omitted: {
                tool_result_count: 1,
                round_limit_reached: false,
                final_synthesis: false
            }
        });

        assert.strictEqual(display.label, 'Delegate report');
        assert.strictEqual(display.language, 'markdown');
        assert.strictEqual(display.lineNumbers, false);
        assert.match(display.text, /Task: codebase_investigation/);
        assert.match(display.text, /Available subagent tools: find, read_file/);
        assert.match(display.text, /Tool round budget: 6/);
        assert.match(display.text, /Hidden tool calls: 1/);
        assert.match(display.text, /find \{"text":"needle","mode":"paths"\} ok/);
        assert.match(display.text, /file: plugins\/example\/app\.php/);
        assert.match(display.text, /Report:\nSummary: found the relevant code\./);
    });

    it('labels delegate live meta with round budget and available tools', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.formatDelegateLiveMeta({
                task_type: 'codebase_investigation',
                round: 2,
                max_rounds: 6,
                available_tools: ['find', 'read_file']
            }),
            'codebase_investigation - round 2/6 - available tools: find, read_file'
        );
    });

    it('normalizes language classes for highlighted code blocks', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(assistant.getCodeLanguageClass('json'), 'ai-language-json');
        assert.strictEqual(assistant.getCodeLanguageClass('text/x-sql'), 'ai-language-text-x-sql');
    });

    it('normalizes JavaScript-labelled JSON content to JSON highlighting', function() {
        const assistant = loadUiMixin();
        const classList = ['ai-language-javascript'];
        classList.add = function(className) {
            if (!this.includes(className)) this.push(className);
        };
        classList.remove = function(className) {
            const index = this.indexOf(className);
            if (index >= 0) this.splice(index, 1);
        };
        const element = {
            textContent: '',
            classList
        };

        assistant.highlightCode(element, '{"id":3360212}', 'javascript', false);

        assert.ok(classList.includes('ai-language-json'));
        assert.ok(!classList.includes('ai-language-javascript'));
        assert.strictEqual(element.textContent, '{"id":3360212}');
    });

    it('can highlight CodeMirror output without adding line numbers', function() {
        const runModeCalls = [];
        const assistant = loadUiMixin({}, {
            wp: {
                CodeMirror: {
                    getMode(config, modeName) {
                        return modeName;
                    },
                    runMode(code, mode, element) {
                        runModeCalls.push({ code, mode });
                        element.innerHTML = '<span class="cm-header"># Heading</span>';
                    }
                }
            }
        });
        let addLineNumbersCalled = false;
        assistant.addLineNumbers = function() {
            addLineNumbersCalled = true;
        };
        const classes = [];
        const element = {
            textContent: '',
            innerHTML: '',
            classList: {
                add(className) {
                    classes.push(className);
                },
                remove() {}
            }
        };

        assistant.highlightCode(element, '# Heading', 'markdown', false, { lineNumbers: false });

        assert.deepStrictEqual(runModeCalls, [{ code: '# Heading', mode: 'markdown' }]);
        assert.equal(addLineNumbersCalled, false);
        assert.ok(classes.includes('ai-language-markdown'));
        assert.ok(classes.includes('cm-s-default'));
    });

    it('marks CodeMirror JSON string tokens followed by a colon as keys', function() {
        const assistant = loadUiMixin();
        const classes = [];
        const valueClasses = [];
        const key = {
            nextSibling: { nodeType: 3, textContent: ': ' },
            classList: {
                add(className) {
                    classes.push(className);
                }
            }
        };
        const value = {
            nextSibling: { nodeType: 3, textContent: ',' },
            classList: {
                add(className) {
                    valueClasses.push(className);
                }
            }
        };

        assistant.markJsonPropertyTokens({
            querySelectorAll(selector) {
                assert.strictEqual(selector, '.cm-string');
                return [key, value];
            }
        });

        assert.deepStrictEqual(classes, ['ai-json-key', 'cm-property']);
        assert.deepStrictEqual(valueClasses, []);
    });
});

describe('AI changes links', function() {
    it('uses server-reported AI Changes metadata for read_file links', function() {
        const assistant = loadUiMixin({
            aiChangesUrl: 'http://example.test/wp-admin/tools.php?page=ai-changes'
        });

        assert.strictEqual(
            assistant.getAiChangesRootFromToolCall(
                'read_file',
                { path: 'plugins/example/includes/admin.php' },
                {
                    path: 'plugins/example/includes/admin.php',
                    ai_changes: {
                        root: 'plugins/example',
                        type: 'plugin'
                    }
                }
            ),
            'plugins/example'
        );

        assert.strictEqual(
            assistant.getAiChangesUrlForRoot('plugins/example'),
            'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fexample'
        );
    });

    it('does not infer AI Changes links from paths or plugin slugs alone', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getAiChangesRootFromToolCall(
                'write_file',
                { path: 'plugins/input-plugin/main.php' },
                {
                    path: 'plugins/result-plugin/main.php',
                    plugin_slug: 'ignored-slug'
                }
            ),
            ''
        );

        assert.strictEqual(
            assistant.getAiChangesRootFromToolCall(
                'write_file',
                {},
                { plugin_slug: 'ignored-slug' }
            ),
            ''
        );
    });

    it('uses nested server metadata from recovery and ability results', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getAiChangesRootFromToolCall(
                'edit_file',
                { path: 'plugins/broken-plugin/main.php' },
                {
                    error: 'Plugin file change broke WordPress.',
                    file_result: {
                        path: 'plugins/broken-plugin/main.php',
                        ai_changes: {
                            root: 'plugins/broken-plugin',
                            type: 'plugin'
                        }
                    }
                }
            ),
            'plugins/broken-plugin'
        );

        assert.strictEqual(
            assistant.getAiChangesRootFromToolCall(
                'ability',
                { ability: 'ai/create-wp-app' },
                {
                    result: {
                        plugin_file: '/var/www/html/wp-content/plugins/my-app/my-app.php',
                        plugin_slug: 'ignored-slug',
                        ai_changes: {
                            root: 'plugins/my-app',
                            type: 'plugin'
                        }
                    }
                }
            ),
            'plugins/my-app'
        );
    });

    it('renders AI Changes as a conversation suggestion instead of a tool-card link', function() {
        const dom = createToolCardsDom();
        const assistant = loadUiMixin({
            aiChangesUrl: 'http://example.test/wp-admin/tools.php?page=ai-changes'
        }, { jQuery: dom.$ });

        assistant.showAiChangesSuggestion(
            'read_file',
            { path: 'plugins/example/example.php' },
            {
                path: 'plugins/example/example.php',
                ai_changes: {
                    root: 'plugins/example',
                    type: 'plugin'
                }
            }
        );

        const suggestion = dom.getById('ai-assistant-ai-changes-suggestion');
        const link = dom.getById('ai-assistant-ai-changes-link');

        assert.ok(suggestion);
        assert.strictEqual(suggestion.parent, dom.messages);
        assert.strictEqual(suggestion.classes.has('ai-assistant-ai-changes-suggestion'), true);
        assert.strictEqual(suggestion.hidden, false);
        assert.ok(link);
        assert.strictEqual(link.parent, suggestion);
        assert.strictEqual(link.textContent, 'View changed files');
        assert.strictEqual(
            link.attrs.href,
            'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fexample'
        );
        assert.strictEqual(link.attrs.target, '_blank');
    });

    it('keeps the AI Changes suggestion after restored tool cards', function() {
        const dom = createToolCardsDom();
        const assistant = loadUiMixin({
            aiChangesUrl: 'http://example.test/wp-admin/tools.php?page=ai-changes'
        }, { jQuery: dom.$ });

        assistant.getActionDescription = function() { return 'Read file'; };
        assistant.getActionContentPreview = function() { return null; };
        assistant.escapeHtml = function(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };
        assistant.highlightCode = function() {};
        assistant.addToolUseMessage(
            'read_file',
            { path: 'plugins/example/example.php' },
            null,
            {
                path: 'plugins/example/example.php',
                ai_changes: {
                    root: 'plugins/example',
                    type: 'plugin'
                }
            }
        );

        const suggestion = dom.getById('ai-assistant-ai-changes-suggestion');
        const lastChild = dom.messages.children[dom.messages.children.length - 1];

        assert.ok(suggestion);
        assert.strictEqual(suggestion.parent, dom.messages);
        assert.strictEqual(lastChild, suggestion);
    });

    it('merges the AI Changes suggestion into a visible area-change suggestion', function() {
        const dom = createToolCardsDom();
        const assistant = loadUiMixin({
            aiChangesUrl: 'http://example.test/wp-admin/tools.php?page=ai-changes'
        }, { jQuery: dom.$ });
        const $areaSuggestion = dom.$('<div id="ai-assistant-area-suggestion" class="ai-assistant-area-suggestion" role="status" aria-live="polite"></div>');

        dom.$('#ai-assistant-messages').append($areaSuggestion);
        assistant.showAiChangesSuggestion(
            'read_file',
            { path: 'plugins/example/example.php' },
            {
                path: 'plugins/example/example.php',
                ai_changes: {
                    root: 'plugins/example',
                    type: 'plugin'
                }
            }
        );

        const areaSuggestion = dom.getById('ai-assistant-area-suggestion');
        const suggestion = dom.getById('ai-assistant-ai-changes-suggestion');
        const link = dom.getById('ai-assistant-ai-changes-link');

        assert.ok(suggestion);
        assert.strictEqual(suggestion.parent, areaSuggestion);
        assert.ok(link);
        assert.strictEqual(link.parent, suggestion);
        assert.strictEqual(link.textContent, 'View changed files');
    });

    it('adds separator spacing when an existing AI Changes suggestion is later merged', function() {
        const dom = createToolCardsDom();
        const assistant = loadUiMixin({
            aiChangesUrl: 'http://example.test/wp-admin/tools.php?page=ai-changes'
        }, { jQuery: dom.$ });

        assistant.showAiChangesSuggestion(
            'read_file',
            { path: 'plugins/example/example.php' },
            {
                path: 'plugins/example/example.php',
                ai_changes: {
                    root: 'plugins/example',
                    type: 'plugin'
                }
            }
        );

        const $areaSuggestion = dom.$('<div id="ai-assistant-area-suggestion" class="ai-assistant-area-suggestion" role="status" aria-live="polite"></div>');
        dom.$('#ai-assistant-messages').append($areaSuggestion);
        assistant.moveAiChangesSuggestionToEnd();

        const areaSuggestion = dom.getById('ai-assistant-area-suggestion');
        const suggestion = dom.getById('ai-assistant-ai-changes-suggestion');
        const link = dom.getById('ai-assistant-ai-changes-link');

        assert.strictEqual(suggestion.parent, areaSuggestion);
        assert.ok(suggestion.textContent.startsWith(' '));
        assert.ok(suggestion.textContent.endsWith('.'));
        assert.strictEqual(link.parent, suggestion);
    });

    it('renders current page AI Changes suggestion in the current window', function() {
        const dom = createToolCardsDom();
        const assistant = loadUiMixin({
            currentAiChanges: {
                root: 'plugins/current-app',
                type: 'plugin',
                url: 'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fcurrent-app',
                open_in_current_window: true,
                version_log: [
                    {
                        key: 'next',
                        label: 'Next',
                        message: 'Latest change message with more words',
                        message_excerpt: 'Latest change message with more...',
                        time_ago: '2 min ago',
                        url: 'http://example.test/wp-admin/admin.php?action=ai_assistant_checkout_version&sha=next',
                        open_in_current_window: true
                    },
                    {
                        key: 'current',
                        label: 'Current',
                        message: 'Middle checked out change message with more words',
                        message_excerpt: 'Middle checked out change message...',
                        time_ago: '5 min ago',
                        is_current: true,
                        open_in_current_window: true
                    },
                    {
                        key: 'previous',
                        label: 'Previous',
                        message: 'First older change message with more words',
                        message_excerpt: 'First older change message with...',
                        time_ago: '8 min ago',
                        url: 'http://example.test/wp-admin/admin.php?action=ai_assistant_checkout_version&sha=previous',
                        open_in_current_window: true
                    }
                ],
                links: [
                    {
                        key: 'overview',
                        label: 'Overview',
                        url: 'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fcurrent-app',
                        open_in_current_window: true
                    }
                ]
            }
        }, { jQuery: dom.$ });

        assistant.showCurrentAiChangesSuggestion();

        const suggestion = dom.getById('ai-assistant-ai-changes-suggestion');
        const link = dom.getById('ai-assistant-ai-changes-link');

        assert.ok(suggestion);
        assert.strictEqual(suggestion.hidden, false);
        const previous = dom.getById('ai-assistant-ai-changes-version-previous');
        const current = dom.getById('ai-assistant-ai-changes-version-current');
        const next = dom.getById('ai-assistant-ai-changes-version-next');
        const overview = dom.getById('ai-assistant-ai-changes-link-overview');

        assert.ok(link);
        assert.strictEqual(previous, undefined);
        assert.strictEqual(current, undefined);
        assert.strictEqual(next, undefined);
        assert.strictEqual(overview, undefined);
        assert.strictEqual(link.textContent, 'View changed files');
        assert.strictEqual(
            link.attrs.href,
            'http://example.test/wp-admin/tools.php?page=ai-changes&plugin=plugins%2Fcurrent-app'
        );
        assert.strictEqual(link.attrs.target, undefined);
    });
});
