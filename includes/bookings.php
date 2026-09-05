<?php
declare(strict_types=1);
/**
 * Unified bookings ledger — the single place revenue is read from for reporting,
 * across website, OTA and agent sources (migration: add_bookings_finance.sql).
 *
 * Two writers feed it:
 *   · Website — bookings_sync_hold() snapshots a confirmed hold's gross at confirm
 *     time (rate map × nights, frozen), so a later rate edit never rewrites a
 *     historical figure. Idempotent per hold (upsert on hold_id).
 *   · Import — bookings_import_upsert() writes a row alongside the availability
 *     block the Ezee importer creates, carrying the amount staff entered in the
 *     preview. Idempotent per block (upsert on block_id).
 *
 * Reporting splits into pure aggregators (fed plain rows, so tests need no DB)
 * and thin DB readers. Every read is guarded by bookings_supported(): before the
 * migration is applied these return empty results rather than a fatal.
 *
 * Multi-currency is real here (rooms price in USD or KES), so money is NEVER
 * summed across currencies — aggregates are keyed by currency and the reports
 * page renders one line per currency. See tests/reports_logic.php.
 */
require_once __DIR__ . '/db.php';

/** True if the bookings ledger table exists (memoised). False pre-migration. */
function bookings_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.bookings')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/* ─────────────────────────── Writers ─────────────────────────── */

/**
 * Snapshot a confirmed website hold into the ledger. Idempotent — re-confirming
 * refreshes the same row (keyed on hold_id). Derives venue/room/unit from the
 * hold's unit, and freezes gross = rate map × nights at confirm time. No-op
 * before the migration, or when the hold/room can't be resolved.
 */
function bookings_sync_hold(int $holdId): void {
    if (!bookings_supported() || $holdId <= 0) return;
    require_once __DIR__ . '/db.php';

    $h = db_query(
        "SELECT h.id, h.check_in, h.check_out, h.guest_name, h.guest_email, h.status,
                u.id AS unit_id, r.id AS room_id, r.venue_id,
                r.price_amount, r.price_currency
         FROM holds h
         JOIN units u ON u.id = h.unit_id
         JOIN rooms r ON r.id = u.room_id
         WHERE h.id = :id",
        [':id' => $holdId]
    )->fetch();
    if (!$h) return;

    $q = room_stay_quote((int)$h['room_id'], (float)$h['price_amount'], (string)$h['check_in'], (string)$h['check_out']);
    $status   = $h['status'] === 'confirmed' ? 'confirmed' : ($h['status'] === 'cancelled' ? 'cancelled' : 'pending');
    $currency = strtoupper(trim((string)($h['price_currency'] ?? 'USD'))) ?: 'USD';

    $existing = db_query('SELECT id FROM bookings WHERE hold_id = :h', [':h' => $holdId])->fetchColumn();
    if ($existing) {
        db_query(
            "UPDATE bookings SET venue_id=:v, room_id=:r, unit_id=:u, guest_name=:gn, guest_email=:ge,
                    check_in=:ci, check_out=:co, nights=:n, gross_amount=:g, currency=:cur, status=:st
             WHERE id=:id",
            [':v'=>$h['venue_id'], ':r'=>$h['room_id'], ':u'=>$h['unit_id'], ':gn'=>$h['guest_name'],
             ':ge'=>$h['guest_email'], ':ci'=>$h['check_in'], ':co'=>$h['check_out'], ':n'=>$q['nights'],
             ':g'=>$q['total'], ':cur'=>$currency, ':st'=>$status, ':id'=>$existing]
        );
    } else {
        db_query(
            "INSERT INTO bookings (venue_id, room_id, unit_id, source, guest_name, guest_email,
                    check_in, check_out, nights, gross_amount, currency, status, hold_id)
             VALUES (:v,:r,:u,'website',:gn,:ge,:ci,:co,:n,:g,:cur,:st,:h)",
            [':v'=>$h['venue_id'], ':r'=>$h['room_id'], ':u'=>$h['unit_id'], ':gn'=>$h['guest_name'],
             ':ge'=>$h['guest_email'], ':ci'=>$h['check_in'], ':co'=>$h['check_out'], ':n'=>$q['nights'],
             ':g'=>$q['total'], ':cur'=>$currency, ':st'=>$status, ':h'=>$holdId]
        );
    }
}

/** Mark a hold's ledger row cancelled (declined/cancelled hold). No-op if none. */
function bookings_mark_hold_cancelled(int $holdId): void {
    if (!bookings_supported() || $holdId <= 0) return;
    db_query("UPDATE bookings SET status='cancelled' WHERE hold_id = :h", [':h' => $holdId]);
}

/**
 * Write/refresh the ledger row for an imported calendar block. Idempotent per
 * block_id. Called by the importer right after it inserts the availability block,
 * with the amount staff entered in the preview (0 allowed — unpriced import).
 * $ctx: venue_id, room_id, unit_id, guest_name, agent, check_in, check_out,
 *       gross_amount, currency, external_ref.
 */
