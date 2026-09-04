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

    // ── rates_apply_ranges: trim / split ───────────────────────────────────
    // Helper: the room's rows as [from, to, price] triples, ordered.
    $rows = function () use ($roomId): array {
        return array_map(
            fn($r) => [(string)$r['date_from'], (string)$r['date_to'], (float)$r['price_amount']],
            db_query('SELECT date_from, date_to, price_amount FROM rates
                       WHERE room_id = :r ORDER BY date_from ASC', [':r' => $roomId])->fetchAll()
        );
    };

    // Case A — new range fully covers the existing one → existing deleted.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-10','2099-06-15',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-07-01']], 300.0, 'Wide');
    check('trim A: covered row is deleted',
        $rows() === [['2099-06-01', '2099-07-01', 300.0]]);

    // Case B — existing spans the new range → split into two.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-01','2099-07-01',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-15']], 300.0, 'Inner');
    check('trim B: spanning row splits in two, new row between',
        $rows() === [
            ['2099-06-01', '2099-06-10', 500.0],
            ['2099-06-10', '2099-06-15', 300.0],
            ['2099-06-15', '2099-07-01', 500.0],
        ]);

    // Case C — new range overlaps the existing row's tail → existing pulled back.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-01','2099-06-20',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-25']], 300.0, null);
    check('trim C: existing date_to pulled back',
        $rows() === [['2099-06-01', '2099-06-10', 500.0], ['2099-06-10', '2099-06-25', 300.0]]);

    // Case D — new range overlaps the existing row's head → existing pushed forward.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-10','2099-06-25',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-15']], 300.0, null);
    check('trim D: existing date_from pushed forward',
        $rows() === [['2099-06-01', '2099-06-15', 300.0], ['2099-06-15', '2099-06-25', 500.0]]);

    // Two ranges in one submission that overlap each other merge into one row.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    $n = rates_apply_ranges($roomId, [['2099-06-01', '2099-06-10'], ['2099-06-05', '2099-06-20']], 300.0, null);
    check('apply: overlapping submitted ranges merge',
        $n === 1 && $rows() === [['2099-06-01', '2099-06-20', 300.0]]);

    // Disjoint ranges in one submission stay separate but share price + label.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    $n = rates_apply_ranges($roomId, [['2099-06-01', '2099-06-05'], ['2099-08-01', '2099-08-05']], 275.0, 'Shoulder');
    check('apply: disjoint ranges make two rows', $n === 2 && count($rows()) === 2);
    check('apply: both rows share the label',
        (int) db_query("SELECT COUNT(*) FROM rates WHERE room_id = :r AND label = 'Shoulder'",
            [':r' => $roomId])->fetchColumn() === 2);

    // Guards.
    check('apply: zero price is refused',  rates_apply_ranges($roomId, [['2099-09-01', '2099-09-05']], 0.0, null) === 0);
    check('apply: no ranges is a no-op',   rates_apply_ranges($roomId, [], 300.0, null) === 0);

    // No write ever leaves an overlap behind.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-07-01']], 500.0, null);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-15']], 300.0, null);
    rates_apply_ranges($roomId, [['2099-06-12', '2099-06-20']], 200.0, null);
    check('apply: never leaves overlapping rows',
        (int) db_query(
            "SELECT COUNT(*) FROM rates a JOIN rates b
                ON a.room_id = b.room_id AND a.id <> b.id
               AND a.date_from < b.date_to AND a.date_to > b.date_from
             WHERE a.room_id = :r", [':r' => $roomId])->fetchColumn() === 0);
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
