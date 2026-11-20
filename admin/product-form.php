<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();
$db = db();

$productId = (int) ($_GET['id'] ?? 0);
$isEdit    = $productId > 0;
$product   = null;
$errors    = [];

if ($isEdit) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) {
        flash_set('error', 'Product not found.');
        redirect(url('/admin/products.php'));
    }
}

$allCategories = $db->query('SELECT id, name, parent_id FROM categories ORDER BY sort_order ASC, name ASC')->fetchAll();

/** Replace a product's variants with the rows posted from the form. */
function save_posted_variants(PDO $db, int $productId): void
{
    $db->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);
    $variantNames  = (array) ($_POST['variant_name'] ?? []);
    $variantValues = (array) ($_POST['variant_value'] ?? []);
    $variantAdjust = (array) ($_POST['variant_adjustment'] ?? []);
    $variantStock  = (array) ($_POST['variant_stock'] ?? []);
    $variantSku    = (array) ($_POST['variant_sku'] ?? []);
    $vStmt = $db->prepare(
        'INSERT INTO product_variants (product_id, variant_name, variant_value, price_adjustment, stock_quantity, sku, status)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    foreach ($variantNames as $i => $vname) {
        if (trim((string) $vname) === '' && trim((string) ($variantValues[$i] ?? '')) === '') continue;
        $vStmt->execute([
            $productId,
            trim((string) $vname),
            trim((string) ($variantValues[$i] ?? '')),
            decimal((float) ($variantAdjust[$i] ?? 0)),
            max(0, (int) ($variantStock[$i] ?? 0)),
            trim((string) ($variantSku[$i] ?? '')),
        ]);
    }
}

