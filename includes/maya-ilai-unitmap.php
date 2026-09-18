<?php
declare(strict_types=1);
/**
 * Maya Ilai live "unit map" — read-only occupancy for the aerial compound view.
 *
 * This is the data behind the Unit Map tab in admin/maya-ilai-rates.php. It is
 * READ-ONLY end to end: it never writes a hold, a block or a rate. It answers one
 * question — "on date D, what is the status of every villa bedroom and every
 * studio, and who is in it?" — from the SAME availability_blocks / holds / bookings
 * the Gantt and the booking engine already use. So the map is "synced with the
 * system" by construction: any booking that reaches the calendar shows here, no
 * matter how it got there —
 *
 *   · website 24h holds and confirmed bookings  (block_type 'hold' / 'booked')
 *   · OTA imports over iCal (api/sync-ical.php)  (block_type 'blocked' + a bookings row)
 *   · channel-manager / eZee spreadsheet imports (block_type 'blocked' + a bookings row)
 *   · travel-agent holds, maintenance closures   (holds / 'blocked' with no bookings row)
 *
 * The classification rule that distinguishes a real stay from a maintenance
 * closure is load-bearing and lives in ONE place (mi_unitmap_is_stay): an OTA
 * import and a manual maintenance block are BOTH block_type='blocked' — the
 * imported one carries a bookings ledger row, the maintenance one does not. Get
 * this wrong and Booking.com stays read as "blocked", or a maintenance closure
 * reads as an occupied guest.
 *
 * Everything here is pre-migration-safe: mi_components_select() degrades the
 * per-bedroom (components) column to NULL when the composite migration has not
 * run, in which case a villa block reads as the whole villa — which is exactly
 * what a pre-migration Maya Ilai block already means.
 *
 * The pure status resolver (mi_unitmap_cell_status) takes annotated blocks and a
 * date and returns a status, so it can be unit-tested without a database
 * (tests/maya_ilai_unitmap.php).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/maya-ilai-inventory.php';
require_once __DIR__ . '/bookings.php';

/** Villa bedroom/space keys, in the fixed catalogue order, with display labels. */
function mi_unitmap_room_labels(): array {
    return ['double_a' => 'Double A', 'double_b' => 'Double B', 'bunk' => 'Bunk', 'living' => 'Living'];
}

/**
 * The three bedroom components that SLEEP guests (the living/kitchen space does
 * not). The daily summary counts only these plus studios, so it matches the
 * "available rooms / occupied / arrivals / departures" tally staff expect.
 */
function mi_unitmap_sleeping_components(): array {
    return ['double_a', 'double_b', 'bunk'];
}

/**
 * Is this annotated block a real guest STAY (as opposed to a maintenance/closed
 * block that should read as "blocked")?
 *
 * · 'hold' / 'booked' blocks come from our own engine — always a stay.
 * · A 'blocked' block is ambiguous: an OTA iCal import and a channel-manager
 *   spreadsheet import both write block_type='blocked', and so does a manual
 *   maintenance closure. The imported ones carry a bookings ledger row; the
 *   maintenance ones do not. That ledger row is the tell.
 */
function mi_unitmap_is_stay(string $blockType, bool $hasBooking): bool {
    if ($blockType === 'hold' || $blockType === 'booked') return true;
    return $hasBooking;
}

/**
 * Resolve the status of ONE cell (a villa bedroom, or a whole studio) on $date.
 *
 * $blocks: the blocks touching this unit, each annotated:
 *   ['date_from'=>'Y-m-d','date_to'=>'Y-m-d' (EXCLUSIVE checkout morning),
 *    'is_stay'=>bool, 'taken'=>string[] (components this block consumes)]
 * $component: a villa bedroom key to filter on, or NULL for a whole unit (studio).
 *
 * All comparisons are string compares on zero-padded 'Y-m-d', which are
 * chronological — the same convention the rest of the app relies on. date_to is
 * EXCLUSIVE, so a stay covers the NIGHT of date_from up to (not including)
 * date_to; the guest departs on the morning of date_to.
 *
 * Returns ['status'=>one of available|occupied|arriving|departing|blocked,
 *          'block'=>the winning block or null].
 */
