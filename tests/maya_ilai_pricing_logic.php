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

// ── The configuration search ────────────────────────────────────────────────
// maya_ilai_suggest() answers "we are N, what fits?" by generating candidate
// selections and handing each to maya_ilai_quote(). The quote is both the
// feasibility oracle and the pricer, so the bar these assertions defend is:
// EVERY suggestion must be bookable. A suggestion the guest cannot actually
// book is worse than no suggestion.

$maxParty = maya_ilai_max_party($D);
check('search: the compound sleeps 8 villas × 10 + 8 studios × 2 = 96', $maxParty === 96);

// Every party size the property can host gets at least one answer, and every
// answer it gives quotes clean. This is the assertion that matters most.
$anyEmpty = [];
for ($g = 1; $g <= 20; $g++) {
    $sugs = maya_ilai_suggest($g, 3, $D, 5);
    if (!$sugs) { $anyEmpty[] = $g; continue; }

    foreach ($sugs as $i => $s) {
        // Re-quote the returned selection from scratch: what the guest is shown
        // must be what the one pricing path says, not a figure carried alongside.
        $re = maya_ilai_quote($s['sel'], $D);
        if ($re['errors'] !== []) {
            check("search: {$g} guests — suggestion #" . ($i + 1) . " ('{$s['label']}') quotes without errors", false);
        }
        if (!eq((float)$re['total'], (float)$s['quote']['total'])) {
            check("search: {$g} guests — suggestion #" . ($i + 1) . " re-quotes to the same total", false);
        }
        if ((int)$s['quote']['guests'] !== $g) {
            check("search: {$g} guests — suggestion #" . ($i + 1) . " seats the whole party", false);
        }
    }
}
check('search: every party size 1–20 is answered', $anyEmpty === []);
check('search: every suggestion for every party size 1–20 quotes without errors', true);   // failures reported above

// ── Ranking: whole stays first, price second, cheapest never hidden ─────────
// A stay expressed as ONE product outranks the pile of rooms that sleeps the
// same party — leading a family of seven with "2× Private Bunk Room" sells the
// place as a hostel. Price is the second sort. The one deliberate exception is
// the globally cheapest stay, which is pulled up to slot two so a guest hunting
// for the cheapest way to sleep their party finds it without going back a step.
$units = fn(array $s) => array_sum(array_column($s['units'], 'qty'));
$cheapIdx = function (array $sugs) {
    $at = 0;
    foreach ($sugs as $i => $s) if ((float)$s['quote']['total'] < (float)$sugs[$at]['quote']['total'] - 0.005) $at = $i;
    return $at;
};

$orderOk = true; $presentOk = true; $slotOk = true; $taggedOk = true; $leadOk = true;
for ($g = 1; $g <= 20; $g++) {
    $sugs = maya_ilai_suggest($g, 3, $D, 5);
    if (!$sugs) continue;

    // THE property most likely to break silently: the cheapest configuration in
    // existence must be in the returned set, whatever the wholeness sort did to
    // it. Compared against an effectively unlimited search of the same party.
    $all   = maya_ilai_suggest($g, 3, $D, 999);
    $floor = min(array_map(fn($s) => (float)$s['quote']['total'], $all));
    $shown = min(array_map(fn($s) => (float)$s['quote']['total'], $sugs));
    if (abs($floor - $shown) >= 0.005) { $presentOk = false; echo "NOTE  {$g} guests: cheapest {$floor} missing, best shown {$shown}\n"; }

    $at = $cheapIdx($sugs);
    if ($at > 1) $slotOk = false;                                     // slot one or two, never buried
    $badge = $sugs[$at]['badge'] ?? '';
    if ($badge !== 'cheapest' && $badge !== 'both') $taggedOk = false;  // and visibly tagged
    if (($sugs[$at]['why'] ?? '') === '') $taggedOk = false;

    // The lead is simply the best-ranked stay — the wholest, then cheapest.
    foreach ($sugs as $s) if ($units($sugs[0]) > $units($s)) $leadOk = false;

    // With the two promoted rows set aside, the rest is ordered
    // whole-stays-first, then price.
    $rest = $sugs; unset($rest[$at], $rest[0]); $rest = array_values($rest);
    for ($i = 1; $i < count($rest); $i++) {
        $a = [$units($rest[$i - 1]), (float)$rest[$i - 1]['quote']['total']];
        $b = [$units($rest[$i]),     (float)$rest[$i]['quote']['total']];
        if ($a > $b) { $orderOk = false; echo "NOTE  {$g} guests: '{$rest[$i-1]['label']}' ranked above '{$rest[$i]['label']}'\n"; }
    }
}
check('search: the cheapest configuration is ALWAYS in the returned set (parties 1–20)', $presentOk);
check('search: the cheapest sits at slot one or two, never buried',                      $slotOk);
check('search: the cheapest is visibly tagged, with a reason',                           $taggedOk);
check('search: no lead is a bare bunk pile, and the lead is the wholest eligible stay',  $leadOk);
check('search: the rest is ordered whole stays first, then price',                       $orderOk);

// ── The bare bunk room is not a product a guest may select ──────────────────
// It exists only INSIDE the Two-Bedroom Family Room, the Two-Bedroom Family
// Suite and the Three-Bedroom Villa. It remains a PRIMITIVE — those products
// expand into it and price through it — so the assertions below are in two
// halves: nothing OFFERS a bare bunk room, and nothing about the PRICING of the
// products that contain one has moved.
//
// (This replaced maya_ilai_offer_is_bunk_only(), which kept a bare bunk pile
// out of the lead slot. With the product unselectable that predicate could
// never fire again, so it and its assertions are gone; commit 919cbb6 has them
// if the standalone bunk room is ever reinstated.)
check('no bare bunk: it is not in the sellable product list',
    !array_filter(maya_ilai_products($D),
        fn($p) => !$p['combo'] && array_keys($p['parts']) === ['bunk']));
check('no bare bunk: nor under any name',
    !array_filter(maya_ilai_products($D), fn($p) => $p['key'] === 'Private Bunk Room'));

$bareBunkAt = [];
for ($g = 1; $g <= maya_ilai_max_party($D); $g++) {
    foreach (maya_ilai_suggest($g, 3, $D, 5) as $s) {
        foreach ($s['units'] as $u) if ($u['key'] === 'Private Bunk Room') $bareBunkAt[] = $g;
    }
}
check('no bare bunk: no suggestion at any party size contains one', $bareBunkAt === []);
check('no bare bunk: and no mixed pair is half a bunk room either', (function () use ($D) {
    for ($g = 1; $g <= maya_ilai_max_party($D); $g++) {
        foreach (maya_ilai_suggest($g, 3, $D, 8) as $s) {
            if (str_contains($s['label'], 'Private Bunk Room')) return false;
        }
    }
    return true;
})());

