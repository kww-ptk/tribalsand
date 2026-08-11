<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/mail.php';
if (session_status() === PHP_SESSION_NONE) session_start();

[$holdId, $onlyGuestId, $ref] = checkin_auth_context();
verify_csrf();
$isLead = ($onlyGuestId === null);

$hold = fetch_hold_for_guest($holdId);
$back = $isLead
    ? '/booking.php?ref=' . urlencode((string)$ref) . '&view=checkin'
    : '/booking.php?g=' . urlencode((string)($_POST['g'] ?? ''));

if (!$hold || !checkin_required($hold)) { header('Location: ' . ($isLead ? '/booking.php?ref=' . urlencode((string)$ref) : $back)); exit; }

// Editable until the arrival day; locked afterward.
if (checkin_is_complete($hold) && strtotime((string)$hold['check_in']) < strtotime('today')) {
    header('Location: ' . ($isLead ? '/booking.php?ref=' . urlencode((string)$ref) . '&view=home' : $back)); exit;
}

$s = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;

// ── Booking-level fields (LEAD only — booking_checkin is hold-scoped) ───────
if ($isLead) {
    $arrivalAt = ($_POST['arrival_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime((string)$_POST['arrival_at'])) : null;
    $needsTransfer = array_key_exists('needs_transfer', $_POST) && $_POST['needs_transfer'] !== ''
        ? ($_POST['needs_transfer'] === '1') : null;

    // Arrival mode: validated against the catalog, '' when unknown or unmigrated.
    $mode = '';
    if (checkin_arrival_mode_supported()) {
        $posted = (string)($_POST['arrival_mode'] ?? '');
        if (array_key_exists($posted, checkin_arrival_modes())) $mode = $posted;
    }

    // The airport select posts '__other' to reveal a free-text box; store the text.
    $airport = $s('arrival_airport');
    if ($airport === '__other') $airport = $s('arrival_airport_other');
    $flight = $s('flight_number');
    // Switching away from flying clears stale flight data so the transfer desk
    // never sees a flight number for someone driving in.
    if ($mode !== '' && $mode !== 'flight') { $airport = null; $flight = null; }

    // Column list is composed so the write works pre- and post-migration —
    // same approach as insert_booking_addon() in includes/booking.php.
    $cols = ['arrival_airport', 'flight_number', 'arrival_at', 'needs_transfer',
             'transfer_details', 'dietary', 'special_requests'];
    $vals = [':aa', ':fn', ':at', ':nt', ':td', ':di', ':sr'];
    $p = [':h'=>$holdId, ':aa'=>$airport, ':fn'=>$flight, ':at'=>$arrivalAt,
          ':nt'=>$needsTransfer, ':td'=>$s('transfer_details'), ':di'=>$s('dietary'),
          ':sr'=>$s('special_requests')];

    if (checkin_arrival_mode_supported()) {
        $cols[] = 'arrival_mode';    $vals[] = ':am'; $p[':am'] = $mode === '' ? null : $mode;
        $cols[] = 'arrival_vehicle'; $vals[] = ':av'; $p[':av'] = $mode === 'road'  ? $s('arrival_vehicle') : null;
        $cols[] = 'arrival_note';    $vals[] = ':an'; $p[':an'] = $mode === 'other' ? $s('arrival_note')    : null;
    }

    $sets = [];
    foreach ($cols as $i => $c) { $sets[] = "{$c}={$vals[$i]}"; }

    db_query(
        'INSERT INTO booking_checkin (hold_id, ' . implode(', ', $cols) . ', updated_at)
         VALUES (:h, ' . implode(', ', $vals) . ', now())
         ON CONFLICT (hold_id) DO UPDATE SET ' . implode(', ', $sets) . ', updated_at=now()',
        $p
    );
}

// ── Passport identity for the target guest (lead: any guest; co-guest: own) ─
$guestId = checkin_target_guest_id($holdId, $onlyGuestId);
db_query(
    "UPDATE checkin_guests SET passport_name=:n, passport_number=:num, nationality=:nat, passport_expiry=:exp
     WHERE id=:g AND hold_id=:h",
    [':n'=>$s('passport_name'), ':num'=>$s('passport_number'), ':nat'=>$s('nationality'),
     ':exp'=>$s('passport_expiry'), ':g'=>$guestId, ':h'=>$holdId]);

// ── Per-guest waiver signature: self-sign only, requires a drawn signature ──
$sig = (string)($_POST['waiver_signature'] ?? '');
$targetIsLead = (bool) db_query('SELECT is_lead FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetchColumn();
if (checkin_signature_supported() && !empty($_POST['waiver_agree']) && $s('waiver_signed_name')
    && checkin_can_sign_self($onlyGuestId, $targetIsLead)
    && checkin_valid_signature($sig)) {
    $terms  = checkin_waiver_text();
    $method = checkin_signing_method((string)($_POST['via'] ?? ''));
    db_query(
        "UPDATE checkin_guests
            SET waiver_signed_name=:n, waiver_signed_at=now(), waiver_signed_ip=:ip,
                waiver_version=:v, waiver_signature=:sig, waiver_terms_snapshot=:terms,
                waiver_signed_user_agent=:ua, waiver_signed_method=:m
          WHERE id=:g AND hold_id=:h",
        [':n'=>$s('waiver_signed_name'), ':ip'=>client_ip(), ':v'=>waiver_version($terms),
         ':sig'=>$sig, ':terms'=>$terms, ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
         ':m'=>$method, ':g'=>$guestId, ':h'=>$holdId]);
}

$do = $_POST['do'] ?? 'save';

if ($do === 'submit') {
    if ($isLead) {
        $config  = checkin_config();
        $missing = checkin_missing_steps($config, fetch_checkin($holdId), checkin_lead_guest($holdId)); // booking-level + lead's own
        if ($missing) {
            $_SESSION['ci_error'] = 'Please complete: ' . implode(', ', array_map(fn($k) => checkin_step_catalog()[$k]['label'] ?? $k, $missing));
            header('Location: ' . $back); exit;
        }
        $need   = max(1, (int)($hold['guest_count'] ?? 1));
        $adults = count(array_filter(fetch_checkin_guests($holdId), fn($g) => empty($g['is_child'])));
        if ($adults < $need) {
            $_SESSION['ci_error'] = "Please add all {$need} adults to your party before submitting.";
            header('Location: ' . $back); exit;
        }
        db_query("UPDATE booking_checkin SET submitted_at = COALESCE(submitted_at, now()) WHERE hold_id = :h", [':h'=>$holdId]); // lifts the lead's gate
        checkin_recompute_completion($holdId);   // fully green now iff every adult is already complete
        header('Location: ' . $back); exit;
    }
    checkin_recompute_completion($holdId);        // co-guest finished their own passport + waiver
    header('Location: ' . $back . '&done=1'); exit;
}

// Per-step save: recompute (a completing field flips the booking green), then respond.
checkin_recompute_completion($holdId);
if (($_POST['ajax'] ?? '') === '1') { http_response_code(204); exit; }
header('Location: ' . $back); exit;
