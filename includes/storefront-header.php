<?php
/**
 * Storefront header partial.
 * Expected variables (optional): $page_title, $meta_description, $canonical, $active_nav
 */
declare(strict_types=1);

$page_title      = $page_title      ?? '';
$meta_description = $meta_description ?? setting('meta_description', '');
$canonical       = $canonical       ?? abs_current();
$extra_schema    = $extra_schema    ?? [];
$full_title      = $full_title      ?? '';   // page-controlled <title>, wins over the default pattern
$robots_noindex  = $robots_noindex  ?? false; // set by search-result / account pages

// Canonical / Open Graph URLs must be absolute; page templates pass path-absolute
// or already-absolute values, so only a missing origin is filled in here.
$origin = site_origin();
if ($origin !== '' && is_string($canonical) && str_starts_with($canonical, '/')) {
    $canonical = $origin . $canonical;
}
if ($origin !== '' && isset($og_image) && is_string($og_image) && str_starts_with($og_image, '/')) {
    $og_image = $origin . $og_image;
}
$active_nav      = $active_nav      ?? '';

$storeName  = setting('store_name');
$fullTitle  = $page_title !== ''
    ? (str_contains($page_title, $storeName) ? $page_title : $page_title . ' | ' . $storeName)
    : $storeName;
if ($full_title !== '') {
    $fullTitle = $full_title;
}

$cartCount = cart_count();
$wishCount = wishlist_count();
$og_image   = $og_image ?? abs_url('/images/brand/logo-square.png');

/* Rotating announcement messages — first entry is the real admin-managed bar.
   The rest are factual store policies (exchange days, WhatsApp, season). */
$announcementMessages = [];
if (setting('announcement_bar') !== '') {
    $announcementMessages[] = setting('announcement_bar');
}
$announcementMessages[] = '7-Day Easy Exchange';
$announcementMessages[] = 'Order on WhatsApp — ' . setting('whatsapp_number', '+92 334 232 2324');
$announcementMessages[] = 'New Season Now Live';
$announcementMessages = array_values(array_unique(array_filter($announcementMessages)));

