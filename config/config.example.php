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
 *   TC_WHATSAPP_TOKEN   TC_WHATSAPP_PHONE_ID   TC_WHATSAPP_TEMPLATE
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

/* ---- WhatsApp Cloud API (order receipts) ----------------------------------
 * Sends the automated "order successful" WhatsApp receipt to the shopper's
 * own number (POST /api/order/success). Get both values in Meta Business
 * Manager > WhatsApp > API Setup; the token must be a permanent System-User
 * token with the whatsapp_business_messaging permission.
 *
 *   WHATSAPP_PHONE_ID      "Phone number ID" from the API Setup page
 *   WHATSAPP_ACCESS_TOKEN  permanent System-User access token
 *   WHATSAPP_TEMPLATE_NAME approved Meta template with 3 text variables in
 *                          this order: customer name, order id, total amount.
 *                          Leave empty to send a plain text message instead
 *                          (only works inside the 24h customer-service window,
 *                          i.e. development / sandbox testing).
 *   WHATSAPP_DEFAULT_COUNTRY_CODE  prefix for local numbers starting with 0
 *                                  (Pakistan = 92).
 *
 * Env overrides: TC_WHATSAPP_TOKEN, TC_WHATSAPP_PHONE_ID, TC_WHATSAPP_API_VER,
 *                TC_WHATSAPP_TEMPLATE, TC_WHATSAPP_TEMPLATE_LANG, TC_WHATSAPP_CC
 */
define('WHATSAPP_ACCESS_TOKEN',  getenv('TC_WHATSAPP_TOKEN')    ?: 'your_whatsapp_cloud_api_token');
define('WHATSAPP_PHONE_ID',      getenv('TC_WHATSAPP_PHONE_ID') ?: 'your_whatsapp_phone_number_id');
define('WHATSAPP_API_VERSION',   getenv('TC_WHATSAPP_API_VER')  ?: 'v21.0');
define('WHATSAPP_TEMPLATE_NAME', getenv('TC_WHATSAPP_TEMPLATE') ?: '');
define('WHATSAPP_TEMPLATE_LANG', getenv('TC_WHATSAPP_TEMPLATE_LANG') ?: 'en');
define('WHATSAPP_DEFAULT_COUNTRY_CODE', getenv('TC_WHATSAPP_CC') ?: '92');