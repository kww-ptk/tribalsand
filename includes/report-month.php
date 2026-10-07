<?php
declare(strict_types=1);
/**
 * Finance → Reports: the monthly report (design C, Oct 2026). Shaped like the
 * reservations team's monthly report (gross → commission → net, extras, the
 * property breakdown, daily occupancy, channels and partners, what is already
 * booked ahead, and the team's own notes).
 *
 * Revenue is counted by STAY NIGHT, the way eZee's "stay date" reports count it:
 * a booking contributes gross × (its nights inside the period ÷ all its nights),
 * so a 28 Aug → 3 Sep stay lands partly in August and partly in September.
 * Commission is spread the same way. Extras (priced guest requests + bill lines,
 * POS room charges excluded — the POS section reports those) belong to the
 * period their stay ARRIVED in.
 *
 * Split into thin DB readers and ONE pure view model (report_model()) so the
 * figures are tested without a database (tests/report_month_logic.php). Money is
 * never summed across currencies: every total is keyed by currency, and the
 * charts use the "primary" currency (the one with the most revenue) and say so.
 * Every read is pre-migration-safe.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bookings.php';

/* ─────────────────────────── Period ─────────────────────────── */

/** Report ranges: key => [label, months covered, months the ‹ › arrows move]. */
const REPORT_RANGES = [
    'month'   => ['Month',     1,  1],
    'quarter' => ['Quarter',   3,  3],
    'year'    => ['Year',      12, 12],
    '12m'     => ['12 months', 12, 1],
];

/**
 * The window a range + anchor month covers. Pure.
 * $anchor 'YYYY-MM' (falls back to $today's month when unreadable).
 * Returns from / to (inclusive last day) / label / short (for the headline) /
 * prev_anchor / next_anchor / cmp_from / cmp_to (the period before, same kind) /
 * key (stable id for the team's notes).
 */
function report_period(string $range, string $anchor, string $today): array {
    if (!isset(REPORT_RANGES[$range])) $range = 'month';
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $anchor, $m)) {
        $anchor = substr($today, 0, 7);
        preg_match('/^(\d{4})-(\d{2})$/', $anchor, $m);
    }
    $y = (int)$m[1]; $mo = (int)$m[2];
    [, $len, $step] = REPORT_RANGES[$range];

    $first = static fn(int $y, int $mo): string => sprintf('%04d-%02d-01', $y, $mo);
    $shift = static function (int $y, int $mo, int $by): array {
        $i = $y * 12 + ($mo - 1) + $by;
        return [intdiv($i, 12), $i % 12 + 1];
    };
    $lastDay = static fn(string $ymd01): string => date('Y-m-t', strtotime($ymd01));

    switch ($range) {
        case 'quarter':
            $qs = intdiv($mo - 1, 3) * 3 + 1;
            $start = [$y, $qs];
            $label = 'Q' . (intdiv($mo - 1, 3) + 1) . ' ' . $y;
            $short = $label;
            break;
        case 'year':
            $start = [$y, 1];
            $label = (string)$y; $short = (string)$y;
            break;
        case '12m':
            $start = $shift($y, $mo, -11);
            $label = date('M Y', strtotime($first(...$start))) . ' – ' . date('M Y', strtotime($first($y, $mo)));
            $short = 'These 12 months';
            break;
        default:
            $start = [$y, $mo];
            $label = date('F Y', strtotime($first($y, $mo)));
            $short = date('F', strtotime($first($y, $mo)));
    }
    $end   = $shift($start[0], $start[1], $len - 1);
    $from  = $first(...$start);
    $to    = $lastDay($first(...$end));
    $cmpS  = $shift($start[0], $start[1], -$len);
    $cmpE  = $shift($start[0], $start[1], -1);
    $prevA = $shift($y, $mo, -$step);
    $nextA = $shift($y, $mo, $step);

    return [
        'range' => $range, 'anchor' => sprintf('%04d-%02d', $y, $mo),
        'from' => $from, 'to' => $to, 'label' => $label, 'short' => $short,
        'prev_anchor' => sprintf('%04d-%02d', ...$prevA), 'next_anchor' => sprintf('%04d-%02d', ...$nextA),
        'cmp_from' => $first(...$cmpS), 'cmp_to' => $lastDay($first(...$cmpE)),
        'key' => $range . ':' . $from,
    ];
}

