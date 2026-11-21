<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$slug = get_string('slug', 200);
$collection = $slug !== '' ? tc_collection($slug) : null;

if (!$collection) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$collectionId = (int) $collection['id'];
$products     = tc_collection_products($collectionId, 24);
$count        = (int) $collection['product_count'];

$page_title       = $collection['meta_title'] ?: $collection['name'];
$meta_description = $collection['meta_description']
    ?: mb_substr(strip_tags((string) ($collection['description'] ?? '')), 0, 155)
    ?: 'Shop the ' . $collection['name'] . ' at ' . setting('store_name') . '.';
$canonical        = collection_url($collection['slug']);
$active_nav       = 'collections.php';

$extra_schema = [
    ['@context' => 'https://schema.org', '@type' => 'CollectionPage',
     'name' => $collection['name'], 'url' => abs_url('/collection.php?slug=' . rawurlencode((string) $collection['slug'])),
     'description' => strip_tags((string) $meta_description),
     'isPartOf' => ['@type' => 'WebSite', 'name' => setting('store_name'), 'url' => abs_url('/index.php')]],
    ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => abs_url('/index.php')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Collections', 'item' => abs_url('/collections.php')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $collection['name'], 'item' => abs_url('/collection.php?slug=' . rawurlencode((string) $collection['slug']))],
    ]],
];

require __DIR__ . '/includes/storefront-header.php';
?>

<!-- HERO -->
<?php $heroImage = (string) ($collection['banner'] ?: $collection['image']); ?>
<section class="collection-hero<?= $heroImage !== '' ? ' has-image' : '' ?>"<?php if ($heroImage !== ''): ?> style="background-image: linear-gradient(rgba(28,28,28,.55), rgba(28,28,28,.55)), url('<?= e(image_url($heroImage)) ?>')"<?php endif; ?>>
    <div class="container collection-hero-content">
        <span class="eyebrow"><?= e(strtoupper(setting('store_name'))) ?> COLLECTION</span>
        <h1><?= e($collection['name']) ?></h1>
        <?php if (!empty($collection['description'])): ?>
            <p><?= e($collection['description']) ?></p>
        <?php endif; ?>
        <p class="collection-count"><?= (int) $count ?> piece<?= (int) $count === 1 ? '' : 's' ?></p>
    </div>
</section>

<!-- PRODUCTS -->
<section class="products-section section-padding">
    <div class="container">

        <?php if (!$products): ?>
            <div class="section-empty">
                <i class="fa-regular fa-sparkles"></i>
                <h3>This collection is being styled</h3>
                <p>New pieces are being added. Please check back shortly.</p>
                <a href="<?= url('/shop.php') ?>" class="btn btn-outline">Browse the Shop</a>
            </div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product) echo render_product_card($product); ?>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/includes/storefront-footer.php'; ?>