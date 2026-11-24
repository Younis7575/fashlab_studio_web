/* Fashlab Studio — header, mega menu and mobile drawer (v2). */
(function () {
    'use strict';
    var qs = function (s, r) { return (r || document).querySelector(s); };
    var qsa = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    /* Header shadow on scroll */
    var header = qs('[data-fx-header]');
    if (header) {
        var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* Hide zero badges */
    function syncBadges() {
        qsa('.fx-badge').forEach(function (b) {
            if ((b.textContent || '').trim() === '0') b.setAttribute('data-zero', '');
            else b.removeAttribute('data-zero');
        });
    }
    syncBadges();
    qsa('.fx-badge').forEach(function (b) {
        new MutationObserver(syncBadges).observe(b, { childList: true, characterData: true, subtree: true });
    });

    /* Mega menu: hover with intent on desktop, click / keyboard everywhere */
    var items = qsa('[data-fx-mega]');
    var closeTimer = null;
    function setOpen(item, open) {
        item.classList.toggle('is-open', open);
        var btn = qs('.fx-nav-link', item);
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function closeAll(except) { items.forEach(function (i) { if (i !== except) setOpen(i, false); }); }
    var canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    items.forEach(function (item) {
        var btn = qs('.fx-nav-link', item);
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !item.classList.contains('is-open');
            closeAll(item); setOpen(item, open);
        });
        if (canHover) {
            item.addEventListener('mouseenter', function () { clearTimeout(closeTimer); closeAll(item); setOpen(item, true); });
            item.addEventListener('mouseleave', function () { closeTimer = setTimeout(function () { setOpen(item, false); }, 160); });
        }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('[data-fx-mega]')) closeAll(); });

    /* Drawer */
    var drawer = qs('[data-fx-drawer]');
    var overlay = qs('[data-fx-drawer-overlay]');
    var lastFocus = null;
    function openDrawer() {
        if (!drawer) return;
        lastFocus = document.activeElement;
        overlay.hidden = false;
        requestAnimationFrame(function () { overlay.classList.add('is-visible'); drawer.classList.add('is-open'); });
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('fx-lock');
        qsa('[data-fx-drawer-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
        setTimeout(function () { drawer.focus(); }, 50);
        if (history.pushState && location.hash !== '#menu') history.pushState({ fxDrawer: 1 }, '', '#menu');
    }
    function closeDrawer(fromPop) {
        if (!drawer || !drawer.classList.contains('is-open')) return;
        drawer.classList.remove('is-open');
        overlay.classList.remove('is-visible');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('fx-lock');
        qsa('[data-fx-drawer-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        setTimeout(function () { overlay.hidden = true; }, 320);
        if (!fromPop && location.hash === '#menu') history.back();
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    qsa('[data-fx-drawer-open]').forEach(function (b) { b.addEventListener('click', openDrawer); });
    qsa('[data-fx-drawer-close]').forEach(function (b) { b.addEventListener('click', function () { closeDrawer(false); }); });
    if (overlay) overlay.addEventListener('click', function () { closeDrawer(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeDrawer(false); closeAll(); }
    });
    window.addEventListener('popstate', function () { closeDrawer(true); });
    window.addEventListener('resize', function () { if (window.innerWidth > 1023) closeDrawer(false); });
    if (location.hash === '#menu' && history.replaceState) history.replaceState(null, '', location.pathname + location.search);

    /* Drawer links close the drawer before navigating to anchors / overlays */
    if (drawer) {
        qsa('a[href]', drawer).forEach(function (a) {
            a.addEventListener('click', function () {
                if (location.hash === '#menu' && history.replaceState) history.replaceState(null, '', location.pathname + location.search);
            });
        });
    }

    /* Accordions (only one open at a time) */
    var accs = qsa('.fx-acc');
    accs.forEach(function (acc) {
        var t = qs('.fx-acc-toggle', acc), p = qs('.fx-acc-panel', acc);
        t.addEventListener('click', function () {
            var open = !acc.classList.contains('is-open');
            accs.forEach(function (o) {
                if (o !== acc) { o.classList.remove('is-open'); qs('.fx-acc-panel', o).hidden = true; qs('.fx-acc-toggle', o).setAttribute('aria-expanded', 'false'); }
            });
            acc.classList.toggle('is-open', open);
            p.hidden = !open;
            t.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
})();