/** Every Y-m-d from $from to $toIncl (inclusive). Pure. Capped at 400 days. */
function report_days(string $from, string $toIncl): array {
    $out = []; $t = strtotime($from); $end = strtotime($toIncl);
    if ($t === false || $end === false) return [];
    while ($t <= $end && count($out) < 400) { $out[] = date('Y-m-d', $t); $t = strtotime('+1 day', $t); }
    return $out;
}

/* ─────────────────────────── DB readers ─────────────────────────── */

/** Memoised column check (information_schema — safe inside a transaction). */
function report_column_exists(string $table, string $column): bool {
    static $c = [];
    $k = $table . '.' . $column;
    if (isset($c[$k])) return $c[$k];
    try {
        return $c[$k] = (bool) db_query(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema()
                AND table_name = :t AND column_name = :c", [':t' => $table, ':c' => $column]
        )->fetchColumn();
    } catch (Throwable $e) { return $c[$k] = false; }
}

/** ' AND <col> IN (…)' for a scope (null = all). Ints only. */
function report_venue_sql(?array $venueIds, string $col): string {
    if ($venueIds === null) return '';
    $ids = array_values(array_filter(array_map('intval', $venueIds), static fn($i) => $i > 0));
    return $ids ? " AND {$col} IN (" . implode(',', $ids) . ')' : ' AND FALSE';
}

/**
 * Ledger rows with at least one night inside [from, toIncl] (not cancelled),
 * with the property name and whether the room is a whole-property room.
 */
function report_stay_rows(?array $venueIds, string $from, string $toIncl): array {
    if (!bookings_supported()) return [];
    if (is_array($venueIds) && !$venueIds) return [];
    try {
        return db_query(
            "SELECT b.*, v.name AS venue_name, COALESCE(r.is_entire_place, FALSE) AS entire_place
               FROM bookings b
               LEFT JOIN venues v ON v.id = b.venue_id
               LEFT JOIN rooms  r ON r.id = b.room_id
              WHERE b.status <> 'cancelled' AND b.check_in <= :to AND b.check_out > :from"
            . report_venue_sql($venueIds, 'b.venue_id') . "
              ORDER BY b.check_in, b.id",
            [':from' => $from, ':to' => $toIncl]
        )->fetchAll();
    } catch (Throwable $e) { error_log('[reports] stays: ' . $e->getMessage()); return []; }
}

