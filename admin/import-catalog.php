<?php
/**
 * One-off catalog reset + seed (admin only).
 * Wipes products, categories, collections and their links, then imports
 * scripts/catalog-seed.json. Orders/customers are kept.
 * Remove this file after use.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();
$db = db();
$seed = json_decode((string) file_get_contents(__DIR__ . '/../scripts/catalog-seed.json'), true);
if (!$seed) { http_response_code(500); exit('Seed file missing/invalid.'); }

$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    $db->beginTransaction();
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['product_images', 'product_variants', 'product_categories', 'collection_products',
                  'wishlist_items', 'recently_viewed', 'product_alerts', 'look_products', 'bundle_items',
                  'reviews', 'products', 'collections', 'categories'] as $t) {
            try { $db->exec("DELETE FROM `$t`"); } catch (Throwable $e) { /* table may not exist */ }
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $catIds = [];
        $ci = $db->prepare('INSERT INTO categories (name, slug, description, image, status, show_in_nav, sort_order) VALUES (?,?,?,?,1,1,?)');
        foreach ($seed['categories'] as $i => $c) {
            $first = null;
            foreach ($seed['products'] as $p) { if ($p['category'] === $c['slug']) { $first = $p['images'][0]; break; } }
            $ci->execute([$c['name'], $c['slug'], $c['description'], $first, $i + 1]);
            $catIds[$c['slug']] = (int) $db->lastInsertId();
        }

        $skuIds = [];
        $pi = $db->prepare(
            'INSERT INTO products (category_id, name, slug, sku, short_description, description, price, stock_quantity, stock_status,
                                   product_type, fabric, color, featured, status)
             VALUES (?,?,?,?,?,?,?,?, "in_stock", ?, ?, ?, ?, 1)'
        );
        $ii = $db->prepare('INSERT INTO product_images (product_id, image, sort_order, is_primary) VALUES (?,?,?,?)');
        $pc = $db->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?,?)');
        $vi = $db->prepare('INSERT INTO product_variants (product_id, variant_name, variant_value, price_adjustment, stock_quantity, status) VALUES (?,?,?,0,?,1)');
        $sized = ['ready-to-wear', 'western-wear', 'co-ord-sets', 'abayas'];
        foreach ($seed['products'] as $p) {
            $cid = $catIds[$p['category']];
            $pi->execute([$cid, $p['name'], slugify($p['name']) . '-' . strtolower(substr($p['sku'], 3, 6)), $p['sku'],
                $p['short'], $p['desc'], $p['price'], 25, $p['category'], $p['fabric'] ?: null, $p['color'] ?: null, $p['featured']]);
            $pid = (int) $db->lastInsertId();
            $skuIds[$p['sku']] = $pid;
            $pc->execute([$pid, $cid]);
            foreach ($p['images'] as $n => $img) { $ii->execute([$pid, $img, $n, $n === 0 ? 1 : 0]); }
            if (in_array($p['category'], $sized, true)) {
                foreach (['S', 'M', 'L', 'XL'] as $s) { $vi->execute([$pid, 'Size', $s, 10]); }
            }
        }

        $ki = $db->prepare('INSERT INTO collections (name, slug, collection_type, description, image, status, is_featured, sort_order) VALUES (?,?,?,?,?,1,1,?)');
        $kp = $db->prepare('INSERT INTO collection_products (collection_id, product_id, sort_order) VALUES (?,?,?)');
        foreach ($seed['collections'] as $i => $c) {
            $img = null;
            foreach ($seed['products'] as $p) { if (in_array($p['sku'], $c['skus'], true)) { $img = $p['images'][0]; break; } }
            $ki->execute([$c['name'], $c['slug'], $c['type'], $c['description'], $img, $i + 1]);
            $kid = (int) $db->lastInsertId();
            foreach ($c['skus'] as $n => $sku) { if (isset($skuIds[$sku])) $kp->execute([$kid, $skuIds[$sku], $n]); }
        }
        $db->commit();
        $done = count($seed['products']) . ' products, ' . count($seed['categories']) . ' categories, ' . count($seed['collections']) . ' collections imported.';
    } catch (Throwable $e) {
        $db->rollBack();
        $done = 'FAILED: ' . $e->getMessage();
    }
}
?>
<!doctype html><meta charset="utf-8"><title>Catalog reset</title>
<body style="font-family:sans-serif;max-width:560px;margin:60px auto">
<h2>Catalog reset &amp; import</h2>
<?php if ($done): ?><p><strong><?= e($done) ?></strong></p><?php else: ?>
<p>This deletes <b>all</b> products, categories and collections, then imports <?= count($seed['products']) ?> Sapphire-sourced products. Orders are kept.</p>
<form method="post"><?= csrf_field() ?><button style="padding:10px 20px">Wipe &amp; import</button></form>
<?php endif; ?>
</body>
