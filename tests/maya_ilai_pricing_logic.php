<?php
declare(strict_types=1);
// Maya Ilai quote tool — combination products, guest splitting, supplements,
// discounts, the eco fee and the living-room invariant.
// Run: php tests/maya_ilai_pricing_logic.php
//
// Everything here is READ-ONLY: the pure assertions price against
// maya_ilai_pricing_defaults() (no DB at all), and the live-config section only
// reads the saved settings blob. Nothing is ever written, so there is nothing to
// roll back.
require_once __DIR__ . '/../includes/maya-ilai-pricing.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
/** Money comparison — quote() rounds to 2dp, so compare with a cent of slack. */
function eq(float $a, float $b): bool { return abs($a - $b) < 0.005; }

$D = maya_ilai_pricing_defaults();

/** Quote a named combination at the shipped defaults. */
function comboQuote(string $key, array $cfg, array $over = []): array {
    $pick = ['qty' => $over['qty'] ?? 1];
    if (isset($over['guests'])) $pick['guests'] = $over['guests'];
    $sel = maya_ilai_expand_combos([
        'combos'  => [$key => $pick],
        'nights'  => $over['nights'] ?? 1,
        'season'  => $over['season'] ?? 'high',
        'program' => $over['program'] ?? 'group',
    ] + ($over['extra'] ?? []), $cfg);
    return maya_ilai_quote($sel, $cfg);
}

// ── The shipped combination table ───────────────────────────────────────────
// Each combination must price EXACTLY as the sum of its parts. This is the fact
// the whole feature rests on: it is why a combination can be offered by name and
// then expanded into primitives without teaching quote() a second rate.
$table = [
    'One-Bedroom Suite'        => 750.0,
    'Two-Bedroom Family Room'  => 500.0,
    'Two-Bedroom Family Suite' => 900.0,
    'Two-Bedroom Suite'        => 1100.0,
];
foreach ($table as $key => $high) {
    check("combo '{$key}' prices {$high} at High season",
        eq(comboQuote($key, $D)['base'], $high));
    // Standard is High × 0.8 (standardReduction = 20%).
    check("combo '{$key}' prices " . ($high * 0.8) . " at Standard season",
        eq(comboQuote($key, $D, ['season' => 'standard'])['base'], $high * 0.8));
}

// The four products are subsets of a whole villa, so each must be cheaper than
// one — otherwise the guest pays more for strictly less.
foreach ($table as $key => $high) {
    check("combo '{$key}' is cheaper than a whole villa", $high < (float)$D['rates']['villa']);
}

// ── The whole villa is NOT a combination ────────────────────────────────────
// Its parts sum to 1250; it sells for 1170. That $80 whole-villa saving is why
// it stays a primitive with its own rate. If this ever flips to 1250 the villa
// has been quietly turned into the sum of its parts.
$villaParts = maya_ilai_combo_rate($D, ['double' => 2, 'bunk' => 1, 'living' => 1]);
check('regression: whole villa still prices 1170',
    eq(maya_ilai_quote(['qtyVilla' => 1, 'guestVilla' => 7, 'nights' => 1, 'program' => 'none'], $D)['base'], 1170.0));
check('regression: whole villa is NOT the 1250 sum of its parts', eq($villaParts, 1250.0) && !eq($villaParts, 1170.0));
check('whole villa is not offered as a combination',
    !in_array('Three-Bedroom Villa', array_column(maya_ilai_combos(), 'key'), true));

// ── Occupancy decomposes into the parts' own rules ──────────────────────────
$occExpect = [
    'One-Bedroom Suite'        => ['min' => 1, 'included' => 2, 'max' => 2],
    'Two-Bedroom Family Room'  => ['min' => 2, 'included' => 5, 'max' => 8],
    'Two-Bedroom Family Suite' => ['min' => 2, 'included' => 5, 'max' => 8],
    'Two-Bedroom Suite'        => ['min' => 2, 'included' => 4, 'max' => 4],
];
foreach (maya_ilai_combos() as $c) {
    check("occupancy of '{$c['key']}' derives from its parts",
        maya_ilai_combo_occupancy($D, $c['parts']) === $occExpect[$c['key']]);
}

// ── Guest splitting drives the bunk supplement ──────────────────────────────
// The tool charges max(0, guestBunk - bunk*bunkIncluded) * bunkExtra, so the
// split is what decides the supplement. bunkIncluded 3, bunkExtra 45.
$fr = 'Two-Bedroom Family Room';
check('split: 6 guests → 2 in the double, 4 in the bunk',
    comboQuote($fr, $D, ['guests' => 6])['g']['double'] === 2 && comboQuote($fr, $D, ['guests' => 6])['g']['bunk'] === 4);