/** Cancelled ledger bookings that would have stayed in the window. */
function report_cancelled_count(?array $venueIds, string $from, string $toIncl): int {
    if (!bookings_supported() || (is_array($venueIds) && !$venueIds)) return 0;
    try {
        return (int) db_query(
            "SELECT COUNT(*) FROM bookings b WHERE b.status = 'cancelled' AND b.check_in <= :to AND b.check_out > :from"
            . report_venue_sql($venueIds, 'b.venue_id'), [':from' => $from, ':to' => $toIncl]
        )->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Enquiries received in the window (website, agents, WhatsApp…), scoped by the enquiry's room when it has one. */
function report_enquiry_count(?array $venueIds, string $from, string $toIncl): ?int {
    if (is_array($venueIds) && !$venueIds) return 0;
    try {
        $scope = $venueIds === null ? ''
            : ' AND s.room_id IN (SELECT id FROM rooms WHERE TRUE' . report_venue_sql($venueIds, 'venue_id') . ')';
        return (int) db_query(
            "SELECT COUNT(*) FROM submissions s WHERE s.type = 'enquiry'
                AND s.created_at >= :from AND s.created_at < (CAST(:to AS date) + 1)" . $scope,
            [':from' => $from, ':to' => $toIncl]
        )->fetchColumn();
    } catch (Throwable $e) { return null; }
}

/**
 * Extras for stays ARRIVING in the window: priced, confirmed/completed guest
 * requests and staff-added bill lines. POS room charges are left out (the POS
 * section reports every till sale). Rows: venue_id, venue_name, kind, amount, currency.
 */
function report_extras_rows(?array $venueIds, string $from, string $toIncl): array {
    if (is_array($venueIds) && !$venueIds) return [];
    $site  = strtoupper(setting('site_currency', 'USD')) ?: 'USD';
    $scope = report_venue_sql($venueIds, 'r.venue_id');
    $out   = [];
    $args  = [':from' => $from, ':to' => $toIncl, ':site' => $site];
    if (report_column_exists('booking_addons', 'price_amount')) {
        $cur = report_column_exists('booking_addons', 'price_currency') ? 'COALESCE(ba.price_currency, :site)' : ':site';
        try {
            foreach (db_query(
                "SELECT r.venue_id, v.name AS venue_name, ba.kind, ba.price_amount AS amount, {$cur} AS currency
                   FROM booking_addons ba
                   JOIN holds h ON h.id = ba.hold_id
                   JOIN units u ON u.id = h.unit_id
                   JOIN rooms r ON r.id = u.room_id
                   LEFT JOIN venues v ON v.id = r.venue_id
                  WHERE ba.status IN ('confirmed','completed') AND ba.price_amount > 0
                    AND h.status = 'confirmed' AND h.check_in BETWEEN :from AND :to{$scope}", $args
            )->fetchAll() as $row) { $out[] = $row; }
        } catch (Throwable $e) { error_log('[reports] addons: ' . $e->getMessage()); }
    }
    try {
        $cur   = report_column_exists('bill_items', 'currency') ? 'COALESCE(bi.currency, :site)' : ':site';
        $noPos = report_column_exists('bill_items', 'pos_sale_id') ? ' AND bi.pos_sale_id IS NULL' : '';
        foreach (db_query(
            "SELECT r.venue_id, v.name AS venue_name, 'bill' AS kind, bi.amount, {$cur} AS currency
               FROM bill_items bi
               JOIN holds h ON h.id = bi.hold_id
               JOIN units u ON u.id = h.unit_id
               JOIN rooms r ON r.id = u.room_id
               LEFT JOIN venues v ON v.id = r.venue_id
              WHERE bi.amount > 0 AND h.status = 'confirmed' AND h.check_in BETWEEN :from AND :to{$noPos}{$scope}", $args
        )->fetchAll() as $row) { $out[] = $row; }
    } catch (Throwable $e) { error_log('[reports] bill: ' . $e->getMessage()); }
    return $out;
}

/**
 * The properties a report shows: published ones in scope plus any with ledger
 * rows (passed in), each with its sellable units (the occupancy denominator) and
 * its hero photo. A property sold only as a whole (My Amani) counts as ONE unit.
 */
function report_venues(?array $venueIds, array $extraIds = []): array {
    if (is_array($venueIds) && !$venueIds) return [];
    $extra = array_values(array_filter(array_map('intval', $extraIds), static fn($i) => $i > 0));
    try {
        $rows = db_query(
            "SELECT v.id, v.name, v.slug, COALESCE(v.location, '') AS town, v.sort_order,
                    (SELECT COUNT(*) FROM units u JOIN rooms r ON r.id = u.room_id
                      WHERE r.venue_id = v.id AND u.is_active = TRUE AND COALESCE(r.is_entire_place, FALSE) = FALSE) AS room_units,
                    (SELECT COUNT(*) FROM units u JOIN rooms r ON r.id = u.room_id
                      WHERE r.venue_id = v.id AND u.is_active = TRUE AND COALESCE(r.is_entire_place, FALSE) = TRUE) AS whole_units
               FROM venues v
              WHERE (v.is_published = TRUE" . ($extra ? ' OR v.id IN (' . implode(',', $extra) . ')' : '') . ')'
            . report_venue_sql($venueIds, 'v.id') . "
              ORDER BY v.sort_order, v.name"
        )->fetchAll();
    } catch (Throwable $e) { error_log('[reports] venues: ' . $e->getMessage()); return []; }

    $photos = [];
    try {
        foreach (db_query(
            "SELECT DISTINCT ON (venue_id) venue_id, filename FROM venue_images
              ORDER BY venue_id, is_hero DESC, sort_order, id"
        )->fetchAll() as $p) { $photos[(int)$p['venue_id']] = storage_url((string)$p['filename']); }
    } catch (Throwable $e) { /* no photos — cards fall back to a tint */ }

    $out = [];
    foreach ($rows as $r) {
        $rooms = (int)$r['room_units'];
        $out[(int)$r['id']] = [
            'id' => (int)$r['id'], 'name' => (string)$r['name'], 'town' => trim((string)$r['town']),
            'units' => $rooms > 0 ? $rooms : ((int)$r['whole_units'] > 0 ? 1 : 0),
            'photo' => $photos[(int)$r['id']] ?? '',
        ];
    }
    return $out;
}

/* ─────────────────────────── Pure model ─────────────────────────── */

/** Label for an extra's kind. */
function report_extra_label(string $kind): string {
    return match ($kind) {
        'tour'       => 'Excursions & activities',
        'transfer'   => 'Transfers',
        'restaurant' => 'Food & drink',
        'laundry'    => 'Laundry',
        'event'      => 'Events',
        'bill'       => 'Other charges on the bill',
        default      => 'Other services',
    };
}

/** Channel groups, in the fixed order (and colour) the page draws them. */
const REPORT_CHANNELS = ['direct' => 'Direct', 'agent' => 'Travel agents', 'ota' => 'OTAs', 'website' => 'Website'];

/**
 * Everything the page draws, from plain rows. Pure.
 *   $stays   report_stay_rows()   — prorated to the window's nights
 *   $extras  report_extras_rows()
 *   $venues  report_venues()      — the cards (in order) + units for occupancy
 */
function report_model(array $stays, array $extras, string $from, string $toIncl, array $venues): array {
    $days    = report_days($from, $toIncl);
    $dayIdx  = array_flip($days);
    $winEnd  = date('Y-m-d', strtotime($toIncl . ' +1 day'));
    $zero    = ['gross' => 0.0, 'commission' => 0.0, 'net' => 0.0, 'extras' => 0.0, 'total' => 0.0, 'nights' => 0, 'stays' => 0];

    $cur = []; $byVenue = []; $chan = []; $partners = []; $xType = []; $xVenue = [];
    foreach ($venues as $vid => $v) {
        $byVenue[$vid] = ['id' => $vid, 'name' => $v['name'], 'town' => $v['town'] ?? '', 'photo' => $v['photo'] ?? '',
                          'units' => (int)($v['units'] ?? 0), 'cur' => [], 'nights' => 0, 'stays' => 0,
                          'sold' => array_fill(0, count($days), 0)];
    }
    $add = static function (array &$bucket, string $c, array $vals) use ($zero): void {
        if (!isset($bucket[$c])) $bucket[$c] = $zero;
        foreach ($vals as $k => $n) $bucket[$c][$k] += $n;
    };

    foreach ($stays as $r) {
        $ci = (string)$r['check_in']; $co = (string)$r['check_out'];
        $all = bookings_night_count($ci, $co);
        $in  = bookings_night_overlap($ci, $co, $from, $toIncl);
        if ($in <= 0 || $all <= 0) continue;
        $share = $in / $all;
        $c     = strtoupper(trim((string)($r['currency'] ?? 'USD'))) ?: 'USD';
        $gross = round((float)($r['gross_amount'] ?? 0) * $share, 2);
        $comm  = round(min((float)($r['commission_amount'] ?? 0), (float)($r['gross_amount'] ?? 0)) * $share, 2);
        $vals  = ['gross' => $gross, 'commission' => $comm, 'net' => $gross - $comm, 'total' => $gross - $comm, 'nights' => $in, 'stays' => 1];
        $add($cur, $c, $vals);

        $vid = (int)($r['venue_id'] ?? 0);
        if (!isset($byVenue[$vid])) {
            $byVenue[$vid] = ['id' => $vid, 'name' => (string)($r['venue_name'] ?? '—') ?: '—', 'town' => '', 'photo' => '',
                              'units' => 0, 'cur' => [], 'nights' => 0, 'stays' => 0, 'sold' => array_fill(0, count($days), 0)];
        }
        $add($byVenue[$vid]['cur'], $c, $vals);
        $byVenue[$vid]['nights'] += $in;
        $byVenue[$vid]['stays']  += 1;

        // Daily occupancy: a whole-property room takes every unit that night.
        $units = $byVenue[$vid]['units'];
        if ($units > 0) {
            $take = !empty($r['entire_place']) && $r['entire_place'] !== 'f' ? $units : 1;
            $t = strtotime(max($ci, $from)); $stop = strtotime(min($co, $winEnd));
            while ($t < $stop) {
                $i = $dayIdx[date('Y-m-d', $t)] ?? null;
                if ($i !== null) $byVenue[$vid]['sold'][$i] = min($units, $byVenue[$vid]['sold'][$i] + $take);
                $t = strtotime('+1 day', $t);
            }
        }

        $src = (string)($r['source'] ?? 'website');
        if (!isset(REPORT_CHANNELS[$src])) $src = 'direct';
        $chan[$c][$src] = ($chan[$c][$src] ?? 0) + $gross;

        $agent = trim((string)($r['agent'] ?? ''));
        if ($agent === '-') $agent = '';
        $name  = $agent !== '' ? $agent : ($src === 'ota' ? trim((string)($r['channel'] ?? '')) : '');
        if ($name !== '' && in_array($src, ['agent', 'ota'], true)) {
            $k = mb_strtolower($name);
            if (!isset($partners[$c][$k])) $partners[$c][$k] = ['name' => $name, 'kind' => $src === 'ota' ? 'OTA' : 'Travel agent', 'amount' => 0.0, 'stays' => 0];
            $partners[$c][$k]['amount'] += $gross;
            $partners[$c][$k]['stays']  += 1;
        }
    }

    foreach ($extras as $x) {
        $c   = strtoupper(trim((string)($x['currency'] ?? 'USD'))) ?: 'USD';
        $amt = round((float)($x['amount'] ?? 0), 2);
        if ($amt <= 0) continue;
        $add($cur, $c, ['extras' => $amt, 'total' => $amt]);
        $vid = (int)($x['venue_id'] ?? 0);
        if (!isset($byVenue[$vid])) {
            $byVenue[$vid] = ['id' => $vid, 'name' => (string)($x['venue_name'] ?? '—') ?: '—', 'town' => '', 'photo' => '',
                              'units' => 0, 'cur' => [], 'nights' => 0, 'stays' => 0, 'sold' => array_fill(0, count($days), 0)];
        }
        $add($byVenue[$vid]['cur'], $c, ['extras' => $amt, 'total' => $amt]);
        $label = report_extra_label((string)($x['kind'] ?? ''));
        $xType[$c][$label]  = ($xType[$c][$label] ?? 0) + $amt;
        $xVenue[$c][$vid]   = ($xVenue[$c][$vid] ?? 0) + $amt;
    }

    // Primary currency = the one with the most revenue; charts use it.
    $primary = '';
    foreach ($cur as $c => $t) { if ($primary === '' || $t['total'] > $cur[$primary]['total']) $primary = $c; }
    ksort($cur);

    // Occupancy per property + the portfolio (sold ÷ available unit-nights).
    $sold = 0; $avail = 0;
    foreach ($byVenue as $vid => $v) {
        $u = $v['units']; $n = count($days);
        $byVenue[$vid]['daily'] = $u > 0 ? array_map(static fn($s) => round($s / $u * 100, 1), $v['sold']) : [];
        $byVenue[$vid]['occ']   = ($u > 0 && $n > 0) ? round(array_sum($v['sold']) / ($u * $n) * 100, 1) : null;
        if ($u > 0) { $sold += array_sum($v['sold']); $avail += $u * $n; }
        unset($byVenue[$vid]['sold']);
    }

    foreach ($partners as $c => $list) {
        usort($list, static fn($a, $b) => $b['amount'] <=> $a['amount']);
        $partners[$c] = array_slice(array_values($list), 0, 5);
    }
    foreach ($xType as $c => $list) { arsort($list); $xType[$c] = $list; }
    foreach ($chan as $c => $list) {
        $ordered = [];
        foreach (array_keys(REPORT_CHANNELS) as $k) { if (!empty($list[$k])) $ordered[$k] = $list[$k]; }
        $chan[$c] = $ordered;
    }

    // Venues without a single figure and no units (unpublished, never booked) drop out.
    // A property with no rooms to sell (e.g. a community venue) only shows when it earned something.
    $byVenue = array_filter($byVenue, static fn($v) => $v['cur'] || (isset($venues[$v['id']]) && $v['units'] > 0));

    return [
        'days' => $days, 'currencies' => $cur, 'primary' => $primary,
        'venues' => $byVenue, 'occupancy' => ['sold' => $sold, 'available' => $avail,
                                              'pct' => $avail > 0 ? round($sold / $avail * 100, 1) : null],
        'channels' => $chan, 'partners' => $partners, 'extras_by_type' => $xType, 'extras_by_venue' => $xVenue,
    ];
}

/**
 * Nights and revenue already booked for each of the next $months months after
 * $fromMonth ('YYYY-MM'), in one currency. Pure. Returns [['ym','nights','gross']].
 */
function report_forward(array $stays, string $fromMonth, int $months, string $currency): array {
    $out = [];
    for ($i = 0; $i < $months; $i++) {
        $f = date('Y-m-01', strtotime($fromMonth . '-01 +' . $i . ' month'));
        $t = date('Y-m-t', strtotime($f));
        $n = 0; $g = 0.0;
        foreach ($stays as $r) {
            if ((strtoupper((string)($r['currency'] ?? '')) ?: 'USD') !== $currency) continue;
            $all = bookings_night_count((string)$r['check_in'], (string)$r['check_out']);
            $in  = bookings_night_overlap((string)$r['check_in'], (string)$r['check_out'], $f, $t);
            if ($in <= 0 || $all <= 0) continue;
            $n += $in; $g += (float)$r['gross_amount'] * $in / $all;
        }
        $out[] = ['ym' => substr($f, 0, 7), 'nights' => $n, 'gross' => round($g, 2)];
    }
    return $out;
}

/** Change vs the period before: ['dir' => up|down|flat, 'label' => '▲ 12%'] or null when there is nothing to compare. Pure. */
function report_delta(float $now, ?float $before): ?array {
    if ($before === null || $before <= 0) return null;
    $p = ($now - $before) / $before * 100;
    if (abs($p) < 0.5) return ['dir' => 'flat', 'label' => 'same as before'];
    return ['dir' => $p > 0 ? 'up' : 'down', 'label' => ($p > 0 ? '▲ ' : '▼ ') . number_format(abs($p), 0) . '%'];
}

/** "KES 10.48M", "KES 550K", "$1,234". Pure. */
function report_money_short(float $amount, string $currency): string {
    $c = strtoupper($currency) ?: 'USD';
    $a = abs($amount);
    if ($a >= 1e6)      $n = rtrim(rtrim(number_format($amount / 1e6, 2), '0'), '.') . 'M';
    elseif ($a >= 1e4)  $n = number_format($amount / 1e3, 0) . 'K';
    else                $n = number_format($amount, 0);
    return $c === 'USD' ? '$' . $n : $c . ' ' . $n;
}

/**
 * The headline sentence and the line under it — written only from the figures. Pure.
 * Returns ['headline' => plain text with the amount wrapped in {{ }}, 'lede' => plain text].
 */
function report_story(array $model, array $period, int $cancelled): array {
    $p = $model['primary'];
    if ($p === '') {
        return ['headline' => 'No revenue recorded for ' . $period['label'] . ' yet.',
                'lede' => 'Bookings appear here once they are confirmed or imported from eZee with their amounts.'];
    }
    $t = $model['currencies'][$p];
    $head = $period['short'] . ' brought in {{' . report_money_short($t['total'], $p) . '}}';
    $lead = null;
    foreach ($model['venues'] as $v) {
        $vt = $v['cur'][$p]['total'] ?? 0;
        if ($vt > 0 && ($lead === null || $vt > $lead[1])) $lead = [$v['name'], $vt];
    }
    $withMoney = count(array_filter($model['venues'], static fn($v) => ($v['cur'][$p]['total'] ?? 0) > 0));
    if ($lead && $withMoney > 1) {
        $share = $lead[1] / $t['total'];
        $head .= ' — ' . $lead[0] . ' earned ' . ($share > .5 ? 'more than half of it' : 'the most, ' . round($share * 100) . '%');
    }
    $head .= '.';

    $bits = [];
    $bits[] = $t['stays'] . ' ' . ($t['stays'] === 1 ? 'stay' : 'stays') . ', ' . $t['nights'] . ' ' . ($t['nights'] === 1 ? 'night' : 'nights')
            . ', ' . ($cancelled === 0 ? 'no cancellations' : $cancelled . ' cancelled') . '.';
    if ($t['extras'] > 0) {
        $top = array_key_first($model['extras_by_type'][$p] ?? []);
        $bits[] = 'Extras added ' . report_money_short($t['extras'], $p) . ' on top of rooms' . ($top ? ', mostly ' . mb_strtolower($top) : '') . '.';
    }
    $empty = array_values(array_map(static fn($v) => $v['name'],
        array_filter($model['venues'], static fn($v) => !$v['cur'] && $v['units'] > 0)));
    if ($empty) $bits[] = report_join($empty) . ' had no bookings.';
    foreach ($model['currencies'] as $c => $o) {
        if ($c !== $p && $o['total'] > 0) $bits[] = 'Plus ' . bookings_money($o['total'], $c) . ' in ' . $c . '.';
    }
    return ['headline' => $head, 'lede' => implode(' ', $bits)];
}

/** "A, B and C". Pure. */
function report_join(array $names): string {
    if (count($names) <= 1) return (string)($names[0] ?? '');
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/* ─────────────────────────── Team notes ─────────────────────────── */

/** Settings key for a period's notes; $venueId 0 = all properties. */
function report_notes_key(string $periodKey, int $venueId): string {
    return 'report_notes:' . $periodKey . ':' . ($venueId > 0 ? $venueId : 'all');
}

/** Clean posted note text into a list of lines (max 12 × 300 chars). Pure. */
function report_note_lines(string $text): array {
    $out = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line) ?? '');
        if ($line !== '') $out[] = mb_substr($line, 0, 300);
        if (count($out) >= 12) break;
    }
    return $out;
}

/** The team's notes for a period: ['highlights','watch','next' => lines, 'by','at']. */
function report_notes_get(string $periodKey, int $venueId): array {
    $blank = ['highlights' => [], 'watch' => [], 'next' => [], 'by' => '', 'at' => ''];
    try { $raw = setting(report_notes_key($periodKey, $venueId), ''); } catch (Throwable $e) { return $blank; }
    $d = json_decode($raw, true);
    if (!is_array($d)) return $blank;
    foreach (['highlights', 'watch', 'next'] as $k) $blank[$k] = array_values(array_filter(array_map('strval', (array)($d[$k] ?? []))));
    $blank['by'] = (string)($d['by'] ?? ''); $blank['at'] = (string)($d['at'] ?? '');
    return $blank;
}

function report_notes_save(string $periodKey, int $venueId, array $post, string $by): void {
    set_setting(report_notes_key($periodKey, $venueId), json_encode([
        'highlights' => report_note_lines((string)($post['highlights'] ?? '')),
        'watch'      => report_note_lines((string)($post['watch'] ?? '')),
        'next'       => report_note_lines((string)($post['next'] ?? '')),
        'by' => mb_substr($by, 0, 120), 'at' => date('Y-m-d H:i'),
    ], JSON_UNESCAPED_UNICODE));
}
