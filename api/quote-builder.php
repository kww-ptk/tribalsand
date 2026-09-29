<?php
declare(strict_types=1);
/**
 * Quote Builder pricing (JSON). POST {csrf_token, sel} → {ok, quote}.
 *
 * READ-ONLY — prices a selection with the booking engine's own resolvers and
 * returns lines, totals, notices and the quote text. Nothing is saved.
 * Guards: signed-in admin session; Bookings audience (owner or reception, the
 * same rule as require_bookings()); CSRF token in the JSON body (verify_csrf()
 * reads $_POST, which a JSON fetch does not fill) and the session token must be
 * non-empty (hash_equals('', '') is true); rooms scoped by admin_venue_ids().
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-builder.php';

header('Content-Type: application/json');

// Same rule as require_login(): the account must still exist and be active.
$__admin = current_admin();
if (!$__admin || (array_key_exists('is_active', $__admin) && !$__admin['is_active'])) {
    http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'Your session expired. Sign in again.']));
}
if (!is_owner() && !is_reception()) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Only the owner and reception can build quotes.'])); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed'])); }

$data  = json_decode((string)file_get_contents('php://input'), true) ?? [];
$token = (string)($data['csrf_token'] ?? '');
$sess  = (string)($_SESSION['csrf_token'] ?? '');
if ($sess === '' || !hash_equals($sess, $token)) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Your session token expired. Reload the page.']));
}

try {
    $sel = is_array($data['sel'] ?? null) ? $data['sel'] : [];
    echo json_encode(['ok' => true, 'quote' => qb_price_selection($sel, admin_venue_ids())]);
} catch (Throwable $e) {
    error_log('[quote-builder] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not price this quote. Try again.']);
}