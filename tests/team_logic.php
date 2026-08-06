<?php
declare(strict_types=1);
// Team roles & job types — auth helpers. Run: php tests/team_logic.php
// Requires the add_team_roles.sql migration (role='manager' + job_type) applied.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';   // pulls in includes/team.php (routing + assignee helpers)

// Start the session up front: the auth helpers call session_init() internally,
// and a session_start() firing *after* we set $_SESSION['admin_id'] would wipe it.
session_init();

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// Two venues + a unit belonging to the first (for hold scoping). Skip if the
// data model isn't seeded far enough to exercise venue scoping.
$vrows = db()->query("SELECT id FROM venues ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($vrows) < 1) { echo "SKIP  no venues — cannot test team scoping\n\nALL PASS\n"; exit(0); }
$V1 = (int)$vrows[0];
$V2 = isset($vrows[1]) ? (int)$vrows[1] : $V1;

$unit = db()->query("SELECT u.id FROM units u JOIN rooms r ON r.id = u.room_id WHERE r.venue_id = {$V1} LIMIT 1")->fetchColumn();

// ── Fixtures (ZZ-prefixed, cleaned up at the end) ──────────────────────────
// Clear any leftovers from an interrupted prior run so re-running is safe
// (admin_user email is UNIQUE — stale rows would break the inserts below).
db_query("DELETE FROM admin_users WHERE name LIKE 'ZZ Team %' OR email LIKE 'zz-mgr%@example.com'");

$mk_admin = function (string $role, ?string $job, ?string $email = null) : int {
    db_query(
        "INSERT INTO admin_users (name, role, job_type, email, access_code, is_active)
         VALUES (:n, :r, :j, :e, :c, TRUE)",
        [':n' => 'ZZ Team ' . $role . ($job ? "/$job" : ''), ':r' => $role, ':j' => $job,
         ':e' => $email, ':c' => gen_staff_code()]
    );
    return (int)db()->lastInsertId();
};

$owner    = $mk_admin('owner',   null);
$mgrA     = $mk_admin('manager', null, 'zz-mgra@example.com');   // scoped to V1
$mgrB     = $mk_admin('manager', null, 'zz-mgrb@example.com');   // no venues
$sHouse   = $mk_admin('staff',   'housekeeping');
$sSecurity= $mk_admin('staff',   'security');
$sDriver  = $mk_admin('staff',   'driver');
$sFront   = $mk_admin('staff',   'frontdesk');
$sNull    = $mk_admin('staff',   null);                          // legacy staff → frontdesk

db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a'=>$mgrA, ':v'=>$V1]);
db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a'=>$sHouse, ':v'=>$V1]);

// A confirmed hold at V1, for staff_can_hold scoping (only if we have a unit).
$hold = null;
if ($unit) {
    db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
              VALUES (:u,'2031-07-01','2031-07-03','ZZ Team Guest','zz@example.com','confirmed', NOW())",
             [':u'=>(int)$unit]);
    $hold = (int)db()->lastInsertId();
}

/** Log in as a given admin id for the assertions that follow. */
function as_admin(int $id): void { $_SESSION['admin_id'] = $id; }

// ── Role predicates are mutually exclusive ─────────────────────────────────
as_admin($owner);
check('owner: is_owner',            is_owner() === true);
check('owner: not manager/staff',   !is_manager() && !is_staff());
check('owner: admin_venue_ids null', admin_venue_ids() === null);
check('owner: admin_job null',      admin_job() === null);
check('owner: home = dashboard',    admin_home_url() === '/admin/dashboard.php');

as_admin($mgrA);
check('manager: is_manager',        is_manager() === true);
check('manager: not owner/staff',   !is_owner() && !is_staff());
check('manager: venue set = [V1]',  admin_venue_ids() === [$V1]);
check('manager: admin_job null',    admin_job() === null);
check('manager: home = frontdesk',  admin_home_url() === '/admin/frontdesk.php');
if ($hold) check('manager A can act on V1 hold', staff_can_hold($hold) === true);

