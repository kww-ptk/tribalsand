<?php
declare(strict_types=1);
/**
 * Multi-room bookings: a room COMBINATION (from ts_property_configurations()) held
 * as one request — every room or none. The holds share holds.group_ref and are
 * confirmed / cancelled together with ONE email to the guest.
 * Migration: db/migrations/add_hold_groups.sql. Test: php tests/hold_groups_logic.php.
 *
 * Rules that are load-bearing:
 *  - All-or-nothing: every unit is allocated and blocked inside ONE transaction;
 *    if any room is gone the whole request rolls back (the lead row is kept).
 *  - The request is re-validated on the server: rooms must belong to the venue,
 *    be published, individually bookable, in availability mode, and sleep the
 *    party. The client's combination is a request, never trusted.
 *  - Maya Ilai composite products allocate through mi_allocate_and_hold() (it
 *    joins the outer transaction); everything else through find_available_unit()
 *    + create_hold_with_block(), which see this transaction's earlier blocks, so
 *    two units of one room type land on two different units.
 *  - A group is ONE request: confirming or cancelling any member applies to all
 *    (hold_group_ids()). A hold with no group is a group of one — same code path.
 */

require_once __DIR__ . '/db.php';

const HOLD_GROUP_MAX_ROOMS = 6;    // distinct room types in one request
const HOLD_GROUP_MAX_UNITS = 12;   // total rooms in one request

/** True once add_hold_groups.sql has run (catalog lookup — safe in a transaction). */
function hold_groups_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { return $ok = (bool) db_query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'holds' AND column_name = 'group_ref'")->fetchColumn(); }
    catch (Throwable $e) { return $ok = false; }
}

/**
 * Normalise a posted room list [{slug, units}] — PURE. Merges repeats, clamps
 * nothing silently: returns [rooms, error|null].
 */
function hold_group_clean_rooms(mixed $raw): array {
    if (!is_array($raw) || !$raw) return [[], 'Pick the rooms to hold.'];
    $rooms = [];
    foreach ($raw as $r) {
        $slug  = is_array($r) ? trim((string)($r['slug'] ?? '')) : '';
        $units = is_array($r) ? (int)($r['units'] ?? 0) : 0;
        if ($slug === '' || !preg_match('/^[a-z0-9_-]{1,80}$/', $slug)) return [[], 'One of those rooms is not valid.'];
        if ($units < 1 || $units > HOLD_GROUP_MAX_UNITS) return [[], 'One of those rooms has a bad count.'];
        $rooms[$slug] = ($rooms[$slug] ?? 0) + $units;
    }
    if (count($rooms) > HOLD_GROUP_MAX_ROOMS) return [[], 'That is too many different rooms for one request — please contact us.'];
    if (array_sum($rooms) > HOLD_GROUP_MAX_UNITS) return [[], 'That is too many rooms for one online request — please contact us.'];
    if (array_sum($rooms) < 2) return [[], 'A combination has at least two rooms — book a single room from its own card.'];
    $out = [];
    foreach ($rooms as $slug => $units) $out[] = ['slug' => $slug, 'units' => $units];
    return [$out, null];
}

/** A new group reference, e.g. "G-260928-4F1A2B". */
function hold_group_new_ref(): string {
    return 'G-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Resolve a cleaned room list against the venue. Returns ['rooms' => [[room row,
 * units]], 'mode' => 'hold'|'enquiry', 'error' => ?string]. 'enquiry' when any room
 * takes enquiries only (form_mode) or has no inventory — then the whole request is
 * an enquiry (v1), never a partial hold.
 */
function hold_group_resolve(array $venue, array $rooms, int $guests): array {
    $out = ['rooms' => [], 'mode' => 'hold', 'error' => null];
    $capacity = 0;
    foreach ($rooms as $r) {
        $room = db_query('SELECT * FROM rooms WHERE slug = :s', [':s' => $r['slug']])->fetch();
        if (!$room || (int)$room['venue_id'] !== (int)$venue['id'] || !companies_bool_hg($room['is_published'])) {
            $out['error'] = 'One of those rooms is not available at this property.'; return $out;
        }
        if (companies_bool_hg($room['is_entire_place'] ?? false)) { $out['error'] = 'The whole property is booked on its own, not in a combination.'; return $out; }
        $cap = (int)($room['capacity'] ?? 0);
        if ($cap <= 0) { $out['error'] = "{$room['name']} has no capacity on record — please contact us."; return $out; }
        $capacity += $cap * (int)$r['units'];
        $mode = !empty($room['form_mode']) ? $room['form_mode'] : setting('form_mode', 'enquiry');
        if ($mode !== 'availability' || count(fetch_units_by_room(room_inventory_room_id($room))) === 0) $out['mode'] = 'enquiry';
        $out['rooms'][] = ['room' => $room, 'units' => (int)$r['units']];
    }
    if ($capacity < $guests) $out['error'] = "Those rooms sleep {$capacity} — not enough for {$guests} guests.";
    return $out;
}

