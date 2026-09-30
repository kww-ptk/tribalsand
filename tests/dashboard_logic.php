<?php
declare(strict_types=1);
// The admin Dashboard (includes/dashboard.php, admin/dashboard.php): which role
// sees what, and that every lookup is scoped and fails soft.
// Run: php tests/dashboard_logic.php
// Pure rules always run. The data block needs a database and is read-only, except
// for fixtures created inside ONE transaction that is rolled back.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
session_init();   // before any output: the role helpers read the session
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/task-calendar.php';
require_once __DIR__ . '/../includes/internal-messages.php';
require_once __DIR__ . '/../includes/submission-notes.php';
require_once __DIR__ . '/../includes/submission-status.php';
require_once __DIR__ . '/../includes/pos-support.php';
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_once __DIR__ . '/../includes/dashboard.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Which dashboard ─────────────────────────────────────────────────────────
check('kind: owner / manager / reception by role', dashboard_kind('owner', null) === 'owner'
    && dashboard_kind('manager', null) === 'manager' && dashboard_kind('reception', null) === 'reception');
check('kind: staff by job', dashboard_kind('staff', 'frontdesk') === 'frontdesk' && dashboard_kind('staff', 'security') === 'security'
    && dashboard_kind('staff', 'housekeeping') === 'ops' && dashboard_kind('staff', 'laundry') === 'ops'
    && dashboard_kind('staff', 'driver') === 'ops' && dashboard_kind('staff', 'kite') === 'pos' && dashboard_kind('staff', 'spa') === 'pos');
check('kind: staff with no job type is front desk (same rule as admin_job())', dashboard_kind('staff', null) === 'frontdesk');
check('everyone lands on the dashboard', admin_home_url() === '/admin/dashboard.php');

check('greeting follows the Nairobi hour', dashboard_greeting(7) === 'Good morning' && dashboard_greeting(12) === 'Good afternoon'
    && dashboard_greeting(16) === 'Good afternoon' && dashboard_greeting(17) === 'Good evening' && dashboard_greeting(23) === 'Good evening');

// ── Who you are, what you can do ────────────────────────────────────────────
$p = dashboard_profile('owner', null, []);
check('owner: label, every property, a can-do list, nothing withheld', $p['label'] === 'Owner' && str_contains($p['summary'], 'every property')
    && count($p['can']) >= 4 && $p['cannot'] === '');
$p = dashboard_profile('manager', null, ['Zuri', 'Maya Kobe']);
check('manager: names their properties', str_contains($p['summary'], 'Zuri and Maya Kobe') && $p['label'] === 'Manager');
check('manager: says what the owner keeps', str_contains($p['cannot'], 'owner'));
check('three properties read as a list', str_contains(dashboard_profile('reception', null, ['A', 'B', 'C'])['summary'], 'A, B and C'));
check('no property assigned: says so instead of an empty name', str_contains(dashboard_profile('manager', null, [])['summary'], 'no property yet'));
check('staff are labelled by their job', dashboard_profile('ops', 'gardening', ['Zuri'])['label'] === 'Gardening'
    && dashboard_profile('security', 'security', ['Zuri'])['label'] === 'Gate security'
    && dashboard_profile('pos', 'spa', ['Zuri'])['label'] === 'Salon & Spa'
    && dashboard_profile('frontdesk', null, ['Zuri'])['label'] === 'Front desk');
foreach (['owner', 'manager', 'reception', 'frontdesk', 'ops', 'security', 'pos'] as $k) {
    $pp = dashboard_profile($k, null, ['Zuri']);
    check("{$k}: has a summary, a badge and at least three things they can do", $pp['summary'] !== '' && $pp['badge'] !== '' && count($pp['can']) >= 3);
}

// ── What each dashboard shows ───────────────────────────────────────────────
$all  = ['reservations' => true, 'inventory' => true, 'pos' => true, 'tasks' => true, 'visitors' => true, 'ai' => true];
$meta = dashboard_tile_meta();
$plan = fn(string $k) => dashboard_plan($k, $all);

check('owner: bookings, guests, team and stock tiles', $plan('owner')['tiles'] === ['requests', 'enquiries', 'conflicts', 'guest_requests', 'messages',
    'reservations', 'team_overdue', 'counts_review', 'team_chat']);
check('owner: today, money, own tasks, latest enquiries', $plan('owner')['sections'] === ['today', 'money', 'my_tasks', 'recent_enquiries']);
check('manager: no booking-desk tiles (requests, enquiries, conflicts belong to owner + reception)',
    !array_intersect(['requests', 'enquiries', 'conflicts'], $plan('manager')['tiles']) && in_array('money', $plan('manager')['sections'], true));