check('split: 6 guests supplements (4-3) × 45 = 45',
    eq(comboQuote($fr, $D, ['guests' => 6])['supplements'], 45.0));
check('split: 5 guests (the included figure) supplements nothing',
    eq(comboQuote($fr, $D, ['guests' => 5])['supplements'], 0.0));
check('split: 8 guests (the max) supplements (6-3) × 45 = 135',
    eq(comboQuote($fr, $D, ['guests' => 8])['supplements'], 135.0));
check('split: never leaves a selected bunk room empty',
    comboQuote($fr, $D, ['guests' => 2])['g']['bunk'] >= 1);
foreach ([2, 3, 4, 5, 6, 7, 8] as $g) {
    check("split: {$g}-guest Family Room passes the tool's own room validation",
        comboQuote($fr, $D, ['guests' => $g])['errors'] === []);
}
check('split: guests are clamped to the combination max',
    comboQuote($fr, $D, ['guests' => 99])['guests'] === 8);
check('split: guests are floored at one per bedroom',
    comboQuote($fr, $D, ['guests' => 0])['guests'] >= 2);
check('split: pooled across a quantity of 2 (2 doubles, 2 bunks)',
    comboQuote($fr, $D, ['qty' => 2, 'guests' => 10])['g'] === ['double' => 4, 'bunk' => 6, 'studio' => 0, 'villa' => 0]);

// ── ONE pricing path ────────────────────────────────────────────────────────
// A combination and the same primitives selected by hand must produce the
// identical total. If this ever fails, a second summation has appeared.
$pairs = [
    ['One-Bedroom Suite',        6, ['qtyDouble'=>1,'qtyLiving'=>1,'guestDouble'=>2]],
    ['Two-Bedroom Family Room',  6, ['qtyDouble'=>1,'qtyBunk'=>1,'guestDouble'=>2,'guestBunk'=>4]],
    ['Two-Bedroom Family Suite', 6, ['qtyDouble'=>1,'qtyBunk'=>1,'qtyLiving'=>1,'guestDouble'=>2,'guestBunk'=>4]],
    ['Two-Bedroom Suite',        4, ['qtyDouble'=>2,'qtyLiving'=>1,'guestDouble'=>4]],
];
foreach ($pairs as [$key, $guests, $hand]) {
    $combo  = comboQuote($key, $D, ['guests' => $guests, 'nights' => 3]);
    $manual = maya_ilai_quote($hand + ['nights' => 3, 'season' => 'high', 'program' => 'group'], $D);
    check("one path: '{$key}' totals the same as the hand-assembled primitives",
        eq($combo['total'], $manual['total']) && $combo['errors'] === [] && $manual['errors'] === []);
    check("one path: '{$key}' matches the hand-assembled breakdown line for line",
        eq($combo['base'], $manual['base']) && eq($combo['supplements'], $manual['supplements'])
        && eq($combo['nightly'], $manual['nightly']) && eq($combo['eco'], $manual['eco']));
}

// The displayed combination price and the priced expansion are the same number,
// resolved from the same rates — they cannot drift.
foreach (maya_ilai_combos() as $c) {
    $occ = maya_ilai_combo_occupancy($D, $c['parts']);
    check("displayed price of '{$c['key']}' equals the quoted base of its expansion",
        eq(maya_ilai_combo_rate($D, $c['parts']), comboQuote($c['key'], $D, ['guests' => $occ['included']])['base']));
}

// ── Living-room invariant ───────────────────────────────────────────────────
// A villa has ONE living room, and you cannot rent one in a villa you have no
// bedroom in: livings <= max(ceil(doubles / doublePerVilla), bunks).
$hasLivingErr = function (array $sel) use ($D): bool {
    foreach (maya_ilai_quote($sel + ['nights' => 1, 'program' => 'none'], $D)['errors'] as $e) {
        if (str_contains($e, 'living room comes with a villa bedroom')) return true;
    }
    return false;
};
check('living: allowance from 2 loose doubles (doublePerVilla 2) is 1', maya_ilai_living_allowance($D, 2, 0) === 1);
check('living: allowance from 3 loose doubles is 2',              maya_ilai_living_allowance($D, 3, 0) === 2);
check('living: each loose bunk room is its own villa',            maya_ilai_living_allowance($D, 0, 3) === 3);
check('living: loose doubles and bunks share a villa, not stack', maya_ilai_living_allowance($D, 2, 1) === 1);
check('living: no bedrooms, no allowance',                        maya_ilai_living_allowance($D, 0, 0) === 0);
// Each combination unit is a villa of its own and lends one allowance.
check('living: each combination unit lends one allowance',        maya_ilai_living_allowance($D, 0, 0, 2) === 2);
check('living: combination units add to the loose packing',       maya_ilai_living_allowance($D, 2, 0, 2) === 3);
check('living: combination units default to none',                maya_ilai_living_allowance($D, 2, 0) === maya_ilai_living_allowance($D, 2, 0, 0));

