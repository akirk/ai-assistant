(function($) {
    'use strict';

    var AiChanges = {
        commitOffset: 0,
        commitsLoaded: false,
        hasMoreCommits: false,

        init: function() {
            this.bindEvents();
            this.lintAllPhpFiles();
            this.autoExpandFromUrl();
        },

        autoExpandFromUrl: function() {
            var self = this;
            var $detailCards = $('.ai-plugin-card-detail');

            if ($detailCards.length) {
                $detailCards.each(function() {
                    self.lintFilesInCard($(this));
                });
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
                    self.lintFilesInCard($card);

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

                if (willBeVisible) {
                    self.lintFilesInCard($card);
                }
            });

            // Per-file preview toggle
            $(document).on('click', '.ai-file-preview-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleFilePreview($(this));
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
                    var pluginPath = $toggle.closest('.ai-plugin-card').data('plugin');
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
            var pluginPath = $button.closest('.ai-plugin-card').data('plugin');
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
            var pluginPath = $rowTop.closest('.ai-plugin-card').data('plugin');
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
                            $code.html(self.highlightDiff(response.data.diff));
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
            $card.find('.ai-lint-status').each(function() {
                var $status = $(this);
                if (!$status.data('linted')) {
                    self.lintFile($status.data('path'));
                }
            });
        },

        lintAllPhpFiles: function() {
            // This is now a no-op since we lint on-demand when cards are expanded
            // Keep the method for compatibility
        },

        lintFile: function(filePath) {
            var self = this;
            var $status = $('.ai-lint-status[data-path="' + filePath + '"]');
            var $pluginCard = $status.closest('.ai-plugin-card');

            $status.data('linted', true);

            $.post(aiChanges.ajaxUrl, {
                action: 'ai_assistant_lint_php',
                nonce: aiChanges.nonce,
                file_path: filePath
            }, function(response) {
                if (!response.success || !response.data.is_php) {
                    return;
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
            });
        },

        updatePluginLintStatus: function($pluginCard) {
            var $header = $pluginCard.find('.ai-plugin-header');
            var $pluginStatus = $header.find('.ai-plugin-lint-status');

            if ($pluginStatus.length === 0) {
                $pluginStatus = $('<span class="ai-plugin-lint-status"></span>');
                $header.find('.ai-plugin-stats').after($pluginStatus);
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
