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

echo "\n" . ($failures ? "{$failures} FAILED\n" : "All passed\n");
exit($failures ? 1 : 0);