// The guest picker drops its Bunk Room row too. Asserted against the source,
// because the partial needs a DB and this file is deliberately DB-free.
$mib = file_get_contents(__DIR__ . '/../includes/maya-ilai-booking.php');
check('no bare bunk: the "Build it yourself" picker has no Bunk Room row',
    $mib !== false && !str_contains($mib, "'key'=>'Bunk Room'"));
check('no bare bunk: the picker still offers the villa, studio and double',
    str_contains($mib, "'key'=>'Villa'") && str_contains($mib, "'key'=>'Studio'")
    && str_contains($mib, "'key'=>'Double Room'"));

// ── …and NOTHING about the pricing moved ────────────────────────────────────
// These three all contain a bunk room. Their totals are pinned: if the bunk
// rate, the extra-guest supplement or the villa packing had shifted, they would
// move. This is the proof that only the OFFER changed.
$pin = function (array $sel) use ($D) {
    return maya_ilai_quote($sel + ['nights' => 3, 'season' => 'high', 'program' => 'group'], $D);
};
$frQ = comboQuote('Two-Bedroom Family Room',  $D, ['guests' => 7, 'nights' => 3]);
$fsQ = comboQuote('Two-Bedroom Family Suite', $D, ['guests' => 7, 'nights' => 3]);
$vQ  = $pin(['qtyVilla' => 1, 'guestVilla' => 7]);
check('pricing pinned: Two-Bedroom Family Room, 7 guests, 3 nights = $1,910',  eq($frQ['total'], 1910.0));
check('pricing pinned: its bunk supplement is still (5-3) × 45 = 90',          eq($frQ['supplements'], 90.0));
check('pricing pinned: Two-Bedroom Family Suite, 7 guests, 3 nights = $3,110', eq($fsQ['total'], 3110.0));
check('pricing pinned: Three-Bedroom Villa, 7 guests, 3 nights = $3,650',      eq($vQ['total'], 3650.0));
// The server stays permissive: this is a merchandising rule, not a validation
// one, so a payload that names a bunk room is still priced (150 × 3 nights +
// 3 × $20 eco = 510) rather than rejected.
check('pricing pinned: a bare bunk room STILL prices if something posts one',
    eq($pin(['qtyBunk' => 1, 'guestBunk' => 3])['total'], 510.0)
    && $pin(['qtyBunk' => 1, 'guestBunk' => 3])['errors'] === []);
check('pricing pinned: with its extra-guest supplement intact',
    eq($pin(['qtyBunk' => 1, 'guestBunk' => 5])['supplements'], 90.0));
check('pricing pinned: the bunk room is still a primitive the combinations expand into',
    (int)comboQuote('Two-Bedroom Family Room', $D, ['guests' => 7])['q']['bunk'] === 1);

// ── Never quote a party for people who are not coming ───────────────────────
// A configuration is offered only when the guests its rates already COVER do
// not exceed the party — summed across its units, so "Family Room (5) + Studio
// (2)" is right for seven and "2× Bunk Room (3+3)" is right for it too.
check('fits: the included count sums across the units',
    maya_ilai_offer_included(['units' => [['included' => 5], ['included' => 2]]]) === 7);
check('fits: a party is not shown a product that covers more of them than exist',
    !maya_ilai_offer_fits_party(['units' => [['included' => 3]]], 2));
check('fits: it is shown one that covers exactly the party',
    maya_ilai_offer_fits_party(['units' => [['included' => 2]]], 2));
check('fits: and one that covers fewer',
    maya_ilai_offer_fits_party(['units' => [['included' => 3]]], 7));
check('fits: the sum is what counts, not any single unit',
    maya_ilai_offer_fits_party(['units' => [['included' => 5], ['included' => 2]]], 7)
    && !maya_ilai_offer_fits_party(['units' => [['included' => 5], ['included' => 5]]], 7));
check('fits: a solo traveller gets the floor of two, or nothing would fit',
    maya_ilai_offer_fits_party(['units' => [['included' => 2]]], 1)
    && !maya_ilai_offer_fits_party(['units' => [['included' => 3]]], 1));

// Party 2, exactly as the owner specified it.
$two2 = maya_ilai_suggest(2, 3, $D, 8);
$labels2 = array_map(fn($s) => $s['label'], $two2);
check('fits: 2 guests see only products that cover 2',
    $two2 && !array_filter($two2, fn($s) => maya_ilai_offer_included($s) > 2));
check('fits: 2 guests are NOT shown the Private Bunk Room (it covers 3)',
    !in_array('Private Bunk Room', $labels2, true));
check('fits: 2 guests are NOT shown the Two-Bedroom Family Suite (it covers 5)',
    !in_array('Two-Bedroom Family Suite', $labels2, true));
check('fits: 2 guests see the Studio, the Double Room and the One-Bedroom Suite',
    in_array('Studio', $labels2, true) && in_array('Double Room', $labels2, true)
    && in_array('One-Bedroom Suite', $labels2, true));
check('fits: and nothing else — those three are the whole list',
    count($labels2) === 3);
// Stated as its own assertion because it is the open question: the rule also
// hides the whole villa from a couple. If the owner reinstates it as an upsell
// (one disjunct in maya_ilai_offer_fits_party), this is the line that flips.
check('fits: 2 guests are not currently offered the whole villa (it covers 7)',
    !in_array('Three-Bedroom Villa', $labels2, true));

// A solo traveller still gets real options.
$one1 = maya_ilai_suggest(1, 3, $D, 5);
check('fits: a party of one is answered', count($one1) > 0);
check('fits: a party of one sees rooms that sleep them, all covering 2',
    !array_filter($one1, fn($s) => maya_ilai_offer_included($s) > 2)
    && !array_filter($one1, fn($s) => $s['quote']['errors'] !== []));
check('fits: a party of one is led by the Double Room at the lowest price',
    $one1[0]['label'] === 'Double Room' && $one1[0]['badge'] === 'both');

// The invariant across every party the compound can host, AND the proof that
// the relax-rather-than-blank fallback never had to fire: when it fires every
// returned row breaks the cap, so "no row anywhere breaks the cap" is exactly
// "the fallback never fired".
$overCap = []; $emptyAt = [];
for ($g = 1; $g <= maya_ilai_max_party($D); $g++) {
    $sugs = maya_ilai_suggest($g, 3, $D, 5);
    if (!$sugs) { $emptyAt[] = $g; continue; }
    foreach ($sugs as $s) if (maya_ilai_offer_included($s) > max(2, $g)) { $overCap[] = $g; break; }
}
check('fits: no party from 1 to the compound maximum is answered with nothing', $emptyAt === []);
// When the fallback fires every returned row breaks the cap, so this list is
// exactly the set of parties that needed it. A party of THREE is the only one:
// with no bare bunk room to sell, the smallest thing that sleeps three covers
// five, and an oversized suggestion beats a blank page.
check('fits: only a party of 3 needs the relax-rather-than-blank fallback', $overCap === [3]);
check('fits: and a party of 3 is answered with real, bookable stays',
    count(maya_ilai_suggest(3, 3, $D, 5)) === 5
    && !array_filter(maya_ilai_suggest(3, 3, $D, 5), fn($s) => $s['quote']['errors'] !== []));

