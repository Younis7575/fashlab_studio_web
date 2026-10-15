<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$order = $_SESSION['last_order'] ?? null;

$page_title       = 'Order Confirmed';
$meta_description = 'Your order has been placed successfully.';
$active_nav       = 'shop.php';

require __DIR__ . '/includes/storefront-header.php';
?>

<section class="section-tight">
    <div class="container">
        <div class="confirmation-shell">
            <div class="confirmation-card">
                <?php if (!$order): ?>
                    <span class="kicker">NO ORDER FOUND</span>
                    <h1>No order found.</h1>
                    <p class="lead">Head to the shop and place a new order.</p>
                    <div class="cta-row">
                        <a href="<?= url('/shop.php') ?>" class="btn btn-solid">Continue shopping</a>
                        <a href="<?= url('/index.php') ?>" class="btn">Back home</a>
                    </div>
                <?php else: ?>
                    <span class="kicker">ORDER CONFIRMED</span>
                    <h1>Your order has been placed.</h1>
                    <p class="lead">Thank you for shopping with <?= e(setting('store_name')) ?>. Your order details are below.</p>

                    <div class="confirmation-box">
                        <div class="summary-row"><span>Order Number</span><strong><?= e($order['order_number']) ?></strong></div>
                        <div class="summary-row"><span>Customer</span><strong><?= e($order['customer_name']) ?></strong></div>
                        <div class="summary-row"><span>Phone</span><strong><?= e($order['phone']) ?></strong></div>
                        <div class="summary-row"><span>Payment</span><strong><?= e($order['payment_method']) ?></strong></div>
                        <?php foreach ($order['items'] as $item): ?>
                            <div class="summary-row"><span><?= e($item['name']) ?> × <?= (int) $item['qty'] ?></span><strong><?= money((float) $item['price'] * (int) $item['qty']) ?></strong></div>
                        <?php endforeach; ?>
                        <?php if ((float) $order['tax'] > 0): ?>
                            <div class="summary-row"><span>Tax (rate set in Settings)</span><strong>+<?= money((float) $order['tax']) ?></strong></div>
                        <?php endif; ?>
                        <div class="summary-row total"><span>Total</span><strong><?= money((float) $order['total']) ?></strong></div>
                    </div>

                    <div class="cta-row">
                        <a href="<?= url('/shop.php') ?>" class="btn btn-solid">Continue shopping</a>
                        <a href="<?= url('/index.php') ?>" class="btn">Back home</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>