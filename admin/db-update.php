<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/*
 * One-click, idempotent application of the additive column changes listed in
 * tc_schema_updates() (same as migration-personalisation.sql), so the live
 * database can be updated without phpMyAdmin.
 */

$admin = require_admin();
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);

    if (post('action', 20) === 'apply') {
        $applied = [];
        $failed  = [];
        foreach (tc_pending_schema_updates() as $update) {
            try {
                $db->exec($update['ddl']);
                $applied[] = $update['table'] . '.' . $update['column'];
            } catch (Throwable $e) {
                error_log('[db-update] ' . $e->getMessage());
                $failed[] = $update['table'] . '.' . $update['column'];
            }
        }
        if ($applied) {
            record_activity('db_update', null, null, 'Applied database update: ' . implode(', ', $applied));
        }
        if ($failed) {
            flash_set('error', 'Could not apply: ' . implode(', ', $failed) . '. Import migration-personalisation.sql in phpMyAdmin instead.');
        } elseif ($applied) {
            flash_set('success', 'Database updated: ' . implode(', ', $applied) . '.');
        } else {
            flash_set('success', 'Database is already up to date.');
        }
    }
    redirect(url('/admin/db-update.php'));
}

$updates = tc_schema_updates();
$pending = tc_pending_schema_updates();
$pendingKeys = array_map(static fn(array $u): string => $u['table'] . '.' . $u['column'], $pending);

$page_title = 'Database Update';
$active     = 'db_update';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Database Update</h1>
        <p>Adds the columns needed for image alt text and product personalisation. Only new, empty columns are added — no existing data is changed.</p>
    </div>
</div>

<div class="card" style="max-width:760px">
    <div class="card-title">Changes</div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Change</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($updates as $u): ?>
                    <?php $isPending = in_array($u['table'] . '.' . $u['column'], $pendingKeys, true); ?>
                    <tr>
                        <td><?= e($u['label']) ?></td>
                        <td><span class="badge <?= $isPending ? 'gold' : 'green' ?>"><?= $isPending ? 'Pending' : 'Applied' ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pending): ?>
        <form method="post" style="margin-top:18px" onsubmit="return confirm('Apply the database update now?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-database"></i> Apply update</button>
        </form>
    <?php else: ?>
        <p style="margin-top:18px;color:var(--admin-muted)">Everything is up to date.</p>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