as_admin($mgrB);
check('manager B: venue set empty', admin_venue_ids() === []);
if ($hold) check('manager B cannot act on V1 hold', staff_can_hold($hold) === false);

// ── Staff job routing ──────────────────────────────────────────────────────
as_admin($sHouse);
check('housekeeping: is_staff',        is_staff() === true);
check('housekeeping: job',             admin_job() === 'housekeeping');
check('housekeeping: job_is_ops',      job_is_ops(admin_job()) === true);
check('housekeeping: home = mywork',   admin_home_url() === '/admin/mywork.php');
if ($hold) check('housekeeping (V1) can act on V1 hold', staff_can_hold($hold) === true);

as_admin($sSecurity);
check('security: home = gate',         admin_home_url() === '/admin/gate.php');
check('security: not ops',             job_is_ops(admin_job()) === false);
if ($hold) check('security (no venues) cannot act on V1 hold', staff_can_hold($hold) === false);

as_admin($sDriver);
check('driver: job_is_ops',            job_is_ops(admin_job()) === true);
check('driver: home = mywork',         admin_home_url() === '/admin/mywork.php');

as_admin($sFront);
check('frontdesk: home = frontdesk',   admin_home_url() === '/admin/frontdesk.php');
check('frontdesk: not ops',            job_is_ops(admin_job()) === false);

as_admin($sNull);
check('null job treated as frontdesk (job)',  admin_job() === 'frontdesk');
check('null job treated as frontdesk (home)', admin_home_url() === '/admin/frontdesk.php');

// ── Phase 2: kind → job routing map (pure) ─────────────────────────────────
check('route housekeeping', request_job_for_kind('housekeeping') === 'housekeeping');
check('route amenities → housekeeping', request_job_for_kind('amenities') === 'housekeeping');
check('route laundry → housekeeping',   request_job_for_kind('laundry') === 'housekeeping');
check('route maintenance',  request_job_for_kind('maintenance') === 'maintenance');
check('route transfer → driver', request_job_for_kind('transfer') === 'driver');
check('route tour → null (unrouted)',       request_job_for_kind('tour') === null);
check('route restaurant → null (unrouted)', request_job_for_kind('restaurant') === null);

// ── Phase 2: default_assignee_for (job_type is new, so only ZZ fixtures match) ─
// $sHouse is the sole housekeeping staffer at V1 → auto-assigns to them.
check('auto-assign: single housekeeper at V1', default_assignee_for('housekeeping', $V1) === $sHouse);
check('auto-assign: unrouted kind → null',     default_assignee_for('other', $V1) === null);
check('auto-assign: null venue → null',        default_assignee_for('housekeeping', null) === null);
check('auto-assign: no driver at V1 → null',   default_assignee_for('transfer', $V1) === null);

// Two housekeepers at V1 → ambiguous → null (manager routes it).
$sHouse2 = $mk_admin('staff', 'housekeeping');
db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a'=>$sHouse2, ':v'=>$V1]);
check('auto-assign: two housekeepers → null', default_assignee_for('housekeeping', $V1) === null);

// ── Phase 2: assignability helpers ─────────────────────────────────────────
if ($hold) {
    check('team_can_be_assigned: housekeeper at V1 hold', team_can_be_assigned($sHouse, $hold) === true);
    check('team_can_be_assigned: driver (no venue) → false', team_can_be_assigned($sDriver, $hold) === false);
    check('team_can_be_assigned: 0 id → false', team_can_be_assigned(0, $hold) === false);
}
$cand = array_map(fn($r) => (int)$r['id'], assignable_team_for_venue($V1));
check('assignable list includes manager A', in_array($mgrA, $cand, true));
check('assignable list includes housekeeper', in_array($sHouse, $cand, true));
check('assignable list excludes owner',       !in_array($owner, $cand, true));

check('team_can_take_venue: housekeeper at V1', team_can_take_venue($sHouse, $V1) === true);
check('team_can_take_venue: driver (no venue)', team_can_take_venue($sDriver, $V1) === false);

