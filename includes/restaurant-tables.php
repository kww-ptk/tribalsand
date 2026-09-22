<?php
declare(strict_types=1);
/**
 * Restaurant floor-plan tables — Tribalsand owns these (TS → Zuri per §1).
 *
 * Data model (migration: add_restaurant_sync_models.sql): restaurant_tables,
 * venue-scoped, with the standard sync_* columns.
 *
 * Writes bump sync_version / sync_updated_at so a row is always sync-ready, but
 * they do NOT enqueue to the outbox yet — that wiring lands in Stage 2 once the
 * field map is signed off. Deletes are SOFT (is_deleted = TRUE); a synced row is
 * never hard-deleted or the peer re-creates it.
 *
 * All reads are pre-migration-safe via rtables_supported().
 */
require_once __DIR__ . '/db.php';

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

/** Insert a table. Returns the new row. */
function create_restaurant_table(array $d): array {
    $stmt = db_query(
        "INSERT INTO restaurant_tables (venue_id, label, seats, section, sort_order, is_active)
         VALUES (:v, :l, :s, :sec, :o, :a) RETURNING *",
        [
            ':v'   => (int) ($d['venue_id'] ?? 0),
            ':l'   => trim((string) ($d['label'] ?? '')),
            ':s'   => max(1, (int) ($d['seats'] ?? 2)),
            ':sec' => trim((string) ($d['section'] ?? '')) ?: null,
            ':o'   => (int) ($d['sort_order'] ?? 0),
            ':a'   => !empty($d['is_active']),
        ]
    );
    return $stmt->fetch() ?: [];
}

/** Update a table's editable fields; bumps sync_version. Returns true on a change. */
function update_restaurant_table(int $id, array $d): bool {
    if (!rtables_supported() || $id <= 0) return false;
    $n = db_query(
        "UPDATE restaurant_tables
            SET label = :l, seats = :s, section = :sec, sort_order = :o, is_active = :a,
                updated_at = now(), sync_version = sync_version + 1, sync_updated_at = now()
          WHERE id = :id",
        [
            ':l'   => trim((string) ($d['label'] ?? '')),
            ':s'   => max(1, (int) ($d['seats'] ?? 2)),
            ':sec' => trim((string) ($d['section'] ?? '')) ?: null,
            ':o'   => (int) ($d['sort_order'] ?? 0),
            ':a'   => !empty($d['is_active']),
            ':id'  => $id,
        ]
    )->rowCount();
    return $n > 0;
}

/** Soft-delete a table (never a hard DELETE); bumps sync_version. */
function delete_restaurant_table(int $id): bool {
    if (!rtables_supported() || $id <= 0) return false;
    $n = db_query(
        "UPDATE restaurant_tables
            SET is_deleted = TRUE, updated_at = now(),
                sync_version = sync_version + 1, sync_updated_at = now()
          WHERE id = :id AND is_deleted = FALSE",
        [':id' => $id]
    )->rowCount();
    return $n > 0;
}
