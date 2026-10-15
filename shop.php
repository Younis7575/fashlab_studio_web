<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/* ---------------------------------------------------------------------------
   Ramp to the actual product-fabric/occasion chips the homepage renders (only
   values that exist in the database are usable, so nothing can be forced: an
   admin who sets both ?fabric=lawn AND ?fabric=mixed leaves the '?fabric=
   lawn,mixed' URL when the user opens their shop). Normalise to a single
   value or an array of ids, then gate every filter on those lists.
--------------------------------------------------------------------------- */
$q            = get_string('q', 100);
$categoryIds  = array_values(array_filter(array_map('intval', (array) ($_GET['category'] ?? []))));
$price        = get_string('price', 30);
$fabric       = get_string('fabric', 60);
$color        = get_string('color', 60);
$size         = get_string('size', 40);
$availability = get_string('availability', 20) === 'in_stock';
$sale         = get_string('sale', 10) === '1';
$featured     = get_string('featured', 10) === '1';
$sort         = get_string('sort', 30);
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 12;

if (!in_array($sort, ['newest', 'price_low', 'price_high', 'featured', 'best_selling', 'name'], true)) {
    $sort = 'newest';
}

/* ---------------------------------------------------------------------------
   Validated filter values (database-driven)
--------------------------------------------------------------------------- */
$filters = [];

// Fabric / colour / size / price / sort: pieces of a multi-select filter bar,
// not radio buttons. No token (the homepage already passes '?fabric=lawn' for
// one value) and nothing before validation is trusted.
$rawFabric = get_string('fabric', 60);
if ($rawFabric !== '') {
    $values = array_values(array_filter(array_map('trim', explode(',', $rawFabric))));
    $values = array_values(array_unique($values));
    $fabricRows = db()->query(
        'SELECT DISTINCT fabric FROM products WHERE status = 1 AND fabric IS NOT NULL AND TRIM(fabric) <> \'\' ORDER BY fabric ASC'
    )->fetchAll();
    $fabricSet = array_map('strtolower', array_column($fabricRows, 'fabric'));
    $valid = array_values(array_filter($values, static function (string $v) use ($fabricSet): bool {
        return in_array(strtolower($v), $fabricSet, true);
    }));
    if ($valid) $filters['fabric'] = implode(',', $valid);
}

$rawColor = get_string('color', 60);
if ($rawColor !== '') {
    $values = array_values(array_filter(array_map('trim', explode(',', $rawColor))));
    $values = array_values(array_unique($values));
    $colorRows = db()->query(
        'SELECT DISTINCT color FROM products WHERE status = 1 AND color IS NOT NULL AND TRIM(color) <> \'\' ORDER BY color ASC'
    )->fetchAll();
    $colorSet = array_map('strtolower', array_column($colorRows, 'color'));
    $valid = array_values(array_filter($values, static function (string $v) use ($colorSet): bool {
        return in_array(strtolower($v), $colorSet, true);
    }));
    if ($valid) $filters['color'] = implode(',', $valid);
}

$rawSize = get_string('size', 40);
if ($rawSize !== '') {
    $values = array_values(array_filter(array_map('trim', explode(',', $rawSize))));
    $values = array_values(array_unique($values));
    $sizeRows = db()->query(
        'SELECT DISTINCT size FROM products WHERE status = 1 AND size IS NOT NULL AND TRIM(size) <> \'\' ORDER BY size ASC'
    )->fetchAll();
    $sizeSet = array_map('strtolower', array_column($sizeRows, 'size'));
    $valid = array_values(array_filter($values, static function (string $v) use ($sizeSet): bool {
        return in_array(strtolower($v), $sizeSet, true);
    }));
    if ($valid) $filters['size'] = implode(',', $valid);
}

if ($price !== '') $filters['price'] = $price;