// The lead's sentence has to be true of the rooms in it — a studio is not "one
// space, all yours" in the sense a villa is.
$noteOf = fn(array $q, int $u = 1, int $g = 2) => maya_ilai_lead_note(['quote' => ['q' => $q, 'guests' => $g]], $u);
check('lead note: a whole villa says so',
    $noteOf(['villa' => 1]) === 'The whole villa, all yours');
check('lead note: a suite names its living room and kitchen',
    str_contains($noteOf(['double' => 1, 'living' => 1]), 'living room and kitchen'));
check('lead note: one bedroom with a living room reads as one bedroom',
    str_contains($noteOf(['double' => 1, 'living' => 1]), 'A bedroom with'));
check('lead note: two bedrooms with a living room reads as several',
    str_contains($noteOf(['double' => 2, 'living' => 1]), 'Bedrooms with'));
check('lead note: a studio is a studio, not a whole space',
    $noteOf(['studio' => 1]) === 'A studio to yourselves');
check('lead note: a bedroom in a villa keeps the plain promise',
    $noteOf(['double' => 1]) === 'One space, all yours');
check('lead note: several units count the rooms instead',
    $noteOf(['double' => 2], 2, 4) === 'The fewest separate rooms for a party of 4');
// A 9-guest party has no single product but the villa, so the villa leads and
// says what it is.
$nine = maya_ilai_suggest(9, 3, $D, 5);
check('lead note: the 9-guest lead is the whole villa, and says so',
    $nine[0]['label'] === 'Three-Bedroom Villa' && str_contains($nine[0]['why'], 'The whole villa, all yours'));

// The cheapest is still guaranteed in when the limit is tight enough to have
// dropped it — it is swapped in over the last row, not lost.
foreach ([1, 2, 3] as $lim) {
    $tight = maya_ilai_suggest(7, 3, $D, $lim);
    $all7  = maya_ilai_suggest(7, 3, $D, 999);
    check("search: limit {$lim} still carries the cheapest stay",
        count($tight) <= $lim
        && abs(min(array_map(fn($s) => (float)$s['quote']['total'], $tight))
             - min(array_map(fn($s) => (float)$s['quote']['total'], $all7))) < 0.005);
}

// The 7-guest list is the case the owner judged: a real room must come before a
// bunk pile, and the $1,175 must still be on the page.
$seven7 = maya_ilai_suggest(7, 3, $D, 5);
check('search: 7 guests lead with a single whole product', $units($seven7[0]) === 1);
check('search: 7 guests do not lead with a pile of bunk rooms',
    (int)$seven7[0]['quote']['q']['bunk'] <= 1 && $units($seven7[0]) === 1);
check('search: the 7-guest lead is the Two-Bedroom Family Room', $seven7[0]['label'] === 'Two-Bedroom Family Room');
// With no bare bunk room to sell, the 7-guest lead is ALSO the cheapest stay,
// so the two badges merge onto one card. The $1,175 bunk pile that used to hold
// slot two is deliberately gone; the entry price for seven is now $1,910.
check('search: the 7-guest lead is also the cheapest, badges merged',
    $seven7[0]['badge'] === 'both' && eq((float)$seven7[0]['quote']['total'], 1910.0)
    && eq((float)$seven7[0]['quote']['total'],
          min(array_map(fn($s) => (float)$s['quote']['total'], $seven7))));
check('search: no bunk pile is offered to a party of seven',
    !array_filter($seven7, fn($s) => str_contains($s['label'], 'Private Bunk Room')));

// The badge is derived from the offer, so the reason matches the rooms.
// The cheapest card names the SPLIT, not the bunk beds: every stay that has a
// bunk room now has it inside a family product, the lead card included, so
// naming them would single out a fact that is equally true of the row above.
$b = maya_ilai_offer_badge(['units' => [['qty' => 2]], 'quote' => ['q' => ['bunk' => 2], 'guests' => 7]], false, true);
check('badge: a cheaper split names the split, not the bunk beds',
    $b['badge'] === 'cheapest' && str_contains($b['why'], '2 separate rooms')
    && !str_contains($b['why'], 'Bunk beds'));
$b = maya_ilai_offer_badge(['units' => [['qty' => 2]], 'quote' => ['q' => ['bunk' => 0], 'guests' => 4]], false, true);
check('badge: a cheap split with no bunks names only the split',
    !str_contains($b['why'], 'Bunk') && str_contains($b['why'], 'not one space'));
$b = maya_ilai_offer_badge(['units' => [['qty' => 1]], 'quote' => ['q' => ['bunk' => 0], 'guests' => 2]], true, false);
check('badge: the lead claims one space, not a price', $b['badge'] === 'pick' && str_contains($b['why'], 'One space'));
$b = maya_ilai_offer_badge(['units' => [['qty' => 1]], 'quote' => ['q' => ['bunk' => 1], 'guests' => 2]], true, true);
check('badge: lead and cheapest at once merge into one promise',
    $b['badge'] === 'both' && $b['tag'] === 'Our pick · lowest price'
    && str_contains($b['why'], 'lowest price we have'));
check('badge: an unremarkable offer wears nothing',
    maya_ilai_offer_badge(['units' => [['qty' => 1]], 'quote' => ['q' => ['bunk' => 0], 'guests' => 2]], false, false)
    === ['badge' => '', 'tag' => '', 'why' => '']);

// A couple of two sharing is not sold the whole compound.
$two = maya_ilai_suggest(2, 3, $D, 5);
$pos = function (array $sugs, string $label): ?int {
    foreach ($sugs as $i => $s) if ($s['label'] === $label) return $i;
    return null;
};
$villaAt = $pos($two, 'Three-Bedroom Villa'); $studioAt = $pos($two, 'Studio');
check('search: a 2-guest party is offered a Studio', $studioAt !== null);
check('search: a 2-guest party is not offered the whole villa above a studio',
    $villaAt === null || ($studioAt !== null && $studioAt < $villaAt));
check('search: the cheapest 2-guest offer costs less than a whole villa',
    (float)$two[0]['quote']['nightly'] < (float)$D['rates']['villa']);

// A snug fit outranks a cavernous one at the same money — the tie-break after
// units and price is wasted capacity, so a 7-guest party is never shown a
// 20-bed stay above an equally-priced one that fits.
$seven = maya_ilai_suggest(7, 3, $D, 5);
check('search: 7 guests get suggestions', count($seven) > 0);
$sevenOk = true;
foreach ($seven as $i => $s) {
    if ($i === 0) continue;
    $prev = $seven[$i - 1];
    if (array_sum(array_column($prev['units'], 'qty')) === array_sum(array_column($s['units'], 'qty'))
        && eq((float)$prev['quote']['total'], (float)$s['quote']['total'])
        && (int)$prev['quote']['capacity'] > (int)$s['quote']['capacity']) $sevenOk = false;
}
check('search: at equal size and price the snugger fit ranks first', $sevenOk);

