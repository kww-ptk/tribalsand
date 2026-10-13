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

// ── GET ?check=1: what a request would do, shown under the form before Submit ──
if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int)($_GET['staff'] ?? 0);
    if (!$ready || !sq_in_scope($sid, $venueIds, $companyIds)) { echo json_encode(['ok' => false]); exit; }
    $c = staff_request_check($sid, (string)($_GET['type'] ?? ''), (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));
    $fmt = fn(array $ds) => array_map(fn($d) => date('D j M', strtotime($d)), $ds);
    echo json_encode(['ok' => true, 'days' => count($c['days']), 'off' => $fmt($c['off']), 'worked' => $fmt($c['worked']),
        'overlap' => array_map(fn($o) => $o['type'] . ' ' . date('j M', strtotime($o['from'])) . ($o['to'] !== $o['from'] ? '–' . date('j M', strtotime($o['to'])) : '') . ' (' . strtolower($o['status']) . ')', $c['overlap']),
        'needs_hr' => $c['needs_hr']]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$ready) sq_back('Run the add_staff_requests migration first.', 'error');
    if (($_POST['action'] ?? '') === 'hr_types') {
        if (!is_owner()) sq_back('Only the owner decides which requests need HR.', 'error');
        staff_request_set_hr_types(array_map('strval', (array)($_POST['types'] ?? [])));
        audit_log('hr.request_policy', 'setting', 0, implode(',', staff_request_hr_types()));
        sq_back('Saved — the other requests are final once the supervisor approves.');
    }
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
            $sent = $approve ? 0 : staff_request_notify($id, $uid);
            $msg = $approve ? 'Request entered and approved' : 'Request entered — waiting for the supervisor' . ($sent ? ' (' . $sent . ' emailed)' : '');
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
            $sent = $st === 'pending_hr' ? staff_request_notify($id, $uid) : 0;
            $msg = $st === 'approved' ? 'Approved — the attendance is updated.' : 'Approved by the supervisor — now waiting for HR' . ($sent ? ' (HR emailed)' : '') . '.';
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
$venuesF = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
if ($venueIds !== null) $venuesF = array_values(array_filter($venuesF, fn($v) => in_array((int)$v['id'], $venueIds, true)));
$fVenue  = in_array((int)($_GET['venue'] ?? 0), array_map(fn($v) => (int)$v['id'], $venuesF), true) ? (int)$_GET['venue'] : 0;
$reqs    = $ready ? staff_requests_list($venueIds, $companyIds, ['status' => $fStatus, 'type' => $fType, 'company_id' => $fCo, 'venue_id' => $fVenue]) : [];
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
      <div class="sq-wide sq-check" id="sqCheck" hidden aria-live="polite"></div>
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
  <?php if (count($venuesF) > 1): ?>
  <div class="filter-field"><span>Property</span>
    <select name="venue" class="filter-select" onchange="this.form.submit()"><option value="0">All properties</option>
      <?php foreach ($venuesF as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $fVenue === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?>
    </select></div>
  <?php endif; ?>
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
          <?php if (!empty($r['doc_id'])): ?><div style="font-size:12px"><?php if (in_array($role, ['owner', 'hr'], true) || !staff_request_doc_is_medical((int)$r['doc_id'])): ?><a href="/admin/employee-file.php?doc=<?= (int)$r['doc_id'] ?>" target="_blank" rel="noopener"><?= admin_icon('download', 12) ?> Supporting file</a><?php else: ?><span class="text-muted">Medical note on file (HR only)</span><?php endif; ?></div><?php endif; ?>
          <?php if (!empty($d['skipped'])): ?><div style="font-size:12px;color:#9a5b14">Not changed (worked): <?= e(implode(', ', array_map(fn($x) => date('j M', strtotime($x)), $d['skipped']))) ?></div><?php endif; ?></td>
        <td class="text-muted" style="font-size:12.5px"><?= e(date('j M', strtotime((string)$r['created_at']))) ?><?= $r['requested_by_name'] ? '<br>by ' . e($r['requested_by_name']) : '' ?></td>
        <td><span class="badge <?= e($stB) ?>"><?= e($stL) ?></span>
          <?php if (!empty($r['supervisor_name'])): ?><div class="text-muted" style="font-size:12px">Supervisor: <?= e($r['supervisor_name']) ?></div><?php endif; ?>
          <?php if (!empty($r['hr_name']) && $r['status'] === 'approved'): ?><div class="text-muted" style="font-size:12px">HR: <?= e($r['hr_name']) ?></div><?php endif; ?>
          <?php if (!empty($r['decline_reason'])): ?><div class="text-muted" style="font-size:12px"><?= e($r['decline_reason']) ?></div><?php endif; ?></td>
        <td class="sq-act">
          <?php if (in_array($r['status'], ['approved', 'declined', 'cancelled'], true) && ($wa = hr_whatsapp_link((string)($r['phone'] ?? ''))) !== ''): ?>
          <a class="btn-outline btn-sm" target="_blank" rel="noopener" href="<?= e($wa . '?text=' . rawurlencode(staff_request_result_text($r))) ?>" data-tip="Opens WhatsApp with the message ready — nothing is sent until you press Send"><?= admin_icon('phone', 14) ?> Tell them on WhatsApp</a>
          <?php endif; ?>
          <?php if ($canAct): ?>
          <form method="POST" action="<?= $self ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn-primary btn-sm"><?= admin_icon('check', 14) ?> <?= in_array($role, ['owner', 'hr'], true) || !staff_request_needs_hr((string)$r['leave_type']) ? 'Approve' : 'Approve (to HR)' ?></button></form>
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

<?php if (is_owner()): $hrTypes = staff_request_hr_types(); ?>
<details class="card sq-policy" style="margin-top:16px">
  <summary class="card__head"><span class="card__title">Which requests need HR's approval</span>
    <span class="text-muted" style="font-size:12px"><?= count($hrTypes) === count(staff_request_types()) ? 'all of them' : count($hrTypes) . ' of ' . count(staff_request_types()) ?></span></summary>
  <div class="card__body card__body--pad">
    <form method="POST" action="<?= $self ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="hr_types">
      <div class="optset">
        <?php foreach (staff_request_types() as $k => $t): ?><label class="optchip"><input type="checkbox" name="types[]" value="<?= e($k) ?>" <?= in_array($k, $hrTypes, true) ? 'checked' : '' ?>> <?= e($t[0]) ?></label><?php endforeach; ?>
      </div>
      <p class="text-muted" style="font-size:12.5px;margin:10px 0">Ticked: the supervisor approves, then HR gives the final yes. Not ticked: the supervisor's approval is final.</p>
      <button class="btn-primary btn-sm">Save</button>
    </form>
  </div>
</details>
<?php endif; ?>

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
.sq-check{border:1px solid var(--border,#e7ded7);border-radius:8px;padding:10px 12px;font-size:13px;background:#fbf9f6}
.sq-check .warn{color:#9a5b14}.sq-check .bad{color:#a12a1f;font-weight:600}
.sq-policy>summary{list-style:none;display:flex;justify-content:space-between;align-items:center;cursor:pointer}.sq-policy>summary::-webkit-details-marker{display:none}
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

  // What the request would do, before Submit: days covered, off days, days already worked, overlaps.
  var box = document.getElementById('sqCheck'), fromIn = document.getElementById('sqFrom'), toIn = document.getElementById('sqTo');
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  var last = '';
  function check() {
    if (!box || !person.value || !fromIn.value) { if (box) box.hidden = true; return; }
    var q = 'check=1&staff=' + encodeURIComponent(person.value) + '&type=' + encodeURIComponent(type.value)
          + '&from=' + encodeURIComponent(fromIn.value) + '&to=' + encodeURIComponent(toIn.value || fromIn.value);
    if (q === last) return; last = q;
    fetch('/admin/staff-requests.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (c) {
      if (!c.ok) { box.hidden = true; return; }
      var html = [];
      if (c.days) html.push('<strong>' + c.days + ' working day' + (c.days === 1 ? '' : 's') + '</strong> will be marked on the attendance.');
      if (c.off.length) html.push('Weekly off day' + (c.off.length === 1 ? '' : 's') + ' left alone: ' + esc(c.off.join(', ')) + '.');
      if (c.worked.length) html.push('<span class="warn">Already worked — left as they are: ' + esc(c.worked.join(', ')) + '.</span>');
      if (c.overlap.length) html.push('<span class="bad">Overlaps: ' + esc(c.overlap.join('; ')) + '.</span>');
      html.push(c.needs_hr ? 'Needs the supervisor, then HR.' : 'The supervisor\u2019s approval is final for this kind of request.');
      box.innerHTML = html.join('<br>'); box.hidden = false;
    }).catch(function () { box.hidden = true; });
  }
  [person, type].forEach(function (el) { if (el) el.addEventListener('change', check); });
  // The styled date picker fires change on its hidden inputs; poll lightly as a fallback.
  [fromIn, toIn].forEach(function (el) { if (el) el.addEventListener('change', check); });
  setInterval(function () { if (card && !card.hasAttribute('hidden')) check(); }, 800);
  document.querySelectorAll('#reqForm [data-file-input]').forEach(function (fi) {
    fi.addEventListener('change', function () { fi.closest('.filefield').querySelector('[data-file-name]').textContent = fi.files && fi.files.length ? fi.files[0].name : 'No file chosen'; });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
