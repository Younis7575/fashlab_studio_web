<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$slug = get_string('slug', 200);
$category = $slug !== '' ? get_category_by_slug($slug) : null;

if (!$category) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$categoryId = (int) $category['id'];

/* ---------------------------------------------------------------------------
   Filters (same pipeline as shop.php, with category pre-selected)
--------------------------------------------------------------------------- */
$q            = get_string('q', 100);
$extraCatIds  = array_values(array_filter(array_map('intval', explode(',', implode(',', (array) ($_GET['category'] ?? []))))));
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

$filters = [
    'q'             => $q,
    'category_id'   => $categoryId,
    'price'         => $price,
    'fabric'        => $fabric,
    'color'         => $color,
    'size'          => $size,
    'availability'  => $availability,
    'sale'          => $sale,
    'featured'      => $featured,
    'sort'          => $sort,
    'page'          => $page,
    'per_page'      => $perPage,
];

/* Categories ticked in the sidebar replace this page's own category. */
if ($extraCatIds) {
    unset($filters['category_id']);
    $filters['category_ids'] = $extraCatIds;
}

$result = get_products($filters);
$facets = product_facets();
$cats   = categories_with_counts();

$qsParts = [];
if ($q !== '')          $qsParts['q'] = $q;
if ($price !== '')      $qsParts['price'] = $price;
if ($fabric !== '')     $qsParts['fabric'] = $fabric;
if ($color !== '')      $qsParts['color'] = $color;
if ($size !== '')       $qsParts['size'] = $size;
if ($availability)      $qsParts['availability'] = 'in_stock';
if ($sale)              $qsParts['sale'] = '1';
if ($featured)          $qsParts['featured'] = '1';
if ($sort !== 'newest') $qsParts['sort'] = $sort;
$baseQuery = http_build_query($qsParts);

if (is_ajax()) {
    header('Content-Type: text/html; charset=utf-8');
    echo render_products_grid($result['items']);
    echo '<input type="hidden" id="ajax-total" value="' . (int) $result['total'] . '">';
    echo '<input type="hidden" id="ajax-pages" value="' . (int) $result['pages'] . '">';
    echo '<input type="hidden" id="ajax-page" value="' . (int) $result['page'] . '">';
    exit;
}

/* SEO metadata per category — one primary topic per page, written for search
   intent, only where real products exist. Admin meta_* columns always win. */
$freeOver = money((float) setting('free_shipping_threshold', '8000'));
$exDays   = (int) setting('exchange_policy_days', '7');

