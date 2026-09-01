<?php
declare(strict_types=1);
// Submission payload rendering — the admin submission view must display every
// payload shape without warnings, including the NESTED Trip Builder document.
// Run: php tests/submission_payload.php
// Pure logic only — no DB, no network.
require_once __DIR__ . '/../includes/submission-payload.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// Any PHP warning/notice becomes a thrown error, so "Array to string conversion"
// fails the test instead of silently printing "Array" like the bug did.
set_error_handler(function (int $no, string $str, string $file = '', int $line = 0): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

/** Runs $fn and returns true when it completes without raising a warning. */
function quiet(callable $fn): bool {
    try { $fn(); return true; }
    catch (Throwable $e) { echo "      ↳ {$e->getMessage()}\n"; return false; }
}

// ── Fixtures ────────────────────────────────────────────────────────────────
// Mirrors the exact document posted by trip-builder.php → api/trip-builder.php.
$tb = [
    'guest' => [
        'firstName' => 'Patrik', 'lastName' => 'Giuliana',
        'email' => 'p@example.com', 'phone' => '+254700000000',
        'nationality' => 'Italian', 'country' => 'Kenya',
    ],
    'trip' => [
        'arrDate' => '2026-10-02', 'depDate' => '2026-10-06',
        'adults' => 2, 'children' => 1, 'infants' => 0,
        'arrMode' => 'flight', 'flightNum' => 'KQ100', 'airport' => 'MBA',
        'arrTime' => '14:30', 'fromCity' => 'Milan',
        'transfer' => 'yes', 'prop' => 'zuri', 'purpose' => 'Family holiday',
    ],
    'departure' => [
        'flight' => 'KQ101', 'airport' => 'MBA', 'time' => '16:00',
        'transfer' => 'yes', 'notes' => 'Late checkout if possible',
    ],
    'special' => [
        'occasions' => ['Birthday'], 'diet' => ['Vegetarian', 'No pork'],
        'mobility' => [], 'pace' => 'balanced', 'extraNotes' => 'Ground floor room',
    ],
    'itinerary' => [
        ['dayIdx' => 0, 'date' => '2026-10-02', 'label' => 'Day 1 · Fri 2 Oct',
         'slots' => [['name' => 'Sunset Dhow Cruise', 'time' => '17:00']]],
        ['dayIdx' => 1, 'date' => '2026-10-03', 'label' => 'Day 2 · Sat 3 Oct', 'slots' => []],
        ['dayIdx' => 2, 'date' => '2026-10-04', 'label' => 'Day 3 · Sun 4 Oct',
         'slots' => [['name' => 'Snorkelling · Watamu Marine Park', 'time' => ''],
                     ['name' => 'Private Beach Dinner', 'time' => '19:30']]],
    ],
    'cf-turnstile-response' => '1.zEhghBCd-Hnfucoo-8ftplXM1CS5MUyNyrhK8oKIppWDNvvjD9nyGbCHcrBEL4I199vu',
];
$flat = ['agency_name' => 'Acme Travel', 'iata' => '12345678',
         'country' => 'Italy', 'submitted_from' => 'https://tribalsand.com/agents.php'];

// ── Detection ───────────────────────────────────────────────────────────────
check('detects a trip-builder payload',        submission_is_trip_builder($tb));
check('flat agency payload is not trip-builder', !submission_is_trip_builder($flat));
check('empty payload is not trip-builder',     !submission_is_trip_builder([]));

// ── The reported bug: nested values must never reach a string cast ──────────
check('nested payload renders without warnings', quiet(fn() => submission_payload_rows($tb)));
$rows = submission_payload_rows($tb);
$vals = array_column($rows, 1);
check('no row renders as the literal "Array"', !in_array('Array', $vals, true));

// ── Noise / secrets are filtered out of the admin UI ────────────────────────
$labels = array_map('strtolower', array_column($rows, 0));
check('turnstile token is not displayed',
      !array_filter($labels, fn($l) => str_contains($l, 'turnstile')));
check('honeypot field is not displayed',
      !in_array('website', $labels, true));

// ── Flat payloads keep working exactly as before ────────────────────────────
$frows = submission_payload_rows($flat);
$fmap  = [];
foreach ($frows as [$l, $v]) { $fmap[$l] = $v; }
check('flat: label is humanised',      isset($fmap['Agency Name']));
check('flat: value preserved',         ($fmap['Agency Name'] ?? '') === 'Acme Travel');
check('flat: all four keys render',    count($frows) === 4);

// ── Trip Builder gets a real, readable layout ───────────────────────────────
check('sections render without warnings', quiet(fn() => trip_builder_sections($tb)));
$secs = trip_builder_sections($tb);
$byTitle = [];
foreach ($secs as $s) {
    $m = [];
    foreach ($s['rows'] as [$l, $v]) { $m[$l] = $v; }
    $byTitle[$s['title']] = $m;
}
check('has a Guest section',     isset($byTitle['Guest']));
check('has a Stay section',      isset($byTitle['Stay']));
check('has an Arrival section',  isset($byTitle['Arrival']));
check('has a Departure section', isset($byTitle['Departure']));
check('has a Special section',   isset($byTitle['Special Touches']));

check('guest name joined',       ($byTitle['Guest']['Name'] ?? '') === 'Patrik Giuliana');
check('guest email shown',       ($byTitle['Guest']['Email'] ?? '') === 'p@example.com');
check('nationality shown',       ($byTitle['Guest']['Nationality'] ?? '') === 'Italian');
check('property slug humanised', str_contains($byTitle['Stay']['Property'] ?? '', 'Zuri'));
check('arrival date formatted',  str_contains($byTitle['Stay']['Arrival'] ?? '', '2026'));
check('nights computed',         str_contains($byTitle['Stay']['Duration'] ?? '', '4 night'));
check('party string built',      str_contains($byTitle['Stay']['Party'] ?? '', '2 adult'));
check('arrival mode humanised',  ($byTitle['Arrival']['Arriving By'] ?? '') === 'International Flight');
check('flight number shown',     ($byTitle['Arrival']['Flight'] ?? '') === 'KQ100');
check('transfer humanised',      ($byTitle['Arrival']['Transfer'] ?? '') === 'Arranged by Tribal Sand');
check('departure notes shown',   ($byTitle['Departure']['Final Requests'] ?? '') === 'Late checkout if possible');
check('diet list flattened',     ($byTitle['Special Touches']['Dietary'] ?? '') === 'Vegetarian, No pork');
check('occasions flattened',     ($byTitle['Special Touches']['Occasions'] ?? '') === 'Birthday');
check('empty mobility omitted',  !isset($byTitle['Special Touches']['Accessibility']));

// ── Itinerary ───────────────────────────────────────────────────────────────
check('itinerary renders without warnings', quiet(fn() => trip_builder_itinerary($tb)));
$itin = trip_builder_itinerary($tb);
check('empty days are dropped',   count($itin) === 2);
check('day label preserved',      $itin[0]['label'] === 'Day 1 · Fri 2 Oct');
check('slot name preserved',      $itin[0]['items'][0] === 'Sunset Dhow Cruise · 17:00');
check('slot without time has no separator', $itin[1]['items'][0] === 'Snorkelling · Watamu Marine Park');
check('second day keeps both slots', count($itin[1]['items']) === 2);

// ── Defensive: malformed / hostile payloads must not crash or warn ──────────
check('deeply nested value is safe', quiet(fn() => submission_payload_rows(
    ['a' => ['b' => ['c' => ['d' => 'deep']]]])));
check('object-ish itinerary is safe', quiet(fn() => trip_builder_itinerary(
    ['itinerary' => 'not-an-array'])));
check('missing sections are safe',    quiet(fn() => trip_builder_sections(['trip' => []])));
check('null values are safe',         quiet(fn() => submission_payload_rows(['x' => null])));

restore_error_handler();
echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
