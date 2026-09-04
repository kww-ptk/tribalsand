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
 * A strictly canonical Y-m-d, or null. Every comparison in this file is a string
 * comparison, which is only chronological for zero-padded dates — so anything
 * entering the library goes through here first.
 */
function rates_ymd(string $s): ?string {
    $s = trim($s);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

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
        $from = rates_ymd((string)($r[0] ?? ''));
        $to   = rates_ymd((string)($r[1] ?? ''));
        if ($from === null || $to === null || $from >= $to) continue;
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
 * Canonical Y-m-d for a READ window, or null if the input is not a real date.
 *
 * Deliberately more forgiving than rates_ymd(): it repairs a recoverable date
 * ('2099-9-1' -> '2099-09-01') instead of rejecting it. Writes stay strict —
 * a malformed date there is a bug worth surfacing — but a read window comes
 * from $_GET on a public endpoint, and dropping it entirely would leave the
 * caller with an empty map, which sums to a FREE stay. Repair, don't discard.
 */
function rates_window_ymd(string $s): ?string {
    $s = trim($s);
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) return null;
    [, $y, $mo, $d] = array_map('intval', $m);
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
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
 *
 * Call pattern: call this ONCE PER ROOM with the widest date window the page
 * needs, then slice the result in the view. Do not call it inside a nested
 * month/room loop — that turns one page render into N×M queries.
 *
 * The window is normalised here rather than trusted. Every comparison below is
 * a STRING comparison — chronological only for zero-padded dates — and one
 * caller (api/check-availability.php) is a public endpoint fed from $_GET. A
 * non-canonical date such as '2099-9-01' sorts ABOVE '2099-09-15', so an
 * un-normalised window makes max()/min() clamp to the wrong day and quote a
 * guest the override price for nights it never covered. Bad input yields an
 * empty map, and callers fall back to the room's default price.
 */
function rates_nightly_map(int $roomId, float $default, string $fromYmd, string $toExclYmd): array {
    $out = [];
    $fromYmd    = rates_window_ymd($fromYmd);
    $toExclYmd  = rates_window_ymd($toExclYmd);
    if ($fromYmd === null || $toExclYmd === null || $fromYmd >= $toExclYmd) return $out;

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
 * Write one price + label across N date ranges. Returns the number of rows
 * inserted.
 *
 * Ranges are merged first, then each one clears the nights it claims before its
 * own row is inserted, so every night ends up owned by exactly one row.
 *
 * Runs in a transaction — but only opens one if the caller has not already
 * (tests wrap everything in a transaction they roll back, and PDO/pgsql cannot
 * nest).
 *
 * No venue/account scoping here — this function will price any room id it is
 * given. The caller MUST verify the room belongs to the acting account's
 * venues before calling; this function deliberately does not check.
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

/** Every override on one room, earliest first. */
function rates_for_room(int $roomId): array {
    return db_query(
        'SELECT * FROM rates WHERE room_id = :rid ORDER BY date_from ASC, id ASC',
        [':rid' => $roomId]
    )->fetchAll();
}

/**
 * Every override across one property's rooms, grouped by room then date.
 *
 * `rooms.venue_id` is nullable — an orphaned room's overrides are invisible
 * here (and to any venue-scoped delete), effectively owner-only. Accepted
 * behaviour, not a bug.
 */
function rates_for_venue(int $venueId): array {
    return db_query(
        'SELECT r.*, rm.name AS room_name
           FROM rates r JOIN rooms rm ON rm.id = r.room_id
          WHERE rm.venue_id = :vid
          ORDER BY rm.sort_order ASC, rm.id ASC, r.date_from ASC',
        [':vid' => $venueId]
    )->fetchAll();
}

/**
 * Delete one override. $venueScope of null means unscoped (owner); otherwise the
 * row's room must belong to one of those venues, so a scoped account cannot
 * delete another property's rate by posting a foreign id.
 */
function rates_delete(int $rateId, ?array $venueScope): bool {
    if ($rateId <= 0) return false;
    $vid = db_query(
        'SELECT rm.venue_id FROM rates r JOIN rooms rm ON rm.id = r.room_id WHERE r.id = :id',
        [':id' => $rateId]
    )->fetchColumn();
    if ($vid === false) return false;
    if ($venueScope !== null && !in_array((int)$vid, array_map('intval', $venueScope), true)) return false;
    db_query('DELETE FROM rates WHERE id = :id', [':id' => $rateId]);
    return true;
}

/**
 * Parse the rate form's parallel range_from[] / range_to[] arrays into
 * [from, toExcl] pairs.
 *
 * The form's "To" field is the LAST NIGHT (inclusive) — the wording the old
 * Price Overrides form used — so a day is added here for exclusive storage.
 */
function rates_ranges_from_post(array $post): array {
    $from = $post['range_from'] ?? [];
    $to   = $post['range_to']   ?? [];
    if (!is_array($from) || !is_array($to)) return [];

    $out = [];
    foreach ($from as $i => $f) {
        $f = rates_ymd((string)$f);
        $t = rates_ymd((string)($to[$i] ?? ''));
        if ($f === null || $t === null) continue;
        $out[] = [$f, date('Y-m-d', strtotime($t . ' +1 day'))];
    }
    return $out;
}
