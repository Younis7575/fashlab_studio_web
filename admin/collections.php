<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();
$db = db();

/* ---------------------------------------------------------------------------
   POST handlers
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    $action = post('action', 30);

    /* ---------------- save (create / update) ---------------- */
    if ($action === 'save') {
        $id            = post_int('id');
        $name          = post('name', 150);
        $slug          = post('slug', 190);
        $type          = post('collection_type', 50);
        $description   = post('description', 2000);
        $status        = !empty($_POST['status']) ? 1 : 0;
        $featured      = !empty($_POST['is_featured']) ? 1 : 0;
        $sortOrder     = post_int('sort_order');
        $startDate     = post('start_date', 10);
        $endDate       = post('end_date', 10);
        $metaTitle     = post('meta_title', 200);
        $metaDesc      = post('meta_description', 500);
        $metaKeywords  = post('meta_keywords', 300);

        $errors = [];
        if ($name === '') $errors[] = 'Collection name is required.';
        if ($slug === '') $slug = slugify($name);
        else $slug = slugify($slug);

        if (!$errors) {
            $chk = $db->prepare('SELECT id FROM collections WHERE slug = ? AND id <> ? LIMIT 1');
            $chk->execute([$slug, $id]);
            if ($chk->fetch()) $errors[] = 'A collection with this slug already exists.';
        }

        if (!$errors) {
            /* Uploads (optional) */
            $imagePath  = null;
            $bannerPath = null;
            if (!empty($_FILES['image']['name'])) {
                $result = upload_image($_FILES['image'], 'collections');
                if ($result['success']) $imagePath = $result['path'];
                else $errors[] = $result['error'];
            }
            if (!$errors && !empty($_FILES['banner']['name'])) {
                $result = upload_image($_FILES['banner'], 'collections');
                if ($result['success']) $bannerPath = $result['path'];
                else $errors[] = $result['error'];
            }
        }

        if (!$errors) {
            $startDate = $startDate !== '' ? $startDate : null;
            $endDate   = $endDate !== '' ? $endDate : null;

            if ($id > 0) {
                if ($imagePath || $bannerPath) {
                    /* fetch existing paths so we can keep whichever wasn't replaced */
                    $cur = $db->prepare('SELECT image, banner FROM collections WHERE id = ?');
                    $cur->execute([$id]);
                    $curRow = $cur->fetch() ?: [];
                    $imagePath  = $imagePath ?: ($curRow['image'] ?? null);
                    $bannerPath = $bannerPath ?: ($curRow['banner'] ?? null);

                    $stmt = $db->prepare(
                        'UPDATE collections
                         SET name=?, slug=?, collection_type=?, description=?, image=?, banner=?,
                             status=?, is_featured=?, sort_order=?, start_date=?, end_date=?,
                             meta_title=?, meta_description=?, meta_keywords=?
                         WHERE id=?'
                    );
                    $stmt->execute([$name, $slug, $type ?: null, $description, $imagePath, $bannerPath,
                        $status, $featured, $sortOrder, $startDate, $endDate,
                        $metaTitle ?: null, $metaDesc ?: null, $metaKeywords ?: null, $id]);
                } else {
                    $stmt = $db->prepare(
                        'UPDATE collections
                         SET name=?, slug=?, collection_type=?, description=?, status=?, is_featured=?,
                             sort_order=?, start_date=?, end_date=?, meta_title=?, meta_description=?, meta_keywords=?
                         WHERE id=?'
                    );
                    $stmt->execute([$name, $slug, $type ?: null, $description, $status, $featured,
                        $sortOrder, $startDate, $endDate,
                        $metaTitle ?: null, $metaDesc ?: null, $metaKeywords ?: null, $id]);
                }
                record_activity('collection_update', 'collection', $id, 'Updated collection "' . $name . '"');
                flash_set('success', 'Collection updated.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO collections
                        (name, slug, collection_type, description, image, banner, status, is_featured, sort_order, start_date, end_date, meta_title, meta_description, meta_keywords)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$name, $slug, $type ?: null, $description, $imagePath, $bannerPath,
                    $status, $featured, $sortOrder, $startDate, $endDate,
                    $metaTitle ?: null, $metaDesc ?: null, $metaKeywords ?: null]);
                $newId = (int) $db->lastInsertId();
                record_activity('collection_create', 'collection', $newId, 'Created collection "' . $name . '"');
                flash_set('success', 'Collection "' . $name . '" created — assign products to it next.');
                redirect(url('/admin/collections.php?edit=' . $newId));
            }
            redirect(url('/admin/collections.php'));
        }

        foreach ($errors as $err) flash_set('error', $err);
        redirect(url('/admin/collections.php'));
    }

    /* ---------------- assign products ---------------- */
    if ($action === 'assign_products') {
        $id = post_int('id');
        $productIds = array_map('intval', (array) ($_POST['product_ids'] ?? []));

        $chk = $db->prepare('SELECT id FROM collections WHERE id = ?');
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            flash_set('error', 'Collection not found.');
            redirect(url('/admin/collections.php'));
        }

        $db->prepare('DELETE FROM collection_products WHERE collection_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT IGNORE INTO collection_products (collection_id, product_id, sort_order) VALUES (?, ?, 0)');
        foreach ($productIds as $pid) {
            $ins->execute([$id, $pid]);
        }
        record_activity('collection_products', 'collection', $id, 'Assigned ' . count($productIds) . ' product(s) to collection');
        flash_set('success', 'Collection products updated (' . count($productIds) . ' assigned).');
        redirect(url('/admin/collections.php?edit=' . $id));
    }

    /* ---------------- toggle status ---------------- */
    if (in_array($action, ['activate', 'deactivate'], true)) {
        $id = post_int('id');
        $newStatus = $action === 'activate' ? 1 : 0;
        db()->prepare('UPDATE collections SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        record_activity('collection_' . $action, 'collection', $id, ($newStatus ? 'Activated' : 'Deactivated') . ' collection');
        flash_set('success', 'Collection ' . ($newStatus ? 'activated' : 'deactivated') . '.');
        redirect(url('/admin/collections.php'));
    }

    /* ---------------- delete ---------------- */
    if ($action === 'delete') {
        $id = post_int('id');
        $img = db()->prepare('SELECT image, banner FROM collections WHERE id = ?');
        $img->execute([$id]);
        $row = $img->fetch();
        if ($row) {
            if ($row['image']) delete_uploaded_file($row['image']);
            if ($row['banner']) delete_uploaded_file($row['banner']);
        }
        db()->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
        db()->prepare('DELETE FROM collection_products WHERE collection_id = ?')->execute([$id]);
        db()->prepare('UPDATE products SET collection_id = NULL WHERE collection_id = ?')->execute([$id]);
        record_activity('collection_delete', 'collection', $id, 'Deleted collection');
        flash_set('success', 'Collection deleted. Products are untouched.');
        redirect(url('/admin/collections.php'));
    }
}

