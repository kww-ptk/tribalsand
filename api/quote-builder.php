<?php
declare(strict_types=1);
/**
 * Quote Builder (JSON).
 *   POST {csrf_token, sel}                              → {ok, quote}   (action 'price', the default)
 *   POST {csrf_token, action:'save', submission_id, sel} → {ok, saved}
 *
 * 'price' is READ-ONLY — prices a selection with the booking engine's own
 * resolvers and returns lines, totals, notices and the quote text.
 * 'save' stores the quote as the enquiry's next option (qb_quote_save(): the
 * server re-prices, never trusts client figures) — needs the enquiry in scope
 * (submission_in_scope()) and the add_submission_quotes migration.
 * Guards: signed-in admin session; Bookings audience (owner or reception, the
 * same rule as require_bookings()); CSRF token in the JSON body (verify_csrf()
 * reads $_POST, which a JSON fetch does not fill) and the session token must be
 * non-empty (hash_equals('', '') is true); rooms scoped by admin_venue_ids().
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-builder.php';
require_once __DIR__ . '/../includes/quote-docs.php';

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

$action = (string)($data['action'] ?? 'price');
$sel    = is_array($data['sel'] ?? null) ? $data['sel'] : [];

if ($action === 'suggest') {
    // Read-only: what each property in the account's catalogue can offer for the
    // dates + party (includes/quote-suggest.php → ts_property_configurations()).
    require_once __DIR__ . '/../includes/quote-suggest.php';
    $ci = rates_window_ymd((string)($sel['check_in'] ?? ''));
    $co = rates_window_ymd((string)($sel['check_out'] ?? ''));
    $party = max(0, min(500, (int)($sel['adults'] ?? 0))) + max(0, min(500, (int)($sel['children'] ?? 0)));
    $err = match (true) {
        $ci === null || $co === null => 'Pick the check-in and check-out dates first.',
        $ci >= $co                   => 'Check-out must be after check-in.',
        (strtotime($co) - strtotime($ci)) / 86400 > 60 => 'Suggestions cover stays of up to 60 nights.',
        $party < 1                   => 'Add the number of guests first.',
        default                      => null,
    };
    if ($err !== null) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => $err])); }
    $prefer = (int)($data['prefer_venue'] ?? 0) ?: null;   // ordering only — scope comes from the session
    try {
        $groups = qb_suggestions($ci, $co, $party, admin_venue_ids(), $prefer);
    } catch (Throwable $e) {
        error_log('[quote-builder suggest] ' . $e->getMessage());
        http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'Could not load suggestions right now.']));
    }
    exit(json_encode(['ok' => true, 'party' => $party, 'nights' => (int)round((strtotime($co) - strtotime($ci)) / 86400),
                      'groups' => $groups], JSON_UNESCAPED_UNICODE));
}

if ($action === 'save') {
    $sid = (int)($data['submission_id'] ?? 0);
    if (!qb_quotes_supported()) {
        http_response_code(409); exit(json_encode(['ok' => false, 'error' => 'Saving quotes needs the add_submission_quotes migration.']));
    }
    if ($sid <= 0 || !submission_in_scope($sid)) {
        http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Enquiry not found.']));
    }
    try {
        $s = qb_quote_save($sid, (int)($_SESSION['admin_id'] ?? 0) ?: null, $sel, admin_venue_ids());
        $by = trim((string)($__admin['name'] ?? '')) ?: (string)($__admin['email'] ?? '');
        echo json_encode(['ok' => true, 'saved' => [
            'id' => $s['id'], 'option_no' => $s['option_no'], 'ref' => $s['ref'], 'text' => $s['text'],
            'total' => $s['total'], 'currency' => $s['currency'], 'total_text' => qb_fmt($s['total'], $s['currency']),
            'created' => date('j M Y, H:i', strtotime($s['created'])), 'by' => $by,
            'pdf_url' => '/admin/quote-print.php?quote=' . $s['id'],
        ]]);
    } catch (QbQuoteRefusal $e) {
        http_response_code(422); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[quote-builder] save: ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Could not save this quote. Try again.']);
    }
    exit;
}

try {
    echo json_encode(['ok' => true, 'quote' => qb_price_selection($sel, admin_venue_ids())]);
} catch (Throwable $e) {
    error_log('[quote-builder] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not price this quote. Try again.']);
}