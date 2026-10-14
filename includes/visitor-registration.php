<?php
/**
 * General registration link — visitors with no booking register once, sign the
 * waiver and get a reusable pass. PURE part: tokens, matching, cleaning +
 * validation, waiver version, the gate verdict, the audit hash. Every DB read
 * and write lives in includes/visitor-store.php.
 * Spec: docs/superpowers/specs/2026-10-14-visitor-registration-design.md
 * Test: php tests/visitor_registration_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/checkin.php';   // waiver_version(), checkin_waiver_text(), checkin_valid_signature(), CHECKIN_ID_TYPES

const VISITOR_PASS_PREFIX     = 'VP';
/** Identity fields whose change after signing voids the signature. */
const VISITOR_MATERIAL_FIELDS = ['full_name', 'id_type', 'id_number', 'nationality', 'id_expiry'];

function visitor_secret(?string $secret): string {
    return $secret ?? (string)(parse_env()['BOOKING_TOKEN_SECRET'] ?? '');
}

/** The pass token for a visitor: "VP-<id>-<hmac12>". '' when BOOKING_TOKEN_SECRET is unset. */
function visitor_pass_token(int $id, ?string $secret = null): string {
    $secret = visitor_secret($secret);
    if ($secret === '' || $id <= 0) return '';
    return VISITOR_PASS_PREFIX . '-' . $id . '-' . substr(hash_hmac('sha256', "visitor:{$id}", $secret), 0, 12);
}

function visitor_pass_verify(string $tok, ?string $secret = null): int|false {
    $secret = visitor_secret($secret);
    if ($secret === '') return false;
    if (!preg_match('/^VP-(\d{1,10})-([0-9a-f]{12})$/i', trim($tok), $m)) return false;
    $id = (int)$m[1];
    return hash_equals(visitor_pass_token($id, $secret), VISITOR_PASS_PREFIX . '-' . $id . '-' . strtolower($m[2])) ? $id : false;
}

/** The visitor token inside whatever a scanner read (the gate link or the bare token). '' when none. */
function visitor_pass_from_scan(string $text): string {
    return preg_match('/\bVP-\d{1,10}-[0-9a-fA-F]{12}\b/', $text, $m) ? $m[0] : '';
}

function visitor_link(string $path, int $id): string {
    $t = visitor_pass_token($id);
    return $t === '' ? '' : site_url($path . rawurlencode($t));
}
/** The visitor's own pass page. */
function visitor_pass_url(int $id): string   { return visitor_link('/visitor-pass.php?t=', $id); }
/** What the QR carries: the guard's Gate page. */
function visitor_gate_url(int $id): string   { return visitor_link('/admin/gate.php?vp=', $id); }
/** An invited adult's link to register and sign. */
function visitor_invite_url(int $id): string { return visitor_link('/register.php?t=', $id); }

function visitor_phone_digits(string $p): string { return preg_replace('/\D+/', '', $p) ?? ''; }

/** Last 9 digits — "+254 712…" and "0712…" are the same phone. '' when shorter. */
function visitor_phone_key(string $p): string {
    $d = visitor_phone_digits($p);
    return strlen($d) >= 9 ? substr($d, -9) : '';
}

/** Lower-case, accent-free words of a name ("O'Brien" → "o", "brien"). */
function visitor_name_words(string $name): array {
    $s = mb_strtolower(trim($name), 'UTF-8');
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = strtolower($t);
    }
    $s = str_replace(["'", '’', '`', '"'], '', $s);   // O'Brien = OBrien
    return preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** The last word typed (the surname) is one of the words of the stored name, ≥ 2 letters. */
function visitor_booking_name_match(string $typed, string $stored): bool {
    $t = visitor_name_words($typed);
    $s = visitor_name_words($stored);
    if (!$t || !$s) return false;
    $last = end($t);
    return strlen($last) >= 2 && in_array($last, $s, true);
}

/** The terms visitors sign: Settings → Pre-Check-in "Visitor waiver", else the check-in waiver. */
function visitor_waiver_text(): string {
    $w = trim((string) setting('visitor_waiver_text', ''));
    return $w !== '' ? $w : checkin_waiver_text();
}

function visitor_signed(?array $v): bool {
    return $v !== null
        && !empty($v['waiver_signed_at'])
        && trim((string)($v['waiver_signed_name'] ?? '')) !== ''
        && trim((string)($v['waiver_signature'] ?? '')) !== '';
}

/** Signed, and for the terms in force today ($version = waiver_version(visitor_waiver_text())). */
function visitor_waiver_current(?array $v, string $version): bool {
    return visitor_signed($v) && $version !== '' && hash_equals($version, (string)($v['waiver_version'] ?? ''));
}

/** Material identity fields that differ. Only keys present in $new are compared. */
function visitor_identity_changed(array $stored, array $new): array {
    $changed = [];
    foreach (VISITOR_MATERIAL_FIELDS as $f) {
        if (!array_key_exists($f, $new)) continue;
        $a = trim((string)($stored[$f] ?? ''));
        $b = trim((string)($new[$f] ?? ''));
        if ($f === 'id_expiry') { $a = substr($a, 0, 10); $b = substr($b, 0, 10); }
        if ($f === 'id_number') { $a = strtoupper($a); $b = strtoupper($b); }
        if ($a !== $b) $changed[] = $f;
    }
    return $changed;
}

/**
 * The form's fields → stored values. The form uses the check-in field names
 * (so js/checkin-passport.js fills them): passport_name → full_name,
 * passport_number → id_number, passport_expiry → id_expiry.
 */
