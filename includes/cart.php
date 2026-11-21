<?php
/**
 * Session cart.
 * Items are stored minimally (product_id, variant_id, qty); prices/names are
 * always hydrated from the database when rendered, so admin price edits and
 * stock changes are reflected immediately. Stock is validated on every add,
 * update and at checkout.
 */

declare(strict_types=1);

function cart_get(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_key(int $productId, ?int $variantId, array $personalisation = []): string
{
    $key = $productId . ':' . (int) $variantId;
    // Each personalised box is its own line (different photo / name / message).
    return $personalisation
        ? $key . ':' . substr(md5((string) json_encode($personalisation)), 0, 10)
        : $key;
}

/** Total number of pieces in the cart (sum of quantities). */
function cart_count(): int
{
    $count = 0;
    foreach (cart_get() as $item) {
        $count += (int) ($item['qty'] ?? 0);
    }
    return $count;
}

/**
 * Add a product (optionally a variant) to the cart.
 * @return array{ok: bool, message: string, count: int}
 */
function cart_add(int $productId, ?int $variantId, int $qty, array $personalisation = []): array
{
    if ($qty < 1) {
        return ['ok' => false, 'message' => 'Quantity must be at least 1.', 'count' => cart_count()];
    }

    $stmt = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product || (int) $product['status'] !== 1) {
        return ['ok' => false, 'message' => 'This product is not available.', 'count' => cart_count()];
    }

    $variant = null;
    if ($variantId) {
        $vstmt = db()->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND status = 1 LIMIT 1');
        $vstmt->execute([$variantId, $productId]);
        $variant = $vstmt->fetch();
        if (!$variant) {
            return ['ok' => false, 'message' => 'The selected option is not available.', 'count' => cart_count()];
        }
    }

    $available = $variant ? (int) $variant['stock_quantity'] : (int) $product['stock_quantity'];

    if ($available < 1) {
        return ['ok' => false, 'message' => 'Sorry, this item is currently out of stock.', 'count' => cart_count()];
    }

    $cart = cart_get();
    $key  = cart_key($productId, $variantId, $personalisation);
    $line = ['product_id' => $productId, 'variant_id' => $variantId];
    if ($personalisation) {
        $line['personalisation'] = $personalisation;
    }
    $newQty = (int) ($cart[$key]['qty'] ?? 0) + $qty;

    if ($newQty > $available) {
        $newQty = $available;
        $cart[$key] = $line + ['qty' => $newQty];
        $_SESSION['cart'] = $cart;
        return [
            'ok' => false,
            'message' => 'Only ' . $available . ' in stock — cart updated to ' . $available . '.',
            'count' => cart_count(),
        ];
    }

    $cart[$key] = $line + ['qty' => $newQty];
    $_SESSION['cart'] = $cart;

    return ['ok' => true, 'message' => 'Added to cart.', 'count' => cart_count()];
}

function cart_update(string $key, int $qty): array
{
    $cart = cart_get();

    if (!isset($cart[$key])) {
        return ['ok' => false, 'message' => 'Item not found in cart.', 'count' => cart_count()];
    }

    if ($qty < 1) {
        unset($cart[$key]);
        $_SESSION['cart'] = $cart;
        return ['ok' => true, 'message' => 'Item removed.', 'count' => cart_count()];
    }

    $item = $cart[$key];

    // Re-validate against current stock.
    $stmt = db()->prepare('SELECT stock_quantity, status FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$item['product_id']]);
    $product = $stmt->fetch();

    $max = 0;
    if ($product && (int) $product['status'] === 1) {
        if (!empty($item['variant_id'])) {
            $v = db()->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? AND product_id = ? AND status = 1');
            $v->execute([$item['variant_id'], $item['product_id']]);
            $row = $v->fetch();
            $max = $row ? (int) $row['stock_quantity'] : 0;
        } else {
            $max = (int) $product['stock_quantity'];
        }
    }

    if ($max < 1) {
        unset($cart[$key]);
        $_SESSION['cart'] = $cart;
        return ['ok' => false, 'message' => 'This item is out of stock and was removed.', 'count' => cart_count()];
    }

    $item['qty'] = min($qty, $max);
    $cart[$key] = $item;
    $_SESSION['cart'] = $cart;

    return ['ok' => true, 'message' => 'Cart updated.', 'count' => cart_count()];
}

function cart_remove(string $key): array
{
    $cart = cart_get();
    unset($cart[$key]);
    $_SESSION['cart'] = $cart;
    return ['ok' => true, 'message' => 'Item removed.', 'count' => cart_count()];
}

function cart_clear(): void
{
    $_SESSION['cart'] = [];
}

/**
 * Hydrated cart lines, each:
 * ['key', 'product_id', 'variant_id', 'qty', 'product' => row|null,
 *  'name', 'image', 'unit_price', 'line_total', 'available' => bool,
 *  'variant_label']
 */
function cart_items(): array
{
    $cart = cart_get();
    $items = [];

    foreach ($cart as $key => $item) {
        $productId = (int) $item['product_id'];
        $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;

        $stmt = db()->prepare(
            'SELECT p.*, pi.image AS primary_image
             FROM products p
             LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            continue;
        }

        $variant = null;
        $variantLabel = '';
        $adjustment = 0.0;
        if ($variantId) {
            $v = db()->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1');
            $v->execute([$variantId, $productId]);
            $variant = $v->fetch();
            if ($variant) {
                $variantLabel = trim($variant['variant_name'] . ' — ' . $variant['variant_value'], ' —');
                $adjustment = (float) $variant['price_adjustment'];
            }
        }

        $available = (int) $product['status'] === 1
            && ($variant ? (int) ($variant['stock_quantity'] ?? 0) > 0 : (int) $product['stock_quantity'] > 0);

        $unitPrice = effective_price($product, $adjustment);
        $qty = (int) $item['qty'];

        $items[] = [
            'key'           => $key,
            'product_id'    => $productId,
            'variant_id'    => $variantId,
            'qty'           => $qty,
            'product'       => $product,
            'name'          => $product['name'],
            'slug'          => $product['slug'],
            'image'         => image_url($product['primary_image'] ?? ''),
            'unit_price'    => $unitPrice,
            'line_total'    => $unitPrice * $qty,
            'available'     => $available,
            'variant_label' => $variantLabel,
            'personalisation' => tc_personalisation_values($item['personalisation'] ?? null),
            'in_stock'      => $variant ? (int) $variant['stock_quantity'] : (int) $product['stock_quantity'],
        ];
    }

    return $items;
}

/**
 * Cart totals based on live database prices.
 * ['subtotal', 'shipping', 'discount', 'total', 'line_count', 'piece_count', 'has_unavailable']
 */
function cart_totals(): array
{
    $items = cart_items();
    $subtotal = 0.0;
    $hasUnavailable = false;

    foreach ($items as $item) {
        if (!$item['available']) {
            $hasUnavailable = true;
            continue;
        }
        $subtotal += $item['line_total'];
    }

    $shippingFee = (float) setting('shipping_fee', '250');
    $freeAbove  = (float) setting('free_shipping_threshold', '8000');
    $shipping   = ($subtotal > 0 && $subtotal < $freeAbove) ? $shippingFee : 0.0;

    $discount = 0.0;

    return [
        'subtotal'       => $subtotal,
        'shipping'       => $shipping,
        'discount'       => $discount,
        'total'          => max(0.0, $subtotal + $shipping - $discount),
        'line_count'     => count($items),
        'piece_count'    => array_sum(array_column($items, 'qty')),
        'has_unavailable'=> $hasUnavailable,
    ];
}