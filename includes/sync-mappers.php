<?php
declare(strict_types=1);
/**
 * Tribalsand → envelope mappers for the entities Tribalsand OWNS (§1, §11).
 *
 * These translate our PostgreSQL rows into the shared §3 envelope `data` shape.
 * Per §11 the mapper *code* on each side is that side's to own (this is Aly's
 * menu/tables/hours mapper); the FIELD MAP it targets — which envelope key maps
 * to which column — is JOINTLY owned and must be signed off with Bhumika in the
 * shared contract repo. The shapes below follow the §3 example and are the v1
 * PROPOSAL to confirm during Stage 1 (Shadow), which exists precisely so both
 * sides can compare real payloads before anything is applied.
 *
 * Non-negotiables from §3, enforced here:
 *   • money is a decimal STRING ("349.00"), never a float
 *   • cross-system links use the peer's sync_uuid, never our local auto-inc id
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';

/** NUMERIC price → decimal string "349.00", or null when unpriced. */
function sync_money(mixed $price): ?string {
    if ($price === null || $price === '') return null;
    return number_format((float) $price, 2, '.', '');
}

/**
 * menu_category → envelope data. `menu_uuid` links the category to its menu by
 * the menu's sync_uuid (parent link), so identity survives across systems.
 */
function sync_map_menu_category(array $row): array {
    return [
        'menu_uuid'  => (string) ($row['menu_sync_uuid'] ?? ''),
        'section'    => (string) ($row['section'] ?? 'food'),
        'name'       => (string) ($row['name'] ?? ''),
        'tag'        => $row['tag'] !== null ? (string) $row['tag'] : null,
        'icon'       => $row['icon'] !== null ? (string) $row['icon'] : null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'is_visible' => (bool) ($row['is_visible'] ?? true),
    ];
}

/**
 * menu_item → envelope data. `category_uuid` links it to its category. The seven
 * dietary badges + availability travel as booleans. Price is a decimal string.
 */
function sync_map_menu_item(array $row): array {
    return [
        'category_uuid' => (string) ($row['category_sync_uuid'] ?? ''),
        'name'          => (string) ($row['name'] ?? ''),
        'description'   => $row['description'] !== null ? (string) $row['description'] : null,
        'price'         => sync_money($row['price'] ?? null),
        'is_veg'        => (bool) ($row['is_veg'] ?? false),
        'is_vegan'      => (bool) ($row['is_vegan'] ?? false),
        'is_spicy'      => (bool) ($row['is_spicy'] ?? false),
        'has_nuts'      => (bool) ($row['has_nuts'] ?? false),
        'has_gluten'    => (bool) ($row['has_gluten'] ?? false),
        'is_gf'         => (bool) ($row['is_gf'] ?? false),
        'is_signature'  => (bool) ($row['is_signature'] ?? false),
        'is_available'  => (bool) ($row['is_available'] ?? true),
        'sort_order'    => (int) ($row['sort_order'] ?? 0),
    ];
}

/**
 * menu → envelope data. The menu itself is a synced entity too (its slug/title
 * is the container categories hang off).
 */
function sync_map_menu(array $row): array {
    return [
        'slug'           => (string) ($row['slug'] ?? ''),
        'title'          => (string) ($row['title'] ?? ''),
        'subtitle'       => $row['subtitle'] !== null ? (string) $row['subtitle'] : null,
        'currency_label' => (string) ($row['currency_label'] ?? 'Kes'),
        'is_published'   => (bool) ($row['is_published'] ?? true),
        'sort_order'     => (int) ($row['sort_order'] ?? 0),
    ];
}

/**
 * restaurant_table → envelope data. `venue_slug` links it to the property by a
 * stable key (both sides key rooms/venues off slug, not our local id).
 */
function sync_map_restaurant_table(array $row): array {
    return [
        'venue_slug' => (string) ($row['venue_slug'] ?? ''),
        'label'      => (string) ($row['label'] ?? ''),
        'seats'      => (int) ($row['seats'] ?? 0),
        'section'    => $row['section'] !== null ? (string) $row['section'] : null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'is_active'  => (bool) ($row['is_active'] ?? true),
    ];
}

/**
 * opening_hours → envelope data. Times are HH:MM strings (null when closed).
 * day_of_week is 0=Sunday..6=Saturday (agree this convention with Bhumika).
 */
function sync_map_opening_hours(array $row): array {
    $hm = static fn($t) => $t !== null && $t !== '' ? substr((string) $t, 0, 5) : null;
    $closed = (bool) ($row['is_closed'] ?? false);
    return [
        'venue_slug'  => (string) ($row['venue_slug'] ?? ''),
        'day_of_week' => (int) ($row['day_of_week'] ?? 0),
        'open_time'   => $closed ? null : $hm($row['open_time']  ?? null),
        'close_time'  => $closed ? null : $hm($row['close_time'] ?? null),
        'is_closed'   => $closed,
        'sort_order'  => (int) ($row['sort_order'] ?? 0),
    ];
}
