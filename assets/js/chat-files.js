(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        fileContextEventsBound: false,
        fileDragDepth: 0,
        pendingUploadCount: 0,

        bindFileContextEvents: function() {
            var self = this;

            if (this.fileContextEventsBound) {
                return;
            }

            this.fileContextEventsBound = true;

            $(document).on('click.aiAssistantFiles', '#ai-assistant-attach', function(e) {
                e.preventDefault();
                if (self.isUploadingFiles) {
                    return;
                }
                $('#ai-assistant-file-input').trigger('click');
            });

            $(document).on('change.aiAssistantFiles', '#ai-assistant-file-input', function() {
                self.handleContextFiles(this.files);
                this.value = '';
            });

            $(document).on('click.aiAssistantFiles', '.ai-attachment-remove', function(e) {
                e.preventDefault();
                var index = parseInt($(this).attr('data-index'), 10);
                if (!isNaN(index)) {
                    self.removePendingAttachment(index);
                }
            });

            $(document).on('dragenter.aiAssistantFiles', '.ai-assistant-chat-container', function(e) {
                if (!self.hasFileDrag(e)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                self.fileDragDepth++;
                $(this).addClass('ai-file-drag-over');
            });

            $(document).on('dragover.aiAssistantFiles', '.ai-assistant-chat-container', function(e) {
                if (!self.hasFileDrag(e)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                if (e.originalEvent && e.originalEvent.dataTransfer) {
                    e.originalEvent.dataTransfer.dropEffect = 'copy';
                }
            });

            $(document).on('dragleave.aiAssistantFiles', '.ai-assistant-chat-container', function(e) {
                if (!self.hasFileDrag(e)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                self.fileDragDepth = Math.max(0, self.fileDragDepth - 1);
                if (self.fileDragDepth === 0) {
                    $(this).removeClass('ai-file-drag-over');
                }
            });

            $(document).on('drop.aiAssistantFiles', '.ai-assistant-chat-container', function(e) {
                if (!self.hasFileDrag(e)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                self.fileDragDepth = 0;
                $(this).removeClass('ai-file-drag-over');

                var files = e.originalEvent && e.originalEvent.dataTransfer
                    ? e.originalEvent.dataTransfer.files
                    : null;
                self.handleContextFiles(files);
            });
        },

        hasFileDrag: function(event) {
            var dataTransfer = event.originalEvent && event.originalEvent.dataTransfer;
            if (!dataTransfer || !dataTransfer.types) {
                return false;
            }

            for (var i = 0; i < dataTransfer.types.length; i++) {
                if (dataTransfer.types[i] === 'Files') {
                    return true;
                }
            }

            return false;
        },

        getMaxClientFileBytes: function() {
            return (window.aiAssistantConfig && window.aiAssistantConfig.maxClientFileBytes) || (128 * 1024);
        },

        getCompactClientFileBytes: function() {
            return (window.aiAssistantConfig && window.aiAssistantConfig.compactClientFileBytes) || (32 * 1024);
        },

        handleContextFiles: function(fileList) {
            var self = this;
            var files = Array.prototype.slice.call(fileList || []);

            if (files.length === 0) {
                return;
            }

            var readable = [];
            var notices = [];
            var maxBytes = this.getMaxClientFileBytes();

            files.forEach(function(file) {
                if (self.isImageFile(file)) {
                    notices.push(file.name + ': Images are not inserted as chat context. Upload it to the Media Library if you want the assistant to use it in a post or page.');
                    return;
                }

                if (!self.isTextFile(file)) {
                    notices.push(file.name + ': Only small text files can be attached as chat context.');
                    return;
                }

                if (file.size > maxBytes) {
                    if (window.confirm(file.name + ' is ' + self.formatAttachmentBytes(file.size) + ', which is larger than the ' + self.formatAttachmentBytes(maxBytes) + ' direct-insert limit. Insert a compact preview instead?')) {
                        readable.push({ file: file, compact: true });
                    } else {
                        notices.push(file.name + ': Not inserted. You can split it into smaller text files or compact it before attaching.');
                    }
                    return;
                }

                readable.push({ file: file, compact: false });
            });

            if (notices.length > 0) {
                this.addMessage('system', notices.join('\n'));
            }

            if (readable.length === 0) {
                return;
            }

            this.setFileUploading(true, readable.length);

            Promise.all(readable.map(function(item) {
                return item.compact
                    ? self.readCompactedTextFile(item.file)
                    : self.readFullTextFile(item.file);
            })).then(function(attachments) {
                self.pendingAttachments = (self.pendingAttachments || []).concat(attachments);
                self.renderPendingAttachments();
                self.updateTokenCount();
                $('#ai-assistant-input').focus();
            }).catch(function(error) {
                self.addMessage('error', 'Could not read file context: ' + error.message);
            }).finally(function() {
                self.setFileUploading(false, 0);
            });
        },

        isImageFile: function(file) {
            if (file.type && file.type.indexOf('image/') === 0) {
                return true;
            }
            return /\.(avif|bmp|gif|heic|jpeg|jpg|png|svg|webp)$/i.test(file.name || '');
        },

        isTextFile: function(file) {
            if (file.type && file.type.indexOf('text/') === 0) {
                return true;
            }

            var textMimes = [
                'application/json',
                'application/ld+json',
                'application/xml',
                'application/javascript',
                'application/x-javascript',
                'application/x-yaml'
            ];

            if (file.type && textMimes.indexOf(file.type) >= 0) {
                return true;
            }

            return /\.(txt|md|markdown|csv|tsv|json|xml|html|htm|css|js|jsx|ts|tsx|yaml|yml|log)$/i.test(file.name || '');
        },

        readFullTextFile: function(file) {
            var self = this;
            return this.readFileSlice(file, 0, file.size).then(function(content) {
                return self.createTextAttachment(file, content, false);
            });
        },

        readCompactedTextFile: function(file) {
            var self = this;
            var previewBytes = Math.min(this.getCompactClientFileBytes(), file.size);
            var headBytes = Math.ceil(previewBytes / 2);
            var tailBytes = Math.floor(previewBytes / 2);
            var tailStart = Math.max(headBytes, file.size - tailBytes);

            return Promise.all([
                this.readFileSlice(file, 0, headBytes),
                this.readFileSlice(file, tailStart, file.size)
            ]).then(function(parts) {
                var omitted = Math.max(0, file.size - headBytes - (file.size - tailStart));
                var content = parts[0] +
                    '\n\n[... compacted preview: omitted about ' + self.formatAttachmentBytes(omitted) + ' from the middle of ' + file.name + ' ...]\n\n' +
                    parts[1];
                return self.createTextAttachment(file, content, true);
            });
        },

        readFileSlice: function(file, start, end) {
            return new Promise(function(resolve, reject) {
                var reader = new FileReader();
                reader.onload = function() {
                    resolve(String(reader.result || ''));
                };
                reader.onerror = function() {
                    reject(new Error(reader.error ? reader.error.message : 'Read failed'));
                };
                reader.readAsText(file.slice(start, end));
            });
        },

        createTextAttachment: function(file, content, compacted) {
            return {
                name: file.name,
                original_name: file.name,
                mime_type: file.type || 'text/plain',
                size: file.size,
                content: content,
                compacted: !!compacted,
                read_from: 'browser'
            };
        },

        setFileUploading: function(reading, count) {
            this.isUploadingFiles = reading;
            this.pendingUploadCount = reading ? count : 0;

            $('.ai-assistant-chat-container').toggleClass('ai-file-uploading', reading);
            $('#ai-assistant-attach')
                .prop('disabled', reading)
                .toggleClass('ai-uploading', reading)
                .attr('title', reading ? 'Reading files...' : 'Attach files');

            this.renderPendingAttachments();
            this.updateSendButton();
        },

        removePendingAttachment: function(index) {
            if (!this.pendingAttachments || !this.pendingAttachments[index]) {
                return;
            }

            this.pendingAttachments.splice(index, 1);
            this.renderPendingAttachments();
            this.updateTokenCount();
        },

        renderPendingAttachments: function() {
            var self = this;
            var attachments = this.pendingAttachments || [];
            var $container = $('#ai-assistant-attachments');

            if ($container.length === 0) {
                return;
            }

            if (attachments.length === 0 && !this.isUploadingFiles) {
                $container.removeClass('has-attachments').empty();
                return;
            }

            var html = '';

            if (this.isUploadingFiles) {
                html += '<div class="ai-attachment-chip ai-attachment-uploading">' +
                    '<span class="ai-attachment-spinner"></span>' +
                    '<span>' + this.escapeHtml(this.sprintf(this._n('Reading %d file...', 'Reading %d files...', this.pendingUploadCount), this.pendingUploadCount)) + '</span>' +
                    '</div>';
            }

            attachments.forEach(function(file, index) {
                var name = file.original_name || file.name || self.__('Attachment');
                var title = file.compacted ? self.__('Compacted preview') : self.__('Private text context');
                html += '<div class="ai-attachment-chip">' +
                    '<span class="ai-attachment-name" title="' + self.escapeHtml(title) + '">' + self.escapeHtml(name) + '</span>' +
                    '<span class="ai-attachment-size">' + self.formatAttachmentBytes(file.size || 0) + (file.compacted ? ' ' + self.escapeHtml(self.__('compacted')) : '') + '</span>' +
                    '<button type="button" class="ai-attachment-remove" data-index="' + index + '" title="' + self.escapeHtml(self.__('Remove attachment')) + '">&times;</button>' +
                    '</div>';
            });

            $container.addClass('has-attachments').html(html);
        },

        formatAttachmentBytes: function(bytes) {
            if (this.formatBytes) {
                return this.formatBytes(bytes);
            }
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },

        buildUserMessageContent: function(message, attachments) {
            attachments = attachments || [];
            if (attachments.length === 0) {
                return message;
            }

            var visibleMessage = message || 'Use the attached text file' + (attachments.length === 1 ? '' : 's') + ' as context.';
            var payload = {
                instructions: 'The user attached these text files as private chat context. They were read client-side in the browser and were not uploaded to the WordPress Media Library. Use the content fields directly. If compacted is true, the content is only a preview and may omit important middle sections.',
                files: attachments.map(function(file) {
                    return {
                        filename: file.original_name || file.name,
                        mime_type: file.mime_type,
                        size: file.size,
                        compacted: !!file.compacted,
                        read_from: 'browser',
                        content: file.content || ''
                    };
                })
            };

            return visibleMessage + '\n\n<ai_assistant_file_context>\n' +
                JSON.stringify(payload, null, 2) +
                '\n</ai_assistant_file_context>';
        },

        extractFileContextForDisplay: function(content) {
            if (typeof content !== 'string') {
                return null;
            }

            var match = content.match(/\n*<ai_assistant_file_context>\n([\s\S]*?)\n<\/ai_assistant_file_context>/);
            if (!match) {
                return null;
            }

            var payload = null;
            try {
                payload = JSON.parse(match[1]);
            } catch (e) {
                payload = { files: [] };
            }

            return {
                visibleText: content.replace(match[0], '').trim(),
                files: payload.files || []
            };
        },

        getEditableMessageContent: function(content) {
            var fileContext = this.extractFileContextForDisplay(content);
            if (!fileContext) {
                return content;
            }

            if (fileContext.files && fileContext.files.length > 0) {
                this.pendingAttachments = fileContext.files.map(function(file) {
                    return {
                        name: file.filename,
                        original_name: file.filename,
                        mime_type: file.mime_type,
                        size: file.size,
                        content: file.content || '',
                        compacted: !!file.compacted,
                        read_from: 'browser'
                    };
                });
                this.renderPendingAttachments();
            }

            return fileContext.visibleText;
        },

        renderFileContextSummary: function(files) {
            var self = this;

            if (!files || files.length === 0) {
                return '';
            }

            var html = '<div class="ai-message-attachments">';
            files.forEach(function(file) {
                var name = file.filename || file.original_name || 'Attachment';
                html += '<div class="ai-message-attachment">' +
                    '<span class="ai-message-attachment-name">' + self.escapeHtml(name) + '</span>' +
                    '<span class="ai-message-attachment-meta">' + self.formatAttachmentBytes(file.size || 0) + (file.compacted ? ' compacted' : '') + '</span>' +
                    '</div>';
            });
            html += '</div>';

            return html;
        }
    });

})(jQuery);