check('living: valid at the boundary (2 doubles, 1 living)',
    !$hasLivingErr(['qtyDouble' => 2, 'guestDouble' => 4, 'qtyLiving' => 1]));
check('living: rejected one past the boundary (2 doubles, 2 livings)',
    $hasLivingErr(['qtyDouble' => 2, 'guestDouble' => 4, 'qtyLiving' => 2]));
check('living: rejected outright with no bedrooms (0 bedrooms, 1 living)',
    $hasLivingErr(['qtyStudio' => 1, 'guestStudio' => 2, 'qtyLiving' => 1]));
check('living: valid at the boundary across 3 villas (3 doubles + 1 bunk, 2 livings)',
    !$hasLivingErr(['qtyDouble' => 3, 'guestDouble' => 6, 'qtyBunk' => 1, 'guestBunk' => 2, 'qtyLiving' => 2]));

// Whole villas already include their living room and lend no allowance.
check('living: a whole villa does not entitle a separate living room',
    $hasLivingErr(['qtyVilla' => 1, 'guestVilla' => 7, 'qtyLiving' => 1]));
check('living: a whole villa alone is still fine',
    !$hasLivingErr(['qtyVilla' => 1, 'guestVilla' => 7]));
check('living: villa + a double bedroom entitles exactly one',
    !$hasLivingErr(['qtyVilla' => 1, 'guestVilla' => 7, 'qtyDouble' => 1, 'guestDouble' => 2, 'qtyLiving' => 1])
    && $hasLivingErr(['qtyVilla' => 1, 'guestVilla' => 7, 'qtyDouble' => 1, 'guestDouble' => 2, 'qtyLiving' => 2]));

// The invariant must not ride on the inventory check succeeding — that one asks
// "do we have enough villas", this asks "is the living room attached to one".
$stray = maya_ilai_quote(['qtyLiving' => 1, 'qtyStudio' => 1, 'guestStudio' => 2, 'nights' => 1, 'program' => 'none'], $D);
check('living: a stray living room is rejected even though inventory is fine',
    $hasLivingErr(['qtyLiving' => 1, 'qtyStudio' => 1, 'guestStudio' => 2])
    && !in_array("Needs 1 villas; only {$D['inventory']['villas']} available.", $stray['errors'], true));

// Every offered combination satisfies its invariant at quantity 1 AND 2 — two
// combination units are two villas, so they carry two living rooms.
foreach (maya_ilai_combos() as $c) {
    check("living: combination '{$c['key']}' satisfies the invariant",
        comboQuote($c['key'], $D)['errors'] === []);
    check("living: 2× '{$c['key']}' satisfies the invariant",
        comboQuote($c['key'], $D, ['qty' => 2])['errors'] === []);
}

// ── The allowance table, exactly as the owner specified it ──────────────────
// The load-bearing pair is rows 1 and 3: 2× One-Bedroom Suite and 2 loose
// doubles + 2 livings have IDENTICAL primitive totals (2 doubles, 2 livings) and
// the same price, yet one is permitted and one is not. The allowance therefore
// cannot be derived from the expanded totals — the combination context has to
// travel with the selection.
$twoSuites = maya_ilai_expand_combos(['combos' => ['One-Bedroom Suite' => ['qty' => 2]]], $D);
$twoSuitesPlusLoose = $twoSuites;
$twoSuitesPlusLoose['qtyLiving'] = (int)$twoSuitesPlusLoose['qtyLiving'] + 1;

