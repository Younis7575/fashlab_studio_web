<?php
/**
 * Storefront footer partial.
 */
declare(strict_types=1);

$storeName = setting('store_name');
$credit    = setting('footer_credit', '');

// Footer columns from menu_items (migration) with the original layout as fallback.
$footerColumns = tc_render_footer_columns();
if (!$footerColumns) {
    $footerCats = get_categories(true);
    $shopCats   = array_slice($footerCats, 0, 5);
    $shopLinks  = '<a href="' . url('/shop.php?sort=newest') . '">New Arrivals</a>';
    foreach ($shopCats as $cat) {
        $shopLinks .= '<a href="' . e(category_url($cat['slug'])) . '">' . e($cat['name']) . '</a>';
    }
    $footerColumns = [
        'Shop' => $shopLinks,
        'Customer Care' => '<a href="' . url('/contact.php') . '">Contact Us</a>'
            . '<a href="' . url('/shop.php') . '">Shipping &amp; Delivery</a>'
            . '<a href="' . url('/contact.php') . '">Returns &amp; Exchange</a>'
            . '<a href="' . url('/contact.php') . '">Size Guide</a>'
            . '<a href="' . url('/contact.php') . '#faq">FAQs</a>',
        'Information' => '<a href="' . url('/about.php') . '">About ' . e($storeName) . '</a>'
            . '<a href="' . url('/privacy-policy.php') . '">Privacy Policy</a>'
            . '<a href="' . url('/contact.php') . '">Terms &amp; Conditions</a>'
            . '<a href="' . url('/my-orders.php') . '">My Orders</a>'
            . '<a href="' . url('/track-order.php') . '">Track Order</a>'
            . '<a href="' . url('/contact.php') . '">Help</a>',
    ];
}
$waUrl      = whatsapp_url('Hello! I would like to know more about your collection.');
$waNumber   = setting('whatsapp_number', '+92 334 232 2324');
$freeOver   = (float) setting('free_shipping_threshold', '8000');
?>
</main>

<!-- Trust bar -->
<section class="trust-bar">
    <div class="container">
        <div class="trust-grid">
            <div class="trust-item">
                <div class="lx-lottie" data-lottie="cod"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <div>
                    <h4>Cash on Delivery</h4>
                    <p>Pay when your parcel arrives</p>
                </div>
            </div>
            <div class="trust-item">
                <div class="lx-lottie" data-lottie="delivery"><i class="fa-solid fa-truck-fast"></i></div>
                <div>
                    <h4>Free Delivery</h4>
                    <p>On orders above <?= e(money($freeOver)) ?></p>
                </div>
            </div>
            <div class="trust-item">
                <div class="lx-lottie" data-lottie="exchange"><i class="fa-solid fa-rotate-left"></i></div>
                <div>
                    <h4>Easy Exchange</h4>
                    <p><?= (int) setting('exchange_policy_days', '7') ?>-day hassle-free exchange</p>
                </div>
            </div>
            <div class="trust-item">
                <div class="lx-lottie" data-lottie="chat"><i class="fa-brands fa-whatsapp"></i></div>
                <div>
                    <h4>Order on WhatsApp</h4>
                    <p><?= e($waNumber) ?></p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="site-footer">
    <div class="container footer-grid">

        <div class="footer-brand">
            <a href="<?= url('/index.php') ?>" class="footer-logo"><?= e($storeName) ?></a>
            <p>Thoughtfully designed clothing for every version of you.</p>

            <div class="social-links">
                <?php
                // Only render a social icon when the admin has set a real URL —
                // never a dead "#" placeholder, never an invented handle.
                $socialLinks = [
                    ['class' => 's-instagram', 'icon' => 'fa-brands fa-instagram', 'label' => 'Instagram on ' . $storeName, 'url' => setting('instagram_url', '')],
                    ['class' => 's-facebook',  'icon' => 'fa-brands fa-facebook-f', 'label' => 'Facebook page',            'url' => setting('facebook_url', '')],
                    ['class' => 's-linkedin',  'icon' => 'fa-brands fa-linkedin-in', 'label' => 'LinkedIn profile',          'url' => setting('linkedin_url', '')],
                ];
                foreach ($socialLinks as $social):
                    $socialUrl = trim((string) $social['url']);
                    if ($socialUrl === '' || $socialUrl === '#' || !preg_match('#^https?://#i', $socialUrl)) continue;
                ?>
                <a class="<?= e($social['class']) ?>" href="<?= e($socialUrl) ?>" aria-label="<?= e($social['label']) ?>" target="_blank" rel="noopener noreferrer"><i class="<?= e($social['icon']) ?>"></i></a>
                <?php endforeach; ?>
                <?php if ($waUrl !== ''): ?>
                <a class="s-whatsapp" href="<?= e($waUrl) ?>" aria-label="Chat on WhatsApp" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp"></i></a>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach ($footerColumns as $colTitle => $colLinks): ?>
        <div class="footer-column">
            <h3><?= e($colTitle) ?></h3>
            <?= $colLinks ?>
        </div>
        <?php endforeach; ?>

    </div>

    <div class="footer-bottom">
        <div class="container">
            <p>&copy; <span id="year"></span> <?= e($storeName) ?>. All Rights Reserved.</p>
            <?php if ($credit !== ''): ?><p><?= e($credit) ?></p><?php endif; ?>
        </div>
    </div>
</footer>

<?php if ($waUrl !== ''): ?>
<a class="wa-float" href="<?= e($waUrl) ?>" target="_blank" rel="noopener noreferrer"
   aria-label="Chat with us on WhatsApp">
    <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
    <span>Chat with us</span>
</a>
<?php endif; ?>

<script src="<?= e(asset_url('assets/js/site.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/premium.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/luxury.js')) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/fashlab.js')) ?>" defer></script>
</body>
</html>