<?php
declare(strict_types=1);
// Finance → Reports monthly report (includes/report-month.php) + the import
// channel rule (bookings_classify_source). Pure — no DB needed.
// Run: php tests/report_month_logic.php
require_once __DIR__ . '/../includes/report-month.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Period ──
$p = report_period('month', '2026-08', '2026-10-07');
check('month: whole August', $p['from'] === '2026-08-01' && $p['to'] === '2026-08-31');
check('month: label + headline word', $p['label'] === 'August 2026' && $p['short'] === 'August');
check('month: arrows move one month', $p['prev_anchor'] === '2026-07' && $p['next_anchor'] === '2026-09');
check('month: compares with July', $p['cmp_from'] === '2026-07-01' && $p['cmp_to'] === '2026-07-31');
$p = report_period('month', '2026-01', '2026-10-07');
check('month: January compares with December of the year before', $p['cmp_from'] === '2025-12-01' && $p['prev_anchor'] === '2025-12');
$p = report_period('quarter', '2026-08', '2026-10-07');
check('quarter: Q3 = Jul–Sep', $p['from'] === '2026-07-01' && $p['to'] === '2026-09-30' && $p['label'] === 'Q3 2026');
check('quarter: compares with Q2', $p['cmp_from'] === '2026-04-01' && $p['cmp_to'] === '2026-06-30');
$p = report_period('year', '2026-08', '2026-10-07');
check('year: calendar year', $p['from'] === '2026-01-01' && $p['to'] === '2026-12-31' && $p['prev_anchor'] === '2025-08');
$p = report_period('12m', '2026-08', '2026-10-07');
check('12 months: Sep 2025 – Aug 2026', $p['from'] === '2025-09-01' && $p['to'] === '2026-08-31');
check('12 months: compares with the 12 before', $p['cmp_from'] === '2024-09-01' && $p['cmp_to'] === '2025-08-31');
$p = report_period('nonsense', 'garbage', '2026-10-07');
check('bad input falls back to this month', $p['range'] === 'month' && $p['from'] === '2026-10-01');
check('notes key is stable per period', report_period('month', '2026-08', '2026-10-07')['key'] === 'month:2026-08-01');

// ── Channel rule (the eZee import) ──
check('Booking.com in Source = OTA', bookings_classify_source('Booking.com', '')['source'] === 'ota');
check('Booking.com as the agent = OTA, not agent', bookings_classify_source('', 'Booking.com')['source'] === 'ota');
check('Expedia (any case/spacing) = OTA', bookings_classify_source('EXPEDIA Partner', '')['source'] === 'ota');
check('a named travel agent = agent', bookings_classify_source('Paola Safaris', 'Paola Safaris') === ['source' => 'agent', 'channel' => 'Paola Safaris']);
check('walk-in = direct', bookings_classify_source('Walk-in', '-') === ['source' => 'direct', 'channel' => 'Walk-in']);
check('empty Source cell on a sheet that has the column = direct', bookings_classify_source('', '', true)['source'] === 'direct');
check('no Source column and no agent = unknown, kept as OTA (old behaviour)', bookings_classify_source('', '', false) === ['source' => 'ota', 'channel' => '']);
check('dash agent is ignored', bookings_classify_source('Email', '-')['source'] === 'direct');