// Guest allocation: the quote rejects a selected room with nobody in it, so
// every returned configuration must seat at least one guest in every room —
// and at least one per bedroom of a combination.
$allocOk = true; $unitSumOk = true;
for ($g = 1; $g <= 20; $g++) {
    foreach (maya_ilai_suggest($g, 3, $D, 5) as $s) {
        $q = $s['quote']['q']; $gs = $s['quote']['g'];
        foreach (['double', 'bunk', 'studio', 'villa'] as $k) {
            if ($q[$k] > 0 && $gs[$k] < $q[$k]) $allocOk = false;   // a room with nobody in it
            if ($q[$k] === 0 && $gs[$k] > 0)    $allocOk = false;   // guests in a room nobody booked
        }
        $sum = 0;
        foreach ($s['units'] as $u) {
            if ($u['guests'] < $u['qty']) $allocOk = false;         // fewer guests than units
            if ($u['guests'] > $u['max'])  $allocOk = false;        // past the product's ceiling
            $sum += $u['guests'];
        }
        if ($sum !== $g) $unitSumOk = false;
    }
}
check('search: guest allocation never leaves a room with zero guests', $allocOk);
check('search: the units breakdown accounts for every guest in the party', $unitSumOk);

// De-duplication: the same rooms at the same price are one offer, however it
// was assembled. "Two-Bedroom Family Room" and "Double Room + Private Bunk
// Room" are the same primitives at the same money — the guest sees it once.
$dupFree = true;
for ($g = 1; $g <= 20; $g++) {
    $seen = [];
    foreach (maya_ilai_suggest($g, 3, $D, 8) as $s) {
        $q = $s['quote']['q'];
        $key = implode('/', [$q['double'], $q['bunk'], $q['studio'], $q['villa'], $q['living'],
                             number_format((float)$s['quote']['total'], 2, '.', '')]);
        if (isset($seen[$key])) $dupFree = false;
        $seen[$key] = true;
    }
}
check('search: no two suggestions are the same primitives at the same price', $dupFree);
check('search: labels are distinct within a result set', (function () use ($D) {
    for ($g = 1; $g <= 20; $g++) {
        $labels = array_map(fn($s) => $s['label'], maya_ilai_suggest($g, 3, $D, 8));
        if (count($labels) !== count(array_unique($labels))) return false;
    }
    return true;
})());

// A combination beats its hand-assembled twin on the dedup tie-break, because a
// whole product reads like a stay and a parts list does not.
$fourteen = maya_ilai_suggest(14, 3, $D, 8);
$hasPartsList = false;
foreach ($fourteen as $s) {
    if ($s['label'] === 'Double Room + Private Bunk Room' || $s['label'] === 'Private Bunk Room + Double Room') $hasPartsList = true;
}
check('search: the parts-list spelling of a combination is not offered', !$hasPartsList);

// Inventory: a party larger than the compound gets NOTHING, not something
// unbookable.
check('search: one guest past the compound capacity returns nothing', maya_ilai_suggest($maxParty + 1, 3, $D) === []);
check('search: a wildly oversized party returns nothing',             maya_ilai_suggest(500, 3, $D) === []);
check('search: a full-compound party is still answered',              count(maya_ilai_suggest($maxParty, 3, $D)) > 0);

// The same holds against a shrunken inventory — the limit is read from the
// config, never assumed.
$small = maya_ilai_merge($D, ['inventory' => ['villas' => 1, 'studios' => 0]]);
check('search: a one-villa compound sleeps 10',            maya_ilai_max_party($small) === 10);
check('search: 10 fits a one-villa compound',              count(maya_ilai_suggest(10, 3, $small)) > 0);
check('search: 11 does not, and returns nothing',          maya_ilai_suggest(11, 3, $small) === []);
$smallOk = true;
for ($g = 1; $g <= 10; $g++) {
    foreach (maya_ilai_suggest($g, 3, $small) as $s) {
        if (maya_ilai_quote($s['sel'], $small)['errors'] !== []) $smallOk = false;
        if ((int)$s['quote']['q']['studio'] > 0) $smallOk = false;          // there are no studios
        if ((int)$s['quote']['physicalVillas'] > 1) $smallOk = false;       // there is one villa
    }
}
check('search: a shrunken inventory is respected in every suggestion', $smallOk);

// Nights flow through to the total the same way the picker's do.
$oneNight   = maya_ilai_suggest(6, 1, $D, 1);
$threeNight = maya_ilai_suggest(6, 3, $D, 1);
check('search: nights reach the quote', $oneNight && $threeNight
    && (int)$oneNight[0]['quote']['nights'] === 1 && (int)$threeNight[0]['quote']['nights'] === 3);
check('search: a longer stay costs more', $oneNight && $threeNight
    && (float)$threeNight[0]['quote']['total'] > (float)$oneNight[0]['quote']['total']);

// The limit is honoured, and a bad one cannot explode the list.
check('search: honours the limit',     count(maya_ilai_suggest(12, 3, $D, 3)) <= 3);
check('search: a zero limit still returns something rather than nothing', count(maya_ilai_suggest(12, 3, $D, 0)) === 1);
check('search: nonsense guest counts are floored, not crashed', count(maya_ilai_suggest(0, 3, $D)) > 0);
check('search: nonsense night counts are floored, not crashed',
    (int)(maya_ilai_suggest(4, 0, $D, 1)[0]['quote']['nights'] ?? 0) === 1);

// Every product the search can offer is a product the quote accepts on its own.
foreach (maya_ilai_products($D) as $p) {
    $picks = [['product' => $p, 'qty' => 1]];
    $alloc = maya_ilai_allocate_guests($picks, (int)$p['included']);
    check("search: product '{$p['key']}' quotes clean at its included occupancy",
        $alloc !== null && maya_ilai_quote(maya_ilai_picks_to_sel($picks, $alloc, 3, $D), $D)['errors'] === []);
}
check('search: the product list is the three sellable primitives plus the offerable combinations',
    count(maya_ilai_products($D)) === 3 + count(maya_ilai_combos()));

// The allocator itself: seeds one per room, fills to the included count, only
// then spills into the paid extras.
$bunkP   = ['product' => ['min'=>1,'included'=>3,'max'=>6], 'qty' => 2];
check('alloc: below the minimum is impossible',  maya_ilai_allocate_guests([$bunkP], 1) === null);
check('alloc: above the maximum is impossible',  maya_ilai_allocate_guests([$bunkP], 13) === null);
check('alloc: 2 guests seat one per bunk room',  maya_ilai_allocate_guests([$bunkP], 2) === [0 => 2]);
check('alloc: fills toward the included count before the extras',
    maya_ilai_allocate_guests([['product'=>['min'=>1,'included'=>3,'max'=>6],'qty'=>1],
                              ['product'=>['min'=>1,'included'=>2,'max'=>2],'qty'=>1]], 5) === [0 => 3, 1 => 2]);
