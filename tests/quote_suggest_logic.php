<?php
declare(strict_types=1);
// Quote builder "Suggest options" — guest split, per-property options, ordering.
// Run: php tests/quote_suggest_logic.php
// Pure logic always; the DB round-trip is read-only and SKIPs with no database.
require_once __DIR__ . '/../includes/quote-suggest.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Guest split ──
check('split: fills rows in order', qb_suggest_split_guests([['units' => 1, 'capacity' => 4], ['units' => 1, 'capacity' => 2]], 5) === [4, 1]);
check('split: units multiply capacity', qb_suggest_split_guests([['units' => 2, 'capacity' => 2]], 3) === [3]);
check('split: spare room gets 0', qb_suggest_split_guests([['units' => 1, 'capacity' => 4], ['units' => 1, 'capacity' => 2]], 3) === [3, 0]);
check('split: overflow stays on the last row', qb_suggest_split_guests([['units' => 1, 'capacity' => 2]], 3) === [3]);

// ── Options for one property ──
$ids = ['a' => 11, 'b' => 12, 'c' => 13, 'big' => 14, 'whole' => 15];
$single = fn(string $slug, int $cap, float $total, string $name = '') =>
    ['slug' => $slug, 'name' => $name ?: ucfirst($slug), 'capacity' => $cap, 'units_used' => 1, 'total' => $total, 'currency' => 'KES'];
$cfg = [
    'singles' => [$single('c', 2, 300), $single('a', 2, 100), $single('b', 3, 200)],
    'entire'  => [$single('whole', 12, 5000, 'Whole villa')],
    'combos'  => [['rooms' => [
                     ['slug' => 'a', 'name' => 'A', 'units_used' => 1, 'capacity' => 2, 'total' => 100, 'currency' => 'KES'],
                     ['slug' => 'b', 'name' => 'B', 'units_used' => 2, 'capacity' => 3, 'total' => 400, 'currency' => 'KES']],
                   'units' => 3, 'total' => 500, 'currency' => 'KES', 'capacity' => 8, 'waste' => 6]],
    'max_capacity' => 12,
];
$o = qb_suggest_venue_options($cfg, $ids, 2, 3);
check('options: limit 3', count($o) === 3);
check('options: two cheapest singles first', $o[0]['kind'] === 'single' && $o[0]['rooms'] === [['id' => 11, 'qty' => 1, 'guests' => 2]]
    && $o[1]['rooms'][0]['id'] === 12 && $o[0]['total'] === 100.0);
check('options: then the combo', $o[2]['kind'] === 'combo' && $o[2]['sleeps'] === 8 && $o[2]['total'] === 500.0
    && $o[2]['rooms'] === [['id' => 11, 'qty' => 1, 'guests' => 2], ['id' => 12, 'qty' => 2, 'guests' => 0]]);
check('options: combo label names every room', $o[2]['label'] === 'A + B ×2');
$o4 = qb_suggest_venue_options($cfg, $ids, 2, 4);
check('options: whole property last', $o4[3]['kind'] === 'entire' && $o4[3]['rooms'] === [['id' => 15, 'qty' => 1, 'guests' => 2]] && $o4[3]['sleeps'] === 12);
$noSingles = qb_suggest_venue_options(['singles' => [], 'entire' => $cfg['entire'], 'combos' => $cfg['combos']], $ids, 7, 3);
check('options: big party → combo then whole property', array_column($noSingles, 'kind') === ['combo', 'entire']);
check('options: combo guests cover the party', array_sum(array_column($noSingles[0]['rooms'], 'guests')) === 7);
$unpriced = qb_suggest_venue_options(['singles' => [$single('a', 2, 0), $single('b', 2, 150)], 'entire' => [], 'combos' => []], $ids, 2, 3);
check('options: unpriced single → total null, listed after priced', $unpriced[0]['rooms'][0]['id'] === 12 && $unpriced[1]['total'] === null);
$scoped = qb_suggest_venue_options(['singles' => [$single('zzz', 2, 50), $single('a', 2, 100)], 'entire' => [], 'combos' => []], $ids, 2, 3);
check('options: rooms outside the catalogue are dropped', count($scoped) === 1 && $scoped[0]['rooms'][0]['id'] === 11);
$badCombo = qb_suggest_venue_options(['singles' => [], 'entire' => [], 'combos' => [['rooms' => [
    ['slug' => 'a', 'name' => 'A', 'units_used' => 1, 'capacity' => 2, 'total' => 1, 'currency' => 'KES'],
    ['slug' => 'zzz', 'name' => 'Z', 'units_used' => 1, 'capacity' => 2, 'total' => 1, 'currency' => 'KES']],
    'units' => 2, 'total' => 2, 'currency' => 'KES', 'capacity' => 4, 'waste' => 0]]], $ids, 4, 3);
