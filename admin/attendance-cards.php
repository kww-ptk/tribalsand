<?php
declare(strict_types=1);
/**
 * Admin: printable QR clock-in cards, one per active staff member.
 *
 * Each card's QR encodes that person's punch_token (a 32-hex secret, never
 * their id) — the same string /clock.php's jsQR scanner reads. Printed and
 * handed out / laminated, a card is what lets someone clock in without
 * signing in to anything.
 *
 * Gate: owner or manager (require_manager). Property picker + every staff
 * row shown are scoped by admin_venue_ids().
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/hr.php';
require_once __DIR__ . '/../includes/attendance-clock.php';
require_login();
require_manager();

$scope = admin_venue_ids();   // null = owner (all); array = manager's venues

// ── Property picker, scoped ─────────────────────────────────────────────
$venues = $scope === null
    ? db_query("SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC")->fetchAll()
    : ($scope
        ? db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ")
                    ORDER BY sort_order ASC, name ASC")->fetchAll()
        : []);
$allowedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

$venueId = (int)($_POST['venue_id'] ?? $_GET['venue'] ?? 0);
if ($venueId && !in_array($venueId, $allowedVenueIds, true)) $venueId = 0;   // foreign id, ignored
if (!$venueId && $allowedVenueIds) $venueId = $allowedVenueIds[0];

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// ── POST: reissue one person's card token ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'reissue') {
        $sid  = (int)($_POST['id'] ?? 0);
        $staf = $sid ? fetch_hr_staff_row($sid) : false;
        $ok = $staf && $staf['venue_id'] !== null && in_array((int)$staf['venue_id'], $allowedVenueIds, true);
        if ($ok) {
            clock_reissue_token($sid);
            audit_log('attendance_device.card_reissue', 'hr_staff', $sid, (string)$staf['full_name']);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'New card issued for ' . $staf['full_name'] . '. The old printed card stops working immediately — reprint and hand out the new one.'];
        } else {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That staff member isn’t yours to reissue.'];
        }
    }
    header('Location: /admin/attendance-cards.php?venue=' . $venueId); exit;
}

$pageTitle  = 'Clock cards';
$activeMenu = 'attendance_cards';

$people = $venueId
    ? fetch_hr_staff($scope, ['venue_id' => $venueId, 'status' => 'active'])
    : [];

include __DIR__ . '/_layout.php';
?>
<style>
@media print {
  .admin-topbar, .sidebar, .sidebar-overlay, .page-header, .no-print { display: none !important; }
  .admin-content { padding: 0 !important; }
  .cards-grid { display: grid !important; grid-template-columns: repeat(2, 1fr) !important; gap: 12px !important; }
  .qr-card { break-inside: avoid; border: 1px solid #999 !important; box-shadow: none !important; }
}
.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.qr-card { border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; text-align: center; background: #fff; }
.qr-card svg { width: 150px; height: 150px; margin: 0 auto 10px; }
.qr-card__name { font-weight: 700; font-size: 15px; color: var(--navy, #1E5C6B); }
.qr-card__pos { font-size: 12px; color: var(--muted, #6b7280); margin-bottom: 10px; }
</style>

<div class="page-header">
  <h1>Clock cards</h1>
  <div style="display:flex;gap:8px">
    <a href="/admin/attendance-devices.php" class="btn-outline btn-sm"><?= admin_icon('link', 15) ?> Kiosks</a>
    <a href="/admin/attendance.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Attendance</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<form method="GET" action="/admin/attendance-cards.php" class="no-print" style="display:flex;gap:12px;align-items:flex-end;margin-bottom:16px">
  <div class="filter-field"><span>Property</span>
    <select name="venue" class="eselect" onchange="this.form.submit()">
      <?php if (!$allowedVenueIds): ?><option value="0">No properties</option><?php endif; ?>
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>" <?= $venueId === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="button" class="btn-primary btn-sm" onclick="window.print()"><?= admin_icon('download', 15) ?> Print all</button>
</form>

<?php if (!$venueId): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No property available to print cards for.</p></div></div>
<?php elseif (!$people): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No active staff at this property. Add them in the <a href="/admin/staff.php?tab=directory">directory</a> first.</p></div></div>
<?php else: ?>
<div class="cards-grid">
  <?php foreach ($people as $p):
    $sid = (int)$p['id'];
    $tok = clock_ensure_token($sid);   // mints only when missing — never regenerates on view
  ?>
  <div class="qr-card">
    <div data-qr="<?= e($tok) ?>"></div>
    <div class="qr-card__name"><?= e($p['full_name']) ?></div>
    <div class="qr-card__pos"><?= e($p['position'] ?: '&mdash;') ?></div>
    <form method="POST" action="/admin/attendance-cards.php" class="no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reissue">
      <input type="hidden" name="id" value="<?= $sid ?>">
      <input type="hidden" name="venue_id" value="<?= $venueId ?>">
      <button type="submit" class="btn-outline btn-sm" data-confirm="Issue <?= e($p['full_name']) ?> a new card? Their current printed card stops working the instant you do this.">Reissue card</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script src="/js/vendor/qrcode.js?v=<?= @filemtime(__DIR__ . '/../js/vendor/qrcode.js') ?: time() ?>"></script>
<script>
Array.prototype.forEach.call(document.querySelectorAll('[data-qr]'), function (el) {
  var qr = qrcode(0, 'M');
  qr.addData(el.getAttribute('data-qr'));
  qr.make();
  el.innerHTML = qr.createSvgTag({ scalable: true });
});
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
