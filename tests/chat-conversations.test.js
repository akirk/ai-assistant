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

function createJQuery(input, options) {
    options = options || {};
    const elements = new Map();

    function createElement(selector) {
        const state = {
            attrs: {},
            props: {},
            checked: false,
            css: {},
            html: '',
            visible: true,
            classes: new Set()
        };

        return {
            0: selector === '#ai-assistant-input' ? input : {},
            length: 1,
            _state: state,
            val(value) {
                if (selector !== '#ai-assistant-input') {
                    return arguments.length === 0 ? undefined : this;
                }

                if (arguments.length === 0) {
                    return input.value;
                }

                input.value = value;
                return this;
            },
            trigger(event) {
                if (selector === '#ai-assistant-input') {
                    input.triggered.push(event);
                }
                return this;
            },
            prop(name, value) {
                if (arguments.length === 1) {
                    return state.props[name];
                }
                state.props[name] = value;
                return this;
            },
            attr(name, value) {
                if (arguments.length === 1) {
                    return state.attrs[name];
                }
                state.attrs[name] = value;
                return this;
            },
            is(query) {
                return query === ':checked' ? state.checked : false;
            },
            html(value) {
                if (arguments.length === 0) {
                    return state.html;
                }
                state.html = value;
                return this;
            },
            empty() {
                state.html = '';
                return this;
            },
            css(name, value) {
                if (arguments.length === 1) {
                    return state.css[name];
                }
                state.css[name] = value;
                return this;
            },
            toggle(show) {
                state.visible = arguments.length === 0 ? !state.visible : !!show;
                return this;
            },
            toggleClass(className, enabled) {
                if (enabled) {
                    state.classes.add(className);
                } else {
                    state.classes.delete(className);
                }
                return this;
            },
            find(childSelector) {
                return $('#' + selector.replace(/^#/, '') + ' ' + childSelector);
            },
            focus() {
                return this;
            }
        };
    }

    function $(selector) {
        if (!elements.has(selector)) {
            elements.set(selector, createElement(selector));
        }
        return elements.get(selector);
    }

    $.extend = function(target, source) {
        return Object.assign(target, source);
    };

    $.ajax = function(settings) {
        if (typeof options.ajax === 'function') {
            return options.ajax(settings);
        }
        throw new Error('Unexpected AJAX request: ' + settings.data.action);
    };

    return $;
}

function loadConversationMixin(initialStorage, config, options) {
    options = options || {};
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
    const windowObject = Object.assign({
        aiAssistant,
        URL
    }, options.window || {});
    windowObject.aiAssistant = aiAssistant;

    const context = {
        window: windowObject,
        URL,
        jQuery: createJQuery(input, options),
        aiAssistantConfig: config || {},
        localStorage: storage,
        fetch: options.fetch,
        btoa(value) {
            return Buffer.from(value, 'binary').toString('base64');
        },
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-conversations.js'),
        'utf8'
    );
    vm.runInContext(source, context);

    return {
        assistant: aiAssistant,
        input,
        storage,
        element(selector) {
            return context.jQuery(selector);
        }
    };
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

describe('conversation title generation', function() {
    it('uses the active Anthropic conversation model and endpoint', async function() {
        const fetchCalls = [];
        let savedTitle = '';
        const { assistant } = loadConversationMixin(null, {}, {
            fetch(url, settings) {
                fetchCalls.push({ url, settings });
                return Promise.resolve({
                    json() {
                        return Promise.resolve({
                            content: [{ text: 'Title Bug Fix' }]
                        });
                    }
                });
            }
        });

        assistant.messages = [
            { role: 'user', content: 'Why is title generation using an unavailable model?' },
            { role: 'assistant', content: 'It is hard-coded.' }
        ];
        assistant.conversationProvider = 'anthropic';
        assistant.conversationModel = 'claude-sonnet-4-20250514';
        assistant.titleGenerationToken = 0;
        assistant.getProvider = function() { return 'anthropic'; };
        assistant.getModel = function() { return 'fallback-model'; };
        assistant.getApiKey = function() { return 'test-key'; };
        assistant.getProviderEndpoint = function() { return 'https://example.test/v1/messages'; };
        assistant.isConnectorsMode = function() { return false; };
        assistant.saveConversation = function() {
            savedTitle = this.conversationTitle;
        };

        assistant.generateConversationTitle();
        await new Promise(function(resolve) { setImmediate(resolve); });
        await new Promise(function(resolve) { setImmediate(resolve); });

        assert.strictEqual(fetchCalls.length, 1);
        assert.strictEqual(fetchCalls[0].url, 'https://example.test/v1/messages');
        assert.strictEqual(
            JSON.parse(fetchCalls[0].settings.body).model,
            'claude-sonnet-4-20250514'
        );
        assert.strictEqual(savedTitle, 'Title Bug Fix');
    });
});

describe('conversation exports', function() {
    it('sends the system prompt when saving a conversation', function() {
        let postedData = null;
        const { assistant } = loadConversationMixin(null, {
            ajaxUrl: '/admin-ajax.php',
            nonce: 'nonce'
        }, {
            ajax(settings) {
                postedData = settings.data;
                settings.success({
                    success: true,
                    data: {
                        conversation_id: 77,
                        title: 'Saved conversation'
                    }
                });
                settings.complete();
            }
        });

        assistant.messages = [{ role: 'user', content: 'Save this.' }];
        assistant.systemPrompt = 'Stored system prompt.';
        assistant.conversationId = 0;
        assistant.conversationTitle = 'Saved conversation';
        assistant.getProvider = function() { return 'openai'; };
        assistant.getModel = function() { return 'gpt-test'; };
        assistant.getTokenUsageSummary = function() {
            return {
                version: 1,
                input_tokens: 10,
                output_tokens: 3,
                total_tokens: 13,
                source: 'provider'
            };
        };
        assistant.updateSidebarSelection = function() {};
        assistant.updateSummarizeButton = function() {};
        assistant.updateExportButton = function() {};

        assistant.saveConversation(true);

        assert.strictEqual(postedData.system_prompt, 'Stored system prompt.');
        assert.deepStrictEqual(JSON.parse(postedData.token_usage), {
            version: 1,
            input_tokens: 10,
            output_tokens: 3,
            total_tokens: 13,
            source: 'provider'
        });
    });

    it('reads export formats from localized config', function() {
        const { assistant } = loadConversationMixin(null, {
            conversationExportFormats: [
                { format: 'markdown', label: 'Markdown', extension: 'md' },
                { format: 'html', label: 'HTML', extension: 'html' },
                { format: 'json', label: 'JSON', extension: 'json' },
                { label: 'Broken' }
            ]
        });

        assert.deepStrictEqual(assistant.getConversationExportFormats(), [
            { format: 'markdown', label: 'Markdown', extension: 'md' },
            { format: 'html', label: 'HTML', extension: 'html' },
            { format: 'json', label: 'JSON', extension: 'json' }
        ]);
    });

    it('builds a nonce-protected export download URL', function() {
        const { assistant } = loadConversationMixin(null, {
            conversationExportUrl: 'http://example.test/wp-admin/admin-post.php?action=ai_assistant_export_conversation',
            nonce: 'nonce value'
        });

        assistant.conversationId = 42;

        assert.strictEqual(
            assistant.buildConversationExportUrl('markdown'),
            'http://example.test/wp-admin/admin-post.php?action=ai_assistant_export_conversation&conversation_id=42&format=markdown&_wpnonce=nonce%20value'
        );

        assert.strictEqual(
            assistant.buildConversationExportUrl('html', true),
            'http://example.test/wp-admin/admin-post.php?action=ai_assistant_export_conversation&conversation_id=42&format=html&include_tool_calls=1&_wpnonce=nonce%20value'
        );
    });

    it('downloads an existing conversation export without saving first', function() {
        const { assistant } = loadConversationMixin(null, {
            conversationExportUrl: 'http://example.test/wp-admin/admin-post.php?action=ai_assistant_export_conversation',
            nonce: 'nonce value'
        });
        let saveCalled = false;
        let downloadUrl = '';

        assistant.messages = [{ role: 'user', content: 'Export this.' }];
        assistant.conversationId = 42;
        assistant.saveConversation = function() {
            saveCalled = true;
        };
        assistant.startConversationExportDownload = function(url) {
            downloadUrl = url;
        };

        assistant.exportConversation('markdown');

        assert.strictEqual(saveCalled, false);
        assert.strictEqual(
            downloadUrl,
            'http://example.test/wp-admin/admin-post.php?action=ai_assistant_export_conversation&conversation_id=42&format=markdown&_wpnonce=nonce%20value'
        );
    });

    it('saves a new unsaved conversation before export', function() {
        const { assistant } = loadConversationMixin(null, {
            conversationExportUrl: 'http://example.test/wp-admin/admin-post.php?action=ai_assistant_export_conversation',
            nonce: 'nonce value'
        });
        let saveCalled = false;
        let downloadUrl = '';

        assistant.messages = [{ role: 'user', content: 'Export this.' }];
        assistant.conversationId = 0;
        assistant.saveConversation = function(silent, callback) {
            saveCalled = silent;
            this.conversationId = 99;
            callback(true);
        };
        assistant.startConversationExportDownload = function(url) {
            downloadUrl = url;
        };

        assistant.exportConversation('json');

        assert.strictEqual(saveCalled, true);
        assert.strictEqual(
            downloadUrl,
            'http://example.test/wp-admin/admin-post.php?action=ai_assistant_export_conversation&conversation_id=99&format=json&_wpnonce=nonce%20value'
        );
    });
});

describe('conversation area suggestions', function() {
    it('does not update the stored URL component until interaction', function() {
        const { assistant, storage } = loadConversationMixin({
            aiAssistant_lastUrlComponent: 'my-apps'
        }, {
            urlComponent: 'other-app'
        });

        assistant.restoreUrlComponentContext();

        assert.strictEqual(assistant.getCurrentUrlComponent(), 'other-app');
        assert.strictEqual(assistant.previousUrlComponent, 'my-apps');
        assert.strictEqual(storage.getItem('aiAssistant_lastUrlComponent'), 'my-apps');
        assert.ok(parseInt(storage.getItem('aiAssistant_lastUrlContextAt'), 10) > 0);
    });

    it('keeps suggesting across reload until interaction updates the stored component', function() {
        const { assistant, storage } = loadConversationMixin({
            aiAssistant_lastUrlComponent: 'my-apps',
            aiAssistant_lastUrlContextAt: String(Date.now())
        }, {
            urlComponent: 'other-app'
        });

        assistant.restoreUrlComponentContext();
        assistant.messages = [{ role: 'user', content: 'Current chat' }];

        assert.strictEqual(assistant.shouldSuggestNewChatForCurrentArea(), true);

        assistant.markConversationInteracted();
        assert.strictEqual(assistant.shouldSuggestNewChatForCurrentArea(), false);
        assert.strictEqual(storage.getItem('aiAssistant_lastUrlComponent'), 'other-app');
        assert.ok(parseInt(storage.getItem('aiAssistant_lastUrlContextAt'), 10) >= assistant.previousUrlContextAt);

        assistant.messages = [];
        assistant.conversationInteracted = false;
        assert.strictEqual(assistant.shouldSuggestNewChatForCurrentArea(), false);
    });

    it('suggests a new chat when the last conversation context is stale', function() {
        const { assistant } = loadConversationMixin({
            aiAssistant_lastUrlComponent: 'my-apps',
            aiAssistant_lastUrlContextAt: String(Date.now() - (2 * 60 * 60 * 1000))
        }, {
            urlComponent: 'my-apps'
        });

        assistant.newChatSuggestionMaxAgeMs = 60 * 60 * 1000;
        assistant.restoreUrlComponentContext();
        assistant.messages = [{ role: 'user', content: 'Current chat' }];

        assert.strictEqual(assistant.shouldSuggestNewChatForCurrentArea(), true);
    });

    it('does not suggest a new chat for recent context in the same area', function() {
        const { assistant } = loadConversationMixin({
            aiAssistant_lastUrlComponent: 'my-apps',
            aiAssistant_lastUrlContextAt: String(Date.now())
        }, {
            urlComponent: 'my-apps'
        });

        assistant.newChatSuggestionMaxAgeMs = 60 * 60 * 1000;
        assistant.restoreUrlComponentContext();
        assistant.messages = [{ role: 'user', content: 'Current chat' }];

        assert.strictEqual(assistant.shouldSuggestNewChatForCurrentArea(), false);
    });

    it('uses stored message timestamps for stale chat suggestions when available', function() {
        const { assistant } = loadConversationMixin({
            aiAssistant_lastUrlComponent: 'my-apps',
            aiAssistant_lastUrlContextAt: String(Date.now())
        }, {
            urlComponent: 'my-apps'
        });

        assistant.newChatSuggestionMaxAgeMs = 60 * 60 * 1000;
        assistant.restoreUrlComponentContext();
        assistant.messages = [{ role: 'user', content: 'Current chat', _ts: Date.now() - (2 * 60 * 60 * 1000) }];

        assert.strictEqual(assistant.shouldSuggestNewChatForCurrentArea(), true);
    });

    it('prefers a recent message timestamp over an old context timestamp', function() {
        const { assistant } = loadConversationMixin({
            aiAssistant_lastUrlComponent: 'my-apps',
            aiAssistant_lastUrlContextAt: String(Date.now() - (2 * 60 * 60 * 1000))
        }, {
            urlComponent: 'my-apps'
        });

        assistant.newChatSuggestionMaxAgeMs = 60 * 60 * 1000;
        assistant.restoreUrlComponentContext();
        assistant.messages = [{ role: 'user', content: 'Current chat', _ts: Date.now() }];

        assert.strictEqual(assistant.shouldSuggestNewChatForCurrentArea(), false);
    });
});

describe('conversation page history', function() {
    it('builds a conversation URL while preserving unrelated query params', function() {
        const { assistant } = loadConversationMixin(null, {}, {
            window: {
                location: new URL('http://example.test/ai-assistant/conversations/?conversation=12&filter=open#messages')
            }
        });

        assert.strictEqual(
            assistant.getConversationRouteUrl(34),
            '/ai-assistant/conversations/?filter=open&conversation=34#messages'
        );
        assert.strictEqual(
            assistant.getConversationRouteUrl(0),
            '/ai-assistant/conversations/?filter=open#messages'
        );
    });

    it('pushes a conversation URL on the full-page conversations app', function() {
        const pushed = [];
        const location = new URL('http://example.test/ai-assistant/conversations/');
        const { assistant } = loadConversationMixin(null, {}, {
            window: {
                location,
                history: {
                    pushState(state, title, url) {
                        pushed.push({ state, title, url });
                        const next = new URL(url, location.href);
                        location.href = next.href;
                    },
                    replaceState() {}
                }
            }
        });

        assistant.isFullPage = true;
        assistant.updateConversationRoute(42);

        assert.strictEqual(pushed.length, 1);
        assert.strictEqual(pushed[0].state.aiAssistantConversationId, 42);
        assert.strictEqual(pushed[0].title, '');
        assert.strictEqual(pushed[0].url, '/ai-assistant/conversations/?conversation=42');
    });

    it('loads the URL conversation on browser history navigation without pushing again', function() {
        let popstateHandler = null;
        let loadedConversationId = 0;
        let loadOptions = null;
        const { assistant } = loadConversationMixin(null, {}, {
            window: {
                location: new URL('http://example.test/ai-assistant/conversations/?conversation=42'),
                history: {
                    pushState() {},
                    replaceState() {}
                },
                addEventListener(type, handler) {
                    if (type === 'popstate') {
                        popstateHandler = handler;
                    }
                }
            }
        });

        assistant.isFullPage = true;
        assistant.conversationId = 12;
        assistant.loadConversation = function(conversationId, options) {
            loadedConversationId = conversationId;
            loadOptions = options;
        };

        assistant.bindConversationHistoryEvents();
        popstateHandler();

        assert.strictEqual(loadedConversationId, 42);
        assert.strictEqual(loadOptions.updateHistory, false);
    });

    it('restores the default recent conversation for a bare conversations URL history entry', function() {
        let popstateHandler = null;
        let recentOptions = null;
        let newChatStarted = false;
        const { assistant } = loadConversationMixin(null, {}, {
            window: {
                location: new URL('http://example.test/ai-assistant/conversations/'),
                history: {
                    pushState() {},
                    replaceState() {}
                },
                addEventListener(type, handler) {
                    if (type === 'popstate') {
                        popstateHandler = handler;
                    }
                }
            }
        });

        assistant.isFullPage = true;
        assistant.conversationId = 42;
        assistant.messages = [{ role: 'user', content: 'Current conversation' }];
        assistant.loadMostRecentConversation = function(options) {
            recentOptions = options;
        };
        assistant.startNewChat = function() {
            newChatStarted = true;
        };

        assistant.bindConversationHistoryEvents();
        popstateHandler({ state: null });

        assert.strictEqual(newChatStarted, false);
        assert.strictEqual(recentOptions.updateHistory, false);
    });

    it('restores new chat state for a pushed bare conversations URL', function() {
        let popstateHandler = null;
        let newChatOptions = null;
        let recentLoaded = false;
        const { assistant } = loadConversationMixin(null, {}, {
            window: {
                location: new URL('http://example.test/ai-assistant/conversations/'),
                history: {
                    pushState() {},
                    replaceState() {}
                },
                addEventListener(type, handler) {
                    if (type === 'popstate') {
                        popstateHandler = handler;
                    }
                }
            }
        });

        assistant.isFullPage = true;
        assistant.conversationId = 42;
        assistant.loadMostRecentConversation = function() {
            recentLoaded = true;
        };
        assistant.startNewChat = function(options) {
            newChatOptions = options;
        };

        assistant.bindConversationHistoryEvents();
        popstateHandler({ state: { aiAssistantConversationId: 0 } });

        assert.strictEqual(recentLoaded, false);
        assert.strictEqual(newChatOptions.updateHistory, false);
    });
});

describe('conversation playback', function() {
    it('keeps the playback button visible while playback is active', function() {
        const { assistant, element } = loadConversationMixin();
        const $button = element('#ai-assistant-playback');
        const $icon = element('#ai-assistant-playback .dashicons');

        assistant.messages = [{ role: 'user', content: 'Hello' }];
        assistant.isLoading = false;
        assistant.playbackActive = false;

        assistant.updatePlaybackButton();

        assert.strictEqual($button._state.visible, true);
        assert.strictEqual($button._state.props.disabled, false);
        assert.strictEqual($button._state.attrs.title, 'Play back conversation');
        assert.strictEqual($icon._state.classes.has('dashicons-controls-play'), true);
        assert.strictEqual($icon._state.classes.has('dashicons-controls-pause'), false);

        assistant.playbackActive = true;
        assistant.updatePlaybackButton();

        assert.strictEqual($button._state.visible, true);
        assert.strictEqual($button._state.props.disabled, false);
        assert.strictEqual($button._state.attrs.title, 'Stop playback');
        assert.strictEqual($icon._state.classes.has('dashicons-controls-play'), false);
        assert.strictEqual($icon._state.classes.has('dashicons-controls-pause'), true);
    });

    it('continues playback with the edited input text when the user sends it', function() {
        const { assistant, input } = loadConversationMixin();
        let addedMessage = null;
        let clearedDraft = false;
        let sendButtonUpdated = false;
        let continuedRunToken = null;

        assistant.playbackActive = true;
        assistant.playbackWaitingForUser = true;
        assistant.playbackIndex = 2;
        assistant.playbackRunToken = 7;
        assistant.pendingAttachments = [];
        input.value = 'Edited playback text';

        assistant.addMessage = function(role, content) {
            addedMessage = { role: role, content: content };
        };
        assistant.clearDraft = function() {
            clearedDraft = true;
        };
        assistant.updateSendButton = function() {
            sendButtonUpdated = true;
        };
        assistant.runConversationPlayback = function(runToken) {
            continuedRunToken = runToken;
        };

        assert.strictEqual(assistant.continueConversationPlaybackFromInput(), true);
        assert.deepStrictEqual(addedMessage, {
            role: 'user',
            content: 'Edited playback text'
        });
        assert.strictEqual(input.value, '');
        assert.strictEqual(clearedDraft, true);
        assert.strictEqual(sendButtonUpdated, true);
        assert.strictEqual(assistant.playbackWaitingForUser, false);
        assert.strictEqual(assistant.playbackIndex, 3);
        assert.strictEqual(continuedRunToken, 7);
    });
});

describe('loading recent conversations', function() {
    it('loads the newest conversation that has saved messages', function() {
        let loadedConversationId = 0;
        let welcomeLoaded = false;
        const { assistant } = loadConversationMixin(null, {
            ajaxUrl: '/admin-ajax.php',
            nonce: 'nonce'
        }, {
            ajax(settings) {
                assert.strictEqual(settings.data.action, 'ai_assistant_list_conversations');
                settings.success({
                    success: true,
                    data: {
                        conversations: [
                            { id: 10, message_count: 0 },
                            { id: 11, message_count: '0' },
                            { id: 12, message_count: '3' }
                        ]
                    }
                });
            }
        });

        assistant.loadConversation = function(conversationId) {
            loadedConversationId = conversationId;
        };
        assistant.loadWelcomeMessage = function() {
            welcomeLoaded = true;
        };
        assistant.updateExportButton = function() {};

        assistant.loadMostRecentConversation();

        assert.strictEqual(loadedConversationId, 12);
        assert.strictEqual(welcomeLoaded, false);
    });

    it('shows a fresh welcome when only empty conversations exist', function() {
        let loadedConversationId = 0;
        let welcomeLoaded = false;
        let exportButtonUpdated = false;
        const { assistant } = loadConversationMixin(null, {
            ajaxUrl: '/admin-ajax.php',
            nonce: 'nonce'
        }, {
            ajax(settings) {
                settings.success({
                    success: true,
                    data: {
                        conversations: [
                            { id: 10, message_count: 0 },
                            { id: 11, message_count: '0' }
                        ]
                    }
                });
            }
        });

        assistant.loadConversation = function(conversationId) {
            loadedConversationId = conversationId;
        };
        assistant.loadWelcomeMessage = function() {
            welcomeLoaded = true;
        };
        assistant.updateExportButton = function() {
            exportButtonUpdated = true;
        };

        assistant.loadMostRecentConversation();

        assert.strictEqual(loadedConversationId, 0);
        assert.strictEqual(welcomeLoaded, true);
        assert.strictEqual(exportButtonUpdated, true);
    });
});
