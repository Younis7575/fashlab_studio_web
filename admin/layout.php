<?php
/**
 * Admin layout partial.
 * Expected variables: $admin (from require_admin), $page_title, $active, $content.
 */
declare(strict_types=1);

$storeName = setting('store_name');
$unreadMsgs = (int) db()->query('SELECT COUNT(*) FROM contact_messages WHERE status = 0')->fetchColumn();
$pendingOrders = (int) db()->query("SELECT COUNT(*) FROM orders WHERE order_status = 'pending'")->fetchColumn();
$pendingReviews = tc_table_exists('reviews') ? (int) db()->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")->fetchColumn() : 0;

$navItems = [
    'dashboard' => ['label' => 'Dashboard',           'icon' => 'fa-gauge-high',     'href' => '/admin/index.php'],
    'products'  => ['label' => 'All Products',        'icon' => 'fa-shirt',          'href' => '/admin/products.php'],
    'add_product' => ['label' => 'Add Product',       'icon' => 'fa-plus',           'href' => '/admin/product-form.php'],
    'categories'=> ['label' => 'Categories',          'icon' => 'fa-table-cells-large', 'href' => '/admin/categories.php'],
    'collections'=> ['label' => 'Collections',         'icon' => 'fa-gem',            'href' => '/admin/collections.php'],
    'orders'    => ['label' => 'Orders',              'icon' => 'fa-receipt',        'href' => '/admin/orders.php', 'badge' => $pendingOrders],
    'reviews'   => ['label' => 'Reviews',             'icon' => 'fa-star-half-stroke', 'href' => '/admin/reviews.php', 'badge' => $pendingReviews],
    'customers' => ['label' => 'Customers',           'icon' => 'fa-users',          'href' => '/admin/customers.php'],
    'subscribers'=> ['label' => 'Subscribers',        'icon' => 'fa-envelope-open-text', 'href' => '/admin/subscribers.php'],    'messages'  => ['label' => 'Messages',             'icon' => 'fa-comment-dots',   'href' => '/admin/messages.php', 'badge' => $unreadMsgs],
    'journal'   => ['label' => 'Style Journal',         'icon' => 'fa-feather',        'href' => '/admin/journal.php'],
    'reports'   => ['label' => 'Reports',             'icon' => 'fa-chart-column',   'href' => '/admin/reports.php'],
    'analytics' => ['label' => 'Analytics',            'icon' => 'fa-chart-line',     'href' => '/admin/analytics.php'],
    'settings'  => ['label' => 'Settings',            'icon' => 'fa-gear',           'href' => '/admin/settings.php'],
];

$flashes = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($page_title) ?> | Admin — <?= e($storeName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/admin.css')) ?>">
</head>
<body>

<div class="admin-shell">

    <!-- SIDEBAR -->
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <a href="<?= url('/admin/index.php') ?>"><?= e($storeName) ?></a>
            <small>Admin Panel</small>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= e(url($item['href'])) ?>" class="<?= $active === $key ? 'active' : '' ?>">
                    <i class="fa-solid <?= e($item['icon']) ?>"></i>
                    <?= e($item['label']) ?>
                    <?php if (!empty($item['badge'])): ?>
                        <span class="badge gold" style="margin-left:auto"><?= (int) $item['badge'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="<?= url('/admin/activity.php') ?>"><i class="fa-solid fa-clock-rotate-left"></i> Activity Log</a>
            <br><br>
            <a href="<?= url('/index.php') ?>" target="_blank"><i class="fa-solid fa-store"></i> View Store</a>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="admin-main">

        <header class="admin-topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <span class="topbar-title"><?= e($page_title) ?></span>
            </div>
            <div class="topbar-right">
                <a href="<?= url('/admin/profile.php') ?>" class="topbar-admin">
                    <div class="admin-avatar"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></div>
                    <div class="admin-meta">
                        <div class="admin-name"><?= e($admin['name']) ?></div>
                        <div class="admin-role"><?= e($admin['role']) ?></div>
                    </div>
                </a>
                <a href="<?= url('/admin/logout.php') ?>" class="btn btn-outline btn-sm" title="Logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </header>

        <div class="admin-content">
            <?php foreach ($flashes as $f): ?>
                <div class="admin-flash <?= e($f['type']) ?>">
                    <span><?= e($f['message']) ?></span>
                    <button type="button" class="flash-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endforeach; ?>

            <?= $content ?>
        </div>

    </div>
</div>

<script>
    document.getElementById('sidebarToggle').addEventListener('click', function () {
        document.getElementById('adminSidebar').classList.toggle('open');
    });
</script>
</body>
</html>