<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/**
 * My Orders (device-local).
 *
 * The checkout drops a lightweight tc_my_orders cookie on THIS device only.
 * This page lists those orders — no account, no login — and verifies each
 * against the database so the customer always sees the live server status.
 * Cookie data never grants access to anything beyond its own order numbers.
 * hzhgcjhj zb xkjvbdsjd nfmdsbkj bkjsd
 */

$myOrders = [];

if (!empty($_COOKIE['tc_my_orders'])) {
    $raw  = json_decode((string) $_COOKIE['tc_my_orders'], true);
    $list = is_array($raw) ? $raw : [];
    if (isset($list['o'], $list['c'])) { // single-order shape
        $list = [$list];
    }
    foreach ($list as $entry) {
        if (!is_array($entry) || empty($entry['o']) || empty($entry['c'])) {
            continue;
        }
        // Cap lookups so an oversized cookie can't hammer the DB.
        if (count($myOrders) >= 12) {
            break;
        }
        $order   = tc_find_order(substr((string) $entry['o'], 0, 40), substr((string) $entry['c'], 0, 190));
        $myOrders[] = [
            'number'   => (string) $entry['o'],
            'placed_at' => $order['created_at'] ?? null,
            'status'   => $order['order_status'] ?? null,
            'payment'  => $order['payment_status'] ?? null,
            'total'    => $order['total'] !== null ? (float) $order['total'] : null,
            'items'    => $order ? tc_order_items_summary((int) $order['id']) : [],
            'contact'  => (string) $entry['c'],
            'found'    => (bool) $order,
        ];
    }
    // Newest first (cookie is appended chronologically).
    $myOrders = array_reverse($myOrders);
}

$page_title       = 'My Orders';
$meta_description = 'Orders you placed on this device — stored locally, no account needed.';
$active_nav       = 'shop.php';

require __DIR__ . '/includes/storefront-header.php';
?>

<style>
    .mo-list { display: grid; gap: 16px; }
    .mo-card {
        display: flex; align-items: center; gap: 18px;
        background: var(--surface, #fff);
        border: 1px solid var(--line, #e9d6da);
        border-radius: 16px; padding: 20px 22px;
        transition: box-shadow .25s ease, transform .25s ease;
    }
    .mo-card:hover { box-shadow: 0 14px 34px rgba(36,32,32,.10); transform: translateY(-2px); }
    .mo-icon {
        flex: 0 0 48px; width: 48px; height: 48px; border-radius: 13px;
        display: grid; place-items: center; font-size: 20px;
        background: rgba(128, 16, 38, .07); color: #800c26;
    }
    .mo-main { flex: 1; min-width: 0; }
    .mo-number { font-weight: 800; letter-spacing: .4px; font-size: 15px; }
    .mo-meta { font-size: 13px; color: var(--muted, #6f675e); margin-top: 3px; }
    .mo-items { font-size: 13px; color: var(--muted, #6f675e); margin-top: 4px;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mo-side { text-align: right; display: grid; gap: 6px; justify-items: end; }
    .mo-total { font-weight: 800; font-size: 16px; color: #800c26; }
    .mo-badge {
        font-size: 10px; font-weight: 800; letter-spacing: 1.2px;
        text-transform: uppercase; padding: 4px 11px; border-radius: 999px;
    }
    .mo-badge.is-pending   { background: rgba(91, 21, 37,.13); color: #5b1525; }
    .mo-badge.is-confirmed,
    .mo-badge.is-processing{ background: rgba(29,95,168,.12); color: #1d5fa8; }
    .mo-badge.is-shipped   { background: rgba(29,95,168,.12); color: #1d5fa8; }
    .mo-badge.is-delivered { background: rgba(22,132,90,.12); color: #16845a; }
    .mo-badge.is-cancelled { background: rgba(178,34,52,.12); color: #b22234; }
    .mo-unknown            { background: rgba(111,103,94,.12); color: #6f675e; }
    .mo-empty {
        text-align: center; padding: 70px 24px;
        border: 1px dashed var(--line, #e9d6da); border-radius: 20px;
        background: var(--surface, #fff);
    }
    .mo-empty .fa-solid { font-size: 40px; color: #800c26; opacity: .5; }
    .mo-empty h2 { margin: 16px 0 6px; }
    .mo-empty p { color: var(--muted, #6f675e); max-width: 420px; margin: 0 auto 24px; }
    .mo-cta { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    @media (max-width: 640px) {
        .mo-card { flex-direction: column; align-items: flex-start; }
        .mo-side { text-align: left; justify-items: start; }
    }
</style>

<section class="section-tight">
    <div class="container">
        <div class="section-heading left" style="margin-bottom:26px;">
            <p class="section-label">SAVED ON THIS DEVICE</p>
            <h1>My Orders</h1>
            <p class="lead">No account needed — orders you place in this browser are kept right here on your device.</p>
        </div>

        <?php if (!$myOrders): ?>
            <div class="mo-empty">
                <i class="fa-solid fa-bag-shopping"></i>
                <h2>No orders yet</h2>
                <p>When you place an order, it will appear here automatically — stored privately in this browser.</p>
                <div class="mo-cta">
                    <a href="<?= url('/shop.php') ?>" class="btn btn-solid">Start shopping</a>
                    <a href="<?= url('/track-order.php') ?>" class="btn btn-outline">Track an order</a>
                </div>
            </div>
        <?php else: ?>
            <div class="mo-list">
                <?php foreach ($myOrders as $mo): ?>
                    <article class="mo-card">
                        <div class="mo-icon"><i class="fa-solid fa-receipt"></i></div>
                        <div class="mo-main">
                            <div class="mo-number"><?= e($mo['number']) ?></div>
                            <div class="mo-meta">
                                <?php if ($mo['placed_at']): ?>
                                    Placed <?= e(format_datetime($mo['placed_at'])) ?>
                                <?php else: ?>
                                    Details pending sync
                                <?php endif; ?>
                            </div>
                            <?php if ($mo['items']): ?>
                                <div class="mo-items"><?= e(implode(', ', $mo['items'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="mo-side">
                            <?php if ($mo['total'] !== null): ?>
                                <span class="mo-total"><?= money($mo['total']) ?></span>
                            <?php endif; ?>
                            <?php
                                $status = $mo['found'] ? (string) $mo['status'] : 'unknown';
                                $class  = 'mo-badge is-' . preg_replace('/[^a-z]/', '', strtolower($status));
                            ?>
                            <span class="<?= $class ?>"><?= e($mo['found'] ? $status : 'syncing…') ?></span>
                            <a class="link-arrow" href="<?= e(url('/track-order.php?order=' . urlencode($mo['number']) . '&contact=' . urlencode($mo['contact']))) ?>">
                                View &amp; track <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="mo-cta" style="margin-top:28px;">
                <a href="<?= url('/shop.php') ?>" class="btn btn-solid">Continue shopping</a>
                <a href="<?= url('/track-order.php') ?>" class="btn btn-outline">Track another order</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>
