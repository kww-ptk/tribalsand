<?php
/**
 * Admin: team account management (owner-only).
 *
 * Two kinds of non-owner account:
 *   • manager — email + password, per-property ops tier (assign work, tasks, gate).
 *   • staff   — access code, with an operational job type that drives their home
 *               (frontdesk → Front Desk, ops → My Work, security → Gate).
 *
 * Every mutating action guards to role IN ('manager','staff') so the owner
 * account can never be edited, deactivated or deleted from here.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_owner();

$pageTitle  = 'Staff';
$activeMenu = 'staff';

// Operational specialties a staff account can hold. Order = display order.
const STAFF_JOB_TYPES = [
    'frontdesk'    => 'Front desk',
    'housekeeping' => 'Housekeeping',
    'maintenance'  => 'Maintenance',
    'gardening'    => 'Gardening',
    'security'     => 'Gate security',
    'driver'       => 'Driver',
];

$venues    = db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll();
$venueIds  = array_map('intval', array_column($venues, 'id'));
$flash     = '';
$flashType = 'success';

/** Filter a posted venue_id[] down to valid venue ids. */
function staff_posted_venue_ids(array $validIds): array {
    $raw = $_POST['venue_id'] ?? [];
    if (!is_array($raw)) $raw = [];
    $out = [];
    foreach ($raw as $r) {
        $vid = (int)$r;
        if (in_array($vid, $validIds, true) && !in_array($vid, $out, true)) $out[] = $vid;
    }
    return $out;
}

/** Set a flash message + type for the next request, then redirect back. */
function staff_flash(string $msg, string $type = 'success'): void {
    $_SESSION['hold_flash']      = $msg;
    $_SESSION['hold_flash_type'] = $type;
    header('Location: /admin/staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $type = ($_POST['account_type'] ?? 'staff') === 'manager' ? 'manager' : 'staff';
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') staff_flash('Please enter a name.', 'error');

        if ($type === 'manager') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $pass  = (string)($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) staff_flash('Enter a valid email for the manager.', 'error');
            if (strlen($pass) < 10)                          staff_flash('Manager password must be at least 10 characters.', 'error');
            if (db_query('SELECT 1 FROM admin_users WHERE email = :e', [':e' => $email])->fetchColumn()) {
                staff_flash('An account with that email already exists.', 'error');
            }
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            db_query(
                "INSERT INTO admin_users (name, email, password_hash, role, is_active) VALUES (:n, :e, :h, 'manager', TRUE)",
                [':n' => $name, ':e' => $email, ':h' => $hash]
            );
            $newId = (int)db()->lastInsertId();
            foreach (staff_posted_venue_ids($venueIds) as $vid) {
                db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s, :v)', [':s' => $newId, ':v' => $vid]);
            }
            audit_log('staff_create', 'admin_user', $newId, "manager: {$name}");
            staff_flash('Manager account created.');
        } else {
            $job = $_POST['job_type'] ?? 'frontdesk';
            if (!array_key_exists($job, STAFF_JOB_TYPES)) $job = 'frontdesk';
            db_query(
                "INSERT INTO admin_users (name, role, job_type, access_code, is_active) VALUES (:n, 'staff', :j, :c, TRUE)",
                [':n' => $name, ':j' => $job, ':c' => gen_staff_code()]
            );
            $newId = (int)db()->lastInsertId();
            foreach (staff_posted_venue_ids($venueIds) as $vid) {
                db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s, :v)', [':s' => $newId, ':v' => $vid]);
            }
            audit_log('staff_create', 'admin_user', $newId, "staff/{$job}: {$name}");
            staff_flash('Staff member added.');
        }
    } elseif ($action === 'venues') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $ok  = db_query("SELECT 1 FROM admin_users WHERE id = :s AND role IN ('manager','staff')", [':s' => $sid])->fetchColumn();
        if ($ok) {
            db_query('DELETE FROM admin_user_venues WHERE admin_user_id = :s', [':s' => $sid]);
            foreach (staff_posted_venue_ids($venueIds) as $vid) {
                db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s, :v)', [':s' => $sid, ':v' => $vid]);
            }
            audit_log('staff_venues', 'admin_user', $sid, '');
            staff_flash('Properties updated.');
        }
        staff_flash('No change.', 'error');
    } elseif ($action === 'job') {
        // Change a staff member's operational specialty (staff only — managers aren't job-driven).
        $sid = (int)($_POST['staff_id'] ?? 0);
        $job = $_POST['job_type'] ?? '';
        if (array_key_exists($job, STAFF_JOB_TYPES)) {
            $n = db_query("UPDATE admin_users SET job_type = :j WHERE id = :s AND role = 'staff'", [':j' => $job, ':s' => $sid])->rowCount();
            if ($n) { audit_log('staff_job', 'admin_user', $sid, $job); staff_flash('Job updated.'); }
        }
        staff_flash('No change.', 'error');
    } elseif ($action === 'setpw') {
        // Reset a manager's password (managers only — staff use access codes).
        $sid  = (int)($_POST['staff_id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 10) staff_flash('Password must be at least 10 characters.', 'error');
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $n = db_query("UPDATE admin_users SET password_hash = :h WHERE id = :s AND role = 'manager'", [':h' => $hash, ':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_setpw', 'admin_user', $sid, ''); staff_flash('Password updated.'); }
        staff_flash('No change.', 'error');
    } elseif ($action === 'regen') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("UPDATE admin_users SET access_code = :c WHERE id = :s AND role = 'staff'", [':c' => gen_staff_code(), ':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_regen', 'admin_user', $sid, ''); staff_flash('New code generated.'); }
        staff_flash('No change.', 'error');
    } elseif ($action === 'toggle') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("UPDATE admin_users SET is_active = NOT is_active WHERE id = :s AND role IN ('manager','staff')", [':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_toggle', 'admin_user', $sid, ''); staff_flash('Account status updated.'); }
        staff_flash('No change.', 'error');
    } elseif ($action === 'delete') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("DELETE FROM admin_users WHERE id = :s AND role IN ('manager','staff')", [':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_delete', 'admin_user', $sid, ''); staff_flash('Account removed.'); }
        staff_flash('No change.', 'error');
    }
    staff_flash('Unknown action.', 'error');
}

