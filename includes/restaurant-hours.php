<?php
declare(strict_types=1);
/**
 * Restaurant opening hours — ONE record per venue, Tribalsand owns it (TS → Zuri).
 *
 * Data model (migration: add_restaurant_hours.sql): restaurant_hours, one row
 * per venue (venue_id UNIQUE) with the six sync_* columns. This is the shape
 * Zuri's contract expects (handover §6): lunch/dinner as display text, plus the
 * bookable-slot window. The row's sync_uuid is minted once and never changes —
 * it is the fixed uuid Zuri keys the record on.
 *
 * Supersedes the per-day opening_hours table (includes/opening-hours.php), which
 * nothing reads and is left in place only so no data is dropped.
 *
 * Saves go through menu_sync_tx + menu_sync_emit, so an edit and its outbox
 * event land in one transaction; only the synced venue (SYNC_VENUE_SLUG) emits.
 * All reads are pre-migration-safe via rhours_supported().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/menu-sync.php';

function rhours_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.restaurant_hours')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Today's reservation rules (reservation_slots(): 12:00–22:00, 30-min) — the editor's starting values. */
function rhours_defaults(): array {
    return ['lunch' => null, 'dinner' => null, 'first_slot' => '12:00', 'last_slot' => '22:00',
            'slot_minutes' => 30, 'duration_minutes' => 90];
}

function fetch_restaurant_hours(int $venueId): ?array {
    if (!rhours_supported() || $venueId <= 0) return null;
    $row = db_query('SELECT * FROM restaurant_hours WHERE venue_id = :v', [':v' => $venueId])->fetch();
    return $row ?: null;
}

/** "HH:MM" (or "H:MM", "HH:MM:SS") → "HH:MM", else null. Rejects the timepicker's "+1". */
function rhours_hm(mixed $v): ?string {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim((string) $v), $m)) return null;
    $h = (int) $m[1]; $i = (int) $m[2];
    if ($h > 23 || $i > 59) return null;
    return sprintf('%02d:%02d', $h, $i);
}

/**
 * Validate posted hours. Pure. Returns ['data' => normalised row, 'errors' =>
 * field => message]. Bounds follow the contract: slot/duration ≥ 15 minutes,
 * last slot not before the first (a service never crosses midnight here).
 */
function rhours_validate(array $in): array {
    $err = [];
    $text = static function ($v): ?string {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    };
    $d = [
        'lunch'            => $text($in['lunch'] ?? null),
        'dinner'           => $text($in['dinner'] ?? null),
        'first_slot'       => rhours_hm($in['first_slot'] ?? ''),
        'last_slot'        => rhours_hm($in['last_slot'] ?? ''),
        'slot_minutes'     => (int) ($in['slot_minutes'] ?? 0),
        'duration_minutes' => (int) ($in['duration_minutes'] ?? 0),
    ];
    foreach (['lunch', 'dinner'] as $k) {
        if ($d[$k] !== null && mb_strlen($d[$k]) > 120) $err[$k] = 'Keep it under 120 characters.';
    }
    if ($d['first_slot'] === null) $err['first_slot'] = 'Pick a time (HH:MM).';
    if ($d['last_slot'] === null)  $err['last_slot']  = 'Pick a time (HH:MM).';
    if ($d['first_slot'] !== null && $d['last_slot'] !== null && $d['last_slot'] < $d['first_slot']) {
        $err['last_slot'] = 'The last slot must be at or after the first.';
    }
    if ($d['slot_minutes'] < 15 || $d['slot_minutes'] > 240) $err['slot_minutes'] = 'Between 15 and 240 minutes.';
    if ($d['duration_minutes'] < 15 || $d['duration_minutes'] > 600) $err['duration_minutes'] = 'Between 15 and 600 minutes.';
    return ['data' => $d, 'errors' => $err];
}

/** The fields that matter for "did anything change" — compared in normalised form. */
function rhours_comparable(array $row): array {
    return [
        'lunch'            => ($row['lunch'] ?? null) === '' ? null : ($row['lunch'] ?? null),
        'dinner'           => ($row['dinner'] ?? null) === '' ? null : ($row['dinner'] ?? null),
        'first_slot'       => rhours_hm($row['first_slot'] ?? ''),
        'last_slot'        => rhours_hm($row['last_slot'] ?? ''),
        'slot_minutes'     => (int) ($row['slot_minutes'] ?? 0),
        'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
    ];
}

/**
 * Upsert a venue's hours (already validated) and queue the sync event in the
 * same transaction. An unchanged save writes nothing and emits nothing, so the
 * version stays a true edit count. Returns the row (null pre-migration).
 */
function save_restaurant_hours(int $venueId, array $d): ?array {
    if (!rhours_supported() || $venueId <= 0) return null;
    return menu_sync_tx(function () use ($venueId, $d) {
        $cur = fetch_restaurant_hours($venueId);
        $p = [
            ':lunch' => $d['lunch'], ':dinner' => $d['dinner'],
            ':fs' => $d['first_slot'], ':ls' => $d['last_slot'],
            ':sm' => (int) $d['slot_minutes'], ':dm' => (int) $d['duration_minutes'],
        ];
        if ($cur === null) {
            $id = (int) db_query(
                "INSERT INTO restaurant_hours (venue_id, lunch, dinner, first_slot, last_slot, slot_minutes, duration_minutes)
                 VALUES (:v, :lunch, :dinner, :fs, :ls, :sm, :dm) RETURNING id",
                $p + [':v' => $venueId]
            )->fetchColumn();
            menu_sync_emit('opening_hours', $id, 'create');
        } else {
            if (rhours_comparable($cur) === rhours_comparable($d)) return $cur;
            $id = (int) $cur['id'];
            db_query(
                "UPDATE restaurant_hours
                    SET lunch = :lunch, dinner = :dinner, first_slot = :fs, last_slot = :ls,
                        slot_minutes = :sm, duration_minutes = :dm, updated_at = now()
                  WHERE id = :id",
                $p + [':id' => $id]
            );
            menu_sync_emit('opening_hours', $id, 'update');
        }
        return fetch_restaurant_hours($venueId);
    });
}
