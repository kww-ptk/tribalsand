<?php
declare(strict_types=1);
// Reception role — auth predicates and property scoping.
// Run: php tests/reception_role.php
// Requires db/migrations/add_reception_role.sql applied.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/checkin.php';   // can_view_guest_docs()

// Start the session up front — the auth helpers call session_init() internally,
// and a session_start() firing after we set $_SESSION['admin_id'] would wipe it.
session_init();

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

if (!reception_supported()) {
    echo "SKIP  add_reception_role migration not applied — nothing to test\n\nALL PASS\n";
    exit(0);
}
check('reception_supported() true once migrated', reception_supported() === true);

// Two venues, each with a room, plus a unit at the first (for hold scoping).
$vrows = db()->query('SELECT id FROM venues ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($vrows) < 2) { echo "SKIP  need two venues to test scoping\n\nALL PASS\n"; exit(0); }
$V1 = (int)$vrows[0];
$V2 = (int)$vrows[1];

$room1 = db()->query("SELECT id FROM rooms WHERE venue_id = {$V1} LIMIT 1")->fetchColumn();
$room2 = db()->query("SELECT id FROM rooms WHERE venue_id = {$V2} LIMIT 1")->fetchColumn();
$unit1 = db()->query("SELECT u.id FROM units u JOIN rooms r ON r.id = u.room_id WHERE r.venue_id = {$V1} LIMIT 1")->fetchColumn();

// ── Fixtures (ZZ-prefixed; removed at the end) ─────────────────────────────
db_query("DELETE FROM admin_users WHERE name LIKE 'ZZ Rcp %' OR email LIKE 'zz-rcp%@example.com'");
db_query("DELETE FROM submissions WHERE guest_name LIKE 'ZZ Rcp %'");

$mk = function (string $role, ?string $email, ?string $job = null): int {
    db_query(
        "INSERT INTO admin_users (name, role, job_type, email, access_code, is_active)
         VALUES (:n, :r, :j, :e, :c, TRUE)",
        [':n' => 'ZZ Rcp ' . $role, ':r' => $role, ':j' => $job, ':e' => $email, ':c' => gen_staff_code()]
    );
    return (int)db()->lastInsertId();
};

$owner = $mk('owner',     'zz-rcp-owner@example.com');
$rcpA  = $mk('reception', 'zz-rcp-a@example.com');      // scoped to V1
$rcpB  = $mk('reception', 'zz-rcp-b@example.com');      // scoped to V2
$rcpN  = $mk('reception', 'zz-rcp-n@example.com');      // no venues at all
$mgr   = $mk('manager',   'zz-rcp-mgr@example.com');

db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a' => $rcpA, ':v' => $V1]);
db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a' => $rcpB, ':v' => $V2]);

$hold = null;
if ($unit1) {
    db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
              VALUES (:u,'2031-08-01','2031-08-03','ZZ Rcp Guest','zz-rcp@example.com','confirmed', NOW())",
             [':u' => (int)$unit1]);
    $hold = (int)db()->lastInsertId();
}

// Three submissions: one per venue, plus a property-less contact enquiry.
$mkSub = function (string $type, $roomId): int {
    db_query(
        "INSERT INTO submissions (type, room_id, guest_name, guest_email, message)
         VALUES (:t, :r, 'ZZ Rcp Lead', 'zz-rcp-lead@example.com', 'test')",
        [':t' => $type, ':r' => $roomId]
    );
    return (int)db()->lastInsertId();
};
$sub1    = $room1 ? $mkSub('enquiry', (int)$room1) : 0;
$sub2    = $room2 ? $mkSub('enquiry', (int)$room2) : 0;
$subNone = $mkSub('contact', null);

function as_admin(int $id): void { $_SESSION['admin_id'] = $id; }

