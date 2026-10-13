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
require_once __DIR__ . '/../includes/hr-documents.php';      // Documents card (contracts / IDs)
require_once __DIR__ . '/../includes/hr-establishment.php';  // company + payroll no., company scope
require_once __DIR__ . '/../includes/hr-balances.php';       // Attendance tab: month figures + balances
require_once __DIR__ . '/../includes/staff-rooms.php';       // current staff room on the Overview
require_once __DIR__ . '/../includes/staff-requests.php';    // request types + statuses on the Attendance tab
require_once __DIR__ . '/../includes/inventory-people.php';   // Assets tab (assigned items)
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_hr();   // owner or manager

$pageTitle  = 'Employee';
$activeMenu = 'staff';

if (!hr_staff_supported()) {
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">The team directory needs the <code>add_hr_staff</code> migration.</p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

// A POST bigger than post_max_size arrives with $_POST and $_FILES EMPTY — no
// action, no CSRF token — so explain it instead of failing the CSRF check.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['emp_flash'] = ['type'=>'error','msg'=>'Those files are too large to upload in one go (max 15 MB each). Try fewer or smaller files.'];
    header('Location: /admin/employee.php?id=' . (int)($_GET['id'] ?? 0)); exit;
}

$id   = (int)($_GET['id'] ?? $_POST['hr_id'] ?? 0);
$p    = fetch_hr_staff_row($id);
$vids = admin_venue_ids();   // null = owner
$cids = hr_admin_company_ids();   // companies this account manages
if (!$p || !hr_staff_in_venue_scope($id, $p['venue_id'] !== null ? (int)$p['venue_id'] : null, $vids, $cids)) {
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
            "UPDATE hr_staff SET company=COALESCE(:co, company), contract_type=:ct, contract_start=:cs, contract_end=:ce, national_id=:nid, hr_notes=:notes WHERE id=:id",
            [':co'=>$g('company'), ':ct'=>$ctype, ':cs'=>$ymd('contract_start'), ':ce'=>$ymd('contract_end'),
             ':nid'=>$g('national_id'), ':notes'=>$g('hr_notes'), ':id'=>$id]
        );
        // Employing company + payroll number (staff establishment): a real company, and the
        // (company, payroll no.) pair stays unique.
        if (hr_staff_company_supported() && array_key_exists('company_id', $_POST)) {
            $coId  = (int)$_POST['company_id'];
            $coId  = isset(hr_company_names()[$coId]) ? $coId : 0;
            $payNo = $g('payroll_no');
            $clash = $coId && $payNo !== null && db_query('SELECT 1 FROM hr_staff WHERE company_id = :c AND UPPER(payroll_no) = UPPER(:p) AND id <> :id',
                         [':c' => $coId, ':p' => $payNo, ':id' => $id])->fetchColumn();
            if ($clash) {
                $_SESSION['emp_flash'] = ['type'=>'error','msg'=>'Someone at ' . hr_company_names()[$coId] . ' already has payroll number ' . $payNo . '. The other details were saved.'];
                header('Location: /admin/employee.php?id=' . $id . '#employment'); exit;
            }
            db_query('UPDATE hr_staff SET company_id = :c, payroll_no = :p WHERE id = :id', [':c' => $coId ?: null, ':p' => $payNo, ':id' => $id]);
        }
        audit_log('hr.profile_save', 'hr_staff', $id, (string)$p['full_name']);
        $_SESSION['emp_flash'] = ['type'=>'success','msg'=>'Employment details saved.'];
    }
    header('Location: /admin/employee.php?id=' . $id); exit;
}

// ── Balances: HR's correction / days paid out / opening balance (owner + HR) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bal_move') {
    verify_csrf();
    if (!is_owner() && !is_hr()) {
        $_SESSION['emp_flash'] = ['type'=>'error','msg'=>'Balances are changed by the owner or HR.'];
    } else {
        try {
            $days = (float) str_replace(',', '.', (string)($_POST['days'] ?? ''));
            hr_bal_add_move($id, (string)($_POST['kind'] ?? ''), (string)($_POST['reason'] ?? ''), $days,
                (string)($_POST['on'] ?? date('Y-m-d')), (string)($_POST['note'] ?? ''), (int)($_SESSION['admin_id'] ?? 0));
            audit_log('hr.balance_' . (string)$_POST['reason'], 'hr_staff', $id, (string)$_POST['kind'] . ' ' . $days . ': ' . (string)($_POST['note'] ?? ''));
            $_SESSION['emp_flash'] = ['type'=>'success','msg'=>'Balance updated.'];
        } catch (Throwable $e) {
            $_SESSION['emp_flash'] = ['type'=>'error','msg'=>$e->getMessage()];
        }
    }
    header('Location: /admin/employee.php?id=' . $id . '&amonth=' . urlencode((string)($_POST['amonth'] ?? '')) . '#attendance'); exit;
}

