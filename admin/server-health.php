<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api-auth.php';

/*
 * Read-only server diagnostics: PHP configuration, OPcache, database latency,
 * self-request timing and the tail of the PHP error log. Changes nothing.
 */

$admin = require_admin();
$db = db();

/* The only action here: remove mobile-app tokens that hold nothing. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    if (post('action', 30) === 'clean_tokens' && api_tokens_available()) {
        @set_time_limit(120);
        $removed = 0;
        for ($i = 0; $i < 100; $i++) {
            $n = api_tokens_gc(1000);
            $removed += $n;
            if ($n === 0) break;
        }
        record_activity('api_tokens_cleanup', null, null, 'Removed ' . $removed . ' empty/expired app tokens');
        flash_set('success', 'Removed ' . $removed . ' empty or expired app tokens.');
    }
    redirect(url('/admin/server-health.php'));
}

$tokenStats = null;
if (api_tokens_available()) {
    $tokenStats = [
        'total'   => (int) $db->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn(),
        'expired' => (int) $db->query('SELECT COUNT(*) FROM api_tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()')->fetchColumn(),
        'empty'   => (int) $db->query('SELECT COUNT(*) FROM api_tokens WHERE ' . api_tokens_empty_idle_sql())->fetchColumn(),
        'today'   => (int) $db->query('SELECT COUNT(*) FROM api_tokens WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)')->fetchColumn(),
    ];
}

$ms = static fn(float $start): float => round((microtime(true) - $start) * 1000, 1);

/* ---- PHP ---------------------------------------------------------------- */
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$opcacheInfo = 'not available';
if (is_array($opcache)) {
    $stats = $opcache['opcache_statistics'] ?? [];
    $opcacheInfo = !empty($opcache['opcache_enabled'])
        ? 'enabled — hit rate ' . round((float) ($stats['opcache_hit_rate'] ?? 0), 1) . '%, '
          . (int) ($stats['num_cached_scripts'] ?? 0) . ' scripts cached'
        : 'installed but disabled';
} elseif (ini_get('opcache.enable')) {
    $opcacheInfo = 'enabled (status hidden by host)';
}

$load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

$php = [
    'PHP version'          => PHP_VERSION . ' (' . PHP_SAPI . ')',
    'OPcache'              => $opcacheInfo,
    'memory_limit'         => (string) ini_get('memory_limit'),
    'max_execution_time'   => (string) ini_get('max_execution_time') . ' s',
    'upload_max_filesize'  => (string) ini_get('upload_max_filesize'),
    'post_max_size'        => (string) ini_get('post_max_size'),
    'Image library'        => tc_image_optimizer_available() ? 'GD' . (tc_webp_supported() ? ' with WebP' : ' (no WebP)') : 'none',
    'Server load (1/5/15m)' => is_array($load) ? implode(' / ', array_map(static fn($v) => round((float) $v, 2), $load)) : 'hidden by host',
    'Server software'      => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
];

/* ---- Database ------------------------------------------------------------ */
$t = microtime(true);
for ($i = 0; $i < 5; $i++) {
    $db->query('SELECT 1')->fetchColumn();
}
$dbPing = round(((microtime(true) - $t) * 1000) / 5, 2);

$t = microtime(true);
$sample = new_arrivals(12);
$dbProducts = $ms($t);

$t = microtime(true);
$cats = categories_with_counts(8);
$dbCategories = $ms($t);

$dbVersion = (string) $db->query('SELECT VERSION()')->fetchColumn();
$tableSizes = [];
try {
    $rows = $db->query(
        "SELECT TABLE_NAME AS t, TABLE_ROWS AS r, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) AS mb
         FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC LIMIT 6"
    )->fetchAll();
    foreach ($rows as $r) {
        $tableSizes[] = $r['t'] . ' (' . (int) $r['r'] . ' rows, ' . $r['mb'] . ' MB)';
    }
} catch (Throwable $e) {
    $tableSizes[] = 'unavailable';
}

$database = [
    'MySQL version'                  => $dbVersion,
    'Round trip (SELECT 1, avg of 5)' => $dbPing . ' ms',
    'Product listing query (12)'     => $dbProducts . ' ms (' . count($sample) . ' rows)',
    'Category counts query'          => $dbCategories . ' ms (' . count($cats) . ' rows)',
    'Largest tables'                 => implode(', ', $tableSizes),
];