function bookings_import_upsert(int $blockId, array $ctx): void {
    if (!bookings_supported() || $blockId <= 0) return;

    $nights = bookings_night_count((string)$ctx['check_in'], (string)$ctx['check_out']);
    $agent  = trim((string)($ctx['agent'] ?? ''));
    $source = ($agent !== '' && $agent !== '-') ? 'agent' : 'ota';
    $cur    = strtoupper(trim((string)($ctx['currency'] ?? 'USD'))) ?: 'USD';
    $gross  = max(0.0, (float)($ctx['gross_amount'] ?? 0));

    $existing = db_query('SELECT id FROM bookings WHERE block_id = :b', [':b' => $blockId])->fetchColumn();
    if ($existing) {
        db_query(
            "UPDATE bookings SET venue_id=:v, room_id=:r, unit_id=:u, source=:src, guest_name=:gn,
                    agent=:ag, check_in=:ci, check_out=:co, nights=:n, gross_amount=:g, currency=:cur,
                    external_ref=:ref WHERE id=:id",
            [':v'=>$ctx['venue_id'], ':r'=>$ctx['room_id'], ':u'=>$ctx['unit_id'], ':src'=>$source,
             ':gn'=>$ctx['guest_name'], ':ag'=>$agent, ':ci'=>$ctx['check_in'], ':co'=>$ctx['check_out'],
             ':n'=>$nights, ':g'=>$gross, ':cur'=>$cur, ':ref'=>trim((string)($ctx['external_ref'] ?? '')),
             ':id'=>$existing]
        );
    } else {
        db_query(
            "INSERT INTO bookings (venue_id, room_id, unit_id, source, guest_name, agent, check_in,
                    check_out, nights, gross_amount, currency, status, block_id, external_ref, imported_at)
             VALUES (:v,:r,:u,:src,:gn,:ag,:ci,:co,:n,:g,:cur,'confirmed',:b,:ref,NOW())",
            [':v'=>$ctx['venue_id'], ':r'=>$ctx['room_id'], ':u'=>$ctx['unit_id'], ':src'=>$source,
             ':gn'=>$ctx['guest_name'], ':ag'=>$agent, ':ci'=>$ctx['check_in'], ':co'=>$ctx['check_out'],
             ':n'=>$nights, ':g'=>$gross, ':cur'=>$cur, ':b'=>$blockId,
             ':ref'=>trim((string)($ctx['external_ref'] ?? ''))]
        );
    }
}

/* ─────────────────────── Pure date maths ─────────────────────── */

/** Whole nights between two Y-m-d dates (check_out exclusive). 0 if unparseable/reversed. */
function bookings_night_count(string $ci, string $co): int {
    $a = strtotime($ci); $b = strtotime($co);
    if ($a === false || $b === false || $b <= $a) return 0;
    return (int) round(($b - $a) / 86400);
}

/**
 * Nights of a booking [ci, co) that fall inside the window of nights
 * [winFrom .. winToIncl] (winToIncl is the last night, inclusive). Pure.
 * Used for occupancy / nights-sold so a stay straddling the window edge only
 * contributes the nights actually inside it.
 */
function bookings_night_overlap(string $ci, string $co, string $winFrom, string $winToIncl): int {
    $s = max(strtotime($ci), strtotime($winFrom));
    // The window's exclusive end is the morning after its last night.
    $winEnd = strtotime($winToIncl . ' +1 day');
    $e = min(strtotime($co), $winEnd);
    if ($s === false || $e === false || $e <= $s) return 0;
    return (int) round(($e - $s) / 86400);
}

/* ───────────────────── Reporting: DB readers ─────────────────── */

/**
 * Confirmed bookings ARRIVING within [from, toIncl] for the given venue scope.
 * $venueIds: null = all (owner); [] = none; [ids] = those venues.
 * $source: '' = all, else one of website|ota|agent|direct.
 * Cancelled rows are excluded from revenue reporting.
 */
function bookings_in_window(?array $venueIds, string $from, string $toIncl, string $source = ''): array {
    if (!bookings_supported()) return [];
    if (is_array($venueIds) && !$venueIds) return [];   // scoped account with no venues

    $sql = "SELECT b.*, v.name AS venue_name
            FROM bookings b
            LEFT JOIN venues v ON v.id = b.venue_id
            WHERE b.status <> 'cancelled'
              AND b.check_in >= :from AND b.check_in <= :to";
    $args = [':from' => $from, ':to' => $toIncl];

    if ($source !== '') { $sql .= " AND b.source = :src"; $args[':src'] = $source; }
    if (is_array($venueIds)) {
        $in = implode(',', array_map('intval', $venueIds));   // ints only — safe to inline
        $sql .= " AND b.venue_id IN ($in)";
    }
    $sql .= " ORDER BY b.check_in DESC, b.id DESC";
    return db_query($sql, $args)->fetchAll();
}

