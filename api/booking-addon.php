<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$str  = fn($v) => is_scalar($v) ? trim((string)$v) : '';

$hold = resolve_booking_by_ref($str($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
if (!in_array($hold['status'], ['pending','confirmed'], true)) {
    http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'This booking can no longer take additions.']));
}

// Rate limit — max 10 add-on requests per hold / 10 min
$cnt = db_query("SELECT COUNT(*) c FROM booking_addons WHERE hold_id=:h AND created_at > NOW() - INTERVAL '10 minutes'",
    [':h'=>$hold['id']])->fetch()['c'];
if ((int)$cnt >= 10) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many requests. Please wait a few minutes.'])); }

// Validate
$kind = $str($data['kind'] ?? '');
if (!in_array($kind, ['tour','transfer','itinerary','other',
                      'housekeeping','amenities','maintenance','restaurant','laundry'], true)) {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown add-on type.']));
}
$details = $str($data['details'] ?? '');
$tour_id = null;
$priceSnapshot = null; // set for laundry/transfer/tour from the catalog
$paxValue      = null; // set for tour
$schedOverride = null; // tour date → scheduled_for (set after the generic sched block)

if ($kind === 'tour') {
    $slug = $str($data['tour_slug'] ?? '');
    $tour = $slug ? fetch_tour_for_booking($slug, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : false;
    if (!$tour) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That activity isn’t available for your stay.'])); }
    $tour_id = (int)$tour['id'];
    $cap = (int)($tour['max_pax'] ?? 0);
    $pax = (int)($data['pax'] ?? 1); if ($pax < 1) $pax = 1; if ($cap > 0 && $pax > $cap) $pax = $cap;
    $atDate = $str($data['at_date'] ?? '');
    $ts = $atDate !== '' ? strtotime($atDate) : false;
    if ($ts === false || $atDate < (string)$hold['check_in'] || $atDate > (string)$hold['check_out']) {
        http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a date within your stay.']));
    }
    $paxValue      = $pax;
    $schedOverride = date('Y-m-d H:i:s', $ts);
    $priceSnapshot = activity_price_total($tour, $pax);
    $note = $str($data['details'] ?? '');
    $details = $tour['name'] . ' · ' . $pax . ' pax' . ($note !== '' ? ' — ' . $note : '');
} elseif ($kind === 'transfer' || $kind === 'laundry') {
    $optId = (int)($data[$kind === 'laundry' ? 'service' : 'transfer'] ?? 0);
    $opt   = fetch_service_option($optId);
    if (!$opt || $opt['service'] !== $kind || !$opt['is_active']) {
        http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a valid option.']));
    }
    $label   = $opt['label'];
    $details = $details === '' ? $label : "{$label} — {$details}";
    $priceSnapshot = (float)$opt['price_amount'];
} else { // itinerary / other
    if ($details === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please add a few details.'])); }
}

$sched = $str($data['scheduled_for'] ?? '');
$schedSql = null;
if ($sched !== '') {
    $ts = strtotime($sched);
    if ($ts !== false) $schedSql = date('Y-m-d H:i:s', $ts); // silently ignore an unparseable value
}
if ($schedOverride !== null) $schedSql = $schedOverride; // tour date wins over any preferred-time field

try {
    $addonId = insert_booking_addon([
        'hold_id'      => $hold['id'],
        'kind'         => $kind,
        'tour_id'      => $tour_id,
        'details'      => $details,
        'scheduled_for'=> $schedSql,
        'price_amount' => $priceSnapshot,
        'pax'          => $paxValue,
    ]);

    // Auto-start a conversation for this request so guest + staff manage it in one place.
    // Never fail the request if the messages table is unavailable.
    $redirect = null;
    try {
        seed_request_message((int)$hold['id'], $addonId, $details);
        $ref = make_guest_ref((int)$hold['id']); // re-sign; never trust the posted ref for a URL
        $redirect = '/booking.php?ref=' . urlencode($ref) . '&view=messages&thread=' . $addonId;
    } catch (Throwable $e) {
        error_log('[booking-addon] thread seed failed: ' . $e->getMessage());
    }

    if (function_exists('send_addon_request_notification')) {
        send_addon_request_notification($hold, ['kind'=>$kind,'details'=>$details]);
    }
    echo json_encode(['ok'=>true, 'redirect'=>$redirect]);
} catch (Throwable $e) {
    error_log('[booking-addon] failed: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save your request. Please try again.']);
}
