<?php
/**
 * Admin: staff requests (Team → Attendance → Requests) — one inbox for leave,
 * sick, day off, unpaid, corrections, overtime and shift swaps.
 *
 * Owner, manager or HR. Enter a request FOR someone (most staff have no login);
 * step 1 = supervisor (owner, their manager, HR), step 2 = HR (owner, HR);
 * owner/HR may "Submit and approve". Approval writes the attendance; cancelling
 * an approved request (owner/HR, with a reason) puts every day back.
 * Logic: includes/staff-requests.php · test: php tests/staff_requests_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/staff-requests.php';
require_once __DIR__ . '/../includes/hr-establishment.php';
require_once __DIR__ . '/../includes/hr-balances.php';
require_once __DIR__ . '/../includes/hr-documents.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_hr();   // owner, manager or HR

$pageTitle  = 'Requests';
$activeMenu = 'attendance';
$self       = '/admin/staff-requests.php';
$venueIds   = admin_venue_ids();
$companyIds = hr_admin_company_ids();
$role       = is_owner() ? 'owner' : (is_hr() ? 'hr' : (is_manager() ? 'manager' : ''));
$ready      = hr_staff_supported() && leave_requests_supported() && staff_requests_supported();

function sq_back(string $msg, string $type = 'success', string $qs = ''): never {
    $_SESSION['sq_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: /admin/staff-requests.php' . $qs); exit;
}
/** Is the person this account may act for? */
function sq_in_scope(int $staffId, ?array $venueIds, ?array $companyIds): bool {
    $p = fetch_hr_staff_row($staffId);
    return $p && hr_staff_in_venue_scope($staffId, $p['venue_id'] !== null ? (int)$p['venue_id'] : null, $venueIds, $companyIds);
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$ready) sq_back('Run the add_staff_requests migration first.', 'error');
    $action = (string)($_POST['action'] ?? '');
    $uid = (int)($_SESSION['admin_id'] ?? 0);
    try {
        if ($action === 'create') {
            $sid = (int)($_POST['hr_staff_id'] ?? 0);
            if (!sq_in_scope($sid, $venueIds, $companyIds)) throw new StaffRequestRefusal('Pick one of your team.');
            $in = $_POST;
            // A sick note (or any supporting file) is stored privately with the person's documents.
            if (!empty($_FILES['doc']['name']) && ($_FILES['doc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && hr_staff_documents_supported()) {
                $label = staff_request_type_label((string)($_POST['type'] ?? '')) . ' · ' . (string)($_POST['from'] ?? '');
                $d = hr_doc_store($sid, $_FILES['doc'], $label, $uid);
                if (!$d['ok']) throw new StaffRequestRefusal($d['error']);
                $in['doc_id'] = $d['id'];
            }
            $approve = !empty($_POST['and_approve']) && in_array($role, ['owner', 'hr'], true) ? $role : null;
            $id = staff_request_create($sid, $in, $uid, $approve);
            $r = staff_request_fetch($id);
            audit_log('hr.request_' . ($approve ? 'approved' : 'create'), 'leave_request', $id, staff_request_type_label((string)$r['leave_type']) . ' · ' . $r['full_name']);
            $msg = $approve ? 'Request entered and approved' : 'Request entered — waiting for the supervisor';
            if (!empty($r['details']['skipped'])) $msg .= '. Not changed (already worked): ' . implode(', ', array_map(fn($d) => date('j M', strtotime($d)), $r['details']['skipped']));
            sq_back($msg . '.');
        }
        $id = (int)($_POST['id'] ?? 0);
        $r = staff_request_fetch($id);
        if (!$r) throw new StaffRequestRefusal('That request no longer exists.');
        $inScope = sq_in_scope((int)$r['hr_staff_id'], $venueIds, $companyIds);
        if ($action === 'approve') {
            $st = staff_request_approve($id, $role, $uid, $inScope);
            audit_log('hr.request_' . ($st === 'approved' ? 'approved' : 'supervisor_ok'), 'leave_request', $id, $r['full_name']);
            $r2 = staff_request_fetch($id);
            $msg = $st === 'approved' ? 'Approved — the attendance is updated.' : 'Approved by the supervisor — now waiting for HR.';
            if ($st === 'approved' && !empty($r2['details']['skipped'])) $msg .= ' Not changed (already worked): ' . implode(', ', array_map(fn($d) => date('j M', strtotime($d)), $r2['details']['skipped'])) . '.';
            sq_back($msg);
        }
        if ($action === 'decline') {
            staff_request_decline($id, $role, $uid, $inScope, (string)($_POST['reason'] ?? ''));
            audit_log('hr.request_declined', 'leave_request', $id, (string)($_POST['reason'] ?? ''));
            sq_back('Declined.');
        }
        if ($action === 'cancel') {
            staff_request_cancel($id, $role, $uid, $inScope, (string)($_POST['reason'] ?? ''));
            audit_log('hr.request_cancelled', 'leave_request', $id, (string)($_POST['reason'] ?? ''));
            sq_back($r['status'] === 'approved' ? 'Cancelled — the attendance is back as it was.' : 'Cancelled.');
        }
        sq_back('Unknown action.', 'error');
    } catch (StaffRequestRefusal $e) {
        sq_back($e->getMessage(), 'error');
    }
}

// ── GET ──────────────────────────────────────────────────────────────────────
$flash = $_SESSION['sq_flash'] ?? null; unset($_SESSION['sq_flash']);
$fStatus = in_array($_GET['status'] ?? 'open', ['open', 'approved', 'declined', 'cancelled', 'all'], true) ? (string)($_GET['status'] ?? 'open') : 'open';
$fType   = isset(staff_request_types()[$_GET['type'] ?? '']) ? (string)$_GET['type'] : '';
$fCo     = (int)($_GET['co'] ?? 0);
$reqs    = $ready ? staff_requests_list($venueIds, $companyIds, ['status' => $fStatus, 'type' => $fType, 'company_id' => $fCo]) : [];
$people  = $ready ? fetch_hr_staff($venueIds, ['status' => 'active'], $companyIds) : [];
$bal     = $ready && hr_balances_supported() ? hr_bal_balances(array_map(fn($p) => (int)$p['id'], $people)) : [];
$balJs   = [];
foreach ($people as $p) $balJs[(int)$p['id']] = ['off' => hr_bal_fmt($bal[(int)$p['id']]['off_days'] ?? 0), 'leave' => hr_bal_fmt($bal[(int)$p['id']]['annual_leave'] ?? 0), 'offDay' => $p['off_day'] ?: 'SUN'];
$coNames = hr_staff_company_supported() ? hr_company_names() : [];
if ($venueIds !== null) $coNames = array_intersect_key($coNames, array_flip($companyIds ?? []));
$waiting = $ready ? staff_requests_waiting($venueIds, $companyIds, $role) : 0;
$staffNames = [];
foreach ($people as $p) $staffNames[(int)$p['id']] = $p['full_name'];

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Requests<?= $waiting ? ' <span class="badge badge--orange" style="vertical-align:middle">' . $waiting . ' waiting for you</span>' : '' ?></h1>
  <div class="actions">
    <?php if ($ready): ?><button type="button" class="btn-primary btn-sm" id="newReqBtn" aria-controls="reqCard" aria-expanded="false" data-help="staff-request-new"><?= admin_icon('plus', 15) ?> New request</button><?php endif; ?>
    <a href="/admin/attendance.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Attendance</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$ready): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">Run <code>add_staff_requests.sql</code> (Settings → Your account → Migrations) to use staff requests.</p></div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif; ?>

<!-- ═══════════ NEW REQUEST ═══════════ -->
<div class="card" id="reqCard" data-inline-form hidden style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">New request</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="<?= $self ?>" enctype="multipart/form-data" class="sq-form" id="reqForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="field"><label>For</label>
        <select name="hr_staff_id" class="filter-select eselect--block" required id="sqPerson">
          <option value="">Choose a person…</option>
          <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['full_name']) ?><?= $p['position'] ? ' · ' . e($p['position']) : '' ?></option><?php endforeach; ?>
        </select>
        <div class="sq-bal text-muted" id="sqBal" hidden></div>
      </div>
      <div class="field"><label>What</label>
        <select name="type" class="filter-select eselect--block" id="sqType" required>
          <?php foreach (staff_request_types() as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t[0]) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label id="sqFromLbl">First day</label>
        <button type="button" class="dp-btn" data-dp-target="sqFrom" data-dp-placeholder="Pick a date" style="width:100%">Pick a date</button><input type="hidden" id="sqFrom" name="from" value=""></div>
      <div class="field" data-sq-only="range"><label>Last day <span class="text-muted">(blank = one day)</span></label>
        <button type="button" class="dp-btn" data-dp-target="sqTo" data-dp-placeholder="Same day" style="width:100%">Same day</button><input type="hidden" id="sqTo" name="to" value=""></div>
      <div class="field sq-wide" data-sq-only="leave"><label class="optchip"><input type="checkbox" name="half_day" value="1"> Half day</label></div>
      <div class="field sq-wide" data-sq-only="correction" hidden>
        <label>Correct times</label>
        <div class="sq-times">
          <input name="in1" class="inp inp--sm tp-input" placeholder="In" aria-label="Time in"> <input name="out1" class="inp inp--sm tp-input" placeholder="Out" aria-label="Time out">
          <input name="in2" class="inp inp--sm tp-input" placeholder="In 2" aria-label="Time in after the break"> <input name="out2" class="inp inp--sm tp-input" placeholder="Out 2" aria-label="Time out after the break">
        </div>
        <span class="text-muted" style="font-size:12px">The day's times as they should be — what is there now is kept with the request.</span>
      </div>
      <div class="field" data-sq-only="overtime" hidden><label>Hours</label><input name="hours" class="inp" inputmode="decimal" placeholder="e.g. 3" style="width:100%">
        <label class="optchip" style="margin-top:6px"><input type="checkbox" name="worked" value="1"> Already worked (confirm)</label></div>
      <div class="field" data-sq-only="swap" hidden><label>Colleague who covers</label>
        <select name="with_staff_id" class="filter-select eselect--block"><option value="">Choose…</option>
          <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?></select>
        <label class="optchip" style="margin-top:6px"><input type="checkbox" name="accepted" value="1"> The colleague has agreed</label></div>
      <div class="field sq-wide"><label>Reason / note</label><input name="reason" class="inp" maxlength="500" placeholder="e.g. family wedding · forgot to clock out" style="width:100%"></div>
      <div class="field sq-wide" data-sq-only="sick"><label>Sick note or other paper <span class="text-muted">(optional — kept private with their documents)</span></label>
        <div class="filefield"><label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('plus', 15) ?> Choose file<input type="file" name="doc" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" data-file-input></label><span class="filefield__name" data-file-name>No file chosen</span></div></div>
      <div class="sq-wide sq-btns">
        <button class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Submit</button>
        <?php if (in_array($role, ['owner', 'hr'], true)): ?><button class="btn-outline btn-sm" name="and_approve" value="1" data-tip="Records both steps as you">Submit and approve</button><?php endif; ?>
        <span class="text-muted" style="font-size:12px">Entering a request never approves it on its own — it goes to the supervisor, then HR.</span>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════ FILTERS ═══════════ -->
