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
                      'housekeeping','amenities','maintenance','restaurant','laundry','event'], true)) {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown add-on type.']));
}
$details = $str($data['details'] ?? '');
$tour_id = null;
$priceSnapshot = null; // set for laundry/transfer/tour from the catalog
$paxValue      = null; // set for tour
$schedOverride = null; // tour date → scheduled_for (set after the generic sched block)
$threadBody    = null; // richer opening message for the request thread (tours)
$boardPostId   = null; // set for event joins → links the addon to its board post

if ($kind === 'tour') {
    $slug = $str($data['tour_slug'] ?? '');
    $tour = $slug ? fetch_tour_for_booking($slug, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : false;
    if (!$tour) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That activity isn’t available for your stay.'])); }
    $tour_id = (int)$tour['id'];
    $cap = (int)($tour['max_pax'] ?? 0);
    $pax = (int)($data['pax'] ?? 1); if ($pax < 1) $pax = 1; if ($cap > 0 && $pax > $cap) $pax = $cap;
    $atDate = $str($data['at_date'] ?? '');
    $ts   = $atDate !== '' ? strtotime($atDate) : false;
    $norm = $ts !== false ? date('Y-m-d', $ts) : ''; // compare the normalized date we actually store
    if ($ts === false || $norm < (string)$hold['check_in'] || $norm > (string)$hold['check_out']) {
        http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a date within your stay.']));
    }
    $paxValue      = $pax;
    $schedOverride = date('Y-m-d H:i:s', $ts);
    $priceSnapshot = activity_price_total($tour, $pax);
    $note = $str($data['details'] ?? '');
    // Stored details holds only the note — addon_label() already shows the tour
    // name and the admin views add "· N pax", so keep those out of details to
    // avoid duplication. The thread gets the full human-readable line.
    $details = $note;
    $threadBody = $tour['name'] . ' · ' . $pax . ' pax' . ($note !== '' ? ' — ' . $note : '');
} elseif ($kind === 'transfer' || $kind === 'laundry') {
    $optId = (int)($data[$kind === 'laundry' ? 'service' : 'transfer'] ?? 0);
    $opt   = fetch_service_option($optId);
    if (!$opt || $opt['service'] !== $kind || !$opt['is_active']) {
        http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a valid option.']));
    }
    $label   = $opt['label'];
    $details = $details === '' ? $label : "{$label} — {$details}";
    $priceSnapshot = (float)$opt['price_amount'];
} elseif ($kind === 'event') {
    $postId = (int)($data['board_post_id'] ?? 0);
    $ev = $postId ? fetch_board_event($postId, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : false;
    if (!$ev) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That event isn’t available.'])); }
    if (guest_joined_event((int)$hold['id'], $postId)) { http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'You’ve already requested to join this.'])); }
    $boardPostId   = $postId;
    $details       = 'Join: ' . $ev['title'];
    if (!empty($ev['event_date'])) { $schedOverride = date('Y-m-d H:i:s', strtotime((string)$ev['event_date'])); }
    $priceSnapshot = ($ev['price_amount'] === null || $ev['price_amount'] === '' || (float)$ev['price_amount'] <= 0) ? null : (float)$ev['price_amount']; // matches the portal's "free" test (> 0)
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

// Auto-route by job type: a housekeeping/maintenance/transfer request lands on
// the matching on-site specialist's My Work queue when there's exactly one at
// this property. Otherwise it stays unassigned for the manager to route.
$assignee = default_assignee_for($kind, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null);

try {
    $addonId = insert_booking_addon([
        'hold_id'      => $hold['id'],
        'kind'         => $kind,
        'tour_id'      => $tour_id,
        'details'      => $details,
        'scheduled_for'=> $schedSql,
        'price_amount' => $priceSnapshot,
        'pax'          => $paxValue,
        'board_post_id'=> $boardPostId,
        'assigned_to'  => $assignee,
    ]);

    // Auto-start a conversation for this request so guest + staff manage it in one place.
    // Never fail the request if the messages table is unavailable.
    $redirect = null;
    try {
        seed_request_message((int)$hold['id'], $addonId, $threadBody ?? $details);
        $ref = make_guest_ref((int)$hold['id']); // re-sign; never trust the posted ref for a URL
        $redirect = '/booking.php?ref=' . urlencode($ref) . '&view=messages&thread=' . $addonId;
    } catch (Throwable $e) {
        error_log('[booking-addon] thread seed failed: ' . $e->getMessage());
    }

    if (function_exists('send_addon_request_notification')) {
        send_addon_request_notification($hold, ['kind'=>$kind,'details'=>$threadBody ?? $details]);
    }
    echo json_encode(['ok'=>true, 'redirect'=>$redirect]);
} catch (Throwable $e) {
    error_log('[booking-addon] failed: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save your request. Please try again.']);
}
