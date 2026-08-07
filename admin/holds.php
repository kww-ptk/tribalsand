<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/checkin.php';
require_login();
require_owner();

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
$status_filter = $_GET['status'] ?? 'pending';
$room_filter   = (int)($_GET['room'] ?? 0);

$conditions = [];
$params     = [];

switch ($status_filter) {
    case 'pending':   $conditions[] = "h.status = 'pending'";   break;
    case 'confirmed': $conditions[] = "h.status = 'confirmed'"; break;
    case 'expired':   $conditions[] = "h.status = 'expired'";   break;
    case 'cancelled': $conditions[] = "h.status = 'cancelled'"; break;
    case 'checkin_pending': if (checkin_supported()) { $conditions[] = "h.require_checkin = TRUE AND h.checkin_completed_at IS NULL AND h.status IN ('pending','confirmed')"; break; } // else fall through to active
    default:          $conditions[] = "h.status IN ('pending','confirmed')"; break; // active
}

if ($room_filter) {
    $conditions[] = "r.id = :room_id";
    $params[':room_id'] = $room_filter;
}

// Free-text search + pagination.
$pg = paginate_params(25);
$sw = search_where(['h.guest_name', "COALESCE(h.guest_email,'')", 'r.name', "COALESCE(h.access_code,'')"], $pg['q'], $params);
if ($sw !== '') $conditions[] = $sw;

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$holdsFrom = "FROM holds h
     JOIN units u ON u.id = h.unit_id
     JOIN rooms r ON r.id = u.room_id
     {$where}";

$total = (int) db_query("SELECT COUNT(*) {$holdsFrom}", $params)->fetchColumn();
$meta  = paginate_meta($total, $pg['page'], $pg['per']);

// Guarded subquery so a pre-migration DB (no checkin_guests table) doesn't 42703;
// feeds checkin_badge()'s "X/N" party label alongside guest_count (already in h.*).
$ciCols = checkin_supported()
    ? ", (SELECT COUNT(*) FROM checkin_guests cg WHERE cg.hold_id = h.id AND cg.is_child = FALSE
           AND COALESCE(cg.passport_name,'') <> '' AND COALESCE(cg.passport_number,'') <> ''
           AND COALESCE(cg.passport_file_key,'') <> '' AND cg.waiver_signed_at IS NOT NULL) AS ci_complete_count"
    : '';

$holds = db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.id AS room_db_id, r.venue_id AS venue_id
     {$ciCols}
     {$holdsFrom}
     ORDER BY h.created_at DESC
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $params
)->fetchAll();

// Assignment (Phase 2): only surfaces once add_addon_assignee.sql has run. Owner
// sees this page, so candidates are cached per venue as we render.
$asgOn = addon_assigned_supported();
$venueCandCache = [];
$candsFor = function (int $vid) use (&$venueCandCache) {
    if (!array_key_exists($vid, $venueCandCache)) $venueCandCache[$vid] = $vid > 0 ? assignable_team_for_venue($vid) : [];
    return $venueCandCache[$vid];
};

// KPIs
$kpi_pending   = db_query("SELECT COUNT(*) FROM holds WHERE status='pending'")->fetchColumn();
$kpi_confirmed = db_query("SELECT COUNT(*) FROM holds WHERE status='confirmed'")->fetchColumn();
$kpi_today     = db_query("SELECT COUNT(*) FROM holds WHERE created_at::date = CURRENT_DATE")->fetchColumn();

// Room list for filter dropdown
$rooms = db_query("SELECT id, name FROM rooms ORDER BY sort_order ASC")->fetchAll();

$pageTitle  = 'Holds & Bookings';
$activeMenu = 'holds';

