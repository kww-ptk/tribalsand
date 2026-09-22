<?php
/**
 * Admin: Team — internal directory + login accounts (owner-only).
 *
 * Two tabs:
 *   • Directory  — the whole workforce (hr_staff): who works where, position,
 *                  department, weekly off-day. A row MAY link to a login account
 *                  but usually does not. This is the "who works where" view and
 *                  the seam the future HR/attendance tool connects to.
 *   • Accounts   — login accounts (admin_users): manager / reception / staff.
 *                  Per-row management lives in a collapsible drawer so the table
 *                  reads cleanly even with many properties assigned.
 *
 * Three kinds of non-owner login account:
 *   • manager   — email + password, per-property ops tier.
 *   • reception — email + password, front of house, scoped to its properties.
 *   • staff     — access code, with an operational job type that drives its home.
 *
 * Every mutating account action guards to role IN ('manager','reception','staff')
 * so the owner account can never be edited, deactivated or deleted from here.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr.php';
require_once __DIR__ . '/../includes/internal-messages.php';  // DM a teammate (Item 6)
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_owner();

$pageTitle  = 'Team';
$activeMenu = 'staff';

// Operational specialties a staff account can hold. Order = display order.
const STAFF_JOB_TYPES = [
    'frontdesk'    => 'Front desk',
    'housekeeping' => 'Housekeeping',
    'laundry'      => 'Laundry',
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

/** Set a flash message + type for the next request, then redirect back (keeping the tab). */
function staff_flash(string $msg, string $type = 'success', string $tab = 'accounts'): void {
    $_SESSION['hold_flash']      = $msg;
    $_SESSION['hold_flash_type'] = $type;
    header('Location: /admin/staff.php?tab=' . urlencode($tab));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── Directory (hr_staff) actions ─────────────────────────────────────────
    if (str_starts_with($action, 'hr_')) {
        if (!hr_staff_supported()) staff_flash('Directory needs the add_hr_staff migration — run it first.', 'error', 'directory');

        if ($action === 'hr_save') {
            $sid   = (int)($_POST['hr_id'] ?? 0);
            $name  = trim((string)($_POST['full_name'] ?? ''));
            $posn  = trim((string)($_POST['position'] ?? ''));
            $dept  = trim((string)($_POST['department'] ?? '')) ?: hr_department($posn);
            if (!in_array($dept, hr_departments(), true)) $dept = hr_department($posn);
            $vid   = (int)($_POST['venue_id'] ?? 0);
            $vid   = in_array($vid, $venueIds, true) ? $vid : 0; // 0 → NULL (no property)
            $off   = strtoupper(trim((string)($_POST['off_day'] ?? '')));
            if (!in_array($off, hr_off_days(), true)) $off = '';
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $status= ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            // Optional link to a login account (validated against non-owner accounts).
            $auId  = (int)($_POST['admin_user_id'] ?? 0);
            $auOk  = $auId > 0 && db_query("SELECT 1 FROM admin_users WHERE id = :i", [':i' => $auId])->fetchColumn();

            if ($name === '') staff_flash('Please enter a name.', 'error', 'directory');
            $params = [
                ':n' => $name, ':p' => $posn, ':d' => $dept,
                ':v' => $vid ?: null, ':u' => $vid ? null : trim((string)($_POST['unit_label'] ?? '')),
                ':o' => $off, ':ph' => $phone, ':em' => $email, ':st' => $status,
                ':au' => $auOk ? $auId : null,
            ];
            // Additional properties this person can be managed from (Item 5). The
            // home venue ($vid) is the single "who works where" card; these only
            // widen visibility/scope. hr_set_staff_venues filters out the home and
            // any invalid id.
            $alsoVenues = is_array($_POST['also_venue_id'] ?? null) ? $_POST['also_venue_id'] : [];
            if ($sid > 0 && fetch_hr_staff_row($sid)) {
                db_query(
                    "UPDATE hr_staff SET full_name=:n, position=:p, department=:d, venue_id=:v,
                            unit_label=COALESCE(:u, unit_label), off_day=:o, phone=:ph, email=:em,
                            status=:st, admin_user_id=:au WHERE id=:id",
                    $params + [':id' => $sid]
                );
                hr_set_staff_venues($sid, $alsoVenues, $vid ?: null, $venueIds);
                audit_log('hr_staff_update', 'hr_staff', $sid, $name);
                staff_flash('Directory entry updated.', 'success', 'directory');
            } else {
                $order = (int) db_query('SELECT COALESCE(MAX(sort_order),0)+10 FROM hr_staff')->fetchColumn();
                db_query(
                    "INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, phone, email, status, admin_user_id, sort_order)
                     VALUES (:n,:p,:d,:v,:u,:o,:ph,:em,:st,:au,:so)",
                    $params + [':so' => $order]
                );
                $newId = (int)db()->lastInsertId();
                hr_set_staff_venues($newId, $alsoVenues, $vid ?: null, $venueIds);
                audit_log('hr_staff_create', 'hr_staff', $newId, $name);
                staff_flash('Team member added to the directory.', 'success', 'directory');
            }
        } elseif ($action === 'hr_toggle') {
            $sid = (int)($_POST['hr_id'] ?? 0);
            $n = db_query("UPDATE hr_staff SET status = CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id = :id", [':id' => $sid])->rowCount();
            if ($n) { audit_log('hr_staff_toggle', 'hr_staff', $sid, ''); staff_flash('Status updated.', 'success', 'directory'); }
            staff_flash('No change.', 'error', 'directory');
        } elseif ($action === 'hr_delete') {
            $sid = (int)($_POST['hr_id'] ?? 0);
            $n = db_query("DELETE FROM hr_staff WHERE id = :id", [':id' => $sid])->rowCount();
            if ($n) { audit_log('hr_staff_delete', 'hr_staff', $sid, ''); staff_flash('Directory entry removed.', 'success', 'directory'); }
            staff_flash('No change.', 'error', 'directory');
        }
        staff_flash('Unknown action.', 'error', 'directory');
    }

    // ── Login account (admin_users) actions ──────────────────────────────────
    if ($action === 'create') {
        $posted = $_POST['account_type'] ?? 'staff';
        // Reception needs the widened role CHECK; without the migration the
        // INSERT would be rejected, so fall back rather than 500.
        if ($posted === 'reception' && !reception_supported()) {
            staff_flash('Reception accounts need the add_reception_role migration — run it first.', 'error');
        }
        $type = in_array($posted, ['owner', 'manager', 'reception'], true) ? $posted : 'staff';
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') staff_flash('Please enter a name.', 'error');

        if ($type === 'owner' || $type === 'manager' || $type === 'reception') {
            // All three are email + password accounts. Owner sees everything (no
            // venue scoping); manager/reception are scoped by admin_user_venues.
            $label = $type === 'owner' ? 'Owner' : ($type === 'manager' ? 'Manager' : 'Reception');
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $pass  = (string)($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) staff_flash("Enter a valid email for the " . strtolower($label) . " account.", 'error');
            if (strlen($pass) < 10)                          staff_flash("{$label} password must be at least 10 characters.", 'error');
            if (db_query('SELECT 1 FROM admin_users WHERE email = :e', [':e' => $email])->fetchColumn()) {
                staff_flash('An account with that email already exists.', 'error');
            }
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            db_query(
                "INSERT INTO admin_users (name, email, password_hash, role, is_active) VALUES (:n, :e, :h, :r, TRUE)",
                [':n' => $name, ':e' => $email, ':h' => $hash, ':r' => $type]
            );
            $newId = (int)db()->lastInsertId();
            // Owners are unscoped (admin_venue_ids() returns null for them), so
            // property assignment only applies to manager/reception.
            if ($type !== 'owner') {
                foreach (staff_posted_venue_ids($venueIds) as $vid) {
                    db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s, :v)', [':s' => $newId, ':v' => $vid]);
                }
            }
            audit_log('staff_create', 'admin_user', $newId, "{$type}: {$name}");
            staff_flash("{$label} account created.");
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
        $ok  = db_query("SELECT 1 FROM admin_users WHERE id = :s AND role IN ('manager','reception','staff')", [':s' => $sid])->fetchColumn();
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
        // Reset a manager/reception password (staff use access codes instead).
        $sid  = (int)($_POST['staff_id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 10) staff_flash('Password must be at least 10 characters.', 'error');
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $n = db_query("UPDATE admin_users SET password_hash = :h WHERE id = :s AND role IN ('manager','reception')", [':h' => $hash, ':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_setpw', 'admin_user', $sid, ''); staff_flash('Password updated.'); }
        staff_flash('No change.', 'error');
    } elseif ($action === 'regen') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("UPDATE admin_users SET access_code = :c WHERE id = :s AND role = 'staff'", [':c' => gen_staff_code(), ':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_regen', 'admin_user', $sid, ''); staff_flash('New code generated.'); }
        staff_flash('No change.', 'error');
    } elseif ($action === 'toggle') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("UPDATE admin_users SET is_active = NOT is_active WHERE id = :s AND role IN ('manager','reception','staff')", [':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_toggle', 'admin_user', $sid, ''); staff_flash('Account status updated.'); }
        staff_flash('No change.', 'error');
    } elseif ($action === 'delete') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("DELETE FROM admin_users WHERE id = :s AND role IN ('manager','reception','staff')", [':s' => $sid])->rowCount();
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

// Which tab (default the Directory — the primary "who works where" view).
$tab = ($_GET['tab'] ?? 'directory') === 'accounts' ? 'accounts' : 'directory';

// ── Accounts: search + pagination (existing dt toolkit) ──────────────────────
$pg = paginate_params(25);
$teamParams = [];
$teamWhere  = "WHERE role IN ('manager','reception','staff')";
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

// Additional-venue assignments for directory rows: hr_staff_id => [venue_id, ...]
// (Item 5). Prefetched once to avoid an N+1 across the directory tables.
$hrExtraVenues = [];
if (hr_staff_supported() && hr_staff_venues_supported()) {
    foreach (db_query('SELECT hr_staff_id, venue_id FROM hr_staff_venues')->fetchAll() as $row) {
        $hrExtraVenues[(int)$row['hr_staff_id']][] = (int)$row['venue_id'];
    }
}

// Login accounts available to link from a directory entry.
$linkAccounts = db_query("SELECT id, name, email, role FROM admin_users WHERE role IN ('owner','manager','reception','staff') ORDER BY name ASC")->fetchAll();

// ── Directory filters + grouped data ─────────────────────────────────────────
$dirFilters = [
    'q'          => trim((string)($_GET['dq'] ?? '')),
    'department' => in_array(($_GET['dept'] ?? ''), hr_departments(), true) ? $_GET['dept'] : '',
    'venue_id'   => (int)($_GET['dvenue'] ?? 0),
    'status'     => in_array(($_GET['dstatus'] ?? ''), ['active', 'inactive'], true) ? $_GET['dstatus'] : '',
];
$dirGroups = hr_staff_supported() ? hr_staff_by_property(null, $dirFilters) : [];
$dirCount  = 0; foreach ($dirGroups as $g) { $dirCount += count($g['staff']); }

// ── Accounts swappable body (reused for AJAX + full page) ────────────────────
ob_start(); ?>
<div class="card">
  <div class="card__head"><span class="card__title">Login accounts</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Role / job</th><th>Sign in</th><th>Properties</th><th>Status</th><th style="text-align:right">Manage</th></tr></thead>
      <tbody>
        <?php if (!$team): ?>
        <tr><td colspan="6" style="padding:0"><?php dt_empty($pg['q'] !== '' ? 'No accounts match your search.' : 'No accounts yet.'); ?></td></tr>
        <?php else: foreach ($team as $s):
          $sid         = (int)$s['id'];
          $isManager   = ($s['role'] ?? '') === 'manager';
          $isReception = ($s['role'] ?? '') === 'reception';
          // Manager and reception are both email + password accounts: they share
          // the sign-in cell, the password form, and have no access code or job.
          $isPwAcct    = $isManager || $isReception;
          $job         = $s['job_type'] ?? null;
          $jobEff      = $job ?: 'frontdesk';
          $jobLabel    = $isPwAcct ? '—' : (array_key_exists($jobEff, STAFF_JOB_TYPES) ? STAFF_JOB_TYPES[$jobEff] : STAFF_JOB_TYPES['frontdesk']);
          $assigned  = $assignMap[$sid] ?? [];
          $names     = array_values(array_filter(array_map(fn($vid) => $venueNames[$vid] ?? null, $assigned)));
        ?>
        <tr>
          <td><strong><?= e($s['name'] ?? '') ?></strong></td>
          <td>
            <?php if ($isManager): ?>
              <span class="badge badge--green">Manager</span>
            <?php elseif ($isReception): ?>
              <span class="badge badge--orange">Reception</span>
            <?php else: ?>
              <span class="badge badge--blue">Staff</span> <span class="text-muted"><?= e($jobLabel) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isPwAcct): ?>
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
          <td>
            <div class="dt-actions">
              <button type="button" class="btn-icon btn-icon--outline" data-acct-toggle="<?= $sid ?>" data-tip="Manage account" aria-label="Manage account" aria-expanded="false"><?= admin_icon('settings') ?></button>
              <?php if (!$isPwAcct): ?>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="regen"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-icon btn-icon--outline" data-tip="Regenerate access code" aria-label="Regenerate access code"><?= admin_icon('rotate') ?></button></form>
              <?php endif; ?>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-icon btn-icon--outline" data-tip="<?= !empty($s['is_active']) ? 'Deactivate account' : 'Activate account' ?>" aria-label="<?= !empty($s['is_active']) ? 'Deactivate account' : 'Activate account' ?>"><?= admin_icon(!empty($s['is_active']) ? 'ban' : 'check') ?></button></form>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-icon btn-icon--danger" data-confirm="Remove this account?" data-tip="Delete account" aria-label="Delete account"><?= admin_icon('trash') ?></button></form>
            </div>
          </td>
        </tr>
        <tr class="acct-drawer" id="acct-drawer-<?= $sid ?>" hidden>
          <td colspan="6" style="background:var(--bg,#f9fafb)">
            <form method="POST" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="venues">
              <input type="hidden" name="staff_id" value="<?= $sid ?>">
              <span class="text-muted">Properties:</span>
              <?php if (!$venues): ?>
                <span class="text-muted">No properties yet.</span>
              <?php else: ?>
                <div class="optset">
                <?php foreach ($venues as $v): ?>
                  <label class="optchip"><input type="checkbox" name="venue_id[]" value="<?= (int)$v['id'] ?>" <?= in_array((int)$v['id'], $assigned, true) ? 'checked' : '' ?>><?= e($v['name']) ?></label>
                <?php endforeach; ?>
                </div>
                <button class="btn-outline btn-sm">Save properties</button>
              <?php endif; ?>
            </form>
            <?php if (!$isPwAcct): ?>
            <form method="POST" style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="job">
              <input type="hidden" name="staff_id" value="<?= $sid ?>">
              <span class="text-muted">Job:</span>
              <select name="job_type" class="filter-select" aria-label="Job type">
                <?php foreach (STAFF_JOB_TYPES as $jk => $jl): ?>
                <option value="<?= e($jk) ?>" <?= ($job ?? 'frontdesk') === $jk ? 'selected' : '' ?>><?= e($jl) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn-outline btn-sm">Save job</button>
            </form>
            <?php else: ?>
            <form method="POST" style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="setpw">
              <input type="hidden" name="staff_id" value="<?= $sid ?>">
              <span class="text-muted">Set password:</span>
              <input type="password" name="password" minlength="10" required autocomplete="new-password" placeholder="min. 10 chars" class="inp inp--sm">
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

