<?php
declare(strict_types=1);
// Listing page alternatives — ranking, labels, window validation.
// Run: php tests/listing_alternatives_logic.php
require_once __DIR__ . '/../includes/listing-alternatives.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

check('type label hotel', ts_venue_type_label('zuri') === 'Boutique Hotel');
check('type label villa', ts_venue_type_label('my-amani') === 'Private Villa');
check('type label unknown', ts_venue_type_label('nowhere') === '');
check('town key', ts_venue_town('Watamu · Kenya') === 'watamu' && ts_venue_town('Kilifi, Kenya') === 'kilifi');
check('town key empty', ts_venue_town('') === '');

$today = '2026-09-30';
check('window ok',           ts_alternatives_window('2026-10-15', '2026-10-18', $today) === ['2026-10-15', '2026-10-18', null]);
check('window repairs',      ts_alternatives_window('2026-10-5', '2026-10-8', $today)[0] === '2026-10-05');
check('window bad date',     ts_alternatives_window('nope', '2026-10-18', $today)[2] !== null);
check('window order',        ts_alternatives_window('2026-10-18', '2026-10-15', $today)[2] !== null);
check('window past',         ts_alternatives_window('2026-09-29', '2026-10-02', $today)[2] !== null);
check('window today ok',     ts_alternatives_window('2026-09-30', '2026-10-02', $today)[2] === null);
check('window 60 nights ok', ts_alternatives_window('2026-10-01', '2026-11-30', $today)[2] === null);
check('window 61 refused',   ts_alternatives_window('2026-10-01', '2026-12-01', $today)[2] !== null);

$r = fn(string $slug, string $loc, int $count, ?float $from, string $cur = 'KES') => [
    'venue' => ['slug' => $slug, 'name' => ucfirst($slug), 'location' => $loc],
    'hero' => "/img/$slug.jpg", 'count' => $count, 'from' => $from, 'currency' => $cur,
];
$results = [
    $r('maya-kobe', 'Watamu', 0, null),          // the page we're on (full)
    $r('my-amani',  'Kilifi', 1, 50000),
    $r('zuri',      'Watamu', 3, 124050),
    $r('sandbox',   'Watamu', 0, null),          // full elsewhere
    $r('enkare-bofa', 'Kilifi', 1, 40000),
    $r('maya_ilai', 'Watamu', 2, 200000),
];
$alt = ts_alternative_properties($results, 'maya-kobe', 3);
$slugs = array_column($alt['options'], 'slug');
check('excludes current venue', !in_array('maya-kobe', $slugs, true));
check('excludes full venues',   !in_array('sandbox', $slugs, true));
check('same town first, then cheapest', $slugs === ['zuri', 'maya_ilai', 'enkare-bofa']);
check('more counts the rest',   $alt['more'] === 1);
check('option shape', ($alt['options'][0]['name'] ?? '') === 'Zuri' && $alt['options'][0]['type'] === 'Boutique Hotel'
    && $alt['options'][0]['location'] === 'Watamu' && $alt['options'][0]['from'] === 124050.0 && $alt['options'][0]['currency'] === 'KES'
    && $alt['options'][0]['hero'] === '/img/zuri.jpg');
$alt2 = ts_alternative_properties($results, 'unknown-slug', 10);
check('unknown current venue: cheapest first', array_column($alt2['options'], 'slug') === ['enkare-bofa', 'my-amani', 'zuri', 'maya_ilai'] && $alt2['more'] === 0);
$alt3 = ts_alternative_properties([$r('a', 'X', 1, null), $r('b', 'X', 1, 10)], 'none', 3);
check('unpriced sorts last', array_column($alt3['options'], 'slug') === ['b', 'a'] && $alt3['options'][1]['from'] === null);
check('nothing free → empty', ts_alternative_properties([$r('a', 'X', 0, null)], 'none')['options'] === []);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
