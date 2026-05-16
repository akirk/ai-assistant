const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadUiMixin(config, globals) {
    const aiAssistant = {};
    globals = globals || {};
    const windowGlobals = {
        aiAssistant,
        aiAssistantConfig: config || {}
    };
    if (globals.location) {
        windowGlobals.location = globals.location;
    }
    if (globals.history) {
        windowGlobals.history = globals.history;
    }
    const context = {
        window: windowGlobals,
        aiAssistantConfig: config || {},
        fetch: globals.fetch || fetch,
        URL,
        jQuery: {
            extend(target, ...sources) {
                return Object.assign(target, ...sources);
            }
        },
        console
    };

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-ui.js'),
        'utf8'
    );
    vm.runInContext(source, context);
    return aiAssistant;
}

function imageResponse(body, type) {
    const blob = new Blob([body], { type });
    return {
        ok: true,
        status: 200,
        headers: {
            get(name) {
                return name.toLowerCase() === 'content-type' ? type : '';
            }
        },
        blob() {
            return Promise.resolve(blob);
        }
    };
}

describe('navigation suggestion links', function() {
    it('reloads only plain same-page ai-open navigation clicks', function() {
        let replacedUrl = '';
        let reloaded = false;
        let prevented = false;
        const location = {
            href: 'http://example.test/wp-admin/edit.php?post_type=page',
            reload() {
                reloaded = true;
            }
        };
        const assistant = loadUiMixin({}, {
            location,
            history: {
                replaceState(_state, _title, url) {
                    replacedUrl = url;
                    location.href = url;
                }
            }
        });

        function click(href, event) {
            prevented = false;
            reloaded = false;
            replacedUrl = '';
            return assistant.handleNavigationSuggestionClick(Object.assign({
                preventDefault() {
                    prevented = true;
                },
                button: 0
            }, event || {}), {
                getAttribute(name) {
                    return name === 'href' ? href : '';
                }
            });
        }

        const targetUrl = 'http://example.test/wp-admin/edit.php?post_type=page#ai-open';

        assert.strictEqual(click(targetUrl), true);
        assert.strictEqual(prevented, true);
        assert.strictEqual(replacedUrl, targetUrl);
        assert.strictEqual(location.href, targetUrl);
        assert.strictEqual(reloaded, true);

        location.href = 'http://example.test/wp-admin/edit.php?post_type=page';
        assert.strictEqual(click('http://example.test/wp-admin/edit.php?page=other#ai-open'), false);
        assert.strictEqual(prevented, false);
        assert.strictEqual(reloaded, false);

        assert.strictEqual(click(targetUrl, { ctrlKey: true }), false);
        assert.strictEqual(prevented, false);
        assert.strictEqual(reloaded, false);
    });
});

describe('model lifecycle notices', function() {
    it('renders a warning when the active model has a replacement', function() {
        const assistant = loadUiMixin({ settingsUrl: 'http://example.test/settings' });
        const messages = [];

        assistant.getModelUpgradeInfo = function() {
            return {
                severity: 'warning',
                status: 'deprecated',
                replacement: 'claude-sonnet-4-6',
                replacementName: 'Claude Sonnet 4.6',
                retirement: 'June 15, 2026'
            };
        };
        assistant.addMessage = function(role, content, extraClass) {
            messages.push({ role, content, extraClass });
        };

        assistant.showModelUpgradeNotice('anthropic', 'claude-sonnet-4-20250514');

        assert.strictEqual(messages.length, 1);
        assert.strictEqual(messages[0].role, 'system');
        assert.strictEqual(messages[0].extraClass, 'ai-model-warning');
        assert.match(messages[0].content, /deprecated/);
        assert.match(messages[0].content, /claude-sonnet-4-6/);
        assert.match(messages[0].content, /June 15, 2026/);
        assert.match(messages[0].content, /Settings/);
    });

    it('renders a note when a newer model exists but the active model is not outdated', function() {
        const assistant = loadUiMixin({ settingsUrl: 'http://example.test/settings' });
        const messages = [];

        assistant.getModelUpgradeInfo = function() {
            return {
                severity: 'note',
                status: 'newer_available',
                replacement: 'claude-sonnet-4-6',
                replacementName: 'Claude Sonnet 4.6'
            };
        };
        assistant.addMessage = function(role, content, extraClass) {
            messages.push({ role, content, extraClass });
        };

        assistant.showModelUpgradeNotice('anthropic', 'claude-sonnet-4-5-20250929');

        assert.strictEqual(messages.length, 1);
        assert.strictEqual(messages[0].role, 'system');
        assert.strictEqual(messages[0].extraClass, 'ai-model-note');
        assert.match(messages[0].content, /Model note/);
        assert.match(messages[0].content, /Claude Sonnet 4\.6/);
        assert.doesNotMatch(messages[0].content, /warning/i);
    });

    it('does not render a model lifecycle message when no replacement is available', function() {
        const assistant = loadUiMixin();
        let count = 0;

        assistant.getModelUpgradeInfo = function() {
            return null;
        };
        assistant.addMessage = function() {
            count++;
        };

        assistant.showModelUpgradeNotice('anthropic', 'claude-sonnet-4-6');

        assert.strictEqual(count, 0);
    });
});