/* ---------------------------------------------------------------------------
   POST handlers
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    $action = post('action', 30);

    /* ---------------- main product save ---------------- */
    if ($action === 'save') {
        $data = [
            'name'             => post('name', 200),
            'slug'             => post('slug', 220),
            'sku'              => post('sku', 100),
            'category_id'      => post_int('category_id'),
            'categories'       => array_values(array_filter(array_map('intval', (array) ($_POST['categories'] ?? [])))),
            'product_type'     => post('product_type', 100),
            'short_description'=> post('short_description', 500),
            'description'      => post_text('description', 100000),
            'price'            => post_float('price'),
            'sale_price'       => post_float('sale_price'),
            'cost_price'       => post_float('cost_price'),
            'stock_quantity'   => post_int('stock_quantity'),
            'stock_status'     => post('stock_status', 20),
            'fabric'           => post('fabric', 150),
            'color'            => post('color', 150),
            'size'             => post('size', 150),
            'featured'         => !empty($_POST['featured']) ? 1 : 0,
            'status'           => !empty($_POST['status']) ? 1 : 0,
        ];

        if ($data['name'] === '')                       $errors['name'] = 'Product name is required.';
        if ($data['sku'] === '')                        $errors['sku'] = 'SKU is required.';
        if ($data['price'] <= 0)                        $errors['price'] = 'Enter a valid price.';
        if ($data['sale_price'] > 0 && $data['sale_price'] >= $data['price']) {
            $errors['sale_price'] = 'Sale price must be lower than the regular price.';
        }
        if ($data['slug'] === '') {
            $data['slug'] = slugify($data['name']);
        } else {
            $data['slug'] = slugify($data['slug']);
        }
        if (!in_array($data['stock_status'], ['in_stock', 'low_stock', 'out_of_stock', 'backorder'], true)) {
            $data['stock_status'] = 'in_stock';
        }

        if (!$errors) {
            /* Ensure unique slug/sku */
            $slug = $data['slug'];
            $i = 1;
            while (true) {
                $chk = $db->prepare('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1');
                $chk->execute([$slug, $productId]);
                if (!$chk->fetch()) break;
                $slug = $data['slug'] . '-' . (++$i);
            }
            $data['slug'] = $slug;

            $sku = $data['sku'];
            $i = 1;
            while (true) {
                $chk = $db->prepare('SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1');
                $chk->execute([$sku, $productId]);
                if (!$chk->fetch()) break;
                $sku = $data['sku'] . '-' . (++$i);
            }
            $data['sku'] = $sku;

            if ($isEdit) {
                $stmt = $db->prepare(
                    'UPDATE products SET
                        name = ?, slug = ?, sku = ?, category_id = ?, product_type = ?,
                        short_description = ?, description = ?, price = ?, sale_price = ?, cost_price = ?,
                        stock_quantity = ?, stock_status = ?, fabric = ?, color = ?, size = ?,
                        featured = ?, status = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $data['name'], $data['slug'], $data['sku'],
                    $data['category_id'] ?: null, $data['product_type'],
                    $data['short_description'], $data['description'],
                    decimal($data['price']),
                    $data['sale_price'] > 0 ? decimal($data['sale_price']) : null,
                    $data['cost_price'] > 0 ? decimal($data['cost_price']) : null,
                    $data['stock_quantity'], $data['stock_status'],
                    $data['fabric'], $data['color'], $data['size'],
                    $data['featured'], $data['status'],
                    $productId,
                ]);

                /* Replace category links */
                $db->prepare('DELETE FROM product_categories WHERE product_id = ?')->execute([$productId]);
                foreach ($data['categories'] as $cid) {
                    $db->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)')->execute([$productId, $cid]);
                }
                if (!in_array($data['category_id'], $data['categories'], true) && $data['category_id'] > 0) {
                    $db->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)')->execute([$productId, $data['category_id']]);
                }

                /* Replace variants */
                save_posted_variants($db, $productId);

                record_activity('product_update', 'product', $productId, 'Updated "' . $data['name'] . '"');
                flash_set('success', 'Product updated — changes are live on the storefront.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO products (category_id, name, slug, sku, short_description, description,
                        price, sale_price, cost_price, stock_quantity, stock_status, product_type,
                        fabric, color, size, featured, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $data['category_id'] ?: null,
                    $data['name'], $data['slug'], $data['sku'],
                    $data['short_description'], $data['description'],
                    decimal($data['price']),
                    $data['sale_price'] > 0 ? decimal($data['sale_price']) : null,
                    $data['cost_price'] > 0 ? decimal($data['cost_price']) : null,
                    $data['stock_quantity'], $data['stock_status'],
                    $data['product_type'], $data['fabric'], $data['color'], $data['size'],
                    $data['featured'], $data['status'],
                ]);
                $productId = (int) $db->lastInsertId();

                foreach ($data['categories'] as $cid) {
                    $db->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)')->execute([$productId, $cid]);
                }
                if (!in_array($data['category_id'], $data['categories'], true) && $data['category_id'] > 0) {
                    $db->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)')->execute([$productId, $data['category_id']]);
                }

                save_posted_variants($db, $productId);

                record_activity('product_create', 'product', $productId, 'Created "' . $data['name'] . '"');
                flash_set('success', 'Product created successfully.');
                redirect(url('/admin/product-form.php?id=' . $productId));
            }

            /* Refresh product for re-render */
            $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            $isEdit = true;
        }
    }

    /* ---------------- image upload ---------------- */
    if ($action === 'upload_image' && $productId > 0) {
        $result = upload_image($_FILES['image'] ?? [], 'products');
        if ($result['success']) {
            $isPrimary = (int) $db->query('SELECT COUNT(*) FROM product_images WHERE product_id = ' . (int) $productId)->fetchColumn() === 0 ? 1 : 0;
            $stmt = $db->prepare(
                'INSERT INTO product_images (product_id, image, sort_order, is_primary) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$productId, $result['path'], (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM product_images WHERE product_id = ' . (int) $productId)->fetchColumn(), $isPrimary]);
            record_activity('product_image_add', 'product', $productId, 'Added product image');
            flash_set('success', 'Image uploaded.');
        } else {
            flash_set('error', $result['error']);
        }
        redirect(url('/admin/product-form.php?id=' . $productId));
    }

    /* ---------------- image delete ---------------- */
    if ($action === 'delete_image' && $productId > 0) {
        $imgId = post_int('image_id');
        $stmt = $db->prepare('SELECT image, is_primary FROM product_images WHERE id = ? AND product_id = ? LIMIT 1');
        $stmt->execute([$imgId, $productId]);
        $img = $stmt->fetch();
        if ($img) {
            delete_uploaded_file($img['image']);
            $db->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imgId]);
            if ((int) $img['is_primary'] === 1) {
                $db->prepare(
                    'UPDATE product_images SET is_primary = 1
                     WHERE product_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1'
                )->execute([$productId]);
            }
            record_activity('product_image_delete', 'product', $productId, 'Removed product image');
            flash_set('success', 'Image deleted.');
        }
        redirect(url('/admin/product-form.php?id=' . $productId));
    }

    /* ---------------- set primary image ---------------- */
    if ($action === 'set_primary' && $productId > 0) {
        $imgId = post_int('image_id');
        $db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        $db->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')->execute([$imgId, $productId]);
        record_activity('product_image_primary', 'product', $productId, 'Changed primary image');
        flash_set('success', 'Primary image updated.');
        redirect(url('/admin/product-form.php?id=' . $productId));
    }
}