check('reception: booking-desk tiles but no money', in_array('requests', $plan('reception')['tiles'], true) && in_array('conflicts', $plan('reception')['tiles'], true)
    && !in_array('money', $plan('reception')['sections'], true) && !in_array('team_overdue', $plan('reception')['tiles'], true));
check('front desk: guest requests + messages only, and today', $plan('frontdesk')['tiles'] === ['guest_requests', 'messages', 'team_chat']
    && $plan('frontdesk')['sections'] === ['today', 'my_tasks']);
check('ops: their tasks and the counts due; no guest data', $plan('ops')['tiles'] === ['team_chat'] && $plan('ops')['sections'] === ['my_tasks', 'counts_due']);
check('security: arrivals and visitors, no guest messages', $plan('security')['tiles'] === ['visitors', 'team_chat'] && $plan('security')['sections'] === ['today', 'my_tasks']);
check('till staff: the till card first', $plan('pos')['sections'] === ['till', 'my_tasks'] && $plan('pos')['tiles'] === ['team_chat']);
foreach (['owner', 'manager', 'reception', 'frontdesk', 'ops', 'security', 'pos'] as $k) {
    check("{$k}: every tile is defined and everyone has the team chat", !array_diff($plan($k)['tiles'], array_keys($meta)) && in_array('team_chat', $plan($k)['tiles'], true));
}

$bare = dashboard_plan('owner', []);
check('a module that is not installed drops its tiles and sections', !array_intersect(['reservations', 'counts_review', 'team_overdue'], $bare['tiles'])
    && !in_array('my_tasks', $bare['sections'], true) && in_array('requests', $bare['tiles'], true));
check('till card only for an account that can sell', !in_array('till', dashboard_plan('pos', ['tasks' => true])['sections'], true));
check('AI assistant shortcut only with a key, and never for ops', in_array(['AI assistant', '/admin/assistant.php', 'sparkle'], $plan('owner')['shortcuts'], true)
    && !in_array('/admin/assistant.php', array_column(dashboard_plan('owner', ['ai' => false])['shortcuts'], 1), true)
    && !in_array('/admin/assistant.php', array_column($plan('ops')['shortcuts'], 1), true));

// Every tile and shortcut points at a real admin page.
$targets = array_column($meta, 'href');
foreach (['owner', 'manager', 'reception', 'frontdesk', 'ops', 'security', 'pos'] as $k) foreach ($plan($k)['shortcuts'] as $sc) $targets[] = $sc[1];
$dead = array_filter(array_unique($targets), fn($h) => !is_file(__DIR__ . '/..' . strtok($h, '?')));
check('every tile and shortcut points at an existing page' . ($dead ? ' — ' . implode(', ', $dead) : ''), !$dead);

// ── The bento pieces ────────────────────────────────────────────────────────
foreach (['owner', 'manager', 'reception', 'frontdesk', 'ops', 'security', 'pos'] as $k) {
    $pp = dashboard_profile($k, null, ['Zuri']);
    check("{$k}: short 'you can' chips, each a few words", count($pp['chips']) >= 4 && !array_filter($pp['chips'], fn($c) => strlen($c) > 22));
    check("{$k}: every shortcut has a label, a page and a drawn icon", !array_filter($plan($k)['shortcuts'],
        fn($sc) => count($sc) !== 3 || str_contains(dashboard_icon($sc[2]), '<circle cx="12" cy="12" r="2"/>')));
}
check('icon: an unknown name draws a dot instead of nothing', str_contains(dashboard_icon('nope'), '<circle cx="12" cy="12" r="2"/>') && str_contains(dashboard_icon('plus', 20), 'width="20"'));

$q = dashboard_queue(['requests' => 2, 'conflicts' => 0, 'team_chat' => 4, 'messages' => 1, 'counts_review' => null, 'guest_requests' => 0], $meta);
check('queue: action items first, then informative ones, then unreadable', array_column($q['rows'], 'key') === ['requests', 'messages', 'team_chat', 'counts_review']);
check('queue: only action items are hot', array_column($q['rows'], 'hot') === [true, true, false, false]);
check('queue: zeros fold into the all-clear line', $q['clear'] === ['Calendar conflicts', 'Guest requests']);
check('queue: a tile that could not be read is never reported as clear', !in_array('Stock counts', $q['clear'], true) && $q['rows'][3]['n'] === null);
check('queue: everything at zero → no rows', dashboard_queue(['requests' => 0, 'team_chat' => 0], $meta)['rows'] === []);

