<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$slug = trim($_GET['room'] ?? '');
if (!$slug) {
    http_response_code(400);
    exit(json_encode(['error' => 'room parameter required']));
}

$room = fetch_room_by_slug($slug);
if (!$room) {
    http_response_code(404);
    exit(json_encode(['error' => 'Room not found']));
}

$check_in  = trim($_GET['check_in']  ?? '');
$check_out = trim($_GET['check_out'] ?? '');

// ── Specific date check (used when guest selects a range) ────────
if ($check_in && $check_out) {
    if ($check_in >= $check_out) {
        http_response_code(422);
        exit(json_encode(['error' => 'Check-out must be after check-in']));
    }

    $unit = find_available_unit($room['id'], $check_in, $check_out);

    $nights = max(1, (int)((strtotime($check_out) - strtotime($check_in)) / 86400));

    // Fetch all rates overlapping this range, build per-night price lookup
    // (multiple rates can cover different nights within the same stay)
    $overlap_rates = db_query(
        "SELECT date_from, date_to, price_amount FROM rates
         WHERE room_id = :rid AND date_from < :co AND date_to > :ci
         ORDER BY created_at DESC",
        [':rid' => $room['id'], ':ci' => $check_in, ':co' => $check_out]
    )->fetchAll();

    $rate_by_night = [];
    foreach ($overlap_rates as $r) {
        $rd = new DateTime($r['date_from']);
        $re = new DateTime($r['date_to']);
        while ($rd < $re) {
            $key = $rd->format('Y-m-d');
            if (!isset($rate_by_night[$key])) $rate_by_night[$key] = (float)$r['price_amount'];
            $rd->modify('+1 day');
        }
    }

    $default_price = (float)$room['price_amount'];
    $total = 0.0;
    $d = new DateTime($check_in);
    $end_dt = new DateTime($check_out);
    while ($d < $end_dt) {
        $total += $rate_by_night[$d->format('Y-m-d')] ?? $default_price;
        $d->modify('+1 day');
    }

    exit(json_encode([
        'available'       => (bool)$unit,
        'price_per_night' => round($total / $nights, 2),
        'currency'        => $room['price_currency'],
        'price_unit'      => $room['price_unit'],
        'nights'          => $nights,
        'total'           => round($total, 2),
    ]));
}

// ── Calendar view: return fully-blocked dates + rate-override dates ─
$from = date('Y-m-d');
$to   = date('Y-m-d', strtotime('+18 months'));

// Build list of dates that have a price override (so the JS can mark them)
$rate_rows = db_query(
    "SELECT date_from, date_to FROM rates
     WHERE room_id = :rid AND date_to > :from AND date_from < :to",
    [':rid' => $room['id'], ':from' => $from, ':to' => $to]
)->fetchAll();

$rate_dates_map = [];
foreach ($rate_rows as $r) {
    $d   = new DateTime(max($r['date_from'], $from));
    $end = new DateTime(min($r['date_to'],   $to));
    while ($d < $end) { $rate_dates_map[$d->format('Y-m-d')] = true; $d->modify('+1 day'); }
}

exit(json_encode([
    'fully_blocked' => get_room_blocked_dates($room['id'], $from, $to),
    'rate_dates'    => array_keys($rate_dates_map),
    'price'         => (float)$room['price_amount'],
    'currency'      => $room['price_currency'],
    'price_unit'    => $room['price_unit'],
]));
