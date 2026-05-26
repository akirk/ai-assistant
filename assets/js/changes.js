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
