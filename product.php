<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$slug = get_string('slug', 200);
$product = $slug !== '' ? get_product($slug) : null;

if (!$product) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$images        = get_product_images((int) $product['id']);
$variants      = get_product_variants((int) $product['id']);
$categories    = get_product_categories((int) $product['id']);
$related       = related_products($product, 4);
$reviewSummary = tc_review_summary((int) $product['id']);
$reviews       = tc_product_reviews((int) $product['id'], 10);
$wished        = in_array((int) $product['id'], wishlist_ids(), true);

// Review submission (stored pending until an admin approves it).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['review_submit'] ?? '') === '1') {
    csrf_require($_POST['csrf_token'] ?? null);
    $result = tc_submit_review([
        'product_id' => (int) $product['id'],
        'name'       => post('review_name', 150),
        'email'      => post('review_email', 190),
        'rating'     => post_int('review_rating'),
        'title'      => post('review_title', 190),
        'body'       => post_text('review_body', 2000),
    ]);
    flash_set($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    redirect(url('/product.php?slug=' . urlencode($product['slug'])) . '#tab-reviews');
}

/* Recently viewed — a cookie list of real catalog slugs, capped at 8 and
   switchable off with the admin's enable_recently_viewed setting. */
$recentlyViewed = [];
if (setting('enable_recently_viewed', '1') === '1') {
    $rvSeen = array_values(array_filter(array_map('trim', explode(',', (string) ($_COOKIE['tc_recently_viewed'] ?? '')))));
    $rvSeen = array_values(array_filter($rvSeen, static fn(string $s): bool => $s !== '' && $s !== (string) $product['slug']));
    array_unshift($rvSeen, (string) $product['slug']);
    $rvSeen = array_slice(array_unique($rvSeen), 0, 8);

    if (!headers_sent()) {
        setcookie('tc_recently_viewed', implode(',', $rvSeen), [
            'expires'  => time() + 31536000,
            'path'     => BASE_URL !== '' ? BASE_URL : '/',
            'samesite' => 'Lax',
            'httponly' => true,
        ]);
    }

    foreach ($rvSeen as $rvSlug) {
        if (count($recentlyViewed) >= 8) {
            break;
        }
        $rvProduct = get_product($rvSlug);
        if ($rvProduct) {
            $recentlyViewed[] = $rvProduct;
        }
    }
}

$primaryImage = image_url($product['primary_image'] ?: ($images[0]['image'] ?? ''));
$outOfStock   = (int) $product['stock_quantity'] < 1;
$onSale       = product_has_sale($product);
$discountPct  = $onSale && (float) $product['price'] > 0
    ? (int) round(((float) $product['price'] - (float) $product['sale_price']) / (float) $product['price'] * 100)
    : 0;

$sizes = array_values(array_filter(array_map('trim', explode(',', (string) $product['size']))));
$colors = array_values(array_filter(array_map('trim', explode(',', (string) $product['color']))));

$page_title     = $product['name'] . " | Women's Fashion Pakistan";
$metaLead = trim(strip_tags((string) ($product['short_description'] ?: $product['description'])));
$metaLead = mb_strlen($metaLead) > 110 ? trim(mb_substr($metaLead, 0, 110)) : $metaLead;
$metaBits = $metaLead !== '' ? [rtrim($metaLead, '. ') . '.'] : [];
if ($product['fabric'] !== '') {
    $metaBits[] = $product['fabric'] . ' fabric.';
}
if ($categories) {
    $metaBits[] = $categories[0]['name'] . ' piece from ' . setting('store_name') . '.';
}
$meta_description = implode(' ', $metaBits);
$metaTail = ' Shop online in Pakistan with ' . (int) setting('exchange_policy_days', '7') . '-day easy exchange.';
if ($metaTail !== '' && mb_strlen($meta_description . $metaTail) <= 158) {
    $meta_description .= $metaTail;
}
$cutAt = mb_strrpos($meta_description, ' ');
if (mb_strlen($meta_description) > 158 && $cutAt !== false) {
    $meta_description = trim(mb_substr($meta_description, 0, $cutAt));
}
$canonical      = product_url($product['slug']);
$active_nav     = 'shop.php';
$og_image       = $primaryImage;

