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

        setAutoApproveMode: function(enabled) {
            this.autoApproveMode = enabled === true;
            this.yoloMode = this.autoApproveMode;
            $('#ai-assistant-auto-approve, #ai-assistant-yolo').prop('checked', this.autoApproveMode);
        },

        isMyWordPressRuntime: function() {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            var urls = [
                config.homeUrl || '',
                config.adminUrl || '',
                config.restApiUrl || ''
            ];

            if (typeof window !== 'undefined' && window.location && window.location.href) {
                urls.push(window.location.href);
            }

            return urls.some(function(url) {
                if (!url) {
                    return false;
                }

                try {
                    return new URL(url, window.location && window.location.href ? window.location.href : undefined).hostname === 'my.wordpress.net';
                } catch (e) {
                    return false;
                }
            });
        },

        getDefaultAutoApproveMode: function() {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};

            if (config.defaultAutoApproveMode === true) {
                return true;
            }

            if (this.isMyWordPressSite) {
                return this.isMyWordPressSite();
            }

            if (this.isMyWordPressRuntime()) {
                return true;
            }

            return false;
        },

        saveAutoApproveMode: function() {
            var storageKey = this.autoApproveStorageKey || 'aiAssistant_autoApproveMode';
            var legacyStorageKey = this.yoloStorageKey || 'aiAssistant_yoloMode';

            try {
                localStorage.setItem(storageKey, this.autoApproveMode ? '1' : '0');
                localStorage.removeItem(legacyStorageKey);
            } catch (e) {
                console.warn('[AI Assistant] Could not save auto-approve mode:', e);
            }
        },

        saveYoloMode: function() {
            this.saveAutoApproveMode();
        },

        restoreAutoApproveMode: function() {
            var storageKey = this.autoApproveStorageKey || 'aiAssistant_autoApproveMode';
            var legacyStorageKey = this.yoloStorageKey || 'aiAssistant_yoloMode';
            var stored = null;

            try {
                stored = localStorage.getItem(storageKey);

                if (stored === null) {
                    stored = localStorage.getItem(legacyStorageKey);
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not restore auto-approve mode:', e);
            }

            this.setAutoApproveMode(stored === null ? this.getDefaultAutoApproveMode() : stored === '1');
        },

        restoreYoloMode: function() {
            this.restoreAutoApproveMode();
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

        // Route area tracking uses the same server-side URL component as welcome tips.
        getCurrentUrlComponent: function() {
            return typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.urlComponent
                ? String(aiAssistantConfig.urlComponent)
                : '';
        },

        getNewChatSuggestionTimestamp: function() {
            return Date.now ? Date.now() : new Date().getTime();
        },

        getStoredMessageTimestamp: function(message) {
            var timestamp = message && typeof message === 'object'
                ? parseInt(message._ts, 10)
                : 0;

            return Number.isFinite(timestamp) && timestamp > 0 ? timestamp : 0;
        },

        getLatestStoredMessageTimestamp: function() {
            var latest = 0;

            (this.messages || []).forEach(function(message) {
                latest = Math.max(latest, this.getStoredMessageTimestamp(message));
            }, this);

            return latest;
        },

        restoreUrlComponentContext: function() {
            var current = this.getCurrentUrlComponent();
            var key = this.urlComponentStorageKey || 'aiAssistant_lastUrlComponent';
            var timeKey = this.urlContextTimestampStorageKey || 'aiAssistant_lastUrlContextAt';

            this.previousUrlComponent = '';
            this.previousUrlContextAt = 0;
            this.conversationInteracted = false;

            try {
                this.previousUrlComponent = localStorage.getItem(key) || '';
                this.previousUrlContextAt = parseInt(localStorage.getItem(timeKey), 10) || 0;
                if (!this.previousUrlComponent && current) {
                    this.previousUrlComponent = current;
                    localStorage.setItem(key, current);
                }
                if (!this.previousUrlContextAt) {
                    this.previousUrlContextAt = this.getNewChatSuggestionTimestamp();
                    localStorage.setItem(timeKey, String(this.previousUrlContextAt));
                }
            } catch (e) {
                console.warn('[AI Assistant] Could not restore URL component context:', e);
            }
        },

        markConversationInteracted: function() {
            this.conversationInteracted = true;
            this.storeCurrentUrlComponent();
            this.hideAreaChangeSuggestion();
        },

        storeCurrentUrlComponent: function() {
            var current = this.getCurrentUrlComponent();

            try {
                if (current) {
                    localStorage.setItem(this.urlComponentStorageKey || 'aiAssistant_lastUrlComponent', current);
                    this.previousUrlComponent = current;
                }
                this.previousUrlContextAt = this.getNewChatSuggestionTimestamp();
                localStorage.setItem(this.urlContextTimestampStorageKey || 'aiAssistant_lastUrlContextAt', String(this.previousUrlContextAt));
            } catch (e) {
                console.warn('[AI Assistant] Could not store URL component context:', e);
            }
        },

        shouldSuggestNewChatForCurrentArea: function(current) {
            var origin = this.previousUrlComponent || '';
            var maxAge = parseInt(this.newChatSuggestionMaxAgeMs, 10) || 60 * 60 * 1000;
            var contextAt = this.getLatestStoredMessageTimestamp() || parseInt(this.previousUrlContextAt, 10) || 0;
            var isStale = contextAt > 0 && this.getNewChatSuggestionTimestamp() - contextAt > maxAge;
            current = current || this.getCurrentUrlComponent();

            return !!(
                this.messages &&
                this.messages.length > 0 &&
                !this.pendingNewChat &&
                !this.conversationInteracted &&
                (
                    (origin && current && origin !== current) ||
                    isStale
                )
            );
        },

        ensureAreaChangeSuggestion: function() {
            var $suggestion = $('#ai-assistant-area-suggestion');
            if ($suggestion.length) {
                return $suggestion;
            }

            $suggestion = $('<div id="ai-assistant-area-suggestion" class="ai-assistant-area-suggestion" role="status" aria-live="polite" hidden>' +
                'Click to <a href="#" id="ai-assistant-area-new-chat">start a new chat</a> or just continue the conversation.' +
            '</div>');

            $('#ai-assistant-messages').append($suggestion);
            return $suggestion;
        },

        hideAreaChangeSuggestion: function() {
            $('#ai-assistant-area-suggestion').prop('hidden', true);
            if (this.moveAiChangesSuggestionToEnd) {
                this.moveAiChangesSuggestionToEnd();
            }
        },

        updateAreaChangeSuggestion: function() {
            var current = this.getCurrentUrlComponent();

            if (!this.shouldSuggestNewChatForCurrentArea(current)) {
                this.hideAreaChangeSuggestion();
                return;
            }

            var $suggestion = this.ensureAreaChangeSuggestion();
            $suggestion.prop('hidden', false);
            if (this.moveAiChangesSuggestionToEnd) {
                this.moveAiChangesSuggestionToEnd();
            }
            this.scrollToBottom(true);
        },

        shouldUseConversationHistory: function() {
            return !!(
                this.isFullPage &&
                typeof window !== 'undefined' &&
                window.history &&
                typeof window.history.pushState === 'function' &&
                typeof window.history.replaceState === 'function' &&
                window.location
            );
        },

        getConversationRouteUrlConstructor: function() {
            if (typeof window !== 'undefined' && window.URL) {
                return window.URL;
            }

            if (typeof URL !== 'undefined') {
                return URL;
            }

            return null;
        },

        getConversationRouteUrl: function(conversationId) {
            if (typeof window === 'undefined' || !window.location) {
                return '';
            }

            var UrlConstructor = this.getConversationRouteUrlConstructor();
            if (!UrlConstructor) {
                return '';
            }

            var id = parseInt(conversationId, 10) || 0;
            var url;

            try {
                url = new UrlConstructor(window.location.href);
            } catch (e) {
                return '';
            }

            url.searchParams.delete('conversation');
            url.searchParams.delete('conversation_id');

            if (id > 0) {
                url.searchParams.set('conversation', String(id));
            }

            return url.pathname + url.search + url.hash;
        },

        getCurrentConversationRouteId: function() {
            if (typeof window === 'undefined' || !window.location) {
                return 0;
            }

            var UrlConstructor = this.getConversationRouteUrlConstructor();
            if (!UrlConstructor) {
                return 0;
            }

            try {
                var url = new UrlConstructor(window.location.href);
                var id = parseInt(url.searchParams.get('conversation') || url.searchParams.get('conversation_id'), 10);
                return id > 0 ? id : 0;
            } catch (e) {
                return 0;
            }
        },

        getCurrentConversationRouteUrl: function() {
            if (typeof window === 'undefined' || !window.location) {
                return '';
            }

            return window.location.pathname + window.location.search + window.location.hash;
        },

        updateConversationRoute: function(conversationId, options) {
            if (!this.shouldUseConversationHistory()) {
                return;
            }

            options = options || {};

            var id = parseInt(conversationId, 10) || 0;
            var url = this.getConversationRouteUrl(id);
            if (!url || url === this.getCurrentConversationRouteUrl()) {
                return;
            }

            var method = options.replace ? 'replaceState' : 'pushState';
            window.history[method]({
                aiAssistantConversationId: id
            }, '', url);
        },

        bindConversationHistoryEvents: function() {
            var self = this;

            if (this.conversationHistoryEventsBound || typeof window === 'undefined' || !window.addEventListener) {
                return;
            }

            this.conversationHistoryEventsBound = true;

            window.addEventListener('popstate', function(event) {
                if (!self.shouldUseConversationHistory()) {
                    return;
                }

                var conversationId = self.getCurrentConversationRouteId();
                var currentConversationId = parseInt(self.conversationId, 10) || 0;
                var stateConversationId = event && event.state
                    ? parseInt(event.state.aiAssistantConversationId, 10)
                    : null;

                if (conversationId > 0) {
                    if (conversationId !== currentConversationId) {
                        self.loadConversation(conversationId, { updateHistory: false });
                    }
                    return;
                }

                if (stateConversationId === 0) {
                    self.startNewChat({ updateHistory: false });
                    return;
                }

                if (currentConversationId > 0 || (self.messages && self.messages.length > 0)) {
                    self.loadMostRecentConversation({ updateHistory: false });
                }
            });
        },

        // New chat
        newChat: function() {
            var self = this;

            if (this.markConversationInteracted) {
                this.markConversationInteracted();
            }

            if (this.isFullPage) {
                this.startNewChat();
                return;
            }

            if (this.messages.length > 0 && !this.pendingNewChat) {
                this.pendingNewChat = true;
                this.pendingNewChatProvider = this.getProvider();
                this.pendingNewChatModel = this.getModel();
                // Save current messages HTML for undo
                this.pendingChatOriginalHtml = $('#ai-assistant-messages').html();
                // Clear and show new welcome with the model reserved for the next send.
                $('#ai-assistant-messages').empty();
                this.loadWelcomeMessage();
                if (this.showCurrentAiChangesSuggestion) {
                    this.showCurrentAiChangesSuggestion();
                }
                $('#ai-token-count').hide();
                $('#ai-assistant-new-chat').text('Undo').attr('id', 'ai-assistant-undo-new-chat');
                this.hideAreaChangeSuggestion();

                $('#ai-assistant-input').focus();
                return;
            }

            this.startNewChat();
        },

        startNewChat: function(options) {
            options = options || {};
            this.messages = [];
            this.pendingActions = [];
            this.pendingToolChecks = 0;
            if (this.clearQueuedMessages) {
                this.clearQueuedMessages();
            }
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
            this.conversationTitleIsPlaceholder = false;
            this.pendingNewChat = false;
            this.pendingNewChatProvider = '';
            this.pendingNewChatModel = '';
            this.conversationDirty = false;
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
            this.hideAreaChangeSuggestion();
            this.updateSidebarSelection();
            this.loadWelcomeMessage();
            if (this.showCurrentAiChangesSuggestion) {
                this.showCurrentAiChangesSuggestion();
            }
            this.updateSummarizeButton();
            this.updateExportButton();
            if (options.updateHistory !== false) {
                this.updateConversationRoute(0);
            }
            $('#ai-assistant-input').focus();
        },

        undoNewChat: function() {
            this.pendingNewChat = false;
            this.pendingNewChatProvider = '';
            this.pendingNewChatModel = '';
            // Restore original messages
            if (this.pendingChatOriginalHtml) {
                $('#ai-assistant-messages').html(this.pendingChatOriginalHtml);
                this.pendingChatOriginalHtml = null;
            }
            $('#ai-token-count').show();
            $('#ai-assistant-undo-new-chat').text('New Chat').attr('id', 'ai-assistant-new-chat');

            this.scrollToBottom();
            this.hideAreaChangeSuggestion();
            $('#ai-assistant-input').focus();
        },

        // Conversation persistence
        getConversationTitleForSave: function() {
            return this.conversationTitleIsPlaceholder ? '' : this.conversationTitle;
        },

        getMessagesForSave: function(options) {
            options = options || {};
            var messages = Array.isArray(this.messages) ? this.messages.slice() : [];
            var queuedMessages = options.includeQueued === false || !Array.isArray(this.queuedMessages)
                ? []
                : this.queuedMessages;

            queuedMessages.forEach(function(item) {
                if (item && item.content) {
                    messages.push({
                        role: 'user',
                        content: item.content,
                        _ts: item.queuedAt || this.getNewChatSuggestionTimestamp()
                    });
                }
            }, this);

            return messages;
        },

        getEncodedMessagesForSave: function(messages) {
            messages = messages || this.getMessagesForSave();
            return btoa(unescape(encodeURIComponent(JSON.stringify(messages))));
        },

        getConversationSaveData: function(overrides) {
            return $.extend({
                action: 'ai_assistant_save_conversation',
                _wpnonce: aiAssistantConfig.nonce,
                conversation_id: this.conversationId,
                messages: this.getEncodedMessagesForSave(),
                title: this.getConversationTitleForSave(),
                provider: this.conversationProvider || this.getProvider(),
                model: this.conversationModel || this.getModel(),
                system_prompt: this.systemPrompt || '',
                token_usage: this.getTokenUsageSummary ? JSON.stringify(this.getTokenUsageSummary()) : ''
            }, overrides || {});
        },

        createConversationSaveBody: function(data) {
            var body = new URLSearchParams();
            Object.keys(data).forEach(function(key) {
                body.append(key, data[key] == null ? '' : data[key]);
            });
            return body;
        },

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

            var messagesForSave = this.getMessagesForSave();

            if (messagesForSave.length === 0) {
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
            var titleForSave = this.getConversationTitleForSave();
            var saveData = this.getConversationSaveData({
                messages: this.getEncodedMessagesForSave(messagesForSave),
                title: titleForSave
            });
            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: saveData,
                success: function(response) {
                    saveResponse = response;
                    if (response.success) {
                        saveSucceeded = true;
                        self.conversationId = response.data.conversation_id;
                        var currentTitleForSave = self.getConversationTitleForSave();
                        var titleUnchangedSinceRequest = titleForSave === currentTitleForSave;
                        if (response.data.title_status === 'placeholder') {
                            if (!self.conversationTitle || self.conversationTitleIsPlaceholder) {
                                self.conversationTitle = response.data.title;
                                self.conversationTitleIsPlaceholder = true;
                            }
                        } else if (!self.conversationTitle || self.conversationTitleIsPlaceholder || titleUnchangedSinceRequest) {
                            self.conversationTitle = response.data.title;
                            self.conversationTitleIsPlaceholder = false;
                        }
                        self.updateSidebarSelection();
                        self.updateConversationRoute(self.conversationId);
                        if (!silent) {
                            self.addMessage('system', 'Conversation saved.');
                        }

                        if (self.isFullPage) {
                            self.loadSidebarConversations();
                        }

                        self.updateSummarizeButton();
                        self.updateExportButton();
                        self.updateAreaChangeSuggestion();
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
                    } else if (saveSucceeded) {
                        self.conversationDirty = false;
                    }
                }
            });
        },

        autoSaveConversation: function() {
            if (this.autoSave && this.getMessagesForSave().length > 0) {
                if (this.shouldGenerateConversationTitle && this.shouldGenerateConversationTitle()) {
                    this.titleGenerationAttempted = true;
                    this.saveConversation(true);
                    this.generateConversationTitle();
                    return;
                }
                this.saveConversation(true);
            }
        },

        persistConversationForPageExit: function() {
            var messages = this.getMessagesForSave();

            if (messages.length === 0) {
                return false;
            }

            if (typeof aiAssistantConfig === 'undefined' || !aiAssistantConfig.ajaxUrl) {
                return false;
            }

            var body;
            try {
                body = this.createConversationSaveBody(this.getConversationSaveData({
                    messages: this.getEncodedMessagesForSave(messages)
                }));
            } catch (e) {
                console.error('[AI Assistant] Failed to prepare page-exit save:', e);
                return false;
            }

            var bodyString = body.toString();
            var contentType = 'application/x-www-form-urlencoded; charset=UTF-8';

            if (navigator.sendBeacon && typeof Blob !== 'undefined') {
                var blob = new Blob([bodyString], { type: contentType });
                if (navigator.sendBeacon(aiAssistantConfig.ajaxUrl, blob)) {
                    return true;
                }
            }

            if (window.fetch) {
                try {
                    window.fetch(aiAssistantConfig.ajaxUrl, {
                        method: 'POST',
                        body: bodyString,
                        headers: {
                            'Content-Type': contentType
                        },
                        credentials: 'same-origin',
                        keepalive: true
                    }).catch(function() {});
                    return true;
                } catch (e) {
                    return false;
                }
            }

            return false;
        },

        loadConversation: function(conversationId, options) {
            var self = this;
            options = options || {};

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
                        var titleStatus = response.data.title_status || '';
                        self.titleGenerationInProgress = false;
                        self.conversationTitleIsPlaceholder = titleStatus === 'placeholder';
                        self.titleGenerationAttempted = !!response.data.title && !self.conversationTitleIsPlaceholder;
                        self.titleGenerationToken++;
                        self.conversationId = response.data.conversation_id;
                        self.conversationTitle = response.data.title;
                        self.pendingActions = [];
                        self.pendingToolResults = [];
                        self.pendingToolChecks = 0;
                        self.executingToolCount = 0;
                        self.conversationDirty = false;
                        if (self.clearQueuedMessages) {
                            self.clearQueuedMessages();
                        }
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
                        self.conversationTokenUsage = response.data.token_usage || null;
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
                        self.updateAreaChangeSuggestion();
                        if (options.updateHistory !== false) {
                            self.updateConversationRoute(self.conversationId, {
                                replace: !!options.replaceHistory
                            });
                        }

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

        loadMostRecentConversation: function(options) {
            var self = this;
            options = options || {};

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
                        var mostRecent = null;

                        for (var i = 0; i < response.data.conversations.length; i++) {
                            if (parseInt(response.data.conversations[i].message_count, 10) > 0) {
                                mostRecent = response.data.conversations[i];
                                break;
                            }
                        }

                        if (mostRecent) {
                            self.loadConversation(mostRecent.id, {
                                updateHistory: options.updateHistory !== false
                            });
                            return;
                        }

                        // Only empty conversations exist - show fresh welcome
                        var $emptyMessages = $('#ai-assistant-messages');
                        $emptyMessages.empty();
                        self.loadWelcomeMessage();
                        if (self.showCurrentAiChangesSuggestion) {
                            self.showCurrentAiChangesSuggestion();
                        }
                        $emptyMessages.css('visibility', 'visible');
                        self.updateExportButton();
                        self.hideAreaChangeSuggestion();
                    } else {
                        // No conversations - show fresh welcome
                        var $messages = $('#ai-assistant-messages');
                        $messages.empty();
                        self.loadWelcomeMessage();
                        if (self.showCurrentAiChangesSuggestion) {
                            self.showCurrentAiChangesSuggestion();
                        }
                        $messages.css('visibility', 'visible');
                        self.updateExportButton();
                        self.hideAreaChangeSuggestion();
                    }
                },
                error: function() {
                    // On error, show fresh welcome
                    var $messages = $('#ai-assistant-messages');
                    $messages.empty();
                    self.loadWelcomeMessage();
                    if (self.showCurrentAiChangesSuggestion) {
                        self.showCurrentAiChangesSuggestion();
                    }
                    $messages.css('visibility', 'visible');
                    self.updateExportButton();
                    self.hideAreaChangeSuggestion();
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
                html += '<button type="button" class="ai-export-format" role="menuitem" data-format="' + self.escapeAttribute(format.format) + '" title="' + self.escapeAttribute(format.description || '') + '">';
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

        buildConversationExportUrl: function(format, includeToolCalls, conversationId) {
            var baseUrl = (typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.conversationExportUrl) || '';
            var separator = baseUrl.indexOf('?') === -1 ? '?' : '&';
            conversationId = conversationId || this.conversationId;
            var url = baseUrl + separator +
                'conversation_id=' + encodeURIComponent(conversationId) +
                '&format=' + encodeURIComponent(format);
            if (includeToolCalls) {
                url += '&include_tool_calls=1';
            }
            return url + '&_wpnonce=' + encodeURIComponent(aiAssistantConfig.nonce);
        },

        exportConversationById: function(conversationId, format, includeToolCalls) {
            conversationId = parseInt(conversationId, 10);
            if (!conversationId || !format) {
                return;
            }

            this.startConversationExportDownload(this.buildConversationExportUrl(format, includeToolCalls, conversationId));
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
                var title = conv.title || '';
                html += '<div class="ai-conv-item' + activeClass + '" data-id="' + conv.id + '">';
                html += '<div class="ai-conv-item-title" title="' + self.escapeAttribute(title) + '">' + self.escapeHtml(title) + '</div>';
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

        renameConversation: function(conversationId, newTitle, options) {
            var self = this;
            options = options || {};
            var titleStatus = options.titleStatus || 'manual';

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ai_assistant_rename_conversation',
                    _wpnonce: aiAssistantConfig.nonce,
                    conversation_id: conversationId,
                    title: newTitle,
                    title_status: titleStatus
                },
                success: function(response) {
                    if (response.success) {
                        if (self.conversationId === conversationId) {
                            self.conversationTitle = newTitle;
                            self.conversationTitleIsPlaceholder = false;
                            self.titleGenerationAttempted = true;
                        }
                        $('.ai-conv-item[data-id="' + conversationId + '"] .ai-conv-item-title').text(newTitle).attr('title', newTitle);
                        if (typeof options.success === 'function') {
                            options.success(response);
                        }
                    } else {
                        console.error('[AI Assistant] Rename failed:', response.data);
                        if (typeof options.error === 'function') {
                            options.error(response.data && response.data.message ? response.data.message : 'Failed to rename conversation.');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[AI Assistant] Rename error:', error);
                    if (typeof options.error === 'function') {
                        options.error(error || 'Failed to rename conversation.');
                    }
                },
                complete: function() {
                    if (typeof options.complete === 'function') {
                        options.complete();
                    }
                }
            });
        },

        startConversationRename: function($item) {
            var self = this;

            if (!$item || !$item.length) {
                return;
            }

            var $title = $item.find('.ai-conv-item-title').first();
            if ($title.find('.ai-conv-rename-input').length) {
                return;
            }

            var id = parseInt($item.data('id'), 10);
            var currentTitle = $title.text();

            if ($item.data('clickTimeout')) {
                clearTimeout($item.data('clickTimeout'));
                $item.removeData('clickTimeout');
            }

            var $input = $('<input type="text" class="ai-conv-rename-input" aria-label="Conversation title">')
                .val(currentTitle);

            $title.empty().append($input);
            $input.trigger('focus').select();

            function saveRename() {
                var newTitle = $input.val().trim();
                if (newTitle && newTitle !== currentTitle) {
                    $title.text(newTitle);
                    self.renameConversation(id, newTitle, {
                        titleStatus: 'manual',
                        error: function() {
                            $title.text(currentTitle);
                        }
                    });
                } else {
                    $title.text(currentTitle);
                }
            }

            $input.on('blur', saveRename);
            $input.on('keydown', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $input.off('blur');
                    saveRename();
                } else if (e.which === 27) {
                    e.preventDefault();
                    $input.off('blur');
                    $title.text(currentTitle);
                }
            });
        },

        ensureConversationItemMenu: function() {
            var $menu = $('#ai-conv-context-menu');
            if ($menu.length) {
                return $menu;
            }

            $menu = $('<div id="ai-conv-context-menu" class="ai-conv-context-menu" role="menu" hidden></div>');

            var $mount = $('.ai-assistant-app-page').first();
            if ($mount.length === 0) {
                $mount = $('body');
            }
            $mount.append($menu);
            return $menu;
        },

        renderConversationItemMenu: function($menu) {
            var self = this;
            var formats = this.getConversationExportFormats();
            var html =
                '<button type="button" class="ai-conv-context-action" role="menuitem" data-action="rename">' +
                    '<span class="dashicons dashicons-edit" aria-hidden="true"></span>' +
                    '<span>Rename</span>' +
                '</button>' +
                '<button type="button" class="ai-conv-context-action" role="menuitem" data-action="regenerate">' +
                    '<span class="dashicons dashicons-update" aria-hidden="true"></span>' +
                    '<span>Regenerate title</span>' +
                '</button>';

            if (formats.length > 0) {
                html += '<div class="ai-conv-context-separator" role="separator"></div>';
                html += '<div class="ai-conv-context-label">Export</div>';
                html += '<label class="ai-conv-context-option">' +
                    '<input type="checkbox" id="ai-conv-context-include-tool-calls" value="1">' +
                    '<span>Include tool calls</span>' +
                    '</label>';
                formats.forEach(function(format) {
                    html += '<button type="button" class="ai-conv-context-action ai-conv-context-export-format" role="menuitem" data-format="' + self.escapeAttribute(format.format) + '" title="' + self.escapeAttribute(format.description || '') + '">';
                    html += '<span class="dashicons dashicons-download" aria-hidden="true"></span>';
                    html += '<span class="ai-conv-context-export-label">' + self.escapeHtml(format.label) + '</span>';
                    html += '<span class="ai-conv-context-export-ext">.' + self.escapeHtml(format.extension || format.format) + '</span>';
                    html += '</button>';
                });
            }

            $menu.html(html);
        },

        showConversationItemMenu: function($item, event) {
            if (!$item || !$item.length || !event) {
                return;
            }

            var id = parseInt($item.data('id'), 10);
            if (!id) {
                return;
            }

            var $menu = this.ensureConversationItemMenu();
            this.renderConversationItemMenu($menu);
            var left = event.pageX;
            var top = event.pageY;

            $menu
                .data('conversationId', id)
                .prop('hidden', false)
                .css({
                    left: left + 'px',
                    top: top + 'px'
                });

            var padding = 8;
            var $window = $(window);
            var maxLeft = $window.scrollLeft() + $window.width() - $menu.outerWidth() - padding;
            var maxTop = $window.scrollTop() + $window.height() - $menu.outerHeight() - padding;

            $menu.css({
                left: Math.max($window.scrollLeft() + padding, Math.min(left, maxLeft)) + 'px',
                top: Math.max($window.scrollTop() + padding, Math.min(top, maxTop)) + 'px'
            });
        },

        hideConversationItemMenu: function() {
            $('#ai-conv-context-menu').prop('hidden', true).removeData('conversationId');
        },

        handleConversationItemMenuAction: function(action) {
            var $menu = $('#ai-conv-context-menu');
            var conversationId = parseInt($menu.data('conversationId'), 10);
            var $item = $('.ai-conv-item[data-id="' + conversationId + '"]').first();

            this.hideConversationItemMenu();

            if (!conversationId || !$item.length) {
                return;
            }

            if (action === 'rename') {
                this.startConversationRename($item);
            } else if (action === 'regenerate') {
                this.regenerateConversationTitle(conversationId);
            }
        },

        handleConversationItemExport: function(format) {
            var $menu = $('#ai-conv-context-menu');
            var conversationId = parseInt($menu.data('conversationId'), 10);
            var includeToolCalls = $('#ai-conv-context-include-tool-calls').is(':checked');

            this.hideConversationItemMenu();
            this.exportConversationById(conversationId, format, includeToolCalls);
        },

        decodeConversationMessagesBase64: function(messagesBase64) {
            if (!messagesBase64) {
                return [];
            }

            try {
                var json = decodeURIComponent(escape(atob(messagesBase64)));
                var messages = JSON.parse(json);
                return Array.isArray(messages) ? messages : [];
            } catch (e) {
                console.error('[AI Assistant] Failed to decode messages for title generation:', e);
                return [];
            }
        },

        loadConversationTitleSource: function(conversationId) {
            var self = this;

            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_load_conversation',
                        _wpnonce: aiAssistantConfig.nonce,
                        conversation_id: conversationId
                    },
                    success: function(response) {
                        if (!response.success) {
                            reject(new Error(response.data && response.data.message ? response.data.message : 'Failed to load conversation.'));
                            return;
                        }

                        resolve({
                            messages: self.decodeConversationMessagesBase64(response.data.messages_base64 || ''),
                            provider: response.data.provider || '',
                            model: response.data.model || ''
                        });
                    },
                    error: function(xhr, status, error) {
                        reject(new Error(error || 'Failed to load conversation.'));
                    }
                });
            });
        },

        regenerateConversationTitle: function(conversationId) {
            var self = this;
            conversationId = parseInt(conversationId, 10);

            if (!conversationId) {
                return;
            }

            this.titleRegenerationInProgress = this.titleRegenerationInProgress || {};
            if (this.titleRegenerationInProgress[conversationId]) {
                return;
            }
            this.titleRegenerationInProgress[conversationId] = true;

            function getItem() {
                return $('.ai-conv-item[data-id="' + conversationId + '"]').first();
            }

            var $item = getItem();
            var $title = $item.find('.ai-conv-item-title').first();
            var originalTitle = $title.text();

            $item.addClass('is-regenerating');
            $title.text('Regenerating...').attr('title', originalTitle);

            function finish() {
                delete self.titleRegenerationInProgress[conversationId];
                getItem().removeClass('is-regenerating');
            }

            function restoreWithError(message) {
                getItem().find('.ai-conv-item-title').text(originalTitle).attr('title', originalTitle);
                if (self.addMessage) {
                    self.addMessage('error', message || 'Failed to regenerate conversation title.');
                }
                finish();
            }

            function persistTitle(title) {
                return new Promise(function(resolve, reject) {
                    self.renameConversation(conversationId, title, {
                        titleStatus: 'generated',
                        success: resolve,
                        error: function(message) {
                            reject(new Error(message || 'Failed to save generated title.'));
                        }
                    });
                });
            }

            var activeConversationId = parseInt(this.conversationId, 10) || 0;
            var sourcePromise;

            if (activeConversationId === conversationId && Array.isArray(this.messages) && this.messages.length > 0) {
                sourcePromise = Promise.resolve({
                    messages: this.messages.slice(),
                    provider: this.conversationProvider || this.getProvider(),
                    model: this.conversationModel || this.getModel()
                });
            } else {
                sourcePromise = this.loadConversationTitleSource(conversationId);
            }

            sourcePromise
                .then(function(source) {
                    if (!source.messages || source.messages.length === 0) {
                        throw new Error('This conversation has no messages to title.');
                    }

                    return self.generateConversationTitleFromMessages({
                        messages: source.messages,
                        provider: source.provider || self.getProvider(),
                        model: source.model || self.getModel()
                    });
                })
                .then(function(title) {
                    return persistTitle(title);
                })
                .then(function() {
                    finish();
                })
                .catch(function(error) {
                    restoreWithError(error && error.message ? error.message : 'Failed to regenerate conversation title.');
                });
        },

        // Title generation
        messageHasToolCallsForTitle: function(message) {
            if (!message) {
                return false;
            }

            if (message.tool_calls && message.tool_calls.length > 0) {
                return true;
            }

            if (!Array.isArray(message.content)) {
                return false;
            }

            return message.content.some(function(block) {
                return block && block.type === 'tool_use';
            });
        },

        getMessageTextForTitle: function(message) {
            if (!message) {
                return '';
            }

            var content = message.content;
            var parts = [];

            if (typeof content === 'string') {
                parts.push(content);
            } else if (Array.isArray(content)) {
                content.forEach(function(block) {
                    if (!block || block.type === 'tool_use' || block.type === 'tool_result') {
                        return;
                    }

                    if (typeof block.text === 'string') {
                        parts.push(block.text);
                    } else if (typeof block.content === 'string') {
                        parts.push(block.content);
                    }
                });
            } else if (content && typeof content.text === 'string') {
                parts.push(content.text);
            }

            var text = parts.join(' ');

            if (this.extractFileContextForDisplay) {
                var fileContext = this.extractFileContextForDisplay(text);
                if (fileContext) {
                    text = fileContext.visibleText || 'Attached files';
                }
            }

            return text.replace(/\s+/g, ' ').trim();
        },

        getFirstUserTextForTitle: function(messages) {
            messages = Array.isArray(messages) ? messages : this.messages;

            for (var i = 0; i < messages.length; i++) {
                if (messages[i] && messages[i].role === 'user') {
                    var text = this.getMessageTextForTitle(messages[i]);
                    if (text) {
                        return text;
                    }
                }
            }

            return '';
        },

        buildConversationTitleContext: function(messages) {
            messages = Array.isArray(messages) ? messages : this.messages;
            var lines = [];
            var maxLength = 1800;

            for (var i = 0; i < messages.length && lines.join('\n').length < maxLength; i++) {
                var message = messages[i];
                if (!message || (message.role !== 'user' && message.role !== 'assistant')) {
                    continue;
                }

                var text = this.getMessageTextForTitle(message);
                if (!text) {
                    continue;
                }

                var label = message.role === 'user' ? 'User' : 'Assistant';
                var limit = message.role === 'assistant' ? 900 : 500;
                if (text.length > limit) {
                    text = text.substring(0, limit).trim() + '...';
                }
                lines.push(label + ': ' + text);
            }

            return lines.join('\n').substring(0, maxLength).trim();
        },

        getFallbackConversationTitle: function(firstUserContent) {
            var words = (firstUserContent || '').split(/\s+/).slice(0, 6).join(' ');
            return words.length > 50 ? words.substring(0, 50) + '...' : words;
        },

        cleanGeneratedConversationTitle: function(title, fallbackTitle) {
            title = (title || '').trim().replace(/^["']|["']$/g, '').replace(/^title:\s*/i, '').split('\n')[0].trim();
            if (!title) {
                title = fallbackTitle || '';
            }
            if (title.length > 80) {
                title = title.substring(0, 77).trim() + '...';
            }
            return title;
        },

        generateConversationTitleFromMessages: function(options) {
            var self = this;
            options = options || {};

            var messages = Array.isArray(options.messages) ? options.messages : this.messages;
            var provider = options.provider || this.conversationProvider || this.getProvider();
            var model = options.model || this.conversationModel || this.getModel();
            var apiKey = this.getApiKey(provider);
            var providerConfig = this.isConnectorsMode() && typeof aiAssistantProviders !== 'undefined'
                ? aiAssistantProviders.available[provider]
                : null;
            var hasServerAuth = !!(providerConfig && providerConfig.serverSideAuth && providerConfig.proxySupported);
            var fetchProvider = typeof self.fetchLLMProvider === 'function'
                ? self.fetchLLMProvider.bind(self)
                : function(providerId, endpoint, headers, payload) {
                    return fetch(endpoint, {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify(payload)
                    });
                };

            var titleContext = this.buildConversationTitleContext(messages);
            var firstUserContent = this.getFirstUserTextForTitle(messages);

            if (!titleContext || !firstUserContent) {
                return Promise.reject(new Error('This conversation does not have enough text to generate a title.'));
            }

            var fallbackTitle = this.getFallbackConversationTitle(firstUserContent);
            var titlePrompt = 'Generate a specific, accurate title (3-6 words max) for this conversation. Use the assistant answer when it clarifies a vague opening message. Do not invent details that are not in the excerpt. Return ONLY the title, nothing else.\n\nConversation excerpt:\n' + titleContext;

            return new Promise(function(resolve) {
                function finishWithTitle(title) {
                    resolve(self.cleanGeneratedConversationTitle(title, fallbackTitle));
                }

                function finishWithFallback(err) {
                    if (err) {
                        console.error('[AI Assistant] Title generation failed:', err);
                    }
                    finishWithTitle(fallbackTitle);
                }

                if (provider === 'anthropic' && (apiKey || hasServerAuth)) {
                    var anthropicEndpoint = self.getProviderEndpoint(provider) || 'https://api.anthropic.com/v1/messages';

                    fetchProvider(
                        'anthropic',
                        anthropicEndpoint,
                        {
                            'Content-Type': 'application/json',
                            'x-api-key': apiKey,
                            'anthropic-version': '2023-06-01',
                            'anthropic-dangerous-direct-browser-access': 'true'
                        },
                        {
                            model: model,
                            max_tokens: 30,
                            messages: [{ role: 'user', content: titlePrompt }]
                        }
                    )
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
                } else if (provider === 'openai' && (apiKey || hasServerAuth)) {
                    var openAIEndpoint = self.getProviderEndpoint(provider) || 'https://api.openai.com/v1/chat/completions';

                    fetchProvider(
                        'openai',
                        openAIEndpoint,
                        {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + apiKey
                        },
                        {
                            model: model,
                            max_tokens: 30,
                            messages: [{ role: 'user', content: titlePrompt }]
                        }
                    )
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
            });
        },

        shouldGenerateConversationTitle: function() {
            if (this.titleGenerationAttempted || this.titleGenerationInProgress) {
                return false;
            }

            if (this.conversationTitle && !this.conversationTitleIsPlaceholder) {
                return false;
            }

            if (this.messages.length < 2) {
                return false;
            }

            if ((this.pendingToolChecks || 0) > 0 || (this.executingToolCount || 0) > 0) {
                return false;
            }

            if (this.pendingActions && this.pendingActions.length > 0) {
                return false;
            }

            var lastMessage = null;
            for (var i = this.messages.length - 1; i >= 0; i--) {
                if (this.messages[i] && (this.messages[i].role === 'user' || this.messages[i].role === 'assistant')) {
                    lastMessage = this.messages[i];
                    break;
                }
            }

            if (!lastMessage || lastMessage.role !== 'assistant' || this.messageHasToolCallsForTitle(lastMessage)) {
                return false;
            }

            return !!this.getMessageTextForTitle(lastMessage);
        },

        generateConversationTitle: function() {
            var self = this;
            var provider = this.conversationProvider || this.getProvider();
            var model = this.conversationModel || this.getModel();

            var titleContext = this.buildConversationTitleContext();
            var firstUserContent = this.getFirstUserTextForTitle();

            if (!titleContext || !firstUserContent) {
                this.titleGenerationAttempted = false;
                return;
            }

            this.titleGenerationInProgress = true;
            this.titleGenerationToken++;
            var titleGenerationToken = this.titleGenerationToken;

            this.generateConversationTitleFromMessages({
                messages: this.messages,
                provider: provider,
                model: model
            }).then(function(title) {
                if (self.titleGenerationToken !== titleGenerationToken) {
                    return;
                }
                self.conversationTitle = title;
                self.conversationTitleIsPlaceholder = false;
                self.titleGenerationInProgress = false;
                self.saveConversation(true);
            }).catch(function(error) {
                if (self.titleGenerationToken !== titleGenerationToken) {
                    return;
                }
                console.error('[AI Assistant] Title generation failed:', error);
                self.titleGenerationInProgress = false;
                self.titleGenerationAttempted = false;
            });
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
