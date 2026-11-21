<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$order      = null;
$timeline   = [];
$orderItems = [];
$error      = '';
$prefill    = trim((string) ($_GET['order'] ?? ''));

// My Orders page deep-links here with ?order=TC-... — auto-run the lookup
// using the phone/email stored alongside the order on this device.
$autoContact = trim((string) ($_GET['contact'] ?? ''));
if ($prefill !== '' && $autoContact !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $order = tc_find_order($prefill, $autoContact);
    if (!$order) {
        $error = 'No order found for this number on the server yet — it may still be syncing. Try again shortly.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);

    $orderNumber = post('order_number', 40);
    $contact     = post('contact', 190);

    if ($orderNumber === '' || $contact === '') {
        $error = 'Please enter your order number and the email or phone used at checkout.';
    } else {
        $order = tc_find_order($orderNumber, $contact);
        if (!$order) {
            $error = 'No order found. Please check your order number and email/phone and try again.';
        }
    }
}

if ($order) {
    $orderId   = (int) $order['id'];
    $timeline  = tc_order_timeline($orderId);
    $flow      = tc_order_flow();
    $current   = (int) array_search($order['order_status'], $flow, true);

    $stmt = db()->prepare(
        'SELECT oi.*, p.slug AS product_slug
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?'
    );
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll();
}

$page_title       = 'Track Order';
$meta_description = 'Track your ' . setting('store_name') . ' order status in real time.';
$active_nav       = 'track-order.php';

require __DIR__ . '/includes/storefront-header.php';
?>

<section class="cart-hero">
    <div class="container">
        <span class="eyebrow">ORDER STATUS</span>
        <h1>Track Your Order</h1>
        <p>Enter your order number and the email or phone you used at checkout.</p>
    </div>
</section>

<main class="track-section">
    <div class="container track-container">

        <?php if (!$order): ?>
            <form method="post" action="<?= e(url('/track-order.php')) ?>" class="track-form" novalidate>
                <?= csrf_field() ?>
                <?php if ($error !== ''): ?>
                    <div class="flash flash-error"><span><?= e($error) ?></span></div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="order_number">Order Number <span class="required">*</span></label>
                    <input type="text" id="order_number" name="order_number" value="<?= e($prefill) ?>" placeholder="e.g. TC-20260904-ABC123" required>
                </div>
                <div class="form-group">
                    <label for="contact">Email or Phone <span class="required">*</span></label>
                    <input type="text" id="contact" name="contact" placeholder="you@example.com or 03XX XXXXXXX" required>
                </div>
                <button type="submit" class="btn btn-primary track-submit">TRACK ORDER</button>
            </form>

            <div class="track-help">
                <p>Need help? <a href="<?= url('/contact.php') ?>">Contact our support team</a> or
                <a href="<?= e(whatsapp_url('Hi Fashlab Studio, I need help tracking my order.')) ?>">chat on WhatsApp</a>.</p>
            </div>
        <?php else: ?>

            <div class="track-card">
                <div class="track-card-head">
                    <div>
                        <span class="eyebrow">ORDER <?= e($order['order_number']) ?></span>
                        <h2><?= e($order['customer_name']) ?></h2>
                    </div>
                    <span class="badge <?= e(order_status_color((string) $order['order_status'])) ?>"><?= e(ucfirst((string) $order['order_status'])) ?></span>
                </div>

                <?php if ($current >= 0): ?>
                <ol class="track-timeline">
                    <?php foreach ($flow as $i => $status): ?>
                        <?php
                        $isDone = $i <= $current;
                        $isNow  = $i === $current;
                        $lastEntry = null;
                        foreach ($timeline as $entry) {
                            if ($entry['status'] === $status) { $lastEntry = $entry; }
                        }
                        ?>
                        <li class="<?= $isNow ? 'now' : ($isDone ? 'done' : '') ?>">
                            <span class="timeline-dot"><?= $isDone ? '<i class="fa-solid fa-check"></i>' : '' ?></span>
                            <div class="timeline-label">
                                <strong><?= e(ucwords(str_replace('_', ' ', $status))) ?></strong>
                                <?php if ($lastEntry): ?>
                                    <time><?= e(format_datetime($lastEntry['created_at'])) ?></time>
                                    <?php if (!empty($lastEntry['note'])): ?><p><?= e($lastEntry['note']) ?></p><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>

                <?php if (!empty($order['tracking_number'])): ?>
                    <div class="track-tracking">
                        <i class="fa-solid fa-truck-fast"></i>
                        <span>Tracking number: <strong><?= e($order['tracking_number']) ?></strong></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['delivery_estimate'])): ?>
                    <div class="track-tracking">
                        <i class="fa-regular fa-clock"></i>
                        <span>Estimated delivery: <strong><?= e($order['delivery_estimate']) ?></strong></span>
                    </div>
                <?php endif; ?>

                <div class="track-items">
                    <?php foreach ($orderItems as $item): ?>
                        <div class="track-item">
                            <div>
                                <strong><?= e($item['product_name']) ?></strong>
                                <?php if (!empty($item['variant_label'])): ?><p><?= e($item['variant_label']) ?></p><?php endif; ?>
                                <?= tc_personalisation_html($item['personalisation'] ?? null) ?>
                            </div>
                            <span><?= (int) $item['quantity'] ?> × <?= money($item['price']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="track-summary">
                    <div><span>Subtotal</span><strong><?= money((float) $order['subtotal']) ?></strong></div>
                    <div><span>Shipping</span><strong><?= (float) $order['shipping_fee'] > 0 ? money((float) $order['shipping_fee']) : 'FREE' ?></strong></div>
                    <?php if ((float) $order['discount'] > 0): ?>
                        <div><span>Discount</span><strong>-<?= money((float) $order['discount']) ?></strong></div>
                    <?php endif; ?>
                    <div class="track-total"><span>Total</span><strong><?= money((float) $order['total']) ?></strong></div>
                    <div><span>Payment</span><strong><?= e(ucwords(str_replace('_', ' ', (string) $order['payment_method']))) ?> (<?= e(ucfirst((string) $order['payment_status'])) ?>)</strong></div>
                </div>

                <a href="<?= e(url('/track-order.php')) ?>" class="text-link">Track another order <i class="fa-solid fa-arrow-right"></i></a>
            </div>

        <?php endif; ?>

    </div>
</main>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>