<?php
/**
 * Admin: Dashboard — every account's landing page, as a bento grid.
 *
 * A dark greeting tile (who you are, one line on the day, quick actions) beside
 * a "Needs you" queue, then today's numbers, guest lists, money and tasks. What
 * a role sees is decided by the pure rules in includes/dashboard.php
 * (dashboard_plan()); every figure comes from the helper the linked page
 * already uses, scoped by admin_venue_ids(), and a lookup that fails shows a
 * dash instead of an error — this is where everyone lands after signing in, and
 * where a refused page sends them back to (admin_home_url()), so it must always
 * render.
 *
 * Layout: a 12-column grid on a desktop, 6 on a tablet, one column on a phone.
 * Every tile is a link or a list of links; nothing here is a form and nothing
 * writes. No browser-default controls: icons are dashboard_icon() SVGs.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';            // count_unread_admin(), hold_room_id_sql(), tasks/visitors (team.php)
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/checkin.php';            // frontdesk_rows() reads the check-in columns when present
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/task-calendar.php';
require_once __DIR__ . '/../includes/internal-messages.php';
require_once __DIR__ . '/../includes/submission-notes.php';
require_once __DIR__ . '/../includes/attendance.php';         // leave_requests_supported() — HR's leave tile
require_once __DIR__ . '/../includes/submission-status.php';
require_once __DIR__ . '/../includes/pos-support.php';
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_login();

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';

$me       = current_admin() ?: [];
$meId     = (int)($me['id'] ?? 0);
$role     = admin_role();
$job      = admin_job();
$kind     = dashboard_kind($role, $job);
$venueIds = admin_venue_ids();   // null = every property (owner, HR); [] = none assigned yet
$todayYmd = frontdesk_today_ymd();
$now      = new DateTime('now', new DateTimeZone('Africa/Nairobi'));

$venueNames = $venueIds === null ? [] : dashboard_safe(function () use ($venueIds) {
    if (!$venueIds) return [];
    return db_query('SELECT name FROM venues WHERE id IN (' . implode(',', array_map('intval', $venueIds)) . ') ORDER BY sort_order, name')
        ->fetchAll(PDO::FETCH_COLUMN);
}, []);

$profile = dashboard_profile($kind, $job, $venueNames);
$plan    = dashboard_plan($kind, [
    'reservations' => reservations_supported(),
    'inventory'    => inv_supported(),
    'pos'          => pos_supported() && pos_is_seller($me),
    'tasks'        => tasks_supported(),
    'visitors'     => visitors_supported(),
    'ai'           => ai_assistant_supported(),
    'leave'        => function_exists('leave_requests_supported') && leave_requests_supported(),
]);
$tileMeta = dashboard_tile_meta();
$tiles    = dashboard_tile_values($plan['tiles'], $venueIds, $meId, $todayYmd);
$has      = fn(string $s): bool => in_array($s, $plan['sections'], true);

$today    = $has('today')            ? dashboard_today($venueIds, $todayYmd)      : null;
$money    = $has('money')            ? dashboard_money($venueIds, $me, $todayYmd) : null;
$myTasks  = $has('my_tasks')         ? dashboard_my_tasks($meId, $todayYmd)       : null;
$recent   = $has('recent_enquiries') ? dashboard_recent_enquiries($venueIds, 5)   : null;
$countsCard = $has('counts_due') ? (string)dashboard_safe(fn() => inv_counts_due_card(
    inv_countable_locations($meId, inv_actor_role(), $venueIds, $todayYmd), 0, 5, '/admin/inventory-count.php'), '') : '';

// A page that refused this account sends it here with the reason.
$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$firstName = trim((string)strtok(trim((string)($me['name'] ?? '')), ' '));
// Today's list keeps finished tasks (shown with a Done badge); only open ones count.
$overdue   = $myTasks['overdue'] ?? [];
$openToday = count(array_filter($myTasks['today'] ?? [], fn($t) => in_array((string)$t['status'], ['todo', 'in_progress'], true)));

$queue = dashboard_queue($tiles, $tileMeta);
$needs = count(array_filter($queue['rows'], fn($r) => $r['hot'])) + ($overdue ? 1 : 0);
$headline = dashboard_headline($kind, $today === null ? null : count($today['arriving']),
    $today === null ? null : count($today['departing']), $needs, $openToday, count($overdue));
$tasksHref = in_array($kind, ['ops', 'reception'], true) ? '/admin/mywork.php' : '/admin/timetable.php';
$todayHref = $kind === 'security' ? '/admin/gate.php' : '/admin/frontdesk.php';

$whereLabel = $venueIds === null ? 'all properties'
    : (count($venueNames) === 0 ? 'no property yet' : (count($venueNames) <= 2 ? implode(' & ', $venueNames) : count($venueNames) . ' properties'));
$rooms = $today !== null ? dashboard_room_count($venueIds) : 0;
$occ   = $today !== null ? dashboard_occupancy_pct((int)$today['kpi_inhouse'], $rooms) : null;

/** A guest row: avatar initials, name, room and dates; the whole row opens the booking. */
$stayRow = function (array $r, string $when): string {
    $place = trim((string)($r['room_name'] ?? '') . ((string)($r['unit_name'] ?? '') !== '' ? ' · ' . $r['unit_name'] : ''));
    $date  = $when === 'out' ? 'since ' . date('j M', strtotime((string)$r['check_in'])) : 'until ' . date('j M', strtotime((string)$r['check_out']));
    // Room names usually start with the property ("My Amani — Superior Sea View"): don't say it twice.
    $venue = (string)($r['venue_name'] ?? '');
    $where = ($venue === '' || stripos($place, $venue) === 0) ? $place : $venue . ' · ' . $place;
    $flags = '';
    if ((int)($r['open_requests'] ?? 0) > 0) $flags .= '<span class="dz-flag dz-flag--warn">' . (int)$r['open_requests'] . ' request' . ((int)$r['open_requests'] === 1 ? '' : 's') . '</span>';
    if ((int)($r['unread_msgs'] ?? 0) > 0)   $flags .= '<span class="dz-flag">' . (int)$r['unread_msgs'] . ' unread</span>';
    return '<a class="dz-row" href="/admin/booking.php?hold=' . (int)$r['id'] . '">'
         . '<span class="dz-av" aria-hidden="true">' . e(dashboard_initials((string)$r['guest_name'])) . '</span>'
         . '<span class="dz-row__t"><b>' . e((string)$r['guest_name']) . $flags . '</b><small>' . e($where . ' · ' . $date) . '</small></span></a>';
};
$taskRow = function (array $t, bool $late) use ($tasksHref): string {
    $time = substr((string)($t['due_time'] ?? ''), 0, 5);
    $done = (string)$t['status'] === 'done';
    $when = $late ? 'due ' . date('j M', strtotime((string)$t['due_date'])) . ' · overdue' : ($time !== '' ? 'today · ' . $time : 'today · any time');
    $av   = $late ? '<span class="dz-av dz-av--warn" aria-hidden="true">!</span>'
          : ($done ? '<span class="dz-av dz-av--ok" aria-hidden="true">' . dashboard_icon('check', 15) . '</span>'
                   : '<span class="dz-av" aria-hidden="true">' . e($time !== '' ? substr($time, 0, 2) : '·') . '</span>');
    return '<a class="dz-row' . ($done ? ' is-done' : '') . '" href="' . e($tasksHref) . '" data-shell-link>' . $av
         . '<span class="dz-row__t"><b>' . e((string)$t['title']) . '</b><small>' . e(trim((string)($t['venue_name'] ?? '') . ' · ' . $when, ' ·')) . '</small></span></a>';
};
/** A list tile: title, optional count + button, rows, "+N more", or an empty line. */
$listTile = function (string $cls, string $title, array $rowsHtml, string $empty, ?array $btn = null, int $max = 5): string {
    $n   = count($rowsHtml);
    $out = '<section class="dz-tile ' . $cls . '"><header class="dz-tile__h"><h2>' . e($title) . ($n ? '<span class="dz-n">' . $n . '</span>' : '') . '</h2>'
         . ($btn ? '<a class="dz-link" href="' . e($btn[1]) . '" data-shell-link>' . e($btn[0]) . dashboard_icon('chevron', 14) . '</a>' : '') . '</header>';
    if (!$n) return $out . '<p class="dz-empty">' . e($empty) . '</p></section>';
    $out .= '<div class="dz-rows">' . implode('', array_slice($rowsHtml, 0, $max)) . '</div>';
    if ($n > $max && $btn) $out .= '<a class="dz-more" href="' . e($btn[1]) . '" data-shell-link>+ ' . ($n - $max) . ' more</a>';
    return $out . '</section>';
};

