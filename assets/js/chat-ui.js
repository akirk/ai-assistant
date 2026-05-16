(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        addMessage: function(role, content, extraClass) {
            var $messages = $('#ai-assistant-messages');

            var messageClass = 'ai-message ai-message-' + role;
            if (extraClass) {
                messageClass += ' ' + extraClass;
            }
            var displayContent = content;
            var copyContent = content;
            var fileContext = this.extractFileContextForDisplay
                ? this.extractFileContextForDisplay(content)
                : null;

            if (fileContext) {
                displayContent = fileContext.visibleText || 'Attached files';
                copyContent = displayContent;
            }

            var formattedContent = this.formatContent(displayContent);
            if (fileContext && this.renderFileContextSummary) {
                formattedContent += this.renderFileContextSummary(fileContext.files);
            }

            var $message = $('<div class="' + messageClass + '">' +
                '<div class="ai-message-content">' + formattedContent + '</div>' +
                '</div>');

            if (role === 'assistant' && !extraClass) {
                $message.attr('data-raw-content', content);
                $message.append(this.getMessageActions());
                this.updateSummarizeVisibility();
            } else if (role === 'user') {
                $message.attr('data-raw-content', content);
                if (copyContent !== content) {
                    $message.attr('data-copy-content', copyContent);
                }
                $message.append(this.getUserMessageActions());
            }

            $messages.append($message);
            this.scrollToBottom();
        },

        getMessageActions: function() {
            return '<div class="ai-message-actions">' +
                '<button type="button" class="ai-action-btn ai-action-copy" title="Copy">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 9h9a2 2 0 012 2v9a2 2 0 01-2 2h-9a2 2 0 01-2-2v-9a2 2 0 012-2z"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>' +
                '</button>' +
                '<button type="button" class="ai-action-btn ai-action-summarize" title="Summarize conversation">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>' +
                '</button>' +
                '</div>';
        },

        getUserMessageActions: function() {
            return '<div class="ai-message-actions">' +
                '<button type="button" class="ai-action-btn ai-action-copy" title="Copy">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 9h9a2 2 0 012 2v9a2 2 0 01-2 2h-9a2 2 0 01-2-2v-9a2 2 0 012-2z"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>' +
                '</button>' +
                '<button type="button" class="ai-action-btn ai-action-edit" title="Edit and resend">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
                '</button>' +
                '<button type="button" class="ai-action-btn ai-action-retry" title="Retry">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15"/></svg>' +
                '</button>' +
                '</div>';
        },

        updateSummarizeVisibility: function() {
            var $messages = $('#ai-assistant-messages');
            $messages.find('.ai-action-summarize').hide();
            var $lastAssistant = $messages.find('.ai-message-assistant').last();
            if ($lastAssistant.length && this.conversationId && this.conversationId > 0) {
                $lastAssistant.find('.ai-action-summarize').show();
            }
        },

        startReply: function() {
            var $messages = $('#ai-assistant-messages');
            var $message = $('<div class="ai-message ai-message-assistant ai-message-streaming">' +
                '<div class="ai-message-content"></div>' +
                '</div>');
            $messages.append($message);
            this.scrollToBottom();
            return $message;
        },

        startThinking: function() {
            var $messages = $('#ai-assistant-messages');
            var $thinking = $('<div class="ai-thinking-block">' +
                '<button type="button" class="ai-thinking-toggle">' +
                '<span class="ai-thinking-spinner"></span>' +
                '<span class="ai-thinking-label">Thinking...</span>' +
                '<span class="ai-thinking-toggle-icon" aria-hidden="true">&gt;</span>' +
                '</button>' +
                '<div class="ai-thinking-content"></div>' +
                '</div>');
            $messages.append($thinking);
            this.scrollToBottom();
            return $thinking;
        },

        updateThinking: function($thinking, text) {
            var $content = $thinking.find('.ai-thinking-content');
            $content.text(text);
            this.scrollToBottom();
        },

        finalizeThinking: function($thinking, durationMs) {
            if (!$thinking.find('.ai-thinking-content').text().trim()) {
                $thinking.remove();
                return;
            }
            $thinking.find('.ai-thinking-spinner').hide();
            var durationSec = (durationMs / 1000).toFixed(1);
            $thinking.find('.ai-thinking-label').text('Thought for ' + durationSec + 's');
            $thinking.addClass('ai-thinking-complete');
        },

        updateReply: function($message, text) {
            var $content = $message.find('.ai-message-content');
            $content.html(this.formatContent(text));
            $message.attr('data-raw-content', text);
            this.scrollToBottom();
        },

        finalizeReply: function($message) {
            if (!$message.find('.ai-message-content').text().trim()) {
                $message.remove();
                return;
            }
            $message.removeClass('ai-message-streaming');
            if (!$message.find('.ai-message-actions').length) {
                $message.append(this.getMessageActions());
            }
            this.updateSummarizeVisibility();
        },

        handleNavigationSuggestionClick: function(event, link) {
            if (
                !event ||
                !link ||
                event.defaultPrevented ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey ||
                (event.which && event.which !== 1) ||
                (typeof event.button === 'number' && event.button !== 0)
            ) {
                return false;
            }

            try {
                var target = new URL(link.getAttribute('href'), window.location.href);
                var current = new URL(window.location.href);
                if (
                    target.hash.indexOf('ai-open') === -1 ||
                    target.origin + target.pathname + target.search !== current.origin + current.pathname + current.search
                ) {
                    return false;
                }

                event.preventDefault();
                if (window.history && typeof window.history.replaceState === 'function') {
                    window.history.replaceState(null, '', target.href);
                } else {
                    window.location.href = target.href;
                }
                window.location.reload();
                return true;
            } catch (e) {
                return false;
            }
        },

        getImageSearchUrl: function(query, page) {
            var url = new URL('https://api.openverse.org/v1/images/');
            url.searchParams.set('q', query);
            url.searchParams.set('page', page || 1);
            url.searchParams.set('page_size', 12);
            url.searchParams.set('mature', 'false');
            url.searchParams.set('license', 'pdm,cc0');
            url.searchParams.set('excluded_source', 'flickr,inaturalist,wikimedia');
            return url.toString();
        },

        formatImageAttribution: function(image) {
            var parts = [];
            if (image.title) parts.push(image.title);
            if (image.creator) parts.push('by ' + image.creator);
            if (image.license) parts.push(image.license);
            return parts.join(' - ');
        },

        normalizeImageResult: function(raw) {
            raw = raw || {};
            var title = raw.title || '';
            if (typeof title === 'string' && title.toLowerCase().indexOf('file:') === 0) {
                title = title.slice(5);
            }

            var license = raw.license || '';
            if (raw.license_version) {
                license += (license ? ' ' : '') + raw.license_version;
            }

            var image = {
                url: raw.url || '',
                thumbnail: raw.thumbnail || raw.url || '',
                title: title,
                creator: raw.creator || '',
                creator_url: raw.creator_url || '',
                landing_url: raw.foreign_landing_url || raw.url || '',
                foreign_landing_url: raw.foreign_landing_url || '',
                license: license,
                license_url: raw.license_url || '',
                source: raw.source || '',
                width: raw.width ? Number(raw.width) : null,
                height: raw.height ? Number(raw.height) : null
            };

            image.attribution = this.formatImageAttribution(image);
            return image;
        },

        fetchImageResults: function(query, page) {
            var self = this;
            return fetch(this.getImageSearchUrl(query, page), {
                credentials: 'omit'
            }).then(function(response) {
                if (!response.ok) {
                    throw new Error('Search failed');
                }
                return response.json();
            }).then(function(data) {
                return (data.results || []).map(function(result) {
                    return self.normalizeImageResult(result);
                }).filter(function(image) {
                    return !!image.url;
                });
            });
        },

        getPickedImageUploadLimit: function() {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            var limit = Number(config.maxMediaUploadBytes || 0);
            return limit > 0 ? limit : 0;
        },

        getPickedImageUploadUrl: function() {
            if (typeof aiAssistantConfig === 'undefined' || !aiAssistantConfig.restApiUrl) {
                return '';
            }
            return String(aiAssistantConfig.restApiUrl).replace(/\/+$/, '') + '/wp/v2/media';
        },

        getPickedImageFileExtension: function(url, contentType) {
            var mime = String(contentType || '').split(';')[0].toLowerCase();
            var mimeExtensions = {
                'image/jpeg': 'jpg',
                'image/png': 'png',
                'image/gif': 'gif',
                'image/webp': 'webp',
                'image/avif': 'avif',
                'image/bmp': 'bmp',
                'image/heic': 'heic',
                'image/heif': 'heif',
                'image/svg+xml': 'svg'
            };
            var cleanUrl = String(url || '').split('?')[0].split('#')[0];
            var match = cleanUrl.match(/\.([a-z0-9]{2,5})$/i);
            var extension = match ? match[1].toLowerCase() : '';
            var allowedExtensions = {
                jpg: true,
                jpeg: true,
                png: true,
                gif: true,
                webp: true,
                avif: true,
                bmp: true,
                heic: true,
                heif: true,
                svg: true
            };

            if (extension && allowedExtensions[extension]) {
                return extension === 'jpeg' ? 'jpg' : extension;
            }

            return mimeExtensions[mime] || 'jpg';
        },

        getPickedImageMimeType: function(url, contentType) {
            var mime = String(contentType || '').split(';')[0].toLowerCase();
            var extensionTypes = {
                jpg: 'image/jpeg',
                jpeg: 'image/jpeg',
                png: 'image/png',
                gif: 'image/gif',
                webp: 'image/webp',
                avif: 'image/avif',
                bmp: 'image/bmp',
                heic: 'image/heic',
                heif: 'image/heif',
                svg: 'image/svg+xml'
            };

            if (mime.indexOf('image/') === 0) {
                return mime;
            }

            var cleanUrl = String(url || '').split('?')[0].split('#')[0];
            var match = cleanUrl.match(/\.([a-z0-9]{2,5})$/i);
            var extension = match ? match[1].toLowerCase() : '';
            return extensionTypes[extension] || '';
        },

        getPickedImageFileName: function(image, contentType) {
            image = image || {};
            var title = String(image.title || 'openverse-image')
                .replace(/^file:/i, '')
                .replace(/\.[a-z0-9]{2,5}$/i, '');
            var baseName = title
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .substring(0, 80);

            if (!baseName) {
                baseName = 'openverse-image';
            }

            return baseName + '.' + this.getPickedImageFileExtension(image.url, contentType);
        },

        getPickedImageFileTitle: function(file) {
            var name = String((file && file.name) || '')
                .replace(/^.*[\\/]/, '')
                .trim();
            name = name.replace(/\.[a-z0-9]{2,5}$/i, '');
            return name || 'Uploaded image';
        },

        getShortImageSourceDomain: function(sourceUrl) {
            var raw = String(sourceUrl || '').trim();
            var host = '';
            var match = raw.match(/^[a-z][a-z0-9+.-]*:\/\/([^/?#]+)/i) ||
                raw.match(/^\/\/([^/?#]+)/);

            if (match) {
                host = match[1];
            } else if (raw) {
                host = raw.split(/[/?#]/)[0];
            }

            host = host
                .replace(/^[^@]+@/, '')
                .replace(/:\d+$/, '')
                .toLowerCase()
                .replace(/^www\./, '');

            if (!host) {
                return 'source';
            }

            var parts = host.split('.').filter(function(part) { return !!part; });
            var disposablePrefixes = {
                asset: true,
                assets: true,
                cdn: true,
                file: true,
                files: true,
                image: true,
                images: true,
                img: true,
                media: true,
                m: true,
                static: true,
                upload: true,
                uploads: true
            };

            while (parts.length > 2 && disposablePrefixes[parts[0]]) {
                parts.shift();
            }

            if (parts.length > 2) {
                var secondLevel = parts[parts.length - 2];
                var topLevel = parts[parts.length - 1];
                var compoundPublicSuffix = topLevel.length === 2 && {
                    ac: true,
                    co: true,
                    com: true,
                    edu: true,
                    gov: true,
                    net: true,
                    org: true
                }[secondLevel];

                return parts.slice(compoundPublicSuffix ? -3 : -2).join('.');
            }

            return parts.join('.') || host;
        },

        isPickedImageFile: function(file) {
            if (!file) {
                return false;
            }
            if (typeof this.isImageFile === 'function' && this.isImageFile(file)) {
                return true;
            }
            if (file.type && String(file.type).indexOf('image/') === 0) {
                return true;
            }
            return /\.(avif|bmp|gif|heic|heif|jpeg|jpg|png|svg|webp)$/i.test(file.name || '');
        },

        getPickedImageFileData: function(file) {
            if (!file) {
                return Promise.reject(new Error('Drop an image file.'));
            }

            var contentType = this.getPickedImageMimeType(file.name || '', file.type || '');
            var limit = this.getPickedImageUploadLimit();

            if (!this.isPickedImageFile(file) || !contentType || contentType.indexOf('image/') !== 0) {
                return Promise.reject(new Error('Drop an image file.'));
            }

            if (!file.size) {
                return Promise.reject(new Error('Selected image was empty.'));
            }

            if (limit && file.size > limit) {
                return Promise.reject(new Error('Selected image is larger than this site allows for uploads.'));
            }

            return Promise.resolve({
                blob: file,
                contentType: contentType,
                filename: this.getPickedImageFileName({
                    title: this.getPickedImageFileTitle(file),
                    url: file.name || ''
                }, contentType)
            });
        },

        getPickedImageMetadata: function(image) {
            image = image || {};
            return {
                title: image.title || '',
                creator: image.creator || '',
                creator_url: image.creator_url || '',
                attribution: image.attribution || this.formatImageAttribution(image),
                license: image.license || '',
                license_url: image.license_url || '',
                source: image.source || '',
                landing_url: image.landing_url || image.foreign_landing_url || image.url || '',
                foreign_landing_url: image.foreign_landing_url || '',
                remote_url: image.url || '',
                width: image.width || null,
                height: image.height || null
            };
        },

        getPickedImageThumbnailUrl: function(attachment, fallback) {
            var details = attachment && attachment.media_details;
            var sizes = details && details.sizes;
            if (sizes) {
                if (sizes.thumbnail && sizes.thumbnail.source_url) {
                    return sizes.thumbnail.source_url;
                }
                if (sizes.medium && sizes.medium.source_url) {
                    return sizes.medium.source_url;
                }
            }
            return (attachment && attachment.source_url) || fallback || '';
        },

        getPickedImageFetchUrls: function(image) {
            image = image || {};
            var urls = [];
            var seen = {};

            [image.url, image.thumbnail].forEach(function(url) {
                url = String(url || '').trim();
                if (!url || seen[url]) {
                    return;
                }
                seen[url] = true;
                urls.push(url);
            });

            return urls;
        },

        fetchPickedImageBlob: function(image, signal, onStatus) {
            var self = this;
            var urls = this.getPickedImageFetchUrls(image);

            if (!urls.length) {
                return Promise.reject(new Error('Selected image has no URL.'));
            }

            var options = { credentials: 'omit' };
            if (signal) {
                options.signal = signal;
            }

            function fetchUrl(index, lastError) {
                if (index >= urls.length) {
                    return Promise.reject(lastError || new Error('Selected image could not be fetched.'));
                }

                if (index > 0 && typeof onStatus === 'function') {
                    onStatus('Full image unavailable. Trying preview image...');
                }

                return fetch(urls[index], options).then(function(response) {
                    if (!response.ok) {
                        throw new Error('Image fetch failed with HTTP ' + response.status + '.');
                    }

                    var responseType = String(response.headers.get('content-type') || '').split(';')[0].toLowerCase();
                    if (responseType &&
                        responseType.indexOf('image/') !== 0 &&
                        responseType !== 'application/octet-stream' &&
                        responseType !== 'binary/octet-stream') {
                        throw new Error('Selected URL did not return an image.');
                    }

                    return response.blob().then(function(blob) {
                        var contentType = self.getPickedImageMimeType(urls[index], blob.type || responseType);
                        var limit = self.getPickedImageUploadLimit();

                        if (!contentType && index > 0) {
                            contentType = 'image/jpeg';
                        }

                        if (!contentType || contentType.indexOf('image/') !== 0) {
                            throw new Error('Selected file is not an image.');
                        }

                        if (!blob.size) {
                            throw new Error('Selected image was empty.');
                        }

                        if (limit && blob.size > limit) {
                            throw new Error('Selected image is larger than this site allows for uploads.');
                        }

                        return {
                            blob: blob,
                            contentType: contentType,
                            filename: self.getPickedImageFileName(image, contentType),
                            sourceUrl: urls[index],
                            usedThumbnailFallback: index > 0
                        };
                    });
                }).catch(function(error) {
                    if (signal && signal.aborted) {
                        throw error;
                    }
                    return fetchUrl(index + 1, error);
                });
            }

            return fetchUrl(0);
        },

        uploadPickedImageToMediaLibrary: function(fileData, image, signal) {
            var uploadUrl = this.getPickedImageUploadUrl();
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};

            if (!uploadUrl) {
                return Promise.reject(new Error('WordPress media upload URL is unavailable.'));
            }
            if (typeof FormData === 'undefined') {
                return Promise.reject(new Error('This browser cannot upload files from the image picker.'));
            }

            var formData = new FormData();
            var title = (image && image.title) || fileData.filename;
            var attribution = image ? (image.attribution || this.formatImageAttribution(image)) : '';
            var headers = {};

            formData.append('file', fileData.blob, fileData.filename);
            if (title) {
                formData.append('title', title);
                formData.append('alt_text', title);
            }
            if (attribution) {
                formData.append('caption', attribution);
            }

            if (config.restApiNonce) {
                headers['X-WP-Nonce'] = config.restApiNonce;
            }

            var options = {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: formData
            };
            if (signal) {
                options.signal = signal;
            }

            return fetch(uploadUrl, options).then(function(response) {
                return response.text().then(function(text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (e) {
                        throw new Error('Media upload returned a non-JSON response.');
                    }

                    if (!response.ok) {
                        throw new Error((data && data.message) || ('Media upload failed with HTTP ' + response.status + '.'));
                    }
                    if (!data || !data.id) {
                        throw new Error('Media upload did not return an attachment ID.');
                    }

                    return data;
                });
            });
        },

        buildPickedImageResult: function(image, attachment, uploadError, options) {
            options = options || {};
            var metadata = this.getPickedImageMetadata(image);
            var localUrl = attachment && (attachment.source_url || attachment.link);
            var result = $.extend({}, metadata, {
                attachment_id: attachment ? Number(attachment.id) : null,
                id: attachment ? Number(attachment.id) : null,
                url: localUrl || metadata.remote_url,
                source_url: localUrl || metadata.remote_url,
                thumbnail: this.getPickedImageThumbnailUrl(attachment, image && image.thumbnail),
                uploaded: !!attachment,
                external: !attachment,
                media: attachment ? {
                    id: Number(attachment.id),
                    link: attachment.link || '',
                    mime_type: attachment.mime_type || '',
                    media_type: attachment.media_type || ''
                } : null
            });

            if (uploadError) {
                result.upload_failed = true;
                result.upload_error = uploadError.message || String(uploadError);
            }

            if (options.usedThumbnailFallback) {
                result.low_resolution = true;
                result.uploaded_from_thumbnail = true;
                result.note = 'low resolution image';
            }

            return result;
        },

        preparePickedImageSelection: function(image, args, onStatus, signal) {
            var self = this;
            var setStatus = typeof onStatus === 'function' ? onStatus : function() {};

            setStatus('Fetching selected image...');

            var selectedFileData = null;

            return this.fetchPickedImageBlob(image, signal, setStatus)
                .then(function(fileData) {
                    selectedFileData = fileData;
                    setStatus('Uploading to Media Library...');
                    return self.uploadPickedImageToMediaLibrary(fileData, image, signal);
                })
                .then(function(attachment) {
                    setStatus('Uploaded to Media Library');
                    return {
                        selection: self.buildPickedImageResult(image, attachment, null, {
                            usedThumbnailFallback: !!(selectedFileData && selectedFileData.usedThumbnailFallback)
                        }),
                        success: true
                    };
                })
                .catch(function(error) {
                    setStatus('Media Library upload failed. Drop a photo here or click the image domain to use its source URL.');
                    return {
                        selection: self.buildPickedImageResult(image, null, error),
                        success: false,
                        can_use_external: true
                    };
                });
        },

        preparePickedImageFileSelection: function(file, onStatus, signal) {
            var self = this;
            var setStatus = typeof onStatus === 'function' ? onStatus : function() {};
            var image = {
                url: '',
                thumbnail: '',
                title: this.getPickedImageFileTitle(file),
                creator: '',
                creator_url: '',
                attribution: '',
                license: '',
                license_url: '',
                source: 'local_upload',
                landing_url: '',
                foreign_landing_url: '',
                width: null,
                height: null
            };

            setStatus('Preparing image upload...');

            return this.getPickedImageFileData(file)
                .then(function(fileData) {
                    setStatus('Uploading dropped image to Media Library...');
                    return self.uploadPickedImageToMediaLibrary(fileData, image, signal);
                })
                .then(function(attachment) {
                    setStatus('Uploaded to Media Library');
                    return {
                        selection: self.buildPickedImageResult(image, attachment),
                        success: true
                    };
                })
                .catch(function(error) {
                    var message = error && error.message ? error.message : String(error || 'Upload failed.');
                    setStatus(message);
                    return {
                        selection: { error: message },
                        success: false
                    };
                });
        },

        getBroaderImageQuery: function(query) {
            var normalized = String(query || '')
                .toLowerCase()
                .replace(/["'`.,;:!?()[\]{}]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            if (!normalized) return '';

            var synonymQuery = normalized
                .replace(/\bpalatschinken\b/g, 'crepe')
                .replace(/\bpalatschinke\b/g, 'crepe')
                .replace(/\bcrêpes?\b/g, 'crepe');

            if (synonymQuery !== normalized && synonymQuery.indexOf('crepe') >= 0) {
                return 'crepe';
            }

            var stopWords = {
                a: true, an: true, and: true, as: true, at: true, by: true, for: true,
                from: true, in: true, of: true, on: true, or: true, the: true, to: true,
                with: true, about: true, image: true, photo: true, picture: true,
                background: true, wallpaper: true, launcher: true, app: true, apps: true,
                beautiful: true, cool: true, detailed: true, high: true, minimal: true,
                modern: true, nice: true, quality: true, realistic: true,
                black: true, blue: true, brown: true, gray: true, green: true, grey: true,
                orange: true, pink: true, purple: true, red: true, white: true, yellow: true
            };
            var words = synonymQuery.split(/\s+/).filter(function(word) {
                return word.length > 2 && !stopWords[word];
            });

            if (words.length > 2) {
                return words.slice(0, 2).join(' ');
            }
            if (words.length === 2) {
                return words[0];
            }
            return '';
        },

        renderImagePicker: function(toolId, args, onSelect) {
            var self = this;
            var query = String((args && args.query) || '').trim();
            var page = 1;
            var done = false;
            var lastFallbackFrom = '';
            var activeSelectionController = null;
            var selectionRun = 0;
            var $card = $('[data-tool-id="' + toolId + '"]');

            if (!$card.length) {
                this.showToolProgress('pick_image', 0, toolId);
                $card = $('[data-tool-id="' + toolId + '"]');
            }

            $card.find('.ai-tool-card-status').text('Choose image');
            $card.find('.ai-tool-card-spinner').hide();
            $card.find('.ai-tool-card-size').hide();
            $card.find('.ai-tool-card-actions').empty();

            var $cardPreview = $card.find('.ai-tool-card-preview').empty();
            var $cardNote = $('<div class="ai-image-picker-card-note"></div>');
            var $cardNoteText = $('<span></span>').text('Image picker open');
            var $reopen = $('<button type="button" class="button button-small ai-image-picker-reopen">Open picker</button>');
            $cardNote.append($cardNoteText, $reopen);
            $cardPreview.append($cardNote);

            var $overlay = $('<div class="ai-image-picker-overlay" role="dialog" aria-modal="true"></div>');
            var $dialog = $('<div class="ai-image-picker-dialog"></div>');
            var $header = $('<div class="ai-image-picker-header"></div>');
            var $headingWrap = $('<div class="ai-image-picker-heading"></div>');
            var $titleText = $('<strong></strong>').text('Choose image');
            var $subtitle = $('<span></span>').text(args && args.purpose ? args.purpose : 'Pick one result to continue');
            var $close = $('<button type="button" class="ai-image-picker-close" aria-label="Cancel image selection">&times;</button>');
            var $picker = $('<div class="ai-image-picker"></div>');
            var $controls = $('<div class="ai-image-picker-controls"></div>');
            var $input = $('<input type="search" class="ai-image-picker-query">')
                .attr('placeholder', 'Search images')
                .val(query);
            var $search = $('<button type="button" class="button button-small ai-image-picker-search">Search</button>');
            var $more = $('<button type="button" class="button button-small ai-image-picker-more">More</button>').prop('hidden', true);
            var $cancel = $('<button type="button" class="button button-small ai-image-picker-cancel">Cancel</button>');
            var $upload = $('<div class="ai-image-picker-upload" tabindex="0"></div>');
            var $uploadText = $('<span class="ai-image-picker-upload-text"></span>').text('Drop a photo here');
            var $browse = $('<button type="button" class="button button-small ai-image-picker-browse">Choose file</button>');
            var $fileInput = $('<input type="file" class="ai-image-picker-file-input" accept="image/*">').attr('aria-label', 'Choose image file');
            var $status = $('<div class="ai-image-picker-status" aria-live="polite"></div>');
            var $grid = $('<div class="ai-image-picker-grid"></div>');

            $headingWrap.append($titleText, $subtitle);
            $header.append($headingWrap, $close);
            $controls.append($input, $search, $more, $cancel);
            $upload.append($uploadText, $browse, $fileInput);
            $picker.append($controls, $upload, $status, $grid);
            $dialog.append($header, $picker);
            $overlay.append($dialog);
            $('body').append($overlay);

            $input.trigger('focus');

            function finish(selection, success) {
                if (done) return;
                done = true;
                if (activeSelectionController) {
                    activeSelectionController.abort();
                    activeSelectionController = null;
                }
                $overlay.remove();
                $cardPreview.empty();
                onSelect(selection, success !== false);
            }

            function setSelectionBusy(busy) {
                $grid.find('.ai-image-result').toggleClass('is-disabled', busy);
                $grid.find('.ai-image-result-select, .ai-image-result-source').prop('disabled', busy);
                $search.prop('disabled', busy);
                $more.prop('disabled', busy);
                $input.prop('disabled', busy);
                $browse.prop('disabled', busy);
                $upload.toggleClass('is-uploading', busy);
            }

            function hasDraggedFiles(e) {
                if (typeof self.hasFileDrag === 'function') {
                    return self.hasFileDrag(e);
                }

                var dataTransfer = e.originalEvent && e.originalEvent.dataTransfer;
                if (!dataTransfer || !dataTransfer.types) {
                    return false;
                }

                for (var i = 0; i < dataTransfer.types.length; i++) {
                    if (dataTransfer.types[i] === 'Files') {
                        return true;
                    }
                }

                return false;
            }

            function firstImageFile(fileList) {
                var files = Array.prototype.slice.call(fileList || []);
                for (var i = 0; i < files.length; i++) {
                    if (self.isPickedImageFile(files[i])) {
                        return files[i];
                    }
                }
                return null;
            }

            function selectLocalFile(file) {
                if (done) return;

                if (!file) {
                    $status.text('Drop an image file.');
                    return;
                }

                if (activeSelectionController) {
                    activeSelectionController.abort();
                    activeSelectionController = null;
                }

                var runId = ++selectionRun;
                $grid.find('.ai-image-result').removeClass('is-selected');
                setSelectionBusy(true);
                activeSelectionController = typeof AbortController !== 'undefined'
                    ? new AbortController()
                    : null;

                self.preparePickedImageFileSelection(file, function(message) {
                    $status.text(message);
                }, activeSelectionController ? activeSelectionController.signal : null).then(function(outcome) {
                    if (done || runId !== selectionRun) return;
                    activeSelectionController = null;
                    setSelectionBusy(false);

                    if (outcome.success) {
                        finish(outcome.selection, true);
                        return;
                    }

                    $input.prop('disabled', false).trigger('focus');
                });
            }

            function selectSourceUrl(image) {
                if (done || !image || !image.url) return;

                if (activeSelectionController) {
                    activeSelectionController.abort();
                    activeSelectionController = null;
                }

                finish(self.buildPickedImageResult(image, null), true);
            }

            function renderResults(results, append) {
                if (!append) {
                    $grid.empty();
                }

                results.forEach(function(image) {
                    var sourceUrl = image.url || '';
                    var $result = $('<div class="ai-image-result"></div>');
                    var $select = $('<button type="button" class="ai-image-result-select"></button>');
                    var $thumb = $('<span class="ai-image-result-thumb"></span>');
                    var $meta = $('<span class="ai-image-result-meta"></span>');
                    var $title = $('<span class="ai-image-result-title"></span>').text(image.title || 'Untitled');
                    var details = [image.creator, image.license].filter(function(part) { return !!part; }).join(' - ');
                    var $details = $('<span class="ai-image-result-details"></span>').text(details);
                    var $source = $('<button type="button" class="ai-image-result-source"></button>')
                        .text(self.getShortImageSourceDomain(sourceUrl))
                        .attr('title', 'Use source URL: ' + sourceUrl);

                    if (image.thumbnail || image.url) {
                        $thumb.append($('<img>').attr({
                            src: image.thumbnail || image.url,
                            alt: image.title || ''
                        }));
                    }

                    $meta.append($title, $details);
                    $select.append($thumb, $meta);
                    $result.append($select, $source);
                    $select.on('click', function() {
                        $grid.find('.ai-image-result').removeClass('is-selected');
                        $result.addClass('is-selected');
                        setSelectionBusy(true);
                        var runId = ++selectionRun;
                        activeSelectionController = typeof AbortController !== 'undefined'
                            ? new AbortController()
                            : null;
                        self.preparePickedImageSelection(image, args, function(message) {
                            $status.text(message);
                        }, activeSelectionController ? activeSelectionController.signal : null).then(function(outcome) {
                            if (done) return;
                            if (runId !== selectionRun) return;
                            activeSelectionController = null;

                            if (outcome.success) {
                                finish(outcome.selection, true);
                                return;
                            }

                            setSelectionBusy(false);
                            $result.removeClass('is-selected');
                            $input.prop('disabled', false).trigger('focus');
                        });
                    });
                    $source.on('click', function() {
                        selectSourceUrl(image);
                    });
                    $grid.append($result);
                });
            }

            function searchImages(nextPage) {
                if (done) return;

                var nextQuery = $input.val().trim();
                if (!nextQuery) {
                    $status.text('Enter a search term.');
                    return;
                }

                if (nextPage) {
                    page++;
                } else {
                    page = 1;
                    $grid.empty();
                }

                $status.text('Searching...');
                $search.prop('disabled', true);
                $more.prop('disabled', true);

                self.fetchImageResults(nextQuery, page).then(function(results) {
                    if (done) return;

                    if (!nextPage && results.length === 0) {
                        var broaderQuery = self.getBroaderImageQuery(nextQuery);
                        if (broaderQuery && broaderQuery !== nextQuery && lastFallbackFrom !== nextQuery) {
                            lastFallbackFrom = nextQuery;
                            page = 1;
                            $input.val(broaderQuery);
                            $status.text('Trying broader search: ' + broaderQuery);
                            $more.prop('hidden', true);

                            return self.fetchImageResults(broaderQuery, page).then(function(broaderResults) {
                                if (done) return;
                                renderResults(broaderResults, false);
                                if (broaderResults.length > 0) {
                                    $status.text('');
                                    $more.prop('hidden', false);
                                } else {
                                    $status.text('No images found.');
                                }
                            });
                        }
                    }

                    renderResults(results, nextPage);
                    if (results.length > 0) {
                        $status.text('');
                        $more.prop('hidden', false);
                    } else {
                        $status.text(nextPage ? 'No more images.' : 'No images found.');
                        if (!nextPage) {
                            $more.prop('hidden', true);
                        }
                    }
                }).catch(function() {
                    if (!done) {
                        $status.text('Image search failed.');
                    }
                }).then(function() {
                    if (!done) {
                        $search.prop('disabled', false);
                        $more.prop('disabled', false);
                    }
                    self.scrollToBottom();
                });
            }

            $search.on('click', function() {
                searchImages(false);
            });

            $more.on('click', function() {
                searchImages(true);
            });

            $browse.on('click', function(e) {
                e.preventDefault();
                if (!$browse.prop('disabled')) {
                    $fileInput.trigger('click');
                }
            });

            $fileInput.on('change', function() {
                selectLocalFile(firstImageFile(this.files));
                this.value = '';
            });

            $cancel.on('click', function() {
                $status.text('Cancelled');
                finish({ cancelled: true, message: 'User cancelled image selection' });
            });

            $close.on('click', function() {
                $status.text('Cancelled');
                finish({ cancelled: true, message: 'User cancelled image selection' });
            });

            $reopen.on('click', function() {
                $overlay.show();
                $input.trigger('focus');
            });

            $overlay.on('keydown', function(e) {
                if (e.which === 27) {
                    e.preventDefault();
                    finish({ cancelled: true, message: 'User cancelled image selection' });
                }
            });

            $dialog.on('dragenter dragover', function(e) {
                if (!hasDraggedFiles(e)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                $upload.addClass('is-drag-over');
                if (e.originalEvent && e.originalEvent.dataTransfer) {
                    e.originalEvent.dataTransfer.dropEffect = 'copy';
                }
            });

            $dialog.on('dragleave', function(e) {
                if (!hasDraggedFiles(e)) {
                    return;
                }
                var related = e.relatedTarget || (e.originalEvent && e.originalEvent.relatedTarget);
                if (!related || !$.contains($dialog[0], related)) {
                    $upload.removeClass('is-drag-over');
                }
            });

            $dialog.on('drop', function(e) {
                if (!hasDraggedFiles(e)) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                $upload.removeClass('is-drag-over');

                var files = e.originalEvent && e.originalEvent.dataTransfer
                    ? e.originalEvent.dataTransfer.files
                    : null;
                selectLocalFile(firstImageFile(files));
            });

            $input.on('keydown', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    searchImages(false);
                } else if (e.which === 27) {
                    e.preventDefault();
                    finish({ cancelled: true, message: 'User cancelled image selection' });
                }
            });

            searchImages(false);
        },

        renderPickedImageOutput: function($card, output) {
            if (!output) return false;

            if (output.cancelled) {
                $card.append($('<div class="ai-tool-output ai-picked-image-output"></div>').text('No image selected.'));
                return true;
            }

            if (!output.url) return false;

            var $output = $('<div class="ai-tool-output ai-picked-image-output"></div>');
            var $summary = $('<div class="ai-picked-image-summary"></div>');
            var $meta = $('<div class="ai-picked-image-meta"></div>');
            var $title = $('<strong></strong>').text(output.title || 'Selected image');
            var detailsParts = [];
            if (output.attachment_id) {
                detailsParts.push('Attachment #' + output.attachment_id);
            } else if (output.external) {
                detailsParts.push('External URL');
            }
            if (output.creator) detailsParts.push(output.creator);
            if (output.license) detailsParts.push(output.license);
            if (output.note) detailsParts.push(output.note);
            var details = detailsParts.join(' - ');
            var $details = $('<span></span>').text(details);
            var $link = $('<a target="_blank" rel="noopener noreferrer">Open image</a>').attr('href', output.source_url || output.url || output.landing_url);

            if (output.thumbnail || output.url) {
                $summary.append($('<img>').attr({
                    src: output.thumbnail || output.url,
                    alt: output.title || ''
                }));
            }

            $meta.append($title, $details, $link);
            $summary.append($meta);
            $output.append($summary);
            $card.append($output);
            return true;
        },

        renderToolResultOutput: function($card, toolName, output) {
            if (!output) return;
            if (toolName === 'navigate') return;
            if (toolName === 'pick_image' && this.renderPickedImageOutput($card, output)) {
                return;
            }
            if (toolName === 'rest_api') {
                this.renderRestApiResultLinks($card, output);
            }

            var outputText = '';
            if (output.ability !== undefined && output.success !== undefined) {
                var r = output.result;
                if (r !== null && r !== undefined) {
                    outputText = typeof r === 'string' ? r : JSON.stringify(r, null, 2);
                }
            } else {
                if (output.output) outputText += output.output;
                if (output.result !== undefined && output.result !== null) {
                    var rs = typeof output.result === 'string' ? output.result : JSON.stringify(output.result, null, 2);
                    if (outputText) outputText += '\n';
                    outputText += rs;
                }
                if (!outputText.trim() && typeof output === 'object') {
                    outputText = JSON.stringify(output, null, 2);
                }
            }
            if (!outputText.trim()) return;
            var lineCount = (outputText.match(/\n/g) || []).length + 1;
            var autoExpand = lineCount <= 10 && toolName !== 'db_query';
            var $output = $('<div class="ai-tool-output">' +
                '<div class="ai-action-preview' + (autoExpand ? ' expanded' : '') + '">' +
                '<button type="button" class="ai-action-preview-toggle">' +
                '<span class="ai-action-preview-icon" aria-hidden="true">&gt;</span>' +
                'Result (' + lineCount + ' line' + (lineCount !== 1 ? 's' : '') + ')' +
                '</button>' +
                '<div class="ai-action-preview-content"><pre class="ai-code-preview"></pre></div>' +
                '</div></div>');
            $output.find('.ai-code-preview').text(outputText);
            $card.append($output);
        },

        renderRestApiResultLinks: function($card, output) {
            if (!output || typeof output !== 'object') return;

            var editUrl = output.edit_url || output.editUrl || '';
            var viewUrl = output.view_url || output.link || '';
            if (!editUrl && !viewUrl) return;

            var $links = $('<div class="ai-tool-result-links"></div>');
            if (editUrl) {
                $links.append($('<a target="_blank" rel="noopener noreferrer">Edit</a>').attr('href', editUrl));
            }
            if (viewUrl) {
                $links.append($('<a target="_blank" rel="noopener noreferrer">View</a>').attr('href', viewUrl));
            }
            $card.append($links);
        },

        addToolUseMessage: function(toolName, input, $container, result) {
            var self = this;
            var $messages = $container || $('#ai-assistant-messages');
            var description = this.getActionDescription(toolName, input || {});

            var $card = $('<div class="ai-tool-card ai-tool-card-completed">' +
                '<div class="ai-tool-card-header">' +
                '<span class="ai-tool-card-name">' + this.escapeHtml(toolName) + '</span>' +
                '<span class="ai-tool-card-status">Completed</span>' +
                '</div>' +
                '<div class="ai-tool-card-desc">' + this.escapeHtml(description) + '</div>' +
                '<div class="ai-tool-card-preview"></div>' +
                '</div>');

            // Add input preview if available
            var preview = this.getActionContentPreview(toolName, input || {});
            if (preview) {
                var previewLabel = preview.isEdit ? 'Show changes' : 'Show content';
                var contentStr = typeof preview.content === 'string' ? preview.content : String(preview.content || '');
                contentStr = contentStr.trim();
                var lineCount = (contentStr.match(/\n/g) || []).length + 1;
                var autoExpand = lineCount <= 5;
                var previewHtml = '<div class="ai-action-preview' + (autoExpand ? ' expanded' : '') + '"' +
                    ' data-language="' + (preview.language || '') + '"' +
                    ' data-is-edit="' + (preview.isEdit ? '1' : '0') + '">' +
                    '<button type="button" class="ai-action-preview-toggle">' +
                    '<span class="ai-action-preview-icon" aria-hidden="true">&gt;</span>' +
                    previewLabel + ' (' + lineCount + ' line' + (lineCount !== 1 ? 's' : '') + ')</button>' +
                    '<div class="ai-action-preview-content"><pre class="ai-code-preview"></pre></div>' +
                    '</div>';
                $card.find('.ai-tool-card-preview').html(previewHtml);
                this.highlightCode($card.find('.ai-code-preview')[0], contentStr, preview.language, preview.isEdit);
            }

            // Add result output
            if (result !== undefined) {
                this.renderToolResultOutput($card, toolName, result);
            }

            $messages.append($card);
        },

        toolGroupLabel: function(toolUses) {
            var count = toolUses.length;
            var seen = {};
            var names = [];
            toolUses.forEach(function(tu) {
                if (!seen[tu.name]) { seen[tu.name] = true; names.push(tu.name); }
            });
            return (count === 1 ? '1 tool' : count + ' tools') + ': ' + names.join(', ');
        },

        addToolUseGroup: function(toolUses) {
            var self = this;
            var label = this.toolGroupLabel(toolUses);
            var $details = $('<details class="ai-tool-cards-group"><summary class="ai-tool-cards-summary">' + this.escapeHtml(label) + '</summary></details>');
            toolUses.forEach(function(tu) {
                self.addToolUseMessage(tu.name, tu.input, $details, tu.result);
            });
            $('#ai-assistant-messages').append($details);
        },

        formatContent: function(content) {
            if (!content) return '';

            var self = this;
            var codeBlocks = [];

            content = String(content).replace(/\r\n?/g, '\n').replace(/\s+$/, '');

            content = content.replace(/```([\w-]+)?[^\S\n]*\n([\s\S]*?)```/g, function(match, lang, code) {
                var token = '@@AI_ASSISTANT_CODE_BLOCK_' + codeBlocks.length + '@@';
                codeBlocks.push(
                    '<pre><code class="language-' + self.escapeHtml(lang || '') + '">' +
                    self.escapeHtml(code.replace(/^\n+|\n+$/g, '')) +
                    '</code></pre>'
                );
                return '\n\n' + token + '\n\n';
            });

            content = this.escapeHtml(content);

            // Inline markdown.
            content = content.replace(/`([^`]+)`/g, '<code>$1</code>');
            content = content.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            content = content.replace(/\*([^*]+)\*/g, '<em>$1</em>');
            content = content.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');

            // Block markdown.
            content = content.replace(/^### (.+)$/gm, '<h4>$1</h4>');
            content = content.replace(/^## (.+)$/gm, '<h3>$1</h3>');
            content = content.replace(/^# (.+)$/gm, '<h2>$1</h2>');

            content = this.renderMarkdownBlocks(content);
            content = content.replace(/@@AI_ASSISTANT_CODE_BLOCK_(\d+)@@/g, function(match, index) {
                return codeBlocks[Number(index)] || '';
            });

            return content;
        },

        renderMarkdownBlocks: function(content) {
            var html = [];
            var blocks = content.split(/\n{2,}/);

            blocks.forEach(function(block) {
                var lines = block.trim().split('\n');
                var paragraphLines = [];

                function flushParagraph() {
                    if (!paragraphLines.length) return;
                    html.push('<p>' + paragraphLines.join('<br>') + '</p>');
                    paragraphLines = [];
                }

                lines.forEach(function(line) {
                    if (!line.trim()) {
                        flushParagraph();
                        return;
                    }

                    if (/^<h[234]>.*<\/h[234]>$/.test(line) || /^@@AI_ASSISTANT_CODE_BLOCK_\d+@@$/.test(line)) {
                        flushParagraph();
                        html.push(line);
                        return;
                    }

                    paragraphLines.push(line);
                });

                flushParagraph();
            });

            return html.join('');
        },

        loadWelcomeMessage: function() {
            if (!this.isProviderConfigured()) {
                var hasConnectors = typeof aiAssistantProviders !== 'undefined' && aiAssistantProviders.source === 'connectors';
                var message;
                if (hasConnectors) {
                    var connectorsUrl = aiAssistantProviders.connectorsUrl || aiAssistantConfig.settingsUrl;
                    message = 'Welcome! No AI providers with API keys found. Please configure a provider in [Connectors](' + connectorsUrl + ') to start chatting.';
                } else {
                    message = 'Welcome! Please configure your API key in [Settings](' + aiAssistantConfig.settingsUrl + ') to start chatting.';
                }
                this.addMessage('system', message, 'ai-welcome-message');
            } else {
                var provider = this.getProvider();
                var model = this.getModel();
                var providerName = this.getProviderName(provider);
                var modelInfo = model ? ' (' + model + ')' : '';
                this.addMessage('assistant', 'Hello! I\'m your AI Assistant. I can help you manage your WordPress installation - read and modify files, manage plugins, query the database, and more. What would you like to do?', 'ai-welcome-message');
                this.addMessage('system', 'You\'re chatting with **' + providerName + '**' + modelInfo, 'ai-model-info');
                this.showModelUpgradeNotice(provider, model);
                this.showLegacyKeyMigrationNotice();
            }
        },

        showModelUpgradeNotice: function(provider, model) {
            if (!this.getModelUpgradeInfo) return;

            var upgrade = this.getModelUpgradeInfo(provider, model);
            if (!upgrade) return;

            var replacement = upgrade.replacement || '';
            var replacementText = replacement;
            if (upgrade.replacementName && upgrade.replacementName !== replacement) {
                replacementText = '**' + upgrade.replacementName + '** (`' + replacement + '`)';
            } else if (replacement) {
                replacementText = '`' + replacement + '`';
            }

            var statusText = upgrade.status === 'retired' ? 'retired' :
                             upgrade.status === 'deprecated' ? 'deprecated' : 'older';
            var message = 'Model warning: `' + model + '` is ' + statusText + '.';

            if (replacementText) {
                message += ' Use ' + replacementText + ' for newer results.';
            }
            if (upgrade.retirement) {
                message += ' Retirement: ' + upgrade.retirement + '.';
            }
            if (typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.settingsUrl) {
                message += ' Change it in [Settings](' + aiAssistantConfig.settingsUrl + ').';
            }

            this.addMessage('system', message, 'ai-model-warning');
        },

        /**
         * Show a one-time notice when Connectors is available but the user
         * still has legacy API keys in localStorage from before the upgrade.
         */
        showLegacyKeyMigrationNotice: function() {
            var hasConnectors = typeof aiAssistantProviders !== 'undefined' && aiAssistantProviders.source === 'connectors';
            if (!hasConnectors) return;

            // Already dismissed?
            if (this.getSetting('legacyKeyNoticeDismissed')) return;

            // Check for legacy localStorage API keys
            var hasLegacyKeys = !!(this.getSetting('anthropicApiKey') || this.getSetting('openaiApiKey'));
            if (!hasLegacyKeys) return;

            var connectorsUrl = aiAssistantProviders.connectorsUrl || aiAssistantConfig.settingsUrl;
            this.addMessage('system',
                'Your API keys are stored in this browser from a previous setup. ' +
                'Your site now supports [WordPress Connectors](' + connectorsUrl + ') — ' +
                'you can manage providers there instead, and they\'ll work across all your devices. ' +
                'Once configured, you can clear your browser keys in [Settings](' + aiAssistantConfig.settingsUrl + ').',
                'ai-migration-notice'
            );

            // Don't show again
            this.setSetting('legacyKeyNoticeDismissed', '1');
        },

        loadConversationWelcome: function(provider, model) {
            this.addMessage('assistant', 'Hello! I\'m your AI Assistant. I can help you manage your WordPress installation - read and modify files, manage plugins, query the database, and more. What would you like to do?', 'ai-welcome-message');
            // Only show model info if the conversation has it saved
            if (provider) {
                var providerName = this.getProviderName(provider);
                var modelInfo = model ? ' (' + model + ')' : '';
                this.addMessage('system', 'You\'re chatting with **' + providerName + '**' + modelInfo, 'ai-model-info');
                this.showModelUpgradeNotice(provider, model);
            }
        },

        getProviderName: function(provider) {
            return provider === 'anthropic' ? 'Anthropic' :
                   provider === 'openai' ? 'OpenAI' :
                   provider === 'local' ? 'Local LLM' : provider;
        },

        rebuildMessagesUI: function() {
            var self = this;

            // First pass: collect resolved tool IDs and their results
            var resolvedToolIds = {};
            var toolResults = {}; // tool_use_id -> parsed result object
            this.messages.forEach(function(msg) {
                if (msg.role === 'user' && Array.isArray(msg.content)) {
                    msg.content.forEach(function(block) {
                        if (block.type === 'tool_result' && block.tool_use_id) {
                            resolvedToolIds[block.tool_use_id] = true;
                            var raw = Array.isArray(block.content)
                                ? (block.content[0] && block.content[0].text || '')
                                : (block.content || '');
                            try { toolResults[block.tool_use_id] = JSON.parse(raw); } catch(e) { toolResults[block.tool_use_id] = raw; }
                        }
                    });
                }
                if (msg.role === 'tool' && msg.tool_call_id) {
                    resolvedToolIds[msg.tool_call_id] = true;
                    try { toolResults[msg.tool_call_id] = JSON.parse(msg.content); } catch(e) { toolResults[msg.tool_call_id] = msg.content; }
                }
            });

            // Collect pending tool calls to process at the end
            var pendingToolCalls = [];
            // Accumulate resolved tool uses across consecutive tool-only turns
            var accumulatedToolUses = [];

            var flushToolUseGroup = function() {
                if (accumulatedToolUses.length > 0) {
                    self.addToolUseGroup(accumulatedToolUses);
                    accumulatedToolUses = [];
                }
            };

            // Second pass: render messages
            this.messages.forEach(function(msg) {
                if (msg.role === 'user') {
                    // Real user text flushes accumulated tool uses first
                    var hasText = typeof msg.content === 'string'
                        ? msg.content.trim()
                        : Array.isArray(msg.content) && msg.content.some(function(b) { return b.type === 'text' && b.text && b.text.trim(); });
                    if (hasText) {
                        flushToolUseGroup();
                    }
                    if (typeof msg.content === 'string' && msg.content.trim()) {
                        self.addMessage('user', msg.content);
                    } else if (Array.isArray(msg.content)) {
                        msg.content.forEach(function(block) {
                            if (block.type === 'text' && block.text && block.text.trim()) {
                                self.addMessage('user', block.text);
                            }
                            // tool_result blocks are skipped - shown with tool_use
                        });
                    }
                } else if (msg.role === 'assistant') {
                    if (typeof msg.content === 'string' && msg.content.trim()) {
                        flushToolUseGroup();
                        self.addMessage('assistant', msg.content);
                    }
                    if (Array.isArray(msg.content)) {
                        var hasAssistantText = msg.content.some(function(b) { return b.type === 'text' && b.text && b.text.trim(); });
                        if (hasAssistantText) flushToolUseGroup();
                        msg.content.forEach(function(block) {
                            if (block.type === 'text' && block.text && block.text.trim()) {
                                self.addMessage('assistant', block.text);
                            } else if (block.type === 'tool_use') {
                                if (resolvedToolIds[block.id]) {
                                    accumulatedToolUses.push({ name: block.name, input: block.input || block.arguments || {}, result: toolResults[block.id] });
                                } else {
                                    pendingToolCalls.push({
                                        id: block.id,
                                        name: block.name,
                                        arguments: block.input || block.arguments || {}
                                    });
                                }
                            }
                        });
                    }
                    // OpenAI format
                    if (msg.tool_calls && Array.isArray(msg.tool_calls)) {
                        msg.tool_calls.forEach(function(tc) {
                            var args = tc.function ? tc.function.arguments : tc.arguments;
                            var name = tc.function ? tc.function.name : tc.name;
                            try {
                                if (typeof args === 'string') args = JSON.parse(args);
                            } catch(e) { args = {}; }
                            if (resolvedToolIds[tc.id]) {
                                accumulatedToolUses.push({ name: name, input: args || {}, result: toolResults[tc.id] });
                            } else {
                                pendingToolCalls.push({ id: tc.id, name: name, arguments: args || {} });
                            }
                        });
                    }
                } else if (msg.role === 'tool') {
                    // Skip - shown with tool_use
                }
            });
            flushToolUseGroup();

            // Process pending tool calls through normal flow
            if (pendingToolCalls.length > 0) {
                var provider = this.conversationProvider || this.getProvider();
                this.streamComplete = true;
                this.executingToolCount = 0;
                this.pendingToolResults = [];
                this.pendingToolChecks = 0;
                this.processToolCalls(pendingToolCalls, provider === 'anthropic' ? 'anthropic' : 'openai');
            }

            // Show container and scroll to bottom
            var $messages = $('#ai-assistant-messages');
            $messages.css('visibility', 'visible');
            this.scrollToBottom(true);
        },

        estimateTokens: function() {
            var totalChars = this.systemPrompt.length;

            this.messages.forEach(function(msg) {
                if (typeof msg.content === 'string') {
                    totalChars += msg.content.length;
                } else if (Array.isArray(msg.content)) {
                    msg.content.forEach(function(block) {
                        if (block.type === 'text' && block.text) {
                            totalChars += block.text.length;
                        } else if (block.type === 'tool_use' && block.input) {
                            totalChars += JSON.stringify(block.input).length;
                        } else if (block.type === 'tool_result' && block.content) {
                            totalChars += block.content.length;
                        }
                    });
                }
                if (msg.tool_calls) {
                    totalChars += JSON.stringify(msg.tool_calls).length;
                }
            });

            return Math.ceil(totalChars / 4);
        },

        updateTokenCount: function() {
            var tokens = this.estimateTokens();
            var display = tokens.toLocaleString() + ' tokens';

            var $counter = $('#ai-token-count');
            $counter.text(display);
            $counter.removeClass('ai-tokens-warning ai-tokens-danger');

            if (tokens > 100000) {
                $counter.addClass('ai-tokens-danger');
            } else if (tokens > 50000) {
                $counter.addClass('ai-tokens-warning');
            }
        },

        toolCardsState: {},

        formatBytes: function(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },

        highlightCode: function(element, code, language, isEdit) {
            // Clear existing content
            element.textContent = '';

            if (isEdit) {
                // For diffs, render with line-by-line coloring using DOM methods
                var lines = code.split('\n');
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i];
                    var span = document.createElement('span');
                    span.className = 'ai-diff-line';
                    span.textContent = line;

                    if (line.startsWith('+ ')) {
                        span.classList.add('ai-diff-add');
                    } else if (line.startsWith('- ')) {
                        span.classList.add('ai-diff-remove');
                    } else if (line.startsWith('---')) {
                        span.classList.add('ai-diff-header');
                    } else if (line.startsWith('  ')) {
                        span.classList.add('ai-diff-context');
                    }

                    element.appendChild(span);
                }
                return;
            }

            // Try CodeMirror syntax highlighting
            if (language && wp.CodeMirror && wp.CodeMirror.runMode) {
                var CM = wp.CodeMirror;
                // Only map languages that differ from their CodeMirror mode name
                var modeMap = {
                    'js': 'javascript',
                    'html': 'htmlmixed',
                    'json': {name: 'javascript', json: true}
                };
                var modeName = modeMap[language] || language;

                try {
                    var codeToHighlight = code;
                    var prependedPhpTag = false;

                    // PHP needs <?php tag for proper highlighting
                    if (modeName === 'php' && !code.trim().startsWith('<?')) {
                        codeToHighlight = '<?php\n' + code;
                        prependedPhpTag = true;
                    }

                    var mode = CM.getMode({}, modeName);
                    element.classList.add('cm-s-default');
                    CM.runMode(codeToHighlight, mode, element);

                    // Remove the prepended <?php tag from output
                    if (prependedPhpTag) {
                        var firstChild = element.firstChild;
                        if (firstChild && firstChild.classList && firstChild.classList.contains('cm-meta')) {
                            firstChild.remove();
                            // Also remove the newline text node if present
                            if (element.firstChild && element.firstChild.nodeType === 3 && element.firstChild.textContent === '\n') {
                                element.firstChild.remove();
                            }
                        }
                    } else {
                        // Add line numbers (only when we didn't prepend <?php)
                        this.addLineNumbers(element);
                    }
                    return;
                } catch (e) {
                    console.warn('[AI Assistant] CodeMirror.runMode failed for mode:', modeName, e);
                }
            }

            // Fallback: plain escaped text
            element.textContent = code;
        },

        addLineNumbers: function(element) {
            // Get current HTML and split into lines
            var html = element.innerHTML;
            var lines = html.split('\n');

            // Build new HTML with line numbers (no newlines between - they're block elements)
            var numberedHtml = lines.map(function(line, i) {
                var lineNum = i + 1;
                return '<span class="ai-line"><span class="ai-line-number">' + lineNum + '</span><span class="ai-line-content">' + (line || ' ') + '</span></span>';
            }).join('');

            element.innerHTML = numberedHtml;
            element.classList.add('ai-code-with-lines');
        },

        getToolCardsContainer: function() {
            var $container = $('#ai-assistant-tool-cards');
            if ($container.length === 0) {
                $container = $('<details id="ai-assistant-tool-cards" open><summary class="ai-tool-cards-summary">Tools</summary></details>');
                $('#ai-assistant-messages').append($container);
            } else {
                // Move container to end of messages if it already exists
                // This handles cases where LLM responds multiple times with tool calls
                $container.attr('open', '');
                $('#ai-assistant-messages').append($container);
            }
            return $container;
        },

        updateToolCardsSummary: function() {
            var $container = $('#ai-assistant-tool-cards');
            if (!$container.length) return;
            var state = this.toolCardsState;
            var ids = Object.keys(state);
            var total = ids.length;
            var done = ids.filter(function(id) {
                var s = state[id].state;
                return s === 'completed' || s === 'error' || s === 'skipped';
            }).length;
            var seen = {};
            var names = [];
            ids.forEach(function(id) { var n = state[id].name; if (n && !seen[n]) { seen[n] = true; names.push(n); } });
            var base = (total === 1 ? '1 tool' : total + ' tools') + (names.length ? ': ' + names.join(', ') : '');
            if (done === total && total > 0) {
                $container.removeAttr('open');
                $container.find('.ai-tool-cards-summary').text(base);
            } else {
                $container.find('.ai-tool-cards-summary').text(base + ' \u2013 ' + done + '/' + total + ' done');
            }
        },

        showToolProgress: function(toolName, bytesReceived, toolId, partialInput) {
            toolId = toolId || 'tool-' + toolName;

            if (!this.toolCardsState[toolId]) {
                this.toolCardsState[toolId] = {
                    name: toolName,
                    bytes: 0,
                    state: 'generating',
                    partialDesc: null
                };
                this.addToolCard(toolId, toolName);
            }

            this.toolCardsState[toolId].bytes = bytesReceived;
            this.updateToolCardProgress(toolId, bytesReceived);

            // Try to extract description from partial JSON
            if (partialInput) {
                var desc = this.extractPartialDescription(toolName, partialInput);
                if (desc && desc !== this.toolCardsState[toolId].partialDesc) {
                    this.toolCardsState[toolId].partialDesc = desc;
                    var $card = $('[data-tool-id="' + toolId + '"]');
                    if ($card.length) {
                        $card.find('.ai-tool-card-desc').text(desc);
                    }
                }
            }
        },

        extractPartialDescription: function(toolName, partialJson) {
            var pathMatch, match;
            switch (toolName) {
                case 'write_file':
                case 'read_file':
                case 'delete_file':
                case 'edit_file':
                    pathMatch = partialJson.match(/"path"\s*:\s*"([^"]+)"/);
                    if (pathMatch) {
                        var verb = toolName === 'write_file' ? 'Write' :
                                   toolName === 'read_file' ? 'Read' :
                                   toolName === 'delete_file' ? 'Delete' : 'Edit';
                        return verb + ': ' + pathMatch[1];
                    }
                    break;
                case 'run_php':
                    // Can't really preview code meaningfully
                    return null;
                case 'rest_api':
                    var methodMatch = partialJson.match(/"method"\s*:\s*"([^"]+)"/);
                    pathMatch = partialJson.match(/"path"\s*:\s*"([^"]+)"/);
                    if (methodMatch && pathMatch) {
                        return methodMatch[1].toUpperCase() + ' ' + pathMatch[1];
                    }
                    break;
                case 'search_content':
                    match = partialJson.match(/"needle"\s*:\s*"([^"]+)"/);
                    if (match) {
                        var needle = match[1].substring(0, 30);
                        return 'Search for: "' + needle + (match[1].length > 30 ? '...' : '') + '"';
                    }
                    break;
                case 'db_query':
                    match = partialJson.match(/"sql"\s*:\s*"([^"]+)"/);
                    if (match) {
                        return this.describeSql(match[1].replace(/\\n/g, ' ').replace(/\\t/g, ' '));
                    }
                    break;
                case 'ability':
                case 'execute_ability':
                    match = partialJson.match(/"ability"\s*:\s*"([^"]+)"/);
                    if (match) {
                        var abilityDesc = 'Execute: ' + match[1];
                        var argMatches = partialJson.match(/"(?:query|name|username|group_slug)"\s*:\s*"([^"]+)"/);
                        if (argMatches) abilityDesc += ' (' + argMatches[1] + ')';
                        return abilityDesc;
                    }
                    break;
            }
            return null;
        },

        addToolCard: function(toolId, toolName) {
            var $container = this.getToolCardsContainer();
            var description = this.getActionDescription(toolName, {});

            var $card = $('<div class="ai-tool-card ai-tool-card-generating" data-tool-id="' + toolId + '">' +
                '<div class="ai-tool-card-header">' +
                '<span class="ai-tool-card-spinner"></span>' +
                '<span class="ai-tool-card-name">' + this.escapeHtml(toolName) + '</span>' +
                '<span class="ai-tool-card-status">Generating...</span>' +
                '<span class="ai-tool-card-size">0 B</span>' +
                '</div>' +
                '<div class="ai-tool-card-desc">' + this.escapeHtml(description) + '</div>' +
                '<div class="ai-tool-card-preview"></div>' +
                '<div class="ai-tool-card-actions"></div>' +
                '</div>');

            $container.append($card);
            this.updateToolCardsSummary();
            this.scrollToBottom();
        },

        updateToolCardProgress: function(toolId, bytes) {
            if (this.toolCardsState[toolId]) {
                this.toolCardsState[toolId].bytes = bytes;
            }
            var $card = $('[data-tool-id="' + toolId + '"]');
            if ($card.length) {
                $card.find('.ai-tool-card-size').text(this.formatBytes(bytes));
            }
        },

        updateToolCardDescription: function(toolId, toolName, args) {
            var $card = $('[data-tool-id="' + toolId + '"]');

            // Create card if it doesn't exist (for providers that report tools at completion)
            if (!$card.length) {
                this.showToolProgress(toolName, JSON.stringify(args || {}).length, toolId);
                $card = $('[data-tool-id="' + toolId + '"]');
            }

            if ($card.length) {
                if (this.toolCardsState[toolId]) {
                    this.toolCardsState[toolId].arguments = args;
                }
                var description = this.getActionDescription(toolName, args);
                $card.find('.ai-tool-card-desc').text(description);

                // Show size now that tool is fully received
                var argsStr = JSON.stringify(args || {});
                var bytes = argsStr.length;
                $card.find('.ai-tool-card-size').text(this.formatBytes(bytes));

                var preview = this.getActionContentPreview(toolName, args);
                if (preview) {
                    var previewLabel = preview.isEdit ? 'Show changes' : 'Show content';
                    var contentStr = typeof preview.content === 'string' ? preview.content : String(preview.content || '');
                    contentStr = contentStr.trim();
                    var lineCount = (contentStr.match(/\n/g) || []).length + 1;
                    var autoExpand = lineCount <= 5;
                    var previewHtml = '<div class="ai-action-preview' + (autoExpand ? ' expanded' : '') + '"' +
                        ' data-language="' + (preview.language || '') + '"' +
                        ' data-is-edit="' + (preview.isEdit ? '1' : '0') + '">' +
                        '<button type="button" class="ai-action-preview-toggle">' +
                        '<span class="ai-action-preview-icon" aria-hidden="true">&gt;</span>' +
                        previewLabel + ' (' + lineCount + ' line' + (lineCount !== 1 ? 's' : '') + ')</button>' +
                        '<div class="ai-action-preview-content"><pre class="ai-code-preview"></pre></div>' +
                        '</div>';
                    $card.find('.ai-tool-card-preview').html(previewHtml);

                    var $pre = $card.find('.ai-code-preview');
                    this.highlightCode($pre[0], contentStr, preview.language, preview.isEdit);
                }
            }
        },

        setToolCardState: function(toolId, state, options) {
            options = options || {};
            var $card = $('[data-tool-id="' + toolId + '"]');
            if (!$card.length) return;

            $card.removeClass('ai-tool-card-generating ai-tool-card-ready ai-tool-card-checking ai-tool-card-pending ai-tool-card-executing ai-tool-card-completed ai-tool-card-error ai-tool-card-skipped');
            $card.addClass('ai-tool-card-' + state);

            var $status = $card.find('.ai-tool-card-status');
            var $actions = $card.find('.ai-tool-card-actions');
            var $spinner = $card.find('.ai-tool-card-spinner');

            switch (state) {
                case 'ready':
                    $status.text('Ready');
                    $spinner.hide();
                    $actions.empty();
                    break;
                case 'checking':
                    $status.text(options.message || 'Checking ability...');
                    $spinner.show();
                    $actions.empty();
                    $card.find('.ai-tool-params, .ai-ability-approval-slot, .ai-ability-approval-details').remove();
                    break;
                case 'pending':
                    $status.text('Waiting for approval');
                    $spinner.hide();
                    var cardState = this.toolCardsState[toolId];
                    var $params = $card.find('.ai-tool-params');
                    if (!$params.length && this.toolCardsState[toolId] && this.toolCardsState[toolId].arguments) {
                        var pendingArgs = this.toolCardsState[toolId].arguments;
                        var cardName = this.toolCardsState[toolId].name;
                        // Skip Parameters block if a content preview already shows the arguments
                        var hasPreview = !!this.getActionContentPreview(cardName, pendingArgs);
                        if (!hasPreview) {
                            var pendingArgsJson = JSON.stringify(pendingArgs, null, 2);
                            if (pendingArgsJson !== 'null') {
                                $params = $('<details class="ai-tool-params"><summary>Parameters</summary><pre></pre></details>');
                                $params.find('pre').text(pendingArgsJson);
                                $card.find('.ai-tool-card-actions').before($params);
                            }
                        }
                    }
                    $card.find('.ai-ability-approval-slot').remove();
                    $card.find('.ai-tool-card-desc .ai-ability-info-toggle').remove();
                    var hasAbilityDetails = !!(cardState && cardState.abilityDetails);
                    if (hasAbilityDetails) {
                        $card.find('.ai-tool-card-desc').append(
                            $('<button class="ai-ability-info-toggle" type="button" aria-label="Show ability details" aria-expanded="false">What\'s this?</button>')
                                .attr('data-tool-id', toolId)
                        );
                        $card.find('.ai-tool-card-actions').before(
                            $('<div class="ai-ability-approval-slot" hidden></div>').attr('data-tool-id', toolId)
                        );
                    }
                    var isAbilityExecute = cardState && cardState.name === 'ability' &&
                        cardState.arguments && cardState.arguments.action === 'execute' &&
                        cardState.arguments.ability;
                    var isRestApiWrite = cardState && cardState.name === 'rest_api' &&
                        cardState.arguments && (cardState.arguments.method || 'GET').toUpperCase() !== 'GET';
                    var restApiPattern = isRestApiWrite
                        ? (cardState.arguments.method || 'POST').toUpperCase() + ' ' + (cardState.arguments.path || '/')
                        : '';
                    $actions.html(
                        '<button class="ai-tool-approve ai-approve-btn" data-tool-id="' + toolId + '">Approve</button>' +
                        (isAbilityExecute ? '<button class="ai-tool-approve-always ai-always-approve-btn" data-tool-id="' + toolId + '" data-ability="' + this.escapeHtml(cardState.arguments.ability) + '">Always approve</button>' : '') +
                        (isRestApiWrite ? '<button class="ai-tool-approve-always ai-always-approve-btn" data-tool-id="' + toolId + '" data-rest-api="' + this.escapeHtml(restApiPattern) + '">Always approve</button>' : '') +
                        '<button class="ai-tool-skip ai-skip-btn" data-tool-id="' + toolId + '">Skip</button>'
                    );
                    break;
                case 'executing':
                    $status.text('Executing...');
                    $spinner.show();
                    $actions.empty();
                    $card.find('.ai-tool-params, .ai-ability-approval-slot, .ai-ability-approval-details').remove();
                    break;
                case 'completed':
                    $status.text(options.message || 'Completed');
                    $spinner.hide();
                    $actions.empty();
                    $card.find('.ai-tool-card-size').hide();
                    if (options.output) {
                        var toolCardName = this.toolCardsState[toolId] && this.toolCardsState[toolId].name;
                        this.renderToolResultOutput($card, toolCardName, options.output);
                    }
                    break;
                case 'error':
                    $status.text(options.message || 'Error');
                    $spinner.hide();
                    $actions.empty();
                    break;
                case 'skipped':
                    $status.text('Skipped by user');
                    $spinner.hide();
                    $actions.empty();
                    $card.find('.ai-tool-params, .ai-ability-approval-slot, .ai-ability-approval-details').remove();
                    break;
            }

            if (this.toolCardsState[toolId]) {
                this.toolCardsState[toolId].state = state;
            }

            this.updateToolCardsSummary();
            this.scrollToBottom();
        },

        clearToolCards: function() {
            this.toolCardsState = {};
            var $container = $('#ai-assistant-tool-cards');
            var $finished = $container.find('.ai-tool-card-completed, .ai-tool-card-error, .ai-tool-card-skipped');
            if ($finished.length > 0) {
                var state = this.toolCardsState;
                var seen = {}, names = [];
                Object.keys(state).forEach(function(id) { var n = state[id].name; if (n && !seen[n]) { seen[n] = true; names.push(n); } });
                var count = $finished.length;
                var label = (count === 1 ? '1 tool' : count + ' tools') + (names.length ? ': ' + names.join(', ') : '');
                var $group = $('<details class="ai-tool-cards-group"><summary class="ai-tool-cards-summary">' + this.escapeHtml(label) + '</summary></details>');
                $finished.appendTo($group);
                $group.insertBefore($container);
            }
            $container.remove();
        },

        hideToolProgress: function() {
            // Legacy compatibility - now just clears incomplete cards
            var self = this;
            Object.keys(this.toolCardsState).forEach(function(toolId) {
                if (self.toolCardsState[toolId].state === 'generating') {
                    $('[data-tool-id="' + toolId + '"]').remove();
                    delete self.toolCardsState[toolId];
                }
            });
        },

        deduplicateFileReads: function(newResults) {
            var newReadPaths = {};
            newResults.forEach(function(r) {
                if (r.name === 'read_file' && r.success && r.result && r.result.path) {
                    newReadPaths[r.result.path] = r.id;
                }
            });

            if (Object.keys(newReadPaths).length === 0) return;

            var oldToolIds = new Set();

            this.messages.forEach(function(msg) {
                if (msg.role === 'assistant' && Array.isArray(msg.content)) {
                    msg.content.forEach(function(block) {
                        if (block.type === 'tool_use' && block.name === 'read_file' &&
                            block.input && block.input.path && newReadPaths[block.input.path] &&
                            block.id !== newReadPaths[block.input.path]) {
                            oldToolIds.add(block.id);
                        }
                    });
                }
            });

            if (oldToolIds.size === 0) return;

            this.messages = this.messages.map(function(msg) {
                if (msg.role === 'assistant' && Array.isArray(msg.content)) {
                    msg.content = msg.content.filter(function(block) {
                        return !(block.type === 'tool_use' && oldToolIds.has(block.id));
                    });
                }
                if (msg.role === 'user' && Array.isArray(msg.content)) {
                    msg.content = msg.content.filter(function(block) {
                        return !(block.type === 'tool_result' && oldToolIds.has(block.tool_use_id));
                    });
                }
                if (msg.role === 'tool' && oldToolIds.has(msg.tool_call_id)) {
                    return null;
                }
                return msg;
            }).filter(function(msg) {
                if (msg === null) return false;
                if (Array.isArray(msg.content) && msg.content.length === 0) return false;
                return true;
            });
        }
    });

})(jQuery);
