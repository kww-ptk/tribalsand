<?php
/**
 * Admin: the weekly job timetable — a school-timetable grid of real tasks.
 *
 * Days across, clock hours down, ONE property at a time, with an "Anytime" row
 * pinned above the hours for tasks that carry no due_time. Read-only over the
 * tasks table: creating and editing routines stays in admin/task-schedules.php.
 *
 * Scope: owner sees every property; a manager sees their own. A STAFF member's
 * person filter is LOCKED to themselves — the lock is derived from the session
 * role here, never from a request parameter, so there is no identity to forge.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/task-calendar.php';
require_login();

$pageTitle  = 'Timetable';
$activeMenu = 'timetable';

$meId     = (int)($_SESSION['admin_id'] ?? 0);
$venueIds = admin_venue_ids();                 // null = owner (all)
$JOBS     = team_job_types();
$today    = frontdesk_today_ymd();
$nowHms   = date('H:i:s');

// Venues in scope (owner: all; manager/staff: assigned).
if ($venueIds === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($venueIds) {
    $ph = []; $p = [];
    foreach ($venueIds as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else {
    $venues = [];
}

if (!tasks_supported()) {
    include __DIR__ . '/_layout.php';
    echo '<div class="page-header"><h1>Timetable</h1></div>';
    echo '<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">Tasks aren’t enabled yet. Ask the owner to run the <code>add_tasks.sql</code> migration.</p></div></div>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

// Chosen property: a ?venue= outside the account's own list is ignored, never honoured.
$venueId = (int)($_GET['venue'] ?? 0);
$allowed = array_map(fn($v) => (int)$v['id'], $venues);
if (!in_array($venueId, $allowed, true)) $venueId = $allowed[0] ?? 0;

$weekStart = task_week_start((string)($_GET['week'] ?? $today));

// Person filter. Managers and the owner choose; staff are locked to themselves.
$canFilterPeople = is_owner() || is_manager();
$filters = [];
if ($canFilterPeople) {
    $who = (string)($_GET['who'] ?? '');
    if ($who === 'unassigned')  $filters['assigned_to'] = 'unassigned';
    elseif (ctype_digit($who))  $filters['assigned_to'] = (int)$who;
    $job = (string)($_GET['job'] ?? '');
    if ($job !== '' && isset($JOBS[$job])) $filters['job_type'] = $job;
} else {
    $filters['assigned_to'] = $meId;
}

$rows = $venueId ? task_week_fetch($venueIds, $venueId, $weekStart, $filters) : [];
$grid = task_week_grid($rows, $weekStart, $today, $nowHms);

// People who could hold a task at this property, for the filter menu.
$people = [];
if ($canFilterPeople && $venueId) {
    try {
        $people = db_query(
            "SELECT DISTINCT a.id, a.name FROM admin_users a
               JOIN admin_user_venues av ON av.admin_user_id = a.id
              WHERE a.is_active = TRUE AND av.venue_id = :v
              ORDER BY a.name ASC", [':v' => $venueId]
        )->fetchAll();
    } catch (Throwable $e) { $people = []; }
}

/** Keep the current view when changing one parameter. */
$url = function (array $over = []) use ($venueId, $weekStart): string {
    $q = array_merge([
        'venue' => $venueId,
        'week'  => $weekStart,
        'who'   => (string)($_GET['who'] ?? ''),
        'job'   => (string)($_GET['job'] ?? ''),
    ], $over);
    return '/admin/timetable.php?' . http_build_query(array_filter($q, fn($x) => $x !== '' && $x !== 0));
};

/** A chip colour per job type, so a glance reads "who does what". */
$jobClass = fn(?string $j): string => 'tt-chip--' . preg_replace('/[^a-z]/', '', strtolower((string)$j)) ?: 'tt-chip--none';

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Timetable</h1>
  <div class="text-muted" style="font-size:13px">
    <?= (int)$grid['counts']['total'] ?> tasks ·
    <?= (int)$grid['counts']['done'] ?> done<?php if ($grid['counts']['overdue'] > 0): ?> ·
    <span style="color:#b3261e"><?= (int)$grid['counts']['overdue'] ?> overdue</span><?php endif; ?>
  </div>
</div>

<?php if (!$venueId): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">No properties are assigned to your account.</p></div></div>
<?php else: ?>

