<?php
/**
 * Storefront navigation (desktop mega menu + mobile drawer).
 *
 * Source of truth is still menu_items (Admin → Menu, unchanged). This layer:
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
 * Storefront menu structure, built at render time from menu_items without
 * writing to the database:
 *   SHOP (Discover + Shop by Price) · CLOTHING · FABRICS · OCCASIONS · GIFTS · SALE
 * Seeded Shop groups are split into their own menus, real clothing / gift
 * categories are added, and top-level items that now live in the drawer or
 * footer (Collections, New In, Best Sellers, About, Contact…) are left out.
 * Every link is then filtered by tc_nav_link_live().
 *
 * Returns [ ['item' => row, 'url' => string, 'groups' => ['Heading' => [rows]]], ... ]
 */
function tc_nav_tree(): array
{
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }

    $raw = tc_menu_tree('main');
    $virtual = static fn(string $label, string $url, string $group = ''): array =>
        ['id' => 0, 'label' => $label, 'url' => $url, 'group_label' => $group];

    $sections = [
        'shop'      => ['item' => $virtual('Shop', 'shop.php'), 'groups' => []],
        'clothing'  => ['item' => $virtual('Clothing', 'shop.php'), 'groups' => []],
        'fabrics'   => ['item' => $virtual('Fabrics', 'shop.php'), 'groups' => []],
        'occasions' => ['item' => $virtual('Occasions', 'shop.php'), 'groups' => []],
        'gifts'     => ['item' => $virtual('Gifts', 'collections.php'), 'groups' => []],
    ];
    $groupMap = [
        'clothing' => ['clothing', 'Clothing'], 'shop by fabric' => ['fabrics', 'Fabrics'], 'fabrics' => ['fabrics', 'Fabrics'],
        'gifts' => ['gifts', 'Gifts'], 'featured' => ['shop', 'Discover'], 'discover' => ['shop', 'Discover'],
        'shop by price' => ['shop', 'Shop by Price'],
    ];
    $hiddenTop = ['collections', 'new in', 'best sellers', 'style edit', 'journal', 'about', 'contact'];
    $extraTop = [];
    $seen = [];

    $add = static function (string $section, string $group, array $row) use (&$sections, &$seen): void {
        $key = strtolower(trim((string) $row['url']));
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $sections[$section]['groups'][$group][] = $row;
    };

    foreach ($raw as $node) {
        $item  = $node['item'];
        $label = strtolower(trim((string) $item['label']));
        if ($label === 'shop') {
            foreach ($node['children'] as $child) {
                $g = strtolower(trim((string) ($child['item']['group_label'] ?? '')));
                [$section, $heading] = $groupMap[$g] ?? ['shop', 'Discover'];
                $add($section, $heading, $child['item']);
            }
        } elseif ($label === 'occasions') {
            foreach ($node['children'] as $child) {
                $add('occasions', 'Occasions', $child['item']);
            }
        } elseif ($label === 'sale') {
            $extraTop['sale'] = $item;
        } elseif (!in_array($label, $hiddenTop, true)) {
            // Anything an admin adds later still shows up.
            $extraTop[$label] = ['node' => $node];
        }
    }

    // Real categories that exist in the catalogue.
    $giftSlugs = ['luxury-gift-boxes', 'luxury-heart-gift-boxes', 'jewelry-bouquets', 'customized-caps'];
    foreach (tc_nav_categories() as $slug => $cat) {
        if (!empty($cat['parent_id']) && !in_array($slug, $giftSlugs, true)) continue;
        // Merchandising categories already appear under Shop → Discover.
        if (preg_match('/^(new[- ]?arrivals?|new[- ]?in|best[- ]?sellers?|sale|trending|featured)$/i', (string) $cat['name'])) continue;
        $row = $virtual((string) $cat['name'], 'category.php?slug=' . $slug);
        $add(in_array($slug, $giftSlugs, true) ? 'gifts' : 'clothing', in_array($slug, $giftSlugs, true) ? 'Gifts' : 'Clothing', $row);
    }

    $tree = [];
    foreach ($sections as $sec) {
        $groups = [];
        foreach ($sec['groups'] as $heading => $rows) {
            $live = array_values(array_filter($rows, static fn(array $r): bool => tc_nav_link_live((string) $r['url'])));
            if ($live) $groups[$heading] = $live;
        }
        if (!$groups) continue;
        if (isset($groups['Discover'])) {
            $groups = ['Discover' => $groups['Discover']] + $groups;
        }
        // A single link group under the menu's own name needs no repeated heading.
        $tree[] = ['item' => $sec['item'], 'url' => tc_menu_url($sec['item']), 'groups' => $groups];
    }
    foreach ($extraTop as $key => $entry) {
        if ($key === 'sale') {
            if (tc_nav_link_live((string) $entry['url'])) {
                $tree[] = ['item' => $entry, 'url' => tc_menu_url($entry), 'groups' => []];
            }
            continue;
        }
        $node = $entry['node'];
        $groups = [];
        foreach ($node['children'] as $child) {
            if (tc_nav_link_live((string) $child['item']['url'])) {
                $groups[(string) ($child['item']['group_label'] ?? '')][] = $child['item'];
            }
        }
        if ($groups || tc_nav_link_live((string) $node['item']['url'])) {
            $tree[] = ['item' => $node['item'], 'url' => tc_menu_url($node['item']), 'groups' => $groups];
        }
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
