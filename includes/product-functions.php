<?php
/**
 * Product & category query layer.
 * All storefront listings read from MySQL through these functions.
 */

declare(strict_types=1);

/** Effective selling price (sale price wins when set). */
function effective_price(array $product, float $adjustment = 0.0): float
{
    $base = (float) ($product['sale_price'] ?? 0) > 0
        ? (float) $product['sale_price']
        : (float) $product['price'];
    return $base + $adjustment;
}

function product_has_sale(array $product): bool
{
    return (float) ($product['sale_price'] ?? 0) > 0
        && (float) $product['sale_price'] < (float) $product['price'];
}

/* ============================================================================
   PRODUCT LISTING (search / filter / sort / pagination)
   ============================================================================ */

/**
 * @param array $filters keys: q, category_id, price (preset), price_min, price_max,
 *                       fabric, color, size, availability, sale, featured, sort, page, per_page
 */
function get_products(array $filters = []): array
{
    $page    = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(48, (int) ($filters['per_page'] ?? 12)));

    $where  = ['p.status = 1'];
    $params = [];

    if (!empty($filters['category_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM product_categories pc
                             WHERE pc.product_id = p.id AND pc.category_id = ?)';
        $params[] = (int) $filters['category_id'];
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.short_description LIKE ? OR p.description LIKE ?
                     OR p.fabric LIKE ? OR p.color LIKE ?
                     OR EXISTS (SELECT 1 FROM product_categories pc2
                                JOIN categories c2 ON c2.id = pc2.category_id
                                WHERE pc2.product_id = p.id AND c2.name LIKE ?))';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $pricePreset = (string) ($filters['price'] ?? '');
    if ($pricePreset === 'under-5000') {
        $where[] = 'COALESCE(p.sale_price, p.price) < 5000';
    } elseif ($pricePreset === '5000-10000') {
        $where[] = 'COALESCE(p.sale_price, p.price) >= 5000 AND COALESCE(p.sale_price, p.price) <= 10000';
    } elseif ($pricePreset === '10000-15000') {
        $where[] = 'COALESCE(p.sale_price, p.price) > 10000 AND COALESCE(p.sale_price, p.price) <= 15000';
    } elseif ($pricePreset === 'over-15000') {
        $where[] = 'COALESCE(p.sale_price, p.price) > 15000';
    } else {
        if (isset($filters['price_min']) && $filters['price_min'] !== '') {
            $where[] = 'COALESCE(p.sale_price, p.price) >= ?';
            $params[] = (float) $filters['price_min'];
        }
        if (isset($filters['price_max']) && $filters['price_max'] !== '') {
            $where[] = 'COALESCE(p.sale_price, p.price) <= ?';
            $params[] = (float) $filters['price_max'];
        }
    }

    $fabric = trim((string) ($filters['fabric'] ?? ''));
    if ($fabric !== '') {
        $where[] = 'p.fabric LIKE ?';
        $params[] = '%' . $fabric . '%';
    }
    $color = trim((string) ($filters['color'] ?? ''));
    if ($color !== '') {
        $where[] = 'p.color LIKE ?';
        $params[] = '%' . $color . '%';
    }
    $size = trim((string) ($filters['size'] ?? ''));
    if ($size !== '') {
        $where[] = 'p.size LIKE ?';
        $params[] = '%' . $size . '%';
    }
    if (!empty($filters['availability'])) {
        $where[] = 'p.stock_quantity > 0';
    }
    if (!empty($filters['sale'])) {
        $where[] = 'p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price';
    }
    if (!empty($filters['featured'])) {
        $where[] = 'p.featured = 1';
    }

    $sortMap = [
        'newest'      => 'p.created_at DESC, p.id DESC',
        'price_low'   => 'COALESCE(p.sale_price, p.price) ASC',
        'price_high'  => 'COALESCE(p.sale_price, p.price) DESC',
        'featured'    => 'p.featured DESC, p.created_at DESC, p.id DESC',
        'best_selling'=> 'oi.qty DESC, p.created_at DESC, p.id DESC',
        'name'        => 'p.name ASC',
    ];
    $orderBy = $sortMap[$filters['sort'] ?? 'newest'] ?? 'p.created_at DESC, p.id DESC';

    $whereSql = implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT p.*,
                   pi.image AS primary_image,
                   cats.category_names,
                   cats.category_slugs,
                   oi.qty AS sold_qty
            FROM products p
            LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
            LEFT JOIN (SELECT pc.product_id,
                              GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ') AS category_names,
                              GROUP_CONCAT(c.slug  ORDER BY c.sort_order SEPARATOR ',')  AS category_slugs
                       FROM product_categories pc
                       JOIN categories c ON c.id = pc.category_id
                       GROUP BY pc.product_id) cats ON cats.product_id = p.id
            LEFT JOIN (SELECT product_id, SUM(quantity) AS qty FROM order_items GROUP BY product_id) oi ON oi.product_id = p.id
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    return [
        'items'    => $items,
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $page,
        'per_page' => $perPage,
    ];
}

