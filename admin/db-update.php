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

/**
 * Read-only status of every migration-*.sql file: which tables / columns it
 * creates that are missing from this database.
 */
function tc_migration_status(): array
{
    $status = [];
    foreach (glob(__DIR__ . '/../migration-*.sql') ?: [] as $file) {
        $sql = (string) file_get_contents($file);
        $missing = [];
        $checked = 0;
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?/i', $sql, $m)) {
            foreach (array_unique($m[1]) as $table) {
                $checked++;
                if (!tc_table_exists($table)) $missing[] = 'table ' . $table;
            }
        }
        $columns = [];
        if (preg_match_all("/tc_add_column\\(\\s*'([a-z0-9_]+)'\\s*,\\s*'([a-z0-9_]+)'/i", $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $columns[$hit[1] . '.' . $hit[2]] = [$hit[1], $hit[2]];
        }
        if (preg_match_all('/ALTER\s+TABLE\s+`?([a-z0-9_]+)`?\s+ADD\s+COLUMN\s+`?([a-z0-9_]+)`?/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $columns[$hit[1] . '.' . $hit[2]] = [$hit[1], $hit[2]];
        }
        foreach ($columns as $key => [$table, $column]) {
            $checked++;
            if (!tc_column_exists($table, $column)) $missing[] = 'column ' . $key;
        }
        $status[basename($file)] = ['checked' => $checked, 'missing' => $missing];
    }
    ksort($status);
    return $status;
}

$migrationStatus = tc_migration_status();
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

<div class="card" style="max-width:760px;margin-top:20px">
    <div class="card-title">All migration files</div>
    <p class="help" style="margin-bottom:12px">Read-only check: every table and column each <code>migration-*.sql</code> file creates, compared with this database.</p>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>File</th><th>Checked</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($migrationStatus as $file => $st): ?>
                    <tr>
                        <td><?= e($file) ?></td>
                        <td><?= (int) $st['checked'] ?></td>
                        <td>
                            <?php if (!$st['missing']): ?>
                                <span class="badge green">Applied</span>
                            <?php else: ?>
                                <span class="badge gold"><?= count($st['missing']) ?> missing</span>
                                <div style="font-size:12px;color:var(--admin-muted);margin-top:4px"><?= e(implode(', ', array_slice($st['missing'], 0, 12))) ?><?= count($st['missing']) > 12 ? '…' : '' ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
