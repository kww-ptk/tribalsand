<?php
declare(strict_types=1);
// Maya Ilai composite inventory — component resolution, ring-fencing, allocation.
// Run: php tests/maya_ilai_inventory.php
// Any DB assertions run inside ONE transaction that is ROLLED BACK at the end.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-inventory.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Postgres array codec ────────────────────────────────────────────────────
check('encode: empty set',
    mi_pg_array_encode([]) === '{}');
check('encode: one component',
    mi_pg_array_encode(['bunk']) === '{bunk}');
check('encode: several keep order',
    mi_pg_array_encode(['double_a', 'living']) === '{double_a,living}');
check('decode: raw codec treats NULL as empty — NULL-means-whole-unit is mi_block_taken_components()\'s job',
    mi_pg_array_decode(null) === []);
check('decode: empty literal',
    mi_pg_array_decode('{}') === []);
check('decode: one component',
    mi_pg_array_decode('{bunk}') === ['bunk']);
check('decode: several',
    mi_pg_array_decode('{double_a,living}') === ['double_a', 'living']);
check('decode: tolerates quotes and spaces',
    mi_pg_array_decode('{"double_a", "living"}') === ['double_a', 'living']);
check('codec: round-trips',
    mi_pg_array_decode(mi_pg_array_encode(['double_b', 'bunk', 'living']))
        === ['double_b', 'bunk', 'living']);

// ── NULL-means-whole-unit, in one place ─────────────────────────────────────
check('block taken: a NULL components column means the whole villa is taken',
    mi_block_taken_components(null) === MAYA_ILAI_ALL_COMPONENTS);
check('block taken: a components list passes through the raw codec',
    mi_block_taken_components('{bunk}') === ['bunk']);

// ── Product map ─────────────────────────────────────────────────────────────
check('map: seven composite products',
    count(mi_product_map()) === 7);
check('map: the studio is NOT composite — it is its own unit',
    !isset(mi_product_map()['maya-ilai-studio']));
check('map: villa takes all four components',
    mi_product_map()['maya-ilai-villa'] === ['double', 'double', 'bunk', 'living']);
check('map: exact product map — pins every pattern, not just the ones resolve tests happen to use',
    mi_product_map() === [
        'maya-ilai-bunk-room'     => ['bunk'],
        'maya-ilai-double'        => ['double'],
        'maya-ilai-family-room'   => ['double', 'bunk'],
        'maya-ilai-one-bed-suite' => ['double', 'living'],
        'maya-ilai-family-suite'  => ['double', 'bunk', 'living'],
        'maya-ilai-two-bed-suite' => ['double', 'double', 'living'],
        'maya-ilai-villa'         => ['double', 'double', 'bunk', 'living'],
    ]);
check('is_composite: a component product',
    mi_is_composite_room(['slug' => 'maya-ilai-double']) === true);
check('is_composite: the studio is not',
    mi_is_composite_room(['slug' => 'maya-ilai-studio']) === false);
check('is_composite: another property is not',
    mi_is_composite_room(['slug' => 'zuri-jua']) === false);

// ── Component resolution ────────────────────────────────────────────────────
check('resolve: double into an empty villa takes double_a',
    mi_resolve(['double'], []) === ['double_a']);
check('resolve: double when double_a is taken falls to double_b',
    mi_resolve(['double'], ['double_a']) === ['double_b']);
check('resolve: no doubles left',
    mi_resolve(['double'], ['double_a', 'double_b']) === null);
check('resolve: bunk is free regardless of doubles',
    mi_resolve(['bunk'], ['double_a', 'double_b']) === ['bunk']);
check('resolve: bunk already taken',
    mi_resolve(['bunk'], ['bunk']) === null);
check('resolve: villa into an empty villa',
    mi_resolve(['double', 'double', 'bunk', 'living'], [])
        === ['double_a', 'double_b', 'bunk', 'living']);
check('resolve: villa blocked by a single taken double',
    mi_resolve(['double', 'double', 'bunk', 'living'], ['double_b']) === null);