$allowanceTable = [
    ['2× One-Bedroom Suite',                  $twoSuites,                                                             2, false],
    ['1× Two-Bedroom Suite',                  maya_ilai_expand_combos(['combos' => ['Two-Bedroom Suite' => ['qty' => 1]]], $D), 1, false],
    ['2 loose doubles + 2 livings',           ['qtyDouble' => 2, 'guestDouble' => 4, 'qtyLiving' => 2],                1, true],
    ['2 loose doubles + 1 living',            ['qtyDouble' => 2, 'guestDouble' => 4, 'qtyLiving' => 1],                1, false],
    ['Studio only + 1 living',                ['qtyStudio' => 1, 'guestStudio' => 2, 'qtyLiving' => 1],                0, true],
    ['Whole villa + 1 living',                ['qtyVilla' => 1, 'guestVilla' => 7, 'qtyLiving' => 1],                  0, true],
    ['2× One-Bedroom Suite + 1 loose living', $twoSuitesPlusLoose,                                                     2, true],
];
foreach ($allowanceTable as [$label, $sel, $allowed, $shouldReject]) {
    $q = maya_ilai_quote($sel + ['nights' => 1, 'program' => 'none'], $D);
    $rejected = false;
    foreach ($q['errors'] as $e) if (str_contains($e, 'living room comes with a villa bedroom')) $rejected = true;
    check("allowance: {$label} → allowed {$allowed}", $q['livingAllowance'] === $allowed);
    check("allowance: {$label} → " . ($shouldReject ? 'rejected' : 'permitted'), $rejected === $shouldReject);
}

// Identical primitives, different outcome — stated as its own assertion because
// it is the fact the whole context-passing design exists to serve.
check('allowance: the suite pair and the loose pair have identical primitives',
    (int)$twoSuites['qtyDouble'] === 2 && (int)$twoSuites['qtyLiving'] === 2);
check('allowance: identical primitives, opposite outcomes',
    maya_ilai_quote($twoSuites + ['nights' => 1, 'program' => 'none'], $D)['errors'] === []
    && maya_ilai_quote(['qtyDouble' => 2, 'guestDouble' => 4, 'qtyLiving' => 2, 'nights' => 1, 'program' => 'none'], $D)['errors'] !== []);

// The inventory check is untouched and still answers its own question.
$eight = maya_ilai_quote(['qtyDouble' => 18, 'guestDouble' => 36, 'nights' => 1, 'program' => 'none'], $D);
check('inventory: the villa-count check still fires independently',
    (bool)array_filter($eight['errors'], fn($e) => str_contains($e, 'villas; only')));

// ── Discounts, supplements and the eco fee ──────────────────────────────────
// 2× Family Suite, 10 guests, 3 nights: base 2×(350+150+400) = 1800, no bunk
// supplement (6 bunk guests vs 2×3 included), 5% group discount at 10 guests,
// eco fee 10 × $20 charged once for the stay.
$grp = comboQuote('Two-Bedroom Family Suite', $D, ['qty' => 2, 'guests' => 10, 'nights' => 3]);
check('group: base is 1800',              eq($grp['base'], 1800.0));
check('group: 10 guests earn -5%',        eq($grp['adjustment'], -5.0));
check('group: nightly is 1710',           eq($grp['nightly'], 1710.0));
check('group: eco fee is 10 × $20 once',  eq($grp['eco'], 200.0));
check('group: total is 1710×3 + 200',     eq($grp['total'], 5330.0));
check('group: no errors',                 $grp['errors'] === []);

$short = comboQuote('Two-Bedroom Family Suite', $D, ['qty' => 2, 'guests' => 10, 'nights' => 2]);
check('group: under minNights earns no discount', eq($short['adjustment'], 0.0));
check('group: under minNights says so',           $short['adjustmentLabel'] === 'Minimum 3 nights not met');

// Single-occupancy: one guest in a double takes 15% off that room.
$single = maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 1, 'nights' => 1, 'program' => 'none'], $D);
check('single: one guest in a double is 350 × 0.85', eq($single['base'], 297.5));
check('single: two guests in a double is full rate',
    eq(maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 1, 'program' => 'none'], $D)['base'], 350.0));

// A selection with no program named must still behave like the documented default.
$noProg = maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 3], $D);
check('default program is group', $noProg['program'] === 'group');

// ── Live config (reads the saved settings blob; no writes) ──────────────────
try {
    $live = maya_ilai_pricing_get();
} catch (Throwable $e) {
    echo "\nSKIP  live config unreadable ({$e->getMessage()})\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}

foreach (maya_ilai_combos() as $c) {
    $occ = maya_ilai_combo_occupancy($live, $c['parts']);
    // Whatever the live rates are, the price the guest is SHOWN is summed from
    // the same rates that price the expansion, so it can never misprice.
    check("live: '{$c['key']}' shows what its expansion is quoted",
        eq(maya_ilai_combo_rate($live, $c['parts']), comboQuote($c['key'], $live, ['guests' => $occ['included']])['base']));
    check("live: '{$c['key']}' still satisfies the living-room invariant",
        comboQuote($c['key'], $live)['errors'] === []);
    // Dominated products are dropped by the configurator rather than quoted.
    if (!maya_ilai_combo_offerable($live, $c['parts'])) {
        echo "NOTE  live rates make '{$c['key']}' cost at least a whole villa — the configurator drops it\n";
    }
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
