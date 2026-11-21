<?php
/**
 * Image alt text + product personalisation (customer photo / name / message).
 *
 * Both features need columns added by migration-personalisation.sql (or the
 * one-click admin/db-update.php). Every helper checks for its column first, so
 * the store keeps working unchanged until the update has been applied.
 */

declare(strict_types=1);

/* ============================================================================
   SCHEMA UPDATES
   ============================================================================ */

/**
 * Additive schema changes the one-click Database Update can apply.
 * Column entries mirror migration-personalisation.sql; table entries
 * ('column' => null) mirror the CREATE TABLE in their migration file.
 */
function tc_schema_updates(): array
{
    return [
        [
            'table'  => 'page_views',
            'column' => null,
            'label'  => 'Visitor analytics table (migration-analytics.sql)',
            'ddl'    => "CREATE TABLE IF NOT EXISTS `page_views` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_hash` CHAR(64) NOT NULL,
                `path` VARCHAR(500) NOT NULL,
                `page_type` VARCHAR(30) NOT NULL DEFAULT 'other',
                `referrer` VARCHAR(500) NULL DEFAULT NULL,
                `device` ENUM('desktop','tablet','mobile') NOT NULL DEFAULT 'desktop',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_pv_created` (`created_at`),
                KEY `idx_pv_session` (`session_hash`, `created_at`),
                KEY `idx_pv_path` (`path`(120)),
                KEY `idx_pv_device` (`device`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        [
            'table'  => 'product_images',
            'column' => 'alt_text',
            'label'  => 'Image alt text (product_images.alt_text)',
            'ddl'    => 'ALTER TABLE `product_images` ADD COLUMN `alt_text` VARCHAR(200) NULL DEFAULT NULL AFTER `image`',
        ],
        [
            'table'  => 'products',
            'column' => 'personalisation_fields',
            'label'  => 'Personalisation fields per product (products.personalisation_fields)',
            'ddl'    => 'ALTER TABLE `products` ADD COLUMN `personalisation_fields` TEXT NULL AFTER `size`',
        ],
        [
            'table'  => 'order_items',
            'column' => 'personalisation',
            'label'  => 'Customer personalisation on order lines (order_items.personalisation)',
            'ddl'    => 'ALTER TABLE `order_items` ADD COLUMN `personalisation` TEXT NULL AFTER `variant_label`',
        ],
    ];
}

/** Updates whose column does not exist yet. */
function tc_pending_schema_updates(): array
{
    return array_values(array_filter(
        tc_schema_updates(),
        static fn(array $u): bool => $u['column'] === null
            ? !tc_table_exists($u['table'])
            : !tc_column_exists($u['table'], $u['column'])
    ));
}

function tc_alt_text_enabled(): bool
{
    return tc_column_exists('product_images', 'alt_text');
}

function tc_personalisation_enabled(): bool
{
    return tc_column_exists('products', 'personalisation_fields');
}

/* ============================================================================
   PRODUCT CONFIG
   ============================================================================ */

const TC_PERSONALISATION_TYPES = [
    'text'     => 'Text (one line)',
    'textarea' => 'Text (multi-line)',
    'photo'    => 'Photo upload',
];

/**
 * Personalisation fields configured on a product:
 * [ ['label' => string, 'type' => text|textarea|photo, 'required' => bool, 'max' => int], ... ]
 */
function tc_product_personalisation(array $product): array
{
    $raw = $product['personalisation_fields'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $fields = [];
    foreach ($decoded as $f) {
        if (!is_array($f) || trim((string) ($f['label'] ?? '')) === '') {
            continue;
        }
        $type = (string) ($f['type'] ?? 'text');
        $fields[] = [
            'label'    => trim((string) $f['label']),
            'type'     => array_key_exists($type, TC_PERSONALISATION_TYPES) ? $type : 'text',
            'required' => !empty($f['required']),
            'max'      => max(0, (int) ($f['max'] ?? 0)),
        ];
    }
    return $fields;
}

/** Field definitions posted from the admin product form, as JSON (null when none). */
function tc_posted_personalisation_json(): ?string
{
    $labels   = (array) ($_POST['pers_label'] ?? []);
    $types    = (array) ($_POST['pers_type'] ?? []);
    $required = (array) ($_POST['pers_required'] ?? []);
    $max      = (array) ($_POST['pers_max'] ?? []);

    $fields = [];
    foreach ($labels as $i => $label) {
        $label = mb_substr(trim((string) $label), 0, 80);
        if ($label === '') {
            continue;
        }
        $type = (string) ($types[$i] ?? 'text');
        $fields[] = [
            'label'    => $label,
            'type'     => array_key_exists($type, TC_PERSONALISATION_TYPES) ? $type : 'text',
            'required' => ($required[$i] ?? '0') === '1',
            'max'      => $type === 'photo' ? 0 : max(0, min(1000, (int) ($max[$i] ?? 0))),
        ];
    }
    return $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null;
}

/* ============================================================================
   CUSTOMER INPUT (add to cart)
   ============================================================================ */

/**
 * Validate the customer's personalisation for a product (web add-to-cart).
 * Text arrives as $_POST['pers'][i]; photos as $_FILES['pers_file_i'].
 *
 * @return array{ok: bool, error: ?string, values: array}
 *         values: [ ['label' => string, 'type' => string, 'value' => string], ... ]
 */
function tc_collect_personalisation(array $fields, array $post, array $files): array
{
    $values   = [];
    $uploaded = [];
    $text     = (array) ($post['pers'] ?? []);

    $fail = static function (string $msg) use (&$uploaded): array {
        foreach ($uploaded as $path) {
            delete_uploaded_file($path);
        }
        return ['ok' => false, 'error' => $msg, 'values' => []];
    };

    foreach ($fields as $i => $f) {
        if ($f['type'] === 'photo') {
            $file = $files['pers_file_' . $i] ?? null;
            $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if (!$hasFile) {
                if ($f['required']) {
                    return $fail('Please upload: ' . $f['label'] . '.');
                }
                continue;
            }
            $result = upload_image($file, 'personalisation');
            if (!$result['success']) {
                return $fail($f['label'] . ': ' . $result['error']);
            }
            $uploaded[] = $result['path'];
            $values[] = ['label' => $f['label'], 'type' => 'photo', 'value' => $result['path']];
            continue;
        }

        $value = trim(str_replace("\r\n", "\n", (string) ($text[$i] ?? '')));
        if ($f['type'] === 'text') {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }
        if ($value === '') {
            if ($f['required']) {
                return $fail('Please fill in: ' . $f['label'] . '.');
            }
            continue;
        }
        if ($f['max'] > 0 && mb_strlen($value) > $f['max']) {
            return $fail($f['label'] . ' can be at most ' . $f['max'] . ' characters.');
        }
        $values[] = ['label' => $f['label'], 'type' => $f['type'], 'value' => mb_substr($value, 0, 1000)];
    }

    return ['ok' => true, 'error' => null, 'values' => $values];
}

/** Normalise stored personalisation (array from the cart, JSON from order_items). */
function tc_personalisation_values(array|string|null $stored): array
{
    if (is_string($stored)) {
        $stored = $stored === '' ? [] : json_decode($stored, true);
    }
    if (!is_array($stored)) {
        return [];
    }
    return array_values(array_filter($stored, static fn($v): bool => is_array($v) && isset($v['label'], $v['value'])));
}

/** Small HTML summary of a line's personalisation for cart, checkout and order views. */
function tc_personalisation_html(array|string|null $stored): string
{
    $values = tc_personalisation_values($stored);
    if (!$values) {
        return '';
    }
    $html = '<div class="line-personalisation">';
    foreach ($values as $v) {
        $html .= '<p><span>' . e((string) $v['label']) . ':</span> ';
        if (($v['type'] ?? '') === 'photo') {
            $src = image_url((string) $v['value']);
            $html .= '<a href="' . e($src) . '" target="_blank" rel="noopener">'
                   . '<img src="' . e($src) . '" alt="' . e((string) $v['label']) . '" style="width:40px;height:40px;object-fit:cover;border-radius:4px;vertical-align:middle"></a>';
        } else {
            $html .= nl2br(e((string) $v['value']));
        }
        $html .= '</p>';
    }
    return $html . '</div>';
}