// ── Assets: assign / return / report lost (owner/manager, scoped above; every move re-checked) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['asset_assign', 'asset_return'], true)) {
    verify_csrf();
    try {
        if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
        $note = (string)($_POST['note'] ?? '');
        if ($_POST['action'] === 'asset_assign') {
            if (($p['status'] ?? 'active') !== 'active') throw new InvRefusal('They have left — items can be returned, not handed over.');
            $pick = (string)($_POST['pick'] ?? '');
            if (preg_match('/^qty:(\d+):(\d+)$/', $pick, $m)) {
                $item = inv_fetch_item((int)$m[1]);
                $in   = ['action' => 'transfer', 'qty' => (string)($_POST['qty'] ?? ''), 'from_id' => $m[2], 'to' => 'staff:' . $id, 'note' => $note];
            } elseif (preg_match('/^unit:(\d+)$/', $pick, $m)) {
                $itemId = db_query('SELECT item_id FROM inv_assets WHERE id = :a', [':a' => (int)$m[1]])->fetchColumn();
                $item   = $itemId ? inv_fetch_item((int)$itemId) : false;
                $in     = ['action' => 'transfer', 'asset_id' => $m[1], 'to' => 'staff:' . $id, 'note' => $note];
            } else {
                throw new InvRefusal('Pick what to hand over.');
            }
        } else {
            $item  = inv_fetch_item((int)($_POST['item_id'] ?? 0));
            if (!inv_person_location_find($id)) throw new InvRefusal('They don’t hold anything.');
            // The ensure call re-copies name + home venue onto their location, so a person
            // who moved property is scoped to their NEW venue before the move is checked.
            $ploc  = inv_person_location_id($id);
            $assetId = (string)($_POST['asset_id'] ?? '');
            if ($assetId !== '') {
                $at = db_query("SELECT location_id FROM inv_assets WHERE id = :a AND status = 'active'", [':a' => (int)$assetId])->fetchColumn();
                if ($at === false || (int)$at !== $ploc) throw new InvRefusal('That unit isn’t with this person.');
            }
            $do    = (string)($_POST['do'] ?? '');
            $base  = ['qty' => (string)($_POST['qty'] ?? '1'), 'asset_id' => $assetId, 'from_id' => (string)$ploc, 'note' => $note];
            if (preg_match('/^loc:\d+$/', $do))                        $in = $base + ['action' => 'transfer', 'to' => $do];
            elseif (preg_match('/^loss:(broken|missing|stolen)$/', $do, $m)) $in = $base + ['action' => 'loss', 'reason' => $m[1]];
            else throw new InvRefusal('Pick where it goes back to, or what happened.');
        }
        if (!$item) throw new InvRefusal('That item no longer exists.');
        $msg = inv_apply_item_action($in, $item, $vids, (int)(current_admin()['id'] ?? 0));
        audit_log('inv.' . $_POST['action'], 'hr_staff', $id, $msg);
        $_SESSION['emp_flash'] = ['type' => 'success', 'msg' => $msg];
    } catch (InvRefusal $e) {
        $_SESSION['emp_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: /admin/employee.php?id=' . $id . '#assets'); exit;
}

// ── Documents: upload one or more / delete (owner/manager, scoped above) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_docs') {
    verify_csrf();
    // Normalise docs[] (multiple) into a list of $_FILES-style entries.
    $files = [];
    $raw   = $_FILES['docs'] ?? null;
    if ($raw && is_array($raw['name'] ?? null)) {
        foreach ($raw['name'] as $i => $n) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $files[] = ['name' => $n, 'tmp_name' => $raw['tmp_name'][$i] ?? '', 'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $raw['size'][$i] ?? 0];
        }
    }
    $label = trim((string)($_POST['doc_label'] ?? ''));
    $ok = 0; $errs = [];
    foreach ($files as $f) {
        $r = hr_doc_store($id, $f, $label, (int)($_SESSION['admin_id'] ?? 0));
        if ($r['ok']) { $ok++; audit_log('hr.document_upload', 'hr_staff', $id, (string)$f['name']); }
        else          { $errs[] = $r['error']; }
    }
    if (!$files) {
        $_SESSION['emp_flash'] = ['type'=>'error','msg'=>'Choose at least one file to upload.'];
    } elseif ($errs) {
        $_SESSION['emp_flash'] = ['type'=>'error','msg'=>($ok ? "{$ok} uploaded. " : '') . implode(' ', $errs)];
    } else {
        $_SESSION['emp_flash'] = ['type'=>'success','msg'=>$ok === 1 ? 'Document uploaded.' : "{$ok} documents uploaded."];
    }
    header('Location: /admin/employee.php?id=' . $id . '#documents'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_doc') {
    verify_csrf();
    $docId = (int)($_POST['doc_id'] ?? 0);
    $doc   = fetch_hr_staff_document($docId);
    if ($doc && hr_doc_delete($docId, $id)) {
        audit_log('hr.document_delete', 'hr_staff', $id, (string)$doc['filename']);
        $_SESSION['emp_flash'] = ['type'=>'success','msg'=>'Document deleted.'];
    } else {
        $_SESSION['emp_flash'] = ['type'=>'error','msg'=>'Document not found.'];
    }
    header('Location: /admin/employee.php?id=' . $id . '#documents'); exit;
}

// ── Gather profile data ───────────────────────────────────────────
$venueNames = [];
foreach (db_query('SELECT id, name FROM venues')->fetchAll() as $v) { $venueNames[(int)$v['id']] = $v['name']; }
$homeVenue  = $p['venue_id'] !== null ? ($venueNames[(int)$p['venue_id']] ?? ('Property #' . (int)$p['venue_id'])) : (trim((string)($p['unit_label'] ?? '')) ?: 'Unassigned');
$extraVids  = hr_staff_venue_ids($id);
$dept       = (string)($p['department'] ?? '');
$acctId     = (int)($p['admin_user_id'] ?? 0);
$hasProfile = hr_staff_profile_supported();
$hasDocs    = hr_staff_documents_supported();
$docs       = $hasDocs ? fetch_hr_staff_documents($id) : [];

