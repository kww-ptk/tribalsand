<?php
declare(strict_types=1);
/**
 * Admin: kiosk tablets for the staff clock in/out QR card system.
 *
 * A "device" is a tablet running /clock.php that has been registered (paired
 * with a token stored, hashed, in attendance_devices). This page lists them
 * and lets a manager retire one. Registering a NEW device happens on the
 * tablet itself (/clock.php's setup mode) — this page only manages existing
 * ones, scoped to the account's own properties.
 *
 * Gate: owner or manager (require_manager). Scoped by admin_venue_ids().
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/attendance-clock.php';
require_login();
require_manager();

$scope = admin_venue_ids();   // null = owner (all); array = manager's venues

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// ── POST: revoke a device (ownership re-checked server-side) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'toggle') {
        // Site-wide config, so owner only — same rule as pricing, the site menu
        // and AI settings. A manager scoped to one property must not switch a
        // feature on across the whole estate.
        if (!is_owner()) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Only the owner can switch clocking in on or off.'];
        } else {
            $on = ($_POST['on'] ?? '') === '1';
            clock_kiosk_set_enabled($on);
            audit_log('clock_kiosk.' . ($on ? 'enable' : 'disable'), 'setting', 0, 'clock_kiosk_enabled');
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=> $on
                ? 'Clocking in is ON. Register a tablet to start.'
                : 'Clocking in is OFF. Registered tablets have stopped recording.'];
        }
    } elseif (($_POST['action'] ?? '') === 'revoke') {
        $id  = (int)($_POST['id'] ?? 0);
        $dev = $id ? db_query("SELECT id, venue_id, name FROM attendance_devices WHERE id = :i", [':i'=>$id])->fetch() : false;
        $ok = $dev && ($scope === null || in_array((int)$dev['venue_id'], array_map('intval', $scope), true));
        if ($ok) {
            clock_revoke_device($id);
            audit_log('attendance_device.revoke', 'attendance_device', $id, (string)$dev['name']);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Tablet revoked. It can no longer record time.'];
        } else {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That tablet isn’t yours to revoke.'];
        }
    }
    header('Location: /admin/attendance-devices.php'); exit;
}

$pageTitle  = 'Clock kiosks';
$activeMenu = 'attendance_devices';

$devices = [];
if (attendance_devices_supported()) {
    $where = '';
    if ($scope !== null) {
        $where = $scope ? ('WHERE d.venue_id IN (' . implode(',', array_map('intval', $scope)) . ')') : 'WHERE FALSE';
    }
    $devices = db_query(
        "SELECT d.*, v.name AS venue_name FROM attendance_devices d
           LEFT JOIN venues v ON v.id = d.venue_id
           $where
          ORDER BY d.is_active DESC, d.last_seen_at DESC NULLS LAST, d.created_at DESC"
    )->fetchAll();
}

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Clock kiosks</h1>
  <a href="/admin/attendance.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Attendance</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!attendance_devices_supported()): ?>
<div class="card"><div class="card__body card__body--pad">
  <p class="text-muted" style="margin:0">Clock kiosks aren’t set up yet. Run the <code>add_attendance_punches.sql</code> migration (after <code>add_attendance</code>) to enable this.</p>
</div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif; ?>

<?php $kioskOn = clock_kiosk_enabled(); ?>
<div class="card" style="margin-bottom:16px"><div class="card__body card__body--pad"
     style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
  <div style="flex:1 1 320px;min-width:0">
    <p style="margin:0 0 2px"><strong>Clocking in is
      <span style="color:<?= $kioskOn ? '#2f6f4f' : '#8a4b2a' ?>"><?= $kioskOn ? 'ON' : 'OFF' ?></span></strong></p>
    <p class="text-muted" style="margin:0;font-size:12.5px">
      <?php if ($kioskOn): ?>
        Staff can scan their cards on a registered tablet. Switching this off stops every
        tablet recording immediately — it does not delete anything already recorded.
      <?php else: ?>
        The kiosk, the card sheet and every registered tablet are switched off. Nothing is
        lost while it is off; turning it back on resumes exactly where it left off.
      <?php endif; ?>
    </p>
  </div>
  <?php if (is_owner()): ?>
  <form method="POST" style="flex:0 0 auto">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="on" value="<?= $kioskOn ? '0' : '1' ?>">
    <button class="<?= $kioskOn ? 'btn-outline' : 'btn-primary' ?> btn-sm"<?= $kioskOn ? ' data-confirm="Switch clocking in OFF? Every registered tablet stops recording immediately."' : '' ?>>
      <?= $kioskOn ? 'Switch off' : 'Switch on' ?>
    </button>
  </form>
  <?php else: ?>
  <span class="text-muted" style="font-size:12.5px;flex:0 0 auto">Only the owner can change this.</span>
  <?php endif; ?>
</div></div>

<?php if ($kioskOn): ?>
<div class="card" style="margin-bottom:16px"><div class="card__body card__body--pad">
  <p style="margin:0 0 4px"><strong>To register a new tablet:</strong> open <code><?= e(site_url('/clock.php')) ?></code> on it and follow the on-screen setup — give it a name and pick its property. It will then appear here.</p>
  <p class="text-muted" style="margin:0;font-size:12.5px">Registration happens on the tablet, not here, because the token is generated and stored only on that device.</p>
</div></div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Registered tablets</span><span class="text-muted" style="font-size:12px"><?= count($devices) ?> total</span></div>
  <div class="card__body" style="padding:0"><div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Property</th><th>Last seen</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
        <?php if (!$devices): ?>
        <tr><td colspan="5" style="padding:22px;text-align:center;color:var(--muted)">No tablets registered yet.</td></tr>
        <?php else: foreach ($devices as $d): ?>
        <tr>
          <td><strong><?= e($d['name']) ?></strong></td>
          <td><?= e($d['venue_name'] ?? 'Unassigned') ?></td>
          <td class="text-muted" style="font-size:12.5px"><?= $d['last_seen_at'] ? e(date('j M Y, H:i', strtotime($d['last_seen_at']))) : 'Never' ?></td>
          <td><?php if ($d['is_active']): ?><span class="badge badge--green">Active</span><?php else: ?><span class="badge badge--red">Revoked</span><?php endif; ?></td>
          <td style="text-align:right">
            <?php if ($d['is_active']): ?>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn-outline btn-sm" data-confirm="Revoke &ldquo;<?= e($d['name']) ?>&rdquo;? It will stop recording punches immediately.">Revoke</button>
            </form>
            <?php else: ?>
            <span class="text-muted" style="font-size:12px">Revoked</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
