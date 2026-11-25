<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);

    $fields = [
        'store_name', 'store_tagline', 'store_email', 'store_phone', 'store_address',
        'currency', 'currency_symbol', 'shipping_fee', 'free_shipping_threshold',
        'min_order_amount', 'low_stock_threshold', 'announcement_bar',
        'instagram_url', 'facebook_url', 'linkedin_url', 'tiktok_url', 'whatsapp_number',
        'store_status', 'meta_description', 'footer_credit',
        'jazzcash_account_title', 'jazzcash_account_number',
    ];

    foreach ($fields as $key) {
        $value = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
        update_setting($key, $value);
    }

    record_activity('settings_update', 'settings', null, 'Store settings updated');
    flash_set('success', 'Settings saved.');
    redirect(url('/admin/settings.php'));
}

$settings = settings_all();

$page_title = 'Settings';
$active     = 'settings';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Settings</h1>
        <p>These values are used across the storefront — update them once and everything follows.</p>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/settings.php')) ?>">
    <?= csrf_field() ?>

    <div class="detail-grid">
        <div>

            <div class="card">
                <div class="card-title">Store Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="store_name">Store Name</label>
                        <input type="text" id="store_name" name="store_name" value="<?= e($settings['store_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="store_tagline">Tagline</label>
                        <input type="text" id="store_tagline" name="store_tagline" value="<?= e($settings['store_tagline'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="store_email">Store Email</label>
                        <input type="email" id="store_email" name="store_email" value="<?= e($settings['store_email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="store_phone">Phone</label>
                        <input type="tel" id="store_phone" name="store_phone" value="<?= e($settings['store_phone'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="store_address">Address</label>
                        <input type="text" id="store_address" name="store_address" value="<?= e($settings['store_address'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Currency &amp; Delivery</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="currency">Currency Code</label>
                        <input type="text" id="currency" name="currency" value="<?= e($settings['currency'] ?? 'Rs') ?>">
                    </div>
                    <div class="form-group">
                        <label for="currency_symbol">Currency Symbol</label>
                        <input type="text" id="currency_symbol" name="currency_symbol" value="<?= e($settings['currency_symbol'] ?? 'Rs.') ?>">
                    </div>
                    <div class="form-group">
                        <label for="shipping_fee">Shipping Fee</label>
                        <input type="number" id="shipping_fee" name="shipping_fee" min="0" step="0.01" value="<?= e($settings['shipping_fee'] ?? '250') ?>">
                    </div>
                    <div class="form-group">
                        <label for="free_shipping_threshold">Free Shipping Above</label>
                        <input type="number" id="free_shipping_threshold" name="free_shipping_threshold" min="0" step="0.01" value="<?= e($settings['free_shipping_threshold'] ?? '8000') ?>">
                    </div>
                    <div class="form-group">
                        <label for="min_order_amount">Minimum Order Amount</label>
                        <input type="number" id="min_order_amount" name="min_order_amount" min="0" step="0.01" value="<?= e($settings['min_order_amount'] ?? '0') ?>">
                    </div>
                    <div class="form-group">
                        <label for="low_stock_threshold">Low Stock Threshold</label>
                        <input type="number" id="low_stock_threshold" name="low_stock_threshold" min="1" value="<?= e($settings['low_stock_threshold'] ?? '5') ?>">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Social Links</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="instagram_url">Instagram URL</label>
                        <input type="url" id="instagram_url" name="instagram_url" value="<?= e($settings['instagram_url'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="facebook_url">Facebook URL</label>
                        <input type="url" id="facebook_url" name="facebook_url" value="<?= e($settings['facebook_url'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="tiktok_url">TikTok URL</label>
                        <input type="url" id="tiktok_url" name="tiktok_url" value="<?= e($settings['tiktok_url'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="linkedin_url">LinkedIn URL</label>
                        <input type="url" id="linkedin_url" name="linkedin_url" value="<?= e($settings['linkedin_url'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="whatsapp_number">WhatsApp Number</label>
                        <input type="text" id="whatsapp_number" name="whatsapp_number" value="<?= e($settings['whatsapp_number'] ?? '') ?>" placeholder="+92 334 232 2324">
                        <p class="help">Drives the floating chat button and the WhatsApp links. Any format works &mdash; a leading 0 is treated as a Pakistani number.</p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><i class="fa-solid fa-mobile-screen-button" style="color:#a3122e"></i> JazzCash Manual Payment</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="jazzcash_account_title">Account Title</label>
                        <input type="text" id="jazzcash_account_title" name="jazzcash_account_title" maxlength="100" value="<?= e($settings['jazzcash_account_title'] ?? 'Muhammad Younis') ?>">
                    </div>
                    <div class="form-group">
                        <label for="jazzcash_account_number">Account Number</label>
                        <input type="text" id="jazzcash_account_number" name="jazzcash_account_number" maxlength="30" value="<?= e($settings['jazzcash_account_number'] ?? '03342322324') ?>" placeholder="03XXXXXXXXX">
                        <p class="help">Shown to customers on website and app checkout when they choose JazzCash.</p>
                    </div>
                </div>
            </div>

        </div>

        <div>

            <div class="card">
                <div class="card-title">Storefront</div>
                <div class="form-group">
                    <label for="announcement_bar">Announcement Bar</label>
                    <input type="text" id="announcement_bar" name="announcement_bar" value="<?= e($settings['announcement_bar'] ?? '') ?>">
                    <p class="help">Shown at the very top of the storefront. Leave empty to hide.</p>
                </div>
                <div class="form-group">
                    <label for="meta_description">Default Meta Description</label>
                    <textarea id="meta_description" name="meta_description"><?= e($settings['meta_description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label for="footer_credit">Footer Credit</label>
                    <input type="text" id="footer_credit" name="footer_credit" value="<?= e($settings['footer_credit'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="store_status">Store Status</label>
                    <select id="store_status" name="store_status">
                        <option value="open" <?= ($settings['store_status'] ?? 'open') === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="closed" <?= ($settings['store_status'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed (maintenance mode)</option>
                    </select>
                    <p class="help">When closed, customers see a maintenance page. The admin panel stays accessible.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Admin Account</div>
                <p style="color:var(--admin-muted);font-size:13px;margin:0 0 12px">
                    Change your password and profile details.
                </p>
                <a href="<?= url('/admin/profile.php') ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-user-gear"></i> Edit Profile &amp; Password</a>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;padding:12px">
                <i class="fa-solid fa-floppy-disk"></i> Save Settings
            </button>

        </div>
    </div>
</form>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';