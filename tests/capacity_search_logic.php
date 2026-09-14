<?php
declare(strict_types=1);
/**
 * Capacity-aware search — combination ranking + per-venue configurations.
 * Run: php tests/capacity_search_logic.php
 *
 * Pure ts_rank_combos() logic is exercised without a DB. The DB-backed
 * ts_property_configurations() asserts run read-only against a far-future
 * window (so every unit is free); they SKIP when no DB / no seed is present.
 * Nothing is written, so there is nothing to roll back.
 *
 * Acceptance bar (the ONE pricing path): a combo's total always equals the sum
 * of room_stay_quote() per room × units — the same figure the booking widget
 * quotes for those rooms/dates.
 */
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure combination ranking (no DB) ─────────────────────────────────────────
$inv = fn(string $slug, int $cap, int $free, float $price, string $cur = 'USD') =>
    ['slug' => $slug, 'name' => ucfirst($slug), 'capacity' => $cap, 'free' => $free, 'unit_total' => $price, 'currency' => $cur];

// Fewest units wins: one 4-cap beats two 2-caps for a party of 4.
$c = ts_rank_combos([$inv('big', 4, 1, 400), $inv('sm', 2, 2, 150)], 4);
check('rank: a fitting single unit is the top combo', !empty($c) && $c[0]['units'] === 1 && $c[0]['rooms'][0]['slug'] === 'big');

// Least waste breaks a units tie, even against a cheaper option.
$c = ts_rank_combos([$inv('six', 6, 1, 200), $inv('eight', 8, 1, 100)], 5);
check('rank: least waste beats cheaper at equal units', !empty($c) && $c[0]['rooms'][0]['slug'] === 'six' && $c[0]['waste'] === 1);

// Price breaks a units+waste tie.
$c = ts_rank_combos([$inv('pricey', 4, 1, 300), $inv('cheap', 4, 1, 200)], 4);
check('rank: cheapest breaks the final tie', !empty($c) && $c[0]['rooms'][0]['slug'] === 'cheap');

// Multi-unit of one room type, plus the classic 6+2 for 7.
$c = ts_rank_combos([$inv('villa', 6, 2, 600), $inv('studio', 2, 4, 200)], 7);
check('rank: 6+2 combo for a party of 7', !empty($c) && $c[0]['units'] === 2 && $c[0]['capacity'] === 8 && $c[0]['waste'] === 1);
$slugsTop = array_map(fn($r) => $r['slug'], $c[0]['rooms']);
check('rank: top 7-combo is villa+studio', in_array('villa', $slugsTop, true) && in_array('studio', $slugsTop, true));
check('rank: combo total = sum of unit quotes', abs($c[0]['total'] - (600 + 200)) < 0.001);

// Feasibility: even all beds together fall short → no combo.
check('rank: infeasible party → no combo', ts_rank_combos([$inv('a', 2, 1, 100), $inv('b', 2, 1, 100)], 40) === []);
check('rank: empty inventory → no combo',   ts_rank_combos([], 4) === []);

// Currencies are never summed inside one combo.
$c = ts_rank_combos([$inv('usd', 4, 1, 400, 'USD'), $inv('kes', 2, 1, 200, 'KES')], 5);
check('rank: mixed-currency combo is dropped', $c === []);

// A same-currency combo of those two still works when the party fits one currency's beds.
$c = ts_rank_combos([$inv('a', 4, 1, 400, 'USD'), $inv('b', 4, 1, 200, 'USD')], 7);
check('rank: same-currency 4+4 for 7 sums both', !empty($c) && $c[0]['capacity'] === 8 && abs($c[0]['total'] - 600) < 0.001);

