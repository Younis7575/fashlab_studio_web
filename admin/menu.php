<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/*
 * Storefront menu manager (menu_items). The top navigation, mega-menu columns
 * and footer link lists are all read from this table.
 */

$admin = require_admin();
$db = db();

const TC_MENU_LOCATIONS = [
    'main'           => 'Main navigation',
    'mobile'         => 'Mobile menu',
    'footer_shop'    => 'Footer — Shop',
    'footer_explore' => 'Footer — Explore',
    'footer_care'    => 'Footer — Customer Care',
    'footer_about'   => 'Footer — About',
];

$tableReady = tc_table_exists('menu_items');
$location   = (string) ($_GET['location'] ?? $_POST['location'] ?? 'main');
if (!array_key_exists($location, TC_MENU_LOCATIONS)) {
    $location = 'main';
}
$backUrl = url('/admin/menu.php?location=' . $location);

/* ---------------------------------------------------------------------------
   POST handlers
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    csrf_require($_POST['csrf_token'] ?? null);
    $action = post('action', 20);
    $id     = post_int('id');

    if ($action === 'save') {
        $label    = post('label', 100);
        $urlValue = post('url', 255);
        $parentId = post_int('parent_id');
        $group    = post('group_label', 60);
        $sort     = post_int('sort_order');
        $status   = !empty($_POST['status']) ? 1 : 0;
        $desktop  = !empty($_POST['desktop_visible']) ? 1 : 0;
        $mobile   = !empty($_POST['mobile_visible']) ? 1 : 0;

        $errors = [];
        if ($label === '')    $errors[] = 'Label is required.';
        if ($urlValue === '') $errors[] = 'Link (URL) is required.';
        if (preg_match('#^\s*(javascript|data|vbscript):#i', $urlValue)) $errors[] = 'That link type is not allowed.';
        if ($parentId > 0) {
            $chk = $db->prepare('SELECT id FROM menu_items WHERE id = ? AND location = ? AND parent_id IS NULL');
            $chk->execute([$parentId, $location]);
            if (!$chk->fetch() || $parentId === $id) $errors[] = 'Choose a valid parent item.';
        }

        if ($errors) {
            flash_set('error', implode(' ', $errors));
            redirect($backUrl . ($id > 0 ? '&edit=' . $id : ''));
        }

        $params = [$parentId ?: null, $label, $urlValue, $group !== '' ? $group : null, $location, $sort, $status, $desktop, $mobile];
        if ($id > 0) {
            $db->prepare(
                'UPDATE menu_items SET parent_id = ?, label = ?, url = ?, group_label = ?, location = ?,
                        sort_order = ?, status = ?, desktop_visible = ?, mobile_visible = ?
                 WHERE id = ?'
            )->execute([...$params, $id]);
            record_activity('menu_update', 'menu_item', $id, 'Updated menu link "' . $label . '"');
            flash_set('success', 'Menu link updated.');
        } else {
            $db->prepare(
                'INSERT INTO menu_items (parent_id, label, url, group_label, location, sort_order, status, desktop_visible, mobile_visible)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($params);
            record_activity('menu_create', 'menu_item', (int) $db->lastInsertId(), 'Added menu link "' . $label . '"');
            flash_set('success', 'Menu link added.');
        }
        redirect($backUrl);
    }

    if (($action === 'activate' || $action === 'deactivate') && $id > 0) {
        $db->prepare('UPDATE menu_items SET status = ? WHERE id = ?')->execute([$action === 'activate' ? 1 : 0, $id]);
        record_activity('menu_' . $action, 'menu_item', $id, 'Menu link ' . $action . 'd');
        flash_set('success', $action === 'activate' ? 'Menu link shown.' : 'Menu link hidden.');
        redirect($backUrl);
    }

    if ($action === 'delete' && $id > 0) {
        $db->prepare('DELETE FROM menu_items WHERE id = ?')->execute([$id]);
        record_activity('menu_delete', 'menu_item', $id, 'Deleted menu link');
        flash_set('success', 'Menu link deleted.');
        redirect($backUrl);
    }

    redirect($backUrl);
}

/* ---------------------------------------------------------------------------
   View
--------------------------------------------------------------------------- */
$rows = [];
$editItem = null;
if ($tableReady) {
    $st = $db->prepare('SELECT * FROM menu_items WHERE location = ? ORDER BY sort_order ASC, id ASC');
    $st->execute([$location]);
    $rows = $st->fetchAll();

    $editId = (int) ($_GET['edit'] ?? 0);
    foreach ($rows as $r) {
        if ((int) $r['id'] === $editId) $editItem = $r;
    }
}

$topLevel = array_values(array_filter($rows, static fn(array $r): bool => empty($r['parent_id'])));
$children = [];
foreach ($rows as $r) {
    if (!empty($r['parent_id'])) $children[(int) $r['parent_id']][] = $r;
}

