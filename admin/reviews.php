<?php
/**
 * Admin — Product reviews moderation.
 * Visitors submit reviews on the product page; they stay 'pending' until approved
 * here (unless "Auto-approve" is switched on). Approved/featured reviews show on
 * the storefront.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();
$db = db();

if (!tc_table_exists('reviews')) {
    $page_title = 'Reviews';
    $active = 'reviews';
    $content = '<div class="card" style="padding:32px">The <code>reviews</code> table is missing. Run <code>migration-fashlab-upgrade.sql</code> first.</div>';
    require __DIR__ . '/layout.php';
    exit;
}

$statuses = ['pending' => 'Pending', 'approved' => 'Approved', 'featured' => 'Featured', 'rejected' => 'Rejected'];

/* ---------------------------------------------------------------------------
   Actions
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    $action = post('action', 20);
    $id = post_int('id');
    $back = url('/admin/reviews.php' . (($_POST['back'] ?? '') !== '' ? '?' . preg_replace('/[^a-z0-9=&_%-]/i', '', (string) $_POST['back']) : ''));

    if ($action === 'auto_approve') {
        $on = ($_POST['value'] ?? '0') === '1';
        update_setting('reviews_auto_approve', $on ? '1' : '0');
        record_activity('settings_update', 'setting', 0, 'Review auto-approve ' . ($on ? 'enabled' : 'disabled'));
        flash_set('success', $on ? 'New reviews will now go live immediately.' : 'New reviews will now wait for your approval.');
        redirect($back);
    }

    if ($id > 0 && isset($statuses[$action])) {
        $db->prepare('UPDATE reviews SET status = ? WHERE id = ?')->execute([$action, $id]);
        record_activity('review_' . $action, 'review', $id, 'Review marked ' . $action);
        flash_set('success', 'Review ' . strtolower($statuses[$action]) . '.');
        redirect($back);
    }

    if ($id > 0 && $action === 'delete') {
        $db->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
        record_activity('review_delete', 'review', $id, 'Review deleted');
        flash_set('success', 'Review deleted.');
        redirect($back);
    }
}

/* ---------------------------------------------------------------------------
   Listing
--------------------------------------------------------------------------- */
$status = get_string('status', 20);
if ($status !== '' && !isset($statuses[$status])) {
    $status = '';
}
$q = get_string('q', 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'r.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(r.name LIKE ? OR r.email LIKE ? OR r.body LIKE ? OR p.name LIKE ?)';
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM reviews r JOIN products p ON p.id = r.product_id {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT r.*, p.name AS product_name, p.slug AS product_slug
     FROM reviews r JOIN products p ON p.id = r.product_id
     {$whereSql}
     ORDER BY (r.status = 'pending') DESC, r.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

$counts = array_fill_keys(array_keys($statuses), 0);
foreach ($db->query('SELECT status, COUNT(*) AS n FROM reviews GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['n'];
}
$avgRow = $db->query("SELECT ROUND(AVG(rating), 1) AS avg, COUNT(*) AS n FROM reviews WHERE status IN ('approved','featured')")->fetch();
$autoApprove = setting('reviews_auto_approve', '1') === '1';

$qsParts = [];
if ($status !== '') $qsParts[] = 'status=' . urlencode($status);
if ($q !== '') $qsParts[] = 'q=' . urlencode($q);
$qs = implode('&', $qsParts);

$badgeClass = ['pending' => 'gold', 'approved' => 'green', 'featured' => 'blue', 'rejected' => 'gray'];

$page_title = 'Reviews';
$active = 'reviews';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Product Reviews</h1>
        <p>
            <?= (int) $avgRow['n'] ?> live review<?= (int) $avgRow['n'] === 1 ? '' : 's' ?>
            <?php if ((int) $avgRow['n'] > 0): ?> · average <strong><?= e((string) $avgRow['avg']) ?> ★</strong><?php endif; ?>
            · <?= $counts['pending'] ?> waiting for approval
        </p>
    </div>
    <div class="page-actions">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="auto_approve">
            <input type="hidden" name="value" value="<?= $autoApprove ? '0' : '1' ?>">
            <input type="hidden" name="back" value="<?= e($qs) ?>">
            <button class="btn <?= $autoApprove ? 'btn-primary' : 'btn-outline' ?>" title="When on, new reviews appear on the store without approval">
                <i class="fa-solid fa-<?= $autoApprove ? 'toggle-on' : 'toggle-off' ?>"></i>
                Auto-approve: <?= $autoApprove ? 'On' : 'Off' ?>
            </button>
        </form>
    </div>
</div>

<div class="filter-bar" style="flex-wrap:wrap">
    <a href="<?= e(url('/admin/reviews.php' . ($q !== '' ? '?q=' . urlencode($q) : ''))) ?>" class="btn btn-sm <?= $status === '' ? 'btn-primary' : 'btn-outline' ?>">All (<?= array_sum($counts) ?>)</a>
    <?php foreach ($statuses as $key => $label): ?>
        <a href="<?= e(url('/admin/reviews.php?status=' . $key . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>" class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-outline' ?>"><?= e($label) ?> (<?= $counts[$key] ?>)</a>
    <?php endforeach; ?>
    <form method="get" action="<?= e(url('/admin/reviews.php')) ?>" style="display:flex;gap:8px;margin-left:auto">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <input type="search" name="q" placeholder="Search name, email, product…" value="<?= e($q) ?>">
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Review</th>
                    <th>Product</th>
                    <th>Rating</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$reviews): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--admin-muted);padding:36px">No reviews here yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($reviews as $r): ?>
                    <tr>
                        <td style="max-width:420px">
                            <strong><?= e($r['name']) ?></strong>
                            <?php if ((int) $r['is_verified_purchase'] === 1): ?>
                                <span class="badge green" style="margin-left:6px"><i class="fa-solid fa-circle-check"></i> Verified</span>
                            <?php endif; ?>
                            <div style="font-size:12px;color:var(--admin-muted)"><?= e($r['email']) ?></div>
                            <?php if (($r['title'] ?? '') !== ''): ?><div style="margin-top:6px;font-weight:600"><?= e($r['title']) ?></div><?php endif; ?>
                            <div style="margin-top:4px;white-space:pre-line"><?= e((string) $r['body']) ?></div>
                        </td>
                        <td><a href="<?= e(product_url($r['product_slug'])) ?>" target="_blank"><?= e($r['product_name']) ?></a></td>
                        <td style="white-space:nowrap;color:#c8951e">
                            <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa-<?= $i <= (int) $r['rating'] ? 'solid' : 'regular' ?> fa-star"></i><?php endfor; ?>
                        </td>
                        <td><span class="badge <?= $badgeClass[$r['status']] ?? 'gray' ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span></td>
                        <td style="white-space:nowrap"><?= e(format_date($r['created_at'])) ?></td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php foreach (['approved' => ['fa-check', 'Approve'], 'featured' => ['fa-star', 'Feature'], 'rejected' => ['fa-ban', 'Reject']] as $act => [$icon, $label]): ?>
                                <?php if ($r['status'] !== $act): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="<?= $act ?>">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="back" value="<?= e($qs) ?>">
                                        <button class="btn btn-outline btn-xs" title="<?= $label ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $label ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this review permanently?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="back" value="<?= e($qs) ?>">
                                <button class="btn btn-danger btn-xs" title="Delete"><i class="fa-solid fa-trash"></i></button>
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
