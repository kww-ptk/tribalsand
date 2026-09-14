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

echo "\n" . ($failures ? "{$failures} FAILED\n" : "All passed\n");
exit($failures ? 1 : 0);
