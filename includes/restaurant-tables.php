<?php
declare(strict_types=1);
/**
 * Restaurant floor-plan tables — Tribalsand owns these (TS → Zuri per §1).
 *
 * Data model (migration: add_restaurant_sync_models.sql): restaurant_tables,
 * venue-scoped, with the standard sync_* columns.
 *
 * Every write runs inside menu_sync_tx and queues its event through
 * menu_sync_emit (includes/menu-sync.php) — the ONE emit path, which bumps
 * sync_version, skips while applying an inbound change, and only sends rows of
 * the synced venue (SYNC_VENUE_SLUG). Deletes are SOFT (is_deleted = TRUE); a
 * synced row is never hard-deleted or the peer re-creates it.
 *
 * Contract limits (handover §6): number ≤10 chars (our `label`), name ≤80
 * (`name`, add_restaurant_table_name.sql), capacity 1–255 (our `seats`), zone ≤60
 * (our `section`). rtable_validate() enforces them.
 *
 * All reads are pre-migration-safe via rtables_supported().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/menu-sync.php';

/** True once add_restaurant_sync_models.sql has run (memoised). */
function rtables_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.restaurant_tables')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Active tables, scoped to $venueIds (null = owner = all). Excludes soft-deleted. */
function fetch_restaurant_tables(?array $venueIds = null): array {
    if (!rtables_supported()) return [];
    $where = 'WHERE t.is_deleted = FALSE';
    if ($venueIds !== null) {
        if (!$venueIds) return [];
        $where .= ' AND t.venue_id IN (' . implode(',', array_map('intval', $venueIds)) . ')';
    }
    return db_query(
        "SELECT t.*, v.name AS venue_name
           FROM restaurant_tables t
           JOIN venues v ON v.id = t.venue_id
           $where
          ORDER BY t.venue_id, t.sort_order, t.id"
    )->fetchAll();
}

function fetch_restaurant_table(int $id): ?array {
    if (!rtables_supported() || $id <= 0) return null;
    $row = db_query('SELECT * FROM restaurant_tables WHERE id = :id', [':id' => $id])->fetch();
    return $row ?: null;
}

/**
 * Validate posted table fields. Pure. Returns ['data' => normalised, 'errors' =>
 * field => message].
 */
function rtable_validate(array $in): array {
    $err = [];
    $d = [
        'label'      => trim((string) ($in['label'] ?? '')),
        'name'       => trim((string) ($in['name'] ?? '')),
        'seats'      => (int) ($in['seats'] ?? 0),
        'section'    => trim((string) ($in['section'] ?? '')),
        'sort_order' => (int) ($in['sort_order'] ?? 0),
        'is_active'  => !empty($in['is_active']),
    ];
    if ($d['label'] === '')                 $err['label'] = 'Give the table its number, e.g. 7.';
    elseif (mb_strlen($d['label']) > 10)    $err['label'] = 'At most 10 characters.';
    if ($d['seats'] < 1 || $d['seats'] > 255) $err['seats'] = 'Between 1 and 255 seats.';
    if (mb_strlen($d['section']) > 60)      $err['section'] = 'At most 60 characters.';
    if (mb_strlen($d['name']) > 80)         $err['name'] = 'At most 80 characters.';
    return ['data' => $d, 'errors' => $err];
}

/** Is $label already used by another live table at the venue? (Zuri matches tables on number.) */
function rtable_label_taken(int $venueId, string $label, int $exceptId = 0): bool {
    if (!rtables_supported()) return false;
    return (bool) db_query(
        "SELECT 1 FROM restaurant_tables
          WHERE venue_id = :v AND lower(label) = lower(:l) AND is_deleted = FALSE AND id <> :x LIMIT 1",
        [':v' => $venueId, ':l' => $label, ':x' => $exceptId]
    )->fetchColumn();
}

/** True once add_restaurant_table_name.sql has run (tables have a display name). */
function rtables_name_supported(): bool {
    return rtables_supported() && sync_column_exists('restaurant_tables', 'name');
}

/** Insert a table and queue its create event. Returns the new row. */
function create_restaurant_table(array $d): array {
    return menu_sync_tx(function () use ($d) {
        $withName = rtables_name_supported();
        $p = [
            ':v'   => (int) ($d['venue_id'] ?? 0),
            ':l'   => trim((string) ($d['label'] ?? '')),
            ':s'   => max(1, (int) ($d['seats'] ?? 2)),
            ':sec' => trim((string) ($d['section'] ?? '')) ?: null,
            ':o'   => (int) ($d['sort_order'] ?? 0),
            ':a'   => !empty($d['is_active']) ? 'true' : 'false',   // emulated prepares send PHP false as '' — Postgres rejects it
        ];
        if ($withName) $p[':n'] = trim((string) ($d['name'] ?? '')) ?: null;
        $id = (int) db_query(
            "INSERT INTO restaurant_tables (venue_id, label, seats, section, sort_order, is_active" . ($withName ? ', name' : '') . ")
             VALUES (:v, :l, :s, :sec, :o, :a" . ($withName ? ', :n' : '') . ") RETURNING id",
            $p
        )->fetchColumn();
        menu_sync_emit('restaurant_table', $id, 'create');
        return fetch_restaurant_table($id) ?? [];
    });
}

/**
 * Update a table's editable fields and queue an update event (menu_sync_emit
 * bumps sync_version). Returns true on a change.
 */
function update_restaurant_table(int $id, array $d): bool {
    if (!rtables_supported() || $id <= 0) return false;
    return menu_sync_tx(function () use ($id, $d) {
        $withName = rtables_name_supported();
        $p = [
            ':l'   => trim((string) ($d['label'] ?? '')),
            ':s'   => max(1, (int) ($d['seats'] ?? 2)),
            ':sec' => trim((string) ($d['section'] ?? '')) ?: null,
            ':o'   => (int) ($d['sort_order'] ?? 0),
            ':a'   => !empty($d['is_active']) ? 'true' : 'false',   // emulated prepares send PHP false as '' — Postgres rejects it
            ':id'  => $id,
        ];
        if ($withName) $p[':n'] = trim((string) ($d['name'] ?? '')) ?: null;
        $n = db_query(
            "UPDATE restaurant_tables
                SET label = :l, seats = :s, section = :sec, sort_order = :o, is_active = :a,"
                . ($withName ? ' name = :n,' : '') . "
                    updated_at = now()
              WHERE id = :id AND is_deleted = FALSE",
            $p
        )->rowCount();
        if ($n > 0) menu_sync_emit('restaurant_table', $id, 'update');
        return $n > 0;
    });
}

/** Soft-delete a table (never a hard DELETE) and queue its delete event. */
function delete_restaurant_table(int $id): bool {
    if (!rtables_supported() || $id <= 0) return false;
    return menu_sync_tx(fn() => menu_sync_soft_delete_row('restaurant_table', $id, ', updated_at = now()'));
}