/* ---------------------------------------------------------------------------
   Load editable state
--------------------------------------------------------------------------- */
if ($isEdit && $product) {
    $images = $db->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC');
    $images->execute([$productId]);
    $images = $images->fetchAll();

    $variants = $db->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC');
    $variants->execute([$productId]);
    $variants = $variants->fetchAll();

    $linked = $db->prepare('SELECT category_id FROM product_categories WHERE product_id = ?');
    $linked->execute([$productId]);
    $linkedCatIds = array_column($linked->fetchAll(), 'category_id');
} else {
    $images = [];
    $variants = [];
    $linkedCatIds = [];
}

$page_title = $isEdit ? 'Edit Product' : 'Add Product';
$active     = $isEdit ? 'products' : 'add_product';

ob_start();
?>

<div class="page-header">
    <div>
        <h1><?= $isEdit ? 'Edit Product' : 'Add Product' ?></h1>
        <p><?= $isEdit ? e($product['name']) : 'Fill in the details below — the storefront updates instantly.' ?></p>
    </div>
    <div class="page-actions">
        <a href="<?= url('/admin/products.php') ?>" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to Products</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="admin-flash error">
        <span>
            <?php foreach ($errors as $err): ?>
                <?= e($err) ?><br>
            <?php endforeach; ?>
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/product-form.php' . ($isEdit ? '?id=' . $productId : ''))) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <div class="detail-grid">
        <div>

            <!-- BASIC INFO -->
            <div class="card">
                <div class="card-title">Basic Information</div>
                <div class="form-grid">
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="name">Product Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name" value="<?= e($product['name'] ?? '') ?>" required>
                        <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="slug">Slug (URL)</label>
                        <input type="text" id="slug" name="slug" placeholder="auto-generated from name" value="<?= e($product['slug'] ?? '') ?>">
                        <p class="help">Leave empty to auto-generate from the product name.</p>
                    </div>
                    <div class="form-group">
                        <label for="sku">SKU <span class="required">*</span></label>
                        <input type="text" id="sku" name="sku" value="<?= e($product['sku'] ?? '') ?>" required>
                        <?php if (isset($errors['sku'])): ?><p class="field-error"><?= e($errors['sku']) ?></p><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="product_type">Product Type</label>
                        <input type="text" id="product_type" name="product_type" placeholder="e.g. 3 Piece Suit" value="<?= e($product['product_type'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="short_description">Short Description</label>
                        <input type="text" id="short_description" name="short_description" maxlength="500" placeholder="One-line teaser shown on cards" value="<?= e($product['short_description'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="description">Full Description</label>
                        <textarea id="description" name="description" style="min-height:140px" placeholder="Detailed description shown on the product page…"><?= e($product['description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- PRICING & STOCK -->
            <div class="card">
                <div class="card-title">Pricing &amp; Stock</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="price">Price (<?= e(setting('currency', 'Rs')) ?>) <span class="required">*</span></label>
                        <input type="number" id="price" name="price" min="0" step="0.01" value="<?= e($product['price'] ?? '') ?>" required>
                        <?php if (isset($errors['price'])): ?><p class="field-error"><?= e($errors['price']) ?></p><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="sale_price">Sale Price</label>
                        <input type="number" id="sale_price" name="sale_price" min="0" step="0.01" value="<?= e($product['sale_price'] ?? '') ?>">
                        <p class="help">Leave empty for no discount.</p>
                        <?php if (isset($errors['sale_price'])): ?><p class="field-error"><?= e($errors['sale_price']) ?></p><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="cost_price">Cost Price</label>
                        <input type="number" id="cost_price" name="cost_price" min="0" step="0.01" value="<?= e($product['cost_price'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="stock_quantity">Stock Quantity</label>
                        <input type="number" id="stock_quantity" name="stock_quantity" min="0" value="<?= e((string) ($product['stock_quantity'] ?? 0)) ?>">
                    </div>
                    <div class="form-group">
                        <label for="stock_status">Stock Status</label>
                        <select id="stock_status" name="stock_status">
                            <?php foreach (['in_stock', 'low_stock', 'out_of_stock', 'backorder'] as $ss): ?>
                                <option value="<?= e($ss) ?>" <?= ($product['stock_status'] ?? 'in_stock') === $ss ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $ss))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- DETAILS -->
            <div class="card">
                <div class="card-title">Product Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="fabric">Fabric</label>
                        <input type="text" id="fabric" name="fabric" placeholder="e.g. Lawn, Cotton, Linen" value="<?= e($product['fabric'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="color">Color</label>
                        <input type="text" id="color" name="color" placeholder="e.g. Black, Maroon" value="<?= e($product['color'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="size">Sizes</label>
                        <input type="text" id="size" name="size" placeholder="e.g. S, M, L, XL" value="<?= e($product['size'] ?? '') ?>">
                    </div>
                </div>
                <div class="checkbox-line">
                    <input type="checkbox" id="featured" name="featured" value="1" <?= !empty($product['featured']) ? 'checked' : '' ?>>
                    <label for="featured">Featured product (shown in homepage featured sections)</label>
                </div>
                <div class="checkbox-line">
                    <input type="checkbox" id="status" name="status" value="1" <?= !isset($product['status']) || (int) $product['status'] === 1 ? 'checked' : '' ?>>
                    <label for="status">Active (visible on the storefront)</label>
                </div>
            </div>

            <!-- VARIANTS -->
            <div class="card">
                <div class="card-title">Product Variants <small style="font-weight:400;color:var(--admin-muted)">(optional — e.g. size/color bundles)</small></div>
                <div id="variantRows">
                    <?php foreach ($variants as $v): ?>
                        <div class="variant-row">
                            <input type="text" name="variant_name[]" placeholder="Name (e.g. Size)" value="<?= e($v['variant_name']) ?>">
                            <input type="text" name="variant_value[]" placeholder="Value (e.g. M)" value="<?= e($v['variant_value']) ?>">
                            <input type="number" name="variant_adjustment[]" placeholder="Price adj." step="0.01" value="<?= e($v['price_adjustment'] ?: '') ?>">
                            <input type="number" name="variant_stock[]" placeholder="Stock" min="0" value="<?= e((string) $v['stock_quantity']) ?>">
                            <input type="text" name="variant_sku[]" placeholder="SKU" value="<?= e($v['sku']) ?>">
                            <button type="button" class="remove-variant" title="Remove row">&times;</button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$variants): ?>
                        <div class="variant-row">
                            <input type="text" name="variant_name[]" placeholder="Name (e.g. Size)">
                            <input type="text" name="variant_value[]" placeholder="Value (e.g. M)">
                            <input type="number" name="variant_adjustment[]" placeholder="Price adj." step="0.01">
                            <input type="number" name="variant_stock[]" placeholder="Stock" min="0">
                            <input type="text" name="variant_sku[]" placeholder="SKU">
                            <button type="button" class="remove-variant" title="Remove row">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn btn-outline btn-sm" id="addVariantRow"><i class="fa-solid fa-plus"></i> Add Variant</button>
            </div>

        </div>

        <div>

            <!-- CATEGORIES -->
            <div class="card">
                <div class="card-title">Categories</div>
                <div class="form-group">
                    <label for="category_id">Primary Category</label>
                    <select id="category_id" name="category_id">
                        <option value="0">— None —</option>
                        <?php foreach ($allCategories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= (int) ($product['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:8px">
                    Also list under (multiple allowed)
                </label>
                <div class="category-check-grid">
                    <?php foreach ($allCategories as $c): ?>
                        <label>
                            <input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>"
                                   <?= in_array((int) $c['id'], $linkedCatIds, true) ? 'checked' : '' ?>>
                            <?= e($c['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="help" style="margin-top:10px">A product can belong to many collections (e.g. Stitched + New Arrivals + Eid Collection).</p>
            </div>

            <?php if ($isEdit): ?>
            <!-- IMAGES -->
            <div class="card">
                <div class="card-title">Product Images (<?= count($images) ?>)</div>

                <?php if ($images): ?>
                    <div class="image-grid" style="margin-bottom:16px">
                        <?php foreach ($images as $img): ?>
                            <div class="img-item">
                                <img src="<?= e(image_url($img['image'] ?? '')) ?>" alt="">
                                <div class="img-meta">
                                    <span>
                                        <?php if ((int) $img['is_primary'] === 1): ?>
                                            <span class="img-primary-badge">PRIMARY</span>
                                        <?php else: ?>
                                            <button type="submit" form="imgPrimary<?= (int) $img['id'] ?>" class="btn btn-outline btn-xs">Set primary</button>
                                        <?php endif; ?>
                                    </span>
                                    <span class="img-actions">
                                        <button type="submit" form="imgDelete<?= (int) $img['id'] ?>" class="btn btn-danger btn-xs" title="Delete image"><i class="fa-solid fa-trash"></i></button>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--admin-muted)">No images yet — upload at least one so the product displays on the storefront.</p>
                <?php endif; ?>

                <label class="dropzone" id="dropzone">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:24px;display:block;margin-bottom:8px"></i>
                    Click to choose images (JPG, PNG, WebP, GIF — max <?= (int) round(UPLOAD_MAX_SIZE / 1048576) ?> MB)
                    <input type="file" name="image" form="imgUpload" accept="image/jpeg,image/png,image/webp,image/gif" required>
                </label>
                <button type="submit" form="imgUpload" class="btn btn-outline btn-sm" style="margin-top:12px"><i class="fa-solid fa-upload"></i> Upload Image</button>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <div class="page-actions" style="margin-top:8px">
        <button type="submit" class="btn btn-primary" style="padding:12px 28px">
            <i class="fa-solid fa-floppy-disk"></i> <?= $isEdit ? 'Save Changes' : 'Create Product' ?>
        </button>
        <?php if ($isEdit): ?>
            <a href="<?= e(product_url($product['slug'])) ?>" target="_blank" class="btn btn-outline">View on Storefront</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($isEdit): ?>
<!-- Image action forms live outside the product form (forms cannot nest);
     the controls in the Images card point at them via the form="" attribute. -->
<?php $imageFormAction = e(url('/admin/product-form.php?id=' . $productId)); ?>
<form id="imgUpload" method="post" enctype="multipart/form-data" action="<?= $imageFormAction ?>" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_image">
</form>
<?php foreach ($images as $img): ?>
    <?php if ((int) $img['is_primary'] !== 1): ?>
        <form id="imgPrimary<?= (int) $img['id'] ?>" method="post" action="<?= $imageFormAction ?>" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_primary">
            <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
        </form>
    <?php endif; ?>
    <form id="imgDelete<?= (int) $img['id'] ?>" method="post" action="<?= $imageFormAction ?>" hidden onsubmit="return confirm('Delete this image?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_image">
        <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
    </form>
<?php endforeach; ?>
<?php endif; ?>

<script>
    (function () {
        var rows = document.getElementById('variantRows');
        function addRow() {
            var div = document.createElement('div');
            div.className = 'variant-row';
            div.innerHTML =
                '<input type="text" name="variant_name[]" placeholder="Name (e.g. Size)">' +
                '<input type="text" name="variant_value[]" placeholder="Value (e.g. M)">' +
                '<input type="number" name="variant_adjustment[]" placeholder="Price adj." step="0.01">' +
                '<input type="number" name="variant_stock[]" placeholder="Stock" min="0">' +
                '<input type="text" name="variant_sku[]" placeholder="SKU">' +
                '<button type="button" class="remove-variant" title="Remove row">&times;</button>';
            rows.appendChild(div);
        }
        document.getElementById('addVariantRow').addEventListener('click', addRow);
        rows.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-variant')) e.target.closest('.variant-row').remove();
        });

        var dz = document.getElementById('dropzone');
        if (dz) {
            var input = dz.querySelector('input[type="file"]');
            dz.addEventListener('click', function () { input.click(); });
            dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('dragover'); });
            dz.addEventListener('dragleave', function () { dz.classList.remove('dragover'); });
            dz.addEventListener('drop', function (e) {
                e.preventDefault();
                dz.classList.remove('dragover');
                if (e.dataTransfer.files.length) input.files = e.dataTransfer.files;
            });
        }
    })();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';