/* Real size guide from the admin's size_charts tables (never invented data). */
$sizeChart = null;
$sizeRows  = [];
if (tc_table_exists('size_charts') && tc_table_exists('size_chart_measurements')) {
    $scStmt = db()->prepare('SELECT * FROM size_charts WHERE status = 1 AND is_global = 1 ORDER BY sort_order, id LIMIT 1');
    $scStmt->execute();
    $sizeChart = $scStmt->fetch() ?: null;
    if ($sizeChart) {
        $srStmt = db()->prepare('SELECT * FROM size_chart_measurements WHERE size_chart_id = ? ORDER BY sort_order, id');
        $srStmt->execute([(int) $sizeChart['id']]);
        $sizeRows = $srStmt->fetchAll();
    }
}
$sizeGuideUrl = $sizeRows ? '#size-guide' : '';

// Structured data — real product data only, no fabricated ratings/reviews.
$product_schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => (string) $product['name'],
    'image'       => $primaryImage,
    'description' => $meta_description !== '' ? $meta_description : mb_substr(strip_tags((string) $product['description']), 0, 155),
    'sku'         => (string) $product['sku'],
    'brand'       => ['@type' => 'Brand', 'name' => (string) setting('brand_name', setting('store_name', 'Fashlab Studio'))],
    'offers'      => [
        '@type'             => 'Offer',
        'url'               => abs_url('/product.php?slug=' . rawurlencode((string) $product['slug'])),
        'priceCurrency'     => 'PKR',
        'price'             => (string) ($onSale ? $product['sale_price'] : $product['price']),
        'availability'      => $outOfStock ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
        'itemCondition'     => 'https://schema.org/NewCondition',
    ],
];
if ($images) {
    $product_schema['image'] = array_values(array_map(static fn($im): string => image_url($im['image'] ?? ''), $images));
}

/* Apparel variants → ProductGroup (only when the product really has variants). */
$group_schema = null;
if ($variants) {
    $variesBy  = [];
    $hasVariants = [];
    foreach ($variants as $v) {
        $vName = trim((string) $v['variant_name']);
        if ($vName !== '' && !in_array($vName, $variesBy, true)) {
            $lower = strtolower($vName);
            $variesBy[] = str_contains($lower, 'size') ? 'https://schema.org/Size'
                : (str_contains($lower, 'color') || str_contains($lower, 'colour') ? 'https://schema.org/Color' : 'https://schema.org/PropertyValue');
        }
        $vLabel = trim($vName . ' ' . (string) $v['variant_value']);
        $hasVariants[] = [
            '@type'     => 'Product',
            'name'      => (string) $product['name'] . ($vLabel !== '' ? ' — ' . $vLabel : ''),
            'sku'       => (string) $v['sku'] !== '' ? (string) $v['sku'] : ((string) $product['sku'] . '-' . (int) $v['id']),
            'image'     => $primaryImage,
            'offers'    => [
                '@type'         => 'Offer',
                'price'         => (string) effective_price($product, (float) $v['price_adjustment']),
                'priceCurrency' => 'PKR',
                'availability'  => (int) $v['stock_quantity'] < 1 ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
                'url'           => abs_url('/product.php?slug=' . rawurlencode((string) $product['slug'])),
            ],
        ];
    }
    $group_schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'ProductGroup',
        'name'          => (string) $product['name'],
        'productGroupID' => (string) ($product['sku'] !== '' ? $product['sku'] : 'product-' . (int) $product['id']),
        'url'           => abs_url('/product.php?slug=' . rawurlencode((string) $product['slug'])),
        'description'   => $meta_description,
        'variesBy'      => array_values(array_unique($variesBy ?: ['https://schema.org/PropertyValue'])),
        'hasVariant'    => $hasVariants,
    ];
}

