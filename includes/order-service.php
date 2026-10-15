<?php
/**
 * Order placement — the single path that turns a cart into an order.
 *
 * The website checkout and the mobile API both call place_order(), so stock
 * decrements, coupon accounting and the inventory log can never drift apart
 * between the two channels.
 *
 * Everything is recalculated here from the database: the caller supplies only
 * the delivery details, never prices or totals.
 */

declare(strict_types=1);

/** Delivery options offered at checkout. */
function order_delivery_options(): array
{
    return [
        'standard' => [
            'code'  => 'standard',
            'label' => 'Standard delivery',
            'fee'   => 0.0, // uses the cart's shipping rule
            'note'  => setting('standard_delivery_note', '3-5 working days'),
        ],
        'express' => [
            'code'  => 'express',
            'label' => 'Express delivery',
            'fee'   => (float) setting('express_shipping_fee', '250'),
            'note'  => setting('express_delivery_note', '1-2 working days'),
        ],
    ];
}

/** Validate the delivery details. @return array<string,string> */
function order_validate(array $input): array
{
    $errors = [];

    if (trim((string) ($input['name'] ?? '')) === '')  $errors['name']    = 'Please enter your full name.';
    if (!valid_email((string) ($input['email'] ?? ''))) $errors['email']   = 'Please enter a valid email address.';
    if (!valid_phone((string) ($input['phone'] ?? ''))) $errors['phone']   = 'Please enter a valid phone number.';
    if (trim((string) ($input['city'] ?? '')) === '')  $errors['city']    = 'Please enter your city.';
    if (trim((string) ($input['address'] ?? '')) === '') $errors['address'] = 'Please enter your complete address.';

    return $errors;
}

/**
 * Place an order from the current cart.
 *
 * @param array $input name, email, phone, city, address, postal_code, notes,
 *                     delivery, country, is_gift, gift_message
 * @return array{ok: bool, errors?: array<string,string>, order_number?: string,
 *               order_id?: int, total?: float}
 */
