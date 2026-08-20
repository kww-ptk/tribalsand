<?php
declare(strict_types=1);
// Zuri restaurant reservation logic. Run: php tests/restaurant_logic.php
// Pure logic — no DB writes, no migration required.
require_once __DIR__ . '/../includes/restaurant.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── restaurant_normalise_hours ─────────────────────────────────────────────
$d = restaurant_normalise_hours(null);
check('null config falls back to 18:00-22:00/30', $d['from'] === '18:00' && $d['to'] === '22:00' && $d['step'] === 30);
check('null config opens every day',              count($d['days']) === 7);

$p = restaurant_normalise_hours(['from' => '12:00', 'to' => '15:00', 'step' => 60, 'days' => [1,2,3]]);
check('partial config is preserved',              $p['from'] === '12:00' && $p['step'] === 60 && $p['days'] === [1,2,3]);

$bad = restaurant_normalise_hours(['from' => 'nonsense', 'to' => '', 'step' => 0, 'days' => 'x']);
check('garbage config falls back to defaults',    $bad['from'] === '18:00' && $bad['step'] === 30 && count($bad['days']) === 7);

// ── restaurant_slots_for ───────────────────────────────────────────────────
// 2026-08-20 is a Thursday (day 4).
$cfg  = ['days' => [0,1,2,3,4,5,6], 'from' => '18:00', 'to' => '22:00', 'step' => 30];
$open = restaurant_slots_for('2026-08-20', $cfg);
check('first slot is the opening time',     $open[0] === '18:00');
check('closing time is NOT bookable',       !in_array('22:00', $open, true));
check('last seating is 21:30',              end($open) === '21:30');
check('30-min steps give 8 slots',          count($open) === 8);

$hourly = restaurant_slots_for('2026-08-20', ['days' => [0,1,2,3,4,5,6], 'from' => '18:00', 'to' => '21:00', 'step' => 60]);
check('60-min steps give 3 slots',          $hourly === ['18:00', '19:00', '20:00']);

$closed = restaurant_slots_for('2026-08-20', ['days' => [0,1,2,3,5,6], 'from' => '18:00', 'to' => '22:00', 'step' => 30]);
check('closed day yields no slots',         $closed === []);

// ── regression: inverted windows and impossible dates ──────────────────────
check('inverted window falls back to defaults', restaurant_slots_for('2026-08-20', ['days'=>[0,1,2,3,4,5,6],'from'=>'22:00','to'=>'18:00','step'=>30]) !== []);
check('from == to falls back to defaults',      restaurant_slots_for('2026-08-20', ['days'=>[0,1,2,3,4,5,6],'from'=>'18:00','to'=>'18:00','step'=>30]) !== []);
check('impossible date rejected',               restaurant_slots_for('2026-02-30', $cfg) === []);
check('relative date string rejected',          restaurant_slots_for('tomorrow', $cfg) === []);
check('empty date rejected',                    restaurant_slots_for('', $cfg) === []);
check('partial config keeps its closing time',  restaurant_normalise_hours(['from'=>'12:00','to'=>'15:00','step'=>60,'days'=>[1,2,3]])['to'] === '15:00');

// ── restaurant_validate ────────────────────────────────────────────────────
$cfg   = ['days' => [0,1,2,3,4,5,6], 'from' => '18:00', 'to' => '22:00', 'step' => 30];
$today = '2026-08-19';
$valid = [
    'name'       => 'Dan Oburu',
    'email'      => 'dan@example.com',
    'phone'      => '0703869559',
    'party_size' => 3,
    'date'       => '2026-08-20',
    'time'       => '18:30',
    'occasion'   => 'romantic',
];

check('a good request has no errors',      restaurant_validate($valid, $cfg, $today) === []);

check('name is required',                  isset(restaurant_validate(['name' => ''] + $valid, $cfg, $today)['name']));
check('email must be valid',               isset(restaurant_validate(['email' => 'nope'] + $valid, $cfg, $today)['email']));
check('phone stays optional',              restaurant_validate(['phone' => ''] + $valid, $cfg, $today) === []);

check('past date rejected',                isset(restaurant_validate(['date' => '2026-08-18'] + $valid, $cfg, $today)['date']));
check('today is bookable',                 !isset(restaurant_validate(['date' => '2026-08-19'] + $valid, $cfg, $today)['date']));
check('beyond 120-day horizon rejected',   isset(restaurant_validate(['date' => '2026-12-31'] + $valid, $cfg, $today)['date']));
check('malformed date rejected',           isset(restaurant_validate(['date' => '20/08/2026'] + $valid, $cfg, $today)['date']));

check('off-grid time rejected',            isset(restaurant_validate(['time' => '18:36'] + $valid, $cfg, $today)['time']));
check('closing time not bookable',         isset(restaurant_validate(['time' => '22:00'] + $valid, $cfg, $today)['time']));
check('on-grid time accepted',             !isset(restaurant_validate(['time' => '21:30'] + $valid, $cfg, $today)['time']));

check('party of 0 rejected',               isset(restaurant_validate(['party_size' => 0] + $valid, $cfg, $today)['party_size']));
check('party of 1 accepted',               !isset(restaurant_validate(['party_size' => 1] + $valid, $cfg, $today)['party_size']));
check('party of 20 accepted',              !isset(restaurant_validate(['party_size' => 20] + $valid, $cfg, $today)['party_size']));
$big = restaurant_validate(['party_size' => 21] + $valid, $cfg, $today);
check('party over 20 rejected',            isset($big['party_size']));
check('large party told to call',          str_contains(strtolower($big['party_size']), 'call'));

check('unknown occasion rejected',         isset(restaurant_validate(['occasion' => 'wedding'] + $valid, $cfg, $today)['occasion']));
check('empty occasion allowed',            restaurant_validate(['occasion' => ''] + $valid, $cfg, $today) === []);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
