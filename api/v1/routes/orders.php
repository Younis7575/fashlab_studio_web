<?php
/**
 * Checkout and order tracking.
 *
 * Guest checkout only, exactly like the website: the shopper fills in the form
 * and orders. There is no order history, because there is no account to hang
 * one on — an order is looked up by its number plus the email or phone that
 * was used on it, which is what track-order.php does on the site.
 *
 * Orders are created through includes/order-service.php, the same function the
 * website checkout calls, so stock, coupons and the inventory log behave
 * identically on both channels.
 */

declare(strict_types=1);

// Route files are includes, never entry points: without the front controller
// route() does not exist. .htaccess blocks them too; this is the fallback for
// servers where it is not applied.
if (!defined('TC_API')) {
    http_response_code(404);
    exit;
}

/**
 * GET /checkout — everything the checkout screen needs before it can submit:
 * live totals, delivery options and the payment methods actually accepted.
 */
route('GET', '/checkout', static function (): void {
    $shippingMethods = [];

    if (tc_table_exists('shipping_methods')) {
        $rows = db()->query(
            'SELECT * FROM shipping_methods WHERE status = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
        $shippingMethods = array_map(static fn (array $m): array => [
            'code'           => (string) $m['code'],
            'name'           => (string) $m['name'],
            'description'    => $m['description'] ?? null,
            'fee'            => round((float) $m['fee'], 2),
            'formatted_fee'  => (float) $m['fee'] > 0 ? money((float) $m['fee']) : 'Free',
            'free_above'     => $m['free_above'] !== null ? round((float) $m['free_above'], 2) : null,
            'estimated_days' => $m['estimated_days'] ?? null,
        ], $rows);
    }

    if (!$shippingMethods) {
        $shippingMethods = array_values(array_map(static fn (array $o): array => [
            'code'           => $o['code'],
            'name'           => $o['label'],
            'description'    => $o['note'],
            'fee'            => $o['fee'],
            'formatted_fee'  => $o['fee'] > 0 ? money($o['fee']) : 'Included',
            'free_above'     => null,
            'estimated_days' => $o['note'],
        ], order_delivery_options()));
    }

    api_ok([
        'cart'             => api_cart_payload(),
        'delivery_options' => $shippingMethods,
        // Only COD is offered: no payment gateway is connected, and taking a
        // card payment the store cannot settle would strand the order.
        'payment_methods'  => [
            ['code' => 'cod', 'name' => 'Cash on Delivery', 'enabled' => true],
        ],
        'free_shipping_threshold' => (float) setting('free_shipping_threshold', '8000'),
    ]);
});

/**
 * POST /orders — place the order from the current cart.
 * Body: { name, email, phone, address, city, postal_code?, notes?,
 *         delivery?, is_gift?, gift_message? }
 *
 * Prices, shipping and the coupon are recalculated server-side; anything the
 * client sends about money is ignored.
 *
 * Keep the order_number from the response — with no account, that number plus
 * the email or phone is the only way back to the order.
 */
route('POST', '/orders', static function (): void {
    $result = place_order([
        'name'         => api_input('name'),
        'email'        => api_input('email'),
        'phone'        => api_input('phone'),
        'address'      => api_input('address'),
        'city'         => api_input('city'),
        'postal_code'  => api_input('postal_code'),
        'notes'        => api_input('notes'),
        'delivery'     => api_input('delivery', 'standard'),
        'country'      => api_input('country', 'Pakistan'),
        'is_gift'      => api_input_bool('is_gift'),
        'gift_message' => api_input('gift_message'),
    ]);

    if (!$result['ok']) {
        api_invalid($result['errors'], $result['errors']['cart'] ?? 'Please check the highlighted fields.');
    }

    $order = order_by_number((string) $result['order_number']);

    api_ok([
        'order' => $order ? api_order_detail($order) : null,
        'cart'  => api_cart_payload(),
    ], 'Order ' . $result['order_number'] . ' placed. Thank you!', 201);
});

/**
 * POST /orders/track — Body: { order_number, contact }
 *
 * `contact` must be the email or phone number used on the order. Requiring it
 * is what stops an order number on its own from exposing someone's address.
 */
route('POST', '/orders/track', static function (): void {
    $orderNumber = api_input('order_number');
    $contact     = api_input('contact');

    if ($orderNumber === '' || $contact === '') {
        api_invalid([
            'order_number' => $orderNumber === '' ? 'Please enter your order number.' : null,
            'contact'      => $contact === '' ? 'Please enter the email or phone used on the order.' : null,
        ]);
    }

    $order = tc_find_order($orderNumber, $contact);
    if (!$order) {
        api_fail('We could not find that order. Please check the details and try again.', 404);
    }

    api_ok(['order' => api_order_detail($order)]);
});

/**
 * GET /orders/{order_number}?contact=... — the same lookup as a GET, for
 * deep links and for polling an order the app just placed.
 */
route('GET', '/orders/{order_number}', static function (array $params): void {
    $contact = api_query('contact');

    if ($contact === '') {
        api_invalid(['contact' => 'Pass the email or phone used on the order as ?contact=.']);
    }

    $order = tc_find_order((string) $params['order_number'], $contact);
    if (!$order) {
        api_fail('We could not find that order. Please check the details and try again.', 404);
    }

    api_ok(['order' => api_order_detail($order)]);
});
