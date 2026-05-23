(function($) {
    'use strict';

    function getConfig() {
        return window.aiAssistantBootstrap || {};
    }

    function getStrings() {
        var config = getConfig();
        return config.strings || {};
    }

    function getUrls() {
        var config = getConfig();
        return config.urls || {};
    }

    function text(key, fallback) {
        var strings = getStrings();
        return strings[key] || fallback || '';
    }

    function url(key) {
        var urls = getUrls();
        return urls[key] || '#';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function(char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }

    function resizeHandle() {
        return '<div id="ai-assistant-expand" class="ai-assistant-resize-handle" role="separator" tabindex="0" aria-controls="ai-assistant-messages" aria-orientation="horizontal" aria-valuemin="300" aria-valuemax="900" aria-valuenow="380" aria-label="' + escapeAttr(text('resizePanel', 'Resize AI Assistant')) + '" title="' + escapeAttr(text('resizePanelTitle', 'Drag to resize AI Assistant. Double-click to fit the window.')) + '">' +
            '<span class="ai-resize-grip" aria-hidden="true"></span>' +
            '</div>';
    }

    function attachIcon() {
        return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
            '<path d="M21.44 11.05l-8.49 8.49a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.19 9.19a2 2 0 01-2.83-2.83l8.49-8.49"/>' +
            '</svg>';
    }

    function buildPanelHtml() {
        return '<div id="ai-assistant-wrap" class="hidden" tabindex="-1" aria-label="' + escapeAttr(text('ariaLabel', 'AI Assistant Tab')) + '">' +
            '<div id="ai-assistant-columns">' +
                '<div class="ai-assistant-chat-container">' +
                    '<div class="ai-assistant-header">' +
                        '<h2>' + escapeHtml(text('title', 'AI Assistant')) + '</h2>' +
                        '<div class="ai-assistant-header-actions">' +
                            '<div id="ai-token-count" class="ai-token-count" tabindex="0" aria-label="' + escapeAttr(text('tokenCountTitle', 'Token usage')) + '">0 tokens</div>' +
                            '<span class="ai-header-sep">|</span>' +
                            '<label class="ai-yolo-label" title="' + escapeAttr(text('yoloTitle', 'Skip confirmation prompts for destructive actions')) + '">' +
                                '<input type="checkbox" id="ai-assistant-yolo"> ' + escapeHtml(text('yoloMode', 'YOLO Mode')) +
                            '</label>' +
                            '<span class="ai-header-sep">|</span>' +
                            '<a href="#" id="ai-assistant-new-chat" class="ai-header-link">' + escapeHtml(text('newChat', 'New Chat')) + '</a>' +
                            '<span class="ai-header-sep">|</span>' +
                            '<a href="' + escapeAttr(url('history')) + '" class="ai-header-link">' + escapeHtml(text('history', 'Conversations')) + '</a>' +
                            '<span class="ai-header-sep">|</span>' +
                            '<a href="' + escapeAttr(url('settings')) + '" class="ai-header-link">' + escapeHtml(text('settings', 'Settings')) + '</a>' +
                        '</div>' +
                    '</div>' +
                    '<div id="ai-assistant-messages"></div>' +
                    '<div class="ai-assistant-drop-zone" aria-hidden="true">' +
                        '<div class="ai-assistant-drop-zone-inner">' + escapeHtml(text('dropFiles', 'Drop files to attach')) + '</div>' +
                    '</div>' +
                    '<button type="button" id="ai-assistant-scroll-bottom" title="' + escapeAttr(text('scrollBottom', 'Scroll to bottom')) + '" style="display:none">&#8595;</button>' +
                    '<div id="ai-assistant-loading" style="display: none;">' +
                        '<div class="ai-loading-dots"><span></span><span></span><span></span></div>' +
                        '<div class="ai-loading-status" aria-live="polite" style="display: none;"></div>' +
                    '</div>' +
                    '<div id="ai-assistant-pending-actions"></div>' +
                    '<div id="ai-assistant-attachments" class="ai-assistant-attachments"></div>' +
                    '<div class="ai-assistant-input-area">' +
                        '<input type="file" id="ai-assistant-file-input" multiple hidden>' +
                        '<button type="button" id="ai-assistant-attach" class="button" title="' + escapeAttr(text('attachFiles', 'Attach files')) + '">' + attachIcon() + '</button>' +
                        '<textarea id="ai-assistant-input" placeholder="' + escapeAttr(text('placeholder', 'Ask me anything about your WordPress site...')) + '" rows="2"></textarea>' +
                        '<button type="button" id="ai-assistant-send" class="button button-primary">' + escapeHtml(text('send', 'Send')) + '</button>' +
                        '<button type="button" id="ai-assistant-stop" class="button" style="display: none;" title="' + escapeAttr(text('stopGeneration', 'Stop generation')) + '"></button>' +
                    '</div>' +
                    resizeHandle() +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function buildStandaloneHtml() {
        return '<div id="ai-assistant-standalone-wrap" class="ai-assistant-standalone-wrap" style="display: none;">' +
            '<div id="ai-assistant-standalone-panel" class="ai-assistant-standalone-panel">' +
                buildPanelHtml() +
            '</div>' +
            '<div class="ai-assistant-standalone-links">' +
                '<div id="ai-assistant-standalone-trigger" class="ai-assistant-standalone-trigger hide-if-no-js">' +
                    '<button type="button" aria-controls="ai-assistant-standalone-panel" aria-expanded="false">' +
                        escapeHtml(text('buttonText', 'AI Assistant')) +
                    '</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function focusInputAndScroll() {
        setTimeout(function() {
            $('#ai-assistant-input').trigger('focus');
            if (window.aiAssistant && typeof window.aiAssistant.scrollToBottom === 'function') {
                window.aiAssistant.scrollToBottom(true);
            }
            if (window.aiAssistant && typeof window.aiAssistant.syncModelSelectWidths === 'function') {
                window.aiAssistant.syncModelSelectWidths();
            }
        }, 50);
    }

    function getAdminbarOffset() {
        var $masterbar = $('#wpadminbar');
        return $masterbar.length && $masterbar.is(':visible') ? $masterbar.outerHeight() : 0;
    }

    function preloadConversationIfNeeded() {
        if (!window.aiAssistant) {
            return;
        }

        if (typeof window.aiAssistant.preloadMostRecentConversation === 'function') {
            window.aiAssistant.preloadMostRecentConversation();
        } else if ($('#ai-assistant-messages').children().length === 0 && typeof window.aiAssistant.loadMostRecentConversation === 'function') {
            window.aiAssistant.loadMostRecentConversation();
        }
    }

    function getScreenMetaPanel($button) {
        return $('#' + $button.attr('aria-controls'));
    }

    function closeScreenMetaPanel($panel, $button) {
        if (window.screenMeta && typeof window.screenMeta.close === 'function') {
            window.screenMeta.close($panel, $button);
            return;
        }

        $panel.slideUp('fast', function() {
            $button.removeClass('screen-meta-active').attr('aria-expanded', 'false');
            $('.screen-meta-toggle').css('visibility', '');
            $screenMetaSafe($panel).hide();
            $panel.addClass('hidden');
        });
    }

    function hideSiblingScreenMetaPanel($panel, $button) {
        $panel.stop(true, true).slideUp('fast', function() {
            $panel.addClass('hidden');
        });
        $button.removeClass('screen-meta-active').attr('aria-expanded', 'false');
    }

    function openScreenMetaPanel($panel, $button, onOpen) {
        if (window.screenMeta && typeof window.screenMeta.open === 'function') {
            window.screenMeta.open($panel, $button);
            if (typeof onOpen === 'function') {
                onOpen();
            }
            return;
        }

        $('#screen-meta-links').find('.screen-meta-toggle').not($button.parent()).css('visibility', 'hidden');
        $screenMetaSafe($panel).show();
        $panel.slideDown('fast', function() {
            $panel.removeClass('hidden').trigger('focus');
            $button.addClass('screen-meta-active').attr('aria-expanded', 'true');
            if (typeof onOpen === 'function') {
                onOpen();
            }
        });
    }

    function $screenMetaSafe($panel) {
        var $parent = $panel.parent();
        return $parent.length ? $parent : $('#screen-meta');
    }

    function bindScreenMeta($screenMeta, $button) {
        $('#contextual-help-link, #show-settings-link')
            .off('click.aiAssistantBootstrap')
            .on('click.aiAssistantBootstrap', function() {
                var $wrap = getScreenMetaPanel($('#ai-assistant-link'));
                var $aiButton = $('#ai-assistant-link');
                if ($aiButton.attr('aria-expanded') === 'true' || $aiButton.hasClass('screen-meta-active')) {
                    hideSiblingScreenMetaPanel($wrap, $aiButton);
                }
            });

        $button.off('click.aiAssistantBootstrap').on('click.aiAssistantBootstrap', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var $clicked = $(this);
            var $wrap = getScreenMetaPanel($clicked);
            var isExpanded = $clicked.attr('aria-expanded') === 'true';

            $screenMeta.find('> div').not($wrap).filter(':visible').each(function() {
                var $panel = $(this);
                var $otherButton = $('.screen-meta-toggle button[aria-controls="' + $panel.attr('id') + '"]');
                hideSiblingScreenMetaPanel($panel, $otherButton);
            });

            if (isExpanded) {
                closeScreenMetaPanel($wrap, $clicked);
            } else {
                openScreenMetaPanel($wrap, $clicked, function() {
                    focusInputAndScroll();
                    preloadConversationIfNeeded();
                });
            }
        });
    }

    function bindStandalone($wrap, options) {
        options = options || {};

        var $panel = $wrap.find('#ai-assistant-standalone-panel');
        var $trigger = $wrap.find('#ai-assistant-standalone-trigger');
        var $button = $trigger.find('button');
        var updateStandalonePosition = function() {
            $wrap.css('--ai-assistant-adminbar-offset', getAdminbarOffset() + 'px');

            if (options.alignToScreenMeta) {
                var $screenMetaLinks = $('#screen-meta-links');
                var screenMetaLinksWidth = $screenMetaLinks.length ? $screenMetaLinks.outerWidth() : 0;
                $wrap.css('--ai-assistant-screen-meta-links-width', screenMetaLinksWidth + 'px');
            }
        };
        var closePanel = function() {
            if ($button.attr('aria-expanded') !== 'true') {
                return;
            }

            $panel.slideUp('fast');
            $button.attr('aria-expanded', 'false');
        };
        var closeScreenMetaPanels = function() {
            var $screenMeta = $('#screen-meta');
            if (!$screenMeta.length) {
                return;
            }

            $('#screen-meta-links').find('.screen-meta-toggle').css('visibility', '');
            $('#screen-meta-links').find('.screen-meta-toggle button')
                .removeClass('screen-meta-active')
                .attr('aria-expanded', 'false');

            var $visiblePanels = $screenMeta.find('> div:visible');
            if (!$visiblePanels.length) {
                $screenMeta.hide();
                return;
            }

            var remainingPanels = $visiblePanels.length;
            $visiblePanels.stop(true, true).slideUp('fast', function() {
                $(this).addClass('hidden');
                remainingPanels--;
                if (remainingPanels === 0) {
                    $screenMeta.hide();
                }
            });
        };

        if (options.alignToScreenMeta) {
            $wrap.addClass('ai-assistant-admin-screen-meta');
        }

        $wrap.appendTo(document.body);
        updateStandalonePosition();
        $(window).off('resize.aiAssistantStandalone').on('resize.aiAssistantStandalone', updateStandalonePosition);

        $wrap.show();
        $('#ai-assistant-wrap').removeClass('hidden');

        $button.off('click.aiAssistantBootstrap').on('click.aiAssistantBootstrap', function() {
            var isExpanded = $button.attr('aria-expanded') === 'true';

            if (isExpanded) {
                closePanel();
            } else {
                updateStandalonePosition();
                if (options.alignToScreenMeta) {
                    closeScreenMetaPanels();
                }
                $panel.slideDown('fast', focusInputAndScroll);
                $button.attr('aria-expanded', 'true');
                preloadConversationIfNeeded();
            }
        });

        if (options.alignToScreenMeta) {
            $('#screen-meta-links .screen-meta-toggle button')
                .off('click.aiAssistantStandaloneScreenMeta')
                .on('click.aiAssistantStandaloneScreenMeta', closePanel);
        }

        $(document).off('keydown.aiAssistantStandalone').on('keydown.aiAssistantStandalone', function(e) {
            if (e.key === 'Escape' && $button.attr('aria-expanded') === 'true') {
                closePanel();
            }
        });
    }

    function render() {
        var config = getConfig();
        if (!config.renderLatch) {
            return false;
        }

        if (
            document.getElementById('ai-assistant-wrap') ||
            document.getElementById('ai-assistant-standalone-wrap') ||
            document.getElementById('ai-assistant-link-wrap') ||
            document.getElementById('ai-assistant-input')
        ) {
            return false;
        }

        var $screenMetaLinks = $('#screen-meta-links');
        var $screenMeta = $('#screen-meta');
        var hasScreenMeta = $screenMetaLinks.length > 0 && $screenMeta.length > 0;
        var $standaloneWrap = $(buildStandaloneHtml());

        if (hasScreenMeta) {
            bindStandalone($standaloneWrap, { alignToScreenMeta: true });
        } else {
            bindStandalone($standaloneWrap);
        }

        return true;
    }

    function init() {
        if (!window.aiAssistant || typeof window.aiAssistant.init !== 'function') {
            return false;
        }

        if (window.aiAssistant.initialized) {
            return true;
        }

        if (!document.getElementById('ai-assistant-input')) {
            return false;
        }

        window.aiAssistant.init();
        return true;
    }

    function renderAndInit() {
        render();
        init();
    }

    window.aiAssistantBootstrapRuntime = {
        render: render,
        init: init,
        renderAndInit: renderAndInit
    };

    $(document).ready(renderAndInit);

})(jQuery);