describe('pick_image media upload helpers', function() {
    it('builds a safe filename from selected image metadata', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getPickedImageFileName({
                title: 'File:Summer Background.jpeg',
                url: 'https://images.example.test/source.png?size=large'
            }, 'image/png'),
            'summer-background.png'
        );

        assert.strictEqual(
            assistant.getPickedImageFileName({ title: '東京', url: '' }, 'image/webp'),
            'openverse-image.webp'
        );
    });

    it('infers image MIME types from URLs only when the extension is clear', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getPickedImageMimeType('https://images.example.test/source.jpg', 'application/octet-stream'),
            'image/jpeg'
        );
        assert.strictEqual(
            assistant.getPickedImageMimeType('https://images.example.test/download', 'application/octet-stream'),
            ''
        );
    });

    it('shortens image source URLs to readable domains', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.getShortImageSourceDomain('https://images.example.test/source.jpg'),
            'example.test'
        );
        assert.strictEqual(
            assistant.getShortImageSourceDomain('https://cdn.assets.example.co.uk/file.png'),
            'example.co.uk'
        );
        assert.strictEqual(
            assistant.getShortImageSourceDomain(''),
            'source'
        );
    });

    it('builds upload data from a dropped image file', async function() {
        const assistant = loadUiMixin({ maxMediaUploadBytes: 1024 });
        const file = {
            name: 'My Vacation.PNG',
            type: 'image/png',
            size: 64
        };

        const fileData = await assistant.getPickedImageFileData(file);

        assert.strictEqual(fileData.blob, file);
        assert.strictEqual(fileData.contentType, 'image/png');
        assert.strictEqual(fileData.filename, 'my-vacation.png');
        assert.strictEqual(assistant.getPickedImageFileTitle(file), 'My Vacation');
    });

    it('builds uploaded image results with attachment_id and local URL', function() {
        const assistant = loadUiMixin();
        const result = assistant.buildPickedImageResult({
            url: 'https://cdn.example.test/remote.jpg',
            thumbnail: 'https://cdn.example.test/thumb.jpg',
            title: 'Selected image',
            creator: 'Creator',
            license: 'CC0',
            attribution: 'Selected image - by Creator - CC0'
        }, {
            id: 123,
            source_url: 'http://example.test/wp-content/uploads/image.jpg',
            link: 'http://example.test/image/',
            mime_type: 'image/jpeg',
            media_type: 'image',
            media_details: {
                sizes: {
                    thumbnail: {
                        source_url: 'http://example.test/wp-content/uploads/image-150x150.jpg'
                    }
                }
            }
        });

        assert.strictEqual(result.attachment_id, 123);
        assert.strictEqual(result.id, 123);
        assert.strictEqual(result.url, 'http://example.test/wp-content/uploads/image.jpg');
        assert.strictEqual(result.remote_url, 'https://cdn.example.test/remote.jpg');
        assert.strictEqual(result.thumbnail, 'http://example.test/wp-content/uploads/image-150x150.jpg');
        assert.strictEqual(result.uploaded, true);
        assert.strictEqual(result.external, false);
    });

    it('offers remote URL after upload failure', async function() {
        const assistant = loadUiMixin();
        const image = {
            url: 'https://cdn.example.test/remote.jpg',
            title: 'Selected image'
        };

        assistant.fetchPickedImageBlob = function() {
            return Promise.reject(new Error('Failed to fetch'));
        };

        const fallback = await assistant.preparePickedImageSelection(image, {});
        assert.strictEqual(fallback.success, false);
        assert.strictEqual(fallback.can_use_external, true);
        assert.strictEqual(fallback.selection.url, image.url);
        assert.strictEqual(fallback.selection.source_url, image.url);
        assert.strictEqual(fallback.selection.external, true);
        assert.strictEqual(fallback.selection.upload_failed, true);
    });

    it('falls back to the Openverse thumbnail when the source image cannot be fetched', async function() {
        const sourceUrl = 'https://cdn.stocksnap.io/img-thumbs/960w/XNVBVXO3B7.jpg';
        const thumbnailUrl = 'https://api.openverse.org/v1/images/abc/thumb/';
        const fetches = [];
        const statuses = [];
        const assistant = loadUiMixin({}, {
            fetch(url) {
                fetches.push(url);
                if (url === sourceUrl) {
                    return Promise.resolve({
                        ok: false,
                        status: 403,
                        headers: {
                            get() {
                                return 'text/html';
                            }
                        },
                        blob() {
                            return Promise.resolve(new Blob([]));
                        }
                    });
                }
                return Promise.resolve(imageResponse('thumbnail image', 'application/octet-stream'));
            }
        });

        const fileData = await assistant.fetchPickedImageBlob({
            url: sourceUrl,
            thumbnail: thumbnailUrl,
            title: 'Tree Bark Photo'
        }, null, function(message) {
            statuses.push(message);
        });

        assert.deepStrictEqual(fetches, [sourceUrl, thumbnailUrl]);
        assert.strictEqual(fileData.contentType, 'image/jpeg');
        assert.strictEqual(fileData.filename, 'tree-bark-photo.jpg');
        assert.strictEqual(fileData.sourceUrl, thumbnailUrl);
        assert.strictEqual(fileData.usedThumbnailFallback, true);
        assert.ok(statuses.includes('Full image unavailable. Trying preview image...'));
    });

    it('marks uploaded thumbnail fallbacks as low resolution', async function() {
        const assistant = loadUiMixin();

        assistant.fetchPickedImageBlob = function() {
            return Promise.resolve({
                blob: {},
                filename: 'preview.jpg',
                contentType: 'image/jpeg',
                usedThumbnailFallback: true
            });
        };
        assistant.uploadPickedImageToMediaLibrary = function() {
            return Promise.resolve({
                id: 987,
                source_url: 'http://example.test/wp-content/uploads/preview.jpg'
            });
        };

        const result = await assistant.preparePickedImageSelection({
            url: 'https://cdn.stocksnap.io/source.jpg',
            thumbnail: 'https://api.openverse.org/v1/images/abc/thumb/',
            title: 'Preview'
        }, {});

        assert.strictEqual(result.success, true);
        assert.strictEqual(result.selection.low_resolution, true);
        assert.strictEqual(result.selection.uploaded_from_thumbnail, true);
        assert.strictEqual(result.selection.note, 'low resolution image');
    });

    it('uploads a dropped image file through the Media Library path', async function() {
        const assistant = loadUiMixin();
        const signal = { aborted: false };
        const statuses = [];
        const file = {
            name: 'Dropped Photo.jpg',
            type: 'image/jpeg',
            size: 128
        };
        let uploadedFileData = null;
        let uploadedImage = null;
        let uploadSignal = null;

        assistant.uploadPickedImageToMediaLibrary = function(fileData, image, passedSignal) {
            uploadedFileData = fileData;
            uploadedImage = image;
            uploadSignal = passedSignal;
            return Promise.resolve({
                id: 654,
                source_url: 'http://example.test/wp-content/uploads/dropped-photo.jpg',
                media_details: {
                    sizes: {
                        thumbnail: {
                            source_url: 'http://example.test/wp-content/uploads/dropped-photo-150x150.jpg'
                        }
                    }
                }
            });
        };

        const result = await assistant.preparePickedImageFileSelection(file, function(message) {
            statuses.push(message);
        }, signal);

        assert.strictEqual(result.success, true);
        assert.strictEqual(result.selection.attachment_id, 654);
        assert.strictEqual(result.selection.url, 'http://example.test/wp-content/uploads/dropped-photo.jpg');
        assert.strictEqual(result.selection.remote_url, '');
        assert.strictEqual(result.selection.title, 'Dropped Photo');
        assert.strictEqual(uploadedFileData.blob, file);
        assert.strictEqual(uploadedFileData.filename, 'dropped-photo.jpg');
        assert.strictEqual(uploadedImage.source, 'local_upload');
        assert.strictEqual(uploadSignal, signal);
        assert.ok(statuses.includes('Uploading dropped image to Media Library...'));
    });

    it('passes abort signals through image fetch and media upload', async function() {
        const assistant = loadUiMixin();
        const signal = { aborted: false };
        let fetchSignal = null;
        let uploadSignal = null;

        assistant.fetchPickedImageBlob = function(image, passedSignal) {
            fetchSignal = passedSignal;
            return Promise.resolve({
                blob: {},
                filename: 'selected-image.jpg',
                contentType: 'image/jpeg'
            });
        };
        assistant.uploadPickedImageToMediaLibrary = function(fileData, image, passedSignal) {
            uploadSignal = passedSignal;
            return Promise.resolve({
                id: 321,
                source_url: 'http://example.test/wp-content/uploads/selected-image.jpg'
            });
        };

        const result = await assistant.preparePickedImageSelection({
            url: 'https://cdn.example.test/remote.jpg',
            title: 'Selected image'
        }, {}, null, signal);

        assert.strictEqual(result.success, true);
        assert.strictEqual(result.selection.attachment_id, 321);
        assert.strictEqual(fetchSignal, signal);
        assert.strictEqual(uploadSignal, signal);
    });
});

