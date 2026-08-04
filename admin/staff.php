<?php
/**
 * Admin: staff account management (owner-only).
 * Create staff members, assign them to venues, regenerate access codes,
 * activate/deactivate, and delete. Every action guards to role='staff'
 * rows so owner accounts can never be touched here.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_owner();

$pageTitle  = 'Staff';
$activeMenu = 'staff';

$venues    = db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll();
$venueIds  = array_map('intval', array_column($venues, 'id'));
$flash     = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '') {
            db_query(
                "INSERT INTO admin_users (name, role, access_code, is_active) VALUES (:n, 'staff', :c, TRUE)",
                [':n' => $name, ':c' => gen_staff_code()]
            );
            $newId = (int)db()->lastInsertId();
            foreach (staff_posted_venue_ids($venueIds) as $vid) {
                db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s, :v)', [':s' => $newId, ':v' => $vid]);
            }
            audit_log('staff_create', 'admin_user', $newId, $name);
            $_SESSION['hold_flash'] = 'Staff member added.';
        }
        header('Location: /admin/staff.php');
        exit;
    } elseif ($action === 'venues') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $ok  = db_query("SELECT 1 FROM admin_users WHERE id = :s AND role='staff'", [':s' => $sid])->fetchColumn();
        if ($ok) {
            db_query('DELETE FROM admin_user_venues WHERE admin_user_id = :s', [':s' => $sid]);
            foreach (staff_posted_venue_ids($venueIds) as $vid) {
                db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s, :v)', [':s' => $sid, ':v' => $vid]);
            }
            audit_log('staff_venues', 'admin_user', $sid, '');
            $_SESSION['hold_flash'] = 'Properties updated.';
        }
        header('Location: /admin/staff.php');
        exit;
    } elseif ($action === 'regen') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("UPDATE admin_users SET access_code = :c WHERE id = :s AND role='staff'", [':c' => gen_staff_code(), ':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_regen', 'admin_user', $sid, ''); $_SESSION['hold_flash'] = 'New code generated.'; }
        header('Location: /admin/staff.php');
        exit;
    } elseif ($action === 'toggle') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("UPDATE admin_users SET is_active = NOT is_active WHERE id = :s AND role='staff'", [':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_toggle', 'admin_user', $sid, ''); $_SESSION['hold_flash'] = 'Staff status updated.'; }
        header('Location: /admin/staff.php');
        exit;
    } elseif ($action === 'delete') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        $n = db_query("DELETE FROM admin_users WHERE id = :s AND role='staff'", [':s' => $sid])->rowCount();
        if ($n) { audit_log('staff_delete', 'admin_user', $sid, ''); $_SESSION['hold_flash'] = 'Staff member removed.'; }
        header('Location: /admin/staff.php');
        exit;
    }
}

if (!empty($_SESSION['hold_flash']) && is_string($_SESSION['hold_flash'])) {
    $flash = $_SESSION['hold_flash'];
    unset($_SESSION['hold_flash']);
}

$staff = db_query("SELECT * FROM admin_users WHERE role='staff' ORDER BY name")->fetchAll();

// Prefetch venue assignments: admin_user_id => [venue_id, ...]
$assignMap = [];
foreach (db_query('SELECT admin_user_id, venue_id FROM admin_user_venues')->fetchAll() as $row) {
    $assignMap[(int)$row['admin_user_id']][] = (int)$row['venue_id'];
}
$venueNames = [];
foreach ($venues as $v) { $venueNames[(int)$v['id']] = $v['name']; }

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Staff</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<?php if ($flash): ?><div class="alert alert--success"><?= e($flash) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:24px">
  <div class="card__head"><span class="card__title">Add staff member</span></div>
  <div class="card__body">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <label style="display:block;margin-bottom:12px">Name
        <input type="text" name="name" required style="display:block;width:100%;max-width:360px;margin-top:4px">
      </label>

      <div style="margin-bottom:16px">
        <span class="text-muted" style="display:block;margin-bottom:6px">Properties this staff member can manage</span>
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

<div class="card">
  <div class="card__head"><span class="card__title">Staff accounts</span></div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Access code</th><th>Properties</th><th>Status</th><th style="text-align:right">Manage</th></tr></thead>
      <tbody>
        <?php if (!$staff): ?>
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">No staff members yet.</td></tr>
        <?php else: foreach ($staff as $s):
          $sid      = (int)$s['id'];
          $assigned = $assignMap[$sid] ?? [];
          $names    = array_values(array_filter(array_map(fn($vid) => $venueNames[$vid] ?? null, $assigned)));
        ?>
        <tr>
          <td><strong><?= e($s['name'] ?? '') ?></strong></td>
          <td><code><?= e($s['access_code'] ?? '') ?></code></td>
          <td>
            <?php if ($names): ?>
              <?= e(implode(', ', $names)) ?>
            <?php else: ?>
              <span class="text-muted">None assigned</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($s['is_active'])): ?>
              <span class="badge badge--green">Active</span>
            <?php else: ?>
              <span class="badge badge--grey">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="regen"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-outline btn-sm">Regenerate code</button></form>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-outline btn-sm"><?= !empty($s['is_active']) ? 'Deactivate' : 'Activate' ?></button></form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove this staff member?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="staff_id" value="<?= $sid ?>"><button class="btn-danger btn-sm">Delete</button></form>
          </td>
        </tr>
        <tr>
          <td colspan="5" style="background:transparent">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="venues">
              <input type="hidden" name="staff_id" value="<?= $sid ?>">
              <span class="text-muted" style="margin-right:8px">Properties:</span>
              <?php if (!$venues): ?>
                <span class="text-muted">No properties yet.</span>
              <?php else: foreach ($venues as $v): ?>
                <label style="display:inline-block;margin-right:16px">
                  <input type="checkbox" name="venue_id[]" value="<?= (int)$v['id'] ?>" <?= in_array((int)$v['id'], $assigned, true) ? 'checked' : '' ?>> <?= e($v['name']) ?>
                </label>
              <?php endforeach; ?>
                <button class="btn-outline btn-sm">Save</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
