<?php
/**
 * Staff room allocation — who sleeps in which staff room (and bed), per property,
 * with history. Page: Team → Staff rooms (admin/staff-rooms.php); the person's
 * current room shows on their profile.
 *
 * Rules:
 *  • A person has at most ONE current room (unique index on open allocations).
 *    Assigning someone who already has a room MOVES them: the old allocation is
 *    closed on the move date, in the same transaction.
 *  • A bed holds one person at a time; a room never takes more people than beds.
 *  • Rooms belong to a property (or none); a manager works only with rooms of
 *    their own properties (and people they can see) — owner and HR with all.
 *  • A room that was ever used is switched off, never deleted (history stays).
 * Every read is pre-migration-safe (staff_rooms_supported()).
 * Test: php tests/staff_rooms_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hr.php';

final class StaffRoomRefusal extends RuntimeException {}

function staff_rooms_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.staff_room_allocations')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** May an account scoped to $venueIds (null = everyone) manage this room's property? */
function staff_room_in_scope(?int $roomVenueId, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    return $roomVenueId !== null && in_array($roomVenueId, array_map('intval', $venueIds), true);
}

/** The first free bed of a room given the taken beds, or null when full. Pure. */
function staff_room_free_bed(int $beds, array $takenBeds): ?int {
    for ($b = 1; $b <= $beds; $b++) if (!in_array($b, array_map('intval', $takenBeds), true)) return $b;
    return null;
}

/**
 * Rooms in scope with their current occupants:
 * [['id','venue_id','label','beds','notes','is_active','people' => [['allocation_id','hr_staff_id','full_name','position','bed','date_from']]]].
 */
