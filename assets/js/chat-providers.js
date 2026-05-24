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
                    this.addMessage('user', item.content);
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
            if (this.playbackActive && this.playbackWaitingForUser && this.continueConversationPlaybackFromInput) {
                this.continueConversationPlaybackFromInput();
                return;
            }

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
            this.addMessage('user', messageContent);
            this.messages.push(this.createStoredMessage('user', messageContent));
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

        createAssistantStreamConsumer: function(options) {
            options = options || {};

            var self = this;
            var state = {
                textContent: '',
                toolCallsMap: {},
                toolCallOrder: [],
                providerUsage: null,
                stopReason: null,
                $reply: null,
                $thinking: null,
                thinkingContent: '',
                thinkingStartTime: null
            };

            var now = function() {
                return Date.now ? Date.now() : new Date().getTime();
            };

            var ensureReply = function() {
                if (!state.$reply) {
                    state.$reply = self.startReply();
                }
                return state.$reply;
            };

            var finishThinking = function() {
                if (!state.$thinking) {
                    return;
                }
                if (self.finalizeThinking) {
                    self.finalizeThinking(state.$thinking, now() - (state.thinkingStartTime || now()));
                }
                state.$thinking = null;
                state.thinkingStartTime = null;
            };

            var startThinking = function() {
                if (state.$thinking || !self.startThinking || !self.updateThinking) {
                    return;
                }
                if (state.$reply && state.$reply.remove) {
                    state.$reply.remove();
                    state.$reply = null;
                }
                state.$thinking = self.startThinking();
                state.thinkingStartTime = now();
            };

            var showToolProgress = function(tool) {
                if (!self.showToolProgress || !tool || !tool.function || !tool.function.name) {
                    return;
                }
                var id = tool.id || tool._fallbackId || '';
                if (!id) {
                    return;
                }
                var args = tool.function.arguments || '';
                self.showToolProgress(tool.function.name, String(args).length, id, args);
            };

            var getToolKey = function(event) {
                if (event.index !== undefined && event.index !== null) {
                    return 'index:' + event.index;
                }
                if (event.id) {
                    return 'id:' + event.id;
                }
                return 'index:' + state.toolCallOrder.length;
            };

            var ensureTool = function(event) {
                var key = getToolKey(event);
                if (!state.toolCallsMap[key]) {
                    state.toolCallsMap[key] = {
                        id: '',
                        type: 'function',
                        function: {
                            name: '',
                            arguments: ''
                        }
                    };
                    state.toolCallOrder.push(key);
                }

                var tool = state.toolCallsMap[key];
                if (event.id) {
                    tool.id = event.id;
                }
                if (event.fallbackId) {
                    tool._fallbackId = event.fallbackId;
                }
                if (event.name) {
                    tool.function.name = event.name;
                }
                if (event.argumentsText !== undefined && event.argumentsText !== null) {
                    tool.function.arguments = String(event.argumentsText);
                }
                if (event.argumentsDelta) {
                    tool.function.arguments += event.argumentsDelta;
                }
                if (event.argumentsObject !== undefined) {
                    tool.function.arguments = JSON.stringify(event.argumentsObject || {});
                }

                return tool;
            };

            return {
                state: state,

                handle: function(event) {
                    event = event || {};

                    switch (event.type) {
                        case 'usage':
                            state.providerUsage = self.mergeTokenUsage
                                ? self.mergeTokenUsage(state.providerUsage, event.usage || {})
                                : $.extend(state.providerUsage || {}, event.usage || {});
                            break;

                        case 'stop_reason':
                            state.stopReason = event.reason || null;
                            break;

                        case 'assistant_text_delta':
                            finishThinking();
                            state.textContent += event.text || '';
                            if (state.textContent || state.$reply) {
                                self.updateReply(ensureReply(), state.textContent);
                            }
                            break;

                        case 'assistant_text_snapshot':
                            finishThinking();
                            state.textContent = event.text || '';
                            if (state.textContent || state.$reply) {
                                self.updateReply(ensureReply(), state.textContent);
                            }
                            break;

                        case 'thinking_delta':
                            startThinking();
                            if (!state.$thinking) {
                                return;
                            }
                            state.thinkingContent += event.text || '';
                            self.updateThinking(state.$thinking, state.thinkingContent);
                            break;

                        case 'thinking_done':
                            finishThinking();
                            break;

                        case 'tool_call_delta':
                            showToolProgress(ensureTool(event));
                            break;

                        case 'tool_call_done':
                            var tool = ensureTool(event);
                            showToolProgress(tool);
                            if (self.updateToolCardDescription && tool.id && tool.function.name) {
                                self.updateToolCardDescription(
                                    tool.id,
                                    tool.function.name,
                                    this.parseToolArguments(tool.function.arguments)
                                );
                            }
                            break;

                        case 'tool_use_group':
                            if (event.toolUses && event.toolUses.length && self.addToolUseGroup) {
                                self.addToolUseGroup(event.toolUses);
                            }
                            break;
                    }
                },

                parseToolArguments: function(args) {
                    if (!args) {
                        return {};
                    }
                    if (typeof args === 'string') {
                        return JSON.parse(args || '{}');
                    }
                    return args;
                },

                finishAssistant: function(options) {
                    options = options || {};
                    finishThinking();

                    if (options.textContent !== undefined) {
                        state.textContent = options.textContent || '';
                    }

                    if (state.textContent && options.stripReasoningTokens && self.stripReasoningTokens) {
                        var stripped = self.stripReasoningTokens(state.textContent);
                        if (stripped !== state.textContent) {
                            state.textContent = stripped;
                            if (state.$reply) {
                                self.updateReply(state.$reply, state.textContent);
                            }
                        }
                    }

                    if (!state.textContent) {
                        if (state.$reply && state.$reply.remove) {
                            state.$reply.remove();
                        }
                    } else if (state.$reply) {
                        self.finalizeReply(state.$reply);
                    }

                    return state;
                },

                getToolCalls: function(options) {
                    options = options || {};
                    var calls = [];

                    state.toolCallOrder.forEach(function(key) {
                        var tool = state.toolCallsMap[key];
                        if (!tool || !tool.function || !tool.function.name) {
                            return;
                        }
                        var id = tool.id || tool._fallbackId || ('tool_' + calls.length);
                        var args = this.parseToolArguments(tool.function.arguments);
                        calls.push({
                            id: id,
                            name: tool.function.name,
                            arguments: args
                        });
                        if (self.updateToolCardDescription) {
                            self.updateToolCardDescription(id, tool.function.name, args);
                        }
                    }, this);

                    if (!options.deduplicate) {
                        return calls;
                    }

                    var seen = {};
                    return calls.filter(function(call) {
                        var sig = call.name + ':' + JSON.stringify(call.arguments);
                        if (seen[sig]) {
                            return false;
                        }
                        seen[sig] = true;
                        return true;
                    });
                },

                getOpenAIToolCallsForMessage: function(validIds) {
                    return state.toolCallOrder.map(function(key, index) {
                        var tool = state.toolCallsMap[key];
                        if (!tool || !tool.function || !tool.function.name) {
                            return null;
                        }
                        var id = tool.id || tool._fallbackId || ('tool_' + index);
                        if (validIds && !validIds[id]) {
                            return null;
                        }
                        return {
                            id: id,
                            type: tool.type || 'function',
                            function: {
                                name: tool.function.name,
                                arguments: tool.function.arguments || '{}'
                            }
                        };
                    }).filter(Boolean);
                }
            };
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

                var stream = this.createAssistantStreamConsumer({ provider: 'anthropic' });
                var contentBlocks = [];
                var currentBlock = null;
                var toolCalls = [];
                var stopReason = null;

                for await (var event of this.readSSEStream(response)) {
                    if (event.message && event.message.usage) {
                        stream.handle({ type: 'usage', usage: event.message.usage });
                    }
                    if (event.usage) {
                        stream.handle({ type: 'usage', usage: event.usage });
                    }

                    switch (event.type) {
                        case 'content_block_start':
                            currentBlock = { ...event.content_block };
                            if (currentBlock.type === 'tool_use') {
                                currentBlock.input = '';
                                stream.handle({
                                    type: 'tool_call_delta',
                                    id: currentBlock.id,
                                    name: currentBlock.name,
                                    argumentsText: ''
                                });
                            } else if (currentBlock.type === 'text') {
                                currentBlock.text = '';
                            }
                            break;

                        case 'content_block_delta':
                            if (event.delta.type === 'text_delta') {
                                if (currentBlock && currentBlock.type === 'text') {
                                    currentBlock.text += event.delta.text;
                                }
                                stream.handle({ type: 'assistant_text_delta', text: event.delta.text });
                            } else if (event.delta.type === 'input_json_delta') {
                                if (currentBlock) {
                                    currentBlock.input += event.delta.partial_json;
                                    stream.handle({
                                        type: 'tool_call_delta',
                                        id: currentBlock.id,
                                        name: currentBlock.name,
                                        argumentsText: currentBlock.input
                                    });
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
                                stream.handle({ type: 'stop_reason', reason: stopReason });
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

                var streamState = stream.finishAssistant();

                var filteredBlocks = contentBlocks.filter(function(block) {
                    return block.type !== 'text' || (block.text && block.text.length > 0);
                });
                var message = this.createStoredMessage('assistant', filteredBlocks);
                if (this.attachTokenUsageToAssistantMessage) {
                    this.attachTokenUsageToAssistantMessage(message, 'anthropic', model, streamState.providerUsage, [
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

                var stream = this.createAssistantStreamConsumer({ provider: 'openai' });
                for await (var chunk of this.readSSEStream(response)) {
                    if (chunk.usage) {
                        stream.handle({ type: 'usage', usage: chunk.usage });
                    }

                    var delta = chunk.choices && chunk.choices[0] && chunk.choices[0].delta;
                    if (!delta) continue;

                    // Handle reasoning/thinking content (o1, o3 models)
                    if (delta.reasoning_content) {
                        stream.handle({ type: 'thinking_delta', text: delta.reasoning_content });
                    }

                    if (delta.content) {
                        stream.handle({ type: 'assistant_text_delta', text: delta.content });
                    }

                    if (delta.tool_calls) {
                        delta.tool_calls.forEach(function(tc) {
                            stream.handle({
                                type: 'tool_call_delta',
                                index: tc.index,
                                id: tc.id || '',
                                name: tc.function && tc.function.name ? tc.function.name : '',
                                argumentsDelta: tc.function && tc.function.arguments ? tc.function.arguments : ''
                            });
                        });
                    }
                }

                var streamState = stream.finishAssistant();
                var toolCalls = stream.getToolCalls();
                var message = this.createStoredMessage('assistant', streamState.textContent || null);
                var messageToolCalls = stream.getOpenAIToolCallsForMessage();
                if (messageToolCalls.length > 0) {
                    message.tool_calls = messageToolCalls;
                }
                if (this.attachTokenUsageToAssistantMessage) {
                    this.attachTokenUsageToAssistantMessage(message, 'openai', model, streamState.providerUsage, requestMessages);
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

                var stream = this.createAssistantStreamConsumer({ provider: useOllamaApi ? 'ollama' : 'local' });
                var streamTruncated = false;
                var enableToolsIdx = null;

                if (useOllamaApi) {
                    for await (var chunk of this.readOllamaStream(response)) {
                        if (chunk.error) {
                            throw new Error(chunk.error.message || chunk.error || 'Unknown error from Ollama');
                        }
                        if (chunk.prompt_eval_count || chunk.eval_count || chunk.usage) {
                            stream.handle({ type: 'usage', usage: chunk.usage || chunk });
                        }
                        if (chunk.message && chunk.message.content) {
                            stream.handle({ type: 'assistant_text_delta', text: chunk.message.content });
                        }
                        if (chunk.message && chunk.message.tool_calls) {
                            chunk.message.tool_calls.forEach(function(tc, idx) {
                                stream.handle({
                                    type: 'tool_call_delta',
                                    index: idx,
                                    id: tc.id || 'ollama_tool_' + idx,
                                    name: tc.function && tc.function.name ? tc.function.name : '',
                                    argumentsObject: tc.function ? (tc.function.arguments || {}) : {}
                                });
                            });
                        }
                    }
                } else {
                    for await (var chunk of this.readSSEStream(response)) {
                        if (chunk.error) {
                            throw new Error(chunk.error.message || chunk.message || 'Unknown error from local LLM');
                        }
                        if (chunk.usage) {
                            stream.handle({ type: 'usage', usage: chunk.usage });
                        }

                        var delta = chunk.choices && chunk.choices[0] && chunk.choices[0].delta;
                        if (!delta) continue;

                        // Handle reasoning/thinking content (DeepSeek, etc.)
                        if (delta.reasoning_content) {
                            stream.handle({ type: 'thinking_delta', text: delta.reasoning_content });
                        }

                        if (delta.content) {
                            stream.handle({ type: 'assistant_text_delta', text: delta.content });
                        }

                        if (delta.tool_calls) {
                            delta.tool_calls.forEach(function(tc) {
                                if (tc.function && tc.function.name === 'enable_tools') {
                                    enableToolsIdx = tc.index;
                                }
                                stream.handle({
                                    type: 'tool_call_delta',
                                    index: tc.index,
                                    id: tc.id || '',
                                    fallbackId: 'local_tool_' + tc.index,
                                    name: tc.function && tc.function.name ? tc.function.name : '',
                                    argumentsDelta: tc.function && tc.function.arguments ? tc.function.arguments : ''
                                });
                            });

                            if (Object.keys(stream.state.toolCallsMap).length > 10) {
                                streamTruncated = true;
                                break;
                            }

                            // Break as soon as enable_tools has complete arguments —
                            // anything else in the stream lacks schemas and must be dropped.
                            if (enableToolsIdx !== null) {
                                var enableTool = stream.state.toolCallsMap['index:' + enableToolsIdx];
                                var enableArgs = enableTool && enableTool.function ? enableTool.function.arguments : '';
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

                    if (streamTruncated && this.abortController) {
                        this.abortController.abort();
                    }
                }

                // Deduplicate tool calls by name+arguments before storing in message history.
                // The assistant message and the tool results sent back must stay in sync —
                // if the model requests 25 calls but we only execute 3, it will loop waiting
                // for the other 22 results.
                var uniqueToolIds = {};
                var toolCalls = stream.getToolCalls({ deduplicate: true }).filter(function(tc) {
                    uniqueToolIds[tc.id] = true;
                    return true;
                });

                var streamState = stream.finishAssistant({ stripReasoningTokens: true });
                var message = this.createStoredMessage('assistant', streamState.textContent || null);
                var messageToolCalls = stream.getOpenAIToolCallsForMessage(uniqueToolIds);
                if (messageToolCalls.length > 0) {
                    message.tool_calls = messageToolCalls;
                }
                if (this.attachTokenUsageToAssistantMessage) {
                    this.attachTokenUsageToAssistantMessage(message, useOllamaApi ? 'ollama' : 'local', model, streamState.providerUsage, requestMessages);
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
                if (stream && stream.state && stream.state.$reply) stream.state.$reply.remove();
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