$breadcrumb_schema = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => abs_url('/index.php')],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop', 'item' => abs_url('/shop.php')],
]];
$bp = 3;
foreach ($categories as $cat) {
    $breadcrumb_schema['itemListElement'][] = ['@type' => 'ListItem', 'position' => $bp++, 'name' => $cat['name'], 'item' => abs_url('/category.php?slug=' . rawurlencode((string) $cat['slug']))];
}
$breadcrumb_schema['itemListElement'][] = ['@type' => 'ListItem', 'position' => $bp, 'name' => (string) $product['name']];

$extra_schema = array_values(array_filter([$product_schema, $group_schema, $breadcrumb_schema]));

require __DIR__ . '/includes/storefront-header.php';
?>

<!-- BREADCRUMB -->
<section class="breadcrumb-section">
    <div class="container">
        <div class="breadcrumb">
            <a href="<?= url('/index.php') ?>">Home</a>
            <span>/</span>
            <a href="<?= url('/shop.php') ?>">Shop</a>
            <?php if ($categories): ?>
                <span>/</span>
                <a href="<?= e(category_url($categories[0]['slug'])) ?>"><?= e($categories[0]['name']) ?></a>
            <?php endif; ?>
            <span>/</span>
            <span><?= e($product['name']) ?></span>
        </div>
    </div>
</section>

