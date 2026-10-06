<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';   // the owner's "Access by role" choices (admin/staff.php?tab=access)

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
    // A section the owner switched OFF for this role refuses its pages, whatever
    // the page's own gate would allow (the Dashboard is never configurable).
    if (access_page_blocked()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area isn’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
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
 * HR: the people admin (Oct 2026). Runs the staff directory, employee profiles
 * and their private documents, attendance (times, leave, clock cards and kiosks)
 * for EVERY property — admin_venue_ids() returns null for HR. Email + password
 * login like reception. No bookings, money, settings, login accounts or "Access
 * by role" (those stay with the owner). Migration add_hr_role.sql.
 */
function is_hr(): bool { return admin_role() === 'hr'; }

/** True once add_hr_role.sql has widened the role CHECK (hides the account type before). */
function hr_role_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query(
            "SELECT 1 FROM pg_constraint
              WHERE conname = 'admin_users_role_check'
                AND pg_get_constraintdef(oid) LIKE '%''hr''%'"
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
    return in_array($job, ['housekeeping','laundry','maintenance','gardening','driver'], true);
}

/** The POS job types — staff who sell at an outlet till (shop / salon & spa / kite school). */
function job_is_pos(?string $job): bool {
    return in_array($job, ['shop','spa','kite'], true);
}

/** The storekeeper job — staff who run the stock: Inventory, orders and counts (scoped to their properties). */
function job_is_store(?string $job): bool {
    return $job === 'storekeeper';
}

/**
 * Jobs with NO guest-facing desk: ops, till, storekeeper and gate security. They
 * get no guest messaging, no booking workspace actions, no AI assistant and take
 * no payments. Every "front-desk staff?" check goes through this one list, so a
 * new back-of-house job is added here once instead of in each page.
 */
function job_is_back_of_house(?string $job): bool {
    return job_is_ops($job) || job_is_pos($job) || job_is_store($job) || $job === 'security';
}

/** Staff whose job is the guest desk (front desk, or a job-less staff account). */
function is_frontdesk_staff(): bool {
    return is_staff() && !job_is_back_of_house(admin_job());
}

/**
 * Inventory & Assets pages (list, items, places, import, orders, count review):
 * the owner, managers and storekeepers — the last two scoped to their properties
 * by admin_venue_ids(). Reception and every other job never see them.
 */
function can_manage_inventory(): bool {
    return is_owner() || is_manager() || (is_staff() && job_is_store(admin_job()));
}

/**
 * The role string the stock-count rules (inv_can_count()) take for this account:
 * owner · manager · storekeeper · staff (ops jobs — housekeeping, laundry,
 * maintenance, gardening, driver — who count linen and supplies). '' = may not
 * count at all: reception, front-desk, gate and till staff.
 */
function inv_actor_role(): string {
    if (is_owner())   return 'owner';
    if (is_manager()) return 'manager';
    if (is_staff()) {
        $job = admin_job();
        if (job_is_store($job)) return 'storekeeper';
        if (job_is_ops($job))   return 'staff';
    }
    // The owner switched stock counting (or the stock pages) ON for this role in
    // "Access by role": count like staff — at their own properties only.
    if (access_section_granted('inventory-count.php') || access_section_granted('inventory-counts.php') || access_section_granted('inventory.php')) return 'staff';
    return '';
}

/** True once add_storekeeper_job.sql has widened the job_type CHECK (a catalog lookup). */
function storekeeper_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query(
            "SELECT 1 FROM pg_constraint
              WHERE conname = 'admin_users_job_type_check'
                AND pg_get_constraintdef(oid) LIKE '%storekeeper%'"
        )->fetchColumn();
    } catch (\Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * True once add_pos_job_types.sql has widened the job_type CHECK. Used to hide
 * the POS jobs in the Team forms pre-migration (the UPDATE would be rejected).
 * A catalog lookup, never a failing statement.
 */
function pos_jobs_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query(
            "SELECT 1 FROM pg_constraint
              WHERE conname = 'admin_users_job_type_check'
                AND pg_get_constraintdef(oid) LIKE '%kite%'"
        )->fetchColumn();
    } catch (\Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * Where an account lands after signing in, and where a page that refuses it
 * sends it back to: the Dashboard, for every role (admin/dashboard.php shapes
 * itself to the role — owner, manager, reception, front desk, ops, gate, till).
 * The dashboard is require_login() only, so this can never bounce in a loop.
 */
function admin_home_url(): string {
    return '/admin/dashboard.php';
}

/** Venue ids the current admin may see; null = all (owner). Managers and staff are scoped. */
function admin_venue_ids(): ?array {
    // HR looks after people at every property, so it is unscoped like the owner.
    if (is_owner() || is_hr()) return null;
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
    if (!is_owner() && !is_manager() && !access_page_granted()) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is only available to managers.']; header('Location: ' . admin_home_url()); exit; }
}

/**
 * People tier — owner, manager or HR. Guards employee profiles + documents and
 * attendance (times, leave, clock cards, kiosks, punch photos). Managers keep
 * their own properties (admin_venue_ids()); HR sees every property.
 */
function require_hr(): void {
    require_login();
    if (!is_owner() && !is_manager() && !is_hr() && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is only available to managers and HR.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/**
 * Reception tier — owner, manager or reception. Guards the surfaces managers
 * already had and reception now joins: Tasks and restaurant Reservations.
 * (Menus stays require_manager() — reception does no content editing.)
 */
function require_reception(): void {
    require_login();
    if (!is_owner() && !is_manager() && !is_reception() && !access_page_granted()) {
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
    if (!is_owner() && !is_reception() && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is only available to the owner and reception.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/**
 * Messages gate — owner, manager, or front-desk staff. Ops staff (housekeeping,
 * maintenance, gardening, driver) and gate-security get a focused interface with
 * no guest messaging, so they are bounced to their own home.
 */
/**
 * Accounts with NO guest messaging (and no AI availability assistant): back-of-house
 * staff and HR. HR is company-wide (admin_venue_ids() = null), so letting it in would
 * open every property's guest conversations. The ONE rule for require_frontdesk(),
 * admin/messages-poll.php, api/assistant.php, api/assistant-draft.php and the booking
 * workspace's no-messaging flag — never re-list the roles inline.
 */
function no_guest_messaging(): bool {
    return is_hr() || (is_staff() && job_is_back_of_house(admin_job()));
}

/**
 * Guest desk pages (admin/frontdesk.php, admin/concierge-desk.php): every login
 * except HR. HR is the people admin — no guests, bookings or requests — and is
 * company-wide, so the concierge desk would otherwise list every property's guest
 * requests to it. Back-of-house staff keep their access here (the front desk's
 * stock-count card, ops requests), unchanged.
 */
function require_guest_desk(): void {
    require_login();
    if (is_hr() && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That page isn’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

function require_frontdesk(): void {
    require_login();
    if (no_guest_messaging() && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Messages aren’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/**
 * Rates gate (admin/rates.php + its calendar fragment) — owner, manager or
 * reception: the people who quote or run a property. Prices are business data,
 * so ops, gate, till, storekeeper and front-desk staff are bounced home.
 */
function require_rates(): void {
    require_login();
    if (!is_owner() && !is_manager() && !is_reception() && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Rates aren’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/** Inventory gate — owner, manager or storekeeper (see can_manage_inventory()). Others are bounced home. */
function require_inventory(): void {
    require_login();
    if (!can_manage_inventory() && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Inventory isn’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/** Stock-count gate — anyone inv_actor_role() lets count. Reception, front desk, gate and till staff are bounced home. */
function require_stock_count(): void {
    require_login();
    if (inv_actor_role() === '' && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Stock counts aren’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/** Gate access — owner, manager, or gate-security staff. Others are bounced home. */
function require_gate(): void {
    require_login();
    if (!is_owner() && !is_manager() && !is_reception() && !(is_staff() && admin_job() === 'security') && !access_page_granted()) {
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
    if (!empty($_SESSION['admin_id'])) {
        try { audit_log('auth.logout', 'admin_user', (int)$_SESSION['admin_id'], ''); } catch (Throwable $e) {}
    }
    session_unset();
    session_destroy();
}

function is_rate_limited(string $email, string $ip): bool {
    $window = date('Y-m-d H:i:s', time() - 600); // 10 minutes
    // POS PIN attempts share this table (keyed 'pos:<id>', includes/pos-auth.php) and
    // are rate-limited there. They must not count here: a till sits on the same
    // property network as the office, so a few wrong PINs would otherwise lock
    // every admin sign-in from that IP.
    $row = db_query(
        "SELECT COUNT(*) AS cnt FROM login_attempts
         WHERE (email = :email OR ip_address = :ip)
           AND email NOT LIKE 'pos:%'
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
