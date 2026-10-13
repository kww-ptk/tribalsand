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
require_once __DIR__ . '/../includes/attendance-clock.php';
require_once __DIR__ . '/../includes/hr-establishment.php';
require_once __DIR__ . '/../includes/attendance-import.php';
require_once __DIR__ . '/../includes/hr-balances.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_hr();

$venueIds   = admin_venue_ids();
$companyIds = hr_admin_company_ids();   // companies this account manages (Tribal Sand Limited has no property)
$view = (string)($_GET['view'] ?? 'daily');
if (!in_array($view, ['daily', 'week', 'month', 'summary', 'holidays', 'dashboard', 'person', 'leave'], true)) $view = 'daily';

/** True when the acting account may manage attendance/leave for this staff id. */
function att_staff_in_scope(int $staffId, ?array $venueIds, ?array $companyIds = null): bool {
    if (!$staffId) return false;
    $row = fetch_hr_staff_row($staffId);
    if (!$row) return false;
    // In scope via HOME venue OR any additional venue (Item 5).
    return hr_staff_in_venue_scope($staffId, $row['venue_id'] !== null ? (int)$row['venue_id'] : null, $venueIds, $companyIds);
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
    foreach (fetch_attendance_for_date($venueIds, $ymd, [], $companyIds) as $r) $allowed[(int)$r['hr_staff_id']] = true;

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
        if (isset($_POST['night_shown'][$sid])) attendance_set_night((int)$sid, $ymd, !empty($_POST['night'][$sid]));
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
    if (leave_requests_supported() && att_staff_in_scope($sid, $venueIds, $companyIds)) {
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
    if ($req && att_staff_in_scope((int)$req['hr_staff_id'], $venueIds, $companyIds)) {
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

// ── Public holidays as HR counts them (owner + HR) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'holiday') {
    verify_csrf();
    $hy = (int)($_POST['year'] ?? date('Y'));
    if (!is_owner() && !is_hr()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Holidays are changed by the owner or HR.'];
    } else {
        try {
            $d = (string)($_POST['date'] ?? '');
            $state = (string)($_POST['state'] ?? '');
            if ($state === 'add') $state = trim((string)($_POST['name'] ?? '')) ?: throw new RuntimeException('Give the holiday a name.');
            hr_holiday_set($d, $state, (string)($_POST['reason'] ?? ''), (string)(current_admin()['name'] ?? 'HR'));
            audit_log('hr.holiday', 'setting', 0, "$d → $state: " . (string)($_POST['reason'] ?? ''));
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Holiday list updated. Close the month again if it was already closed.'];
            $hy = (int)substr($d, 0, 4);
        } catch (Throwable $e) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>$e->getMessage()];
        }
    }
    header('Location: /admin/attendance.php?view=holidays&year=' . $hy); exit;
}

// ── Night allowance per property (owner + HR) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'night_rates') {
    verify_csrf();
    if (is_owner() || is_hr()) {
        $valid = array_map('intval', array_column(db_query('SELECT id FROM venues')->fetchAll(), 'id'));
        $rates = [];
        foreach ((array)($_POST['rate'] ?? []) as $vid => $amt) {
            $amt = trim(str_replace(',', '', (string)$amt));
            if (in_array((int)$vid, $valid, true) && is_numeric($amt) && (float)$amt > 0 && (float)$amt < 100000) $rates[(string)(int)$vid] = round((float)$amt, 2);
        }
        set_setting('night_allowance', json_encode($rates));
        audit_log('attendance.night_rates', 'setting', 0, json_encode($rates));
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Night allowance saved.'];
    }
    header('Location: /admin/attendance.php?view=summary&month=' . urlencode((string)($_POST['month'] ?? ''))); exit;
}

// ── Close a month: write everyone's balances for it (owner + HR) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_month') {
    verify_csrf();
    $ym = (string)($_POST['month'] ?? '');
    if (!is_owner() && !is_hr()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Closing a month is for the owner and HR.'];
    } else {
        try {
            $n = hr_bal_close_month($ym, (int)($_SESSION['admin_id'] ?? 0));
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>date('F Y', strtotime($ym . '-01')) . ' closed — balances updated for ' . $n['people'] . ' people'
                . ($n['skipped'] ? ' (' . $n['skipped'] . ' already covered by their opening balance)' : '') . '.'];
        } catch (Throwable $e) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>$e->getMessage()];
        }
    }
    header('Location: /admin/attendance.php?view=summary&month=' . urlencode($ym)); exit;
}

$pageTitle  = 'Attendance';
$activeMenu = 'attendance';

// Date / month context (Nairobi-local defaults).
$date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
[$mY, $mM] = array_map('intval', explode('-', $month));
$week  = attendance_week_start(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['week'] ?? '')) ? (string)$_GET['week'] : date('Y-m-d'));

// Shared filters (department / property).
$depts   = hr_departments();
$fDept   = in_array(($_GET['dept'] ?? ''), $depts, true) ? $_GET['dept'] : '';
$fVenue  = (int)($_GET['venue'] ?? 0);
// Employing company — everyone sees the companies their scope reaches (managers: the ones they look after).
$coNames = hr_staff_company_supported() ? hr_company_names() : [];
if ($venueIds !== null) $coNames = array_intersect_key($coNames, array_flip($companyIds ?? []));
$fCo     = isset($coNames[(int)($_GET['co'] ?? 0)]) ? (int)$_GET['co'] : 0;
$filters = array_filter(['department' => $fDept, 'venue_id' => $fVenue, 'company_id' => $fCo]);

