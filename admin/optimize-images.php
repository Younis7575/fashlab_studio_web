<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/*
 * Shrinks the existing product / category / collection images that were
 * uploaded before automatic optimisation existed (often 1–8 MB each).
 * Works in small batches so shared hosting never times out. The original
 * files are kept on disk; only the database path changes to the new file.
 */

$admin = require_admin();
$db = db();

const TC_OPT_MIN_BYTES = 300 * 1024;
const TC_OPT_BATCH     = 8;

/** Every image reference we can optimise: [table, id column, id, path column, path]. */
function tc_opt_candidates(PDO $db): array
{
    $sources = [
        ['product_images', 'image'],
        ['categories', 'image'],
        ['collections', 'image'],
        ['collections', 'banner'],
    ];
    $root = realpath(__DIR__ . '/..');
    $out = [];
    foreach ($sources as [$table, $col]) {
        if (!tc_table_exists($table) || !tc_column_exists($table, $col)) {
            continue;
        }
        $rows = $db->query("SELECT id, `$col` AS path FROM `$table` WHERE `$col` LIKE 'uploads/%'")->fetchAll();
        foreach ($rows as $r) {
            $abs = $root . '/' . ltrim((string) $r['path'], '/');
            if (!is_file($abs)) {
                continue;
            }
            $size = (int) filesize($abs);
            $dims = @getimagesize($abs);
            $big  = $size > TC_OPT_MIN_BYTES || ($dims && max($dims[0], $dims[1]) > TC_IMAGE_MAX_DIMENSION);
            if ($big && !str_ends_with(strtolower($abs), '.gif')) {
                $out[] = ['table' => $table, 'col' => $col, 'id' => (int) $r['id'], 'path' => (string) $r['path'], 'abs' => $abs, 'size' => $size];
            }
        }
    }
    return $out;
}

/** Remember files that cannot be shrunk so batches do not retry them forever. */
function tc_opt_skipped(): array
{
    return (array) ($_SESSION['tc_opt_skipped'] ?? []);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    @set_time_limit(120);

    $done = 0;
    $saved = 0;
    $skipped = tc_opt_skipped();
    $batch = array_slice(array_values(array_filter(
        tc_opt_candidates($db),
        static fn(array $c): bool => !in_array($c['path'], $skipped, true)
    )), 0, TC_OPT_BATCH);

    foreach ($batch as $c) {
        $new = tc_optimize_image_file($c['abs'], false);
        if ($new === null) {
            $skipped[] = $c['path'];
            continue;
        }
        $newRel = preg_replace('#/[^/]+$#', '/' . basename($new), $c['path']);
        $db->prepare("UPDATE `{$c['table']}` SET `{$c['col']}` = ? WHERE id = ?")->execute([$newRel, $c['id']]);
        $saved += max(0, $c['size'] - (int) filesize($new));
        $done++;
    }
    $_SESSION['tc_opt_skipped'] = array_values(array_unique($skipped));

    if ($done > 0) {
        record_activity('images_optimize', null, null, 'Optimised ' . $done . ' images');
    }
    flash_set('success', 'Optimised ' . $done . ' image(s), saved ' . round($saved / 1048576, 1) . ' MB.');
    redirect(url('/admin/optimize-images.php' . (!empty($_POST['auto']) ? '?auto=1' : '')));
}

$skipped    = tc_opt_skipped();
$candidates = array_values(array_filter(tc_opt_candidates($db), static fn(array $c): bool => !in_array($c['path'], $skipped, true)));
$totalBytes = array_sum(array_column($candidates, 'size'));
$auto       = !empty($_GET['auto']) && $candidates;

$page_title = 'Optimise Images';
$active     = 'optimize_images';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Optimise Images</h1>
        <p>Large images make product, category and collection pages load slowly. This resizes them to <?= (int) TC_IMAGE_MAX_DIMENSION ?>px and re-saves them as <?= tc_webp_supported() ? 'WebP' : 'JPEG' ?>. New uploads are optimised automatically.</p>
    </div>
</div>

<div class="card" style="max-width:760px">
    <?php if (!tc_image_optimizer_available()): ?>
        <p>This server's PHP has no image library (GD), so images cannot be optimised here.</p>
    <?php else: ?>
        <p style="font-size:15px">
            <strong><?= count($candidates) ?></strong> large image(s) left —
            <strong><?= round($totalBytes / 1048576, 1) ?> MB</strong> in total.
            <?php if ($skipped): ?><span style="color:var(--admin-muted)">(<?= count($skipped) ?> could not be reduced and were skipped.)</span><?php endif; ?>
        </p>
        <?php if ($candidates): ?>
            <form method="post" id="optForm" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="auto" value="1">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Optimise all (<?= (int) TC_OPT_BATCH ?> at a time)</button>
            </form>
            <?php if ($auto): ?>
                <p style="margin-top:10px;color:var(--admin-muted)">Working… this page continues automatically until every image is done.</p>
                <script>setTimeout(function () { document.getElementById('optForm').submit(); }, 400);</script>
            <?php endif; ?>
        <?php else: ?>
            <p style="margin-top:10px;color:var(--admin-success, #2e7d32)">All images are optimised.</p>
        <?php endif; ?>
        <p class="help" style="margin-top:14px">Originals stay on the server; only the image the site uses changes.</p>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