/** All product images ordered for display. */
function get_product_images(int $productId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC'
    );
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/** All active variants for a product. */
function get_product_variants(int $productId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM product_variants WHERE product_id = ? AND status = 1 ORDER BY id ASC'
    );
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/** Categories a product belongs to. */
function get_product_categories(int $productId): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.name, c.slug FROM product_categories pc
         JOIN categories c ON c.id = pc.category_id
         WHERE pc.product_id = ? ORDER BY c.sort_order ASC, c.name ASC'
    );
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/**
 * Single product (with primary image, category names).
 * @param bool $includeInactive also return deactivated products (admin use).
 */
function get_product(string $slugOrId, bool $includeInactive = false): ?array
{
    $stmt = db()->prepare(
        "SELECT p.*, pi.image AS primary_image,
                cats.category_names, cats.category_slugs
         FROM products p
         LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
         LEFT JOIN (SELECT pc.product_id,
                           GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ') AS category_names,
                           GROUP_CONCAT(c.slug  ORDER BY c.sort_order SEPARATOR ',')  AS category_slugs
                    FROM product_categories pc
                    JOIN categories c ON c.id = pc.category_id
                    GROUP BY pc.product_id) cats ON cats.product_id = p.id
         WHERE (" . ($includeInactive ? 'p.slug = ? OR p.id = ?' : 'p.slug = ? AND p.status = 1') . ")
         LIMIT 1"
    );

    if ($includeInactive) {
        $stmt->execute([$slugOrId, is_numeric($slugOrId) ? (int) $slugOrId : 0]);
    } else {
        $stmt->execute([$slugOrId]);
    }

    $product = $stmt->fetch();
    return $product ?: null;
}

/* ============================================================================
   HOMEPAGE SECTIONS
   ============================================================================ */

function featured_products(int $limit = 8): array
{
    return get_products(['featured' => 1, 'sort' => 'newest', 'per_page' => $limit, 'page' => 1])['items'];
}

function new_arrivals(int $limit = 8): array
{
    return get_products(['sort' => 'newest', 'per_page' => $limit, 'page' => 1])['items'];
}

function sale_products(int $limit = 8): array
{
    return get_products(['sale' => 1, 'sort' => 'price_low', 'per_page' => $limit, 'page' => 1])['items'];
}

function best_sellers(int $limit = 8): array
{
    return get_products(['sort' => 'best_selling', 'per_page' => $limit, 'page' => 1])['items'];
}

