<?php
declare(strict_types=1);
// Financial reporting — pure aggregators + ledger round-trip.
// Run: php tests/reports_logic.php
// DB writes run inside a rolled-back transaction — nothing persists.
require_once __DIR__ . '/../includes/bookings.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure date maths ──
check('night count 3',        bookings_night_count('2026-01-01', '2026-01-04') === 3);
check('night count reversed',  bookings_night_count('2026-01-04', '2026-01-01') === 0);
check('night count same day',  bookings_night_count('2026-01-01', '2026-01-01') === 0);

check('overlap inside window',  bookings_night_overlap('2026-01-01', '2026-01-05', '2026-01-02', '2026-01-03') === 2);
check('overlap straddles start', bookings_night_overlap('2025-12-30', '2026-01-03', '2026-01-01', '2026-01-10') === 2);
check('overlap fully before',    bookings_night_overlap('2025-11-01', '2025-11-05', '2026-01-01', '2026-01-10') === 0);
check('overlap fully after',     bookings_night_overlap('2026-03-01', '2026-03-05', '2026-01-01', '2026-01-10') === 0);
check('overlap fully contains window', bookings_night_overlap('2025-01-01', '2027-01-01', '2026-01-01', '2026-01-10') === 10);

// ── Aggregation (pure — synthetic rows, no DB) ──
$rows = [
    ['venue_name'=>'Zuri','source'=>'website','currency'=>'USD','gross_amount'=>1000,'nights'=>4,'check_in'=>'2026-01-05','check_out'=>'2026-01-09'],
    ['venue_name'=>'Zuri','source'=>'ota',    'currency'=>'USD','gross_amount'=>500, 'nights'=>2,'check_in'=>'2026-02-10','check_out'=>'2026-02-12'],
    ['venue_name'=>'Maya','source'=>'agent',  'currency'=>'KES','gross_amount'=>60000,'nights'=>3,'check_in'=>'2026-01-20','check_out'=>'2026-01-23'],
];
$s = bookings_summarize($rows);
check('USD revenue summed',   $s['currencies']['USD']['revenue'] === 1500.0);
check('USD nights summed',    $s['currencies']['USD']['nights'] === 6);
check('USD ADR = rev/nights', $s['currencies']['USD']['adr'] === 250.0);
check('KES kept separate',    $s['currencies']['KES']['revenue'] === 60000.0);
check('never mixes currencies', count($s['currencies']) === 2);
check('by_property Zuri USD',  $s['by_property']['Zuri']['USD']['revenue'] === 1500.0);
check('by_property Maya KES',  $s['by_property']['Maya']['KES']['bookings'] === 1);
check('by_source split',       $s['by_source']['website']['USD']['revenue'] === 1000.0
                            && $s['by_source']['ota']['USD']['revenue'] === 500.0
                            && $s['by_source']['agent']['KES']['revenue'] === 60000.0);
check('by_month split',        isset($s['by_month']['2026-01']['USD'], $s['by_month']['2026-02']['USD'], $s['by_month']['2026-01']['KES']));
check('by_month Jan USD rev',  $s['by_month']['2026-01']['USD']['revenue'] === 1000.0);

// Occupancy: 2 units × 10 window nights = 20 available; the Jan-05→09 stay (4 nights) is inside.
$occ = bookings_occupancy(
    [['check_in'=>'2026-01-05','check_out'=>'2026-01-09']],
    '2026-01-01', '2026-01-10', 2
);
check('occupancy available',  $occ['available'] === 20);
check('occupancy sold',       $occ['sold'] === 4);
check('occupancy pct',        $occ['pct'] === 20.0);
check('occupancy zero units', bookings_occupancy($rows, '2026-01-01', '2026-01-10', 0)['pct'] === 0.0);

// ── Formatting ──
check('money USD',   bookings_money(1234.0, 'USD') === '$1,234');
check('money KES',   bookings_money(60000.0, 'KES') === 'KES 60,000');
check('source label', bookings_source_label('ota') === 'OTA / channel');

// ── Ledger round-trip (only when the migration is applied) ──
if (!bookings_supported()) {
    echo "SKIP  ledger round-trip (add_bookings_finance not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}

$unit = db_query(
    "SELECT u.id AS unit_id, r.venue_id, r.price_amount, r.price_currency
     FROM units u JOIN rooms r ON r.id=u.room_id
     WHERE u.is_active = TRUE AND r.price_amount > 0
     ORDER BY u.id LIMIT 1"
)->fetch();
if (!$unit) { echo "\nSKIP  no priced active unit seeded\n"; echo ($failures?"{$failures} FAILURE(S)\n":"ALL PASS\n"); exit($failures?1:0); }

$otherVenue = (int) db_query("SELECT id FROM venues WHERE id <> :v ORDER BY id LIMIT 1", [':v'=>$unit['venue_id']])->fetchColumn();

db()->beginTransaction();
try {
    $ci = '2031-05-01'; $co = '2031-05-05';   // far future so no seed collides
    $holdId = create_hold_with_block((int)$unit['unit_id'], null, $ci, $co, 'Ledger Guest', 'lg@x.com', 'confirmed', null);

    bookings_sync_hold($holdId);
    $b = db_query("SELECT * FROM bookings WHERE hold_id=:h", [':h'=>$holdId])->fetch();
    $expected = room_stay_quote(
        (int) db_query("SELECT room_id FROM units WHERE id=:u", [':u'=>$unit['unit_id']])->fetchColumn(),
        (float)$unit['price_amount'], $ci, $co
    );
    check('sync wrote a website ledger row', (bool)$b);
    check('ledger source website', $b && $b['source'] === 'website');
    check('ledger status confirmed', $b && $b['status'] === 'confirmed');
    check('ledger nights = 4', $b && (int)$b['nights'] === 4);
    check('ledger gross = rate map × nights (snapshot)', $b && (float)$b['gross_amount'] === (float)$expected['total']);

    // Idempotent: re-sync updates the same row, never a duplicate.
    bookings_sync_hold($holdId);
    check('re-sync does not duplicate', (int)db_query("SELECT COUNT(*) FROM bookings WHERE hold_id=:h",[':h'=>$holdId])->fetchColumn() === 1);

    // Window + scope readers.
    $all = bookings_in_window(null, '2031-05-01', '2031-05-31');
    check('window includes the booking', in_array($holdId, array_map(fn($r)=>(int)$r['hold_id'], $all), true));

    $scoped = bookings_in_window([(int)$unit['venue_id']], '2031-05-01', '2031-05-31');
    check('scope to own venue includes it', count($scoped) >= 1);

    if ($otherVenue && $otherVenue !== (int)$unit['venue_id']) {
        $foreign = bookings_in_window([$otherVenue], '2031-05-01', '2031-05-31');
        $foundForeign = in_array($holdId, array_map(fn($r)=>(int)$r['hold_id'], $foreign), true);
        check('scope to another venue excludes it', !$foundForeign);
    }
    check('empty scope returns nothing', bookings_in_window([], '2031-05-01', '2031-05-31') === []);

    // Arrival outside the window is excluded.
    check('window by arrival excludes earlier month',
        !in_array($holdId, array_map(fn($r)=>(int)$r['hold_id'], bookings_in_window(null, '2031-04-01', '2031-04-30')), true));

    // Cancelling drops it from revenue reporting.
    bookings_mark_hold_cancelled($holdId);
    check('cancelled hold leaves the window', count(bookings_in_window(null, '2031-05-01', '2031-05-31', '')) === count($all) - 1);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