// Assets tab: what they hold, and what this account may hand them. Only the owner or
// a manager of their HOME venue sees their items — a manager who sees the profile via
// an extra venue gets the note only (a person's stock is hidden from them elsewhere too).
$invOn       = inv_supported();
$assetsOwned = $invOn && (is_owner() || ($p['venue_id'] !== null && in_array((int)$p['venue_id'], array_map('intval', $vids ?? []), true)));
$isActive    = ($p['status'] ?? 'active') === 'active';
if ($invOn && inv_person_location_find($id) !== null) inv_person_location_id($id);   // keep the owning venue current
$assets      = $assetsOwned ? inv_person_assets($id) : ['location' => null, 'rows' => []];
$assetStock  = $assetsOwned ? inv_assignable_stock($vids) : [];
$assetUnits  = $assetsOwned ? inv_assignable_units($vids) : [];
$returnTo    = $assetsOwned ? array_values(array_filter(inv_locations_visible($vids), fn($l) => $l['kind'] !== 'person')) : [];
$assetValue  = inv_sum_by_currency($assets['rows']);

// Linked account's open tasks + recent activity.
$tasks = ($acctId > 0) ? mywork_tasks($acctId) : [];

// Attendance tab: one month as a calendar + its figures, the balances and their history.
$aMonth = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['amonth'] ?? '')) ? (string)$_GET['amonth'] : date('Y-m');
$aDays  = [];
$aFig   = null; $nowFig = null;
if (attendance_supported()) {
    foreach (fetch_attendance_month($id, (int)substr($aMonth, 0, 4), (int)substr($aMonth, 5, 2)) as $row) $aDays[(string)$row['work_date']] = $row;
    $aHol  = hr_public_holidays($aMonth . '-01', date('Y-m-d', strtotime(date('Y-m-t', strtotime($aMonth . '-01')) . ' +1 day')));
    $aFig  = $aDays ? hr_bal_month_figures($aDays, (string)$p['off_day'], $aMonth, $aHol) : null;
    if ($aMonth === date('Y-m')) $nowFig = $aFig;
    else {
        $nd = [];
        foreach (fetch_attendance_month($id, (int)date('Y'), (int)date('n')) as $row) $nd[(string)$row['work_date']] = $row;
        $nowFig = $nd ? hr_bal_month_figures($nd, (string)$p['off_day'], date('Y-m'), hr_public_holidays(date('Y-m-01'), date('Y-m-t'))) : null;
    }
}
$balOn   = hr_balances_supported();
$bal     = $balOn ? (hr_bal_balances([$id])[$id] ?? []) : [];
$balMoves= $balOn ? hr_bal_moves($id) : [];
$canBal  = $balOn && (is_owner() || is_hr());
$leaveReqs = leave_requests_supported()
    ? db_query("SELECT * FROM leave_requests WHERE hr_staff_id = :s ORDER BY start_date DESC LIMIT 8", [':s' => $id])->fetchAll() : [];

// Message action target.
$dmUrl = '';
if ($acctId > 0 && internal_group_channels_supported()) $dmUrl = '/admin/internal-messages.php?dm=' . $acctId;
elseif (!empty($p['phone']) && ($wa = hr_whatsapp_link($p['phone'])) !== '') $dmUrl = $wa;

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

<nav class="tabs" aria-label="Employee sections">
  <button type="button" class="tab-btn is-active" data-tab="overview">Overview</button>
  <button type="button" class="tab-btn" data-tab="employment">Employment</button>
  <button type="button" class="tab-btn" data-tab="documents">Documents</button>
  <?php if (attendance_supported()): ?><button type="button" class="tab-btn" data-tab="attendance">Attendance</button><?php endif; ?>
  <?php if ($invOn): ?><button type="button" class="tab-btn" data-tab="assets">Assets<?= $assets['rows'] ? ' (' . count($assets['rows']) . ')' : '' ?></button><?php endif; ?>
</nav>

