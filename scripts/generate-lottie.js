#!/usr/bin/env node
/* ============================================================================
   Generates the storefront Lottie icon set into assets/lottie/.

   Every animation is a minimal, elegant "line-draw" loop: a champagne stroke
   draws itself on (trim path), holds, then fades — 3s at 60fps, looping.
   No external dependencies and no cartoonish motion: fashion-editorial only.

   Run:  node scripts/generate-lottie.js
   ========================================================================== */
'use strict';

const fs = require('fs');
const path = require('path');

const OUT = path.resolve(__dirname, '..', 'assets', 'lottie');
const W = 120, H = 120, FPS = 60, OP = 180; // 3s loop

/* champagne / charcoal palette */
const GOLD = [0.353, 0.078, 0.141, 1];   // #5a1424
const GOLD_L = [0.498, 0.196, 0.282, 1]; // #7f3248
const INK = [0.129, 0.114, 0.102, 1];    // #211d1a

function layer(name, shapes, opts) {
    const o = opts || {};
    return {
        ddd: 0,
        ind: 1,
        ty: 4,
        nm: name,
        sr: 1,
        ks: {
            o: { a: 0, k: 100 },
            r: { a: 0, k: o.rotate || 0 },
            p: { a: 0, k: [o.px == null ? W / 2 : o.px, o.py == null ? H / 2 : o.py, 0] },
            a: { a: 0, k: [0, 0, 0] },
            s: { a: 0, k: [100, 100, 100] }
        },
        ao: 0,
        shapes: shapes,
        ip: 0,
        op: OP,
        st: 0,
        bm: 0
    };
}

/* Trim-path draw-on keyframes (stroke draws 0→100% over ~1.6s) */
function drawTrim(delay) {
    const start = delay || 0;
    return {
        ty: 'tm',
        s: {
            a: 1,
            k: [
                { i: { x: [0.65], y: [1] }, o: { x: [0.35], y: [0] }, t: start, s: [0] },
                { t: start + 55, s: [100] }
            ]
        },
        e: { a: 0, k: 100 },
        o: { a: 0, k: 0 },
        m: 1,
        nm: 'draw'
    };
}

function stroke(color, width) {
    return {
        ty: 'st',
        c: { a: 0, k: color || GOLD },
        o: { a: 0, k: 100 },
        w: { a: 0, k: width || 3.4 },
        lc: 2,
        lj: 2,
        ml: 4,
        nm: 'stroke'
    };
}

function fill(color) {
    return { ty: 'fl', c: { a: 0, k: color || GOLD_L }, o: { a: 0, k: 100 }, nm: 'fill' };
}

function xf() {
    return {
        ty: 'tr',
        p: { a: 0, k: [0, 0] },
        a: { a: 0, k: [0, 0] },
        s: { a: 0, k: [100, 100] },
        r: { a: 0, k: 0 },
        o: { a: 0, k: 100 },
        sk: { a: 0, k: 0 },
        sa: { a: 0, k: 0 },
        nm: 'transform'
    };
}

/* Path helper: builds a Lottie shape from a list of [x,y] points */
function poly(points, closed) {
    const v = points.map((p) => [p[0], p[1]]);
    const i = points.map(() => [0, 0]);
    const o = points.map(() => [0, 0]);
    return {
        ty: 'sh',
        d: 1,
        ks: { a: 0, k: { i: i, o: o, v: v, c: closed !== false } },
        nm: 'path'
    };
}

/* Bezier path helper: vertices + explicit in/out handles */
function bez(v, i, o, closed) {
    return {
        ty: 'sh',
        d: 1,
        ks: { a: 0, k: { i: i, o: o, v: v, c: !!closed } },
        nm: 'path'
    };
}

function circle(r, cx, cy) {
    const k = 0.5523 * r;
    const x = cx || 0, y = cy || 0;
    return bez(
        [[x, y - r], [x + r, y], [x, y + r], [x - r, y]],
        [[-k, 0], [0, -k], [k, 0], [0, k]],
        [[k, 0], [0, k], [-k, 0], [0, -k]],
        true
    );
}

function group(name, items) {
    return { ty: 'gr', nm: name, it: items.concat([xf()]) };
}

function doc(name, layers) {
    return {
        v: '5.7.4',
        fr: FPS,
        ip: 0,
        op: OP,
        w: W,
        h: H,
        nm: name,
        ddd: 0,
        assets: [],
        layers: layers,
        markers: []
    };
}

/* ------------------------------------------------------------------ icons */

