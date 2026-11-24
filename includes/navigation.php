<?php
/**
 * Storefront navigation (desktop mega menu + mobile drawer).
 *
 * Source of truth is still menu_items (Admin → Menu). On top of it this layer:
 *  - hides links that would lead to an empty page (category without active
 *    products, search / filter with no results, missing category → 404);
 *  - renders the desktop mega menu and the mobile accordion drawer from the
 *    same filtered tree, so both always match.
 * Link checks are cached for 10 minutes so they cost nothing per page view.
 */

declare(strict_types=1);

const TC_NAV_CACHE_TTL = 600;

/** Active categories keyed by slug, with live product counts and a cover image. */
function tc_nav_categories(): array
{
    static $bySlug = null;
    if ($bySlug === null) {
        $bySlug = [];
        foreach (categories_with_counts() as $c) {
            $bySlug[(string) $c['slug']] = $c;
        }
    }
    return $bySlug;
}

function tc_nav_cache_file(): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/fashlab-nav-' . md5(__DIR__ . DB_NAME) . '.json';
}

/** Does this menu URL lead to a page with products (or a non-listing page)? */
function tc_nav_link_live(string $rawUrl): bool
{
    static $memo = null;
    $file = tc_nav_cache_file();
    if ($memo === null) {
        $memo = [];
        if (is_file($file) && filemtime($file) > time() - TC_NAV_CACHE_TTL) {
            $memo = json_decode((string) @file_get_contents($file), true) ?: [];
        }
    }
    if (array_key_exists($rawUrl, $memo)) {
        return (bool) $memo[$rawUrl];
    }

    $live = true;
    $parts = parse_url($rawUrl);
    $path  = basename((string) ($parts['path'] ?? ''));
    parse_str((string) ($parts['query'] ?? ''), $query);

    try {
        if ($path === 'category.php') {
            $cat = tc_nav_categories()[(string) ($query['slug'] ?? '')] ?? null;
            $live = $cat !== null && (int) $cat['product_count'] > 0;
        } elseif ($path === 'collection.php') {
            $col = function_exists('tc_collection') ? tc_collection((string) ($query['slug'] ?? '')) : null;
            $live = $col !== null && (int) ($col['product_count'] ?? 0) > 0;
        } elseif ($path === 'shop.php') {
            $filters = array_intersect_key($query, array_flip(['q', 'price', 'sale', 'fabric', 'color', 'size', 'featured', 'availability']));
            $filters['per_page'] = 1;
            $filters['page'] = 1;
            $live = (int) (get_products($filters)['total'] ?? 0) > 0;
        }
    } catch (Throwable $e) {
        error_log('[nav] link check failed for ' . $rawUrl . ': ' . $e->getMessage());
        $live = true;
    }

    $memo[$rawUrl] = $live;
    @file_put_contents($file, json_encode($memo), LOCK_EX);
    return $live;
}

/** Clear cached link checks (call after products / categories change). */
function tc_nav_cache_clear(): void
{
    @unlink(tc_nav_cache_file());
}

/**
 * Filtered main-menu tree:
 * [ ['item' => row, 'url' => string, 'groups' => ['Heading' => [link rows]]], ... ]
 * Dropdowns that lose every link are dropped unless their own link is live.
 */
function tc_nav_tree(): array
{
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }
    $tree = [];
    foreach (tc_menu_tree('main') as $node) {
        $item = $node['item'];
        $groups = [];
        foreach ($node['children'] as $child) {
            $row = $child['item'];
            if (!tc_nav_link_live((string) $row['url'])) {
                continue;
            }
            $groups[(string) ($row['group_label'] ?? '')][] = $row;
        }
        $hadChildren = (bool) $node['children'];
        if ($hadChildren && !$groups && !tc_nav_link_live((string) $item['url'])) {
            continue;
        }
        if (!$hadChildren && !tc_nav_link_live((string) $item['url'])) {
            continue;
        }
        $tree[] = ['item' => $item, 'url' => tc_menu_url($item), 'groups' => $groups];
    }
    return $tree;
}

/** A cover image for a dropdown: first linked category / collection with an image. */
function tc_nav_feature(array $groups): ?array
{
    foreach ($groups as $links) {
        foreach ($links as $row) {
            parse_str((string) parse_url((string) $row['url'], PHP_URL_QUERY), $q);
            $slug = (string) ($q['slug'] ?? '');
            $cat = tc_nav_categories()[$slug] ?? null;
            if ($cat && !empty($cat['image'])) {
                return ['label' => (string) $row['label'], 'url' => tc_menu_url($row), 'image' => image_url((string) $cat['image'])];
            }
        }
    }
    return null;
}

