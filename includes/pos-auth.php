<?php
declare(strict_types=1);
/**
 * POS access — who is at the till, on which tablet.
 *
 * Two ways in (docs/pos/POS-PLAN.md §3):
 *   1. ADMIN mode — a browser with NO terminal cookie and a signed-in admin
 *      session. Works on any device; outlets = pos_user_outlet_ids().
 *   2. TERMINAL mode — a tablet registered by a manager (pos_terminals, token in a
 *      long-lived httpOnly cookie, sha256 of it in the DB). The tablet shows a lock
 *      screen; staff tap their name and enter a PIN. That sets pos_user_id +
 *      pos_terminal_id in the session — NEVER admin_id, so a PIN session opens no
 *      admin page. Outlets = the user's outlets ∩ the terminal's outlets.
 *
 * A registered tablet ALWAYS runs in terminal mode, even if an admin session is
 * present: a shared till must lock between people.
 *
 * PIN rules: 4–6 digits, password_hash()ed, trivial PINs refused, per-user
 * lockout after POS_PIN_MAX_FAILS wrong tries (login_attempts keyed 'pos:<id>'),
 * per-IP cap, and a PIN is useless without a registered terminal cookie.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pos.php';

const POS_TERMINAL_COOKIE     = 'ts_pos_terminal';
const POS_PIN_MAX_FAILS       = 5;      // per user, per window
const POS_PIN_IP_MAX_FAILS    = 20;     // per IP, per window
const POS_PIN_WINDOW_SEC      = 900;    // 15 minutes
const POS_SESSION_MAX_SEC     = 43200;  // a PIN session is hard-capped at 12 h
const POS_IDLE_LOCK_DEFAULT   = 120;    // seconds of inactivity before the till locks

// ── Pure ────────────────────────────────────────────────────────────────────

/** Why a PIN is refused, or null when acceptable — PURE. */
function pos_pin_problem(string $pin): ?string {
    if (!preg_match('/^\d{4,6}$/', $pin)) return 'A PIN is 4 to 6 digits.';
    if (preg_match('/^(\d)\1+$/', $pin)) return 'Pick a PIN that isn’t one repeated digit.';
    $asc = '0123456789'; $desc = '9876543210';
    if (str_contains($asc, $pin) || str_contains($desc, $pin)) return 'Pick a PIN that isn’t a straight run like 1234.';
    if (in_array($pin, ['1212', '1122', '6969', '2580', '0852', '121212', '112233', '123123'], true)) return 'That PIN is too easy to guess.';
    return null;
}

/** Initials for an avatar: "Amina Kariuki" → "AK" — PURE. */
function pos_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach ($parts as $p) { if ($p !== '') $ini .= mb_strtoupper(mb_substr($p, 0, 1)); if (mb_strlen($ini) >= 2) break; }
    return $ini !== '' ? $ini : '?';
}

/** A terminal token: 64 hex chars from a CSPRNG. Only its sha256 is stored. */
function pos_new_terminal_token(): string { return bin2hex(random_bytes(32)); }
function pos_token_hash(string $token): string { return hash('sha256', $token); }

// ── Terminals ───────────────────────────────────────────────────────────────

/** The active terminal this browser is registered as, or null. Stamps last_seen_at. */
function pos_current_terminal(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $tok = (string)($_COOKIE[POS_TERMINAL_COOKIE] ?? '');
    if (!pos_supported() || !preg_match('/^[0-9a-f]{64}$/', $tok)) return $cache = null;
    $t = db_query('SELECT * FROM pos_terminals WHERE token_hash = :h AND is_active = TRUE', [':h' => pos_token_hash($tok)])->fetch();
    if (!$t) return $cache = null;
    try { db_query('UPDATE pos_terminals SET last_seen_at = now() WHERE id = :i', [':i' => (int)$t['id']]); } catch (Throwable $e) {}
    return $cache = $t;
}

/** True when the browser carries a terminal cookie at all (valid or not). */
function pos_has_terminal_cookie(): bool {
    return !empty($_COOKIE[POS_TERMINAL_COOKIE]);
}

