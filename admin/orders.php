<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin();
$db = db();

/* ---------------------------------------------------------------------------
   POST: update order status / payment status
--------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($_POST['csrf_token'] ?? null);
    $action = post('action', 30);

    if ($action === 'update_status') {
        $orderId  = post_int('id');
        $status   = post('order_status', 30);
        $payStatus = post('payment_status', 30);
        $allowed  = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
        $payAllowed = ['pending', 'paid', 'unpaid', 'refunded'];

        if (in_array($status, $allowed, true) && in_array($payStatus, $payAllowed, true)) {
            $stmt = $db->prepare('SELECT order_status, payment_status FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $before = $stmt->fetch();

            $db->prepare('UPDATE orders SET order_status = ?, payment_status = ? WHERE id = ?')
               ->execute([$status, $payStatus, $orderId]);

            // Keep the customer-facing timeline in sync (track-order + API read it).
            if ($before && $before['order_status'] !== $status && tc_table_exists('order_status_history')) {
                $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
                   ->execute([$orderId, $status, 'Updated by store admin']);
            }

            record_activity('order_status_update', 'order', $orderId, 'Order status set to "' . $status . '"');
            flash_set('success', 'Order updated.');
        } else {
            flash_set('error', 'Invalid status value.');
        }
        redirect(url('/admin/orders.php?id=' . $orderId));
    }

    if ($action === 'payment_review') {
        $orderId   = post_int('id');
        $paymentId = post_int('payment_id');
        $approve   = post('decision', 10) === 'approve';
        if (manual_payment_review($paymentId, $approve, post('admin_note', 255))) {
            record_activity('payment_review', 'order', $orderId, $approve ? 'JazzCash payment approved' : 'JazzCash payment rejected');
            flash_set('success', $approve ? 'Payment approved — order confirmed.' : 'Payment rejected.');
        } else {
            flash_set('error', 'Payment record not found.');
        }
        redirect(url('/admin/orders.php?id=' . $orderId));
    }

    if ($action === 'delete') {
        $orderId = post_int('id');
        $db->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        record_activity('order_delete', 'order', $orderId, 'Deleted order');
        flash_set('success', 'Order deleted.');
        redirect(url('/admin/orders.php'));
    }
}

/* ---------------------------------------------------------------------------
   Detail view
--------------------------------------------------------------------------- */
$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0) {
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$detailId]);
    $order = $stmt->fetch();

    if (!$order) {
        flash_set('error', 'Order not found.');
        redirect(url('/admin/orders.php'));
    }

    $stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $stmt->execute([$detailId]);
    $orderItems = $stmt->fetchAll();

    $page_title = 'Order ' . $order['order_number'];
    $active     = 'orders';

    ob_start();
    ?>

    <div class="page-header">
        <div>
            <h1>Order <?= e($order['order_number']) ?></h1>
            <p>Placed <?= e(format_datetime($order['created_at'])) ?></p>
        </div>
        <div class="page-actions">
            <a href="<?= url('/admin/orders.php') ?>" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> All Orders</a>
        </div>
    </div>

    <div class="detail-grid">
        <div>

            <!-- ITEMS -->
            <div class="card">
                <div class="card-title">Items (<?= count($orderItems) ?>)</div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderItems as $item): ?>
                                <tr>
                                    <td>
                                        <?= e($item['product_name']) ?> <span style="color:var(--admin-muted)">(ID <?= (int) $item['product_id'] ?>)</span>
                                        <?php if (!empty($item['variant_label'])): ?>
                                            <div style="font-size:12.5px;margin-top:4px"><?= e($item['variant_label']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['personalisation'])): ?>
                                            <div style="font-size:12.5px;margin-top:6px;padding:8px 10px;background:#faf6f2;border-radius:6px">
                                                <strong style="display:block;margin-bottom:4px">Personalisation</strong>
                                                <?= tc_personalisation_html($item['personalisation']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) $item['quantity'] ?></td>
                                    <td><?= money((float) $item['price']) ?></td>
                                    <td><strong><?= money((float) $item['subtotal']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="3" style="text-align:right;font-weight:600">Subtotal</td>
                                <td><?= money((float) $order['subtotal']) ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" style="text-align:right;font-weight:600">Shipping</td>
                                <td><?= money((float) $order['shipping_fee']) ?></td>
                            </tr>
                            <?php if ((float) $order['discount'] > 0): ?>
                            <tr>
                                <td colspan="3" style="text-align:right;font-weight:600">Discount</td>
                                <td>-<?= money((float) $order['discount']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="3" style="text-align:right;font-weight:700;font-size:15px">Total</td>
                                <td style="font-weight:700;font-size:15px"><?= money((float) $order['total']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($order['notes']): ?>
            <div class="card">
                <div class="card-title">Order Notes</div>
                <p style="margin:0"><?= nl2br(e($order['notes'])) ?></p>
            </div>
            <?php endif; ?>

        </div>

        <div>

            <!-- CUSTOMER -->
            <div class="card">
                <div class="card-title">Customer</div>
                <ul class="detail-list">
                    <li><span>Name</span><strong><?= e($order['customer_name']) ?></strong></li>
                    <li><span>Email</span><strong><?= e($order['customer_email']) ?></strong></li>
                    <li><span>Phone</span><strong><?= e($order['customer_phone']) ?></strong></li>
                    <li><span>Address</span><strong><?= nl2br(e($order['shipping_address'])) ?></strong></li>
                    <li><span>City</span><strong><?= e($order['city']) ?></strong></li>
                    <li><span>Postal Code</span><strong><?= e($order['postal_code'] ?: '—') ?></strong></li>
                </ul>
            </div>

            <?php $mp = manual_payment_for_order((int) $order['id']); if ($mp): ?>
            <!-- JAZZCASH PAYMENT -->
            <div class="card" style="border:2px solid <?= $mp['status'] === 'submitted' ? '#e0a100' : ($mp['status'] === 'approved' ? '#2f8a4e' : '#c0392b') ?>">
                <div class="card-title">JazzCash Payment — <?= e(ucfirst($mp['status'] === 'submitted' ? 'Awaiting approval' : $mp['status'])) ?></div>
                <ul class="detail-list">
                    <li><span>Sender account title</span><strong><?= e($mp['sender_title']) ?></strong></li>
                    <li><span>Transaction ID</span><strong><?= e($mp['transaction_id']) ?></strong></li>
                    <li><span>Submitted</span><strong><?= e(format_datetime($mp['created_at'])) ?></strong></li>
                    <?php if (!empty($mp['admin_note'])): ?><li><span>Note</span><strong><?= e($mp['admin_note']) ?></strong></li><?php endif; ?>
                </ul>
                <a href="<?= e(url('/admin/payment-proof.php?id=' . (int) $mp['id'])) ?>" target="_blank" rel="noopener">
                    <img src="<?= e(url('/admin/payment-proof.php?id=' . (int) $mp['id'])) ?>" alt="Payment screenshot" style="width:100%;max-height:420px;object-fit:contain;border-radius:8px;background:#f6f6f6;margin:10px 0">
                </a>
                <?php if ($mp['status'] === 'submitted'): ?>
                <form method="post" action="<?= e(url('/admin/orders.php?id=' . $detailId)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="payment_review">
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="payment_id" value="<?= (int) $mp['id'] ?>">
                    <div class="form-group"><label for="admin_note">Note (optional, shown to customer if rejected)</label>
                        <input type="text" id="admin_note" name="admin_note" maxlength="255"></div>
                    <div style="display:flex;gap:8px">
                        <button name="decision" value="approve" class="btn btn-primary" style="flex:1;background:#2f8a4e;border-color:#2f8a4e"><i class="fa-solid fa-check"></i> Approve &amp; Confirm Order</button>
                        <button name="decision" value="reject" class="btn btn-danger" style="flex:1" onclick="return confirm('Reject this payment?')"><i class="fa-solid fa-xmark"></i> Reject</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- STATUS -->
            <div class="card">
                <div class="card-title">Update Status</div>
                <form method="post" action="<?= e(url('/admin/orders.php?id=' . $detailId)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">

                    <div class="form-group">
                        <label for="order_status">Order Status</label>
                        <select id="order_status" name="order_status">
                            <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $order['order_status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payment_status">Payment Status</label>
                        <select id="payment_status" name="payment_status">
                            <?php foreach (['pending', 'paid', 'unpaid', 'refunded'] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $order['payment_status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%"><i class="fa-solid fa-check"></i> Update Status</button>
                </form>

                <hr style="border:none;border-top:1px solid var(--admin-border);margin:18px 0">
                <p style="margin:0 0 10px;font-size:12.5px;color:var(--admin-muted)">
                    <strong>Payment:</strong> <?= e(ucwords(str_replace('_', ' ', (string) $order['payment_method']))) ?>
                </p>
                <form method="post" onsubmit="return confirm('Delete this order permanently? This cannot be undone.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <button class="btn btn-danger btn-sm" style="width:100%"><i class="fa-solid fa-trash"></i> Delete Order</button>
                </form>
            </div>

        </div>
    </div>

    <?php
    $content = ob_get_clean();
    require __DIR__ . '/layout.php';
    exit;
}

/* ---------------------------------------------------------------------------
   List view
--------------------------------------------------------------------------- */
$status  = get_string('status', 30);
$q       = get_string('q', 100);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where  = [];
$params = [];
if ($status === 'awaiting_payment' && manual_payments_ready()) {
    $where[] = "id IN (SELECT order_id FROM manual_payments WHERE status = 'submitted')";
} elseif ($status !== '' && in_array($status, ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'], true)) {
    $where[] = 'order_status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(order_number LIKE ? OR customer_name LIKE ? OR customer_email LIKE ? OR customer_phone LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM orders {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT id, order_number, customer_name, customer_email, customer_phone, total, payment_method,
            payment_status, order_status, created_at
     FROM orders {$whereSql}
     ORDER BY created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$qs = http_build_query(array_filter(['status' => $status, 'q' => $q], static fn($v) => $v !== ''));

// One-click status chips with live counts.
$statusCounts = [];
foreach ($db->query('SELECT order_status, COUNT(*) AS c FROM orders GROUP BY order_status') as $row) {
    $statusCounts[$row['order_status']] = (int) $row['c'];
}
$statusCounts['all'] = array_sum($statusCounts);

$page_title = 'Orders';
$active     = 'orders';

ob_start();
?>

<div class="page-header">
    <div>
        <h1>Orders</h1>
        <p><?= (int) $total ?> order<?= (int) $total === 1 ? '' : 's' ?></p>
    </div>
</div>

<form class="filter-bar" method="get" action="<?= e(url('/admin/orders.php')) ?>">
    <input type="search" name="q" placeholder="Search order #, name, email, phone…" value="<?= e($q) ?>">
    <select name="status">
        <option value="">All Statuses</option>
        <?php foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    <?php if ($qs): ?><a href="<?= url('/admin/orders.php') ?>" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
</form>

<div class="quick-chips" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px">
    <?php foreach (['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $key => $label): ?>
        <a href="<?= e(url('/admin/orders.php' . ($key === 'all' ? '' : '?status=' . $key))) ?>"
           style="padding:6px 14px;border-radius:999px;font-size:12.5px;font-weight:600;text-decoration:none;
                  <?= ($key === 'all' ? $status === '' : $status === $key)
                      ? 'background:var(--admin-primary,#800c26);color:#fff' : 'background:#f2ede5;color:#4a443c' ?>">
            <?= e($label) ?> <span style="opacity:.65"><?= (int) ($statusCounts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
    <?php $jcWaiting = manual_payments_waiting(); ?>
    <a href="<?= e(url('/admin/orders.php?status=awaiting_payment')) ?>"
       style="padding:6px 14px;border-radius:999px;font-size:12.5px;font-weight:600;text-decoration:none;
              <?= $status === 'awaiting_payment' ? 'background:#a3122e;color:#fff' : 'background:#fde8ec;color:#a3122e' ?>">
        <i class="fa-solid fa-mobile-screen-button"></i> JazzCash awaiting approval <span style="opacity:.75"><?= $jcWaiting ?></span>
    </a>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Payment Status</th>
                    <th>Order Status</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--admin-muted);padding:36px">No orders found.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><a href="<?= url('/admin/orders.php?id=' . (int) $o['id']) ?>"><strong><?= e($o['order_number']) ?></strong></a></td>
                        <td>
                            <?= e($o['customer_name']) ?><br>
                            <span style="font-size:12px;color:var(--admin-muted)"><?= e($o['customer_phone']) ?></span>
                        </td>
                        <td><strong><?= money((float) $o['total']) ?></strong></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $o['payment_method']))) ?></td>
                        <td><span class="badge <?= e(payment_status_color((string) $o['payment_status'])) ?>"><?= e(ucfirst((string) $o['payment_status'])) ?></span></td>
                        <td><span class="badge <?= e(order_status_color((string) $o['order_status'])) ?>"><?= e(ucfirst((string) $o['order_status'])) ?></span></td>
                        <td><?= e(format_date($o['created_at'])) ?></td>
                        <td><a class="btn btn-outline btn-xs" href="<?= url('/admin/orders.php?id=' . (int) $o['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($page, $pages, $qs) ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';