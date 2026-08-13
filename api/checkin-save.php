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
// scope=guest means "this post carries ONE guest card, not the whole wizard form".
// The per-guest save in js/checkin-wizard.js sends it. Without the marker that
// request still carries `ref`, so $isLead is true and the upsert below would run
// against a $_POST holding none of the booking-level fields — writing NULL over
// every arrival, transfer, dietary and requests answer the guest already gave.
// Silently: checkin_recompute_completion() only ever stamps checkin_completed_at
// NULL→now(), never clears it, so the booking still reads "Checked in ✓" over an
// emptied record. Explicit marker, not a "is some field missing?" sniff — a sniff
// breaks the day a field is renamed, and this failure is invisible.
if ($isLead && ($_POST['scope'] ?? '') !== 'guest') {
    $arrivalAt = ($_POST['arrival_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime((string)$_POST['arrival_at'])) : null;
    // Both booleans go through checkin_bool_param() — 'TRUE'/'FALSE'/null, never a
    // PHP bool. See its docblock for why that distinction took check-in down.
    $needsTransfer    = checkin_bool_param($_POST, 'needs_transfer');
    $needsDepTransfer = checkin_bool_param($_POST, 'needs_departure_transfer');

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
    // The mirror of that clear, for the hidden pickup-location textarea: a guest who
    // typed "Likoni ferry" on road and then switched to flying keeps posting it.
    // A TRANSITION test (was non-flight, now flight), NOT a state test — pre-epic
    // this column was a general box shown to everyone, so a legacy flier's
    // "2 pax, 3 bags" must survive, and a NULL mode reads as flight. Only an actual
    // switch makes the text stale; no stored row means no switch, so keep it.
    // Rationale in docs/superpowers/specs/2026-08-13-transfer-step-restructure-design.md.
    $transferDetails = $s('transfer_details');
    $stored = fetch_checkin($holdId);
    if ($stored !== null
        && checkin_effective_mode($stored) !== 'flight'
        && checkin_effective_mode(['arrival_mode' => $mode]) === 'flight') {
        $transferDetails = null;
    }

    // Column list is composed so the write works pre- and post-migration —
    // same approach as insert_booking_addon() in includes/booking.php.
    $cols = ['arrival_airport', 'flight_number', 'arrival_at', 'needs_transfer',
             'transfer_details', 'dietary', 'special_requests'];
    $vals = [':aa', ':fn', ':at', ':nt', ':td', ':di', ':sr'];
    $p = [':h'=>$holdId, ':aa'=>$airport, ':fn'=>$flight, ':at'=>$arrivalAt,
          ':nt'=>$needsTransfer, ':td'=>$transferDetails, ':di'=>$s('dietary'),
          ':sr'=>$s('special_requests')];

    if (checkin_arrival_mode_supported()) {
        $cols[] = 'arrival_mode';    $vals[] = ':am'; $p[':am'] = $mode === '' ? null : $mode;
        $cols[] = 'arrival_vehicle'; $vals[] = ':av'; $p[':av'] = $mode === 'road'  ? $s('arrival_vehicle') : null;
        $cols[] = 'arrival_note';    $vals[] = ':an'; $p[':an'] = $mode === 'other' ? $s('arrival_note')    : null;
    }

    if (checkin_property_arrival_supported()) {
        // The guest's desired check-in time, asked of every arrival mode. The
        // wizard prefills it from a legacy road/other arrival_at via
        // checkin_desired_time(), so saving here is also what heals those rows.
        // Asked of every mode now, so there is no mode branch to get wrong.
        $cols[] = 'property_arrival_time'; $vals[] = ':pat';
        $p[':pat'] = checkin_clock_time($s('property_arrival_time'));
    }

    if (checkin_departure_transfer_supported()) {
        // Destination and time are only meaningful once the guest says yes;
        // clearing them on "no" stops the driver seeing a stale pickup. Unlike
        // transfer_details above this IS a state test, and correctly so: these
        // columns are new in this migration, so there is no legacy content under
        // them to protect — nothing can predate the question.
        $wantsOut = $needsDepTransfer === 'TRUE';
        $cols[] = 'needs_departure_transfer'; $vals[] = ':ndt'; $p[':ndt'] = $needsDepTransfer;
        $cols[] = 'departure_destination';    $vals[] = ':dd';  $p[':dd']  = $wantsOut ? $s('departure_destination') : null;
        $cols[] = 'departure_time';           $vals[] = ':dt';  $p[':dt']  = $wantsOut ? checkin_clock_time($s('departure_time')) : null;
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
$targetRow    = db_query('SELECT * FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetch() ?: null;
$targetIsLead = (bool)($targetRow['is_lead'] ?? false);

// Did this request genuinely try to sign? The wizard posts the WHOLE form on every
// per-step save, so a ticked box or a pre-filled name travels along with a save
// from an unrelated step — those must not be read as a failed signing attempt, or
// a half-filled consent step would reject the guest's transfer or dietary save.
// A posted signature is a real attempt; so is the final submit.
$triedConsent = $sig !== '' || ($_POST['do'] ?? 'save') === 'submit';

if ($triedConsent && checkin_signature_supported() && checkin_can_sign_self($onlyGuestId, $targetIsLead)) {
    $already = checkin_guest_waiver_signed($targetRow);
    $missing = checkin_consent_missing(
        !empty($_POST['waiver_agree']),
        (string)($_POST['waiver_signed_name'] ?? ''),
        $sig,
        $already
    );
    if ($missing) {
        // Previously this fell through silently, so a guest could finish the wizard
        // with no agreement and no signature and never be told.
        $msg = 'We could not record your signature — please ' . implode(', ', $missing) . '.';
        if (($_POST['ajax'] ?? '') === '1') {
            http_response_code(422);
            header('Content-Type: application/json');
            exit(json_encode(['ok'=>false, 'error'=>$msg]));
        }
        $_SESSION['ci_error'] = $msg;
        header('Location: ' . $back); exit;
    }
    // A fresh drawing replaces the stored one; an untouched signed panel posts an
    // empty value and leaves the existing signature alone.
    if (checkin_valid_signature($sig)) {
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