check('headline: arrivals and what needs you', dashboard_headline('owner', 3, 2, 4, 0, 0) === '3 guests arrive today and 4 things need you.');
check('headline: singular forms', dashboard_headline('reception', 1, 0, 1, 0, 0) === '1 guest arrives today and 1 thing needs you.');
check('headline: a quiet day', dashboard_headline('frontdesk', 0, 0, 0, 0, 0) === 'No arrivals today and nothing is waiting on you.');
check('headline: gate counts who comes and goes', dashboard_headline('security', 2, 1, 0, 0, 0) === '2 guests arrive today, 1 leaves.');
check('headline: task roles hear about their tasks', dashboard_headline('ops', null, null, 0, 2, 1) === 'You have 2 tasks today, and 1 overdue.'
    && dashboard_headline('ops', null, null, 0, 0, 0) === 'No tasks for you today.' && dashboard_headline('ops', null, null, 0, 0, 2) === 'Nothing new today, and 2 overdue.');
check('headline: the till', str_starts_with(dashboard_headline('pos', null, null, 0, 1, 0), 'The till is ready when you are. You have 1 task today'));

check('initials: two names, one name, extra spaces, empty', dashboard_initials('Amina Njoroge') === 'AN' && dashboard_initials('cher') === 'CH'
    && dashboard_initials('  Jean  Luc   Picard ') === 'JP' && dashboard_initials('') === '?' && dashboard_initials('Édith Piaf') === 'ÉP');
check('occupancy: whole percent, capped, null without rooms', dashboard_occupancy_pct(14, 22) === 64 && dashboard_occupancy_pct(0, 10) === 0
    && dashboard_occupancy_pct(30, 20) === 100 && dashboard_occupancy_pct(3, 0) === null);

$rows = [['check_in' => '2026-09-02', 'gross_amount' => 100, 'currency' => 'USD'], ['check_in' => '2026-09-02', 'gross_amount' => 50000, 'currency' => 'KES'],
         ['check_in' => '2026-09-04', 'gross_amount' => 20000, 'currency' => 'kes'], ['check_in' => '2026-08-31', 'gross_amount' => 99999, 'currency' => 'KES']];
$ser = dashboard_daily_series($rows, '2026-09-01', '2026-09-05');
check('bars: one currency only — the biggest — never a mix', $ser['currency'] === 'KES' && $ser['days']['2026-09-02'] === 50000.0 && $ser['days']['2026-09-04'] === 20000.0);
check('bars: a day for every date in the window, days outside it ignored', array_keys($ser['days']) === ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05']
    && $ser['max'] === 50000.0);
check('bars: nothing to draw without revenue', dashboard_daily_series([], '2026-09-01', '2026-09-05')['days'] === []
    && dashboard_daily_series([['check_in' => '2026-09-02', 'gross_amount' => 0, 'currency' => 'USD']], '2026-09-01', '2026-09-05')['max'] === 0.0);

// ── The heading only says "nothing waiting" when that is true ───────────────
check('heading: a warn tile above zero', dashboard_heading(['requests' => 2, 'team_chat' => 0], $meta, 0, 0) === 'Needs your attention');
check('heading: an info tile alone does not nag', dashboard_heading(['team_chat' => 5, 'counts_review' => 1], $meta, 0, 0) === 'Nothing is waiting on you');
check('heading: an overdue task of your own', dashboard_heading(['team_chat' => 0], $meta, 1, 0) === 'Needs your attention');
check('heading: tasks today but nothing late', dashboard_heading(['team_chat' => 0], $meta, 0, 3) === 'Today');
check('heading: all clear', dashboard_heading(['requests' => 0, 'messages' => 0], $meta, 0, 0) === 'Nothing is waiting on you');
check('heading: an unreadable tile (null) is not counted as waiting', dashboard_heading(['requests' => null], $meta, 0, 0) === 'Nothing is waiting on you');

// ── Fail-soft wrapper ───────────────────────────────────────────────────────
check('dashboard_safe: returns the value', dashboard_safe(fn() => 7) === 7);
$log = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');   // the two deliberate failures below are logged
check('dashboard_safe: an exception becomes the fallback, not an error',
    dashboard_safe(function () { throw new RuntimeException('boom'); }, 'x') === 'x' && dashboard_safe(function () { throw new Error('boom'); }) === null);
ini_set('error_log', (string)$log);

