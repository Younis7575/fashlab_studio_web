<?php
/**
 * Enterprise feature layer (Fashlab Studio).
 * ----------------------------------------------------------------------------
 * All new-feature helpers live here. Every database-backed helper is guarded
 * by tc_table_exists() / tc_column_exists(), so the storefront keeps working
 * unchanged on databases where migration-fashlab-upgrade.sql has NOT been
 * imported yet — features simply switch off until the schema exists.
 *
 * Covered: navigation menus, collections, wishlist, hero slides, reviews,
 * order tracking, coupons, size chart.
 */

declare(strict_types=1);

/* ============================================================================
   SCHEMA GUARDS (cached once per request)
   ============================================================================ */

function tc_table_exists(string $table): bool
{
    static $known = [];
    if (array_key_exists($table, $known)) {
        return $known[$table];
    }
    try {
        /* SHOW TABLES LIKE ? does not accept bound parameters in MariaDB */
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $known[$table] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('[enterprise] table check failed: ' . $e->getMessage());
        return $known[$table] = false;
    }
}

function tc_column_exists(string $table, string $column): bool
{
    static $known = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $known)) {
        return $known[$key];
    }
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return $known[$key] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('[enterprise] column check failed: ' . $e->getMessage());
        return $known[$key] = false;
    }
}

/* ============================================================================
   NAVIGATION MENUS (menu_items)
   ============================================================================ */

/** Flat, sorted menu rows for a location. */
function tc_menu_rows(string $location): array
{
    if (!tc_table_exists('menu_items')) {
        return [];
    }
    static $cache = [];
    if (array_key_exists($location, $cache)) {
        return $cache[$location];
    }
    $stmt = db()->prepare(
        'SELECT * FROM menu_items
         WHERE location = ? AND status = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$location]);
    return $cache[$location] = $stmt->fetchAll();
}

/** Nested menu tree: [ ['item' => row, 'children' => [...]] ... ]. */
function tc_menu_tree(string $location): array
{
    $rows = tc_menu_rows($location);
    if (!$rows) {
        return [];
    }

    $byParent = [];
    foreach ($rows as $row) {
        $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
    }

    $build = function (int $parentId) use (&$build, $byParent): array {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $row) {
            $out[] = ['item' => $row, 'children' => $build((int) $row['id'])];
        }
        return $out;
    };

    return $build(0);
}

/** Resolve a menu URL to a safe app URL (BASE_URL applied). */
function tc_menu_url(array $row): string
{
    $raw = trim((string) ($row['url'] ?? ''));
    if ($raw === '') {
        return url('/index.php');
    }
    if (preg_match('#^(https?:)?//#i', $raw)) {
        return $raw;
    }
    return url('/' . ltrim($raw, '/'));
}

/** Main navigation HTML (mega-menu ready). Empty string when no menus exist. */
function tc_render_main_nav(string $activeKey = ''): string
{
    $tree = tc_menu_tree('main');
    if (!$tree) {
        return '';
    }

    $html = '';

    foreach ($tree as $node) {
        $item = $node['item'];
        $label = e($item['label']);
        $children = $node['children'];

        if (!$children) {
            $active = $activeKey !== '' && ($item['url'] ?? '') !== '' && str_contains($item['url'], $activeKey) ? ' class="active"' : '';
            $html .= '<a href="' . e(tc_menu_url($item)) . '"' . $active . '>' . $label . '</a>';
            continue;
        }

        // Group children into mega-menu columns when they carry group_label.
        $grouped = [];
        foreach ($children as $child) {
            $g = (string) ($child['item']['group_label'] ?? '');
            $grouped[$g][] = $child;
        }

        $isMega = count($grouped) > 1;
        $html .= '<div class="nav-dropdown' . ($isMega ? ' mega' : '') . '">'
               . '<button type="button" class="nav-dropdown-toggle" aria-expanded="false">'
               . $label . ' <i class="fa-solid fa-chevron-down"></i></button>'
               . '<div class="nav-dropdown-menu' . ($isMega ? ' mega-menu' : '') . '">';

        foreach ($grouped as $group => $links) {
            $html .= '<div class="mega-column">';
            if ($group !== '') {
                $html .= '<span class="mega-group-label">' . e($group) . '</span>';
            }
            foreach ($links as $link) {
                $html .= '<a href="' . e(tc_menu_url($link['item'])) . '">' . e($link['item']['label']) . '</a>';
            }
            $html .= '</div>';
        }

        // Add a "View All" link at the bottom of the dropdown
        $html .= '<a href="' . url('/collections.php') . '" class="dropdown-featured">'
               . '<span class="dropdown-featured-label">EXPLORE ALL</span>'
               . '<span class="dropdown-featured-text">View all collections &rarr;</span></a>';

        $html .= '</div></div>';
    }

    return $html;
}

