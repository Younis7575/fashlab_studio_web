<?php
/**
 * Device tokens for the mobile app.
 *
 * There are no customer accounts -- the app mirrors the website, where a
 * visitor browses, fills the checkout form and orders as a guest. A token is
 * therefore an anonymous device identity, not a credential: it exists purely
 * so a phone with no session cookie can keep a cart and a wishlist between
 * requests.
 *
 * api_state_load() copies that state into $_SESSION before a request runs and
 * api_state_save() writes it back, which lets every existing cart, coupon and
 * wishlist helper be reused unchanged.
 */

declare(strict_types=1);

const API_TOKEN_TTL_DAYS = 180;

function api_tokens_available(): bool
{
    return tc_table_exists('api_tokens');
}

/** Create a token row and return the raw token string. */
function api_token_issue(string $device = '', string $platform = ''): string
{
    $token = bin2hex(random_bytes(32));

    db()->prepare(
        'INSERT INTO api_tokens (token, device_name, platform, last_used_at, expires_at)
         VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ' . API_TOKEN_TTL_DAYS . ' DAY))'
    )->execute([
        $token,
        $device !== '' ? mb_substr($device, 0, 120) : null,
        $platform !== '' ? mb_substr($platform, 0, 20) : null,
    ]);

    return $token;
}

/** The token row, or null when unknown/expired. */
function api_token_row(string $token): ?array
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT * FROM api_tokens
         WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function api_token_touch(string $token): void
{
    db()->prepare(
        'UPDATE api_tokens
            SET last_used_at = NOW(),
                expires_at   = DATE_ADD(NOW(), INTERVAL ' . API_TOKEN_TTL_DAYS . ' DAY)
          WHERE token = ?'
    )->execute([$token]);
}

function api_token_revoke(string $token): void
{
    db()->prepare('DELETE FROM api_tokens WHERE token = ?')->execute([$token]);
}

/**
 * SQL condition (on table/alias $t) for tokens that carry nothing: no cart, no coupon,
 * no wishlist rows, and idle for at least a day. Deleting one is harmless —
 * that device simply receives a fresh token on its next request.
 */
function api_tokens_empty_idle_sql(string $t = 'api_tokens'): string
{
    $cond = "$t.last_used_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
             AND ($t.cart_json IS NULL OR $t.cart_json IN ('', '[]', '{}', '" . API_EMPTY_STATE . "'))
             AND ($t.coupon_code IS NULL OR $t.coupon_code = '')";
    if (tc_table_exists('wishlist_items')) {
        $cond .= " AND NOT EXISTS (SELECT 1 FROM wishlist_items w WHERE w.session_id = $t.token)";
    }
    return $cond;
}

/**
 * Housekeeping: remove expired tokens and idle tokens that hold no state.
 * Returns the number of rows deleted (at most $limit per category).
 */
function api_tokens_gc(int $limit = 500): int
{
    $limit = max(1, $limit);
    $db = db();
    $deleted = $db->exec('DELETE FROM api_tokens WHERE expires_at IS NOT NULL AND expires_at < NOW() LIMIT ' . $limit);
    $deleted += $db->exec('DELETE FROM api_tokens WHERE ' . api_tokens_empty_idle_sql() . ' LIMIT ' . $limit);
    return (int) $deleted;
}

/* ---------------------------------------------------------------------------
   Cart / coupon / wishlist state carried by the token
--------------------------------------------------------------------------- */

/** The empty state, as api_state_save() encodes it. Used by the collector. */
const API_EMPTY_STATE = '{"cart":[],"wishlist":[]}';

/** Copy the token's stored state into the request session. */
function api_state_load(array $tokenRow): void
{
    $state = json_decode((string) ($tokenRow['cart_json'] ?? ''), true);

    if (isset($state['cart']) || isset($state['wishlist'])) {
        $_SESSION['cart']        = is_array($state['cart'] ?? null) ? $state['cart'] : [];
        $_SESSION['tc_wishlist'] = is_array($state['wishlist'] ?? null) ? $state['wishlist'] : [];
    } else {
        // Rows written before the wishlist was carried here held a bare cart.
        // (Cart keys look like "92:0", so they can never be mistaken for the
        // "cart"/"wishlist" keys above.)
        $_SESSION['cart']        = is_array($state) ? $state : [];
        $_SESSION['tc_wishlist'] = [];
    }

    // wishlist_items rows are keyed by tc_visitor_id(); pinning it to the
    // token gives the app a wishlist that survives across requests.
    $_SESSION['tc_visitor'] = (string) $tokenRow['token'];

    $coupon = (string) ($tokenRow['coupon_code'] ?? '');
    if ($coupon !== '') {
        $_SESSION['tc_coupon_code'] = $coupon;
    } else {
        unset($_SESSION['tc_coupon_code']);
    }
}

/**
 * Persist the session cart/wishlist/coupon back onto the token row.
 *
 * The wishlist normally lives in wishlist_items, but enterprise.php falls back
 * to the session when that table is absent. Without carrying that fallback
 * here the app's wishlist would vanish between requests on the base schema.
 */
function api_state_save(string $token): void
{
    db()->prepare('UPDATE api_tokens SET cart_json = ?, coupon_code = ? WHERE token = ?')
        ->execute([
            json_encode([
                'cart'     => $_SESSION['cart'] ?? [],
                'wishlist' => $_SESSION['tc_wishlist'] ?? [],
            ], JSON_UNESCAPED_UNICODE),
            $_SESSION['tc_coupon_code'] ?? null,
            $token,
        ]);
}
