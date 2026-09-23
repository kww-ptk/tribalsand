<?php
declare(strict_types=1);
/**
 * Tribalsand → envelope mappers for the entities Tribalsand OWNS (§1, §11).
 *
 * These translate our PostgreSQL rows into the shared §3 envelope `data` shape.
 * The field names follow Zuri's contract — Bhumika's handover (21 Sep 2026) §6,
 * mirrored in docs/sync-contract.json in the Zuri repository. Change a field
 * here only after changing it there, together.
 *
 * Contract rules enforced here:
 *   • money is a decimal STRING ("349.00"), never a float; menu_item.price is required
 *   • cross-system links use sync_uuid, never our local auto-inc id
 *   • Zuri has no `menu` entity — categories are sent as-is, grouped food|drinks
 *   • item availability (sold out) is ZURI's: menu_item must never carry
 *     `is_available`, or Zuri rejects the whole event as not_owner. Our admin's
 *     "Hidden" toggle (menu_items.is_available) is the item's `is_active`.
 *   • only the Zuri property's menu syncs (SYNC_VENUE_SLUG, default `zuri`) —
 *     Maya Kobe's breakfast menu must never appear on Zuri's site.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';

/** The venue whose menu/tables sync to Zuri. */
function sync_venue_slug(): string {
    $v = trim((string) (parse_env()['SYNC_VENUE_SLUG'] ?? ''));
    return $v !== '' ? $v : 'zuri';
}

/** NUMERIC price → decimal string "349.00", or null when unpriced. */
function sync_money(mixed $price): ?string {
    if ($price === null || $price === '') return null;
    return number_format((float) $price, 2, '.', '');
}

/** Nullable text → string|null (trimmed, '' → null). */
function sync_text(mixed $v): ?string {
    if ($v === null) return null;
    $s = trim((string) $v);
    return $s === '' ? null : $s;
}

/**
 * menu_category → envelope data (contract: group, name, subtitle, icon,
 * sort_order, is_active). Our `tag` is the one-line strapline under the
 * category name ("Mains · Seafood") — Zuri's `subtitle`.
 */
function sync_map_menu_category(array $row): array {
    return [
        'group'      => ($row['section'] ?? '') === 'drinks' ? 'drinks' : 'food',
        'name'       => (string) ($row['name'] ?? ''),
        'subtitle'   => sync_text($row['tag'] ?? null),
        'icon'       => sync_text($row['icon'] ?? null),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'is_active'  => (bool) ($row['is_visible'] ?? true),
    ];
}

/**
 * menu_item → envelope data. Fields we don't hold (code, slug, image,
 * is_featured, allergens, prep_minutes) are omitted, not nulled — partial
 * objects are allowed, and a null would wipe a value Zuri holds. `is_gf` has no
 * contract field and is not sent.
 */
function sync_map_menu_item(array $row): array {
    return [
        'category_uuid'   => (string) ($row['category_sync_uuid'] ?? ''),
        'name'            => (string) ($row['name'] ?? ''),
        'description'     => sync_text($row['description'] ?? null),
        'price'           => sync_money($row['price'] ?? null),
        'is_signature'    => (bool) ($row['is_signature'] ?? false),
        'is_vegetarian'   => (bool) ($row['is_veg'] ?? false),
        'is_vegan'        => (bool) ($row['is_vegan'] ?? false),
        'is_spicy'        => (bool) ($row['is_spicy'] ?? false),
        'contains_nuts'   => (bool) ($row['has_nuts'] ?? false),
        'contains_gluten' => (bool) ($row['has_gluten'] ?? false),
        'sort_order'      => (int) ($row['sort_order'] ?? 0),
        'is_active'       => (bool) ($row['is_available'] ?? true),   // our "Hidden" toggle
    ];
}

/**
 * restaurant_table → envelope data (contract: number ≤10 required, zone,
 * capacity 1–255, in_service, sort_order, is_active). Our `label` is the
 * table's number ("T1"); live seating state stays local to Zuri.
 */
function sync_map_restaurant_table(array $row): array {
    return [
        'number'     => (string) ($row['label'] ?? ''),
        'zone'       => sync_text($row['section'] ?? null),
        'capacity'   => max(1, min(255, (int) ($row['seats'] ?? 0))),
        'in_service' => (bool) ($row['is_active'] ?? true),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'is_active'  => (bool) ($row['is_active'] ?? true),
    ];
}

/**
 * opening_hours → envelope data (contract: ONE record with a fixed uuid —
 * lunch, dinner, first_slot HH:MM, last_slot HH:MM, slot_minutes ≥15,
 * duration_minutes ≥15). Source row: restaurant_hours (one per venue).
 */
function sync_map_opening_hours(array $row): array {
    $hm = static fn($t) => $t !== null && $t !== '' ? substr((string) $t, 0, 5) : null;
    return [
        'lunch'            => sync_text($row['lunch'] ?? null),
        'dinner'           => sync_text($row['dinner'] ?? null),
        'first_slot'       => $hm($row['first_slot'] ?? null),
        'last_slot'        => $hm($row['last_slot'] ?? null),
        'slot_minutes'     => max(15, (int) ($row['slot_minutes'] ?? 30)),
        'duration_minutes' => max(15, (int) ($row['duration_minutes'] ?? 90)),
    ];
}

/**
 * Why an item row can't be sent, or '' if it can. price is required by the
 * contract, so an unpriced item waits until it has one (the first priced edit
 * goes out, and Zuri treats an update for an unknown uuid as a create).
 */
function sync_menu_item_skip_reason(array $row): string {
    return sync_money($row['price'] ?? null) === null ? 'no_price' : '';
}

/**
 * Menu/table rows for the synced venue, with each row's parent sync_uuid/name.
 * $id = one row (any is_deleted state — a delete event still describes it);
 * null = every live row, parents before children order by id.
 */