check('alloc: only spills into the extras once every included bed is taken',
    maya_ilai_allocate_guests([['product'=>['min'=>1,'included'=>3,'max'=>6],'qty'=>1],
                              ['product'=>['min'=>1,'included'=>2,'max'=>2],'qty'=>1]], 7) === [0 => 5, 1 => 2]);

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

/* ── The photograph on an offer card ────────────────────────────────────────
 *
 * Pure first: which product a configuration is OF is decided from the units
 * alone and needs no DB at all.
 */
check('dominant product: a single product is its own dominant one',
    maya_ilai_dominant_product([['key' => 'Double Room', 'qty' => 1]], $D) === 'Double Room');
check('dominant product: "2× Double Room" picks the double',
    maya_ilai_dominant_product([['key' => 'Double Room', 'qty' => 2]], $D) === 'Double Room');
// One unit each, so the tie falls to the higher nightly rate: the villa's 1170
// against the studio's 390.
check('dominant product: "Villa + Studio" picks the villa',
    maya_ilai_dominant_product([['key' => 'Three-Bedroom Villa', 'qty' => 1],
                                ['key' => 'Studio', 'qty' => 1]], $D) === 'Three-Bedroom Villa');
check('dominant product: the order of the units does not decide it',
    maya_ilai_dominant_product([['key' => 'Studio', 'qty' => 1],
                                ['key' => 'Three-Bedroom Villa', 'qty' => 1]], $D) === 'Three-Bedroom Villa');
// Units beat rate: three studios are what that stay is, whatever a villa costs.
check('dominant product: more units beats a higher rate',
    maya_ilai_dominant_product([['key' => 'Three-Bedroom Villa', 'qty' => 1],
                                ['key' => 'Studio', 'qty' => 3]], $D) === 'Studio');
check('dominant product: an offer with no units resolves to nothing',
    maya_ilai_dominant_product([], $D) === null
    && maya_ilai_dominant_product([['key' => 'Studio', 'qty' => 0]], $D) === null);

// Every product the search can actually offer must have a room to photograph.
$slugMap = maya_ilai_room_slugs();
$unmapped = array_values(array_filter(array_column(maya_ilai_products($D), 'key'),
    fn($k) => !isset($slugMap[$k])));
check('every offerable product maps to a room slug' . ($unmapped ? ' (missing: ' . implode(', ', $unmapped) . ')' : ''),
    $unmapped === []);
// The bare bunk room is mapped but unreachable: it is not a product a guest can
// select, so no generated configuration carries it as a unit and it can never
// be a dominant product. The entry survives for the day that changes.
check('the bunk room has a slug but can never be a dominant product',
    isset($slugMap['Private Bunk Room'])
    && !array_filter(maya_ilai_products($D), fn($p) => $p['key'] === 'Private Bunk Room'));

/*
 * Then the resolution chain, against real rows. Everything below is seeded
 * inside ONE transaction that is rolled back, so the database is left exactly
 * as it was found — including the venue photographs the chain's second step
 * needs temporarily removed.
 */
$photoTx = false;
try { db()->beginTransaction(); $photoTx = true; }
catch (Throwable $e) { echo "\nSKIP  no DB — offer-photograph assertions skipped\n"; }

