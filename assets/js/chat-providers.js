(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        getMessageTimestamp: function() {
            return Date.now ? Date.now() : new Date().getTime();
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

            this.hideToolProgress();
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

        getProviderErrorMessage: async function(response, fallback) {
            try {
                var error = await response.json();
                return error.error?.message ||
                    error.data?.error?.message ||
                    error.data?.message ||
                    error.message ||
                    fallback;
            } catch (e) {
                return fallback;
            }
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

        prepareAnthropicMessages: function() {
            var prepared = this.repairAnthropicMessages(this.messages);

            if (prepared.repaired) {
                this.messages = prepared.messages;
                this.updateTokenCount();
                this.autoSaveConversation();
            }

            return prepared.messages.map(function(message) {
                return this.stripMessageMetadata(message);
            }, this);
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
                var requestMessages = this.prepareAnthropicMessages();
                if (!this.canSendAnthropicMessages(requestMessages)) {
                    console.warn('[AI Assistant] Skipped Anthropic request because the message history does not end with a user message.');
                    this.setLoading(false);
                    return;
                }
                var endpoint = this.getProviderEndpoint('anthropic') || 'https://api.anthropic.com/v1/messages';
                var response = await this.fetchLLMProvider(
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
                        max_tokens: 16384,
                        stream: true,
                        system: this.systemPrompt,
                        messages: requestMessages,
                        tools: this.getTools()
                    },
                    this.abortController ? this.abortController.signal : undefined
                );

                if (!response.ok) {
                    throw new Error(await this.getProviderErrorMessage(response, 'API request failed'));
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
                var requestMessages = [
                    { role: 'system', content: this.systemPrompt },
                    ...this.sanitizeMessages(this.messages)
                ];

                var endpoint = this.getProviderEndpoint('openai') || 'https://api.openai.com/v1/chat/completions';
                var response = await this.fetchLLMProvider(
                    'openai',
                    endpoint,
                    {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + apiKey
                    },
                    {
                        model: model,
                        stream: true,
                        stream_options: { include_usage: true },
                        messages: requestMessages,
                        tools: this.getToolsOpenAI()
                    },
                    this.abortController ? this.abortController.signal : undefined
                );

                if (!response.ok) {
                    throw new Error(await this.getProviderErrorMessage(response, 'API request failed'));
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
                var requestMessages = [
                    { role: 'system', content: this.systemPrompt },
                    ...this.sanitizeMessages(this.messages)
                ];

                var useOllamaApi = false;

                var abortSignal = this.abortController ? this.abortController.signal : undefined;

                var response = await fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        model: model,
                        stream: true,
                        messages: requestMessages,
                        tools: this.getToolsOpenAI()
                    }),
                    signal: abortSignal
                });

                if (!response.ok && (response.status === 404 || response.status === 405)) {
                    useOllamaApi = true;
                    response = await fetch(endpoint + '/api/chat', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            model: model,
                            messages: requestMessages,
                            tools: this.getToolsOpenAI(),
                            stream: true
                        }),
                        signal: abortSignal
                    });
                }

                if (!response.ok) {
                    var errBody = '';
                    try { var errJson = await response.clone().json(); errBody = errJson.error?.message || JSON.stringify(errJson); } catch(e) {}

                    // On 500 with no useful error message, assume context overflow and retry with trimmed messages
                    if (response.status === 500 && !errBody) {
                        var trimmed = self.trimMessagesForContext(requestMessages.slice(1)); // exclude system prompt
                        if (trimmed.length < requestMessages.length - 1) {
                            requestMessages = [requestMessages[0], ...trimmed];
                            self.addMessage('system', 'Context trimmed: dropped ' + (self.messages.filter(function(m){ return !m.content && !m.tool_calls; }).length === 0 ? 'older' : 'empty') + ' messages to fit context window.');
                            response = await fetch(endpoint + (useOllamaApi ? '/api/chat' : '/v1/chat/completions'), {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ model: model, stream: true, messages: requestMessages, tools: this.getToolsOpenAI() }),
                                signal: abortSignal
                            });
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
                if ($reply) $reply.remove();
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