function sync_menu_rows(string $entity, ?int $id = null): array {
    $one   = $id !== null;
    $where = $one ? ' AND x.id = :id' : ' AND x.is_deleted = FALSE';
    $p     = [':venue' => sync_venue_slug()] + ($one ? [':id' => $id] : []);
    $sql = match ($entity) {
        'menu_category' =>
            "SELECT x.id, x.sync_uuid, x.sync_version, x.section, x.name, x.tag, x.icon, x.sort_order, x.is_visible
               FROM menu_categories x
               JOIN menus m  ON m.id = x.menu_id
               JOIN venues v ON v.id = m.venue_id
              WHERE v.slug = :venue{$where}" . ($one ? '' : ' AND m.is_deleted = FALSE') . "
              ORDER BY x.id",
        'menu_item' =>
            "SELECT x.id, x.sync_uuid, x.sync_version, x.name, x.description, x.price,
                    x.is_veg, x.is_vegan, x.is_spicy, x.has_nuts, x.has_gluten, x.is_gf,
                    x.is_signature, x.is_available, x.sort_order,
                    c.sync_uuid AS category_sync_uuid, c.name AS category_name
               FROM menu_items x
               JOIN menu_categories c ON c.id = x.category_id
               JOIN menus m  ON m.id = c.menu_id
               JOIN venues v ON v.id = m.venue_id
              WHERE v.slug = :venue{$where}" . ($one ? '' : ' AND c.is_deleted = FALSE AND m.is_deleted = FALSE') . "
              ORDER BY x.id",
        'restaurant_table' =>
            "SELECT x.id, x.sync_uuid, x.sync_version, x.label, x.seats, x.section, x.sort_order, x.is_active
               FROM restaurant_tables x
               JOIN venues v ON v.id = x.venue_id
              WHERE v.slug = :venue{$where}
              ORDER BY x.id",
        'opening_hours' =>
            "SELECT x.id, x.sync_uuid, x.sync_version, x.lunch, x.dinner, x.first_slot, x.last_slot,
                    x.slot_minutes, x.duration_minutes
               FROM restaurant_hours x
               JOIN venues v ON v.id = x.venue_id
              WHERE v.slug = :venue{$where}
              ORDER BY x.id",
        default => null,
    };
    if ($sql === null) return [];
    $table = ['restaurant_table' => 'restaurant_tables', 'opening_hours' => 'restaurant_hours'][$entity] ?? null;
    if ($table !== null
        && !db_query("SELECT to_regclass('public.{$table}')")->fetchColumn()) {
        return [];   // its migration has not been applied yet
    }
    return db_query($sql, $p)->fetchAll();
}

/**
 * The full shadow export (§7) as ordered §3 envelopes — categories before
 * items so a backfill applies in dependency order, then tables and the hours
 * record. READ-ONLY. Unpriced items are left out (see
 * sync_menu_item_skip_reason). Empty pre-migration.
 */
function sync_export_events(): array {
    if (!sync_supported()) return [];
    $events = [];
    foreach (sync_menu_rows('menu_category') as $c) {
        $events[] = sync_make_event('menu_category', 'create', (string) $c['sync_uuid'], (int) $c['sync_version'], sync_map_menu_category($c));
    }
    foreach (sync_menu_rows('menu_item') as $i) {
        if (sync_menu_item_skip_reason($i) !== '') continue;
        $events[] = sync_make_event('menu_item', 'create', (string) $i['sync_uuid'], (int) $i['sync_version'], sync_map_menu_item($i));
    }
    foreach (sync_menu_rows('restaurant_table') as $t) {
        $events[] = sync_make_event('restaurant_table', 'create', (string) $t['sync_uuid'], (int) $t['sync_version'], sync_map_restaurant_table($t));
    }
    foreach (sync_menu_rows('opening_hours') as $h) {
        $events[] = sync_make_event('opening_hours', 'create', (string) $h['sync_uuid'], (int) $h['sync_version'], sync_map_opening_hours($h));
    }
    return $events;
}

/**
 * The backfill file Zuri's natural-key matcher reads (handover §9): per entity,
 * just the uuid plus the match keys. Also reports what was left out, so the
 * manual review knows about it. READ-ONLY.
 */
function sync_backfill_export(): array {
    $out = ['menu_category' => [], 'menu_item' => [], 'restaurant_table' => [], 'opening_hours' => []];
    $skipped = [];
    if (!sync_supported()) return $out + ['skipped' => $skipped];

    foreach (sync_menu_rows('menu_category') as $c) {
        $out['menu_category'][] = ['sync_uuid' => (string) $c['sync_uuid'], 'name' => (string) $c['name']];
    }
    foreach (sync_menu_rows('menu_item') as $i) {
        $why = sync_menu_item_skip_reason($i);
        if ($why !== '') {
            $skipped[] = ['entity' => 'menu_item', 'sync_uuid' => (string) $i['sync_uuid'], 'name' => (string) $i['name'], 'reason' => $why];
            continue;
        }
        $out['menu_item'][] = [
            'sync_uuid' => (string) $i['sync_uuid'],
            'name'      => (string) $i['name'],
            'category'  => (string) $i['category_name'],
        ];
    }
    foreach (sync_menu_rows('restaurant_table') as $t) {
        $out['restaurant_table'][] = ['sync_uuid' => (string) $t['sync_uuid'], 'number' => (string) $t['label']];
    }
    foreach (sync_menu_rows('opening_hours') as $h) {
        $out['opening_hours'][] = ['sync_uuid' => (string) $h['sync_uuid']];   // the fixed uuid Zuri keys on
    }
    return $out + ['venue' => sync_venue_slug(), 'skipped' => $skipped];
}