/** Footer column HTML. ['title' => html] pairs; empty when no menus exist. */
function tc_render_footer_columns(): array
{
    $locations = [
        'footer_shop'    => 'Shop',
        'footer_explore' => 'Explore',
        'footer_care'    => 'Customer Care',
        'footer_about'   => 'About',
    ];

    $columns = [];
    foreach ($locations as $loc => $title) {
        $rows = tc_menu_rows($loc);
        if (!$rows) {
            continue;
        }
        $links = '';
        foreach ($rows as $row) {
            $links .= '<a href="' . e(tc_menu_url($row)) . '">' . e($row['label']) . '</a>';
        }
        if ($links !== '') {
            $columns[$title] = $links;
        }
    }
    return $columns;
}

/* ============================================================================
   COLLECTIONS (collections + collection_products)
   ============================================================================ */

function tc_collections(): array
{
    if (!tc_table_exists('collections')) {
        return [];
    }
    $stmt = db()->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM collection_products cp
                 JOIN products p ON p.id = cp.product_id AND p.status = 1
                 WHERE cp.collection_id = c.id) AS product_count
         FROM collections c
         WHERE c.status = 1
         ORDER BY c.sort_order ASC, c.id ASC'
    );
    return $stmt->fetchAll();
}

function tc_collection(string $slugOrId): ?array
{
    if (!tc_table_exists('collections')) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT c.*,
                (SELECT COUNT(*) FROM collection_products cp
                 JOIN products p ON p.id = cp.product_id AND p.status = 1
                 WHERE cp.collection_id = c.id) AS product_count
         FROM collections c
         WHERE c.status = 1 AND (c.slug = ? OR c.id = ?)
         LIMIT 1'
    );
    $stmt->execute([$slugOrId, is_numeric($slugOrId) ? (int) $slugOrId : 0]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tc_collection_products(int $collectionId, int $limit = 12): array
{
    if (!tc_table_exists('collection_products')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT p.*, pi.image AS primary_image
         FROM collection_products cp
         JOIN products p ON p.id = cp.product_id AND p.status = 1
         LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
         WHERE cp.collection_id = ?
         ORDER BY cp.sort_order ASC, p.created_at DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $collectionId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/* ============================================================================
   WISHLIST (guests via session_id, logged-in customers later via customer_id)
   ============================================================================ */

function tc_visitor_id(): string
{
    if (empty($_SESSION['tc_visitor'])) {
        $_SESSION['tc_visitor'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['tc_visitor'];
}

function wishlist_ids(): array
{
    if (!tc_table_exists('wishlist_items')) {
        return $_SESSION['tc_wishlist'] ?? [];
    }
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = db()->prepare(
        'SELECT product_id FROM wishlist_items WHERE session_id = ? ORDER BY id DESC'
    );
    $stmt->execute([tc_visitor_id()]);
    $cache = array_map('intval', array_column($stmt->fetchAll(), 'product_id'));
    return $cache;
}

function wishlist_count(): int
{
    return count(wishlist_ids());
}

/**
 * Toggle a product in the wishlist.
 * @return array{ok: bool, added: bool, count: int}
 */
function wishlist_toggle(int $productId): array
{
    $ids = wishlist_ids();
    $added = false;

    if (in_array($productId, $ids, true)) {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $productId));
    } else {
        array_unshift($ids, $productId);
        $added = true;
    }

    if (tc_table_exists('wishlist_items')) {
        $db = db();
        if ($added) {
            $stmt = $db->prepare(
                'INSERT IGNORE INTO wishlist_items (customer_id, session_id, product_id) VALUES (NULL, ?, ?)'
            );
            $stmt->execute([tc_visitor_id(), $productId]);
        } else {
            $stmt = $db->prepare(
                'DELETE FROM wishlist_items WHERE session_id = ? AND product_id = ?'
            );
            $stmt->execute([tc_visitor_id(), $productId]);
        }
    } else {
        $_SESSION['tc_wishlist'] = $ids;
    }

    return ['ok' => true, 'added' => $added, 'count' => count($ids)];
}

/** Hydrated wishlist items (same shape as cart lines minus qty). */
function wishlist_items(): array
{
    $ids = wishlist_ids();
    if (!$ids) {
        return [];
    }

    $items = [];
    foreach ($ids as $productId) {
        $stmt = db()->prepare(
            'SELECT p.*, pi.image AS primary_image
             FROM products p
             LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || (int) $product['status'] !== 1) {
            continue;
        }
        $items[] = [
            'product'    => $product,
            'id'         => (int) $product['id'],
            'slug'       => $product['slug'],
            'name'       => $product['name'],
            'image'      => image_url($product['primary_image'] ?? ''),
            'price'      => effective_price($product),
            'in_stock'   => (int) $product['stock_quantity'] > 0,
            'added_at'   => $product['created_at'],
        ];
    }
    return $items;
}

/* ============================================================================
   HERO SLIDES (hero_slides)
   ============================================================================ */

function tc_hero_slides(): array
{
    if (!tc_table_exists('hero_slides')) {
        return [];
    }
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = db()->query(
        'SELECT * FROM hero_slides WHERE status = 1 ORDER BY sort_order ASC, id ASC'
    );
    return $cache = $stmt->fetchAll();
}

/* ============================================================================
   REVIEWS (reviews)
   ============================================================================ */

function tc_product_reviews(int $productId, int $limit = 10): array
{
    if (!tc_table_exists('reviews')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT * FROM reviews
         WHERE product_id = ? AND status IN (\'approved\', \'featured\')
         ORDER BY (status = \'featured\') DESC, created_at DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $productId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** ['avg', 'count', 'breakdown' => [5=>n, 4=>n, ...]] — all from real rows. */
function tc_review_summary(int $productId): array
{
    if (!tc_table_exists('reviews')) {
        return ['avg' => 0.0, 'count' => 0, 'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];
    }
    $stmt = db()->prepare(
        'SELECT rating, COUNT(*) AS n FROM reviews
         WHERE product_id = ? AND status IN (\'approved\', \'featured\')
         GROUP BY rating'
    );
    $stmt->execute([$productId]);

    $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $sum = 0;
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $r = (int) $row['rating'];
        if ($r < 1 || $r > 5) {
            continue;
        }
        $breakdown[$r] = (int) $row['n'];
        $sum += $r * (int) $row['n'];
        $count += (int) $row['n'];
    }
    return ['avg' => $count > 0 ? round($sum / $count, 1) : 0.0, 'count' => $count, 'breakdown' => $breakdown];
}

/**
 * Live rating per product for listing cards: [product_id => ['avg'=>4.5,'count'=>2]].
 * One grouped query per request, cached.
 */
function tc_rating_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!tc_table_exists('reviews')) {
        return $map;
    }
    try {
        $rows = db()->query(
            "SELECT product_id, ROUND(AVG(rating), 1) AS avg, COUNT(*) AS n
             FROM reviews WHERE status IN ('approved', 'featured') GROUP BY product_id"
        )->fetchAll();
        foreach ($rows as $r) {
            $map[(int) $r['product_id']] = ['avg' => (float) $r['avg'], 'count' => (int) $r['n']];
        }
    } catch (Throwable $e) {
        $map = [];
    }
    return $map;
}

/** One star icon for an average rating: full, half or empty. */
function tc_star_icon(int $position, float $avg, bool $hasReviews = true): string
{
    if ($hasReviews && $avg >= $position - 0.25) {
        return '<i class="fa-solid fa-star"></i>';
    }
    if ($hasReviews && $avg >= $position - 0.75) {
        return '<i class="fa-solid fa-star-half-stroke"></i>';
    }
    return '<i class="fa-regular fa-star"></i>';
}

/** Submit a review (stored 'pending' until admin approves). */
function tc_submit_review(array $data): array
{
    if (!tc_table_exists('reviews')) {
        return ['ok' => false, 'error' => 'Reviews are not available yet.'];
    }

    $productId = (int) ($data['product_id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
    $rating = (int) ($data['rating'] ?? 0);
    $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 190);
    $body = trim((string) ($data['body'] ?? ''));

    if ($productId < 1) {
        return ['ok' => false, 'error' => 'Invalid product.'];
    }
    if ($name === '' || !valid_email($email)) {
        return ['ok' => false, 'error' => 'Please enter your name and a valid email.'];
    }
    if ($rating < 1 || $rating > 5) {
        return ['ok' => false, 'error' => 'Please select a star rating.'];
    }
    if (mb_strlen($body) < 10) {
        return ['ok' => false, 'error' => 'Please write a short review (at least 10 characters).'];
    }

    $db = db();

    // One review per email per product — stops accidental double posts and spam bursts.
    $dupe = $db->prepare('SELECT COUNT(*) FROM reviews WHERE product_id = ? AND email = ?');
    $dupe->execute([$productId, $email]);
    if ((int) $dupe->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'You have already reviewed this product. Thank you!'];
    }

    // "Verified Purchase" only when this email really ordered this product.
    $verified = 0;
    $orderId = null;
    try {
        $v = $db->prepare(
            'SELECT o.id FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE LOWER(o.customer_email) = ? AND oi.product_id = ? AND o.order_status <> \'cancelled\'
             ORDER BY o.id DESC LIMIT 1'
        );
        $v->execute([$email, $productId]);
        $found = $v->fetchColumn();
        if ($found !== false) {
            $verified = 1;
            $orderId = (int) $found;
        }
    } catch (Throwable $e) {
        // Column names differ on older installs — the review still saves, just unverified.
    }

    $autoApprove = setting('reviews_auto_approve', '1') === '1';
    $status = $autoApprove ? 'approved' : 'pending';

    $stmt = $db->prepare(
        'INSERT INTO reviews (product_id, customer_id, order_id, name, email, rating, title, body, is_verified_purchase, status)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$productId, $orderId, mb_substr($name, 0, 150), $email, $rating, $title ?: null, mb_substr($body, 0, 2000), $verified, $status]);

    return ['ok' => true, 'message' => $autoApprove
        ? 'Thank you! Your review is now live.'
        : 'Thank you! Your review has been submitted and will appear once approved.'];
}

/* ============================================================================
   ORDER TRACKING (orders + order_status_history)
   ============================================================================ */

/** Canonical status flow for the tracking timeline. */
function tc_order_flow(): array
{
    return ['pending', 'confirmed', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered'];
}

function tc_find_order(string $orderNumber, string $contact): ?array
{
    $orderNumber = mb_strtoupper(trim($orderNumber));
    $contact = mb_strtolower(trim($contact));
    if ($orderNumber === '' || $contact === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT * FROM orders
         WHERE UPPER(order_number) = ?
           AND (LOWER(customer_email) = ? OR REPLACE(LOWER(customer_phone), \' \', \'\') = ?)
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$orderNumber, $contact, str_replace(' ', '', $contact)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Comma-joined item names for an order, used by My Orders listing. */
function tc_order_items_summary(int $orderId): array
{
    $stmt = db()->prepare(
        'SELECT oi.product_name, oi.quantity FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id ASC'
    );
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll();
    $names = [];
    foreach ($rows as $row) {
        $names[] = $row['product_name'] . ' × ' . (int) $row['quantity'];
    }
    return $names;
}

/** Timeline entries, newest first. Falls back to the order status itself. */
function tc_order_timeline(int $orderId): array
{
    if (!tc_table_exists('order_status_history')) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT status, note, created_at FROM order_status_history
         WHERE order_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

/* ============================================================================
   COUPONS (coupons) — server-side validation only, never trust the client
   ============================================================================ */

function tc_coupon_for_code(string $code): ?array
{
    if (!tc_table_exists('coupons')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
    $stmt->execute([mb_strtoupper(trim($code))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Discount amount for a subtotal, or null when the coupon does not apply. */
function tc_coupon_discount(array $coupon, float $subtotal): ?float
{
    if ((int) $coupon['status'] !== 1) {
        return null;
    }
    $now = time();
    if (!empty($coupon['starts_at']) && strtotime((string) $coupon['starts_at']) > $now) {
        return null;
    }
    if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < $now) {
        return null;
    }
    if ((int) $coupon['usage_limit'] > 0 && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
        return null;
    }
    if ($subtotal < (float) $coupon['min_order']) {
        return null;
    }

    $discount = (string) $coupon['type'] === 'percent'
        ? $subtotal * (float) $coupon['value'] / 100.0
        : (float) $coupon['value'];

    if (!empty($coupon['max_discount']) && (float) $coupon['max_discount'] > 0) {
        $discount = min($discount, (float) $coupon['max_discount']);
    }

    return max(0.0, min($discount, $subtotal));
}

/** Validate a code the customer typed, with a user-facing error. */
function tc_apply_coupon(string $code, float $subtotal): array
{
    $coupon = tc_coupon_for_code($code);
    if (!$coupon) {
        return ['ok' => false, 'error' => 'This coupon code is not valid.'];
    }

    $discount = tc_coupon_discount($coupon, $subtotal);
    if ($discount === null) {
        if ((int) $coupon['status'] !== 1) {
            return ['ok' => false, 'error' => 'This coupon is no longer active.'];
        }
        $now = time();
        if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < $now) {
            return ['ok' => false, 'error' => 'This coupon has expired.'];
        }
        if (!empty($coupon['starts_at']) && strtotime((string) $coupon['starts_at']) > $now) {
            return ['ok' => false, 'error' => 'This coupon is not active yet.'];
        }
        if ((int) $coupon['usage_limit'] > 0 && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            return ['ok' => false, 'error' => 'This coupon has reached its usage limit.'];
        }
        if ($subtotal < (float) $coupon['min_order']) {
            return ['ok' => false, 'error' => 'A minimum order of ' . money((float) $coupon['min_order']) . ' is required for this coupon.'];
        }
        return ['ok' => false, 'error' => 'This coupon does not apply to your order.'];
    }

    return ['ok' => true, 'code' => $coupon['code'], 'id' => (int) $coupon['id'], 'discount' => $discount];
}

/** Coupon currently applied in the session, re-validated against live totals. */
function tc_checkout_coupon(float $subtotal): ?array
{
    $code = $_SESSION['tc_coupon_code'] ?? '';
    if ($code === '') {
        return null;
    }
    $coupon = tc_coupon_for_code($code);
    if (!$coupon) {
        unset($_SESSION['tc_coupon_code']);
        return null;
    }
    $discount = tc_coupon_discount($coupon, $subtotal);
    if ($discount === null) {
        unset($_SESSION['tc_coupon_code']);
        return null;
    }
    return ['id' => (int) $coupon['id'], 'code' => $coupon['code'], 'discount' => $discount];
}

/* ============================================================================
   SIZE CHART (size_charts + size_chart_measurements)
   ============================================================================ */

function tc_size_chart(): ?array
{
    if (!tc_table_exists('size_chart_measurements')) {
        return null;
    }
    $stmt = db()->query(
        'SELECT * FROM size_chart_measurements
         WHERE size_chart_id = (SELECT id FROM size_charts WHERE is_global = 1 AND status = 1 ORDER BY id ASC LIMIT 1)
         ORDER BY sort_order ASC, id ASC'
    );
    $rows = $stmt->fetchAll();
    return $rows ? ['chart' => $rows] : null;
}