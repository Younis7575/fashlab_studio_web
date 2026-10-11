<?php
/**
 * WhatsApp order receipts via the Meta WhatsApp Cloud API.
 *
 * Sends a confirmation message to the shopper's own WhatsApp number right
 * after an order succeeds. Two sending modes:
 *
 *  - Template mode (production): WHATSAPP_TEMPLATE_NAME set to an approved
 *    Meta template whose body takes three text variables in this order:
 *    customer name, order id, total amount.
 *  - Text mode (development / sandbox): no template configured, a plain
 *    free-form message is sent instead.
 *
 * The send is best-effort: every failure is logged and reported in the
 * return value, never thrown — a WhatsApp outage must not fail an order.
 */

declare(strict_types=1);

// Guard: includes/ are never entry points. The WHATSAPP_* constants come
// from config/config.php, which every real entry point loads first.
if (!defined('WHATSAPP_ACCESS_TOKEN')) {
    http_response_code(404);
    exit;
}

/**
 * Normalize a shopper phone number to the digits-only international format
 * the Cloud API expects (no '+', no spaces). Local numbers starting with 0
 * are prefixed with WHATSAPP_DEFAULT_COUNTRY_CODE.
 */
function tc_whatsapp_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '0')) {
        $digits = WHATSAPP_DEFAULT_COUNTRY_CODE . substr($digits, 1);
    }

    return strlen($digits) >= 8 ? $digits : '';
}

/**
 * Build the Cloud API request body for the order receipt.
 *
 * @return array<string, mixed>
 */
function tc_whatsapp_build_payload(
    string $to,
    string $customerName,
    string $orderId,
    string $totalAmount
): array {
    if (WHATSAPP_TEMPLATE_NAME !== '') {
        return [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => WHATSAPP_TEMPLATE_NAME,
                'language'   => ['code' => WHATSAPP_TEMPLATE_LANG],
                'components' => [
                    [
                        'type'       => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $customerName],
                            ['type' => 'text', 'text' => $orderId],
                            ['type' => 'text', 'text' => $totalAmount],
                        ],
                    ],
                ],
            ],
        ];
    }

    $body = sprintf(
        "Assalam-o-Alaikum %s!\n\nYour order #%s has been placed successfully.\nOrder total: %s\n\nWe will message you again as soon as it ships. Thank you for shopping with Fashlab Studio.",
        $customerName,
        $orderId,
        $totalAmount
    );

    return [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['preview_url' => false, 'body' => $body],
    ];
}

/**
 * Send the order receipt. Returns ['sent' => bool, 'reason' => ?string].
 */
function tc_whatsapp_send_order_receipt(
    string $phone,
    string $customerName,
    string $orderId,
    string $totalAmount
): array {
    if (WHATSAPP_ACCESS_TOKEN === '' || str_starts_with(WHATSAPP_ACCESS_TOKEN, 'your_')
        || WHATSAPP_PHONE_ID === '' || str_starts_with(WHATSAPP_PHONE_ID, 'your_')) {
        return [
            'sent'   => false,
            'reason' => 'WhatsApp Cloud API credentials are not configured (WHATSAPP_ACCESS_TOKEN / WHATSAPP_PHONE_ID).',
        ];
    }

    $to = tc_whatsapp_normalize_phone($phone);
    if ($to === '') {
        return ['sent' => false, 'reason' => 'Phone number is not in a usable format.'];
    }

    $payload = tc_whatsapp_build_payload($to, $customerName, $orderId, $totalAmount);

    $url = 'https://graph.facebook.com/' . WHATSAPP_API_VERSION . '/'
        . rawurlencode(WHATSAPP_PHONE_ID) . '/messages';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => (string) json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . WHATSAPP_ACCESS_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $responseBody = curl_exec($ch);
    $status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError    = curl_error($ch);

    if ($responseBody === false || $status < 200 || $status >= 300) {
        error_log(sprintf(
            '[whatsapp] receipt send failed for order %s: HTTP %d %s %s',
            $orderId,
            $status,
            $curlError,
            is_string($responseBody) ? substr($responseBody, 0, 400) : ''
        ));

        return ['sent' => false, 'reason' => 'WhatsApp API rejected the message (HTTP ' . $status . ').'];
    }

    error_log('[whatsapp] receipt sent for order ' . $orderId . ' to +' . $to);

    return ['sent' => true, 'reason' => null];
}
