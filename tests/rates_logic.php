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

// ── DB assertions (rolled back) ─────────────────────────────────────────────
$roomId = (int) db_query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
if (!$roomId) { echo "\nSKIP  no rooms seeded\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);

    db_query(
        "INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
         VALUES (:r, '2099-06-10', '2099-06-15', 500, 'Peak')",
        [':r' => $roomId]
    );

    $map = rates_nightly_map($roomId, 100.0, '2099-06-08', '2099-06-18');
    check('map: one entry per night',        count($map) === 10);
    check('map: night before is default',    $map['2099-06-09']['price'] === 100.0);
    check('map: first override night',       $map['2099-06-10']['price'] === 500.0);
    check('map: last override night',        $map['2099-06-14']['price'] === 500.0);
    check('map: date_to is exclusive',       $map['2099-06-15']['price'] === 100.0);
    check('map: override carries label',     $map['2099-06-10']['label'] === 'Peak');
    check('map: override flagged',           $map['2099-06-10']['is_override'] === true);
    check('map: default not flagged',        $map['2099-06-09']['is_override'] === false);
    check('map: default has no label',       $map['2099-06-09']['label'] === null);
    check('map: default has no rate_id',     $map['2099-06-09']['rate_id'] === null);
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
