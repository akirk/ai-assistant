(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        getMessageTimestamp: function() {
            return Date.now ? Date.now() : new Date().getTime();
        },

        getPromptCacheKey: function(provider) {
            provider = String(provider || this.conversationProvider || this.getProvider?.() || 'default')
                .toLowerCase()
                .replace(/[^a-z0-9_-]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'default';

            if (!this.promptCacheKey) {
                var conversationId = parseInt(this.conversationId, 10) || 0;
                var suffix = conversationId > 0
                    ? 'conversation-' + conversationId
                    : 'draft-' + this.getMessageTimestamp().toString(36) + '-' + Math.random().toString(36).slice(2, 10);

                this.promptCacheKey = 'ai-assistant-' + provider + '-' + suffix;
            }

            return this.promptCacheKey;
        },

        createStoredMessage: function(role, content, extra) {
            var message = $.extend({
                role: role,
                content: content,
                _ts: this.getMessageTimestamp()
            }, extra || {});

            if (!message._ts) {
                message._ts = this.getMessageTimestamp();
            }

            return message;
        },

        getTrailingReasoningTagPrefix: function(text, tags) {
            text = String(text || '');
            tags = tags || [];

            var maxLength = tags.reduce(function(max, tag) {
                return Math.max(max, tag.length - 1);
            }, 0);

            for (var length = Math.min(maxLength, text.length); length > 0; length--) {
                var suffix = text.slice(-length).toLowerCase();
                for (var i = 0; i < tags.length; i++) {
                    if (tags[i].toLowerCase().indexOf(suffix) === 0) {
                        return text.slice(-length);
                    }
                }
            }

            return '';
        },

        getReasoningDelimiters: function() {
            return [
                {
                    name: 'think-tag',
                    open: /<think>/ig,
                    close: /<\/think>/ig,
                    openPrefixes: ['<think>'],
                    closePrefixes: ['</think>']
                },
                {
                    name: 'think-bracket',
                    open: /\[THINK\]/ig,
                    close: /\[\/THINK\]/ig,
                    openPrefixes: ['[THINK]'],
                    closePrefixes: ['[/THINK]']
                },
                {
                    name: 'thought-channel',
                    open: /<\|channel\|?>thought(?=\s|<|$)[^\S\n]*(?:\r?\n)?/ig,
                    close: /<channel\|>|<\|channel\|>/ig,
                    openPrefixes: ['<|channel>thought', '<|channel|>thought'],
                    closePrefixes: ['<channel|>', '<|channel|>']
                }
            ];
        },

        cloneReasoningPattern: function(pattern) {
            var flags = pattern.flags || '';
            if (flags.indexOf('g') === -1) {
                flags += 'g';
            }

            return new RegExp(pattern.source, flags);
        },

        getReasoningOpenPrefixes: function() {
            var prefixes = [];
            this.getReasoningDelimiters().forEach(function(delimiter) {
                Array.prototype.push.apply(prefixes, delimiter.openPrefixes || []);
            });
            return prefixes;
        },

        findNextReasoningDelimiter: function(source, startIndex) {
            var self = this;
            var next = null;

            this.getReasoningDelimiters().forEach(function(delimiter) {
                var openPattern = self.cloneReasoningPattern(delimiter.open);
                openPattern.lastIndex = startIndex;
                var match = openPattern.exec(source);

                if (!match) {
                    return;
                }

                if (!next || match.index < next.match.index) {
                    next = {
                        delimiter: delimiter,
                        match: match,
                        end: openPattern.lastIndex
                    };
                }
            });

            return next;
        },

        findReasoningClose: function(source, delimiter, startIndex) {
            var closePattern = this.cloneReasoningPattern(delimiter.close);
            closePattern.lastIndex = startIndex;
            var match = closePattern.exec(source);

            if (!match) {
                return null;
            }

            return {
                match: match,
                end: closePattern.lastIndex
            };
        },

        extractReasoningFromContent: function(text) {
            var source = String(text || '');
            var output = '';
            var thinkingParts = [];
            var index = 0;
            var thinkingOpen = false;

            while (index < source.length) {
                var open = this.findNextReasoningDelimiter(source, index);
                if (!open) {
                    break;
                }

                output += source.slice(index, open.match.index);

                var close = this.findReasoningClose(source, open.delimiter, open.end);
                if (!close) {
                    thinkingOpen = true;
                    var openThinking = source.slice(open.end);
                    var pendingClose = this.getTrailingReasoningTagPrefix(openThinking, open.delimiter.closePrefixes || []);
                    if (pendingClose) {
                        openThinking = openThinking.slice(0, -pendingClose.length);
                    }
                    thinkingParts.push(openThinking);
                    index = source.length;
                    break;
                }

                thinkingParts.push(source.slice(open.end, close.match.index));
                index = close.end;
            }

            if (index < source.length) {
                output += source.slice(index);
            }

            var pendingOpen = this.getTrailingReasoningTagPrefix(output, this.getReasoningOpenPrefixes());
            if (pendingOpen) {
                output = output.slice(0, -pendingOpen.length);
            }

            return {
                content: output.trim(),
                thinking: thinkingParts.join('\n\n').trim(),
                thinkingOpen: thinkingOpen
            };
        },

        createStreamingResponseState: function($reply) {
            return {
                $reply: $reply,
                replyRemoved: false,
                $thinking: null,
                thinkingContent: '',
                thinkingStartTime: null,
                thinkingDurationMs: 0,
                thinkingFinalized: false,
                rawContent: '',
                textContent: ''
            };
        },

        removeEmptyStreamingReply: function(state) {
            if (!state || state.textContent || state.replyRemoved || !state.$reply) {
                return;
            }

            state.$reply.remove();
            state.replyRemoved = true;
        },

        ensureStreamingReply: function(state) {
            if (!state.$reply || state.replyRemoved) {
                state.$reply = this.startReply();
                state.replyRemoved = false;
            }

            return state.$reply;
        },

        appendThinkingContent: function(state, text) {
            if (!state || !text) {
                return;
            }

            if (!state.$thinking) {
                state.$thinking = this.startThinking({ expanded: true });
                state.thinkingStartTime = Date.now();
                this.removeEmptyStreamingReply(state);
            }

            state.thinkingContent += text;
            this.updateThinking(state.$thinking, state.thinkingContent);
        },

        appendAssistantContent: function(state, text) {
            if (!state || !text) {
                return;
            }

            state.rawContent += text;

            var parsed = this.extractReasoningFromContent(state.rawContent);
            if (parsed.thinking && parsed.thinking !== state.thinkingContent) {
                if (!state.$thinking) {
                    state.$thinking = this.startThinking({ expanded: true });
                    state.thinkingStartTime = Date.now();
                    this.removeEmptyStreamingReply(state);
                }
                state.thinkingContent = parsed.thinking;
                this.updateThinking(state.$thinking, state.thinkingContent);
            }

            if (state.$thinking && !parsed.thinkingOpen) {
                this.finalizeThinkingState(state);
            }

            if (parsed.content !== state.textContent) {
                state.textContent = parsed.content;
                if (state.textContent) {
                    this.updateReply(this.ensureStreamingReply(state), state.textContent);
                }
            }
        },

        finalizeThinkingState: function(state) {
            if (!state || !state.$thinking || state.thinkingFinalized) {
                return;
            }

            var started = state.thinkingStartTime || Date.now();
            state.thinkingDurationMs = Date.now() - started;
            this.finalizeThinking(state.$thinking, state.thinkingDurationMs);
            state.thinkingFinalized = true;
        },

        shouldQueueUserMessage: function() {
            return !!(
                this.isLoading ||
                (this.pendingActions && this.pendingActions.length > 0) ||
                (this.pendingToolChecks || 0) > 0 ||
                (this.executingToolCount || 0) > 0 ||
                (this.pendingToolResults && this.pendingToolResults.length > 0)
            );
        },

        queueUserMessage: function(message, attachments) {
            attachments = attachments || [];

            if (this.markConversationInteracted) {
                this.markConversationInteracted();
            }

            var messageContent = this.buildUserMessageContent
                ? this.buildUserMessageContent(message, attachments)
                : message;

            if (!Array.isArray(this.queuedMessages)) {
                this.queuedMessages = [];
            }

            this.queuedMessages.push({
                content: messageContent,
                queuedAt: this.getMessageTimestamp()
            });
            this.conversationDirty = true;

            this.addToDraftHistory(message);
            this.pendingAttachments = [];
            if (this.renderPendingAttachments) {
                this.renderPendingAttachments();
            }

            $('#ai-assistant-input').val('');
            this.clearDraft();
            this.draftHistoryIndex = -1;
            this.draftHistoryDraft = '';
            this.updateSendButton();
            this.updateLoadingStatus();
            if (this.saveConversation) {
                this.saveConversation(true);
            }
        },

        clearQueuedMessages: function() {
            this.queuedMessages = [];
            this.updateSendButton();
            this.updateLoadingStatus();
        },

        flushQueuedMessages: function(provider, options) {
            options = options || {};

            if (!Array.isArray(this.queuedMessages) || this.queuedMessages.length === 0) {
                return false;
            }

            var queued = this.queuedMessages.slice();
            this.queuedMessages = [];

            var contents = queued.map(function(item) {
                return item.content;
            }).filter(function(content) {
                return !!content;
            });

            if (contents.length === 0) {
                this.updateSendButton();
                this.updateLoadingStatus();
                return false;
            }

            queued.forEach(function(item) {
                if (item.content) {
                    this.addMessage('user', item.content, null, { timestamp: item.queuedAt });
                }
            }, this);

            if (
                provider === 'anthropic' &&
                options.appendToLastToolResultMessage &&
                this.messages.length > 0
            ) {
                var lastMessage = this.messages[this.messages.length - 1];
                if (lastMessage && lastMessage.role === 'user' && Array.isArray(lastMessage.content)) {
                    lastMessage._ts = queued[queued.length - 1].queuedAt || this.getMessageTimestamp();
                    contents.forEach(function(content) {
                        lastMessage.content.push({ type: 'text', text: content });
                    });
                } else {
                    this.messages.push(this.createStoredMessage('user', contents.join('\n\n'), {
                        _ts: queued[queued.length - 1].queuedAt || this.getMessageTimestamp()
                    }));
                }
            } else {
                this.messages.push(this.createStoredMessage('user', contents.join('\n\n'), {
                    _ts: queued[queued.length - 1].queuedAt || this.getMessageTimestamp()
                }));
            }

            this.conversationDirty = true;
            this.updateSendButton();
            this.updateLoadingStatus();
            this.updateTokenCount();
            if (this.updateExportButton) {
                this.updateExportButton();
            }
            return true;
        },

        sendQueuedMessagesIfAvailable: function(provider, options) {
            if (!this.flushQueuedMessages(provider, options)) {
                return false;
            }

            this.toolCallRounds = 0;
            this.consecutiveFailedToolRounds = 0;
            this.usesCodingToolWorkflow = false;
            this.autoSaveConversation();
            this.shouldFollowStreamingScroll = true;
            this.callLLM();
            return true;
        },

        sendMessage: async function() {
            if (!this.isProviderConfigured()) return;

            var $input = $('#ai-assistant-input');
            var message = $input.val().trim();
            var attachments = (this.pendingAttachments || []).slice();

            if (this.isUploadingFiles) return;
            if (!message && attachments.length === 0) return;
            if (this.isCompactingStandaloneContinuation) return;

            if (this.shouldQueueUserMessage()) {
                this.queueUserMessage(message, attachments);
                return;
            }

            if (this.pendingActions && this.pendingActions.length > 0) {
                this.setLoading(false);
                if (this.showToolApprovalModal) {
                    this.showToolApprovalModal();
                }
                return;
            }

            if ((this.pendingToolChecks || 0) > 0) {
                this.setLoading(false);
                return;
            }

            if (this.ensureBrowserProviderStatuses) {
                this.setLoading(true);
                await this.ensureBrowserProviderStatuses();
                this.setLoading(false);

                if (!this.isProviderConfigured()) {
                    this.addMessage('error', 'No AI provider is reachable from this browser. If you are using a local provider, make sure it is running on this device and allows browser requests from WordPress.');
                    return;
                }

                if (!this.conversationId && this.messages.length === 0) {
                    this.conversationProvider = this.getProvider();
                    this.conversationModel = this.getModel();
                } else if (this.pendingNewChat) {
                    this.pendingNewChatProvider = this.getProvider();
                    this.pendingNewChatModel = this.getModel();
                }
            }

            if (this.markConversationInteracted) {
                this.markConversationInteracted();
            }

            if (this.pendingNewChat) {
                this.messages = [];
                this.pendingActions = [];
                this.pendingToolChecks = 0;
                if (this.showToolApprovalModal) {
                    this.showToolApprovalModal();
                }
                this.conversationId = 0;
                this.conversationTitle = '';
                this.conversationProvider = this.pendingNewChatProvider || this.getProvider();
                this.conversationModel = this.pendingNewChatModel || this.getModel();
                this.titleGenerationInProgress = false;
                this.titleGenerationAttempted = false;
                this.titleGenerationToken++;
                this.conversationTitleIsPlaceholder = false;
                this.pendingNewChat = false;
                this.pendingNewChatProvider = '';
                this.pendingNewChatModel = '';
                this.pendingChatOriginalHtml = null;
                $('#ai-assistant-messages').empty();
                $('#ai-token-count').show();
                $('#ai-assistant-pending-actions').empty().hide();
                $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');
                if (this.hideAreaChangeSuggestion) {
                    this.hideAreaChangeSuggestion();
                }
                this.updateSidebarSelection();
                this.loadWelcomeMessage();
            }

            this.clearToolCards();
            this.pendingToolResults = [];
            this.pendingToolChecks = 0;
            this.streamComplete = false;
            this.executingToolCount = 0;
            this.processedToolIds = {};
            this.pickImageToolCallInCurrentResponse = '';
            this.toolCallRounds = 0;
            this.consecutiveFailedToolRounds = 0;
            this.usesCodingToolWorkflow = false;
            this.addToDraftHistory(message);
            var messageContent = this.buildUserMessageContent
                ? this.buildUserMessageContent(message, attachments)
                : message;
            var userMessage = this.createStoredMessage('user', messageContent);
            this.addMessage('user', messageContent, null, { timestamp: userMessage._ts });
            this.messages.push(userMessage);
            this.conversationDirty = true;
            if (this.updateExportButton) {
                this.updateExportButton();
            }
            this.pendingAttachments = [];
            if (this.renderPendingAttachments) {
                this.renderPendingAttachments();
            }
            $input.val('');
            this.clearDraft();
            this.draftHistoryIndex = -1;
            this.draftHistoryDraft = '';

            this.updateTokenCount();
            if (this.autoSaveConversation) {
                this.autoSaveConversation();
            }
            this.shouldFollowStreamingScroll = true;
            this.callLLM();
        },

        callLLM: async function() {
            var provider = this.conversationProvider || this.getProvider();

            if (this.ensureBrowserProviderStatus && this._providerNeedsBrowserStatus && this._providerNeedsBrowserStatus(provider)) {
                await this.ensureBrowserProviderStatus(provider);
                if (!this._isProviderAvailable(provider)) {
                    this.addMessage('error', 'Provider "' + provider + '" is not reachable from this browser or has no usable loaded models.');
                    this.setLoading(false);
                    return;
                }
            }

            if (this.pendingActions && this.pendingActions.length > 0) {
                this.setLoading(false);
                if (this.showToolApprovalModal) {
                    this.showToolApprovalModal();
                }
                return;
            }

            if ((this.pendingToolChecks || 0) > 0) {
                this.setLoading(false);
                return;
            }

            if (this.archiveToolCards) {
                this.archiveToolCards({ removeIncomplete: true });
            } else {
                this.hideToolProgress();
            }
            this.setLoading(true);
            this.streamComplete = false;
            this.executingToolCount = 0;
            this.processedToolIds = {};
            this.pickImageToolCallInCurrentResponse = '';
            this.pendingToolChecks = 0;

            // In connectors mode, route based on provider type
            if (this.isConnectorsMode() && provider !== 'local') {
                var providerConfig = aiAssistantProviders.available[provider];
                if (providerConfig && providerConfig.type === 'server') {
                    // Server-type providers (e.g. LM Studio, Ollama) — use browser-direct local LLM path
                    // with the endpoint from Connectors if available
                    this.callLocalLLM(providerConfig.endpoint || null);
                    return;
                }
                if (providerConfig && !providerConfig.browserSupported) {
                    this.addMessage('error', 'Provider "' + provider + '" is not yet supported for browser-direct calls. Please select a different provider.');
                    this.setLoading(false);
                    return;
                }
            }

            switch (provider) {
                case 'anthropic':
                    this.callAnthropic();
                    break;
                case 'openai':
                    this.callOpenAI();
                    break;
                case 'local':
                    this.callLocalLLM();
                    break;
                default:
                    // In connectors mode, unknown providers with cloud type might be OpenAI-compatible
                    if (this.isConnectorsMode()) {
                        var config = aiAssistantProviders.available[provider];
                        if (config && config.type === 'cloud' && config.apiKey) {
                            this.callOpenAI();
                            return;
                        }
                    }
                    this.addMessage('error', 'Unknown provider: ' + provider);
                    this.setLoading(false);
            }
        },

        shouldProxyProvider: function(provider) {
            if (!this.isConnectorsMode() || provider === 'local') {
                return false;
            }

            var config = aiAssistantProviders.available[provider];
            return !!(config && config.proxySupported && config.serverSideAuth);
        },

        fetchLLMProvider: function(provider, endpoint, headers, payload, signal) {
            if (this.shouldProxyProvider(provider)) {
                var separator = aiAssistantConfig.ajaxUrl.indexOf('?') === -1 ? '?' : '&';
                var proxyUrl = aiAssistantConfig.ajaxUrl +
                    separator +
                    'action=ai_assistant_llm_proxy&_wpnonce=' +
                    encodeURIComponent(aiAssistantConfig.nonce) +
                    '&provider=' +
                    encodeURIComponent(provider);
                var params = new URLSearchParams();
                params.append('body', JSON.stringify(payload));

                return fetch(proxyUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: params,
                    signal: signal
                });
            }

            return fetch(endpoint, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify(payload),
                signal: signal
            });
        },

        stripMessageMetadata: function(message) {
            if (!message || typeof message !== 'object') {
                return message;
            }

            var clean = null;
            Object.keys(message).forEach(function(key) {
                if (key.charAt(0) !== '_') {
                    return;
                }

                if (!clean) {
                    clean = $.extend({}, message);
                }
                delete clean[key];
            });

            return clean || message;
        },

        getProviderLimit: function(configKey, fallback, minimum) {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            var value = parseInt(config[configKey], 10);

            if (!Number.isFinite(value) || value < (minimum || 1)) {
                return fallback;
            }

            return value;
        },

        getToolResultCompactLimits: function(mode) {
            var aggressive = mode === 'aggressive' || mode === 'recovery';
            var maxResultChars = this.getProviderLimit('maxToolResultChars', 32 * 1024, 4096);
            var maxStringChars = this.getProviderLimit('maxToolResultStringChars', 8 * 1024, 1024);
            var maxArrayItems = this.getProviderLimit('maxToolResultArrayItems', 40, 1);

            return {
                maxResultChars: aggressive ? Math.max(4096, Math.floor(maxResultChars / 4)) : maxResultChars,
                maxStringChars: aggressive ? Math.max(1024, Math.floor(maxStringChars / 4)) : maxStringChars,
                maxArrayItems: aggressive ? Math.max(8, Math.floor(maxArrayItems / 4)) : maxArrayItems,
                maxObjectKeys: aggressive ? 40 : 120,
                maxDepth: aggressive ? 4 : 8
            };
        },

        getProviderRequestCharLimit: function(mode) {
            var limit = this.getProviderLimit('maxProviderRequestChars', 160 * 1024, 32 * 1024);
            if (mode === 'recovery') {
                return Math.max(16 * 1024, Math.floor(limit / 2));
            }
            return limit;
        },

        truncateProviderString: function(text, maxChars) {
            text = String(text || '');
            maxChars = Math.max(0, parseInt(maxChars, 10) || 0);

            if (!maxChars || text.length <= maxChars) {
                return text;
            }

            var marker = '\n\n[... truncated ' + (text.length - maxChars) + ' chars ...]\n\n';
            if (maxChars <= marker.length + 20) {
                return text.substring(0, maxChars);
            }

            var available = maxChars - marker.length;
            var headLength = Math.max(1, Math.floor(available * 0.75));
            var tailLength = Math.max(0, available - headLength);

            return text.substring(0, headLength) + marker + (tailLength ? text.slice(-tailLength) : '');
        },

        safeJsonStringify: function(value) {
            var useWeakSet = typeof WeakSet !== 'undefined';
            var seen = useWeakSet ? new WeakSet() : [];

            try {
                var json = JSON.stringify(value, function(key, item) {
                    if (!item || typeof item !== 'object') {
                        return item;
                    }

                    if (useWeakSet) {
                        if (seen.has(item)) {
                            return '[Circular]';
                        }
                        seen.add(item);
                        return item;
                    }

                    if (seen.indexOf(item) >= 0) {
                        return '[Circular]';
                    }
                    seen.push(item);
                    return item;
                });

                return json === undefined ? 'null' : json;
            } catch (e) {
                return JSON.stringify({
                    error: 'Could not serialize tool result for provider context.',
                    message: e.message || String(e)
                });
            }
        },

        compactProviderValue: function(value, limits, depth) {
            limits = limits || this.getToolResultCompactLimits();
            depth = depth || 0;

            if (value === null || value === undefined) {
                return value;
            }

            if (typeof value === 'string') {
                return this.truncateProviderString(value, limits.maxStringChars);
            }

            if (typeof value !== 'object') {
                return value;
            }

            if (
                !Array.isArray(value) &&
                typeof value.content === 'string' &&
                value.path &&
                Object.prototype.hasOwnProperty.call(value, 'size')
            ) {
                return this.compactFileContentToolResult(value, limits, depth);
            }

            if (depth >= limits.maxDepth) {
                if (Array.isArray(value)) {
                    return {
                        _truncated: true,
                        type: 'array',
                        length: value.length
                    };
                }

                return {
                    _truncated: true,
                    type: 'object',
                    keys: Object.keys(value).slice(0, 20)
                };
            }

            if (Array.isArray(value)) {
                var maxItems = Math.max(1, limits.maxArrayItems);
                var items = value.slice(0, maxItems).map(function(item) {
                    return this.compactProviderValue(item, limits, depth + 1);
                }, this);

                if (value.length > maxItems) {
                    items.push({
                        _truncated_items: value.length - maxItems
                    });
                }

                return items;
            }

            var keys = Object.keys(value);
            var compacted = {};
            var maxKeys = Math.max(1, limits.maxObjectKeys);

            keys.slice(0, maxKeys).forEach(function(key) {
                compacted[key] = this.compactProviderValue(value[key], limits, depth + 1);
            }, this);

            if (keys.length > maxKeys) {
                compacted._truncated_keys = keys.length - maxKeys;
            }

            return compacted;
        },

        createLargeStringSignature: function(text) {
            text = String(text || '');

            return [
                text.length,
                text.substring(0, 256),
                text.slice(-256)
            ].join('|');
        },

        dedupeLargeProviderStrings: function(value, options, path, state) {
            options = options || {};
            path = path || 'result';
            state = state || { seen: {} };

            if (typeof value === 'string') {
                var minChars = options.minChars || 2048;
                if (value.length < minChars) {
                    return value;
                }

                var signature = this.createLargeStringSignature(value);
                if (state.seen[signature]) {
                    return '[Duplicate of ' + state.seen[signature] + ' omitted; ' + value.length + ' chars.]';
                }

                state.seen[signature] = path;
                return value;
            }

            if (!value || typeof value !== 'object') {
                return value;
            }

            if (Array.isArray(value)) {
                return value.map(function(item, index) {
                    return this.dedupeLargeProviderStrings(item, options, path + '[' + index + ']', state);
                }, this);
            }

            var output = {};
            Object.keys(value).forEach(function(key) {
                output[key] = this.dedupeLargeProviderStrings(
                    value[key],
                    options,
                    path + '.' + key,
                    state
                );
            }, this);

            return output;
        },

        compactFileContentToolResult: function(value, limits, depth) {
            var keys = Object.keys(value);
            var compacted = {};
            var content = value.content || '';
            var offset = parseInt(value.offset, 10);
            offset = Number.isFinite(offset) && offset > 0 ? offset : 0;

            keys.forEach(function(key) {
                if (key === 'content') {
                    return;
                }
                compacted[key] = this.compactProviderValue(value[key], limits, depth + 1);
            }, this);

            if (content.length <= limits.maxStringChars) {
                compacted.content = content;
                return compacted;
            }

            var marker = '\n\n[... file content truncated for context ...]';
            var keepLength = Math.max(1, limits.maxStringChars - marker.length);
            var nextOffset = offset + keepLength;

            compacted.content = content.substring(0, keepLength) + marker;
            compacted.content_truncated_for_context = true;
            compacted.content_original_chars = content.length;
            compacted.returned_bytes = keepLength;
            compacted.truncated = true;
            compacted.next_offset = nextOffset;
            compacted.instruction = 'File content was compacted for provider context. Call read_file again with offset set to next_offset to continue.';

            return compacted;
        },

        createProviderValueSummary: function(value) {
            if (value === null || value === undefined) {
                return value;
            }

            if (typeof value === 'string') {
                return {
                    _truncated: true,
                    type: 'string',
                    original_chars: value.length,
                    preview: this.truncateProviderString(value, 4000)
                };
            }

            if (typeof value !== 'object') {
                return value;
            }

            if (Array.isArray(value)) {
                return {
                    _truncated: true,
                    type: 'array',
                    length: value.length,
                    sample: value.slice(0, 3).map(function(item) {
                        return this.compactProviderValue(item, this.getToolResultCompactLimits('aggressive'), 0);
                    }, this)
                };
            }

            var summary = {
                _truncated: true,
                type: 'object',
                keys: Object.keys(value).slice(0, 40)
            };
            var aggressiveLimits = this.getToolResultCompactLimits('aggressive');

            [
                'tool_use_id', 'tool', 'path', 'requested_path', 'path_corrected',
                'type', 'length', 'item_offset', 'item_count', 'truncated',
                'next_item_offset', 'offset', 'returned_chars', 'next_offset',
                'size', 'modified', 'count', 'error', 'message', 'url', 'title',
                'ability', 'success', 'instruction'
            ].forEach(function(key) {
                if (Object.prototype.hasOwnProperty.call(value, key)) {
                    summary[key] = this.compactProviderValue(value[key], aggressiveLimits, 0);
                }
            }, this);

            if (typeof value.content === 'string') {
                summary.content_preview = this.truncateProviderString(value.content, 6000);
                summary.content_original_chars = value.content.length;
            }

            if (Array.isArray(value.items)) {
                summary.items_sample = value.items.slice(0, 3).map(function(item) {
                    return this.summarizeProviderValueShape(item, 2);
                }, this);
            }

            if (
                value.result !== undefined &&
                Object.prototype.hasOwnProperty.call(value, 'ability') &&
                Object.prototype.hasOwnProperty.call(value, 'success')
            ) {
                summary.result_summary = this.summarizeProviderValueShape(value.result, 0);
                summary.instruction = summary.instruction || 'Inspect a narrow child path for the returned data. Object-like ability results are usually exposed at the top level; scalar or list ability results are under result.';
            }

            return summary;
        },

        summarizeProviderValueShape: function(value, depth) {
            depth = depth || 0;

            if (value === null || value === undefined || typeof value !== 'object') {
                if (typeof value === 'string') {
                    return {
                        type: 'string',
                        chars: value.length,
                        preview: this.truncateProviderString(value, 500)
                    };
                }

                return value;
            }

            if (Array.isArray(value)) {
                return {
                    type: 'array',
                    length: value.length,
                    sample: depth >= 1 ? undefined : value.slice(0, 3).map(function(item) {
                        return this.summarizeProviderValueShape(item, depth + 1);
                    }, this)
                };
            }

            var keys = Object.keys(value);
            var summary = {
                type: 'object',
                keys: keys.slice(0, 30)
            };

            if (depth >= 2) {
                return summary;
            }

            var fields = {};
            keys.slice(0, 12).forEach(function(key) {
                fields[key] = this.summarizeProviderValueShape(value[key], depth + 1);
            }, this);
            summary.fields = fields;

            return summary;
        },

        addToolInspectionHint: function(value, toolUseId) {
            if (!toolUseId || !value || typeof value !== 'object') {
                return value;
            }

            var hintToolUseId = String(toolUseId);
            var hint = null;

            if (
                !Array.isArray(value) &&
                value.tool_use_id &&
                Object.prototype.hasOwnProperty.call(value, 'path') &&
                Object.prototype.hasOwnProperty.call(value, 'type')
            ) {
                hintToolUseId = String(value.tool_use_id);
                hint = {
                    tool_use_id: hintToolUseId,
                    path: String(value.path || ''),
                    instruction: 'This inspect_tool_result response was compacted, but it inspected a cached original result. Continue inspecting that original result with this same tool_use_id and path; use the provided offset fields to request the next slice.'
                };

                if (value.next_item_offset !== undefined && value.next_item_offset !== null) {
                    hint.item_offset = value.next_item_offset;
                    hint.instruction = 'This inspect_tool_result response was compacted, but it inspected a cached original result. Call inspect_tool_result again with this tool_use_id, path, and item_offset to continue the array slice.';
                } else if (value.next_offset !== undefined && value.next_offset !== null) {
                    hint.offset = value.next_offset;
                    hint.instruction = 'This inspect_tool_result response was compacted, but it inspected a cached original result. Call inspect_tool_result again with this tool_use_id, path, and offset to continue the text/JSON slice.';
                }
            }

            if (!hint) {
                hint = {
                    tool_use_id: hintToolUseId,
                    instruction: 'This result was compacted before it was returned to the LLM. You can call inspect_tool_result multiple times with this same tool_use_id. Use a narrow path plus search, offset/next_offset, or item_offset/next_item_offset to inspect additional slices. Do not rerun the original broad tool call just to inspect more of this cached result.'
                };
            }

            var metadata = {
                returned_to_llm_truncated: true,
                truncation_reason: 'Tool result exceeded the provider-safe context budget and was compacted before being returned to the LLM.'
            };

            if (Array.isArray(value)) {
                return {
                    _truncated: true,
                    returned_to_llm_truncated: true,
                    truncation_reason: metadata.truncation_reason,
                    type: 'array',
                    length: value.length,
                    sample: value,
                    inspect_tool_result: hint
                };
            }

            return $.extend({}, value, metadata, {
                inspect_tool_result: hint
            });
        },

        getToolResultStringifyMode: function(providerOrMode, mode) {
            if (mode) {
                return mode;
            }
            if (providerOrMode === 'aggressive' || providerOrMode === 'recovery') {
                return providerOrMode;
            }
            return undefined;
        },

        stringifyToolResultForProvider: function(result, providerOrMode, mode, options) {
            options = options || {};
            mode = this.getToolResultStringifyMode(providerOrMode, mode);
            var limits = this.getToolResultCompactLimits(mode);
            result = this.dedupeLargeProviderStrings(result);
            var compacted = this.compactProviderValue(result, limits, 0);
            if (options.toolUseId && this.isCompactedToolResultValue(compacted, 0)) {
                compacted = this.addToolInspectionHint(compacted, options.toolUseId);
            }
            var json = this.safeJsonStringify(compacted);

            if (json.length <= limits.maxResultChars) {
                return json;
            }

            limits = this.getToolResultCompactLimits('aggressive');
            compacted = this.compactProviderValue(result, limits, 0);
            if (options.toolUseId && this.isCompactedToolResultValue(compacted, 0)) {
                compacted = this.addToolInspectionHint(compacted, options.toolUseId);
            }
            json = this.safeJsonStringify(compacted);

            if (json.length <= limits.maxResultChars) {
                return json;
            }

            compacted = this.createProviderValueSummary(result);
            if (options.toolUseId) {
                compacted = this.addToolInspectionHint(compacted, options.toolUseId);
            }
            json = this.safeJsonStringify(compacted);
            if (json.length <= limits.maxResultChars) {
                return json;
            }

            compacted = {
                _truncated: true,
                reason: 'Tool result exceeded the provider context limit.',
                original_chars: json.length,
                preview: this.truncateProviderString(json, Math.max(1000, limits.maxResultChars - 1000))
            };
            if (options.toolUseId) {
                compacted = this.addToolInspectionHint(compacted, options.toolUseId);
            }
            return this.safeJsonStringify(compacted);
        },

        compactExistingToolResultContent: function(content, mode, options) {
            options = options || {};
            content = String(content || '');
            if (content.length <= this.getToolResultCompactLimits(mode).maxResultChars) {
                return content;
            }

            try {
                return this.stringifyToolResultForProvider(JSON.parse(content), mode, undefined, options);
            } catch (e) {
                return this.truncateProviderString(content, this.getToolResultCompactLimits(mode).maxResultChars);
            }
        },

        compactProviderMessageForRequest: function(message, provider, mode) {
            message = this.stripMessageMetadata(message);
            if (!message || typeof message !== 'object') {
                return message;
            }

            var limits = this.getToolResultCompactLimits(mode);
            var requestTextLimit = Math.max(limits.maxStringChars, Math.floor(limits.maxResultChars / 2));
            var compacted = null;

            if (typeof message.content === 'string' && message.content.length > requestTextLimit) {
                compacted = $.extend({}, message, {
                    content: this.truncateProviderString(message.content, requestTextLimit)
                });
            } else if (Array.isArray(message.content)) {
                var changed = false;
                var content = message.content.map(function(block) {
                    if (!block || typeof block !== 'object') {
                        return block;
                    }

                    if (block.type === 'tool_result' && block.content) {
                        var nextContent = this.compactExistingToolResultContent(block.content, mode, {
                            toolUseId: block.tool_use_id
                        });
                        if (nextContent !== block.content) {
                            changed = true;
                            return $.extend({}, block, { content: nextContent });
                        }
                    }

                    if (block.type === 'tool_use' && block.input) {
                        var nextInput = this.compactProviderValue(block.input, limits, 0);
                        if (this.safeJsonStringify(nextInput) !== this.safeJsonStringify(block.input)) {
                            changed = true;
                            return $.extend({}, block, { input: nextInput });
                        }
                    }

                    if (block.type === 'text' && block.text && block.text.length > requestTextLimit) {
                        changed = true;
                        return $.extend({}, block, {
                            text: this.truncateProviderString(block.text, requestTextLimit)
                        });
                    }

                    return block;
                }, this);

                if (changed) {
                    compacted = $.extend({}, message, { content: content });
                }
            }

            var output = compacted || message;

            if (output.role === 'tool' && typeof output.content === 'string') {
                var toolContent = this.compactExistingToolResultContent(output.content, mode, {
                    toolUseId: output.tool_call_id
                });
                if (toolContent !== output.content) {
                    output = $.extend({}, output, { content: toolContent });
                }
            }

            if (output.tool_calls && Array.isArray(output.tool_calls)) {
                var toolCallsChanged = false;
                var toolCalls = output.tool_calls.map(function(toolCall) {
                    if (!toolCall || typeof toolCall !== 'object' || !toolCall.function) {
                        return toolCall;
                    }

                    var args = toolCall.function.arguments;
                    if (typeof args !== 'string' || args.length <= requestTextLimit) {
                        return toolCall;
                    }

                    toolCallsChanged = true;
                    var nextArgs = args;
                    try {
                        nextArgs = this.stringifyToolResultForProvider(JSON.parse(args), mode);
                    } catch (e) {
                        nextArgs = this.truncateProviderString(args, requestTextLimit);
                    }

                    return $.extend({}, toolCall, {
                        function: $.extend({}, toolCall.function, { arguments: nextArgs })
                    });
                }, this);

                if (toolCallsChanged) {
                    output = $.extend({}, output, { tool_calls: toolCalls });
                }
            }

            return output;
        },

        compactProviderMessageForStorage: function(message) {
            if (!message || typeof message !== 'object') {
                return message;
            }

            if (Array.isArray(message.content)) {
                var changed = false;
                var content = message.content.map(function(block) {
                    if (!block || typeof block !== 'object') {
                        return block;
                    }

                    if (block.type !== 'tool_result' || !block.content) {
                        return block;
                    }

                    var nextContent = this.compactExistingToolResultContent(block.content, undefined, {
                        toolUseId: block.tool_use_id
                    });
                    if (nextContent === block.content) {
                        return block;
                    }

                    changed = true;
                    return $.extend({}, block, { content: nextContent });
                }, this);

                if (changed) {
                    return $.extend({}, message, { content: content });
                }
            }

            if (message.role === 'tool' && typeof message.content === 'string') {
                var toolContent = this.compactExistingToolResultContent(message.content, undefined, {
                    toolUseId: message.tool_call_id
                });
                if (toolContent !== message.content) {
                    return $.extend({}, message, { content: toolContent });
                }
            }

            return message;
        },

        compactMessagesForStorage: function(messages) {
            return messages || [];
        },

        shouldPruneStaleToolResults: function() {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            return config.pruneStaleToolResults !== false;
        },

        hasAssistantAfterMessage: function(messages, index) {
            messages = messages || [];
            for (var i = index + 1; i < messages.length; i++) {
                if (messages[i] && messages[i].role === 'assistant') {
                    return true;
                }
            }

            return false;
        },

        isCompactedToolResultValue: function(value, depth) {
            depth = depth || 0;
            if (!value || typeof value !== 'object' || depth > 8) {
                return false;
            }

            if (
                value._truncated === true ||
                value.content_truncated_for_context === true ||
                (
                    value.truncated === true &&
                    (
                        Object.prototype.hasOwnProperty.call(value, 'next_offset') ||
                        Object.prototype.hasOwnProperty.call(value, 'returned_bytes') ||
                        Object.prototype.hasOwnProperty.call(value, 'returned_chars')
                    )
                ) ||
                Object.prototype.hasOwnProperty.call(value, '_truncated_items') ||
                Object.prototype.hasOwnProperty.call(value, '_truncated_keys') ||
                Object.prototype.hasOwnProperty.call(value, 'content_original_chars')
            ) {
                return true;
            }

            if (typeof value.content === 'string' && value.content.indexOf('[... truncated ') !== -1) {
                return true;
            }

            if (Array.isArray(value)) {
                return value.some(function(item) {
                    return this.isCompactedToolResultValue(item, depth + 1);
                }, this);
            }

            return Object.keys(value).some(function(key) {
                return this.isCompactedToolResultValue(value[key], depth + 1);
            }, this);
        },

        shouldKeepStaleToolResult: function(toolCall) {
            if (!toolCall || !toolCall.name) {
                return false;
            }

            if (toolCall.name === 'skill') {
                return true;
            }

            if (
                toolCall.name === 'ability' &&
                toolCall.input &&
                (toolCall.input.action === 'list' || toolCall.input.action === 'get')
            ) {
                return true;
            }

            return false;
        },

        getStaleReadFileResultKeepLimit: function() {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            var value = parseInt(config.staleReadFileResultKeepLimit, 10);
            return Number.isFinite(value) && value >= 0 ? value : 8;
        },

        getReadFileRequestIntegerForProvider: function(value) {
            var number = parseInt(value, 10);
            return Number.isFinite(number) ? number : null;
        },

        getReadFileRequestKeyForProvider: function(input, fallbackPath) {
            input = input || {};
            var path = input.path || fallbackPath || '';
            var key = {
                path: String(path || '').replace(/^\/+|\/+$/g, '')
            };
            var value;

            if (!key.path) {
                return '';
            }

            if (input.search) {
                key.search = String(input.search);
                value = this.getReadFileRequestIntegerForProvider(input.occurrence);
                if (value !== null && value > 1) {
                    key.occurrence = value;
                }
                value = this.getReadFileRequestIntegerForProvider(input.before_lines);
                if (value !== null && value > 0) {
                    key.before_lines = value;
                }
                value = this.getReadFileRequestIntegerForProvider(input.after_lines);
                if (value !== null && value !== 80) {
                    key.after_lines = value;
                }
            } else if (input.offset !== undefined) {
                value = this.getReadFileRequestIntegerForProvider(input.offset);
                if (value !== null && value > 0) {
                    key.offset = value;
                }
            }

            value = this.getReadFileRequestIntegerForProvider(input.max_length);
            if (value !== null && value !== 65536) {
                key.max_length = value;
            }

            return JSON.stringify(key);
        },

        getReadFilePathKeyForProvider: function(path) {
            return String(path || '').replace(/^\/+|\/+$/g, '');
        },

        parseProviderToolResultContent: function(content) {
            if (!content || typeof content !== 'string') {
                return {};
            }

            try {
                return JSON.parse(content);
            } catch (e) {
                return {};
            }
        },

        getFileMutationPathFromProviderResult: function(toolCall, content, isError) {
            var toolName = toolCall && toolCall.name;
            var input = toolCall && toolCall.input ? toolCall.input : {};
            var result = this.parseProviderToolResultContent(content);
            var path = this.getReadFilePathKeyForProvider((result && result.path) || input.path || '');

            if (!path || isError || !toolName || ['write_file', 'edit_file', 'delete_file'].indexOf(toolName) < 0) {
                return '';
            }

            if (result && result.error) {
                return '';
            }

            if (toolName === 'edit_file') {
                return parseInt(result.edits_applied, 10) > 0 ? path : '';
            }

            if (toolName === 'delete_file') {
                return result.action === 'deleted' ? path : '';
            }

            return (result.action === 'created' || result.action === 'updated') ? path : '';
        },

        collectInvalidatedReadFileIds: function(messages, provider, toolCalls) {
            var invalidatedIds = {};
            var mutationPathsByLaterMessage = {};
            var self = this;

            function markReadIfInvalidated(toolUseId) {
                var toolCall = toolCalls && toolCalls[toolUseId];
                var input = toolCall && toolCall.input ? toolCall.input : {};
                var path = self.getReadFilePathKeyForProvider(input.path || '');

                if (toolCall && toolCall.name === 'read_file' && path && mutationPathsByLaterMessage[path]) {
                    invalidatedIds[toolUseId] = true;
                }
            }

            function collectMessageMutationPaths(message) {
                var paths = {};

                if (provider === 'anthropic') {
                    if (!message || message.role !== 'user' || !Array.isArray(message.content)) {
                        return paths;
                    }

                    message.content.forEach(function(block) {
                        var path;
                        if (!block || block.type !== 'tool_result' || !block.tool_use_id) {
                            return;
                        }

                        path = self.getFileMutationPathFromProviderResult(
                            toolCalls[block.tool_use_id],
                            block.content,
                            block.is_error === true
                        );
                        if (path) {
                            paths[path] = true;
                        }
                    });
                    return paths;
                }

                if (!message || message.role !== 'tool' || !message.tool_call_id) {
                    return paths;
                }

                var path = self.getFileMutationPathFromProviderResult(
                    toolCalls[message.tool_call_id],
                    message.content,
                    false
                );
                if (path) {
                    paths[path] = true;
                }
                return paths;
            }

            for (var i = (messages || []).length - 1; i >= 0; i--) {
                var message = messages[i];
                var sameMessageMutationPaths = collectMessageMutationPaths(message);
                var content;
                var j;

                if (provider === 'anthropic') {
                    if (message && message.role === 'user' && Array.isArray(message.content)) {
                        content = message.content;
                        for (j = content.length - 1; j >= 0; j--) {
                            if (
                                content[j] &&
                                content[j].type === 'tool_result' &&
                                content[j].tool_use_id
                            ) {
                                Object.keys(sameMessageMutationPaths).forEach(function(path) {
                                    mutationPathsByLaterMessage[path] = true;
                                });
                                markReadIfInvalidated(content[j].tool_use_id);
                            }
                        }
                    }
                } else if (message && message.role === 'tool' && message.tool_call_id) {
                    Object.keys(sameMessageMutationPaths).forEach(function(path) {
                        mutationPathsByLaterMessage[path] = true;
                    });
                    markReadIfInvalidated(message.tool_call_id);
                }

                Object.keys(sameMessageMutationPaths).forEach(function(path) {
                    mutationPathsByLaterMessage[path] = true;
                });
            }

            return invalidatedIds;
        },

        collectRecentReadFileResultIdsToKeep: function(messages, provider, toolCalls, invalidatedIds) {
            var limit = this.getStaleReadFileResultKeepLimit();
            var keepIds = {};
            var seenRequestKeys = {};
            var kept = 0;
            var self = this;

            if (limit <= 0) {
                return keepIds;
            }

            function keepToolResultId(toolUseId) {
                var toolCall = toolCalls && toolCalls[toolUseId];
                var input = toolCall && toolCall.input ? toolCall.input : {};
                var requestKey;

                if (invalidatedIds && invalidatedIds[toolUseId]) {
                    return false;
                }

                if (!toolCall || toolCall.name !== 'read_file') {
                    return false;
                }

                requestKey = self.getReadFileRequestKeyForProvider(input, input.path);
                if (!requestKey || seenRequestKeys[requestKey]) {
                    return false;
                }

                seenRequestKeys[requestKey] = true;
                keepIds[toolUseId] = true;
                kept++;
                return kept >= limit;
            }

            for (var i = (messages || []).length - 1; i >= 0; i--) {
                var message = messages[i];
                var content;
                var j;

                if (!message || typeof message !== 'object') {
                    continue;
                }

                if (provider === 'anthropic') {
                    if (message.role !== 'user' || !Array.isArray(message.content)) {
                        continue;
                    }

                    content = message.content;
                    for (j = content.length - 1; j >= 0; j--) {
                        if (
                            content[j] &&
                            content[j].type === 'tool_result' &&
                            content[j].tool_use_id &&
                            keepToolResultId(content[j].tool_use_id)
                        ) {
                            return keepIds;
                        }
                    }
                    continue;
                }

                if (
                    message.role === 'tool' &&
                    message.tool_call_id &&
                    keepToolResultId(message.tool_call_id)
                ) {
                    return keepIds;
                }
            }

            return keepIds;
        },

        shouldPruneStaleToolResultContent: function(content, toolCall) {
            if (toolCall && toolCall.name === 'inspect_tool_result') {
                try {
                    var inspectResult = JSON.parse(String(content || '{}'));
                    return !!(
                        inspectResult &&
                        inspectResult.error === 'Cached tool result is no longer available'
                    );
                } catch (e) {
                    return false;
                }
            }

            if (this.shouldKeepStaleToolResult(toolCall)) {
                return false;
            }

            content = String(content || '');
            if (content.length > this.getToolResultCompactLimits().maxResultChars) {
                return true;
            }

            try {
                return this.isCompactedToolResultValue(JSON.parse(content), 0);
            } catch (e) {
                return content.indexOf('[... truncated ') !== -1;
            }
        },

        collectToolCallsById: function(messages, provider) {
            var toolCalls = {};

            (messages || []).forEach(function(message) {
                if (!message || typeof message !== 'object') {
                    return;
                }

                if (provider === 'anthropic' && Array.isArray(message.content)) {
                    message.content.forEach(function(block) {
                        if (!block || block.type !== 'tool_use' || !block.id) {
                            return;
                        }

                        toolCalls[block.id] = {
                            id: block.id,
                            name: block.name || '',
                            input: block.input || {}
                        };
                    });
                }

                if (provider !== 'anthropic' && Array.isArray(message.tool_calls)) {
                    message.tool_calls.forEach(function(toolCall) {
                        if (!toolCall || !toolCall.id) {
                            return;
                        }

                        var args = toolCall.function ? toolCall.function.arguments : toolCall.arguments;
                        var input = {};
                        if (typeof args === 'string' && args) {
                            try {
                                input = JSON.parse(args);
                            } catch (e) {
                                input = {};
                            }
                        } else if (args && typeof args === 'object') {
                            input = args;
                        }

                        toolCalls[toolCall.id] = {
                            id: toolCall.id,
                            name: toolCall.function ? toolCall.function.name : (toolCall.name || ''),
                            input: input
                        };
                    });
                }
            });

            return toolCalls;
        },

        collectStaleToolIds: function(messages, provider) {
            var staleIds = {};
            var toolCalls = this.collectToolCallsById(messages, provider);
            var invalidatedReadFileIds = this.collectInvalidatedReadFileIds(messages, provider, toolCalls);
            var readFileIdsToKeep = this.collectRecentReadFileResultIdsToKeep(messages, provider, toolCalls, invalidatedReadFileIds);

            (messages || []).forEach(function(message, index) {
                if (provider === 'anthropic') {
                    return;
                }

                if (!message || message.role !== 'tool' || !message.tool_call_id) {
                    return;
                }

                if (
                    invalidatedReadFileIds[message.tool_call_id] ||
                    (
                        !readFileIdsToKeep[message.tool_call_id] &&
                    this.hasAssistantAfterMessage(messages, index) &&
                    this.shouldPruneStaleToolResultContent(message.content, toolCalls[message.tool_call_id])
                    )
                ) {
                    staleIds[message.tool_call_id] = true;
                }
            }, this);

            if (provider !== 'anthropic') {
                return staleIds;
            }

            (messages || []).forEach(function(message, index) {
                if (!message || message.role !== 'user' || !Array.isArray(message.content)) {
                    return;
                }

                var stale = this.hasAssistantAfterMessage(messages, index);
                if (!stale) {
                    return;
                }

                message.content.forEach(function(block) {
                    if (!block || block.type !== 'tool_result' || !block.tool_use_id) {
                        return;
                    }

                    if (invalidatedReadFileIds[block.tool_use_id]) {
                        staleIds[block.tool_use_id] = true;
                        return;
                    }

                    if (readFileIdsToKeep[block.tool_use_id]) {
                        return;
                    }

                    if (!this.shouldPruneStaleToolResultContent(block.content, toolCalls[block.tool_use_id])) {
                        return;
                    }

                    staleIds[block.tool_use_id] = true;
                }, this);
            }, this);

            return staleIds;
        },

        pruneStaleToolCallsForRequest: function(messages, provider) {
            messages = messages || [];
            if (!this.shouldPruneStaleToolResults()) {
                return messages;
            }

            var staleIds = this.collectStaleToolIds(messages, provider);
            if (Object.keys(staleIds).length === 0) {
                return messages;
            }

            return messages.map(function(message) {
                if (!message || typeof message !== 'object') {
                    return message;
                }

                if (provider === 'anthropic') {
                    if (message.role === 'assistant' && Array.isArray(message.content)) {
                        var assistantContent = message.content.filter(function(block) {
                            return !(block && block.type === 'tool_use' && staleIds[block.id]);
                        });
                        if (assistantContent.length === 0) {
                            return null;
                        }
                        if (assistantContent.length !== message.content.length) {
                            return $.extend({}, message, { content: assistantContent });
                        }
                    }

                    if (message.role === 'user' && Array.isArray(message.content)) {
                        var userContent = message.content.filter(function(block) {
                            return !(block && block.type === 'tool_result' && staleIds[block.tool_use_id]);
                        });
                        if (userContent.length === 0) {
                            return null;
                        }
                        if (userContent.length !== message.content.length) {
                            return $.extend({}, message, { content: userContent });
                        }
                    }

                    return message;
                }

                if (message.role === 'assistant' && Array.isArray(message.tool_calls)) {
                    var toolCalls = message.tool_calls.filter(function(toolCall) {
                        return !(toolCall && staleIds[toolCall.id]);
                    });
                    if (toolCalls.length !== message.tool_calls.length) {
                        if (!message.content && toolCalls.length === 0) {
                            return null;
                        }
                        return $.extend({}, message, { tool_calls: toolCalls.length ? toolCalls : undefined });
                    }
                }

                if (message.role === 'tool' && staleIds[message.tool_call_id]) {
                    return null;
                }

                return message;
            }).filter(function(message) {
                return !!message;
            });
        },

        estimateProviderRequestChars: function(messages) {
            return (this.systemPrompt || '').length + this.safeJsonStringify(messages || []).length;
        },

        isPureAnthropicToolResultMessage: function(message) {
            return !!(
                message &&
                message.role === 'user' &&
                Array.isArray(message.content) &&
                message.content.length > 0 &&
                message.content.every(function(block) {
                    return block && block.type === 'tool_result';
                })
            );
        },

        removeLeadingProviderOrphans: function(messages, provider) {
            var result = (messages || []).slice();

            while (result.length > 1) {
                var first = result[0];
                if (!first) {
                    result.shift();
                    continue;
                }

                if (first.role !== 'user') {
                    result.shift();
                    continue;
                }

                if (provider === 'anthropic' && Array.isArray(first.content)) {
                    var nonToolResultContent = first.content.filter(function(block) {
                        return !(block && block.type === 'tool_result');
                    });
                    if (nonToolResultContent.length !== first.content.length) {
                        if (nonToolResultContent.length === 0) {
                            result.shift();
                        } else {
                            result[0] = $.extend({}, first, { content: nonToolResultContent });
                        }
                        continue;
                    }
                }

                if (provider !== 'anthropic' && first.role === 'tool') {
                    result.shift();
                    continue;
                }

                break;
            }

            return result;
        },

        trimProviderMessagesToBudget: function(messages, provider, budget) {
            var result = (messages || []).slice();
            var dropped = 0;
            var minKeep = Math.min(8, Math.max(2, result.length));

            while (result.length > minKeep && this.estimateProviderRequestChars(result) > budget) {
                result.shift();
                dropped++;
                result = this.removeLeadingProviderOrphans(result, provider);
            }

            if (dropped > 0) {
                result.unshift({
                    role: 'user',
                    content: '[Earlier conversation messages were omitted to keep this request within provider token limits.]'
                });
            }

            return result;
        },

        compactProviderMessagesForRequest: function(messages, provider, mode) {
            var compacted = (messages || []).map(function(message) {
                return this.compactProviderMessageForRequest(message, provider, mode);
            }, this);
            compacted = this.pruneStaleToolCallsForRequest(compacted, provider);
            var budget = this.getProviderRequestCharLimit(mode);

            if (this.estimateProviderRequestChars(compacted) <= budget) {
                return compacted;
            }

            return this.trimProviderMessagesToBudget(compacted, provider, budget);
        },

        getProviderErrorDetails: async function(response, fallback) {
            var details = {
                status: response && response.status ? response.status : 0,
                message: fallback
            };

            try {
                var error = await response.json();
                details.message = error.error?.message ||
                    error.data?.error?.message ||
                    error.data?.message ||
                    error.message ||
                    JSON.stringify(error) ||
                    fallback;
            } catch (e) {
                details.message = fallback;
            }

            return details;
        },

        getProviderErrorMessage: async function(response, fallback) {
            var details = await this.getProviderErrorDetails(response, fallback);
            return details.message;
        },

        isProviderContextRecoverableError: function(provider, status, message) {
            message = String(message || '').toLowerCase();

            if (status === 401 || status === 403) {
                return false;
            }

            if (status === 413) {
                return true;
            }

            var contextPatterns = [
                'context length',
                'context window',
                'context limit',
                'maximum context',
                'prompt is too long',
                'prompt too long',
                'too many tokens',
                'input tokens',
                'exceed',
                'reduce the prompt',
                'request too large',
                'rate limit'
            ];

            var hasContextPattern = contextPatterns.some(function(pattern) {
                return message.indexOf(pattern) !== -1;
            });

            if (!hasContextPattern) {
                return false;
            }

            if (status === 400 || status === 413 || status === 429 || status === 500) {
                return true;
            }

            return message.indexOf('input tokens') !== -1 ||
                message.indexOf('reduce the prompt') !== -1 ||
                (message.indexOf('prompt') !== -1 && message.indexOf('token') !== -1);
        },

        buildContextRetryErrorMessage: function(retryMessage, originalMessage) {
            retryMessage = retryMessage || 'API request failed';
            originalMessage = originalMessage || retryMessage;

            if (retryMessage === originalMessage) {
                return retryMessage + ' Retried once with stricter local context compaction, but the provider still rejected the request.';
            }

            return retryMessage + ' Retried once with stricter local context compaction after: ' + originalMessage;
        },

        readSSEStream: async function*(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            try {
                while (true) {
                    var { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line.startsWith('data: ')) {
                            var data = line.slice(6);
                            if (data === '[DONE]') return;
                            try {
                                yield JSON.parse(data);
                                // Yield to browser between events to allow repaints
                                await new Promise(function(r) { requestAnimationFrame(r); });
                            } catch (e) {
                                // Skip non-JSON data lines
                            }
                        }
                    }
                }
            } finally {
                reader.releaseLock();
            }
        },

        readOllamaStream: async function*(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            try {
                while (true) {
                    var { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line) {
                            try {
                                yield JSON.parse(line);
                                // Yield to browser between events to allow repaints
                                await new Promise(function(r) { requestAnimationFrame(r); });
                            } catch (e) {
                                // Skip non-JSON lines
                            }
                        }
                    }
                }
            } finally {
                reader.releaseLock();
            }
        },

        getAnthropicToolUseIds: function(message) {
            var ids = [];
            if (!message || message.role !== 'assistant' || !Array.isArray(message.content)) {
                return ids;
            }

            message.content.forEach(function(block) {
                if (block && block.type === 'tool_use' && block.id) {
                    ids.push(block.id);
                }
            });
            return ids;
        },

        isAnthropicToolResultMessage: function(message, toolUseIds) {
            if (!message || message.role !== 'user' || !Array.isArray(message.content)) {
                return false;
            }

            var required = {};
            var found = {};
            toolUseIds.forEach(function(id) {
                required[id] = true;
            });

            for (var i = 0; i < message.content.length; i++) {
                var block = message.content[i];
                if (!block || block.type !== 'tool_result') {
                    break;
                }

                if (!required[block.tool_use_id]) {
                    return false;
                }

                if (block.tool_use_id) {
                    found[block.tool_use_id] = true;
                }
            }

            return toolUseIds.every(function(id) {
                return !!found[id];
            });
        },

        buildUnavailableAnthropicToolResultMessage: function(toolUseIds) {
            return {
                role: 'user',
                content: toolUseIds.map(function(id) {
                    return {
                        type: 'tool_result',
                        tool_use_id: id,
                        is_error: true,
                        content: JSON.stringify({
                            error: 'Tool result is unavailable because the conversation history no longer contains the approved result.',
                            instruction: 'Do not tell the user they skipped this action unless the tool result explicitly says the user declined it.'
                        })
                    };
                })
            };
        },

        extractAnthropicToolResultsFromRange: function(messages, startIndex, toolUseIds) {
            var required = {};
            var found = {};
            var foundCount = 0;

            toolUseIds.forEach(function(id) {
                required[id] = true;
            });

            for (var i = startIndex; i < messages.length && foundCount < toolUseIds.length; i++) {
                var message = messages[i];

                if (i > startIndex && this.getAnthropicToolUseIds(message).length > 0) {
                    break;
                }

                if (!message || message.role !== 'user' || !Array.isArray(message.content)) {
                    continue;
                }

                var nextContent = [];
                var removed = false;

                message.content.forEach(function(block) {
                    if (
                        block &&
                        block.type === 'tool_result' &&
                        required[block.tool_use_id] &&
                        !found[block.tool_use_id]
                    ) {
                        found[block.tool_use_id] = block;
                        foundCount++;
                        removed = true;
                        return;
                    }

                    nextContent.push(block);
                });

                if (removed) {
                    messages[i] = Object.assign({}, message, { content: nextContent });
                }
            }

            if (foundCount !== toolUseIds.length) {
                return null;
            }

            return {
                role: 'user',
                content: toolUseIds.map(function(id) {
                    return found[id];
                })
            };
        },

        repairAnthropicMessages: function(messages) {
            var repaired = false;
            var result = [];
            var workingMessages = messages.map(function(message) {
                if (message && Array.isArray(message.content)) {
                    return Object.assign({}, message, { content: message.content.slice() });
                }
                return message;
            });

            for (var i = 0; i < workingMessages.length; i++) {
                var message = workingMessages[i];
                var toolUseIds = this.getAnthropicToolUseIds(message);

                if (toolUseIds.length > 0) {
                    result.push(message);

                    var nextMessage = workingMessages[i + 1];
                    if (this.isAnthropicToolResultMessage(nextMessage, toolUseIds)) {
                        result.push(nextMessage);
                        i++;
                    } else {
                        var recoveredResult = this.extractAnthropicToolResultsFromRange(workingMessages, i + 1, toolUseIds);
                        result.push(recoveredResult || this.buildUnavailableAnthropicToolResultMessage(toolUseIds));
                        repaired = true;
                    }
                    continue;
                }

                if (message && message.role === 'user' && Array.isArray(message.content)) {
                    if (message.content.length === 0) {
                        repaired = true;
                        continue;
                    }

                    var contentWithoutOrphanResults = message.content.filter(function(block) {
                        return !(block && block.type === 'tool_result');
                    });
                    if (contentWithoutOrphanResults.length !== message.content.length) {
                        repaired = true;
                        if (contentWithoutOrphanResults.length === 0) {
                            continue;
                        }
                        message = Object.assign({}, message, { content: contentWithoutOrphanResults });
                    }
                }

                result.push(message);
            }

            return {
                messages: result,
                repaired: repaired
            };
        },

        prepareAnthropicMessages: function(messages, options) {
            options = options || {};
            if (typeof messages === 'string') {
                options.mode = messages;
                messages = this.messages;
            }
            messages = Array.isArray(messages) ? messages : this.messages;
            var prepared = this.repairAnthropicMessages(messages);

            if (prepared.repaired && !options.promptOnly) {
                this.messages = prepared.messages;
                this.updateTokenCount();
                this.autoSaveConversation();
            }

            var requestMessages = prepared.messages.map(function(message) {
                return this.stripMessageMetadata(message);
            }, this);

            return this.compactProviderMessagesForRequest(requestMessages, 'anthropic', options.mode || 'normal');
        },

        canSendAnthropicMessages: function(messages) {
            if (!Array.isArray(messages) || messages.length === 0) {
                return false;
            }

            return messages[messages.length - 1].role === 'user';
        },

        callAnthropic: async function() {
            var self = this;
            var model = this.conversationModel || this.getModel();
            var apiKey = this.getApiKey('anthropic');

            try {
                var requestMode = 'normal';
                var requestState = this.prepareProviderRequestMessages
                    ? await this.prepareProviderRequestMessages('anthropic', this.messages)
                    : { messages: this.messages, compacted: false };
                var buildAnthropicRequestMessages = function(mode) {
                    return this.prepareAnthropicMessages(requestState.messages, {
                        promptOnly: !!requestState.compacted,
                        mode: mode
                    });
                }.bind(this);
                var requestMessages = buildAnthropicRequestMessages(requestMode);
                if (!this.canSendAnthropicMessages(requestMessages)) {
                    console.warn('[AI Assistant] Skipped Anthropic request because the message history does not end with a user message.');
                    this.setLoading(false);
                    return;
                }
                var endpoint = this.getProviderEndpoint('anthropic') || 'https://api.anthropic.com/v1/messages';
                var headers = {
                    'Content-Type': 'application/json',
                    'x-api-key': apiKey,
                    'anthropic-version': '2023-06-01',
                    'anthropic-dangerous-direct-browser-access': 'true'
                };
                var buildPayload = function(messages) {
                    var payload = {
                        model: model,
                        max_tokens: 16384,
                        stream: true,
                        system: this.systemPrompt,
                        messages: messages,
                        tools: this.getTools()
                    };

                    if (this.isAnthropicPromptCacheEnabled && this.isAnthropicPromptCacheEnabled()) {
                        payload.cache_control = { type: 'ephemeral' };
                    }

                    return payload;
                }.bind(this);
                var response = await this.fetchLLMProvider(
                    'anthropic',
                    endpoint,
                    headers,
                    buildPayload(requestMessages),
                    this.abortController ? this.abortController.signal : undefined
                );

                if (!response.ok) {
                    var errorDetails = await this.getProviderErrorDetails(response, 'API request failed');
                    if (this.isProviderContextRecoverableError('anthropic', errorDetails.status, errorDetails.message)) {
                        requestMode = 'recovery';
                        requestMessages = buildAnthropicRequestMessages(requestMode);
                        if (!this.canSendAnthropicMessages(requestMessages)) {
                            console.warn('[AI Assistant] Skipped Anthropic recovery request because the message history does not end with a user message.');
                            this.setLoading(false);
                            return;
                        }
                        response = await this.fetchLLMProvider(
                            'anthropic',
                            endpoint,
                            headers,
                            buildPayload(requestMessages),
                            this.abortController ? this.abortController.signal : undefined
                        );

                        if (!response.ok) {
                            var retryDetails = await this.getProviderErrorDetails(response, 'API request failed');
                            throw new Error(this.buildContextRetryErrorMessage(retryDetails.message, errorDetails.message));
                        }
                    } else {
                        throw new Error(errorDetails.message);
                    }
                }

                var $reply = this.startReply();
                var textContent = '';
                var contentBlocks = [];
                var currentBlock = null;
                var toolCalls = [];
                var stopReason = null;
                var providerUsage = null;

                for await (var event of this.readSSEStream(response)) {
                    if (event.message && event.message.usage) {
                        providerUsage = this.mergeTokenUsage
                            ? this.mergeTokenUsage(providerUsage, event.message.usage)
                            : $.extend(providerUsage || {}, event.message.usage);
                    }
                    if (event.usage) {
                        providerUsage = this.mergeTokenUsage
                            ? this.mergeTokenUsage(providerUsage, event.usage)
                            : $.extend(providerUsage || {}, event.usage);
                    }

                    switch (event.type) {
                        case 'content_block_start':
                            currentBlock = { ...event.content_block };
                            if (currentBlock.type === 'tool_use') {
                                currentBlock.input = '';
                                self.showToolProgress(currentBlock.name, 0, currentBlock.id);
                            } else if (currentBlock.type === 'text') {
                                currentBlock.text = '';
                            }
                            break;

                        case 'content_block_delta':
                            if (event.delta.type === 'text_delta') {
                                textContent += event.delta.text;
                                if (currentBlock && currentBlock.type === 'text') {
                                    currentBlock.text += event.delta.text;
                                }
                                this.updateReply($reply, textContent);
                            } else if (event.delta.type === 'input_json_delta') {
                                if (currentBlock) {
                                    currentBlock.input += event.delta.partial_json;
                                    self.showToolProgress(currentBlock.name, currentBlock.input.length, currentBlock.id, currentBlock.input);
                                }
                            }
                            break;

                        case 'content_block_stop':
                            if (currentBlock) {
                                if (currentBlock.type === 'tool_use') {
                                    try {
                                        currentBlock.input = JSON.parse(currentBlock.input);
                                    } catch (e) {
                                        currentBlock.input = {};
                                    }
                                    // Process tool immediately - don't wait for stream to end
                                    self.processToolCallImmediate(currentBlock.id, currentBlock.name, currentBlock.input, 'anthropic');
                                }
                                contentBlocks.push(currentBlock);
                                currentBlock = null;
                            }
                            break;

                        case 'message_delta':
                            if (event.delta && event.delta.stop_reason) {
                                stopReason = event.delta.stop_reason;
                            }
                            break;
                    }
                }

                contentBlocks.forEach(function(block) {
                    if (block.type === 'tool_use') {
                        toolCalls.push({
                            id: block.id,
                            name: block.name,
                            arguments: block.input
                        });
                    }
                });

                var filteredBlocks = contentBlocks.filter(function(block) {
                    return block.type !== 'text' || (block.text && block.text.length > 0);
                });
                var message = this.createStoredMessage('assistant', filteredBlocks);
                if (!textContent) {
                    $reply.remove();
                } else {
                    this.finalizeReply($reply, message._ts);
                }
                if (this.attachTokenUsageToAssistantMessage) {
                    this.attachTokenUsageToAssistantMessage(message, 'anthropic', model, providerUsage, [
                        { role: 'system', content: this.systemPrompt },
                        ...requestMessages
                    ]);
                }
                this.messages.push(message);
                this.updateTokenCount();

                // Save conversation before processing tools (in case user reloads while pending)
                this.autoSaveConversation();

                // Mark any incomplete tools (e.g., truncated by max_tokens)
                if (this.toolCardsState) {
                    var processedIds = this.processedToolIds || {};
                    Object.keys(this.toolCardsState).forEach(function(toolId) {
                        if (!processedIds[toolId] && self.toolCardsState[toolId].state === 'generating') {
                            var message = stopReason === 'max_tokens' ? 'Truncated (max tokens)' : 'Incomplete';
                            self.setToolCardState(toolId, 'error', { message: message });
                        }
                    });
                }

                // Mark stream as complete and check if all tools resolved
                this.streamComplete = true;
                if (toolCalls.length > 0) {
                    this.checkAllToolsResolved();
                } else {
                    if (this.sendQueuedMessagesIfAvailable('anthropic')) {
                        return;
                    }
                    this.setLoading(false);
                    this.autoSaveConversation();
                }

            } catch (error) {
                if (this.isPageExiting) {
                    if (typeof this.prepareForPageExit === 'function') {
                        this.prepareForPageExit(null, { abort: false });
                    }
                    return;
                }
                var expectedAbort = this.isExpectedGenerationAbort
                    ? this.isExpectedGenerationAbort(error)
                    : error.name === 'AbortError';
                this.hideToolProgress();
                this.pendingToolResults = [];
                this.pendingActions = [];
                this.pendingToolChecks = 0;
                if (this.showToolApprovalModal) {
                    this.showToolApprovalModal();
                }
                this.setLoading(false);
                if (!expectedAbort) {
                    this.autoSaveConversation();
                    this.addMessage('error', 'Anthropic API error: ' + error.message);
                }
            }
        },

        callOpenAI: async function() {
            var self = this;
            var model = this.conversationModel || this.getModel();
            var apiKey = this.getApiKey('openai');

            try {
                var requestMode = 'normal';
                var openAiRequestState = this.prepareProviderRequestMessages
                    ? await this.prepareProviderRequestMessages('openai', this.messages)
                    : { messages: this.messages, compacted: false };
                var buildRequestMessages = function(mode) {
                    return [
                        { role: 'system', content: this.systemPrompt },
                        ...this.compactProviderMessagesForRequest(this.sanitizeMessages(openAiRequestState.messages), 'openai', mode)
                    ];
                }.bind(this);
                var requestMessages = buildRequestMessages(requestMode);

                var endpoint = this.getProviderEndpoint('openai') || 'https://api.openai.com/v1/chat/completions';
                var headers = {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + apiKey
                };
                var buildPayload = function(messages) {
                    return {
                        model: model,
                        stream: true,
                        stream_options: { include_usage: true },
                        prompt_cache_key: this.getPromptCacheKey('openai'),
                        messages: messages,
                        tools: this.getToolsOpenAI()
                    };
                }.bind(this);
                var response = await this.fetchLLMProvider(
                    'openai',
                    endpoint,
                    headers,
                    buildPayload(requestMessages),
                    this.abortController ? this.abortController.signal : undefined
                );

                if (!response.ok) {
                    var errorDetails = await this.getProviderErrorDetails(response, 'API request failed');
                    if (this.isProviderContextRecoverableError('openai', errorDetails.status, errorDetails.message)) {
                        requestMode = 'recovery';
                        requestMessages = buildRequestMessages(requestMode);
                        response = await this.fetchLLMProvider(
                            'openai',
                            endpoint,
                            headers,
                            buildPayload(requestMessages),
                            this.abortController ? this.abortController.signal : undefined
                        );

                        if (!response.ok) {
                            var retryDetails = await this.getProviderErrorDetails(response, 'API request failed');
                            throw new Error(this.buildContextRetryErrorMessage(retryDetails.message, errorDetails.message));
                        }
                    } else {
                        throw new Error(errorDetails.message);
                    }
                }

                var streamState = this.createStreamingResponseState(this.startReply());
                var toolCallsMap = {};
                var providerUsage = null;
                for await (var chunk of this.readSSEStream(response)) {
                    if (chunk.usage) {
                        providerUsage = this.mergeTokenUsage
                            ? this.mergeTokenUsage(providerUsage, chunk.usage)
                            : $.extend(providerUsage || {}, chunk.usage);
                    }

                    var delta = chunk.choices && chunk.choices[0] && chunk.choices[0].delta;
                    if (!delta) continue;

                    // Handle reasoning/thinking content (o1, o3 models)
                    if (delta.reasoning_content) {
                        this.appendThinkingContent(streamState, delta.reasoning_content);
                    }

                    if (delta.content) {
                        this.appendAssistantContent(streamState, delta.content);
                    }

                    if (delta.tool_calls) {
                        delta.tool_calls.forEach(function(tc) {
                            var idx = tc.index;
                            if (!toolCallsMap[idx]) {
                                toolCallsMap[idx] = { id: '', type: 'function', function: { name: '', arguments: '' } };
                            }
                            if (tc.id) toolCallsMap[idx].id = tc.id;
                            if (tc.function) {
                                if (tc.function.name) toolCallsMap[idx].function.name = tc.function.name;
                                if (tc.function.arguments) toolCallsMap[idx].function.arguments += tc.function.arguments;
                            }
                            var toolInfo = toolCallsMap[idx];
                            if (toolInfo.function.name && toolInfo.id) {
                                self.showToolProgress(toolInfo.function.name, toolInfo.function.arguments.length, toolInfo.id, toolInfo.function.arguments);
                            }
                        });
                    }
                }

                // Finalize thinking if stream ended during thinking phase
                this.finalizeThinkingState(streamState);

                var toolCalls = [];
                Object.keys(toolCallsMap).forEach(function(idx) {
                    var tc = toolCallsMap[idx];
                    var parsedArgs = JSON.parse(tc.function.arguments || '{}');
                    toolCalls.push({
                        id: tc.id,
                        name: tc.function.name,
                        arguments: parsedArgs
                    });
                    self.updateToolCardDescription(tc.id, tc.function.name, parsedArgs);
                });

                var messageExtra = {};
                if (streamState.thinkingContent && streamState.thinkingContent.trim()) {
                    messageExtra._thinking = streamState.thinkingContent.trim();
                    messageExtra._thinkingDurationMs = streamState.thinkingDurationMs || 0;
                }
                var message = this.createStoredMessage('assistant', streamState.textContent || null, messageExtra);
                if (!streamState.textContent) {
                    streamState.$reply.remove();
                } else {
                    this.finalizeReply(streamState.$reply, message._ts);
                }
                if (Object.keys(toolCallsMap).length > 0) {
                    message.tool_calls = Object.values(toolCallsMap);
                }
                if (this.attachTokenUsageToAssistantMessage) {
                    this.attachTokenUsageToAssistantMessage(message, 'openai', model, providerUsage, requestMessages);
                }
                this.messages.push(message);
                this.updateTokenCount();

                // Save conversation before processing tools (in case user reloads while pending)
                this.autoSaveConversation();

                // Mark stream as complete before processing tools
                this.streamComplete = true;

                if (toolCalls.length > 0) {
                    this.processToolCalls(toolCalls, 'openai');
                } else {
                    if (this.sendQueuedMessagesIfAvailable('openai')) {
                        return;
                    }
                    this.setLoading(false);
                }

            } catch (error) {
                if (this.isPageExiting) {
                    if (typeof this.prepareForPageExit === 'function') {
                        this.prepareForPageExit(null, { abort: false });
                    }
                    return;
                }
                var expectedAbort = this.isExpectedGenerationAbort
                    ? this.isExpectedGenerationAbort(error)
                    : error.name === 'AbortError';
                this.hideToolProgress();
                this.pendingToolResults = [];
                this.pendingActions = [];
                this.pendingToolChecks = 0;
                if (this.showToolApprovalModal) {
                    this.showToolApprovalModal();
                }
                this.setLoading(false);
                if (!expectedAbort) {
                    this.autoSaveConversation();
                    this.addMessage('error', 'OpenAI API error: ' + error.message);
                }
            }
        },

        // Sanitize messages before sending to the API:
        // - Drop empty assistant messages (no content, no tool_calls)
        // - Normalize empty tool_call arguments to {} (copies objects, never mutates source)
        // - Remove tool result messages whose tool_call_id has no matching tool_call
        // - Collapse consecutive user messages into one
        sanitizeMessages: function(messages) {
            var validToolCallIds = {};
            messages.forEach(function(m) {
                if (m.role === 'assistant' && m.tool_calls) {
                    m.tool_calls.forEach(function(tc) { validToolCallIds[tc.id] = true; });
                }
            });

            var result = [];
            messages.forEach(function(m) {
                m = this.stripMessageMetadata(m);

                if (m.role === 'assistant' && !m.content && !m.tool_calls) return;
                if (m.role === 'tool' && !validToolCallIds[m.tool_call_id]) return;

                if (m.role === 'assistant' && m.tool_calls) {
                    var needsCopy = m.tool_calls.some(function(tc) {
                        var args = tc.function ? tc.function.arguments : tc.arguments;
                        return args === '' || args === null || args === undefined;
                    });
                    if (needsCopy) {
                        m = Object.assign({}, m, {
                            tool_calls: m.tool_calls.map(function(tc) {
                                var args = tc.function ? tc.function.arguments : tc.arguments;
                                if (args !== '' && args !== null && args !== undefined) return tc;
                                if (tc.function) {
                                    return Object.assign({}, tc, { function: Object.assign({}, tc.function, { arguments: '{}' }) });
                                }
                                return Object.assign({}, tc, { arguments: {} });
                            })
                        });
                    }
                }

                if (m.role === 'user' && result.length > 0 && result[result.length - 1].role === 'user') {
                    var prev = result[result.length - 1];
                    var prevText = typeof prev.content === 'string' ? prev.content : '';
                    var newText = typeof m.content === 'string' ? m.content : '';
                    if (newText) prev.content = prevText ? prevText + '\n' + newText : newText;
                    return;
                }

                result.push(m);
            }, this);
            return result;
        },

        // Drop messages from the front in safe units, keeping at least the last 6 messages.
        // A "unit" is either a standalone message or an assistant+tool_calls group with all its tool results.
        trimMessagesForContext: function(messages) {
            var keep = Math.max(6, Math.floor(messages.length / 2));
            if (messages.length <= keep) return messages;

            // Find a safe cut point: start after a complete tool round-trip
            var cutAt = messages.length - keep;
            // Walk forward from cutAt until we land on a user or standalone assistant message
            while (cutAt < messages.length - 4) {
                var m = messages[cutAt];
                if (m.role === 'user' || (m.role === 'assistant' && !m.tool_calls)) break;
                cutAt++;
            }
            return messages.slice(cutAt);
        },

        callLocalLLM: async function(endpointOverride) {
            var self = this;
            var endpoint = (endpointOverride || this.getLocalEndpoint()).replace(/\/$/, '');
            var model = this.conversationModel || this.getModel();
            if (!model) {
                this.addMessage('error', 'No local model selected. Choose a model in Settings before using a local LLM.');
                this.setLoading(false);
                return;
            }

            try {
                var requestMode = 'normal';
                var localRequestState = this.prepareProviderRequestMessages
                    ? await this.prepareProviderRequestMessages('local', this.messages)
                    : { messages: this.messages, compacted: false };
                var buildRequestMessages = function(mode) {
                    return [
                        { role: 'system', content: this.systemPrompt },
                        ...this.compactProviderMessagesForRequest(this.sanitizeMessages(localRequestState.messages), 'local', mode)
                    ];
                }.bind(this);
                var requestMessages = buildRequestMessages(requestMode);

                var useOllamaApi = false;

                var abortSignal = this.abortController ? this.abortController.signal : undefined;
                var buildPayload = function(messages) {
                    return {
                        model: model,
                        stream: true,
                        messages: messages,
                        tools: this.getToolsOpenAI()
                    };
                }.bind(this);

                var response = await fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(buildPayload(requestMessages)),
                    signal: abortSignal
                });

                if (!response.ok && (response.status === 404 || response.status === 405)) {
                    useOllamaApi = true;
                    response = await fetch(endpoint + '/api/chat', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(buildPayload(requestMessages)),
                        signal: abortSignal
                    });
                }

                if (!response.ok) {
                    var errBody = '';
                    try { var errJson = await response.clone().json(); errBody = errJson.error?.message || JSON.stringify(errJson); } catch(e) {}

                    if (
                        (response.status === 500 && !errBody) ||
                        this.isProviderContextRecoverableError('local', response.status, errBody)
                    ) {
                        var originalError = errBody || 'Local LLM rejected the request, likely because the context was too large.';
                        requestMode = 'recovery';
                        requestMessages = buildRequestMessages(requestMode);
                        response = await fetch(endpoint + (useOllamaApi ? '/api/chat' : '/v1/chat/completions'), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(buildPayload(requestMessages)),
                            signal: abortSignal
                        });

                        if (!response.ok && !useOllamaApi && (response.status === 404 || response.status === 405)) {
                            useOllamaApi = true;
                            response = await fetch(endpoint + '/api/chat', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(buildPayload(requestMessages)),
                                signal: abortSignal
                            });
                        }

                        if (!response.ok) {
                            var retryErrBody = '';
                            try { var retryErrJson = await response.clone().json(); retryErrBody = retryErrJson.error?.message || JSON.stringify(retryErrJson); } catch(e) {}
                            throw new Error(this.buildContextRetryErrorMessage(
                                'Local LLM error ' + response.status + (retryErrBody ? ': ' + retryErrBody : '. Make sure Ollama or LM Studio is running.'),
                                originalError
                            ));
                        }
                    }

                    if (!response.ok) {
                        throw new Error('Local LLM error ' + response.status + (errBody ? ': ' + errBody : '. Make sure Ollama or LM Studio is running.'));
                    }
                }

                var streamState = this.createStreamingResponseState(this.startReply());
                var toolCallsMap = {};
                var streamTruncated = false;
                var enableToolsIdx = null;
                var providerUsage = null;

                if (useOllamaApi) {
                    for await (var chunk of this.readOllamaStream(response)) {
                        if (chunk.error) {
                            throw new Error(chunk.error.message || chunk.error || 'Unknown error from Ollama');
                        }
                        if (chunk.prompt_eval_count || chunk.eval_count || chunk.usage) {
                            providerUsage = this.mergeTokenUsage
                                ? this.mergeTokenUsage(providerUsage, chunk.usage || chunk)
                                : $.extend(providerUsage || {}, chunk.usage || chunk);
                        }
                        if (chunk.message && chunk.message.content) {
                            this.appendAssistantContent(streamState, chunk.message.content);
                        }
                        if (chunk.message && chunk.message.tool_calls) {
                            chunk.message.tool_calls.forEach(function(tc, idx) {
                                toolCallsMap[idx] = tc;
                                if (tc.function && tc.function.name) {
                                    var toolId = tc.id || 'ollama_tool_' + idx;
                                    var argsStr = tc.function.arguments ? JSON.stringify(tc.function.arguments) : '';
                                    self.showToolProgress(tc.function.name, argsStr.length, toolId, argsStr);
                                }
                            });
                        }
                    }
                } else {
                    for await (var chunk of this.readSSEStream(response)) {
                        if (chunk.error) {
                            throw new Error(chunk.error.message || chunk.message || 'Unknown error from local LLM');
                        }
                        if (chunk.usage) {
                            providerUsage = this.mergeTokenUsage
                                ? this.mergeTokenUsage(providerUsage, chunk.usage)
                                : $.extend(providerUsage || {}, chunk.usage);
                        }

                        var delta = chunk.choices && chunk.choices[0] && chunk.choices[0].delta;
                        if (!delta) continue;

                        // Handle reasoning/thinking content (DeepSeek, etc.)
                        if (delta.reasoning_content) {
                            this.appendThinkingContent(streamState, delta.reasoning_content);
                        }

                        if (delta.content) {
                            this.appendAssistantContent(streamState, delta.content);
                        }

                        if (delta.tool_calls) {
                            delta.tool_calls.forEach(function(tc) {
                                var idx = tc.index;
                                if (!toolCallsMap[idx]) {
                                    toolCallsMap[idx] = { id: '', type: 'function', function: { name: '', arguments: '' } };
                                }
                                if (tc.id) toolCallsMap[idx].id = tc.id;
                                if (tc.function) {
                                    if (tc.function.name) {
                                        toolCallsMap[idx].function.name = tc.function.name;
                                        if (tc.function.name === 'enable_tools') enableToolsIdx = idx;
                                    }
                                    if (tc.function.arguments) toolCallsMap[idx].function.arguments += tc.function.arguments;
                                }
                                var toolInfo = toolCallsMap[idx];
                                if (toolInfo.function.name) {
                                    var toolId = toolInfo.id || 'local_tool_' + idx;
                                    self.showToolProgress(toolInfo.function.name, toolInfo.function.arguments.length, toolId, toolInfo.function.arguments);
                                }
                            });

                            if (Object.keys(toolCallsMap).length > 10) {
                                streamTruncated = true;
                                break;
                            }

                            // Break as soon as enable_tools has complete arguments —
                            // anything else in the stream lacks schemas and must be dropped.
                            if (enableToolsIdx !== null) {
                                var enableArgs = toolCallsMap[enableToolsIdx].function.arguments;
                                if (enableArgs && enableArgs.trim()) {
                                    try {
                                        JSON.parse(enableArgs);
                                        streamTruncated = true;
                                        break;
                                    } catch (e) { /* arguments not yet complete */ }
                                }
                            }
                        }
                    }

                    // Finalize thinking if stream ended during thinking phase
                    this.finalizeThinkingState(streamState);

                    if (streamTruncated && this.abortController) {
                        this.abortController.abort();
                    }
                }

                var toolCalls = [];
                Object.keys(toolCallsMap).forEach(function(idx) {
                    var tc = toolCallsMap[idx];
                    if (tc.function) {
                        var toolId = tc.id || 'tool_' + idx;
                        var parsedArgs = JSON.parse(tc.function.arguments || '{}');
                        toolCalls.push({
                            id: toolId,
                            name: tc.function.name,
                            arguments: parsedArgs
                        });
                        self.updateToolCardDescription(toolId, tc.function.name, parsedArgs);
                    }
                });

                // Deduplicate tool calls by name+arguments before storing in message history.
                // The assistant message and the tool results sent back must stay in sync —
                // if the model requests 25 calls but we only execute 3, it will loop waiting
                // for the other 22 results.
                var seenToolSigs = {};
                var uniqueToolIds = {};
                toolCalls = toolCalls.filter(function(tc) {
                    var sig = tc.name + ':' + JSON.stringify(tc.arguments);
                    if (seenToolSigs[sig]) return false;
                    seenToolSigs[sig] = true;
                    uniqueToolIds[tc.id] = true;
                    return true;
                });
                Object.keys(toolCallsMap).forEach(function(idx) {
                    var tc = toolCallsMap[idx];
                    var toolId = tc.id || 'tool_' + idx;
                    if (!uniqueToolIds[toolId]) {
                        delete toolCallsMap[idx];
                    }
                });

                this.finalizeThinkingState(streamState);

                var strippedContent = streamState.textContent;
                var messageExtra = {};
                if (streamState.thinkingContent && streamState.thinkingContent.trim()) {
                    messageExtra._thinking = streamState.thinkingContent.trim();
                    messageExtra._thinkingDurationMs = streamState.thinkingDurationMs || 0;
                }

                var message = this.createStoredMessage('assistant', strippedContent || null, messageExtra);
                if (!strippedContent) {
                    streamState.$reply.remove();
                } else {
                    this.finalizeReply(streamState.$reply, message._ts);
                }

                if (Object.keys(toolCallsMap).length > 0) {
                    message.tool_calls = Object.values(toolCallsMap);
                }
                if (this.attachTokenUsageToAssistantMessage) {
                    this.attachTokenUsageToAssistantMessage(message, useOllamaApi ? 'ollama' : 'local', model, providerUsage, requestMessages);
                }
                this.messages.push(message);
                this.updateTokenCount();

                // Save conversation before processing tools (in case user reloads while pending)
                this.autoSaveConversation();

                // Mark stream as complete before processing tools
                this.streamComplete = true;

                if (toolCalls.length > 0) {
                    this.processToolCalls(toolCalls, 'openai');
                } else {
                    if (this.sendQueuedMessagesIfAvailable('openai')) {
                        return;
                    }
                    this.setLoading(false);
                }

            } catch (error) {
                if (this.isPageExiting) {
                    if (typeof this.prepareForPageExit === 'function') {
                        this.prepareForPageExit(null, { abort: false });
                    }
                    return;
                }
                var expectedAbort = this.isExpectedGenerationAbort
                    ? this.isExpectedGenerationAbort(error)
                    : error.name === 'AbortError';
                if (typeof streamState !== 'undefined' && streamState && streamState.$reply) {
                    streamState.$reply.remove();
                }
                this.hideToolProgress();
                this.pendingToolResults = [];
                this.pendingActions = [];
                this.pendingToolChecks = 0;
                if (this.showToolApprovalModal) {
                    this.showToolApprovalModal();
                }
                this.setLoading(false);
                if (!expectedAbort) {
                    this.autoSaveConversation();
                    this.addMessage('error', 'Local LLM error: ' + error.message);
                }
            }
        },

        // Summarization API calls
        generateConversationSummary: function(convData) {
            var provider = this.getProvider();
            var model = this.getSummarizationModel() || this.getModel();

            var summaryPrompt = 'Summarize this conversation concisely. Include:\n' +
                '1. Main topics discussed\n' +
                '2. Key decisions or outcomes\n' +
                '3. Files created or modified (if any)\n' +
                '4. Important context for continuing this work later\n\n' +
                'Keep the summary under 500 words. Focus on information that would help someone resume this conversation.\n' +
                'Do NOT include a title or "Conversation Summary" heading - just start with the content.\n\n' +
                'Conversation:\n' + convData.messages_text;

            if (provider === 'anthropic') {
                return this.callAnthropicForSummary(model, summaryPrompt);
            } else if (provider === 'openai') {
                return this.callOpenAIForSummary(model, summaryPrompt);
            } else {
                return this.callLocalForSummary(model, summaryPrompt);
            }
        },

        callAnthropicForSummary: function(model, prompt) {
            var self = this;
            var apiKey = this.getApiKey('anthropic');
            var endpoint = this.getProviderEndpoint('anthropic') || 'https://api.anthropic.com/v1/messages';
            return new Promise(function(resolve, reject) {
                self.fetchLLMProvider(
                    'anthropic',
                    endpoint,
                    {
                        'Content-Type': 'application/json',
                        'x-api-key': apiKey,
                        'anthropic-version': '2023-06-01',
                        'anthropic-dangerous-direct-browser-access': 'true'
                    },
                    {
                        model: model,
                        max_tokens: 1024,
                        messages: [{ role: 'user', content: prompt }]
                    }
                ).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (data.content && data.content[0] && data.content[0].text) {
                        resolve(data.content[0].text);
                    } else if (data.error) {
                        reject(new Error(data.error.message));
                    } else {
                        reject(new Error('Invalid response from Anthropic'));
                    }
                }).catch(reject);
            });
        },

        callOpenAIForSummary: function(model, prompt) {
            var self = this;
            var apiKey = this.getApiKey('openai');
            var endpoint = this.getProviderEndpoint('openai') || 'https://api.openai.com/v1/chat/completions';
            return new Promise(function(resolve, reject) {
                self.fetchLLMProvider(
                    'openai',
                    endpoint,
                    {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + apiKey
                    },
                    {
                        model: model,
                        max_tokens: 1024,
                        messages: [{ role: 'user', content: prompt }]
                    }
                ).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        resolve(data.choices[0].message.content);
                    } else if (data.error) {
                        reject(new Error(data.error.message));
                    } else {
                        reject(new Error('Invalid response from OpenAI'));
                    }
                }).catch(reject);
            });
        },

        callLocalForSummary: function(model, prompt) {
            var self = this;
            var endpoint = this.getLocalEndpoint().replace(/\/$/, '');

            return new Promise(function(resolve, reject) {
                fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        model: model,
                        max_tokens: 1024,
                        messages: [{ role: 'user', content: prompt }]
                    })
                }).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        resolve(self.stripReasoningTokens(data.choices[0].message.content));
                    } else {
                        reject(new Error('Invalid response from local LLM'));
                    }
                }).catch(function() {
                    fetch(endpoint + '/api/generate', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            model: model,
                            prompt: prompt,
                            stream: false
                        })
                    }).then(function(response) {
                        return response.json();
                    }).then(function(data) {
                        if (data.response) {
                            resolve(self.stripReasoningTokens(data.response));
                        } else {
                            reject(new Error('Invalid response from Ollama'));
                        }
                    }).catch(reject);
                });
            });
        }
    });

})(jQuery);
