<?php
declare(strict_types=1);
/**
 * Restaurant opening hours — Tribalsand owns these (TS → Zuri per §1).
 *
 * Data model (migration: add_restaurant_sync_models.sql): opening_hours,
 * venue-scoped, with the standard sync_* columns. Multiple rows per (venue, day)
 * are allowed for split service (lunch + dinner); is_closed = TRUE marks the day
 * closed and ignores the times. Writes bump sync_version; deletes are SOFT.
 *
 * All reads are pre-migration-safe via ohours_supported().
 */
require_once __DIR__ . '/db.php';

function ohours_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.opening_hours')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Weekday names in DB order (0 = Sunday). */
function ohours_day_names(): array {
    return ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
}

/** All hour rows for a venue, day then sort order. Excludes soft-deleted. */
function fetch_opening_hours(int $venueId): array {
    if (!ohours_supported() || $venueId <= 0) return [];
    return db_query(
        "SELECT * FROM opening_hours
          WHERE venue_id = :v AND is_deleted = FALSE
          ORDER BY day_of_week, sort_order, id",
        [':v' => $venueId]
    )->fetchAll();
}

function fetch_opening_hour(int $id): ?array {
    if (!ohours_supported() || $id <= 0) return null;
    $row = db_query('SELECT * FROM opening_hours WHERE id = :id', [':id' => $id])->fetch();
    return $row ?: null;
}

/** Insert an hours row. Returns the new row. */
function create_opening_hour(array $d): array {
    $closed = !empty($d['is_closed']);
    $stmt = db_query(
        "INSERT INTO opening_hours (venue_id, day_of_week, open_time, close_time, is_closed, sort_order)
         VALUES (:v, :dow, :o, :c, :closed, :sort) RETURNING *",
        [
            ':v'      => (int) ($d['venue_id'] ?? 0),
            ':dow'    => max(0, min(6, (int) ($d['day_of_week'] ?? 0))),
            ':o'      => $closed ? null : (trim((string) ($d['open_time'] ?? '')) ?: null),
            ':c'      => $closed ? null : (trim((string) ($d['close_time'] ?? '')) ?: null),
            ':closed' => $closed ? 'true' : 'false',   // emulated prepares send PHP false as ''
            ':sort'   => (int) ($d['sort_order'] ?? 0),
        ]
    );
    return $stmt->fetch() ?: [];
}

/** Update an hours row; bumps sync_version. Returns true on a change. */
function update_opening_hour(int $id, array $d): bool {
    if (!ohours_supported() || $id <= 0) return false;
    $closed = !empty($d['is_closed']);
    $n = db_query(
        "UPDATE opening_hours
            SET day_of_week = :dow, open_time = :o, close_time = :c, is_closed = :closed,
                sort_order = :sort, updated_at = now(),
                sync_version = sync_version + 1, sync_updated_at = now()
          WHERE id = :id",
        [
            ':dow'    => max(0, min(6, (int) ($d['day_of_week'] ?? 0))),
            ':o'      => $closed ? null : (trim((string) ($d['open_time'] ?? '')) ?: null),
            ':c'      => $closed ? null : (trim((string) ($d['close_time'] ?? '')) ?: null),
            ':closed' => $closed ? 'true' : 'false',   // emulated prepares send PHP false as ''
            ':sort'   => (int) ($d['sort_order'] ?? 0),
            ':id'     => $id,
        ]
    )->rowCount();
    return $n > 0;
}

/** Soft-delete an hours row; bumps sync_version. */
function delete_opening_hour(int $id): bool {
    if (!ohours_supported() || $id <= 0) return false;
    $n = db_query(
        "UPDATE opening_hours
            SET is_deleted = TRUE, updated_at = now(),
                sync_version = sync_version + 1, sync_updated_at = now()
          WHERE id = :id AND is_deleted = FALSE",
        [':id' => $id]
    )->rowCount();
    return $n > 0;
}