if ($photoTx) {
    try {
        $ph = []; $args = [];
        foreach (array_values($slugMap) as $i => $s) { $ph[] = ":s{$i}"; $args[":s{$i}"] = $s; }
        $roomIds = [];
        foreach (db_query('SELECT id, slug FROM rooms WHERE slug IN (' . implode(',', $ph) . ')', $args)->fetchAll() as $r) {
            $roomIds[$r['slug']] = (int)$r['id'];
        }
        check('the eight Maya Ilai products exist as rooms', count($roomIds) === count($slugMap));

        /** The photographs on the offer whose dominant product is $key, party of 7. */
        $photosFor = function (string $key) use ($D): array {
            maya_ilai_photo_index(true);                       // the seed just changed
            foreach (maya_ilai_suggest(7, 3, $D, 8) as $o) {
                if (maya_ilai_dominant_product($o['units'], $D) === $key) return $o['photos'];
            }
            return [['url' => '(no such offer)', 'alt' => '', 'source' => '']];
        };
        /** Just the URLs, for asserting ORDER rather than one winner. */
        $urls = fn(array $photos) => array_column($photos, 'url');

        // Step 3 — nothing anywhere. The card gets an empty list, and still quotes.
        if ($roomIds) {
            db_query('DELETE FROM room_images WHERE room_id IN (' . implode(',', array_map('intval', $roomIds)) . ')');
        }
        db_query('DELETE FROM venue_images WHERE venue_id = :v', [':v' => MAYA_ILAI_VENUE_ID]);
        maya_ilai_photo_index(true);
        $bare = maya_ilai_suggest(7, 3, $D, 8);
        check('with no photograph anywhere every offer still quotes and still returns',
            count($bare) >= 1 && !array_filter($bare, fn($o) => $o['quote']['errors'] !== []));
        check('with no photograph anywhere the photos list is empty, never a broken value',
            !array_filter($bare, fn($o) => $o['photos'] !== []));

        // Step 2 — the venue's own photographs, when the product has none. ALL of
        // them now, in the same hero-first order, so the fallback is a slider too.
        // Their alt text is the venue's, never the product's: those are the property.
        db_query("INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
                  VALUES (:v, 'venue-second.jpg', 'Second', FALSE, 3),
                         (:v, 'venue-third.jpg',  NULL,     FALSE, 5),
                         (:v, 'venue-hero.jpg',   'Maya Ilai', TRUE, 9)",
                 [':v' => MAYA_ILAI_VENUE_ID]);
        $venuePhotos = $photosFor('Two-Bedroom Family Room');
        check('a product with no photograph falls back to ALL of the venue\'s, hero first',
            $urls($venuePhotos) === [storage_url('venue-hero.jpg'),
                                     storage_url('venue-second.jpg'),
                                     storage_url('venue-third.jpg')]
            && array_column($venuePhotos, 'source') === ['venue', 'venue', 'venue']);
        check('the venue fallback is labelled with the venue, not with the product',
            array_column($venuePhotos, 'alt') === ['Maya Ilai', 'Second', 'Maya Ilai']);

        // Step 1 — the dominant product's own room wins, and within that room the
        // HERO image leads, then sort order. Eight seeded, six returned: the cap
        // is what stops a forty-photograph room filling a keystroke's payload.
        db_query("INSERT INTO room_images (room_id, filename, alt_text, is_hero, sort_order)
                  VALUES (:r, 'family-a.jpg', 'First by order', FALSE, 0),
                         (:r, 'family-b.jpg', NULL,   FALSE, 1),
                         (:r, 'family-c.jpg', 'Sea',  FALSE, 2),
                         (:r, 'family-d.jpg', NULL,   FALSE, 3),
                         (:r, 'family-e.jpg', NULL,   FALSE, 4),
                         (:r, 'family-f.jpg', NULL,   FALSE, 5),
                         (:r, 'family-g.jpg', NULL,   FALSE, 6),
                         (:r, 'family-hero.jpg', 'The family room', TRUE, 7)",
                 [':r' => $roomIds['maya-ilai-family-room']]);
        $roomPhotos = $photosFor('Two-Bedroom Family Room');
        check('a configuration resolves to its dominant product\'s room photographs',
            count($roomPhotos) && $roomPhotos[0]['source'] === 'room'
            && $roomPhotos[0]['url'] === storage_url('family-hero.jpg')
            && $roomPhotos[0]['alt'] === 'The family room');
        check('the room\'s photographs come back hero first, then by sort order',
            $urls($roomPhotos) === array_map('storage_url',
                ['family-hero.jpg', 'family-a.jpg', 'family-b.jpg',
                 'family-c.jpg', 'family-d.jpg', 'family-e.jpg']));
        check('eight photographs on a room yield ' . MAYA_ILAI_PHOTO_MAX . ' on the card — the cap holds',
            count($roomPhotos) === MAYA_ILAI_PHOTO_MAX);
        check('a room photograph with no alt text is labelled with the room name',
            $roomPhotos[2]['alt'] === 'Two-Bedroom Family Room');
        // The room set and the venue set never blend: a card is showing the room
        // or showing the property, and a slider that mixed them would caption a
        // compound shot with a room it does not show.
        check('a room with photographs takes none of the venue\'s',
            !array_intersect($urls($roomPhotos), $urls($venuePhotos)));
        // A room the migration has not created yet is a FALLBACK, not an error —
        // six of the eight products are in that state on production today. Proved
        // by hiding a room that DOES have photographs: the slug stops resolving,
        // so the six images above stop reaching the card and the venue set does
        // instead. Renamed rather than deleted, so nothing referencing the row is
        // disturbed, and put straight back.
        db_query("UPDATE rooms SET slug = 'maya-ilai-family-room-absent' WHERE slug = 'maya-ilai-family-room'");
        $absent = $photosFor('Two-Bedroom Family Room');
        check('a product whose room does not exist falls back to the venue, quietly',
            $urls($absent) === $urls($venuePhotos));
        db_query("UPDATE rooms SET slug = 'maya-ilai-family-room' WHERE slug = 'maya-ilai-family-room-absent'");
        check('and the room\'s own photographs come back once the slug resolves again',
            $urls($photosFor('Two-Bedroom Family Room')) === $urls($roomPhotos));

        // …and ONLY that configuration. Its neighbours still have no photo of
        // their own, so they are still on the venue fallback.
        $neighbour = $photosFor('Three-Bedroom Villa');
        check('one product\'s photographs are not borrowed by another configuration',
            count($neighbour) && $neighbour[0]['source'] === 'venue');

        // A room with exactly ONE image yields exactly one — the card renders it
        // as it always did, with no arrows and no counter over it.
        db_query("INSERT INTO room_images (room_id, filename, alt_text, is_hero, sort_order)
                  VALUES (:r, 'villa-only.jpg', NULL, FALSE, 0)",
                 [':r' => $roomIds['maya-ilai-villa']]);
        $one = $photosFor('Three-Bedroom Villa');
        check('a room with one photograph yields exactly one, so the card gets no slider chrome',
            count($one) === 1 && $one[0]['source'] === 'room'
            && $one[0]['url'] === storage_url('villa-only.jpg')
            && $one[0]['alt'] === 'Three-Bedroom Villa');

        // The cost. One query builds the whole library — every image of every
        // candidate room AND the venue's — and the offers are then resolved from
        // it, so the count does not move with how many offers or images there are.
        $before = maya_ilai_photo_query_count();
        maya_ilai_photo_index(true);
        $afterIndex = maya_ilai_photo_query_count();
        $many = maya_ilai_suggest(12, 3, $D, 8);
        $afterOffers = maya_ilai_photo_query_count();
        check('the photograph library costs exactly one query', $afterIndex - $before === 1);
        check('resolving ' . count($many) . ' offers\' photographs costs no further query',
            count($many) >= 2 && $afterOffers === $afterIndex);
        check('and a second search re-uses the memoised library',
            maya_ilai_suggest(9, 3, $D, 8) && maya_ilai_photo_query_count() === $afterIndex);
        // The sliders really are populated — a one-query assertion over empty
        // lists would prove nothing.
        check('those offers carry real slider sets, not one photograph each',
            (bool)array_filter($many, fn($o) => count($o['photos']) > 1));
    } finally {
        db()->rollBack();
        maya_ilai_photo_index(true);   // drop the memo built from seeded rows
    }
    // Belt and braces: the seeded rows are really gone, and the room this block
    // renamed to prove the missing-room fallback is back under its own slug.
    $left = (int) db_query("SELECT COUNT(*) FROM room_images WHERE filename LIKE 'family-%' OR filename LIKE 'villa-only%'")->fetchColumn();
    $venueLeft = (int) db_query("SELECT COUNT(*) FROM venue_images WHERE filename LIKE 'venue-hero%' OR filename LIKE 'venue-second%' OR filename LIKE 'venue-third%'")->fetchColumn();
    $renamed = (int) db_query("SELECT COUNT(*) FROM rooms WHERE slug = 'maya-ilai-family-room-absent'")->fetchColumn();
    check('the seeded photograph rows were rolled back', $left === 0 && $venueLeft === 0);
    check('the renamed room was rolled back too', $renamed === 0);
}

// ── Offer → live-inventory demand translation (pure) ────────────────────────
// maya_ilai_offer_demand() maps the pricing tool's product NAMES onto the
// inventory's room SLUGS (studios split out to be counted), so the live
// availability oracle can be asked whether an offer is bookable.
$demand = maya_ilai_offer_demand([
    ['product' => ['key' => 'Three-Bedroom Villa'], 'qty' => 1],
    ['product' => ['key' => 'Studio'], 'qty' => 2],
    ['product' => ['key' => 'One-Bedroom Suite'], 'qty' => 1],
]);
check('demand: villa + one-bed-suite become villa slugs, 2 studios counted',
    $demand === ['villaSlugs' => ['maya-ilai-villa', 'maya-ilai-one-bed-suite'], 'studios' => 2]);
check('demand: an unknown product key is dropped',
    maya_ilai_offer_demand([['product' => ['key' => 'Mystery Room'], 'qty' => 3]])
        === ['villaSlugs' => [], 'studios' => 0]);

// ── Live-availability filtering in the suggestion search (pure) ─────────────
// With a live snapshot, maya_ilai_suggest() drops any configuration that cannot
// actually be booked for the dates — BEFORE ranking and the limit.
$allFull = ['freeVillas' => 0, 'villaStates' => [], 'freeStudios' => 0, 'reserved' => 0];
for ($i = 0; $i < 8; $i++) {
    $allFull['villaStates'][] = ['unit_id' => 200 + $i, 'sort_order' => $i + 1, 'taken' => MAYA_ILAI_ALL_COMPONENTS];
}
check('suggest+live: a fully-booked compound offers nothing',
    maya_ilai_suggest(2, 3, $D, 5, $allFull) === []);