if (!empty($_SESSION['hold_flash']) && is_string($_SESSION['hold_flash'])) {
    $flash     = $_SESSION['hold_flash'];
    $flashType = (($_SESSION['hold_flash_type'] ?? 'success') === 'error') ? 'error' : 'success';
    unset($_SESSION['hold_flash'], $_SESSION['hold_flash_type']);
}

// Search + pagination.
$pg = paginate_params(25);
$teamParams = [];
$teamWhere  = "WHERE role IN ('manager','staff')";
$sw = search_where(['name', "COALESCE(email,'')", "COALESCE(access_code,'')"], $pg['q'], $teamParams);
if ($sw !== '') $teamWhere .= " AND $sw";

$total = (int) db_query("SELECT COUNT(*) FROM admin_users $teamWhere", $teamParams)->fetchColumn();
$meta  = paginate_meta($total, $pg['page'], $pg['per']);

$team = db_query(
    "SELECT * FROM admin_users $teamWhere ORDER BY role ASC, name ASC LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $teamParams
)->fetchAll();

// Prefetch venue assignments: admin_user_id => [venue_id, ...]
$assignMap = [];
foreach (db_query('SELECT admin_user_id, venue_id FROM admin_user_venues')->fetchAll() as $row) {
    $assignMap[(int)$row['admin_user_id']][] = (int)$row['venue_id'];
}
$venueNames = [];
foreach ($venues as $v) { $venueNames[(int)$v['id']] = $v['name']; }

// ── Swappable body (team list + pager) — reused for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__head"><span class="card__title">Team accounts</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Role / job</th><th>Sign in</th><th>Properties</th><th>Status</th><th style="text-align:right">Manage</th></tr></thead>
      <tbody>
        <?php if (!$team): ?>
        <tr><td colspan="6" style="padding:0"><?php dt_empty($pg['q'] !== '' ? 'No accounts match your search.' : 'No accounts yet.'); ?></td></tr>
        <?php else: foreach ($team as $s):
          $sid       = (int)$s['id'];
          $isManager = ($s['role'] ?? '') === 'manager';
          $job       = $s['job_type'] ?? null;
          $jobEff    = $job ?: 'frontdesk';
          $jobLabel  = $isManager ? '—' : (array_key_exists($jobEff, STAFF_JOB_TYPES) ? STAFF_JOB_TYPES[$jobEff] : STAFF_JOB_TYPES['frontdesk']);
          $assigned  = $assignMap[$sid] ?? [];
          $names     = array_values(array_filter(array_map(fn($vid) => $venueNames[$vid] ?? null, $assigned)));
        ?>
        <tr>
          <td><strong><?= e($s['name'] ?? '') ?></strong></td>
          <td>
            <?php if ($isManager): ?>
              <span class="badge badge--green">Manager</span>
            <?php else: ?>
              <span class="badge badge--blue">Staff</span> <span class="text-muted"><?= e($jobLabel) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isManager): ?>
              <span class="text-muted"><?= e($s['email'] ?? '') ?></span>
            <?php else: ?>
              <code><?= e($s['access_code'] ?? '') ?></code>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($names): ?><?= e(implode(', ', $names)) ?>
            <?php else: ?><span class="text-muted">None assigned</span><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($s['is_active'])): ?>
              <span class="badge badge--green">Active</span>
            <?php else: ?>
              <span class="badge badge--grey">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (!$isManager): ?>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="regen"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-outline btn-sm">Regenerate code</button></form>
            <?php endif; ?>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-outline btn-sm"><?= !empty($s['is_active']) ? 'Deactivate' : 'Activate' ?></button></form>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-danger btn-sm" data-confirm="Remove this account?">Delete</button></form>
          </td>
        </tr>
        <tr>
          <td colspan="6" style="background:transparent">
            <form method="POST" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="venues">
              <input type="hidden" name="staff_id" value="<?= $sid ?>">
              <span class="text-muted">Properties:</span>
              <?php if (!$venues): ?>
                <span class="text-muted">No properties yet.</span>
              <?php else: foreach ($venues as $v): ?>
                <label style="display:inline-block">
                  <input type="checkbox" name="venue_id[]" value="<?= (int)$v['id'] ?>" <?= in_array((int)$v['id'], $assigned, true) ? 'checked' : '' ?>> <?= e($v['name']) ?>
                </label>
              <?php endforeach; ?>
                <button class="btn-outline btn-sm">Save properties</button>
              <?php endif; ?>
            </form>
            <?php if (!$isManager): ?>
            <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:8px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="job">
              <input type="hidden" name="staff_id" value="<?= $sid ?>">
              <span class="text-muted">Job:</span>
              <select name="job_type" style="padding:6px 8px;border:1px solid #d9d2c6;border-radius:6px">
                <?php foreach (STAFF_JOB_TYPES as $jk => $jl): ?>
                <option value="<?= e($jk) ?>" <?= ($job ?? 'frontdesk') === $jk ? 'selected' : '' ?>><?= e($jl) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn-outline btn-sm">Save job</button>
            </form>
            <?php else: ?>
            <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:8px" onsubmit="return this.password.value.length>=10 || (alert('Password must be at least 10 characters.'),false)">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="setpw">
              <input type="hidden" name="staff_id" value="<?= $sid ?>">
              <span class="text-muted">Set password:</span>
              <input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="min. 10 chars" style="padding:6px 8px;border:1px solid #d9d2c6;border-radius:6px">
              <button class="btn-outline btn-sm">Update</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

