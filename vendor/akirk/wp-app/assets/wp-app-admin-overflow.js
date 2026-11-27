(function () {
    let pending = false;
    function positionMenu() {
        pending = false;
        const item = document.getElementById('wp-admin-bar-wp-app-admin-overflow');
        const menu = item && item.querySelector(':scope > .ab-sub-wrapper');
        if (!menu) return;
        menu.style.removeProperty('--wp-app-overflow-offset');
        if (window.innerWidth <= 782 || !menu.getClientRects().length) return;
        const button = item.querySelector(':scope > .ab-item');
        if (!button) return;
        const bounds = menu.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const left = Math.max(8, Math.min(button.getBoundingClientRect().left, viewportWidth - bounds.width - 8));
        menu.style.setProperty('--wp-app-overflow-offset', (left - bounds.left) + 'px');
    }
    function schedule() {
        if (!pending) {
            pending = true;
            requestAnimationFrame(positionMenu);
        }
    }
    function toolbarEvent(event) {
        if (event.target instanceof Element && event.target.closest('#wpadminbar')) schedule();
    }
    document.addEventListener('mouseover', toolbarEvent);
    document.addEventListener('focusin', toolbarEvent);
    document.addEventListener('click', toolbarEvent);
    document.addEventListener('scroll', schedule, true);
    window.addEventListener('resize', schedule);
    // Toolbar nodes are replaced after settings edits; observe the current DOM
    // instead of attaching listeners to nodes which may be removed.
    new MutationObserver(function (changes) {
        if (changes.some(change => change.target instanceof Element && change.target.closest('#wpadminbar'))) schedule();
    }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
    document.addEventListener('DOMContentLoaded', schedule);
    if (document.fonts) document.fonts.ready.then(schedule);
    schedule();
})();
