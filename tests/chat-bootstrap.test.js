const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class FakeElement {
    constructor(id, tag) {
        this.id = id || '';
        this.tag = tag || 'div';
        this.attrs = {};
        this.classes = new Set();
        this.children = [];
        this.parentNode = null;
        this.handlers = {};
        this.visible = true;
        this.styles = {};
        this.htmlContent = '';
    }
}

function createHarness(useCoreScreenMeta) {
    const elements = {};
    let openCount = 0;
    let closeCount = 0;
    let preloadCount = 0;

    function register(element) {
        if (element.id) {
            elements[element.id] = element;
        }
        return element;
    }

    function append(parent, child) {
        child.parentNode = parent;
        parent.children.push(child);
        return child;
    }

    function makeBaseDom() {
        const screenMetaLinks = register(new FakeElement('screen-meta-links'));
        const screenMeta = register(new FakeElement('screen-meta'));
        screenMeta.visible = false;

        const screenOptionsWrap = register(new FakeElement('screen-options-link-wrap'));
        screenOptionsWrap.classes.add('hide-if-no-js');
        screenOptionsWrap.classes.add('screen-meta-toggle');
        append(screenMetaLinks, screenOptionsWrap);

        const screenOptionsButton = register(new FakeElement('show-settings-link', 'button'));
        screenOptionsButton.classes.add('button');
        screenOptionsButton.classes.add('show-settings');
        screenOptionsButton.attrs['aria-controls'] = 'screen-options-wrap';
        screenOptionsButton.attrs['aria-expanded'] = 'false';
        append(screenOptionsWrap, screenOptionsButton);

        const screenOptionsPanel = register(new FakeElement('screen-options-wrap'));
        screenOptionsPanel.classes.add('hidden');
        screenOptionsPanel.visible = false;
        append(screenMeta, screenOptionsPanel);

        return { screenMetaLinks, screenMeta };
    }

    function createAssistantLink(parent) {
        const wrap = register(new FakeElement('ai-assistant-link-wrap'));
        wrap.classes.add('hide-if-no-js');
        wrap.classes.add('screen-meta-toggle');
        append(parent, wrap);

        const button = register(new FakeElement('ai-assistant-link', 'button'));
        button.classes.add('button');
        button.classes.add('show-settings');
        button.attrs['aria-controls'] = 'ai-assistant-wrap';
        button.attrs['aria-expanded'] = 'false';
        append(wrap, button);
    }

    function createAssistantPanel(parent) {
        const wrap = register(new FakeElement('ai-assistant-wrap'));
        wrap.classes.add('hidden');
        wrap.visible = false;
        append(parent, wrap);

        append(wrap, register(new FakeElement('ai-assistant-messages')));
        append(wrap, register(new FakeElement('ai-assistant-input', 'textarea')));
    }

    function matchesSelector(element, selector) {
        if (!element) {
            return false;
        }

        if (selector === ':visible') {
            return element.visible;
        }

        if (selector[0] === '#') {
            return element.id === selector.slice(1);
        }

        if (selector[0] === '.') {
            return element.classes.has(selector.slice(1));
        }

        return element.tag === selector;
    }

    function allElements() {
        return Object.keys(elements).map((id) => elements[id]);
    }

    function collection(items) {
        const elems = items.filter(Boolean);
        const api = {
            length: elems.length,
            ready(callback) {
                callback();
                return this;
            },
            on(eventName, handler) {
                const eventType = eventName.split('.')[0];
                elems.forEach((element) => {
                    element.handlers[eventType] = element.handlers[eventType] || [];
                    element.handlers[eventType].push(handler);
                });
                return this;
            },
            off(eventName) {
                const eventType = eventName.split('.')[0];
                elems.forEach((element) => {
                    element.handlers[eventType] = [];
                });
                return this;
            },
            trigger(eventName) {
                const eventType = eventName.split('.')[0];
                elems.forEach((element) => {
                    const event = {
                        type: eventType,
                        prevented: false,
                        immediateStopped: false,
                        preventDefault() {
                            this.prevented = true;
                        },
                        stopImmediatePropagation() {
                            this.immediateStopped = true;
                        }
                    };

                    (element.handlers[eventType] || []).slice().some((handler) => {
                        handler.call(element, event);
                        return event.immediateStopped;
                    });
                });
                return this;
            },
            attr(name, value) {
                if (value === undefined) {
                    return elems[0] ? elems[0].attrs[name] : undefined;
                }
                elems.forEach((element) => {
                    element.attrs[name] = String(value);
                });
                return this;
            },
            addClass(className) {
                elems.forEach((element) => element.classes.add(className));
                return this;
            },
            removeClass(className) {
                elems.forEach((element) => element.classes.delete(className));
                return this;
            },
            hasClass(className) {
                return elems[0] ? elems[0].classes.has(className) : false;
            },
            css(name, value) {
                elems.forEach((element) => {
                    element.styles[name] = value;
                });
                return this;
            },
            show() {
                elems.forEach((element) => {
                    element.visible = true;
                });
                return this;
            },
            hide() {
                elems.forEach((element) => {
                    element.visible = false;
                });
                return this;
            },
            slideDown(_speed, callback) {
                elems.forEach((element) => {
                    element.visible = true;
                    if (callback) {
                        callback.call(element);
                    }
                });
                return this;
            },
            slideUp(_speed, callback) {
                elems.forEach((element) => {
                    element.visible = false;
                    if (callback) {
                        callback.call(element);
                    }
                });
                return this;
            },
            stop() {
                return this;
            },
            parent() {
                return collection(elems.map((element) => element.parentNode));
            },
            find(selector) {
                let found = [];
                elems.forEach((element) => {
                    if (selector === '#ai-assistant-standalone-panel') {
                        found = found.concat(element.children.filter((child) => child.id === 'ai-assistant-standalone-panel'));
                    } else if (selector === '> div') {
                        found = found.concat(element.children.filter((child) => child.tag === 'div'));
                    } else if (selector === '.screen-meta-toggle') {
                        found = found.concat(element.children.filter((child) => child.classes.has('screen-meta-toggle')));
                    }
                });
                return collection(found);
            },
            not(other) {
                const excluded = other && other.elements ? other.elements : [];
                return collection(elems.filter((element) => !excluded.includes(element)));
            },
            filter(selector) {
                return collection(elems.filter((element) => matchesSelector(element, selector)));
            },
            each(callback) {
                elems.forEach((element, index) => {
                    callback.call(element, index, element);
                });
                return this;
            },
            prepend(html) {
                elems.forEach((element) => {
                    if (typeof html === 'string' && html.indexOf('ai-assistant-link') !== -1) {
                        createAssistantLink(element);
                    }
                    if (typeof html === 'string' && html.indexOf('ai-assistant-wrap') !== -1) {
                        createAssistantPanel(element);
                    }
                });
                return this;
            },
            html() {
                return elems[0] ? elems[0].htmlContent : '';
            },
            appendTo() {
                return this;
            },
            children() {
                return collection(elems.flatMap((element) => element.children));
            },
            is(selector) {
                return elems[0] ? matchesSelector(elems[0], selector) : false;
            }
        };

        elems.forEach((element, index) => {
            api[index] = element;
        });
        api.elements = elems;
        return api;
    }

    function makeStandaloneFragment() {
        const wrap = new FakeElement('ai-assistant-standalone-wrap');
        const panel = new FakeElement('ai-assistant-standalone-panel');
        panel.htmlContent = '<div id="ai-assistant-wrap" class="hidden"><textarea id="ai-assistant-input"></textarea></div>';
        append(wrap, panel);
        return collection([wrap]);
    }

    function $(selector) {
        if (selector instanceof FakeElement) {
            return collection([selector]);
        }

        if (selector && selector.nodeType === 9) {
            return collection([selector]);
        }

        if (selector === context.window) {
            return collection([windowElement]);
        }

        if (typeof selector === 'string' && selector.trim()[0] === '<') {
            return makeStandaloneFragment();
        }

        if (typeof selector === 'string' && selector.indexOf(',') !== -1) {
            return collection(selector.split(',').flatMap((part) => $(part.trim()).elements));
        }

        if (typeof selector === 'string' && selector.indexOf('.screen-meta-toggle button') === 0) {
            const match = selector.match(/aria-controls="([^"]+)"/);
            const ariaControls = match ? match[1] : '';
            return collection(allElements().filter((element) => {
                return element.tag === 'button' &&
                    element.attrs['aria-controls'] === ariaControls &&
                    element.parentNode &&
                    element.parentNode.classes.has('screen-meta-toggle');
            }));
        }

        if (typeof selector === 'string' && selector[0] === '#') {
            return collection([elements[selector.slice(1)]]);
        }

        if (typeof selector === 'string' && selector[0] === '.') {
            return collection(allElements().filter((element) => element.classes.has(selector.slice(1))));
        }

        return collection([]);
    }

    $.extend = function(target, source) {
        return Object.assign(target, source);
    };

    const documentElement = { nodeType: 9 };
    const windowElement = new FakeElement('window');
    makeBaseDom();

    const context = {
        window: {
            aiAssistantBootstrap: {
                deferInit: true,
                renderLatch: true,
                strings: {},
                urls: {}
            },
            aiAssistant: {
                initialized: false,
                init() {
                    this.initialized = true;
                },
                scrollToBottom() {},
                preloadMostRecentConversation() {
                    preloadCount++;
                }
            },
            location: { hash: '' },
            history: { replaceState() {} }
        },
        document: {
            nodeType: 9,
            getElementById(id) {
                return elements[id] || null;
            }
        },
        history: { replaceState() {} },
        jQuery: $,
        setTimeout(callback) {
            callback();
            return 1;
        },
        console
    };

    if (useCoreScreenMeta) {
        context.window.screenMeta = {
            open(panel, button) {
                openCount++;
                panel.parent().show();
                panel.slideDown('fast', function() {
                    panel.removeClass('hidden').trigger('focus');
                    button.addClass('screen-meta-active').attr('aria-expanded', 'true');
                });
            },
            close(panel, button) {
                closeCount++;
                panel.slideUp('fast', function() {
                    button.removeClass('screen-meta-active').attr('aria-expanded', 'false');
                    $('.screen-meta-toggle').css('visibility', '');
                    panel.parent().hide();
                    panel.addClass('hidden');
                });
            }
        };
    }

    vm.createContext(context);
    const source = fs.readFileSync(
        path.join(__dirname, '../assets/js/chat-bootstrap.js'),
        'utf8'
    );
    vm.runInContext(source, context);

    return {
        context,
        elements,
        clickAssistant() {
            $('#ai-assistant-link').trigger('click');
        },
        clickScreenOptions() {
            $('#show-settings-link').trigger('click');
        },
        counts() {
            return { openCount, closeCount, preloadCount };
        }
    };
}