/**
 * Active bookable units in scope — the occupancy denominator base. Entire-place
 * units are excluded so a whole-property unit doesn't double-count against its
 * individual rooms. $venueIds: null = all, [] = none.
 */
function bookings_active_unit_count(?array $venueIds): int {
    if (!bookings_supported()) return 0;
    if (is_array($venueIds) && !$venueIds) return 0;
    $sql = "SELECT COUNT(*) FROM units u
            JOIN rooms r ON r.id = u.room_id
            WHERE u.is_active = TRUE AND COALESCE(r.is_entire_place, FALSE) = FALSE";
    if (is_array($venueIds)) {
        $in = implode(',', array_map('intval', $venueIds));
        $sql .= " AND r.venue_id IN ($in)";
    }
    return (int) db_query($sql)->fetchColumn();
}

/* ─────────────────── Reporting: pure aggregators ─────────────── */

/**
 * Aggregate ledger rows (from bookings_in_window) into report figures, keyed by
 * currency so money is never mixed. Returns:
 *   ['currencies' => [CUR => ['revenue','bookings','nights','adr']],
 *    'by_property' => [venue_name => [CUR => ['revenue','bookings','nights']]],
 *    'by_month'    => ['YYYY-MM' => [CUR => ['revenue','bookings','nights']]],
 *    'by_source'   => [source    => [CUR => ['revenue','bookings','nights']]]]
 * ADR = revenue / nights within a currency. Pure — no DB, no globals.
 */
function bookings_summarize(array $rows): array {
    $cur = []; $prop = []; $month = []; $src = [];
    $bump = function (array &$bucket, string $key, string $c, float $rev, int $nights): void {
        if (!isset($bucket[$key][$c])) $bucket[$key][$c] = ['revenue'=>0.0, 'bookings'=>0, 'nights'=>0];
        $bucket[$key][$c]['revenue'] += $rev;
        $bucket[$key][$c]['bookings'] += 1;
        $bucket[$key][$c]['nights'] += $nights;
    };
    foreach ($rows as $r) {
        $c   = strtoupper(trim((string)($r['currency'] ?? 'USD'))) ?: 'USD';
        $rev = (float)($r['gross_amount'] ?? 0);
        $ni  = (int)($r['nights'] ?? 0);
        if (!isset($cur[$c])) $cur[$c] = ['revenue'=>0.0, 'bookings'=>0, 'nights'=>0, 'adr'=>0.0];
        $cur[$c]['revenue'] += $rev; $cur[$c]['bookings'] += 1; $cur[$c]['nights'] += $ni;

        $bump($prop,  ((string)($r['venue_name'] ?? '—')) ?: '—', $c, $rev, $ni);
        $bump($month, substr((string)($r['check_in'] ?? ''), 0, 7),       $c, $rev, $ni);
        $bump($src,   (string)($r['source'] ?? 'website'),                 $c, $rev, $ni);
    }
    foreach ($cur as $c => $t) {
        $cur[$c]['adr'] = $t['nights'] > 0 ? round($t['revenue'] / $t['nights'], 2) : 0.0;
    }
    ksort($cur); ksort($month);
    return ['currencies' => $cur, 'by_property' => $prop, 'by_month' => $month, 'by_source' => $src];
}

/**
 * Occupancy for the window: sold room-nights (intersection of each stay with the
 * window) ÷ available room-nights (active units × nights in window). Returns
 * ['sold','available','pct']. pct clamped to [0,100]. Entire-place stays are
 * excluded from sold nights to stay consistent with the denominator.
 */
function bookings_occupancy(array $rows, string $from, string $toIncl, int $activeUnits): array {
    $windowNights = bookings_night_count($from, (string) date('Y-m-d', (int) strtotime($toIncl . ' +1 day')));
    $available = max(0, $activeUnits) * max(0, $windowNights);
    $sold = 0;
    foreach ($rows as $r) {
        $sold += bookings_night_overlap((string)$r['check_in'], (string)$r['check_out'], $from, $toIncl);
    }
    $pct = $available > 0 ? round(min(100.0, max(0.0, $sold / $available * 100)), 1) : 0.0;
    return ['sold' => $sold, 'available' => $available, 'pct' => $pct];
}

/** Format money with its currency for reports: USD → "$1,234", else "KES 1,234". */
function bookings_money(float $amount, string $currency): string {
    $c = strtoupper(trim($currency)) ?: 'USD';
    $n = number_format($amount, 0);
    return $c === 'USD' ? '$' . $n : $c . ' ' . $n;
}

/** Human label for a booking source. */
function bookings_source_label(string $source): string {
    return match ($source) {
        'website' => 'Website', 'ota' => 'OTA / channel', 'agent' => 'Travel agent',
        'direct'  => 'Direct', default => ucfirst($source),
    };
}
