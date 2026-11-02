/* ============================================================================
   FASHLAB STUDIO — LUXURY INTERACTIONS
   ----------------------------------------------------------------------------
   Loaded after site.js + premium.js. Everything here is progressive
   enhancement: if this file fails, the storefront still works.

   Contains:  particle system · fabric canvas · hero parallax/tilt ·
              announcement rotator · live search overlay · Lottie loader ·
              Three.js experience scene · scroll reveals · cursor glow ·
              product image zoom + lightbox · mobile sticky buy bar
   ========================================================================== */
(function () {
    'use strict';

    var qs = function (s, c) { return (c || document).querySelector(s); };
    var qsa = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var isMobile = function () { return window.innerWidth < 768; };
    var base = (window.TC_SETTINGS && TC_SETTINGS.baseUrl) || '';

    /* ================================================================
       1. ANNOUNCEMENT ROTATOR
       ================================================================ */
    function initAnnouncement() {
        var bar = qs('[data-announcement-bar]');
        if (!bar) return;
        var msgs = qsa('.announcement-msg', bar);
        if (msgs.length < 2 || reduced) return;
        var i = 0;
        setInterval(function () {
            msgs[i].classList.remove('is-active');
            i = (i + 1) % msgs.length;
            msgs[i].classList.add('is-active');
        }, 4200);
    }

    /* ================================================================
       2. PARTICLE SYSTEM — champagne dust, depth parallax
       ================================================================ */
    function initParticles() {
        var canvas = qs('.lx-hero-particles');
        if (!canvas || reduced || !canvas.getContext) return;
        var ctx = canvas.getContext('2d');
        var hero = canvas.parentElement;
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var W = 0, H = 0;
        var mouse = { x: 0.5, y: 0.5, tx: 0.5, ty: 0.5 };
        var scrollOffset = 0;
        var running = true;
        var particles = [];

        var COUNT = isMobile() ? 26 : (window.innerWidth < 1200 ? 55 : 90);

        function resize() {
            var r = hero.getBoundingClientRect();
            W = r.width; H = r.height;
            canvas.width = Math.floor(W * dpr);
            canvas.height = Math.floor(H * dpr);
            canvas.style.width = W + 'px';
            canvas.style.height = H + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }

        function seed() {
            particles = [];
            for (var i = 0; i < COUNT; i++) {
                var depth = 0.25 + Math.random() * 0.75; // 0..1 → far..near
                particles.push({
                    x: Math.random(),
                    y: Math.random(),
                    z: depth,
                    r: 0.7 + depth * 2.1,
                    vx: (Math.random() - 0.5) * 0.00016 * depth,
                    vy: -(0.00008 + Math.random() * 0.00022) * depth,
                    a: 0.12 + depth * 0.4,
                    tw: Math.random() * Math.PI * 2,
                    tws: 0.006 + Math.random() * 0.012,
                    warm: Math.random() > 0.35
                });
            }
        }

        function frame() {
            if (!running) { requestAnimationFrame(frame); return; }
            mouse.x += (mouse.tx - mouse.x) * 0.05;
            mouse.y += (mouse.ty - mouse.y) * 0.05;

            ctx.clearRect(0, 0, W, H);
            for (var i = 0; i < particles.length; i++) {
                var p = particles[i];
                p.x += p.vx;
                p.y += p.vy;
                p.tw += p.tws;
                if (p.y < -0.05) { p.y = 1.05; p.x = Math.random(); }
                if (p.x < -0.05) p.x = 1.05;
                if (p.x > 1.05) p.x = -0.05;

                // depth-based parallax: near particles move more with mouse + scroll
                var px = p.x * W + (mouse.x - 0.5) * 46 * p.z;
                var py = p.y * H + (mouse.y - 0.5) * 30 * p.z - scrollOffset * p.z * 0.18;
                if (py < -20 || py > H + 20) continue;

                var alpha = p.a * (0.55 + 0.45 * Math.sin(p.tw));
                ctx.beginPath();
                ctx.arc(px, py, p.r, 0, Math.PI * 2);
                ctx.fillStyle = p.warm
                    ? 'rgba(136, 32, 56,' + alpha.toFixed(3) + ')'
                    : 'rgba(226, 194, 201,' + (alpha * 0.9).toFixed(3) + ')';
                ctx.fill();
            }
            requestAnimationFrame(frame);
        }

        hero.addEventListener('mousemove', function (e) {
            var r = hero.getBoundingClientRect();
            mouse.tx = (e.clientX - r.left) / r.width;
            mouse.ty = (e.clientY - r.top) / r.height;
        }, { passive: true });

        window.addEventListener('scroll', function () {
            scrollOffset = Math.min(window.scrollY, H);
        }, { passive: true });

        window.addEventListener('resize', function () {
            resize();
            if ((isMobile() ? 26 : 90) !== particles.length) seed();
        });

        // Pause when hero is off-screen (performance)
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                running = entries[0].isIntersecting;
            }, { threshold: 0 }).observe(hero);
        }

        resize();
        seed();
        requestAnimationFrame(frame);
    }

    /* ================================================================
       3. FABRIC / SILK CANVAS — slow flowing ribbon behind hero product
       ================================================================ */
    function initFabric() {
        var canvas = qs('.lx-fabric-canvas');
        if (!canvas || reduced || !canvas.getContext) return;
        var ctx = canvas.getContext('2d');
        var stage = canvas.parentElement;
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var W = 0, H = 0;
        var t = 0;
        var mx = 0.5, tmx = 0.5;
        var running = true;

        function resize() {
            var r = canvas.getBoundingClientRect();
            W = r.width; H = r.height;
            canvas.width = Math.floor(W * dpr);
            canvas.height = Math.floor(H * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }

        /* One silk band = layered sine curves with a champagne gradient fill */
        function band(phase, amp, yBase, thickness, hue) {
            var steps = 34;
            ctx.beginPath();
            var i, x, y;
            for (i = 0; i <= steps; i++) {
                x = (i / steps) * W;
                y = yBase
                    + Math.sin(phase + (i / steps) * 3.4) * amp
                    + Math.sin(phase * 0.6 + (i / steps) * 6.2) * (amp * 0.35);
                if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            }
            for (i = steps; i >= 0; i--) {
                x = (i / steps) * W;
                y = yBase + thickness
                    + Math.sin(phase + 0.7 + (i / steps) * 3.4) * amp
                    + Math.sin(phase * 0.6 + (i / steps) * 6.2) * (amp * 0.35);
                ctx.lineTo(x, y);
            }
            ctx.closePath();
            var g = ctx.createLinearGradient(0, yBase - amp, W, yBase + thickness + amp);
            g.addColorStop(0, 'rgba(231, 190, 200, 0)');
            g.addColorStop(0.35, hue[0]);
            g.addColorStop(0.7, hue[1]);
            g.addColorStop(1, 'rgba(136, 32, 56, 0)');
            ctx.fillStyle = g;
            ctx.fill();
        }

        function frame() {
            if (!running) { requestAnimationFrame(frame); return; }
            t += 0.006;
            mx += (tmx - mx) * 0.04;
            ctx.clearRect(0, 0, W, H);

            var shift = (mx - 0.5) * 34;
            ctx.save();
            ctx.translate(shift, 0);

            band(t, H * 0.055, H * 0.30, H * 0.13, ['rgba(223, 171, 183,.42)', 'rgba(239, 213, 219,.30)']);
            band(t * 0.85 + 1.7, H * 0.07, H * 0.52, H * 0.16, ['rgba(136, 32, 56,.30)', 'rgba(233, 198, 206,.36)']);
            band(t * 1.15 + 3.4, H * 0.05, H * 0.72, H * 0.11, ['rgba(246, 234, 237,.5)', 'rgba(219, 166, 178,.3)']);

            ctx.restore();
            requestAnimationFrame(frame);
        }

        stage.addEventListener('mousemove', function (e) {
            var r = stage.getBoundingClientRect();
            tmx = (e.clientX - r.left) / r.width;
        }, { passive: true });

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (en) { running = en[0].isIntersecting; }, { threshold: 0 }).observe(stage);
        }

        window.addEventListener('resize', resize);
        resize();
        requestAnimationFrame(frame);
    }

    /* ================================================================
       4. HERO PARALLAX + STAGE TILT
       ================================================================ */
    function initHeroTilt() {
        var stage = qs('.lx-hero-stage');
        if (!stage || reduced) return;
        if (!window.matchMedia || !window.matchMedia('(hover: hover)').matches) return;

        var frame = qs('.lx-hero-frame', stage);
        var ghost = qs('.lx-hero-frame-ghost', stage);
        var cards = qsa('.lx-float-card', stage);
        var raf = null;
        var target = { x: 0, y: 0 };
        var cur = { x: 0, y: 0 };

        function apply() {
            cur.x += (target.x - cur.x) * 0.07;
            cur.y += (target.y - cur.y) * 0.07;
            var ry = -9 + cur.x * 10;
            var rx = 3 - cur.y * 8;
            if (frame) frame.style.transform =
                'rotateY(' + ry.toFixed(2) + 'deg) rotateX(' + rx.toFixed(2) + 'deg) translateZ(30px)';
            if (ghost) ghost.style.animation = 'none',
                ghost.style.transform =
                'rotateY(' + (ry * 0.7).toFixed(2) + 'deg) rotateX(' + (rx * 0.7).toFixed(2) + 'deg) translateZ(-40px)';
            for (var i = 0; i < cards.length; i++) {
                var depth = 1 + i * 0.35;
                cards[i].style.setProperty('--tilt', '0');
                cards[i].style.marginLeft = (cur.x * 14 * depth).toFixed(1) + 'px';
                cards[i].style.marginTop = (cur.y * 10 * depth).toFixed(1) + 'px';
            }
            raf = requestAnimationFrame(apply);
        }

        stage.addEventListener('mousemove', function (e) {
            var r = stage.getBoundingClientRect();
            target.x = ((e.clientX - r.left) / r.width - 0.5) * 2;
            target.y = ((e.clientY - r.top) / r.height - 0.5) * 2;
        }, { passive: true });

        stage.addEventListener('mouseleave', function () { target.x = 0; target.y = 0; });

        raf = requestAnimationFrame(apply);
    }

    /* ================================================================
       5. PRODUCT CARD 3D TILT (subtle, desktop only)
       ================================================================ */
    function initCardTilt() {
        if (reduced) return;
        if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

        var MAX = 6; // degrees — deliberately subtle
        var SEL = '.product-card, .category-card';
        document.addEventListener('mousemove', function (ev) {
            var card = ev.target.closest && ev.target.closest(SEL);
            if (!card) return;
            var r = card.getBoundingClientRect();
            var px = (ev.clientX - r.left) / r.width - 0.5;
            var py = (ev.clientY - r.top) / r.height - 0.5;
            card.style.transform =
                'perspective(1200px) rotateY(' + (px * MAX).toFixed(2) + 'deg) rotateX(' +
                (-py * MAX).toFixed(2) + 'deg) translateY(-6px)';
            card.style.transition = 'transform .18s ease-out, box-shadow .4s ease';
            /* depth: the category image moves independently from the card */
            if (card.classList.contains('category-card')) {
                var img = card.querySelector('.category-image img');
                if (img) {
                    img.style.transition = 'transform .25s ease-out';
                    img.style.transform = 'translate3d(' + (-px * 16).toFixed(1) + 'px,' +
                        (-py * 16).toFixed(1) + 'px,0) scale(1.1)';
                }
            }
        }, { passive: true });

        document.addEventListener('mouseout', function (ev) {
            var card = ev.target.closest && ev.target.closest(SEL);
            if (!card) return;
            var to = ev.relatedTarget;
            if (to && card.contains(to)) return;
            card.style.transform = '';
            card.style.transition = '';
            var img = card.querySelector('.category-image img');
            if (img) { img.style.transform = ''; img.style.transition = ''; }
        }, { passive: true });
    }

    /* ================================================================
       6. LIVE SEARCH OVERLAY
       ================================================================ */
    function initSearch() {
        var overlay = qs('[data-search-overlay]');
        if (!overlay) return;
        var input = qs('[data-live-search]', overlay);
        var results = qs('[data-search-results]', overlay);
        var viewAll = qs('[data-search-view-all]', overlay);
        var lastFocus = null;
        var timer = null;
        var seq = 0;

        function open() {
            lastFocus = document.activeElement;
            overlay.hidden = false;
            requestAnimationFrame(function () { overlay.classList.add('is-open'); });
            document.body.style.overflow = 'hidden';
            setTimeout(function () { input && input.focus(); }, 60);
        }
        function close() {
            overlay.classList.remove('is-open');
            document.body.style.overflow = '';
            setTimeout(function () { overlay.hidden = true; }, 260);
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        qsa('[data-search-open]').forEach(function (b) {
            b.addEventListener('click', function (e) { e.preventDefault(); open(); });
        });
        qsa('[data-search-close]').forEach(function (b) { b.addEventListener('click', close); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) close();
            // "/" opens search when not typing in a field
            if (e.key === '/' && overlay.hidden) {
                var tag = (document.activeElement && document.activeElement.tagName) || '';
                if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
                    e.preventDefault();
                    open();
                }
            }
        });

        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function render(data, q) {
            var products = (data && data.products) || [];
            var cats = (data && data.categories) || [];
            var colls = (data && data.collections) || [];
            var html = '';

            if (!products.length && !cats.length && !colls.length) {
                html = '<p class="search-hint">No matches for “' + esc(q) +
                    '”. Try a fabric like lawn or silk, or browse <a href="' +
                    base + '/shop.php">the full collection</a>.</p>';
                results.innerHTML = html;
                if (viewAll) viewAll.style.display = 'none';
                return;
            }

            if (products.length) {
                html += '<p class="search-section-label">Products</p>';
                products.slice(0, 6).forEach(function (p) {
                    var price = (window.TC_SETTINGS && TC_SETTINGS.currencySymbol || 'Rs.') + ' ' +
                        Number(p.final_price || p.price || 0).toLocaleString('en-PK');
                    html += '<a class="search-result" href="' + base + '/product.php?slug=' + encodeURIComponent(p.slug) + '">' +
                        '<img src="' + esc(p.image || '') + '" alt="' + esc(p.name) + '" loading="lazy">' +
                        '<span class="sr-info"><span class="sr-name">' + esc(p.name) + '</span>' +
                        '<span class="sr-cat">' + esc(p.category || 'Collection') + '</span></span>' +
                        '<span class="sr-price">' + (p.on_sale && p.formatted_compare_at
                            ? '<s>' + esc(p.formatted_compare_at) + '</s>' : '') + esc(p.formatted_price || price) +
                        '</span></a>';
                });
            }
            if (cats.length) {
                html += '<p class="search-section-label">Categories</p>';
                cats.slice(0, 4).forEach(function (c) {
                    html += '<a class="search-result" href="' + base + '/category.php?slug=' + encodeURIComponent(c.slug) + '">' +
                        '<img src="' + esc(c.image || '') + '" alt="' + esc(c.name) + '" loading="lazy">' +
                        '<span class="sr-info"><span class="sr-name">' + esc(c.name) + '</span>' +
                        '<span class="sr-cat">Category</span></span></a>';
                });
            }
            if (colls.length) {
                html += '<p class="search-section-label">Collections</p>';
                colls.slice(0, 3).forEach(function (c) {
                    html += '<a class="search-result" href="' + base + '/collection.php?slug=' + encodeURIComponent(c.slug) + '">' +
                        '<img src="' + esc(c.image || '') + '" alt="' + esc(c.name) + '" loading="lazy">' +
                        '<span class="sr-info"><span class="sr-name">' + esc(c.name) + '</span>' +
                        '<span class="sr-cat">Collection</span></span></a>';
                });
            }
            results.innerHTML = html;
            if (viewAll) {
                viewAll.style.display = '';
                viewAll.setAttribute('href', base + '/shop.php?q=' + encodeURIComponent(q));
            }
        }

        if (input) {
            input.addEventListener('input', function () {
                var q = input.value.trim();
                if (timer) clearTimeout(timer);
                if (q.length < 2) {
                    results.innerHTML = '<p class="search-hint">Start typing to search our catalogue by name, category, fabric or collection.</p>';
                    if (viewAll) viewAll.setAttribute('href', base + '/shop.php');
                    return;
                }
                var id = ++seq;
                results.innerHTML = '<div class="search-skeleton"></div><div class="search-skeleton"></div><div class="search-skeleton"></div>';
                timer = setTimeout(function () {
                    var url = (TC_SETTINGS.searchUrl || (base + '/api/v1/index.php?_route=search/suggest&q=')) +
                        encodeURIComponent(q);
                    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (id !== seq) return; // stale
                            render((res && res.data) || {}, q);
                        })
                        .catch(function () {
                            if (id !== seq) return;
                            results.innerHTML = '<p class="search-hint">Search is unavailable right now — please try again.</p>';
                        });
                }, 280);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var q = input.value.trim();
                    window.location.href = base + '/shop.php?q=' + encodeURIComponent(q);
                }
            });
        }
    }

    /* ================================================================
       7. LOTTIE LOADER — lazy, plays on view / hover
       ================================================================ */
    var lottieReady = null;
    function loadLottieLib() {
        if (lottieReady) return lottieReady;
        lottieReady = new Promise(function (resolve, reject) {
            if (window.lottie) { resolve(window.lottie); return; }
            var s = document.createElement('script');
            s.src = base + '/assets/js/vendor/lottie.min.js';
            s.async = true;
            s.onload = function () { resolve(window.lottie); };
            s.onerror = reject;
            document.head.appendChild(s);
        });
        return lottieReady;
    }

    function initLottie() {
        var nodes = qsa('[data-lottie]');
        if (!nodes.length) return;
        if (reduced) { // static fallback: keep the FA icon visible
            nodes.forEach(function (n) { var i = qs('i', n); if (i) i.style.display = ''; });
            return;
        }
        if (!('IntersectionObserver' in window)) return;

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var host = entry.target;
                io.unobserve(host);
                loadLottieLib().then(function (lottie) {
                    var name = host.getAttribute('data-lottie');
                    var icon = qs('i', host);
                    var anim = lottie.loadAnimation({
                        container: host,
                        renderer: 'svg',
                        loop: true,
                        autoplay: false,
                        path: base + '/assets/lottie/' + name + '.json'
                    });
                    anim.addEventListener('data_failed', function () {
                        if (icon) icon.style.display = '';
                    });
                    anim.addEventListener('DOMLoaded', function () {
                        if (icon) icon.style.display = 'none';
                        anim.play();
                    });
                    // replay on hover for feature cards
                    var card = host.closest('.feature-card');
                    if (card) {
                        card.addEventListener('mouseenter', function () { anim.goToAndPlay(0, true); });
                    }
                }).catch(function () { /* FA fallback stays visible */ });
            });
        }, { rootMargin: '80px', threshold: 0.2 });

        nodes.forEach(function (n) { io.observe(n); });
    }

    /* ================================================================
       7B. THREE.JS HERO — "The Signature Edit" above-the-fold scene
       Animated silk sheets (real vertex waves) · glass forms · floating
       cards · pearl spheres · champagne dust · mouse + scroll parallax.
       Shared lazy loader; silent fallback keeps the 2D canvas layers.
       ================================================================ */
    var threePromise = null;
    function loadThreeLib() {
        if (window.THREE) return Promise.resolve(window.THREE);
        if (!threePromise) {
            threePromise = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = base + '/assets/js/vendor/three.min.js';
                s.async = true;
                s.onload = function () { resolve(window.THREE); };
                s.onerror = reject;
                document.head.appendChild(s);
            });
        }
        return threePromise;
    }

    function initHeroScene() {
        var canvas = qs('.lx-hero-3d');
        var hero = qs('.lx-hero');
        if (!canvas || !hero || reduced) return;
        if (navigator.deviceMemory && navigator.deviceMemory < 2) return;
        if (!window.WebGLRenderingContext) return;

        var tier = isMobile() ? 'low' : (window.innerWidth < 1200 ? 'mid' : 'high');

        loadThreeLib().then(function (THREE) {
            try { buildHero(THREE); } catch (e) { /* 2D layers stay visible */ }
        }).catch(function () { /* blocked/offline — fallback layers remain */ });

        function buildHero(THREE) {
            var W = hero.clientWidth || 1, H = hero.clientHeight || 1;
            var renderer;
            try {
                renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: tier === 'high' });
            } catch (e) { return; }
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, tier === 'low' ? 1.3 : 1.6));
            renderer.setSize(W, H, false);

            var scene = new THREE.Scene();
            scene.fog = new THREE.Fog(0xfdfbf7, 13.5, 36); // light ivory atmosphere, keeps objects crisp
            var camera = new THREE.PerspectiveCamera(40, W / H, 0.1, 100);
            camera.position.set(0, 0, 10);

            /* champagne lighting — key-dominant so 3D forms shade visibly */
            scene.add(new THREE.AmbientLight(0xfaf3f4, 0.72));
            var key = new THREE.DirectionalLight(0xf1dadf, 1.35);
            key.position.set(5, 7, 6);
            scene.add(key);
            var rim = new THREE.DirectionalLight(0x882038, 0.5);
            rim.position.set(-7, -4, 3);
            scene.add(rim);
            var glow = new THREE.PointLight(0xe8c1ca, 0.65, 40);
            glow.position.set(0, 2.5, 7);
            scene.add(glow);

            var world = new THREE.Group();
            scene.add(world);

            /* --- silk sheets: vertex-displaced planes = moving fabric --- */
            function makeSilk(w, h, sx, sy, color, opacity) {
                var geo = new THREE.PlaneGeometry(w, h, sx, sy);
                var mesh = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({
                    color: color, metalness: 0.3, roughness: 0.3,
                    transparent: true, opacity: opacity, side: THREE.DoubleSide
                }));
                mesh.userData.base = Float32Array.from(geo.attributes.position.array);
                return mesh;
            }
            var segX = tier === 'low' ? 30 : 60;
            var segY = tier === 'low' ? 8 : 14;
            var silkA = makeSilk(11.5, 2.7, segX, segY, 0x882038, 0.62);
            silkA.position.set(0.5, 2.4, -3.8);
            silkA.rotation.set(-0.3, 0, -0.1);
            world.add(silkA);
            var silkB = makeSilk(9.5, 2.1, segX, segY, 0xeed3d9, 0.55);
            silkB.position.set(-0.5, -2.8, -4);
            silkB.rotation.set(0.26, 0, 0.09);
            world.add(silkB);

            function wave(mesh, t, amp) {
                var pos = mesh.geometry.attributes.position;
                var base = mesh.userData.base;
                for (var i = 0; i < pos.count; i++) {
                    var x = base[i * 3], y = base[i * 3 + 1];
                    pos.array[i * 3 + 2] =
                        Math.sin(x * 0.75 + t * 1.15) * amp +
                        Math.sin(y * 1.9 + t * 0.85) * amp * 0.5;
                }
                pos.needsUpdate = true;
                /* re-light the folds: real shading across the moving fabric */
                mesh.geometry.computeVertexNormals();
            }

            /* --- champagne silk ribbon (tube) across mid depth --- */
            var ribbonPts = [];
            for (var i = 0; i <= 48; i++) {
                var t = (i / 48) * Math.PI * 3;
                ribbonPts.push(new THREE.Vector3(
                    -7.5 + (i / 48) * 15,
                    Math.sin(t) * 0.9 + Math.sin(t * 0.5) * 0.3,
                    Math.cos(t * 0.6) * 1.4
                ));
            }
            var ribbon = new THREE.Mesh(
                new THREE.TubeGeometry(new THREE.CatmullRomCurve3(ribbonPts), 140, 0.12, 8, false),
                new THREE.MeshStandardMaterial({
                    color: 0x882038, metalness: 0.4, roughness: 0.4,
                    transparent: true, opacity: 0.85
                })
            );
            ribbon.position.set(0, -0.4, -2.6);
            world.add(ribbon);

            /* --- transparent glass forms --- */
            var glassMat = new THREE.MeshPhysicalMaterial({
                color: 0xf7eaed, metalness: 0.05, roughness: 0.08,
                transparent: true, opacity: 0.4, clearcoat: 0.6, clearcoatRoughness: 0.15
            });
            var edgeMat = new THREE.LineBasicMaterial({ color: 0x882038, transparent: true, opacity: 0.75 });
            var icoGeo = new THREE.IcosahedronGeometry(1.15, 0);
            var ico = new THREE.Group();
            ico.add(new THREE.Mesh(icoGeo, glassMat));
            ico.add(new THREE.LineSegments(new THREE.EdgesGeometry(icoGeo), edgeMat));
            ico.position.set(tier === 'low' ? -5.0 : -5.6, 1.9, -3.2);
            world.add(ico);

            var pearlGlass = new THREE.Mesh(new THREE.SphereGeometry(0.8, 24, 18), glassMat.clone());
            pearlGlass.material.opacity = 0.34;
            pearlGlass.position.set(5.6, -2.5, -3);
            world.add(pearlGlass);

            var ring = new THREE.Mesh(
                new THREE.TorusGeometry(1.0, 0.045, 10, 64),
                new THREE.MeshStandardMaterial({ color: 0x882038, metalness: 0.7, roughness: 0.3 })
            );
            ring.position.set(5.1, 2.7, -4.4);
            ring.rotation.set(0.9, 0.4, 0);
            world.add(ring);

            /* --- floating product cards (abstract ivory + champagne edge) --- */
            var cards = [];
            var cardMat = new THREE.MeshStandardMaterial({
                color: 0xfffdf8, metalness: 0.05, roughness: 0.6, transparent: true, opacity: 0.95
            });
            var cardEdgeMat = new THREE.MeshStandardMaterial({ color: 0x882038, metalness: 0.5, roughness: 0.4 });
            var cardSpots = tier === 'low'
                ? [[-5.0, 2.7, -3.6], [5.2, -1.4, -4.2]]
                : [[-5.2, 2.8, -3.6], [5.3, -1.4, -4.2], [-5.5, -2.4, -3.2]];
            for (var c = 0; c < cardSpots.length; c++) {
                var cw = 1.05, ch = 1.4;
                var wrap = new THREE.Group();
                var edge = new THREE.Mesh(new THREE.BoxGeometry(cw + 0.06, ch + 0.06, 0.02), cardEdgeMat);
                edge.position.z = -0.03;
                var face = new THREE.Mesh(new THREE.BoxGeometry(cw, ch, 0.05), cardMat);
                wrap.add(edge); wrap.add(face);
                wrap.position.set(cardSpots[c][0], cardSpots[c][1], cardSpots[c][2]);
                wrap.rotation.set(0.05, cardSpots[c][0] < 0 ? 0.55 : -0.55, cardSpots[c][0] < 0 ? -0.08 : 0.08);
                wrap.userData = { base: wrap.position.clone(), phase: c * 1.7, speed: 0.6 + c * 0.18 };
                world.add(wrap);
                cards.push(wrap);
            }

            /* --- pearl accents --- */
            var pearls = [];
            var pearlMat = new THREE.MeshStandardMaterial({ color: 0xf2dde2, metalness: 0.35, roughness: 0.25 });
            var pearlSpots = [[-3.4, -1.6, -1.8, 0.16], [3.6, 1.8, -2.2, 0.12], [1.8, -2.6, -1.2, 0.1]];
            for (var pI = 0; pI < pearlSpots.length; pI++) {
                var pl = new THREE.Mesh(new THREE.SphereGeometry(pearlSpots[pI][3], 18, 14), pearlMat);
                pl.position.set(pearlSpots[pI][0], pearlSpots[pI][1], pearlSpots[pI][2]);
                pl.userData = { base: pl.position.clone(), phase: pI * 2.1 };
                world.add(pl);
                pearls.push(pl);
            }

            /* --- champagne dust (depth layers, tiered density) --- */
            var pCount = tier === 'high' ? 180 : (tier === 'mid' ? 110 : 60);
            var pGeo = new THREE.BufferGeometry();
            var pPos = new Float32Array(pCount * 3);
            for (var p = 0; p < pCount; p++) {
                pPos[p * 3] = (Math.random() - 0.5) * 20;
                pPos[p * 3 + 1] = (Math.random() - 0.5) * 11;
                pPos[p * 3 + 2] = -7 + Math.random() * 9;
            }
            pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
            var dust = new THREE.Points(pGeo, new THREE.PointsMaterial({
                color: 0x882038, size: 0.055, transparent: true, opacity: 0.65, sizeAttenuation: true
            }));
            scene.add(dust);

            /* --- interaction state --- */
            var mx = 0, my = 0, tmx = 0, tmy = 0;
            var sy = 0, tsy = 0;
            hero.addEventListener('mousemove', function (e) {
                var r = hero.getBoundingClientRect();
                tmx = ((e.clientX - r.left) / r.width - 0.5) * 2;
                tmy = ((e.clientY - r.top) / r.height - 0.5) * 2;
            }, { passive: true });
            window.addEventListener('scroll', function () {
                tsy = Math.min(window.scrollY || 0, (hero.offsetHeight || 800) * 1.2);
            }, { passive: true });

            var running = true;
            if ('IntersectionObserver' in window) {
                new IntersectionObserver(function (en) { running = en[0].isIntersecting; }, { threshold: 0 })
                    .observe(hero);
            }

            /* first frame immediately — visible even before rAF ticks */
            renderer.render(scene, camera);
            canvas.classList.add('is-live');

            var clock = new THREE.Clock();
            function loop() {
                requestAnimationFrame(loop);
                if (!running) return;
                var e = clock.getElapsedTime();
                mx += (tmx - mx) * 0.05;
                my += (tmy - my) * 0.05;
                sy += (tsy - sy) * 0.07;

                /* fabric in motion */
                wave(silkA, e, 0.4 + sy * 0.00025);
                wave(silkB, e + 2.1, 0.3 + sy * 0.0002);
                ribbon.rotation.z = Math.sin(e * 0.3) * 0.07;
                ribbon.position.y = -0.4 + Math.sin(e * 0.5) * 0.22 + sy * 0.0035;

                /* glass + accents */
                ico.rotation.y = e * 0.16;
                ico.rotation.x = Math.sin(e * 0.4) * 0.18;
                ico.position.y = 1.9 + Math.sin(e * 0.7) * 0.24;
                pearlGlass.rotation.y = -e * 0.2;
                pearlGlass.position.y = -2.5 + Math.cos(e * 0.6) * 0.2;
                ring.rotation.z = e * 0.25;
                ring.rotation.x = 0.9 + Math.sin(e * 0.35) * 0.2;

                /* floating cards bob */
                for (var k = 0; k < cards.length; k++) {
                    var d = cards[k].userData;
                    cards[k].position.y = d.base.y + Math.sin(e * d.speed + d.phase) * 0.22;
                    cards[k].position.x = d.base.x + Math.cos(e * 0.4 + d.phase) * 0.1;
                    cards[k].rotation.y = (d.base.x < 0 ? 0.55 : -0.55) + Math.sin(e * 0.5 + d.phase) * 0.08 + mx * 0.12;
                }
                for (var q = 0; q < pearls.length; q++) {
                    var pd = pearls[q].userData;
                    pearls[q].position.y = pd.base.y + Math.sin(e * 0.8 + pd.phase) * 0.3;
                    pearls[q].position.x = pd.base.x + Math.cos(e * 0.55 + pd.phase) * 0.16;
                }

                /* mouse + scroll parallax with depth layers */
                world.rotation.y = mx * 0.13;
                world.rotation.x = my * 0.07 + Math.sin(e * 0.2) * 0.02;
                world.position.y = sy * 0.006;
                camera.position.x = mx * 0.55;
                camera.position.y = -my * 0.35 + sy * 0.002;
                camera.position.z = 10 + sy * 0.003;
                camera.lookAt(0, 0, 0);
                dust.rotation.y = e * 0.015 + mx * -0.06;
                dust.position.x = mx * -0.8;
                dust.position.y = my * -0.45 + sy * 0.012;

                renderer.render(scene, camera);
            }
            loop();

            window.addEventListener('resize', function () {
                var w = hero.clientWidth || 1, h = hero.clientHeight || 1;
                camera.aspect = w / h;
                camera.updateProjectionMatrix();
                renderer.setSize(w, h, false);
            });
        }
    }

    /* ================================================================
       7C. SCROLL PROGRESS — thin champagne bar
       ================================================================ */
    function initScrollProgress() {
        var bar = qs('.lx-scroll-progress');
        if (!bar) return;
        if (reduced) { bar.style.display = 'none'; return; }
        var ticking = false;
        function update() {
            ticking = false;
            var doc = document.documentElement;
            var max = (doc.scrollHeight - window.innerHeight) || 1;
            var p = Math.min(1, Math.max(0, (window.scrollY || doc.scrollTop || 0) / max));
            bar.style.transform = 'scaleX(' + p.toFixed(4) + ')';
        }
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(update); }
        }, { passive: true });
        update();
    }

    /* ================================================================
       8. THREE.JS — "The Fashlab Experience" boutique scene
       Lazy-loads three.min.js; graceful CSS fallback if WebGL fails.
       ================================================================ */
    function initExperience() {
        var canvas = qs('.lx-exp-canvas');
        if (!canvas || reduced) return;
        if (navigator.deviceMemory && navigator.deviceMemory < 2) return;

        var fallback = qs('.lx-exp-fallback');
        var section = canvas.parentElement;

        var loaded = false;
        function boot() {
            if (loaded) return;
            loaded = true;
            loadThreeLib().then(function (THREE) { build(THREE); }).catch(function () { /* keep fallback */ });
        }

        function build(THREE) {
            var W = section.clientWidth, H = section.clientHeight;
            var renderer;
            try {
                renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: !isMobile() });
            } catch (e) { return; }
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.6));
            renderer.setSize(W, H, false);

            var scene = new THREE.Scene();
            var camera = new THREE.PerspectiveCamera(42, W / H, 0.1, 100);
            camera.position.set(0, 0, 9);

            /* --- champagne lighting --- */
            scene.add(new THREE.AmbientLight(0xf9eef1, 0.85));
            var key = new THREE.DirectionalLight(0xebccd3, 1.05);
            key.position.set(4, 6, 8);
            scene.add(key);
            var rim = new THREE.DirectionalLight(0x882038, 0.55);
            rim.position.set(-6, -3, 4);
            scene.add(rim);

            var group = new THREE.Group();
            scene.add(group);

            /* --- flowing silk ribbon (custom tube) --- */
            var ribbonPts = [];
            for (var i = 0; i <= 60; i++) {
                var t = (i / 60) * Math.PI * 4;
                ribbonPts.push(new THREE.Vector3(
                    -6.5 + (i / 60) * 13,
                    Math.sin(t) * 1.15,
                    Math.cos(t * 0.7) * 1.3
                ));
            }
            var curve = new THREE.CatmullRomCurve3(ribbonPts);
            var tubeGeo = new THREE.TubeGeometry(curve, 180, 0.16, 10, false);
            var tubeMat = new THREE.MeshStandardMaterial({
                color: 0x882038, metalness: 0.32, roughness: 0.42,
                transparent: true, opacity: 0.9
            });
            var ribbon = new THREE.Mesh(tubeGeo, tubeMat);
            group.add(ribbon);

            var ribbon2 = new THREE.Mesh(
                new THREE.TubeGeometry(curve, 180, 0.07, 8, false),
                new THREE.MeshStandardMaterial({ color: 0xefd5db, metalness: 0.2, roughness: 0.5, transparent: true, opacity: 0.75 })
            );
            ribbon2.position.y = -1.6;
            ribbon2.rotation.z = 0.18;
            group.add(ribbon2);

            /* --- floating product "cards" (rounded planes) --- */
            var cards = [];
            var cardMat = new THREE.MeshStandardMaterial({
                color: 0xfffdf9, metalness: 0.05, roughness: 0.65,
                transparent: true, opacity: 0.96
            });
            var edgeMat = new THREE.MeshStandardMaterial({ color: 0x882038, metalness: 0.5, roughness: 0.4 });
            for (var c = 0; c < 5; c++) {
                var cw = 1.15, ch = 1.55;
                var geo = new THREE.BoxGeometry(cw, ch, 0.05);
                var mesh = new THREE.Mesh(geo, cardMat);
                var edge = new THREE.Mesh(new THREE.BoxGeometry(cw + 0.06, ch + 0.06, 0.02), edgeMat);
                edge.position.z = -0.03;
                var wrap = new THREE.Group();
                wrap.add(edge); wrap.add(mesh);
                var ang = (c / 5) * Math.PI * 2;
                wrap.position.set(Math.cos(ang) * 3.4, Math.sin(ang) * 1.7, Math.sin(ang * 1.3) * 1.6);
                wrap.rotation.y = -ang * 0.45;
                wrap.userData = { ang: ang, speed: 0.12 + c * 0.015, base: wrap.position.clone() };
                group.add(wrap);
                cards.push(wrap);
            }

            /* --- hanger silhouette (line torus + bar) --- */
            var hanger = new THREE.Group();
            var hook = new THREE.Mesh(
                new THREE.TorusGeometry(0.3, 0.035, 8, 40, Math.PI * 1.4),
                new THREE.MeshStandardMaterial({ color: 0x882038, metalness: 0.7, roughness: 0.3 })
            );
            hook.position.y = 0.62;
            hanger.add(hook);
            var barGeo = new THREE.CylinderGeometry(0.028, 0.028, 2.4, 8);
            var barMat = new THREE.MeshStandardMaterial({ color: 0x882038, metalness: 0.6, roughness: 0.35 });
            var bar1 = new THREE.Mesh(barGeo, barMat);
            bar1.rotation.z = Math.PI / 2.6; bar1.position.set(-0.56, 0.1, 0);
            var bar2 = new THREE.Mesh(barGeo, barMat);
            bar2.rotation.z = -Math.PI / 2.6; bar2.position.set(0.56, 0.1, 0);
            var top = new THREE.Mesh(new THREE.CylinderGeometry(0.028, 0.028, 1.15, 8), barMat);
            top.position.y = 0.36;
            hanger.add(bar1); hanger.add(bar2); hanger.add(top);
            hanger.position.set(-4.6, 1.4, -1.5);
            hanger.scale.setScalar(0.9);
            group.add(hanger);

            /* --- perfume-like abstract object --- */
            var bottle = new THREE.Group();
            var body = new THREE.Mesh(
                new THREE.CylinderGeometry(0.42, 0.46, 1.0, 24),
                new THREE.MeshStandardMaterial({ color: 0xf6eaed, metalness: 0.1, roughness: 0.25, transparent: true, opacity: 0.9 })
            );
            var neck = new THREE.Mesh(
                new THREE.CylinderGeometry(0.14, 0.16, 0.3, 16),
                new THREE.MeshStandardMaterial({ color: 0x882038, metalness: 0.75, roughness: 0.28 })
            );
            neck.position.y = 0.65;
            bottle.add(body); bottle.add(neck);
            bottle.position.set(4.6, -1.5, -1.2);
            group.add(bottle);

            /* --- soft particle field --- */
            var pCount = isMobile() ? 60 : 160;
            var pGeo = new THREE.BufferGeometry();
            var pos = new Float32Array(pCount * 3);
            for (var p = 0; p < pCount; p++) {
                pos[p * 3] = (Math.random() - 0.5) * 16;
                pos[p * 3 + 1] = (Math.random() - 0.5) * 8;
                pos[p * 3 + 2] = (Math.random() - 0.5) * 8;
            }
            pGeo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
            var points = new THREE.Points(pGeo, new THREE.PointsMaterial({
                color: 0x882038, size: 0.05, transparent: true, opacity: 0.7, sizeAttenuation: true
            }));
            scene.add(points);

            if (fallback) fallback.style.display = 'none';

            /* --- interaction + loop --- */
            var mx = 0, my = 0, tmx = 0, tmy = 0;
            section.addEventListener('mousemove', function (e) {
                var r = section.getBoundingClientRect();
                tmx = ((e.clientX - r.left) / r.width - 0.5) * 2;
                tmy = ((e.clientY - r.top) / r.height - 0.5) * 2;
            }, { passive: true });

            var running = true;
            if ('IntersectionObserver' in window) {
                new IntersectionObserver(function (en) { running = en[0].isIntersecting; }, { threshold: 0 }).observe(section);
            }

            var clock = new THREE.Clock();
            function loop() {
                requestAnimationFrame(loop);
                if (!running) return;
                var e = clock.getElapsedTime();
                mx += (tmx - mx) * 0.045;
                my += (tmy - my) * 0.045;

                group.rotation.y = e * 0.08 + mx * 0.35;
                group.rotation.x = Math.sin(e * 0.22) * 0.06 + my * 0.18;

                ribbon.rotation.x = Math.sin(e * 0.35) * 0.12;
                ribbon2.rotation.x = Math.cos(e * 0.3) * 0.14;

                for (var k = 0; k < cards.length; k++) {
                    var d = cards[k].userData;
                    var a = d.ang + e * d.speed;
                    cards[k].position.x = Math.cos(a) * 3.4;
                    cards[k].position.y = Math.sin(a) * 1.7 + Math.sin(e * 0.7 + k) * 0.18;
                    cards[k].position.z = Math.sin(a * 1.3) * 1.6;
                    cards[k].rotation.y = -a * 0.45 + mx * 0.3;
                    cards[k].rotation.x = Math.sin(e * 0.6 + k) * 0.08;
                }

                hanger.rotation.z = Math.sin(e * 0.5) * 0.1;
                hanger.position.y = 1.4 + Math.sin(e * 0.8) * 0.14;
                bottle.rotation.y = e * 0.4;
                bottle.position.y = -1.5 + Math.cos(e * 0.9) * 0.16;

                points.rotation.y = e * 0.03;

                renderer.render(scene, camera);
            }
            loop();

            window.addEventListener('resize', function () {
                var w = section.clientWidth, h = section.clientHeight;
                camera.aspect = w / h;
                camera.updateProjectionMatrix();
                renderer.setSize(w, h, false);
            });
        }

        // Boot only when the section approaches the viewport
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (en) {
                if (en[0].isIntersecting) { io.disconnect(); boot(); }
            }, { rootMargin: '300px' });
            io.observe(section);
        } else {
            boot();
        }
    }

    /* ================================================================
       9. SCROLL REVEALS (.lx-reveal)
       ================================================================ */
    function initReveals() {
        var targets = qsa('.lx-reveal');
        if (!targets.length) return;
        if (reduced || !('IntersectionObserver' in window)) {
            targets.forEach(function (el) { el.classList.add('is-visible'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                en.target.classList.add('is-visible');
                io.unobserve(en.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
        targets.forEach(function (el) { io.observe(el); });
    }

    /* ================================================================
       10. MAGNETIC BUTTONS + CURSOR GLOW (desktop)
       ================================================================ */
    function initMagnet() {
        if (reduced) return;
        if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

        qsa('.lx-btn').forEach(function (btn) {
            btn.addEventListener('mousemove', function (e) {
                var r = btn.getBoundingClientRect();
                var x = (e.clientX - r.left - r.width / 2) * 0.16;
                var y = (e.clientY - r.top - r.height / 2) * 0.3;
                btn.style.transform = 'translate(' + x.toFixed(1) + 'px,' + (y - 2).toFixed(1) + 'px)';
            }, { passive: true });
            btn.addEventListener('mouseleave', function () { btn.style.transform = ''; });
        });

        var glow = document.createElement('div');
        glow.className = 'lx-cursor';
        document.body.appendChild(glow);
        var shown = false;
        document.addEventListener('mousemove', function (e) {
            if (!shown) { glow.classList.add('is-on'); shown = true; }
            glow.style.left = e.clientX + 'px';
            glow.style.top = e.clientY + 'px';
            var t = e.target;
            var hot = t && t.closest && t.closest('a, button, .product-card, input, select');
            glow.classList.toggle('is-hot', !!hot);
        }, { passive: true });
        document.addEventListener('mouseleave', function () { glow.classList.remove('is-on'); shown = false; });
    }

    /* ================================================================
       11. PARALLAX on editorial images
       ================================================================ */
    function initParallax() {
        if (reduced) return;
        var imgs = qsa('[data-parallax]');
        if (!imgs.length) return;
        var ticking = false;
        function apply() {
            var vh = window.innerHeight;
            imgs.forEach(function (el) {
                var r = el.getBoundingClientRect();
                if (r.bottom < -80 || r.top > vh + 80) return;
                var progress = (r.top + r.height / 2 - vh / 2) / vh; // -0.5..0.5-ish
                var amount = parseFloat(el.getAttribute('data-parallax')) || 26;
                el.style.transform = 'translate3d(0,' + (-progress * amount).toFixed(1) + 'px,0) scale(1.06)';
            });
            ticking = false;
        }
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(apply); }
        }, { passive: true });
        apply();
    }

    /* ================================================================
       12. PRODUCT IMAGE ZOOM + FULLSCREEN LIGHTBOX
       ================================================================ */
    function initProductGallery() {
        var container = qs('#productImageContainer');
        var mainImg = qs('#mainProductImage');
        if (!container || !mainImg) return;

        var thumbs = qsa('.product-thumb');

        /* hover zoom with cursor-following origin (desktop) */
        if (window.matchMedia && window.matchMedia('(hover: hover)').matches) {
            container.addEventListener('mouseenter', function () {
                if (!reduced) container.classList.add('is-zooming');
            });
            container.addEventListener('mouseleave', function () {
                container.classList.remove('is-zooming');
                mainImg.style.transformOrigin = 'center';
            });
            container.addEventListener('mousemove', function (e) {
                var r = container.getBoundingClientRect();
                var x = ((e.clientX - r.left) / r.width) * 100;
                var y = ((e.clientY - r.top) / r.height) * 100;
                mainImg.style.transformOrigin = x.toFixed(1) + '% ' + y.toFixed(1) + '%';
            }, { passive: true });
        }

        /* fullscreen lightbox */
        var urls = thumbs.map(function (t) {
            var img = t.querySelector('img');
            return img ? { src: img.getAttribute('data-image') || img.src, alt: img.alt } : null;
        }).filter(Boolean);
        if (!urls.length) urls = [{ src: mainImg.src, alt: mainImg.alt }];

        var lb = document.createElement('div');
        lb.className = 'lx-lightbox';
        lb.hidden = true;
        lb.setAttribute('role', 'dialog');
        lb.setAttribute('aria-modal', 'true');
        lb.setAttribute('aria-label', 'Product image gallery');
        var lbImg = document.createElement('img');
        lbImg.src = urls[0].src;
        lbImg.alt = urls[0].alt;
        var lbClose = document.createElement('button');
        lbClose.type = 'button';
        lbClose.className = 'lx-lightbox-close';
        lbClose.setAttribute('aria-label', 'Close gallery');
        lbClose.innerHTML = '&times;';
        var lbThumbs = document.createElement('div');
        lbThumbs.className = 'lx-lightbox-thumbs';
        urls.forEach(function (u, idx) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = idx === 0 ? 'active' : '';
            b.setAttribute('aria-label', 'View image ' + (idx + 1));
            var im = document.createElement('img');
            im.src = u.src;
            im.alt = '';
            b.appendChild(im);
            b.addEventListener('click', function () {
                lbImg.src = u.src;
                lbImg.alt = u.alt;
                qsa('button', lbThumbs).forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
            });
            lbThumbs.appendChild(b);
        });
        lb.appendChild(lbClose);
        lb.appendChild(lbImg);
        lb.appendChild(lbThumbs);
        document.body.appendChild(lb);

        var current = 0;
        function openLb(idx) {
            current = idx || 0;
            lbImg.src = urls[current].src;
            lbImg.alt = urls[current].alt;
            qsa('button', lbThumbs).forEach(function (x, i) { x.classList.toggle('active', i === current); });
            lb.hidden = false;
            requestAnimationFrame(function () { lb.classList.add('is-open'); });
            document.body.style.overflow = 'hidden';
        }
        function closeLb() {
            lb.classList.remove('is-open');
            document.body.style.overflow = '';
            setTimeout(function () { lb.hidden = true; }, 260);
        }

        container.addEventListener('click', function () { openLb(0); });
        lbClose.addEventListener('click', closeLb);
        lb.addEventListener('click', function (e) { if (e.target === lb) closeLb(); });
        document.addEventListener('keydown', function (e) {
            if (lb.hidden) return;
            if (e.key === 'Escape') closeLb();
            if (e.key === 'ArrowRight') { current = (current + 1) % urls.length; qsa('button', lbThumbs)[current].click(); }
            if (e.key === 'ArrowLeft') { current = (current - 1 + urls.length) % urls.length; qsa('button', lbThumbs)[current].click(); }
        });
    }

    /* ================================================================
       13. WHATSAPP ORDER — dynamic message (never auto-sends)
       ================================================================ */
    function initWhatsAppOrder() {
        var btn = qs('[data-wa-order]');
        if (!btn) return;
        var num = (window.TC_SETTINGS && TC_SETTINGS.whatsappNumber) || '';

        function update() {
            var name = (window.TC_PRODUCT && TC_PRODUCT.name) || '';
            var priceEl = qs('.product-price .current-price');
            var price = priceEl ? priceEl.textContent.trim() : '';
            var qtyEl = qs('#quantity');
            var qty = qtyEl ? qtyEl.textContent.trim() : '1';
            var size = qs('.size-option.active');
            var color = qs('#selectedColor');
            var lines = ['Hi ' + ((window.TC_SETTINGS && TC_SETTINGS.storeName) || 'Fashlab Studio') + ', I would like to order:',
                '', 'Product: ' + name];
            if (size) lines.push('Size: ' + size.textContent.trim());
            if (color && color.textContent.trim()) lines.push('Color: ' + color.textContent.trim());
            lines.push('Quantity: ' + qty);
            if (price) lines.push('Price: ' + price);
            lines.push('', 'Is it available?');
            var url = 'https://wa.me/' + num + '?text=' + encodeURIComponent(lines.join('\n'));
            btn.setAttribute('href', url);
        }

        update();
        qsa('.size-option, .color-option, .qty-btn').forEach(function (el) {
            el.addEventListener('click', function () { setTimeout(update, 0); });
        });
        var qtyEl = qs('#quantity');
        if (qtyEl && window.MutationObserver) {
            new MutationObserver(update).observe(qtyEl, { childList: true, characterData: true, subtree: true });
        }
    }

    /* ================================================================
       13b. SIZE GUIDE modal (real admin size-chart data)
       ================================================================ */
    function initSizeGuide() {
        var modal = qs('#size-guide');
        if (!modal) return;
        var openLink = qs('[data-size-guide]');

        function show(e) {
            if (e) e.preventDefault();
            modal.hidden = false;
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            var close = qs('[data-size-guide-close]', modal);
            if (close) close.focus();
        }
        function hide() {
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
            setTimeout(function () { modal.hidden = true; }, 260);
            if (location.hash === '#size-guide') {
                history.replaceState(null, document.title, location.pathname + location.search);
            }
        }

        if (openLink) openLink.addEventListener('click', show);
        var closeBtn = qs('[data-size-guide-close]', modal);
        if (closeBtn) closeBtn.addEventListener('click', hide);
        modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) hide(); });
        if (location.hash === '#size-guide') show();
    }

    /* ================================================================
       13c. SHARE PRODUCT (Web Share API, clipboard fallback, no auto-send)
       ================================================================ */
    function initShare() {
        var btn = qs('[data-share-product]');
        if (!btn) return;
        var label = qs('span', btn);
        var original = label ? label.textContent : '';

        function copied() {
            if (!label) return;
            label.textContent = 'LINK COPIED';
            setTimeout(function () { label.textContent = original; }, 2000);
        }
        function fallbackCopy() {
            var ta = document.createElement('textarea');
            ta.value = location.href;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); copied(); } catch (err) { /* clipboard unavailable */ }
            document.body.removeChild(ta);
        }

        btn.addEventListener('click', function () {
            if (navigator.share) {
                navigator.share({ title: document.title, url: location.href }).catch(function () { /* user cancelled */ });
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(location.href).then(copied).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        });
    }

    /* ================================================================
       14. MOBILE STICKY BUY BAR (product page)
       ================================================================ */
    function initMobileBar() {
        var addBtn = qs('.product-add-cart');
        if (!addBtn) return;

        var bar = document.createElement('div');
        bar.className = 'lx-mobile-bar';
        var price = qs('.product-price .current-price');
        var out = addBtn.disabled;
        bar.innerHTML =
            '<div class="mb-price"><strong>' + (price ? price.textContent.trim() : '') + '</strong>' +
            '<span>' + (out ? 'Out of stock' : 'Free exchange · COD') + '</span></div>' +
            '<button type="button" class="mb-add" ' + (out ? 'disabled' : '') + '>' +
            (out ? 'Sold Out' : 'Add to Bag') + '</button>';
        document.body.appendChild(bar);
        document.body.classList.add('has-lx-bar');

        bar.querySelector('.mb-add').addEventListener('click', function () { addBtn.click(); });

        var trigger = qs('.product-information');
        if (!trigger || !('IntersectionObserver' in window)) return;
        new IntersectionObserver(function (en) {
            bar.classList.toggle('is-on', !en[0].isIntersecting && en[0].boundingClientRect.top < 0);
        }, { threshold: 0 }).observe(trigger);
    }

    /* ================================================================
       15. SMOOTH ANCHOR for hero cue
       ================================================================ */
    function initCue() {
        var cue = qs('.lx-hero-cue');
        if (!cue) return;
        cue.addEventListener('click', function (e) {
            var target = qs(cue.getAttribute('href'));
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
        });
    }

    /* ================================================================
       16. PRODUCT IMAGE skeleton cleanup
       ================================================================ */
    function initImageFlags() {
        qsa('.product-card').forEach(function (card) {
            var img = qs('.product-thumb img', card);
            if (!img) { card.classList.add('loaded'); return; }
            if (img.complete) card.classList.add('loaded');
            else img.addEventListener('load', function () { card.classList.add('loaded'); },
                { once: true });
            img.addEventListener('error', function () { card.classList.add('loaded'); },
                { once: true });
        });
    }

    /* ================================================================ */
    function init() {
        initAnnouncement();
        initParticles();
        initFabric();
        initHeroTilt();
        initCardTilt();
        initSearch();
        initLottie();
        initHeroScene();
        initScrollProgress();
        initExperience();
        initReveals();
        initMagnet();
        initParallax();
        initProductGallery();
        initWhatsAppOrder();
        initSizeGuide();
        initShare();
        initMobileBar();
        initCue();
        initImageFlags();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
