<?php
declare(strict_types=1);
// Calendar zoom + "Services & excursions" row (admin/gantt.php, includes/gantt-services.php).
// Run: php tests/gantt_services_logic.php   (pure; the DB reader is read-only and fails soft)
require_once __DIR__ . '/../includes/gantt-services.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// Zoom + window
check('unknown zoom → quarter (the old view)', gantt_zoom('nope') === 'quarter' && gantt_zoom(null) === 'quarter');
check('week = 14 days from this Monday', gantt_window('week', 0, '2026-10-09') === ['2026-10-05', '2026-10-19']);
check('week steps a week', gantt_window('week', -1, '2026-10-09') === ['2026-09-28', '2026-10-12']);
check('month = one calendar month', gantt_window('month', 0, '2026-10-31') === ['2026-10-01', '2026-11-01']);
check('quarter = three months from the 1st, stepping a month', gantt_window('quarter', 1, '2026-10-09') === ['2026-11-01', '2027-02-01']);
check('quarter keeps the old 28px day', GANTT_ZOOMS['quarter']['day_w'] === 28);

// Groups
check('activity/tour → excursions', gantt_service_group('tour') === 'excursion' && gantt_service_group('activity') === 'excursion');
check('transfer/flight → transfers', gantt_service_group('transfer') === 'transfer' && gantt_service_group('flight') === 'transfer');
check('restaurant/dining → dining', gantt_service_group('restaurant') === 'dining' && gantt_service_group('dining') === 'dining');
check('housekeeping/laundry/amenities → house', gantt_service_group('laundry') === 'house' && gantt_service_group('amenities') === 'house');
check('maintenance → repair; anything else → other', gantt_service_group('maintenance') === 'repair' && gantt_service_group('itinerary') === 'other');
check('every group has a chip label', count(array_unique(array_map('gantt_service_group', ['tour','transfer','dining','laundry','maintenance','x']))) === count(GANTT_SERVICE_GROUPS));

// Lanes
$idx = ['2026-10-05' => 0, '2026-10-06' => 1];
$l = gantt_service_lanes([['key' => 'a', 'day' => '2026-10-05'], ['key' => 'b', 'day' => '2026-10-05'], ['key' => 'c', 'day' => '2026-10-06'], ['key' => 'd', 'day' => '2026-12-01']], $idx);
check('same day stacks, next day starts at the top', $l['lanes'] === ['a' => 0, 'b' => 1, 'c' => 0] && $l['count'] === 2);
check('an item outside the window is dropped', !isset($l['lanes']['d']));

// Time
check('a date-only request shows no time', gantt_service_time('2026-10-12 00:00:00') === '' && gantt_service_time(null) === '');
check('a timed request shows HH:MM', gantt_service_time('2026-10-11 19:30:00') === '19:30');

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