// ── DB-backed configurations (read-only, far-future window = all free) ────────
try {
    db_query('SELECT 1')->fetchColumn();
} catch (\Throwable $e) {
    echo "\nSKIP  DB configurations (database unavailable: " . $e->getMessage() . ")\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}

$ci = '2099-03-10';
$co = '2099-03-13';   // 3 nights, far future → nothing booked

/** Sum room_stay_quote() over a combo's rooms — the canonical parity figure. */
$comboCanonTotal = function(array $combo) use ($ci, $co): float {
    $sum = 0.0;
    foreach ($combo['rooms'] as $r) {
        $room = fetch_room_by_slug($r['slug']);
        if (!$room) return -1.0;
        $q = room_stay_quote((int)$room['id'], (float)$room['price_amount'], $ci, $co);
        $sum += $q['total'] * (int)$r['units_used'];
    }
    return round($sum, 2);
};

$venueBySlug = function(string $slug) {
    return db_query('SELECT * FROM venues WHERE slug = :s AND is_published = TRUE', [':s' => $slug])->fetch();
};

// Regression: a party a single room fits still returns singles, and NO combo.
$mk = $venueBySlug('maya-kobe');
if ($mk) {
    $c2 = ts_property_configurations($mk, $ci, $co, 2);
    check('maya-kobe/2: has fitting single rooms', !empty($c2['singles']));
    check('maya-kobe/2: no combo when a single fits', $c2['combos'] === []);

    $c4 = ts_property_configurations($mk, $ci, $co, 4);
    check('maya-kobe/4: prestige is a fitting single', (bool)array_filter($c4['singles'], fn($s) => $s['slug'] === 'maya-kobe-prestige'));
    check('maya-kobe/4: no combo when a single fits', $c4['combos'] === []);

    // The headline fix: 7 guests → a combination summing >= 7.
    $c7 = ts_property_configurations($mk, $ci, $co, 7);
    check('maya-kobe/7: no single fits', $c7['singles'] === []);
    check('maya-kobe/7: a combo is offered', !empty($c7['combos']));
    if (!empty($c7['combos'])) {
        $top = $c7['combos'][0];
        check('maya-kobe/7: combo capacity >= 7', $top['capacity'] >= 7);
        check('maya-kobe/7: combo total = sum of per-room quotes', abs($top['total'] - $comboCanonTotal($top)) < 0.01);
    }
} else {
    echo "SKIP  maya-kobe not seeded\n";
}

// Zuri: 7 guests → a combo CHEAPER than the whole-villa buyout, buyout still offered.
$zuri = $venueBySlug('zuri');
if ($zuri) {
    $z7 = ts_property_configurations($zuri, $ci, $co, 7);
    check('zuri/7: whole-villa (buyout) still offered', !empty($z7['entire']));
    check('zuri/7: a combo is offered', !empty($z7['combos']));
    if (!empty($z7['combos']) && !empty($z7['entire'])) {
        $combo = $z7['combos'][0];
        check('zuri/7: combo is cheaper than the buyout', $combo['total'] < $z7['entire'][0]['total']);
        check('zuri/7: combo total = sum of per-room quotes', abs($combo['total'] - $comboCanonTotal($combo)) < 0.01);
    }
} else {
    echo "SKIP  zuri not seeded\n";
}

// Maya Ilai: multi-unit room types → family(6)+junior/studio(2) for 7.
$ilai = $venueBySlug('maya_ilai');
if ($ilai) {
    $i7 = ts_property_configurations($ilai, $ci, $co, 7);
    check('maya_ilai/7: no single fits', $i7['singles'] === []);
    check('maya_ilai/7: a combo is offered', !empty($i7['combos']));
    if (!empty($i7['combos'])) {
        $top = $i7['combos'][0];
        check('maya_ilai/7: combo capacity >= 7', $top['capacity'] >= 7);
        check('maya_ilai/7: combo total = sum of per-room quotes', abs($top['total'] - $comboCanonTotal($top)) < 0.01);
    }
    // Infeasible: a party larger than every bed combined → no combo.
    $iBig = ts_property_configurations($ilai, $ci, $co, 9999);
    check('maya_ilai/9999: infeasible → no combo', $iBig['combos'] === []);
} else {
    echo "SKIP  maya_ilai not seeded\n";
}

// Entire-place-only venue never produces individual-room combos.
$amani = $venueBySlug('my-amani');
if ($amani) {
    $a = ts_property_configurations($amani, $ci, $co, 7);
    check('my-amani/7: whole place offered', !empty($a['entire']));
    check('my-amani/7: no combos (entire-only venue)', $a['combos'] === []);
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
