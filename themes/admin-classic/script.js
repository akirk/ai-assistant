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
        return '<div id="ai-assistant-expand" class="ai-assistant-resize-handle" role="separator" tabindex="0" aria-controls="ai-assistant-messages" aria-orientation="horizontal" aria-valuemin="300" aria-valuemax="900" aria-valuenow="380" aria-label="' + escapeAttr(text('resizePanel', 'Resize AI Assistant')) + '" title="' + escapeAttr(text('resizePanelTitle', 'Drag to resize AI Assistant. Double-click to toggle maximum size.')) + '"></div>';
    }

    function usesCornerResizeHandle() {
        var config = getConfig();
        return config.theme && config.theme.id === 'floating-button';
    }

    function cornerResizeHandle() {
        if (!usesCornerResizeHandle()) {
            return '';
        }

        return '<div id="ai-assistant-corner-resize" class="ai-assistant-corner-resize" role="separator" tabindex="0" aria-controls="ai-assistant-messages" aria-orientation="horizontal" aria-label="' + escapeAttr(text('resizePanel', 'Resize AI Assistant')) + '" title="' + escapeAttr(text('resizePanelTitle', 'Drag to resize AI Assistant.')) + '">' +
            '<span aria-hidden="true"></span>' +
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
                            '<label class="ai-auto-approve-label" title="' + escapeAttr(text('autoApproveTitle', text('yoloTitle', 'Automatically approve destructive actions without confirmation'))) + '">' +
                                '<input type="checkbox" id="ai-assistant-auto-approve"> ' + escapeHtml(text('autoApproveMode', text('yoloMode', 'Auto-approve'))) +
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
                    cornerResizeHandle() +
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

        var preload = function() {
            if (window.aiAssistant && typeof window.aiAssistant.preloadMostRecentConversation === 'function') {
                window.aiAssistant.preloadMostRecentConversation();
            } else if (
                window.aiAssistant &&
                $('#ai-assistant-messages').children().length === 0 &&
                typeof window.aiAssistant.loadMostRecentConversation === 'function'
            ) {
                window.aiAssistant.loadMostRecentConversation();
            }
        };

        if (typeof window.aiAssistant.ensureProviderConfigLoaded === 'function') {
            window.aiAssistant.ensureProviderConfigLoaded().then(preload, preload);
            return;
        }

        preload();
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
                $panel.promise('fx').done(onOpen);
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

    function setScreenMetaAssistantSticky($screenMeta, enabled) {
        if (!$screenMeta || !$screenMeta.length) {
            return;
        }

        $screenMeta.toggleClass('ai-assistant-screen-meta-sticky', !!enabled);
        if (enabled) {
            $screenMeta.css('--ai-assistant-adminbar-offset', getAdminbarOffset() + 'px');
        } else {
            $screenMeta[0].style.removeProperty('--ai-assistant-adminbar-offset');
        }
    }

    function getScreenMetaAssistantLatchRight($linkWrap) {
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var wasSticky = $linkWrap.hasClass('ai-assistant-link-sticky');
        var rect;

        if (wasSticky) {
            $linkWrap.removeClass('ai-assistant-link-sticky');
        }

        rect = $linkWrap[0].getBoundingClientRect();

        if (wasSticky) {
            $linkWrap.addClass('ai-assistant-link-sticky');
        }

        return Math.max(0, viewportWidth - rect.right);
    }

    function setScreenMetaAssistantLatchSticky($screenMeta, $button, enabled, attachToPanel) {
        var $linkWrap = $button.closest('#ai-assistant-link-wrap');
        var adminbarOffset;
        var panelHeight;

        if (!$linkWrap.length) {
            return;
        }

        if (!enabled) {
            $linkWrap.removeClass('ai-assistant-link-sticky');
            $linkWrap[0].style.removeProperty('--ai-assistant-adminbar-offset');
            $linkWrap[0].style.removeProperty('--ai-assistant-link-top');
            $linkWrap[0].style.removeProperty('--ai-assistant-link-right');
            return;
        }

        adminbarOffset = getAdminbarOffset();
        panelHeight = attachToPanel && $screenMeta && $screenMeta.length && $screenMeta.is(':visible') ? $screenMeta.outerHeight() : 0;

        $linkWrap
            .css({
                '--ai-assistant-adminbar-offset': adminbarOffset + 'px',
                '--ai-assistant-link-top': Math.max(adminbarOffset, adminbarOffset + panelHeight - 1) + 'px',
                '--ai-assistant-link-right': getScreenMetaAssistantLatchRight($linkWrap) + 'px'
            })
            .addClass('ai-assistant-link-sticky');
    }

    function bindScreenMeta($screenMeta, $button) {
        setScreenMetaAssistantLatchSticky($screenMeta, $button, true, false);

        $('#contextual-help-link, #show-settings-link')
            .off('click.aiAssistantBootstrap')
            .on('click.aiAssistantBootstrap', function() {
                var $wrap = getScreenMetaPanel($('#ai-assistant-link'));
                var $aiButton = $('#ai-assistant-link');
                if ($aiButton.attr('aria-expanded') === 'true' || $aiButton.hasClass('screen-meta-active')) {
                    setScreenMetaAssistantLatchSticky($screenMeta, $button, true, false);
                    setScreenMetaAssistantSticky($screenMeta, false);
                    hideSiblingScreenMetaPanel($wrap, $aiButton);
                }
            });

        $(window).off('resize.aiAssistantScreenMeta').on('resize.aiAssistantScreenMeta', function() {
            if ($button.attr('aria-expanded') === 'true') {
                setScreenMetaAssistantSticky($screenMeta, true);
                setScreenMetaAssistantLatchSticky($screenMeta, $button, true, true);
            } else {
                setScreenMetaAssistantLatchSticky($screenMeta, $button, true, false);
            }
        });

        $(document).off('aiAssistantPanelHeightChange.aiAssistantScreenMeta').on('aiAssistantPanelHeightChange.aiAssistantScreenMeta', function() {
            if ($button.attr('aria-expanded') === 'true') {
                setScreenMetaAssistantLatchSticky($screenMeta, $button, true, true);
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
                setScreenMetaAssistantLatchSticky($screenMeta, $clicked, true, false);
                setScreenMetaAssistantSticky($screenMeta, false);
                closeScreenMetaPanel($wrap, $clicked);
            } else {
                setScreenMetaAssistantSticky($screenMeta, true);
                openScreenMetaPanel($wrap, $clicked, function() {
                    setScreenMetaAssistantLatchSticky($screenMeta, $clicked, true, true);
                    focusInputAndScroll();
                    preloadConversationIfNeeded();
                });
            }
        });
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

        bindCornerResize($wrap);

        $(document).off('keydown.aiAssistantStandalone').on('keydown.aiAssistantStandalone', function(e) {
            if (e.key === 'Escape' && $button.attr('aria-expanded') === 'true') {
                $button.trigger('click');
            }
        });
    }

    function getCornerResizeLimits($wrap, $container) {
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var adminbarOffset = parseFloat($wrap.css('--ai-assistant-adminbar-offset')) || 0;
        var right = parseFloat($wrap.css('right')) || 0;
        var bottom = parseFloat($wrap.css('bottom')) || 0;
        var maxWidth = Math.max(320, viewportWidth - right - 16);
        var maxHeight = Math.max(280, viewportHeight - adminbarOffset - bottom - 72);

        return {
            minWidth: 360,
            maxWidth: Math.max(360, maxWidth),
            minHeight: 360,
            maxHeight: Math.max(360, maxHeight),
            currentWidth: $wrap.outerWidth(),
            currentHeight: $container.outerHeight()
        };
    }

    function applyCornerResize($wrap, $container, width, height, limits) {
        var nextWidth = Math.round(Math.max(limits.minWidth, Math.min(width, limits.maxWidth)));
        var nextHeight = Math.round(Math.max(limits.minHeight, Math.min(height, limits.maxHeight)));

        $wrap[0].style.setProperty('--ai-assistant-floating-width', nextWidth + 'px');
        if (window.aiAssistant && typeof window.aiAssistant.applyAssistantPanelHeight === 'function') {
            window.aiAssistant.applyAssistantPanelHeight($container, nextHeight, {
                min: limits.minHeight,
                max: limits.maxHeight
            });
        } else {
            $container[0].style.setProperty('--ai-assistant-chat-height', nextHeight + 'px');
        }
    }

    function bindCornerResize($wrap) {
        var $handle = $wrap.find('#ai-assistant-corner-resize');
        if (!$handle.length) {
            return;
        }

        $handle.off('pointerdown.aiAssistantBootstrap').on('pointerdown.aiAssistantBootstrap', function(event) {
            var originalEvent = event.originalEvent || event;
            if (originalEvent.button !== undefined && originalEvent.button !== 0) {
                return;
            }

            var handle = this;
            var $container = $wrap.find('.ai-assistant-chat-container');
            var limits = getCornerResizeLimits($wrap, $container);
            var startX = originalEvent.clientX;
            var startY = originalEvent.clientY;
            var startWidth = limits.currentWidth;
            var startHeight = limits.currentHeight;
            var pointerId = originalEvent.pointerId;

            $container.addClass('is-resizing');
            $('body').addClass('ai-assistant-panel-resizing ai-assistant-corner-resizing');

            if (handle.setPointerCapture && pointerId !== undefined) {
                try {
                    handle.setPointerCapture(pointerId);
                } catch (error) {}
            }

            $(document).off('.aiAssistantCornerResize');
            $(document).on('pointermove.aiAssistantCornerResize', function(moveEvent) {
                var moveOriginal = moveEvent.originalEvent || moveEvent;
                if (pointerId !== undefined && moveOriginal.pointerId !== undefined && moveOriginal.pointerId !== pointerId) {
                    return;
                }

                applyCornerResize(
                    $wrap,
                    $container,
                    startWidth + startX - moveOriginal.clientX,
                    startHeight + startY - moveOriginal.clientY,
                    limits
                );
                moveEvent.preventDefault();
            });

            $(document).on('pointerup.aiAssistantCornerResize pointercancel.aiAssistantCornerResize', function(upEvent) {
                var upOriginal = upEvent.originalEvent || upEvent;
                if (pointerId !== undefined && upOriginal.pointerId !== undefined && upOriginal.pointerId !== pointerId) {
                    return;
                }

                $(document).off('.aiAssistantCornerResize');
                $container.removeClass('is-resizing');
                $('body').removeClass('ai-assistant-panel-resizing ai-assistant-corner-resizing');

                if (handle.releasePointerCapture && pointerId !== undefined) {
                    try {
                        handle.releasePointerCapture(pointerId);
                    } catch (error) {}
                }
            });

            event.preventDefault();
        });

        $handle.off('keydown.aiAssistantBootstrap').on('keydown.aiAssistantBootstrap', function(event) {
            var $container = $wrap.find('.ai-assistant-chat-container');
            var limits = getCornerResizeLimits($wrap, $container);
            var step = event.shiftKey ? 60 : 24;
            var width = limits.currentWidth;
            var height = limits.currentHeight;

            switch (event.key) {
                case 'ArrowLeft':
                    width += step;
                    break;
                case 'ArrowRight':
                    width -= step;
                    break;
                case 'ArrowUp':
                    height += step;
                    break;
                case 'ArrowDown':
                    height -= step;
                    break;
                case 'Home':
                    width = limits.minWidth;
                    height = limits.minHeight;
                    break;
                case 'End':
                    width = limits.maxWidth;
                    height = limits.maxHeight;
                    break;
                default:
                    return;
            }

            applyCornerResize($wrap, $container, width, height, limits);
            event.preventDefault();
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
        var placement = config.theme && config.theme.placement ? config.theme.placement : 'auto';
        var hasScreenMeta = placement !== 'standalone' && $screenMetaLinks.length > 0 && $screenMeta.length > 0;
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
