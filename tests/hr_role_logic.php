<?php
declare(strict_types=1);
// The HR account type (Oct 2026): what HR sees and can open, and what stays the
// owner's. Pure rules always; the role CHECK round-trip in a rolled-back
// transaction when a database with add_hr_role.sql is reachable.
// Run: php tests/hr_role_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-nav.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/help-guides.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$env   = ['clockOn' => true, 'inv' => true, 'pos' => true, 'ai' => true, 'companies' => true, 'acctDocs' => true, 'acctIc' => true];
$nav   = fn(string $role, ?string $job = null) => admin_nav_resolve(admin_nav_definition(admin_nav_flags($role, $job, $env)), '');
$pages = fn(string $role) => help_visible_pages($nav($role));

// ── What HR sees ──
$hr = $pages('hr');
foreach (['dashboard.php', 'help.php', 'staff.php', 'employee.php', 'attendance.php', 'attendance-cards.php', 'attendance-devices.php', 'internal-messages.php', 'timetable.php'] as $p) {
    check("HR sees {$p}", in_array($p, $hr, true));
}
foreach (['holds.php', 'submissions.php', 'gantt.php', 'rates.php', 'reports.php', 'acct-documents.php', 'settings.php', 'emails.php',
          'venues.php', 'pos-sales.php', 'inventory.php', 'messages.php', 'frontdesk.php', 'tasks.php'] as $p) {
    check("HR does not see {$p}", !in_array($p, $hr, true));
}
check('managers keep Attendance but still not the staff directory', in_array('attendance.php', $pages('manager'), true) && !in_array('staff.php', $pages('manager'), true));
check('reception sees no HR pages', !array_intersect(['staff.php', 'employee.php', 'attendance.php'], $pages('reception')));
check('owner unchanged: staff + attendance', !array_diff(['staff.php', 'attendance.php'], $pages('owner')));

// ── Access by role ──
require_once __DIR__ . '/../includes/access.php';
check('HR is a role the owner can configure', (access_role_options([])['hr'] ?? '') === 'HR' && access_role_key('hr', null) === 'hr');
$sections = access_sections();
check('the Team page stays an owner-only section (cannot be granted to other roles)', ($sections['staff.php']['kind'] ?? '') === 'owner');
check('…but HR has it by default', access_resolve([], 'hr', $sections['staff.php'], true) === true);

// ── Dashboard ──
check('dashboard kind', dashboard_kind('hr', null) === 'hr');
$prof = dashboard_profile('hr', null, []);
check('dashboard profile says HR and what stays with the owner', $prof['label'] === 'HR' && str_contains($prof['cannot'], 'owner'));
$plan = dashboard_plan('hr', ['leave' => true]);
check('HR dashboard: leave tile + team chat', $plan['tiles'] === ['leave_pending', 'team_chat']);
check('…no leave tile before the leave migration', dashboard_plan('hr', [])['tiles'] === ['team_chat']);
$shortPages = array_map(fn($s) => basename(strtok($s[1], '?')), $plan['shortcuts']);
check('every HR shortcut opens a page HR can see', !array_diff($shortPages, $hr));
check('leave tile is described', isset(dashboard_tile_meta()['leave_pending']));

// ── Help ──
$g = array_column(help_visible_guides(help_guides(), $hr), 'slug');
check('HR reads the HR guides', !array_diff(['staff-directory', 'employee-documents', 'attendance-day', 'leave', 'clock-cards'], $g));
check('HR does not read booking or owner guides', !array_intersect(['convert-to-hold', 'set-rates', 'website-holds', 'emails'], $g));

// ── The pages enforce it (source checks — the nav only hides links) ──
$src = fn(string $p) => (string)file_get_contents(__DIR__ . '/../' . $p);
foreach (['admin/employee.php', 'admin/employee-file.php', 'admin/attendance.php', 'admin/attendance-cards.php',
          'admin/attendance-devices.php', 'admin/attendance-photo.php', 'api/clock-register.php'] as $p) {
    check("{$p} is guarded by require_hr()", (bool)preg_match('/^require_hr\(\);/m', $src($p)));
}
$staff = $src('admin/staff.php');
check('Team page: owner or HR only', str_contains($staff, "if (!is_owner() && !is_hr()) {"));
check('Team page: HR may only post directory (hr_) actions', str_contains($staff, "if (!is_owner() && !str_starts_with((string)\$action, 'hr_')) {"));
check('Team page: HR never gets the Login accounts / Access tabs', str_contains($staff, "\$tab = is_owner() && in_array("));
check('HR is unscoped like the owner', str_contains($src('includes/auth.php'), 'if (is_owner() || is_hr()) return null;'));
// Gates that are not nav-driven — HR must be refused even by a typed URL (Oct 2026 fix:
// Messages, the AI assistant and the concierge desk let any non-back-of-house login in).
$auth = $src('includes/auth.php');
check('no_guest_messaging() covers HR and back-of-house staff', str_contains($auth, "return is_hr() || (is_staff() && job_is_back_of_house(admin_job()));"));
check('require_frontdesk() asks no_guest_messaging()', str_contains($auth, 'if (no_guest_messaging() && !access_page_granted()) {'));
foreach (['admin/messages-poll.php', 'api/assistant.php', 'api/assistant-draft.php'] as $p) {
    check("{$p} refuses HR (no_guest_messaging)", str_contains($src($p), 'if (no_guest_messaging()) {'));
}
check('booking workspace hides messaging from HR', str_contains($src('admin/booking.php'), '$__noMessaging = no_guest_messaging();'));
check('require_guest_desk() refuses HR unless granted', str_contains($auth, 'if (is_hr() && !access_page_granted()) {'));
foreach (['admin/frontdesk.php', 'admin/concierge-desk.php'] as $p) {
    check("{$p} is guarded by require_guest_desk()", (bool)preg_match('/^require_guest_desk\(\);/m', $src($p)));
}
check('Login accounts list shows an HR account as HR (email, all properties)', str_contains($staff, "\$isHr        = (\$s['role'] ?? '') === 'hr';") && str_contains($staff, '<span class="badge badge--teal">HR</span>'));
check('migration widens the role CHECK to hr', str_contains($src('db/migrations/add_hr_role.sql'), "'owner','manager','reception','hr','staff'"));

// ── DB: an HR account can be stored (rolled back) ──
try {
    $db = db();
    if (!hr_role_supported()) { echo "SKIP  DB round-trip (add_hr_role.sql not applied)\n"; }
    else {
        $db->beginTransaction();
        db_query("INSERT INTO admin_users (name, email, password_hash, role, is_active) VALUES ('ZZ HR', 'zz-hr@example.test', 'x', 'hr', TRUE)");
        check('db: an HR account saves', (string)db_query("SELECT role FROM admin_users WHERE email = 'zz-hr@example.test'")->fetchColumn() === 'hr');
        $db->rollBack();
    }
} catch (Throwable $e) {
    echo "SKIP  DB round-trip (no database: " . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
