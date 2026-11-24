<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/* Homepage SEO — brand + broad commercial intent (one primary topic, no stuffing). */
$full_title       = setting('store_name') . " | Women's Clothing & Fashion Online in Pakistan";
$page_title       = setting('store_tagline', 'Where Style Meets Elegance');
$meta_description = "Shop women's clothing online in Pakistan with " . setting('store_name')
    . '. Explore stitched and unstitched suits, lawn, formal wear, casual styles and new fashion collections.';
$active_nav       = 'index.php';

$homeCategories = categories_with_counts(8);
$newIn          = new_arrivals(10);
$bestSellers    = best_sellers(8);
$saleItems      = sale_products(10);
$heroSlides     = tc_hero_slides();

/* Shop-by-fabric chips + occasion links (real categories only) */
$facets       = product_facets();
$homeFabrics  = array_slice($facets['fabrics'] ?? [], 0, 10);
$homeOccasions = [];
foreach (['formal-wear', 'casual-wear', 'festive-collection', 'eid-collection', 'luxury-collection', 'eastern-wear'] as $occSlug) {
    $occ = get_category_by_slug($occSlug);
    if ($occ && (int) $occ['status'] === 1) {
        $homeOccasions[] = $occ;
    }
}

/* Floating hero cards — real products, never invented data */
$heroCards = [];
foreach (array_slice($newIn, 0, 3) as $hp) {
    $heroCards[] = [
        'name'     => $hp['name'],
        'price'    => money(effective_price($hp)),
        'old'      => product_has_sale($hp) ? money((float) $hp['price']) : '',
        'image'    => image_url($hp['primary_image'] ?? ''),
        'cat'      => $hp['category_names'] !== null && $hp['category_names'] !== ''
                        ? explode(', ', (string) $hp['category_names'])[0]
                        : setting('store_name'),
        'url'      => product_url($hp['slug']),
    ];
}

function section_empty(string $label): string
{
    return '<div class="section-empty">
        <i class="fa-regular fa-sparkles"></i>
        <h3>Coming soon</h3>
        <p>New ' . e($label) . ' pieces are being prepared. Please check back shortly.</p>
    </div>';
}

require __DIR__ . '/includes/storefront-header.php';
?>

<?php
/* Hero copy: admin-managed slide when present, brand default otherwise. */
$hero = $heroSlides ? $heroSlides[0] : null;
$heroEyebrow   = !empty($hero['eyebrow'])   ? $hero['eyebrow']   : 'NEW SEASON';
$heroTitle     = !empty($hero['title'])     ? $hero['title']     : 'The Signature Edit';
$heroSubtitle  = !empty($hero['subtitle'])  ? $hero['subtitle']  : 'Timeless silhouettes. Contemporary elegance.';
$heroCta1      = !empty($hero['cta_text'])           ? $hero['cta_text']           : 'SHOP NEW ARRIVALS';
$heroCta1Link  = !empty($hero['cta_link'])           ? tc_menu_url(['url' => $hero['cta_link']]) : url('/shop.php?sort=newest');
$heroCta2      = !empty($hero['cta_secondary_text']) ? $hero['cta_secondary_text'] : 'EXPLORE COLLECTION';
$heroCta2Link  = !empty($hero['cta_secondary_link']) ? tc_menu_url(['url' => $hero['cta_secondary_link']]) : url('/collections.php');
$heroImage     = image_url($hero['image'] ?? '');
if ($heroImage === '' && $newIn) {
    $heroImage = image_url($newIn[0]['primary_image'] ?? '');
}
$freeOver   = (float) setting('free_shipping_threshold', '8000');
?>

