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
    $where  = '';
    $params = [];
    if ($venueIds !== null) {
        if (!$venueIds) return [];   // scoped user with no venues sees nothing
        $in = implode(',', array_map('intval', $venueIds));
        $where = "WHERE m.venue_id IN ($in)";
    }
    return db_query(
        "SELECT m.*, v.name AS venue_name,
                (SELECT COUNT(*) FROM menu_categories c WHERE c.menu_id = m.id) AS category_count,
                (SELECT COUNT(*) FROM menu_items i
                    JOIN menu_categories c ON c.id = i.category_id
                   WHERE c.menu_id = m.id) AS item_count
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
           LEFT JOIN venues v ON v.id = m.venue_id WHERE m.id = :id",
        [':id' => $id]
    )->fetch();
    return $row ?: null;
}

/** One published menu row by slug (public). NULL if missing/unpublished. */
function fetch_menu_by_slug(string $slug, bool $publishedOnly = true): ?array {
    if (!menus_supported() || $slug === '') return null;
    $sql = "SELECT * FROM menus WHERE slug = :s";
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
    $catWhere = $forAdmin ? '' : ' AND is_visible = TRUE';
    $cats = db_query(
        "SELECT * FROM menu_categories WHERE menu_id = :m {$catWhere} ORDER BY sort_order, id",
        [':m' => $menuId]
    )->fetchAll();
    if (!$cats) return [];

    $ids = implode(',', array_map(fn($c) => (int)$c['id'], $cats));
    $itemWhere = $forAdmin ? '' : ' AND is_available = TRUE';
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
