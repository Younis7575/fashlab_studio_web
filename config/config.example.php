<?php
/**
 * ============================================================================
 * TAYYABACOLLECTIVE — CONFIGURATION
 * ============================================================================
 * Copy this file to  config/config.php  on your server and fill in your real
 * database credentials. config.php is NOT deployed by the CI pipeline and is
 * git-ignored, so your credentials never end up in the repository.
 *
 * Environment variables (set by your hosting panel, e.g. IONOS) override the
 * defaults below — this is the recommended way on production hosting.
 *
 *   TC_DB_HOST   TC_DB_NAME   TC_DB_USER   TC_DB_PASS
 *   TC_BASE_URL  TC_APP_ENV
 * ============================================================================
 */

declare(strict_types=1);

/* ---- MySQL / MariaDB ---------------------------------------------------- */
define('DB_HOST',    getenv('TC_DB_HOST') ?: 'localhost');
define('DB_NAME',    getenv('TC_DB_NAME') ?: 'tayyaba_collective');
define('DB_USER',    getenv('TC_DB_USER') ?: 'your_db_username');
define('DB_PASS',    getenv('TC_DB_PASS') ?: 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

/* ---- Application --------------------------------------------------------- */
// Leave empty when the site is installed at the domain root (the default).
// Use '/shop' when installed in a sub-folder, e.g. https://example.com/shop
define('BASE_URL', rtrim((string) getenv('TC_BASE_URL'), '/'));
define('APP_ENV', getenv('TC_APP_ENV') ?: 'production'); // 'production' | 'development'

/* ---- Session & security -------------------------------------------------- */
define('SESSION_NAME',    'tayyaba_collective_session');
define('SESSION_TIMEOUT', 7200);                  // admin auto-logout after 2h idle
define('UPLOAD_MAX_SIZE', 4 * 1024 * 1024);       // 4 MB max per image
define('LOGIN_MAX_ATTEMPTS', 5);                  // brute-force lockout threshold
define('LOGIN_LOCKOUT_MINUTES', 15);              // lockout window
