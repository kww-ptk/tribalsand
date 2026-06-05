<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_login();

// Lazy-expire stale holds on every page load
expire_stale_holds();

$success = '';
$error   = '';

// Flash message from tokenized email action (hold-action.php redirect)
if (!empty($_SESSION['hold_flash'])) {
    $flash = $_SESSION['hold_flash'];
    unset($_SESSION['hold_flash']);
    if ($flash['type'] === 'success') $success = $flash['msg'];
    else                               $error   = $flash['msg'];
}

// ── POST: confirm or cancel a hold ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $hold_id = (int)($_POST['hold_id'] ?? 0);
    $action  = $_POST['action'] ?? '';

    if ($hold_id && in_array($action, ['confirm', 'cancel'])) {
        $hold = db_query(
            "SELECT h.*, u.name AS unit_name, r.name AS room_name
             FROM holds h
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = u.room_id
             WHERE h.id = :id",
            [':id' => $hold_id]
        )->fetch();

        if ($hold && $action === 'confirm' && $hold['status'] === 'pending') {
            db_query("UPDATE holds SET status='confirmed', confirmed_at=NOW() WHERE id=:id", [':id' => $hold_id]);
            db_query("UPDATE availability_blocks SET block_type='booked' WHERE hold_id=:hid", [':hid' => $hold_id]);
            if ($hold['guest_email']) send_hold_confirmed($hold);
            audit_log('hold.confirm', 'hold', $hold_id, "{$hold['guest_name']} {$hold['check_in']}→{$hold['check_out']}");
            $success = "Hold #{$hold_id} confirmed — confirmation email sent to {$hold['guest_email']}.";
        } elseif ($hold && $action === 'cancel' && in_array($hold['status'], ['pending', 'confirmed'])) {
            $was_status = $hold['status'];
            db_query("UPDATE holds SET status='cancelled', cancelled_at=NOW() WHERE id=:id", [':id' => $hold_id]);
            db_query("DELETE FROM availability_blocks WHERE hold_id=:hid", [':hid' => $hold_id]);
            if ($hold['guest_email']) send_hold_cancelled($hold, 'cancelled');
            audit_log('hold.cancel', 'hold', $hold_id, "{$hold['guest_name']} {$hold['check_in']}→{$hold['check_out']} (was {$was_status})");
            $success = "Hold #{$hold_id} cancelled — dates freed, guest notified.";
        } else {
            $error = 'Action not allowed for this hold status.';
        }
    }
}

// ── Filters ─────────────────────────────────────────────────────
$status_filter = $_GET['status'] ?? 'active';
$room_filter   = (int)($_GET['room'] ?? 0);

$conditions = [];
$params     = [];

switch ($status_filter) {
    case 'pending':   $conditions[] = "h.status = 'pending'";   break;
    case 'confirmed': $conditions[] = "h.status = 'confirmed'"; break;
    case 'expired':   $conditions[] = "h.status = 'expired'";   break;
    case 'cancelled': $conditions[] = "h.status = 'cancelled'"; break;
    default:          $conditions[] = "h.status IN ('pending','confirmed')"; break; // active
}

if ($room_filter) {
    $conditions[] = "r.id = :room_id";
    $params[':room_id'] = $room_filter;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$holds = db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.id AS room_db_id
     FROM holds h
     JOIN units u ON u.id = h.unit_id
     JOIN rooms r ON r.id = u.room_id
     {$where}
     ORDER BY h.created_at DESC
     LIMIT 200",
    $params
)->fetchAll();

// KPIs
$kpi_pending   = db_query("SELECT COUNT(*) FROM holds WHERE status='pending'")->fetchColumn();
$kpi_confirmed = db_query("SELECT COUNT(*) FROM holds WHERE status='confirmed'")->fetchColumn();
$kpi_today     = db_query("SELECT COUNT(*) FROM holds WHERE created_at::date = CURRENT_DATE")->fetchColumn();

// Room list for filter dropdown
$rooms = db_query("SELECT id, name FROM rooms ORDER BY sort_order ASC")->fetchAll();

$pageTitle  = 'Holds & Bookings';
$activeMenu = 'holds';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Holds &amp; Bookings</h1>
</div>