// Main navigation from menu_items (migration table) when available, otherwise
// fall back to the original static navigation so nothing breaks pre-migration.
$mainNav = tc_render_main_nav($active_nav);
if ($mainNav === '') {
    $navItem = static fn(string $key, string $label): string =>
        '<a href="' . url('/' . $key) . '"' . ($active_nav === $key ? ' class="active"' : '') . '>' . e($label) . '</a>';

    $navCategories = get_categories(true, true);
    $dropdownHtml = '';
    if ($navCategories) {
        $dropdownLinks = '<a href="' . url('/collections.php') . '">All Collections</a>';
        foreach ($navCategories as $cat) {
            $dropdownLinks .= '<a href="' . e(category_url($cat['slug'])) . '">' . e($cat['name']) . '</a>';
        }
        $dropdownHtml = '<div class="nav-dropdown">
            <button type="button" class="nav-dropdown-toggle" aria-expanded="false">
                Collections <i class="fa-solid fa-chevron-down"></i>
            </button>
            <div class="nav-dropdown-menu">' . $dropdownLinks . '</div>
        </div>';
    }

    $mainNav = $navItem('index.php', 'Home')
             . $navItem('shop.php', 'Shop')
             . $dropdownHtml
             . $navItem('collections.php', 'Collections')
             . $navItem('about.php', 'About')
             . $navItem('contact.php', 'Contact');
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($fullTitle) ?></title>
    <meta name="description" content="<?= e($meta_description) ?>">
    <?php if ($robots_noindex): ?>
    <meta name="robots" content="noindex, follow">
    <?php endif; ?>
    <meta name="author" content="<?= e($storeName) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">

    <!-- Favicons -->
    <link rel="icon" href="<?= e(asset_url('images/brand/favicon.ico')) ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset_url('images/brand/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= e(asset_url('images/brand/favicon-16.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset_url('images/brand/favicon-180.png')) ?>">
    <meta name="theme-color" content="#5e0b27">

    <!-- Social preview -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($storeName) ?>">
    <meta property="og:title" content="<?= e($fullTitle) ?>">
    <meta property="og:description" content="<?= e($meta_description) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:image" content="<?= e($og_image) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($fullTitle) ?>">
    <meta name="twitter:description" content="<?= e($meta_description) ?>">
    <meta name="twitter:image" content="<?= e($og_image) ?>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Main CSS -->
    <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('css/premium.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('css/dynamic.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('css/luxury.css')) ?>">

    <script>
        window.TC_SETTINGS = {
            baseUrl: <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>,
            currencySymbol: <?= json_encode(setting('currency_symbol', 'Rs.'), JSON_UNESCAPED_UNICODE) ?>,
            searchUrl: <?= json_encode(url('/api/v1/index.php?_route=search/suggest&q='), JSON_UNESCAPED_SLASHES) ?>,
            shopUrl: <?= json_encode(url('/shop.php'), JSON_UNESCAPED_SLASHES) ?>,
            productUrl: <?= json_encode(url('/product.php?slug='), JSON_UNESCAPED_SLASHES) ?>,
            whatsappNumber: <?= json_encode(whatsapp_number()) ?>
        };
    </script>

    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => $storeName,
        'url'      => abs_url('/index.php'),
        'logo'     => abs_url('/images/brand/logo-square.png'),
        'description' => $meta_description,
        'contactPoint' => [
            '@type' => 'ContactPoint',
            'contactType' => 'customer service',
            'telephone'   => setting('store_phone', setting('whatsapp_number', '')),
            'email'       => setting('store_email', ''),
        ],
    ] + (setting('instagram_url') !== '#' && setting('instagram_url') !== ''
        ? ['sameAs' => [setting('instagram_url')]] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $storeName,
        'url'  => abs_url('/index.php'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => abs_url('/shop.php?q={search_term_string}'),
            'query-input' => 'required name=search_term_string',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>
    <?php foreach ($extra_schema as $schema): ?>
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endforeach; ?>
</head>
<body>

<!-- Scroll progress (champagne) -->
<div class="lx-scroll-progress" aria-hidden="true"></div>

<!-- Announcement bar (rotating) -->
<?php if ($announcementMessages): ?>
<div class="announcement-bar" data-announcement-bar>
    <div class="announcement-track">
        <?php foreach ($announcementMessages as $i => $msg): ?>
            <p class="announcement-msg<?= $i === 0 ? ' is-active' : '' ?>"><?= e($msg) ?></p>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Navbar -->
<header class="site-header">
    <div class="container nav-container">

        <a href="<?= url('/index.php') ?>" class="brand-logo">
            <img src="<?= e(asset_url('images/brand/logo-wordmark.png')) ?>"
                 alt="<?= e($storeName) ?>" width="790" height="192">
            <span class="sr-only"><?= e($storeName) ?></span>
        </a>

        <button class="nav-toggle" aria-label="Open navigation menu" type="button">
            <i class="fa-solid fa-bars"></i>
        </button>

        <nav class="site-nav" aria-label="Main navigation">
            <?= $mainNav ?>
        </nav>

        <div class="nav-actions">
            <button type="button" class="search-trigger" aria-label="Search" data-search-open>
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
            <a href="<?= url('/my-orders.php') ?>" aria-label="My Orders (saved on this device)" class="orders-link">
                <i class="fa-solid fa-receipt"></i>
            </a>
            <a href="<?= url('/wishlist.php') ?>" class="wishlist-link" aria-label="Wishlist">
                <i class="fa-regular fa-heart"></i>
                <span class="wishlist-count"><?= (int) $wishCount ?></span>
            </a>
            <a href="<?= url('/cart.php') ?>" class="cart-link" aria-label="Shopping cart" data-cart-drawer-open>
                <i class="fa-solid fa-bag-shopping"></i>
                <span class="cart-count"><?= (int) $cartCount ?></span>
            </a>
        </div>

    </div>
</header>

<!-- Premium search overlay -->
<div class="search-overlay" data-search-overlay hidden>
    <div class="search-overlay-panel" role="dialog" aria-modal="true" aria-label="Search products">
        <div class="search-overlay-head">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" id="live-search-input" data-live-search
                   placeholder="Search products, fabrics, collections…"
                   autocomplete="off" aria-label="Search products">
            <button type="button" class="search-close" data-search-close aria-label="Close search">&times;</button>
        </div>
        <div class="search-overlay-body" data-search-results>
            <p class="search-hint">Start typing to search our catalogue by name, category, fabric or collection.</p>
        </div>
        <div class="search-overlay-foot">
            <a href="<?= url('/shop.php') ?>" class="search-view-all" data-search-view-all>
                View all results <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<!-- Cart drawer (slide-out) -->
<div class="cart-drawer-overlay" data-cart-drawer-overlay hidden></div>
<aside class="cart-drawer" data-cart-drawer aria-hidden="true">
    <div class="cart-drawer-head">
        <h2>Your Bag</h2>
        <button type="button" class="cart-drawer-close" data-cart-drawer-close aria-label="Close cart">&times;</button>
    </div>
    <div class="cart-drawer-body" data-cart-drawer-body>
        <p class="drawer-loading">Loading your bag…</p>
    </div>
</aside>

<main>
<?= flash_render() ?>