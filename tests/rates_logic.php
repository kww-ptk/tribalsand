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

    // ── listing / delete / POST parsing ────────────────────────────────────
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-05']], 400.0, 'Listed');
    $listed = rates_for_room($roomId);
    check('for_room: returns the row',        count($listed) === 1);
    check('for_room: carries the label',      ($listed[0]['label'] ?? '') === 'Listed');

    $venueId = (int) db_query('SELECT venue_id FROM rooms WHERE id = :r', [':r' => $roomId])->fetchColumn();
    if ($venueId) {
        $vrows = rates_for_venue($venueId);
        check('for_venue: includes the row',  count($vrows) >= 1);
        check('for_venue: joins room name',   isset($vrows[0]['room_name']));
    }

    $rateId = (int)$listed[0]['id'];
    check('delete: refused outside scope',    rates_delete($rateId, [-1]) === false);
    check('delete: still present after refusal',
        (int) db_query('SELECT COUNT(*) FROM rates WHERE id = :i', [':i' => $rateId])->fetchColumn() === 1);
    check('delete: allowed for owner (null scope)', rates_delete($rateId, null) === true);
    check('delete: row is gone',
        (int) db_query('SELECT COUNT(*) FROM rates WHERE id = :i', [':i' => $rateId])->fetchColumn() === 0);
    check('delete: unknown id → false',       rates_delete(0, null) === false);

    // The form posts the LAST NIGHT; storage is exclusive, so parsing adds a day.
    check('post parse: last night → exclusive',
        rates_ranges_from_post(['range_from' => ['2099-06-10'], 'range_to' => ['2099-06-14']])
            === [['2099-06-10', '2099-06-15']]);
    check('post parse: skips incomplete rows',
        rates_ranges_from_post(['range_from' => ['2099-06-10', ''], 'range_to' => ['2099-06-14', '2099-07-01']])
            === [['2099-06-10', '2099-06-15']]);
    check('post parse: missing keys → empty',  rates_ranges_from_post([]) === []);

    // ── new coverage ────────────────────────────────────────────────────────

    // 1. Resolution rule: overlapping rows resolve to the NEWER created_at.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query(
        "INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
         VALUES (:r, '2099-06-01', '2099-06-20', 500, 'Old', '2020-01-01 00:00:00')",
        [':r' => $roomId]
    );
    db_query(
        "INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
         VALUES (:r, '2099-06-10', '2099-06-15', 700, 'New', '2021-01-01 00:00:00')",
        [':r' => $roomId]
    );
    $resMap = rates_nightly_map($roomId, 100.0, '2099-06-01', '2099-06-20');
    check('resolution: contested night goes to newer created_at',
        $resMap['2099-06-12']['price'] === 700.0 && $resMap['2099-06-12']['label'] === 'New');
    check('resolution: uncontested night keeps the older row',
        $resMap['2099-06-01']['price'] === 500.0 && $resMap['2099-06-01']['label'] === 'Old');

    // 2. Split preserves created_at on both surviving halves.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query(
        "INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
         VALUES (:r, '2099-06-01', '2099-07-01', 500, 'Wide', '2020-05-05 12:00:00')",
        [':r' => $roomId]
    );
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-15']], 300.0, 'Inner');
    $splitRows = db_query(
        "SELECT date_from, date_to, price_amount, created_at FROM rates
          WHERE room_id = :r ORDER BY date_from ASC", [':r' => $roomId]
    )->fetchAll();
    check('split: exactly three rows result', count($splitRows) === 3);
    check('split: left half keeps original created_at',
        count($splitRows) === 3
        && (string)$splitRows[0]['date_from'] === '2099-06-01'
        && (string)$splitRows[0]['created_at'] === '2020-05-05 12:00:00');
    check('split: right half keeps original created_at',
        count($splitRows) === 3
        && (string)$splitRows[2]['date_from'] === '2099-06-15'
        && (string)$splitRows[2]['created_at'] === '2020-05-05 12:00:00');

    // 3. Adjacent-but-not-overlapping ranges are left alone.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-10']], 500.0, null);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-15']], 300.0, null);
    check('adjacent: first row untouched, exactly two rows',
        $rows() === [
            ['2099-06-01', '2099-06-10', 500.0],
            ['2099-06-10', '2099-06-15', 300.0],
        ]);

    // 4. Exact-equality replacement.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-10']], 500.0, null);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-10']], 300.0, null);
    check('exact replace: exactly one row at the new price',
        $rows() === [['2099-06-01', '2099-06-10', 300.0]]);

    // 5. rates_for_venue() excludes other venues (skip gracefully if no second venue with rooms).
    $otherVenueId = (int) db_query(
        'SELECT venue_id FROM rooms WHERE venue_id IS NOT NULL AND venue_id <> :v LIMIT 1',
        [':v' => $venueId]
    )->fetchColumn();
    $otherRoomId = $otherVenueId ? (int) db_query(
        'SELECT id FROM rooms WHERE venue_id = :v LIMIT 1', [':v' => $otherVenueId]
    )->fetchColumn() : 0;
    if ($otherVenueId && $otherRoomId) {
        db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
        db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $otherRoomId]);
        rates_apply_ranges($roomId, [['2099-06-01', '2099-06-05']], 400.0, 'Mine');
        rates_apply_ranges($otherRoomId, [['2099-06-01', '2099-06-05']], 400.0, 'Theirs');
        $scoped = rates_for_venue($venueId);
        $scopedRoomIds = array_map(fn($r) => (int)$r['room_id'], $scoped);
        check('for_venue: excludes other venues rates',
            in_array($roomId, $scopedRoomIds, true) && !in_array($otherRoomId, $scopedRoomIds, true));
        db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $otherRoomId]);
    } else {
        echo "SKIP  for_venue: excludes other venues rates (no second venue with rooms)\n";
    }

    // 6. rates_delete() succeeds inside a scope that contains the row's own venue.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-05']], 400.0, 'Scoped');
    $scopedRateId = (int) db_query(
        'SELECT id FROM rates WHERE room_id = :r LIMIT 1', [':r' => $roomId]
    )->fetchColumn();
    check('delete: succeeds when scope includes the row\'s own venue',
        rates_delete($scopedRateId, [$venueId]) === true);
    check('delete: row is gone after in-scope delete',
        (int) db_query('SELECT COUNT(*) FROM rates WHERE id = :i', [':i' => $scopedRateId])->fetchColumn() === 0);

    // 7. rates_nightly_map() with from >= to returns [].
    check('map: from >= to returns empty array',
        rates_nightly_map($roomId, 100.0, '2099-06-10', '2099-06-10') === []);
    check('map: from > to returns empty array',
        rates_nightly_map($roomId, 100.0, '2099-06-15', '2099-06-10') === []);

    // 8. rates_ranges_from_post() drops malformed dates instead of throwing.
    check('post parse: drops unparseable date',
        rates_ranges_from_post(['range_from' => ['not-a-date'], 'range_to' => ['2099-06-14']]) === []);
    check('post parse: drops non-canonical date (single-digit month/day)',
        rates_ranges_from_post(['range_from' => ['2099-6-1'], 'range_to' => ['2099-06-14']]) === []);
    check('post parse: keeps valid rows alongside dropped malformed ones',
        rates_ranges_from_post([
            'range_from' => ['not-a-date', '2099-06-10'],
            'range_to'   => ['2099-06-14', '2099-06-14'],
        ]) === [['2099-06-10', '2099-06-15']]);
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