if ($pg['ajax']) { echo $dtBody; exit; }

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Staff &amp; managers</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flashType) ?>"><?= e($flash) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:24px">
  <div class="card__head"><span class="card__title">Add an account</span></div>
  <div class="card__body">
    <form method="POST" id="createForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div style="margin-bottom:14px">
        <span class="text-muted" style="display:block;margin-bottom:6px">Account type</span>
        <label style="margin-right:18px"><input type="radio" name="account_type" value="staff" checked> Staff (access code)</label>
        <label><input type="radio" name="account_type" value="manager"> Manager (email + password)</label>
      </div>

      <label style="display:block;margin-bottom:12px">Name
        <input type="text" name="name" required placeholder="Enter full name" style="display:block;width:100%;max-width:360px;margin-top:4px">
      </label>

      <div class="acct-staff" style="margin-bottom:12px">
        <label style="display:block">Job type
          <select name="job_type" style="display:block;margin-top:4px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px">
            <?php foreach (STAFF_JOB_TYPES as $jk => $jl): ?>
            <option value="<?= e($jk) ?>"><?= e($jl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <span class="text-muted" style="font-size:12px">Ops jobs land on “My work”; gate security lands on “Gate”; front desk lands on “Front desk”.</span>
      </div>

      <div class="acct-manager" style="margin-bottom:12px;display:none">
        <label style="display:block;margin-bottom:8px">Email
          <input type="email" name="email" placeholder="Enter email address" style="display:block;width:100%;max-width:360px;margin-top:4px">
        </label>
        <label style="display:block">Password <small class="text-muted">(min. 10 chars)</small>
          <input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="Enter a password (min. 10 characters)" style="display:block;width:100%;max-width:360px;margin-top:4px">
        </label>
        <span class="text-muted" style="font-size:12px">Managers assign work, create tasks and run the gate — for their properties only. They can’t touch pricing, settings or accounts.</span>
      </div>

      <div style="margin-bottom:16px">
        <span class="text-muted" style="display:block;margin-bottom:6px">Properties this account can manage</span>
        <?php if (!$venues): ?>
          <span class="text-muted">No properties yet.</span>
        <?php else: foreach ($venues as $v): ?>
          <label style="display:inline-block;margin-right:16px;margin-bottom:6px">
            <input type="checkbox" name="venue_id[]" value="<?= (int)$v['id'] ?>"> <?= e($v['name']) ?>
          </label>
        <?php endforeach; endif; ?>
      </div>

      <button type="submit" class="btn-primary">Create</button>
    </form>
  </div>
</div>

<div class="dt" data-dt>
  <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, email or access code…']); ?>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<script>
(function () {
  var form = document.getElementById('createForm');
  if (!form) return;
  function sync() {
    var isMgr = form.querySelector('input[name=account_type]:checked').value === 'manager';
    form.querySelectorAll('.acct-manager').forEach(function (el) { el.style.display = isMgr ? '' : 'none'; });
    form.querySelectorAll('.acct-staff').forEach(function (el) { el.style.display = isMgr ? 'none' : ''; });
    // Only require the fields that are visible, so the hidden set doesn't block submit.
    var email = form.querySelector('input[name=email]'), pw = form.querySelector('input[name=password]');
    if (email) email.required = isMgr;
    if (pw)    pw.required    = isMgr;
  }
  form.querySelectorAll('input[name=account_type]').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
