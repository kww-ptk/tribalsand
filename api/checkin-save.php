<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/mail.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$ref    = trim((string)($_POST['ref'] ?? ''));
$holdId = verify_guest_ref($ref);
if (!$holdId) { http_response_code(403); exit('Invalid booking reference.'); }
verify_csrf();

$hold = fetch_hold_for_guest($holdId);
if (!$hold || !checkin_required($hold)) { header('Location: /booking.php?ref=' . urlencode($ref)); exit; }
// Editable until the arrival day; locked afterward.
if (checkin_is_complete($hold) && strtotime((string)$hold['check_in']) < strtotime('today')) {
    header('Location: /booking.php?ref=' . urlencode($ref) . '&view=home'); exit;
}

// ── Upsert booking-level fields ────────────────────────────────────────────
$s = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;
$arrivalAt = ($_POST['arrival_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime((string)$_POST['arrival_at'])) : null;
$needsTransfer = array_key_exists('needs_transfer', $_POST) && $_POST['needs_transfer'] !== ''
    ? ($_POST['needs_transfer'] === '1') : null;

db_query(
    "INSERT INTO booking_checkin (hold_id, arrival_airport, flight_number, arrival_at, needs_transfer, transfer_details, dietary, special_requests, updated_at)
     VALUES (:h,:aa,:fn,:at,:nt,:td,:di,:sr, now())
     ON CONFLICT (hold_id) DO UPDATE SET
       arrival_airport=:aa, flight_number=:fn, arrival_at=:at, needs_transfer=:nt,
       transfer_details=:td, dietary=:di, special_requests=:sr, updated_at=now()",
    [':h'=>$holdId, ':aa'=>$s('arrival_airport'), ':fn'=>$s('flight_number'), ':at'=>$arrivalAt,
     ':nt'=>$needsTransfer, ':td'=>$s('transfer_details'), ':di'=>$s('dietary'), ':sr'=>$s('special_requests')]
);

// ── Upsert lead guest identity (file key handled by the upload endpoint) ───
$lead = checkin_lead_guest($holdId);
if ($lead) {
    db_query("UPDATE checkin_guests SET passport_name=:n, passport_number=:num, nationality=:nat, passport_expiry=:exp WHERE id=:id",
        [':n'=>$s('passport_name'), ':num'=>$s('passport_number'), ':nat'=>$s('nationality'),
         ':exp'=>$s('passport_expiry'), ':id'=>(int)$lead['id']]);
} else {
    db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, passport_number, nationality, passport_expiry) VALUES (:h,TRUE,:n,:num,:nat,:exp)",
        [':h'=>$holdId, ':n'=>$s('passport_name'), ':num'=>$s('passport_number'), ':nat'=>$s('nationality'), ':exp'=>$s('passport_expiry')]);
}

// ── Waiver signature (only when they tick + type a name) ───────────────────
if (!empty($_POST['waiver_agree']) && $s('waiver_signed_name')) {
    db_query("UPDATE booking_checkin SET waiver_signed_name=:n, waiver_signed_at=now(), waiver_signed_ip=:ip, waiver_version=:v WHERE hold_id=:h",
        [':n'=>$s('waiver_signed_name'), ':ip'=>client_ip(), ':v'=>waiver_version(setting('checkin_waiver_text','')), ':h'=>$holdId]);
}

$do = $_POST['do'] ?? 'save';

if ($do === 'submit') {
    $data = fetch_checkin($holdId);
    $lead = checkin_lead_guest($holdId);
    $missing = checkin_missing_steps(checkin_config(), $data, $lead);
    if ($missing) {
        $_SESSION['ci_error'] = 'Please complete: ' . implode(', ', array_map(fn($k) => checkin_step_catalog()[$k]['label'] ?? $k, $missing));
        header('Location: /booking.php?ref=' . urlencode($ref) . '&view=checkin'); exit;
    }
    db_query("UPDATE holds SET checkin_completed_at = now() WHERE id = :h AND checkin_completed_at IS NULL", [':h'=>$holdId]);
    db_query("UPDATE booking_checkin SET submitted_at = now() WHERE hold_id = :h", [':h'=>$holdId]);
    audit_log('checkin.submit', 'hold', $holdId, (string)$hold['guest_name']);
    try { send_checkin_completed(fetch_hold_for_guest($holdId), fetch_checkin($holdId)); } catch (Throwable $e) { error_log('[checkin] mail: ' . $e->getMessage()); }
    header('Location: /booking.php?ref=' . urlencode($ref) . '&view=checkin'); exit;
}

// AJAX per-step save → 204; full-form fallback → back to the wizard.
if (($_POST['ajax'] ?? '') === '1') { http_response_code(204); exit; }
header('Location: /booking.php?ref=' . urlencode($ref) . '&view=checkin'); exit;
