<?php
/**
 * Guest itinerary — add / delete personal plan items (no service request, no Turnstile).
 * Ref-gated + rate-limited. Guests may only touch items they created (created_by='guest').
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$str  = fn($v) => is_scalar($v) ? trim((string)$v) : '';

$hold = resolve_booking_by_ref($str($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }

$action = $str($data['action'] ?? 'add');

if ($action === 'delete') {
    $id = (int)($data['item_id'] ?? 0);
    if ($id <= 0) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Missing item.'])); }
    db_query("DELETE FROM itinerary_items WHERE id=:i AND hold_id=:h AND created_by='guest'", [':i'=>$id, ':h'=>$hold['id']]);
    echo json_encode(['ok'=>true]); exit;
}

// ── add ──
// Guests choose from a friendly subset of categories.
$GUEST_CATS = ['activity'=>'Activity','transfer'=>'Transfer','dining'=>'Restaurant','note'=>'Other'];
$cat   = $str($data['category'] ?? '');
$title = $str($data['title'] ?? '');
$detail= $str($data['detail'] ?? '');
$day   = $str($data['day'] ?? '');
$atRaw = $str($data['at_time'] ?? '');
$at    = preg_match('/^\d{2}:\d{2}$/', $atRaw) ? $atRaw : null;

if (!isset($GUEST_CATS[$cat])) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Pick a category.'])); }
if ($title === '')             { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Add a title.'])); }
// Day must fall within the stay (inclusive).
$inRange = $day !== '' && $day >= (string)$hold['check_in'] && $day <= (string)$hold['check_out']
           && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
if (!$inRange) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Pick a day within your stay.'])); }

// Rate limit: max 30 personal items per hold / 10 min.
$cnt = (int)db_query("SELECT COUNT(*) FROM itinerary_items WHERE hold_id=:h AND created_by='guest' AND created_at > NOW() - INTERVAL '10 minutes'", [':h'=>$hold['id']])->fetchColumn();
if ($cnt >= 30) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many additions. Please wait a few minutes.'])); }

try {
    db_query(
        "INSERT INTO itinerary_items (hold_id, day, at_time, category, title, detail, created_by)
         VALUES (:h, :d, :t, :c, :ti, :de, 'guest')",
        [':h'=>$hold['id'], ':d'=>$day, ':t'=>$at, ':c'=>$cat, ':ti'=>$title, ':de'=>($detail !== '' ? $detail : null)]
    );
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[itinerary] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not add to your plan. Please try again.']);
}
