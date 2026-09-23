<?php
/**
 * Restaurant menu helpers (DB-driven, per property).
 *
 * Data model (migration: add_menus.sql):
 *   menus ─┬─ menu_categories ─── menu_items
 *          (section = food | drinks)
 *
 * All reads are pre-migration-safe via menus_supported(): pages that call these
 * before the migration has run get empty results instead of a fatal.
 */

declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** True if the menus tables exist (memoised). False pre-migration. */
function menus_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.menus')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * SQL that hides soft-deleted rows (Zuri sync, add_restaurant_sync.sql adds
 * is_deleted to menus / menu_categories / menu_items). '' pre-migration, so
 * every reader keeps working on a database without the column. A catalog
 * lookup, never a failing SELECT — some readers run inside a transaction.
 */
function menu_live_sql(string $alias = ''): string {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) db_query(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_schema = 'public' AND table_name = 'menu_items' AND column_name = 'is_deleted'"
            )->fetchColumn();
        } catch (Throwable $e) { $has = false; }
    }
    if (!$has) return '';
    return ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'is_deleted = FALSE';
}

/** The badge flags in render order: [item-column => [class, label]]. */
function menu_badge_defs(): array {
    return [
        'is_veg'       => ['veg',    '🌿 Veg'],
        'is_vegan'     => ['vegan',  '🌱 Vegan'],
        'is_spicy'     => ['spicy',  '🌶'],
        'has_nuts'     => ['nuts',   '🥜'],
        'has_gluten'   => ['gluten', '🌾'],
        'is_gf'        => ['gf',     'GF'],
        'is_signature' => ['sig',    '★'],
    ];
}

/**
 * Format a menu price for display, e.g. 1000 → "1,000 Kes". NULL / '' → ''.
 * $label is the menu's currency_label (default 'Kes'), rendered as a suffix.
 */
function menu_price_label($price, string $label = 'Kes'): string {
    if ($price === null || $price === '') return '';
    $n = (float) $price;
    // Whole numbers render without decimals (600 Kes); fractional keep 2dp.
    $num = (floor($n) == $n) ? number_format($n, 0) : number_format($n, 2);
    return trim($num . ' ' . $label);
}

/** All menus (admin list). Scoped to $venueIds unless null (owner = all). Includes hidden. */
function fetch_menus(?array $venueIds = null): array {
    if (!menus_supported()) return [];
    $where  = 'WHERE TRUE' . menu_live_sql('m');
    $params = [];
    if ($venueIds !== null) {
        if (!$venueIds) return [];   // scoped user with no venues sees nothing
        $in = implode(',', array_map('intval', $venueIds));
        $where .= " AND m.venue_id IN ($in)";
    }
    $liveC = menu_live_sql('c');
    $liveI = menu_live_sql('i');
    return db_query(
        "SELECT m.*, v.name AS venue_name,
                (SELECT COUNT(*) FROM menu_categories c WHERE c.menu_id = m.id{$liveC}) AS category_count,
                (SELECT COUNT(*) FROM menu_items i
                    JOIN menu_categories c ON c.id = i.category_id
                   WHERE c.menu_id = m.id{$liveC}{$liveI}) AS item_count
           FROM menus m
           LEFT JOIN venues v ON v.id = m.venue_id
           $where
          ORDER BY m.sort_order, m.title",
        $params
    )->fetchAll();
}

/** One menu row by id (admin). NULL if missing. */
function fetch_menu(int $id): ?array {
    if (!menus_supported() || $id <= 0) return null;
    $row = db_query(
        "SELECT m.*, v.name AS venue_name FROM menus m
           LEFT JOIN venues v ON v.id = m.venue_id WHERE m.id = :id" . menu_live_sql('m'),
        [':id' => $id]
    )->fetch();
    return $row ?: null;
}

/** One published menu row by slug (public). NULL if missing/unpublished. */
function fetch_menu_by_slug(string $slug, bool $publishedOnly = true): ?array {
    if (!menus_supported() || $slug === '') return null;
    $sql = "SELECT * FROM menus WHERE slug = :s" . menu_live_sql();
    if ($publishedOnly) $sql .= " AND is_published = TRUE";
    $row = db_query($sql, [':s' => $slug])->fetch();
    return $row ?: null;
}

/**
 * All categories for a menu, each with its items nested under ['items'].
 * $forAdmin=false hides invisible categories and unavailable items (public view).
 */
function fetch_menu_categories(int $menuId, bool $forAdmin = false): array {
    if (!menus_supported() || $menuId <= 0) return [];
    $catWhere = ($forAdmin ? '' : ' AND is_visible = TRUE') . menu_live_sql();
    $cats = db_query(
        "SELECT * FROM menu_categories WHERE menu_id = :m {$catWhere} ORDER BY sort_order, id",
        [':m' => $menuId]
    )->fetchAll();
    if (!$cats) return [];

    $ids = implode(',', array_map(fn($c) => (int)$c['id'], $cats));
    $itemWhere = ($forAdmin ? '' : ' AND is_available = TRUE') . menu_live_sql();
    $items = db_query(
        "SELECT * FROM menu_items WHERE category_id IN ($ids){$itemWhere} ORDER BY sort_order, id"
    )->fetchAll();

    $byCat = [];
    foreach ($items as $it) $byCat[(int)$it['category_id']][] = $it;
    foreach ($cats as &$c) $c['items'] = $byCat[(int)$c['id']] ?? [];
    unset($c);
    return $cats;
}

