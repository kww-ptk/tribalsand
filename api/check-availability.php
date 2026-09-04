<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

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

    // Same resolver as room_stay_quote() and the admin rates calendar.
    // Overridden nights only — the sum below falls back to $default_price for
    // every night this map does not hold.
    $rate_by_night = [];
    foreach (rates_nightly_map((int)$room['id'], (float)$room['price_amount'], $check_in, $check_out) as $ymd => $n) {
        if ($n['is_override']) $rate_by_night[$ymd] = $n['price'];
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

// Build list of dates that have a price override (so the JS can mark them).
// Same resolver as the quote above, clamped to the calendar window.
$rate_dates_map = [];
foreach (rates_nightly_map((int)$room['id'], (float)$room['price_amount'], $from, $to) as $ymd => $n) {
    if ($n['is_override']) $rate_dates_map[$ymd] = true;
}

exit(json_encode([
    'fully_blocked' => get_room_blocked_dates($room['id'], $from, $to),
    'rate_dates'    => array_keys($rate_dates_map),
    'price'         => (float)$room['price_amount'],
    'currency'      => $room['price_currency'],
    'price_unit'    => $room['price_unit'],
]));
