(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        // Draft management
        saveDraft: function() {
            var content = $('#ai-assistant-input').val();
            try {
                if (content) {
                    localStorage.setItem(this.draftStorageKey, content);
                } else {
                    localStorage.removeItem(this.draftStorageKey);
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not save draft:', e);
            }
        },

        restoreDraft: function() {
            try {
                var draft = localStorage.getItem(this.draftStorageKey);
                if (draft) {
                    $('#ai-assistant-input').val(draft);
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not restore draft:', e);
            }
        },

        clearDraft: function() {
            try {
                localStorage.removeItem(this.draftStorageKey);
            } catch (e) {
                console.warn('[AI Assistant] Could not clear draft:', e);
            }
        },

        saveYoloMode: function() {
            try {
                localStorage.setItem(this.yoloStorageKey, this.yoloMode ? '1' : '0');
            } catch (e) {
                console.warn('[AI Assistant] Could not save YOLO mode:', e);
            }
        },

        restoreYoloMode: function() {
            try {
                var stored = localStorage.getItem(this.yoloStorageKey);
                this.yoloMode = stored === '1';
                $('#ai-assistant-yolo').prop('checked', this.yoloMode);
            } catch (e) {
                console.warn('[AI Assistant] Could not restore YOLO mode:', e);
            }
        },

        loadDraftHistory: function() {
            try {
                var stored = localStorage.getItem(this.draftHistoryKey);
                if (stored) {
                    this.draftHistory = JSON.parse(stored);
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not load draft history:', e);
                this.draftHistory = [];
            }

            if (!Array.isArray(this.draftHistory)) {
                this.draftHistory = [];
            }

            this.draftHistory = this.draftHistory.filter(function(message) {
                return typeof message === 'string' && message.trim() !== '';
            }).slice(0, this.draftHistoryMax);

            this.saveDraftHistory();
        },

        saveDraftHistory: function() {
            try {
                localStorage.setItem(this.draftHistoryKey, JSON.stringify(this.draftHistory));
            } catch (e) {
                console.warn('[AI Assistant] Could not save draft history:', e);
            }
        },

        addToDraftHistory: function(message) {
            if (!message || message.trim() === '') return;

            if (this.draftHistory.length > 0 && this.draftHistory[0] === message) {
                return;
            }

            this.draftHistory.unshift(message);

            if (this.draftHistory.length > this.draftHistoryMax) {
                this.draftHistory = this.draftHistory.slice(0, this.draftHistoryMax);
            }

            this.saveDraftHistory();
        },

        navigateDraftHistory: function(direction) {
            if (this.draftHistory.length === 0) return;

            var $input = $('#ai-assistant-input');
            if (this.draftHistoryIndex === -1) {
                this.draftHistoryDraft = $input.val();
            }

            var newIndex = this.draftHistoryIndex + direction;

            if (newIndex < -1) newIndex = -1;
            if (newIndex >= this.draftHistory.length) newIndex = this.draftHistory.length - 1;

            if (newIndex === this.draftHistoryIndex) return;

            this.draftHistoryIndex = newIndex;

            if (newIndex === -1) {
                $input.val(this.draftHistoryDraft || '');
            } else {
                $input.val(this.draftHistory[newIndex]);
            }
            $input.trigger('input');

            var input = $input[0];
            input.selectionStart = input.selectionEnd = input.value.length;
        },

        // New chat
        newChat: function() {
            var self = this;

            if (this.isFullPage) {
                this.startNewChat();
                return;
            }

            if (this.messages.length > 0 && !this.pendingNewChat) {
                this.pendingNewChat = true;
                // Save current messages HTML for undo
                this.pendingChatOriginalHtml = $('#ai-assistant-messages').html();
                // Clear and show new welcome with current model
                $('#ai-assistant-messages').empty();
                this.loadWelcomeMessage();
                $('#ai-token-count').hide();
                $('#ai-assistant-new-chat').text('Undo').attr('id', 'ai-assistant-undo-new-chat');

                $('#ai-assistant-input').focus();
                return;
            }

            this.startNewChat();
        },

        startNewChat: function() {
            this.messages = [];
            this.pendingActions = [];
            if (this.showToolApprovalModal) {
                this.showToolApprovalModal();
            }
            this.conversationId = 0;
            this.conversationTitle = '';
            this.conversationProvider = this.getProvider();
            this.conversationModel = this.getModel();
            this.titleGenerationInProgress = false;
            this.titleGenerationAttempted = false;
            this.titleGenerationToken++;
            this.pendingNewChat = false;
            this.pendingAttachments = [];
            if (this.renderPendingAttachments) {
                this.renderPendingAttachments();
            }
            this.updateSendButton();
            this.updateTokenCount();
            this.clearToolCards();
            $('#ai-assistant-messages').empty();
            $('#ai-token-count').show();
            $('#ai-assistant-pending-actions').empty().hide();
            $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');
            this.updateSidebarSelection();
            this.loadWelcomeMessage();
            this.updateSummarizeButton();
            this.updateExportButton();
            $('#ai-assistant-input').focus();
        },

        undoNewChat: function() {
            this.pendingNewChat = false;
            // Restore original messages
            if (this.pendingChatOriginalHtml) {
                $('#ai-assistant-messages').html(this.pendingChatOriginalHtml);
                this.pendingChatOriginalHtml = null;
            }
            $('#ai-token-count').show();
            $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');

            this.scrollToBottom();
            $('#ai-assistant-input').focus();
        },

        // Conversation persistence
        saveConversation: function(silent, callback) {
            var self = this;

            if (typeof silent === 'function') {
                callback = silent;
                silent = true;
            }

            function finishCallback(success, response) {
                if (typeof callback === 'function') {
                    callback(success, response);
                }
            }

            if (this.messages.length === 0) {
                if (!silent) {
                    this.addMessage('system', 'No messages to save.');
                }
                finishCallback(false, null);
                return;
            }

            // Prevent concurrent saves - queue if one is in progress
            if (this.saveInProgress) {
                this.savePending = true;
                this.savePendingSilent = this.savePendingSilent && silent;
                if (typeof callback === 'function') {
                    this.savePendingCallbacks.push(callback);
                }
                return;
            }

            this.saveInProgress = true;
            var saveSucceeded = false;
            var saveResponse = null;
            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_save_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId,
                    messages: btoa(unescape(encodeURIComponent(JSON.stringify(this.messages)))),
                    title: this.conversationTitle,
                    provider: this.conversationProvider || this.getProvider(),
                    model: this.conversationModel || this.getModel()
                },
                success: function(response) {
                    saveResponse = response;
                    if (response.success) {
                        saveSucceeded = true;
                        self.conversationId = response.data.conversation_id;
                        if (!self.conversationTitle) {
                            self.conversationTitle = response.data.title;
                        }
                        self.updateSidebarSelection();
                        if (!silent) {
                            self.addMessage('system', 'Conversation saved.');
                        }

                        if (self.isFullPage) {
                            self.loadSidebarConversations();
                        }

                        self.updateSummarizeButton();
                        self.updateExportButton();
                    } else {
                        console.error('[AI Assistant] Save failed:', response.data);
                        if (!silent) {
                            self.addMessage('error', 'Failed to save: ' + (response.data.message || 'Unknown error'));
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[AI Assistant] Save error:', error);
                    if (!silent) {
                        self.addMessage('error', 'Failed to save conversation.');
                    }
                },
                complete: function() {
                    self.saveInProgress = false;
                    finishCallback(saveSucceeded, saveResponse);
                    // Process queued save if any
                    if (self.savePending) {
                        var pendingSilent = self.savePendingSilent;
                        var pendingCallbacks = self.savePendingCallbacks.slice();
                        self.savePendingCallbacks = [];
                        self.savePending = false;
                        self.savePendingSilent = true;
                        self.saveConversation(pendingSilent, function(success, response) {
                            pendingCallbacks.forEach(function(pendingCallback) {
                                pendingCallback(success, response);
                            });
                        });
                    }
                }
            });
        },

        autoSaveConversation: function() {
            if (this.autoSave && this.messages.length > 0) {
                if (!this.conversationTitle && this.messages.length >= 2 && !this.titleGenerationAttempted && !this.titleGenerationInProgress) {
                    this.titleGenerationAttempted = true;
                    this.saveConversation(true);
                    this.generateConversationTitle();
                    return;
                }
                this.saveConversation(true);
            }
        },

        saveConversationThenNavigate: function(targetUrl) {
            var self = this;

            // Add hash to reopen panel after navigation
            var urlWithHash = targetUrl + (targetUrl.indexOf('#') === -1 ? '#' : '&') + 'ai-open';

            if (this.messages.length === 0) {
                window.location.href = urlWithHash;
                return;
            }

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_save_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId,
                    messages: btoa(unescape(encodeURIComponent(JSON.stringify(this.messages)))),
                    title: this.conversationTitle,
                    provider: this.conversationProvider,
                    model: this.conversationModel
                },
                success: function() {
                    window.location.href = urlWithHash;
                },
                error: function() {
                    console.error('[AI Assistant] Failed to save conversation before navigation');
                    window.location.href = urlWithHash;
                }
            });
        },

        loadConversation: function(conversationId) {
            var self = this;

            // Hide immediately to avoid showing stale content during load
            $('#ai-assistant-messages').css('visibility', 'hidden');

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_load_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: conversationId
                },
                success: function(response) {
                    if (response.success) {
                        self.titleGenerationInProgress = false;
                        self.titleGenerationAttempted = !!response.data.title;
                        self.titleGenerationToken++;
                        self.conversationId = response.data.conversation_id;
                        self.conversationTitle = response.data.title;
                        self.pendingActions = [];
                        self.pendingToolResults = [];
                        self.executingToolCount = 0;
                        if (self.showToolApprovalModal) {
                            self.showToolApprovalModal();
                        }
                        try {
                            var base64 = response.data.messages_base64 || '';
                            if (base64) {
                                var json = decodeURIComponent(escape(atob(base64)));
                                self.messages = JSON.parse(json);
                            } else {
                                self.messages = [];
                            }
                        } catch (e) {
                            console.error('[AI Assistant] Failed to decode messages:', e);
                            self.messages = [];
                        }

                        var $messages = $('#ai-assistant-messages');
                        $messages.css('visibility', 'hidden').empty();

                        // Use saved provider/model, fall back to current only for API calls
                        self.conversationProvider = response.data.provider || self.getProvider();
                        self.conversationModel = response.data.model || self.getModel();
                        self.updateSendButton();
                        self.updateTokenCount();

                        // Display only what was actually saved with the conversation
                        self.loadConversationWelcome(response.data.provider, response.data.model);
                        if (response.data.summary) {
                            self.showConversationSummary(response.data.summary);
                        }
                        self.rebuildMessagesUI();
                        self.updateSidebarSelection();
                        $('#ai-assistant-input').focus();
                        self.updateSummarizeButton();
                        self.updateExportButton();

                    } else {
                        self.addMessage('error', 'Failed to load: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[AI Assistant] Load error:', error);
                    self.addMessage('error', 'Failed to load conversation.');
                }
            });
        },

        loadMostRecentConversation: function() {
            var self = this;

            // Hide immediately to avoid showing stale content during load
            $('#ai-assistant-messages').css('visibility', 'hidden');

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_list_conversations',
                    _wpnonce: aiAssistantConfig.nonce
                },
                success: function(response) {
                    if (response.success && response.data.conversations && response.data.conversations.length > 0) {
                        var mostRecent = response.data.conversations[0];
                        self.loadConversation(mostRecent.id);
                    } else {
                        // No conversations - show fresh welcome
                        var $messages = $('#ai-assistant-messages');
                        $messages.empty();
                        self.loadWelcomeMessage();
                        $messages.css('visibility', 'visible');
                        self.updateExportButton();
                    }
                },
                error: function() {
                    // On error, show fresh welcome
                    var $messages = $('#ai-assistant-messages');
                    $messages.empty();
                    self.loadWelcomeMessage();
                    $messages.css('visibility', 'visible');
                    self.updateExportButton();
                }
            });
        },

        getConversationExportFormats: function() {
            var formats = (typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.conversationExportFormats) || [];
            return Array.isArray(formats) ? formats.filter(function(format) {
                return format && format.format && format.label;
            }) : [];
        },

        renderConversationExportMenu: function() {
            var formats = this.getConversationExportFormats();
            var $menu = $('#ai-assistant-export-menu');
            var self = this;

            if ($menu.length === 0) {
                return;
            }

            if (formats.length === 0) {
                $menu.empty();
                this.updateExportButton();
                return;
            }

            var html = '<label class="ai-export-option">' +
                '<input type="checkbox" id="ai-export-include-tool-calls" value="1">' +
                '<span>Include tool calls</span>' +
                '</label>';
            formats.forEach(function(format) {
                html += '<button type="button" class="ai-export-format" role="menuitem" data-format="' + self.escapeHtml(format.format) + '" title="' + self.escapeHtml(format.description || '') + '">';
                html += '<span class="ai-export-format-label">' + self.escapeHtml(format.label) + '</span>';
                html += '<span class="ai-export-format-extension">.' + self.escapeHtml(format.extension || format.format) + '</span>';
                html += '</button>';
            });

            $menu.html(html);
            this.updateExportButton();
        },

        toggleConversationExportMenu: function() {
            var $button = $('#ai-assistant-export');
            var $menu = $('#ai-assistant-export-menu');

            if ($button.prop('disabled') || $menu.length === 0) {
                return;
            }

            var isOpen = !$menu.prop('hidden');
            $menu.prop('hidden', isOpen);
            $button.attr('aria-expanded', isOpen ? 'false' : 'true');
        },

        hideConversationExportMenu: function() {
            $('#ai-assistant-export-menu').prop('hidden', true);
            $('#ai-assistant-export').attr('aria-expanded', 'false');
        },

        updateExportButton: function() {
            var formats = this.getConversationExportFormats();
            var hasFormats = formats.length > 0;
            var hasMessages = this.messages && this.messages.length > 0;

            $('.ai-export-menu-wrap').toggle(hasFormats);
            $('.ai-export-header-sep').toggle(hasFormats);
            $('#ai-assistant-export').prop('disabled', !hasMessages || !hasFormats);

            if (!hasMessages || !hasFormats) {
                this.hideConversationExportMenu();
            }
        },

        exportConversation: function(format) {
            var self = this;

            this.hideConversationExportMenu();

            if (!format) {
                return;
            }

            if (!this.messages || this.messages.length === 0) {
                this.addMessage('system', 'No messages to export.');
                this.updateExportButton();
                return;
            }

            var includeToolCalls = $('#ai-export-include-tool-calls').is(':checked');

            if (this.conversationId) {
                this.startConversationExportDownload(this.buildConversationExportUrl(format, includeToolCalls));
                return;
            }

            this.saveConversation(true, function(success) {
                if (!success || !self.conversationId) {
                    self.addMessage('error', 'Failed to save conversation before export.');
                    return;
                }

                self.startConversationExportDownload(self.buildConversationExportUrl(format, includeToolCalls));
            });
        },

        startConversationExportDownload: function(url) {
            var iframeId = 'ai-assistant-export-frame';
            var $iframe = $('#' + iframeId);

            if ($iframe.length === 0) {
                $iframe = $('<iframe>', {
                    id: iframeId,
                    name: iframeId,
                    title: 'Conversation export download'
                }).css('display', 'none').appendTo('body');
            }

            $iframe.attr('src', url);
        },

        buildConversationExportUrl: function(format, includeToolCalls) {
            var baseUrl = (typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.conversationExportUrl) || '';
            var separator = baseUrl.indexOf('?') === -1 ? '?' : '&';
            var url = baseUrl + separator +
                'conversation_id=' + encodeURIComponent(this.conversationId) +
                '&format=' + encodeURIComponent(format);
            if (includeToolCalls) {
                url += '&include_tool_calls=1';
            }
            return url + '&_wpnonce=' + encodeURIComponent(aiAssistantConfig.nonce);
        },
        // Sidebar management
        loadSidebarConversations: function() {
            var self = this;
            var $container = $('#ai-sidebar-conversations');

            if ($container.length === 0) return;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_list_conversations',
                    _wpnonce: aiAssistantConfig.nonce
                },
                success: function(response) {
                    if (response.success && response.data.conversations) {
                        self.renderSidebarConversations(response.data.conversations);
                    } else {
                        $container.html('<div class="ai-sidebar-empty">No conversations yet</div>');
                    }
                },
                error: function() {
                    $container.html('<div class="ai-sidebar-empty">Failed to load</div>');
                }
            });
        },

        renderSidebarConversations: function(conversations) {
            var self = this;
            var $container = $('#ai-sidebar-conversations');

            if (conversations.length === 0) {
                $container.html('<div class="ai-sidebar-empty">No conversations yet</div>');
                return;
            }

            var today = new Date().toDateString();
            var yesterday = new Date(Date.now() - 86400000).toDateString();
            var groups = { today: [], yesterday: [], older: [] };

            conversations.forEach(function(conv) {
                var convDate = new Date(conv.date).toDateString();
                if (convDate === today) {
                    groups.today.push(conv);
                } else if (convDate === yesterday) {
                    groups.yesterday.push(conv);
                } else {
                    groups.older.push(conv);
                }
            });

            var html = '';

            if (groups.today.length > 0) {
                html += '<div class="ai-conv-date-group">Today</div>';
                html += self.renderConversationGroup(groups.today);
            }
            if (groups.yesterday.length > 0) {
                html += '<div class="ai-conv-date-group">Yesterday</div>';
                html += self.renderConversationGroup(groups.yesterday);
            }
            if (groups.older.length > 0) {
                html += '<div class="ai-conv-date-group">Previous</div>';
                html += self.renderConversationGroup(groups.older);
            }

            $container.html(html);
            this.updateSidebarSelection();
        },

        renderConversationGroup: function(conversations) {
            var self = this;
            var html = '';
            conversations.forEach(function(conv) {
                var activeClass = conv.id === self.conversationId ? ' active' : '';
                html += '<div class="ai-conv-item' + activeClass + '" data-id="' + conv.id + '">';
                html += '<div class="ai-conv-item-title">' + self.escapeHtml(conv.title) + '</div>';
                html += '<button type="button" class="ai-conv-item-delete" data-id="' + conv.id + '" title="Delete">&times;</button>';
                html += '</div>';
            });
            return html;
        },

        updateSidebarSelection: function() {
            $('.ai-conv-item').removeClass('active');
            if (this.conversationId > 0) {
                $('.ai-conv-item[data-id="' + this.conversationId + '"]').addClass('active');
            }
        },

        showConversationList: function() {
            var self = this;
            var $modal = $('#ai-conversation-modal');
            var $list = $('#ai-conversation-list');

            $list.html('<p>Loading...</p>');
            $modal.show();

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_list_conversations',
                    _wpnonce: aiAssistantConfig.nonce
                },
                success: function(response) {
                    if (response.success && response.data.conversations) {
                        var conversations = response.data.conversations;
                        if (conversations.length === 0) {
                            $list.html('<p>No saved conversations.</p>');
                            return;
                        }

                        var html = '';
                        conversations.forEach(function(conv) {
                            html += '<div class="ai-conversation-item">';
                            html += '<div class="ai-conversation-item-title">' + self.escapeHtml(conv.title) + '</div>';
                            html += '<div class="ai-conversation-item-meta">' + conv.message_count + ' messages &bull; ' + conv.date + '</div>';
                            html += '<div class="ai-conversation-item-actions">';
                            html += '<button class="button button-primary button-small ai-conversation-load" data-id="' + conv.id + '">Load</button>';
                            html += '<button class="button button-small ai-conversation-delete" data-id="' + conv.id + '">Delete</button>';
                            html += '</div>';
                            html += '</div>';
                        });
                        $list.html(html);
                    } else {
                        $list.html('<p>Failed to load conversations.</p>');
                    }
                },
                error: function() {
                    $list.html('<p>Error loading conversations.</p>');
                }
            });
        },

        deleteConversation: function(conversationId) {
            var self = this;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_delete_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: conversationId
                },
                success: function(response) {
                    if (response.success) {
                        $('.ai-conv-item[data-id="' + conversationId + '"]').remove();
                        $('.ai-conversation-item [data-id="' + conversationId + '"]').closest('.ai-conversation-item').remove();

                        if (self.conversationId === conversationId) {
                            self.newChat();
                        }
                    }
                }
            });
        },

        renameConversation: function(conversationId, newTitle) {
            var self = this;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_rename_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: conversationId,
                    title: newTitle
                },
                success: function(response) {
                    if (response.success) {
                        if (self.conversationId === conversationId) {
                            self.conversationTitle = newTitle;
                        }
                    } else {
                        console.error('[AI Assistant] Rename failed:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[AI Assistant] Rename error:', error);
                }
            });
        },

        // Title generation
        generateConversationTitle: function() {
            var self = this;
            var provider = this.conversationProvider || this.getProvider();
            var apiKey = this.getApiKey(provider);
            var providerConfig = this.isConnectorsMode() && typeof aiAssistantProviders !== 'undefined'
                ? aiAssistantProviders.available[provider]
                : null;

            var firstUserMsg = this.messages.find(function(m) { return m.role === 'user'; });
            if (!firstUserMsg) return;

            var userContent = typeof firstUserMsg.content === 'string'
                ? firstUserMsg.content
                : (firstUserMsg.content[0]?.text || '');

            if (this.extractFileContextForDisplay) {
                var fileContext = this.extractFileContextForDisplay(userContent);
                if (fileContext) {
                    userContent = fileContext.visibleText || 'Attached files';
                }
            }

            if (!userContent) return;

            this.titleGenerationInProgress = true;
            this.titleGenerationToken++;
            var titleGenerationToken = this.titleGenerationToken;

            var titlePrompt = 'Generate a very short title (3-6 words max) for a conversation that starts with this message. Return ONLY the title, nothing else. Do not explain or reason - just output the title directly.\n\n' + userContent.substring(0, 500);

            function fallbackTitle() {
                var words = userContent.split(/\s+/).slice(0, 6).join(' ');
                return words.length > 50 ? words.substring(0, 50) + '...' : words;
            }

            function finishWithTitle(title) {
                if (self.titleGenerationToken !== titleGenerationToken) {
                    return;
                }
                title = (title || '').trim().replace(/^["']|["']$/g, '');
                if (!title) {
                    title = fallbackTitle();
                }
                self.conversationTitle = title;
                self.titleGenerationInProgress = false;
                self.saveConversation(true);
            }

            function finishWithFallback(err) {
                if (err) {
                    console.error('[AI Assistant] Title generation failed:', err);
                }
                finishWithTitle(fallbackTitle());
            }

            if (provider === 'anthropic' && apiKey) {
                fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'x-api-key': apiKey,
                        'anthropic-version': '2023-06-01',
                        'anthropic-dangerous-direct-browser-access': 'true'
                    },
                    body: JSON.stringify({
                        model: 'claude-3-5-haiku-20241022',
                        max_tokens: 30,
                        messages: [{ role: 'user', content: titlePrompt }]
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.content && data.content[0] && data.content[0].text) {
                        finishWithTitle(data.content[0].text);
                    } else {
                        finishWithFallback();
                    }
                })
                .catch(function(err) {
                    finishWithFallback(err);
                });
            } else if ((provider === 'openai' || (providerConfig && providerConfig.type === 'cloud')) && apiKey) {
                var openAIEndpoint = this.getProviderEndpoint(provider) || 'https://api.openai.com/v1/chat/completions';

                fetch(openAIEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + apiKey
                    },
                    body: JSON.stringify({
                        model: 'gpt-4o-mini',
                        max_tokens: 30,
                        messages: [{ role: 'user', content: titlePrompt }]
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        finishWithTitle(data.choices[0].message.content);
                    } else {
                        finishWithFallback();
                    }
                })
                .catch(function(err) {
                    finishWithFallback(err);
                });
            } else {
                var endpoint = (self.getProviderEndpoint(provider) || self.getLocalEndpoint()).replace(/\/$/, '');
                var model = self.conversationModel || self.getModel();

                fetch(endpoint + '/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        model: model,
                        max_tokens: 150,
                        messages: [{ role: 'user', content: titlePrompt }]
                    })
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.choices && data.choices[0] && data.choices[0].message) {
                        var title = self.stripReasoningTokens(data.choices[0].message.content);
                        finishWithTitle(title);
                    } else {
                        finishWithFallback();
                    }
                })
                .catch(function(err) {
                    finishWithFallback(err);
                });
            }
        },

        // Summarization
        manualSummarizeConversation: function() {
            var self = this;

            if (!this.conversationId || this.conversationId <= 0) {
                this.addMessage('system', 'Please save the conversation first before generating a summary.');
                return;
            }

            if (this.isLoading) {
                return;
            }

            var $btn = $('#ai-assistant-summarize');
            $btn.prop('disabled', true).addClass('loading');
            this.addMessage('system', 'Generating conversation summary...');
            var $generatingMsg = $('#ai-assistant-messages .ai-message-system').last();

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_get_conversation_for_summary',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: this.conversationId
                },
                success: function(response) {
                    if (!response.success) {
                        self.addMessage('error', 'Failed to load conversation: ' + (response.data?.message || 'Unknown error'));
                        $btn.prop('disabled', false).removeClass('loading');
                        return;
                    }

                    var convData = response.data;

                    if (convData.existing_summary) {
                        $generatingMsg.remove();
                        var $existing = $('#ai-assistant-messages .ai-conversation-summary');
                        if ($existing.length) {
                            $existing.removeClass('collapsed');
                            $existing[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                        } else {
                            self.showConversationSummary(convData.existing_summary);
                            self.scrollToBottom();
                        }
                        $btn.prop('disabled', false).removeClass('loading');
                        return;
                    }

                    self.generateConversationSummary(convData).then(function(summary) {
                        $generatingMsg.remove();
                        $.ajax({
                            url: aiAssistantConfig.ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'ai_assistant_save_summary',
                                _wpnonce: aiAssistantConfig.nonce,
                                conversation_id: self.conversationId,
                                summary: summary
                            },
                            success: function() {
                                self.addMessage('system', 'Summary generated and saved to post excerpt:\n\n' + summary);
                            },
                            error: function() {
                                self.addMessage('system', 'Summary generated (but failed to save):\n\n' + summary);
                            },
                            complete: function() {
                                $btn.prop('disabled', false).removeClass('loading');
                            }
                        });
                    }).catch(function(error) {
                        $generatingMsg.remove();
                        self.addMessage('error', 'Failed to generate summary: ' + error.message);
                        $btn.prop('disabled', false).removeClass('loading');
                    });
                },
                error: function() {
                    $generatingMsg.remove();
                    self.addMessage('error', 'Failed to load conversation data');
                    $btn.prop('disabled', false).removeClass('loading');
                }
            });
        },

        showConversationSummary: function(summary) {
            var $messages = $('#ai-assistant-messages');
            var html = '<div class="ai-conversation-summary">' +
                '<div class="ai-summary-header">' +
                '<span class="ai-summary-icon">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>' +
                '</span>' +
                '<span class="ai-summary-title">Conversation Summary</span>' +
                '<span class="ai-summary-toggle">&#9660;</span>' +
                '</div>' +
                '<div class="ai-summary-content">' + this.formatContent(summary) + '</div>' +
                '</div>';
            $messages.append(html);
        },

        updateSummarizeButton: function() {
            this.updateSummarizeVisibility();
        }
    });

})(jQuery);