<!-- PRODUCT DETAILS -->
<section class="product-details-section">
    <div class="container product-details-wrapper">

        <!-- LEFT - GALLERY -->
        <div class="product-gallery">
            <div class="product-thumbnails">
                <?php foreach ($images as $i => $img): ?>
                    <button class="product-thumb<?= $i === 0 ? ' active' : '' ?>" type="button"
                            data-image="<?= e(image_url($img['image'] ?? '')) ?>">
                        <img src="<?= e(image_url($img['image'] ?? '')) ?>" alt="<?= e($product['name']) ?> view <?= (int) ($i + 1) ?>" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="product-main-image" id="productImageContainer">
                <img id="mainProductImage" src="<?= e($primaryImage) ?>" alt="<?= e(setting('brand_name', setting('store_name')) . ' ' . $product['name']) ?>">
                <div class="zoom-hint"><i class="fa-solid fa-magnifying-glass-plus"></i> Hover to zoom</div>
            </div>
        </div>

        <!-- RIGHT - INFORMATION -->
        <div class="product-information">

            <?php if ($categories): ?>
                <a class="product-category" href="<?= e(category_url($categories[0]['slug'])) ?>"><?= e($categories[0]['name']) ?></a>
            <?php endif; ?>

            <h1 class="product-title"><?= e($product['name']) ?></h1>

            <div class="product-rating">
                <div class="stars">
                    <?php if ($reviewSummary['count'] > 0): ?>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fa-<?= $i <= (int) round($reviewSummary['avg']) ? 'solid' : 'regular' ?> fa-star"></i>
                        <?php endfor; ?>
                    <?php else: ?>
                        <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa-solid fa-star"></i><?php endfor; ?>
                    <?php endif; ?>
                </div>
                <span>
                    <?php if ($reviewSummary['count'] > 0): ?>
                        <?= $reviewSummary['avg'] ?> (<?= (int) $reviewSummary['count'] ?> review<?= (int) $reviewSummary['count'] === 1 ? '' : 's' ?>)
                    <?php else: ?>
                        Premium <?= e($categories[0]['name'] ?? setting('store_name')) ?> piece
                    <?php endif; ?>
                </span>
            </div>

            <div class="product-price">
                <span class="current-price"><?= money(effective_price($product)) ?></span>
                <?php if ($onSale): ?>
                    <span class="old-price"><?= money((float) $product['price']) ?></span>
                    <span class="discount"><?= (int) $discountPct ?>% OFF</span>
                <?php endif; ?>
            </div>

            <p class="product-short-description">
                <?= e($product['short_description'] ?: 'A beautifully crafted contemporary outfit designed with delicate detailing and a comfortable silhouette.') ?>
            </p>

            <?php if ($product['sku'] !== ''): ?>
            <p class="product-sku">SKU: <strong><?= e($product['sku']) ?></strong></p>
            <?php endif; ?>

            <div class="product-divider"></div>

            <!-- Variants -->
            <?php if ($variants): ?>
                <div class="product-option">
                    <div class="option-heading"><strong>Available Options</strong></div>
                    <select class="variant-select" id="variantSelect" data-product-id="<?= (int) $product['id'] ?>">
                        <?php foreach ($variants as $v): ?>
                            <option value="<?= (int) $v['id'] ?>"
                                    data-price="<?= effective_price($product, (float) $v['price_adjustment']) ?>"
                                    data-stock="<?= (int) $v['stock_quantity'] ?>"
                                    <?= (int) $v['stock_quantity'] < 1 ? 'disabled' : '' ?>>
                                <?= e(trim($v['variant_name'] . ' — ' . $v['variant_value'], ' —')) ?>
                                <?= (float) $v['price_adjustment'] != 0 ? ' (+' . money((float) $v['price_adjustment']) . ')' : '' ?>
                                <?= (int) $v['stock_quantity'] < 1 ? ' (Sold out)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <!-- Color -->
            <?php if ($colors): ?>
                <div class="product-option">
                    <div class="option-heading"><strong>Color</strong> <span id="selectedColor"><?= e($colors[0]) ?></span></div>
                    <div class="color-options">
                        <?php foreach ($colors as $ci => $col): ?>
                            <button type="button"
                                    class="color-option<?= $ci === 0 ? ' active' : '' ?> color-swatch"
                                    data-color="<?= e($col) ?>"
                                    style="background-color: <?= e(strtolower($col) === 'black' ? '#1a1a1a' : (strtolower($col) === 'white' ? '#f4f4f4' : '#882038')) ?>"
                                    aria-label="<?= e($col) ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Size -->
            <?php if ($sizes): ?>
                <div class="product-option">
                    <div class="option-heading"><strong>Size</strong> <?php if ($sizeRows): ?><a href="#size-guide" data-size-guide>Size Guide</a><?php endif; ?></div>
                    <div class="size-options">
                        <?php foreach ($sizes as $si => $sz): ?>
                            <button type="button" class="size-option<?= $si === 0 ? ' active' : '' ?>"><?= e($sz) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quantity -->
            <div class="product-option">
                <div class="option-heading"><strong>Quantity</strong></div>
                <div class="quantity-selector">
                    <button type="button" class="qty-btn" data-qty="-1" aria-label="Decrease quantity">−</button>
                    <span id="quantity">1</span>
                    <button type="button" class="qty-btn" data-qty="1" aria-label="Increase quantity">+</button>
                </div>
            </div>

            <!-- Stock status -->
            <p class="stock-status <?= $outOfStock ? 'out' : 'in' ?>">
                <i class="fa-solid <?= $outOfStock ? 'fa-circle-xmark' : 'fa-circle-check' ?>"></i>
                <?= $outOfStock ? 'Currently out of stock' : 'In stock — ' . (int) $product['stock_quantity'] . ' available' ?>
            </p>

            <!-- Add To Cart -->
            <button type="button" class="btn btn-primary product-add-cart"
                    data-product-id="<?= (int) $product['id'] ?>"
                    data-product-name="<?= e($product['name']) ?>"
                    <?= $outOfStock ? 'disabled' : '' ?>>
                <i class="fa-solid fa-bag-shopping"></i> ADD TO CART
            </button>

            <!-- Buy Now -->
            <button type="button" class="btn btn-outline product-buy-now"
                    data-product-id="<?= (int) $product['id'] ?>"
                    <?= $outOfStock ? 'disabled' : '' ?>>
                BUY NOW
            </button>

            <!-- Order on WhatsApp (message built client-side, never auto-sent) -->
            <a class="btn btn-wa" data-wa-order href="<?= e('https://wa.me/' . preg_replace('/[^0-9]/', '', (string) setting('whatsapp_number', ''))) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-whatsapp"></i> ORDER ON WHATSAPP
            </a>

            <!-- Wishlist -->
            <button type="button" class="btn btn-outline product-wishlist<?= $wished ? ' active' : '' ?>"
                    data-product-id="<?= (int) $product['id'] ?>"
                    data-added="<?= $wished ? '1' : '0' ?>"
                    aria-pressed="<?= $wished ? 'true' : 'false' ?>">
                <i class="fa-<?= $wished ? 'solid' : 'regular' ?> fa-heart"></i>
                <span><?= $wished ? 'In Wishlist' : 'Wishlist' ?></span>
            </button>

            <!-- Share -->
            <button type="button" class="btn btn-outline product-share" data-share-product aria-label="Share this product">
                <i class="fa-solid fa-arrow-up-from-bracket"></i>
                <span>SHARE</span>
            </button>

            <!-- Product Benefits -->
            <div class="product-benefits">
                <div class="benefit-item">
                    <i class="fa-solid fa-truck"></i>
                    <div><strong>Free Shipping</strong><span>On orders above <?= money(setting('free_shipping_threshold', '8000')) ?></span></div>
                </div>
                <div class="benefit-item">
                    <i class="fa-solid fa-rotate-left"></i>
                    <div><strong>Easy Returns</strong><span>7 days return &amp; exchange</span></div>
                </div>
                <div class="benefit-item">
                    <i class="fa-solid fa-shield-halved"></i>
                    <div><strong>Secure Payment</strong><span>Safe &amp; secure checkout</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- SIZE GUIDE (real admin size-chart data) -->