function mi_unitmap_cell_status(array $blocks, string $date, ?string $component): array {
    $covering = null;   // covers the night of $date  (date_from <= date < date_to)
    $departing = null;  // ends on the morning of $date (date_to === date)
    foreach ($blocks as $b) {
        if ($component !== null && !in_array($component, $b['taken'], true)) continue;
        $df = (string)$b['date_from'];
        $dt = (string)$b['date_to'];
        if ($df <= $date && $date < $dt) {
            if ($covering === null) $covering = $b;
        } elseif ($dt === $date) {
            if ($departing === null) $departing = $b;
        }
    }
    if ($covering !== null) {
        $status = !$covering['is_stay']
            ? 'blocked'
            : ((string)$covering['date_from'] === $date ? 'arriving' : 'occupied');
        return ['status' => $status, 'block' => $covering];
    }
    if ($departing !== null) return ['status' => 'departing', 'block' => $departing];
    return ['status' => 'available', 'block' => null];
}

/**
 * A booking summary for the details panel, or null for an available cell / a
 * cell whose winning block has no useful detail.
 */
function mi_unitmap_booking_payload(?array $block): ?array {
    if ($block === null) return null;
    $isStay = (bool)$block['is_stay'];
    $guest  = trim((string)($block['guest'] ?? ''));
    return [
        'kind'      => $isStay ? 'booking' : 'block',
        'guest'     => $guest !== '' ? $guest : ($isStay ? 'Guest' : 'Maintenance / closed'),
        'source'    => (string)($block['source'] ?? ''),
        'check_in'  => (string)$block['date_from'],
        'check_out' => (string)$block['date_to'],           // exclusive (checkout morning)
        'status'    => (string)($block['status'] ?? ($isStay ? '' : 'blocked')),
        'ref'       => (string)($block['ref'] ?? ''),
        'note'      => (string)($block['notes'] ?? ''),
        'hold_id'   => $block['hold_id'] !== null ? (int)$block['hold_id'] : null,
    ];
}

/**
 * The whole compound's status on a single date.
 *
 * Returns:
 *   ['supported'=>bool, 'date'=>'Y-m-d',
 *    'villas'=>[ ['n'=>1,'label'=>'Villa 01','unit_id'=>int,
 *                 'rooms'=>['double_a'=>['label'=>..,'status'=>..,'booking'=>..|null], …]], … ],
 *    'studios'=>[ ['n'=>1,'label'=>'Studio 01A','unit_id'=>int,'status'=>..,'booking'=>..|null], … ],
 *    'summary'=>['available'=>N,'occupied'=>N,'arriving'=>N,'departing'=>N,'blocked'=>N] ]
 *
 * supported=false when the composite villa room is absent (a deploy before the
 * Maya Ilai catalogue migration, or a non-Maya-Ilai environment). The caller
 * shows a "not set up yet" note rather than an empty grid.
 */