<!-- HERO — compact banner, products start right below -->
<section class="fx-hero" aria-label="Featured collection">
    <canvas class="fx-hero-3d" data-fx-hero3d aria-hidden="true"></canvas>
    <div class="container fx-hero-inner">
        <div class="fx-hero-copy">
            <p class="fx-hero-eyebrow"><?= e($heroEyebrow) ?></p>
            <h1 class="fx-hero-title">Women's Fashion, Gifts &amp; Surprises <span>Online in Pakistan</span></h1>
            <p class="fx-hero-sub"><?= e($heroSubtitle) ?></p>
            <div class="fx-hero-cta">
                <a href="<?= e($heroCta1Link) ?>" class="fx-btn fx-btn-primary"><?= e($heroCta1) ?></a>
                <a href="<?= e(url('/shop.php?dept=gifts')) ?>" class="fx-btn fx-btn-ghost">Shop Gifts</a>
            </div>
            <ul class="fx-hero-trust">
                <li><i class="fa-solid fa-truck-fast"></i> Free delivery over <?= e(money($freeOver)) ?></li>
                <li><i class="fa-solid fa-hand-holding-dollar"></i> Cash on delivery</li>
                <li><i class="fa-solid fa-rotate-left"></i> <?= (int) setting('exchange_policy_days', '7') ?>-day exchange</li>
            </ul>
        </div>
        <?php
        /* Newest gift products (real data) for the hero carousel. */
        $giftCatIds = [];
        foreach (categories_with_counts() as $gc) {
            if ((int) $gc['product_count'] > 0 && preg_match('/nikkah|\bpens?\b|frames?\b|birthday|bouquet|gift|basket|hamper|surprise|\bbox|chocolate|snack|flower|gajr|couple/i', $gc['name'] . ' ' . $gc['slug'])) {
                $giftCatIds[] = (int) $gc['id'];
            }
        }
        $heroGifts = $giftCatIds ? (get_products(['category_ids' => $giftCatIds, 'sort' => 'newest', 'per_page' => 12])['items'] ?? []) : [];
        $heroGifts = array_values(array_filter($heroGifts, static fn(array $p): bool => !empty($p['primary_image'])));
        ?>
        <?php if ($heroGifts): ?>
        <div class="fx-hero-carousel" data-fx-carousel aria-roledescription="carousel" aria-label="New gifts">
            <div class="fx-hc-track">
                <?php foreach ($heroGifts as $i => $g): ?>
                <a class="fx-hc-slide<?= $i === 0 ? ' is-active' : '' ?>" href="<?= e(product_url($g['slug'])) ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>>
                    <img src="<?= e(image_url($g['primary_image'])) ?>" alt="<?= e($g['name']) ?>" width="600" height="750" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
                    <span class="fx-hc-cap"><span class="fx-hc-tag">New Gift</span><strong><?= e($g['name']) ?></strong><em><?= e(money((float) (($g['sale_price'] ?? 0) > 0 ? $g['sale_price'] : $g['price']))) ?></em></span>
                </a>
                <?php endforeach; ?>
            </div>
            <button type="button" class="fx-hc-nav fx-hc-prev" aria-label="Previous gift"><i class="fa-solid fa-chevron-left"></i></button>
            <button type="button" class="fx-hc-nav fx-hc-next" aria-label="Next gift"><i class="fa-solid fa-chevron-right"></i></button>
            <div class="fx-hc-dots"><?php foreach ($heroGifts as $i => $g): ?><button type="button" aria-label="Gift <?= $i + 1 ?>"<?= $i === 0 ? ' class="is-active"' : '' ?>></button><?php endforeach; ?></div>
        </div>
        <?php elseif ($heroImage !== ''): ?>
        <a class="fx-hero-media" href="<?= e($heroCta1Link) ?>">
            <img src="<?= e($heroImage) ?>" alt="<?= e($heroTitle) ?> — <?= e(setting('store_name')) ?>" fetchpriority="high" width="900" height="600">
        </a>
        <?php endif; ?>
    </div>
</section>

<?php
/* Category circles + one product row per category (real data only). */
$homeCats = array_values(array_filter(categories_with_counts(), static fn(array $c): bool => (int) $c['product_count'] > 0));
$railCats = array_values(array_filter($homeCats, static fn(array $c): bool => (int) $c['product_count'] >= 2));
usort($railCats, static fn(array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
$railCats = array_slice($railCats, 0, 30);
$shownIds = [];
?>

<?php if ($homeCats): ?>
<section class="fx-cats" aria-label="Shop by category">
    <div class="container">
        <div class="fx-cats-row">
            <?php foreach ($homeCats as $cat): ?>
            <a class="fx-cat" href="<?= e(category_url($cat['slug'])) ?>">
                <span class="fx-cat-img"><img src="<?= e(image_url($cat['image'] ?? '')) ?>" alt="<?= e($cat['name']) ?>" loading="lazy" width="120" height="120"></span>
                <span class="fx-cat-name"><?= e($cat['name']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($newIn): ?>
<section class="fx-rail" aria-labelledby="rail-new">
    <div class="container">
        <div class="fx-rail-head">
            <h2 id="rail-new">New Arrivals</h2>
            <a href="<?= url('/shop.php?sort=newest') ?>" class="fx-rail-all">View all <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="product-grid fx-grid">
            <?php foreach ($newIn as $product) echo render_product_card($product); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php foreach ($railCats as $i => $cat):
    $items = get_products(['category_id' => (int) $cat['id'], 'sort' => 'newest', 'per_page' => 10, 'page' => 1])['items'];
    if (!$items) continue; ?>
<section class="fx-rail" aria-labelledby="rail-<?= (int) $cat['id'] ?>">
    <div class="container">
        <div class="fx-rail-head">
            <h2 id="rail-<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></h2>
            <a href="<?= e(category_url($cat['slug'])) ?>" class="fx-rail-all">View all <?= (int) $cat['product_count'] ?> <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="product-grid fx-grid">
            <?php foreach ($items as $product) echo render_product_card($product); ?>
        </div>
    </div>
</section>
<?php endforeach; ?>

<?php if ($saleItems): ?>
<section class="fx-rail" aria-labelledby="rail-sale">
    <div class="container">
        <div class="fx-rail-head">
            <h2 id="rail-sale">On Sale</h2>
            <a href="<?= url('/shop.php?sale=1') ?>" class="fx-rail-all">View all <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="product-grid fx-grid">
            <?php foreach ($saleItems as $product) echo render_product_card($product); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<script src="<?= e(asset_url('assets/js/vendor/three.min.js')) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/hero3d.js')) ?>" defer></script>
<?php require __DIR__ . '/includes/storefront-footer.php'; ?>