// One empty villa, no studios free: a couple still gets a villa-based stay, but
// no offer may consume a studio that is not there.
$oneVilla = ['freeVillas' => 1,
             'villaStates' => [['unit_id' => 300, 'sort_order' => 1, 'taken' => []]],
             'freeStudios' => 0, 'reserved' => 0];
$sugsOneVilla = maya_ilai_suggest(2, 3, $D, 8, $oneVilla);
check('suggest+live: one free villa still yields a bookable stay for a couple',
    count($sugsOneVilla) >= 1);
$consumesStudio = false;
foreach ($sugsOneVilla as $s) {
    $d = maya_ilai_offer_demand(array_map(
        fn($u) => ['product' => ['key' => $u['key']], 'qty' => $u['qty']], $s['units']));
    if ($d['studios'] > 0) { $consumesStudio = true; break; }
}
check('suggest+live: no offer consumes a studio when none are free', $consumesStudio === false);

check('suggest: with no snapshot the search is unfiltered, as before',
    count(maya_ilai_suggest(2, 3, $D, 8)) >= 1);

// ── Dynamic availability pricing: the 'live' program (pure) ─────────────────
// 'group' and 'availability' stay single levers (byte-identical to before);
// 'live' composes the availability band with the automatic group discount, so a
// guest sees the dynamic rate AND their discount in one price.
$sel2 = ['qtyVilla' => 1, 'guestVilla' => 2, 'nights' => 3];   // a couple, whole villa

// Reference band (2-3 villas free): 0% availability adjustment. With only 2
// guests there is no group tier, so 'live' equals the plain published price.
$qRef = maya_ilai_quote($sel2 + ['program' => 'live', 'availableUnits' => 3], $D);
$qNone = maya_ilai_quote($sel2 + ['program' => 'none'], $D);
check('live: reference band + small party == the plain published price',
    eq((float)$qRef['total'], (float)$qNone['total']));

// Opening rate (6-8 free): -15% off the base, shown as a discount.
$qOpen = maya_ilai_quote($sel2 + ['program' => 'live', 'availableUnits' => 8], $D);
check('live: opening-rate band discounts the base 15%',
    eq((float)$qOpen['availabilityAdjustment'], -15.0)
    && eq((float)$qOpen['adjustedBase'], (float)$qNone['base'] * 0.85));

// Limited availability (1 free): +15% on the base, surfaced as its own lever.
$qTight = maya_ilai_quote($sel2 + ['program' => 'live', 'availableUnits' => 1], $D);
check('live: limited-availability band lifts the base 15%',
    eq((float)$qTight['availabilityAdjustment'], 15.0)
    && (float)$qTight['total'] > (float)$qNone['total']);

// Composition: a party big enough for a group tier AND a discounted band applies
// BOTH, multiplicatively — never one or the other.
$grp = ['qtyVilla' => 2, 'guestVilla' => 20, 'nights' => 5];   // 20 guests → a group tier
$gTier = maya_ilai_group_discount($D, 20, 5);                  // the % that tier earns
check('live: composes band × group discount (both apply, multiplicatively)', (function () use ($grp, $D, $gTier) {
    $q = maya_ilai_quote($grp + ['program' => 'live', 'availableUnits' => 8], $D);
    $base = (float)$q['base'];
    $expected = $base * 0.85 * (1 - $gTier / 100);
    return $gTier > 0 && eq((float)$q['adjustedBase'], $expected)
        && eq((float)$q['groupDiscount'], $gTier)
        && eq((float)$q['availabilityAdjustment'], -15.0);
})());

// Backward-compat: 'group' and 'availability' are unchanged single levers.
check('live-refactor: plain group discount is unchanged',
    eq((float)maya_ilai_quote($grp + ['program' => 'group'], $D)['adjustedBase'],
       (float)maya_ilai_quote($grp, $D)['base'] * (1 - $gTier / 100)));
check('live-refactor: plain availability lever is unchanged (no group applied)',
    eq((float)maya_ilai_quote($sel2 + ['program' => 'availability', 'availableUnits' => 8], $D)['adjustedBase'],
       (float)$qNone['base'] * 0.85));

// The whole band ladder is owner-editable now. Sanitize coerces each row
// (min>=0, max>=min, adjustment real incl. negatives, label a string) and
// accepts a variable number of bands.
$san = maya_ilai_pricing_sanitize(['availability' => [
    ['min' => 5, 'max' => 3, 'adjustment' => -10, 'label' => 'Deep discount'],   // inverted range
    ['min' => 0, 'max' => 0, 'adjustment' => 0,   'label' => 'Sold out'],
]]);
check('sanitize: an inverted range is clamped (max >= min), negatives kept',
    $san['availability'][0] === ['min' => 5, 'max' => 5, 'adjustment' => -10.0, 'label' => 'Deep discount']);
check('sanitize: the ladder length follows what was posted (add/remove bands)',
    count($san['availability']) === 2);
check('sanitize: an empty ladder falls back to the shipped defaults',
    maya_ilai_pricing_sanitize(['availability' => []])['availability'] === $D['availability']);

// The band lookup fails SAFE: an out-of-range count is priced at reference (0%),
// never read as sold out.
$safe = maya_ilai_availability_band($D, 99);
check('band: an unmatched count fails safe to reference rate, not sold out',
    (int)$safe['adjustment'] === 0 && (int)$safe['max'] !== 0);
check('band: the explicit 0-free row is still sold out',
    (int)maya_ilai_availability_band($D, 0)['max'] === 0);

// ── Proportional ledger split for a multi-room booking (pure) ───────────────
// mi_proportional_shares() splits one quoted total across rooms by base rate,
// and the shares must sum EXACTLY to the total (money reconciles).
$pk = fn(string $key, int $qty = 1) => ['product' => ['key' => $key], 'qty' => $qty];

$sh = mi_proportional_shares([$pk('Three-Bedroom Villa'), $pk('Studio')], 1000.0, $D);
check('split: villa vs studio 1170:390 of 1000 → 750 / 250',
    eq($sh[0], 750.0) && eq($sh[1], 250.0));

$sh2 = mi_proportional_shares([$pk('Double Room', 2), $pk('Studio')], 1234.56, $D);
check('split: shares sum exactly to the total (rounding reconciled)',
    eq(array_sum($sh2), 1234.56) && $sh2[0] > 0 && $sh2[1] > 0);

$sh3 = mi_proportional_shares([$pk('Three-Bedroom Villa')], 999.99, $D);
check('split: a single room takes the whole total',
    eq($sh3[0], 999.99));

$sh4 = mi_proportional_shares([$pk('Mystery A'), $pk('Mystery B')], 100.0, $D);
check('split: all-zero-weight (unknown products) splits evenly and sums to total',
    eq($sh4[0], 50.0) && eq($sh4[1], 50.0) && eq(array_sum($sh4), 100.0));