describe('REST API tool card descriptions', function() {
    it('extracts method and path from streamed rest_api arguments', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.extractPartialDescription(
                'rest_api',
                '{"method":"POST","path":"/wp/v2/posts"'
            ),
            'POST /wp/v2/posts'
        );
    });

    it('waits for both method and path before describing rest_api arguments', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(
            assistant.extractPartialDescription('rest_api', '{"method":"POST"'),
            null
        );
    });
});

describe('tool result display', function() {
    it('renders read_file results as file content with the file language', function() {
        const assistant = loadUiMixin();
        assistant.getLanguageFromPath = function(path) {
            return path.endsWith('.php') ? 'php' : null;
        };

        const display = assistant.getToolResultDisplay('read_file', {
            path: 'plugins/example/example.php',
            content: "<?php\necho 'Hello';\n",
            size: 22
        });

        assert.strictEqual(display.text, "<?php\necho 'Hello';\n");
        assert.strictEqual(display.language, 'php');
        assert.strictEqual(display.label, 'Content');
    });

    it('marks structured tool results as JSON', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('db_query', {
            query: 'SELECT option_name FROM wp_options',
            rows: [{ option_name: 'siteurl' }],
            count: 1
        });

        assert.strictEqual(display.language, 'json');
        assert.match(display.text, /"rows"/);
        assert.match(display.text, /"siteurl"/);
    });

    it('detects JSON returned as a string', function() {
        const assistant = loadUiMixin();

        const display = assistant.getToolResultDisplay('run_php', {
            output: '{"name":"Ada","active":true}'
        });

        assert.strictEqual(display.language, 'json');
        assert.strictEqual(display.text, '{"name":"Ada","active":true}');
    });

    it('normalizes language classes for highlighted code blocks', function() {
        const assistant = loadUiMixin();

        assert.strictEqual(assistant.getCodeLanguageClass('json'), 'ai-language-json');
        assert.strictEqual(assistant.getCodeLanguageClass('text/x-sql'), 'ai-language-text-x-sql');
    });

    it('normalizes JavaScript-labelled JSON content to JSON highlighting', function() {
        const assistant = loadUiMixin();
        const classList = ['ai-language-javascript'];
        classList.add = function(className) {
            if (!this.includes(className)) this.push(className);
        };
        classList.remove = function(className) {
            const index = this.indexOf(className);
            if (index >= 0) this.splice(index, 1);
        };
        const element = {
            textContent: '',
            classList
        };

        assistant.highlightCode(element, '{"id":3360212}', 'javascript', false);

        assert.ok(classList.includes('ai-language-json'));
        assert.ok(!classList.includes('ai-language-javascript'));
        assert.strictEqual(element.textContent, '{"id":3360212}');
    });

    it('marks CodeMirror JSON string tokens followed by a colon as keys', function() {
        const assistant = loadUiMixin();
        const classes = [];
        const valueClasses = [];
        const key = {
            nextSibling: { nodeType: 3, textContent: ': ' },
            classList: {
                add(className) {
                    classes.push(className);
                }
            }
        };
        const value = {
            nextSibling: { nodeType: 3, textContent: ',' },
            classList: {
                add(className) {
                    valueClasses.push(className);
                }
            }
        };

        assistant.markJsonPropertyTokens({
            querySelectorAll(selector) {
                assert.strictEqual(selector, '.cm-string');
                return [key, value];
            }
        });

        assert.deepStrictEqual(classes, ['ai-json-key', 'cm-property']);
        assert.deepStrictEqual(valueClasses, []);
    });
});
