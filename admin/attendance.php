<?php
/**
 * Admin: staff attendance (HR/attendance module).
 *
 * Three views (?view=daily|month|dashboard):
 *   • Daily     — the editor: pick a date, fill status/times for every staff
 *                 member (grouped by property), with quick-fill shift buttons and
 *                 bulk actions. PRG save; JS enhances with live totals + quick-fill.
 *   • Month     — everyone × days grid with the Sheet3 summary columns.
 *   • Dashboard — KPIs + by-property / by-department breakdowns.
 *
 * Gate: owner or manager (require_manager). Scoped by admin_venue_ids() — a
 * manager only sees/saves their own properties' staff. Nairobi-local dates.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_manager();

$venueIds = admin_venue_ids();
$view = (string)($_GET['view'] ?? 'daily');
if (!in_array($view, ['daily', 'month', 'dashboard', 'person', 'leave'], true)) $view = 'daily';

/** True when the acting account may manage attendance/leave for this staff id. */
function att_staff_in_scope(int $staffId, ?array $venueIds): bool {
    if (!$staffId) return false;
    $row = fetch_hr_staff_row($staffId);
    if (!$row) return false;
    return $venueIds === null || ($row['venue_id'] !== null && in_array((int)$row['venue_id'], $venueIds, true));
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// ── Save a day (PRG) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_day') {
    verify_csrf();
    $ymd = (string)($_POST['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) $ymd = date('Y-m-d');
    if (!attendance_supported()) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Run the add_attendance migration first.']; header('Location: /admin/attendance.php'); exit; }

    // Only staff in the acting account's scope may be written.
    $allowed = [];
    foreach (fetch_attendance_for_date($venueIds, $ymd) as $r) $allowed[(int)$r['hr_staff_id']] = true;

    $status = $_POST['status'] ?? []; $in1 = $_POST['in1'] ?? []; $out1 = $_POST['out1'] ?? [];
    $in2 = $_POST['in2'] ?? []; $out2 = $_POST['out2'] ?? [];
    $n = 0;
    foreach ($allowed as $sid => $_) {
        $st = trim((string)($status[$sid] ?? ''));
        $row = [
            'status' => $st,
            'in1' => (string)($in1[$sid] ?? ''), 'out1' => (string)($out1[$sid] ?? ''),
            'in2' => (string)($in2[$sid] ?? ''), 'out2' => (string)($out2[$sid] ?? ''),
        ];
        // Skip completely empty rows (no status, no times) so we don't create blank records.
        if ($st === '' && trim($row['in1'].$row['out1'].$row['in2'].$row['out2']) === '') continue;
        attendance_upsert((int)$sid, $ymd, $row, (int)($_SESSION['admin_id'] ?? 0));
        $n++;
    }
    audit_log('attendance.save_day', 'attendance', 0, "$ymd: $n rows");
    $_SESSION['hold_flash'] = ['type'=>'success','msg'=>"Saved attendance for " . date('D j M Y', strtotime($ymd)) . " ($n staff)."];
    header('Location: /admin/attendance.php?view=daily&date=' . urlencode($ymd)); exit;
}

// ── Leave: create a request ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'leave_create') {
    verify_csrf();
    $sid = (int)($_POST['hr_staff_id'] ?? 0);
    if (leave_requests_supported() && att_staff_in_scope($sid, $venueIds)) {
        $id = leave_create($sid, (string)($_POST['start_date'] ?? ''), (string)($_POST['end_date'] ?? ''),
                           (string)($_POST['leave_type'] ?? 'annual'), trim((string)($_POST['reason'] ?? '')),
                           (int)($_SESSION['admin_id'] ?? 0));
        $_SESSION['hold_flash'] = $id
            ? ['type'=>'success','msg'=>'Leave request created (pending approval).']
            : ['type'=>'error','msg'=>'Check the dates — end must be on or after start.'];
    } else {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your staff, or run the add_leave_requests migration.'];
    }
    header('Location: /admin/attendance.php?view=leave'); exit;
}

// ── Leave: approve / decline ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'leave_decide') {
    verify_csrf();
    $lid = (int)($_POST['leave_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'declined';
    $req = leave_requests_supported() ? fetch_leave_request($lid) : false;
    if ($req && att_staff_in_scope((int)$req['hr_staff_id'], $venueIds)) {
        $stamped = leave_decide($lid, $decision, (int)($_SESSION['admin_id'] ?? 0));
        audit_log('attendance.leave_' . $decision, 'leave_request', $lid, $stamped ? "$stamped days" : '');
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>$decision === 'approved'
            ? "Leave approved — $stamped day(s) marked on the attendance grid."
            : 'Leave request declined.'];
    } else {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your request.'];
    }
    header('Location: /admin/attendance.php?view=leave'); exit;
}

$pageTitle  = 'Attendance';
$activeMenu = 'attendance';

// Date / month context (Nairobi-local defaults).
$date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
[$mY, $mM] = array_map('intval', explode('-', $month));

// Shared filters (department / property).
$depts   = hr_departments();
$fDept   = in_array(($_GET['dept'] ?? ''), $depts, true) ? $_GET['dept'] : '';
$fVenue  = (int)($_GET['venue'] ?? 0);
$filters = array_filter(['department' => $fDept, 'venue_id' => $fVenue]);

$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll();

// ── CSV export of the month (same scope + filters) ───────────────────────────
// Native .xlsx with preserved formulas needs the zip extension + the original
// workbook template — a documented prod follow-up. CSV opens in Excel and
// carries the same data the Month grid shows.
if (($_GET['export'] ?? '') === 'csv' && attendance_supported()) {
    $matrix = attendance_month_matrix($venueIds, $mY, $mM, $filters);
    $daysIn = $matrix['days'];
    $venueNames = []; foreach ($venues as $v) { $venueNames[(int)$v['id']] = $v['name']; }
    $fname = 'attendance_' . $month . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    $head = ['Staff', 'Property', 'Department'];
    for ($d = 1; $d <= $daysIn; $d++) $head[] = (string)$d;
    array_push($head, 'Days worked', 'Days off', 'Sick', 'Recovery', 'Public holiday', 'Hours', 'Overtime');
    fputcsv($out, $head);
    foreach ($matrix['staff'] as $s) {
        $row = [
            $s['name'],
            $s['venue_id'] !== null ? ($venueNames[$s['venue_id']] ?? '') : 'Unassigned',
            $s['department'],
        ];
        for ($d = 1; $d <= $daysIn; $d++) $row[] = $s['cells'][$d] ?? '';
        $m = $s['summary'];
        array_push($row, $m['daysWorked'], $m['normalOff'], $m['sick'], $m['rec'], $m['ph'], $m['hours'], $m['ot']);
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Attendance</h1>
  <a href="/admin/staff.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Team</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!attendance_supported()): ?>
<div class="card"><div class="card__body card__body--pad">
  <p class="text-muted" style="margin:0">Attendance is unavailable. Run the <code>add_attendance.sql</code> migration (after <code>add_hr_staff</code>) to enable it.</p>
</div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif; ?>

<?php
// Preserve the active date/month + filters across the tab links.
$qs = function (array $extra) use ($date, $month, $fDept, $fVenue) {
    return http_build_query(array_merge(['date'=>$date,'month'=>$month,'dept'=>$fDept,'venue'=>$fVenue], $extra));
};
$tab = fn($v, $label) => '<a href="/admin/attendance.php?' . e($qs(['view'=>$v])) . '" data-shell-link class="ts-tab' . ($view===$v?' is-active':'') . '" style="padding:9px 16px;font-weight:600;font-size:14px;text-decoration:none;border-bottom:2px solid ' . ($view===$v?'var(--teal,#1E5C6B)':'transparent') . ';color:' . ($view===$v?'var(--teal,#1E5C6B)':'var(--muted,#6b7280)') . '">' . e($label) . '</a>';
?>
<?php $__leavePending = leave_requests_supported() ? leave_pending_count($venueIds) : 0; ?>
<div class="ts-tabs" role="tablist" style="display:flex;gap:6px;border-bottom:1px solid var(--border,#e5e7eb);margin-bottom:18px">
  <?= $tab('daily', 'Daily') ?><?= $tab('month', 'Month grid') ?><?= $tab('dashboard', 'Dashboard') ?><?= $tab('leave', 'Leave' . ($__leavePending ? ' (' . $__leavePending . ')' : '')) ?>
</div>

<!-- Shared filters -->
<form method="GET" action="/admin/attendance.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <?php if ($view === 'daily'): ?>
  <div class="filter-field"><span>Date</span>
    <div style="display:flex;gap:4px;align-items:center">
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?<?= e($qs(['view'=>'daily','date'=>date('Y-m-d', strtotime($date.' -1 day'))])) ?>" data-shell-link aria-label="Previous day"><?= admin_icon('chevron-left') ?></a>
      <button type="button" class="dp-btn" data-dp-target="attDateInput" style="min-width:150px"><?= e(date('D j M Y', strtotime($date))) ?></button>
      <input type="hidden" name="date" id="attDateInput" value="<?= e($date) ?>">
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?<?= e($qs(['view'=>'daily','date'=>date('Y-m-d', strtotime($date.' +1 day'))])) ?>" data-shell-link aria-label="Next day"><?= admin_icon('chevron-right') ?></a>
    </div>
  </div>
  <?php else: ?>
  <div class="filter-field"><span>Month</span>
    <div style="display:flex;gap:4px;align-items:center">
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?<?= e($qs(['view'=>$view,'month'=>date('Y-m', strtotime($month.'-01 -1 month'))])) ?>" data-shell-link aria-label="Previous month"><?= admin_icon('chevron-left') ?></a>
      <select name="month" class="filter-select" onchange="this.form.submit()">
        <?php for ($i = 15; $i >= -2; $i--): $mv = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); ?>
        <option value="<?= e($mv) ?>" <?= $month===$mv?'selected':'' ?>><?= e(date('M Y', strtotime($mv.'-01'))) ?></option>
        <?php endfor; ?>
      </select>
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?<?= e($qs(['view'=>$view,'month'=>date('Y-m', strtotime($month.'-01 +1 month'))])) ?>" data-shell-link aria-label="Next month"><?= admin_icon('chevron-right') ?></a>
    </div>
  </div>
  <?php endif; ?>
  <div class="filter-field"><span>Property</span>
    <select name="venue" class="filter-select" onchange="this.form.submit()">
      <option value="0">All properties</option>
      <?php foreach ($venues as $v): if ($venueIds !== null && !in_array((int)$v['id'], $venueIds, true)) continue; ?>
      <option value="<?= (int)$v['id'] ?>" <?= $fVenue===(int)$v['id']?'selected':'' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field"><span>Department</span>
    <select name="dept" class="filter-select" onchange="this.form.submit()">
      <option value="">All departments</option>
      <?php foreach ($depts as $d): ?><option value="<?= e($d) ?>" <?= $fDept===$d?'selected':'' ?>><?= e($d) ?></option><?php endforeach; ?>
    </select>
  </div>
</form>

<?php
// Group helper: venue name lookup.
$venueNames = []; foreach ($venues as $v) { $venueNames[(int)$v['id']] = $v['name']; }

if ($view === 'daily'):
  $rows = fetch_attendance_for_date($venueIds, $date, $filters);
  // Group by property.
  $groups = [];
  foreach ($rows as $r) {
    $vid = $r['venue_id'] !== null ? (int)$r['venue_id'] : 0;
    $label = $vid > 0 ? ($venueNames[$vid] ?? 'Property') : (trim((string)$r['unit_label']) ?: 'Unassigned');
    $groups[$label][] = $r;
  }
?>
<form method="POST" action="/admin/attendance.php" id="attForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_day">
  <input type="hidden" name="date" value="<?= e($date) ?>">

  <div class="card" style="margin-bottom:16px"><div class="card__body" style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <span class="text-muted">Bulk fill blank rows:</span>
    <button type="button" class="btn-outline btn-sm" data-bulk="std">Standard 8h</button>
    <button type="button" class="btn-outline btn-sm" data-bulk="secday">Security day</button>
    <button type="button" class="btn-outline btn-sm" data-bulk="OFF">Mark OFF</button>
    <button type="button" class="btn-outline btn-sm" data-bulk="auto">Auto off-days</button>
    <button type="submit" class="btn-primary btn-sm" style="margin-left:auto"><?= admin_icon('check', 15) ?> Save day</button>
  </div></div>

  <?php if (!$rows): ?>
  <div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No staff match — add people in the <a href="/admin/staff.php?tab=directory">directory</a> or widen the filters.</p></div></div>
  <?php else: foreach ($groups as $label => $gRows): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title"><?= e($label) ?></span><span class="text-muted" style="font-size:12px"><?= count($gRows) ?> staff</span></div>
    <div class="card__body" style="padding:0"><div class="table-wrap">
      <table class="data-table att-table">
        <thead><tr><th>Staff</th><th>Off</th><th>Quick</th><th>In 1</th><th>Out 1</th><th>In 2</th><th>Out 2</th><th>Status</th><th style="text-align:right">Total / OT</th></tr></thead>
        <tbody>
          <?php foreach ($gRows as $r):
            $sid = (int)$r['hr_staff_id'];
            $eff = attendance_effective_status($r);
            $isP = $eff === 'P';
          ?>
          <tr class="att-row" data-sid="<?= $sid ?>" data-dept="<?= e($r['department'] ?? '') ?>" data-off="<?= e($r['off_day'] ?? '') ?>" data-std="<?= attendance_standard_hours($r['department'] ?? '') ?>">
            <td><strong><?= e($r['full_name']) ?></strong><span class="text-muted" style="display:block;font-size:11px"><?= e($r['position'] ?: '—') ?></span></td>
            <td class="text-muted" style="font-size:12px"><?= e($r['off_day'] ?: 'Sun') ?></td>
            <td><div style="display:flex;gap:3px">
              <button type="button" class="btn-icon btn-icon--outline att-q" data-shift="std" title="Standard 8h">8h</button>
              <button type="button" class="btn-icon btn-icon--outline att-q" data-shift="secday" title="Security day">D</button>
              <button type="button" class="btn-icon btn-icon--outline att-q" data-shift="secnight" title="Security night">N</button>
            </div></td>
            <td><input class="inp inp--sm att-t" name="in1[<?= $sid ?>]"  value="<?= e(attendance_min_to_hhmm($r['in1']  !== null ? (int)$r['in1']  : null)) ?>" placeholder="—" style="width:74px"></td>
            <td><input class="inp inp--sm att-t" name="out1[<?= $sid ?>]" value="<?= e(attendance_min_to_hhmm($r['out1'] !== null ? (int)$r['out1'] : null)) ?>" placeholder="—" style="width:74px"></td>
            <td><input class="inp inp--sm att-t" name="in2[<?= $sid ?>]"  value="<?= e(attendance_min_to_hhmm($r['in2']  !== null ? (int)$r['in2']  : null)) ?>" placeholder="—" style="width:74px"></td>
            <td><input class="inp inp--sm att-t" name="out2[<?= $sid ?>]" value="<?= e(attendance_min_to_hhmm($r['out2'] !== null ? (int)$r['out2'] : null)) ?>" placeholder="—" style="width:74px"></td>
            <td>
              <select name="status[<?= $sid ?>]" class="filter-select att-status">
                <option value="" <?= $isP || $eff==='' ? 'selected' : '' ?>>Worked (P)</option>
                <?php foreach (attendance_statuses() as $sk => $sl): if ($sk === 'P') continue; ?>
                <option value="<?= e($sk) ?>" <?= $eff===$sk?'selected':'' ?>><?= e($sl) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td style="text-align:right"><span class="att-total" style="font-variant-numeric:tabular-nums"><?= $isP ? number_format(attendance_hours($r),1) . 'h' : '—' ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <?php endforeach; endif; ?>
</form>

<script src="/admin/assets/admin-attendance.js?v=<?= @filemtime(__DIR__ . '/assets/admin-attendance.js') ?: time() ?>"></script>

<?php
elseif ($view === 'month'):
  $matrix = attendance_month_matrix($venueIds, $mY, $mM, $filters);
  $daysIn = $matrix['days'];
?>
<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
  <a href="/admin/attendance.php?<?= e($qs(['view'=>'month','export'=>'csv'])) ?>" class="btn-outline btn-sm" data-tip="Download this month as CSV (opens in Excel)"><?= admin_icon('download', 15) ?> Export CSV</a>
</div>
<div class="card"><div class="card__body" style="padding:0"><div class="table-wrap">
  <table class="data-table att-month">
    <thead><tr>
      <th style="text-align:left;position:sticky;left:0;background:var(--navy,#1E5C6B)">Staff</th>
      <?php for ($d=1;$d<=$daysIn;$d++): $wd=date('D', strtotime(sprintf('%04d-%02d-%02d',$mY,$mM,$d))); $we=in_array($wd,['Sat','Sun']); ?>
      <th style="min-width:26px;<?= $we?'background:#0b6273':'' ?>"><?= $d ?></th>
      <?php endfor; ?>
      <th>Wk</th><th>Off</th><th>SK</th><th>Rec</th><th>PH</th>
    </tr></thead>
    <tbody>
      <?php if (!$matrix['staff']): ?>
      <tr><td colspan="<?= $daysIn+6 ?>" style="padding:22px;text-align:center;color:var(--muted)">No staff for this month / filter.</td></tr>
      <?php else: foreach ($matrix['staff'] as $s): $sm=$s['summary']; ?>
      <tr>
        <td style="text-align:left;position:sticky;left:0;background:#fff;white-space:nowrap"><a href="/admin/attendance.php?view=person&staff=<?= (int)$s['id'] ?>&month=<?= e($month) ?>" data-shell-link><strong><?= e($s['name']) ?></strong></a></td>
        <?php for ($d=1;$d<=$daysIn;$d++): $st=$s['cells'][$d] ?? ''; $cellDate=sprintf('%04d-%02d-%02d',$mY,$mM,$d); ?>
        <td style="padding:2px;text-align:center"><a href="/admin/attendance.php?view=daily&date=<?= e($cellDate) ?>" data-shell-link title="<?= $st?e(attendance_status_label($st)):'Edit '.$cellDate ?>" style="text-decoration:none;display:block"><?php if ($st!==''): ?><span class="badge <?= e(attendance_status_badge($st)) ?>" style="padding:2px 5px;font-size:10px"><?= e($st) ?></span><?php else: ?><span style="color:var(--border,#e5e7eb)">·</span><?php endif; ?></a></td>
        <?php endfor; ?>
        <td><strong><?= $sm['daysWorked'] ?></strong></td><td><?= $sm['normalOff'] ?></td><td><?= $sm['sick'] ?></td><td><?= $sm['rec'] ?></td><td><?= $sm['ph'] ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div></div>
<p class="text-muted" style="font-size:12px;margin-top:10px">Wk = days worked · Off = normal days off · SK = sick · Rec = recovery · PH = public holiday. Click a name for that person's month; click a day to edit it.</p>

<?php
elseif ($view === 'leave'):
  if (!leave_requests_supported()):
?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">Leave requests are unavailable. Run the <code>add_leave_requests.sql</code> migration (after <code>add_attendance</code>) to enable it.</p></div></div>
<?php
  else:
    $activeStaff = fetch_hr_staff($venueIds, ['status' => 'active']);
    $reqs = fetch_leave_requests($venueIds);
    $venueNamesL = []; foreach ($venues as $v) { $venueNamesL[(int)$v['id']] = $v['name']; }
?>
<div class="card" style="margin-bottom:18px">
  <div class="card__head"><span class="card__title">Request leave</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="/admin/attendance.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="leave_create">
      <div style="display:grid;grid-template-columns:repeat(2,minmax(200px,1fr));gap:16px">
        <div class="field"><label>Staff member</label>
          <select name="hr_staff_id" class="filter-select eselect--block" required>
            <option value="">— select —</option>
            <?php foreach ($activeStaff as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['full_name']) ?><?= $p['venue_id']!==null ? ' · ' . e($venueNamesL[(int)$p['venue_id']] ?? '') : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Type</label>
          <select name="leave_type" class="filter-select eselect--block">
            <?php foreach (leave_types() as $k=>$l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Start date</label>
          <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="lv" data-dp-target="lvStart" data-dp-placeholder="Start date">Start date</button>
          <input type="hidden" id="lvStart" name="start_date" required>
        </div>
        <div class="field"><label>End date</label>
          <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="lv" data-dp-target="lvEnd" data-dp-placeholder="End date">End date</button>
          <input type="hidden" id="lvEnd" name="end_date" required>
        </div>
        <div class="field" style="grid-column:1/-1"><label>Reason <small class="text-muted">(optional)</small></label>
          <input type="text" name="reason" class="inp" placeholder="Optional" style="width:100%">
        </div>
      </div>
      <div style="margin-top:14px"><button type="submit" class="btn-primary">Submit request</button></div>
    </form>
  </div>
</div>

<div class="card"><div class="card__head"><span class="card__title">Leave requests</span><span class="text-muted" style="font-size:12px"><?= count($reqs) ?> total</span></div>
  <div class="card__body" style="padding:0"><div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Staff</th><th>Dates</th><th>Type</th><th>Reason</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
        <?php if (!$reqs): ?>
        <tr><td colspan="6" style="padding:22px;text-align:center;color:var(--muted)">No leave requests yet.</td></tr>
        <?php else: foreach ($reqs as $r):
          $days = (int) ((strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400) + 1;
        ?>
        <tr>
          <td><strong><?= e($r['full_name']) ?></strong><span class="text-muted" style="display:block;font-size:11px"><?= $r['venue_id']!==null ? e($venueNamesL[(int)$r['venue_id']] ?? '') : 'Unassigned' ?></span></td>
          <td><?= e(date('j M', strtotime($r['start_date']))) ?> – <?= e(date('j M Y', strtotime($r['end_date']))) ?> <span class="text-muted">(<?= $days ?>d)</span></td>
          <td><?= e(leave_types()[$r['leave_type']] ?? $r['leave_type']) ?></td>
          <td class="text-muted" style="font-size:12.5px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['reason'] ?: '—') ?></td>
          <td><span class="badge <?= e(leave_status_badge($r['status'])) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td style="text-align:right">
            <?php if ($r['status'] === 'pending'): ?>
            <div class="dt-actions" style="justify-content:flex-end">
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="leave_decide"><input type="hidden" name="leave_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="approved"><button class="btn-outline btn-sm" data-confirm="Approve this leave? It will mark the days on the attendance grid.">Approve</button></form>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="leave_decide"><input type="hidden" name="leave_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="declined"><button class="btn-outline btn-sm">Decline</button></form>
            </div>
            <?php else: ?><span class="text-muted" style="font-size:12px"><?= $r['decided_at'] ? e(date('j M', strtotime($r['decided_at']))) : '' ?></span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div></div>
</div>
<?php endif; /* leave_requests_supported */ ?>

<?php
elseif ($view === 'person'):
  $sid = (int)($_GET['staff'] ?? 0);
  $person = fetch_hr_staff_row($sid);
  // Scope: a manager may only view staff at their own properties.
  $inScope = $person && ($venueIds === null || ($person['venue_id'] !== null && in_array((int)$person['venue_id'], $venueIds, true)));
  if (!$inScope):
?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">Staff member not found or outside your properties. <a href="/admin/attendance.php?view=month&month=<?= e($month) ?>">Back to the month grid</a>.</p></div></div>
<?php
  else:
    $dept  = (string)($person['department'] ?? '');
    $std   = attendance_standard_hours($dept);
    $days  = fetch_attendance_month($sid, $mY, $mM);
    $psum  = attendance_person_summary($days, $dept);
    $daysIn = (int) date('t', strtotime(sprintf('%04d-%02d-01', $mY, $mM)));
?>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:14px;flex-wrap:wrap">
  <div>
    <div style="font-size:1.15rem;font-weight:700;color:var(--navy,#1E5C6B)"><?= e($person['full_name']) ?></div>
    <div class="text-muted" style="font-size:13px"><?= e($person['position'] ?: '—') ?> · <?= e($dept ?: 'Other') ?> · <?= e(date('F Y', strtotime($month.'-01'))) ?> · standard <?= $std ?>h/day</div>
  </div>
  <button type="button" class="btn-outline btn-sm no-print" onclick="window.print()"><?= admin_icon('download', 15) ?> Print / PDF</button>
</div>
<div class="card"><div class="card__body" style="padding:0"><div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Date</th><th>Day</th><th>Status</th><th>In 1</th><th>Out 1</th><th>In 2</th><th>Out 2</th><th>Hours</th><th>OT</th></tr></thead>
    <tbody>
      <?php for ($d=1;$d<=$daysIn;$d++):
        $ymd = sprintf('%04d-%02d-%02d', $mY, $mM, $d);
        $row = $days[$d] ?? null;
        $eff = $row ? attendance_effective_status($row) : '';
        $hrs = $row && $eff==='P' ? attendance_hours($row) : 0;
        $ot  = $row && $eff==='P' ? attendance_overtime($row, $dept) : 0;
        $we  = in_array(date('D', strtotime($ymd)), ['Sat','Sun']);
      ?>
      <tr<?= $we?' style="background:#f2f7f8"':'' ?>>
        <td><?= e(date('j M', strtotime($ymd))) ?></td>
        <td class="text-muted"><?= e(date('D', strtotime($ymd))) ?></td>
        <td><?php if ($eff!==''): ?><span class="badge <?= e(attendance_status_badge($eff)) ?>"><?= e($eff) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><?= e($row ? attendance_min_to_hhmm($row['in1']  !== null ? (int)$row['in1']  : null) : '') ?: '—' ?></td>
        <td><?= e($row ? attendance_min_to_hhmm($row['out1'] !== null ? (int)$row['out1'] : null) : '') ?: '—' ?></td>
        <td><?= e($row ? attendance_min_to_hhmm($row['in2']  !== null ? (int)$row['in2']  : null) : '') ?: '—' ?></td>
        <td><?= e($row ? attendance_min_to_hhmm($row['out2'] !== null ? (int)$row['out2'] : null) : '') ?: '—' ?></td>
        <td style="text-align:right"><?= $eff==='P' ? number_format($hrs,1) : '' ?></td>
        <td style="text-align:right"><?= $eff==='P' && abs($ot)>0.01 ? (($ot>0?'+':'').number_format($ot,1)) : '' ?></td>
      </tr>
      <?php endfor; ?>
    </tbody>
    <tfoot><tr style="font-weight:700;border-top:2px solid var(--border,#e5e7eb)">
      <td colspan="7">Totals — worked <?= $psum['daysWorked'] ?> · off <?= $psum['normalOff'] ?> · sick <?= $psum['sick'] ?> · rec <?= $psum['rec'] ?> · PH <?= $psum['ph'] ?> · leave <?= $psum['leave'] ?></td>
      <td style="text-align:right"><?= number_format($psum['hours'],1) ?></td>
      <td style="text-align:right"><?= ($psum['ot']>=0?'+':'').number_format($psum['ot'],1) ?></td>
    </tr></tfoot>
  </table>
</div></div></div>
<p class="no-print" style="margin-top:12px"><a href="/admin/attendance.php?view=month&month=<?= e($month) ?>" data-shell-link class="btn-outline btn-sm"><?= admin_icon('arrow-left',15) ?> Month grid</a></p>
<?php endif; /* inScope */ ?>

<?php
else: // dashboard
  $dash = attendance_dashboard($venueIds, $mY, $mM, $filters);
  $k = $dash['kpi'];
  $card = fn($label, $val, $sub='') => '<div class="card" style="flex:1;min-width:150px"><div class="card__body" style="padding:16px 18px"><div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em">' . e($label) . '</div><div style="font-size:1.5rem;font-weight:800;color:var(--navy,#1E5C6B);margin-top:2px">' . e($val) . '</div>' . ($sub?'<div class="text-muted" style="font-size:11px">' . e($sub) . '</div>':'') . '</div></div>';
?>
<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px">
  <?= $card('Staff', $k['staff']) ?>
  <?= $card('Days worked', $k['daysWorked']) ?>
  <?= $card('Hours', number_format($k['hours'],1)) ?>
  <?= $card('Overtime', ($k['ot']>=0?'+':'') . number_format($k['ot'],1) . 'h') ?>
  <?= $card('Attendance', $k['attendancePct'] . '%', 'worked ÷ possible') ?>
  <?= $card('Sick', $k['sick']) ?>
  <?= $card('Off + leave', $k['offLeave']) ?>
  <?= $card('Public hol.', $k['ph']) ?>
</div>

<div class="two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
  <div class="card"><div class="card__head"><span class="card__title">By property</span></div><div class="card__body" style="padding:0"><div class="table-wrap">
    <table class="data-table"><thead><tr><th>Property</th><th>Staff</th><th>Worked</th><th>Hours</th><th>OT</th></tr></thead><tbody>
      <?php foreach ($dash['byProperty'] as $p): ?>
      <tr><td><?= e($p['label']) ?></td><td><?= $p['staff'] ?></td><td><?= $p['daysWorked'] ?></td><td><?= number_format($p['hours'],1) ?></td><td><?= number_format($p['ot'],1) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$dash['byProperty']): ?><tr><td colspan="5" style="padding:18px;text-align:center;color:var(--muted)">No data.</td></tr><?php endif; ?>
    </tbody></table>
  </div></div></div>
  <div class="card"><div class="card__head"><span class="card__title">By department</span></div><div class="card__body" style="padding:0"><div class="table-wrap">
    <table class="data-table"><thead><tr><th>Department</th><th>Staff</th><th>Worked</th><th>Hours</th><th>OT</th></tr></thead><tbody>
      <?php foreach ($dash['byDepartment'] as $p): ?>
      <tr><td><?= e($p['label']) ?></td><td><?= $p['staff'] ?></td><td><?= $p['daysWorked'] ?></td><td><?= number_format($p['hours'],1) ?></td><td><?= number_format($p['ot'],1) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$dash['byDepartment']): ?><tr><td colspan="5" style="padding:18px;text-align:center;color:var(--muted)">No data.</td></tr><?php endif; ?>
    </tbody></table>
  </div></div></div>
</div>
<style>@media(max-width:820px){.two-col{grid-template-columns:1fr!important}}</style>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