function mi_unit_map(string $date): array {
    $none = ['supported' => false, 'date' => $date, 'villas' => [], 'studios' => [],
             'summary' => ['available' => 0, 'occupied' => 0, 'arriving' => 0, 'departing' => 0, 'blocked' => 0]];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $none;

    $villaRoomId  = (int) db_query('SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG])->fetchColumn();
    if (!$villaRoomId) return $none;
    $studioRoomId = (int) db_query('SELECT id FROM rooms WHERE slug = :s', [':s' => 'maya-ilai-studio'])->fetchColumn();

    // Freshen the calendar so an expired 24h hold does not linger on the map.
    expire_stale_holds();

    $villaUnits  = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order, id',
        [':r' => $villaRoomId]
    )->fetchAll();
    $studioUnits = $studioRoomId ? db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order, id',
        [':r' => $studioRoomId]
    )->fetchAll() : [];

    // Every block touching the night of $date OR ending on its morning (so a cell
    // can read "departing"). date_to is exclusive, so date_to >= date catches the
    // checkout-morning block; date_from <= date drops anything starting later.
    $roomIds = $studioRoomId ? [$villaRoomId, $studioRoomId] : [$villaRoomId];
    $in = implode(',', array_map('intval', $roomIds));   // ints only — safe to inline
    $rows = db_query(
        "SELECT ab.id, ab.unit_id, ab.date_from, ab.date_to, ab.block_type, ab.notes, ab.hold_id,
                " . mi_components_select('ab') . ",
                h.guest_name AS hold_guest, h.status AS hold_status
           FROM availability_blocks ab
           JOIN units u ON u.id = ab.unit_id
           LEFT JOIN holds h ON h.id = ab.hold_id
          WHERE u.room_id IN ($in)
            AND ab.date_from <= :d AND ab.date_to >= :d",
        [':d' => $date]
    )->fetchAll();

    $ledger = bookings_by_block_ids(array_column($rows, 'id'));

    // Annotate each block once (stay/blocked, guest, source, ref) and bucket by unit.
    $blocksByUnit = [];
    foreach ($rows as $r) {
        $bk        = $ledger[(int)$r['id']] ?? null;
        $type      = (string)$r['block_type'];
        $isStay    = mi_unitmap_is_stay($type, $bk !== null);
        $guest     = trim((string)($r['hold_guest'] ?? '')) ?: trim((string)($bk['guest_name'] ?? ''));
        $source    = $bk ? bookings_source_label((string)($bk['source'] ?? ''))
                         : (($type === 'hold' || $type === 'booked') ? 'Direct' : '');
        $status    = trim((string)($r['hold_status'] ?? '')) ?: trim((string)($bk['status'] ?? ''));
        $blocksByUnit[(int)$r['unit_id']][] = [
            'date_from' => (string)$r['date_from'],
            'date_to'   => (string)$r['date_to'],
            'block_type'=> $type,
            'is_stay'   => $isStay,
            'taken'     => mi_block_taken_components($r['components']),  // NULL => whole villa
            'guest'     => $guest,
            'source'    => $source,
            'status'    => $status,
            'ref'       => trim((string)($bk['external_ref'] ?? '')),
            'notes'     => trim((string)$r['notes']),
            'hold_id'   => $r['hold_id'] !== null ? (int)$r['hold_id'] : null,
        ];
    }

    $summary = ['available' => 0, 'occupied' => 0, 'arriving' => 0, 'departing' => 0, 'blocked' => 0];
    $bump = static function (string $s) use (&$summary) { if (isset($summary[$s])) $summary[$s]++; };
    $sleeping = mi_unitmap_sleeping_components();

    $villas = [];
    foreach ($villaUnits as $i => $u) {
        $uid    = (int)$u['id'];
        $blocks = $blocksByUnit[$uid] ?? [];
        $rooms  = [];
        foreach (mi_unitmap_room_labels() as $key => $label) {
            $res = mi_unitmap_cell_status($blocks, $date, $key);
            $rooms[$key] = [
                'label'   => $label,
                'status'  => $res['status'],
                'booking' => mi_unitmap_booking_payload($res['block']),
            ];
            if (in_array($key, $sleeping, true)) $bump($res['status']);
        }
        $villas[] = ['n' => $i + 1, 'label' => sprintf('Villa %02d', $i + 1), 'unit_id' => $uid, 'rooms' => $rooms];
    }

    $studios = [];
    foreach ($studioUnits as $i => $u) {
        $uid = (int)$u['id'];
        $res = mi_unitmap_cell_status($blocksByUnit[$uid] ?? [], $date, null);
        $bump($res['status']);
        $studios[] = [
            'n' => $i + 1, 'label' => sprintf('Studio %02dA', $i + 1), 'unit_id' => $uid,
            'status' => $res['status'], 'booking' => mi_unitmap_booking_payload($res['block']),
        ];
    }

    return ['supported' => true, 'date' => $date, 'villas' => $villas, 'studios' => $studios, 'summary' => $summary];
}
