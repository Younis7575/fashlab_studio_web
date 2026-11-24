/* Fashlab hero: three.js floating gold particles + gift carousel. */
(function () {
    'use strict';
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Carousel ---------- */
    var car = document.querySelector('[data-fx-carousel]');
    if (car) {
        var slides = car.querySelectorAll('.fx-hc-slide');
        var dots = car.querySelectorAll('.fx-hc-dots button');
        var idx = 0, timer = null;
        var go = function (n) {
            idx = (n + slides.length) % slides.length;
            slides.forEach(function (s, i) {
                var on = i === idx;
                s.classList.toggle('is-active', on);
                s.setAttribute('aria-hidden', on ? 'false' : 'true');
                if (on) s.removeAttribute('tabindex'); else s.setAttribute('tabindex', '-1');
            });
            dots.forEach(function (d, i) { d.classList.toggle('is-active', i === idx); });
        };
        var start = function () { if (!reduce && slides.length > 1) { clearInterval(timer); timer = setInterval(function () { go(idx + 1); }, 3800); } };
        car.querySelector('.fx-hc-prev').addEventListener('click', function () { go(idx - 1); start(); });
        car.querySelector('.fx-hc-next').addEventListener('click', function () { go(idx + 1); start(); });
        dots.forEach(function (d, i) { d.addEventListener('click', function () { go(i); start(); }); });
        car.addEventListener('mouseenter', function () { clearInterval(timer); });
        car.addEventListener('mouseleave', start);
        var sx = null;
        car.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
        car.addEventListener('touchend', function (e) {
            if (sx === null) return;
            var dx = e.changedTouches[0].clientX - sx; sx = null;
            if (Math.abs(dx) > 40) { go(idx + (dx < 0 ? 1 : -1)); start(); }
        });
        start();
    }

    /* ---------- three.js background ---------- */
    var canvas = document.querySelector('[data-fx-hero3d]');
    if (!canvas || !window.THREE || reduce) return;
    var T = window.THREE, host = canvas.parentElement;
    var renderer;
    try { renderer = new T.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true }); } catch (e) { return; }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    var scene = new T.Scene();
    var camera = new T.PerspectiveCamera(50, 1, 0.1, 100);
    camera.position.z = 14;

    /* Soft round sprite texture */
    var c = document.createElement('canvas'); c.width = c.height = 64;
    var g = c.getContext('2d'), grd = g.createRadialGradient(32, 32, 0, 32, 32, 32);
    grd.addColorStop(0, 'rgba(255,255,255,1)'); grd.addColorStop(0.4, 'rgba(255,255,255,.6)'); grd.addColorStop(1, 'rgba(255,255,255,0)');
    g.fillStyle = grd; g.fillRect(0, 0, 64, 64);
    var tex = new T.CanvasTexture(c);

    var makeLayer = function (count, color, size, spread) {
        var pos = new Float32Array(count * 3);
        for (var i = 0; i < count; i++) {
            pos[i * 3] = (Math.random() - 0.5) * spread;
            pos[i * 3 + 1] = (Math.random() - 0.5) * spread * 0.55;
            pos[i * 3 + 2] = (Math.random() - 0.5) * 8;
        }
        var geo = new T.BufferGeometry();
        geo.setAttribute('position', new T.BufferAttribute(pos, 3));
        var mat = new T.PointsMaterial({ color: color, size: size, map: tex, transparent: true, depthWrite: false, opacity: 0.85 });
        var pts = new T.Points(geo, mat); scene.add(pts); return pts;
    };
    var gold = makeLayer(160, 0xc9a24b, 0.22, 34);
    var wine = makeLayer(70, 0x8a1c3c, 0.3, 30);

    /* Floating gift-like shapes: wire cubes (boxes) and torus rings */
    var shapes = [];
    var lineMat = new T.LineBasicMaterial({ color: 0xc9a24b, transparent: true, opacity: 0.35 });
    var addShape = function (geo, x, y, z, s) {
        var m = new T.LineSegments(new T.EdgesGeometry(geo), lineMat);
        m.position.set(x, y, z); m.scale.setScalar(s);
        m.userData = { vy: 0.002 + Math.random() * 0.003, r: Math.random() * 0.01 + 0.004, y0: y, ph: Math.random() * 6 };
        scene.add(m); shapes.push(m);
    };
    addShape(new T.BoxGeometry(1, 1, 1), -9, 2.5, -2, 1.1);
    addShape(new T.BoxGeometry(1, 1, 1), -2, -3.2, -3, 0.8);
    addShape(new T.OctahedronGeometry(0.8), -6, -2.5, 0, 1);
    addShape(new T.TorusGeometry(0.7, 0.18, 6, 18), -12, -1, -1, 1);
    addShape(new T.IcosahedronGeometry(0.7), 1, 3.6, -4, 1);

    var mx = 0, my = 0;
    host.addEventListener('pointermove', function (e) {
        var r = host.getBoundingClientRect();
        mx = ((e.clientX - r.left) / r.width - 0.5); my = ((e.clientY - r.top) / r.height - 0.5);
    });
    var resize = function () {
        var w = host.clientWidth, h = host.clientHeight;
        renderer.setSize(w, h, false); camera.aspect = w / h; camera.updateProjectionMatrix();
    };
    window.addEventListener('resize', resize); resize();

    var visible = true;
    if ('IntersectionObserver' in window) new IntersectionObserver(function (en) { visible = en[0].isIntersecting; }).observe(host);
    var t0 = performance.now();
    (function loop() {
        requestAnimationFrame(loop);
        if (!visible || document.hidden) return;
        var t = (performance.now() - t0) / 1000;
        gold.rotation.y = t * 0.03; gold.position.y = Math.sin(t * 0.4) * 0.3;
        wine.rotation.y = -t * 0.02; wine.position.y = Math.cos(t * 0.3) * 0.4;
        shapes.forEach(function (s) {
            s.rotation.x += s.userData.r; s.rotation.y += s.userData.r * 0.8;
            s.position.y = s.userData.y0 + Math.sin(t * 0.6 + s.userData.ph) * 0.5;
        });
        camera.position.x += (mx * 2 - camera.position.x) * 0.04;
        camera.position.y += (-my * 1.2 - camera.position.y) * 0.04;
        camera.lookAt(0, 0, 0);
        renderer.render(scene, camera);
    })();
    canvas.classList.add('is-ready');
})();
