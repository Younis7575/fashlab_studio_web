<?php
/**
 * Cart drawer partial — rendered into the slide-out drawer.
 * Expects $items and $totals from cart_items() / cart_totals().
 */
declare(strict_types=1);

$items      = $items ?? cart_items();
$totals     = $totals ?? cart_totals();
$freeAbove  = (float) setting('free_shipping_threshold', '8000');
$remaining  = max(0.0, $freeAbove - $totals['subtotal']);
?>

<?php if (!$items): ?>
    <div class="drawer-empty">
        <div class="lx-lottie" data-lottie="bag-empty"><i class="fa-regular fa-bag-shopping"></i></div>
        <h3>Your bag is waiting for something beautiful</h3>
        <a href="<?= url('/shop.php') ?>" class="btn btn-primary">SHOP NOW</a>
    </div>
<?php else: ?>

    <?php if ($freeAbove > 0 && $remaining > 0): ?>
        <div class="drawer-progress">
            <p>You are <strong><?= e(money($remaining)) ?></strong> away from <strong>FREE SHIPPING</strong>.</p>
            <div class="drawer-progress-bar"><span style="width: <?= min(100, (int) round($totals['subtotal'] / $freeAbove * 100)) ?>%"></span></div>
        </div>
    <?php elseif ($freeAbove > 0): ?>
        <div class="drawer-progress unlocked">
            <p><i class="fa-solid fa-truck-fast"></i> You've unlocked <strong>FREE SHIPPING</strong>!</p>
        </div>
    <?php endif; ?>

    <div class="drawer-items">
        <?php foreach ($items as $item): ?>
            <div class="drawer-item" data-cart-key="<?= e($item['key']) ?>">
                <a href="<?= e(product_url($item['slug'])) ?>" class="drawer-item-img">
                    <img src="<?= e($item['image']) ?>" alt="<?= e($item['name']) ?>">
                </a>
                <div class="drawer-item-info">
                    <a href="<?= e(product_url($item['slug'])) ?>"><strong><?= e($item['name']) ?></strong></a>
                    <?php if ($item['variant_label']): ?><p><?= e($item['variant_label']) ?></p><?php endif; ?>
                    <?= tc_personalisation_html($item['personalisation'] ?? null) ?>
                    <p class="drawer-item-price"><?= money($item['unit_price']) ?> × <?= (int) $item['qty'] ?>
                        <?php if (!$item['available']): ?><span class="drawer-unavailable">— unavailable</span><?php endif; ?>
                    </p>
                    <div class="drawer-item-qty">
                        <button type="button" class="qty-btn" data-key="<?= e($item['key']) ?>" data-qty="-1" aria-label="Decrease quantity">−</button>
                        <span><?= (int) $item['qty'] ?></span>
                        <button type="button" class="qty-btn" data-key="<?= e($item['key']) ?>" data-qty="1" aria-label="Increase quantity">+</button>
                    </div>
                </div>
                <button type="button" class="drawer-item-remove" data-key="<?= e($item['key']) ?>" aria-label="Remove item">&times;</button>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="drawer-summary">
        <div class="drawer-subtotal"><span>Subtotal</span><strong><?= money($totals['subtotal']) ?></strong></div>
        <?php if ($totals['discount'] > 0): ?>
            <div class="drawer-subtotal"><span>Discount</span><strong>-<?= money($totals['discount']) ?></strong></div>
        <?php endif; ?>
        <?php if ($totals['has_unavailable']): ?>
            <p class="drawer-note"><i class="fa-solid fa-circle-exclamation"></i> Some items are unavailable and are not included in the total.</p>
        <?php endif; ?>
        <a href="<?= url('/checkout.php') ?>" class="btn btn-primary drawer-checkout">CHECKOUT</a>
        <a href="<?= url('/cart.php') ?>" class="drawer-view-cart">View full cart</a>
    </div>
<?php endif; ?>