<?php
declare(strict_types=1);
// Maya Ilai live unit map — the pure status resolver behind the aerial view.
// Run: php tests/maya_ilai_unitmap.php
//
// Everything here is READ-ONLY and DB-free: mi_unitmap_cell_status(),
// mi_unitmap_is_stay() and mi_unitmap_booking_payload() are pure. (Requiring the
// helper file pulls in db.php for its function definitions but never connects.)
require_once __DIR__ . '/../includes/maya-ilai-unitmap.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

/** Build one annotated block the resolver understands. */
function blk(string $df, string $dt, bool $isStay, array $taken): array {
    return ['date_from' => $df, 'date_to' => $dt, 'is_stay' => $isStay, 'taken' => $taken,
            'guest' => 'Sofia Martin', 'source' => 'Direct', 'status' => 'confirmed',
            'ref' => '', 'notes' => '', 'hold_id' => 42];
}

$ALL = MAYA_ILAI_ALL_COMPONENTS;   // double_a, double_b, bunk, living

// ── is_stay classification (OTA vs maintenance) ──────────────────────────────
check('hold block is a stay',              mi_unitmap_is_stay('hold', false) === true);
check('booked block is a stay',            mi_unitmap_is_stay('booked', false) === true);
check('blocked + ledger row is a stay',    mi_unitmap_is_stay('blocked', true) === true);   // OTA / channel-manager import
check('blocked + no ledger is maintenance',mi_unitmap_is_stay('blocked', false) === false); // manual closure

// ── cell status: the empty and the covered ───────────────────────────────────
check('no blocks → available', mi_unitmap_cell_status([], '2026-09-17', 'double_a')['status'] === 'available');

$mid = [blk('2026-09-15', '2026-09-19', true, ['double_a'])];
check('mid-stay on the booked bedroom → occupied', mi_unitmap_cell_status($mid, '2026-09-17', 'double_a')['status'] === 'occupied');
check('other bedroom in the same villa → available', mi_unitmap_cell_status($mid, '2026-09-17', 'double_b')['status'] === 'available');
check('arrival day (== date_from) → arriving', mi_unitmap_cell_status($mid, '2026-09-15', 'double_a')['status'] === 'arriving');
// date_to is EXCLUSIVE (checkout morning): the last night is the 18th; the 19th departs.
check('checkout morning (== date_to) → departing', mi_unitmap_cell_status($mid, '2026-09-19', 'double_a')['status'] === 'departing');
check('the night before checkout is still occupied', mi_unitmap_cell_status($mid, '2026-09-18', 'double_a')['status'] === 'occupied');
check('after departure → available', mi_unitmap_cell_status($mid, '2026-09-20', 'double_a')['status'] === 'available');

// ── maintenance vs guest ──────────────────────────────────────────────────────
$maint = [blk('2026-09-16', '2026-09-19', false, $ALL)];   // whole-villa closure (components NULL → all)
check('maintenance block reads as blocked', mi_unitmap_cell_status($maint, '2026-09-17', 'bunk')['status'] === 'blocked');
check('a whole-villa block covers every bedroom', mi_unitmap_cell_status($maint, '2026-09-17', 'double_a')['status'] === 'blocked');

// ── back-to-back: arrival wins over the same day's departure ─────────────────
$b2b = [blk('2026-09-15', '2026-09-17', true, ['double_a']),   // departs the 17th
        blk('2026-09-17', '2026-09-20', true, ['double_a'])];  // arrives the 17th
check('back-to-back day → arriving (a covering stay outranks a departure)',
      mi_unitmap_cell_status($b2b, '2026-09-17', 'double_a')['status'] === 'arriving');

// ── studios: whole-unit (component === null) ──────────────────────────────────
$studio = [blk('2026-09-14', '2026-09-17', true, [])];   // taken irrelevant when component is null
check('studio mid-stay → occupied', mi_unitmap_cell_status($studio, '2026-09-15', null)['status'] === 'occupied');
check('studio checkout morning → departing', mi_unitmap_cell_status($studio, '2026-09-17', null)['status'] === 'departing');

// ── booking payload shape ─────────────────────────────────────────────────────
check('available cell → null payload', mi_unitmap_booking_payload(null) === null);
$pay = mi_unitmap_booking_payload(blk('2026-09-15', '2026-09-19', true, ['double_a']));
check('stay payload keeps the guest name', $pay['guest'] === 'Sofia Martin' && $pay['kind'] === 'booking');
check('stay payload carries exclusive checkout', $pay['check_out'] === '2026-09-19');
$mp = blk('2026-09-16', '2026-09-19', false, $ALL); $mp['guest'] = '';
$mpay = mi_unitmap_booking_payload($mp);
check('maintenance payload labels an unnamed block', $mpay['kind'] === 'block' && $mpay['guest'] === 'Maintenance / closed');

echo $failures ? "\n{$failures} FAILED\n" : "\nAll unit-map assertions passed.\n";
exit($failures ? 1 : 0);
