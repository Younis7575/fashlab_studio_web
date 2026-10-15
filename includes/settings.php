<?php
/**
 * Store settings (key/value) — cached once per request.
 * Never hardcode store values in templates; read them via setting().
 */

declare(strict_types=1);

const SETTING_DEFAULTS = [
    'store_name'             => 'Fashlab Studio',
    'store_tagline'          => 'Where Style Meets Elegance',
    'store_email'            => '',
    'store_phone'            => '',
    'store_address'          => '',
    'currency'               => 'Rs',
    'currency_symbol'        => 'Rs.',
    'shipping_fee'           => '250',
    'free_shipping_threshold'=> '8000',
    'min_order_amount'       => '0',
    'low_stock_threshold'    => '5',
    'announcement_bar'       => '',
    'instagram_url'          => '#',
    'facebook_url'           => '#',
    'tiktok_url'             => '#',
    'linkedin_url'           => '#',
    'whatsapp_number'        => '+92 334 232 2324',
    'store_status'           => 'open',
    'sales_tax_rate'         => '0',
    'price_decimal'          => 'price_decimal',
    'mobile_api_url'         => 'https://fashlabstudio.mytechrcm.com/api/v1/index.php',
    'meta_description'       => '',
    'footer_credit'          => '',
];

function settings_all(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = SETTING_DEFAULTS;

    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        foreach ($rows as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('[tayyaba] settings load failed: ' . $e->getMessage());
    }

    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return (string) ($all[$key] ?? $default);
}

function update_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

/** True when the storefront is open to customers. */
function store_is_open(): bool
{
    return setting('store_status', 'open') !== 'closed';
}

/** Mobile API base URL (https://fashlabstudio.mytechrcm.com/api/v1/index.php).
 *  The app reads this from GET /settings so it never needs a hard-coded URL. */
function mobile_api_url(): string
{
    return setting('mobile_api_url', 'https://fashlabstudio.mytechrcm.com/api/v1/index.php');
}