$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll();

// ── Week CSV (same scope + filters) ──────────────────────────────────────────
if (($_GET['export'] ?? '') === 'week' && attendance_supported()) {
    $wk = attendance_week($venueIds, $week, $filters, $companyIds);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-week_' . $week . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['Name', 'Position', 'Property / unit', 'Off day'];
    foreach ($wk['days'] as $d) $head[] = date('D j M', strtotime($d));
    array_push($head, 'Days worked', 'Hours', 'Overtime hours', 'Night duties', 'Missing time out', 'Worked on off day', 'No rest day');
    fputcsv($out, $head);
    $venueNamesW = []; foreach ($venues as $v) { $venueNamesW[(int)$v['id']] = $v['name']; }
    foreach ($wk['staff'] as $s) {
        $row = [$s['full_name'], $s['position'], $s['venue_id'] !== null ? ($venueNamesW[(int)$s['venue_id']] ?? '') : (string)$s['unit_label'], $s['off_day'] ?: 'SUN'];
        foreach ($wk['days'] as $d) {
            $c = $s['cells'][$d] ?? null;
            $eff = $c ? attendance_effective_status($c) : '';
            $row[] = !$c ? '' : ($eff === 'P'
                ? trim(attendance_min_to_hhmm($c['in1'] !== null ? (int)$c['in1'] : null) . '-' . attendance_min_to_hhmm($c['out1'] !== null ? (int)$c['out1'] : null)
                  . ($c['in2'] !== null ? ' ' . attendance_min_to_hhmm((int)$c['in2']) . '-' . attendance_min_to_hhmm($c['out2'] !== null ? (int)$c['out2'] : null) : ''), '-')
                  . ' (' . number_format(attendance_hours($c), 1) . 'h' . (attendance_is_night($c) ? ', night' : '') . ')'
                : $eff);
        }
        $f = $s['fig'];
        array_push($row, $f['days'], $f['hours'], $f['ot'], $f['nights'], $f['missing'], $f['off_worked'], $f['no_rest'] ? 'yes' : '');
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ── Month summary CSV (HR's own columns; same scope + filters) ───────────────
if (($_GET['export'] ?? '') === 'summary' && attendance_supported()) {
    $sumRows = hr_bal_month($month, $venueIds, $companyIds, $filters);
    $venueNamesX = []; foreach ($venues as $v) { $venueNamesX[(int)$v['id']] = $v['name']; }
    $coAll = hr_company_names();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-summary_' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel reads UTF-8 with a BOM
    fputcsv($out, ['Company', 'Payroll no.', 'Name', 'Position', 'Property', 'Off day', 'Entitled off days (off day + PH)', 'Days worked',
        'Days off taken', 'Of which recovery', 'PH not worked', 'Sick', 'Leave', 'Unpaid leave', 'Absent', 'Not recorded', 'Hours worked',
        'Overtime hours', 'Overtime days', 'Off days earned', 'Off days owed (start)', 'Off days owed (end)', 'Leave accrued', 'Leave taken',
        'Annual leave (end)', 'Rest days worked', 'Holidays worked', 'Holiday hours', 'Night duties', 'Night allowance (KES)',
        'Normal hours', 'Overtime hours (paid)', 'Rest-day hours', 'Public-holiday hours']);
    $nightRates = attendance_night_rates();
    foreach ($sumRows as $sr) {
        $s = $sr['staff']; $f = $sr['fig'];
        if (!$f) continue;
        $offStart = (float)($sr['before']['off_days'] ?? 0); $lvStart = (float)($sr['before']['annual_leave'] ?? 0);
        $closed = hr_bal_month_closed($month) !== null;
        $offEnd = $closed ? (float)($sr['after']['off_days'] ?? 0) : $offStart + ($sr['counts'] ? $f['off_earned'] : 0);
        $lvEnd  = $closed ? (float)($sr['after']['annual_leave'] ?? 0) : $lvStart + ($sr['counts'] ? $f['leave_change'] : 0);
        fputcsv($out, [$coAll[(int)($s['company_id'] ?? 0)] ?? '', $s['payroll_no'] ?? '', $s['full_name'], $s['position'],
            $s['venue_id'] !== null ? ($venueNamesX[(int)$s['venue_id']] ?? '') : (string)$s['unit_label'], $s['off_day'] ?: 'SUN',
            $f['entitled'], $f['worked'], $f['off_taken'], $f['rec'], $f['ph_taken'], $f['sick'], $f['leave'], $f['unpaid'], $f['absent'], $f['not_recorded'],
            $f['hours'], $f['ot_hours'], $f['ot_days'], $f['off_earned'], round($offStart, 3), round($offEnd, 3),
            $f['leave_accrued'], $f['leave'], round($lvEnd, 3), $f['rest_day_worked'], $f['holiday_worked'], $f['holiday_hours'],
            $f['nights'], $s['venue_id'] !== null && isset($nightRates[(int)$s['venue_id']]) ? $f['nights'] * $nightRates[(int)$s['venue_id']] : 0,
            $f['hours_normal'], $f['hours_overtime'], $f['hours_rest'], $f['hours_holiday']]);
    }
    fclose($out);
    exit;
}

// ── CSV export of the month (same scope + filters) ───────────────────────────
// Native .xlsx with preserved formulas needs the zip extension + the original
// workbook template — a documented prod follow-up. CSV opens in Excel and
// carries the same data the Month grid shows.
if (($_GET['export'] ?? '') === 'csv' && attendance_supported()) {
    $matrix = attendance_month_matrix($venueIds, $mY, $mM, $filters, $companyIds);
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
  <div class="actions">
    <?php if ((is_owner() || is_hr()) && function_exists('attendance_import_supported') && attendance_import_supported()): ?>
    <a href="/admin/attendance-import.php" class="btn-outline btn-sm" data-help="attendance-import"><?= admin_icon('upload', 15) ?> Import from Excel</a>
    <?php endif; ?>
    <a href="/admin/staff.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Team</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!attendance_supported()): ?>
<div class="card"><div class="card__body card__body--pad">
  <p class="text-muted" style="margin:0">Attendance is unavailable. Run the <code>add_attendance.sql</code> migration (after <code>add_hr_staff</code>) to enable it.</p>
</div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif; ?>

<?php
// Preserve the active date/month + filters across the tab links.
$qs = function (array $extra) use ($date, $month, $week, $fDept, $fVenue, $fCo) {
    return http_build_query(array_merge(['date'=>$date,'month'=>$month,'week'=>$week,'dept'=>$fDept,'venue'=>$fVenue,'co'=>$fCo], $extra));
};
$tab = fn($v, $label) => '<a href="/admin/attendance.php?' . e($qs(['view'=>$v])) . '" data-shell-link class="ts-tab' . ($view===$v?' is-active':'') . '" style="padding:9px 16px;font-weight:600;font-size:14px;text-decoration:none;border-bottom:2px solid ' . ($view===$v?'var(--teal,#1E5C6B)':'transparent') . ';color:' . ($view===$v?'var(--teal,#1E5C6B)':'var(--muted,#6b7280)') . '">' . e($label) . '</a>';
?>
<?php $__leavePending = leave_requests_supported() ? leave_pending_count($venueIds, $companyIds) : 0; ?>
<div class="ts-tabs" role="tablist" style="display:flex;gap:6px;border-bottom:1px solid var(--border,#e5e7eb);margin-bottom:18px">
  <?= $tab('daily', 'Daily') ?><?= $tab('week', 'Week') ?><?= $tab('month', 'Month grid') ?><?= hr_balances_supported() ? $tab('summary', 'Month summary') . $tab('holidays', 'Holidays') : '' ?><?= $tab('dashboard', 'Dashboard') ?><?= $tab('leave', 'Leave' . ($__leavePending ? ' (' . $__leavePending . ')' : '')) ?>
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
  <?php elseif ($view === 'week'): ?>
  <div class="filter-field"><span>Week</span>
    <div style="display:flex;gap:4px;align-items:center">
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?<?= e($qs(['view'=>'week','week'=>date('Y-m-d', strtotime($week.' -7 days'))])) ?>" data-shell-link aria-label="Previous week"><?= admin_icon('chevron-left') ?></a>
      <button type="button" class="dp-btn" data-dp-target="attWeekInput" style="min-width:190px"><?= e(date('j M', strtotime($week)) . ' – ' . date('j M Y', strtotime($week . ' +6 days'))) ?></button>
      <input type="hidden" name="week" id="attWeekInput" value="<?= e($week) ?>" onchange="this.form.submit()">
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?<?= e($qs(['view'=>'week','week'=>date('Y-m-d', strtotime($week.' +7 days'))])) ?>" data-shell-link aria-label="Next week"><?= admin_icon('chevron-right') ?></a>
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
  <?php if ($coNames): ?>
  <div class="filter-field"><span>Company</span>
    <select name="co" class="filter-select" onchange="this.form.submit()">
      <option value="0">All companies</option>
      <?php foreach ($coNames as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= $fCo===(int)$cid?'selected':'' ?>><?= e($cn) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</form>

<?php
// Group helper: venue name lookup.
$venueNames = []; foreach ($venues as $v) { $venueNames[(int)$v['id']] = $v['name']; }

if ($view === 'daily'):
  $rows = fetch_attendance_for_date($venueIds, $date, $filters, $companyIds);
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
        <thead><tr><th>Staff</th><th>Off</th><th>Quick</th><th>In 1</th><th>Out 1</th><th>In 2</th><th>Out 2</th><th>Status</th><?php if (attendance_night_supported()): ?><th data-tip="Night duty — on its own when the shift runs past midnight; tick or untick to set it">Night</th><?php endif; ?><th style="text-align:right">Total / OT</th></tr></thead>
        <tbody>
          <?php foreach ($gRows as $r):
            $sid = (int)$r['hr_staff_id'];
            $eff = attendance_effective_status($r);
            $isP = $eff === 'P';
            $punches = attendance_punches_supported() ? clock_punches_for($sid, $date) : [];
          ?>
          <tr class="att-row" data-sid="<?= $sid ?>" data-dept="<?= e($r['department'] ?? '') ?>" data-off="<?= e($r['off_day'] ?? '') ?>" data-std="<?= attendance_standard_hours($r['department'] ?? '') ?>">
            <td><strong><?= e($r['full_name']) ?></strong><span class="text-muted" style="display:block;font-size:11px"><?= e($r['position'] ?: '—') ?></span>
              <?php if ($punches): ?>
              <div class="text-muted" style="font-size:10.5px;margin-top:4px;line-height:1.6">
                <span style="text-transform:uppercase;letter-spacing:.03em">Self-recorded:</span>
                <?php foreach ($punches as $pu): ?>
                <div style="display:flex;align-items:center;gap:5px;margin-top:2px">
                  <span><?= e(date('H:i', strtotime((string)$pu['punched_at']))) ?> <?= e($pu['kind']) ?></span>
                  <?php if (!empty($pu['photo_key'])): ?>
                  <a href="/admin/attendance-photo.php?punch=<?= (int)$pu['id'] ?>" target="_blank" rel="noopener" title="View captured photo">
                    <img src="/admin/attendance-photo.php?punch=<?= (int)$pu['id'] ?>" alt="" style="width:20px;height:20px;object-fit:cover;border-radius:4px;vertical-align:middle;border:1px solid var(--border,#e5e7eb)">
                  </a>
                  <?php elseif (clock_photo_expired($pu)): ?>
                  <span title="Photos are kept for <?= (int)CLOCK_PHOTO_RETENTION_DAYS ?> days, then deleted">photo expired</span>
                  <?php else: ?>
                  <span>no photo</span>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:12px"><?= e($r['off_day'] ?: 'Sun') ?></td>
            <td><div style="display:flex;gap:3px">
              <button type="button" class="btn-icon btn-icon--outline att-q" data-shift="std" title="Standard 8h">8h</button>
              <button type="button" class="btn-icon btn-icon--outline att-q" data-shift="secday" title="Security day">D</button>
              <button type="button" class="btn-icon btn-icon--outline att-q" data-shift="secnight" title="Security night">N</button>
            </div></td>
            <td><input class="inp inp--sm att-t tp-input" name="in1[<?= $sid ?>]"  value="<?= e(attendance_min_to_hhmm($r['in1']  !== null ? (int)$r['in1']  : null)) ?>" placeholder="—" style="width:74px"></td>
            <td><input class="inp inp--sm att-t tp-input" name="out1[<?= $sid ?>]" value="<?= e(attendance_min_to_hhmm($r['out1'] !== null ? (int)$r['out1'] : null)) ?>" placeholder="—" style="width:74px"></td>
            <td><input class="inp inp--sm att-t tp-input" name="in2[<?= $sid ?>]"  value="<?= e(attendance_min_to_hhmm($r['in2']  !== null ? (int)$r['in2']  : null)) ?>" placeholder="—" style="width:74px"></td>
            <td><input class="inp inp--sm att-t tp-input" name="out2[<?= $sid ?>]" value="<?= e(attendance_min_to_hhmm($r['out2'] !== null ? (int)$r['out2'] : null)) ?>" placeholder="—" style="width:74px"></td>
            <td>
              <select name="status[<?= $sid ?>]" class="filter-select att-status">
                <option value="" <?= $isP || $eff==='' ? 'selected' : '' ?>>Worked (P)</option>
                <?php foreach (attendance_statuses() as $sk => $sl): if ($sk === 'P') continue; ?>
                <option value="<?= e($sk) ?>" <?= $eff===$sk?'selected':'' ?>><?= e($sl) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <?php if (attendance_night_supported()): ?>
            <td style="text-align:center"><input type="hidden" name="night_shown[<?= $sid ?>]" value="1">
              <label class="att-night" data-tip="Night duty"><input type="checkbox" name="night[<?= $sid ?>]" value="1" <?= $r['att_id'] && attendance_is_night($r) ? 'checked' : '' ?> aria-label="Night duty for <?= e($r['full_name']) ?>"><?= admin_icon('moon', 15) ?></label></td>
            <?php endif; ?>
            <td style="text-align:right"><span class="att-total" style="font-variant-numeric:tabular-nums"><?= $isP ? number_format(attendance_hours($r),1) . 'h' : '—' ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <?php endforeach; endif; ?>
</form>

<style>.att-night{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid var(--border,#e5e7eb);border-radius:8px;cursor:pointer;color:var(--muted,#6b7280)}
.att-night input{position:absolute;opacity:0;width:1px;height:1px}
.att-night:has(input:checked){background:#1E2A44;border-color:#1E2A44;color:#fff}
.att-night:has(input:focus-visible){outline:2px solid var(--teal,#1E5C6B);outline-offset:2px}</style>
<link rel="stylesheet" href="/css/timepicker.css?v=<?= @filemtime(__DIR__ . '/../css/timepicker.css') ?: '1' ?>">
<script src="/js/timepicker.js?v=<?= @filemtime(__DIR__ . '/../js/timepicker.js') ?: '1' ?>"></script>
<script src="/admin/assets/admin-attendance.js?v=<?= @filemtime(__DIR__ . '/assets/admin-attendance.js') ?: time() ?>"></script>

<?php
elseif ($view === 'week'):
  $wk = attendance_week($venueIds, $week, $filters, $companyIds);
  $wkHol = hr_public_holidays($wk['days'][0], $wk['days'][6]);
  $wgroups = [];
  foreach ($wk['staff'] as $s) {
    $vid = $s['venue_id'] !== null ? (int)$s['venue_id'] : 0;
    $wgroups[$vid > 0 ? ($venueNames[$vid] ?? 'Property') : (trim((string)$s['unit_label']) ?: 'No property')][] = $s;
  }
  $tot = ['missing' => 0, 'no_rest' => 0, 'nights' => 0];
  foreach ($wk['staff'] as $s) { $tot['missing'] += $s['fig']['missing']; $tot['no_rest'] += $s['fig']['no_rest'] ? 1 : 0; $tot['nights'] += $s['fig']['nights']; }
?>
<div class="card no-print" style="margin-bottom:16px"><div class="card__body" style="padding:12px 18px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
  <span style="display:flex;gap:6px;flex-wrap:wrap">
    <span class="badge badge--grey"><?= count($wk['staff']) ?> people</span>
    <?php if ($tot['missing']): ?><span class="badge badge--orange"><?= $tot['missing'] ?> missing time out</span><?php endif; ?>
    <?php if ($tot['no_rest']): ?><span class="badge badge--red"><?= $tot['no_rest'] ?> with no rest day</span><?php endif; ?>
    <?php if ($tot['nights']): ?><span class="badge badge--blue"><?= $tot['nights'] ?> night duties</span><?php endif; ?>
  </span>
  <span style="display:flex;gap:8px">
    <button type="button" class="btn-outline btn-sm" onclick="window.print()"><?= admin_icon('download', 15) ?> Print</button>
    <a href="/admin/attendance.php?<?= e($qs(['view'=>'week','export'=>'week'])) ?>" class="btn-outline btn-sm"><?= admin_icon('download', 15) ?> Export</a>
  </span>
</div></div>
<h2 class="att-week-title"><?= e(date('j M', strtotime($wk['days'][0])) . ' – ' . date('j M Y', strtotime($wk['days'][6]))) ?></h2>
<?php if (!$wgroups): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No staff match these filters.</p></div></div>
<?php else: foreach ($wgroups as $gl => $list): ?>
<div class="card att-week-card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title"><?= e($gl) ?></span><span class="text-muted" style="font-size:12px"><?= count($list) ?> people</span></div>
  <div class="table-wrap"><table class="data-table att-week">
    <thead><tr><th>Name</th>
      <?php foreach ($wk['days'] as $d): ?><th class="d<?= isset($wkHol[$d]) ? ' is-ph' : '' ?>"<?= isset($wkHol[$d]) ? ' data-tip="' . e($wkHol[$d]) . '"' : '' ?>><?= e(date('D', strtotime($d))) ?><span><?= e(date('j M', strtotime($d))) ?></span></th><?php endforeach; ?>
      <th class="n">Days</th><th class="n">Hours</th><th class="n">OT</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $s): $f = $s['fig']; ?>
      <tr>
        <td class="nm"><a href="/admin/employee.php?id=<?= (int)$s['id'] ?>#attendance"><strong><?= e($s['full_name']) ?></strong></a><span><?= e($s['position'] ?: '—') ?> · off <?= e($s['off_day'] ?: 'SUN') ?></span></td>
        <?php foreach ($wk['days'] as $d): $c = $s['cells'][$d] ?? null; $eff = $c ? attendance_effective_status($c) : '';
          $isOff = attendance_is_off_day((string)$s['off_day'], $d);
          $miss = $c && (($c['in1'] !== null) !== ($c['out1'] !== null) || ($c['in2'] !== null) !== ($c['out2'] !== null)); ?>
        <td class="d<?= $isOff ? ' is-off' : '' ?><?= isset($wkHol[$d]) ? ' is-ph' : '' ?><?= $miss ? ' is-miss' : '' ?>">
          <a href="/admin/attendance.php?<?= e($qs(['view'=>'daily','date'=>$d])) ?>" data-shell-link>
          <?php if (!$c): ?><span class="dot">·</span>
          <?php elseif ($eff === 'P' || $c['in1'] !== null): ?>
            <span class="t"><?= e(attendance_min_to_hhmm($c['in1'] !== null ? (int)$c['in1'] : null) ?: '?') ?>–<?= e(attendance_min_to_hhmm($c['out1'] !== null ? (int)$c['out1'] : null) ?: '?') ?></span>
            <?php if ($c['in2'] !== null || $c['out2'] !== null): ?><span class="t"><?= e(attendance_min_to_hhmm($c['in2'] !== null ? (int)$c['in2'] : null) ?: '?') ?>–<?= e(attendance_min_to_hhmm($c['out2'] !== null ? (int)$c['out2'] : null) ?: '?') ?></span><?php endif; ?>
            <span class="h"><?= number_format(attendance_hours($c), 1) ?>h<?= attendance_is_night($c) ? ' ' . admin_icon('moon', 11) : '' ?></span>
          <?php else: ?><span class="badge <?= e(attendance_status_badge($eff)) ?>"><?= e($eff) ?></span><?php endif; ?>
          </a>
        </td>
        <?php endforeach; ?>
        <td class="n"><strong><?= $f['days'] ?></strong></td>
        <td class="n"><?= number_format($f['hours'], 1) ?></td>
        <td class="n"><?= ($f['ot'] > 0 ? '+' : '') . number_format($f['ot'], 1) ?></td>
        <td class="fl"><?php if ($f['no_rest']): ?><span class="badge badge--red" data-tip="Worked all seven days — one rest day a week is the rule">no rest day</span><?php endif; ?>
          <?php if ($f['missing']): ?><span class="badge badge--orange"><?= $f['missing'] ?> missing out</span><?php endif; ?>
          <?php if ($f['off_worked']): ?><span class="badge badge--grey" data-tip="Worked on their weekly off day">off day worked</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; endif; ?>
<style>
.att-week-title{display:none}
.att-week th.d{text-align:center;min-width:92px;font-size:11px}
.att-week th.d span{display:block;font-weight:500;opacity:.8}
.att-week td{vertical-align:top}
.att-week td.nm strong{display:block;white-space:nowrap}
.att-week td.nm span{font-size:11.5px;color:var(--muted,#6b7280);white-space:nowrap}
.att-week td.d{text-align:center;padding:6px 4px}
.att-week td.d a{display:flex;flex-direction:column;align-items:center;gap:1px;text-decoration:none;color:inherit}
.att-week td.d .t{font-size:11.5px;font-variant-numeric:tabular-nums;white-space:nowrap}
.att-week td.d .h{font-size:11px;color:var(--muted,#6b7280);display:inline-flex;align-items:center;gap:2px}
.att-week td.d .dot{color:var(--border,#e5e7eb)}
.att-week td.is-off{background:#f6f3ef}
.att-week .is-ph{background:#fdf0ec}
.att-week td.is-miss{box-shadow:inset 0 0 0 2px #f2c48f}
.att-week .n{text-align:right;white-space:nowrap}
.att-week td.fl .badge{margin:0 4px 4px 0}
@media print{.att-week-title{display:block;font-size:16px;margin:0 0 8px}.att-week-card{break-inside:avoid}.att-week td.d a{pointer-events:none}}
</style>

<?php
elseif ($view === 'month'):
  $matrix = attendance_month_matrix($venueIds, $mY, $mM, $filters, $companyIds);
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
elseif ($view === 'summary'):
  $sumRows = hr_bal_month($month, $venueIds, $companyIds, $filters);
  $closed  = hr_bal_month_closed($month);
  $canClose = (is_owner() || is_hr()) && $month < date('Y-m');
  $coAll   = hr_company_names();
  $nightRates = attendance_night_rates();
  $anyNights  = (bool) array_filter($sumRows, fn($sr) => ($sr['fig']['nights'] ?? 0) > 0);
  $groups  = [];
  foreach ($sumRows as $sr) {
    if (!$sr['fig']) continue;
    $g = $coAll[(int)($sr['staff']['company_id'] ?? 0)] ?? 'No company yet';
    $groups[$g][] = $sr;
  }
?>
<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap">
    <div>
      <?php if ($closed): ?>
        <span class="badge badge--green">Closed</span> <span class="text-muted" style="font-size:13px"><?= e(date('j M Y H:i', strtotime((string)$closed['closed_at']))) ?><?= $closed['by_name'] ? ' · ' . e($closed['by_name']) : '' ?> — balances include <?= e(date('F', strtotime($month . '-01'))) ?>.</span>
      <?php elseif ($month >= date('Y-m')): ?>
        <span class="badge badge--grey">Open</span> <span class="text-muted" style="font-size:13px">The month is still running — balances shown include it as it stands today.</span>
      <?php else: ?>
        <span class="badge badge--orange">Not closed</span> <span class="text-muted" style="font-size:13px">Check the month, then close it to add it to everyone's balances.</span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="/admin/attendance.php?<?= e($qs(['view'=>'summary','export'=>'summary'])) ?>" class="btn-outline btn-sm" data-tip="HR's monthly table, opens in Excel"><?= admin_icon('download', 15) ?> Export</a>
      <?php if ($canClose): ?>
      <form method="POST" action="/admin/attendance.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="close_month"><input type="hidden" name="month" value="<?= e($month) ?>">
        <button class="btn-<?= $closed ? 'outline' : 'primary' ?> btn-sm" data-confirm="<?= $closed ? 'Close ' . e(date('F Y', strtotime($month . '-01'))) . ' again? Its balances are worked out afresh from the attendance as it is now.' : 'Close ' . e(date('F Y', strtotime($month . '-01'))) . '? Everyone\'s off days and leave for the month are added to their balances.' ?>"><?= admin_icon('check', 15) ?> <?= $closed ? 'Close again' : 'Close month' ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (!$groups): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No attendance recorded for <?= e(date('F Y', strtotime($month . '-01'))) ?> with these filters.</p></div></div>
<?php else: foreach ($groups as $gName => $list): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title"><?= e($gName) ?></span><span class="text-muted" style="font-size:12px"><?= count($list) ?> people</span></div>
  <div class="table-wrap"><table class="data-table att-sum">
    <thead><tr><th>Name</th><th class="n" data-tip="Weekly off days + public holidays this month">Entitled off</th><th class="n">Worked</th><th class="n">Off taken</th>
      <th class="n">Sick</th><th class="n">Leave</th><th class="n">Hours</th><th class="n" data-tip="Hours over 8 a day worked (short days count against)">Overtime</th>
      <th class="n" data-tip="Entitled − taken + overtime ÷ 8">Off days earned</th><th class="n">Off days owed</th><th class="n">Annual leave</th>
      <?php if ($anyNights || $nightRates): ?><th class="n" data-tip="Night duties this month">Nights</th><th class="n" data-tip="Nights × the property's rate">Night allowance</th><?php endif; ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $sr): $s = $sr['staff']; $f = $sr['fig'];
      $offB = (float)($sr['before']['off_days'] ?? 0); $lvB = (float)($sr['before']['annual_leave'] ?? 0);
      $offA = $closed ? (float)($sr['after']['off_days'] ?? 0) : $offB + ($sr['counts'] ? $f['off_earned'] : 0);
      $lvA  = $closed ? (float)($sr['after']['annual_leave'] ?? 0) : $lvB + ($sr['counts'] ? $f['leave_change'] : 0); ?>
      <tr>
        <td><a href="/admin/employee.php?id=<?= (int)$s['id'] ?>#attendance"><strong><?= e($s['full_name']) ?></strong></a>
          <div class="text-muted" style="font-size:12px"><?= e($s['position'] ?: '—') ?> · off <?= e($s['off_day'] ?: 'SUN') ?><?= ($s['payroll_no'] ?? '') !== '' ? ' · No. ' . e($s['payroll_no']) : '' ?></div></td>
        <td class="n"><?= $f['entitled'] ?></td>
        <td class="n"><strong><?= $f['worked'] ?></strong></td>
        <td class="n"><?= $f['off_taken'] ?><?= $f['rec'] ? ' <span class="text-muted" data-tip="of which recovery days">(' . $f['rec'] . ' rec)</span>' : '' ?></td>
        <td class="n"><?= $f['sick'] ?: '' ?></td>
        <td class="n"><?= $f['leave'] ?: '' ?><?= $f['unpaid'] ? ' <span class="text-muted" data-tip="unpaid leave">+' . $f['unpaid'] . ' unpaid</span>' : '' ?></td>
        <td class="n"><?= number_format($f['hours'], 1) ?></td>
        <td class="n"><?= ($f['ot_hours'] > 0 ? '+' : '') . number_format($f['ot_hours'], 1) ?>h</td>
        <td class="n"><?= $sr['counts'] ? e(hr_bal_fmt($f['off_earned'])) : '<span class="text-muted" data-tip="Already inside their opening balance">—</span>' ?></td>
        <td class="n"><strong><?= e(hr_bal_fmt($offA)) ?></strong></td>
        <td class="n"><strong><?= e(hr_bal_fmt($lvA)) ?></strong></td>
        <?php if ($anyNights || $nightRates): $nr = $s['venue_id'] !== null ? ($nightRates[(int)$s['venue_id']] ?? 0) : 0; ?>
        <td class="n"><?= $f['nights'] ?: '' ?></td>
        <td class="n"><?= $f['nights'] && $nr ? 'KES ' . e(number_format($f['nights'] * $nr)) : '' ?></td>
        <?php endif; ?>
        <td><?php if ($f['not_recorded']): ?><span class="badge badge--orange" data-tip="Days in the month with nothing recorded (no times, no status)"><?= $f['not_recorded'] ?> not recorded</span><?php endif; ?>
            <?php if (!$sr['opened'] && $sr['counts']): ?><span class="badge badge--grey" data-tip="No opening balance yet — balances start from 0">no opening</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; endif; ?>
<?php
  $holWork = [];
  foreach ($sumRows as $sr) foreach (($sr['fig']['holiday_work'] ?? []) as $hd => $hh) $holWork[$hd][] = ['name' => $sr['staff']['full_name'], 'id' => (int)$sr['staff']['id'], 'hours' => $hh];
  ksort($holWork);
  $holNames = hr_public_holidays($month . '-01', date('Y-m-d', strtotime(date('Y-m-t', strtotime($month . '-01')) . ' +1 day')));
  if ($holWork): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Public-holiday work</span><span class="text-muted" style="font-size:12px">hours that fell on the holiday — a night shift counts only from midnight</span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Holiday</th><th>Who worked</th><th style="text-align:right">Hours</th></tr></thead>
    <tbody>
    <?php foreach ($holWork as $hd => $list): ?>
      <tr><td style="white-space:nowrap"><strong><?= e(date('D j M', strtotime($hd))) ?></strong><div class="text-muted" style="font-size:12px"><?= e($holNames[$hd] ?? '') ?></div></td>
        <td><?= implode(', ', array_map(fn($w) => '<a href="/admin/employee.php?id=' . $w['id'] . '#attendance">' . e($w['name']) . '</a> <span class="text-muted">' . e(number_format($w['hours'], 1)) . ' h</span>', $list)) ?></td>
        <td style="text-align:right"><strong><?= e(number_format(array_sum(array_column($list, 'hours')), 1)) ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php if (is_owner() || is_hr()): ?>
<details class="card att-rates" style="margin-bottom:16px" <?= $nightRates ? '' : 'open' ?>>
  <summary class="card__head" style="cursor:pointer"><span class="card__title"><?= admin_icon('moon', 15) ?> Night allowance</span>
    <span class="text-muted" style="font-size:12px"><?= $nightRates ? e(implode(' · ', array_map(fn($vid, $amt) => ($venueNames[$vid] ?? '#' . $vid) . ' KES ' . number_format($amt), array_keys($nightRates), $nightRates))) : 'not set' ?></span></summary>
  <div class="card__body card__body--pad">
    <form method="POST" action="/admin/attendance.php" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="night_rates"><input type="hidden" name="month" value="<?= e($month) ?>">
      <?php foreach ($venues as $v): ?>
      <div class="filter-field"><span><?= e($v['name']) ?> · KES a night</span><input name="rate[<?= (int)$v['id'] ?>]" class="inp inp--sm" inputmode="decimal" value="<?= isset($nightRates[(int)$v['id']]) ? e((string)$nightRates[(int)$v['id']]) : '' ?>" placeholder="none" style="width:120px"></div>
      <?php endforeach; ?>
      <button class="btn-primary btn-sm">Save</button>
    </form>
    <p class="text-muted" style="font-size:12px;margin:10px 0 0">Paid to people whose home property has a rate, for each night duty — a shift past midnight counts on its own; tick the moon on the Daily page to set one by hand.</p>
  </div>
</details>
<?php endif; ?>
<p class="text-muted" style="font-size:12px">Off days earned = entitled off days (weekly off + public holidays) − days off taken (off, recovery, holiday not worked) + overtime ÷ 8. Annual leave grows 1.75 days a month and drops by each leave day. Sick and unpaid days change neither.</p>
<style>.att-sum .n{text-align:right;white-space:nowrap}.att-sum td{vertical-align:top}.att-rates>summary{list-style:none;display:flex;justify-content:space-between;align-items:center}.att-rates>summary::-webkit-details-marker{display:none}</style>

<?php
elseif ($view === 'holidays'):
  $hy = (int)($_GET['year'] ?? date('Y'));
  if ($hy < 2020 || $hy > 2100) $hy = (int)date('Y');
  $hl = hr_holiday_list($hy);
  $canHol = is_owner() || is_hr();
?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="card__title">Public holidays <?= $hy ?></span>
    <span style="display:flex;gap:6px">
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?view=holidays&amp;year=<?= $hy - 1 ?>" aria-label="Previous year"><?= admin_icon('chevron-left') ?></a>
      <a class="btn-icon btn-icon--outline" href="/admin/attendance.php?view=holidays&amp;year=<?= $hy + 1 ?>" aria-label="Next year"><?= admin_icon('chevron-right') ?></a>
    </span>
  </div>
  <div class="card__body" style="padding:10px 18px"><p class="text-muted" style="margin:0;font-size:13px">The days that count as public holidays for staff: entitled off days, holiday hours and the holiday-work list use this list. Kenya's calendar is built in (Easter moves each year; Eid dates are entered per year). Switch a day off or add a gazetted one with a reason. Moon-sighting holidays not yet entered for the year simply don't appear — add them here.</p></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Date</th><th>Holiday</th><th>From</th><th>Counts for staff</th><th>Why</th><?php if ($canHol): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($hl as $h): ?>
      <tr<?= $h['counts'] ? '' : ' style="opacity:.6"' ?>>
        <td style="white-space:nowrap"><strong><?= e(date('D j M', strtotime($h['date']))) ?></strong></td>
        <td><?= e($h['name']) ?></td>
        <td class="text-muted"><?= $h['source'] === 'kenya' ? 'Kenya calendar' : 'Added by HR' ?></td>
        <td><?= $h['counts'] ? '<span class="badge badge--green">Yes</span>' : '<span class="badge badge--grey">Switched off</span>' ?></td>
        <td class="text-muted" style="font-size:12.5px"><?= e($h['note']) ?></td>
        <?php if ($canHol): ?>
        <td style="text-align:right">
          <form method="POST" action="/admin/attendance.php" class="hol-form"><?= csrf_field() ?><input type="hidden" name="action" value="holiday"><input type="hidden" name="year" value="<?= $hy ?>"><input type="hidden" name="date" value="<?= e($h['date']) ?>">
            <input type="hidden" name="state" value="<?= $h['source'] === 'hr' ? '' : ($h['counts'] ? 'off' : 'on') ?>">
            <input name="reason" class="inp inp--sm" required placeholder="Why?" style="width:150px" aria-label="Why">
            <button class="btn-outline btn-sm"><?= $h['source'] === 'hr' ? 'Remove' : ($h['counts'] ? 'Switch off' : 'Switch on') ?></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php if ($canHol): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Add a holiday</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="/admin/attendance.php" class="hol-add"><?= csrf_field() ?><input type="hidden" name="action" value="holiday"><input type="hidden" name="state" value="add"><input type="hidden" name="year" value="<?= $hy ?>">
      <div class="filter-field"><span>Date</span><button type="button" class="dp-btn" data-dp-target="holDate" style="min-width:150px">Pick a date</button><input type="hidden" id="holDate" name="date" value=""></div>
      <div class="filter-field"><span>Name</span><input name="name" class="inp inp--sm" required maxlength="80" placeholder="e.g. Eid al-Adha"></div>
      <div class="filter-field"><span>Why</span><input name="reason" class="inp inp--sm" required maxlength="200" placeholder="e.g. Gazette notice 12 May 2026"></div>
      <button class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add</button>
    </form>
  </div>
</div>
<?php endif; ?>
<style>.hol-form{display:flex;gap:6px;justify-content:flex-end;align-items:center}.hol-add{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}</style>

<?php
elseif ($view === 'leave'):
  if (!leave_requests_supported()):
?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">Leave requests are unavailable. Run the <code>add_leave_requests.sql</code> migration (after <code>add_attendance</code>) to enable it.</p></div></div>
<?php
  else:
    $activeStaff = fetch_hr_staff($venueIds, ['status' => 'active'], $companyIds);
    $reqs = fetch_leave_requests($venueIds, [], $companyIds);
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
  // Scope: a manager may only view staff at their own properties (home or extra).
  $inScope = $person && att_staff_in_scope($sid, $venueIds, $companyIds);
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
  $dash = attendance_dashboard($venueIds, $mY, $mM, $filters, $companyIds);
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