check('resolve: two-bed suite needs both doubles',
    mi_resolve(['double', 'double', 'living'], ['double_a']) === null);

// Fail-closed: an empty or unrecognised pattern must never read as "fits, takes
// nothing" — that oversells a fully-occupied villa as available.
check('resolve: an empty pattern fails closed, not "fits and takes nothing"',
    mi_resolve([], ['double_a', 'double_b', 'bunk', 'living']) === null);
check('resolve: an empty pattern fails closed even against an empty villa',
    mi_resolve([], []) === null);
check('resolve: an unrecognised component name fails closed',
    mi_resolve(['jacuzzi'], []) === null);
check('resolve: a literal double_a is not legal vocabulary (only the "double" placeholder is)',
    mi_resolve(['double_a', 'double'], []) === null);

// The case from the design review: a One-Bedroom Suite is sold in this villa,
// taking double_a + living. double_b and bunk must stay sellable.
$suiteSold = ['double_a', 'living'];
check('One-Bed Suite sold: a Double Room still sells',
    mi_resolve(['double'], $suiteSold) === ['double_b']);
check('One-Bed Suite sold: the Bunk Room still sells',
    mi_resolve(['bunk'], $suiteSold) === ['bunk']);
check('One-Bed Suite sold: a Family Room still sells',
    mi_resolve(['double', 'bunk'], $suiteSold) === ['double_b', 'bunk']);
check('One-Bed Suite sold: a SECOND One-Bed Suite does not',
    mi_resolve(['double', 'living'], $suiteSold) === null);
check('One-Bed Suite sold: the Family Suite does not',
    mi_resolve(['double', 'bunk', 'living'], $suiteSold) === null);
check('One-Bed Suite sold: the full villa does not',
    mi_resolve(['double', 'double', 'bunk', 'living'], $suiteSold) === null);

// ── Ring-fencing ────────────────────────────────────────────────────────────
// Reserved villas are the LAST N by RANK (1-based position in the villa list
// ordered by sort_order) — NOT by the raw sort_order value, which is nullable-
// default-0 and admin-editable, so it can be sparse or 0-based. Rank stays
// dense and 1-based no matter how sort_order is laid out, so changing N never
// reshuffles which villas were already reserved. These four tests pass ranks
// 6..8 of 8, which happen to equal sort_order in a dense 1..8 layout.
check('reserved: rank 8 of 8 with N=2',
    mi_villa_is_reserved(8, 8, 2) === true);
check('reserved: rank 7 of 8 with N=2',
    mi_villa_is_reserved(7, 8, 2) === true);
check('reserved: rank 6 of 8 with N=2',
    mi_villa_is_reserved(6, 8, 2) === false);
check('reserved: nothing reserved with N=0',
    mi_villa_is_reserved(8, 8, 0) === false);

// ── Villa ordering ──────────────────────────────────────────────────────────
$villas = [
    ['unit_id' => 101, 'sort_order' => 1, 'taken' => []],
    ['unit_id' => 102, 'sort_order' => 2, 'taken' => ['double_a']],
    ['unit_id' => 103, 'sort_order' => 3, 'taken' => ['double_a', 'bunk']],
    ['unit_id' => 104, 'sort_order' => 4, 'taken' => []],
    ['unit_id' => 105, 'sort_order' => 5, 'taken' => []],
    ['unit_id' => 106, 'sort_order' => 6, 'taken' => []],
    ['unit_id' => 107, 'sort_order' => 7, 'taken' => []],
    ['unit_id' => 108, 'sort_order' => 8, 'taken' => []],
];

$ordered = mi_order_villas($villas, false, 2);
check('order: component products skip the 2 reserved villas',
    count($ordered) === 6);
check('order: reserved villas are absent',
    !in_array(107, array_column($ordered, 'unit_id'), true)
    && !in_array(108, array_column($ordered, 'unit_id'), true));
check('order: pack tight — most-occupied villa first',
    array_column($ordered, 'unit_id') === [103, 102, 101, 104, 105, 106]);

$orderedVilla = mi_order_villas($villas, true, 2);
check('order: the villa product sees all 8',
    count($orderedVilla) === 8);
