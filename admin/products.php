<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();

/* ---------------------------------------------------------------------------
   POST actions: toggle status / archive (soft delete via status = 0)
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    $action = post('action', 20);
    $id     = post_int('id');

    if ($action === 'activate' && $id > 0) {
        db()->prepare('UPDATE products SET status = 1 WHERE id = ?')->execute([$id]);
        record_activity('product_activate', 'product', $id, 'Product activated');
        flash_set('success', 'Product activated and now visible on the storefront.');
    } elseif ($action === 'deactivate' && $id > 0) {
        db()->prepare('UPDATE products SET status = 0 WHERE id = ?')->execute([$id]);
        record_activity('product_deactivate', 'product', $id, 'Product deactivated');
        flash_set('success', 'Product deactivated — hidden from the storefront.');
    } elseif ($action === 'archive' && $id > 0) {
        db()->prepare('UPDATE products SET status = 0 WHERE id = ?')->execute([$id]);
        record_activity('product_archive', 'product', $id, 'Product archived');
        flash_set('success', 'Product archived. You can restore it from the Inactive filter.');
    }

    redirect(url('/admin/products.php' . (!empty($_POST['back']) ? '?' . e($_POST['back']) : '')));
}

/* ---------------------------------------------------------------------------
   Filters
--------------------------------------------------------------------------- */
$q        = get_string('q', 100);
$catId    = (int) ($_GET['category'] ?? 0);
$status   = get_string('status', 10);
$stock    = get_string('stock', 10);
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 15;

$where  = [];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    array_push($params, $like, $like);
}
if ($catId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = p.id AND pc.category_id = ?)';
    $params[] = $catId;
}
if ($status === 'active') {
    $where[] = 'p.status = 1';
} elseif ($status === 'inactive') {
    $where[] = 'p.status = 0';
}
if ($stock === 'low') {
    $where[] = 'p.status = 1 AND p.stock_quantity <= ?';
    $params[] = (int) setting('low_stock_threshold', '5');
} elseif ($stock === 'out') {
    $where[] = 'p.stock_quantity < 1';
} elseif ($stock === 'featured') {
    $where[] = 'p.featured = 1';
} elseif ($stock === 'in_stock') {
    $where[] = 'p.stock_quantity > 0';
} elseif ($stock === 'sale') {
    $where[] = 'p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price';
} elseif ($stock === 'new') {
    $where[] = 'p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM products p {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT p.*, pi.image AS primary_image,
            GROUP_CONCAT(DISTINCT c.name ORDER BY c.sort_order SEPARATOR ', ') AS category_names
     FROM products p
     LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
     LEFT JOIN product_categories pc ON pc.product_id = p.id
     LEFT JOIN categories c ON c.id = pc.category_id
     {$whereSql}
     GROUP BY p.id
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = db()->query('SELECT id, name FROM categories ORDER BY sort_order ASC, name ASC')->fetchAll();

$qs = http_build_query(array_filter([
    'q' => $q, 'category' => $catId ?: null, 'status' => $status, 'stock' => $stock,
], static fn($v) => $v !== null && $v !== ''));

// The stock dropdown is a multi-purpose facet: render its extra options
// (in_stock / sale / new) only once the query they came from is known, then
// add them to the filter bar so the browser history stays clean.
foreach (['in_stock', 'sale', 'new'] as $extra) {
    if (isset($_GET['stock']) && $_GET['stock'] === $extra) {
        $qs .= '&' . http_build_query(['stock' => $extra]);
    }
}

$page_title = 'Products';
$active     = 'products';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Products</h1>
        <p><?= (int) $total ?> product<?= (int) $total === 1 ? '' : 's' ?> found</p>
    </div>
    <div class="page-actions">
        <a href="<?= url('/admin/product-form.php') ?>" class="btn btn-accent"><i class="fa-solid fa-plus"></i> Add Product</a>
    </div>
</div>

<form class="filter-bar" method="get" action="<?= e(url('/admin/products.php')) ?>">
    <input type="search" name="q" placeholder="Search by name or SKU…" value="<?= e($q) ?>">
    <select name="category">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $catId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">All Statuses</option>
        <option value="active"   <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive / Archived</option>
    </select>
    <select name="stock">
        <option value="">All Stock</option>
        <option value="low" <?= $stock === 'low' ? 'selected' : '' ?>>Low Stock</option>
        <option value="out" <?= $stock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
        <option value="featured" <?= $stock === 'featured' ? 'selected' : '' ?>>Featured</option>
        <option value="in_stock" <?= $stock === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
        <option value="sale" <?= $stock === 'sale' ? 'selected' : '' ?>>On Sale</option>
        <option value="new" <?= $stock === 'new' ? 'selected' : '' ?>>New (30 days)</option>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <?php if ($qs): ?>
        <a href="<?= url('/admin/products.php') ?>" class="btn btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Categories</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Featured</th>
                    <th>Created</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;color:var(--admin-muted);padding:40px">
                            No products match your filters.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($products as $p): ?>
                    <?php $onSale = (float) $p['sale_price'] > 0 && (float) $p['sale_price'] < (float) $p['price']; ?>
                    <tr>
                        <td>
                            <div class="prod-cell">
                                <img class="table-img" src="<?= e(image_url($p['primary_image'] ?? '')) ?>" alt="">
                                <div>
                                    <div class="prod-name"><?= e($p['name']) ?></div>
                                    <a href="<?= e(product_url($p['slug'])) ?>" target="_blank" style="font-size:12px">View on storefront →</a>
                                </div>
                            </div>
                        </td>
                        <td><?= e($p['sku']) ?></td>
                        <td style="max-width:180px"><?= e(mb_strimwidth((string) $p['category_names'], 0, 60, '…')) ?></td>
                        <td>
                            <?= money((float) $p['price']) ?>
                            <?php if ($onSale): ?><br><span class="badge red">Sale <?= money((float) $p['sale_price']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $p['stock_quantity'] < 1): ?>
                                <span class="badge red">Out of stock</span>
                            <?php elseif ((int) $p['stock_quantity'] <= (int) setting('low_stock_threshold', '5')): ?>
                                <span class="badge gold"><?= (int) $p['stock_quantity'] ?> left</span>
                            <?php else: ?>
                                <span class="badge green"><?= (int) $p['stock_quantity'] ?> in stock</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= (int) $p['status'] === 1 ? 'green' : 'gray' ?>">
                                <?= (int) $p['status'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ((int) $p['featured'] === 1): ?>
                                <span class="badge gold"><i class="fa-solid fa-star"></i> Featured</span>
                            <?php else: ?>
                                <span style="color:var(--admin-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_date($p['created_at'])) ?></td>
                        <td style="text-align:right;white-space:nowrap">
                            <a class="btn btn-outline btn-xs" href="<?= url('/admin/product-form.php?id=' . (int) $p['id']) ?>">
                                <i class="fa-solid fa-pen"></i> Edit
                            </a>
                            <?php if ((int) $p['status'] === 1): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this product? It will disappear from the storefront.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="back" value="<?= e($qs) ?>">
                                    <button class="btn btn-outline btn-xs"><i class="fa-solid fa-eye-slash"></i> Deactivate</button>
                                </form>
                            <?php else: ?>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="back" value="<?= e($qs) ?>">
                                    <button class="btn btn-outline btn-xs"><i class="fa-solid fa-eye"></i> Restore</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Archive this product? It will be hidden from the storefront but kept in the database.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="back" value="<?= e($qs) ?>">
                                <button class="btn btn-danger btn-xs"><i class="fa-solid fa-box-archive"></i> Archive</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($page, $pages, $qs) ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';