// The three tiles under the numbers: guests in, guests out, and the list that matters most next.
$trio = [];
if ($today !== null) {
    $trio[] = ['Arriving today', array_map(fn($r) => $stayRow($r, 'in'),  $today['arriving']),  'No arrivals today.',   [$kind === 'security' ? 'Gate' : 'Front desk', $todayHref]];
    $trio[] = ['Leaving today',  array_map(fn($r) => $stayRow($r, 'out'), $today['departing']), 'No departures today.', null];
}
$taskRows = $myTasks === null ? null : array_merge(array_map(fn($t) => $taskRow($t, true), $overdue), array_map(fn($t) => $taskRow($t, false), $myTasks['today']));
$tasksInTrio = false;
if ($recent !== null) {
    $trio[] = ['Latest enquiries', array_map(fn($s) => '<a class="dz-row" href="/admin/submission-view.php?id=' . (int)$s['id'] . '">'
        . '<span class="dz-av" aria-hidden="true">' . e(dashboard_initials((string)$s['guest_name'])) . '</span><span class="dz-row__t"><b>' . e((string)$s['guest_name']) . '</b><small>'
        . e(trim((string)($s['room_name'] ?? ucfirst(str_replace('_', ' ', (string)$s['type']))) . ' · ' . date('j M, H:i', strtotime((string)$s['created_at'])), ' ·'))
        . '</small></span></a>', $recent), 'No enquiries yet.', ['All enquiries', '/admin/submissions.php']];
} elseif ($today !== null && $taskRows !== null) {
    $trio[] = ['My tasks', $taskRows, 'No tasks for you today.', [$kind === 'reception' ? 'My work' : 'My week', $tasksHref]];
    $tasksInTrio = true;
}

