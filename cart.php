<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$items  = cart_items();
$totals = cart_totals();

$page_title       = 'Shopping Cart';
$meta_description = 'Review your selected pieces before checkout.';
$active_nav       = 'shop.php';

require __DIR__ . '/includes/storefront-header.php';
?>

<!-- HERO -->
<section class="cart-hero">
    <div class="container">
        <span class="eyebrow">YOUR SHOPPING BAG</span>
        <h1>Shopping Cart</h1>
        <p>Review your selected pieces before checkout.</p>
    </div>
</section>

<!-- CART -->
<main class="cart-section">
    <div class="container">

        <div class="cart-layout" id="cartLayout">

            <div>

                <!-- Cart Items -->
                <div class="cart-items-wrapper" id="cartItemsWrapper" <?= $items ? '' : 'style="display:none"' ?>>

                    <div class="cart-table-header">
                        <span>Product</span>
                        <span>Price</span>
                        <span>Quantity</span>
                        <span>Total</span>
                        <span></span>
                    </div>

                    <div id="cartItems">
                        <?php foreach ($items as $item): ?>
                            <div class="cart-item" data-cart-key="<?= e($item['key']) ?>">
                                <div class="cart-product">
                                    <div class="cart-product-image">
                                        <img src="<?= e($item['image']) ?>" alt="<?= e($item['name']) ?>">
                                    </div>
                                    <div class="cart-product-info">
                                        <h3><?= e($item['name']) ?></h3>
                                        <?= tc_personalisation_html($item['personalisation'] ?? null) ?>
                                        <?php if ($item['variant_label']): ?>
                                            <p><?= e($item['variant_label']) ?></p>
                                        <?php endif; ?>
                                        <a href="<?= e(product_url($item['slug'])) ?>">View Details</a>
                                        <?php if (!$item['available']): ?>
                                            <p class="stock-status out"><i class="fa-solid fa-circle-xmark"></i> Currently unavailable</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="cart-price"><?= money($item['unit_price']) ?></div>
                                <div class="cart-quantity">
                                    <button type="button" class="qty-btn" data-key="<?= e($item['key']) ?>" data-qty="-1" aria-label="Decrease quantity">−</button>
                                    <input type="number" class="qty-input" value="<?= (int) $item['qty'] ?>" min="1"
                                           max="<?= max(1, (int) $item['in_stock']) ?>" data-key="<?= e($item['key']) ?>">
                                    <button type="button" class="qty-btn" data-key="<?= e($item['key']) ?>" data-qty="1" aria-label="Increase quantity">+</button>
                                </div>
                                <div class="cart-total"><?= money($item['line_total']) ?></div>
                                <button type="button" class="cart-remove" data-key="<?= e($item['key']) ?>" aria-label="Remove item">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="cart-actions">
                        <a href="<?= url('/shop.php') ?>" class="continue-shopping">
                            <i class="fa-solid fa-arrow-left"></i> Continue Shopping
                        </a>
                        <?php if ($items): ?>
                        <button type="button" class="clear-cart" id="clearCart">Clear Cart</button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Empty Cart -->
                <div class="empty-cart" id="emptyCart" <?= $items ? 'style="display:none"' : '' ?>>
                    <div class="empty-cart-icon"><div class="lx-lottie" data-lottie="bag-empty"><i class="fa-solid fa-bag-shopping"></i></div></div>
                    <h2>Your Cart Is Empty</h2>
                    <p>Looks like you haven't added anything to your shopping bag yet. Explore our latest collections and find something you love.</p>
                    <a href="<?= url('/shop.php') ?>">START SHOPPING</a>
                </div>

            </div>

            <!-- Order Summary -->
            <aside class="order-summary" id="orderSummary">
                <h2>Order Summary</h2>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <strong id="subtotal"><?= money($totals['subtotal']) ?></strong>
                </div>
                <div class="summary-row">
                    <span>Shipping</span>
                    <strong id="shipping"><?= $totals['shipping'] > 0 ? money($totals['shipping']) : 'FREE' ?></strong>
                </div>

                <?php if ($totals['has_unavailable']): ?>
                <p class="summary-note"><i class="fa-solid fa-circle-exclamation"></i> Some items are unavailable and are not included in the total.</p>
                <?php endif; ?>

                <div class="summary-divider"></div>
                <div class="summary-total">
                    <span>Total</span>
                    <span id="grandTotal"><?= money($totals['total']) ?></span>
                </div>

                <a href="<?= url('/checkout.php') ?>" class="checkout-btn" id="checkoutBtn" <?= $items ? '' : 'style="pointer-events:none;opacity:.5"' ?>>
                    PROCEED TO CHECKOUT
                </a>

                <div class="secure-note"><i class="fa-solid fa-lock"></i> Secure &amp; encrypted checkout</div>
                <div class="shipping-note">
                    <i class="fa-solid fa-truck"></i>
                    <span>Free shipping on orders above <?= money(setting('free_shipping_threshold', '8000')) ?>.</span>
                </div>
            </aside>

        </div>
    </div>
</main>

<script>
    window.TC_CART = {
        lineCount: <?= (int) $totals['line_count'] ?>,
        pieceCount: <?= (int) $totals['piece_count'] ?>
    };
</script>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>