function iconBag() {
    // shopping bag: body + handle arc
    return doc('bag', [layer('bag', [
        group('body', [
            bez(
                [[-26, -16], [26, -16], [30, 34], [-30, 34]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.6),
            drawTrim(0)
        ]),
        group('handle', [
            bez(
                [[-14, -16], [14, -16]],
                [[0, -14], [0, -14]],
                [[0, -14], [0, -14]],
                false
            ),
            stroke(GOLD_L, 3.4),
            drawTrim(18)
        ])
    ])]);
}

function iconTruck() {
    // delivery truck: cargo box + cab + wheels
    return doc('truck', [layer('truck', [
        group('box', [
            bez(
                [[-34, -14], [-2, -14], [-2, 16], [-34, 16]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('cab', [
            bez(
                [[-2, -4], [16, -4], [24, 6], [24, 16], [-2, 16]],
                [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD_L, 3.4),
            drawTrim(14)
        ]),
        group('wheels', [
            circle(6.5, -20, 22),
            stroke(GOLD, 3),
            drawTrim(30)
        ]),
        group('wheel2', [
            circle(6.5, 14, 22),
            stroke(GOLD, 3),
            drawTrim(38)
        ])
    ])]);
}

function iconExchange() {
    // easy exchange: two curved arrows
    return doc('exchange', [layer('exchange', [
        group('top', [
            bez(
                [[-28, -8], [0, -20], [28, -8]],
                [[-8, -4], [8, -4]],
                [[8, 4], [-8, 4]],
                false
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('topArrow', [
            poly([[18, -14], [28, -8], [18, -2]], false),
            stroke(GOLD, 3.4),
            drawTrim(24)
        ]),
        group('bottom', [
            bez(
                [[28, 8], [0, 20], [-28, 8]],
                [[8, 4], [-8, 4]],
                [[-8, -4], [8, -4]],
                false
            ),
            stroke(GOLD_L, 3.4),
            drawTrim(12)
        ]),
        group('bottomArrow', [
            poly([[-18, 14], [-28, 8], [-18, 2]], false),
            stroke(GOLD_L, 3.4),
            drawTrim(34)
        ])
    ])]);
}

function iconHeadset() {
    // customer support: headset band + mic
    return doc('support', [layer('support', [
        group('band', [
            bez(
                [[-24, 10], [-24, -8], [0, -24], [24, -8], [24, 10]],
                [[0, -10], [-13, -8], [13, -8], [0, 8]],
                [[0, -8], [13, 8], [-13, 8], [0, 10]],
                false
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('earL', [
            bez(
                [[-28, 6], [-20, 6], [-20, 22], [-28, 22]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.2),
            drawTrim(22)
        ]),
        group('earR', [
            bez(
                [[20, 6], [28, 6], [28, 22], [20, 22]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD_L, 3.2),
            drawTrim(30)
        ]),
        group('mic', [
            bez(
                [[24, 22], [24, 30], [8, 30]],
                [[0, 5], [-9, 0]],
                [[0, -5], [9, 0]],
                false
            ),
            stroke(GOLD_L, 3.2),
            drawTrim(42)
        ])
    ])]);
}

function iconSparkle() {
    // four-point sparkle (new collection / editorial accent)
    return doc('sparkle', [layer('sparkle', [
        group('star', [
            bez(
                [[0, -34], [7, -7], [34, 0], [7, 7], [0, 34], [-7, 7], [-34, 0], [-7, -7]],
                [[-2.5, 9], [-9, 2.5], [-2.5, -9], [9, -2.5], [2.5, 9], [9, 2.5], [2.5, -9], [-9, -2.5]],
                [[2.5, -9], [9, -2.5], [2.5, 9], [-9, 2.5], [-2.5, -9], [-9, -2.5], [-2.5, 9], [9, 2.5]],
                true
            ),
            stroke(GOLD, 3),
            drawTrim(0)
        ]),
        group('dot', [circle(4, 26, -26), fill(GOLD_L), drawTrim(30)])
    ])]);
}

function iconHeart() {
    // wishlist heart
    return doc('heart', [layer('heart', [
        group('heart', [
            bez(
                [[0, 30], [-30, 2], [-30, -20], [-13, -30], [0, -16], [13, -30], [30, -20], [30, 2]],
                [[-8, -10], [0, -14], [-12, -6], [-7, 7], [7, -7], [12, -6], [0, 14], [8, 10]],
                [[8, 10], [0, 14], [-12, -6], [-7, 7], [7, -7], [12, -6], [0, -14], [-8, -10]],
                true
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('pulse', [circle(7, 0, 0), fill(GOLD_L), drawTrim(48)])
    ])]);
}

function iconShield() {
    // secure checkout shield with check
    return doc('secure', [layer('secure', [
        group('shield', [
            bez(
                [[0, -32], [26, -20], [26, 4], [0, 32], [-26, 4], [-26, -20]],
                [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('check', [
            poly([[-10, 0], [-3, 8], [11, -8]], false),
            stroke(GOLD_L, 3.6),
            drawTrim(34)
        ])
    ])]);
}

function iconGem() {
    // quality fabrics: faceted gem
    return doc('quality', [layer('quality', [
        group('gem', [
            bez(
                [[0, -30], [26, -10], [0, 32], [-26, -10]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('facet', [
            poly([[-26, -10], [26, -10]], false),
            stroke(GOLD_L, 2.8),
            drawTrim(26)
        ]),
        group('facet2', [
            poly([[-13, -20], [0, 32], [13, -20]], false),
            stroke(GOLD_L, 2.4),
            drawTrim(38)
        ])
    ])]);
}

function iconScissors() {
    // thoughtful design: abstract needle + thread line
    return doc('design', [layer('design', [
        group('line', [
            bez(
                [[-30, 22], [-8, -4], [10, 14], [32, -18]],
                [[9, -8], [-7, 8], [-7, -8]],
                [[-9, 8], [7, -8], [7, 8]],
                false
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('eye', [
            bez(
                [[30, -26], [38, -18], [30, -10], [22, -18]],
                [[0, -4.5], [4.5, 0], [0, 4.5], [-4.5, 0]],
                [[0, 4.5], [-4.5, 0], [0, -4.5], [4.5, 0]],
                true
            ),
            stroke(GOLD_L, 3),
            drawTrim(34)
        ]),
        group('cross', [
            poly([[-32, -14], [-18, -14]], false),
            stroke(GOLD_L, 3),
            drawTrim(48)
        ])
    ])]);
}

function iconCod() {
    // cash on delivery: banknote + coin
    return doc('cod', [layer('cod', [
        group('note', [
            bez(
                [[-32, -14], [20, -14], [20, 14], [-32, 14]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('coin', [
            circle(11, 24, 0),
            stroke(GOLD_L, 3.4),
            drawTrim(24)
        ]),
        group('rupee', [
            poly([[-16, -6], [-6, -6], [-6, 6], [-16, 6]], false),
            stroke(GOLD_L, 2.6),
            drawTrim(40)
        ])
    ])]);
}

function iconChat() {
    // WhatsApp / chat bubble
    return doc('chat', [layer('chat', [
        group('bubble', [
            bez(
                [[-28, -20], [28, -20], [28, 12], [6, 12], [-4, 24], [-8, 12], [-28, 12]],
                [[0, 0], [0, 0], [0, 0], [-6, 6], [3, -3], [-4, -2], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [6, -6], [-3, 3], [4, 2], [0, 0]],
                true
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('dots', [
            circle(3, -13, -4), fill(GOLD_L), drawTrim(40)
        ]),
        group('dot2', [
            circle(3, 0, -4), fill(GOLD_L), drawTrim(46)
        ]),
        group('dot3', [
            circle(3, 13, -4), fill(GOLD_L), drawTrim(52)
        ])
    ])]);
}

function iconDelivery() {
    // free delivery: location pin over parcel
    return doc('delivery', [layer('delivery', [
        group('pin', [
            bez(
                [[0, -30], [16, -14], [16, 2], [0, 20], [-16, 2], [-16, -14]],
                [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.4),
            drawTrim(0)
        ]),
        group('core', [circle(6, 0, -7), stroke(GOLD_L, 3), drawTrim(30)]),
        group('parcel', [
            bez(
                [[-18, 24], [18, 24], [18, 38], [-18, 38]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD_L, 3),
            drawTrim(42)
        ])
    ])]);
}

function iconBagEmpty() {
    // empty-state bag with sparkle
    return doc('bag-empty', [layer('bag-empty', [
        group('body', [
            bez(
                [[-26, -12], [26, -12], [30, 32], [-30, 32]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                [[0, 0], [0, 0], [0, 0], [0, 0]],
                true
            ),
            stroke(GOLD, 3.6),
            drawTrim(0)
        ]),
        group('handle', [
            bez([[-13, -12], [13, -12]], [[0, -13], [0, -13]], [[0, -13], [0, -13]], false),
            stroke(GOLD_L, 3.4),
            drawTrim(20)
        ]),
        group('shine', [
            poly([[30, -30], [36, -36]], false),
            stroke(GOLD_L, 3),
            drawTrim(44)
        ]),
        group('shine2', [
            poly([[38, -18], [46, -18]], false),
            stroke(GOLD_L, 3),
            drawTrim(52)
        ])
    ])]);
}

/* ------------------------------------------------------------------- write */

const icons = {
    'bag': iconBag,
    'truck': iconTruck,
    'exchange': iconExchange,
    'support': iconHeadset,
    'sparkle': iconSparkle,
    'heart': iconHeart,
    'secure': iconShield,
    'quality': iconGem,
    'design': iconScissors,
    'cod': iconCod,
    'chat': iconChat,
    'delivery': iconDelivery,
    'bag-empty': iconBagEmpty
};

fs.mkdirSync(OUT, { recursive: true });
let n = 0;
for (const [name, make] of Object.entries(icons)) {
    const file = path.join(OUT, name + '.json');
    fs.writeFileSync(file, JSON.stringify(make()));
    n++;
    console.log('wrote', path.relative(process.cwd(), file));
}
console.log(n + ' lottie icons generated');