// ── Swappable body (table + pager) — reused for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body">
    <?php if (empty($holds)): ?>
    <?php dt_empty($pg['q'] !== '' ? 'No bookings match your search.' : 'Nothing to show for this filter.'); ?>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Guest</th>
          <th>Room / Unit</th>
          <th>Dates</th>
          <th>Status</th>
          <th>Check-in</th>
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
            <?php $__mref = make_manage_url((int)$hold['id']); $__code = $hold['access_code'] ?? ''; ?>
            <?php if ($__code || $__mref): ?>
            <div style="font-size:12px;color:var(--muted);margin-top:4px">
              Code: <strong style="letter-spacing:1px;color:var(--text,#111)"><?= e($__code ?: '—') ?></strong>
              <?php if ($__mref): ?>
              <button type="button" class="copy-link" data-link="<?= e($__mref) ?>"
                      style="margin-left:6px;font-size:11px;padding:1px 7px;cursor:pointer;border:1px solid #ccc;border-radius:4px;background:#fff">Copy portal link</button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
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
          <td><?php $__ci = checkin_badge($hold); if ($__ci): ?><span class="ci-badge <?= e($__ci['class']) ?>"><?= e($__ci['label']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($expires_str) ?></td>
          <td>
            <div class="row-actions">
            <?php if ($status === 'pending'): ?>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="hold_id" value="<?= e($hold['id']) ?>">
              <input type="hidden" name="action"  value="confirm">
              <button type="submit" class="btn-icon btn-icon--primary" title="Confirm hold" aria-label="Confirm hold"
                      data-confirm="Confirm this hold and notify the guest?"><?= admin_icon('check') ?></button>
            </form>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="hold_id" value="<?= e($hold['id']) ?>">
              <input type="hidden" name="action"  value="cancel">
              <button type="submit" class="btn-icon btn-icon--danger" title="Cancel hold" aria-label="Cancel hold"
                      data-confirm="Cancel this hold? Dates will be freed and the guest notified."><?= admin_icon('x') ?></button>
            </form>
            <?php elseif ($status === 'confirmed'): ?>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="hold_id" value="<?= e($hold['id']) ?>">
              <input type="hidden" name="action"  value="cancel">
              <button type="submit" class="btn-icon btn-icon--danger" title="Cancel booking" aria-label="Cancel booking"
                      data-confirm="Cancel this confirmed booking? Dates will be freed and the guest notified."><?= admin_icon('x') ?></button>
            </form>
            <?php endif; ?>
            <a href="/admin/booking.php?hold=<?= (int)$hold['id'] ?>" class="btn-icon btn-icon--outline" title="Manage booking" aria-label="Manage booking"><?= admin_icon('edit') ?></a>
            </div>
          </td>
        </tr>
        <?php
          $h_addons  = fetch_booking_addons((int)$hold['id']);
          $h_changes = fetch_booking_change_requests((int)$hold['id']);
        ?>
        <?php if ($h_addons || $h_changes): ?>
        <tr class="hold-requests-row">
          <td></td>
          <td colspan="7" style="padding-top:0;padding-bottom:12px">
            <div class="hold-requests" style="padding:10px 12px;background:var(--bg-alt,#f7f7f5);border-radius:6px">
              <?php foreach ($h_addons as $a): ?>
              <div style="display:flex;gap:8px;align-items:center;font-size:13px;margin:4px 0;flex-wrap:wrap">
                <strong><?= e(ucfirst($a['kind'])) ?></strong>
                <span><?= e(trim(($a['tour_name'] ?? '') . ' ' . $a['details'])) ?></span>
                <em>(<?= e(addon_status_label($a['status'])) ?>)</em>
                <?php if ($asgOn && in_array($a['status'], ['requested','confirmed'], true)): ?>
                <?php $curAsg = isset($a['assigned_to']) ? (int)$a['assigned_to'] : 0; $cands = $candsFor((int)($hold['venue_id'] ?? 0)); ?>
                <form method="POST" action="/admin/booking-request-action.php" style="display:inline;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="type" value="assign">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <select name="assigned_to" onchange="this.form.submit()" style="font-size:12px;padding:2px 6px;border:1px solid #ccc;border-radius:4px">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($cands as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $curAsg === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= ($c['role'] ?? '')==='manager' ? ' (mgr)' : (!empty($c['job_type']) ? ' · '.e($c['job_type']) : '') ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <?php endif; ?>
                <?php if ($a['status'] === 'requested'): ?>
                <form method="POST" action="/admin/booking-request-action.php" style="display:inline;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="type" value="addon">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" name="status" value="confirmed" class="btn-icon btn-icon--primary" title="Confirm request" aria-label="Confirm request" data-confirm="Apply this action?"><?= admin_icon('check') ?></button>
                  <button type="submit" name="status" value="declined" class="btn-icon btn-icon--danger" title="Decline request" aria-label="Decline request" data-confirm="Apply this action?"><?= admin_icon('x') ?></button>
                </form>
                <?php endif; ?>
                <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
                <form method="POST" action="/admin/booking-request-action.php" style="display:inline;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" name="status" value="completed" class="btn-icon btn-icon--outline" title="Mark done" aria-label="Mark done" data-confirm="Mark this request done?"><?= admin_icon('check-check') ?></button>
                </form>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
              <?php foreach ($h_changes as $c): ?>
              <div style="display:flex;gap:8px;align-items:center;font-size:13px;margin:4px 0;flex-wrap:wrap">
                <strong>Change</strong>
                <span>
                  <?= e(trim((string)($c['requested_check_in'] ?? '') . ' → ' . (string)($c['requested_check_out'] ?? ''), ' →')) ?>
                  <?= $c['requested_guests'] ? ' · ' . (int)$c['requested_guests'] . ' guests' : '' ?>
                  <?= e($c['note']) ?>
                </span>
                <em>(<?= e($c['status']) ?>)</em>
                <?php if ($c['status'] === 'requested'): ?>
                <form method="POST" action="/admin/booking-request-action.php" style="display:inline;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="type" value="change">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" name="status" value="handled" class="btn-icon btn-icon--primary" title="Mark handled" aria-label="Mark handled" data-confirm="Apply this action?"><?= admin_icon('check') ?></button>
                  <button type="submit" name="status" value="declined" class="btn-icon btn-icon--danger" title="Decline change" aria-label="Decline change" data-confirm="Apply this action?"><?= admin_icon('x') ?></button>
                </form>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

// AJAX fragment: emit only the swappable body and stop.
if ($pg['ajax']) { echo $dtBody; exit; }

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Holds &amp; Bookings</h1>
  <div class="actions">
    <a href="/admin/hold-new.php" class="btn-primary btn-sm">+ New Booking</a>
  </div>
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

<!-- Filters + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
<form method="GET" action="/admin/holds" class="filters">
  <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
  <input type="hidden" name="per" value="<?= (int)$meta['per'] ?>">
  <label class="filter-field">Status
    <select name="status" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
      <option value="pending"   <?= $status_filter === 'pending'   ? 'selected' : '' ?>>Pending only</option>
      <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed only</option>
      <option value="expired"   <?= $status_filter === 'expired'   ? 'selected' : '' ?>>Expired</option>
      <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
      <?php if (checkin_supported()): ?>
      <option value="checkin_pending" <?= $status_filter === 'checkin_pending' ? 'selected' : '' ?>>Check-in pending</option>
      <?php endif; ?>
    </select>
  </label>
  <label class="filter-field">Room
    <select name="room" class="filter-select" aria-label="Filter by room" onchange="this.form.submit()">
      <option value="">All rooms</option>
      <?php foreach ($rooms as $r): ?>
      <option value="<?= e($r['id']) ?>" <?= $room_filter === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search guest, email, room or code…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.copy-link');
  if (!b) return;
  var link = b.getAttribute('data-link');
  var done = function () { var t = b.textContent; b.textContent = 'Copied!'; setTimeout(function () { b.textContent = t; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(link).then(done).catch(function () { window.prompt('Copy this portal link:', link); });
  } else {
    window.prompt('Copy this portal link:', link);
  }
});
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