<div class="card" style="margin-bottom:1rem">
  <div class="card__body" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
    <form method="GET" action="/admin/timetable.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="week" value="<?= e($weekStart) ?>">
      <select name="venue" class="eselect" onchange="this.form.submit()">
        <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === $venueId ? 'selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($canFilterPeople): ?>
      <select name="who" class="eselect" onchange="this.form.submit()">
        <option value="">Everyone</option>
        <option value="unassigned" <?= ($_GET['who'] ?? '') === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
        <?php foreach ($people as $pp): ?>
        <option value="<?= (int)$pp['id'] ?>" <?= (string)($_GET['who'] ?? '') === (string)$pp['id'] ? 'selected' : '' ?>><?= e($pp['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="job" class="eselect" onchange="this.form.submit()">
        <option value="">Any job</option>
        <?php foreach ($JOBS as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= (string)($_GET['job'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </form>

    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <a class="btn-icon btn-icon--outline" href="<?= e($url(['week' => task_week_shift($weekStart, -1)])) ?>" aria-label="Previous week">‹</a>
      <span style="font-size:13px;min-width:170px;text-align:center">
        <?= e(date('j M', strtotime($grid['days'][0]))) ?> – <?= e(date('j M Y', strtotime($grid['days'][6]))) ?>
      </span>
      <a class="btn-icon btn-icon--outline" href="<?= e($url(['week' => task_week_shift($weekStart, 1)])) ?>" aria-label="Next week">›</a>
      <a class="btn-icon btn-icon--outline" href="<?= e($url(['week' => task_week_start($today)])) ?>">Today</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__body" style="overflow-x:auto">
    <table class="tt-grid">
      <thead>
        <tr>
          <th class="tt-hourcol"></th>
          <?php foreach ($grid['days'] as $d): ?>
          <th class="<?= $d === $today ? 'is-today' : '' ?>">
            <?= e(date('D', strtotime($d))) ?><br><span class="text-muted" style="font-weight:400"><?= e(date('j M', strtotime($d))) ?></span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <tr class="tt-anytime">
          <th class="tt-hourcol">Anytime</th>
          <?php foreach ($grid['days'] as $d): ?>
          <td class="<?= $d === $today ? 'is-today' : '' ?>">
            <?php foreach ($grid['anytime'][$d] as $t) tt_chip($t, $today, $nowHms, $jobClass); ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php foreach ($grid['hours'] as $h): ?>
        <tr>
          <th class="tt-hourcol"><?= sprintf('%02d:00', $h) ?></th>
          <?php foreach ($grid['days'] as $d): ?>
          <td class="<?= $d === $today ? 'is-today' : '' ?>">
            <?php foreach (($grid['cells'][$d][$h] ?? []) as $t) tt_chip($t, $today, $nowHms, $jobClass); ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($grid['counts']['total'] === 0): ?>
    <p class="text-muted" style="text-align:center;padding:2rem 0;margin:0">
      Nothing scheduled at this property for <?= e(date('j M', strtotime($grid['days'][0]))) ?>–<?= e(date('j M', strtotime($grid['days'][6]))) ?>.
    </p>
    <?php endif; ?>
  </div>
</div>

<div id="ttPanel" class="tt-panel" hidden aria-live="polite"></div>

<?php endif; ?>

<?php
/** One task chip. Carries its own data so the panel needs no second request. */
function tt_chip(array $t, string $today, string $nowHms, callable $jobClass): void {
    $late = task_is_overdue($t, $today, $nowHms);
    $done = (string)$t['status'] === 'done';
    $cls  = 'tt-chip ' . $jobClass($t['job_type'] ?? null)
          . ($done ? ' is-done' : '') . ($late ? ' is-late' : '');
    ?>
    <button type="button" class="<?= e($cls) ?>" data-task='<?= e(json_encode([
        'id'        => (int)$t['id'],
        'title'     => (string)$t['title'],
        'detail'    => (string)($t['detail'] ?? ''),
        'procedure' => (string)($t['procedure_text'] ?? ''),
        'assignee'  => (string)($t['assignee_name'] ?? ''),
        'status'    => (string)$t['status'],
        'time'      => $t['due_time'] ? substr((string)$t['due_time'], 0, 5) : '',
    ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
      <span class="tt-chip__title"><?= e($t['title']) ?></span>
      <span class="tt-chip__who"><?= e($t['assignee_name'] ?: 'Unassigned') ?></span>
    </button>
    <?php
}
?>
<style>
.tt-grid{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed}
.tt-grid th,.tt-grid td{border:1px solid #e7e1d6;vertical-align:top;padding:3px}
.tt-grid thead th{padding:6px 3px;font-size:12px;text-align:center}
.tt-hourcol{width:62px;color:#8a8072;font-weight:400;text-align:right;padding-right:6px!important;white-space:nowrap}
.tt-grid td.is-today,.tt-grid th.is-today{background:#fdfaf4}
.tt-anytime td{background:#faf7f1}
.tt-chip{display:block;width:100%;text-align:left;border:1px solid transparent;border-radius:5px;padding:4px 5px;margin-bottom:3px;cursor:pointer;font:inherit;background:#eef2f7}
.tt-chip:last-child{margin-bottom:0}
.tt-chip__title{display:block;line-height:1.25}
.tt-chip__who{display:block;font-size:11px;opacity:.7}
.tt-chip.is-done{opacity:.5;text-decoration:line-through}
.tt-chip.is-late{border-color:#b3261e}
.tt-chip--housekeeping{background:#e8f1ec}
.tt-chip--laundry{background:#eef0f7}
.tt-chip--maintenance{background:#f7efe6}
.tt-chip--gardening{background:#eaf3e2}
.tt-chip--driver{background:#f2eef7}
.tt-chip--security{background:#f7eaea}
.tt-chip--frontdesk{background:#eaf0f7}
.tt-panel{position:fixed;right:0;top:0;bottom:0;width:340px;max-width:92vw;background:#fff;border-left:1px solid #e7e1d6;box-shadow:-8px 0 24px rgba(0,0,0,.08);padding:1.25rem;overflow:auto;z-index:60}
.tt-panel h3{margin:0 0 .35rem;font-size:16px}
.tt-proc{white-space:pre-wrap;background:#faf7f1;border-radius:6px;padding:10px;font-size:13px;line-height:1.55;margin-top:.5rem}
</style>
<script src="/admin/assets/admin-timetable.js?v=<?= @filemtime(__DIR__ . '/assets/admin-timetable.js') ?: time() ?>"></script>
<?php include __DIR__ . '/_layout_end.php'; ?>
