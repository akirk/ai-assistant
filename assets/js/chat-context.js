(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        contextCompactionCache: null,

        getContextCompactionConfig: function() {
            var config = (typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.contextCompaction) || {};
            return {
                enabled: config.enabled !== false,
                triggerTokens: Math.max(1000, parseInt(config.triggerTokens || 24000, 10)),
                recentMessages: Math.max(4, parseInt(config.recentMessages || 12, 10)),
                maxSummaryWords: Math.max(100, parseInt(config.maxSummaryWords || 700, 10))
            };
        },

        getSubagentModel: function() {
            return (this.getSummarizationModel && this.getSummarizationModel()) ||
                this.conversationModel ||
                (this.getModel ? this.getModel() : '');
        },

        shouldCompactProviderMessages: function(messages) {
            var config = this.getContextCompactionConfig();
            if (!config.enabled || !Array.isArray(messages) || messages.length <= config.recentMessages + 2) {
                return false;
            }

            var estimate = this.estimateTokensForMessages
                ? this.estimateTokensForMessages(messages, true)
                : Math.ceil(JSON.stringify(messages).length / 4);

            return estimate >= config.triggerTokens;
        },

        getContextCompactionCacheKey: function(messages) {
            messages = Array.isArray(messages) ? messages : [];
            var last = messages[messages.length - 1] || {};
            var chars = 0;
            messages.forEach(function(message) {
                try {
                    chars += JSON.stringify(message).length;
                } catch (e) {
                    chars += 0;
                }
            });
            return messages.length + ':' + (last._ts || '') + ':' + chars;
        },

        messageStartsWithToolResult: function(message) {
            if (!message) {
                return false;
            }

            if (message.role === 'tool') {
                return true;
            }

            if (!Array.isArray(message.content)) {
                return false;
            }

            return message.content.some(function(block) {
                return block && block.type === 'tool_result';
            });
        },

        isSafeCompactionTailStart: function(message) {
            if (!message || message.role !== 'user') {
                return false;
            }
            return !this.messageStartsWithToolResult(message);
        },

        findContextCompactionCutIndex: function(messages, recentMessages) {
            if (!Array.isArray(messages) || messages.length <= recentMessages + 2) {
                return -1;
            }

            var start = Math.max(1, messages.length - recentMessages);
            for (var index = start; index < messages.length - 1; index++) {
                if (this.isSafeCompactionTailStart(messages[index])) {
                    return index;
                }
            }

            return -1;
        },

        getMessageTextForContextSummary: function(message) {
            if (!message) {
                return '';
            }

            if (typeof message.content === 'string') {
                return message.content;
            }

            if (Array.isArray(message.content)) {
                return message.content.map(function(block) {
                    if (!block) return '';
                    if (block.type === 'text') return block.text || '';
                    if (block.type === 'tool_use') return '[Tool requested: ' + (block.name || 'tool') + ']';
                    if (block.type === 'tool_result') return '[Tool result: ' + String(block.content || '').substring(0, 1200) + ']';
                    return '';
                }).filter(Boolean).join('\n');
            }

            if (message.tool_calls) {
                return '[Tool requested: ' + message.tool_calls.map(function(toolCall) {
                    return toolCall.function ? toolCall.function.name : toolCall.name;
                }).filter(Boolean).join(', ') + ']';
            }

            return '';
        },

        buildMessagesTextForSummary: function(messages) {
            var lines = [];
            (messages || []).forEach(function(message) {
                var role = message && message.role ? message.role : 'message';
                var text = this.getMessageTextForContextSummary(message).replace(/\s+/g, ' ').trim();
                if (!text) {
                    return;
                }
                if (text.length > 4000) {
                    text = text.substring(0, 4000) + '... [truncated]';
                }
                lines.push(role.toUpperCase() + ': ' + text);
            }, this);
            return lines.join('\n\n');
        },

        generateActiveContextSummary: function(messages, maxWords) {
            var provider = this.conversationProvider || (this.getProvider ? this.getProvider() : '');
            var model = this.getSubagentModel();
            var prompt = 'Compact the older part of this WordPress assistant conversation for future context. ' +
                'Preserve user goals, decisions, changed files, important tool findings, unresolved work, and constraints. ' +
                'Do not add a title. Keep it under ' + maxWords + ' words.\n\nConversation:\n' +
                this.buildMessagesTextForSummary(messages);

            if (provider === 'anthropic') {
                return this.callAnthropicForSummary(model, prompt);
            }
            if (provider === 'openai') {
                return this.callOpenAIForSummary(model, prompt);
            }
            return this.callLocalForSummary(model, prompt);
        },

        createCompactionSummaryMessage: function(summary, omittedCount) {
            var content = 'Prior conversation summary for context only. This is not a new user request.\n\n' +
                summary +
                '\n\n[' + omittedCount + ' older messages compacted from the request payload.]';
            return this.createStoredMessage
                ? this.createStoredMessage('user', content)
                : {
                    role: 'user',
                    content: content
                };
        },

        buildCompactedProviderMessages: function(summary, omittedCount, recentMessages) {
            recentMessages = Array.isArray(recentMessages) ? recentMessages.slice() : [];
            var summaryMessage = this.createCompactionSummaryMessage(summary, omittedCount);
            var firstRecent = recentMessages[0];

            if (firstRecent && firstRecent.role === 'user' && !this.messageStartsWithToolResult(firstRecent)) {
                var merged = $.extend({}, firstRecent);
                if (typeof firstRecent.content === 'string') {
                    merged.content = summaryMessage.content + '\n\nRecent conversation continues:\n' + firstRecent.content;
                } else if (Array.isArray(firstRecent.content)) {
                    merged.content = [{ type: 'text', text: summaryMessage.content + '\n\nRecent conversation continues below.' }]
                        .concat(firstRecent.content);
                } else {
                    merged.content = summaryMessage.content;
                }
                recentMessages[0] = merged;
                return recentMessages;
            }

            return [summaryMessage].concat(recentMessages);
        },

        showContextCompactionStatus: function(state, options) {
            options = options || {};
            if (!this.showToolProgress || !this.setToolCardState) {
                return;
            }

            var toolId = options.id || 'context-compaction';
            this.showToolProgress('compact_context', 0, toolId);
            if (state === 'executing') {
                this.setToolCardState(toolId, 'executing');
            } else if (state === 'completed') {
                this.setToolCardState(toolId, 'completed', { message: options.message || 'Compacted' });
            } else if (state === 'error') {
                this.setToolCardState(toolId, 'error', { message: options.message || 'Compaction skipped' });
            }
        },

        maybeCompactProviderMessages: async function(provider, messages) {
            messages = Array.isArray(messages) ? messages : [];
            if (!this.shouldCompactProviderMessages(messages)) {
                return {
                    messages: messages,
                    compacted: false
                };
            }

            var config = this.getContextCompactionConfig();
            var cutIndex = this.findContextCompactionCutIndex(messages, config.recentMessages);
            if (cutIndex <= 0) {
                return {
                    messages: messages,
                    compacted: false
                };
            }

            var olderMessages = messages.slice(0, cutIndex);
            var recentMessages = messages.slice(cutIndex);
            var cacheKey = this.getContextCompactionCacheKey(olderMessages);
            var summary = this.contextCompactionCache && this.contextCompactionCache.key === cacheKey
                ? this.contextCompactionCache.summary
                : '';

            try {
                if (!summary) {
                    this.showContextCompactionStatus('executing');
                    summary = await this.generateActiveContextSummary(olderMessages, config.maxSummaryWords);
                    this.contextCompactionCache = {
                        key: cacheKey,
                        summary: summary
                    };
                    this.showContextCompactionStatus('completed', { message: 'Compacted context' });
                }

                return {
                    messages: this.buildCompactedProviderMessages(summary, olderMessages.length, recentMessages),
                    compacted: true,
                    omitted: olderMessages.length
                };
            } catch (error) {
                console.warn('[AI Assistant] Context compaction failed:', error);
                this.showContextCompactionStatus('error', { message: 'Compaction failed' });
                return {
                    messages: messages,
                    compacted: false,
                    error: error
                };
            }
        },

        prepareProviderRequestMessages: async function(provider, messages) {
            return this.maybeCompactProviderMessages(provider, messages || this.messages || []);
        }
    });
})(jQuery);