check('order: the villa product takes a reserved villa first',
    array_column($orderedVilla, 'unit_id')[0] === 107
    && array_column($orderedVilla, 'unit_id')[1] === 108);

$noFence = mi_order_villas($villas, false, 0);
check('order: with N=0 every villa is offered',
    count($noFence) === 8);

check('order: the internal _reserved sort artifact is not leaked to callers',
    !array_key_exists('_reserved', $ordered[0] ?? ['_reserved' => true]));

// A villa with no 'taken' key at all must not fatal. With exactly one villa the
// comparator never runs (size-dependent bug, invisible above N=1) — the second
// case below has two villas, so the comparator actually executes.
$singleNoTaken = [['unit_id' => 201, 'sort_order' => 1]];
$singleResult = mi_order_villas($singleNoTaken, false, 0);
check('order: a missing taken key does not fatal with a single villa',
    count($singleResult) === 1 && $singleResult[0]['taken'] === []);

$twoNoTaken = [
    ['unit_id' => 202, 'sort_order' => 1],
    ['unit_id' => 203, 'sort_order' => 2],
];
$twoResult = mi_order_villas($twoNoTaken, false, 0);
check('order: a missing taken key does not fatal with two villas (the comparator runs here)',
    count($twoResult) === 2
    && $twoResult[0]['taken'] === [] && $twoResult[1]['taken'] === []);

// sort_order is NOT NULL DEFAULT 0 and admin-editable, so it can be sparse or
// 0-based. Ranking must come from POSITION after sorting by sort_order, not
// from the raw value — otherwise a 0-based layout under-reserves.
$sparseVillas = [
    ['unit_id' => 301, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 302, 'sort_order' => 1, 'taken' => []],
    ['unit_id' => 303, 'sort_order' => 2, 'taken' => []],
    ['unit_id' => 304, 'sort_order' => 3, 'taken' => []],
    ['unit_id' => 305, 'sort_order' => 4, 'taken' => []],
    ['unit_id' => 306, 'sort_order' => 5, 'taken' => []],
    ['unit_id' => 307, 'sort_order' => 6, 'taken' => []],
    ['unit_id' => 308, 'sort_order' => 7, 'taken' => []],
];
$sparseOrdered = mi_order_villas($sparseVillas, false, 2);
check('order: 0-based/sparse sort_order still reserves exactly 2 villas with N=2',
    count($sparseOrdered) === 6);
check('order: 0-based/sparse sort_order reserves the villas ranked last, not sort_order>=8',
    !in_array(307, array_column($sparseOrdered, 'unit_id'), true)
    && !in_array(308, array_column($sparseOrdered, 'unit_id'), true));

// $reserved larger than the villa count must clamp, not throw. $totalVillas is
// now derived from count($villas) — there is no separate parameter to disagree
// with the list, so a deactivated/filtered villa can no longer desync it.
check('order: reserved clamps to the villa count instead of erroring',
    mi_order_villas($villas, false, 10) === []);

// units.sort_order is NOT NULL DEFAULT 0, so two admin-added villas can share a
// value. Without a tiebreaker, rank falls back to arbitrary input/row order —
// which villa gets ring-fenced would then depend on how Postgres happened to
// return the rows on a given request. unit_id must break the tie so the same
// villas are reserved no matter what order the rows arrive in.
$tieVillasA = [
    ['unit_id' => 51, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 52, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 53, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 54, 'sort_order' => 0, 'taken' => []],
];
$tieVillasB = array_reverse($tieVillasA); // same villas, reverse input order
check('order: a sort_order tie reserves the same villas regardless of input order (forward)',
    array_column(mi_order_villas($tieVillasA, false, 2), 'unit_id') === [51, 52]);
check('order: a sort_order tie reserves the same villas regardless of input order (reversed)',
    array_column(mi_order_villas($tieVillasB, false, 2), 'unit_id') === [51, 52]);

echo "\n" . ($failures ? "{$failures} FAILED\n" : "All passed\n");
exit($failures ? 1 : 0);
