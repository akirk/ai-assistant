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

    function expandIcon() {
        return '<svg class="ai-expand-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' +
            '<path d="M9.5 13.09l1.41 1.41-4.5 4.5H10v2H4v-6h2v3.59l4.5-4.5zm5-2.18L13.09 9.5l4.5-4.5H14v-2h6v6h-2V5.41l-4.5 4.5z"/>' +
            '</svg>';
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
                            '<span id="ai-token-count" class="ai-token-count" title="' + escapeAttr(text('tokenCountTitle', 'Estimated token usage')) + '">0 tokens</span>' +
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
                            '<span class="ai-header-sep">|</span>' +
                            '<button type="button" id="ai-assistant-expand" class="ai-header-link" title="' + escapeAttr(text('expand', 'Expand')) + '">' + expandIcon() + '</button>' +
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
        }, 50);
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

    function consumeOpenHash($button) {
        if (window.location.hash.indexOf('ai-open') === -1) {
            return;
        }

        history.replaceState(null, '', window.location.href.replace(/#.*ai-open.*$/, ''));
        $button.trigger('click');
    }

    function bindScreenMeta($screenMeta, $button) {
        $('#contextual-help-link, #show-settings-link')
            .off('click.aiAssistantBootstrap')
            .on('click.aiAssistantBootstrap', function() {
                var $wrap = $('#ai-assistant-wrap');
                if ($wrap.hasClass('screen-meta-active')) {
                    $wrap.slideUp('fast', function() {
                        $wrap.removeClass('screen-meta-active').addClass('hidden');
                    });
                    $('#ai-assistant-link').attr('aria-expanded', 'false');
                }
            });

        $button.off('click.aiAssistantBootstrap').on('click.aiAssistantBootstrap', function() {
            var $wrap = $('#ai-assistant-wrap');
            var $clicked = $(this);
            var isExpanded = $clicked.attr('aria-expanded') === 'true';

            $screenMeta.find('.screen-meta-active').not($wrap).slideUp('fast', function() {
                $(this).removeClass('screen-meta-active').addClass('hidden');
            });
            $('.screen-meta-toggle button').not($clicked).attr('aria-expanded', 'false');

            if (isExpanded) {
                $wrap.slideUp('fast', function() {
                    $wrap.removeClass('screen-meta-active').addClass('hidden');
                });
                $clicked.attr('aria-expanded', 'false');
            } else {
                $wrap.removeClass('hidden').addClass('screen-meta-active').slideDown('fast', focusInputAndScroll);
                $clicked.attr('aria-expanded', 'true');
                preloadConversationIfNeeded();
            }
        });

        consumeOpenHash($button);
    }

    function bindStandalone($wrap) {
        var $panel = $wrap.find('#ai-assistant-standalone-panel');
        var $trigger = $wrap.find('#ai-assistant-standalone-trigger');
        var $button = $trigger.find('button');
        var updateStandaloneOffset = function() {
            var $masterbar = $('#wpadminbar');
            var offset = $masterbar.length && $masterbar.is(':visible') ? $masterbar.outerHeight() : 0;
            $wrap.css('--ai-assistant-adminbar-offset', offset + 'px');
        };

        $wrap.appendTo(document.body);
        updateStandaloneOffset();
        $(window).off('resize.aiAssistantStandalone').on('resize.aiAssistantStandalone', updateStandaloneOffset);

        $wrap.show();
        $('#ai-assistant-wrap').removeClass('hidden');

        $button.off('click.aiAssistantBootstrap').on('click.aiAssistantBootstrap', function() {
            var isExpanded = $button.attr('aria-expanded') === 'true';

            if (isExpanded) {
                $panel.slideUp('fast');
                $button.attr('aria-expanded', 'false');
            } else {
                $panel.slideDown('fast', focusInputAndScroll);
                $button.attr('aria-expanded', 'true');
                preloadConversationIfNeeded();
            }
        });

        $(document).off('keydown.aiAssistantStandalone').on('keydown.aiAssistantStandalone', function(e) {
            if (e.key === 'Escape' && $button.attr('aria-expanded') === 'true') {
                $button.trigger('click');
            }
        });

        consumeOpenHash($button);
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
            $screenMetaLinks.prepend(
                '<div id="ai-assistant-link-wrap" class="hide-if-no-js screen-meta-toggle">' +
                    '<button type="button" id="ai-assistant-link" class="button show-settings" aria-controls="ai-assistant-wrap" aria-expanded="false">' +
                        escapeHtml(text('buttonText', 'AI Assistant')) +
                    '</button>' +
                '</div>'
            );
            $screenMeta.prepend($standaloneWrap.find('#ai-assistant-standalone-panel').html());
            bindScreenMeta($screenMeta, $('#ai-assistant-link'));
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