/** Desktop navigation HTML. */
function tc_render_desktop_nav(string $activeKey = ''): string
{
    $html = '';
    foreach (tc_nav_tree() as $i => $node) {
        $label = e((string) $node['item']['label']);
        if (!$node['groups']) {
            $isActive = $activeKey !== '' && str_contains((string) $node['item']['url'], $activeKey);
            $html .= '<a class="fx-nav-link' . ($isActive ? ' is-active' : '') . '" href="' . e($node['url']) . '">' . $label . '</a>';
            continue;
        }
        $id = 'fx-mega-' . $i;
        $html .= '<div class="fx-nav-item" data-fx-mega>'
               . '<button type="button" class="fx-nav-link" aria-expanded="false" aria-controls="' . $id . '">' . $label
               . ' <svg class="fx-caret" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M1 3l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.4"/></svg></button>'
               . '<div class="fx-mega" id="' . $id . '"><div class="fx-mega-inner">';
        $html .= '<div class="fx-mega-cols">';
        foreach ($node['groups'] as $heading => $links) {
            $html .= '<div class="fx-mega-col">';
            $html .= '<p class="fx-mega-heading">' . e($heading !== '' ? $heading : (string) $node['item']['label']) . '</p>';
            foreach ($links as $row) {
                $html .= '<a href="' . e(tc_menu_url($row)) . '">' . e((string) $row['label']) . '</a>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        $feature = tc_nav_feature($node['groups']);
        if ($feature) {
            $html .= '<a class="fx-mega-feature" href="' . e($feature['url']) . '">'
                   . '<img src="' . e($feature['image']) . '" alt="' . e($feature['label']) . '" loading="lazy" width="320" height="400">'
                   . '<span>' . e($feature['label']) . ' <em>Shop now</em></span></a>';
        }
        $html .= '<a class="fx-mega-all" href="' . e($node['url']) . '">View all ' . $label . '</a>';
        $html .= '</div></div></div>';
    }
    return $html;
}

/** Mobile drawer accordion HTML (same tree as desktop). */
function tc_render_drawer_nav(): string
{
    $html = '';
    foreach (tc_nav_tree() as $i => $node) {
        $label = e((string) $node['item']['label']);
        if (!$node['groups']) {
            $html .= '<a class="fx-drawer-link" href="' . e($node['url']) . '">' . $label . '</a>';
            continue;
        }
        $id = 'fx-acc-' . $i;
        $html .= '<div class="fx-acc">'
               . '<button type="button" class="fx-acc-toggle" aria-expanded="false" aria-controls="' . $id . '">' . $label
               . '<span class="fx-acc-icon" aria-hidden="true"></span></button>'
               . '<div class="fx-acc-panel" id="' . $id . '" hidden>';
        foreach ($node['groups'] as $heading => $links) {
            if ($heading !== '' && count($node['groups']) > 1) {
                $html .= '<p class="fx-acc-heading">' . e($heading) . '</p>';
            }
            foreach ($links as $row) {
                $html .= '<a href="' . e(tc_menu_url($row)) . '">' . e((string) $row['label']) . '</a>';
            }
        }
        $html .= '<a class="fx-acc-all" href="' . e($node['url']) . '">View all ' . $label . '</a>';
        $html .= '</div></div>';
    }
    return $html;
}

/**
 * Recommended main-menu structure, applied from Admin → Menu.
 * Re-parents seeded items by item_key and hides (never deletes) items that
 * move elsewhere. Safe to run more than once.
 */
function tc_apply_recommended_menu(PDO $db): int
{
    $changes = 0;
    $id = static function (string $key) use ($db): ?int {
        $st = $db->prepare('SELECT id FROM menu_items WHERE item_key = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    };
    $ensureTop = static function (string $key, string $label, string $url, int $sort) use ($db, $id, &$changes): int {
        $existing = $id($key);
        if ($existing) {
            $db->prepare('UPDATE menu_items SET parent_id = NULL, label = ?, url = ?, sort_order = ?, status = 1, location = ? WHERE id = ?')
               ->execute([$label, $url, $sort, 'main', $existing]);
            $changes++;
            return $existing;
        }
        $db->prepare('INSERT INTO menu_items (item_key, parent_id, label, url, group_label, location, sort_order, status, desktop_visible, mobile_visible)
                      VALUES (?, NULL, ?, ?, NULL, ?, ?, 1, 1, 1)')
           ->execute([$key, $label, $url, 'main', $sort]);
        $changes++;
        return (int) $db->lastInsertId();
    };

    $shop      = $ensureTop('main-shop', 'Shop', 'shop.php', 1);
    $clothing  = $ensureTop('main-clothing', 'Clothing', 'shop.php', 2);
    $fabrics   = $ensureTop('main-fabrics', 'Fabrics', 'shop.php', 3);
    $occasions = $ensureTop('main-occasions', 'Occasions', 'shop.php', 4);
    $gifts     = $ensureTop('main-gifts', 'Gifts', 'collections.php', 5);
    $ensureTop('main-sale', 'Sale', 'shop.php?sale=1', 6);

    // Seeded Shop children: clothing / fabric groups move to their own menus.
    $move = static function (int $parent, string $where, array $params, ?string $group) use ($db, &$changes): void {
        $st = $db->prepare('UPDATE menu_items SET parent_id = ?, group_label = ? WHERE location = ? AND ' . $where);
        $st->execute(array_merge([$parent, $group, 'main'], $params));
        $changes += $st->rowCount();
    };
    $move($clothing, "group_label = 'Clothing' AND parent_id = ?", [$shop], 'Clothing');
    $move($fabrics, "group_label = 'Shop By Fabric' AND parent_id = ?", [$shop], 'Fabrics');
    $move($gifts, "group_label = 'Gifts' AND parent_id = ?", [$shop], 'Gifts');
    $move($shop, "group_label = 'Featured' AND parent_id = ?", [$shop], 'Discover');
    $move($shop, "group_label = 'Shop By Price' AND parent_id = ?", [$shop], 'Shop by Price');
    $move($occasions, 'parent_id = ?', [$occasions], 'Occasions');

    // Gifts: make sure every live gift category is linked.
    foreach (['luxury-gift-boxes' => 'Luxury Gift Boxes', 'luxury-heart-gift-boxes' => 'Heart Gift Boxes', 'jewelry-bouquets' => 'Jewellery Bouquets', 'customized-caps' => 'Customized Caps'] as $slug => $label) {
        $url = 'category.php?slug=' . $slug;
        $st = $db->prepare('SELECT id FROM menu_items WHERE location = ? AND url = ? LIMIT 1');
        $st->execute(['main', $url]);
        $existing = $st->fetchColumn();
        if ($existing) {
            $db->prepare('UPDATE menu_items SET parent_id = ?, group_label = ? WHERE id = ?')->execute([$gifts, 'Gifts', (int) $existing]);
        } else {
            $db->prepare('INSERT INTO menu_items (parent_id, label, url, group_label, location, sort_order, status) VALUES (?, ?, ?, ?, ?, 9, 1)')
               ->execute([$gifts, $label, $url, 'Gifts', 'main']);
        }
        $changes++;
    }

    // Real clothing categories (only shown while they have products).
    foreach (['unstitched-3-piece' => 'Unstitched 3 Piece', 'unstitched-2-piece' => 'Unstitched 2 Piece', 'one-piece-unstitched' => 'One Piece Unstitched', 'ready-to-wear' => 'Ready to Wear'] as $slug => $label) {
        $url = 'category.php?slug=' . $slug;
        $st = $db->prepare('SELECT id FROM menu_items WHERE location = ? AND url = ? LIMIT 1');
        $st->execute(['main', $url]);
        if (!$st->fetchColumn()) {
            $db->prepare('INSERT INTO menu_items (parent_id, label, url, group_label, location, sort_order, status) VALUES (?, ?, ?, ?, ?, 0, 1)')
               ->execute([$clothing, $label, $url, 'Clothing', 'main']);
            $changes++;
        }
    }

    // Top-level items that now live inside Shop, the drawer or the footer: hide, don't delete.
    $hide = $db->prepare("UPDATE menu_items SET status = 0 WHERE location = 'main' AND parent_id IS NULL AND item_key IN ('main-collections','main-new-in','main-best-sellers','main-style-edit','main-journal','main-about','main-contact')");
    $hide->execute();
    $changes += $hide->rowCount();

    tc_nav_cache_clear();
    return $changes;
}