/* ---------------------------------------------------------------------------
   View
--------------------------------------------------------------------------- */
$collections = $db->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM collection_products cp WHERE cp.collection_id = c.id) AS product_count,
            (SELECT COUNT(*) FROM collection_products cp
             JOIN products p ON p.id = cp.product_id AND p.status = 1
             WHERE cp.collection_id = c.id) AS live_count
     FROM collections c
     ORDER BY c.sort_order ASC, c.name ASC'
)->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$editCol = null;
if ($editId > 0) {
    $st = $db->prepare('SELECT * FROM collections WHERE id = ?');
    $st->execute([$editId]);
    $editCol = $st->fetch();
}

/* products for the assigner + current selection */
$products = $db->query(
    'SELECT id, name, sku, price, sale_price, status
     FROM products
     ORDER BY name ASC
     LIMIT 500'
)->fetchAll();

$assignedIds = [];
if ($editCol) {
    $st = $db->prepare('SELECT product_id FROM collection_products WHERE collection_id = ?');
    $st->execute([(int) $editCol['id']]);
    $assignedIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
$assignedSet = array_flip($assignedIds);

$page_title = 'Collections';
$active     = 'collections';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Collections</h1>
        <p>Collections group products into shoppable edits — they appear on the storefront collections page and in navigation.</p>
    </div>
</div>

<div class="detail-grid">
    <div class="card">
        <div class="card-title">All Collections (<?= count($collections) ?>)</div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Collection</th>
                        <th>Slug</th>
                        <th>Type</th>
                        <th>Products</th>
                        <th>Featured</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$collections): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--admin-muted);padding:36px">No collections yet — create your first one on the right.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($collections as $c): ?>
                        <tr>
                            <td>
                                <div class="prod-cell">
                                    <?php if ($c['image']): ?>
                                        <img class="table-img" src="<?= e(image_url($c['image'])) ?>" alt="" style="width:46px;height:38px">
                                    <?php else: ?>
                                        <div class="table-img" style="width:46px;height:38px;background:#f0ece8;display:flex;align-items:center;justify-content:center;color:var(--admin-muted)"><i class="fa-solid fa-gem"></i></div>
                                    <?php endif; ?>
                                    <strong><?= e($c['name']) ?></strong>
                                </div>
                            </td>
                            <td>/collections/<?= e($c['slug']) ?></td>
                            <td><?= e($c['collection_type'] ?? '—') ?></td>
                            <td title="Live = active products shown on the storefront">
                                <?= (int) $c['live_count'] ?> live<?php if ((int) $c['product_count'] !== (int) $c['live_count']): ?>
                                    <span style="color:var(--admin-muted)">/ <?= (int) $c['product_count'] ?> total</span><?php endif; ?>
                            </td>
                            <td><?= (int) $c['is_featured'] === 1 ? '✓' : '—' ?></td>
                            <td><?= (int) $c['sort_order'] ?></td>
                            <td>
                                <span class="badge <?= (int) $c['status'] === 1 ? 'green' : 'gray' ?>">
                                    <?= (int) $c['status'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <a class="btn btn-outline btn-xs" href="?edit=<?= (int) $c['id'] ?>">Edit</a>
                                <?php if ((int) $c['status'] === 1): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn btn-outline btn-xs">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn btn-outline btn-xs">Restore</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this collection permanently? Products stay untouched.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-danger btn-xs"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <!-- ADD / EDIT FORM -->
        <div class="card">
            <div class="card-title"><?= $editCol ? 'Edit Collection' : 'Add Collection' ?></div>
            <?php if ($editCol): ?>
                <a href="<?= url('/admin/collections.php') ?>" style="font-size:12.5px;display:block;margin-bottom:12px">← Cancel editing</a>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" action="<?= e(url('/admin/collections.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($editCol['id'] ?? 0) ?>">

                <div class="form-group">
                    <label for="col-name">Name <span class="required">*</span></label>
                    <input type="text" id="col-name" name="name" value="<?= e($editCol['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="col-slug">Slug</label>
                    <input type="text" id="col-slug" name="slug" placeholder="auto-generated" value="<?= e($editCol['slug'] ?? '') ?>">
                    <p class="help">Used in the URL: /collections/your-slug</p>
                </div>
                <div class="form-group">
                    <label for="col-type">Collection Type</label>
                    <select id="col-type" name="collection_type">
                        <?php
                        $types = ['seasonal', 'theme', 'curated', 'sale', 'wedding', 'festive', 'luxury', 'signature'];
                        $curType = $editCol['collection_type'] ?? '';
                        ?>
                        <option value="">— None —</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= e($t) ?>" <?= $curType === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="col-desc">Description</label>
                    <textarea id="col-desc" name="description" rows="3" maxlength="2000"><?= e($editCol['description'] ?? '') ?></textarea>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="col-image">Image</label>
                        <input type="file" id="col-image" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                        <?php if ($editCol && $editCol['image']): ?>
                            <p class="help">Current: <img src="<?= e(image_url($editCol['image'])) ?>" alt="" style="height:40px;border-radius:4px;vertical-align:middle"> Upload replaces it.</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="col-order">Sort Order</label>
                        <input type="number" id="col-order" name="sort_order" min="0" value="<?= e((string) ($editCol['sort_order'] ?? 0)) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="col-banner">Banner Image</label>
                    <input type="file" id="col-banner" name="banner" accept="image/jpeg,image/png,image/webp,image/gif">
                    <?php if ($editCol && $editCol['banner']): ?>
                        <p class="help">Current: <img src="<?= e(image_url($editCol['banner'])) ?>" alt="" style="height:40px;border-radius:4px;vertical-align:middle"> Upload replaces it.</p>
                    <?php endif; ?>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="col-start">Start Date</label>
                        <input type="date" id="col-start" name="start_date" value="<?= e($editCol['start_date'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="col-end">End Date</label>
                        <input type="date" id="col-end" name="end_date" value="<?= e($editCol['end_date'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="col-meta-title">SEO Title</label>
                    <input type="text" id="col-meta-title" name="meta_title" maxlength="200" value="<?= e($editCol['meta_title'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="col-meta-desc">SEO Description</label>
                    <input type="text" id="col-meta-desc" name="meta_description" maxlength="500" value="<?= e($editCol['meta_description'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="col-meta-keys">SEO Keywords</label>
                    <input type="text" id="col-meta-keys" name="meta_keywords" maxlength="300" value="<?= e($editCol['meta_keywords'] ?? '') ?>">
                </div>
                <div class="checkbox-line">
                    <input type="checkbox" id="col-status" name="status" value="1" <?= !isset($editCol['status']) || (int) $editCol['status'] === 1 ? 'checked' : '' ?>>
                    <label for="col-status">Active (visible on storefront)</label>
                </div>
                <div class="checkbox-line">
                    <input type="checkbox" id="col-featured" name="is_featured" value="1" <?= !empty($editCol['is_featured']) ? 'checked' : '' ?>>
                    <label for="col-featured">Featured (highlighted on homepage)</label>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $editCol ? 'Save Changes' : 'Create Collection' ?>
                </button>
            </form>
        </div>

        <?php if ($editCol): ?>
            <!-- PRODUCT ASSIGNER -->
            <div class="card" style="margin-top:20px">
                <div class="card-title">Products in “<?= e($editCol['name']) ?>” (<?= count($assignedIds) ?>)</div>
                <p style="font-size:12.5px;color:var(--admin-muted);margin-top:-6px">Tick the products that belong to this collection. The storefront collection page updates instantly.</p>
                <form method="post" action="<?= e(url('/admin/collections.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_products">
                    <input type="hidden" name="id" value="<?= (int) $editCol['id'] ?>">
                    <div style="max-height:360px;overflow-y:auto;border:1px solid var(--admin-border);border-radius:8px;padding:10px 14px;margin:12px 0">
                        <?php if (!$products): ?>
                            <p style="color:var(--admin-muted);font-size:13px">No products in the store yet.</p>
                        <?php endif; ?>
                        <?php foreach ($products as $p): ?>
                            <label class="checkbox-line" style="padding:7px 0;border-bottom:1px dashed #efe9e4">
                                <input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>" <?= isset($assignedSet[(int) $p['id']]) ? 'checked' : '' ?>>
                                <span style="flex:1;font-size:13.5px">
                                    <?= e($p['name']) ?>
                                    <?php if ($p['sku']): ?><span style="color:var(--admin-muted)"> — <?= e($p['sku']) ?></span><?php endif; ?>
                                </span>
                                <?php if ((int) $p['status'] !== 1): ?><span class="badge gray" style="font-size:10px">Inactive</span><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">
                        <i class="fa-solid fa-link"></i> Save Collection Products
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';