function pos_terminal_outlet_ids(int $terminalId): array {
    return array_map('intval', db_query('SELECT outlet_id FROM pos_terminal_outlets WHERE terminal_id = :t', [':t' => $terminalId])->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Register THIS browser as a terminal: creates the row, links outlets, sets the
 * cookie. Returns the terminal id. The raw token exists only in the cookie.
 */
function pos_register_terminal(string $name, ?int $venueId, array $outletIds, int $createdBy): int {
    $token = pos_new_terminal_token();
    $id = pos_tx(function () use ($name, $venueId, $outletIds, $createdBy, $token): int {
        db_query('INSERT INTO pos_terminals (name, venue_id, token_hash, created_by) VALUES (:n, :v, :h, :u)',
            [':n' => mb_substr($name, 0, 120), ':v' => $venueId, ':h' => pos_token_hash($token), ':u' => $createdBy]);
        $tid = (int) db()->lastInsertId();
        foreach (array_unique($outletIds) as $o) {
            db_query('INSERT INTO pos_terminal_outlets (terminal_id, outlet_id) SELECT :t, id FROM pos_outlets WHERE id = :o ON CONFLICT DO NOTHING', [':t' => $tid, ':o' => (int)$o]);
        }
        return $tid;
    });
    pos_set_terminal_cookie($token);
    return $id;
}

function pos_set_terminal_cookie(string $token): void {
    if (headers_sent()) return;
    setcookie(POS_TERMINAL_COOKIE, $token, [
        'expires'  => time() + 86400 * 400,   // browsers cap at ~400 days; re-registering is one step
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[POS_TERMINAL_COOKIE] = $token;
}

function pos_forget_terminal_cookie(): void {
    if (!headers_sent()) setcookie(POS_TERMINAL_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[POS_TERMINAL_COOKIE]);
}

/** Staff who can unlock this terminal: active, PIN set, and allowed at ≥1 of its outlets. */
function pos_terminal_people(array $terminal): array {
    $tOutlets = pos_terminal_outlet_ids((int)$terminal['id']);
    if (!$tOutlets) return [];
    $out = [];
    foreach (db_query("SELECT * FROM admin_users WHERE is_active = TRUE AND pos_pin_hash IS NOT NULL ORDER BY name, email")->fetchAll() as $u) {
        if (array_intersect($tOutlets, pos_user_outlet_ids($u))) {
            $out[] = ['id' => (int)$u['id'], 'name' => (string)($u['name'] ?: $u['email']), 'role' => pos_role_label($u)];
        }
    }
    return $out;
}

/** "Manager", "Shop", "Front desk"… for the lock screen and the till header. */
function pos_role_label(array $u): string {
    $role = (string)($u['role'] ?? 'staff');
    if ($role !== 'staff') return ucfirst($role);
    $jobs = ['frontdesk' => 'Front desk', 'shop' => 'Shop', 'spa' => 'Salon & Spa', 'kite' => 'Kite school'];
    $j = (string)($u['job_type'] ?? '') ?: 'frontdesk';
    return $jobs[$j] ?? ucfirst($j);
}

// ── Sessions ────────────────────────────────────────────────────────────────

/**
 * Who is at the till right now:
 *   ['mode'=>'pin'|'admin', 'user'=>row, 'terminal'=>?row, 'outlet_ids'=>int[]]
 * or null (locked / signed out). Terminal mode wins whenever the cookie is valid.
 */
function pos_current(): ?array {
    session_init();
    if (!pos_supported()) return null;
    $term = pos_current_terminal();
    if ($term) {
        $uid = (int)($_SESSION['pos_user_id'] ?? 0);
        if (!$uid || (int)($_SESSION['pos_terminal_id'] ?? 0) !== (int)$term['id']) return null;
        if (time() - (int)($_SESSION['pos_started_at'] ?? 0) > POS_SESSION_MAX_SEC) { pos_lock(); return null; }
        // The till locks itself client-side after the idle time; the server allows a
        // minute's grace because building a cart makes no request (the till pings).
        $idle = pos_idle_lock_seconds();
        if ($idle > 0 && time() - (int)($_SESSION['pos_last_active'] ?? 0) > $idle + 60) { pos_lock(); return null; }
        $u = pos_user($uid);
        if (!$u || empty($u['pos_pin_hash'])) { pos_lock(); return null; }
        $_SESSION['pos_last_active'] = time();
        $ids = array_values(array_intersect(pos_user_outlet_ids($u), pos_terminal_outlet_ids((int)$term['id'])));
        return ['mode' => 'pin', 'user' => $u, 'terminal' => $term, 'outlet_ids' => $ids];
    }
    if (pos_has_terminal_cookie()) return null;   // a revoked/unknown tablet never falls back to admin mode
    $a = current_admin();
    if (!$a || (array_key_exists('is_active', $a) && !pos_bool($a['is_active']))) return null;
    return ['mode' => 'admin', 'user' => $a, 'terminal' => null, 'outlet_ids' => pos_user_outlet_ids($a)];
}

/** Idle seconds before the till locks (setting pos_idle_lock_seconds; 0 = never). */
function pos_idle_lock_seconds(): int {
    $v = setting('pos_idle_lock_seconds', (string)POS_IDLE_LOCK_DEFAULT);
    return ctype_digit($v) ? max(0, min(3600, (int)$v)) : POS_IDLE_LOCK_DEFAULT;
}

/** End the PIN session (the terminal stays registered). */
function pos_lock(): void {
    session_init();
    unset($_SESSION['pos_user_id'], $_SESSION['pos_terminal_id'], $_SESSION['pos_started_at'], $_SESSION['pos_last_active']);
}

/** True when the user has too many recent wrong PINs, or the IP does. */
function pos_pin_locked_out(int $userId, string $ip): bool {
    $since = date('Y-m-d H:i:s', time() - POS_PIN_WINDOW_SEC);
    $byUser = (int) db_query("SELECT COUNT(*) FROM login_attempts WHERE email = :e AND success = FALSE AND created_at > :s",
        [':e' => 'pos:' . $userId, ':s' => $since])->fetchColumn();
    if ($byUser >= POS_PIN_MAX_FAILS) return true;
    $byIp = (int) db_query("SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND email LIKE 'pos:%' AND success = FALSE AND created_at > :s",
        [':ip' => $ip, ':s' => $since])->fetchColumn();
    return $byIp >= POS_PIN_IP_MAX_FAILS;
}

/**
 * PIN unlock on a registered terminal. Returns ['ok'=>true] or ['ok'=>false,'error'=>…].
 * Only people shown on this terminal's lock screen can unlock it.
 */
function pos_pin_login(array $terminal, int $userId, string $pin, string $ip): array {
    session_init();
    $people = array_column(pos_terminal_people($terminal), 'id');
    if (!in_array($userId, $people, true)) return ['ok' => false, 'error' => 'Pick your name first.'];
    if (pos_pin_locked_out($userId, $ip)) return ['ok' => false, 'error' => 'Too many wrong PINs. Try again in 15 minutes, or ask a manager to reset your PIN.', 'locked' => true];
    $u = pos_user($userId);
    $ok = $u && !empty($u['pos_pin_hash']) && password_verify($pin, (string)$u['pos_pin_hash']);
    db_query('INSERT INTO login_attempts (email, ip_address, success) VALUES (:e, :ip, :s)',
        [':e' => 'pos:' . $userId, ':ip' => mb_substr($ip, 0, 45), ':s' => $ok ? 'TRUE' : 'FALSE']);
    if (!$ok) {
        $left = POS_PIN_MAX_FAILS - (int) db_query("SELECT COUNT(*) FROM login_attempts WHERE email = :e AND success = FALSE AND created_at > :s",
            [':e' => 'pos:' . $userId, ':s' => date('Y-m-d H:i:s', time() - POS_PIN_WINDOW_SEC)])->fetchColumn();
        return ['ok' => false, 'error' => $left > 0 ? "Wrong PIN — {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.' : 'Too many wrong PINs. Try again in 15 minutes.'];
    }
    session_regenerate_id(true);
    $_SESSION['pos_user_id']     = (int)$u['id'];
    $_SESSION['pos_terminal_id'] = (int)$terminal['id'];
    $_SESSION['pos_started_at']  = time();
    $_SESSION['pos_last_active'] = time();
    return ['ok' => true];
}

/** Set (or clear, with '') a user's PIN. Returns null or the refusal reason. */
function pos_set_pin(int $userId, string $pin): ?string {
    if ($pin === '') {
        db_query('UPDATE admin_users SET pos_pin_hash = NULL, pos_pin_set_at = NULL WHERE id = :i', [':i' => $userId]);
        return null;
    }
    if ($why = pos_pin_problem($pin)) return $why;
    db_query('UPDATE admin_users SET pos_pin_hash = :h, pos_pin_set_at = now() WHERE id = :i', [':h' => password_hash($pin, PASSWORD_DEFAULT), ':i' => $userId]);
    // A new PIN clears the lockout so a manager reset takes effect immediately.
    db_query("DELETE FROM login_attempts WHERE email = :e AND success = FALSE", [':e' => 'pos:' . $userId]);
    return null;
}

// ── Endpoint guard ──────────────────────────────────────────────────────────

/** JSON endpoints: the till context or a 401/403/503 JSON exit. POST bodies must carry csrf_token. */
function pos_api_guard(bool $post): array {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!pos_supported()) { http_response_code(503); exit(json_encode(['ok' => false, 'error' => 'The POS is not enabled yet.'])); }
    $ctx = pos_current();
    if (!$ctx) { http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'The till is locked.', 'locked' => true])); }
    if ($post) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed'])); }
        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];
        $sess = (string)($_SESSION['csrf_token'] ?? '');
        if ($sess === '' || !hash_equals($sess, (string)($body['csrf_token'] ?? ''))) {
            http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Session expired — reload the till.']));
        }
        $ctx['body'] = $body;
    }
    return $ctx;
}

/** The outlet id from the request, only if this till may sell there. */
function pos_ctx_outlet(array $ctx, int $outletId): array {
    if (!in_array($outletId, $ctx['outlet_ids'], true)) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'You are not set up to sell at this outlet.'])); }
    $o = pos_fetch_outlet($outletId);
    if (!$o || !pos_bool($o['is_active'])) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'That outlet is closed.'])); }
    return $o;
}
