<?php
declare(strict_types=1);
// Restaurant reservation helpers — slots, badges, create, scope, status.
// Run: php tests/reservations_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end, so no
// real reservation rows are ever left behind. Requires add_reservations.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/reservations.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic (no DB) ──────────────────────────────────────────────────────
$slots = reservation_slots();
check('slots start at 12:00',            isset($slots['12:00']));
check('slots end at 22:00',              isset($slots['22:00']));
check('slots are 30-min steps',          isset($slots['12:30']) && !isset($slots['12:15']));
check('slot label is 12-hour',           $slots['13:30'] === '1:30 PM' && $slots['12:00'] === '12:00 PM');
check('badge: confirmed → green',        reservation_status_badge('confirmed') === 'badge--green');
check('badge: cancelled → red',          reservation_status_badge('cancelled') === 'badge--red');
check('badge: pending → orange',         reservation_status_badge('pending') === 'badge--orange');
check('time label formats',              reservation_time_label('19:30:00') === '7:30 PM');
check('editable: owner (null) always',   reservation_editable(null, ['venue_id' => 5]) === true);
check('editable: scoped in-set',         reservation_editable([5, 6], ['venue_id' => 5]) === true);
check('editable: scoped out-of-set',     reservation_editable([6], ['venue_id' => 5]) === false);
check('editable: null row → false',      reservation_editable(null, null) === false);

if (!reservations_supported()) {
    echo "\nSKIP  DB assertions (add_reservations.sql not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

$venueId = (int) db_query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
if (!$venueId) { echo "\nSKIP  no venues seeded\n"; exit($failures ? 1 : 0); }
$otherVenue = (int) db_query('SELECT id FROM venues WHERE id <> :v ORDER BY id LIMIT 1', [':v' => $venueId])->fetchColumn();

db()->beginTransaction();
try {
    // ── create_reservation ──
    $res = create_reservation([
        'venue_id'         => $venueId,
        'reservation_date' => '2099-06-15',
        'reservation_time' => '19:30',
        'party_size'       => 4,
        'guest_name'       => 'Test Diner',
        'guest_phone'      => '+254700000000',
        'guest_email'      => 'diner@example.com',
        'notes'            => 'Window table please',
    ]);
    $rid = (int)($res['id'] ?? 0);
    check('create returns a row',              $rid > 0);
    check('create defaults to pending',        ($res['status'] ?? '') === 'pending');
    check('create mints TSR reference',        str_starts_with((string)($res['reference'] ?? ''), 'TSR-' . $venueId . '-'));
    check('create stores party size',          (int)($res['party_size'] ?? 0) === 4);
    check('create records client_ip',          ($res['client_ip'] ?? '') !== '');

    // ── fetch ──
    $one = fetch_reservation($rid);
    check('fetch_reservation joins venue name', !empty($one['venue_name']));

    // ── scope ──
    check('owner scope (null) sees the row',   (bool) array_filter(fetch_reservations(null), fn($r) => (int)$r['id'] === $rid));
    check('in-scope venue sees the row',        (bool) array_filter(fetch_reservations([$venueId]), fn($r) => (int)$r['id'] === $rid));
    check('empty scope sees nothing',           fetch_reservations([]) === []);
    if ($otherVenue) {
        check('other venue scope hides the row', !array_filter(fetch_reservations([$otherVenue]), fn($r) => (int)$r['id'] === $rid));
    }

    // ── filters ──
    check('status filter: pending matches',     (bool) array_filter(fetch_reservations(null, ['status' => 'pending']), fn($r) => (int)$r['id'] === $rid));
    check('status filter: confirmed excludes',  !array_filter(fetch_reservations(null, ['status' => 'confirmed']), fn($r) => (int)$r['id'] === $rid));
    check('date filter matches',                (bool) array_filter(fetch_reservations(null, ['date' => '2099-06-15']), fn($r) => (int)$r['id'] === $rid));
    check('count honours filter',               count_reservations(null, ['status' => 'pending']) >= 1);

    // ── status transitions ──
    check('confirm transitions',                set_reservation_status($rid, 'confirmed') === true);
    check('confirm is now reflected',           (fetch_reservation($rid)['status'] ?? '') === 'confirmed');
    check('re-confirm is a no-op',              set_reservation_status($rid, 'confirmed') === false);
    check('cancel transitions',                 set_reservation_status($rid, 'cancelled') === true);
    check('bad status rejected',                set_reservation_status($rid, 'bogus') === false);

    // ── dashboard counts (scoped) ──
    $counts = reservation_dashboard_counts([$venueId]);
    check('dashboard counts return keys',       isset($counts['today'], $counts['upcoming'], $counts['pending']));
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