<?php
$__openCreate = ($flash !== '' && $flashType === 'error' && $tab === 'accounts');
$__accountsUrl = '/admin/staff.php?tab=accounts';
$__directoryUrl = '/admin/staff.php?tab=directory';
?>
<div class="page-header">
  <h1>Team</h1>
  <div class="actions">
    <?php if ($tab === 'accounts'): ?>
    <button type="button" class="btn-primary btn-sm" id="addAccountBtn" aria-controls="createCard" aria-expanded="<?= $__openCreate ? 'true' : 'false' ?>"><?= admin_icon('plus', 15) ?> Add account</button>
    <?php else: ?>
    <button type="button" class="btn-primary btn-sm" id="addPersonBtn" aria-controls="personCard" aria-expanded="false"><?= admin_icon('plus', 15) ?> Add team member</button>
    <?php endif; ?>
    <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
  </div>
</div>

<!-- Tabs -->
<div class="ts-tabs" role="tablist" style="display:flex;gap:6px;border-bottom:1px solid var(--border,#e5e7eb);margin-bottom:20px">
  <a href="<?= $__directoryUrl ?>" data-shell-link role="tab" class="ts-tab<?= $tab === 'directory' ? ' is-active' : '' ?>"
     style="padding:9px 16px;font-weight:600;font-size:14px;text-decoration:none;border-bottom:2px solid <?= $tab === 'directory' ? 'var(--teal,#1E5C6B)' : 'transparent' ?>;color:<?= $tab === 'directory' ? 'var(--teal,#1E5C6B)' : 'var(--muted,#6b7280)' ?>">
    Directory<?= hr_staff_supported() ? ' <span class="text-muted">(' . $dirCount . ')</span>' : '' ?>
  </a>
  <a href="<?= $__accountsUrl ?>" data-shell-link role="tab" class="ts-tab<?= $tab === 'accounts' ? ' is-active' : '' ?>"
     style="padding:9px 16px;font-weight:600;font-size:14px;text-decoration:none;border-bottom:2px solid <?= $tab === 'accounts' ? 'var(--teal,#1E5C6B)' : 'transparent' ?>;color:<?= $tab === 'accounts' ? 'var(--teal,#1E5C6B)' : 'var(--muted,#6b7280)' ?>">
    Login accounts <span class="text-muted">(<?= $total ?>)</span>
  </a>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flashType) ?> is-flash"><?= e($flash) ?></div><?php endif; ?>

