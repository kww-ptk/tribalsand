<?php
/**
 * Admin: Dashboard — every account's landing page.
 *
 * Says who is signed in and what they can do, then shows the numbers their role
 * acts on, each linking to the page where they act on it. What a role sees is
 * decided by the pure rules in includes/dashboard.php (dashboard_plan()); every
 * figure comes from the helper the linked page already uses, scoped by
 * admin_venue_ids(), and a lookup that fails shows a dash instead of an error —
 * this is where everyone lands after signing in, and where a refused page sends
 * them back to (admin_home_url()), so it must always render.
 *
 * Read-only: no form on this page writes anything.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/booking.php';            // count_unread_admin(), hold_room_id_sql(), tasks/visitors (team.php)
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/checkin.php';            // frontdesk_rows() reads the check-in columns when present
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/task-calendar.php';
require_once __DIR__ . '/../includes/internal-messages.php';
require_once __DIR__ . '/../includes/submission-notes.php';
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
$venueIds = is_owner() ? null : (admin_venue_ids() ?: []);   // null = every property
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
]);
$tileMeta = dashboard_tile_meta();
$tiles    = dashboard_tile_values($plan['tiles'], $venueIds, $meId, $todayYmd);
$has      = fn(string $s): bool => in_array($s, $plan['sections'], true);

$today    = $has('today')            ? dashboard_today($venueIds, $todayYmd)        : null;
$money    = $has('money')            ? dashboard_money($venueIds, $me, $todayYmd)   : null;
$myTasks  = $has('my_tasks')         ? dashboard_my_tasks($meId, $todayYmd)         : null;
$recent   = $has('recent_enquiries') ? dashboard_recent_enquiries($venueIds, 5)                : null;
$countsCard = $has('counts_due') ? (string)dashboard_safe(fn() => inv_counts_due_card(
    inv_countable_locations($meId, $role, $venueIds, $todayYmd), 0, 5, '/admin/inventory-count.php'), '') : '';

// A page that refused this account sends it here with the reason.
$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$firstName = trim((string)strtok(trim((string)($me['name'] ?? '')), ' '));
// Today's list keeps finished tasks (shown with a Done badge); only open ones count.
$openToday = count(array_filter($myTasks['today'] ?? [], fn($t) => in_array((string)$t['status'], ['todo', 'in_progress'], true)));
$heading   = dashboard_heading($tiles, $tileMeta, count($myTasks['overdue'] ?? []), $openToday);
// Roles whose day IS their task list (housekeeping, the till…) see that first; the
// tiles — for them only the team chat — follow it.
$tilesLast = in_array($kind, ['ops', 'pos'], true);

/** One booking row in the Today lists. */
$stayRow = function (array $r, string $when): string {
    $place = trim((string)($r['room_name'] ?? '') . ((string)($r['unit_name'] ?? '') !== '' ? ' · ' . $r['unit_name'] : ''));
    $date  = $when === 'out' ? 'since ' . date('j M', strtotime((string)$r['check_in'])) : 'until ' . date('j M', strtotime((string)$r['check_out']));
    // Room names usually start with the property ("My Amani — Superior Sea View"): don't say it twice.
    $venue = (string)($r['venue_name'] ?? '');
    $where = ($venue === '' || stripos($place, $venue) === 0) ? $place : $venue . ' · ' . $place;
    $badges = '';
    if ((int)($r['open_requests'] ?? 0) > 0) $badges .= ' <span class="badge badge--orange">' . (int)$r['open_requests'] . ' request' . ((int)$r['open_requests'] === 1 ? '' : 's') . '</span>';
    if ((int)($r['unread_msgs'] ?? 0) > 0)   $badges .= ' <span class="badge badge--blue">' . (int)$r['unread_msgs'] . ' unread</span>';
    return '<a class="dash-row" href="/admin/booking.php?hold=' . (int)$r['id'] . '">'
         . '<span class="dash-row__main">' . e((string)$r['guest_name']) . $badges . '</span>'
         . '<span class="dash-row__sub">' . e($where) . ' · ' . e($date) . '</span></a>';
};
$taskRow = function (array $t, bool $overdue): string {
    $time = substr((string)($t['due_time'] ?? ''), 0, 5);
    $when = $overdue ? 'due ' . date('j M', strtotime((string)$t['due_date'])) : ($time !== '' ? $time : 'any time');
    return '<div class="dash-row"><span class="dash-row__main">' . e((string)$t['title'])
         . ($overdue ? ' <span class="badge badge--red">Overdue</span>' : '')
         . ((string)$t['status'] === 'done' ? ' <span class="badge badge--green">Done</span>' : '') . '</span>'
         . '<span class="dash-row__sub">' . e(trim((string)($t['venue_name'] ?? '') . ' · ' . $when, ' ·')) . '</span></div>';
};