<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="kpi-card">
    <div class="kpi-card__label">Pending</div>
    <div class="kpi-card__value"><?= e($kpi_pending) ?></div>
    <div class="kpi-card__sub">awaiting confirmation</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__label">Confirmed</div>
    <div class="kpi-card__value"><?= e($kpi_confirmed) ?></div>
    <div class="kpi-card__sub">active bookings</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__label">New Today</div>
    <div class="kpi-card__value"><?= e($kpi_today) ?></div>
    <div class="kpi-card__sub">hold requests received</div>
  </div>
</div>

<!-- Filters -->
<form method="GET" action="/admin/holds.php" class="filters">
  <select name="status" onchange="this.form.submit()">
    <option value="active"    <?= $status_filter === 'active'    ? 'selected' : '' ?>>Active (pending + confirmed)</option>
    <option value="pending"   <?= $status_filter === 'pending'   ? 'selected' : '' ?>>Pending only</option>
    <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed only</option>
    <option value="expired"   <?= $status_filter === 'expired'   ? 'selected' : '' ?>>Expired</option>
    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
  </select>
  <select name="room" onchange="this.form.submit()">
    <option value="">All rooms</option>
    <?php foreach ($rooms as $r): ?>
    <option value="<?= e($r['id']) ?>" <?= $room_filter === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<!-- Holds table -->
<div class="card">
  <div class="card__body">
    <?php if (empty($holds)): ?>
    <p style="padding:32px;text-align:center;color:var(--muted)">No holds found for this filter.</p>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Guest</th>
          <th>Room / Unit</th>
          <th>Dates</th>
          <th>Status</th>
          <th>Expires / Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($holds as $hold): ?>
        <?php
          $status = $hold['status'];
          $badge  = match($status) {
              'pending'   => 'badge--orange',
              'confirmed' => 'badge--green',
              'expired'   => 'badge--grey',
              'cancelled' => 'badge--red',
              default     => 'badge--grey',
          };
          $expires_str = '';
          if ($status === 'pending' && $hold['expires_at']) {
              $diff = strtotime($hold['expires_at']) - time();
              $expires_str = $diff > 0
                  ? 'Expires in ' . gmdate('H\h i\m', $diff)
                  : 'Expiring…';
          } elseif ($status === 'confirmed' && $hold['confirmed_at']) {
              $expires_str = 'Confirmed ' . date('d M H:i', strtotime($hold['confirmed_at']));
          } elseif ($hold['cancelled_at']) {
              $expires_str = 'Cancelled ' . date('d M H:i', strtotime($hold['cancelled_at']));
          } elseif ($hold['expires_at']) {
              $expires_str = 'Expired ' . date('d M H:i', strtotime($hold['expires_at']));
          }
        ?>
        <tr>
          <td><?= e($hold['id']) ?></td>
          <td>
            <strong><?= e($hold['guest_name']) ?></strong><br>
            <a href="mailto:<?= e($hold['guest_email']) ?>" style="font-size:12px;color:var(--muted)"><?= e($hold['guest_email']) ?></a>
          </td>
          <td>
            <?= e($hold['room_name']) ?><br>
            <span style="font-size:12px;color:var(--muted)"><?= e($hold['unit_name']) ?></span>
          </td>
          <td>
            <?= e($hold['check_in']) ?><br>
            <span style="font-size:12px;color:var(--muted)">→ <?= e($hold['check_out']) ?></span>
          </td>
          <td><span class="badge <?= $badge ?>"><?= e($status) ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($expires_str) ?></td>
          <td>
            <?php if ($status === 'pending'): ?>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="hold_id" value="<?= e($hold['id']) ?>">
              <input type="hidden" name="action"  value="confirm">
              <button type="submit" class="btn-primary btn-sm"
                      onclick="return confirm('Confirm this hold and notify the guest?')">Confirm</button>
            </form>
            <form method="POST" style="display:inline;margin-left:4px">
              <?= csrf_field() ?>
              <input type="hidden" name="hold_id" value="<?= e($hold['id']) ?>">
              <input type="hidden" name="action"  value="cancel">
              <button type="submit" class="btn-danger btn-sm"
                      onclick="return confirm('Cancel this hold? Dates will be freed and the guest notified.')">Cancel</button>
            </form>
            <?php elseif ($status === 'confirmed'): ?>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="hold_id" value="<?= e($hold['id']) ?>">
              <input type="hidden" name="action"  value="cancel">
              <button type="submit" class="btn-danger btn-sm"
                      onclick="return confirm('Cancel this confirmed booking? Dates will be freed and the guest notified.')">Cancel</button>
            </form>
            <?php else: ?>
            <span style="color:var(--muted);font-size:12px">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
