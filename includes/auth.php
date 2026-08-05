<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function session_init(): void {
    if (session_status() !== PHP_SESSION_NONE) return;

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // 2-hour idle timeout
    if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > 7200) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_active'] = time();
}

function require_login(): void {
    session_init();
    if (empty($_SESSION['admin_id'])) {
        header('Location: /admin/login.php');
        exit;
    }
    // The account must still exist and be active — a deleted or deactivated
    // account's live session is invalidated on the next request (prevents a
    // revoked staff session from lingering, or escalating when the row is gone).
    $a = current_admin();
    if (!$a || (array_key_exists('is_active', $a) && !$a['is_active'])) {
        session_unset();
        session_destroy();
        header('Location: /admin/login.php');
        exit;
    }
}

function current_admin(): array|false {
    session_init();
    if (empty($_SESSION['admin_id'])) return false;
    return db_query(
        'SELECT id, email, name, role, is_active, created_at FROM admin_users WHERE id = :id',
        [':id' => $_SESSION['admin_id']]
    )->fetch();
}

/** Current admin's role; defaults to the LEAST-privileged value if unknown (fail closed). */
function admin_role(): string { $a = current_admin(); return ($a && !empty($a['role'])) ? $a['role'] : 'staff'; }
function is_staff(): bool { return admin_role() === 'staff'; }

/** Post-login home for the current admin: staff → Front Desk, owner → dashboard. */
function admin_home_url(): string { return is_staff() ? '/admin/frontdesk.php' : '/admin/dashboard.php'; }

/** Venue ids a staff user may see; null = all (owner). */
function admin_venue_ids(): ?array {
    if (!is_staff()) return null;
    $rows = db_query('SELECT venue_id FROM admin_user_venues WHERE admin_user_id = :id', [':id' => $_SESSION['admin_id'] ?? 0])->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/** Owner-only gate. Staff are redirected to the concierge desk. */
function require_owner(): void {
    require_login();
    if (is_staff()) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is not available for staff accounts.']; header('Location: /admin/frontdesk.php'); exit; }
}

/** True if current admin may act on this hold (owner: always; staff: hold's venue in scope). */
function staff_can_hold(int $holdId): bool {
    if (!is_staff()) return true;
    $vids = admin_venue_ids();
    if (!$vids) return false;
    $v = db_query('SELECT r.venue_id FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE h.id=:id', [':id'=>$holdId])->fetchColumn();
    return $v !== false && $v !== null && in_array((int)$v, $vids, true);
}

/** Log in an onsite staff member by access code. Returns true on success. */
function login_staff(string $code, string $ip): bool {
    session_init();
    $code = strtoupper(trim($code));
    if ($code === '' || is_rate_limited($code, $ip)) {
        db_query('INSERT INTO login_attempts (email, ip_address, success) VALUES (:e,:ip,FALSE)', [':e'=>($code !== '' ? $code : 'staff'), ':ip'=>$ip]);
        return false;
    }
    $user = db_query("SELECT * FROM admin_users WHERE access_code = :c AND role='staff' AND is_active=TRUE", [':c'=>$code])->fetch();
    db_query('INSERT INTO login_attempts (email, ip_address, success) VALUES (:e,:ip,:ok)', [':e'=>$code, ':ip'=>$ip, ':ok'=>$user ? 'TRUE' : 'FALSE']);
    if (!$user) return false;
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $user['id'];
    db_query('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id', [':id'=>$user['id']]);
    return true;
}

/** Generate a unique 12-char staff access code. */
function gen_staff_code(): string {
    do { $c = strtoupper(bin2hex(random_bytes(6))); } while (db_query('SELECT 1 FROM admin_users WHERE access_code=:c', [':c'=>$c])->fetchColumn());
    return $c;
}

function login(string $email, string $password): bool {
    session_init();

    $email = strtolower(trim($email)); // emails are case-insensitive — avoid lockouts from auto-capitalised input

    if (is_rate_limited($email, client_ip())) return false;

    $user = db_query(
        'SELECT * FROM admin_users WHERE email = :email',
        [':email' => $email]
    )->fetch();

    $success = $user && password_verify($password, $user['password_hash']);

    db_query(
        'INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, :ok)',
        [':email' => $email, ':ip' => client_ip(), ':ok' => $success ? 'TRUE' : 'FALSE']
    );

    if ($success) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $user['id'];
        db_query(
            'UPDATE admin_users SET last_login_at = NOW() WHERE id = :id',
            [':id' => $user['id']]
        );
    }

    return $success;
}

function logout(): void {
    session_init();
    session_unset();
    session_destroy();
}

function is_rate_limited(string $email, string $ip): bool {
    $window = date('Y-m-d H:i:s', time() - 600); // 10 minutes
    $row = db_query(
        "SELECT COUNT(*) AS cnt FROM login_attempts
         WHERE (email = :email OR ip_address = :ip)
           AND success = FALSE
           AND created_at > :window",
        [':email' => $email, ':ip' => $ip, ':window' => $window]
    )->fetch();
    return (int)$row['cnt'] >= 5;
}

function csrf_token(): string {
    session_init();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    session_init();
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}
