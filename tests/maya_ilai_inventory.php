<?php
declare(strict_types=1);
// Maya Ilai composite inventory — component resolution, ring-fencing, allocation.
// Run: php tests/maya_ilai_inventory.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end.
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
check('decode: NULL is an empty list',
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

// ── Product map ─────────────────────────────────────────────────────────────
check('map: seven composite products',
    count(mi_product_map()) === 7);
check('map: the studio is NOT composite — it is its own unit',
    !isset(mi_product_map()['maya-ilai-studio']));
check('map: villa takes all four components',
    mi_product_map()['maya-ilai-villa'] === ['double', 'double', 'bunk', 'living']);
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
// Reserved villas are the LAST N by sort order, so changing N never reshuffles
// which villas were already reserved.
check('reserved: villa 8 of 8 with N=2',
    mi_villa_is_reserved(8, 8, 2) === true);
check('reserved: villa 7 of 8 with N=2',
    mi_villa_is_reserved(7, 8, 2) === true);
check('reserved: villa 6 of 8 with N=2',
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

$ordered = mi_order_villas($villas, false, 8, 2);
check('order: component products skip the 2 reserved villas',
    count($ordered) === 6);
check('order: reserved villas are absent',
    !in_array(107, array_column($ordered, 'unit_id'), true)
    && !in_array(108, array_column($ordered, 'unit_id'), true));
check('order: pack tight — most-occupied villa first',
    array_column($ordered, 'unit_id') === [103, 102, 101, 104, 105, 106]);

$orderedVilla = mi_order_villas($villas, true, 8, 2);
check('order: the villa product sees all 8',
    count($orderedVilla) === 8);
check('order: the villa product takes a reserved villa first',
    array_column($orderedVilla, 'unit_id')[0] === 107
    && array_column($orderedVilla, 'unit_id')[1] === 108);

$noFence = mi_order_villas($villas, false, 8, 0);
check('order: with N=0 every villa is offered',
    count($noFence) === 8);

echo "\n" . ($failures ? "{$failures} FAILED\n" : "All passed\n");
exit($failures ? 1 : 0);
