(function () {
    function initialize() {
        const root = document.querySelector('[data-wp-app-config]');
        if (!root) return;
        const config = JSON.parse(root.dataset.wpAppConfig);
        const form = root.querySelector('[data-wp-app-settings-form]');
        const dialog = root.querySelector('[data-app-dialog]');
        const slot = dialog.querySelector('[data-editor-slot]');
        const status = root.querySelector('[data-wp-app-save-status]');
        const models = Array.from(root.querySelectorAll('.wp-app-settings-app')).map(function (row) {
            const editor = row.querySelector('[data-app-editor]');
            return { row, editor, path: row.dataset.appPath, fields: Object.fromEntries(Array.from(editor.querySelectorAll('[data-wp-app-setting]')).filter(el => el.type !== 'hidden').map(el => [el.dataset.wpAppSetting, el])) };
        });
        const orders = {};
        ['launcher', 'desktop'].forEach(function (place) {
            orders[place] = Array.from(root.querySelector('[data-order-inputs="' + place + '"]').querySelectorAll('input')).map(input => input.value);
        });
        let active = null;
        let placeholder = null;
        let trigger = null;
        let anchorRect = null;
        let dragging = null;
        let timer = null;
        let saving = false;
        let version = 0;
        let savedVersion = 0;

        function name(model) { return model.fields.title.value.trim() || model.row.dataset.defaultTitle; }
        function visible(model, place) {
            return model.row.dataset.navigationEnabled === '1' && model.fields[place === 'desktop' ? 'pin_to_desktop' : 'show_in_launcher'].checked;
        }
        function sanitizeColor(value) {
            value = (value || '').replace(/\s+/g, ' ').trim();
            if (value.length > 300 || /[^a-z0-9#.,%()\/\s+-]/i.test(value) || /(^|[^a-z-])(url|var|attr|expression|image-set|element|env)\s*\(/i.test(value)) return '';
            if ((value.match(/\(/g) || []).length !== (value.match(/\)/g) || []).length) return '';
            return value;
        }
        function icon(model) {
            const value = model.fields.icon.value.trim() || model.row.dataset.defaultIcon;
            const span = document.createElement('span');
            span.className = 'wp-app-link-icon';
            span.setAttribute('aria-hidden', 'true');
            if (/^https?:\/\//i.test(value)) {
                span.classList.add('wp-app-link-icon-image');
                const image = document.createElement('img');
                image.src = value; image.alt = ''; image.decoding = 'async'; span.append(image);
            } else {
                if (/^dashicons-[a-z0-9-]+$/.test(value)) {
                    span.classList.add('wp-app-link-icon-dashicon');
                    const dashicon = document.createElement('span');
                    dashicon.className = 'dashicons ' + value; span.append(dashicon);
                } else {
                    span.classList.add('wp-app-link-icon-generated');
                    span.textContent = value || Array.from(name(model))[0].toUpperCase();
                }
                span.style.background = sanitizeColor(model.fields.icon_background.value || model.row.dataset.defaultBackground);
                span.style.color = sanitizeColor(model.fields.icon_color.value || model.row.dataset.defaultColor);
            }
            return span;
        }
        function ordered(place) {
            return orders[place].map(path => models.find(model => model.path === path)).filter(Boolean);
        }
        function render() {
            models.forEach(function (model) {
                model.row.querySelector('[data-app-name]').textContent = model.row.dataset.defaultTitle;
                model.row.querySelector('.wp-app-settings-app-icon').replaceChildren(icon(model));
                model.row.querySelector('[data-app-visibility]').textContent = (visible(model, 'launcher') ? config.launcher : config.hidden) + (visible(model, 'desktop') ? ' · ' + config.pinned : '');
            });
            ['launcher', 'desktop'].forEach(function (place) {
                const preview = root.querySelector('[data-navigation-preview="' + place + '"]');
                const tiles = ordered(place).filter(model => visible(model, place)).map(function (model) {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'wp-app-settings-tile';
                    button.dataset.editApp = model.path; button.draggable = true;
                    button.title = name(model); button.setAttribute('aria-label', name(model));
                    button.append(icon(model));
                    if (place === 'launcher') {
                        const label = document.createElement('span'); label.textContent = name(model); button.append(label);
                    }
                    return button;
                });
                preview.replaceChildren(...tiles);
                const empty = root.querySelector('[data-empty-preview="' + place + '"]');
                empty.hidden = tiles.length > 0; empty.textContent = place === 'desktop' ? config.emptyDesktop : config.emptyLauncher;
                const container = root.querySelector('[data-order-inputs="' + place + '"]');
                container.replaceChildren(...orders[place].map(function (path) {
                    const input = document.createElement('input'); input.type = 'hidden';
                    input.name = 'wp_app_masterbar_settings[' + (place === 'desktop' ? 'desktop_order' : 'launcher_order') + '][]'; input.value = path;
                    return input;
                }));
            });
            root.querySelectorAll('[data-add-pin] option[value]').forEach(function (option) {
                const model = models.find(item => item.path === option.value);
                if (model) { option.disabled = visible(model, 'desktop'); option.textContent = name(model); }
            });
            if (active) {
                dialog.querySelector('h2').textContent = active.model.row.dataset.defaultTitle;
                const available = ordered(active.place).filter(model => visible(model, active.place));
                const index = available.indexOf(active.model);
                dialog.querySelector('[data-move="-1"]').disabled = index <= 0;
                dialog.querySelector('[data-move="1"]').disabled = index < 0 || index >= available.length - 1;
            }
        }
        function setStatus(text, failed) {
            status.hidden = false; status.textContent = text;
            const editorStatus = dialog.querySelector('[data-editor-status]');
            editorStatus.hidden = false; editorStatus.textContent = text;
            status.classList.toggle('is-failed', !!failed); status.classList.toggle('is-saved', text === config.saved);
        }
        function initializeToolbarMenus(fragment) {
            // WordPress binds these interactions once at DOMContentLoaded, so
            // replacement nodes need their own handlers after a settings save.
            fragment.querySelectorAll('li.menupop').forEach(function (item) {
                const button = item.querySelector(':scope > .ab-item');
                function setOpen(open) {
                    item.classList.toggle('hover', open);
                    if (button) button.setAttribute('aria-expanded', String(open));
                }
                if (window.hoverintent) {
                    window.hoverintent(item, () => setOpen(true), () => setOpen(false)).options({ timeout: 180 });
                } else {
                    item.addEventListener('mouseenter', () => setOpen(true));
                    item.addEventListener('mouseleave', () => setOpen(false));
                }
                if (button) {
                    button.addEventListener('keydown', function (event) {
                        if (event.key !== 'Enter' || event.ctrlKey || event.shiftKey) return;
                        event.preventDefault();
                        setOpen(!item.classList.contains('hover'));
                    });
                    if ('ontouchstart' in window) button.addEventListener('click', function (event) {
                        event.preventDefault();
                        const open = !item.classList.contains('hover');
                        document.querySelectorAll('#wpadminbar li.menupop.hover').forEach(other => other.classList.remove('hover'));
                        setOpen(open);
                    });
                }
                item.addEventListener('keydown', function (event) {
                    if (event.key !== 'Escape') return;
                    event.stopPropagation();
                    setOpen(false);
                    if (button) button.focus();
                });
            });
        }
        document.body.addEventListener(/Mobile\/.+Safari/.test(navigator.userAgent) ? 'touchstart' : 'click', function (event) {
            if (!event.target.closest('#wpadminbar li.menupop')) {
                document.querySelectorAll('#wpadminbar li.menupop.hover').forEach(item => {
                    item.classList.remove('hover');
                    const button = item.querySelector(':scope > .ab-item');
                    if (button) button.setAttribute('aria-expanded', 'false');
                });
            }
        });
        async function refreshBar() {
            const menu = document.querySelector('#wpadminbar #wp-admin-bar-root-default');
            if (!menu) return;
            const body = new FormData(); body.set('action', 'wp_app_admin_bar_nodes'); body.set('nonce', config.nonce);
            try {
                const response = await fetch(config.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
                if (!response.ok) return;
                const payload = await response.json();
                if (!payload.success || !payload.data || !Array.isArray(payload.data.nodes)) return;
                const owned = Array.from(menu.children).filter(node => node.id.startsWith('wp-admin-bar-wp-app-link-') || node.id === 'wp-admin-bar-wp-app-admin-overflow');
                const next = owned.length ? owned[owned.length - 1].nextSibling : null;
                owned.forEach(node => node.remove());
                payload.data.nodes.filter(node => !node.parent).forEach(function (node) {
                    const template = document.createElement('template'); template.innerHTML = node.html;
                    initializeToolbarMenus(template.content);
                    menu.insertBefore(template.content, next);
                });
            } catch (_) { /* Preview edits remain available if toolbar refresh is unavailable. */ }
        }
        async function save() {
            clearTimeout(timer);
            if (saving || version === savedVersion) return;
            saving = true;
            const snapshot = version;
            setStatus(config.saving);
            try {
                const response = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
                if (!response.ok) throw new Error('Save failed');
                // options.php redirects to the settings page after a successful save.
                // A redirect to login or a WordPress error page must not count as saved.
                if (response.redirected && !new URL(response.url).searchParams.has('settings-updated')) throw new Error('Save was not confirmed');
                savedVersion = snapshot;
                setStatus(config.saved);
                await refreshBar();
            } catch (_) {
                setStatus(config.failed, true);
                saving = false;
                return;
            }
            saving = false;
            if (version !== savedVersion) save();
        }
        function changed() {
            render(); version++; setStatus(config.saving);
            clearTimeout(timer); timer = setTimeout(save, 450);
        }
        function positionEditor() {
            dialog.style.removeProperty('left'); dialog.style.removeProperty('top');
            if (window.innerWidth <= 782 || !anchorRect) return;
            const rect = trigger && trigger.isConnected ? trigger.getBoundingClientRect() : anchorRect;
            dialog.style.left = Math.max(12, Math.min(rect.right + 12, innerWidth - dialog.offsetWidth - 12)) + 'px';
            dialog.style.top = Math.max(12, Math.min(rect.top, innerHeight - dialog.offsetHeight - 12)) + 'px';
        }
        function openEditor(button) {
            const model = models.find(item => item.path === button.dataset.editApp);
            if (!model) return;
            trigger = button;
            anchorRect = button.getBoundingClientRect();
            active = { model, place: button.closest('[data-navigation-preview]').dataset.navigationPreview };
            placeholder = document.createComment('app editor');
            model.editor.replaceWith(placeholder); slot.append(model.editor);
            dialog.querySelector('h2').textContent = model.row.dataset.defaultTitle;
            dialog.showModal(); render(); positionEditor();
            model.fields.title.focus();
        }
        dialog.addEventListener('close', function () {
            if (!active) return;
            const { model, place } = active;
            placeholder.replaceWith(model.editor); active = null; placeholder = null;
            const replacement = Array.from(root.querySelectorAll('[data-navigation-preview="' + place + '"] [data-edit-app]')).find(button => button.dataset.editApp === model.path);
            (replacement || model.row.querySelector('summary')).focus();
        });
        dialog.querySelector('[data-close-editor]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', function (event) {
            if (event.target !== dialog) return;
            const rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
        });
        function move(place, path, targetPath, after) {
            const order = orders[place];
            order.splice(order.indexOf(path), 1);
            order.splice(order.indexOf(targetPath) + (after ? 1 : 0), 0, path);
            changed();
        }
        root.addEventListener('click', function (event) {
            const button = event.target.closest('[data-edit-app]');
            if (button) openEditor(button);
            const moveButton = event.target.closest('[data-move]');
            if (moveButton && active) {
                const list = ordered(active.place).filter(model => visible(model, active.place));
                const index = list.indexOf(active.model); const delta = Number(moveButton.dataset.move);
                if (list[index + delta]) move(active.place, active.model.path, list[index + delta].path, delta > 0);
            }
        });
        root.addEventListener('input', function (event) {
            if (event.target.matches('input[type="text"][data-wp-app-setting]')) changed();
        });
        root.addEventListener('change', function (event) {
            if (event.target.matches('[data-add-pin]')) {
                const model = models.find(item => item.path === event.target.value);
                if (model) { model.fields.pin_to_desktop.checked = true; changed(); }
                event.target.value = '';
            } else if (event.target.name) changed();
        });
        root.addEventListener('keydown', function (event) {
            const button = event.target.closest('[data-edit-app]');
            if (!button || !event.altKey || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
            event.preventDefault();
            const place = button.closest('[data-navigation-preview]').dataset.navigationPreview;
            const delta = ['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 1;
            const list = ordered(place).filter(model => visible(model, place));
            const other = list[list.findIndex(model => model.path === button.dataset.editApp) + delta];
            if (other) { move(place, button.dataset.editApp, other.path, delta > 0); Array.from(root.querySelectorAll('[data-navigation-preview="' + place + '"] [data-edit-app]')).find(item => item.dataset.editApp === button.dataset.editApp).focus(); }
        });
        root.addEventListener('dragstart', function (event) {
            const button = event.target.closest('[data-edit-app]');
            if (!button) return;
            dragging = { path: button.dataset.editApp, place: button.closest('[data-navigation-preview]').dataset.navigationPreview };
            event.dataTransfer.setData('text/plain', dragging.path); event.dataTransfer.effectAllowed = 'move';
        });
        root.addEventListener('dragover', function (event) {
            const preview = event.target.closest('[data-navigation-preview]');
            if (dragging && preview && preview.dataset.navigationPreview === dragging.place) event.preventDefault();
        });
        root.addEventListener('drop', function (event) {
            const button = event.target.closest('[data-edit-app]');
            if (!dragging || !button || button.closest('[data-navigation-preview]').dataset.navigationPreview !== dragging.place) return;
            event.preventDefault();
            if (button.dataset.editApp !== dragging.path) {
                const rect = button.getBoundingClientRect();
                move(dragging.place, dragging.path, button.dataset.editApp, event.clientX > rect.left + rect.width / 2);
            }
            dragging = null;
        });
        root.addEventListener('dragend', () => { dragging = null; });
        form.addEventListener('submit', function (event) { event.preventDefault(); if (version === savedVersion) version++; save(); });
        window.addEventListener('resize', positionEditor);
        if (window.jQuery && window.jQuery.ui && window.jQuery.ui.autocomplete) {
            window.jQuery('[data-wp-app-dashicon-autocomplete]').each(function () {
                const input = this;
                window.jQuery(input).autocomplete({
                    source: config.dashicons,
                    minLength: 0,
                    classes: { 'ui-autocomplete': 'wp-app-dashicon-autocomplete' },
                    appendTo: input.closest('[data-app-editor]'),
                    select: function (_, ui) { input.value = ui.item.value; changed(); },
                });
                window.jQuery(input).autocomplete('instance')._renderItem = function (ul, item) {
                    const li = window.jQuery('<li>');
                    window.jQuery('<div>').append(window.jQuery('<span>').addClass('dashicons ' + item.value).attr('aria-hidden', 'true')).append(window.jQuery('<code>').text(item.value)).appendTo(li);
                    return li.appendTo(ul);
                };
                window.jQuery(input).on('focus', () => window.jQuery(input).autocomplete('search', ''));
            });
        }
        render();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
