<?php
/**
 * Admin: employee profile (Item 7). Opens one directory person into a detailed
 * view — employment/contract, company, assigned tasks & timetable, attendance
 * (clock in/out) and their activity log — keeping the main Team page clean.
 *
 * Owner or manager, scoped by venue (a manager only sees their own properties'
 * staff). Editing employment details writes an audit row so it shows in the log.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/booking.php';           // mywork_tasks() (team helpers)
require_once __DIR__ . '/../includes/internal-messages.php'; // DM button
require_once __DIR__ . '/../includes/activity-log.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_manager();   // owner or manager

$pageTitle  = 'Employee';
$activeMenu = 'staff';

if (!hr_staff_supported()) {
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">The team directory needs the <code>add_hr_staff</code> migration.</p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

$id   = (int)($_GET['id'] ?? $_POST['hr_id'] ?? 0);
$p    = fetch_hr_staff_row($id);
$vids = admin_venue_ids();   // null = owner
if (!$p || !hr_staff_in_venue_scope($id, $p['venue_id'] !== null ? (int)$p['venue_id'] : null, $vids)) {
    http_response_code(404);
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Employee not found. <a href="/admin/staff.php?tab=directory">Back to Team</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

$flash = null;
if (!empty($_SESSION['emp_flash'])) { $flash = $_SESSION['emp_flash']; unset($_SESSION['emp_flash']); }

// ── Save employment details (owner/manager, scoped) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    verify_csrf();
    if (!hr_staff_profile_supported()) {
        $_SESSION['emp_flash'] = ['type'=>'error','msg'=>'Run the add_hr_staff_profile migration to edit employment details.'];
    } else {
        $g = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;
        $ctype = $g('contract_type');
        if ($ctype !== null && !in_array($ctype, hr_contract_types(), true)) $ctype = null;
        $ymd = function ($k) { $v = trim((string)($_POST[$k] ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
        db_query(
            "UPDATE hr_staff SET company=:co, contract_type=:ct, contract_start=:cs, contract_end=:ce, national_id=:nid, hr_notes=:notes WHERE id=:id",
            [':co'=>$g('company'), ':ct'=>$ctype, ':cs'=>$ymd('contract_start'), ':ce'=>$ymd('contract_end'),
             ':nid'=>$g('national_id'), ':notes'=>$g('hr_notes'), ':id'=>$id]
        );
        audit_log('hr.profile_save', 'hr_staff', $id, (string)$p['full_name']);
        $_SESSION['emp_flash'] = ['type'=>'success','msg'=>'Employment details saved.'];
    }
    header('Location: /admin/employee.php?id=' . $id); exit;
}

// ── Gather profile data ───────────────────────────────────────────
$venueNames = [];
foreach (db_query('SELECT id, name FROM venues')->fetchAll() as $v) { $venueNames[(int)$v['id']] = $v['name']; }
$homeVenue  = $p['venue_id'] !== null ? ($venueNames[(int)$p['venue_id']] ?? ('Property #' . (int)$p['venue_id'])) : (trim((string)($p['unit_label'] ?? '')) ?: 'Unassigned');
$extraVids  = hr_staff_venue_ids($id);
$dept       = (string)($p['department'] ?? '');
$acctId     = (int)($p['admin_user_id'] ?? 0);
$hasProfile = hr_staff_profile_supported();

// Linked account's open tasks + recent activity.
$tasks = ($acctId > 0) ? mywork_tasks($acctId) : [];

// Attendance for the current + previous month (clock in/out), most recent first.
$now = time();
$att = [];
if (attendance_supported()) {
    foreach ([0, -1] as $off) {
        $ym = strtotime(date('Y-m-01', $now) . " {$off} month");
        foreach (fetch_attendance_month($id, (int)date('Y', $ym), (int)date('n', $ym)) as $row) { $att[] = $row; }
    }
    usort($att, fn($a, $b) => strcmp((string)$b['work_date'], (string)$a['work_date']));
    $att = array_slice($att, 0, 20);
}

// Message action target.
$dmUrl = '';
if ($acctId > 0 && internal_group_channels_supported()) $dmUrl = '/admin/internal-messages.php?dm=' . $acctId;
elseif (!empty($p['phone']) && ($wa = hr_whatsapp_link($p['phone'])) !== '') $dmUrl = $wa;
elseif (!empty($p['email'])) $dmUrl = 'mailto:' . $p['email'];

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($p['full_name']) ?>
    <span class="badge <?= e(hr_department_badge($dept)) ?>" style="vertical-align:middle"><?= e($dept ?: 'Other') ?></span>
    <?php if (($p['status'] ?? 'active') !== 'active'): ?><span class="badge badge--grey" style="vertical-align:middle">Inactive</span><?php endif; ?>
  </h1>
  <div class="actions">
    <?php if ($dmUrl !== ''): ?>
    <a href="<?= e($dmUrl) ?>"<?= str_starts_with($dmUrl, 'http') ? ' target="_blank" rel="noopener"' : '' ?> class="btn-primary btn-sm"><?= admin_icon('message', 15) ?> Message</a>
    <?php endif; ?>
    <a href="/admin/staff.php?tab=directory" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Team</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="emp-grid">
  <!-- Overview -->
  <div class="card">
    <div class="card__head"><span class="card__title">Overview</span></div>
    <div class="card__body" style="padding:18px">
      <div class="detail-grid">
        <div><div class="detail-item__label">Position</div><div class="detail-item__value"><?= e($p['position'] ?: '—') ?></div></div>
        <div><div class="detail-item__label">Department</div><div class="detail-item__value"><?= e($dept ?: 'Other') ?></div></div>
        <div><div class="detail-item__label">Home property</div><div class="detail-item__value"><?= e($homeVenue) ?></div></div>
        <div><div class="detail-item__label">Also works at</div><div class="detail-item__value"><?php
          $ev = array_values(array_filter(array_map(fn($v) => $venueNames[$v] ?? '', $extraVids)));
          echo $ev ? e(implode(', ', $ev)) : '—'; ?></div></div>
        <div><div class="detail-item__label">Weekly off</div><div class="detail-item__value"><?= e($p['off_day'] ?: 'Sun') ?></div></div>
        <div><div class="detail-item__label">Phone</div><div class="detail-item__value"><?= $p['phone'] ? e($p['phone']) : '—' ?></div></div>
        <div><div class="detail-item__label">Email</div><div class="detail-item__value"><?= $p['email'] ? '<a href="mailto:' . e($p['email']) . '">' . e($p['email']) . '</a>' : '—' ?></div></div>
        <div><div class="detail-item__label">Login account</div><div class="detail-item__value"><?= $acctId ? 'Linked' : 'None' ?></div></div>
      </div>
    </div>
  </div>

  <!-- Employment / contract -->
  <div class="card">
    <div class="card__head"><span class="card__title">Employment &amp; contract</span></div>
    <div class="card__body" style="padding:18px">
      <?php if (!$hasProfile): ?>
        <p class="text-muted" style="font-size:13px;margin:0">Run the <code>add_hr_staff_profile.sql</code> migration to record contract details.</p>
      <?php else: ?>
      <form method="POST" action="/admin/employee.php?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_profile">
        <input type="hidden" name="hr_id" value="<?= $id ?>">
        <div class="emp-form">
          <div class="field"><label>Company</label><input name="company" class="inp" value="<?= e($p['company'] ?? '') ?>" placeholder="Employing company" style="width:100%"></div>
          <div class="field"><label>Contract type</label>
            <select name="contract_type" class="filter-select eselect--block">
              <option value="">—</option>
              <?php foreach (hr_contract_types() as $ct): ?>
              <option value="<?= e($ct) ?>" <?= ($p['contract_type'] ?? '') === $ct ? 'selected' : '' ?>><?= e($ct) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Contract start</label>
            <button type="button" class="dp-btn" data-dp-target="cStart" data-dp-placeholder="Start date" style="width:100%"><?= !empty($p['contract_start']) ? e(date('j M Y', strtotime((string)$p['contract_start']))) : 'Start date' ?></button>
            <input type="hidden" id="cStart" name="contract_start" value="<?= e($p['contract_start'] ?? '') ?>">
          </div>
          <div class="field"><label>Contract end</label>
            <button type="button" class="dp-btn" data-dp-target="cEnd" data-dp-placeholder="End date (open-ended if blank)" style="width:100%"><?= !empty($p['contract_end']) ? e(date('j M Y', strtotime((string)$p['contract_end']))) : 'Open-ended' ?></button>
            <input type="hidden" id="cEnd" name="contract_end" value="<?= e($p['contract_end'] ?? '') ?>">
          </div>
          <div class="field"><label>ID / passport no.</label><input name="national_id" class="inp" value="<?= e($p['national_id'] ?? '') ?>" style="width:100%"></div>
        </div>
        <div class="field" style="margin-top:12px"><label>HR notes</label>
          <textarea name="hr_notes" class="inp inp--area" rows="3" style="width:100%;box-sizing:border-box" placeholder="Internal notes (not shown to the employee)"><?= e($p['hr_notes'] ?? '') ?></textarea>
        </div>
        <div style="margin-top:12px"><button type="submit" class="btn-primary btn-sm">Save details</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tasks / timetable -->
  <div class="card">
    <div class="card__head"><span class="card__title">Assigned tasks &amp; timetable</span></div>
    <div class="card__body" style="padding:18px">
      <?php if ($acctId <= 0): ?>
        <p class="text-muted" style="font-size:13px;margin:0">This person has no login account, so tasks can’t be assigned to them yet. Link an account from the Team page to enable tasks &amp; the timetable.</p>
      <?php elseif (!$tasks): ?>
        <p class="text-muted" style="font-size:13px;margin:0 0 10px">No open tasks assigned.</p>
        <a href="/admin/timetable.php" class="btn-outline btn-sm">Open timetable</a>
      <?php else: ?>
        <ul class="emp-tasks">
          <?php foreach ($tasks as $tk): ?>
          <li>
            <span class="badge <?= e(task_badge_class((string)$tk['status'])) ?>"><?= e(task_status_label((string)$tk['status'])) ?></span>
            <span class="emp-tasks__t"><?= e($tk['title']) ?></span>
            <span class="text-muted" style="font-size:12px"><?= !empty($tk['due_date']) ? e(date('j M', strtotime((string)$tk['due_date']))) : '' ?><?= !empty($tk['venue_name']) ? ' · ' . e($tk['venue_name']) : '' ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <a href="/admin/timetable.php" class="btn-outline btn-sm" style="margin-top:10px">Open timetable</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Attendance / clock in-out -->
  <div class="card">
    <div class="card__head" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card__title">Clock in / out</span>
      <?php if (attendance_supported()): ?><a href="/admin/attendance.php" class="btn-outline btn-sm">Full attendance</a><?php endif; ?>
    </div>
    <div class="card__body" style="padding:0">
      <?php if (!attendance_supported()): ?>
        <p class="text-muted" style="font-size:13px;margin:0;padding:18px">Attendance needs the <code>add_attendance</code> migration.</p>
      <?php elseif (!$att): ?>
        <p class="text-muted" style="font-size:13px;margin:0;padding:18px">No attendance recorded in the last two months.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Status</th><th>In</th><th>Out</th><th>In</th><th>Out</th><th style="text-align:right">Hours</th></tr></thead>
          <tbody>
            <?php foreach ($att as $r):
              $eff = attendance_effective_status($r); $hrs = attendance_hours($r); ?>
            <tr>
              <td style="white-space:nowrap"><?= e(date('D j M', strtotime((string)$r['work_date']))) ?></td>
              <td><span class="badge <?= e(attendance_status_badge($eff)) ?>"><?= e(attendance_status_label($eff)) ?></span></td>
              <td class="text-muted"><?= e(attendance_min_to_hhmm($r['in1'] ?? null) ?: '—') ?></td>
              <td class="text-muted"><?= e(attendance_min_to_hhmm($r['out1'] ?? null) ?: '—') ?></td>
              <td class="text-muted"><?= e(attendance_min_to_hhmm($r['in2'] ?? null) ?: '—') ?></td>
              <td class="text-muted"><?= e(attendance_min_to_hhmm($r['out2'] ?? null) ?: '—') ?></td>
              <td style="text-align:right"><?= $hrs > 0 ? number_format($hrs, 1) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Activity log -->
  <div class="card emp-span">
    <div class="card__head"><span class="card__title">Activity log</span></div>
    <div class="card__body" style="padding:14px 20px"><?php activity_log_html('hr_staff', $id); ?></div>
  </div>
</div>

<style>
.emp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;align-items:start}
.emp-span{grid-column:1/-1}
.emp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.emp-form .field label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.emp-tasks{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.emp-tasks li{display:flex;align-items:center;gap:8px;font-size:13px;flex-wrap:wrap}
.emp-tasks__t{font-weight:600}
</style>

<?php include __DIR__ . '/_layout_end.php'; ?>