function visitor_clean_input(array $p): array {
    $s = fn(string $k): string => trim((string)($p[$k] ?? ''));
    $exp = $s('passport_expiry');
    $okDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $exp, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    $phone = mb_substr($s('phone'), 0, 30);
    return [
        'full_name'   => mb_substr(preg_replace('/\s+/u', ' ', $s('passport_name')) ?? '', 0, 120),
        'phone'       => $phone,
        'phone_key'   => visitor_phone_key($phone),
        'email'       => mb_strtolower(mb_substr($s('email'), 0, 160)),
        'id_type'     => checkin_id_type($s('id_type')),
        'id_number'   => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s('passport_number')) ?? ''),
        'nationality' => mb_substr($s('nationality'), 0, 60),
        'id_expiry'   => $okDate ? $exp : '',
    ];
}

/**
 * Check the form. $in = visitor_clean_input() + place_id, photo (bool),
 * signed_name, signature, agree. $mode 'register' (details + place + signature)
 * or 'edit' (details only). $gate = the guard's tablet (email optional).
 * Returns field => message ([] = fine).
 */
function visitor_validate(array $in, string $mode, bool $gate, array $placeIds): array {
    $e = [];
    $reg = $mode === 'register';
    if ($reg && !in_array((int)($in['place_id'] ?? 0), array_map('intval', $placeIds), true)) $e['place_id'] = 'Choose where you are going.';
    if (count(visitor_name_words((string)($in['full_name'] ?? ''))) < 2) $e['passport_name'] = 'Enter your full name, as on your ID.';
    if (($in['phone_key'] ?? '') === '') $e['phone'] = 'Enter a phone number we can reach you on.';
    $email = (string)($in['email'] ?? '');
    if ($email === '' && !$gate) $e['email'] = 'Enter your email — we send your pass there.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'That email address doesn’t look right.';
    $num = (string)($in['id_number'] ?? '');
    if (strlen($num) < 5 || strlen($num) > 20) $e['passport_number'] = 'Enter your passport or ID number.';
    if (trim((string)($in['nationality'] ?? '')) === '') $e['nationality'] = 'Enter your nationality.';
    if (empty($in['photo'])) $e['photo'] = 'Take a picture of your passport or ID.';
    if ($reg) {
        if (trim((string)($in['signed_name'] ?? '')) === '') $e['waiver_signed_name'] = 'Type your full name to sign.';
        if (!checkin_valid_signature((string)($in['signature'] ?? ''))) $e['signature'] = 'Sign in the box with your finger.';
        if (empty($in['agree'])) $e['agree'] = 'Tick that you have read and agree to the terms.';
    }
    return $e;
}

/** A signed record's reference: TSV-<6-digit id>-<4 random>. */
function visitor_make_reference(int $id): string {
    return sprintf('TSV-%06d-%s', $id, generate_access_code(4));
}

/** The exact string an audit row is hashed over. FIXED order — changing it breaks every stored hash. */
function visitor_audit_canonical(array $r): string {
    $f = fn(string $k): string => (string)($r[$k] ?? '');
    return implode("\x1f", [$f('reference'), $f('visitor_id'), $f('step'), $f('waiver_version'),
                            $f('ip'), $f('user_agent'), $f('method'), $f('detail')]);
}

function visitor_audit_hash(string $prev, array $r): string {
    return hash('sha256', $prev . "\x1e" . visitor_audit_canonical($r));
}

/**
 * What the guard does. $v = the visitor row; $visit = today's visit
 * (place_name, venue_id) or null; $adults = [['name', 'current' => signed for
 * today's terms]] coming with them; $termsCurrent = the visitor's own signature
 * is for today's terms; $inScope = today's place is behind a property this
 * account covers. Scope is checked FIRST so a guard elsewhere learns nothing
 * but the place.
 */
function visitor_gate_verdict(array $v, ?array $visit, array $adults, bool $termsCurrent, bool $inScope): array {
    if (!empty($v['removed_at'])) return ['level' => 'stop', 'msg' => 'This pass has been withdrawn. Do not admit — call reception.'];
    if ($visit !== null && !$inScope) {
        return ['level' => 'stop', 'msg' => 'This pass is for ' . ((string)($visit['place_name'] ?? '') ?: 'another place') . ', which you don’t cover. Do not admit — call reception.'];
    }
    if (!visitor_signed($v)) return ['level' => 'stop', 'msg' => 'Not registered yet — the waiver isn’t signed. Ask them to finish on their phone, or register them on this tablet.'];
    if (!$termsCurrent) return ['level' => 'stop', 'msg' => 'Our terms have changed since they signed. They must sign again on their pass before entering.'];
    $waiting = [];
    foreach ($adults as $a) if (empty($a['current'])) $waiting[] = (string)($a['name'] ?? 'Guest');
    if ($waiting) return ['level' => 'stop', 'msg' => 'Still to sign: ' . implode(', ', $waiting) . '. Every adult must sign before the group enters.'];
    if ($visit === null) return ['level' => 'warn', 'msg' => 'No place chosen for today — pick where they are going, then let them in.'];
    return ['level' => 'ok', 'msg' => 'Registered — going to ' . (string)$visit['place_name'] . '.'];
}

function visitor_people_count(array $adults, array $children): int {
    return 1 + count($adults) + count($children);
}

/** The message a lead sends another adult with their link. */
function visitor_invite_share_text(string $name, string $place, string $link): string {
    $first = trim($name) !== '' ? explode(' ', trim($name))[0] : '';
    $where = trim($place) !== '' ? ' to ' . trim($place) : '';
    return 'Hi' . ($first !== '' ? ' ' . $first : '') . ', everyone who goes' . $where
         . ' has to register with Tribal Sand first. It takes 2 minutes — add your details and sign here: ' . $link;
}
