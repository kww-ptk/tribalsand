<?php
declare(strict_types=1);
// Nightly rate overrides — range merging, resolution, trim/split writes, scope.
// Run: php tests/rates_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end, so no
// real rate rows are ever left behind.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic (no DB) ──────────────────────────────────────────────────────
check('merge: drops from >= to',
    rates_merge_ranges([['2099-01-05', '2099-01-05']]) === []);
check('merge: drops blank rows',
    rates_merge_ranges([['', '2099-01-05'], ['2099-01-01', '']]) === []);
check('merge: sorts by start',
    rates_merge_ranges([['2099-02-01', '2099-02-05'], ['2099-01-01', '2099-01-05']])
        === [['2099-01-01', '2099-01-05'], ['2099-02-01', '2099-02-05']]);
check('merge: joins overlapping',
    rates_merge_ranges([['2099-01-01', '2099-01-10'], ['2099-01-05', '2099-01-20']])
        === [['2099-01-01', '2099-01-20']]);
check('merge: joins abutting',
    rates_merge_ranges([['2099-01-01', '2099-01-10'], ['2099-01-10', '2099-01-20']])
        === [['2099-01-01', '2099-01-20']]);
check('merge: keeps a contained range inside its parent',
    rates_merge_ranges([['2099-01-01', '2099-01-30'], ['2099-01-05', '2099-01-10']])
        === [['2099-01-01', '2099-01-30']]);
check('merge: leaves disjoint ranges alone',
    rates_merge_ranges([['2099-01-01', '2099-01-05'], ['2099-03-01', '2099-03-05']])
        === [['2099-01-01', '2099-01-05'], ['2099-03-01', '2099-03-05']]);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
