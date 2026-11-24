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
            $filters = array_intersect_key($query, array_flip(['q', 'price', 'sale', 'fabric', 'color', 'size', 'featured', 'availability', 'dept']));
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

/** Department for a category, by name / slug: women | gifts | accessories. */
function tc_category_department(array $cat): string
{
    $text = strtolower((string) ($cat['name'] ?? '') . ' ' . (string) ($cat['slug'] ?? ''));
    if (preg_match('/\bcaps?\b|gajr|jewel(?!.*bouquet)|\bbags?\b|scarf|hijab|clutch|bangle|earring/', $text)) {
        return 'accessories';
    }
    if (preg_match('/nikkah|\bpens?\b|bouquet|gift|basket|hamper|surprise|\bbox|chocolate|snack|flower|money|cake/', $text)) {
        return 'gifts';
    }
    return 'women';
}

/** Sub-group heading inside the Gifts department. */
function tc_gift_group(array $cat): string
{
    $text = strtolower((string) $cat['name'] . ' ' . (string) $cat['slug']);
    if (preg_match('/nikkah|\bpens?\b/', $text)) return 'Nikkah & Wedding';
    if (str_contains($text, 'bouquet')) return 'Bouquets';
    if (preg_match('/basket|surprise|hamper|package/', $text)) return 'Baskets & Surprises';
    return 'Gift Boxes';
}

/**
 * Storefront menu, built at render time (no database writes):
 *   NEW IN · WOMEN · GIFTS & SURPRISES · ACCESSORIES · SALE
 * Women = clothing categories + the seeded fabric / occasion / price links;
 * Gifts and Accessories = their categories. Every link is filtered by
 * tc_nav_link_live(), so nothing leads to an empty page.
 *
 * Returns [ ['item' => row, 'url' => string, 'groups' => ['Heading' => [rows]]], ... ]
 */
function tc_nav_tree(): array
{
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }

    $v = static fn(string $label, string $url, string $group = ''): array =>
        ['id' => 0, 'label' => $label, 'url' => $url, 'group_label' => $group];

    $sections = [
        'women'       => ['item' => $v('Women', 'shop.php?dept=women'), 'groups' => ['Shop by Category' => [], 'Fabrics' => [], 'Occasions' => [], 'Shop by Price' => []]],
        'gifts'       => ['item' => $v('Gifts & Surprises', 'shop.php?dept=gifts'), 'groups' => ['Gift Boxes' => [], 'Baskets & Surprises' => [], 'Bouquets' => [], 'Nikkah & Wedding' => []]],
        'accessories' => ['item' => $v('Accessories', 'shop.php?dept=accessories'), 'groups' => ['Accessories' => []]],
    ];
    $seen = [];
    $add = static function (string $section, string $group, array $row) use (&$sections, &$seen): void {
        $key = strtolower(trim((string) $row['url']));
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $sections[$section]['groups'][$group][] = $row;
    };

    // 1. Real categories → departments.
    foreach (tc_nav_categories() as $slug => $cat) {
        if (preg_match('/^(new[- ]?arrivals?|new[- ]?in|best[- ]?sellers?|sale|trending|featured)$/i', (string) $cat['name'])) continue;
        $dept = tc_category_department($cat);
        if ($dept === 'women' && !empty($cat['parent_id'])) continue;
        $group = $dept === 'women' ? 'Shop by Category' : ($dept === 'gifts' ? tc_gift_group($cat) : 'Accessories');
        $add($dept, $group, $v((string) $cat['name'], 'category.php?slug=' . $slug));
    }

    // 2. Seeded menu links: fabrics / occasions / price → Women.
    $sale = null;
    $extra = [];
    foreach (tc_menu_tree('main') as $node) {
        $label = strtolower(trim((string) $node['item']['label']));
        if ($label === 'sale') { $sale = $node['item']; continue; }
        if (in_array($label, ['collections', 'new in', 'best sellers', 'style edit', 'journal', 'about', 'contact'], true)) continue;
        if (!in_array($label, ['shop', 'occasions'], true)) { $extra[] = $node; continue; }
        foreach ($node['children'] as $child) {
            $row = $child['item'];
            // Fabric / occasion / price links search only within Women's fashion.
            if (str_starts_with((string) $row['url'], 'shop.php') && !str_contains((string) $row['url'], 'dept=')) {
                $row['url'] .= (str_contains((string) $row['url'], '?') ? '&' : '?') . 'dept=women';
            }
            $g = strtolower(trim((string) ($row['group_label'] ?? '')));
            if ($label === 'occasions')                 $add('women', 'Occasions', $row);
            elseif (str_contains($g, 'fabric'))         $add('women', 'Fabrics', $row);
            elseif (str_contains($g, 'price'))          $add('women', 'Shop by Price', $row);
            // Clothing keyword links and Featured / Gifts groups are covered by categories and NEW IN / SALE.
        }
    }

    $tree = [];
    $newIn = $v('New In', 'shop.php?sort=newest');
    if (tc_nav_link_live($newIn['url'])) {
        $tree[] = ['item' => $newIn, 'url' => tc_menu_url($newIn), 'groups' => []];
    }
    foreach ($sections as $sec) {
        $groups = [];
        foreach ($sec['groups'] as $heading => $rows) {
            $live = array_values(array_filter($rows, static fn(array $r): bool => tc_nav_link_live((string) $r['url'])));
            if ($live) $groups[$heading] = $live;
        }
        if ($groups) {
            $tree[] = ['item' => $sec['item'], 'url' => tc_menu_url($sec['item']), 'groups' => $groups];
        }
    }
    foreach ($extra as $node) {
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
    if ($sale && tc_nav_link_live((string) $sale['url'])) {
        $tree[] = ['item' => $sale, 'url' => tc_menu_url($sale), 'groups' => []];
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