describe('chat bootstrap screen-meta latch', function() {
    it('uses WordPress screenMeta methods for a dynamically rendered admin latch', function() {
        const harness = createHarness(true);

        harness.clickAssistant();

        assert.strictEqual(harness.counts().openCount, 1);
        assert.strictEqual(harness.elements['screen-meta'].visible, true);
        assert.strictEqual(harness.elements['ai-assistant-wrap'].classes.has('hidden'), false);
        assert.strictEqual(harness.elements['ai-assistant-link'].attrs['aria-expanded'], 'true');
        assert.strictEqual(harness.elements['ai-assistant-link'].classes.has('screen-meta-active'), true);
        assert.strictEqual(harness.counts().preloadCount, 1);

        harness.clickAssistant();

        assert.strictEqual(harness.counts().closeCount, 1);
        assert.strictEqual(harness.elements['screen-meta'].visible, false);
        assert.strictEqual(harness.elements['ai-assistant-link'].attrs['aria-expanded'], 'false');
    });

    it('shows the screen-meta parent when WordPress screenMeta is unavailable', function() {
        const harness = createHarness(false);

        harness.clickAssistant();

        assert.strictEqual(harness.elements['screen-meta'].visible, true);
        assert.strictEqual(harness.elements['ai-assistant-wrap'].visible, true);
        assert.strictEqual(harness.elements['ai-assistant-link'].attrs['aria-expanded'], 'true');
        assert.strictEqual(harness.elements['ai-assistant-link'].classes.has('screen-meta-active'), true);
    });

    it('does not hide the screen-meta parent when another tab closes the assistant panel', function() {
        const harness = createHarness(false);

        harness.clickAssistant();
        harness.clickScreenOptions();

        assert.strictEqual(harness.elements['screen-meta'].visible, true);
        assert.strictEqual(harness.elements['ai-assistant-wrap'].visible, false);
        assert.strictEqual(harness.elements['ai-assistant-link'].attrs['aria-expanded'], 'false');
    });
});