// ── Role predicates are mutually exclusive ─────────────────────────────────
as_admin($rcpA);
check('reception: is_reception',              is_reception() === true);
check('reception: not owner/manager/staff',   !is_owner() && !is_manager() && !is_staff());
check('reception: admin_job() is null',       admin_job() === null);
check('reception: job_is_ops(null) false',    job_is_ops(admin_job()) === false);
check('reception: lands on Front desk',       admin_home_url() === '/admin/frontdesk.php');

as_admin($owner);
check('owner: is_reception false',            is_reception() === false);
as_admin($mgr);
check('manager: is_reception false',          is_reception() === false);

// ── Venue scoping ──────────────────────────────────────────────────────────
as_admin($owner);
check('owner: admin_venue_ids() is null',     admin_venue_ids() === null);
check('owner: venue_scope_sql() unrestricted', venue_scope_sql('r.venue_id') === '');

as_admin($rcpA);
check('reception: admin_venue_ids() scoped',  admin_venue_ids() === [$V1]);
check('reception: venue_scope_sql() filters', venue_scope_sql('r.venue_id') === "r.venue_id IN ({$V1})");

as_admin($rcpN);
check('venue-less reception: empty scope',    admin_venue_ids() === []);
check('venue-less reception: sees nothing',   venue_scope_sql('r.venue_id') === '1=0');

// ── Hold scoping ───────────────────────────────────────────────────────────
if ($hold) {
    as_admin($owner);
    check('owner may act on any hold',        staff_can_hold($hold) === true);
    as_admin($rcpA);
    check('reception may act on own hold',    staff_can_hold($hold) === true);
    check('reception may view guest docs',    can_view_guest_docs($hold) === true);
    as_admin($rcpB);
    check('reception blocked on other venue', staff_can_hold($hold) === false);
    check('reception blocked from docs',      can_view_guest_docs($hold) === false);
    as_admin($rcpN);
    check('venue-less reception blocked',     staff_can_hold($hold) === false);
} else {
    echo "SKIP  no unit at the first venue — skipping hold scoping\n";
}

// ── Submission scoping ─────────────────────────────────────────────────────
// Room enquiries scope by venue; a property-less contact enquiry stays visible
// to every account, including one assigned no venues.
if ($sub1 && $sub2) {
    as_admin($owner);
    check('owner sees every submission',
        submission_in_scope($sub1) && submission_in_scope($sub2) && submission_in_scope($subNone));
    as_admin($rcpA);
    check('reception sees own venue enquiry',   submission_in_scope($sub1) === true);
    check('reception blocked on other venue',   submission_in_scope($sub2) === false);
    check('reception sees property-less lead',  submission_in_scope($subNone) === true);
    as_admin($rcpN);
    check('venue-less: no room enquiries',      submission_in_scope($sub1) === false);
    check('venue-less: still sees general lead', submission_in_scope($subNone) === true);
} else {
    echo "SKIP  need a room at each venue — skipping submission scoping\n";
}
as_admin($rcpA);
check('unknown submission id is out of scope', submission_in_scope(0) === false);

// ── Reception cannot sign in with a staff access code ──────────────────────
$code = (string)db_query('SELECT access_code FROM admin_users WHERE id = :id', [':id' => $rcpA])->fetchColumn();
db_query("DELETE FROM login_attempts WHERE email = :e", [':e' => $code]);   // avoid a stale rate limit
$before = $_SESSION['admin_id'] ?? null;
check('login_staff refuses a reception code', login_staff($code, '127.0.0.1') === false);
db_query("DELETE FROM login_attempts WHERE email = :e", [':e' => $code]);
$_SESSION['admin_id'] = $before;

// ── Cleanup (admin_user_venues cascades on admin_users delete) ─────────────
if ($hold) db_query('DELETE FROM holds WHERE id = :h', [':h' => $hold]);
db_query("DELETE FROM submissions WHERE guest_name LIKE 'ZZ Rcp %'");
db_query('DELETE FROM admin_users WHERE id IN (:a,:b,:c,:d,:e)',
    [':a' => $owner, ':b' => $rcpA, ':c' => $rcpB, ':d' => $rcpN, ':e' => $mgr]);
unset($_SESSION['admin_id']);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