$categoryLinks = $db->query('SELECT name, slug FROM categories WHERE status = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
$collectionLinks = tc_table_exists('collections')
    ? $db->query('SELECT name, slug FROM collections WHERE status = 1 ORDER BY sort_order ASC, name ASC')->fetchAll()
    : [];

$page_title = 'Menu';
$active     = 'menu';

$renderRow = static function (array $r, bool $isChild) use ($location): string {
    ob_start(); ?>
    <tr>
        <td style="<?= $isChild ? 'padding-left:34px' : '' ?>">
            <?= $isChild ? '<span style="color:var(--admin-muted)">↳</span> ' : '' ?><strong><?= e($r['label']) ?></strong>
        </td>
        <td style="font-size:12.5px;word-break:break-all"><?= e($r['url']) ?></td>
        <td><?= e($r['group_label'] ?? '') ?: '—' ?></td>
        <td><?= (int) $r['sort_order'] ?></td>
        <td><span class="badge <?= (int) $r['status'] === 1 ? 'green' : 'gray' ?>"><?= (int) $r['status'] === 1 ? 'Visible' : 'Hidden' ?></span></td>
        <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-outline btn-xs" href="?location=<?= e($location) ?>&edit=<?= (int) $r['id'] ?>">Edit</a>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="location" value="<?= e($location) ?>">
                <input type="hidden" name="action" value="<?= (int) $r['status'] === 1 ? 'deactivate' : 'activate' ?>">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-outline btn-xs"><?= (int) $r['status'] === 1 ? 'Hide' : 'Show' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this link<?= $isChild ? '' : ' and every link under it' ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="location" value="<?= e($location) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-danger btn-xs" title="Delete"><i class="fa-solid fa-trash"></i></button>
            </form>
        </td>
    </tr>
    <?php return (string) ob_get_clean();
};

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Menu</h1>
        <p>Links in the storefront navigation and footer. Links placed under a parent appear in its dropdown; links with a column heading form mega-menu columns.</p>
    </div>
</div>

<?php if (!$tableReady): ?>
    <div class="card"><p>The menu table has not been created on this database yet, so the storefront uses its built-in navigation.</p></div>
<?php else: ?>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    <?php foreach (TC_MENU_LOCATIONS as $key => $name): ?>
        <a class="btn btn-sm <?= $key === $location ? 'btn-primary' : 'btn-outline' ?>" href="?location=<?= e($key) ?>"><?= e($name) ?></a>
    <?php endforeach; ?>
</div>

<div class="detail-grid">
    <div class="card">
        <div class="card-title"><?= e(TC_MENU_LOCATIONS[$location]) ?> (<?= count($rows) ?>)</div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Label</th><th>Link</th><th>Column</th><th>Order</th><th>Status</th><th style="text-align:right">Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--admin-muted);padding:36px">No links here yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($topLevel as $r): ?>
                        <?= $renderRow($r, false) ?>
                        <?php foreach ($children[(int) $r['id']] ?? [] as $c): ?>
                            <?= $renderRow($c, true) ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title"><?= $editItem ? 'Edit Link' : 'Add Link' ?></div>
            <?php if ($editItem): ?>
                <a href="<?= e($backUrl) ?>" style="font-size:12.5px;display:block;margin-bottom:12px">← Cancel editing</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/menu.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="location" value="<?= e($location) ?>">
                <input type="hidden" name="id" value="<?= (int) ($editItem['id'] ?? 0) ?>">

                <div class="form-group">
                    <label for="menu-label">Label *</label>
                    <input type="text" id="menu-label" name="label" maxlength="100" required value="<?= e($editItem['label'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="menu-url">Link *</label>
                    <input type="text" id="menu-url" name="url" maxlength="255" required list="menu-url-suggestions" placeholder="category.php?slug=…" value="<?= e($editItem['url'] ?? '') ?>">
                    <datalist id="menu-url-suggestions">
                        <?php foreach ($categoryLinks as $c): ?>
                            <option value="category.php?slug=<?= e($c['slug']) ?>"><?= e($c['name']) ?> (category)</option>
                        <?php endforeach; ?>
                        <?php foreach ($collectionLinks as $c): ?>
                            <option value="collection.php?slug=<?= e($c['slug']) ?>"><?= e($c['name']) ?> (collection)</option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="help">Pick a category or collection, or type a page such as <code>shop.php?sale=1</code>.</p>
                </div>
                <div class="form-group">
                    <label for="menu-parent">Show under</label>
                    <select id="menu-parent" name="parent_id">
                        <option value="0">— Top level —</option>
                        <?php foreach ($topLevel as $t): ?>
                            <?php if ((int) $t['id'] === (int) ($editItem['id'] ?? 0)) continue; ?>
                            <option value="<?= (int) $t['id'] ?>" <?= (int) ($editItem['parent_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="menu-group">Column heading (mega menu)</label>
                    <input type="text" id="menu-group" name="group_label" maxlength="60" placeholder="e.g. Gifts" value="<?= e($editItem['group_label'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="menu-sort">Sort order</label>
                    <input type="number" id="menu-sort" name="sort_order" value="<?= (int) ($editItem['sort_order'] ?? 0) ?>">
                </div>
                <div class="checkbox-line">
                    <input type="checkbox" id="menu-status" name="status" value="1" <?= !isset($editItem['status']) || (int) $editItem['status'] === 1 ? 'checked' : '' ?>>
                    <label for="menu-status">Visible on the storefront</label>
                </div>
                <div class="checkbox-line">
                    <input type="checkbox" id="menu-desktop" name="desktop_visible" value="1" <?= !isset($editItem['desktop_visible']) || (int) $editItem['desktop_visible'] === 1 ? 'checked' : '' ?>>
                    <label for="menu-desktop">Show on desktop</label>
                </div>
                <div class="checkbox-line">
                    <input type="checkbox" id="menu-mobile" name="mobile_visible" value="1" <?= !isset($editItem['mobile_visible']) || (int) $editItem['mobile_visible'] === 1 ? 'checked' : '' ?>>
                    <label for="menu-mobile">Show on mobile</label>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $editItem ? 'Save Changes' : 'Add Link' ?>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
