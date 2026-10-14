<?php
/**
 * The ONE POST handler for /register.php and /visitor-pass.php (post/redirect/get).
 *   lookup  booking code + name → the booking's check-in (or an error)
 *   nocode  email → emails current booking links (neutral answer)
 *   again   phone/email → emails the visitor's pass link (neutral answer)
 *   register details + photo + signature + place (+ children, invited adults)
 *   update  details only (t=) — a material change voids the signature
 *   sign    sign today's terms (t=)
 *   visit   today's place + who comes (t=)
 *   invite / child / drop   the group (t=)
 * Public actions without a pass token: CSRF + honeypot + Turnstile once per
 * session + per-IP limits. Token actions: the unguessable VP token is the key.
 * Tablet mode (gate=1): a signed-in gate account; no Turnstile, no limits.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/visitor-store.php';
session_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /register.php'); exit; }
// A cookie-less POST has no session token and verify_csrf() would match '' against
// '' and pass, so require a real page load that minted the token first.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['vr_flash'] = ['errors' => ['general' => 'Your session expired — please try again.']];
    header('Location: /register.php'); exit;
}
verify_csrf();

$action = (string)($_POST['action'] ?? '');
$gate   = !empty($_POST['gate']);
if ($gate) { require_login(); require_gate(); }
$today  = frontdesk_today_ymd();
$tok    = trim((string)($_POST['t'] ?? ''));
$me     = $tok !== '' ? visitor_find_by_token($tok) : null;
$ret    = (string)($_POST['ret'] ?? '');
if (!preg_match('#^/(register|visitor-pass)\.php(\?[A-Za-z0-9=&%._~-]*)?$#', $ret)) $ret = '/register.php';
$old    = array_diff_key($_POST, array_flip(['csrf_token', 'waiver_signature', 'cf-turnstile-response', 't']));

$back = function (array $flash = [], ?string $to = null) use ($ret): never {
    if ($flash) $_SESSION['vr_flash'] = $flash;
    header('Location: ' . ($to ?? $ret)); exit;
};
$neutral = fn(string $msg) => $back(['notice' => $msg], '/register.php?step=sent');

if (!visitor_reg_supported()) $back(['errors' => ['general' => 'Registration isn’t available yet. Please ask at the gate.']], '/register.php');
if (!empty($_POST['website'])) $back([], '/register.php');   // honeypot: accept and ignore

$tokenActions = ['update', 'sign', 'visit', 'invite', 'child', 'drop'];
if (in_array($action, $tokenActions, true) && !$me) $back(['errors' => ['general' => 'This link isn’t valid any more. Register again, or ask at the gate.']], '/register.php');

// Turnstile: public actions without a pass token, once per session.
if (!$gate && !$me && !visitor_session_verified()) {
    if (!verify_captcha((string)($_POST['cf-turnstile-response'] ?? ''), client_ip())) {
        $back(['errors' => ['general' => 'Please confirm you’re not a robot, then try again.'], 'old' => $old]);
    }
    visitor_mark_verified();
}

$placeIdsFor = fn(): array => array_map(fn($p) => (int)$p['id'], visitor_places(true, $gate ? admin_venue_ids() : null));
$via = $gate ? 'gate' : 'self';

switch ($action) {
case 'lookup':
    if (visitor_attempt_count('code_fail', 10) >= VISITOR_CODE_FAIL_MAX) {
        $back(['errors' => ['general' => 'Too many tries. Please wait 10 minutes, or register as a visitor.'], 'old' => $old]);
    }
    $res = visitor_booking_lookup((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), $today);
    if ($res['url'] !== '') { header('Location: ' . $res['url']); exit; }
    visitor_attempt_log('code_fail');
    // One message for a wrong code and a wrong name: a miss must not reveal whether the code exists.
    $back(['errors' => ['general' => 'We couldn’t find a current booking for that code and name. Check them, ask the person who booked to add you, or register as a visitor.'], 'old' => $old]);

case 'nocode':
case 'again':
    $said = $action === 'nocode'
        ? 'If we found a booking for that email, we’ve sent the link to it. Check your inbox (and spam).'
        : 'If you’re registered with us, we’ve emailed your pass to the address you gave. Check your inbox (and spam).';
    if (visitor_attempt_count('recover', 10) >= VISITOR_RECOVER_MAX) $neutral($said);
    visitor_attempt_log('recover');
    $q = trim((string)($_POST['contact'] ?? ''));
    if ($action === 'nocode') {
        $email = mb_strtolower($q);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && visitor_attempt_count('recover_mail', 10, $email) === 0) {
            $links = visitor_booking_links_for_email($email, $today);
            if ($links && function_exists('send_visitor_booking_link')) {
                visitor_attempt_log('recover_mail', $email);
                try { send_visitor_booking_link($email, $links); } catch (Throwable $e) { error_log('[visitor-register] booking link mail: ' . $e->getMessage()); }
            }
        }
    } else {
        $v = visitor_find_by_contact($q);
        $to = $v ? mb_strtolower((string)$v['email']) : '';
        if ($v && $to !== '' && visitor_attempt_count('recover_mail', 10, $to) === 0 && function_exists('send_visitor_pass')) {
            visitor_attempt_log('recover_mail', $to);
            try { send_visitor_pass($v); } catch (Throwable $e) { error_log('[visitor-register] pass mail: ' . $e->getMessage()); }
        }
    }
    $neutral($said);

case 'register':
case 'update':
    $mode = $action === 'register' ? 'register' : 'edit';
    if ($mode === 'register' && !$gate && !$me && visitor_attempt_count('register', 60) >= VISITOR_REGISTER_MAX) {
        $back(['errors' => ['general' => 'Too many registrations from this connection. Please ask at the gate.'], 'old' => $old]);
    }
    if ($mode === 'register' && $me && visitor_signed($me)) $back([], visitor_pass_url((int)$me['id']));
    $in = visitor_clean_input($_POST);
    // The session's draft photo belongs to THIS form only when the form carries the nonce its upload returned
    // (one gate-tablet session serves many visitors; an abandoned photo must never attach to the next one).
    $nonce = (string)($_SESSION['vr_photo_nonce'] ?? '');
    $draft = ($nonce !== '' && hash_equals($nonce, (string)($_POST['photo_draft'] ?? ''))) ? (string)($_SESSION['vr_photo'] ?? '') : '';
    $errors = visitor_validate($in + [
        'place_id' => (int)($_POST['place_id'] ?? 0), 'photo' => $draft !== '' || !empty($me['id_photo_key']),
        'signed_name' => (string)($_POST['waiver_signed_name'] ?? ''), 'signature' => (string)($_POST['waiver_signature'] ?? ''),
        'agree' => !empty($_POST['waiver_agree']),
    ], $mode, $gate, $placeIdsFor());
    if ($errors) $back(['errors' => $errors, 'old' => $old]);
    $d = $in + ['photo_key' => $draft];
    $voided = false;
    db()->beginTransaction();
    try {
        if ($me) { $id = (int)$me['id']; $voided = visitor_update_identity($id, $d, $gate ? 'gate' : 'visitor'); }
        else     { $id = visitor_create($d, $via, $gate ? (int)$_SESSION['admin_id'] : null); }
        if ($mode === 'register') {
            visitor_sign($id, (string)$_POST['waiver_signed_name'], (string)$_POST['waiver_signature'], $via);
            $adultIds = []; $childIds = [];
            if (!$me) {
                foreach ((array)($_POST['child_name'] ?? []) as $i => $cn) {
                    if (trim((string)$cn) === '') continue;
                    $childIds[] = visitor_add_child($id, (string)$cn, (int)(($_POST['child_age'] ?? [])[$i] ?? -1));
                }
                foreach ((array)($_POST['adult_name'] ?? []) as $an) {
                    if (trim((string)$an) !== '') $adultIds[] = visitor_invite_adult($id, (string)$an);
                }
            }
            visitor_choose_visit($id, (int)$_POST['place_id'], $today, $adultIds, $childIds, $via);
        }
        db()->commit();
        visitor_flush_file_deletes();   // a replaced ID photo is removed only once the commit holds
        if ($draft !== '') unset($_SESSION['vr_photo'], $_SESSION['vr_photo_nonce']);   // now attached to a visitor: a later upload must never delete it
    } catch (InvalidArgumentException $e) {
        if (db()->inTransaction()) db()->rollBack();
        visitor_discard_file_deletes();
        $back(['errors' => ['general' => $e->getMessage()], 'old' => $old]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        visitor_discard_file_deletes();
        error_log('[visitor-register] ' . $e->getMessage());
        $back(['errors' => ['general' => 'Something went wrong — nothing was saved. Please try again.'], 'old' => $old]);
    }
    if ($mode === 'register' && !$gate && !$me) visitor_attempt_log('register');
    $v = visitor_find($id);
    if ($mode === 'register' && $v && !empty($v['email']) && function_exists('send_visitor_pass')) {
        try { send_visitor_pass($v); } catch (Throwable $e) { error_log('[visitor-register] pass mail: ' . $e->getMessage()); }
    }
    if ($gate) {   // back to the Gate page (its flash slot is hold_flash) with this visitor's card open
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => $mode === 'register' ? 'Registered — check the card and let them in.'
            : ($voided ? 'Details saved — they must sign again before entering.' : 'Details saved.')];
        header('Location: /admin/gate.php?vp=' . rawurlencode(visitor_pass_token($id))); exit;
    }
    $back($voided ? ['notice' => 'Your details changed, so please sign again.'] : ['notice' => $mode === 'register' ? 'You’re registered.' : 'Details saved.'],
          visitor_pass_url($id) . ($mode === 'register' ? '&new=1' : ''));

case 'sign':
    // A name-only invited-adult row has no identity yet: it must be completed (details + ID photo) before it can sign.
    if (visitor_validate($me + ['photo' => !empty($me['id_photo_key'])], 'edit', true, [])) {
        $back(['errors' => ['general' => 'Please add your details and a photo of your ID first.']], visitor_invite_url((int)$me['id']));
    }
    $errors = [];
    if (trim((string)($_POST['waiver_signed_name'] ?? '')) === '') $errors['waiver_signed_name'] = 'Type your full name to sign.';
    if (!checkin_valid_signature((string)($_POST['waiver_signature'] ?? ''))) $errors['signature'] = 'Sign in the box with your finger.';
    if (empty($_POST['waiver_agree'])) $errors['agree'] = 'Tick that you have read and agree to the terms.';
    if ($errors) $back(['errors' => $errors, 'old' => $old]);
    visitor_sign((int)$me['id'], (string)$_POST['waiver_signed_name'], (string)$_POST['waiver_signature'], $via);
    $back(['notice' => 'Thank you — signed.'], visitor_pass_url((int)$me['id']));

case 'visit':
    if (!visitor_waiver_current($me, waiver_version(visitor_waiver_text()))) $back([], visitor_pass_url((int)$me['id']));   // the pass page asks them to sign
    if (!in_array((int)($_POST['place_id'] ?? 0), $placeIdsFor(), true)) $back(['errors' => ['place_id' => 'Choose where you are going.']]);
    visitor_choose_visit((int)$me['id'], (int)$_POST['place_id'], $today, (array)($_POST['with_adult'] ?? []), (array)($_POST['with_child'] ?? []), $via);
    $back(['notice' => 'Saved — show this pass at the gate.'], visitor_pass_url((int)$me['id']));

case 'invite':
case 'child':
case 'drop':
    if (!visitor_signed($me)) $back([], visitor_pass_url((int)$me['id']));
    try {
        if ($action === 'invite') visitor_invite_adult((int)$me['id'], (string)($_POST['name'] ?? ''));
        elseif ($action === 'child') visitor_add_child((int)$me['id'], (string)($_POST['name'] ?? ''), (int)($_POST['age'] ?? -1));
        else visitor_remove_member((int)$me['id'], (int)($_POST['member_id'] ?? 0));
    } catch (InvalidArgumentException $e) {
        $back(['errors' => ['group' => $e->getMessage()]], visitor_pass_url((int)$me['id']) . '#group');
    }
    $back([], visitor_pass_url((int)$me['id']) . '#group');

default:
    $back([], '/register.php');
}
