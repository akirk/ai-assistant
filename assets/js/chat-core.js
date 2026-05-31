(function($) {
    'use strict';

    var existingAiAssistant = window.aiAssistant || {};
    var queuedToolCallbacks = Array.isArray(window.aiAssistantToolCallbacks)
        ? window.aiAssistantToolCallbacks.slice()
        : [];

    window.aiAssistant = $.extend(existingAiAssistant, {
        // State
        isOpen: false,
        conversationId: 0,
        conversationTitle: '',
        messages: [],
        pendingActions: [],
        toolCallRounds: 0,
        consecutiveFailedToolRounds: 0,
        usesCodingToolWorkflow: false,
        isLoading: false,
        systemPrompt: '',
        isFullPage: false,
        autoSave: true,
        queuedMessages: [],
        draftStorageKey: 'aiAssistant_draftMessage',
        draftHistoryKey: 'aiAssistant_draftHistory',
        urlComponentStorageKey: 'aiAssistant_lastUrlComponent',
        urlContextTimestampStorageKey: 'aiAssistant_lastUrlContextAt',
        newChatSuggestionMaxAgeMs: 60 * 60 * 1000,
        autoApproveStorageKey: 'aiAssistant_autoApproveMode',
        yoloStorageKey: 'aiAssistant_yoloMode',
        conversationPreloaded: false,
        autoApproveMode: false,
        yoloMode: false,
        conversationProvider: '',
        conversationModel: '',
        draftHistory: [],
        draftHistoryIndex: -1,
        draftHistoryDraft: '',
        draftHistoryMax: 10,
        pendingNewChat: false,
        pendingNewChatProvider: '',
        pendingNewChatModel: '',
        pendingChatOriginalHtml: null,
        previousUrlComponent: '',
        previousUrlContextAt: 0,
        conversationInteracted: false,
        saveInProgress: false,
        savePending: false,
        savePendingSilent: true,
        savePendingCallbacks: [],
        titleGenerationInProgress: false,
        titleGenerationAttempted: false,
        titleGenerationToken: 0,
        titleRegenerationInProgress: {},
        conversationTitleIsPlaceholder: false,
        consecutiveAjaxErrors: 0,
        ajaxErrorThreshold: 2,
        recoveryMessageShown: false,
        wordpressRecoveryCheckInProgress: false,
        abortController: null,
        isPageExiting: false,
        pageExitPrepared: false,
        conversationDirty: false,
        pendingAttachments: [],
        isUploadingFiles: false,
        toolCallSubscriptions: existingAiAssistant.toolCallSubscriptions || [],
        nextToolCallSubscriptionId: existingAiAssistant.nextToolCallSubscriptionId || 1,
        toolResultCache: existingAiAssistant.toolResultCache || {},
        toolResultCacheOrder: existingAiAssistant.toolResultCacheOrder || [],
        shouldFollowStreamingScroll: true,
        streamingScrollThreshold: 24,
        streamingScrollResumeThreshold: 4,
        defaultScrollThreshold: 100,
        messagesTouchStartY: null,
        initialized: existingAiAssistant.initialized || false,

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

        init: function() {
            if (this.initialized) {
                return;
            }

            this.initialized = true;

            var self = this;
            this.setupAjaxErrorTracking();
            this.bindEvents();
            if (this.bindConversationHistoryEvents) {
                this.bindConversationHistoryEvents();
            }
            this.buildSystemPrompt();
            this.restoreDraft();
            if (this.restoreAutoApproveMode) {
                this.restoreAutoApproveMode();
            } else if (this.restoreYoloMode) {
                this.restoreYoloMode();
            }
            this.loadDraftHistory();
            if (this.restoreUrlComponentContext) {
                this.restoreUrlComponentContext();
            }

            this.conversationProvider = this.getProvider();
            this.conversationModel = this.getModel();
            this.updateSendButton();
            this.updateTokenCount();
            if (this.renderConversationExportMenu) {
                this.renderConversationExportMenu();
            }
            if (this.updateExportButton) {
                this.updateExportButton();
            }

            if (typeof aiAssistantPageConfig !== 'undefined') {
                this.isFullPage = aiAssistantPageConfig.isFullPage || false;
                if (this.isFullPage) {
                    this.loadSidebarConversations();
                    $('#ai-assistant-input').focus();
                }
                if (aiAssistantPageConfig.conversationId > 0) {
                    this.loadConversation(aiAssistantPageConfig.conversationId);
                } else {
                    this.loadMostRecentConversation({ updateHistory: false });
                }
            } else {
                this.loadWelcomeMessage();
            }

            if (this.showCurrentAiChangesSuggestion) {
                this.showCurrentAiChangesSuggestion();
            }
        },

        bindEvents: function() {
            var self = this;

            $(document).on('click', '.ai-assistant-toggle', function(e) {
                e.preventDefault();
                self.toggle();
            });

            $(document).on('click', '#ai-assistant-close', function(e) {
                e.preventDefault();
                self.close();
            });

            $(document).on('click', '#ai-assistant-send', function(e) {
                e.preventDefault();
                self.sendMessage();
            });

            $(document).on('click', '#ai-assistant-stop', function(e) {
                e.preventDefault();
                self.stopGeneration();
            });

            $(window).on('beforeunload.aiAssistant', function(e) {
                self.prepareForPageExit(e, {
                    abort: false,
                    captureInterruptedReply: false,
                    markExiting: false
                });

                if (self.shouldConfirmPageExit()) {
                    e.preventDefault();
                    if (e.originalEvent) {
                        e.originalEvent.returnValue = '';
                    }
                    e.returnValue = '';
                    return '';
                }
            });

            $(window).on('pagehide.aiAssistant', function(e) {
                self.prepareForPageExit(e, { abort: true });
            });

            $(window).on('pageshow.aiAssistant', function() {
                self.isPageExiting = false;
                self.pageExitPrepared = false;
            });

            $(document).on('keydown', '#ai-assistant-input', function(e) {
                if (e.which === 13 && !e.shiftKey && !e.altKey) {
                    e.preventDefault();
                    self.sendMessage();
                } else if (e.which === 38 && !e.shiftKey) {
                    var $input = $(this);
                    if ($input.val() === '' || $input[0].selectionStart === 0) {
                        e.preventDefault();
                        self.navigateDraftHistory(1);
                    }
                } else if (e.which === 40 && !e.shiftKey) {
                    var $input = $(this);
                    var val = $input.val();
                    if (val === '' || $input[0].selectionStart === val.length) {
                        e.preventDefault();
                        self.navigateDraftHistory(-1);
                    }
                }
            });

            $(document).on('click', '#ai-assistant-new-chat', function(e) {
                e.preventDefault();
                self.newChat();
            });

            $(document).on('click', '#ai-assistant-undo-new-chat', function(e) {
                e.preventDefault();
                self.undoNewChat();
            });

            $(document).on('click', '#ai-assistant-area-new-chat', function(e) {
                e.preventDefault();
                self.newChat();
            });

            $(document).on('click', '#ai-confirm-all', function(e) {
                e.preventDefault();
                self.confirmAllActions(true);
            });

            $(document).on('click', '#ai-skip-all', function(e) {
                e.preventDefault();
                self.confirmAllActions(false);
            });

            $(document).on('click', '.ai-tool-approval-close', function(e) {
                e.preventDefault();
                self.hideToolApprovalModal();
            });

            $(document).on('click', '.ai-tool-approve', function(e) {
                e.preventDefault();
                var toolId = $(this).attr('data-tool-id');
                self.confirmAction(toolId, true);
            });

            $(document).on('click', '.ai-tool-approve-always', function(e) {
                e.preventDefault();
                var toolId = $(this).attr('data-tool-id');
                var abilityId = $(this).attr('data-ability');
                var restApi = $(this).attr('data-rest-api');
                if (abilityId) {
                    self.saveAutoApprovedAbility(abilityId);
                } else if (restApi) {
                    self.saveAutoApprovedRestApi(restApi);
                }
                if ((abilityId || restApi) && self.confirmMatchingAutoApprovedActions) {
                    self.confirmMatchingAutoApprovedActions(toolId);
                } else {
                    self.confirmAction(toolId, true);
                }
            });

            $(document).on('click', '.ai-ability-info-toggle', function(e) {
                e.preventDefault();
                if (self.toggleAbilityApprovalDetails) {
                    self.toggleAbilityApprovalDetails($(this));
                }
            });

            $(document).on('click', '.ai-tool-skip', function(e) {
                e.preventDefault();
                var toolId = $(this).attr('data-tool-id');
                self.confirmAction(toolId, false);
            });

            $(document).on('keydown', function(e) {
                if (e.which === 27 && self.isOpen) {
                    if ($('#ai-tool-approval-overlay:visible').length) {
                        self.hideToolApprovalModal();
                        return;
                    }
                    self.close();
                }
            });

            $(document).on('input', '#ai-assistant-input', function() {
                self.saveDraft();
                self.updateSendButton();
            });

            $(document).on('change', '.ai-model-select', function() {
                if (self.handleModelSelectionChange) {
                    self.handleModelSelectionChange($(this));
                }
            });

            $(document).on('mouseenter', '#ai-assistant-link-wrap', function() {
                self.preloadMostRecentConversation();
            });

            $(document).on('change', '#ai-assistant-auto-approve, #ai-assistant-yolo', function() {
                if (self.setAutoApproveMode) {
                    self.setAutoApproveMode($(this).is(':checked'));
                } else {
                    self.autoApproveMode = $(this).is(':checked');
                    self.yoloMode = self.autoApproveMode;
                }
                if (self.saveAutoApproveMode) {
                    self.saveAutoApproveMode();
                } else if (self.saveYoloMode) {
                    self.saveYoloMode();
                }
                self.addMessage('system', self.autoApproveMode
                    ? 'Auto-approve enabled - destructive actions will execute without confirmation.'
                    : 'Auto-approve disabled - destructive actions will require confirmation.');
            });

            $(document).on('click', '#ai-assistant-scroll-bottom', function() {
                self.shouldFollowStreamingScroll = true;
                self.scrollToBottom(true);
            });

            $(document).on('scroll', '#ai-assistant-messages', function() {
                self.handleMessagesScroll();
            });

            $(document).on('wheel', '#ai-assistant-messages', function(e) {
                self.handleMessagesWheel(e);
            });

            $(document).on('touchstart', '#ai-assistant-messages', function(e) {
                self.handleMessagesTouchStart(e);
            });

            $(document).on('touchmove', '#ai-assistant-messages', function(e) {
                self.handleMessagesTouchMove(e);
            });

            $(document).on('keydown', '#ai-assistant-messages', function(e) {
                self.handleMessagesScrollKeydown(e);
            });

            $(document).on('pointerdown', '#ai-assistant-expand', function(e) {
                self.startAssistantPanelResize(e, this);
            });

            $(document).on('dblclick', '#ai-assistant-expand', function(e) {
                e.preventDefault();
                self.toggleAssistantPanelMax($(this).closest('.ai-assistant-chat-container'));
            });

            $(document).on('keydown', '#ai-assistant-expand', function(e) {
                self.handleAssistantPanelResizeKeydown(e, this);
            });

            $(window).on('resize.aiAssistantPanelResize', function() {
                self.clampAssistantPanelHeight();
            });

            $(document).on('click', '#ai-assistant-save-chat', function(e) {
                e.preventDefault();
                self.saveConversation();
            });

            $(document).on('click', '#ai-assistant-summarize', function(e) {
                e.preventDefault();
                self.manualSummarizeConversation();
            });

            $(document).on('click', '#ai-assistant-export', function(e) {
                e.preventDefault();
                self.toggleConversationExportMenu();
            });

            $(document).on('click', '.ai-export-format', function(e) {
                e.preventDefault();
                self.exportConversation($(this).data('format'));
            });

            $(document).on('click', function(e) {
                if ($(e.target).closest('.ai-export-menu-wrap').length === 0) {
                    self.hideConversationExportMenu();
                }
            });

            $(document).on('click', '.ai-action-copy', function(e) {
                e.preventDefault();
                var $msg = $(this).closest('.ai-message');
                var text = $msg.attr('data-copy-content') || $msg.attr('data-raw-content') || $msg.find('.ai-message-content').text();
                navigator.clipboard.writeText(text).then(function() {
                    var $btn = $(e.currentTarget);
                    $btn.addClass('ai-action-success');
                    setTimeout(function() { $btn.removeClass('ai-action-success'); }, 1500);
                });
            });

            $(document).on('click', '.ai-action-retry', function(e) {
                e.preventDefault();
                if (self.isLoading) return;
                var $message = $(this).closest('.ai-message');
                var content = $message.attr('data-raw-content');
                self.truncateFromUserMessage(content, $message.attr('data-message-timestamp'));
                $('#ai-assistant-input').val(content);
                self.sendMessage();
            });

            $(document).on('click', '.ai-action-edit', function(e) {
                e.preventDefault();
                if (self.isLoading) return;
                var $message = $(this).closest('.ai-message');
                var content = $message.attr('data-raw-content');
                self.truncateFromUserMessage(content, $message.attr('data-message-timestamp'));
                if (self.getEditableMessageContent) {
                    content = self.getEditableMessageContent(content);
                }
                var $input = $('#ai-assistant-input');
                $input.val(content).trigger('input').focus();
            });

            $(document).on('click', '.ai-action-summarize', function(e) {
                e.preventDefault();
                self.manualSummarizeConversation();
            });

            $(document).on('click', '.ai-summary-header', function() {
                $(this).closest('.ai-conversation-summary').toggleClass('collapsed');
            });

            $(document).on('click', '#ai-assistant-load-chat', function(e) {
                e.preventDefault();
                self.showConversationList();
            });

            $(document).on('click', '.ai-modal-close', function() {
                $(this).closest('.ai-modal').hide();
            });

            $(document).on('click', '.ai-modal', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            $(document).on('click', '.ai-conversation-load', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (self.markConversationInteracted) {
                    self.markConversationInteracted();
                }
                var id = $(this).data('id');
                self.loadConversation(id);
                $('#ai-conversation-modal').hide();
            });

            $(document).on('click', '.ai-conversation-delete, .ai-conv-item-delete', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.hideConversationItemMenu();
                var id = $(this).data('id');
                if (confirm('Delete this conversation?')) {
                    self.deleteConversation(id);
                }
            });

            $(document).on('contextmenu', '.ai-conv-item', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $item = $(this);
                if ($item.data('clickTimeout')) {
                    clearTimeout($item.data('clickTimeout'));
                    $item.removeData('clickTimeout');
                }

                self.showConversationItemMenu($item, e);
            });

            $(document).on('click', '.ai-conv-context-action', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if ($(this).hasClass('ai-conv-context-export-format')) {
                    return;
                }
                self.handleConversationItemMenuAction($(this).data('action'));
            });

            $(document).on('click', '.ai-conv-context-export-format', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.handleConversationItemExport($(this).data('format'));
            });

            $(document).on('click', function(e) {
                if ($(e.target).closest('.ai-conv-context-menu').length === 0) {
                    self.hideConversationItemMenu();
                }
            });

            $(document).on('keydown', function(e) {
                if (e.which === 27) {
                    self.hideConversationItemMenu();
                }
            });

            $(document).on('click', '.ai-conv-item', function(e) {
                if ($(e.target).hasClass('ai-conv-item-delete')) return;
                if ($(e.target).hasClass('ai-conv-rename-input')) return;
                var $item = $(this);
                var id = $item.data('id');

                if ($item.data('clickTimeout')) {
                    clearTimeout($item.data('clickTimeout'));
                }

                var timeout = setTimeout(function() {
                    if (self.markConversationInteracted) {
                        self.markConversationInteracted();
                    }
                    self.loadConversation(id);
                }, 250);
                $item.data('clickTimeout', timeout);
            });

            $(document).on('click', '.ai-action-preview-toggle', function(e) {
                e.preventDefault();
                $(this).closest('.ai-action-preview').toggleClass('expanded');
            });

            $(document).on('click', '.ai-thinking-toggle', function(e) {
                e.preventDefault();
                var $block = $(this).closest('.ai-thinking-block').toggleClass('expanded');
                $(this).attr('aria-expanded', $block.hasClass('expanded') ? 'true' : 'false');
            });

            $(document).on('dblclick', '.ai-conv-item-title', function(e) {
                e.stopPropagation();
                self.startConversationRename($(this).closest('.ai-conv-item'));
            });

            if (this.bindFileContextEvents) {
                this.bindFileContextEvents();
            }
        },

        toggle: function() {
            this.isOpen ? this.close() : this.open();
        },

        preloadMostRecentConversation: function() {
            if (this.isFullPage || this.conversationPreloaded) {
                return;
            }

            this.conversationPreloaded = true;
            this.loadMostRecentConversation();
        },

        open: function() {
            $('#ai-assistant-drawer').addClass('open');
            this.isOpen = true;
            this.scrollToBottom(true);
            $('#ai-assistant-input').focus();

            this.preloadMostRecentConversation();
            if (this.updateAreaChangeSuggestion) {
                this.updateAreaChangeSuggestion();
            }
        },

        close: function() {
            $('#ai-assistant-drawer').removeClass('open');
            this.isOpen = false;
        },

        buildSystemPrompt: function() {
            this.systemPrompt = aiAssistantConfig.systemPrompt || '';
            if (!this.systemPrompt) {
                console.error('[AI Assistant] No system prompt provided');
                this.addMessage('error', 'Configuration error: system prompt not available. Please check plugin settings.');
            }
        },

        isProviderConfigured: function() {
            return this.isConfigured();
        },

        updateSendButton: function() {
            var $btn = $('#ai-assistant-send');
            if (!$btn.length) return;

            if (!$btn.data('default-text')) {
                $btn.data('default-text', $btn.text());
            }

            var isQueueing = this.shouldQueueUserMessage && this.shouldQueueUserMessage();
            $btn
                .text(isQueueing ? 'Queue' : $btn.data('default-text'))
                .attr('title', isQueueing ? 'Queue message' : '')
                .prop('disabled', !this.isProviderConfigured() || this.isUploadingFiles);
            this.updateModelSelectLockState();
        },

        hasConversationStarted: function() {
            if (this.pendingNewChat) {
                return false;
            }

            var messages = this.getMessagesForSave
                ? this.getMessagesForSave()
                : (Array.isArray(this.messages) ? this.messages : []);

            return Array.isArray(messages) && messages.length > 0;
        },

        isModelSelectionLocked: function() {
            return this.hasConversationStarted();
        },

        updateModelSelectLockState: function() {
            var locked = this.isModelSelectionLocked && this.isModelSelectionLocked();
            $('.ai-model-select')
                .prop('disabled', !!this.isLoading || !!locked)
                .attr('title', locked ? 'Model is locked after the conversation starts' : 'Change model');
        },

        getQueuedMessageCount: function() {
            return Array.isArray(this.queuedMessages) ? this.queuedMessages.length : 0;
        },

        getQueuedMessageStatusText: function() {
            var count = this.getQueuedMessageCount();
            if (count === 0) {
                return '';
            }
            if (count === 1) {
                return 'Your queued message will be sent after the next tool call finishes.';
            }
            return count + ' queued messages will be sent after the next tool call finishes.';
        },

        updateLoadingStatus: function() {
            var $loading = $('#ai-assistant-loading');
            if (!$loading.length) return;

            var $status = $loading.find('.ai-loading-status');
            if (!$status.length) {
                $status = $('<div class="ai-loading-status" aria-live="polite"></div>');
                $loading.append($status);
            }

            var statusText = this.getQueuedMessageStatusText();
            if (!statusText && this.isLoading) {
                statusText = 'Generating. Leaving this page will stop this response.';
            }
            $status.text(statusText).toggle(!!statusText);

            if (this.isLoading || statusText) {
                $loading.show();
            } else {
                $loading.hide();
            }
        },

        setLoading: function(loading) {
            var self = this;
            this.isLoading = loading;
            var $loading = $('#ai-assistant-loading');
            var $send = $('#ai-assistant-send');
            var $stop = $('#ai-assistant-stop');
            var $input = $('#ai-assistant-input');

            if (loading) {
                this.abortController = new AbortController();
                $loading.show();
                $send.show();
                $stop.show();
                this.updateSendButton();
                this.updateLoadingStatus();
                if (this.shouldFollowStreamingScroll) {
                    this.scrollToBottom(true);
                    setTimeout(function() {
                        self.scrollToBottom(true);
                    }, 0);
                } else {
                    this.updateScrollBottomButton();
                }
            } else {
                this.abortController = null;
                $stop.hide();
                $send.show();
                this.updateSendButton();
                this.updateLoadingStatus();
                $input.focus();
            }
        },

        stopGeneration: function() {
            if (this.abortController) {
                this.abortController.abort();
            }
            this.hideToolProgress();
            if (this.clearQueuedMessages) {
                this.clearQueuedMessages();
            }
            this.setLoading(false);

            var $streaming = $('#ai-assistant-messages .ai-message-streaming');
            if ($streaming.length) {
                this.finalizeReply($streaming);
            }
        },

        isExpectedGenerationAbort: function(error) {
            if (error && error.name === 'AbortError') {
                return true;
            }

            return !!(
                this.isPageExiting ||
                (this.abortController && this.abortController.signal && this.abortController.signal.aborted)
            );
        },

        shouldConfirmPageExit: function() {
            var hasPendingActions = this.pendingActions && this.pendingActions.length > 0;

            return !!(
                this.conversationDirty ||
                this.saveInProgress ||
                this.isLoading ||
                hasPendingActions ||
                this.getQueuedMessageCount() > 0
            );
        },

        prepareForPageExit: function(e, options) {
            options = options || {};
            var originalEvent = e && e.originalEvent ? e.originalEvent : e;
            var isPersistedPage = !!(originalEvent && originalEvent.persisted);
            var hasPendingActions = this.pendingActions && this.pendingActions.length > 0;
            var shouldPersist = this.conversationDirty || this.isLoading || hasPendingActions || this.getQueuedMessageCount() > 0;

            if (options.markExiting !== false) {
                this.isPageExiting = !isPersistedPage;
            }

            if (!shouldPersist) {
                return;
            }

            if (!this.pageExitPrepared || options.captureInterruptedReply === false) {
                if (typeof this.captureInterruptedReply === 'function') {
                    if (options.captureInterruptedReply !== false) {
                        this.captureInterruptedReply('Response interrupted because the page changed.');
                    }
                }

                if (typeof this.persistConversationForPageExit === 'function') {
                    this.persistConversationForPageExit();
                }

                if (options.captureInterruptedReply !== false) {
                    this.pageExitPrepared = true;
                }
            }

            if (options.abort !== false && this.abortController && !isPersistedPage) {
                this.abortController.abort();
            }
        },

        handlePageHide: function(e) {
            this.prepareForPageExit(e, { abort: true });
        },

        isPanelOpen: function() {
            if (this.isFullPage) {
                return true;
            }
            // Screen-meta mode
            var $screenMetaButton = $('#ai-assistant-link');
            if ($screenMetaButton.length) {
                return $screenMetaButton.attr('aria-expanded') === 'true';
            }
            // Standalone mode
            var $standaloneButton = $('#ai-assistant-standalone-trigger button');
            if ($standaloneButton.length) {
                return $standaloneButton.attr('aria-expanded') === 'true';
            }
            return false;
        },

        shouldDeferBrowserProviderStatusChecks: function() {
            if (this.isFullPage) {
                return false;
            }

            var $standaloneButton = $('#ai-assistant-standalone-trigger button');
            return $standaloneButton.length > 0 && $standaloneButton.attr('aria-expanded') !== 'true';
        },

        isNearBottom: function(threshold) {
            var $messages = $('#ai-assistant-messages');
            if ($messages.length === 0) return true;

            var scrollTop = $messages.scrollTop();
            var scrollHeight = $messages[0].scrollHeight;
            var clientHeight = $messages[0].clientHeight;
            threshold = threshold || 100;

            return (scrollHeight - scrollTop - clientHeight) < threshold;
        },

        getScrollBottomThreshold: function() {
            return this.isLoading ? this.streamingScrollThreshold : this.defaultScrollThreshold;
        },

        pauseStreamingScrollFollow: function() {
            if (!this.isLoading) {
                return;
            }

            this.shouldFollowStreamingScroll = false;
            this.updateScrollBottomButton();
        },

        updateScrollBottomButton: function() {
            var shouldShow = !this.isNearBottom(this.getScrollBottomThreshold());
            $('#ai-assistant-scroll-bottom').toggle(shouldShow);
        },

        handleMessagesScroll: function() {
            var nearBottom = this.isNearBottom(this.getScrollBottomThreshold());

            if (this.isLoading) {
                if (this.isNearBottom(this.streamingScrollResumeThreshold)) {
                    this.shouldFollowStreamingScroll = true;
                } else if (!nearBottom) {
                    this.shouldFollowStreamingScroll = false;
                }
            }

            $('#ai-assistant-scroll-bottom').toggle(!nearBottom);
        },

        handleMessagesWheel: function(e) {
            var event = e.originalEvent || e;

            if (event.deltaY < 0) {
                this.pauseStreamingScrollFollow();
            }
        },

        getTouchClientY: function(e) {
            var event = e.originalEvent || e;
            var touches = event.touches || event.changedTouches;

            return touches && touches.length ? touches[0].clientY : null;
        },

        handleMessagesTouchStart: function(e) {
            this.messagesTouchStartY = this.getTouchClientY(e);
        },

        handleMessagesTouchMove: function(e) {
            var clientY = this.getTouchClientY(e);

            if (clientY === null || this.messagesTouchStartY === null) {
                return;
            }

            if (clientY > this.messagesTouchStartY) {
                this.pauseStreamingScrollFollow();
            }

            this.messagesTouchStartY = clientY;
        },

        handleMessagesScrollKeydown: function(e) {
            if (
                e.key === 'ArrowUp' ||
                e.key === 'PageUp' ||
                e.key === 'Home'
            ) {
                this.pauseStreamingScrollFollow();
            }
        },

        scrollToBottom: function(force) {
            var $messages = $('#ai-assistant-messages');
            if ($messages.length === 0) return;

            if (force) {
                this.shouldFollowStreamingScroll = true;
            }

            var shouldScroll = force || (this.isLoading
                ? this.shouldFollowStreamingScroll
                : this.isNearBottom(this.defaultScrollThreshold));

            if (shouldScroll) {
                $messages.scrollTop($messages[0].scrollHeight);
                $('#ai-assistant-scroll-bottom').hide();
            } else {
                this.updateScrollBottomButton();
            }
        },

        getAssistantPanelResizeLimits: function($container) {
            var viewportHeight = window.innerHeight || $(window).height() || 0;
            var rect = $container.length && $container[0].getBoundingClientRect
                ? $container[0].getBoundingClientRect()
                : { top: 0 };
            var bottomPadding = $container.closest('.ai-assistant-standalone-panel').length ? 20 : 24;
            var max = Math.floor(viewportHeight - rect.top - bottomPadding);

            if (!isFinite(max) || max < 260) {
                max = Math.max(260, viewportHeight - bottomPadding);
            }

            var min = viewportHeight < 520 ? 240 : 300;
            min = Math.min(min, max);

            return {
                min: min,
                max: Math.max(min, max)
            };
        },

        getAssistantPanelDefaultHeight: function(limits) {
            var defaultHeight = window.matchMedia && window.matchMedia('(max-width: 782px)').matches ? 300 : 380;

            if (limits) {
                return Math.min(defaultHeight, limits.max);
            }

            return defaultHeight;
        },

        readAssistantPanelHeight: function($container, limits) {
            if (!$container.length) {
                return 0;
            }

            var rawHeight = $container[0].style.getPropertyValue('--ai-assistant-chat-height');
            var parsedHeight = rawHeight ? parseFloat(rawHeight) : NaN;

            if (isFinite(parsedHeight)) {
                return parsedHeight;
            }

            var currentHeight = $container.outerHeight();
            if (isFinite(currentHeight) && currentHeight > 0) {
                return currentHeight;
            }

            return this.getAssistantPanelDefaultHeight(limits);
        },

        updateAssistantPanelResizeHandle: function($container, height, limits) {
            var $handle = $container.find('#ai-assistant-expand');
            if (!$handle.length) {
                return;
            }

            $handle.attr({
                'aria-valuemin': Math.round(limits.min),
                'aria-valuemax': Math.round(limits.max),
                'aria-valuenow': Math.round(height)
            });
        },

        applyAssistantPanelHeight: function($container, height, limits) {
            if (!$container.length) {
                return 0;
            }

            limits = limits || this.getAssistantPanelResizeLimits($container);
            if (!isFinite(height)) {
                height = this.getAssistantPanelDefaultHeight(limits);
            }

            var nextHeight = Math.round(Math.max(limits.min, Math.min(height, limits.max)));
            $container[0].style.setProperty('--ai-assistant-chat-height', nextHeight + 'px');
            $container.toggleClass('expanded', nextHeight >= limits.max - 2);
            this.updateAssistantPanelResizeHandle($container, nextHeight, limits);
            $(document).trigger('aiAssistantPanelHeightChange', [$container, nextHeight]);

            return nextHeight;
        },

        startAssistantPanelResize: function(event, handle) {
            var originalEvent = event.originalEvent || event;
            if (originalEvent.button !== undefined && originalEvent.button !== 0) {
                return;
            }

            var $handle = $(handle);
            var $container = $handle.closest('.ai-assistant-chat-container');
            if (!$container.length || !isFinite(originalEvent.clientY)) {
                return;
            }

            var self = this;
            var limits = this.getAssistantPanelResizeLimits($container);
            var startHeight = this.applyAssistantPanelHeight($container, this.readAssistantPanelHeight($container, limits), limits);
            var startY = originalEvent.clientY;
            var pointerId = originalEvent.pointerId;

            $container.addClass('is-resizing').removeClass('expanded');
            $('body').addClass('ai-assistant-panel-resizing');

            if (handle.setPointerCapture && pointerId !== undefined) {
                try {
                    handle.setPointerCapture(pointerId);
                } catch (error) {
                    // Pointer capture can fail if the browser has already canceled the pointer.
                }
            }

            $(document).off('.aiAssistantResize');
            $(document).on('pointermove.aiAssistantResize', function(moveEvent) {
                var moveOriginal = moveEvent.originalEvent || moveEvent;
                if (pointerId !== undefined && moveOriginal.pointerId !== undefined && moveOriginal.pointerId !== pointerId) {
                    return;
                }
                if (!isFinite(moveOriginal.clientY)) {
                    return;
                }

                self.applyAssistantPanelHeight($container, startHeight + moveOriginal.clientY - startY);
                moveEvent.preventDefault();
            });

            $(document).on('pointerup.aiAssistantResize pointercancel.aiAssistantResize', function(upEvent) {
                var upOriginal = upEvent.originalEvent || upEvent;
                if (pointerId !== undefined && upOriginal.pointerId !== undefined && upOriginal.pointerId !== pointerId) {
                    return;
                }

                $(document).off('.aiAssistantResize');
                $container.removeClass('is-resizing');
                $('body').removeClass('ai-assistant-panel-resizing');

                if (handle.releasePointerCapture && pointerId !== undefined) {
                    try {
                        handle.releasePointerCapture(pointerId);
                    } catch (error) {
                        // Pointer capture may already be released after pointer cancellation.
                    }
                }
            });

            event.preventDefault();
        },

        toggleAssistantPanelMax: function($container) {
            if (!$container.length) {
                return;
            }

            var limits = this.getAssistantPanelResizeLimits($container);
            var currentHeight = this.readAssistantPanelHeight($container, limits);
            var defaultHeight = this.getAssistantPanelDefaultHeight(limits);
            var targetHeight = currentHeight >= limits.max - 2 ? defaultHeight : limits.max;

            this.applyAssistantPanelHeight($container, targetHeight, limits);
            this.scrollToBottom();
        },

        handleAssistantPanelResizeKeydown: function(event, handle) {
            var $container = $(handle).closest('.ai-assistant-chat-container');
            if (!$container.length) {
                return;
            }

            var limits = this.getAssistantPanelResizeLimits($container);
            var currentHeight = this.readAssistantPanelHeight($container, limits);
            var step = event.shiftKey ? 60 : 24;
            var targetHeight = null;

            switch (event.key) {
                case 'ArrowDown':
                    targetHeight = currentHeight + step;
                    break;
                case 'ArrowUp':
                    targetHeight = currentHeight - step;
                    break;
                case 'PageDown':
                    targetHeight = currentHeight + 120;
                    break;
                case 'PageUp':
                    targetHeight = currentHeight - 120;
                    break;
                case 'Home':
                    targetHeight = limits.min;
                    break;
                case 'End':
                    targetHeight = limits.max;
                    break;
                case 'Enter':
                case ' ':
                case 'Spacebar':
                    this.toggleAssistantPanelMax($container);
                    event.preventDefault();
                    return;
                default:
                    return;
            }

            this.applyAssistantPanelHeight($container, targetHeight, limits);
            this.scrollToBottom();
            event.preventDefault();
        },

        clampAssistantPanelHeight: function() {
            var self = this;

            $('.ai-assistant-chat-container').each(function() {
                var $container = $(this);
                if (!$container.find('#ai-assistant-expand').length) {
                    return;
                }

                var limits = self.getAssistantPanelResizeLimits($container);
                self.applyAssistantPanelHeight($container, self.readAssistantPanelHeight($container, limits), limits);
            });
        },

        truncateFromUserMessage: function(content, timestamp) {
            // Find the selected user message and truncate everything from there.
            var msgIndex = -1;
            timestamp = parseInt(timestamp, 10);
            if (Number.isFinite(timestamp) && timestamp > 0) {
                for (var i = this.messages.length - 1; i >= 0; i--) {
                    var timestampedMsg = this.messages[i];
                    if (timestampedMsg.role === 'user' && parseInt(timestampedMsg._ts, 10) === timestamp &&
                        (timestampedMsg.content === content ||
                        (Array.isArray(timestampedMsg.content) && timestampedMsg.content.some(function(b) { return b.type === 'text' && b.text === content; })))) {
                        msgIndex = i;
                        break;
                    }
                }
            }

            if (msgIndex < 0) {
                for (var j = this.messages.length - 1; j >= 0; j--) {
                    var msg = this.messages[j];
                    if (msg.role === 'user' && (msg.content === content ||
                        (Array.isArray(msg.content) && msg.content.some(function(b) { return b.type === 'text' && b.text === content; })))) {
                        msgIndex = j;
                        break;
                    }
                }
            }
            if (msgIndex < 0) return;
            this.messages = this.messages.slice(0, msgIndex);
            this.rebuildMessagesUI();
            this.updateSummarizeVisibility();
            if (this.updateExportButton) {
                this.updateExportButton();
            }
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        escapeAttribute: function(text) {
            return this.escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        },

        onToolCall: function(criteria, callback) {
            var self = this;

            if (typeof criteria === 'function' && callback === undefined) {
                callback = criteria;
                criteria = {};
            }

            if (typeof callback !== 'function') {
                console.warn('[AI Assistant] Tool call callback registration requires a callback function.');
                return function() {};
            }

            var subscription = {
                id: this.nextToolCallSubscriptionId++,
                criteria: criteria || {},
                callback: callback
            };

            this.toolCallSubscriptions.push(subscription);

            return function() {
                self.offToolCall(subscription.id);
            };
        },

        registerToolCallCallback: function(criteria, callback) {
            return this.onToolCall(criteria, callback);
        },

        offToolCall: function(idOrCallback) {
            var originalLength = this.toolCallSubscriptions.length;
            this.toolCallSubscriptions = this.toolCallSubscriptions.filter(function(subscription) {
                if (typeof idOrCallback === 'function') {
                    return subscription.callback !== idOrCallback;
                }
                return subscription.id !== idOrCallback;
            });
            return this.toolCallSubscriptions.length !== originalLength;
        },

        notifyToolCallCallbacks: function(result, provider) {
            var self = this;
            var subscriptions = (this.toolCallSubscriptions || []).slice();

            if (!subscriptions.length || !result) {
                return;
            }

            var context = {
                id: result.id,
                name: result.name,
                tool: result.name,
                arguments: result.input || {},
                input: result.input || {},
                result: result.result,
                output: result.result,
                success: !!result.success,
                provider: provider || this.currentProvider || ''
            };

            subscriptions.forEach(function(subscription) {
                var matches = false;

                try {
                    matches = self.matchesToolCallCriteria(subscription.criteria, context);
                } catch (e) {
                    console.error('[AI Assistant] Tool call callback criteria failed:', e);
                    return;
                }

                if (!matches) {
                    return;
                }

                try {
                    var callbackResult = subscription.callback(context);
                    if (callbackResult && typeof callbackResult.then === 'function') {
                        callbackResult.catch(function(error) {
                            console.error('[AI Assistant] Tool call callback failed:', error);
                        });
                    }
                } catch (e) {
                    console.error('[AI Assistant] Tool call callback failed:', e);
                }
            });
        },

        matchesToolCallCriteria: function(criteria, context) {
            if (!criteria) {
                return true;
            }

            if (typeof criteria === 'function') {
                return !!criteria(context);
            }

            if (typeof criteria === 'string') {
                return context.name === criteria;
            }

            if (typeof criteria !== 'object') {
                return false;
            }

            if (criteria.tool && context.name !== criteria.tool) {
                return false;
            }

            if (criteria.name && context.name !== criteria.name) {
                return false;
            }

            if (Object.prototype.hasOwnProperty.call(criteria, 'success') && !!criteria.success !== context.success) {
                return false;
            }

            if (criteria.provider && context.provider !== criteria.provider) {
                return false;
            }

            if (criteria.ability && !this.matchesAbilityToolCall(criteria.ability, context)) {
                return false;
            }

            if (criteria.action && (context.arguments || {}).action !== criteria.action) {
                return false;
            }

            if (criteria.arguments && !this.matchesPartialObject(criteria.arguments, context.arguments || {})) {
                return false;
            }

            if (criteria.input && !this.matchesPartialObject(criteria.input, context.input || {})) {
                return false;
            }

            if (criteria.result && !this.matchesPartialObject(criteria.result, context.result || {})) {
                return false;
            }

            return true;
        },

        matchesAbilityToolCall: function(ability, context) {
            var args = context.arguments || {};

            if (context.name === 'ability') {
                return args.action === 'execute' && args.ability === ability;
            }

            if (context.name === 'execute_ability') {
                return args.ability === ability;
            }

            return false;
        },

        matchesPartialObject: function(expected, actual) {
            var self = this;

            if (typeof expected === 'function') {
                return !!expected(actual);
            }

            if (!expected || typeof expected !== 'object') {
                return expected === actual;
            }

            if (!actual || typeof actual !== 'object') {
                return false;
            }

            if (Array.isArray(expected)) {
                return JSON.stringify(expected) === JSON.stringify(actual);
            }

            return Object.keys(expected).every(function(key) {
                var expectedValue = expected[key];
                var actualValue = actual[key];

                if (
                    expectedValue &&
                    typeof expectedValue === 'object' &&
                    !Array.isArray(expectedValue)
                ) {
                    return self.matchesPartialObject(expectedValue, actualValue);
                }

                if (Array.isArray(expectedValue)) {
                    return JSON.stringify(expectedValue) === JSON.stringify(actualValue);
                }

                return actualValue === expectedValue;
            });
        },

        stripReasoningTokens: function(text) {
            if (!text) return text;
            // Strip [THINK]...[/THINK] blocks (Ministral and similar reasoning models)
            var stripped = text.replace(/\[THINK\][\s\S]*?\[\/THINK\]/gi, '');
            // Strip <think>...</think> blocks (DeepSeek and similar)
            stripped = stripped.replace(/<think>[\s\S]*?<\/think>/gi, '');
            // Strip incomplete [THINK]... at the start (truncated response)
            stripped = stripped.replace(/^\s*\[THINK\][\s\S]*$/gi, '');
            // Strip incomplete <think>... at the start (truncated response)
            stripped = stripped.replace(/^\s*<think>[\s\S]*$/gi, '');
            return stripped.trim();
        },


        setupAjaxErrorTracking: function() {
            var self = this;

            $(document).ajaxSuccess(function(event, xhr, settings) {
                if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
                    self.hideRecoveryMessage();
                }
            });

            $(document).ajaxError(function(event, xhr, settings) {
                if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
                    self.consecutiveAjaxErrors++;

                    if (self.consecutiveAjaxErrors >= self.ajaxErrorThreshold && !self.recoveryMessageShown) {
                        self.showRecoveryMessage();
                    }
                }
            });
        },

        showRecoveryMessage: function() {
            this.recoveryMessageShown = true;
            this.setLoading(false);

            var message = '**WordPress may be broken** due to a recent file change.\n\n' +
                'Multiple requests have failed, which often indicates a PHP syntax error.\n\n' +
                'This page still works because it was already loaded, but navigating to any other WordPress page will likely fail. ' +
                'You can try navigating to confirm, but first remember how to recover:\n\n' +
                'Click the [[GRID_ICON]] grid icon in the top bar and use **Recovery Mode** to restore the last working state.';

            this.addMessage('error', message, 'ai-recovery-message');

            var gridIcon = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="18" height="18" style="vertical-align: text-bottom; display: inline-block;">' +
                '<path d="M6 5.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5H6a.5.5 0 01-.5-.5V6a.5.5 0 01.5-.5zM4 6a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm11-.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5h-3a.5.5 0 01-.5-.5V6a.5.5 0 01.5-.5zM13 6a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2h-3a2 2 0 01-2-2V6zm5 8.5h-3a.5.5 0 00-.5.5v3a.5.5 0 00.5.5h3a.5.5 0 00.5-.5v-3a.5.5 0 00-.5-.5zM15 13a2 2 0 00-2 2v3a2 2 0 002 2h3a2 2 0 002-2v-3a2 2 0 00-2-2h-3zm-9 1.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5H6a.5.5 0 01-.5-.5v-3a.5.5 0 01.5-.5zM4 15a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2H6a2 2 0 01-2-2v-3z" fill-rule="evenodd" clip-rule="evenodd" fill="currentColor"></path>' +
                '</svg>';

            var $lastError = $('#ai-assistant-messages .ai-recovery-message').last();
            if (!$lastError.length) {
                return;
            }

            var html = $lastError.find('.ai-message-content').html();
            $lastError.find('.ai-message-content').html(html.replace('[[GRID_ICON]]', gridIcon));

            $lastError[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        getRecoveryMessages: function() {
            var $messages = $('#ai-assistant-messages .ai-recovery-message');
            var $legacyMessages = $('#ai-assistant-messages .ai-message-error').filter(function() {
                return $(this).text().indexOf('WordPress may be broken') !== -1;
            });

            return $messages.add($legacyMessages);
        },

        hasRecoveryMessage: function() {
            return this.recoveryMessageShown || this.getRecoveryMessages().length > 0;
        },

        hideRecoveryMessage: function() {
            this.consecutiveAjaxErrors = 0;
            this.recoveryMessageShown = false;

            var $messages = this.getRecoveryMessages();
            if (!$messages.length) {
                return;
            }

            $messages.stop(true, true).slideUp(150, function() {
                $(this).remove();
            });
        },

        checkWordPressRecovery: function() {
            if (!this.hasRecoveryMessage() || this.wordpressRecoveryCheckInProgress) {
                return;
            }

            if (!window.aiAssistantConfig || !aiAssistantConfig.ajaxUrl || !aiAssistantConfig.nonce) {
                return;
            }

            var self = this;
            this.wordpressRecoveryCheckInProgress = true;

            $.ajax({
                url: aiAssistantConfig.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ai_assistant_wpok',
                    _wpnonce: aiAssistantConfig.nonce
                },
                success: function(response) {
                    if (response && response.success) {
                        self.hideRecoveryMessage();
                    }
                },
                complete: function() {
                    self.wordpressRecoveryCheckInProgress = false;
                }
            });
        }
    });

    window.aiAssistantToolCallbacks = [];
    window.aiAssistantToolCallbacks.push = function() {
        for (var i = 0; i < arguments.length; i++) {
            var entry = arguments[i];
            if (entry && typeof entry === 'object') {
                window.aiAssistant.onToolCall(entry.criteria || entry.match || {}, entry.callback || entry.handler);
            }
        }
        return Array.prototype.push.apply(this, arguments);
    };
    window.aiAssistantOnToolCall = function(criteria, callback) {
        return window.aiAssistant.onToolCall(criteria, callback);
    };

    queuedToolCallbacks.forEach(function(entry) {
        window.aiAssistantToolCallbacks.push(entry);
    });

    $(document).ready(function() {
        if (window.aiAssistantBootstrap && window.aiAssistantBootstrap.deferInit) {
            return;
        }

        window.aiAssistant.init();
    });

})(jQuery);
