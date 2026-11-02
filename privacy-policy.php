<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/**
 * Privacy Policy.
 *
 * Covers the website AND the Fashlab Studio Android app (Play Console
 * requirement). The store collects only what a cash-on-delivery order
 * needs — no accounts, no analytics SDKs, no ad networks.
 */

$effectiveDate = 'September 20, 2026';

$storeName    = setting('store_name', 'Fashlab Studio');
$storeEmail   = setting('store_email', 'support@fashlabstudio.mytechrcm.com');
$storePhone   = setting('store_phone', setting('whatsapp_number', '+92 334 232 2324'));
$storeAddress = setting('store_address', 'Islamabad, Pakistan');

$page_title       = 'Privacy Policy';
$meta_description = "How {$storeName} collects, uses and protects your data on our website and mobile app — plain language, no surprises.";
$active_nav       = 'about.php';

require __DIR__ . '/includes/storefront-header.php';
?>

<style>
    .legal-wrap { max-width: 860px; margin: 0 auto; }
    .legal-meta {
        display: flex; flex-wrap: wrap; gap: 10px 26px; align-items: center;
        margin: 26px 0 10px; padding: 14px 20px;
        background: var(--surface, #fff);
        border: 1px solid var(--line, #e9d6da);
        border-radius: 14px;
        font-size: 13.5px; color: var(--muted, #6b5f58);
    }
    .legal-meta strong { color: var(--ink, #261a1e); font-weight: 700; }
    .legal-meta .dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: var(--gold, #5a1424); flex: 0 0 auto;
    }
    .legal-card {
        background: var(--surface, #fff);
        border: 1px solid var(--line, #e9d6da);
        border-radius: 18px; padding: clamp(22px, 4vw, 40px);
        margin-top: 18px;
    }
    .legal-card section + section { margin-top: 34px; padding-top: 30px; border-top: 1px solid var(--line, #e9d6da); }
    .legal-card h2 {
        font-size: 19px; font-weight: 800; letter-spacing: .3px;
        color: var(--maroon, #6b2233); margin: 0 0 12px;
        display: flex; align-items: baseline; gap: 12px;
    }
    .legal-card h2 .num {
        font-size: 11px; font-weight: 800; letter-spacing: 1.5px;
        color: var(--gold, #5a1424);
        border: 1px solid color-mix(in srgb, var(--gold, #5a1424) 40%, transparent);
        border-radius: 8px; padding: 3px 8px; flex: 0 0 auto;
    }
    .legal-card p, .legal-card li { font-size: 14.5px; line-height: 1.75; color: var(--body, #4a403b); }
    .legal-card p + p { margin-top: 10px; }
    .legal-card ul, .legal-card ol { margin: 10px 0 0; padding-left: 22px; }
    .legal-card li + li { margin-top: 6px; }
    .legal-card a { color: var(--maroon, #6b2233); font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
    .legal-contact {
        display: grid; gap: 14px; margin-top: 16px;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .legal-contact a {
        display: flex; align-items: center; gap: 12px;
        padding: 14px 16px; border-radius: 14px;
        border: 1px solid var(--line, #e9d6da);
        background: var(--ivory, #faf3f5);
        text-decoration: none !important;
        font-size: 14px; color: var(--ink, #261a1e) !important;
        transition: box-shadow .2s ease, transform .2s ease;
    }
    .legal-contact a:hover { box-shadow: 0 8px 20px rgba(38, 26, 30, .08); transform: translateY(-1px); }
    .legal-contact i { color: var(--maroon, #6b2233); font-size: 16px; }
</style>

<section class="page-hero">
    <div class="container">
        <p class="section-label">YOUR TRUST MATTERS</p>
        <h1>Privacy Policy</h1>
        <p class="lead">Plain language, no legalese traps. This policy covers both our
            website and the Fashlab Studio Android app.</p>
    </div>
</section>

<section class="section-padding">
    <div class="container legal-wrap">

        <div class="legal-meta">
            <span><strong>Effective:</strong> <?= e($effectiveDate) ?></span>
            <span class="dot" aria-hidden="true"></span>
            <span><strong>Applies to:</strong> Website &amp; Android App</span>
            <span class="dot" aria-hidden="true"></span>
            <span><strong>Data collected:</strong> Only what an order needs</span>
        </div>

        <div class="legal-card">

            <section>
                <h2><span class="num">01</span> Who we are</h2>
                <p>
                    <strong><?= e($storeName) ?></strong> operates the online store at
                    <a href="<?= e(url('/index.php')) ?>"><?= e(rtrim((string) setting('base_url', ''), '/')) ?></a>
                    and the Fashlab Studio mobile app. You can reach us any time using the
                    contact details at the end of this policy.
                </p>
            </section>

            <section>
                <h2><span class="num">02</span> What we collect</h2>
                <p>We do not require an account. When you place a cash-on-delivery order we collect:</p>
                <ul>
                    <li><strong>Order details</strong> — your name, phone number, email address (optional), delivery address and city, and any note you add to the order.</li>
                    <li><strong>Order contents</strong> — the items, sizes and quantities you purchase, and the total amount.</li>
                    <li><strong>Device session token</strong> — a random, anonymous identifier that keeps your shopping cart and wishlist working between visits. It contains no personal information.</li>
                    <li><strong>On this device only</strong> — the website saves a small cookie and the app saves your placed orders on your phone, so you can see them under “My Orders” without logging in.</li>
                </ul>
                <p>We do <strong>not</strong> collect: payment card data (we are cash-on-delivery), your contacts, your photos, your location, or any advertising identifier.</p>
            </section>

            <section>
                <h2><span class="num">03</span> How we use your data</h2>
                <ul>
                    <li>To process, pack and deliver your order.</li>
                    <li>To contact you about your order (confirmation, delivery updates) by phone, WhatsApp, SMS or email.</li>
                    <li>To show your past orders to you on this device.</li>
                    <li>To handle returns, exchanges and customer-support requests.</li>
                    <li>To keep the store secure and prevent fraudulent orders.</li>
                </ul>
                <p>We never sell your data, and we do not use it for third-party advertising.</p>
            </section>

            <section>
                <h2><span class="num">04</span> App permissions</h2>
                <p>The Fashlab Studio app asks for a single permission:</p>
                <ul>
                    <li><strong>Internet (android.permission.INTERNET)</strong> — required to load products and place orders. That is all.</li>
                </ul>
                <p>No camera, microphone, location, storage or contacts access is requested.</p>
            </section>

            <section>
                <h2><span class="num">05</span> Payments</h2>
                <p>All orders are <strong>Cash on Delivery</strong>. You pay the courier when the parcel
                    arrives. We never ask for bank details, card numbers or online payment credentials.</p>
            </section>

            <section>
                <h2><span class="num">06</span> Sharing your data</h2>
                <p>Your order information is shared only with the people who need it to complete your order:</p>
                <ul>
                    <li>Our own team that packs and dispatches orders.</li>
                    <li>Our delivery partners, who receive your name, address and phone number to deliver the parcel.</li>
                    <li>Our hosting provider, which stores the shop database.</li>
                </ul>
                <p>We may disclose information if the law requires it.</p>
            </section>

            <section>
                <h2><span class="num">07</span> Cookies &amp; local storage</h2>
                <p>The website uses a small number of essential cookies: a session cookie for your cart
                    and a cookie remembering your recent orders for the “My Orders” page. These are not
                    used for tracking or advertising. The app stores your placed orders locally on your
                    device; uninstalling the app or clearing its data removes them permanently.</p>
            </section>

            <section>
                <h2><span class="num">08</span> How long we keep data</h2>
                <p>Order records are kept for as long as needed for accounting, warranty, returns and
                    legal requirements. Device session tokens are removed with the cart they belong to.
                    Locally stored orders on your phone stay until you delete them.</p>
            </section>

            <section>
                <h2><span class="num">09</span> How we protect data</h2>
                <p>The site runs over HTTPS. Access to order data is limited to staff who need it.
                    No system is perfectly secure, but we apply reasonable technical and organisational
                    measures and keep collected data to the minimum.</p>
            </section>

            <section>
                <h2><span class="num">10</span> Children</h2>
                <p>Our store is not directed at children under 13 and we do not knowingly collect
                    their personal data. If you believe a child has provided us information, contact
                    us and we will delete it.</p>
            </section>

            <section>
                <h2><span class="num">11</span> Your choices</h2>
                <ul>
                    <li>Browse and even order without any account — there is none.</li>
                    <li>Ask us for a copy of your order data, or ask us to delete it, at any time.</li>
                    <li>Clear the website cookie from your browser, or clear the app’s data on your phone.</li>
                </ul>
            </section>

            <section>
                <h2><span class="num">12</span> Changes to this policy</h2>
                <p>If we change this policy we will update the effective date above. Material changes
                    will also be announced on this page before they take effect.</p>
            </section>

            <section>
                <h2><span class="num">13</span> Contact us</h2>
                <p>Questions, data requests or complaints — we are one message away:</p>
                <div class="legal-contact">
                    <a href="mailto:<?= e($storeEmail) ?>"><i class="fa-solid fa-envelope"></i><?= e($storeEmail) ?></a>
                    <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $storePhone)) ?>"><i class="fa-solid fa-phone"></i><?= e($storePhone) ?></a>
                    <a href="<?= e(url('/contact.php')) ?>"><i class="fa-solid fa-comment-dots"></i>Contact page</a>
                    <span><i class="fa-solid fa-location-dot"></i><?= e($storeAddress) ?></span>
                </div>
            </section>

        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>