/* ---- Self request: page time measured on the server, without the visitor's network ---- */
$selfChecks = [];
if (!empty($_GET['selftest'])) {
    $base = rtrim((string) (defined('BASE_URL') && BASE_URL !== '' ? BASE_URL : ''), '/');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin = preg_match('#^https?://#', $base) ? $base : $scheme . '://' . $host . $base;
    foreach (['/robots.txt', '/index.php', '/collections.php', '/shop.php'] as $path) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "User-Agent: FashlabHealthCheck\r\n"]]);
        $t = microtime(true);
        $body = @file_get_contents($origin . $path, false, $ctx);
        $status = isset($http_response_header[0]) ? (string) $http_response_header[0] : 'no response';
        $selfChecks[$path] = $ms($t) . ' ms — ' . $status . ($body !== false ? ' — ' . round(strlen($body) / 1024, 1) . ' KB' : '');
    }
}

/* ---- Uploads ---------------------------------------------------------- */
$uploadsDir = realpath(__DIR__ . '/../uploads');
$uploadBytes = 0;
$uploadCount = 0;
$bigFiles = 0;
if ($uploadsDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $uploadCount++;
            $uploadBytes += $f->getSize();
            if ($f->getSize() > 500 * 1024) $bigFiles++;
        }
    }
}
$free = $uploadsDir ? @disk_free_space($uploadsDir) : false;
$storage = [
    'Upload files'          => $uploadCount . ' files, ' . round($uploadBytes / 1048576, 1) . ' MB (' . $bigFiles . ' over 500 KB, incl. kept originals)',
    'Free disk space'       => $free !== false ? round($free / 1073741824, 1) . ' GB' : 'hidden by host',
];

/* ---- Error log tail ------------------------------------------------------- */
$logFile = realpath(__DIR__ . '/../logs/php-error.log') ?: (string) ini_get('error_log');
$logLines = [];
if ($logFile && is_file($logFile) && is_readable($logFile)) {
    $size = filesize($logFile);
    $fh = fopen($logFile, 'rb');
    if ($fh) {
        fseek($fh, max(0, $size - 16384));
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $logLines = array_slice(array_filter(explode("\n", $chunk), 'strlen'), -40);
    }
}

$pageTime = round((microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 1);

$page_title = 'Server Health';
$active     = 'server_health';

$renderTable = static function (string $title, array $rows): string {
    $html = '<div class="card" style="margin-bottom:18px"><div class="card-title">' . e($title) . '</div><div class="table-wrap"><table class="admin-table"><tbody>';
    foreach ($rows as $k => $v) {
        $html .= '<tr><td style="width:38%;font-weight:600">' . e((string) $k) . '</td><td>' . e((string) $v) . '</td></tr>';
    }
    return $html . '</tbody></table></div></div>';
};

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Server Health</h1>
        <p>Read-only diagnostics for the hosting server. This page was generated in <strong><?= $pageTime ?> ms</strong> of server time.</p>
    </div>
</div>

<div style="max-width:900px">
    <?= $renderTable('PHP', $php) ?>
    <?= $renderTable('Database', $database) ?>
    <?= $renderTable('Storage', $storage) ?>

    <?php if ($tokenStats !== null): ?>
    <div class="card" style="margin-bottom:18px">
        <div class="card-title">Mobile app tokens</div>
        <p style="font-size:14px">
            <strong><?= $tokenStats['total'] ?></strong> total —
            <?= $tokenStats['today'] ?> created in the last 24 h,
            <?= $tokenStats['expired'] ?> expired,
            <strong><?= $tokenStats['empty'] ?></strong> empty and idle (no cart, coupon or wishlist; unused for a day).
        </p>
        <p class="help">Removing empty tokens loses nothing: that phone just gets a new token next time. This also runs automatically on about 1 in 100 app requests.</p>
        <?php if ($tokenStats['expired'] + $tokenStats['empty'] > 0): ?>
            <form method="post" style="margin-top:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clean_tokens">
                <button type="submit" class="btn btn-outline btn-sm"><i class="fa-solid fa-broom"></i> Clean up <?= $tokenStats['expired'] + $tokenStats['empty'] ?> tokens now</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:18px">
        <div class="card-title">Self test (server → its own website)</div>
        <?php if ($selfChecks): ?>
            <div class="table-wrap"><table class="admin-table"><tbody>
                <?php foreach ($selfChecks as $path => $result): ?>
                    <tr><td style="width:38%;font-weight:600"><?= e($path) ?></td><td><?= e($result) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
            <p class="help">Requests a few pages from the server itself, so the times exclude your internet connection.</p>
            <a class="btn btn-outline btn-sm" href="?selftest=1" style="margin-top:8px">Run self test</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">PHP error log (last <?= count($logLines) ?> lines)</div>
        <?php if ($logLines): ?>
            <pre style="white-space:pre-wrap;font-size:11.5px;max-height:420px;overflow:auto;background:#faf7f5;padding:12px;border-radius:6px"><?= e(implode("\n", $logLines)) ?></pre>
        <?php else: ?>
            <p class="help">No error log found or it is empty.</p>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