include __DIR__ . '/_layout.php';
?>
<style>
.dz{--dz-r:18px;--dz-mint:#5EC4B6;--dz-sand:#F3EADB;--dz-warn:#D9541E;--dz-warn-bg:#FDEEE6;--dz-ok:#1F8A5B;--dz-ok-bg:#E6F4EC;--dz-line:#EDE4D6;
  display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;align-items:stretch}
.dz a{text-decoration:none;color:inherit}
.dz-ic{display:block;flex:0 0 auto}
.dz-tile{background:var(--white);border-radius:var(--dz-r);box-shadow:0 1px 2px rgba(16,47,58,.05),0 8px 24px rgba(16,47,58,.06);padding:20px;min-width:0;display:flex;flex-direction:column}
.dz-s12{grid-column:span 12}.dz-s8{grid-column:span 8}.dz-s7{grid-column:span 7}.dz-s6{grid-column:span 6}.dz-s5{grid-column:span 5}.dz-s4{grid-column:span 4}
.dz-cap{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.dz-tile__h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}
.dz-tile__h h2{margin:0;font-size:14.5px;font-weight:700;display:flex;align-items:center;gap:8px}
.dz-n{font-size:11.5px;font-weight:700;background:var(--dz-sand);color:var(--brand-dk);border-radius:999px;padding:2px 8px}
.dz-link{display:inline-flex;align-items:center;gap:3px;font-size:12.5px;font-weight:600;color:var(--brand);white-space:nowrap}
.dz-link:hover{color:var(--brand-dk)}
.dz-empty{margin:auto 0;padding:18px 0;color:var(--muted);font-size:13px}
.dz-more{margin-top:8px;font-size:12.5px;font-weight:600;color:var(--brand)}

