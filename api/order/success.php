<?php
/**
 * POST /api/order/success — automated WhatsApp order receipt.
 *
 * Called by the Flutter app (mobile and web) right after an order is placed
 * successfully. Body (JSON or form):
 *
 *   { "phone": "0334 2322324", "customer_name": "Ayesha",
 *     "order_id": "TC-1042", "total_amount": "Rs. 8,500" }
 *
 * The WhatsApp send happens server-side through the Meta Cloud API
 * (includes/whatsapp-service.php) and is best-effort: the response always
 * reports whether the message actually went out, but a WhatsApp failure
 * never fails this endpoint — the order itself already succeeded.
 *
 * Clean URL /api/order/success is provided by the root .htaccess rewrite;
 * /api/order/success.php works everywhere, including hosts that drop
 * mod_rewrite substitutions.
 */

declare(strict_types=1);

// Config only — deliberately not includes/bootstrap.php: the receipt needs
// no database and no session, so a DB outage must not block it.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/whatsapp-service.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw   = (string) file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$phone        = trim((string) ($input['phone'] ?? ''));
$customerName = trim((string) ($input['customer_name'] ?? ''));
$orderId      = trim((string) ($input['order_id'] ?? ''));
$totalAmount  = trim((string) ($input['total_amount'] ?? ''));

$missing = [];
if ($phone === '')        $missing[] = 'phone';
if ($customerName === '') $missing[] = 'customer_name';
if ($orderId === '')      $missing[] = 'order_id';
if ($totalAmount === '')  $missing[] = 'total_amount';

if ($missing !== []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required field(s): ' . implode(', ', $missing) . '.',
    ]);
    exit;
}

$result = tc_whatsapp_send_order_receipt($phone, $customerName, $orderId, $totalAmount);

echo json_encode([
    'success'  => true,
    'order_id' => $orderId,
    'whatsapp' => $result,
]);