/* ---------------------------------------------------------------------------
   Canonical query-string building (kept for links). Every value is already
   validated, so no 'fabric=lawn,mixed' ever escapes this page consumed by a
   fetch/SPA client.
--------------------------------------------------------------------------- */
$baseQueryParts = [];
if ($q !== '')            $baseQueryParts['q'] = $q;
if ($categoryIds)         $baseQueryParts['category'] = implode(',', $categoryIds);
if (!empty($filters['fabric'])) $baseQueryParts['fabric'] = $filters['fabric'];
if (!empty($filters['color']))  $baseQueryParts['color']  = $filters['color'];
if (!empty($filters['size']))   $baseQueryParts['size']   = $filters['size'];
if ($price !== '')        $baseQueryParts['price'] = $price;
if ($availability)        $baseQueryParts['availability'] = 'in_stock';
if ($sale)                $baseQueryParts['sale'] = '1';
if ($featured)            $baseQueryParts['featured'] = '1';
if ($sort !== 'newest')   $baseQueryParts['sort'] = $sort;
$baseQuery = http_build_query($baseQueryParts);

$result = get_products($filters);
$facets = product_facets();
$cats   = categories_with_counts();

/* Rebuild the current query string (without page) so links preserve filters. */
$qsParts = [];
if ($q !== '')            $qsParts['q'] = $q;
if ($categoryIds)         $qsParts['category'] = implode(',', $categoryIds);
if ($price !== '')        $qsParts['price'] = $price;
if ($fabric !== '')       $qsParts['fabric'] = $fabric;
if ($color !== '')        $qsParts['color'] = $color;
if ($size !== '')         $qsParts['size'] = $size;
if ($availability)        $qsParts['availability'] = 'in_stock';
if ($sale)                $qsParts['sale'] = '1';
if ($featured)            $qsParts['featured'] = '1';
if ($sort !== 'newest')   $qsParts['sort'] = $sort;
$baseQuery = http_build_query($baseQueryParts);

/* AJAX request: return only the grid + result count + pagination. */
if (is_ajax()) {
    header('Content-Type: text/html; charset=utf-8');
    echo render_products_grid($result['items']);
    echo '<input type="hidden" id="ajax-total" value="' . (int) $result['total'] . '">';
    echo '<input type="hidden" id="ajax-pages" value="' . (int) $result['pages'] . '">';
    echo '<input type="hidden" id="ajax-page" value="' . (int) $result['page'] . '">';
    exit;
}

$page_title      = "Shop Women's Clothing Online in Pakistan";
$meta_description = "Shop women's clothing online in Pakistan — stitched and unstitched suits, lawn, formal and casual wear from " . setting('store_name')
    . ', with free delivery on orders above ' . money((float) setting('free_shipping_threshold', '8000')) . '.';
$active_nav      = 'shop.php';

/* Faceted/sorted/search URLs duplicate the base listing: canonicalise them all
   to the clean shop URL and keep internal search results out of the index. */
$canonical = url('/shop.php');
if ($q !== '') {
    $robots_noindex = true;
}

require __DIR__ . '/includes/storefront-header.php';
?>

<!-- HERO -->
<section class="shop-hero">
    <div class="shop-hero-overlay"></div>
    <div class="container shop-hero-content">
        <p class="section-label">THE <?= e(strtoupper(setting('store_name'))) ?> COLLECTION</p>
        <h1>Shop Women's Clothing</h1>
        <p>Discover thoughtfully designed pieces, refined details and timeless styles for every occasion.</p>
    </div>
</section>