// ── Phase 3: tasks (only when the tasks table is migrated in) ──────────────
$task = null;
if (tasks_supported()) {
    db_query("INSERT INTO tasks (venue_id, assigned_to, job_type, title, detail, status, created_by)
              VALUES (:v,:a,'housekeeping','ZZ Team Task','clean the pool','todo',:cb)",
             [':v'=>$V1, ':a'=>$sHouse, ':cb'=>$owner]);
    $task = (int)db()->lastInsertId();

    $inV1  = array_map(fn($r)=>(int)$r['id'], fetch_tasks([$V1], ['status'=>'open']));
    $inBig = array_map(fn($r)=>(int)$r['id'], fetch_tasks([$V1 + 999999], ['status'=>'open']));
    check('task appears in its venue scope',        in_array($task, $inV1, true));
    check('task hidden from a different venue scope', !in_array($task, $inBig, true));

    $mine = array_map(fn($r)=>(int)$r['id'], mywork_tasks($sHouse, ['todo','in_progress']));
    check('task appears in assignee My Work',   in_array($task, $mine, true));
    $notMine = array_map(fn($r)=>(int)$r['id'], mywork_tasks($sDriver, ['todo','in_progress']));
    check('task hidden from a non-assignee',    !in_array($task, $notMine, true));

    // Status filter: 'done' shouldn't include a 'todo' task.
    $doneIds = array_map(fn($r)=>(int)$r['id'], fetch_tasks([$V1], ['status'=>'done']));
    check('todo task excluded from done filter', !in_array($task, $doneIds, true));
} else {
    echo "SKIP  tasks table not migrated — skipping task assertions\n";
}

// ── Phase 4: gate visitors (only when the visitors table is migrated in) ───
$visitor = null;
if (visitors_supported()) {
    $today = (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
    db_query("INSERT INTO visitors (venue_id, visitor_name, visiting, logged_by)
              VALUES (:v,'ZZ Team Visitor','room 1',:lb)", [':v'=>$V1, ':lb'=>$owner]);
    $visitor = (int)db()->lastInsertId();

    $openIds = array_map(fn($r)=>(int)$r['id'], fetch_visitors([$V1], true));
    check('visitor on site appears in open list', in_array($visitor, $openIds, true));
    $otherVenue = array_map(fn($r)=>(int)$r['id'], fetch_visitors([$V1 + 999999], true));
    check('visitor hidden from another venue scope', !in_array($visitor, $otherVenue, true));

    // Sign out → drops from the open list but stays in today's log.
    db_query("UPDATE visitors SET time_out = NOW() WHERE id = :id", [':id'=>$visitor]);
    $openAfter = array_map(fn($r)=>(int)$r['id'], fetch_visitors([$V1], true));
    check('signed-out visitor leaves the open list', !in_array($visitor, $openAfter, true));
    $todayLog = array_map(fn($r)=>(int)$r['id'], fetch_visitors([$V1], false, $today));
    check('signed-out visitor stays in today log', in_array($visitor, $todayLog, true));
} else {
    echo "SKIP  visitors table not migrated — skipping gate assertions\n";
}

// ── Cleanup (admin_user_venues cascade on admin_users delete) ──────────────
if ($visitor) db_query("DELETE FROM visitors WHERE id = :v", [':v'=>$visitor]);
if ($task) db_query("DELETE FROM tasks WHERE id = :t", [':t'=>$task]);
if ($hold) db_query("DELETE FROM holds WHERE id = :h", [':h'=>$hold]);
db_query("DELETE FROM admin_users WHERE id IN (:a,:b,:c,:d,:e,:f,:g,:h,:i)", [
    ':a'=>$owner, ':b'=>$mgrA, ':c'=>$mgrB, ':d'=>$sHouse,
    ':e'=>$sSecurity, ':f'=>$sDriver, ':g'=>$sFront, ':h'=>$sNull, ':i'=>$sHouse2,
]);
unset($_SESSION['admin_id']);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