function staff_rooms_list(?array $venueIds, bool $withInactive = false): array {
    if (!staff_rooms_supported()) return [];
    $where = $withInactive ? 'WHERE 1=1' : 'WHERE r.is_active';
    $params = [];
    if ($venueIds !== null) {
        $ids = array_values(array_filter(array_map('intval', $venueIds)));
        if (!$ids) return [];
        $where .= ' AND r.venue_id IN (' . implode(',', $ids) . ')';
    }
    $rooms = db_query("SELECT r.* FROM staff_rooms r $where ORDER BY r.venue_id NULLS LAST, r.sort_order, r.label", $params)->fetchAll();
    if (!$rooms) return [];
    $in = implode(',', array_map(fn($r) => (int)$r['id'], $rooms));
    $people = [];
    foreach (db_query("SELECT a.id AS allocation_id, a.room_id, a.hr_staff_id, a.bed, a.date_from, s.full_name, s.position, s.status
                         FROM staff_room_allocations a JOIN hr_staff s ON s.id = a.hr_staff_id
                        WHERE a.room_id IN ($in) AND a.date_to IS NULL ORDER BY a.bed NULLS LAST, s.full_name")->fetchAll() as $p) {
        $people[(int)$p['room_id']][] = $p;
    }
    foreach ($rooms as &$r) $r['people'] = $people[(int)$r['id']] ?? [];
    unset($r);
    return $rooms;
}

/** A person's current room (label, property, bed, since), or null. */
function staff_room_of(int $staffId): ?array {
    if (!staff_rooms_supported()) return null;
    $r = db_query("SELECT a.id AS allocation_id, a.bed, a.date_from, r.id AS room_id, r.label, r.venue_id, v.name AS venue_name
                     FROM staff_room_allocations a JOIN staff_rooms r ON r.id = a.room_id LEFT JOIN venues v ON v.id = r.venue_id
                    WHERE a.hr_staff_id = :s AND a.date_to IS NULL", [':s' => $staffId])->fetch();
    return $r ?: null;
}

/** A person's room history, newest first. */
function staff_room_history(int $staffId): array {
    if (!staff_rooms_supported()) return [];
    return db_query("SELECT a.*, r.label, v.name AS venue_name FROM staff_room_allocations a JOIN staff_rooms r ON r.id = a.room_id
                       LEFT JOIN venues v ON v.id = r.venue_id WHERE a.hr_staff_id = :s ORDER BY a.date_from DESC, a.id DESC", [':s' => $staffId])->fetchAll();
}

/** Create or update a room. Returns its id. */
function staff_room_save(?int $id, ?int $venueId, string $label, int $beds, string $notes): int {
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 120) throw new StaffRoomRefusal('Give the room a name (up to 120 characters).');
    if ($beds < 1 || $beds > 20) throw new StaffRoomRefusal('A room has 1 to 20 beds.');
    if ($id) {
        $inUse = (int) db_query('SELECT COUNT(*) FROM staff_room_allocations WHERE room_id = :r AND date_to IS NULL', [':r' => $id])->fetchColumn();
        $maxBed = (int) db_query('SELECT COALESCE(MAX(bed), 0) FROM staff_room_allocations WHERE room_id = :r AND date_to IS NULL', [':r' => $id])->fetchColumn();
        if ($beds < max($inUse, $maxBed)) throw new StaffRoomRefusal('Move people out first — ' . $inUse . ' ' . ($inUse === 1 ? 'person is' : 'people are') . ' in this room (bed ' . $maxBed . ' is taken).');
        db_query('UPDATE staff_rooms SET venue_id = :v, label = :l, beds = :b, notes = :n WHERE id = :id',
            [':v' => $venueId, ':l' => $label, ':b' => $beds, ':n' => trim($notes) !== '' ? trim($notes) : null, ':id' => $id]);
        return $id;
    }
    $order = (int) db_query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM staff_rooms')->fetchColumn();
    db_query('INSERT INTO staff_rooms (venue_id, label, beds, notes, sort_order) VALUES (:v, :l, :b, :n, :o)',
        [':v' => $venueId, ':l' => $label, ':b' => $beds, ':n' => trim($notes) !== '' ? trim($notes) : null, ':o' => $order]);
    return (int) db()->lastInsertId();
}

/**
 * Put a person in a room (bed optional — the first free one is used) from a date.
 * Someone already in a room is moved: their open allocation is closed on $from.
 * One transaction; the room row is locked so two assignments can't overfill it.
 */
function staff_room_assign(int $roomId, int $staffId, ?int $bed, string $from, string $note, ?int $adminId): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) throw new StaffRoomRefusal('Pick the date they move in.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $room = db_query('SELECT * FROM staff_rooms WHERE id = :r FOR UPDATE', [':r' => $roomId])->fetch();
        if (!$room || !$room['is_active']) throw new StaffRoomRefusal('That room is no longer in use.');
        $person = db_query("SELECT id, status FROM hr_staff WHERE id = :s", [':s' => $staffId])->fetch();
        if (!$person) throw new StaffRoomRefusal('That person is not in the directory.');
        if (($person['status'] ?? '') !== 'active') throw new StaffRoomRefusal('They have left — only current staff get a room.');
        $cur = db_query('SELECT * FROM staff_room_allocations WHERE hr_staff_id = :s AND date_to IS NULL FOR UPDATE', [':s' => $staffId])->fetch();
        if ($cur && (int)$cur['room_id'] === $roomId && ($bed === null || (int)$cur['bed'] === $bed)) throw new StaffRoomRefusal('They are already in this room.');
        if ($cur && $from < (string)$cur['date_from']) throw new StaffRoomRefusal('They moved into their current room on ' . date('j M Y', strtotime((string)$cur['date_from'])) . ' — pick a later date.');
        $taken = array_map('intval', array_column(db_query('SELECT bed FROM staff_room_allocations WHERE room_id = :r AND date_to IS NULL AND hr_staff_id <> :s AND bed IS NOT NULL',
            [':r' => $roomId, ':s' => $staffId])->fetchAll(), 'bed'));
        $count = (int) db_query('SELECT COUNT(*) FROM staff_room_allocations WHERE room_id = :r AND date_to IS NULL AND hr_staff_id <> :s', [':r' => $roomId, ':s' => $staffId])->fetchColumn();
        if ($count >= (int)$room['beds']) throw new StaffRoomRefusal('The room is full (' . (int)$room['beds'] . ' ' . ((int)$room['beds'] === 1 ? 'bed' : 'beds') . ').');
        if ($bed !== null) {
            if ($bed < 1 || $bed > (int)$room['beds']) throw new StaffRoomRefusal('That room has beds 1 to ' . (int)$room['beds'] . '.');
            if (in_array($bed, $taken, true)) throw new StaffRoomRefusal('Bed ' . $bed . ' is taken.');
        } else {
            $bed = staff_room_free_bed((int)$room['beds'], $taken);
        }
        if ($cur) db_query('UPDATE staff_room_allocations SET date_to = :d WHERE id = :id', [':d' => $from, ':id' => (int)$cur['id']]);
        db_query('INSERT INTO staff_room_allocations (room_id, hr_staff_id, bed, date_from, note, created_by) VALUES (:r, :s, :b, :f, :n, :by)',
            [':r' => $roomId, ':s' => $staffId, ':b' => $bed, ':f' => $from, ':n' => trim($note) !== '' ? trim($note) : null, ':by' => $adminId ?: null]);
        $id = (int) $pdo->lastInsertId();
        if ($own) $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Move someone out on a date (closes their allocation). */
function staff_room_vacate(int $allocationId, string $on): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) throw new StaffRoomRefusal('Pick the date they moved out.');
    $a = db_query('SELECT * FROM staff_room_allocations WHERE id = :id AND date_to IS NULL', [':id' => $allocationId])->fetch();
    if (!$a) throw new StaffRoomRefusal('They are no longer in that room.');
    if ($on < (string)$a['date_from']) throw new StaffRoomRefusal('They moved in on ' . date('j M Y', strtotime((string)$a['date_from'])) . ' — pick that day or later.');
    db_query('UPDATE staff_room_allocations SET date_to = :d WHERE id = :id', [':d' => $on, ':id' => $allocationId]);
}

/** Remove a room: deleted when never used, else switched off (refused while someone is in it). Returns 'deleted'|'deactivated'. */
function staff_room_remove(int $roomId): string {
    if ((int) db_query('SELECT COUNT(*) FROM staff_room_allocations WHERE room_id = :r AND date_to IS NULL', [':r' => $roomId])->fetchColumn() > 0) {
        throw new StaffRoomRefusal('Move everyone out of the room first.');
    }
    if ((int) db_query('SELECT COUNT(*) FROM staff_room_allocations WHERE room_id = :r', [':r' => $roomId])->fetchColumn() > 0) {
        db_query('UPDATE staff_rooms SET is_active = FALSE WHERE id = :r', [':r' => $roomId]);
        return 'deactivated';
    }
    db_query('DELETE FROM staff_rooms WHERE id = :r', [':r' => $roomId]);
    return 'deleted';
}