<?php if ($sizeRows): ?>
<div class="lx-sizeguide" id="size-guide" role="dialog" aria-modal="true" aria-labelledby="sizeGuideTitle" hidden>
    <div class="lx-sizeguide-card">
        <button type="button" class="lx-sizeguide-close" aria-label="Close size guide" data-size-guide-close><i class="fa-solid fa-xmark"></i></button>
        <p class="section-label">SIZE GUIDE</p>
        <h3 id="sizeGuideTitle"><?= e((string) ($sizeChart['name'] ?? 'Size Guide')) ?></h3>
        <?php if (!empty($sizeChart['description'])): ?>
            <p class="lx-sizeguide-desc"><?= e((string) $sizeChart['description']) ?></p>
        <?php endif; ?>
        <div class="lx-sizeguide-scroll">
            <table class="lx-sizeguide-table">
                <thead>
                    <tr><th scope="col">Size</th><th scope="col">Chest (cm)</th><th scope="col">Waist (cm)</th><th scope="col">Hip (cm)</th><th scope="col">Shoulder (cm)</th><th scope="col">Length (cm)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($sizeRows as $row): ?>
                    <tr>
                        <th scope="row"><?= e((string) $row['size_label']) ?></th>
                        <td><?= (int) (float) $row['chest_cm'] ?></td>
                        <td><?= (int) (float) $row['waist_cm'] ?></td>
                        <td><?= (int) (float) $row['hip_cm'] ?></td>
                        <td><?= (int) (float) $row['shoulder_cm'] ?></td>
                        <td><?= (int) (float) $row['length_cm'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="lx-sizeguide-note">All measurements are garment measurements in centimetres. For the best fit, compare with a similar piece you already own.</p>
    </div>
</div>
<?php endif; ?>

<!-- PRODUCT INFORMATION TABS -->
<section class="product-information-section">
    <div class="container">
        <div class="product-tabs">
            <button class="product-tab active" type="button" data-tab="description">Why You'll Love It</button>
            <button class="product-tab" type="button" data-tab="fabric">Fabric &amp; Care</button>
            <button class="product-tab" type="button" data-tab="details">Product Details</button>
            <button class="product-tab" type="button" data-tab="shipping">Delivery &amp; Exchange</button>
            <button class="product-tab" type="button" data-tab="reviews">Reviews</button>
        </div>

        <div class="product-tab-content active" id="tab-description">
            <h3>Why You'll Love It</h3>
            <ul class="details-list">
                <?php if ($product['fabric'] !== ''): ?><li>Cut from <strong><?= e($product['fabric']) ?></strong> for all-day comfort</li><?php endif; ?>
                <?php if ($categories): ?><li><?= e($categories[0]['name']) ?> silhouette from <?= e(setting('brand_name', setting('store_name', 'Fashlab Studio'))) ?></li><?php endif; ?>
                <?php if ($sizes): ?><li>Available in <?= e(implode(', ', $sizes)) ?></li><?php endif; ?>
                <?php if ($colors): ?><li>Colour options: <?= e(implode(', ', $colors)) ?></li><?php endif; ?>
                <li><?= $outOfStock ? 'Currently out of stock' : 'In stock and ready to ship' ?></li>
            </ul>
            <h3>About this piece</h3>
            <p><?= nl2br(e($product['description'] ?: 'No additional description available.')) ?></p>
        </div>

        <div class="product-tab-content" id="tab-fabric">
            <h3>Fabric &amp; Care</h3>
            <ul class="details-list">
                <?php if ($product['fabric'] !== ''): ?><li><strong>Fabric:</strong> <?= e($product['fabric']) ?></li><?php endif; ?>
                <?php if ($product['color'] !== ''): ?><li><strong>Colour:</strong> <?= e($product['color']) ?></li><?php endif; ?>
                <li><strong>Care:</strong> Gentle hand wash or dry clean. Do not bleach. Dry in shade and iron on low heat.</li>
            </ul>
        </div>

        <div class="product-tab-content" id="tab-details">
            <h3>Product Details</h3>
            <ul class="details-list">
                <?php if ($product['sku'] !== ''): ?><li><strong>SKU:</strong> <?= e($product['sku']) ?></li><?php endif; ?>
                <?php if ($product['product_type'] !== ''): ?><li><strong>Type:</strong> <?= e($product['product_type']) ?></li><?php endif; ?>
                <?php if ($product['fabric'] !== ''): ?><li><strong>Fabric:</strong> <?= e($product['fabric']) ?></li><?php endif; ?>
                <?php if ($product['color'] !== ''): ?><li><strong>Color:</strong> <?= e($product['color']) ?></li><?php endif; ?>
                <?php if ($product['size'] !== ''): ?><li><strong>Size:</strong> <?= e($product['size']) ?></li><?php endif; ?>
                <?php if ($categories): ?><li><strong>Category:</strong> <?= e(implode(', ', array_column($categories, 'name'))) ?></li><?php endif; ?>
                <li><strong>Availability:</strong> <?= $outOfStock ? 'Out of stock' : 'In stock (' . (int) $product['stock_quantity'] . ' pieces)' ?></li>
            </ul>
        </div>

        <div class="product-tab-content" id="tab-shipping">
            <h3>Shipping &amp; Returns</h3>
            <p>Orders are delivered within 3–5 working days across Pakistan. Delivery is free on orders above <?= money(setting('free_shipping_threshold', '8000')) ?>; otherwise a flat shipping fee of <?= money(setting('shipping_fee', '250')) ?> applies. We offer a 7-day return and exchange policy on unworn items.</p>
        </div>

        <div class="product-tab-content" id="tab-reviews">
            <h3>Customer Reviews</h3>

            <?php if (!tc_table_exists('reviews')): ?>
                <p>Reviews are coming soon. In the meantime, feel free to <a href="<?= url('/contact.php') ?>">contact us</a> with any questions about this piece.</p>
            <?php else: ?>

            <div class="reviews-summary">
                <div class="reviews-score">
                    <strong><?= $reviewSummary['count'] > 0 ? $reviewSummary['avg'] : '—' ?></strong>
                    <div class="stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fa-<?= $i <= (int) round($reviewSummary['avg']) ? 'solid' : 'regular' ?> fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <span><?= (int) $reviewSummary['count'] ?> review<?= (int) $reviewSummary['count'] === 1 ? '' : 's' ?></span>
                </div>
                <div class="reviews-bars">
                    <?php foreach ([5, 4, 3, 2, 1] as $star): ?>
                        <?php
                        $n = $reviewSummary['breakdown'][$star] ?? 0;
                        $pct = $reviewSummary['count'] > 0 ? (int) round($n / $reviewSummary['count'] * 100) : 0;
                        ?>
                        <div class="review-bar">
                            <span><?= $star ?> <i class="fa-solid fa-star"></i></span>
                            <div class="review-bar-track"><span style="width: <?= $pct ?>%"></span></div>
                            <em><?= $n ?></em>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($reviews): ?>
                <div class="reviews-list">
                    <?php foreach ($reviews as $review): ?>
                        <article class="review-entry">
                            <div class="review-entry-head">
                                <div class="review-entry-avatar"><?= e(mb_strtoupper(mb_substr($review['name'], 0, 1))) ?></div>
                                <div>
                                    <strong><?= e($review['name']) ?></strong>
                                    <?php if ((int) $review['is_verified_purchase'] === 1): ?>
                                        <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> Verified Purchase</span>
                                    <?php endif; ?>
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fa-<?= $i <= (int) $review['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <time><?= e(format_date($review['created_at'])) ?></time>
                            </div>
                            <?php if ($review['title'] !== ''): ?><h4><?= e($review['title']) ?></h4><?php endif; ?>
                            <p><?= nl2br(e($review['body'])) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="reviews-none">No reviews yet — be the first to review this piece.</p>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/product.php?slug=' . urlencode($product['slug']))) ?>#tab-reviews" class="review-form" id="reviewForm">
                <?= csrf_field() ?>
                <h4>Write a Review</h4>
                <input type="hidden" name="review_submit" value="1">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="review_name">Name <span class="required">*</span></label>
                        <input type="text" id="review_name" name="review_name" required>
                    </div>
                    <div class="form-group">
                        <label for="review_email">Email <span class="required">*</span></label>
                        <input type="email" id="review_email" name="review_email" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="review_rating">Rating <span class="required">*</span></label>
                        <select id="review_rating" name="review_rating" required>
                            <option value="">Select…</option>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?= $i ?>"><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="review_title">Title</label>
                        <input type="text" id="review_title" name="review_title" maxlength="190">
                    </div>
                </div>
                <div class="form-group">
                    <label for="review_body">Your Review <span class="required">*</span></label>
                    <textarea id="review_body" name="review_body" rows="4" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Submit Review</button>
            </form>

            <?php endif; ?>
        </div>
    </div>
</section>

<!-- RELATED PRODUCTS -->
<?php if ($related): ?>
<section class="section-padding related-products">
    <div class="container">
        <div class="section-top">
            <div class="section-heading left">
                <p class="section-label">YOU MAY ALSO LIKE</p>
                <h2>Complete The Look</h2>
            </div>
            <a href="<?= url('/shop.php') ?>" class="text-link">View All <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="product-grid">
            <?php foreach ($related as $rel) echo render_product_card($rel); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($recentlyViewed): ?>
<section class="section-padding recently-viewed">
    <div class="container">
        <div class="section-top">
            <div class="section-heading left">
                <p class="section-label">RECENTLY VIEWED</p>
                <h2>Seen Recently</h2>
            </div>
        </div>
        <div class="product-grid">
            <?php foreach ($recentlyViewed as $rv) echo render_product_card($rv); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<script>
    window.TC_PRODUCT = {
        id: <?= (int) $product['id'] ?>,
        name: <?= json_encode($product['name'], JSON_UNESCAPED_UNICODE) ?>,
        image: <?= json_encode(image_url($product['primary_image'] ?? ''), JSON_UNESCAPED_SLASHES) ?>,
        price: <?= effective_price($product) ?>,
        inStock: <?= $outOfStock ? 'false' : 'true' ?>,
        maxStock: <?= (int) $product['stock_quantity'] ?>
    };
</script>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>