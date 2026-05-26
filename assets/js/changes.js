(function($) {
    'use strict';

    var AiChanges = {
        commitOffset: 0,
        commitsLoaded: false,
        hasMoreCommits: false,

        init: function() {
            this.bindEvents();
            this.autoExpandFromUrl();
        },

        autoExpandFromUrl: function() {
            var $detailCards = $('.ai-changes-plugin-detail[data-plugin]');

            if ($detailCards.length) {
                return;
            }

            var params = new URLSearchParams(window.location.search);
            var pluginPath = params.get('plugin');

            if (pluginPath) {
                var $card = $('.ai-plugin-card[data-plugin="' + pluginPath + '"]');
                if ($card.length) {
                    var $content = $card.find('.ai-plugin-content');
                    var $toggle = $card.find('.ai-plugin-toggle');

                    $toggle.text('▼');
                    $content.show();

                    // Scroll to the card
                    $('html, body').animate({
                        scrollTop: $card.offset().top - 50
                    }, 300);
                }
            }
        },

        bindEvents: function() {
            var self = this;

            // Plugin card toggle
            $(document).on('click', '.ai-plugin-toggle', function(e) {
                e.preventDefault();
                var $card = $(this).closest('.ai-plugin-card');
                var $content = $card.find('.ai-plugin-content');
                var $toggle = $card.find('.ai-plugin-toggle');

                if ($card.hasClass('ai-plugin-card-detail') || !$content.length) {
                    return;
                }

                var willBeVisible = !$content.is(':visible');

                $toggle.text(willBeVisible ? '▼' : '▶');
                $content.slideToggle(200);
            });

            // Per-file preview toggle
            $(document).on('click', '.ai-file-preview-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleFilePreview($(this));
            });

            $(document).on('click', '.ai-changes-file-path', function(e) {
                e.preventDefault();
                self.toggleFilePreview($(this).closest('.ai-changes-file').find('.ai-file-preview-toggle'));
            });

            // Import patch - trigger file input
            $('#ai-import-patch').on('click', function() {
                $('#ai-patch-file').click();
            });

            // Handle patch file selection
            $('#ai-patch-file').on('change', function() {
                if (this.files && this.files[0]) {
                    self.importPatch(this.files[0]);
                    $(this).val('');
                }
            });

            // Checkout commit
            $(document).on('click', '.ai-checkout-commit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var sha = $(this).data('sha');
                self.checkoutCommit(sha, $(this));
            });

            // Commit diff toggle
            $(document).on('click', '.ai-commit-diff-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleCommitDiff($(this));
            });

            // Commit message edit
            $(document).on('click', '.ai-edit-commit-message', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.beginCommitMessageEdit($(this));
            });

            $(document).on('dblclick', '.ai-commit-message', function(e) {
                e.preventDefault();
                self.beginCommitMessageEdit($(this));
            });

            $(document).on('keydown', '.ai-commit-message', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.beginCommitMessageEdit($(this));
                }
            });

            $(document).on('click', '.ai-save-commit-message', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.saveCommitMessage($(this));
            });

            $(document).on('click', '.ai-cancel-commit-message', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.cancelCommitMessageEdit($(this));
            });

            $(document).on('keydown', '.ai-commit-message-input', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.saveCommitMessage($(this));
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    self.cancelCommitMessageEdit($(this));
                }
            });

            $(document).on('click', '.ai-lint-files', function(e) {
                e.preventDefault();
                self.checkPhpSyntax($(this));
            });
        },

        getPluginPath: function($element) {
            return $element.closest('[data-plugin]').data('plugin') || '';
        },

        toggleCommitDiff: function($toggle) {
            var self = this;
            var sha = $toggle.data('sha');
            var $preview = $('.ai-commit-diff-preview[data-sha="' + sha + '"]');
            var $code = $preview.find('code');
            var isVisible = $preview.is(':visible');

            if (isVisible) {
                $toggle.text('▶').removeClass('expanded');
                $preview.slideUp(200);
            } else {
                $toggle.text('▼').addClass('expanded');
                $preview.slideDown(200);

                if (!$code.html()) {
                    var pluginPath = self.getPluginPath($toggle);
                    $code.html('<span class="loading">' + (aiChanges.strings.loading || 'Loading...') + '</span>');
                    $.post(aiChanges.ajaxUrl, {
                        action: 'ai_assistant_get_commit_diff',
                        nonce: aiChanges.nonce,
                        plugin_path: pluginPath,
                        sha: sha
                    }, function(response) {
                        if (response.success) {
                            $code.html(self.highlightDiff(response.data.diff));
                        } else {
                            $code.html('<span class="error">Failed to load diff</span>');
                        }
                    }).fail(function() {
                        $code.html('<span class="error">Failed to load diff</span>');
                    });
                }
            }
        },

        checkoutCommit: function(sha, $button) {
            var pluginPath = this.getPluginPath($button);
            var originalText = $button.text();
            $button.text(aiChanges.strings.checkingOutCommit || 'Checking out...').prop('disabled', true);

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_checkout_commit',
                nonce: aiChanges.nonce,
                plugin_path: pluginPath,
                sha: sha
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || aiChanges.strings.checkoutCommitError || 'Failed to check out commit');
                    $button.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert(aiChanges.strings.checkoutCommitError || 'Failed to check out commit');
                $button.text(originalText).prop('disabled', false);
            });
        },

        beginCommitMessageEdit: function($trigger) {
            var self = this;
            var $rowTop = $trigger.closest('.ai-commit-row-top');
            var $message = $rowTop.find('.ai-commit-message');
            var $editor = $rowTop.find('.ai-commit-message-editor');
            var $input = $rowTop.find('.ai-commit-message-input');
            var currentMessage = $.trim($message.text());

            if ($rowTop.hasClass('ai-commit-message-editing')) {
                $input.trigger('focus');
                if ($input[0]) {
                    $input[0].select();
                }
                return;
            }

            $('.ai-commit-row-top.ai-commit-message-editing').not($rowTop).each(function() {
                self.cancelCommitMessageEdit($(this));
            });

            $rowTop
                .addClass('ai-commit-message-editing')
                .data('original-message', currentMessage);
            $message.attr('aria-hidden', 'true');
            $rowTop.find('.ai-edit-commit-message').attr('aria-hidden', 'true');
            $input.val(currentMessage).removeClass('ai-commit-message-input-error').prop('disabled', false);
            $editor.prop('hidden', false);
            $rowTop.find('.ai-save-commit-message, .ai-cancel-commit-message').prop('disabled', false);

            window.setTimeout(function() {
                $input.trigger('focus');
                if ($input[0]) {
                    $input[0].select();
                }
            }, 0);
        },

        saveCommitMessage: function($trigger) {
            var $rowTop = $trigger.closest('.ai-commit-row-top');
            var $input = $rowTop.find('.ai-commit-message-input');
            var originalMessage = $rowTop.data('original-message') || $.trim($rowTop.find('.ai-commit-message').text());
            var nextMessage = $.trim($input.val());
            var sha = $rowTop.closest('.ai-commit-entry').data('sha');

            if (!nextMessage) {
                $input.addClass('ai-commit-message-input-error').trigger('focus');
                return;
            }

            $input.removeClass('ai-commit-message-input-error');

            if (nextMessage === originalMessage) {
                this.cancelCommitMessageEdit($rowTop);
                return;
            }

            this.updateCommitMessage(sha, nextMessage, $rowTop);
        },

        cancelCommitMessageEdit: function($trigger) {
            var $rowTop = $trigger.hasClass('ai-commit-row-top') ? $trigger : $trigger.closest('.ai-commit-row-top');
            var originalMessage = $rowTop.data('original-message') || $.trim($rowTop.find('.ai-commit-message').text());

            $rowTop.removeClass('ai-commit-message-editing');
            $rowTop.find('.ai-commit-message').removeAttr('aria-hidden');
            $rowTop.find('.ai-edit-commit-message').removeAttr('aria-hidden').prop('disabled', false);
            $rowTop.find('.ai-commit-message-input')
                .val(originalMessage)
                .removeClass('ai-commit-message-input-error')
                .prop('disabled', false);
            $rowTop.find('.ai-commit-message-editor').prop('hidden', true);
            $rowTop.find('.ai-save-commit-message, .ai-cancel-commit-message').prop('disabled', false);
            $rowTop.find('.ai-save-commit-message').text(aiChanges.strings.saveCommitMessage || 'Save');
        },

        updateCommitMessage: function(sha, message, $rowTop) {
            var pluginPath = this.getPluginPath($rowTop);
            var $input = $rowTop.find('.ai-commit-message-input');
            var $save = $rowTop.find('.ai-save-commit-message');
            var originalSaveText = $save.text();

            $input.prop('disabled', true);
            $rowTop.find('.ai-save-commit-message, .ai-cancel-commit-message, .ai-edit-commit-message').prop('disabled', true);
            $save.text(aiChanges.strings.updatingCommitMessage || 'Saving...');

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_update_commit_message',
                nonce: aiChanges.nonce,
                plugin_path: pluginPath,
                sha: sha,
                message: message
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert((response.data && response.data.message) || aiChanges.strings.updateCommitMessageError || 'Failed to update commit message.');
                    $input.prop('disabled', false).trigger('focus');
                    $rowTop.find('.ai-save-commit-message, .ai-cancel-commit-message, .ai-edit-commit-message').prop('disabled', false);
                    $save.text(originalSaveText);
                }
            }).fail(function() {
                alert(aiChanges.strings.updateCommitMessageError || 'Failed to update commit message.');
                $input.prop('disabled', false).trigger('focus');
                $rowTop.find('.ai-save-commit-message, .ai-cancel-commit-message, .ai-edit-commit-message').prop('disabled', false);
                $save.text(originalSaveText);
            });
        },

        highlightDiff: function(diff) {
            var mode = wp.CodeMirror.getMode({}, 'diff');
            var container = document.createElement('pre');
            container.className = 'cm-s-default';
            wp.CodeMirror.runMode(diff, mode, container);
            return container.innerHTML;
        },

        highlightContent: function(content, path) {
            var modeName = this.getModeForPath(path);
            var container = document.createElement('pre');

            if (modeName && typeof wp !== 'undefined' && wp.CodeMirror && wp.CodeMirror.getMode && wp.CodeMirror.runMode) {
                try {
                    wp.CodeMirror.runMode(content, wp.CodeMirror.getMode({}, modeName), container);
                    return container.innerHTML;
                } catch (error) {
                    if (window.console && console.warn) {
                        console.warn('[AI Changes] CodeMirror highlighting failed for', path, modeName, error);
                    }
                }
            }

            return this.escapeHtml(content);
        },

        getModeForPath: function(path) {
            var extension = String(path || '').split('?')[0].split('.').pop().toLowerCase();
            var modes = {
                css: 'css',
                htm: 'htmlmixed',
                html: 'htmlmixed',
                js: 'javascript',
                json: 'application/json',
                jsx: 'jsx',
                md: 'markdown',
                php: 'php',
                scss: 'css',
                svg: 'xml',
                ts: 'javascript',
                tsx: 'jsx',
                txt: null,
                xml: 'xml'
            };

            return Object.prototype.hasOwnProperty.call(modes, extension) ? modes[extension] : null;
        },

        escapeHtml: function(content) {
            return $('<div></div>').text(content || '').html();
        },

        toggleFilePreview: function($toggle) {
            var filePath = $toggle.data('path');
            var $preview = $('.ai-file-inline-preview[data-path="' + filePath + '"]');
            var $code = $preview.find('code');
            var isVisible = $preview.is(':visible');

            if (isVisible) {
                $preview.slideUp(150);
                $toggle.text('▶').removeClass('expanded');
            } else {
                // Check if already loaded
                if ($code.html() === '') {
                    $code.html('<span class="loading">Loading...</span>');
                    var self = this;
                    $.post(aiChanges.ajaxUrl, {
                        action: 'ai_assistant_generate_diff',
                        nonce: aiChanges.nonce,
                        file_paths: [filePath]
                    }, function(response) {
                        if (response.success) {
                            $code.removeClass('cm-s-default ai-file-content-preview ai-file-diff-preview');
                            if (response.data.type === 'content') {
                                $code
                                    .addClass('cm-s-default ai-file-content-preview')
                                    .html(self.highlightContent(response.data.content || '', response.data.path || filePath));
                            } else {
                                $code
                                    .addClass('cm-s-default ai-file-diff-preview')
                                    .html(self.highlightDiff(response.data.diff || ''));
                            }
                        } else {
                            $code.html('<span class="loading">Error loading diff</span>');
                        }
                    }).fail(function() {
                        $code.html('<span class="loading">Error loading diff</span>');
                    });
                }
                $preview.slideDown(150);
                $toggle.text('▼').addClass('expanded');
            }
        },

        importPatch: function(file) {
            var $button = $('#ai-import-patch');
            var originalText = $button.text();
            $button.text(aiChanges.strings.importing).prop('disabled', true);

            var formData = new FormData();
            formData.append('action', 'ai_assistant_apply_patch');
            formData.append('nonce', aiChanges.nonce);
            formData.append('patch_file', file);

            $.ajax({
                url: aiChanges.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        alert(aiChanges.strings.importSuccess.replace('%d', response.data.modified));
                        location.reload();
                    } else {
                        alert(response.data.message || aiChanges.strings.importError);
                        $button.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert(aiChanges.strings.importError);
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },

        lintFilesInCard: function($card) {
            var self = this;
            var requests = [];

            $card.find('.ai-lint-status').each(function() {
                var $status = $(this);
                requests.push(self.lintFile($status.data('path')));
            });

            return requests;
        },

        lintAllPhpFiles: function() {
            // This is now a no-op since syntax checks are manually triggered.
            // Keep the method for compatibility
        },

        checkPhpSyntax: function($button) {
            var self = this;
            var $card = $button.closest('[data-plugin]');
            var $summary = $button.siblings('.ai-lint-summary');
            var originalText = $.trim($button.text());
            var requests = this.lintFilesInCard($card);

            $button
                .text(aiChanges.strings.checkingPhpSyntax || 'Checking...')
                .prop('disabled', true);
            $summary.text('');

            if (requests.length === 0) {
                $button
                    .text(originalText || aiChanges.strings.checkPhpSyntax || 'Check PHP syntax')
                    .prop('disabled', false);
                $summary.text(aiChanges.strings.noPhpFiles || 'No PHP files found.');
                return;
            }

            $.when.apply($, requests).always(function() {
                var checkedCount = $card.find('.ai-lint-ok, .ai-lint-error').length;
                var errorCount = $card.find('.ai-lint-error').length;

                $button
                    .text(originalText || aiChanges.strings.checkPhpSyntax || 'Check PHP syntax')
                    .prop('disabled', false);

                if (errorCount > 0) {
                    $summary.text((aiChanges.strings.syntaxErrorsFound || '%d syntax error(s).').replace('%d', errorCount));
                } else if (checkedCount > 0) {
                    $summary.text(aiChanges.strings.syntaxChecked || 'PHP syntax OK.');
                } else {
                    $summary.text(aiChanges.strings.noPhpFiles || 'No PHP files found.');
                }

                self.updatePluginLintStatus($card);
            });
        },

        lintFile: function(filePath) {
            var self = this;
            var $status = $('.ai-lint-status[data-path="' + filePath + '"]');
            var $pluginCard = $status.closest('[data-plugin]');

            $status.data('linted', true);

            return $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_lint_php',
                nonce: aiChanges.nonce,
                file_path: filePath
            }).then(function(response) {
                if (!response.success || !response.data.is_php) {
                    $status.text('').removeClass('ai-lint-ok ai-lint-error').removeAttr('title');
                    return { isPhp: false, valid: true };
                }

                if (response.data.valid) {
                    $status
                        .text(aiChanges.strings.syntaxOk || 'Syntax OK')
                        .removeClass('ai-lint-error')
                        .addClass('ai-lint-ok');
                } else {
                    var errorMsg = response.data.error || 'Syntax error';
                    if (response.data.line) {
                        errorMsg += ' (line ' + response.data.line + ')';
                    }
                    $status
                        .text(aiChanges.strings.syntaxError || 'Syntax Error')
                        .removeClass('ai-lint-ok')
                        .addClass('ai-lint-error')
                        .attr('title', errorMsg);
                }

                self.updatePluginLintStatus($pluginCard);
                return { isPhp: true, valid: !!response.data.valid };
            }, function() {
                $status
                    .text(aiChanges.strings.syntaxCheckFailed || 'Syntax check failed')
                    .removeClass('ai-lint-ok')
                    .addClass('ai-lint-error');
                self.updatePluginLintStatus($pluginCard);
                return { isPhp: true, valid: false };
            });
        },

        updatePluginLintStatus: function($pluginCard) {
            var $statusTarget = $pluginCard.find('.ai-plugin-files .ai-changes-panel-header').first();

            if (!$statusTarget.length) {
                $statusTarget = $pluginCard.find('.ai-plugin-header').first();
            }

            if (!$statusTarget.length) {
                return;
            }

            var $pluginStatus = $statusTarget.find('.ai-plugin-lint-status');

            if ($pluginStatus.length === 0) {
                $pluginStatus = $('<span class="ai-plugin-lint-status"></span>');
                $statusTarget.append($pluginStatus);
            }

            var errorCount = $pluginCard.find('.ai-lint-error').length;

            if (errorCount > 0) {
                $pluginStatus
                    .text(errorCount + ' syntax ' + (errorCount === 1 ? 'error' : 'errors'))
                    .addClass('ai-lint-error')
                    .show();
            } else {
                $pluginStatus.removeClass('ai-lint-error').hide();
            }
        }
    };

    $(document).ready(function() {
        AiChanges.init();
    });

})(jQuery);