/* greeting */
.dz-hero{grid-column:span 8;position:relative;overflow:hidden;padding:26px 28px;color:#fff;
  background:linear-gradient(135deg,#102F3A 0%,#1E5C6B 62%,#2C7A8C 100%)}
.dz-hero::after{content:"";position:absolute;right:-70px;top:-90px;width:280px;height:280px;border-radius:50%;pointer-events:none;
  background:radial-gradient(circle at 30% 30%,rgba(94,196,182,.42),transparent 65%)}
.dz-hero > *{position:relative;z-index:1}
.dz-pill{align-self:flex-start;display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;
  background:rgba(255,255,255,.14);color:#fff;text-transform:uppercase}
.dz-pill span{text-transform:none;letter-spacing:0;font-weight:600;opacity:.85}
.dz-hero h1{margin:12px 0 6px;font-size:clamp(22px,2.6vw,30px);font-weight:800;letter-spacing:-.02em;line-height:1.15}
.dz-hero__sum{margin:0;color:rgba(255,255,255,.78);font-size:14.5px;max-width:520px}
.dz-hero__foot{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-top:22px}
.dz-acts{display:flex;gap:8px;flex-wrap:wrap;flex:1 1 0;min-width:0}
.dz-act{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border-radius:11px;font-size:13px;font-weight:600;
  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);color:#fff !important;transition:background .15s,transform .12s}
.dz-act:hover{background:rgba(255,255,255,.2)}
.dz-act:active{transform:translateY(1px)}
.dz-act--main{background:#fff;border-color:#fff;color:var(--brand-dk) !important}
.dz-act--main:hover{background:var(--dz-sand)}
.dz-date{text-align:right;font-size:12.5px;color:rgba(255,255,255,.72);line-height:1.45;white-space:nowrap}
.dz-date b{display:block;font-size:20px;color:#fff;font-weight:700;letter-spacing:-.01em}
.dz-mode{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:4px 9px;border-radius:999px;font-size:11.5px;background:rgba(255,255,255,.12);color:#fff !important}

/* needs you */
.dz-queue{grid-column:span 4;grid-row:span 2}
.dz-q{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:13px;margin-top:8px;background:var(--bg);transition:transform .12s,box-shadow .12s}
.dz-q:hover{transform:translateX(2px);box-shadow:0 2px 10px rgba(16,47,58,.08)}
.dz-q__n{min-width:38px;height:38px;padding:0 6px;border-radius:11px;display:grid;place-items:center;font-weight:800;font-size:16px;background:#fff;color:var(--muted);flex:0 0 auto}
.dz-q--hot{background:var(--dz-warn-bg)}
.dz-q--hot .dz-q__n{background:var(--dz-warn);color:#fff}
.dz-q__t{min-width:0;flex:1}
.dz-q__t b{display:block;font-size:13.5px}
.dz-q__t small{display:block;font-size:12px;color:var(--muted)}
.dz-q > .dz-ic{color:var(--muted)}
.dz-clear{margin-top:auto;padding-top:14px;font-size:12.5px;color:var(--dz-ok);display:flex;gap:8px;align-items:flex-start;line-height:1.45}
.dz-clear .dz-ic{margin-top:2px}
.dz-calm{margin:auto 0;padding:22px 6px;text-align:center;color:var(--muted);font-size:13.5px}
.dz-calm i{display:grid;place-items:center;width:52px;height:52px;border-radius:50%;margin:0 auto 12px;background:var(--dz-ok-bg);color:var(--dz-ok)}
.dz-calm b{display:block;color:var(--brand-dk);font-size:15px;margin-bottom:2px}

/* numbers */
.dz-kpis{grid-column:span 8;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.dz-kpi{justify-content:space-between;transition:transform .12s}
a.dz-kpi:hover{transform:translateY(-2px)}
.dz-num{display:block;font-size:34px;font-weight:800;letter-spacing:-.02em;line-height:1;margin-top:12px;color:var(--brand-dk)}
.dz-kpi small{color:var(--muted);font-size:12.5px;display:block;margin-top:6px}
.dz-kpi--dark{background:var(--brand-dk);color:#fff}
.dz-kpi--dark .dz-cap,.dz-kpi--dark small{color:rgba(255,255,255,.66)}
.dz-kpi--dark .dz-num{color:#fff}
.dz-ring{display:block;margin-top:8px}

/* lists */
.dz-rows{display:flex;flex-direction:column}
.dz-row{display:flex;align-items:center;gap:12px;padding:10px 8px;margin:0 -8px;border-radius:11px;border-top:1px solid var(--dz-line)}
.dz-rows .dz-row:first-child{border-top-color:transparent}
a.dz-row:hover{background:var(--bg)}
.dz-av{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;flex:0 0 auto;background:var(--dz-sand);color:var(--brand-dk)}
.dz-av--warn{background:var(--dz-warn-bg);color:var(--dz-warn);font-size:15px}
.dz-av--ok{background:var(--dz-ok-bg);color:var(--dz-ok)}
.dz-row__t{min-width:0;flex:1}
.dz-row__t b{display:block;font-size:13.5px;font-weight:600}
.dz-row__t small{display:block;color:var(--muted);font-size:12px;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dz-row.is-done b{color:var(--muted);text-decoration:line-through}
.dz-flag{display:inline-block;margin-left:7px;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:700;background:#E1F1EE;color:var(--brand);vertical-align:1px}
.dz-flag--warn{background:var(--dz-warn-bg);color:var(--dz-warn)}

/* money */
.dz-money{background:linear-gradient(180deg,#fff 0%,#FBF4E6 100%)}
.dz-figs{display:flex;gap:26px;flex-wrap:wrap;margin-top:4px}
.dz-fig b{display:block;font-size:clamp(20px,2.2vw,28px);font-weight:800;letter-spacing:-.02em;color:var(--brand-dk);line-height:1.1}
.dz-fig small{color:var(--muted);font-size:12.5px}
.dz-bars{display:flex;align-items:flex-end;gap:4px;height:78px;margin-top:auto;padding-top:18px}
.dz-bars i{flex:1;min-width:2px;border-radius:5px 5px 2px 2px;background:#E4D6BD;min-height:3px}
.dz-bars i.is-now{background:var(--accent)}
.dz-bars__cap{font-size:11.5px;color:var(--muted);margin-top:8px}

/* till + access */
.dz-till{background:var(--brand-dk);color:#fff;flex-direction:row;align-items:center;gap:18px;flex-wrap:wrap}
.dz-till .dz-cap{color:rgba(255,255,255,.66)}
.dz-till p{margin:6px 0 0;color:rgba(255,255,255,.8);font-size:14px}
.dz-till__go{margin-left:auto;display:inline-flex;align-items:center;gap:9px;padding:14px 22px;border-radius:14px;background:var(--dz-mint);color:#0E2932 !important;font-weight:700;font-size:15px}
.dz-till__go:hover{filter:brightness(1.06)}
.dz-can{grid-column:span 12;display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:2px 2px 0}
.dz-chip{padding:5px 11px;border-radius:999px;font-size:12px;font-weight:600;background:var(--white);border:1px solid var(--dz-line);color:var(--brand-dk)}
.dz-can em{font-style:normal;font-size:12px;color:var(--muted);margin-left:4px}
.dz-raw{grid-column:span 12;min-width:0}
.dz-raw > .card{margin-bottom:0}

/* tablet: six columns — greeting and queue full width, lists two-up */
@media (max-width:1180px){
  .dz{grid-template-columns:repeat(6,minmax(0,1fr))}
  .dz-hero,.dz-queue,.dz-kpis,.dz-s12,.dz-s8,.dz-s7,.dz-s6,.dz-s5,.dz-can,.dz-raw{grid-column:span 6}
  .dz-queue{grid-row:auto}
  .dz-s4{grid-column:span 3}
  .dz-s4.dz-odd{grid-column:span 6}
}
/* phone: one column, numbers two-up */
@media (max-width:640px){
  .dz{grid-template-columns:minmax(0,1fr);gap:12px}
  .dz > *{grid-column:1 / -1 !important}
  .dz-tile{padding:16px;border-radius:16px}
  .dz-hero{padding:20px 18px}
  .dz-hero__foot{flex-direction:column;align-items:stretch;margin-top:18px}
  .dz-acts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}
  .dz-act{justify-content:center}
  .dz-date{text-align:left}
  .dz-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .dz-num{font-size:30px}
  .dz-till__go{margin-left:0;width:100%;justify-content:center}
}
@media (prefers-reduced-motion:reduce){.dz-q,.dz-kpi,.dz-act{transition:none}}
</style>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? (is_string($flash) ? $flash : '')) ?></div><?php endif; ?>

<div class="dz">

  <!-- greeting -->
  <section class="dz-tile dz-hero">
    <span class="dz-pill"><?= e($profile['label']) ?> <span>· <?= e($whereLabel) ?></span></span>
    <h1><?= e(dashboard_greeting((int)$now->format('G'))) ?><?= $firstName !== '' ? ', ' . e($firstName) : '' ?></h1>
    <p class="dz-hero__sum"><?= e($headline) ?></p>
    <div class="dz-hero__foot">
      <div class="dz-acts">
        <?php if ($has('till')): ?>
        <a class="dz-act dz-act--main" href="/pos/" target="_blank" rel="noopener"><?= dashboard_icon('till') ?>Open till</a>
        <?php endif; ?>
        <?php foreach ($plan['shortcuts'] as $i => [$label, $href, $icon]): ?>
        <a class="dz-act<?= $i === 0 && !$has('till') ? ' dz-act--main' : '' ?>" href="<?= e($href) ?>" data-shell-link><?= dashboard_icon($icon) ?><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="dz-date"><b><?= e($now->format('D j M')) ?></b><?= e($now->format('H:i')) ?> · Kenya time
        <?php if ($kind === 'owner'): ?><br><a class="dz-mode" href="/admin/settings.php" data-tip="How the public booking form behaves — change it in Settings">Form mode: <strong><?= e(ucfirst((string)setting('form_mode', 'enquiry'))) ?></strong><?= dashboard_icon('settings', 13) ?></a><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- needs you -->
  <section class="dz-tile dz-queue" aria-label="Needs you">
    <div class="dz-cap"><?= $needs > 0 ? 'Needs you' : 'Your queue' ?></div>
    <?php if ($overdue): ?>
    <a class="dz-q dz-q--hot" href="<?= e($tasksHref) ?>" data-shell-link><span class="dz-q__n"><?= count($overdue) ?></span>
      <span class="dz-q__t"><b>Your overdue task<?= count($overdue) === 1 ? '' : 's' ?></b><small>still open from an earlier day</small></span><?= dashboard_icon('chevron', 16) ?></a>
    <?php endif; ?>
    <?php foreach ($queue['rows'] as $q): $m = $tileMeta[$q['key']]; ?>
    <a class="dz-q<?= $q['hot'] ? ' dz-q--hot' : '' ?>" href="<?= e($m['href']) ?>" data-shell-link><span class="dz-q__n"><?= $q['n'] === null ? '–' : (int)$q['n'] ?></span>
      <span class="dz-q__t"><b><?= e($m['label']) ?></b><small><?= e($q['n'] === null ? 'could not be read just now' : $m['sub']) ?></small></span><?= dashboard_icon('chevron', 16) ?></a>
    <?php endforeach; ?>
    <?php if (!$queue['rows'] && !$overdue): ?>
    <div class="dz-calm"><i><?= dashboard_icon('check', 24) ?></i><b>Nothing is waiting on you</b><?= e(implode(' · ', $queue['clear'])) ?></div>
    <?php elseif ($queue['clear']): ?>
    <div class="dz-clear"><?= dashboard_icon('check', 15) ?><span>All clear: <?= e(strtolower(implode(', ', $queue['clear']))) ?></span></div>
    <?php endif; ?>
  </section>

  <?php if ($today !== null): ?>
  <!-- today in numbers -->
  <div class="dz-kpis">
    <a class="dz-tile dz-kpi" href="<?= e($todayHref) ?>" data-shell-link><span class="dz-cap">Arriving</span><span><span class="dz-num"><?= count($today['arriving']) ?></span><small>today</small></span></a>
    <a class="dz-tile dz-kpi" href="<?= e($todayHref) ?>" data-shell-link><span class="dz-cap">Leaving</span><span><span class="dz-num"><?= count($today['departing']) ?></span><small>today</small></span></a>
    <a class="dz-tile dz-kpi dz-kpi--dark" href="<?= e($todayHref) ?>" data-shell-link><span class="dz-cap">In house</span><span><span class="dz-num"><?= (int)$today['kpi_inhouse'] ?></span><small>room<?= (int)$today['kpi_inhouse'] === 1 ? '' : 's' ?> tonight</small></span></a>
    <div class="dz-tile dz-kpi"><span class="dz-cap">Occupancy</span>
      <?php if ($occ !== null): $circ = 219.9; ?>
      <svg class="dz-ring" width="84" height="84" viewBox="0 0 86 86" role="img" aria-label="<?= $occ ?>% of <?= $rooms ?> rooms occupied tonight">
        <circle cx="43" cy="43" r="35" fill="none" stroke="#F3EADB" stroke-width="10"/>
        <?php if ($occ > 0): ?><circle cx="43" cy="43" r="35" fill="none" stroke="#1E5C6B" stroke-width="10" stroke-linecap="round" stroke-dasharray="<?= round($circ * $occ / 100, 1) ?> <?= $circ ?>" transform="rotate(-90 43 43)"/><?php endif; ?>
        <text x="43" y="48.500" text-anchor="middle" font-size="17" font-weight="800" fill="#15262C"><?= $occ ?>%</text>
      </svg>
      <small>of <?= $rooms ?> room<?= $rooms === 1 ? '' : 's' ?> tonight</small>
      <?php else: ?><span><span class="dz-num">–</span><small>no rooms to measure</small></span><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($has('till')): ?>
  <section class="dz-tile dz-till dz-s8">
    <div><div class="dz-cap">The till</div><p>Sell items, take payment, or charge a guest's room.<?= empty($me['pos_pin_hash']) ? ' You have no till PIN yet — you need one on a shared tablet.' : '' ?></p></div>
    <a class="dz-till__go" href="/pos/" target="_blank" rel="noopener"><?= dashboard_icon('till', 20) ?>Open till</a>
  </section>
  <?php endif; ?>

  <?php if ($today === null && $taskRows !== null && !$has('till')): /* task-based roles: their tasks sit under the greeting */ ?>
  <?= $listTile('dz-s8', 'My tasks', $taskRows, 'No tasks for you today.', ['My work', $tasksHref], 8) ?>
  <?php endif; ?>

  <?php foreach ($trio as $i => [$title, $rowsHtml, $empty, $btn]):
      $span = count($trio) === 3 ? 'dz-s4' . ($i === 2 ? ' dz-odd' : '') : (count($trio) === 2 ? 'dz-s6' : 'dz-s12'); ?>
  <?= $listTile($span, $title, $rowsHtml, $empty, $btn) ?>
  <?php endforeach; ?>

  <?php $showTasksWide = $taskRows !== null && !$tasksInTrio && ($today !== null || $has('till'));
        $showMoney = $money !== null && ($money['supported'] || $money['pos_today']); ?>
  <?php if ($showMoney): $ser = $money['series']; ?>
  <section class="dz-tile dz-money <?= $showTasksWide ? 'dz-s5' : 'dz-s12' ?>">
    <header class="dz-tile__h"><span class="dz-cap"><?= e($money['month']) ?> so far</span><a class="dz-link" href="/admin/reports.php" data-shell-link>Reports<?= dashboard_icon('chevron', 14) ?></a></header>
    <div class="dz-figs">
      <?php foreach ($money['revenue'] as $r): ?>
      <div class="dz-fig"><b><?= e($r['amount']) ?></b><small>room revenue · <?= (int)$r['bookings'] ?> booking<?= $r['bookings'] === 1 ? '' : 's' ?></small></div>
      <?php endforeach; ?>
      <?php if ($money['supported'] && !$money['revenue']): ?>
      <div class="dz-fig"><b>–</b><small>no confirmed bookings arriving this month yet</small></div>
      <?php endif; ?>
      <?php foreach ($money['pos_today'] as $p): ?>
      <div class="dz-fig"><b><?= e($p['amount']) ?></b><small>till sales today · <?= (int)$p['sales'] ?> sale<?= $p['sales'] === 1 ? '' : 's' ?></small></div>
      <?php endforeach; ?>
      <?php if ($money['occupancy'] !== null): ?>
      <div class="dz-fig"><b><?= e(rtrim(rtrim(number_format($money['occupancy'], 1), '0'), '.')) ?>%</b><small>occupancy this month</small></div>
      <?php endif; ?>
    </div>
    <?php if ($ser['max'] > 0): ?>
    <div class="dz-bars" role="img" aria-label="Room revenue by arrival day this month">
      <?php foreach ($ser['days'] as $ymd => $amt): ?>
      <i<?= $ymd === $todayYmd ? ' class="is-now"' : '' ?> style="height:<?= max(4, (int)round($amt / $ser['max'] * 100)) ?>%" data-tip="<?= e(date('j M', strtotime($ymd)) . ' · ' . bookings_money((float)$amt, $ser['currency'])) ?>"></i>
      <?php endforeach; ?>
    </div>
    <div class="dz-bars__cap">Room revenue by arrival day · <?= e($ser['currency']) ?></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($showTasksWide): ?>
  <?= $listTile($showMoney ? 'dz-s7' : 'dz-s12', 'My tasks', $taskRows, 'No tasks for you today.', [in_array($kind, ['ops', 'reception'], true) ? 'My work' : 'My week', $tasksHref], 6) ?>
  <?php endif; ?>

  <?php if ($countsCard !== ''): ?><div class="dz-raw"><?= $countsCard ?></div><?php endif; ?>

  <!-- what you can do -->
  <div class="dz-can"><span class="dz-cap">You can</span>
    <?php foreach ($profile['chips'] as $c): ?><span class="dz-chip"><?= e($c) ?></span><?php endforeach; ?>
    <?php if ($profile['cannot'] !== ''): ?><em><?= e($profile['cannot']) ?></em><?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
