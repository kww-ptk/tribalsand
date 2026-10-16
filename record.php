<?php
/**
 * Printable signed-consent record for one guest — the electronic registration
 * evidence document. Served from a clean, guest-facing URL (NOT /admin/) so the
 * generated PDF never carries an internal backend route; the record is keyed by
 * a controlled reference ID (?r=TSR-…). Auth is unchanged: the guest's own token
 * (lead ref = any guest on the hold; co-guest g = own row only), else an admin
 * session. Legacy ?hold=&guest= params still resolve for existing links.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/checkin.php';

// A visitor's signed record (general registration link): same clean URL, own body.
if (isset($_GET['vp']) || isset($_GET['vid'])) { require __DIR__ . '/includes/visitor-record.php'; exit; }

$reference = trim((string)($_GET['r'] ?? ''));
$holdId    = (int)($_GET['hold'] ?? 0);
$guestId   = (int)($_GET['guest'] ?? 0);
$ref       = trim((string)($_GET['ref'] ?? ''));
$gTok      = trim((string)($_GET['g'] ?? ''));

if (!checkin_signature_supported()) { http_response_code(503); exit('Run the add_checkin_signature.sql migration first.'); }

// A controlled reference resolves to its hold/guest when raw ids were not passed.
if ($reference !== '' && (!$holdId || !$guestId) && checkin_reference_supported()) {
    $r = db_query('SELECT hold_id, id FROM checkin_guests WHERE waiver_reference = :r', [':r'=>$reference])->fetch();
    if ($r) { $holdId = (int)$r['hold_id']; $guestId = (int)$r['id']; }
}

// Auth: guest token (lead ref = any guest on the hold; co-guest g = own row only), else admin session.
$viewer = 'guest';
if ($ref !== '' && verify_guest_ref($ref) === $holdId) {
    // lead — PII Policy A: may view any guest on their hold
} elseif ($gTok !== '' && ($gc = verify_guest_pass_token($gTok)) !== false && $gc[0] === $holdId && $gc[1] === $guestId) {
    // co-guest — own record only
} else {
    require_login();
    if (!can_view_guest_docs($holdId)) { http_response_code(403); exit('Forbidden'); }
    $viewer = 'admin';
}
if (!$holdId || !$guestId) { http_response_code(400); exit('Bad request.'); }

$hold  = fetch_hold_for_guest($holdId);
$guest = db_query('SELECT * FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetch();
if (!$hold || !$guest)                     { http_response_code(404); exit('Record not found.'); }
if (!checkin_guest_waiver_signed($guest))  { http_response_code(404); exit('This guest has not signed the waiver yet.'); }

// Controlled identifier for this record (minted on first view for older records).
$reference = checkin_ensure_reference($guest);

audit_log('checkin.consent_view', 'hold', $holdId, 'guest ' . $guestId);
checkin_log_signing_step('record_viewed', [
    'reference'      => $reference,
    'hold_id'        => $holdId,
    'guest_id'       => $guestId,
    'waiver_version' => (string)($guest['waiver_version'] ?? ''),
    'personal_link'  => (string)($guest['waiver_signed_link'] ?? ''),
    'method'         => $viewer,
    'detail'         => 'Record viewed',
]);

// One renderer for the page (also used by the monthly ZIP). Identity comes from the
// frozen at-signing snapshots; the scan is shown to staff only, never on a guest link.
require_once __DIR__ . '/includes/waiver-record.php';
$m   = waiver_record_model_guest($hold, $guest, $reference);
$opt = ['scan' => 'none'];
if ($viewer === 'admin') {
    $opt = ['scan' => 'url', 'scan_url' => '/admin/checkin-file.php?hold=' . $holdId . '&guest=' . $guestId . '&signed=1'];
}
echo waiver_record_html($m, $opt);