/** Postgres/PHP boolean → bool (local copy so this file stays standalone). */
function companies_bool_hg(mixed $v): bool { return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true'; }

/**
 * Hold every room of a resolved combination, all or nothing. Returns the hold ids
 * (first = the lead room), or false when any room was taken meanwhile.
 */
function hold_group_create(array $resolved, int $submissionId, string $ci, string $co, string $name, string $email): array|false {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT hold_group');
    try {
        $ids = [];
        foreach ($resolved['rooms'] as $rr) {
            $room = $rr['room'];
            for ($i = 0; $i < $rr['units']; $i++) {
                if (mi_is_composite_room($room)) {
                    $id = mi_allocate_and_hold($room, $submissionId, $ci, $co, $name, $email, 'pending', 24);
                } else {
                    $unit = find_available_unit((int)$room['id'], $ci, $co);   // sees this transaction's earlier blocks
                    $id = $unit ? create_hold_with_block((int)$unit['id'], $submissionId, $ci, $co, $name, $email,
                                                           'pending', 24, $unit['_mi_components'] ?? null, (int)$room['id']) : false;
                }
                if (!$id) throw new RuntimeException('taken');
                $ids[] = (int)$id;
            }
        }
        if (hold_groups_supported() && count($ids) > 1) {
            db_query('UPDATE holds SET group_ref = :g WHERE id IN (' . implode(',', $ids) . ')', [':g' => hold_group_new_ref()]);
        }
        if ($own) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT hold_group');
        return $ids;
    } catch (Throwable $e) {
        if ($own) { if ($pdo->inTransaction()) $pdo->rollBack(); }
        else { try { $pdo->exec('ROLLBACK TO SAVEPOINT hold_group'); $pdo->exec('RELEASE SAVEPOINT hold_group'); } catch (Throwable $ignored) {} }
        if ($e->getMessage() === 'taken') return false;
        throw $e;
    }
}

/** Every hold of $holdId's group, oldest first — [$holdId] when it has none. */
function hold_group_ids(int $holdId): array {
    if (!hold_groups_supported()) return [$holdId];
    $g = db_query('SELECT group_ref FROM holds WHERE id = :h', [':h' => $holdId])->fetchColumn();
    if (!$g) return [$holdId];
    return array_map('intval', db_query('SELECT id FROM holds WHERE group_ref = :g ORDER BY id', [':g' => $g])->fetchAll(PDO::FETCH_COLUMN));
}

/** The holds of a group with their room names (for banners and emails). */
function hold_group_rows(int $holdId): array {
    $ids = hold_group_ids($holdId);
    return db_query("SELECT h.*, u.name AS unit_name, r.name AS room_name, r.venue_id, v.name AS venue_name
                       FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
                       LEFT JOIN venues v ON v.id = r.venue_id
                      WHERE h.id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY h.id")->fetchAll();
}

/** "Garden Room ×2 + Ocean Suite" — the rooms of a group as one label. Pure. */
function hold_group_label(array $rows): string {
    $count = [];
    foreach ($rows as $r) $count[(string)$r['room_name']] = ($count[(string)$r['room_name']] ?? 0) + 1;
    $parts = [];
    foreach ($count as $name => $n) $parts[] = $name . ($n > 1 ? " ×{$n}" : '');
    return implode(' + ', $parts);
}

/**
 * A single hold row that stands for the whole group in one email: the lead hold
 * with room_name = every room, unit_name = "3 rooms". A group of one is unchanged.
 */
function hold_group_mail_row(array $rows): array {
    $lead = $rows[0];
    if (count($rows) < 2) return $lead;
    return array_merge($lead, ['room_name' => hold_group_label($rows), 'unit_name' => count($rows) . ' rooms']);
}

/**
 * Confirm every PENDING hold of $holdId's group — the same steps the admin confirm
 * always ran, per hold — and return ['ids' => confirmed ids, 'mail_row' => one row
 * for the guest email, 'acct' => accounting note]. Nothing to confirm → ids [].
 */
function hold_group_confirm(int $holdId, ?int $userId): array {
    require_once __DIR__ . '/bookings.php';
    require_once __DIR__ . '/acct.php';
    $done = []; $acct = '';
    foreach (hold_group_ids($holdId) as $id) {
        $upd = db_query("UPDATE holds SET status = 'confirmed', confirmed_at = NOW() WHERE id = :id AND status = 'pending'", [':id' => $id]);
        if ($upd->rowCount() !== 1) continue;
        db_query("UPDATE availability_blocks SET block_type = 'booked' WHERE hold_id = :hid", [':hid' => $id]);
        bookings_sync_hold($id);   // snapshot revenue at confirm, per room
        $acct .= acct_hook_hold_confirmed($id, $userId);
        $done[] = $id;
    }
    $rows = $done ? array_values(array_filter(hold_group_rows($holdId), fn($r) => in_array((int)$r['id'], $done, true))) : [];
    return ['ids' => $done, 'mail_row' => $rows ? hold_group_mail_row($rows) : null, 'acct' => $acct];
}

/**
 * Cancel every hold of $holdId's group that is still pending or confirmed: dates
 * freed, ledger marked, an invoiced stay credited. Returns ['ids', 'mail_row', 'acct'].
 */
function hold_group_cancel(int $holdId, ?int $userId): array {
    require_once __DIR__ . '/bookings.php';
    require_once __DIR__ . '/acct.php';
    $rowsBefore = hold_group_rows($holdId);
    $done = []; $acct = '';
    foreach (hold_group_ids($holdId) as $id) {
        $upd = db_query("UPDATE holds SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id AND status IN ('pending','confirmed')", [':id' => $id]);
        if ($upd->rowCount() !== 1) continue;
        db_query('DELETE FROM availability_blocks WHERE hold_id = :hid', [':hid' => $id]);
        bookings_mark_hold_cancelled($id);
        $acct .= acct_hook_hold_cancelled($id, $userId);
        $done[] = $id;
    }
    $rows = array_values(array_filter($rowsBefore, fn($r) => in_array((int)$r['id'], $done, true)));
    return ['ids' => $done, 'mail_row' => $rows ? hold_group_mail_row($rows) : null, 'acct' => $acct];
}