check('options: a combo with an unknown room is dropped whole', $badCombo === []);
check('options: nothing free → empty', qb_suggest_venue_options(['singles' => [], 'entire' => [], 'combos' => []], $ids, 2) === []);

// ── Ordering ──
$g = fn(int $id, string $name, string $loc, ?float $total, string $cur = 'KES') => [
    'venue_id' => $id, 'name' => $name, 'location' => $loc,
    'options' => $total === null ? [] : [['kind' => 'single', 'total' => $total, 'currency' => $cur]],
];
$groups = [$g(1, 'Empty', 'Watamu', null), $g(2, 'Kilifi Cheap', 'Kilifi', 50), $g(3, 'Watamu Dear', 'Watamu, Kenya', 900),
           $g(4, 'Requested', 'Watamu', 700), $g(5, 'Usd', 'Vipingo', 1, 'USD')];
$toKes = fn(float $a, string $c): float => $c === 'USD' ? $a * 129 : $a;
$ord = array_column(qb_suggest_order($groups, 4, $toKes), 'venue_id');
check('order: requested, same town, cheapest (one currency), empty last', $ord === [4, 3, 2, 5, 1]);
$ord2 = array_column(qb_suggest_order($groups, null, $toKes), 'venue_id');
check('order: no request → cheapest first, empty last', $ord2 === [2, 5, 4, 3, 1]);
check('order: preferred flag set', qb_suggest_order($groups, 4, $toKes)[0]['preferred'] === true);

// ── DB round-trip (read-only) ──
try { require_once __DIR__ . '/../includes/quote-builder.php'; db(); $hasDb = true; } catch (Throwable $e) { $hasDb = false; }
if ($hasDb) {
    $ci = '2098-06-10'; $co = '2098-06-13';
    $sug = qb_suggestions($ci, $co, 2, null, null);
    $catIds = array_map(fn($r) => (int)$r['id'], qb_catalog(null)['rooms']);
    $allIds = [];
    foreach ($sug as $grp) foreach ($grp['options'] as $opt) foreach ($opt['rooms'] as $rm) $allIds[] = $rm['id'];
    check('db: suggestions returned', count($sug) > 0);
    check('db: every suggested room is in the catalogue', !array_diff($allIds, $catIds));
    $first = null;
    foreach ($sug as $grp) foreach ($grp['options'] as $opt) if (!$first && $opt['kind'] === 'single' && $opt['total'] !== null) $first = $opt;
    if ($first) {
        $rid = $first['rooms'][0]['id'];
        $p = qb_price_selection(['check_in' => $ci, 'check_out' => $co, 'adults' => 2, 'cur' => $first['currency'],
                                 'rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2]]], null);
        $line = null;
        foreach ($p['rooms'] as $r) if ($r['id'] === $rid) $line = $r['line'];
        check('db: a suggestion costs what the builder charges', $line && abs($line['amt'] - $first['total']) < 0.01 && $line['cur'] === $first['currency']);
    } else {
        echo "SKIP  db: price parity (no priced single in this database)\n";
    }
    check('db: scoped to nothing → no suggestions', qb_suggestions($ci, $co, 2, [], null) === []);
} else {
    echo "SKIP  db round-trip (no database)\n";
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