<div class="tab-panel is-active" id="tab-overview">
  <div class="card">
    <div class="card__head"><span class="card__title">Overview</span></div>
    <div class="card__body" style="padding:18px">
      <div class="detail-grid">
        <?php if (hr_staff_company_supported()): ?>
        <div><div class="detail-item__label">Company</div><div class="detail-item__value"><?= e(hr_company_names()[(int)($p['company_id'] ?? 0)] ?? '—') ?></div></div>
        <div><div class="detail-item__label">Payroll no.</div><div class="detail-item__value"><?= e(($p['payroll_no'] ?? '') !== '' ? $p['payroll_no'] : '—') ?></div></div>
        <?php endif; ?>
        <div><div class="detail-item__label">Position</div><div class="detail-item__value"><?= e($p['position'] ?: '—') ?></div></div>
        <div><div class="detail-item__label">Department</div><div class="detail-item__value"><?= e($dept ?: 'Other') ?></div></div>
        <div><div class="detail-item__label">Home property</div><div class="detail-item__value"><?= e($homeVenue) ?></div></div>
        <div><div class="detail-item__label">Also works at</div><div class="detail-item__value"><?php
          $ev = array_values(array_filter(array_map(fn($v) => $venueNames[$v] ?? '', $extraVids)));
          echo $ev ? e(implode(', ', $ev)) : '—'; ?></div></div>
        <?php if (staff_rooms_supported()): $__room = staff_room_of($id); ?>
        <div><div class="detail-item__label">Staff room</div><div class="detail-item__value"><?php if ($__room): ?><a href="/admin/staff-rooms.php"><?= e($__room['label']) ?></a><span class="text-muted" style="font-size:12px"> · <?= e($__room['venue_name'] ?? 'no property') ?><?= $__room['bed'] ? ' · bed ' . (int)$__room['bed'] : '' ?> · since <?= e(date('j M Y', strtotime((string)$__room['date_from']))) ?></span><?php else: ?>—<?php endif; ?></div></div>
        <?php endif; ?>
        <div><div class="detail-item__label">Weekly off</div><div class="detail-item__value"><?= e($p['off_day'] ?: 'Sun') ?></div></div>
        <div><div class="detail-item__label">Phone</div><div class="detail-item__value"><?= $p['phone'] ? e($p['phone']) : '—' ?></div></div>
        <div><div class="detail-item__label">Email</div><div class="detail-item__value"><?= $p['email'] ? e($p['email']) : '—' ?></div></div>
        <div><div class="detail-item__label">Login account</div><div class="detail-item__value"><?= $acctId ? 'Linked' : 'None' ?></div></div>
      </div>
      <?php if ($nowFig || $bal): ?>
      <div class="emp-now">
        <?php if ($nowFig): ?><span><strong><?= $nowFig['worked'] ?></strong> days worked in <?= e(date('F', strtotime('now'))) ?></span>
        <span><strong><?= ($nowFig['ot_hours'] > 0 ? '+' : '') . number_format($nowFig['ot_hours'], 1) ?> h</strong> overtime</span><?php endif; ?>
        <?php if (isset($bal['off_days'])): ?><span><strong><?= e(hr_bal_fmt($bal['off_days'])) ?></strong> off days owed</span><?php endif; ?>
        <?php if (isset($bal['annual_leave'])): ?><span><strong><?= e(hr_bal_fmt($bal['annual_leave'])) ?></strong> leave days</span><?php endif; ?>
        <a href="#attendance" data-open-tab="attendance">Attendance →</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="tab-panel" id="tab-employment">
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
          <?php if (hr_staff_company_supported() && hr_company_names()): ?>
          <div class="field"><label>Company</label>
            <select name="company_id" class="filter-select eselect--block">
              <option value="0">— not set —</option>
              <?php foreach (hr_company_names() as $cid => $cname): ?><option value="<?= (int)$cid ?>" <?= (int)($p['company_id'] ?? 0) === (int)$cid ? 'selected' : '' ?>><?= e($cname) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Payroll no.</label><input name="payroll_no" class="inp" value="<?= e($p['payroll_no'] ?? '') ?>" placeholder="e.g. MKH032" style="width:100%"></div>
          <?php else: ?>
          <div class="field"><label>Company</label><input name="company" class="inp" value="<?= e($p['company'] ?? '') ?>" placeholder="Employing company" style="width:100%"></div>
          <?php endif; ?>
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
</div>

<div class="tab-panel" id="tab-documents">
  <!-- Documents (contracts, ID copies, certificates) — private files -->
  <div class="card">
    <div class="card__head" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card__title">Documents</span>
      <?php if ($docs): ?><span class="text-muted" style="font-size:12px"><?= count($docs) ?> file<?= count($docs) === 1 ? '' : 's' ?></span><?php endif; ?>
    </div>
    <div class="card__body" style="padding:18px">
      <?php if (!$hasDocs): ?>
        <p class="text-muted" style="font-size:13px;margin:0">Run the <code>add_hr_staff_documents.sql</code> migration to upload contracts and other documents.</p>
      <?php else: ?>
        <?php if (!$docs): ?>
          <p class="text-muted" style="font-size:13px;margin:0 0 14px">No documents yet. Upload the contract, ID copy or certificates here — they stay private to the owner, managers and HR.</p>
        <?php else: ?>
        <ul class="emp-docs">
          <?php foreach ($docs as $d):
            $viewUrl = '/admin/employee-file.php?doc=' . (int)$d['id']; ?>
          <li class="emp-docs__row">
            <span class="emp-docs__ext"><?= e(strtoupper(pathinfo((string)$d['filename'], PATHINFO_EXTENSION))) ?></span>
            <span class="emp-docs__main">
              <a href="<?= e($viewUrl) ?>" target="_blank" rel="noopener" class="emp-docs__name"><?= e($d['label'] ?: $d['filename']) ?></a>
              <span class="text-muted emp-docs__meta"><?php
                $meta = [];
                if (!empty($d['label'])) $meta[] = $d['filename'];
                $meta[] = hr_doc_format_size((int)$d['size_bytes']);
                $meta[] = date('j M Y', strtotime((string)$d['uploaded_at']));
                if (!empty($d['uploader_name'])) $meta[] = 'by ' . $d['uploader_name'];
                echo e(implode(' · ', $meta)); ?></span>
            </span>
            <span class="emp-docs__act">
              <a href="<?= e($viewUrl) ?>&amp;download=1" class="btn-icon" data-tip="Download" aria-label="Download <?= e($d['filename']) ?>"><?= admin_icon('download', 15) ?></a>
              <form method="POST" action="/admin/employee.php?id=<?= $id ?>" style="margin:0" onsubmit="return confirm('Delete this document? This cannot be undone.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_doc">
                <input type="hidden" name="hr_id" value="<?= $id ?>">
                <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn-icon btn-icon--danger" data-tip="Delete" aria-label="Delete <?= e($d['filename']) ?>"><?= admin_icon('trash', 15) ?></button>
              </form>
            </span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <form method="POST" action="/admin/employee.php?id=<?= $id ?>" enctype="multipart/form-data" class="emp-docs__up">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_docs">
          <input type="hidden" name="hr_id" value="<?= $id ?>">
          <div class="field"><label>Label <span class="text-muted">(optional)</span></label>
            <input name="doc_label" class="inp" maxlength="200" placeholder="e.g. Employment contract 2026" style="width:100%">
          </div>
          <div class="emp-docs__uprow">
            <div class="filefield">
              <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('plus', 15) ?> Choose files<input type="file" name="docs[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,image/*" data-file-input required></label>
              <span class="filefield__name" data-file-name>No file chosen</span>
            </div>
            <button type="submit" class="btn-primary btn-sm">Upload</button>
          </div>
          <p class="text-muted" style="font-size:11.5px;margin:8px 0 0">PDF, Word, JPG, PNG or WEBP · up to 15 MB each · you can pick several at once.</p>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (attendance_supported()):
  $aStart = $aMonth . '-01'; $aDaysIn = (int)date('t', strtotime($aStart));
  $aLead  = (int)date('N', strtotime($aStart)) - 1;   // Monday-first grid
  $aPrev  = date('Y-m', strtotime($aStart . ' -1 month')); $aNext = date('Y-m', strtotime($aStart . ' +1 month')); ?>