// ── Data (needs a database) ─────────────────────────────────────────────────
try { db()->query('SELECT 1'); $haveDb = (bool)db()->query("SELECT to_regclass('public.holds')")->fetchColumn(); }
catch (Throwable $e) { $haveDb = false; }
if (!$haveDb) {
    echo "SKIP  no database — data checks skipped\n";
} else {
    db()->beginTransaction();
    try {
        $today = frontdesk_today_ymd();
        $v     = db()->query('SELECT id FROM venues ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);

        // Owner scope (null) reads every tile without error.
        $vals = dashboard_tile_values(array_keys($meta), null, 0, $today);
        $unreadable = array_keys(array_filter($vals, fn($n) => $n === null));
        check('every tile can be read on this database' . ($unreadable ? ' — not read: ' . implode(', ', $unreadable) : ''),
            count($vals) === count($meta) && !array_diff($unreadable, ['reservations', 'counts_review', 'team_overdue', 'visitors']));
        check('tile values are whole numbers ≥ 0', !array_filter($vals, fn($n) => $n !== null && (!is_int($n) || $n < 0)));
        $nowhere = dashboard_tile_values(['requests', 'conflicts', 'guest_requests', 'team_overdue'], [], 0, $today);
        check('an account assigned to no property counts nothing', !array_filter($nowhere, fn($n) => $n !== null && $n !== 0));
        check('an unknown tile key is null, not an error', dashboard_tile_values(['nope'], null, 0, $today) === ['nope' => null]);

        if (count($v) >= 1) {
            $unit = db_query('SELECT u.id FROM units u JOIN rooms r ON r.id = u.room_id WHERE r.venue_id = :v AND u.is_active LIMIT 1', [':v' => $v[0]])->fetchColumn();
            if ($unit) {
                $before = dashboard_today(null, $today);
                db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                          VALUES (:u, :ci, :co, 'ZZ Dash Arrival', 'zz@example.test', 'confirmed', NOW())",
                    [':u' => $unit, ':ci' => $today, ':co' => date('Y-m-d', strtotime($today . ' +2 days'))]);
                $after = dashboard_today(null, $today);
                check('today: a confirmed arrival shows up', count($after['arriving']) === count($before['arriving']) + 1
                    && in_array('ZZ Dash Arrival', array_column($after['arriving'], 'guest_name'), true));
                check('today: scoped to the account\'s properties — another property sees nothing of it',
                    !in_array('ZZ Dash Arrival', array_column(dashboard_today([-1], $today)['arriving'], 'guest_name'), true)
                    && in_array('ZZ Dash Arrival', array_column(dashboard_today([(int)$v[0]], $today)['arriving'], 'guest_name'), true));

                // A web request (pending, 24 h expiry) counts; an expired one does not.
                $r0 = dashboard_tile_values(['requests'], null, 0, $today)['requests'];
                db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                          VALUES (:u, '2099-03-01', '2099-03-03', 'ZZ Dash Pending', 'zz@example.test', 'pending', NOW() + INTERVAL '1 day'),
                                 (:u, '2099-04-01', '2099-04-03', 'ZZ Dash Lapsed',  'zz@example.test', 'pending', NOW() - INTERVAL '1 hour')", [':u' => $unit]);
                check('requests: a live request counts, a lapsed one does not', dashboard_tile_values(['requests'], null, 0, $today)['requests'] === $r0 + 1);
                check('requests: only for the properties in scope', dashboard_tile_values(['requests'], [-1], 0, $today)['requests'] === 0
                    && dashboard_tile_values(['requests'], [(int)$v[0]], 0, $today)['requests'] >= 1);
            } else { echo "SKIP  no active unit — stay checks skipped\n"; }
        }

        $m = dashboard_money(null, ['id' => 0, 'role' => 'owner'], $today);
        check('money: shape is stable whether or not the ledger exists', is_bool($m['supported']) && is_array($m['revenue']) && is_array($m['pos_today'])
            && ($m['occupancy'] === null || ($m['occupancy'] >= 0 && $m['occupancy'] <= 100)) && $m['month'] === date('F', strtotime($today)));
        check('money: carries the bar series', isset($m['series']['currency'], $m['series']['days'], $m['series']['max']));
        check('rooms tonight: a count ≥ 0, and none for an account assigned nowhere', dashboard_room_count(null) >= 0 && dashboard_room_count([]) === 0);
        check('money: one entry per currency, already formatted', !array_filter($m['revenue'], fn($r) => !is_string($r['amount']) || $r['amount'] === ''));

        $t = dashboard_my_tasks(0, $today);
        check('my tasks: nobody signed in → empty lists, no error', $t === ['today' => [], 'overdue' => []]);
        check('recent enquiries: a list of at most the limit', count(dashboard_recent_enquiries(null, 3)) <= 3);
    } catch (Throwable $e) {
        check('data block threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        if (db()->inTransaction()) db()->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