<!-- SHOP CONTENT -->
<section class="shop-section section-padding">
    <div class="container">

        <!-- SHOP TOP BAR -->
        <div class="shop-topbar">
            <div>
                <p class="shop-result-count">
                    Showing <strong id="product-count"><?= (int) $result['total'] ?></strong> products
                </p>
            </div>

            <div class="shop-controls">
                <button class="filter-toggle" type="button">
                    <i class="fa-solid fa-sliders"></i> Filters
                </button>

                <label for="sort-products" class="sort-label">Sort by:</label>
                <select id="sort-products" class="sort-select">
                    <option value="newest"       <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                    <option value="featured"     <?= $sort === 'featured' ? 'selected' : '' ?>>Featured</option>
                    <option value="best_selling" <?= $sort === 'best_selling' ? 'selected' : '' ?>>Best Selling</option>
                    <option value="price_low"    <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_high"   <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="name"         <?= $sort === 'name' ? 'selected' : '' ?>>Name: A to Z</option>
                </select>
            </div>
        </div>

        <!-- SHOP LAYOUT -->
        <div class="shop-layout">

            <!-- FILTER SIDEBAR -->
            <aside class="filter-sidebar" id="filter-sidebar">

                <div class="filter-header">
                    <h2>Filters</h2>
                    <button type="button" class="filter-close" aria-label="Close filters">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <!-- CATEGORY -->
                <div class="filter-group">
                    <h3>Category</h3>
                    <?php foreach ($cats as $cat): ?>
                        <label class="filter-option">
                            <input type="checkbox" name="category" value="<?= (int) $cat['id'] ?>"
                                   <?= in_array((int) $cat['id'], $categoryIds, true) ? 'checked' : '' ?>>
                            <span><?= e($cat['name']) ?> <em>(<?= (int) $cat['product_count'] ?>)</em></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- PRICE -->
                <div class="filter-group">
                    <h3>Price Range</h3>
                    <?php
                    $priceOptions = [
                        ''             => 'All Prices',
                        'under-5000'   => 'Under Rs 5,000',
                        '5000-10000'   => 'Rs 5,000 – Rs 10,000',
                        '10000-15000'  => 'Rs 10,000 – Rs 15,000',
                        'over-15000'   => 'Above Rs 15,000',
                    ];
                    foreach ($priceOptions as $val => $label): ?>
                        <label class="filter-option">
                            <input type="radio" name="price" value="<?= e($val) ?>" <?= $price === $val ? 'checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- FABRIC -->
                <?php if ($facets['fabrics']): ?>
                <div class="filter-group">
                    <h3>Fabric</h3>
                    <?php foreach ($facets['fabrics'] as $f): ?>
                        <label class="filter-option">
                            <input type="checkbox" name="fabric" value="<?= e($f) ?>" <?= $fabric === $f ? 'checked' : '' ?>>
                            <span><?= e($f) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- COLOR -->
                <?php if ($facets['colors']): ?>
                <div class="filter-group">
                    <h3>Color</h3>
                    <?php foreach ($facets['colors'] as $c): ?>
                        <label class="filter-option">
                            <input type="checkbox" name="color" value="<?= e($c) ?>" <?= $color === $c ? 'checked' : '' ?>>
                            <span><?= e($c) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- SIZE -->
                <?php if ($facets['sizes']): ?>
                <div class="filter-group">
                    <h3>Size</h3>
                    <?php foreach ($facets['sizes'] as $s): ?>
                        <label class="filter-option">
                            <input type="checkbox" name="size" value="<?= e($s) ?>" <?= $size === $s ? 'checked' : '' ?>>
                            <span><?= e($s) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- AVAILABILITY -->
                <div class="filter-group">
                    <h3>Availability</h3>
                    <label class="filter-option">
                        <input type="checkbox" name="availability" value="in_stock" <?= $availability ? 'checked' : '' ?>>
                        <span>In Stock Only</span>
                    </label>
                </div>

                <!-- SPECIAL -->
                <div class="filter-group">
                    <h3>Special</h3>
                    <label class="filter-option">
                        <input type="checkbox" name="sale" value="1" <?= $sale ? 'checked' : '' ?>>
                        <span>On Sale</span>
                    </label>
                    <label class="filter-option">
                        <input type="checkbox" name="featured" value="1" <?= $featured ? 'checked' : '' ?>>
                        <span>Featured</span>
                    </label>
                </div>

                <button type="button" class="clear-filters">Clear All Filters</button>
            </aside>

            <!-- PRODUCTS -->
            <div class="shop-products">

                <!-- SEARCH -->
                <div class="shop-search">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" id="shop-search" placeholder="Search dresses, suits, collections..."
                               value="<?= e($q) ?>">
                    </div>
                </div>

                <!-- PRODUCT GRID -->
                <div class="product-grid shop-product-grid" id="shop-product-grid">
                    <?php echo render_products_grid($result['items']); ?>
                </div>

                <!-- PAGINATION -->
                <div id="shop-pagination">
                    <?= pagination_links($result['page'], $result['pages'], $baseQuery) ?>
                </div>

            </div>
        </div>
    </div>
</section>

<script>
    window.TC_SHOP_STATE = {
        baseQuery: <?= json_encode($baseQuery, JSON_UNESCAPED_SLASHES) ?>,
        page: <?= (int) $result['page'] ?>,
        pages: <?= (int) $result['pages'] ?>,
        total: <?= (int) $result['total'] ?>
    };
</script>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>