// ── Model: stay-night split, commission, occupancy, channels, partners ──
$venues = [
    1 => ['id' => 1, 'name' => 'Zuri', 'town' => 'Watamu', 'units' => 6, 'photo' => ''],
    2 => ['id' => 2, 'name' => 'My Amani', 'town' => 'Vipingo', 'units' => 1, 'photo' => ''],
    3 => ['id' => 3, 'name' => 'Enkare Bofa', 'town' => 'Kilifi', 'units' => 1, 'photo' => ''],
    4 => ['id' => 4, 'name' => 'Tribal Dunes', 'town' => '', 'units' => 0, 'photo' => ''],
];
$stays = [
    // 4 nights all in August, Booking.com with commission
    ['venue_id' => 1, 'venue_name' => 'Zuri', 'check_in' => '2026-08-10', 'check_out' => '2026-08-14', 'gross_amount' => 400000,
     'commission_amount' => 60000, 'currency' => 'KES', 'source' => 'ota', 'channel' => 'Booking.com', 'agent' => '', 'entire_place' => false],
    // 28 Aug → 3 Sep: 6 nights, 4 in August → two thirds of the money
    ['venue_id' => 1, 'venue_name' => 'Zuri', 'check_in' => '2026-08-28', 'check_out' => '2026-09-03', 'gross_amount' => 660000,
     'commission_amount' => 0, 'currency' => 'KES', 'source' => 'agent', 'channel' => 'Paola Safaris', 'agent' => 'Paola Safaris', 'entire_place' => false],
    // whole-property villa, direct
    ['venue_id' => 2, 'venue_name' => 'My Amani', 'check_in' => '2026-08-07', 'check_out' => '2026-08-09', 'gross_amount' => 300000,
     'commission_amount' => 0, 'currency' => 'KES', 'source' => 'direct', 'channel' => 'Walk-in', 'agent' => '', 'entire_place' => true],
    // a USD booking — never added to KES
    ['venue_id' => 1, 'venue_name' => 'Zuri', 'check_in' => '2026-08-20', 'check_out' => '2026-08-21', 'gross_amount' => 500,
     'commission_amount' => 0, 'currency' => 'USD', 'source' => 'direct', 'channel' => '', 'agent' => '', 'entire_place' => false],
    // the Zuri buyout: whole property → every unit sold that night
    ['venue_id' => 1, 'venue_name' => 'Zuri', 'check_in' => '2026-08-25', 'check_out' => '2026-08-26', 'gross_amount' => 200000,
     'commission_amount' => 0, 'currency' => 'KES', 'source' => 'direct', 'channel' => '', 'agent' => '', 'entire_place' => true],
];
$extras = [
    ['venue_id' => 1, 'venue_name' => 'Zuri', 'kind' => 'restaurant', 'amount' => 50000, 'currency' => 'KES'],
    ['venue_id' => 1, 'venue_name' => 'Zuri', 'kind' => 'tour', 'amount' => 20000, 'currency' => 'KES'],
    ['venue_id' => 2, 'venue_name' => 'My Amani', 'kind' => 'bill', 'amount' => 0, 'currency' => 'KES'],   // ignored
];
$M = report_model($stays, $extras, '2026-08-01', '2026-08-31', $venues);
$k = $M['currencies']['KES'];
check('primary currency = the bigger one', $M['primary'] === 'KES');
check('USD kept apart', $M['currencies']['USD']['gross'] === 500.0 && count($M['currencies']) === 2);
check('stay-night split: 4 of 6 nights counted', abs($k['gross'] - (400000 + 440000 + 300000 + 200000)) < 0.01);
check('commission and net', $k['commission'] === 60000.0 && abs($k['net'] - 1280000) < 0.01);
check('extras add to total, zero-priced ignored', $k['extras'] === 70000.0 && abs($k['total'] - 1350000) < 0.01);
check('nights only inside the window', $k['nights'] === 4 + 4 + 2 + 1);
check('stays counted once each', $k['stays'] === 4);
$z = $M['venues'][1];
check('Zuri daily: 1 room = 16.7%', $z['daily'][9] === 16.7);
check('Zuri daily: the buyout fills every room', $z['daily'][24] === 100.0);
check('My Amani (sold whole) = 100% on its nights', $M['venues'][2]['daily'][6] === 100.0 && $M['venues'][2]['daily'][8] === 0.0);
check('a property with no bookings still shows (Enkare)', isset($M['venues'][3]) && $M['venues'][3]['cur'] === [] && $M['venues'][3]['occ'] === 0.0);
check('a property with nothing to sell and no revenue is hidden', !isset($M['venues'][4]));
check('portfolio occupancy over sold ÷ available unit-nights',
      $M['occupancy']['available'] === (6 + 1 + 1) * 31 && $M['occupancy']['sold'] === 4 + 4 + 1 + 6 + 2);
check('channels in fixed order: direct, agent, ota', array_keys($M['channels']['KES']) === ['direct', 'agent', 'ota']);
check('partners ranked by money', $M['partners']['KES'][0]['name'] === 'Paola Safaris' && $M['partners']['KES'][1]['name'] === 'Booking.com');
check('partner kinds', $M['partners']['KES'][1]['kind'] === 'OTA' && $M['partners']['KES'][0]['kind'] === 'Travel agent');
check('extras by type, biggest first', array_key_first($M['extras_by_type']['KES']) === 'Food & drink');

// ── Forward, deltas, money, story, notes ──
$fw = report_forward($stays, '2026-09', 2, 'KES');
check('forward: September gets the 2 spill-over nights', $fw[0]['ym'] === '2026-09' && $fw[0]['nights'] === 2 && abs($fw[0]['gross'] - 220000) < 0.01);
check('forward: empty month is 0', $fw[1]['nights'] === 0);
check('delta up', report_delta(120, 100) === ['dir' => 'up', 'label' => '▲ 20%']);
check('delta down', report_delta(80, 100)['dir'] === 'down');
check('no delta without a before figure', report_delta(80, 0.0) === null && report_delta(80, null) === null);
check('short money', report_money_short(10475992, 'KES') === 'KES 10.48M' && report_money_short(550084, 'KES') === 'KES 550K'
      && report_money_short(1500000, 'KES') === 'KES 1.5M' && report_money_short(1234, 'USD') === '$1,234');
$story = report_story($M, report_period('month', '2026-08', '2026-10-07'), 0);
check('headline names the month and the total', str_starts_with($story['headline'], 'August brought in {{KES 1.35M}}'));
check('headline names the lead property', str_contains($story['headline'], 'Zuri earned'));
check('lede: stays, nights, no cancellations', str_contains($story['lede'], '4 stays, 11 nights, no cancellations.'));
check('lede: extras, mostly food & drink', str_contains($story['lede'], 'mostly food & drink'));
check('lede: the empty property is named', str_contains($story['lede'], 'Enkare Bofa had no bookings.'));
check('lede: other currency mentioned, not added', str_contains($story['lede'], 'Plus $500 in USD.'));
$none = report_story(report_model([], [], '2026-08-01', '2026-08-31', $venues), report_period('month', '2026-08', '2026-10-07'), 0);
check('empty period says so', str_starts_with($none['headline'], 'No revenue recorded for August 2026'));
check('join names', report_join(['A', 'B', 'C']) === 'A, B and C' && report_join(['A']) === 'A');
check('note lines: bullets stripped, blanks dropped', report_note_lines("- One\n\n2. Two\n• Three") === ['One', 'Two', 'Three']);
check('note lines capped at 12', count(report_note_lines(str_repeat("x\n", 30))) === 12);
check('notes key per property', report_notes_key('month:2026-08-01', 0) === 'report_notes:month:2026-08-01:all'
      && report_notes_key('month:2026-08-01', 3) === 'report_notes:month:2026-08-01:3');

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
