<?php
/**
 * Application bootstrap — required at the top of every public page.
 * Loads configuration, error handling, the session and all shared layers.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
   1. Configuration (config/config.php is NOT committed — see config.example.php)
--------------------------------------------------------------------------- */
if (!defined('TC_CONFIG_LOADED')) {
    $configFile = __DIR__ . '/../config/config.php';
    if (!is_file($configFile)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Setup Required</title>'
           . '<style>body{font-family:sans-serif;background:#faf4f5;color:#3b2b26;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
           . '.card{max-width:560px;padding:48px;background:#fff;border:1px solid #e7d4d8;border-radius:8px}'
           . 'h1{font-size:24px;margin:0 0 12px}code{background:#f3e5e8;padding:2px 6px;border-radius:4px}</style></head>'
           . '<body><div class="card"><h1>Configuration required</h1>'
           . '<p>Copy <code>config/config.example.php</code> to <code>config/config.php</code> and enter your database '
           . 'credentials. See <code>SETUP.md</code> for full installation instructions.</p></div></body></html>';
        exit;
    }
    require $configFile;
    define('TC_CONFIG_LOADED', true);
}

/* ---------------------------------------------------------------------------
   2. Error handling — never leak details to customers in production.
--------------------------------------------------------------------------- */
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php-error.log');
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[tayyaba] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        require __DIR__ . '/../500.php';
    } else {
        echo 'An unexpected error occurred.';
    }
    exit;
});

/* ---------------------------------------------------------------------------
   3. Session (secure cookie flags)
--------------------------------------------------------------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------------------------------------------------------------------------
   4. Shared layers
--------------------------------------------------------------------------- */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/product-functions.php';
require_once __DIR__ . '/enterprise.php';
require_once __DIR__ . '/order-service.php';
require_once __DIR__ . '/auth.php';

/* ---------------------------------------------------------------------------
   5. Store maintenance mode (admin unaffected)
--------------------------------------------------------------------------- */
// /api/ is exempt: the mobile app cannot parse an HTML maintenance page, so
// the API bootstrap answers a closed store with a JSON 503 instead.
if (!store_is_open()
    && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin/')
    && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
    && !defined('TC_API')
    && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'maintenance.php'
) {
    http_response_code(503);
    require __DIR__ . '/../maintenance.php';
    exit;
}