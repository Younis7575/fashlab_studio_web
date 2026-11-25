<?php
/**
 * Manual (JazzCash) payments.
 *
 * The customer sends money to the store's JazzCash account, then submits the
 * sender account title, transaction ID and a screenshot. The order stays
 * "pending" until an admin approves the proof in admin/orders.php, which marks
 * it paid + confirmed. Website and mobile API share these functions.
 */

declare(strict_types=1);

/** Store account the customer pays into (editable later via settings table). */
function manual_payment_account(): array
{
    return [
        'method'         => 'jazzcash',
        'name'           => 'JazzCash',
        'account_title'  => setting('jazzcash_account_title', 'Muhammad Younis'),
        'account_number' => setting('jazzcash_account_number', '03342322324'),
    ];
}

function manual_payments_ready(): bool
{
    return tc_table_exists('manual_payments');
}

/**
 * Save the payment proof for an order.
 * $file is one $_FILES entry. Returns ['ok'=>bool, 'errors'=>[...]].
 */
function manual_payment_submit(int $orderId, string $senderTitle, string $transactionId, array $file): array
{
    $errors = [];
    $senderTitle   = trim(mb_substr($senderTitle, 0, 150));
    $transactionId = trim(mb_substr($transactionId, 0, 80));

    if ($senderTitle === '')   $errors['sender_title']   = 'Please enter the account title you sent the payment from.';
    if ($transactionId === '') $errors['transaction_id'] = 'Please enter the transaction ID (TID).';
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['screenshot'] = 'Please attach a screenshot of the payment.';
    }
    if (!manual_payments_ready()) {
        $errors['payment'] = 'Manual payment is not available right now. Please choose Cash on Delivery.';
    }
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $up = upload_image($file, 'payments');
    if (!$up['success']) {
        return ['ok' => false, 'errors' => ['screenshot' => $up['error']]];
    }

    $db = db();
    $db->prepare(
        'INSERT INTO manual_payments (order_id, method, sender_title, transaction_id, screenshot, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$orderId, 'jazzcash', $senderTitle, $transactionId, $up['path'], 'submitted']);

    $db->prepare("UPDATE orders SET payment_method = 'jazzcash', payment_status = 'pending' WHERE id = ?")
       ->execute([$orderId]);

    if (tc_table_exists('order_status_history')) {
        $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
           ->execute([$orderId, 'pending', 'JazzCash payment submitted — awaiting verification']);
    }

    return ['ok' => true, 'errors' => []];
}

/** Latest proof for an order, or null. */
function manual_payment_for_order(int $orderId): ?array
{
    if (!manual_payments_ready()) return null;
    $stmt = db()->prepare('SELECT * FROM manual_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Customer-facing status label. */
function manual_payment_label(?array $p): string
{
    if (!$p) return 'Payment proof not submitted';
    return match ($p['status']) {
        'approved' => 'Payment verified',
        'rejected' => 'Payment could not be verified',
        default    => 'Payment under review',
    };
}

/** Number of proofs waiting for the admin. */
function manual_payments_waiting(): int
{
    if (!manual_payments_ready()) return 0;
    return (int) db()->query("SELECT COUNT(*) FROM manual_payments WHERE status = 'submitted'")->fetchColumn();
}

/** Admin decision. $approve true → order paid + confirmed. */
function manual_payment_review(int $paymentId, bool $approve, string $note = ''): bool
{
    $db = db();
    $stmt = $db->prepare('SELECT * FROM manual_payments WHERE id = ?');
    $stmt->execute([$paymentId]);
    $p = $stmt->fetch();
    if (!$p) return false;

    $db->prepare('UPDATE manual_payments SET status = ?, admin_note = ?, reviewed_at = NOW() WHERE id = ?')
       ->execute([$approve ? 'approved' : 'rejected', trim($note) ?: null, $paymentId]);

    if ($approve) {
        $db->prepare("UPDATE orders SET payment_status = 'paid', order_status = IF(order_status = 'pending', 'confirmed', order_status) WHERE id = ?")
           ->execute([(int) $p['order_id']]);
    } else {
        $db->prepare("UPDATE orders SET payment_status = 'failed' WHERE id = ?")->execute([(int) $p['order_id']]);
    }

    if (tc_table_exists('order_status_history')) {
        $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
           ->execute([
               (int) $p['order_id'],
               $approve ? 'confirmed' : 'pending',
               $approve ? 'JazzCash payment verified — order confirmed' : ('JazzCash payment could not be verified' . ($note !== '' ? ': ' . $note : '')),
           ]);
    }
    return true;
}