function place_order(array $input): array
{
    $items  = cart_items();
    $totals = cart_totals();

    $errors = order_validate($input);

    if (!$items) {
        $errors['cart'] = 'Your cart is empty.';
    } elseif ($totals['has_unavailable']) {
        $errors['cart'] = 'One or more items in your cart are no longer available. Please review your cart.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $delivery = (string) ($input['delivery'] ?? 'standard');
    if (!array_key_exists($delivery, order_delivery_options())) {
        $delivery = 'standard';
    }

    $couponState    = tc_checkout_coupon((float) $totals['subtotal']);
    $couponDiscount = $couponState ? (float) $couponState['discount'] : 0.0;
    $discountTotal  = (float) $totals['discount'] + $couponDiscount;

    $deliveryFee = (float) $totals['shipping'] + order_delivery_options()[$delivery]['fee'];
    $grandTotal  = max(0.0, (float) $totals['subtotal'] + $deliveryFee - $discountTotal);

    $orderNumber = setting('order_prefix', 'TC-') . date('Ymd') . '-'
                 . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    $db = db();

    // A failure halfway through the item loop would otherwise leave an order
    // with missing lines and stock already taken.
    $db->beginTransaction();

    try {
        $db->prepare(
            'INSERT INTO orders (order_number, customer_name, customer_email, customer_phone,
                                 shipping_address, city, postal_code, notes,
                                 subtotal, shipping_fee, discount, total,
                                 payment_method, payment_status, order_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orderNumber,
            trim((string) $input['name']),
            mb_strtolower(trim((string) $input['email'])),
            trim((string) ($input['phone'] ?? '')),
            trim((string) $input['address']),
            trim((string) $input['city']),
            trim((string) ($input['postal_code'] ?? '')),
            trim((string) ($input['notes'] ?? '')),
            decimal($totals['subtotal']),
            decimal($deliveryFee),
            decimal($discountTotal),
            decimal($grandTotal),
            // Card payments are not wired to a gateway yet, so accepting one
            // would create an order that can never be settled.
            'cod',
            'pending',
            'pending',
        ]);
        $orderId = (int) $db->lastInsertId();

        /* Migration-only columns, written separately so the API and the site
           both run on the base schema too. The base schema uses
           'price_decimal' as its money type, so tax is written verbatim:
           '0.00' until the admin configures a sales-tax rate. */
        if (tc_column_exists('orders', 'coupon_code')) {
            $db->prepare(
                'UPDATE orders SET coupon_code = ?, coupon_discount = ?, tax = ?, country = ?,
                                   shipping_method = ?
                 WHERE id = ?'
            )->execute([
                $couponState['code'] ?? null,
                decimal($couponDiscount),
                decimal($tax ?? 0.0),
                (string) ($input['country'] ?? 'Pakistan'),
                $delivery,
                $orderId,
            ]);
        }
        if (!empty($input['is_gift']) && tc_column_exists('orders', 'is_gift')) {
            $db->prepare('UPDATE orders SET is_gift = 1, gift_message = ? WHERE id = ?')
               ->execute([trim((string) ($input['gift_message'] ?? '')) ?: null, $orderId]);
        }
        if (tc_column_exists('orders', 'ip_address')) {
            $db->prepare('UPDATE orders SET ip_address = ? WHERE id = ?')->execute([client_ip(), $orderId]);
        }

        /* Timeline entry — powers order tracking. */
        if (tc_table_exists('order_status_history')) {
            $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
               ->execute([$orderId, 'pending', 'Order placed']);
        }

        /* Coupon ledger. */
        if ($couponState && tc_table_exists('coupon_usages')) {
            $db->prepare('INSERT INTO coupon_usages (coupon_id, order_id, email) VALUES (?, ?, ?)')
               ->execute([
                   $couponState['id'],
                   $orderId,
                   mb_strtolower(trim((string) $input['email'])),
               ]);
            $db->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')
               ->execute([$couponState['id']]);
        }

        /* Lines + stock. */
        $itemStmt = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, variant_label, quantity, price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stockStmt        = $db->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?');
        $variantStockStmt = $db->prepare('UPDATE product_variants SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?');
        $prevStmt         = $db->prepare('SELECT stock_quantity FROM products WHERE id = ?');
        $prevVariantStmt  = $db->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');

        $logStmt = tc_table_exists('inventory_logs')
            ? $db->prepare(
                'INSERT INTO inventory_logs
                    (product_id, variant_id, change_qty, previous_qty, new_qty, reason, reference_type, reference_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
              )
            : null;

        $hasVariantColumn = tc_column_exists('order_items', 'variant_id');

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $qty       = (int) $item['qty'];

            $itemStmt->execute([
                $orderId,
                $productId,
                $item['name'],
                $item['variant_label'] ?: null,
                $qty,
                decimal($item['unit_price']),
                decimal($item['line_total']),
            ]);
            $orderItemId = (int) $db->lastInsertId();

            $prevStmt->execute([$productId]);
            $prevQty = (int) $prevStmt->fetchColumn();
            $stockStmt->execute([$qty, $productId]);

            $logStmt?->execute([
                $productId, null, -$qty, $prevQty, max(0, $prevQty - $qty),
                'Order ' . $orderNumber, 'order', $orderId,
            ]);

            if (!empty($item['variant_id'])) {
                $variantId = (int) $item['variant_id'];

                $prevVariantStmt->execute([$variantId]);
                $vPrev = (int) $prevVariantStmt->fetchColumn();
                $variantStockStmt->execute([$qty, $variantId]);

                $logStmt?->execute([
                    $productId, $variantId, -$qty, $vPrev, max(0, $vPrev - $qty),
                    'Order ' . $orderNumber, 'order', $orderId,
                ]);

                if ($hasVariantColumn) {
                    // Keyed by the row just inserted, so two lines of the same
                    // product with different variants do not overwrite each other.
                    $db->prepare('UPDATE order_items SET variant_id = ? WHERE id = ?')
                       ->execute([$variantId, $orderItemId]);
                }
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[order-service] ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['cart' => 'We could not place your order right now. Please try again.']];
    }

    unset($_SESSION['tc_coupon_code']);
    cart_clear();

    return [
        'ok'           => true,
        'order_id'     => $orderId,
        'order_number' => $orderNumber,
        'total'        => $grandTotal,
        'items'        => $items,
        'delivery_fee' => $deliveryFee,
        'discount'     => $discountTotal,
        'tax'          => $tax,
        'gross_total'  => $grandTotal,
        'subtotal'     => (float) $totals['subtotal'],
    ];
}

/** Fetch a placed order by its number (tax included in the total). */
function order_by_number(string $orderNumber): ?array
{
    $stmt = db()->prepare('SELECT *, price_decimal AS total FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([mb_strtoupper(trim($orderNumber))]);
    $row = $stmt->fetch();
    return $row ?: null;
}
