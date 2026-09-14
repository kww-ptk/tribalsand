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
    // Public endpoint: validate before quoting. A string compare alone lets a
    // non-canonical date like '2099-9-01' through, and it sorts ABOVE
    // '2099-09-15' — which used to mis-clamp the rate window and quote override
    // nights the rate never covered.
    $check_in  = rates_window_ymd($check_in)  ?? '';
    $check_out = rates_window_ymd($check_out) ?? '';
    if ($check_in === '' || $check_out === '') {
        http_response_code(422);
        exit(json_encode(['error' => 'Dates must be valid and formatted YYYY-MM-DD']));
    }
    if ($check_in >= $check_out) {
        http_response_code(422);
        exit(json_encode(['error' => 'Check-out must be after check-in']));
    }

    $unit = find_available_unit($room['id'], $check_in, $check_out);

    // One quoting path for the whole app. This endpoint used to re-implement
    // room_stay_quote() line for line; two summations over the same nightly map
    // is exactly how two guests end up quoted two prices for one night.
    $quote  = room_stay_quote((int)$room['id'], (float)$room['price_amount'], $check_in, $check_out);
    $nights = $quote['nights'];
    $total  = $quote['total'];

    exit(json_encode([
        'available'       => (bool)$unit,
        'price_per_night' => round($total / $nights, 2),
        'currency'        => $room['price_currency'],
        'price_unit'      => $room['price_unit'],
        'nights'          => $nights,
        'total'           => round($total, 2),
    ]));
}

/**
 * ── Reachable stay length (check-in chosen, check-out not yet) ────
 *
 * Availability is a property of a STAY, not of a night. `fully_blocked` below
 * answers the per-night question, so a guest can pick two green nights that no
 * single unit spans and only find out on submit. This branch tells the widget
 * how long a stay can actually start on the chosen date, so it can grey out
 * what it cannot sell instead of failing after the fact.
 *
 * Validated exactly as the two-date branch above: an unparseable window is not
 * a 0-night answer, it is a 422.
 */
$max_stay_cap = 30;
if ($check_in && !$check_out) {
    $ci = rates_window_ymd($check_in) ?? '';
    if ($ci === '') {
        http_response_code(422);
        exit(json_encode(['error' => 'Dates must be valid and formatted YYYY-MM-DD']));
    }
    // A check-in in the past is not a stay, so it must not drive the search at
    // all: this branch is a public, unauthenticated GET with no Turnstile and no
    // rate limit, and each call runs a binary search over the availability
    // tables. Nothing can be booked for a past date anyway, so answering 0
    // without touching the database is both cheaper and more honest than
    // probing. "Today" is Nairobi-local — includes/db.php sets the default
    // timezone and the connection's TIME ZONE to Africa/Nairobi, so this
    // comparison agrees with the database's own idea of the date.
    $past = $ci < date('Y-m-d');

    exit(json_encode([
        'max_nights' => $past ? 0 : room_max_stay_nights((int)$room['id'], $ci, $max_stay_cap),
        'check_in'   => $ci,
        // The cap, so the client can tell "5 nights and then it stops" (worth
        // explaining to the guest) from "at least 30" (no constraint to explain)
        // without hard-coding a copy of this number in JavaScript.
        'cap'        => $max_stay_cap,
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