include __DIR__ . '/_layout.php';
?>
<style>
.dash-hero{display:flex;gap:24px;align-items:flex-start;justify-content:space-between;background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px 26px;margin-bottom:20px}
.dash-hero__hi{font-size:21px;font-weight:700;color:var(--brand-dk);margin:0 0 6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.dash-hero__hi .badge{font-size:10.5px;padding:3px 8px}
.dash-hero__sum{margin:0;color:var(--muted);font-size:14px}
.dash-hero__date{font-size:12.5px;color:var(--muted);white-space:nowrap;text-align:right;line-height:1.5}
.dash-hero__date b{display:block;font-size:14px;color:var(--brand-dk)}
.dash-can{margin:14px 0 0;padding:0;list-style:none;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 22px;font-size:13.5px}
.dash-can li{display:flex;gap:8px;align-items:flex-start}
.dash-can svg{flex:0 0 auto;margin-top:2px;color:var(--brand)}
.dash-cannot{margin:10px 0 0;font-size:12.5px;color:var(--muted)}
.dash-h{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:0 0 10px;display:flex;align-items:center;gap:8px}
.dash-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:22px}
.dash-tile{display:block;background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:15px 17px;text-decoration:none;color:inherit;border-left:3px solid transparent;transition:transform .12s,box-shadow .12s}
.dash-tile:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,47,58,.10)}
.dash-tile__n{font-size:26px;font-weight:700;line-height:1;color:#b3aa9c}
.dash-tile__l{font-size:13.5px;font-weight:600;margin-top:7px;color:var(--brand-dk)}
.dash-tile__s{font-size:12px;color:var(--muted);margin-top:2px}
.dash-tile.is-warn{border-left-color:#e65100}.dash-tile.is-warn .dash-tile__n{color:#e65100}
.dash-tile.is-info{border-left-color:var(--brand)}.dash-tile.is-info .dash-tile__n{color:var(--brand)}
.dash-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;align-items:start;margin-bottom:6px}
.dash-cols > .card{margin-bottom:16px;min-width:0}
.dash-row{display:block;padding:11px 20px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit}
.dash-row:last-child{border-bottom:0}
a.dash-row:hover{background:var(--bg)}
.dash-row__main{display:block;font-size:13.5px;font-weight:600}
.dash-row__sub{display:block;font-size:12px;color:var(--muted);margin-top:2px}
.dash-empty{padding:18px 20px;color:var(--muted);font-size:13px}
.dash-count{margin-left:6px;color:var(--muted);font-weight:500}
.dash-money{padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:16px}
.dash-money__n{font-size:20px;font-weight:700;color:var(--brand)}
.dash-money__l{font-size:12px;color:var(--muted);margin-top:3px}
.dash-short{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:22px}
.dash-till{padding:22px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.dash-till p{margin:0;color:var(--muted);font-size:13.5px;flex:1 1 200px}
@media (max-width:640px){
  .dash-hero{flex-direction:column;gap:12px;padding:18px}.dash-hero__date{text-align:left}
  .dash-can{grid-template-columns:1fr}
  .dash-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
}
</style>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? (is_string($flash) ? $flash : '')) ?></div><?php endif; ?>

<section class="dash-hero">
  <div>
    <h1 class="dash-hero__hi"><?= e(dashboard_greeting((int)$now->format('G'))) ?><?= $firstName !== '' ? ', ' . e($firstName) : '' ?>
      <span class="badge badge--<?= e($profile['badge']) ?>"><?= e($profile['label']) ?></span></h1>
    <p class="dash-hero__sum"><?= e($profile['summary']) ?></p>
    <ul class="dash-can" aria-label="What you can do">
      <?php foreach ($profile['can'] as $line): ?>
      <li><?= admin_icon('check', 14) ?><span><?= e($line) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($profile['cannot'] !== ''): ?><p class="dash-cannot"><?= e($profile['cannot']) ?></p><?php endif; ?>
  </div>
  <div class="dash-hero__date"><b><?= e($now->format('l j F')) ?></b><?= e($now->format('H:i')) ?> · Kenya time
    <?php if ($kind === 'owner'): ?>
    <div style="margin-top:10px"><span class="fm-chip" title="How the public booking form behaves">Form mode: <strong><?= e(ucfirst((string)setting('form_mode', 'enquiry'))) ?></strong>
      <a href="/admin/settings.php" class="fm-chip__gear" title="Change in Settings" aria-label="Change form mode in Settings"><?= admin_icon('settings', 15) ?></a></span></div>
    <?php endif; ?>
  </div>
</section>

<?php if ($plan['shortcuts']): ?>
<div class="dash-short">
  <?php foreach ($plan['shortcuts'] as [$label, $href]): ?>
  <a href="<?= e($href) ?>" class="btn-outline btn-sm" data-shell-link><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $renderTiles = function () use ($tiles, $tileMeta, $heading): void { if (!$tiles) return; ?>
<h2 class="dash-h"><?= e($heading) ?></h2>
<div class="dash-tiles">
  <?php foreach ($tiles as $k => $n): $m = $tileMeta[$k]; $cls = ($n !== null && $n > 0) ? ' is-' . $m['tone'] : ''; ?>
  <a class="dash-tile<?= $cls ?>" href="<?= e($m['href']) ?>" data-shell-link>
    <div class="dash-tile__n"><?= $n === null ? '–' : (int)$n ?></div>
    <div class="dash-tile__l"><?= e($m['label']) ?></div>
    <div class="dash-tile__s"><?= e($m['sub']) ?></div>
  </a>
  <?php endforeach; ?>
</div>
<?php }; if (!$tilesLast) $renderTiles(); ?>

<div class="dash-cols">

<?php if ($has('till')): ?>
  <div class="card">
    <div class="card__head"><span class="card__title">The till</span></div>
    <div class="dash-till">
      <p>Sell items, take payment, or charge a guest's room.<?= empty($me['pos_pin_hash']) ? ' You have not set a till PIN yet — you need one on a shared tablet.' : '' ?></p>
      <a href="/pos/" target="_blank" rel="noopener" class="btn-primary">Open till</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($today !== null): ?>
  <div class="card">
    <div class="card__head"><span class="card__title">Arriving today<span class="dash-count"><?= count($today['arriving']) ?></span></span>
      <a href="/admin/<?= $kind === 'security' ? 'gate' : 'frontdesk' ?>.php" class="btn-sm btn-outline" data-shell-link><?= $kind === 'security' ? 'Gate' : 'Front desk' ?></a></div>
    <div class="card__body">
      <?php if (!$today['arriving']): ?><div class="dash-empty">No arrivals today.</div><?php endif; ?>
      <?php foreach (array_slice($today['arriving'], 0, 6) as $r) echo $stayRow($r, 'in'); ?>
      <?php if (count($today['arriving']) > 6): ?><div class="dash-empty">+ <?= count($today['arriving']) - 6 ?> more on the Front desk</div><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card__head"><span class="card__title">Leaving today<span class="dash-count"><?= count($today['departing']) ?></span></span>
      <span class="dash-count"><?= (int)$today['kpi_inhouse'] ?> in house tonight</span></div>
    <div class="card__body">
      <?php if (!$today['departing']): ?><div class="dash-empty">No departures today.</div><?php endif; ?>
      <?php foreach (array_slice($today['departing'], 0, 6) as $r) echo $stayRow($r, 'out'); ?>
      <?php if (count($today['departing']) > 6): ?><div class="dash-empty">+ <?= count($today['departing']) - 6 ?> more on the Front desk</div><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($myTasks !== null): $open = $openToday + count($myTasks['overdue']); ?>
  <div class="card">
    <div class="card__head"><span class="card__title">My tasks<span class="dash-count"><?= $open ?></span></span>
      <a href="/admin/<?= $kind === 'ops' || $kind === 'reception' ? 'mywork' : 'timetable' ?>.php" class="btn-sm btn-outline" data-shell-link><?= $kind === 'ops' || $kind === 'reception' ? 'My work' : 'My week' ?></a></div>
    <div class="card__body">
      <?php if (!$myTasks['today'] && !$myTasks['overdue']): ?><div class="dash-empty">No tasks for you today.</div><?php endif; ?>
      <?php foreach (array_slice($myTasks['overdue'], 0, 4) as $t) echo $taskRow($t, true); ?>
      <?php foreach (array_slice($myTasks['today'], 0, 6) as $t) echo $taskRow($t, false); ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($money !== null && ($money['supported'] || $money['pos_today'])): ?>
  <div class="card">
    <div class="card__head"><span class="card__title"><?= e($money['month']) ?> so far</span>
      <a href="/admin/reports.php" class="btn-sm btn-outline" data-shell-link>Reports</a></div>
    <div class="dash-money">
      <?php foreach ($money['revenue'] as $r): ?>
      <div><div class="dash-money__n"><?= e($r['amount']) ?></div><div class="dash-money__l">room revenue · <?= (int)$r['bookings'] ?> booking<?= $r['bookings'] === 1 ? '' : 's' ?></div></div>
      <?php endforeach; ?>
      <?php if ($money['supported'] && !$money['revenue']): ?>
      <div><div class="dash-money__n">–</div><div class="dash-money__l">no confirmed bookings arriving this month yet</div></div>
      <?php endif; ?>
      <?php if ($money['occupancy'] !== null): ?>
      <div><div class="dash-money__n"><?= e(rtrim(rtrim(number_format($money['occupancy'], 1), '0'), '.')) ?>%</div><div class="dash-money__l">rooms occupied</div></div>
      <?php endif; ?>
      <?php foreach ($money['pos_today'] as $p): ?>
      <div><div class="dash-money__n"><?= e($p['amount']) ?></div><div class="dash-money__l">till sales today · <?= (int)$p['sales'] ?> sale<?= $p['sales'] === 1 ? '' : 's' ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($recent !== null): ?>
  <div class="card">
    <div class="card__head"><span class="card__title">Latest enquiries</span>
      <a href="/admin/submissions.php" class="btn-sm btn-outline" data-shell-link>All enquiries</a></div>
    <div class="card__body">
      <?php if (!$recent): ?><div class="dash-empty">No enquiries yet.</div><?php endif; ?>
      <?php foreach ($recent as $s): ?>
      <a class="dash-row" href="/admin/submission-view.php?id=<?= (int)$s['id'] ?>">
        <span class="dash-row__main"><?= e((string)$s['guest_name']) ?></span>
        <span class="dash-row__sub"><?= e(trim((string)($s['room_name'] ?? ucfirst(str_replace('_', ' ', (string)$s['type']))) . ' · ' . date('j M, H:i', strtotime((string)$s['created_at'])), ' ·')) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

</div>

<?= $countsCard ?>

<?php if ($tilesLast) $renderTiles(); ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
