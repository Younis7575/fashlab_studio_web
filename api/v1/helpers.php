<?php
/**
 * API helpers: request parsing, the response envelope, and the transformers
 * that turn database rows into the JSON shapes the app consumes.
 *
 * Every response is
 *   { "success": bool, "message": string|null, "data": mixed, "errors": {} }
 * so the Flutter client can decode one wrapper type for all endpoints.
 */

declare(strict_types=1);

/* ============================================================================
   REQUEST
   ============================================================================ */

function api_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

function api_bearer_token(): string
{
    $header = api_header('Authorization');

    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $header = trim((string) $v);
                break;
            }
        }
    }
    // Some shared-hosting PHP-CGI setups strip Authorization; .htaccess copies
    // it here as a fallback.
    if ($header === '') {
        $header = trim((string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return api_header('X-Api-Token');
}

/** Decoded request body: JSON when sent as JSON, otherwise the form payload. */
function api_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }

    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_contains($type, 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    } elseif (!empty($_POST)) {
        $body = $_POST;
    } else {
        // PUT / PATCH / DELETE with a urlencoded body.
        parse_str((string) file_get_contents('php://input'), $parsed);
        $body = is_array($parsed) ? $parsed : [];
    }

    return $body;
}

/** A body field as a trimmed string. */
function api_input(string $key, string $default = ''): string
{
    $value = api_body()[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function api_input_int(string $key, int $default = 0): int
{
    $value = api_body()[$key] ?? $default;
    return is_scalar($value) ? (int) $value : $default;
}

function api_input_bool(string $key, bool $default = false): bool
{
    $body = api_body();
    if (!array_key_exists($key, $body)) {
        return $default;
    }
    return filter_var($body[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function api_input_array(string $key): array
{
    $value = api_body()[$key] ?? [];
    return is_array($value) ? $value : [];
}

/** A query-string parameter as a trimmed string. */
function api_query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function api_query_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? (int) $value : $default;
}

/* ============================================================================
   RESPONSE
   ============================================================================ */

function api_json(array $payload, int $status = 200): never
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    http_response_code($status);
    // Explicit Content-Length prevents Apache from truncating or replacing
    // the response body on shared hosting (e.g. IONOS ErrorDocument intercept).
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($body));
    }
    echo $body;

    // Flush every output-buffering layer so the body leaves PHP before exit.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    exit;
}

function api_ok(mixed $data = null, ?string $message = null, int $status = 200): never
{
    api_json([
        'success' => true,
        'message' => $message,
        'data'    => $data,
        'errors'  => new stdClass(),
    ], $status);
}

function api_fail(string $message, int $status = 400, array $errors = []): never
{
    api_json([
        'success' => false,
        'message' => $message,
        'data'    => null,
        'errors'  => $errors ?: new stdClass(),
    ], $status);
}

/** 422 with per-field messages, the shape Flutter form validators expect. */
function api_invalid(array $errors, string $message = 'Please check the highlighted fields.'): never
{
    // Callers may pass null for fields that passed, so a single literal can
    // describe the whole form.
    api_fail($message, 422, array_filter($errors, static fn ($v): bool => $v !== null && $v !== ''));
}

/** Standard paginated envelope. */
function api_paginated(array $items, int $page, int $perPage, int $total): array
{
    return [
        'items' => $items,
        'meta'  => [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'pages'     => max(1, (int) ceil($total / max(1, $perPage))),
            'has_more'  => $page < max(1, (int) ceil($total / max(1, $perPage))),
        ],
    ];
}

/* ============================================================================
   TOKEN
   ============================================================================ */

/** This device's token. Anonymous — there are no customer accounts. */
function api_token(): string
{
    return (string) ($GLOBALS['api_token'] ?? '');
}

/* ============================================================================
   TRANSFORMERS
   Absolute URLs everywhere: a mobile client has no page to resolve a relative
   path against.
   ============================================================================ */

function api_image(?string $path): string
{
    $url = image_url($path);
    if (preg_match('#^(https?:)?//#i', $url)) {
        return $url;
    }
    $origin = site_origin();
    return $origin === '' ? $url : $origin . $url;
}

/** Product row -> list card. Keep it small; lists are the hot path. */
function api_product_card(array $p): array
{
    $price   = (float) $p['price'];
    $sale    = isset($p['sale_price']) && $p['sale_price'] !== null ? (float) $p['sale_price'] : null;
    $final   = effective_price($p);
    $onSale  = product_has_sale($p);

    return [
        'id'             => (int) $p['id'],
        'name'           => (string) $p['name'],
        'slug'           => (string) $p['slug'],
        'sku'            => (string) ($p['sku'] ?? ''),
        'short_description' => $p['short_description'] ?? null,
        'image'          => api_image($p['primary_image'] ?? ($p['image'] ?? '')),
        'price'          => round($price, 2),
        'sale_price'     => $sale !== null ? round($sale, 2) : null,
        'final_price'    => round($final, 2),
        'on_sale'        => $onSale,
        'discount_percent' => $onSale && $price > 0 ? (int) round((($price - $final) / $price) * 100) : 0,
        'formatted_price'  => money($final),
        'formatted_compare_at' => $onSale ? money($price) : null,
        'currency'       => setting('currency_symbol', 'Rs.'),
        'in_stock'       => (int) ($p['stock_quantity'] ?? 0) > 0,
        'stock_quantity' => (int) ($p['stock_quantity'] ?? 0),
        'stock_status'   => (string) ($p['stock_status'] ?? 'in_stock'),
        'rating'         => round((float) ($p['rating_avg'] ?? 0), 2),
        'rating_count'   => (int) ($p['rating_count'] ?? 0),
        'featured'       => (bool) ($p['featured'] ?? 0),
        'badges'         => array_map(
            static fn (array $b): array => ['label' => (string) ($b['text'] ?? ''), 'type' => (string) ($b['class'] ?? 'default')],
            product_badges($p)
        ),
        'category'       => $p['category_names'] ?? null,
        'colors'         => $p['color'] ?? null,
        'fabric'         => $p['fabric'] ?? null,
    ];
}

/** Product row -> full detail payload. */
function api_product_detail(array $p): array
{
    $card = api_product_card($p);

    $images = array_map(
        static fn (array $img): array => [
            'id'         => (int) $img['id'],
            'url'        => api_image($img['image']),
            'is_primary' => (bool) $img['is_primary'],
        ],
        get_product_images((int) $p['id'])
    );

    if (!$images) {
        $images[] = ['id' => 0, 'url' => api_image($p['primary_image'] ?? ''), 'is_primary' => true];
    }

    // Variants are grouped by name ("Size", "Colour") — that is how the app
    // needs to render selector rows.
    $groups = [];
    foreach (get_product_variants((int) $p['id']) as $v) {
        $name = (string) $v['variant_name'];
        $groups[$name] ??= ['name' => $name, 'options' => []];
        $groups[$name]['options'][] = [
            'id'               => (int) $v['id'],
            'value'            => (string) $v['variant_value'],
            'price_adjustment' => round((float) $v['price_adjustment'], 2),
            'final_price'      => round(effective_price($p, (float) $v['price_adjustment']), 2),
            'stock_quantity'   => (int) $v['stock_quantity'],
            'in_stock'         => (int) $v['stock_quantity'] > 0,
            'sku'              => $v['sku'] ?? null,
        ];
    }

    return $card + [
        'description'       => $p['description'] ?? null,
        'care_instructions' => $p['care_instructions'] ?? null,
        'product_type'      => $p['product_type'] ?? null,
        'material'          => $p['material'] ?? null,
        'occasion'          => $p['occasion'] ?? null,
        'style'             => $p['style'] ?? null,
        'size'              => $p['size'] ?? null,
        'gender'            => $p['gender'] ?? null,
        'tags'              => array_values(array_filter(array_map('trim', explode(',', (string) ($p['tags'] ?? ''))))),
        'video_url'         => $p['video_url'] ?? null,
        'images'            => $images,
        'variant_groups'    => array_values($groups),
        'categories'        => array_map(
            static fn (array $c): array => [
                'id' => (int) $c['id'], 'name' => (string) $c['name'], 'slug' => (string) $c['slug'],
            ],
            get_product_categories((int) $p['id'])
        ),
        'meta_title'        => $p['meta_title'] ?? null,
        'meta_description'  => $p['meta_description'] ?? null,
        'share_url'         => abs_url('/product/' . $p['slug']),
    ];
}

/** The global size chart as a flat list of rows (empty when none is set up). */
function api_size_chart(): array
{
    $chart = tc_size_chart();
    return is_array($chart['chart'] ?? null) ? $chart['chart'] : [];
}

function api_category(array $c): array
{
    return [
        'id'          => (int) $c['id'],
        'parent_id'   => isset($c['parent_id']) ? (int) $c['parent_id'] : null,
        'name'        => (string) $c['name'],
        'slug'        => (string) $c['slug'],
        'description' => $c['description'] ?? null,
        'image'       => api_image($c['image'] ?? ''),
        'product_count' => isset($c['product_count']) ? (int) $c['product_count'] : null,
    ];
}

function api_collection(array $c): array
{
    return [
        'id'          => (int) $c['id'],
        'name'        => (string) $c['name'],
        'slug'        => (string) $c['slug'],
        'type'        => $c['collection_type'] ?? null,
        'description' => $c['description'] ?? null,
        'image'       => api_image($c['image'] ?? ''),
        'banner'      => api_image($c['banner'] ?? ($c['image'] ?? '')),
        'is_featured' => (bool) ($c['is_featured'] ?? 0),
        'product_count' => isset($c['product_count']) ? (int) $c['product_count'] : null,
    ];
}

function api_cart_line(array $item): array
{
    return [
        'key'           => (string) $item['key'],
        'product_id'    => (int) $item['product_id'],
        'variant_id'    => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
        'name'          => (string) $item['name'],
        'slug'          => (string) $item['slug'],
        'image'         => api_image($item['product']['primary_image'] ?? ''),
        'variant_label' => $item['variant_label'] ?: null,
        'quantity'      => (int) $item['qty'],
        'unit_price'    => round((float) $item['unit_price'], 2),
        'line_total'    => round((float) $item['line_total'], 2),
        'formatted_unit_price' => money((float) $item['unit_price']),
        'formatted_line_total' => money((float) $item['line_total']),
        'available'     => (bool) $item['available'],
        'max_quantity'  => (int) $item['in_stock'],
    ];
}

/** The full cart payload — returned by every cart mutation so the app can
 *  re-render from one response instead of re-fetching. */
function api_cart_payload(): array
{
    $items  = cart_items();
    $totals = cart_totals();

    $coupon = tc_checkout_coupon((float) $totals['subtotal']);
    $couponDiscount = $coupon ? (float) $coupon['discount'] : 0.0;
    $discount = (float) $totals['discount'] + $couponDiscount;
    $grand    = max(0.0, (float) $totals['subtotal'] + (float) $totals['shipping'] - $discount);

    $freeAbove = (float) setting('free_shipping_threshold', '8000');

    return [
        'items'  => array_map('api_cart_line', $items),
        'coupon' => $coupon ? [
            'code'              => (string) $coupon['code'],
            'discount'          => round($couponDiscount, 2),
            'formatted_discount'=> money($couponDiscount),
        ] : null,
        'totals' => [
            'subtotal'  => round((float) $totals['subtotal'], 2),
            'shipping'  => round((float) $totals['shipping'], 2),
            'discount'  => round($discount, 2),
            'total'     => round($grand, 2),
            'formatted' => [
                'subtotal' => money((float) $totals['subtotal']),
                'shipping' => (float) $totals['shipping'] > 0 ? money((float) $totals['shipping']) : 'Free',
                'discount' => money($discount),
                'total'    => money($grand),
            ],
        ],
        'summary' => [
            'line_count'       => (int) $totals['line_count'],
            'item_count'       => (int) $totals['piece_count'],
            'has_unavailable'  => (bool) $totals['has_unavailable'],
            'free_shipping_threshold' => $freeAbove,
            'amount_to_free_shipping' => max(0.0, round($freeAbove - (float) $totals['subtotal'], 2)),
        ],
        'currency' => setting('currency_symbol', 'Rs.'),
    ];
}

function api_order_summary(array $o): array
{
    return [
        'id'             => (int) $o['id'],
        'order_number'   => (string) $o['order_number'],
        'status'         => (string) $o['order_status'],
        'payment_status' => (string) $o['payment_status'],
        'payment_method' => (string) $o['payment_method'],
        'total'          => round((float) $o['total'], 2),
        'formatted_total'=> money((float) $o['total']),
        'item_count'     => isset($o['item_count']) ? (int) $o['item_count'] : null,
        'placed_at'      => $o['created_at'],
        'placed_at_human'=> format_date($o['created_at'] ?? null),
    ];
}

function api_order_detail(array $o): array
{
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $stmt->execute([(int) $o['id']]);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $image = '';
        if (!empty($r['product_id'])) {
            $imgStmt = db()->prepare(
                'SELECT image FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC LIMIT 1'
            );
            $imgStmt->execute([(int) $r['product_id']]);
            $image = (string) ($imgStmt->fetchColumn() ?: '');
        }
        $items[] = [
            'product_id'    => $r['product_id'] !== null ? (int) $r['product_id'] : null,
            'name'          => (string) $r['product_name'],
            'variant_label' => $r['variant_label'] ?? null,
            'quantity'      => (int) $r['quantity'],
            'price'         => round((float) $r['price'], 2),
            'subtotal'      => round((float) $r['subtotal'], 2),
            'formatted_price'    => money((float) $r['price']),
            'formatted_subtotal' => money((float) $r['subtotal']),
            'image'         => api_image($image ?: ($r['image'] ?? '')),
        ];
    }

    $timeline = array_map(
        static fn (array $t): array => [
            'status'     => (string) $t['status'],
            'label'      => ucwords(str_replace('_', ' ', (string) $t['status'])),
            'note'       => $t['note'] ?? null,
            'created_at' => $t['created_at'],
            'human'      => format_datetime($t['created_at'] ?? null),
        ],
        tc_order_timeline((int) $o['id'])
    );

    return api_order_summary($o) + [
        'customer' => [
            'name'  => (string) $o['customer_name'],
            'email' => (string) $o['customer_email'],
            'phone' => $o['customer_phone'] ?? null,
        ],
        'shipping' => [
            'address'     => $o['shipping_address'] ?? null,
            'city'        => $o['city'] ?? null,
            'postal_code' => $o['postal_code'] ?? null,
            'country'     => $o['country'] ?? 'Pakistan',
            'method'      => $o['shipping_method'] ?? null,
            'tracking_number'   => $o['tracking_number'] ?? null,
            'delivery_estimate' => $o['delivery_estimate'] ?? null,
        ],
        'totals' => [
            'subtotal' => round((float) $o['subtotal'], 2),
            'shipping' => round((float) $o['shipping_fee'], 2),
            'discount' => round((float) $o['discount'], 2),
            'tax'      => round((float) ($o['tax'] ?? 0), 2),
            'total'    => round((float) $o['total'], 2),
            'formatted' => [
                'subtotal' => money((float) $o['subtotal']),
                'shipping' => (float) $o['shipping_fee'] > 0 ? money((float) $o['shipping_fee']) : 'Free',
                'discount' => money((float) $o['discount']),
                'total'    => money((float) $o['total']),
            ],
        ],
        'coupon_code' => $o['coupon_code'] ?? null,
        'notes'       => $o['notes'] ?? null,
        'is_gift'     => (bool) ($o['is_gift'] ?? 0),
        'gift_message'=> $o['gift_message'] ?? null,
        'items'       => $items,
        'timeline'    => $timeline,
        'flow'        => tc_order_flow(),
        'can_cancel'  => in_array((string) $o['order_status'], ['pending', 'confirmed'], true),
    ];
}

function api_review(array $r): array
{
    $images = [];
    if (tc_table_exists('review_images')) {
        $stmt = db()->prepare('SELECT image FROM review_images WHERE review_id = ? ORDER BY id ASC');
        $stmt->execute([(int) $r['id']]);
        $images = array_map(
            static fn (array $row): string => api_image($row['image']),
            $stmt->fetchAll()
        );
    }

    return [
        'id'         => (int) $r['id'],
        'name'       => (string) $r['name'],
        'rating'     => (int) $r['rating'],
        'title'      => $r['title'] ?? null,
        'body'       => $r['body'] ?? null,
        'fit_feedback' => $r['fit_feedback'] ?? null,
        'verified'   => (bool) ($r['is_verified_purchase'] ?? 0),
        'helpful_yes'=> (int) ($r['helpful_yes'] ?? 0),
        'created_at' => $r['created_at'] ?? null,
        'human'      => time_ago($r['created_at'] ?? null),
        'images'     => $images,
    ];
}