<div class="tab-panel" id="tab-attendance">
  <div class="card">
    <div class="card__head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="card__title"><?= e(date('F Y', strtotime($aStart))) ?></span>
      <span style="display:flex;gap:6px;align-items:center">
        <a class="btn-icon btn-icon--outline" href="/admin/employee.php?id=<?= $id ?>&amp;amonth=<?= e($aPrev) ?>#attendance" aria-label="Previous month"><?= admin_icon('chevron-left') ?></a>
        <a class="btn-icon btn-icon--outline" href="/admin/employee.php?id=<?= $id ?>&amp;amonth=<?= e($aNext) ?>#attendance" aria-label="Next month"><?= admin_icon('chevron-right') ?></a>
        <a class="btn-outline btn-sm" href="/admin/attendance.php?view=person&amp;staff=<?= $id ?>&amp;month=<?= e($aMonth) ?>">Times &amp; print</a>
      </span>
    </div>
    <div class="card__body" style="padding:14px 18px">
      <div class="emp-cal" role="grid" aria-label="Attendance <?= e(date('F Y', strtotime($aStart))) ?>">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $wd): ?><div class="emp-cal__wd"><?= $wd ?></div><?php endforeach; ?>
        <?php for ($i = 0; $i < $aLead; $i++): ?><div class="emp-cal__pad"></div><?php endfor; ?>
        <?php for ($d = 1; $d <= $aDaysIn; $d++):
          $ymd = sprintf('%s-%02d', $aMonth, $d); $row = $aDays[$ymd] ?? null;
          $eff = $row ? attendance_effective_status($row) : ''; $hrs = $row ? attendance_hours($row) : 0;
          $isOff = attendance_is_off_day((string)$p['off_day'], $ymd); $ph = $aHol[$ymd] ?? null;
          $tip = $row ? trim(attendance_min_to_hhmm($row['in1'] !== null ? (int)$row['in1'] : null) . '–' . attendance_min_to_hhmm($row['out1'] !== null ? (int)$row['out1'] : null)
                 . ($row['in2'] !== null ? ', ' . attendance_min_to_hhmm((int)$row['in2']) . '–' . attendance_min_to_hhmm($row['out2'] !== null ? (int)$row['out2'] : null) : ''), '–, ')
                 . (!empty($row['note']) ? ' · ' . $row['note'] : '') . (!empty($row['source']) ? ' · ' . $row['source'] : '') : ''; ?>
        <div class="emp-cal__day<?= $isOff ? ' is-off' : '' ?><?= $ph ? ' is-ph' : '' ?><?= $ymd === date('Y-m-d') ? ' is-today' : '' ?>"<?= $tip !== '' || $ph ? ' data-tip="' . e(trim(($ph ? $ph . ' · ' : '') . $tip, ' ·')) . '"' : '' ?>>
          <span class="emp-cal__n"><?= $d ?></span>
          <?php if ($eff !== ''): ?><span class="badge <?= e(attendance_status_badge($eff)) ?>"><?= e($eff) ?></span><?php endif; ?>
          <?php if ($hrs > 0): ?><span class="emp-cal__h"><?= number_format($hrs, 1) ?>h<?= $row && attendance_is_night($row) ? ' <span class="emp-cal__moon" title="Night duty">' . admin_icon('moon', 11) . '</span>' : '' ?></span><?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
      <?php if ($aFig): $f = $aFig; ?>
      <div class="emp-figs">
        <div><span>Worked</span><strong><?= $f['worked'] ?></strong></div>
        <div><span>Off taken / entitled</span><strong><?= $f['off_taken'] ?> / <?= $f['entitled'] ?></strong></div>
        <div><span>Sick</span><strong><?= $f['sick'] ?></strong></div>
        <div><span>Leave</span><strong><?= $f['leave'] ?><?= $f['unpaid'] ? ' + ' . $f['unpaid'] . ' unpaid' : '' ?></strong></div>
        <div><span>Hours</span><strong><?= number_format($f['hours'], 1) ?></strong></div>
        <div><span>Overtime</span><strong><?= ($f['ot_hours'] > 0 ? '+' : '') . number_format($f['ot_hours'], 1) ?> h</strong></div>
        <div><span>Off days earned</span><strong><?= e(hr_bal_fmt($f['off_earned'])) ?></strong></div>
        <?php if ($f['nights']): ?><div><span>Night duties</span><strong><?= $f['nights'] ?></strong></div><?php endif; ?>
        <?php if ($f['not_recorded']): ?><div class="is-warn"><span>Not recorded</span><strong><?= $f['not_recorded'] ?> days</strong></div><?php endif; ?>
      </div>
      <?php else: ?>
      <p class="text-muted" style="margin:12px 0 0;font-size:13px">Nothing recorded for <?= e(date('F Y', strtotime($aStart))) ?>.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($balOn): ?>
  <div class="card" style="margin-top:16px">
    <div class="card__head"><span class="card__title">Balances</span></div>
    <div class="card__body" style="padding:14px 18px">
      <div class="emp-figs emp-figs--big">
        <div><span>Off days owed</span><strong><?= e(hr_bal_fmt($bal['off_days'] ?? 0)) ?></strong></div>
        <div><span>Annual leave</span><strong><?= e(hr_bal_fmt($bal['annual_leave'] ?? 0)) ?></strong></div>
      </div>
      <?php if ($balMoves): ?>
      <div class="table-wrap" style="margin-top:12px"><table class="data-table">
        <thead><tr><th>Date</th><th>What</th><th>Balance</th><th style="text-align:right">Days</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($balMoves as $m): $det = json_decode((string)$m['detail'], true) ?: []; ?>
          <tr><td style="white-space:nowrap"><?= e(date('j M Y', strtotime((string)$m['effective_on']))) ?></td>
            <td><?= e(hr_bal_reason_label((string)$m['reason'])) ?><?= $m['period'] ? ' · ' . e(date('M Y', strtotime($m['period'] . '-01'))) : '' ?></td>
            <td class="text-muted"><?= $m['kind'] === 'off_days' ? 'Off days' : 'Annual leave' ?></td>
            <td style="text-align:right"><strong><?= e(((float)$m['days'] > 0 ? '+' : '') . hr_bal_fmt((float)$m['days'])) ?></strong></td>
            <td class="text-muted" style="font-size:12.5px"><?php
              if ($m['reason'] === 'month' && $m['kind'] === 'off_days' && $det) echo e("entitled {$det['entitled']} − taken {$det['off_taken']} + overtime " . hr_bal_fmt((float)$det['ot_days']) . ' d');
              elseif ($m['reason'] === 'month' && $det) echo e('accrued ' . hr_bal_fmt((float)$det['leave_accrued']) . " − taken {$det['leave']}");
              else echo e((string)($m['note'] ?? '')); ?><?= $m['by_name'] ? ' · ' . e($m['by_name']) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><p class="text-muted" style="margin:10px 0 0;font-size:13px">No balance entries yet — the opening balance comes from HR's summary (Attendance → Import from Excel), then each closed month adds to it.</p><?php endif; ?>

      <?php if ($canBal): ?>
      <details class="emp-balform" style="margin-top:14px"><summary class="btn-outline btn-sm">Change a balance</summary>
        <form method="POST" action="/admin/employee.php?id=<?= $id ?>" class="emp-form" style="margin-top:12px">
          <?= csrf_field() ?><input type="hidden" name="action" value="bal_move"><input type="hidden" name="hr_id" value="<?= $id ?>"><input type="hidden" name="amonth" value="<?= e($aMonth) ?>">
          <div class="field"><label>Balance</label><select name="kind" class="filter-select eselect--block"><option value="off_days">Off days owed</option><option value="annual_leave">Annual leave</option></select></div>
          <div class="field"><label>What</label><select name="reason" class="filter-select eselect--block">
            <option value="adjustment">Correction (+ or −)</option><option value="paid_out">Days paid out</option><option value="opening">Opening balance (replaces)</option></select></div>
          <div class="field"><label>Days</label><input name="days" class="inp" inputmode="decimal" required placeholder="e.g. 2 or -1.5" style="width:100%"></div>
          <div class="field"><label>Date</label>
            <button type="button" class="dp-btn" data-dp-target="balOn" style="width:100%"><?= e(date('j M Y')) ?></button>
            <input type="hidden" id="balOn" name="on" value="<?= e(date('Y-m-d')) ?>"></div>
          <div class="field" style="grid-column:1/-1"><label>Note <span class="text-muted">(needed for a correction)</span></label><input name="note" class="inp" maxlength="300" style="width:100%"></div>
          <div><button class="btn-primary btn-sm">Save</button></div>
        </form>
      </details>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (leave_requests_supported()): ?>
  <div class="card" style="margin-top:16px">
    <div class="card__head" style="display:flex;justify-content:space-between;align-items:center"><span class="card__title">Requests</span><a class="btn-outline btn-sm" href="/admin/attendance.php?view=leave">All requests</a></div>
    <div class="card__body" style="padding:<?= $leaveReqs ? '0' : '14px 18px' ?>">
      <?php if (!$leaveReqs): ?><p class="text-muted" style="margin:0;font-size:13px">No leave requests.</p>
      <?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Dates</th><th>Type</th><th>Status</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($leaveReqs as $lr): ?><tr><td style="white-space:nowrap"><?= e(date('j M', strtotime((string)$lr['start_date']))) ?> – <?= e(date('j M Y', strtotime((string)$lr['end_date']))) ?></td>
          <?php [$__sl, $__sb] = function_exists('staff_request_status') ? staff_request_status((string)$lr['status']) : [ucfirst((string)$lr['status']), leave_status_badge((string)$lr['status'])]; ?>
          <td><?= e(function_exists('staff_request_type_label') ? staff_request_type_label((string)$lr['leave_type']) : ucfirst((string)$lr['leave_type'])) ?></td><td><span class="badge <?= e($__sb) ?>"><?= e($__sl) ?></span></td><td class="text-muted"><?= e((string)($lr['reason'] ?? '')) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($invOn): ?>
