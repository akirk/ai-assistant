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

            $(document).on('click', '.ai-preview-height-toggle', function(e) {
                e.preventDefault();
                self.togglePreviewHeight($(this));
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

            $(document).on('click', '.ai-commit-sha', function(e) {
                e.preventDefault();
                self.toggleCommitDiff($(this).closest('.ai-commit-entry').find('.ai-commit-diff-toggle'));
            });

            $(document).on('keydown', '.ai-commit-sha', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    self.toggleCommitDiff($(this).closest('.ai-commit-entry').find('.ai-commit-diff-toggle'));
                }
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

            $(document).on('click', '.ai-diff-view-button', function(e) {
                e.preventDefault();
                self.switchDiffView($(this));
            });
        },

        getPluginPath: function($element) {
            return $element.closest('[data-plugin]').data('plugin') || '';
        },

        toggleCommitDiff: function($toggle) {
            var self = this;
            var $entry = $toggle.closest('.ai-commit-entry');
            var sha = $toggle.data('sha');
            var $preview = $entry.find('.ai-commit-diff-preview');
            var $heightToggle = $entry.find('.ai-commit-preview-height-toggle');
            var $code = $preview.find('code');
            var isVisible = $preview.is(':visible');

            if (isVisible) {
                $toggle.text('▶').removeClass('expanded');
                $preview.slideUp(200);
                self.resetPreviewHeight($preview, $heightToggle);
            } else {
                $toggle.text('▼').addClass('expanded');
                $preview.slideDown(200);
                $heightToggle.css('display', 'flex');

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
                            self.renderDiffPreview($code, response.data.diff || '', 'unified');
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

        renderDiffPreview: function($code, diff, view) {
            var data = this.parseUnifiedDiff(diff || '');
            var selectedView = view || $code.data('diff-view') || 'unified';

            $code
                .data('diff-text', diff || '')
                .data('diff-view', selectedView)
                .removeClass('cm-s-default ai-code-preview ai-file-content-preview ai-file-diff-preview ai-code-with-lines ai-language-css ai-language-html ai-language-javascript ai-language-json ai-language-markdown ai-language-php ai-language-xml')
                .addClass('ai-rendered-diff')
                .empty()
                .append(this.buildDiffRenderer(data, selectedView));
        },

        switchDiffView: function($button) {
            var $code = $button.closest('code');
            var diff = $code.data('diff-text') || '';
            var view = $button.data('view') || 'unified';

            this.renderDiffPreview($code, diff, view);
        },

        parseUnifiedDiff: function(diff) {
            var lines = String(diff || '').split('\n');
            var files = [];
            var currentFile = null;
            var currentHunk = null;
            var oldLine = 0;
            var newLine = 0;
            var stats = { additions: 0, deletions: 0 };

            function normalizePath(path) {
                return String(path || '').replace(/^[ab]\//, '');
            }

            function ensureFile() {
                if (!currentFile) {
                    currentFile = {
                        oldPath: '',
                        newPath: '',
                        path: '',
                        hunks: [],
                        additions: 0,
                        deletions: 0
                    };
                    files.push(currentFile);
                }
            }

            function addRow(row) {
                if (!currentHunk) {
                    currentHunk = { header: '', rows: [] };
                    ensureFile();
                    currentFile.hunks.push(currentHunk);
                }
                currentHunk.rows.push(row);
            }

            lines.forEach(function(line) {
                var hunkMatch;

                if (line.indexOf('diff --git ') === 0) {
                    currentFile = {
                        oldPath: '',
                        newPath: '',
                        path: line.replace(/^diff --git\s+a\//, '').replace(/\s+b\/.*$/, ''),
                        hunks: [],
                        additions: 0,
                        deletions: 0
                    };
                    files.push(currentFile);
                    currentHunk = null;
                    return;
                }

                if (!currentFile && line === '') {
                    return;
                }

                if (!currentHunk && line.indexOf('--- ') === 0) {
                    ensureFile();
                    currentFile.oldPath = normalizePath(line.substring(4).trim());
                    if (!currentFile.path || currentFile.path === '/dev/null') {
                        currentFile.path = currentFile.oldPath;
                    }
                    return;
                }

                if (!currentHunk && line.indexOf('+++ ') === 0) {
                    ensureFile();
                    currentFile.newPath = normalizePath(line.substring(4).trim());
                    if (currentFile.newPath !== '/dev/null') {
                        currentFile.path = currentFile.newPath;
                    }
                    return;
                }

                if (line.indexOf('@@') === 0) {
                    ensureFile();
                    hunkMatch = line.match(/^@@\s+-(\d+)(?:,\d+)?\s+\+(\d+)(?:,\d+)?\s+@@/);
                    oldLine = hunkMatch ? parseInt(hunkMatch[1], 10) : 0;
                    newLine = hunkMatch ? parseInt(hunkMatch[2], 10) : 0;
                    currentHunk = { header: line, rows: [] };
                    currentFile.hunks.push(currentHunk);
                    return;
                }

                if (!currentHunk) {
                    return;
                }

                if (line.charAt(0) === '+') {
                    addRow({ type: 'add', oldLine: null, newLine: newLine++, text: line.substring(1) });
                    currentFile.additions++;
                    stats.additions++;
                } else if (line.charAt(0) === '-') {
                    addRow({ type: 'del', oldLine: oldLine++, newLine: null, text: line.substring(1) });
                    currentFile.deletions++;
                    stats.deletions++;
                } else if (line.charAt(0) === ' ') {
                    addRow({ type: 'context', oldLine: oldLine++, newLine: newLine++, text: line.substring(1) });
                } else if (line.indexOf('\\') === 0) {
                    addRow({ type: 'meta', oldLine: null, newLine: null, text: line });
                } else if (line !== '') {
                    addRow({ type: 'context', oldLine: oldLine++, newLine: newLine++, text: line });
                }
            });

            return {
                raw: diff || '',
                files: files,
                additions: stats.additions,
                deletions: stats.deletions
            };
        },

        buildDiffStats: function(additions, deletions, className) {
            var $stats = $('<div></div>');
            var totalChanges = additions + deletions;
            var additionBlocks = totalChanges > 0 ? Math.round(additions / totalChanges * 5) : 0;
            var blockIndex;

            $stats
                .addClass(className)
                .attr('title', additions + ' additions, ' + deletions + ' deletions')
                .append($('<span class="ai-diff-stat ai-diff-stat-add"></span>').text('+' + additions))
                .append($('<span class="ai-diff-stat ai-diff-stat-del"></span>').text('-' + deletions));

            if (additions > 0 && additionBlocks === 0) {
                additionBlocks = 1;
            }
            if (deletions > 0 && additionBlocks === 5) {
                additionBlocks = 4;
            }

            if (totalChanges > 0) {
                var $bar = $('<span class="ai-diff-stat-bar" aria-hidden="true"></span>');
                for (blockIndex = 0; blockIndex < 5; blockIndex++) {
                    $('<span class="ai-diff-stat-block"></span>')
                        .addClass(blockIndex < additionBlocks ? 'ai-diff-stat-block-add' : 'ai-diff-stat-block-del')
                        .appendTo($bar);
                }
                $stats.append($bar);
            }

            return $stats;
        },

        buildDiffRenderer: function(data, view) {
            var $renderer = $('<div class="ai-diff-renderer"></div>');
            var $toolbar = $('<div class="ai-diff-toolbar"></div>');
            var $stats = this.buildDiffStats(data.additions, data.deletions, 'ai-diff-stats');
            var $views = $('<div class="ai-diff-view-switcher" role="group" aria-label="Diff view"></div>');
            var self = this;

            ['unified', 'split', 'raw'].forEach(function(option) {
                $('<button type="button" class="button button-small ai-diff-view-button"></button>')
                    .attr('aria-pressed', option === view ? 'true' : 'false')
                    .toggleClass('active', option === view)
                    .data('view', option)
                    .text(option === 'split' ? 'Split' : option.charAt(0).toUpperCase() + option.substring(1))
                    .appendTo($views);
            });

            $toolbar.append($stats, $views);
            $renderer.append($toolbar);

            if (view === 'raw') {
                $renderer.append($('<div class="ai-diff-raw cm-s-default"></div>').html(this.highlightDiff(data.raw)));
                return $renderer;
            }

            if (!data.files.length) {
                $renderer.append($('<div class="ai-diff-empty"></div>').text('No diff to display.'));
                return $renderer;
            }

            data.files.forEach(function(file) {
                $renderer.append(self.buildDiffFile(file, view));
            });

            return $renderer;
        },

        buildDiffFile: function(file, view) {
            var $file = $('<div class="ai-diff-file"></div>');
            var path = file.path || file.newPath || file.oldPath || 'Changed file';

            $file.append(
                $('<div class="ai-diff-file-header"></div>')
                    .append($('<span class="ai-diff-file-path"></span>').text(path))
                    .append(this.buildDiffStats(file.additions, file.deletions, 'ai-diff-file-stats'))
            );

            if (view === 'split') {
                $file.append(this.buildSplitDiffTable(file));
            } else {
                $file.append(this.buildUnifiedDiffTable(file));
            }

            return $file;
        },

        buildUnifiedDiffTable: function(file) {
            var $table = $('<table class="ai-diff-table ai-diff-table-unified"><tbody></tbody></table>');
            var $body = $table.find('tbody');
            var self = this;

            file.hunks.forEach(function(hunk) {
                if (hunk.header) {
                    $('<tr class="ai-diff-hunk-row"></tr>')
                        .append($('<td class="ai-diff-hunk" colspan="4"></td>').text(hunk.header))
                        .appendTo($body);
                }

                hunk.rows.forEach(function(row) {
                    var marker = row.type === 'add' ? '+' : (row.type === 'del' ? '-' : ' ');
                    $('<tr></tr>')
                        .addClass(self.getDiffRowClass(row.type))
                        .append($('<td class="ai-diff-line-no ai-diff-line-old"></td>').text(row.oldLine || ''))
                        .append($('<td class="ai-diff-line-no ai-diff-line-new"></td>').text(row.newLine || ''))
                        .append($('<td class="ai-diff-marker"></td>').text(row.type === 'meta' ? '' : marker))
                        .append($('<td class="ai-diff-code"></td>').text(row.text))
                        .appendTo($body);
                });
            });

            return $table;
        },

        buildSplitDiffTable: function(file) {
            var $table = $('<table class="ai-diff-table ai-diff-table-split"></table>');
            var $body = $('<tbody></tbody>').appendTo($table);
            var self = this;

            $table.prepend(
                $('<colgroup></colgroup>')
                    .append($('<col class="ai-diff-split-line-col">'))
                    .append($('<col class="ai-diff-split-code-col">'))
                    .append($('<col class="ai-diff-split-line-col">'))
                    .append($('<col class="ai-diff-split-code-col">'))
            );

            file.hunks.forEach(function(hunk) {
                if (hunk.header) {
                    $('<tr class="ai-diff-hunk-row"></tr>')
                        .append($('<td class="ai-diff-hunk" colspan="4"></td>').text(hunk.header))
                        .appendTo($body);
                }

                self.pairSplitRows(hunk.rows).forEach(function(pair) {
                    var left = pair.left;
                    var right = pair.right;
                    var $row = $('<tr></tr>');

                    $row
                        .toggleClass('ai-diff-row-change', !!(left || right) && (!left || !right || left.type !== 'context'))
                        .append(self.buildSplitCell(left, 'old'))
                        .append(self.buildSplitCodeCell(left, 'old'))
                        .append(self.buildSplitCell(right, 'new'))
                        .append(self.buildSplitCodeCell(right, 'new'))
                        .appendTo($body);
                });
            });

            return $table;
        },

        pairSplitRows: function(rows) {
            var pairs = [];
            var index = 0;

            while (index < rows.length) {
                var row = rows[index];
                var deletions = [];
                var additions = [];
                var max;
                var i;

                if (row.type === 'del') {
                    while (rows[index] && rows[index].type === 'del') {
                        deletions.push(rows[index++]);
                    }
                    while (rows[index] && rows[index].type === 'add') {
                        additions.push(rows[index++]);
                    }
                    max = Math.max(deletions.length, additions.length);
                    for (i = 0; i < max; i++) {
                        pairs.push({ left: deletions[i] || null, right: additions[i] || null });
                    }
                    continue;
                }

                if (row.type === 'add') {
                    while (rows[index] && rows[index].type === 'add') {
                        additions.push(rows[index++]);
                    }
                    additions.forEach(function(addition) {
                        pairs.push({ left: null, right: addition });
                    });
                    continue;
                }

                pairs.push({ left: row, right: row });
                index++;
            }

            return pairs;
        },

        buildSplitCell: function(row, side) {
            var $cell = $('<td></td>')
                .addClass('ai-diff-line-no')
                .addClass(side === 'old' ? 'ai-diff-line-old' : 'ai-diff-line-new')
                .text(row ? (side === 'old' ? (row.oldLine || '') : (row.newLine || '')) : '');

            if (row) {
                $cell.addClass(this.getDiffRowClass(row.type));
            }

            return $cell;
        },

        buildSplitCodeCell: function(row, side) {
            var $cell = $('<td class="ai-diff-code"></td>');
            var $content = $('<div class="ai-diff-code-content"></div>').appendTo($cell);

            if (!row) {
                return $cell.addClass('ai-diff-empty-cell');
            }

            $cell
                .addClass(this.getDiffRowClass(row.type));
            $content.text((row.type === 'add' ? '+ ' : (row.type === 'del' ? '- ' : '  ')) + row.text);

            if ((side === 'old' && row.type === 'add') || (side === 'new' && row.type === 'del')) {
                $cell.addClass('ai-diff-empty-cell');
                $content.text('');
            }

            return $cell;
        },

        getDiffRowClass: function(type) {
            if (type === 'add') {
                return 'ai-diff-row-add';
            }
            if (type === 'del') {
                return 'ai-diff-row-del';
            }
            if (type === 'meta') {
                return 'ai-diff-row-meta';
            }
            return 'ai-diff-row-context';
        },

        highlightContent: function($code, content, path) {
            var language = this.getLanguageForPath(path);
            var effectiveLanguage = language;
            var element = $code[0];

            if ((language === 'javascript' || language === 'js') && this.isJsonLikeText(content)) {
                effectiveLanguage = 'json';
            }

            element.textContent = '';
            $code
                .removeClass('cm-s-default ai-code-preview ai-rendered-diff ai-file-content-preview ai-file-diff-preview ai-code-with-lines ai-language-css ai-language-html ai-language-javascript ai-language-json ai-language-markdown ai-language-php ai-language-xml')
                .addClass('cm-s-default ai-code-preview ai-file-content-preview');
            this.setCodeLanguageClass(element, effectiveLanguage);

            if (effectiveLanguage && typeof wp !== 'undefined' && wp.CodeMirror && wp.CodeMirror.getMode && wp.CodeMirror.runMode) {
                try {
                    var modeName = this.getCodeMirrorMode(effectiveLanguage);
                    var codeToHighlight = content;
                    var prependedPhpTag = false;

                    if (modeName === 'php' && !String(content).trim().startsWith('<?')) {
                        codeToHighlight = '<?php\n' + content;
                        prependedPhpTag = true;
                    }

                    wp.CodeMirror.runMode(codeToHighlight, wp.CodeMirror.getMode({}, modeName), element);

                    if (prependedPhpTag) {
                        this.removePrependedPhpTag(element);
                    } else {
                        this.addLineNumbers(element);
                    }

                    if (effectiveLanguage === 'json') {
                        this.markJsonPropertyTokens(element);
                    }

                    return;
                } catch (error) {
                    if (window.console && console.warn) {
                        console.warn('[AI Changes] CodeMirror highlighting failed for', path, effectiveLanguage, error);
                    }
                }
            }

            element.textContent = content || '';
        },

        getLanguageForPath: function(path) {
            var extension = String(path || '').split('?')[0].split('.').pop().toLowerCase();
            var languages = {
                css: 'css',
                htm: 'html',
                html: 'html',
                js: 'javascript',
                json: 'json',
                jsx: 'javascript',
                md: 'markdown',
                php: 'php',
                scss: 'css',
                svg: 'xml',
                ts: 'javascript',
                tsx: 'javascript',
                txt: null,
                xml: 'xml'
            };

            return Object.prototype.hasOwnProperty.call(languages, extension) ? languages[extension] : null;
        },

        getCodeMirrorMode: function(language) {
            var modes = {
                html: 'htmlmixed',
                js: 'javascript',
                json: { name: 'javascript', json: true }
            };

            return modes[language] || language;
        },

        setCodeLanguageClass: function(element, language) {
            if (!element || !language) {
                return;
            }

            element.classList.add('ai-language-' + String(language).replace(/[^a-z0-9_-]/gi, '-').toLowerCase());
        },

        isJsonLikeText: function(text) {
            var trimmed = String(text || '').trim();

            if (!trimmed) {
                return false;
            }

            if (!((trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length - 1) === '}') ||
                (trimmed.charAt(0) === '[' && trimmed.charAt(trimmed.length - 1) === ']'))) {
                return false;
            }

            try {
                JSON.parse(trimmed);
                return true;
            } catch (error) {
                return false;
            }
        },

        markJsonPropertyTokens: function(element) {
            if (!element || typeof element.querySelectorAll !== 'function') {
                return;
            }

            Array.prototype.slice.call(element.querySelectorAll('.cm-string')).forEach(function(token) {
                var sibling = token.nextSibling;
                while (sibling) {
                    var text = sibling.textContent || '';
                    if (sibling.nodeType === 3) {
                        if (/^\s*:/.test(text)) {
                            token.classList.add('ai-json-key');
                            token.classList.add('cm-property');
                        }
                        if (text.trim() !== '') {
                            return;
                        }
                    } else if (sibling.nodeType === 1 && text.trim() !== '') {
                        return;
                    }
                    sibling = sibling.nextSibling;
                }
            });
        },

        addLineNumbers: function(element) {
            var lines = element.innerHTML.split('\n');

            element.innerHTML = lines.map(function(line, index) {
                return '<span class="ai-line"><span class="ai-line-number">' + (index + 1) + '</span><span class="ai-line-content">' + (line || ' ') + '</span></span>';
            }).join('');
            element.classList.add('ai-code-with-lines');
        },

        removePrependedPhpTag: function(element) {
            var firstChild = element.firstChild;

            if (firstChild && firstChild.classList && firstChild.classList.contains('cm-meta')) {
                firstChild.remove();
                if (element.firstChild && element.firstChild.nodeType === 3 && element.firstChild.textContent === '\n') {
                    element.firstChild.remove();
                }
            }
        },

        resetPreviewHeight: function($preview, $heightToggle) {
            $preview.removeClass('ai-preview-expanded');
            $heightToggle
                .hide()
                .attr('aria-expanded', 'false')
                .find('.ai-preview-height-arrow')
                .text('▼');
            $heightToggle
                .find('.ai-preview-height-label')
                .text(aiChanges.strings.expandFilePreview);
        },

        togglePreviewHeight: function($button) {
            var $container = $button.closest('.ai-changes-file, .ai-commit-entry');
            var $preview = $container.find('.ai-file-inline-preview, .ai-commit-diff-preview').first();
            var isExpanded = !$preview.hasClass('ai-preview-expanded');
            var label = isExpanded ? aiChanges.strings.collapseFilePreview : aiChanges.strings.expandFilePreview;

            $preview
                .toggleClass('ai-preview-expanded', isExpanded);

            $button
                .attr('aria-expanded', isExpanded ? 'true' : 'false')
                .find('.ai-preview-height-arrow')
                .text(isExpanded ? '▲' : '▼');

            $button
                .find('.ai-preview-height-label')
                .text(label);
        },

        toggleFilePreview: function($toggle) {
            var filePath = $toggle.data('path');
            var $file = $toggle.closest('.ai-changes-file');
            var previewType = $file.data('preview-type') || 'diff';
            var $preview = $file.find('.ai-file-inline-preview');
            var $heightToggle = $file.find('.ai-file-preview-height-toggle');
            var $code = $preview.find('code');
            var isVisible = $preview.is(':visible');

            if (isVisible) {
                $preview.slideUp(150);
                this.resetPreviewHeight($preview, $heightToggle);
                $toggle.text('▶').removeClass('expanded');
            } else {
                // Check if already loaded
                if ($code.html() === '') {
                    $code.html('<span class="loading">Loading...</span>');
                    var self = this;
                    var request = previewType === 'content' ? {
                        action: 'ai_assistant_get_file_content',
                        nonce: aiChanges.nonce,
                        file_path: filePath
                    } : {
                        action: 'ai_assistant_generate_diff',
                        nonce: aiChanges.nonce,
                        file_paths: [filePath]
                    };

                    $.post(aiChanges.ajaxUrl, request, function(response) {
                        if (response.success) {
                            $code.removeClass('cm-s-default ai-code-preview ai-rendered-diff ai-file-content-preview ai-file-diff-preview ai-code-with-lines ai-language-css ai-language-html ai-language-javascript ai-language-json ai-language-markdown ai-language-php ai-language-xml');
                            if (response.data.type === 'content') {
                                self.highlightContent($code, response.data.content || '', response.data.path || filePath);
                            } else {
                                self.renderDiffPreview($code, response.data.diff || '', 'unified');
                            }
                        } else {
                            $code.html('<span class="loading">Error loading diff</span>');
                        }
                    }).fail(function() {
                        $code.html('<span class="loading">Error loading diff</span>');
                    });
                }
                $preview.slideDown(150);
                $heightToggle.css('display', 'flex');
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
