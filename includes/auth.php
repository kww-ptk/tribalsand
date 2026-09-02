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
    // SELECT * (not an explicit column list) so a freshly-added column such as
    // job_type is picked up automatically AND its absence pre-migration never
    // fatals the whole admin on this per-request query.
    return db_query(
        'SELECT * FROM admin_users WHERE id = :id',
        [':id' => $_SESSION['admin_id']]
    )->fetch();
}

/** Current admin's role; defaults to the LEAST-privileged value if unknown (fail closed). */
function admin_role(): string { $a = current_admin(); return ($a && !empty($a['role'])) ? $a['role'] : 'staff'; }
function is_owner(): bool   { return admin_role() === 'owner'; }
function is_manager(): bool { return admin_role() === 'manager'; }
function is_staff(): bool   { return admin_role() === 'staff'; }

/**
 * Front-of-house tier: sees everything the owner sees EXCEPT the Catalog group,
 * the Admin group and site-content editing — i.e. all of Operations, the whole
 * Bookings group and restaurant Reservations, scoped to its assigned venues.
 * Not a staff job type: reception logs in with email + password, never an
 * access code (login_staff() hard-codes role='staff').
 */
function is_reception(): bool { return admin_role() === 'reception'; }

/**
 * True once add_reception_role.sql has widened the role CHECK constraint.
 * Used to hide the account type in the create form pre-migration; the INSERT
 * would be rejected by the constraint anyway, so this fails closed.
 */
function reception_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query(
            "SELECT 1 FROM pg_constraint
              WHERE conname = 'admin_users_role_check'
                AND pg_get_constraintdef(oid) LIKE '%reception%'"
        )->fetchColumn();
    } catch (\Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * Current staff member's operational specialty. A NULL job_type is treated as
 * 'frontdesk' (backward-compatible with pre-extension staff). Owner/manager
 * accounts are not job-driven, so they return null.
 */
function admin_job(): ?string {
    if (!is_staff()) return null;
    $a = current_admin();
    return ($a && !empty($a['job_type'])) ? (string)$a['job_type'] : 'frontdesk';
}

/** The job types whose home is the focused My Work queue (vs Front Desk / Gate). */
function job_is_ops(?string $job): bool {
    return in_array($job, ['housekeeping','maintenance','gardening','driver'], true);
}

/**
 * Post-login home for the current admin:
 *   owner → dashboard · manager → front desk · security staff → gate ·
 *   ops staff → My Work · frontdesk (or job-less) staff → front desk.
 */
function admin_home_url(): string {
    // Everyone who can reach the Front Desk lands there by default (owner included).
    // Ops and gate-security keep their focused homes since Front Desk isn't theirs.
    $job = admin_job();
    if ($job === 'security') return '/admin/gate.php';
    if (job_is_ops($job))    return '/admin/mywork.php';
    return '/admin/frontdesk.php';
}

/** Venue ids the current admin may see; null = all (owner). Managers and staff are scoped. */
function admin_venue_ids(): ?array {
    if (is_owner()) return null;
    $rows = db_query('SELECT venue_id FROM admin_user_venues WHERE admin_user_id = :id', [':id' => $_SESSION['admin_id'] ?? 0])->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/** Owner-only gate. Managers and staff are redirected to their own home. */
function require_owner(): void {
    require_login();
    if (!is_owner()) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is only available to the owner account.']; header('Location: ' . admin_home_url()); exit; }
}

/** Owner-or-manager gate — guards assignment, tasks and gate management. Staff are bounced home. */
function require_manager(): void {
    require_login();
    if (!is_owner() && !is_manager()) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is only available to managers.']; header('Location: ' . admin_home_url()); exit; }
}

/**
 * Reception tier — owner, manager or reception. Guards the surfaces managers
 * already had and reception now joins: Tasks and restaurant Reservations.
 * (Menus stays require_manager() — reception does no content editing.)
 */
function require_reception(): void {
    require_login();
    if (!is_owner() && !is_manager() && !is_reception()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is only available to managers.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/**
 * Bookings gate — owner or reception. Guards holds, the calendar, submissions,
 * conflicts and itineraries. Deliberately NOT open to managers: this replaces
 * require_owner() on those pages and must not widen anyone else's access.
 */
function require_bookings(): void {
    require_login();
    if (!is_owner() && !is_reception()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is only available to the owner and reception.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/**
 * Messages gate — owner, manager, or front-desk staff. Ops staff (housekeeping,
 * maintenance, gardening, driver) and gate-security get a focused interface with
 * no guest messaging, so they are bounced to their own home.
 */
function require_frontdesk(): void {
    require_login();
    $job = admin_job();
    if (is_staff() && (job_is_ops($job) || $job === 'security')) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Messages aren’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/** Gate access — owner, manager, or gate-security staff. Others are bounced home. */
function require_gate(): void {
    require_login();
    if (!is_owner() && !is_manager() && !is_reception() && !(is_staff() && admin_job() === 'security')) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'The gate isn’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/**
 * SQL fragment restricting a query to the venues the current admin may see.
 * '' for the owner (unrestricted); a never-true clause for an account assigned
 * no venues — an empty scope means NOTHING, never everything.
 *
 * $col must be a trusted, literal column expression written by us (e.g.
 * 'r.venue_id'). It is interpolated, so it must NEVER carry user input. The ids
 * themselves are cast through intval() before interpolation.
 */
function venue_scope_sql(string $col): string {
    $vids = admin_venue_ids();
    if ($vids === null) return '';        // owner: every venue
    if (!$vids)         return '1=0';     // assigned nowhere: nothing
    return $col . ' IN (' . implode(',', array_map('intval', $vids)) . ')';
}

/** True if current admin may act on this hold (owner: always; manager/staff: hold's venue in scope). */
function staff_can_hold(int $holdId): bool {
    if (is_owner()) return true;
    $vids = admin_venue_ids();
    if (!$vids) return false;
    $v = db_query('SELECT r.venue_id FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE h.id=:id', [':id'=>$holdId])->fetchColumn();
    return $v !== false && $v !== null && in_array((int)$v, $vids, true);
}

/**
 * True if the current admin may view or act on this submission.
 *
 * Owner: always. A scoped account (reception) may act on an enquiry whose room
 * belongs to one of its venues. A submission with NO room — contact and agency
 * enquiries — is not property-specific, so it stays visible to every account;
 * this mirrors the list predicate in admin/submissions.php exactly, including
 * for an account assigned no venues at all.
 */
function submission_in_scope(int $submissionId): bool {
    if (is_owner()) return true;
    if ($submissionId <= 0) return false;
    $vids = admin_venue_ids();
    if ($vids === null) return true;
    $row = db_query('SELECT room_id FROM submissions WHERE id = :id', [':id' => $submissionId])->fetch();
    if (!$row) return false;
    if ($row['room_id'] === null) return true;   // no property attached
    if (!$vids) return false;                    // assigned nowhere
    $v = db_query('SELECT venue_id FROM rooms WHERE id = :id', [':id' => (int)$row['room_id']])->fetchColumn();
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