<div class="tab-panel" id="tab-assets">
  <div class="card">
    <div class="card__head" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card__title">Assigned assets</span>
      <?php if ($assetValue): ?><span class="text-muted" style="font-size:12px"><?php foreach ($assetValue as $c => $amt): ?><?= e(inv_money((float)$amt, (string)$c)) ?> <?php endforeach; ?></span><?php endif; ?>
    </div>
    <?php if ($assetsOwned && !$assets['rows']): ?>
      <div class="card__body" style="padding:18px"><p class="text-muted" style="margin:0;font-size:13px">Nothing assigned — phones, laptops, keys and tools handed to <?= e($p['full_name']) ?> show here.</p></div>
    <?php elseif ($assetsOwned): ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="inv-num">Qty</th><th>Last given</th><th class="inv-num">Value</th><?php if ($assetsOwned): ?><th>Return / report</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($assets['rows'] as $r): ?>
        <tr>
          <td><a href="/admin/inventory-item.php?id=<?= (int)$r['item_id'] ?>" class="inv-name"><?= inv_thumb_html($r, 32) ?><span><strong><?= e($r['name']) ?></strong>
            <?php if ($r['units']): ?><span class="inv-sub"><?= e(implode(', ', array_map(fn($u) => $u['serial'] ?: 'Unit #' . $u['id'], $r['units']))) ?></span><?php endif; ?></span></a></td>
          <td class="inv-num"><?= (int)$r['qty'] ?></td>
          <td class="text-muted"><?= $r['since'] ? e(date('j M Y', strtotime((string)$r['since']))) : '—' ?></td>
          <td class="inv-num"><?= e(inv_money($r['value'], (string)$r['currency'])) ?></td>
          <?php if ($assetsOwned): ?>
          <td>
            <form method="POST" action="/admin/employee.php?id=<?= $id ?>" class="emp-asset-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="asset_return"><input type="hidden" name="hr_id" value="<?= $id ?>"><input type="hidden" name="item_id" value="<?= (int)$r['item_id'] ?>">
              <?php if ($r['tracking'] === 'serial'): ?>
              <select name="asset_id" class="eselect" aria-label="Which unit"><?php foreach ($r['units'] as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['serial'] ?: 'Unit #' . $u['id']) ?></option><?php endforeach; ?></select>
              <?php else: ?>
              <input name="qty" type="number" class="inp inp--num no-spin" min="1" max="<?= (int)$r['qty'] ?>" step="1" value="<?= (int)$r['qty'] ?>" aria-label="How many" style="width:70px">
              <?php endif; ?>
              <select name="do" class="eselect" aria-label="What happens">
                <optgroup label="Back to"><?php foreach ($returnTo as $l): ?><option value="loc:<?= (int)$l['id'] ?>"><?= e(inv_location_label($l)) ?></option><?php endforeach; ?></optgroup>
                <optgroup label="Report"><?php foreach (['broken' => 'Broken', 'missing' => 'Missing', 'stolen' => 'Stolen'] as $k => $lbl): ?><option value="loss:<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?></optgroup>
              </select>
              <button type="submit" class="btn-outline btn-sm">Save</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>

    <?php if ($assetsOwned && $isActive && !$assetStock && !$assetUnits): ?>
    <div class="card__body" style="padding:12px 18px;border-top:1px solid var(--border)"><p class="text-muted" style="margin:0;font-size:12.5px">Nothing in stock to hand over.</p></div>
    <?php elseif ($assetsOwned && $isActive): ?>
    <div class="card__body" style="padding:16px 18px;border-top:1px solid var(--border)">
      <form method="POST" action="/admin/employee.php?id=<?= $id ?>" class="emp-asset-assign">
        <?= csrf_field() ?><input type="hidden" name="action" value="asset_assign"><input type="hidden" name="hr_id" value="<?= $id ?>">
        <div class="field"><label>Hand over</label>
          <select name="pick" class="eselect eselect--block" required>
            <?php if ($assetUnits): ?><optgroup label="By serial number"><?php foreach ($assetUnits as $u): ?><option value="unit:<?= (int)$u['id'] ?>"><?= e($u['item_name'] . ' · ' . ($u['serial'] ?: 'Unit #' . $u['id']) . ' — ' . $u['location_label']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            <?php if ($assetStock): ?><optgroup label="Counted items"><?php foreach ($assetStock as $s): ?><option value="qty:<?= (int)$s['item_id'] ?>:<?= (int)$s['location_id'] ?>"><?= e($s['item_name'] . ' — ' . $s['location_label'] . ' (' . (int)$s['qty'] . ')') ?></option><?php endforeach; ?></optgroup><?php endif; ?>
          </select></div>
        <div class="emp-asset-row">
          <div class="field"><label>How many <span class="text-muted">(counted items)</span></label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" value="1"></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500" placeholder="e.g. work phone"></div>
        </div>
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Assign</button>
      </form>
    </div>
    <?php elseif (!$assetsOwned): ?>
    <div class="card__body" style="padding:12px 18px"><p class="text-muted" style="margin:0;font-size:12.5px">Items are handed over and returned by the owner<?= $p['venue_id'] !== null ? ' or a manager of ' . e($homeVenue) : '' ?>.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="emp-stack">
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

  <!-- Activity log -->
  <div class="card">
    <div class="card__head"><span class="card__title">Activity log</span></div>
    <div class="card__body" style="padding:14px 20px"><?php person_activity_html($id, $acctId); ?></div>
  </div>
</div>

<style>
.emp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;align-items:start}
.emp-now{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;margin-top:16px;padding-top:14px;border-top:1px solid var(--border,#e7ded7);font-size:13px;color:var(--muted,#6b7280)}
.emp-now strong{color:var(--ink,#1f2937)}
.emp-now a{margin-left:auto;font-weight:600}
.emp-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
.emp-cal__wd{font-size:11px;font-weight:700;color:var(--muted,#6b7280);text-align:center;text-transform:uppercase;letter-spacing:.04em;padding:2px 0}
.emp-cal__day{min-height:58px;border:1px solid var(--border,#e7ded7);border-radius:8px;padding:4px 6px;display:flex;flex-direction:column;gap:3px;background:#fff}
.emp-cal__day.is-off{background:#f6f3ef}
.emp-cal__day.is-ph{background:#fdf0ec}
.emp-cal__day.is-today{outline:2px solid var(--teal,#1E5C6B);outline-offset:-1px}
.emp-cal__day .badge{align-self:flex-start;padding:1px 5px;font-size:10px}
.emp-cal__n{font-size:11px;color:var(--muted,#6b7280);font-weight:600}
.emp-cal__h{font-size:11.5px;font-weight:600;display:inline-flex;align-items:center;gap:3px}
.emp-cal__moon{display:inline-flex;color:#1E2A44}
.emp-figs{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-top:14px}
.emp-figs>div{border:1px solid var(--border,#e7ded7);border-radius:8px;padding:8px 10px;display:flex;flex-direction:column;gap:2px}
.emp-figs span{font-size:11px;color:var(--muted,#6b7280);text-transform:uppercase;letter-spacing:.03em}
.emp-figs strong{font-size:15px}
.emp-figs .is-warn{border-color:#f2c48f;background:#fff8ef}
.emp-figs--big strong{font-size:22px}
.emp-balform>summary{list-style:none;display:inline-flex;cursor:pointer}
.emp-balform>summary::-webkit-details-marker{display:none}
@media (max-width:560px){.emp-cal__day{min-height:46px;padding:3px}.emp-cal__h{display:none}}
.emp-stack{display:flex;flex-direction:column;gap:16px;margin-top:16px}
.emp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.emp-form .field label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.emp-tasks{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.emp-tasks li{display:flex;align-items:center;gap:8px;font-size:13px;flex-wrap:wrap}
.emp-tasks__t{font-weight:600}
.emp-docs{list-style:none;margin:0 0 16px;padding:0;display:flex;flex-direction:column;border:1px solid var(--border,#e7ded7);border-radius:10px;overflow:hidden}
.emp-docs__row{display:flex;align-items:center;gap:12px;padding:10px 12px;border-top:1px solid var(--border,#e7ded7)}
.emp-docs__row:first-child{border-top:0}
.emp-docs__ext{flex:0 0 auto;min-width:40px;text-align:center;font-size:10.5px;font-weight:800;letter-spacing:.04em;color:var(--teal,#1E5C6B);background:#eef5f7;border-radius:6px;padding:6px 4px}
.emp-docs__main{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2px}
.emp-docs__name{font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.emp-docs__meta{font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.emp-docs__act{flex:0 0 auto;display:flex;align-items:center;gap:2px}
.emp-docs__up .field label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.emp-docs__uprow{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:10px}
.emp-docs__uprow .filefield__name{max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.emp-asset-form{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.emp-asset-assign .eselect--block,.emp-asset-assign .inp{width:100%}
.emp-asset-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:0 12px}
@media (max-width:560px){.emp-asset-row{grid-template-columns:1fr}}
</style>

<script>
/* Tabs: Overview / Employment / Documents / Assets. If we landed on #documents or
   #assets (after an upload, delete or hand-over redirect), open that tab — otherwise
   it sits inside a hidden panel and the anchor does nothing. */
(function () {
  var btns = document.querySelectorAll('.tab-btn');
  if (!btns.length) return;
  function activate(tab) {
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === tab); });
    document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.toggle('is-active', p.id === 'tab-' + tab); });
  }
  btns.forEach(function (b) {
    b.addEventListener('click', function () { activate(b.dataset.tab); });
  });
  document.querySelectorAll('[data-open-tab]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); activate(a.getAttribute('data-open-tab')); });
  });
  var h = window.location.hash.slice(1);
  if ((h === 'documents' || h === 'assets' || h === 'employment' || h === 'attendance') && document.getElementById('tab-' + h)) activate(h);
})();

/* Styled file input (.filefield): show what was picked — one name, or "N files". */
(function(){
  document.querySelectorAll('.emp-docs__up [data-file-input]').forEach(function(fi){
    fi.addEventListener('change', function(){
      var out = fi.closest('.filefield').querySelector('[data-file-name]');
      var n = fi.files ? fi.files.length : 0;
      out.textContent = n === 0 ? 'No file chosen' : (n === 1 ? fi.files[0].name : n + ' files selected');
      out.title = n > 1 ? Array.prototype.map.call(fi.files, function(f){ return f.name; }).join('\n') : '';
    });
  });
})();
</script>

<?= inv_shared_css() ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
