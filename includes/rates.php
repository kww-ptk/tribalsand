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
