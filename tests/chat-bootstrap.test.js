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
        this.style = {
            setProperty: (name, value) => {
                this.styles[name] = value;
            },
            getPropertyValue: (name) => {
                return this.styles[name] || '';
            },
            removeProperty: (name) => {
                delete this.styles[name];
            }
        };
        this.htmlContent = '';
        this.textContent = '';
    }

    getBoundingClientRect() {
        return { right: 640 };
    }
}

function createHarness(useCoreScreenMeta, bootstrapOverrides, assistantOverrides) {
    const elements = {};
    let openCount = 0;
    let closeCount = 0;
    let preloadCount = 0;
    let timerId = 0;
    const timers = new Map();

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

        const wpadminbar = register(new FakeElement('wpadminbar'));
        wpadminbar.visible = !!(bootstrapOverrides && bootstrapOverrides.testAdminbarVisible);
        wpadminbar.styles.height = '46px';

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

    function descendants(element) {
        return element.children.reduce((items, child) => {
            items.push(child);
            return items.concat(descendants(child));
        }, []);
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
                    element.handlers = element.handlers || {};
                    element.handlers[eventType] = element.handlers[eventType] || [];
                    element.handlers[eventType].push(handler);
                });
                return this;
            },
            off(eventName) {
                if (eventName && eventName[0] === '.') {
                    return this;
                }
                const eventType = eventName.split('.')[0];
                elems.forEach((element) => {
                    element.handlers = element.handlers || {};
                    if (!eventType) {
                        Object.keys(element.handlers).forEach((type) => {
                            element.handlers[type] = [];
                        });
                    } else {
                        element.handlers[eventType] = [];
                    }
                });
                return this;
            },
            trigger(eventName, eventProps) {
                const eventType = eventName.split('.')[0];
                elems.forEach((element) => {
                    element.handlers = element.handlers || {};
                    const event = Object.assign({
                        type: eventType,
                        prevented: false,
                        immediateStopped: false,
                        preventDefault() {
                            this.prevented = true;
                        },
                        stopPropagation() {
                            this.propagationStopped = true;
                        },
                        stopImmediatePropagation() {
                            this.immediateStopped = true;
                        }
                    }, eventProps || {});

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
            removeAttr(name) {
                elems.forEach((element) => {
                    delete element.attrs[name];
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
            toggleClass(className, force) {
                elems.forEach((element) => {
                    if (force === undefined ? !element.classes.has(className) : !!force) {
                        element.classes.add(className);
                    } else {
                        element.classes.delete(className);
                    }
                });
                return this;
            },
            hasClass(className) {
                return elems[0] ? elems[0].classes.has(className) : false;
            },
            css(name, value) {
                if (value === undefined && typeof name === 'string') {
                    return elems[0] ? elems[0].styles[name] : undefined;
                }
                if (name && typeof name === 'object') {
                    elems.forEach((element) => {
                        Object.assign(element.styles, name);
                    });
                    return this;
                }
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
            closest(selector) {
                const found = [];
                elems.forEach((element) => {
                    let current = element;
                    while (current) {
                        if (matchesSelector(current, selector)) {
                            found.push(current);
                            break;
                        }
                        current = current.parentNode;
                    }
                });
                return collection(found);
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
                    } else {
                        found = found.concat(descendants(element).filter((child) => matchesSelector(child, selector)));
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
            empty() {
                elems.forEach((element) => {
                    element.children = [];
                    element.htmlContent = '';
                    element.textContent = '';
                });
                return this;
            },
            append(child) {
                const children = child && child.elements ? child.elements : [child];
                elems.forEach((element) => {
                    children.filter(Boolean).forEach((childElement) => {
                        append(element, childElement);
                    });
                });
                return this;
            },
            html(value) {
                if (value === undefined) {
                    return elems[0] ? elems[0].htmlContent : '';
                }
                elems.forEach((element) => {
                    element.htmlContent = String(value);
                });
                return this;
            },
            text(value) {
                if (value === undefined) {
                    return elems[0] ? elems[0].textContent : '';
                }
                elems.forEach((element) => {
                    element.textContent = String(value);
                });
                return this;
            },
            appendTo() {
                return this;
            },
            children() {
                return collection(elems.flatMap((element) => element.children));
            },
            outerHeight() {
                if (elems[0] && elems[0].styles.height) {
                    const elementHeight = parseFloat(elems[0].styles.height);
                    if (Number.isFinite(elementHeight)) {
                        return elementHeight;
                    }
                }
                const height = elems[0] ? parseFloat(elems[0].styles['--ai-assistant-chat-height']) : NaN;
                return Number.isFinite(height) ? height : 420;
            },
            outerWidth() {
                const width = elems[0] ? parseFloat(elems[0].styles['--ai-assistant-floating-width']) : NaN;
                return Number.isFinite(width) ? width : 520;
            },
            promise() {
                return {
                    done(callback) {
                        if (callback) {
                            callback();
                        }
                        return this;
                    }
                };
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

    function makeElementFragment(html) {
        const match = html.match(/^<([a-z0-9-]+)>/i);
        if (!match) {
            return collection([]);
        }

        return collection([new FakeElement('', match[1].toLowerCase())]);
    }

    function makeStandaloneFragment(html) {
        const wrap = register(new FakeElement('ai-assistant-standalone-wrap'));
        const panel = register(new FakeElement('ai-assistant-standalone-panel'));
        panel.htmlContent = '<div id="ai-assistant-wrap" class="hidden"><textarea id="ai-assistant-input"></textarea></div>';
        append(wrap, panel);

        const assistantWrap = register(new FakeElement('ai-assistant-wrap'));
        assistantWrap.classes.add('hidden');
        append(panel, assistantWrap);

        const container = new FakeElement('', 'div');
        container.classes.add('ai-assistant-chat-container');
        append(assistantWrap, container);

        append(container, register(new FakeElement('ai-assistant-input', 'textarea')));

        if (html.indexOf('ai-assistant-corner-resize') !== -1) {
            const cornerHandle = register(new FakeElement('ai-assistant-corner-resize'));
            cornerHandle.classes.add('ai-assistant-corner-resize');
            append(container, cornerHandle);
        }

        if (html.indexOf('ai-assistant-floating-menu') !== -1) {
            const menu = register(new FakeElement('ai-assistant-floating-menu'));
            menu.classes.add('ai-assistant-floating-menu');
            append(container, menu);
        }

        const links = new FakeElement('', 'div');
        links.classes.add('ai-assistant-standalone-links');
        append(wrap, links);

        const trigger = register(new FakeElement('ai-assistant-standalone-trigger'));
        trigger.classes.add('ai-assistant-standalone-trigger');
        append(links, trigger);

        const button = new FakeElement('', 'button');
        button.attrs['aria-expanded'] = 'false';
        append(trigger, button);

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
            if (selector.indexOf('ai-assistant-standalone-wrap') === -1) {
                return makeElementFragment(selector.trim());
            }
            return makeStandaloneFragment(selector);
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
            innerWidth: 1024,
            innerHeight: 768,
            scrollY: 0,
            pageYOffset: 0,
            aiAssistantBootstrap: Object.assign({
                deferInit: true,
                renderLatch: true,
                strings: {},
                urls: {}
            }, bootstrapOverrides || {}),
            aiAssistant: Object.assign({
                initialized: false,
                init() {
                    this.initialized = true;
                },
                scrollToBottom() {},
                preloadMostRecentConversation() {
                    preloadCount++;
                }
            }, assistantOverrides || {}),
            requestAnimationFrame(callback) {
                callback();
            },
        },
        document: {
            nodeType: 9,
            documentElement: {
                clientWidth: 1024,
                clientHeight: 768,
                scrollTop: 0
            },
            getElementById(id) {
                return elements[id] || null;
            }
        },
        jQuery: $,
        setTimeout(callback, delay) {
            if (delay && delay >= 550) {
                timerId++;
                timers.set(timerId, callback);
                return timerId;
            }
            callback();
            return 1;
        },
        clearTimeout(id) {
            timers.delete(id);
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
        path.join(__dirname, '../themes/admin-classic/script.js'),
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

    it('toggles panel max width and height from the floating corner handle on double-click', function() {
        const harness = createHarness(false, {
            theme: {
                id: 'floating-button',
                placement: 'standalone'
            },
            strings: {
                resizePanelTitle: 'Drag to resize AI Assistant. Double-click to toggle maximum size.'
            }
        });
        const wrap = harness.elements['ai-assistant-standalone-wrap'];
        const container = harness.elements['ai-assistant-corner-resize'].parentNode;

        harness.context.jQuery('#ai-assistant-corner-resize').trigger('dblclick');

        assert.strictEqual(wrap.styles['--ai-assistant-floating-width'], '1008px');
        assert.strictEqual(container.styles['--ai-assistant-chat-height'], '696px');

        harness.context.jQuery('#ai-assistant-corner-resize').trigger('dblclick');

        assert.strictEqual(wrap.styles['--ai-assistant-floating-width'], '520px');
        assert.strictEqual(container.styles['--ai-assistant-chat-height'], '420px');
    });

    it('renders the floating theme switch link with a single-escaped href and explicit label', function() {
        const harness = createHarness(false, {
            theme: {
                id: 'floating-button',
                placement: 'standalone'
            },
            strings: {
                changeTheme: 'Change to Admin Classic display style'
            },
            urls: {
                changeTheme: 'https://example.test/wp-admin/admin-post.php?action=ai_assistant_switch_theme&theme=admin-classic&_wpnonce=abc123'
            }
        });
        const links = harness.context.jQuery('#ai-assistant-floating-menu').find('a').elements;

        assert.strictEqual(links.length, 1);
        assert.strictEqual(
            links[0].attrs.href,
            'https://example.test/wp-admin/admin-post.php?action=ai_assistant_switch_theme&theme=admin-classic&_wpnonce=abc123'
        );
        assert.strictEqual(links[0].textContent, 'Change to Admin Classic display style');
        assert.doesNotMatch(links[0].attrs.href, /&amp;/);
    });

    it('moves the floating launcher by dragging without opening the panel', function() {
        const harness = createHarness(false, {
            theme: {
                id: 'floating-button',
                placement: 'standalone'
            }
        });
        const $ = harness.context.jQuery;
        const wrap = harness.elements['ai-assistant-standalone-wrap'];
        const trigger = harness.elements['ai-assistant-standalone-trigger'];
        const button = $(trigger).find('button')[0];

        $(trigger).trigger('pointerdown', {
            button: 0,
            clientX: 900,
            clientY: 700,
            pointerId: 1
        });
        $(harness.context.document).trigger('pointermove', {
            clientX: 850,
            clientY: 650,
            pointerId: 1
        });
        $(harness.context.document).trigger('pointerup', {
            pointerId: 1
        });

        assert.strictEqual(wrap.styles['--ai-assistant-floating-right'], '74px');
        assert.strictEqual(wrap.styles['--ai-assistant-floating-bottom'], '74px');

        $(button).trigger('click');

        assert.strictEqual(button.attrs['aria-expanded'], 'false');
    });

    it('opens the floating launcher on click when no drag occurs', function() {
        const harness = createHarness(false, {
            theme: {
                id: 'floating-button',
                placement: 'standalone'
            }
        });
        const $ = harness.context.jQuery;
        const trigger = harness.elements['ai-assistant-standalone-trigger'];
        const button = $(trigger).find('button')[0];
        let pointerDownPrevented = false;

        $(trigger).trigger('pointerdown', {
            button: 0,
            clientX: 900,
            clientY: 700,
            pointerId: 1,
            preventDefault() {
                pointerDownPrevented = true;
            }
        });
        $(harness.context.document).trigger('pointerup', {
            pointerId: 1
        });
        $(button).trigger('click');

        assert.strictEqual(pointerDownPrevented, false);
        assert.strictEqual(button.attrs['aria-expanded'], 'true');
    });

    it('lets the floating launcher follow the mobile masterbar until it scrolls away', function() {
        const harness = createHarness(false, {
            theme: {
                id: 'floating-button',
                placement: 'standalone'
            },
            testAdminbarVisible: true
        });
        const $ = harness.context.jQuery;
        const wrap = harness.elements['ai-assistant-standalone-wrap'];

        harness.context.window.innerWidth = 390;
        harness.context.document.documentElement.clientWidth = 390;

        $(harness.context.window).trigger('resize');
        assert.strictEqual(wrap.styles['--ai-assistant-adminbar-offset'], '46px');

        harness.context.window.scrollY = 20;
        $(harness.context.window).trigger('scroll');
        assert.strictEqual(wrap.styles['--ai-assistant-adminbar-offset'], '26px');

        harness.context.window.scrollY = 80;
        $(harness.context.window).trigger('scroll');
        assert.strictEqual(wrap.styles['--ai-assistant-adminbar-offset'], '0px');
    });

    it('keeps the wp-admin screen-meta latch attached to the mobile masterbar while scrolling', function() {
        const harness = createHarness(false, {
            testAdminbarVisible: true
        });
        const $ = harness.context.jQuery;
        const linkWrap = harness.elements['ai-assistant-link-wrap'];
        const screenMeta = harness.elements['screen-meta'];

        harness.context.window.innerWidth = 390;
        harness.context.document.documentElement.clientWidth = 390;

        $(harness.context.window).trigger('resize');
        assert.strictEqual(linkWrap.styles['--ai-assistant-link-top'], '46px');

        harness.context.window.scrollY = 20;
        $(harness.context.window).trigger('scroll');
        assert.strictEqual(linkWrap.styles['--ai-assistant-link-top'], '26px');

        harness.clickAssistant();
        assert.strictEqual(screenMeta.styles['--ai-assistant-adminbar-offset'], '26px');

        harness.context.window.scrollY = 80;
        $(harness.context.window).trigger('scroll');
        assert.strictEqual(linkWrap.styles['--ai-assistant-link-top'], '419px');
        assert.strictEqual(screenMeta.styles['--ai-assistant-adminbar-offset'], '0px');
    });

    it('opens the floating launcher when pointer capture retargets click to the trigger', function() {
        const harness = createHarness(false, {
            theme: {
                id: 'floating-button',
                placement: 'standalone'
            }
        });
        const $ = harness.context.jQuery;
        const trigger = harness.elements['ai-assistant-standalone-trigger'];
        const button = $(trigger).find('button')[0];

        $(trigger).trigger('pointerdown', {
            button: 0,
            clientX: 900,
            clientY: 700,
            pointerId: 1
        });
        $(harness.context.document).trigger('pointerup', {
            pointerId: 1
        });
        $(trigger).trigger('click');

        assert.strictEqual(button.attrs['aria-expanded'], 'true');
    });
});
