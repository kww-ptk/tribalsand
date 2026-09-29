<?php
declare(strict_types=1);
/**
 * Quote Builder — prices a staff quote across every property, plus activities,
 * transfers and custom lines. Used by admin/quote-builder.php and the enquiry
 * pop-up (admin/submission-view.php) through api/quote-builder.php.
 *
 * Pricing is READ-ONLY: no hold is placed, nothing is sent. The output is text /
 * a printable page that staff send through the normal reply flow. Saving a quote
 * on an enquiry (a snapshot, re-priced here) lives in includes/quote-docs.php.
 *
 * ONE pricing path: room figures come from room_stay_quotes() (the same
 * summation room_stay_quote() — the booking widget's quote — runs), availability
 * from count_available_units() / find_available_unit(). Activities are priced
 * from tours.price_amount (site currency), transfers from service_options (site currency).
 * A client-sent price is used only for custom lines and explicitly edited
 * catalogue prices — staff input by definition.
 *
 * Money: each line keeps its own currency; qb_totals() converts every line to
 * the quote currency with rc_convert() and the quote says when it did.
 * Discount % applies to accommodation only (extras are never discounted).
 *
 * Depends on includes/db.php; requires rates.php, rates-compare.php, services.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/rates-compare.php';
require_once __DIR__ . '/services.php';

const QB_CURRENCIES = ['KES', 'USD'];
const QB_BASES      = ['stay', 'night', 'person'];

/** pdo_pgsql booleans arrive as bool or 't'/'f'. */
function qb_bool($v): bool {
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

/**
 * An extra's label with its basis where it matters: per night → "X × 1 · 4 nights",
 * per person → "X × 2 people"; per stay / trip / transfer → "X × 1".
 */
function qb_extra_label(string $label, int $qty, string $basis, int $nights): string {
    $out = $label . ' × ' . $qty;
    if ($basis === 'night' && $nights > 0) $out .= ' · ' . $nights . ' night' . ($nights === 1 ? '' : 's');
    elseif ($basis === 'person')           $out .= ' ' . ($qty === 1 ? 'person' : 'people');
    return $out;
}

/** An extra's line amount for its basis (unknown basis = per stay). */
function qb_extra_amount(float $unit, int $qty, string $basis, int $nights): float {
    $qty = max(0, $qty);
    $amt = $basis === 'night' ? $unit * max(0, $nights) * $qty : $unit * $qty;
    return round($amt, 2);
}

/**
 * "Venue — Room", without repeating the venue when the room name already starts
 * with it ("Zuri" + "Zuri — Whole Villa" → "Zuri — Whole Villa"). Case-insensitive,
 * trimmed, and only at a word boundary ("Zurich Suite" is not a "Zuri" room).
 */
function qb_room_display_name(string $venue, string $room): string {
    $v = trim($venue); $r = trim($room);
    if ($v === '') return $r;
    $n = mb_strlen($v);
    if (mb_strtolower(mb_substr($r, 0, $n)) === mb_strtolower($v)) {
        $next = mb_substr($r, $n, 1);
        if ($next === '' || !preg_match('/[\p{L}\p{N}]/u', $next)) return $r;
    }
    return $v . ' — ' . $r;
}

/** One line ['amt','cur'] in the quote currency, or null when its currency has no rate. */
function qb_conv_line(array $line, string $cur, array $rates): ?float {
    $v = rc_convert((float)$line['amt'], strtoupper((string)$line['cur']), $cur, $rates);
    return $v === null ? null : round($v, 2);
}

/**
 * Sum room lines and extra lines into the quote currency. Lines are
 * ['amt' => float, 'cur' => 'KES'|'USD'|…] (null entries are skipped).
 * A line whose currency has no rate is NOT summed (never counted as 0 silently):
 * its currency is listed in 'missing' so the caller can say so.
 */
function qb_totals(array $roomLines, array $extraLines, float $discountPct, string $cur, array $rates): array {
    $converted = false;
    $missing   = [];
    $sum = function (array $lines) use ($cur, $rates, &$converted, &$missing): float {
        $t = 0.0;
        foreach ($lines as $l) {
            if ($l === null) continue;
            $from = strtoupper((string)$l['cur']);
            if ($from !== $cur) $converted = true;
            $v = rc_convert((float)$l['amt'], $from, $cur, $rates);
            if ($v === null) { $missing[$from] = true; continue; }
            $t += $v;
        }
        return round($t, 2);
    };
    $acc  = $sum($roomLines);
    $ext  = $sum($extraLines);
    $pct  = max(0.0, min(100.0, $discountPct));
    $disc = round($acc * $pct / 100, 2);
    return [
        'accommodation' => $acc, 'discount' => $disc, 'discount_pct' => $pct, 'extras' => $ext,
        'total' => round($acc - $disc + $ext, 2), 'converted' => $converted, 'missing' => array_keys($missing),
    ];
}

/**
 * Warnings/infos for the summary panel. $picked rows: name, qty, guests,
 * capacity (already × qty), free (int|null), free_exact (false for Maya Ilai
 * composite products, where free means "at least one"). $unpricedExtras = labels.
 * @return list<array{type: string, text: string}>
 */
function qb_notices(array $picked, int $party, bool $okDates, array $unpricedExtras): array {
    $out = [];
    if (!$okDates) $out[] = ['type' => 'warn', 'text' => 'Choose check-in and check-out dates.'];
    $guests = 0; $cap = 0;
    foreach ($picked as $p) {
        $guests += (int)$p['guests'];
        $cap    += (int)$p['capacity'];
        if ((int)$p['capacity'] > 0 && (int)$p['guests'] > (int)$p['capacity']) {
            $out[] = ['type' => 'warn', 'text' => "{$p['name']}: {$p['guests']} guests, sleeps {$p['capacity']}."];
        }
        if ($okDates && $p['free'] !== null) {
            if (empty($p['free_exact'])) {
                if ((int)$p['free'] === 0) $out[] = ['type' => 'warn', 'text' => "{$p['name']}: not free for these dates."];
                // A composite product reports free as yes/no (one villa's worth) — more than one is unverified.
                if ((int)$p['qty'] > 1) $out[] = ['type' => 'warn', 'text' => "{$p['name']}: availability is checked for one at a time — confirm more on the calendar."];
            } elseif ((int)$p['qty'] > (int)$p['free']) {
                $out[] = ['type' => 'warn', 'text' => (int)$p['free'] === 0
                    ? "{$p['name']}: not free for these dates."
                    : "{$p['name']}: only {$p['free']} free for these dates."];
            }
        }
    }
    if ($picked && $party > 0 && $cap > 0 && $cap < $party) {
        $out[] = ['type' => 'warn', 'text' => "The rooms chosen sleep {$cap}; the party is {$party}."];
    }
    if ($picked && $party > 0 && $guests !== $party) {
        $out[] = ['type' => 'info', 'text' => "Guests allocated ({$guests}) differ from the party ({$party})."];
    }
    foreach ($unpricedExtras as $label) $out[] = ['type' => 'warn', 'text' => "{$label}: add a price."];
    return $out;
}

/** "Converted at 1 USD = 129 KES on 29 Sep 2026." */
function qb_fx_note(array $rates, string $cur, string $today): string {
    $kes = (float)($rates['KES'] ?? 0);
    return 'Converted at 1 USD = ' . rc_trimz(number_format($kes, 2, '.', '')) . ' KES on '
         . date('j M Y', strtotime($today)) . '.';
}

/** Money for the quote text, in the quote currency ("KES 48,360", "$375"). */
function qb_fmt(float $amt, string $cur): string {
    return rc_money_text($amt, $cur);
}

/**
 * The copy-and-paste quote. $q: name, check_in, check_out, nights, adults,
 * children, currency, today, rooms[name,qty,mix,amt], extras[label,qty,amt],
 * discount_pct, discount_note, discount, total, fx_note (string|null).
 * Amounts are already in $q['currency'].
 */
function qb_quote_text(array $q): string {
    $c = $q['currency'];
    $party = (int)$q['adults'] . ' adult' . ((int)$q['adults'] === 1 ? '' : 's');
    if ((int)$q['children'] > 0) $party .= ', ' . (int)$q['children'] . ' child' . ((int)$q['children'] === 1 ? '' : 'ren');
    $lines = [];
    $lines[] = 'Tribal Sand — quote' . (trim((string)$q['name']) !== '' ? ' for ' . trim((string)$q['name']) : '');
    if ((int)$q['nights'] > 0) {
        $lines[] = date('j M Y', strtotime($q['check_in'])) . ' → ' . date('j M Y', strtotime($q['check_out']))
                 . ' · ' . (int)$q['nights'] . ' night' . ((int)$q['nights'] === 1 ? '' : 's') . ' · ' . $party;
    }
    if ($q['rooms']) {
        $lines[] = '';
        $lines[] = 'Accommodation';
        foreach ($q['rooms'] as $r) {
            $lines[] = '• ' . $r['name'] . ' × ' . (int)$r['qty'] . ($r['mix'] !== '' ? ' (' . $r['mix'] . ')' : '')
                     . ': ' . qb_fmt((float)$r['amt'], $c);
        }
        if ((float)$q['discount'] > 0) {
            $lines[] = 'Discount ' . rc_trimz(number_format((float)$q['discount_pct'], 2, '.', '')) . '%'
                     . (trim((string)$q['discount_note']) !== '' ? ' (' . trim((string)$q['discount_note']) . ')' : '')
                     . ': −' . qb_fmt((float)$q['discount'], $c);
        }
    }
    if ($q['extras']) {
        $lines[] = '';
        $lines[] = 'Extras';
        foreach ($q['extras'] as $x) {
            $lines[] = '• ' . qb_extra_label((string)$x['label'], (int)$x['qty'], (string)($x['basis'] ?? 'stay'), (int)$q['nights'])
                     . ': ' . qb_fmt((float)$x['amt'], $c);
        }
    }
    $lines[] = '';
    $lines[] = 'Total: ' . qb_fmt((float)$q['total'], $c);
    if (!empty($q['fx_note'])) $lines[] = $q['fx_note'];
    $lines[] = 'Prices valid on ' . date('j M Y', strtotime($q['today'])) . '; subject to availability until booked.';
    return implode("\n", $lines);
}

/**
 * What the builder can quote for an account: published rooms of published
 * venues in scope (null = all), published activities (tours) and active
 * transfers. Memoized per scope for the request.
 *
 * max_qty = the room's active units (a Maya Ilai composite product counts the
 * villa units it allocates from — room_inventory_room_id()); a whole-property
 * room is 0/1; a room with no units (enquiry-only) can still be quoted once.
 */
function qb_catalog(?array $scope): array {
    static $memo = [];
    $key = $scope === null ? 'all' : implode(',', array_map('intval', $scope));
    if (isset($memo[$key])) return $memo[$key];
    $empty = ['rooms' => [], 'tours' => [], 'transfers' => [], 'site_currency' => 'USD'];
    if ($scope !== null && !$scope) return $memo[$key] = $empty;

    $where = 'r.is_published = TRUE AND v.is_published = TRUE';
    if ($scope !== null) $where .= ' AND v.id IN (' . implode(',', array_map('intval', $scope)) . ')';
    $invUnits = [];          // inventory room id => active unit count (composite products share a pool)
    $rooms = db_query(
        "SELECT r.id, r.slug, r.name, r.venue_id, r.capacity, r.price_amount, r.price_currency,
                r.is_entire_place, r.is_published, v.name AS venue_name,
                (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id AND u.is_active = TRUE) AS unit_count
           FROM rooms r JOIN venues v ON v.id = r.venue_id
          WHERE $where
          ORDER BY v.sort_order ASC, v.name ASC, r.sort_order ASC, r.id ASC"
    )->fetchAll();
    foreach ($rooms as &$r) {
        $r['composite'] = mi_is_composite_room($r);
        $inv   = room_inventory_room_id($r);
        $units = $inv === (int)$r['id']
            ? (int)$r['unit_count']
            : ($invUnits[$inv] ??= (int)db_query('SELECT COUNT(*) FROM units WHERE room_id = :r AND is_active = TRUE', [':r' => $inv])->fetchColumn());
        $r['units']   = $units;
        $r['max_qty'] = qb_bool($r['is_entire_place']) ? 1 : max(1, $units);
        $r['price_currency'] = strtoupper((string)($r['price_currency'] ?: 'USD'));
    }
    unset($r);

    $tours = [];
    try {
        $tours = db_query(
            "SELECT id, name, category, price_amount, price_per_person
               FROM tours WHERE is_published = TRUE ORDER BY category ASC, sort_order ASC, name ASC"
        )->fetchAll();
    } catch (Throwable $e) { $tours = []; }
    $transfers = array_map(fn($o) => ['id' => (int)$o['id'], 'label' => (string)$o['label'], 'price_amount' => $o['price_amount']],
                           fetch_service_options('transfer'));

    return $memo[$key] = [
        'rooms' => $rooms, 'tours' => $tours, 'transfers' => $transfers,
        'site_currency' => strtoupper(setting('site_currency', 'USD')),
    ];
}

/** Units free for the stay: a count for ordinary rooms, 1/0 for a composite product; null when the room has no units. */
function qb_free_units(array $room, string $ci, string $co): ?int {
    if (!empty($room['composite'])) return find_available_unit((int)$room['id'], $ci, $co) ? 1 : 0;
    if ((int)$room['units'] === 0) return null;
    return count_available_units((int)$room['id'], $ci, $co, $room);
}

/**
 * Price a builder selection. $sel (from the client, untrusted):
 *   name, check_in, check_out, adults, children, discount_pct, discount_note,
 *   cur ('KES'|'USD'), want_free (bool: dates changed → count free for every room),
 *   rooms: [{id, qty, guests}], extras: [{key, kind: tour|transfer|custom, id,
 *   label, qty, price, edited, price_cur, basis}]
 * Room ids are filtered through the account's catalogue ($scope); catalogue
 * extras must exist; prices are looked up server-side except custom lines and
 * edited catalogue prices.
 */
function qb_price_selection(array $sel, ?array $scope): array {
    $cat   = qb_catalog($scope);
    $rates = fx_rates()['rates'];
    $cur   = in_array($sel['cur'] ?? '', QB_CURRENCIES, true) ? (string)$sel['cur'] : 'KES';
    $ci    = rates_window_ymd((string)($sel['check_in'] ?? ''));
    $co    = rates_window_ymd((string)($sel['check_out'] ?? ''));
    $okDates = $ci !== null && $co !== null && $ci < $co;
    $nights  = $okDates ? max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400)) : 0;
    $adults   = max(0, min(500, (int)($sel['adults'] ?? 0)));
    $children = max(0, min(500, (int)($sel['children'] ?? 0)));
    $party    = $adults + $children;
    $wantFree = !empty($sel['want_free']);
    // Free-text limits, applied once so the breakdown and the copy text agree.
    $qName = trim(mb_substr((string)($sel['name'] ?? ''), 0, 120));
    $qNote = trim(mb_substr((string)($sel['discount_note'] ?? ''), 0, 80));

    $want = [];
    foreach ((array)($sel['rooms'] ?? []) as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $want[$id] = ['qty' => max(0, (int)($r['qty'] ?? 0)), 'guests' => max(0, (int)($r['guests'] ?? 0))];
    }

    $defaults = [];
    foreach ($cat['rooms'] as $room) $defaults[(int)$room['id']] = (float)$room['price_amount'];
    $quotes = ($okDates && $defaults) ? room_stay_quotes($defaults, $ci, $co, true) : [];

    $roomsOut = []; $picked = []; $roomLines = []; $textRooms = []; $unpricedRooms = [];
    foreach ($cat['rooms'] as $room) {
        $id   = (int)$room['id'];
        $c    = $room['price_currency'];
        $w    = $want[$id] ?? ['qty' => 0, 'guests' => 0];
        $qty  = min($w['qty'], (int)$room['max_qty']);
        $q    = $quotes[$id] ?? null;
        $unit = ($q && $q['nights'] > 0) ? (float)$q['total'] : null;
        // No base price and no override covering the stay = unpriced, never quoted at 0.
        $unpricedRoom = $unit !== null && $unit <= 0;
        if ($unpricedRoom) $unit = null;
        $row  = [
            'id' => $id, 'qty' => $qty, 'guests' => $w['guests'],
            'capacity' => (int)$room['capacity'] * max(1, $qty),
            'avg'  => $unit !== null ? ['amt' => round($unit / $nights, 2), 'cur' => $c] : null,
            'line' => ($unit !== null && $qty > 0) ? ['amt' => round($unit * $qty, 2), 'cur' => $c] : null,
            'mix'  => $q ? rc_season_mix($q['nightly']) : '',
        ];
        if ($okDates && ($wantFree || $qty > 0)) {
            $row['free'] = qb_free_units($room, $ci, $co);
            $row['free_exact'] = empty($room['composite']);
        }
        $roomsOut[] = $row;
        if ($qty > 0) {
            $name = qb_room_display_name((string)$room['venue_name'], (string)$room['name']);
            $picked[] = ['name' => $name, 'qty' => $qty, 'guests' => $w['guests'],
                         'capacity' => (int)$room['capacity'] * $qty,
                         'free' => $row['free'] ?? null, 'free_exact' => $row['free_exact'] ?? true];
            $roomLines[] = $row['line'];
            if ($unpricedRoom) $unpricedRooms[] = $name;
            if ($row['line']) $textRooms[] = ['name' => $name, 'venue' => (string)$room['venue_name'], 'room' => (string)$room['name'],
                                              'qty' => $qty, 'mix' => $row['mix'], 'line' => $row['line']];
        }
    }

    $tourById = [];
    foreach ($cat['tours'] as $t) $tourById[(int)$t['id']] = $t;
    $transferById = [];
    foreach ($cat['transfers'] as $t) $transferById[(int)$t['id']] = $t;

    $extrasOut = []; $extraLines = []; $unpriced = []; $needDates = []; $textExtras = [];
    foreach (array_slice((array)($sel['extras'] ?? []), 0, 50) as $x) {
        $key  = substr(preg_replace('/[^a-z0-9_-]/i', '', (string)($x['key'] ?? '')), 0, 20);
        $kind = (string)($x['kind'] ?? '');
        $qtyX = max(0, min(1000, (int)($x['qty'] ?? 0)));
        $sent = isset($x['price']) && is_numeric($x['price']) && (float)$x['price'] >= 0 ? (float)$x['price'] : null;
        $label = null; $unitX = null; $curX = null; $basis = 'stay';
        if ($kind === 'tour' && isset($tourById[(int)($x['id'] ?? 0)])) {
            $t = $tourById[(int)$x['id']];
            $label = (string)$t['name'];
            $basis = qb_bool($t['price_per_person']) ? 'person' : 'stay';
            $curX  = $cat['site_currency'];
            $unitX = !empty($x['edited']) && $sent !== null ? $sent
                   : (is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : $sent);
        } elseif ($kind === 'transfer' && isset($transferById[(int)($x['id'] ?? 0)])) {
            $t = $transferById[(int)$x['id']];
            $label = (string)$t['label'];
            $curX  = $cat['site_currency'];
            $unitX = !empty($x['edited']) && $sent !== null ? $sent
                   : (is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : $sent);
        } elseif ($kind === 'custom') {
            $label = trim(mb_substr((string)($x['label'] ?? ''), 0, 120));
            if ($label === '') $label = 'Custom item';
            $curX  = in_array($x['price_cur'] ?? '', QB_CURRENCIES, true) ? (string)$x['price_cur'] : $cur;
            $basis = in_array($x['basis'] ?? '', QB_BASES, true) ? (string)$x['basis'] : 'stay';
            $unitX = $sent;
        } else {
            continue;                                  // unknown or out-of-catalogue extra: ignored
        }
        // A per-night price needs a stay to multiply by; without dates it is "not yet priced", never × 0.
        $noNights = $basis === 'night' && $nights === 0;
        $line = ($unitX !== null && !$noNights && $qtyX > 0) ? ['amt' => qb_extra_amount($unitX, $qtyX, $basis, $nights), 'cur' => $curX] : null;
        if ($unitX === null) $unpriced[] = $label;
        elseif ($noNights && $qtyX > 0) $needDates[] = $label;
        $extrasOut[] = ['key' => $key, 'label' => $label, 'qty' => $qtyX, 'basis' => $basis,
                        'unit' => $unitX !== null ? ['amt' => $unitX, 'cur' => $curX] : null, 'line' => $line];
        $extraLines[] = $line;
        if ($line) $textExtras[] = ['label' => $label, 'qty' => $qtyX, 'basis' => $basis, 'line' => $line];
    }

    $pct = (float)($sel['discount_pct'] ?? 0);
    $t   = qb_totals($roomLines, $extraLines, $pct, $cur, $rates);
    $capTotal = array_sum(array_column($picked, 'capacity'));
    $notices  = qb_notices($picked, $party, $okDates, $unpriced);
    foreach ($unpricedRooms as $n) $notices[] = ['type' => 'warn', 'text' => "{$n}: no price set for these dates."];
    foreach ($needDates as $n) $notices[] = ['type' => 'warn', 'text' => "{$n}: choose dates for a per-night price."];
    foreach ($t['missing'] as $m) $notices[] = ['type' => 'warn', 'text' => "No exchange rate for {$m}; those lines are left out of the total."];
    $today  = date('Y-m-d');
    $fxNote = $t['converted'] ? qb_fx_note($rates, $cur, $today) : null;

    // A line whose currency has no rate stays out of the breakdown and text (the
    // "No exchange rate" notice already says so) — never printed as 0.
    $convRooms = []; $convExtras = [];
    foreach ($textRooms as $r)  { $v = qb_conv_line($r['line'], $cur, $rates); if ($v !== null) $convRooms[]  = $r + ['conv' => $v]; }
    foreach ($textExtras as $x) { $v = qb_conv_line($x['line'], $cur, $rates); if ($v !== null) $convExtras[] = $x + ['conv' => $v]; }

    $breakdown = [];
    foreach ($convRooms as $r) $breakdown[] = ['kind' => 'room', 'label' => $r['name'] . ' × ' . $r['qty'], 'amt' => $r['conv']];
    if ($t['discount'] > 0) {
        $breakdown[] = ['kind' => 'discount', 'label' => 'Discount ' . rc_trimz(number_format($t['discount_pct'], 2, '.', '')) . '%' . ($qNote !== '' ? " ({$qNote})" : ''), 'amt' => $t['discount']];
    }
    foreach ($convExtras as $x) $breakdown[] = ['kind' => 'extra', 'label' => qb_extra_label($x['label'], $x['qty'], $x['basis'], $nights), 'amt' => $x['conv']];
    $breakdown[] = ['kind' => 'total', 'label' => 'Total', 'amt' => $t['total']];

    $text = qb_quote_text([
        'name' => $qName, 'check_in' => $ci ?? '', 'check_out' => $co ?? '', 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'currency' => $cur, 'today' => $today,
        'rooms'  => array_map(fn($r) => ['name' => $r['name'], 'qty' => $r['qty'], 'mix' => $r['mix'], 'amt' => $r['conv']], $convRooms),
        'extras' => array_map(fn($x) => ['label' => $x['label'], 'qty' => $x['qty'], 'basis' => $x['basis'], 'amt' => $x['conv']], $convExtras),
        'discount_pct' => $t['discount_pct'], 'discount_note' => $qNote,
        'discount' => $t['discount'], 'total' => $t['total'], 'fx_note' => $fxNote,
    ]);

    return [
        'currency' => $cur, 'check_in' => $ci, 'check_out' => $co, 'nights' => $nights,
        'rooms' => $roomsOut, 'extras' => $extrasOut,
        'summary' => [
            'accommodation' => $t['accommodation'], 'discount' => $t['discount'], 'discount_pct' => $t['discount_pct'],
            'extras' => $t['extras'], 'total' => $t['total'],
            'guests' => $party, 'capacity' => $capTotal,
            'per_guest' => $party > 0 && $t['total'] > 0 ? round($t['total'] / $party, 2) : null,
            'nightly'   => $nights > 0 && $t['accommodation'] > 0 ? round($t['accommodation'] / $nights, 2) : null,
        ],
        'lines' => $breakdown, 'notices' => $notices, 'fx_note' => $fxNote, 'text' => $text,
        // For the printable document (includes/quote-docs.php): the same converted
        // lines the text uses, with the property and room kept apart.
        'name' => $qName, 'adults' => $adults, 'children' => $children, 'issued' => $today,
        'quote_rooms'  => array_map(fn($r) => ['venue' => $r['venue'], 'room' => $r['room'], 'name' => $r['name'],
                                               'qty' => $r['qty'], 'mix' => $r['mix'], 'amt' => $r['conv']], $convRooms),
        'quote_extras' => array_map(fn($x) => ['label' => qb_extra_label($x['label'], $x['qty'], $x['basis'], $nights),
                                               'amt' => $x['conv']], $convExtras),
    ];
}