<?php if ($tab === 'directory'): ?>
<!-- ═══════════ DIRECTORY TAB ═══════════ -->
<?php if (!hr_staff_supported()): ?>
  <div class="card"><div class="card__body card__body--pad">
    <p class="text-muted" style="margin:0">The team directory is unavailable. Run the <code>add_hr_staff.sql</code> migration (and <code>db/seeds/seed_hr_staff.php</code> to import the roster) to enable it.</p>
  </div></div>
<?php else: ?>

<!-- Add / edit person form (hidden until "Add team member" or an Edit click) -->
<div class="card" style="margin-bottom:24px" id="personCard" hidden>
  <div class="card__head"><span class="card__title" id="personCardTitle">Add a team member</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" id="personForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="hr_save">
      <input type="hidden" name="hr_id" id="hrId" value="">
      <div class="form-row" style="display:grid;grid-template-columns:repeat(2,minmax(200px,1fr));gap:16px">
        <div class="field"><label for="hrName">Full name</label><input id="hrName" name="full_name" class="inp" required placeholder="Full name" style="width:100%"></div>
        <div class="field"><label for="hrPos">Position</label><input id="hrPos" name="position" class="inp" placeholder="e.g. House Keeper" style="width:100%"></div>
        <div class="field"><label for="hrDept">Department</label>
          <select id="hrDept" name="department" class="filter-select eselect--block">
            <option value="">Auto from position</option>
            <?php foreach (hr_departments() as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="hrVenue">Property</label>
          <select id="hrVenue" name="venue_id" class="filter-select eselect--block">
            <option value="0">— none / other —</option>
            <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="hrOff">Weekly off-day</label>
          <select id="hrOff" name="off_day" class="filter-select eselect--block">
            <option value="">Sunday (default)</option>
            <?php foreach (hr_off_days() as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="hrStatus">Status</label>
          <select id="hrStatus" name="status" class="filter-select eselect--block">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="field"><label for="hrPhone">Phone</label><input id="hrPhone" name="phone" class="inp" placeholder="Optional" style="width:100%"></div>
        <div class="field"><label for="hrEmail">Email</label><input id="hrEmail" name="email" type="email" class="inp" placeholder="Optional" style="width:100%"></div>
        <div class="field"><label for="hrLink">Login account <small class="text-muted">(optional)</small></label>
          <select id="hrLink" name="admin_user_id" class="filter-select eselect--block">
            <option value="0">— not linked —</option>
            <?php foreach ($linkAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name'] ?: $a['email']) ?> (<?= e($a['role']) ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <?php if (hr_staff_venues_supported()): ?>
        <div class="field" style="grid-column:1/-1">
          <label>Also works at <small class="text-muted">(extra properties a manager can find them under — the home property above stays their main card)</small></label>
          <div class="optset" id="hrAlsoVenues">
            <?php foreach ($venues as $v): ?>
            <label class="optchip"><input type="checkbox" name="also_venue_id[]" value="<?= (int)$v['id'] ?>"> <?= e($v['name']) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div style="margin-top:16px">
        <button type="submit" class="btn-primary">Save</button>
        <button type="button" class="btn-outline btn-sm" data-close-person style="margin-left:8px">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Directory filters -->
<form method="GET" action="/admin/staff.php" class="filters" style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
  <input type="hidden" name="tab" value="directory">
  <div class="filter-field"><span>Search</span><input type="text" name="dq" value="<?= e($dirFilters['q']) ?>" class="inp inp--sm" placeholder="Name or position…" style="min-width:180px"></div>
  <div class="filter-field"><span>Department</span>
    <select name="dept" class="filter-select" onchange="this.form.submit()">
      <option value="">All departments</option>
      <?php foreach (hr_departments() as $d): ?><option value="<?= e($d) ?>" <?= $dirFilters['department'] === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field"><span>Property</span>
    <select name="dvenue" class="filter-select" onchange="this.form.submit()">
      <option value="0">All properties</option>
      <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $dirFilters['venue_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field"><span>Status</span>
    <select name="dstatus" class="filter-select" onchange="this.form.submit()">
      <option value="">All</option>
      <option value="active"   <?= $dirFilters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $dirFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <button class="btn-outline btn-sm">Filter</button>
  <?php if ($dirFilters['q'] !== '' || $dirFilters['department'] || $dirFilters['venue_id'] || $dirFilters['status']): ?>
  <a href="<?= $__directoryUrl ?>" class="btn-outline btn-sm">Clear</a>
  <?php endif; ?>
</form>

<?php if (!$dirGroups): ?>
  <div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0"><?= $dirCount === 0 && ($dirFilters['q'] || $dirFilters['department'] || $dirFilters['venue_id'] || $dirFilters['status']) ? 'No team members match your filters.' : 'No team members yet. Add one, or run the roster seed.' ?></p></div></div>
<?php else: foreach ($dirGroups as $g): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card__head">
    <span class="card__title"><?= e($g['label']) ?></span>
    <span class="text-muted" style="font-size:12px"><?= count($g['staff']) ?> <?= count($g['staff']) === 1 ? 'person' : 'people' ?></span>
  </div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Position</th><th>Department</th><th>Off day</th><th>Status</th><th style="text-align:right">Manage</th></tr></thead>
        <tbody>
          <?php foreach ($g['staff'] as $p):
            $pid = (int)$p['id'];
            $isActive = ($p['status'] ?? 'active') === 'active';
            $dept = (string)($p['department'] ?? '');
            $extraVids = $hrExtraVenues[$pid] ?? [];
          ?>
          <tr>
            <td><strong><?= e($p['full_name']) ?></strong><?php if (!empty($p['admin_user_id'])): ?> <span class="badge badge--grey" data-tip="Has a login account">login</span><?php endif; ?>
              <?php foreach ($extraVids as $evid): if (!isset($venueNames[$evid])) continue; ?>
              <span class="badge badge--teal" data-tip="Also works here" style="font-weight:500">+ <?= e($venueNames[$evid]) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="text-muted"><?= e($p['position'] ?: '—') ?></td>
            <td><span class="badge <?= e(hr_department_badge($dept)) ?>"><?= e($dept ?: 'Other') ?></span></td>
            <td class="text-muted"><?= e($p['off_day'] ?: 'Sun') ?></td>
            <td><?php if ($isActive): ?><span class="badge badge--green">Active</span><?php else: ?><span class="badge badge--grey">Inactive</span><?php endif; ?></td>
            <td>
              <div class="dt-actions">
                <?php if (!empty($p['admin_user_id']) && internal_group_channels_supported()): ?>
                <a href="/admin/internal-messages.php?dm=<?= (int)$p['admin_user_id'] ?>" class="btn-icon btn-icon--outline" data-tip="Message in team chat" aria-label="Message"><?= admin_icon('message') ?></a>
                <?php elseif (!empty($p['phone']) && ($__wa = hr_whatsapp_link($p['phone'])) !== ''): ?>
                <a href="<?= e($__wa) ?>" target="_blank" rel="noopener" class="btn-icon btn-icon--outline" data-tip="Message on WhatsApp" aria-label="WhatsApp"><?= admin_icon('phone') ?></a>
                <?php elseif (!empty($p['email'])): ?>
                <a href="mailto:<?= e($p['email']) ?>" class="btn-icon btn-icon--outline" data-tip="Email" aria-label="Email"><?= admin_icon('message') ?></a>
                <?php endif; ?>
                <button type="button" class="btn-icon btn-icon--outline hr-edit"
                        data-tip="Edit" aria-label="Edit"
                        data-id="<?= $pid ?>"
                        data-name="<?= e($p['full_name']) ?>"
                        data-position="<?= e($p['position'] ?? '') ?>"
                        data-department="<?= e($dept) ?>"
                        data-venue="<?= (int)($p['venue_id'] ?? 0) ?>"
                        data-off="<?= e($p['off_day'] ?? '') ?>"
                        data-status="<?= e($p['status'] ?? 'active') ?>"
                        data-phone="<?= e($p['phone'] ?? '') ?>"
                        data-email="<?= e($p['email'] ?? '') ?>"
                        data-link="<?= (int)($p['admin_user_id'] ?? 0) ?>"
                        data-venues="<?= e(implode(',', $extraVids)) ?>"><?= admin_icon('edit') ?></button>
                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="hr_toggle"><input type="hidden" name="hr_id" value="<?= $pid ?>"><button class="btn-icon btn-icon--outline" data-tip="<?= $isActive ? 'Set inactive' : 'Set active' ?>" aria-label="<?= $isActive ? 'Set inactive' : 'Set active' ?>"><?= admin_icon($isActive ? 'ban' : 'check') ?></button></form>
                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="hr_delete"><input type="hidden" name="hr_id" value="<?= $pid ?>"><button class="btn-icon btn-icon--danger" data-confirm="Remove this person from the directory?" data-tip="Delete" aria-label="Delete"><?= admin_icon('trash') ?></button></form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<script>
(function () {
  var card = document.getElementById('personCard');
  var form = document.getElementById('personForm');
  var title = document.getElementById('personCardTitle');
  var addBtn = document.getElementById('addPersonBtn');
  if (!card || !form) return;
  function open() { card.removeAttribute('hidden'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  function close() { card.setAttribute('hidden', ''); }
  function setVal(name, val) { var el = form.querySelector('[name="' + name + '"]'); if (el) el.value = val == null ? '' : val; }
  // "Also works at" checkboxes (Item 5).
  var alsoBoxes = form.querySelectorAll('input[name="also_venue_id[]"]');
  var homeSel = form.querySelector('[name="venue_id"]');
  function setAlso(csv) {
    var ids = String(csv || '').split(',').filter(Boolean);
    alsoBoxes.forEach(function (b) { b.checked = ids.indexOf(b.value) !== -1; });
  }
  // The home property is never also an extra — grey out & clear its box.
  function syncHome() {
    var home = homeSel ? homeSel.value : '0';
    alsoBoxes.forEach(function (b) {
      var isHome = b.value === home && home !== '0';
      b.disabled = isHome;
      if (isHome) b.checked = false;
      b.closest('.optchip').style.opacity = isHome ? '0.45' : '';
    });
  }
  if (homeSel) homeSel.addEventListener('change', syncHome);
  if (addBtn) addBtn.addEventListener('click', function () {
    title.textContent = 'Add a team member';
    form.reset(); setVal('hr_id', ''); setAlso(''); syncHome();
    open(); var f = form.querySelector('#hrName'); if (f) f.focus();
  });
  card.querySelectorAll('[data-close-person]').forEach(function (c) { c.addEventListener('click', close); });
  document.querySelectorAll('.hr-edit').forEach(function (b) {
    b.addEventListener('click', function () {
      title.textContent = 'Edit ' + (b.dataset.name || 'team member');
      setVal('hr_id', b.dataset.id);
      setVal('full_name', b.dataset.name);
      setVal('position', b.dataset.position);
      setVal('department', b.dataset.department);
      setVal('venue_id', b.dataset.venue);
      setVal('off_day', b.dataset.off);
      setVal('status', b.dataset.status);
      setVal('phone', b.dataset.phone);
      setVal('email', b.dataset.email);
      setVal('admin_user_id', b.dataset.link);
      setAlso(b.dataset.venues); syncHome();
      open();
    });
  });
})();
</script>

<?php endif; /* hr_staff_supported */ ?>

<?php else: ?>
<!-- ═══════════ ACCOUNTS TAB ═══════════ -->
<div class="card" style="margin-bottom:24px" id="createCard" <?= $__openCreate ? '' : 'hidden' ?>>
  <div class="card__head"><span class="card__title">Add an account</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" id="createForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="field">
        <span>Account type</span>
        <div class="optset">
          <label class="optchip"><input type="radio" name="account_type" value="staff" checked> Staff (access code)</label>
          <label class="optchip"><input type="radio" name="account_type" value="manager"> Manager (email + password)</label>
          <?php if (reception_supported()): ?>
          <label class="optchip"><input type="radio" name="account_type" value="reception"> Reception (email + password)</label>
          <?php endif; ?>
          <label class="optchip"><input type="radio" name="account_type" value="owner"> Owner (full access)</label>
        </div>
        <span class="field-hint acct-owner-note" style="display:none;color:#8a1c13">Owner accounts have <strong>full, unrestricted access</strong> — pricing, settings, staff and every property. Only create one for someone you fully trust.</span>
        <?php if (!reception_supported()): ?>
        <span class="field-hint">Reception accounts appear here once the <code>add_reception_role</code> migration has run.</span>
        <?php endif; ?>
      </div>

      <div class="field" style="max-width:360px">
        <label for="stName">Name</label>
        <input id="stName" type="text" name="name" class="inp" required placeholder="Enter full name" style="width:100%">
      </div>

      <div class="acct-staff">
        <div class="field" style="max-width:360px">
          <label>Job type</label>
          <select name="job_type" class="filter-select eselect--block" aria-label="Job type">
            <?php foreach (STAFF_JOB_TYPES as $jk => $jl): ?>
            <option value="<?= e($jk) ?>"><?= e($jl) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint">Ops jobs land on “My work”; gate security lands on “Gate”; front desk lands on “Front desk”.</span>
        </div>
      </div>

      <div class="acct-manager" style="display:none">
        <div class="field" style="max-width:360px">
          <label for="stEmail">Email</label>
          <input id="stEmail" type="email" name="email" class="inp" placeholder="Enter email address" style="width:100%">
        </div>
        <div class="field" style="max-width:360px">
          <label for="stPass">Password <small class="text-muted">(min. 10 chars)</small></label>
          <input id="stPass" type="password" name="password" class="inp" minlength="10" autocomplete="new-password" placeholder="Enter a password (min. 10 characters)" style="width:100%">
          <span class="field-hint">Managers assign work, create tasks and run the gate — for their properties only. Reception additionally gets holds, the calendar, submissions and conflicts. Neither can touch pricing, site content, settings or accounts.</span>
        </div>
      </div>

      <div class="field acct-scoped">
        <span>Properties this account can manage</span>
        <?php if (!$venues): ?>
          <span class="text-muted">No properties yet.</span>
        <?php else: ?>
          <div class="optset">
          <?php foreach ($venues as $v): ?>
            <label class="optchip"><input type="checkbox" name="venue_id[]" value="<?= (int)$v['id'] ?>"> <?= e($v['name']) ?></label>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn-primary">Create</button>
      <button type="button" class="btn-outline btn-sm" data-close-create style="margin-left:8px">Cancel</button>
    </form>
  </div>
</div>

<div class="dt" data-dt>
  <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, email or access code…']); ?>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<script>
(function () {
  // Per-row account management drawer.
  document.querySelectorAll('[data-acct-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.getElementById('acct-drawer-' + btn.dataset.acctToggle);
      if (!row) return;
      var open = row.hasAttribute('hidden');
      if (open) row.removeAttribute('hidden'); else row.setAttribute('hidden', '');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // Toggle the inline "Add an account" form from the header button.
  var btn = document.getElementById('addAccountBtn'), card = document.getElementById('createCard');
  if (btn && card) {
    function setOpen(open) {
      if (open) { card.removeAttribute('hidden'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); var f = card.querySelector('input,select,textarea'); if (f) f.focus(); }
      else card.setAttribute('hidden', '');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function () { setOpen(card.hasAttribute('hidden')); });
    card.querySelectorAll('[data-close-create]').forEach(function (c) { c.addEventListener('click', function () { setOpen(false); }); });
  }

  var form = document.getElementById('createForm');
  if (!form) return;
  function sync() {
    // Owner, manager and reception are all email + password accounts; only staff
    // get a job type. Owners are unscoped, so they skip the property picker.
    var val = form.querySelector('input[name=account_type]:checked').value;
    var isPw = val !== 'staff';
    var isOwner = val === 'owner';
    form.querySelectorAll('.acct-manager').forEach(function (el) { el.style.display = isPw ? '' : 'none'; });
    form.querySelectorAll('.acct-staff').forEach(function (el) { el.style.display = isPw ? 'none' : ''; });
    form.querySelectorAll('.acct-scoped').forEach(function (el) { el.style.display = isOwner ? 'none' : ''; });
    form.querySelectorAll('.acct-owner-note').forEach(function (el) { el.style.display = isOwner ? '' : 'none'; });
    // Only require the fields that are visible, so the hidden set doesn't block submit.
    var email = form.querySelector('input[name=email]'), pw = form.querySelector('input[name=password]');
    if (email) email.required = isPw;
    if (pw)    pw.required    = isPw;
  }
  form.querySelectorAll('input[name=account_type]').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>

<?php endif; /* tab */ ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