/* ───────────── Seasons: the dates decide the price, night by night ─────────── */

// The windows are stated twice — here in PHP for the configurator, and as SQL in
// db/migrations/rates_maya_ilai_2026.sql for the booking engine. Two statements
// of one fact drift, so parse the migration and assert they still agree.
$mig = @file_get_contents(__DIR__ . '/../db/migrations/rates_maya_ilai_2026.sql');
if ($mig === false) {
    check('rates migration is readable', false);
} else {
    preg_match_all(
        "/\(DATE\s+'(\d{4}-\d{2}-\d{2})',\s*DATE\s+'(\d{4}-\d{2}-\d{2})',\s*(TRUE|FALSE)\)/i",
        $mig, $m, PREG_SET_ORDER
    );
    $fromSql = [];
    foreach ($m as $row) if (strtoupper($row[3]) === 'TRUE') $fromSql[] = [$row[1], $row[2]];
    check('the migration really declares some high-season windows', count($fromSql) > 0);

    // The mapped calendar: outside it a night falls back to high, so its bounds
    // decide where the standard rate stops being offered at all.
    $all = array_map(fn($r) => [$r[1], $r[2]], $m);
    check('PHP season calendar spans exactly what the migration covers',
          [$all[0][0], $all[count($all) - 1][1]] === maya_ilai_season_calendar());
    check('PHP high-season windows match the rates migration exactly',
          $fromSql === maya_ilai_high_windows());

    // And the collapse the design agreed on: 32 high-season nights in 2026.
    $high = 0;
    foreach (maya_ilai_high_windows() as [$a, $b]) {
        $high += (int) (new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->days;
    }
    check('32 high-season nights, as the design states', $high === 32);
}

check('a night inside a window is high',      maya_ilai_season_for_night('2026-12-25') === 'high');
check('a night outside every window is standard', maya_ilai_season_for_night('2026-06-15') === 'standard');
check('the window end is the checkout morning, not a night',
      maya_ilai_season_for_night('2026-01-11') === 'standard'
   && maya_ilai_season_for_night('2026-01-10') === 'high');
check('an unmapped year is high, never an under-quote',
      maya_ilai_season_for_night('2028-06-15') === 'high');

// New Year into January: the window ends 11 Jan, so 8-14 Jan is 3 high nights
// then 3 standard. Kept inside 2026 deliberately — a stay running past 1 Jan 2027
// leaves the mapped calendar and every night after it falls back to high, which
// is the next assertion rather than an accident of this one.
$sp = maya_ilai_season_split('2026-01-08', 6);
check('a straddling stay is split night by night', $sp['high'] === 3 && $sp['standard'] === 3);
check('the split always accounts for every night', $sp['high'] + $sp['standard'] === 6);
$past = maya_ilai_season_split('2026-12-28', 8);   // 4 nights in 2026, 4 in 2027
check('nights past the mapped calendar fall back to high, not standard',
      $past['high'] === 8 && $past['standard'] === 0);
check('no date falls back to the season given', maya_ilai_season_split(null, 5, 'standard')['standard'] === 5);
check('a malformed date is not tolerated on a price',
      maya_ilai_ymd('2026-9-1') === null && maya_ilai_ymd('2026-02-30') === null
   && maya_ilai_ymd('2026-09-01') === '2026-09-01');

// A mid-June stay must cost the standard rate — the bug the guest reported was
// that it charged high regardless of when they were coming.
$sel = ['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 4, 'program' => 'none'];
$hi  = maya_ilai_quote($sel + ['checkIn' => '2026-12-21'], $D);
$std = maya_ilai_quote($sel + ['checkIn' => '2026-06-15'], $D);
check('a standard-season stay is the reduced rate',
      eq((float)$std['nightly'], $D['rates']['double'] * (1 - $D['rules']['standardReduction'] / 100)));
check('a high-season stay is the published rate', eq((float)$hi['nightly'], (float)$D['rates']['double']));
check('standard really is cheaper than high', (float)$std['total'] < (float)$hi['total']);
check('the season is labelled from the dates', $hi['season'] === 'high' && $std['season'] === 'standard');

// The straddling quote must sit strictly between the two, and its nightly figure
// must still foot exactly — this is what makes the blended rate legitimate
// rather than an approximation.
$mix = maya_ilai_quote($sel + ['checkIn' => '2026-01-09'], $D);   // 9,10 high · 11,12 standard
check('a straddling stay is labelled mixed', $mix['season'] === 'mixed');
check('a straddling stay prices between the two seasons',
      (float)$mix['total'] > (float)$std['total'] && (float)$mix['total'] < (float)$hi['total']);
check('a straddling stay is exactly its nights at their own rates',
      eq((float)$mix['accommodation'],
         $D['rates']['double'] * 2
       + $D['rates']['double'] * (1 - $D['rules']['standardReduction'] / 100) * 2));
check('nightly x nights still foots to accommodation',
      eq((float)$mix['nightly'] * 4, (float)$mix['accommodation']));

// Omitting the date must leave the staff tool byte-for-byte as it was.
check('no date is the old single-season behaviour',
      maya_ilai_quote($sel + ['season' => 'standard'], $D)['total'] === $std['total']);

/* ───────────── The Eco-Resort Fee is a separate charge ─────────────────────── */

$fee = maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 3,
                        'program' => 'none', 'checkIn' => '2026-06-15'], $D);
check('accommodation excludes the Eco-Resort Fee',
      eq((float)$fee['accommodation'], (float)$fee['nightly'] * 3));
check('total is accommodation plus the fee',
      eq((float)$fee['total'], (float)$fee['accommodation'] + (float)$fee['eco']));
check('the fee is per guest for the whole stay, not per night',
      eq((float)$fee['eco'], 2 * (float)$D['rules']['ecoFee']));
check('a longer stay does not multiply the fee',
      eq((float)maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 9,
                                 'program' => 'none'], $D)['eco'], (float)$fee['eco']));


/* ───────────── The Eco-Resort Fee is not part of what a guest is quoted ────── */

$fee = maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 3,
                        'program' => 'none'], $D);
check('accommodation excludes the Eco-Resort Fee',
      eq((float)$fee['accommodation'], (float)$fee['nightly'] * 3));
check('total is accommodation plus the fee — the property still collects it',
      eq((float)$fee['total'], (float)$fee['accommodation'] + (float)$fee['eco']));
check('the fee is per guest for the whole stay, not per night',
      eq((float)$fee['eco'], 2 * (float)$D['rules']['ecoFee']));
check('a longer stay does not multiply the fee',
      eq((float)maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 9,
                                 'program' => 'none'], $D)['eco'], (float)$fee['eco']));
check('a sold-out band quotes nothing, not a fee-only stay',
      eq((float)maya_ilai_quote(['qtyDouble' => 1, 'guestDouble' => 2, 'nights' => 3,
                                 'program' => 'availability', 'availableUnits' => 0], $D)['accommodation'], 0.0));

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