<form method="GET" action="<?= $self ?>" class="filters" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
  <div class="filter-field"><span>Status</span>
    <select name="status" class="filter-select" onchange="this.form.submit()">
      <?php foreach (['open' => 'Waiting', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select></div>
  <div class="filter-field"><span>Type</span>
    <select name="type" class="filter-select" onchange="this.form.submit()"><option value="">All types</option>
      <?php foreach (staff_request_types() as $k => $t): ?><option value="<?= e($k) ?>" <?= $fType === $k ? 'selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach; ?>
    </select></div>
  <?php if ($coNames): ?>
  <div class="filter-field"><span>Company</span>
    <select name="co" class="filter-select" onchange="this.form.submit()"><option value="0">All companies</option>
      <?php foreach ($coNames as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= $fCo === (int)$cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?>
    </select></div>
  <?php endif; ?>
</form>

<!-- ═══════════ INBOX ═══════════ -->
<div class="card">
  <?php if (!$reqs): ?>
  <div class="card__body card__body--pad"><p class="text-muted" style="margin:0"><?= $fStatus === 'open' ? 'Nothing is waiting. New requests appear here.' : 'No requests match.' ?></p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table sq-table">
    <thead><tr><th>For</th><th>Request</th><th>Entered</th><th>Where it is</th><th style="text-align:right"></th></tr></thead>
    <tbody>
    <?php foreach ($reqs as $r): [$stL, $stB] = staff_request_status((string)$r['status']); $d = $r['details'];
      $days = (int) round((strtotime((string)$r['end_date']) - strtotime((string)$r['start_date'])) / 86400) + 1;
      $inScopeRow = true; $canAct = staff_request_can_act((string)$r['status'], $role, $inScopeRow);
      $canCancel = in_array($r['status'], ['pending', 'pending_hr'], true) || ($r['status'] === 'approved' && in_array($role, ['owner', 'hr'], true)); ?>
      <tr>
        <td><a href="/admin/employee.php?id=<?= (int)$r['hr_staff_id'] ?>#attendance"><strong><?= e($r['full_name']) ?></strong></a><div class="text-muted" style="font-size:12px"><?= e($r['position'] ?: '—') ?></div></td>
        <td><strong><?= e(staff_request_type_label((string)$r['leave_type'])) ?></strong>
          <div style="font-size:13px"><?= e(date('D j M', strtotime((string)$r['start_date']))) ?><?= $r['end_date'] !== $r['start_date'] ? ' – ' . e(date('D j M Y', strtotime((string)$r['end_date']))) . ' <span class="text-muted">(' . $days . ' days)</span>' : ' ' . e(date('Y', strtotime((string)$r['start_date']))) ?><?= !empty($d['half_day']) ? ' · half day' : '' ?></div>
          <?php if ($r['leave_type'] === 'correction' && !empty($d['times'])): $t = $d['times']; ?><div class="text-muted" style="font-size:12px">to <?= e(attendance_min_to_hhmm($t['in1']) . '–' . attendance_min_to_hhmm($t['out1']) . ($t['in2'] !== null ? ', ' . attendance_min_to_hhmm($t['in2']) . '–' . attendance_min_to_hhmm($t['out2']) : '')) ?></div><?php endif; ?>
          <?php if ($r['leave_type'] === 'overtime'): ?><div class="text-muted" style="font-size:12px"><?= e((string)($d['hours'] ?? '')) ?> h · <?= !empty($d['worked']) ? 'already worked' : 'planned' ?></div><?php endif; ?>
          <?php if ($r['leave_type'] === 'swap'): ?><div class="text-muted" style="font-size:12px">covered by <?= e($staffNames[(int)($d['with_staff_id'] ?? 0)] ?? '#' . (int)($d['with_staff_id'] ?? 0)) ?> · <?= !empty($d['accepted_at']) ? 'colleague agreed' : 'colleague not asked yet' ?></div><?php endif; ?>
          <?php if ($r['reason']): ?><div class="text-muted" style="font-size:12px">“<?= e($r['reason']) ?>”</div><?php endif; ?>
          <?php if (!empty($r['doc_id'])): ?><div style="font-size:12px"><a href="/admin/employee-file.php?doc=<?= (int)$r['doc_id'] ?>" target="_blank" rel="noopener"><?= admin_icon('download', 12) ?> Supporting file</a></div><?php endif; ?>
          <?php if (!empty($d['skipped'])): ?><div style="font-size:12px;color:#9a5b14">Not changed (worked): <?= e(implode(', ', array_map(fn($x) => date('j M', strtotime($x)), $d['skipped']))) ?></div><?php endif; ?></td>
        <td class="text-muted" style="font-size:12.5px"><?= e(date('j M', strtotime((string)$r['created_at']))) ?><?= $r['requested_by_name'] ? '<br>by ' . e($r['requested_by_name']) : '' ?></td>
        <td><span class="badge <?= e($stB) ?>"><?= e($stL) ?></span>
          <?php if (!empty($r['supervisor_name'])): ?><div class="text-muted" style="font-size:12px">Supervisor: <?= e($r['supervisor_name']) ?></div><?php endif; ?>
          <?php if (!empty($r['hr_name']) && $r['status'] === 'approved'): ?><div class="text-muted" style="font-size:12px">HR: <?= e($r['hr_name']) ?></div><?php endif; ?>
          <?php if (!empty($r['decline_reason'])): ?><div class="text-muted" style="font-size:12px"><?= e($r['decline_reason']) ?></div><?php endif; ?></td>
        <td class="sq-act">
          <?php if ($canAct): ?>
          <form method="POST" action="<?= $self ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn-primary btn-sm"><?= admin_icon('check', 14) ?> <?= in_array($role, ['owner', 'hr'], true) ? 'Approve' : 'Approve (to HR)' ?></button></form>
          <details class="sq-more"><summary class="btn-outline btn-sm">Decline</summary>
            <form method="POST" action="<?= $self ?>" class="sq-reason"><?= csrf_field() ?><input type="hidden" name="action" value="decline"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input name="reason" class="inp inp--sm" required placeholder="Why?" aria-label="Why it is declined"><button class="btn-outline btn-sm">Decline</button></form></details>
          <?php endif; ?>
          <?php if ($canCancel): ?>
          <details class="sq-more"><summary class="btn-outline btn-sm"><?= $r['status'] === 'approved' ? 'Cancel approved' : 'Cancel' ?></summary>
            <form method="POST" action="<?= $self ?>" class="sq-reason"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input name="reason" class="inp inp--sm" <?= $r['status'] === 'approved' ? 'required' : '' ?> placeholder="Why?" aria-label="Why it is cancelled"><button class="btn-outline btn-sm">Cancel it</button></form></details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<link rel="stylesheet" href="/css/timepicker.css?v=<?= @filemtime(__DIR__ . '/../css/timepicker.css') ?: '1' ?>">
<script src="/js/timepicker.js?v=<?= @filemtime(__DIR__ . '/../js/timepicker.js') ?: '1' ?>"></script>
<style>
.sq-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:start}
.sq-form .field>label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.sq-form .sq-wide{grid-column:1/-1}
.sq-times{display:flex;gap:6px;flex-wrap:wrap}.sq-times .inp{width:84px}
.sq-bal{font-size:12.5px;margin-top:6px}
.sq-btns{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.sq-table td{vertical-align:top}
.sq-act{text-align:right;white-space:nowrap}
.sq-act form,.sq-act details{display:inline-block;margin:0 0 4px 4px;vertical-align:top}
.sq-more>summary{list-style:none;cursor:pointer}.sq-more>summary::-webkit-details-marker{display:none}
.sq-reason{display:flex;gap:6px;margin-top:6px}
</style>
<script>
(function () {
  var card = document.getElementById('reqCard'), btn = document.getElementById('newReqBtn');
  if (btn && card) btn.addEventListener('click', function () { card.removeAttribute('hidden'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
  var bal = <?= json_encode($balJs) ?>;
  var person = document.getElementById('sqPerson'), out = document.getElementById('sqBal'), type = document.getElementById('sqType');
  function showBal() {
    var b = bal[person.value];
    if (!b) { out.hidden = true; return; }
    out.hidden = false;
    out.textContent = 'Annual leave: ' + b.leave + ' days · Off days owed: ' + b.off + ' · weekly off ' + b.offDay + ' (balances up to the last closed month)';
  }
  var LEAVE = ['annual', 'sick', 'day_off', 'unpaid', 'maternity', 'paternity', 'compassionate'];
  function showType() {
    var t = type.value;
    document.querySelectorAll('#reqForm [data-sq-only]').forEach(function (el) {
      var k = el.getAttribute('data-sq-only');
      var on = k === t || (k === 'leave' && LEAVE.indexOf(t) !== -1) || (k === 'range' && t !== 'correction') || (k === 'sick' && (t === 'sick' || LEAVE.indexOf(t) !== -1));
      el.hidden = !on;
    });
    document.getElementById('sqFromLbl').textContent = (t === 'correction' || t === 'overtime') ? 'Day' : 'First day';
  }
  if (person) person.addEventListener('change', showBal);
  if (type) { type.addEventListener('change', showType); showType(); }
  document.querySelectorAll('#reqForm [data-file-input]').forEach(function (fi) {
    fi.addEventListener('change', function () { fi.closest('.filefield').querySelector('[data-file-name]').textContent = fi.files && fi.files.length ? fi.files[0].name : 'No file chosen'; });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
