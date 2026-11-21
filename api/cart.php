<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

csrf_require($_POST['csrf_token'] ?? null);

$action = post('action', 20);

try {
    switch ($action) {
        case 'add':
            $productId = post_int('product_id');
            $variantId = !empty($_POST['variant_id']) ? post_int('variant_id') : null;
            $qty = max(1, min(99, post_int('qty', 1)));

            if ($productId < 1) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Invalid product.']);
                exit;
            }

            /* Personalised products: validate the customer's input (and store any photo) first. */
            $personalisation = [];
            if (tc_personalisation_enabled()) {
                $pst = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
                $pst->execute([$productId]);
                $persFields = ($row = $pst->fetch()) ? tc_product_personalisation($row) : [];
                if ($persFields) {
                    $hasRequired = (bool) array_filter($persFields, static fn(array $f): bool => $f['required']);
                    if ($hasRequired && empty($_POST['pers']) && empty($_FILES)) {
                        http_response_code(422);
                        echo json_encode(['ok' => false, 'error' => 'This item is personalised — please add your details on the product page.']);
                        exit;
                    }
                    $collected = tc_collect_personalisation($persFields, $_POST, $_FILES);
                    if (!$collected['ok']) {
                        http_response_code(422);
                        echo json_encode(['ok' => false, 'error' => $collected['error']]);
                        exit;
                    }
                    $personalisation = $collected['values'];
                }
            }

            $result = cart_add($productId, $variantId, $qty, $personalisation);
            echo json_encode([
                'ok'      => $result['ok'],
                'error'   => $result['ok'] ? null : ($result['message'] ?? null),
                'count'   => cart_count(),
                'message' => $result['message'] ?? null,
            ]);
            break;

        case 'update':
            $key = post('key', 60);
            $qty = max(1, min(99, post_int('qty', 1)));

            if ($key === '' || !isset(cart_get()[$key])) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Cart item not found.']);
                exit;
            }

            $result = cart_update($key, $qty);
            echo json_encode([
                'ok'    => $result['ok'],
                'error' => $result['ok'] ? null : ($result['message'] ?? null),
                'count' => cart_count(),
            ]);
            break;

        case 'remove':
            $key = post('key', 60);
            cart_remove($key);
            echo json_encode(['ok' => true, 'count' => cart_count()]);
            break;

        case 'clear':
            cart_clear();
            echo json_encode(['ok' => true, 'count' => 0]);
            break;

        default:
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('[cart-api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong.']);
}