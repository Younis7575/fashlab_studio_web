/* ============================================================================
   TayyabaCollective — storefront scripts
   Vanilla JavaScript, no dependencies.
   ============================================================================ */
(function () {
    'use strict';

    /* ---------------------------------------------------------------- utils */
    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function csrfToken() {
        var meta = qs('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }
    function baseUrl() {
        return (window.TC_SETTINGS && TC_SETTINGS.baseUrl) || '';
    }
    function flash(type, message) {
        var el = document.createElement('div');
        el.className = 'flash flash-' + type;
        el.innerHTML = '<span></span><button type="button" class="flash-close" aria-label="Dismiss">&times;</button>';
        qs('span', el).textContent = message;
        var main = qs('main');
        if (main) main.prepend(el);
        else document.body.prepend(el);
        bindFlashDismiss();
        setTimeout(function () { el.classList.add('flash-hide'); }, 5000);
    }
    function bindFlashDismiss() {
        qsa('.flash-close').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var f = btn.closest('.flash');
                if (f) f.remove();
            });
        });
    }
    function money(n) {
        return (window.TC_SETTINGS && TC_SETTINGS.currencySymbol || 'Rs.') + ' ' + Number(n || 0).toLocaleString('en-PK', { maximumFractionDigits: 0 });
    }

    /* --------------------------------------------------------- cart (AJAX) */
    function cartFetch(action, payload, onDone) {
        var data = new URLSearchParams();
        data.set('action', action);
        data.set('csrf_token', csrfToken());
        Object.keys(payload || {}).forEach(function (k) { data.set(k, payload[k]); });

        fetch(baseUrl() + '/api/cart.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data.toString(),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { flash('error', res.error || 'Something went wrong.'); return; }
                if (typeof onDone === 'function') onDone(res);
            })
            .catch(function () { flash('error', 'Could not reach the server. Please try again.'); });
    }

    function refreshCartBadge(count) {
        qsa('.cart-count').forEach(function (el) { el.textContent = String(count); });
        qsa('.cart-badge').forEach(function (el) { el.textContent = String(count); });
    }

    /* Mini add-to-cart buttons on product cards */
    function bindMiniCartButtons() {
        qsa('.mini-cart-btn').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                if (btn.disabled) return;
                var original = btn.textContent;
                btn.textContent = 'Adding…';
                cartFetch('add', {
                    product_id: btn.getAttribute('data-product-id'),
                    qty: 1
                }, function (res) {
                    btn.textContent = 'Added ✓';
                    refreshCartBadge(res.count);
                    setTimeout(function () { btn.textContent = original; }, 1500);
                });
            });
        });
    }

    /* --------------------------------------------------------------- shop */
    function shopState() {
        var params = new URLSearchParams();
        var q = qs('#shop-search');
        if (q && q.value.trim()) params.set('q', q.value.trim());

        qsa('.filter-sidebar input[name="category"]:checked').forEach(function (el) {
            params.append('category', el.value);
        });
        var price = qs('.filter-sidebar input[name="price"]:checked');
        if (price && price.value) params.set('price', price.value);
        qsa('.filter-sidebar input[name="fabric"]:checked').forEach(function (el) { params.set('fabric', el.value); });
        qsa('.filter-sidebar input[name="color"]:checked').forEach(function (el) { params.set('color', el.value); });
        qsa('.filter-sidebar input[name="size"]:checked').forEach(function (el) { params.set('size', el.value); });
        qsa('.filter-sidebar input[name="availability"]:checked').forEach(function (el) { params.set('availability', el.value); });
        qsa('.filter-sidebar input[name="sale"]:checked').forEach(function (el) { params.set('sale', '1'); });
        qsa('.filter-sidebar input[name="featured"]:checked').forEach(function (el) { params.set('featured', '1'); });

        var sort = qs('#sort-products');
        if (sort && sort.value && sort.value !== 'newest') params.set('sort', sort.value);

        return params;
    }

    function buildPagination(params, pages, page) {
        var wrap = qs('#shop-pagination');
        if (!wrap) return;
        if (pages <= 1) { wrap.innerHTML = ''; return; }
        var html = '<nav class="pagination" aria-label="Pagination">';
        if (page > 1) {
            var prev = new URLSearchParams(params); prev.set('page', page - 1);
            html += '<a class="page-link" href="?' + prev.toString() + '" data-page="' + (page - 1) + '">&laquo;</a>';
        }
        var start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
        for (var i = start; i <= end; i++) {
            var p = new URLSearchParams(params); p.set('page', i);
            html += '<a class="page-link' + (i === page ? ' active' : '') + '" href="?' + p.toString() + '" data-page="' + i + '">' + i + '</a>';
        }
        if (page < pages) {
            var next = new URLSearchParams(params); next.set('page', page + 1);
            html += '<a class="page-link" href="?' + next.toString() + '" data-page="' + (page + 1) + '">&raquo;</a>';
        }
        html += '</nav>';
        wrap.innerHTML = html;
        qsa('#shop-pagination a[data-page]').forEach(function (a) {
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                var p = new URLSearchParams(params); p.set('page', a.getAttribute('data-page'));
                loadShop(p);
            });
        });
    }

    var shopTimer = null;
    function loadShop(params, replaceUrl) {
        if (shopTimer) clearTimeout(shopTimer);
        shopTimer = setTimeout(function () {
            var target = qs('#shop-product-grid');
            if (!target) return;
            target.classList.add('loading');
            var url = window.location.pathname + '?' + params.toString();
            if (replaceUrl !== false) history.replaceState(null, '', url);

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    target.classList.remove('loading');
                    target.innerHTML = html;
                    var totalEl = qs('#ajax-total', target), pagesEl = qs('#ajax-pages', target), pageEl = qs('#ajax-page', target);
                    var total = totalEl ? parseInt(totalEl.value, 10) : 0;
                    var pages = pagesEl ? parseInt(pagesEl.value, 10) : 1;
                    var page = pageEl ? parseInt(pageEl.value, 10) : 1;
                    if (totalEl) totalEl.remove(); if (pagesEl) pagesEl.remove(); if (pageEl) pageEl.remove();

                    var countEl = qs('#product-count');
                    if (countEl) countEl.textContent = String(total);
                    buildPagination(params, pages, page);
                    bindMiniCartButtons();
                })
                .catch(function () { target.classList.remove('loading'); });
        }, 350);
    }

    function bindShop() {
        if (!qs('.shop-layout')) return;

        var search = qs('#shop-search');
        if (search) {
            var searchTimer = null;
            search.addEventListener('input', function () {
                if (searchTimer) clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { loadShop(shopState()); }, 450);
            });
        }

        var sort = qs('#sort-products');
        if (sort) sort.addEventListener('change', function () { loadShop(shopState()); });

        qsa('.filter-sidebar input').forEach(function (input) {
            input.addEventListener('change', function () { loadShop(shopState()); });
        });

        qsa('.filter-sidebar .clear-filters, .shop-empty .clear-filters').forEach(function (btn) {
            btn.addEventListener('click', function () {
                qsa('.filter-sidebar input').forEach(function (i) {
                    if (i.type === 'radio') i.checked = i.value === '' || (i.name === 'price' && i.value === '');
                    else i.checked = false;
                });
                if (search) search.value = '';
                if (sort) sort.value = 'newest';
                loadShop(shopState());
            });
        });

        /* Mobile filter toggle */
        var toggle = qs('.filter-toggle'), sidebar = qs('#filter-sidebar');
        if (toggle && sidebar) {
            toggle.addEventListener('click', function () { sidebar.classList.add('open'); });
            var close = qs('.filter-close', sidebar);
            if (close) close.addEventListener('click', function () { sidebar.classList.remove('open'); });
        }
    }

    /* ------------------------------------------------------------- product */
    function bindProductPage() {
        if (!qs('.product-details-section')) return;

        /* Gallery */
        var mainImg = qs('#mainProductImage');
        qsa('.product-thumb').forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                var img = qs('img', thumb);
                if (img && mainImg) {
                    mainImg.src = img.src;
                    mainImg.alt = img.alt;
                }
                qsa('.product-thumb').forEach(function (t) { t.classList.remove('active'); });
                thumb.classList.add('active');
            });
        });

        /* Quantity */
        var qtyEl = qs('#quantity');
        qsa('.quantity-selector .qty-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!qtyEl) return;
                var current = parseInt(qtyEl.textContent, 10) || 1;
                var delta = parseInt(btn.getAttribute('data-qty'), 10) || 0;
                var next = current + delta;
                var maxStock = window.TC_PRODUCT ? TC_PRODUCT.maxStock : 99;
                if (next < 1) next = 1;
                if (maxStock > 0 && next > maxStock) next = maxStock;
                qtyEl.textContent = String(next);
            });
        });

        /* Color swatches */
        qsa('.color-option').forEach(function (sw) {
            sw.addEventListener('click', function () {
                qsa('.color-option').forEach(function (s) { s.classList.remove('active'); });
                sw.classList.add('active');
                var label = qs('#selectedColor');
                if (label && sw.getAttribute('data-color')) label.textContent = sw.getAttribute('data-color');
            });
        });

        /* Sizes */
        qsa('.size-option').forEach(function (sz) {
            sz.addEventListener('click', function () {
                qsa('.size-option').forEach(function (s) { s.classList.remove('active'); });
                sz.classList.add('active');
            });
        });

        /* Variant select → update displayed price */
        var variantSel = qs('#variantSelect');
        if (variantSel) {
            variantSel.addEventListener('change', function () {
                var opt = variantSel.options[variantSel.selectedIndex];
                var priceEl = qs('.product-price .current-price');
                if (opt && priceEl && opt.getAttribute('data-price')) {
                    priceEl.textContent = money(opt.getAttribute('data-price'));
                }
            });
        }

        /* Add to cart */
        var addBtn = qs('.product-add-cart');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                if (addBtn.disabled) return;
                var productId = addBtn.getAttribute('data-product-id');
                var variantId = variantSel ? variantSel.value : '';
                var qty = qtyEl ? parseInt(qtyEl.textContent, 10) || 1 : 1;
                var original = addBtn.innerHTML;
                addBtn.disabled = true;
                addBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ADDING…';
                cartFetch('add', { product_id: productId, variant_id: variantId, qty: qty }, function (res) {
                    addBtn.disabled = false;
                    addBtn.innerHTML = '<i class="fa-solid fa-check"></i> ADDED TO CART ✓';
                    refreshCartBadge(res.count);
                    setTimeout(function () { addBtn.innerHTML = original; }, 2000);
                });
            });
        }

        /* Buy now → add then go to checkout */
        var buyBtn = qs('.product-buy-now');
        if (buyBtn) {
            buyBtn.addEventListener('click', function () {
                if (buyBtn.disabled) return;
                var productId = buyBtn.getAttribute('data-product-id');
                var variantId = variantSel ? variantSel.value : '';
                var qty = qtyEl ? parseInt(qtyEl.textContent, 10) || 1 : 1;
                cartFetch('add', { product_id: productId, variant_id: variantId, qty: qty }, function () {
                    window.location.href = baseUrl() + '/checkout.php';
                });
            });
        }

        /* Tabs */
        function openTab(name, scroll) {
            var tab = qs('.product-tab[data-tab="' + name + '"]');
            var content = qs('#tab-' + name);
            if (!tab || !content) return null;
            qsa('.product-tab').forEach(function (t) { t.classList.remove('active'); });
            qsa('.product-tab-content').forEach(function (c) { c.classList.remove('active'); });
            tab.classList.add('active');
            content.classList.add('active');
            if (scroll) {
                var top = qs('.product-tabs').getBoundingClientRect().top + window.pageYOffset - 90;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
            return content;
        }
        qsa('.product-tab').forEach(function (tab) {
            tab.addEventListener('click', function () { openTab(tab.getAttribute('data-tab'), false); });
        });

        /* "See reviews" / "Write a review" links open the Reviews tab */
        qsa('[data-open-tab]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var content = openTab(link.getAttribute('data-open-tab'), true);
                var focusSel = link.getAttribute('data-focus');
                if (content && focusSel) {
                    var form = qs(focusSel);
                    if (form) {
                        form.classList.add('is-open');
                        setTimeout(function () {
                            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            var first = form.querySelector('input:not([type=hidden]), textarea');
                            if (first) first.focus({ preventScroll: true });
                        }, 350);
                    }
                }
            });
        });

        /* Write a Review button toggles the form */
        qsa('[data-toggle-review-form]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = qs('#reviewForm');
                if (!form) return;
                var open = form.classList.toggle('is-open');
                if (open) {
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    var first = form.querySelector('input[type=radio]');
                    if (first) first.focus({ preventScroll: true });
                }
            });
        });

        /* Returning from a review submit (#tab-reviews) — show that tab */
        if (window.location.hash === '#tab-reviews') {
            openTab('reviews', true);
        }
    }

    /* ---------------------------------------------------------------- cart */
    function bindCartPage() {
        if (!qs('.cart-section')) return;

        function reloadCart() {
            window.location.reload();
        }

        qsa('.cart-quantity .qty-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-key');
                var input = qs('.qty-input[data-key="' + key + '"]');
                var current = input ? parseInt(input.value, 10) || 1 : 1;
                var delta = parseInt(btn.getAttribute('data-qty'), 10) || 0;
                var next = current + delta;
                if (next < 1) next = 1;
                if (input && input.max && next > parseInt(input.max, 10)) next = parseInt(input.max, 10);
                cartFetch('update', { key: key, qty: next }, reloadCart);
            });
        });

        qsa('.qty-input').forEach(function (input) {
            input.addEventListener('change', function () {
                var val = parseInt(input.value, 10) || 1;
                if (val < 1) val = 1;
                cartFetch('update', { key: input.getAttribute('data-key'), qty: val }, reloadCart);
            });
        });

        qsa('.cart-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                cartFetch('remove', { key: btn.getAttribute('data-key') }, reloadCart);
            });
        });

        var clearBtn = qs('#clearCart');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!window.confirm('Clear your entire cart?')) return;
                cartFetch('clear', {}, reloadCart);
            });
        }
    }

    /* ------------------------------------------------------------ checkout */
    function bindCheckout() {
        if (!qs('.checkout-page') && !qs('.checkout-container')) return;
        if (!window.TC_CHECKOUT) return;

        function updateTotals() {
            var delivery = qs('input[name="delivery"]:checked');
            var shipping = Number(TC_CHECKOUT.shipping || 0);
            if (delivery && delivery.value === 'express') shipping += Number(TC_CHECKOUT.expressExtra || 250);
            var total = Math.max(0, Number(TC_CHECKOUT.subtotal || 0) + shipping - Number(TC_CHECKOUT.discount || 0));

            var shipEls = qsa('#checkoutShipping, #standardPrice, #expressPrice');
            if (qs('#checkoutShipping')) qs('#checkoutShipping').textContent = shipping > 0 ? money(shipping) : 'FREE';
            if (qs('#checkoutTotal')) qs('#checkoutTotal').textContent = money(total);
        }

        qsa('input[name="delivery"]').forEach(function (radio) {
            radio.addEventListener('change', updateTotals);
        });
        updateTotals();
    }

    /* ---------------------------------------------------------- newsletter */
    function bindNewsletter() {
        qsa('[data-newsletter-form]').forEach(function (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var email = qs('input[name="email"]', form);
                if (!email || !email.value || email.value.indexOf('@') < 1) {
                    flash('error', 'Please enter a valid email address.');
                    return;
                }
                var btn = qs('button[type="submit"]', form);
                var original = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Subscribing…';

                var data = new URLSearchParams();
                data.set('email', email.value);
                data.set('csrf_token', csrfToken());

                fetch(baseUrl() + '/api/newsletter.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data.toString(),
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        btn.disabled = false;
                        btn.textContent = original;
                        if (res.ok) {
                            form.innerHTML = '<p class="newsletter-success">' + (res.message || 'Thank you for subscribing!') + '</p>';
                        } else {
                            flash('error', res.error || 'Could not subscribe. Please try again.');
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        btn.textContent = original;
                        flash('error', 'Could not reach the server. Please try again.');
                    });
            });
        });
    }

    /* ----------------------------------------------------------------- misc */
    function bindFaq() {
        qsa('.faq-question').forEach(function (q) {
            q.addEventListener('click', function () {
                var item = q.closest('.faq-item');
                var wasOpen = item.classList.contains('open');
                qsa('.faq-item').forEach(function (i) { i.classList.remove('open'); });
                qsa('.faq-question').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
                if (!wasOpen) {
                    item.classList.add('open');
                    q.setAttribute('aria-expanded', 'true');
                }
            });
        });
    }

    function bindNav() {
        var toggle = qs('.nav-toggle');
        var nav = qs('.site-nav');
        if (toggle && nav) {
            toggle.addEventListener('click', function () {
                nav.classList.toggle('open');
                var expanded = nav.classList.contains('open');
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            });
        }

        // Handles every dropdown/mega menu (multiple nav-dropdown elements).
        qsa('.nav-dropdown').forEach(function (dropdown) {
            var dropdownToggle = qs('.nav-dropdown-toggle', dropdown);
            if (!dropdownToggle) return;
            dropdownToggle.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var open = dropdown.classList.toggle('open');
                dropdownToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                qsa('.nav-dropdown.open').forEach(function (other) {
                    if (other !== dropdown) {
                        other.classList.remove('open');
                        var t = qs('.nav-dropdown-toggle', other);
                        if (t) t.setAttribute('aria-expanded', 'false');
                    }
                });
            });
        });
        document.addEventListener('click', function () {
            qsa('.nav-dropdown.open').forEach(function (dropdown) {
                dropdown.classList.remove('open');
                var t = qs('.nav-dropdown-toggle', dropdown);
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        });
    }

    /* ----------------------------------------------------------- wishlist */
    function refreshWishlistBadge(count) {
        qsa('.wishlist-count').forEach(function (el) { el.textContent = String(count); });
    }

    function bindWishlist() {
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.wishlist-toggle');
            if (!btn) return;
            ev.preventDefault();

            var productId = btn.getAttribute('data-product-id');
            var wasAdded = btn.getAttribute('data-added') === '1';

            var data = new URLSearchParams();
            data.set('action', 'toggle');
            data.set('product_id', productId);
            data.set('csrf_token', csrfToken());

            fetch(baseUrl() + '/api/wishlist.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString(),
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) { flash('error', res.error || 'Could not update wishlist.'); return; }
                    var added = res.added;
                    refreshWishlistBadge(res.count);
                    flash('success', added ? 'Added to wishlist.' : 'Removed from wishlist.');
                    btn.classList.toggle('active', added);
                    btn.setAttribute('data-added', added ? '1' : '0');
                    btn.setAttribute('aria-pressed', added ? 'true' : 'false');
                    btn.querySelector('i').className = added ? 'fa-solid fa-heart' : 'fa-regular fa-heart';
                    var label = btn.getAttribute('aria-label');
                    if (label) btn.setAttribute('aria-label', added ? 'Remove from wishlist' : 'Add to wishlist');

                    var span = btn.querySelector('span');
                    if (span) span.textContent = added ? 'In Wishlist' : 'Wishlist';

                    // Wishlist page: remove the card when un-wished.
                    if (!added && document.body.classList.contains('wishlist-page')) {
                        var card = btn.closest('.product-card');
                        if (card) card.remove();
                        if (!qs('.wishlist-grid .product-card')) {
                            window.location.reload();
                        }
                    }
                })
                .catch(function () { flash('error', 'Could not reach the server. Please try again.'); });
        });
    }

    /* --------------------------------------------------------- quick view */
    function bindQuickView() {
        document.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.quick-view-btn');
            if (!btn) return;
            ev.preventDefault();

            var id = btn.getAttribute('data-product-id');
            if (!id) return;

            fetch(baseUrl() + '/api/quick-view.php?id=' + encodeURIComponent(id), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) { flash('error', res.error || 'Could not load the product.'); return; }
                    renderQuickView(res.html);
                })
                .catch(function () { flash('error', 'Could not load the product. Please try again.'); });
        });
    }

    function renderQuickView(html) {
        var overlay = document.createElement('div');
        overlay.className = 'qv-overlay show';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        var modal = document.createElement('div');
        modal.className = 'qv-modal';
        modal.innerHTML = '<button type="button" class="qv-close" data-qv-close aria-label="Close">&times;</button>'
            + html;
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        var close = function () {
            overlay.remove();
            document.body.style.overflow = '';
        };
        qsa('[data-qv-close]', overlay).forEach(function (el) { el.addEventListener('click', close); });
        overlay.addEventListener('click', function (ev) {
            if (ev.target === overlay) close();
        });
        document.addEventListener('keydown', function esc(ev) {
            if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
        });

        // Quantity + variant + add-to-bag inside the modal.
        var qtyEl = qs('.qv-qty-value', modal);
        qsa('.qv-qty .qty-btn', modal).forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!qtyEl) return;
                var cur = parseInt(qtyEl.textContent, 10) || 1;
                var v = Math.max(1, Math.min(99, cur + Number(btn.getAttribute('data-qty'))));
                qtyEl.textContent = String(v);
            });
        });

        var variantSel = qs('.qv-variant', modal);
        if (variantSel) {
            variantSel.addEventListener('change', function () {
                var opt = variantSel.options[variantSel.selectedIndex];
                var priceEl = qs('.qv-price .current-price', modal);
                if (opt && opt.getAttribute('data-price') && priceEl) {
                    priceEl.textContent = money(opt.getAttribute('data-price'));
                }
            });
        }

        var addBtn = qs('.qv-add', modal);
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                if (addBtn.disabled) return;
                addBtn.disabled = true;
                var qty = qtyEl ? parseInt(qtyEl.textContent, 10) || 1 : 1;
                var variantId = variantSel ? variantSel.value : '';
                cartFetch('add', {
                    product_id: addBtn.getAttribute('data-product-id'),
                    variant_id: variantId,
                    qty: qty
                }, function (res) {
                    addBtn.disabled = false;
                    refreshCartBadge(res.count);
                    flash('success', 'Added to bag.');
                    close();
                });
            });
        }
    }

    /* --------------------------------------------------------- cart drawer */
    function loadCartDrawer() {
        var body = qs('[data-cart-drawer-body]');
        if (!body) return;
        body.innerHTML = '<p class="drawer-loading">Loading your bag…</p>';
        fetch(baseUrl() + '/api/cart-drawer.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { body.innerHTML = '<p class="drawer-error">Could not load your bag.</p>'; return; }
                body.innerHTML = res.html;
                refreshCartBadge(res.count);
                bindDrawerActions();
            })
            .catch(function () { body.innerHTML = '<p class="drawer-error">Could not load your bag.</p>'; });
    }

    function bindDrawerActions() {
        var drawer = qs('[data-cart-drawer]');
        if (!drawer) return;
        qsa('[data-key]', drawer).forEach(function (el) {
            el.addEventListener('click', function () {
                var key = el.getAttribute('data-key');
                var step = Number(el.getAttribute('data-qty') || 0);
                var action = el.classList.contains('drawer-item-remove') ? 'remove' : 'update';
                var qty = step !== 0 ? undefined : 1;
                var data = new URLSearchParams();
                data.set('action', action);
                data.set('key', key);
                data.set('csrf_token', csrfToken());
                if (qty !== undefined) data.set('qty', qty);

                // update needs the current qty from the drawer row
                if (action === 'update') {
                    var row = el.closest('.drawer-item');
                    var cur = row ? parseInt(qs('.drawer-item-qty span', row).textContent, 10) || 1 : 1;
                    data.set('qty', Math.max(1, cur + step));
                }

                fetch(baseUrl() + '/api/cart.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data.toString(),
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function () { loadCartDrawer(); })
                    .catch(function () { flash('error', 'Could not update the cart.'); });
            });
        });
    }

    function bindCartDrawer() {
        var drawer = qs('[data-cart-drawer]');
        var overlay = qs('[data-cart-drawer-overlay]');
        if (!drawer) return;

        var open = function () {
            drawer.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
            if (overlay) overlay.hidden = false;
            document.body.style.overflow = 'hidden';
            loadCartDrawer();
        };
        var close = function () {
            drawer.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
            if (overlay) overlay.hidden = true;
            document.body.style.overflow = '';
        };

        qsa('[data-cart-drawer-open]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                ev.preventDefault();
                open();
            });
        });
        qsa('[data-cart-drawer-close]').forEach(function (el) { el.addEventListener('click', close); });
        if (overlay) overlay.addEventListener('click', close);
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') close();
        });
    }

    function bindYear() {
        var y = qs('#year');
        if (y) y.textContent = String(new Date().getFullYear());
    }

    /* ------------------------------------------------------------- init */
    /* ------------------------------------------------- pageview beacon */
    function trackPageView() {
        try {
            if (!navigator.sendBeacon) return;
            var payload = JSON.stringify({
                path: (location.pathname || '/') + (location.search || ''),
                referrer: document.referrer || ''
            });
            navigator.sendBeacon(
                baseUrl() + '/api/pageview.php',
                new Blob([payload], { type: 'application/json' })
            );
        } catch (e) { /* analytics must never break the page */ }
    }

    document.addEventListener('DOMContentLoaded', function () {
        trackPageView();
        bindFlashDismiss();
        bindYear();
        bindNav();
        bindFaq();
        bindMiniCartButtons();
        bindShop();
        bindProductPage();
        bindCartPage();
        bindCheckout();
        bindNewsletter();
        bindWishlist();
        bindQuickView();
        bindCartDrawer();
    });
})();