/** Products in the same primary category, excluding the current one. */
function related_products(array $product, int $limit = 4): array
{
    if (empty($product['category_id'])) {
        return [];
    }
    $stmt = db()->prepare(
        "SELECT p.*, pi.image AS primary_image
         FROM products p
         LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
         WHERE p.status = 1 AND p.category_id = ? AND p.id <> ?
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, (int) $product['category_id'], PDO::PARAM_INT);
    $stmt->bindValue(2, (int) $product['id'], PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/* ============================================================================
   FACETS (filters)
   ============================================================================ */

function product_facets(): array
{
    $facets = ['fabrics' => [], 'colors' => [], 'sizes' => []];

    foreach (['fabric' => 'fabrics', 'color' => 'colors', 'size' => 'sizes'] as $col => $key) {
        $stmt = db()->prepare(
            "SELECT DISTINCT {$col} AS value FROM products
             WHERE status = 1 AND {$col} IS NOT NULL AND TRIM({$col}) <> ''
             ORDER BY {$col} ASC"
        );
        $stmt->execute();
        $facets[$key] = array_column($stmt->fetchAll(), 'value');
    }

    return $facets;
}

/* ============================================================================
   CATEGORIES
   ============================================================================ */

function get_categories(bool $activeOnly = true, bool $navOnly = false): array
{
    $sql = 'SELECT * FROM categories';
    $where = [];
    if ($activeOnly) $where[] = 'status = 1';
    if ($navOnly) $where[] = 'show_in_nav = 1';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY sort_order ASC, name ASC';

    return db()->query($sql)->fetchAll();
}

function get_category_by_slug(string $slug, bool $includeInactive = false): ?array
{
    $sql = 'SELECT * FROM categories WHERE slug = ?';
    if (!$includeInactive) $sql .= ' AND status = 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Active categories with live product counts (only counting active products).
 * Categories without their own uploaded image get a cover resolved from the
 * primary image of the first active product in that category, so collection
 * cards are never blank on the storefront.
 */
function categories_with_counts(int $limit = 0): array
{
    $sql = 'SELECT c.*, COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN product_categories pc ON pc.category_id = c.id
            LEFT JOIN products p ON p.id = pc.product_id AND p.status = 1
            WHERE c.status = 1
            GROUP BY c.id
            ORDER BY c.sort_order ASC, c.name ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $rows = db()->query($sql)->fetchAll();

    $needCover = array_values(array_filter(array_map(
        static fn (array $r): ?int => empty($r['image']) ? (int) $r['id'] : null,
        $rows
    )));

    if ($needCover) {
        $in  = implode(',', $needCover);
        $coverStmt = db()->query(
            'SELECT pc.category_id AS cid, pi.image AS img
             FROM product_categories pc
             JOIN products p ON p.id = pc.product_id AND p.status = 1
             JOIN product_images pi ON pi.product_id = pc.product_id AND pi.is_primary = 1
             WHERE pc.category_id IN (' . $in . ')
             GROUP BY pc.category_id'
        );
        $covers = [];
        foreach ($coverStmt->fetchAll() as $cv) {
            $covers[(int) $cv['cid']] = $cv['img'];
        }
        foreach ($rows as &$row) {
            if (empty($row['image']) && !empty($covers[(int) $row['id']])) {
                $row['image'] = $covers[(int) $row['id']];
            }
        }
        unset($row);
    }

    return $rows;
}

/* ============================================================================
   CARD RENDERING
   ============================================================================ */

/**
 * Badge labels for a product (SALE / NEW / BEST SELLER / TRENDING / FEATURED).
 * Uses the migration columns when present, else derives NEW from created_at
 * so the storefront works before and after the schema upgrade.
 */
function product_badges(array $product): array
{
    $badges = [];

    if (product_has_sale($product)) {
        $badges[] = ['text' => 'SALE', 'class' => 'sale'];
    }

    $isNew = tc_column_exists('products', 'is_new')
        ? (int) ($product['is_new'] ?? 0) === 1
        : strtotime((string) ($product['created_at'] ?? '')) > time() - 60 * 86400;
    $isBest = tc_column_exists('products', 'is_best_seller') && (int) ($product['is_best_seller'] ?? 0) === 1;
    $isTrending = tc_column_exists('products', 'is_trending') && (int) ($product['is_trending'] ?? 0) === 1;

    if ($isNew) {
        $badges[] = ['text' => 'NEW', 'class' => 'new'];
    } elseif ($isBest) {
        $badges[] = ['text' => 'BEST SELLER', 'class' => 'best'];
    } elseif ($isTrending) {
        $badges[] = ['text' => 'TRENDING', 'class' => 'trending'];
    } elseif ((int) ($product['featured'] ?? 0) === 1) {
        $badges[] = ['text' => 'FEATURED', 'class' => 'featured'];
    }

    return $badges;
}

/** HTML for a product card (matches the existing shop-grid design). */
function render_product_card(array $product): string
{
    $img1 = image_url($product['primary_image'] ?? '');
    $img2 = '';
    if (!empty($product['id'])) {
        $images = get_product_images((int) $product['id']);
        $img2 = isset($images[1]['image']) ? image_url($images[1]['image']) : '';
    }

    $name = e($product['name']);
    $altName = e(setting('brand_name', setting('store_name')) . ' ' . $product['name']);
    $slug = e($product['slug']);
    $href = e(product_url($product['slug']));
    $productId = (int) ($product['id'] ?? 0);

    $price = money(effective_price($product));
    $oldPrice = product_has_sale($product) ? '<span class="old-price">' . money((float) $product['price']) . '</span> ' : '';

    $badges = product_badges($product);
    $badgeHtml = '';
    foreach ($badges as $badge) {
        $badgeHtml .= '<span class="product-badge ' . e($badge['class']) . '">' . e($badge['text']) . '</span>';
    }

    $categoryName = setting('store_name');
    if (!empty($product['category_names'])) {
        $categoryName = explode(', ', $product['category_names'])[0];
    }

    $outOfStock = (int) $product['stock_quantity'] < 1;
    $stockHtml = $outOfStock
        ? '<span class="product-tag out-of-stock">Out of Stock</span>'
        : '<span class="product-tag">' . e($categoryName) . '</span>';

    $altImg = $img2 !== '' && $img2 !== $img1
        ? '<img class="img-alt" src="' . e($img2) . '" alt="" loading="lazy">'
        : '';

    /* Real ratings only — products without reviews show an invitation, never fake stars. */
    $ratingHtml = '';
    if (function_exists('tc_rating_map')) {
        $rating = tc_rating_map()[$productId] ?? null;
        if ($rating && $rating['count'] > 0) {
            $stars = '';
            for ($i = 1; $i <= 5; $i++) {
                $stars .= tc_star_icon($i, $rating['avg']);
            }
            $ratingHtml = '<div class="p-rating" aria-label="Rated ' . e((string) $rating['avg']) . ' out of 5">'
                . '<span class="p-stars">' . $stars . '</span>'
                . '<span class="p-rating-count">' . e(number_format($rating['avg'], 1)) . ' (' . (int) $rating['count'] . ')</span></div>';
        } else {
            $ratingHtml = '<div class="p-rating is-empty"><span class="p-stars"><i class="fa-regular fa-star"></i></span>'
                . '<span class="p-rating-count">No reviews yet</span></div>';
        }
    }

    $wished = in_array($productId, wishlist_ids(), true);
    $actionsHtml = '<div class="product-actions">'
        . '<button type="button" class="quick-view-btn" data-product-id="' . $productId
        . '" aria-label="Quick view of ' . $name . '" title="Quick view"><i class="fa-regular fa-eye"></i></button>'
        . '<button type="button" class="wishlist-toggle' . ($wished ? ' active' : '') . '" data-product-id="' . $productId
        . '" data-added="' . ($wished ? '1' : '0') . '" aria-label="' . ($wished ? 'Remove from wishlist' : 'Add to wishlist')
        . '" aria-pressed="' . ($wished ? 'true' : 'false') . '" title="Wishlist"><i class="fa-' . ($wished ? 'solid' : 'regular') . ' fa-heart"></i></button>'
        . '</div>';

    $addBtn = $outOfStock
        ? '<button type="button" class="mini-cart-btn" disabled>Sold Out</button>'
        : '<button type="button" class="mini-cart-btn" data-product-id="' . $productId
          . '" data-product-name="' . $name . '" data-product-price="' . effective_price($product)
          . '" data-product-image="' . e($img1) . '"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><span>Add to Cart</span></button>';

    return '<article class="product-card" data-product-id="' . $productId . '">
        <a class="product-card-link" href="' . $href . '">
            <div class="product-thumb">
                <div class="product-badges">' . $badgeHtml . '</div>
                ' . $actionsHtml . '
                <img src="' . e($img1) . '" alt="' . $altName . '" loading="lazy">
                ' . $altImg . '
                ' . $stockHtml . '
            </div>
            <div class="product-info">
                <div class="p-cat">' . e($categoryName) . '</div>
                <div class="p-name">' . $name . '</div>
                ' . $ratingHtml . '
                <div class="p-price">' . $oldPrice . $price . '</div>
            </div>
        </a>
        ' . $addBtn . '
    </article>';
}

/** Grid fragment used by shop.php and the AJAX endpoint. */
function render_products_grid(array $items): string
{
    if (!$items) {
        return '<div class="shop-empty" id="shop-empty">
            <div class="lx-lottie" data-lottie="bag-empty"><i class="fa-regular fa-face-frown"></i></div>
            <h2>No products found</h2>
            <p>Try changing your search or filters.</p>
        </div>';
    }
    $html = '';
    foreach ($items as $product) {
        $html .= render_product_card($product);
    }
    return $html;
}