/** Published menus for the public "Restaurant" nav dropdown: [slug,title,subtitle]. */
function fetch_published_menus(): array {
    if (!menus_supported()) return [];
    try {
        return db_query(
            "SELECT slug, title, subtitle FROM menus WHERE is_published = TRUE ORDER BY sort_order, title"
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ── Public sync API (api/menu-feed.php) ─────────────────────────────────────
// A read-only JSON feed of a published menu, so an external site (e.g. the
// standalone Zuri restaurant site) can render the SAME menu the admin edits
// here. There is one source of truth — the `menus`/`menu_categories`/
// `menu_items` tables — so an admin edit is visible to the external site on its
// next fetch, with no separate copy to keep in step. Everything below is pure
// data-shaping over the existing readers; no new query paths.

/** Dietary/attribute flags for a menu item as booleans, in a stable order. */
function menu_item_attributes(array $it): array {
    $out = [];
    foreach (menu_badge_defs() as $col => [$cls, $_label]) {
        $out[$cls] = !empty($it[$col]) && $it[$col] !== 'f';
    }
    return $out;   // veg, vegan, spicy, nuts, gluten, gf, sig
}

/**
 * The public JSON shape for ONE published menu (or null if missing/unpublished).
 * $slug is a raw slug; it is looked up published-only. Includes only visible
 * categories and available items — exactly what /menu.php renders.
 */
function menu_feed_payload(string $slug): ?array {
    $menu = fetch_menu_by_slug($slug, true);
    if (!$menu) return null;

    $curLabel = $menu['currency_label'] ?: 'Kes';
    $cats     = fetch_menu_categories((int)$menu['id'], false);

    // Resolve the tied published venue (for the reservation deep link), if any.
    $venue = null; $reserveUrl = null;
    if (!empty($menu['venue_id'])) {
        try {
            $v = db_query(
                'SELECT slug, name FROM venues WHERE id = :id AND is_published = TRUE',
                [':id' => (int)$menu['venue_id']]
            )->fetch();
        } catch (Throwable $e) { $v = false; }
        if ($v) {
            $venue = ['slug' => (string)$v['slug'], 'name' => (string)$v['name']];
            if (function_exists('reservations_supported') && reservations_supported())
                $reserveUrl = site_url('/reserve.php?venue=' . rawurlencode((string)$v['slug']));
        }
    }

    // Everything a partner site needs to put its own "book a table" form in
    // front of this menu. The slot list is reservation_slots() itself, so the
    // partner form can never offer a time api/reservation-api.php would reject.
    $resSupported = function_exists('reservations_supported') && reservations_supported();
    $reservations = [
        'enabled'        => $resSupported && $venue !== null,
        'venue_slug'     => $venue['slug'] ?? null,
        'api_url'        => site_url('/api/reservation-api.php'),
        'public_url'     => $reserveUrl,
        'slots'          => $resSupported ? reservation_slots() : [],
        'max_party_size' => function_exists('reservation_max_party') ? reservation_max_party() : 30,
    ];

    $sections = ['food' => [], 'drinks' => []];
    foreach ($cats as $c) {
        $section = ($c['section'] === 'drinks') ? 'drinks' : 'food';
        $items = [];
        foreach ($c['items'] as $it) {
            $price = ($it['price'] === null || $it['price'] === '') ? null : (float)$it['price'];
            $items[] = [
                'name'        => (string)$it['name'],
                'description' => (string)($it['description'] ?? ''),
                'price'       => $price,
                'price_label' => menu_price_label($it['price'], $curLabel),
                'attributes'  => menu_item_attributes($it),
            ];
        }
        $sections[$section][] = [
            'name'  => (string)$c['name'],
            'tag'   => (string)($c['tag'] ?? ''),
            'icon'  => (string)($c['icon'] ?? ''),
            'items' => $items,
        ];
    }

    return [
        'slug'        => (string)$menu['slug'],
        'title'       => (string)$menu['title'],
        'subtitle'    => (string)($menu['subtitle'] ?? ''),
        'tagline'     => (string)($menu['tagline'] ?? ''),
        'location'    => (string)($menu['location_label'] ?? ''),
        'footer_note' => (string)($menu['footer_note'] ?? ''),
        'currency'    => $curLabel,
        'venue'       => $venue,
        'reserve_url' => $reserveUrl,
        'reservations'=> $reservations,
        'public_url'  => site_url('/menu.php?m=' . rawurlencode((string)$menu['slug'])),
        'updated_at'  => $menu['updated_at'] ? date('c', strtotime((string)$menu['updated_at'])) : null,
        'sections'    => $sections,
    ];
}

/** Index of published menus for the feed root (no slug): [slug,title,subtitle,venue_slug]. */
function menu_feed_index(): array {
    if (!menus_supported()) return [];
    try {
        $rows = db_query(
            "SELECT m.slug, m.title, m.subtitle, v.slug AS venue_slug
               FROM menus m LEFT JOIN venues v ON v.id = m.venue_id
              WHERE m.is_published = TRUE ORDER BY m.sort_order, m.title"
        )->fetchAll();
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'slug'       => (string)$r['slug'],
            'title'      => (string)$r['title'],
            'subtitle'   => (string)($r['subtitle'] ?? ''),
            'venue_slug' => $r['venue_slug'] !== null ? (string)$r['venue_slug'] : null,
            'feed_url'   => site_url('/api/menu-feed.php?slug=' . rawurlencode((string)$r['slug'])),
            'public_url' => site_url('/menu.php?m=' . rawurlencode((string)$r['slug'])),
        ];
    }
    return $out;
}
