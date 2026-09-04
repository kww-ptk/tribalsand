<?php
/**
 * Nightly rate overrides (`rates`), stored per ROOM (not per unit).
 *
 * Data model (no migration — the table predates this file):
 *   rates(id, room_id, date_from, date_to, price_amount, label, created_at)
 *
 * date_to is EXCLUSIVE — it is the checkout morning, not the last night. The
 * forms label it "To (last night)" and add a day before storing; never change
 * that without repricing every stored override.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Normalise a list of [from, toExcl] ranges: drop invalid ones, sort by start,
 * and merge any that overlap or abut. Pure — no DB.
 *
 * Ranges submitted together share one price and one label, so merging is
 * lossless. It also stops two ranges in the same submission from trimming each
 * other in rates_apply_ranges().
 *
 * Dates are 'Y-m-d', so string comparison is chronological comparison.
 */
function rates_merge_ranges(array $ranges): array {
    $clean = [];
    foreach ($ranges as $r) {
        $from = trim((string)($r[0] ?? ''));
        $to   = trim((string)($r[1] ?? ''));
        if ($from === '' || $to === '' || $from >= $to) continue;
        $clean[] = [$from, $to];
    }
    if (!$clean) return [];

    usort($clean, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

    $out = [array_shift($clean)];
    foreach ($clean as [$from, $to]) {
        $i = count($out) - 1;
        if ($from <= $out[$i][1]) {                       // overlaps or abuts
            if ($to > $out[$i][1]) $out[$i][1] = $to;
        } else {
            $out[] = [$from, $to];
        }
    }
    return $out;
}

/**
 * ymd => ['price','label','rate_id','is_override'] for every night in
 * [$fromYmd, $toExclYmd).
 *
 * THE single source of truth for what a night costs. room_stay_quote() sums
 * this, so a rates calendar can never show a price a guest is not quoted.
 *
 * Resolution: the first overlapping row by created_at DESC claims a night;
 * anything unclaimed falls back to the room's own price. Production may still
 * hold overlapping rows written by the old Gantt form, and they must keep
 * resolving exactly as they did. (`id DESC` is only a tiebreak for rows sharing
 * a created_at — previously those resolved arbitrarily.)
 */
function rates_nightly_map(int $roomId, float $default, string $fromYmd, string $toExclYmd): array {
    $out = [];
    if ($fromYmd >= $toExclYmd) return $out;

    $rows = db_query(
        "SELECT id, date_from, date_to, price_amount, label
           FROM rates
          WHERE room_id = :rid AND date_from < :to AND date_to > :from
          ORDER BY created_at DESC, id DESC",
        [':rid' => $roomId, ':from' => $fromYmd, ':to' => $toExclYmd]
    )->fetchAll();

    $claimed = [];
    foreach ($rows as $r) {
        $d = new DateTime(max((string)$r['date_from'], $fromYmd));
        $e = new DateTime(min((string)$r['date_to'],   $toExclYmd));
        while ($d < $e) {
            $k = $d->format('Y-m-d');
            if (!isset($claimed[$k])) {
                $lbl = (string)($r['label'] ?? '');
                $claimed[$k] = [
                    'price'       => (float)$r['price_amount'],
                    'label'       => $lbl !== '' ? $lbl : null,
                    'rate_id'     => (int)$r['id'],
                    'is_override' => true,
                ];
            }
            $d->modify('+1 day');
        }
    }

    $d = new DateTime($fromYmd);
    $e = new DateTime($toExclYmd);
    while ($d < $e) {
        $k = $d->format('Y-m-d');
        $out[$k] = $claimed[$k] ?? [
            'price'       => $default,
            'label'       => null,
            'rate_id'     => null,
            'is_override' => false,
        ];
        $d->modify('+1 day');
    }
    return $out;
}

/**
 * Free every night in [$from, $toExcl) on this room, trimming, splitting or
 * deleting whatever already overlaps.
 *
 * The "spans" case must be tested BEFORE the two one-sided cases: a row that
 * covers the new range on both sides satisfies both of their conditions, and
 * checking them first would swallow it instead of splitting around it.
 *
 * The right-hand half of a split keeps the original created_at so resolution
 * order stays stable for any legacy overlapping rows around it.
 */
function rates_clear_span(int $roomId, string $from, string $toExcl): void {
    $rows = db_query(
        "SELECT id, date_from, date_to, price_amount, label, created_at
           FROM rates
          WHERE room_id = :rid AND date_from < :to AND date_to > :from",
        [':rid' => $roomId, ':from' => $from, ':to' => $toExcl]
    )->fetchAll();

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $ef = (string)$r['date_from'];
        $et = (string)$r['date_to'];

        if ($ef >= $from && $et <= $toExcl) {              // fully inside → gone
            db_query('DELETE FROM rates WHERE id = :id', [':id' => $id]);
        } elseif ($ef < $from && $et > $toExcl) {          // spans → split (test FIRST)
            db_query('UPDATE rates SET date_to = :nt WHERE id = :id', [':nt' => $from, ':id' => $id]);
            db_query(
                "INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
                 VALUES (:rid, :df, :dt, :price, :label, :ca)",
                [':rid' => $roomId, ':df' => $toExcl, ':dt' => $et,
                 ':price' => $r['price_amount'], ':label' => $r['label'],
                 ':ca' => (string)$r['created_at']]
            );
        } elseif ($ef < $from) {                           // overlaps the tail
            db_query('UPDATE rates SET date_to = :nt WHERE id = :id', [':nt' => $from, ':id' => $id]);
        } else {                                           // overlaps the head
            db_query('UPDATE rates SET date_from = :nf WHERE id = :id', [':nf' => $toExcl, ':id' => $id]);
        }
    }
}

/**
 * Write one price + label across N date ranges. Returns the rows inserted.
 *
 * Ranges are merged first, then each one clears the nights it claims before its
 * own row is inserted, so every night ends up owned by exactly one row.
 *
 * Runs in a transaction — but only opens one if the caller has not already
 * (tests wrap everything in a transaction they roll back, and PDO/pgsql cannot
 * nest).
 */
function rates_apply_ranges(int $roomId, array $ranges, float $price, ?string $label): int {
    $merged = rates_merge_ranges($ranges);
    if ($roomId <= 0 || !$merged || $price <= 0) return 0;

    $label = ($label !== null && trim($label) !== '') ? trim($label) : null;

    $pdo   = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        $inserted = 0;
        foreach ($merged as [$from, $toExcl]) {
            rates_clear_span($roomId, $from, $toExcl);
            db_query(
                "INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
                 VALUES (:rid, :df, :dt, :price, :label)",
                [':rid' => $roomId, ':df' => $from, ':dt' => $toExcl,
                 ':price' => $price, ':label' => $label]
            );
            $inserted++;
        }
        if ($ownTx) $pdo->commit();
        return $inserted;
    } catch (\Throwable $e) {
        if ($ownTx) $pdo->rollBack();
        throw $e;
    }
}