$seoMap = [
    'stitched' => [
        'title' => "Stitched Women's Clothes Online in Pakistan",
        'h1'    => "Stitched Women's Clothing",
        'intro' => 'Ready-to-wear stitched clothing online in Pakistan: stitched suits, Pakistani stitched dresses and finished outfits for everyday and occasion wear. Explore ready-to-wear outfits by fabric, size and price, with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'unstitched' => [
        'title' => 'Unstitched Suits Online in Pakistan',
        'h1'    => 'Unstitched Women\'s Suits',
        'intro' => 'Shop unstitched suits online in Pakistan — unstitched lawn, 2 piece and 3 piece unstitched suits and fabric collections you can tailor to your own measurements. Browse Pakistani unstitched clothing by fabric, size and price, with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'two-piece' => [
        'title' => '2 Piece Suits Online in Pakistan',
        'h1'    => '2 Piece Suits',
        'intro' => 'Shop 2 piece suits online in Pakistan — shirt and trouser sets and 2 piece lawn, cotton and formal suits for everyday and occasion wear, with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'three-piece' => [
        'title' => '3 Piece Suits Online in Pakistan',
        'h1'    => '3 Piece Suits',
        'intro' => 'Explore 3 piece suits online in Pakistan — complete looks with shirt, bottom and dupatta in lawn, cotton, chiffon and formal fabrics, with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'lawn-collection' => [
        'title' => 'Lawn Suits Online in Pakistan',
        'h1'    => 'Lawn Collection',
        'intro' => 'Explore lawn suits online in Pakistan — printed lawn, summer lawn and seasonal Pakistani lawn fashion in 2 piece and 3 piece sets. Browse the lawn collection by colour and price, with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'formal-wear' => [
        'title' => "Women's Formal Wear Online in Pakistan",
        'h1'    => "Women's Formal Wear",
        'intro' => "Women's formal wear online in Pakistan — occasion wear, wedding guest dresses and eastern ensembles for weddings, dinners and festive events. Browse Pakistani formal wear by size and price, with free delivery above " . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'casual-wear' => [
        'title' => "Women's Casual Wear Online in Pakistan",
        'h1'    => "Women's Casual Wear",
        'intro' => "Casual wear for women in Pakistan — everyday stitched and unstitched outfits, kurtas and co-ord sets designed for comfort. Shop women's casual wear online with free delivery above " . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'eid-collection' => [
        'title' => 'Eid Dresses & Eid Collection for Women',
        'h1'    => 'Eid Collection',
        'intro' => 'Eid dresses and Eid collection for women — stitched and unstitched Eid outfits in lawn, cotton and formal fabrics, delivered across Pakistan with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'new-arrivals' => [
        'title' => "New Arrivals: New Women's Clothing in Pakistan",
        'h1'    => 'New Arrivals',
        'intro' => 'Discover new arrivals in Pakistani women\'s clothing — the latest stitched and unstitched suits, lawn sets and co-ords added this season, with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
    'sale' => [
        'title' => "Sale on Women's Clothing Online in Pakistan",
        'h1'    => 'The Sale Edit',
        'intro' => 'Shop the sale on women\'s clothing online in Pakistan — marked-down stitched and unstitched suits, lawn and formal wear while stock lasts, with free delivery above ' . $freeOver . ' and ' . $exDays . '-day easy exchange.',
    ],
];

$seoCat   = $seoMap[$category['slug']] ?? [];
$introText = $category['meta_description']
    ?: ($seoCat['intro'] ?? $category['description'] ?? '');

$page_title       = $category['meta_title']
    ?: ($seoCat['title'] ?? $category['name'] . " Online in Pakistan");
$meta_description = $category['meta_description']
    ?: mb_substr(strip_tags((string) ($seoCat['intro'] ?? $category['description'] ?? '')), 0, 158);
$canonical        = category_url($category['slug']);
$active_nav       = 'shop.php';

if ($q !== '') {
    $robots_noindex = true; // internal search results stay out of the index
}

$extra_schema = [
    ['@context' => 'https://schema.org', '@type' => 'CollectionPage',
     'name' => $category['name'], 'url' => abs_url('/category.php?slug=' . rawurlencode((string) $category['slug'])),
     'description' => strip_tags((string) $meta_description),
     'isPartOf' => ['@type' => 'WebSite', 'name' => setting('store_name'), 'url' => abs_url('/index.php')]],
    ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => abs_url('/index.php')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop', 'item' => abs_url('/shop.php')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $category['name'], 'item' => abs_url('/category.php?slug=' . rawurlencode((string) $category['slug']))],
    ]],
];

require __DIR__ . '/includes/storefront-header.php';
?>

<!-- HERO -->
<section class="categories-hero">
    <div class="container">
        <div class="categories-hero-content">
            <span class="eyebrow"><?= e(strtoupper(setting('store_name'))) ?> COLLECTION</span>
            <h1><?= e($seoCat['h1'] ?? $category['name']) ?></h1>
            <p><?= e($introText ?: 'Discover the ' . $category['name'] . ' collection — thoughtfully designed pieces for every occasion.') ?></p>
        </div>
    </div>
</section>

<!-- PRODUCTS -->
<section class="shop-section section-padding">
    <div class="container">

        <div class="shop-topbar">
            <div>
                <p class="shop-result-count">Showing <strong id="product-count"><?= (int) $result['total'] ?></strong> products</p>
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

        <div class="shop-layout">
            <aside class="filter-sidebar" id="filter-sidebar">
                <div class="filter-header">
                    <h2>Filters</h2>
                    <button type="button" class="filter-close" aria-label="Close filters">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="filter-group">
                    <h3>Category</h3>
                    <?php foreach ($cats as $cat): ?>
                        <label class="filter-option">
                            <input type="checkbox" name="category" value="<?= (int) $cat['id'] ?>"
                                   <?= (int) $cat['id'] === $categoryId || in_array((int) $cat['id'], $extraCatIds, true) ? 'checked' : '' ?>>
                            <span><?= e($cat['name']) ?> <em>(<?= (int) $cat['product_count'] ?>)</em></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="filter-group">
                    <h3>Price Range</h3>
                    <?php
                    $priceOptions = [
                        ''            => 'All Prices',
                        'under-5000'  => 'Under Rs 5,000',
                        '5000-10000'  => 'Rs 5,000 – Rs 10,000',
                        '10000-15000' => 'Rs 10,000 – Rs 15,000',
                        'over-15000'  => 'Above Rs 15,000',
                    ];
                    foreach ($priceOptions as $val => $label): ?>
                        <label class="filter-option">
                            <input type="radio" name="price" value="<?= e($val) ?>" <?= $price === $val ? 'checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

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

                <div class="filter-group">
                    <h3>Availability</h3>
                    <label class="filter-option">
                        <input type="checkbox" name="availability" value="in_stock" <?= $availability ? 'checked' : '' ?>>
                        <span>In Stock Only</span>
                    </label>
                </div>

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

            <div class="shop-products">
                <div class="shop-search">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" id="shop-search" placeholder="Search in <?= e($category['name']) ?>..."
                               value="<?= e($q) ?>">
                    </div>
                </div>

                <div class="product-grid shop-product-grid" id="shop-product-grid">
                    <?php echo render_products_grid($result['items']); ?>
                </div>

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
        categoryId: <?= (int) $categoryId ?>,
        page: <?= (int) $result['page'] ?>,
        pages: <?= (int) $result['pages'] ?>,
        total: <?= (int) $result['total'] ?>
    };
</script>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>