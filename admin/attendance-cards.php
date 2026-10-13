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
 * Gate: owner, manager or HR (require_hr). Pick a property, a company, or
 * (owner/HR) everyone with no property; every row is scoped by admin_venue_ids()
 * plus the companies a manager looks after (hr_admin_company_ids()).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/hr.php';
require_once __DIR__ . '/../includes/attendance-clock.php';
require_once __DIR__ . '/../includes/hr-establishment.php';
require_login();
require_hr();

// Printing cards for a feature that is switched off would hand out credentials
// that do nothing. Send them to the one page that can turn it on.
if (!clock_kiosk_enabled()) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Clocking in is switched off. An owner can turn it on here first.'];
    header('Location: /admin/attendance-devices.php'); exit;
}

$scope      = admin_venue_ids();       // null = owner/HR (all); array = manager's venues
$companyIds = hr_admin_company_ids();   // companies a manager looks after (Tribal Sand Limited has no property)

// ── Who to print for: a property, a company, or (owner/HR) people with no property ──
// Key "v<id>" / "c<id>" / "none"; the old ?venue=<id> links still work.
$venues = $scope === null
    ? db_query("SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC")->fetchAll()
    : ($scope
        ? db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ")
                    ORDER BY sort_order ASC, name ASC")->fetchAll()
        : []);
$groups = [];
foreach ($venues as $v) $groups['v' . (int)$v['id']] = ['label' => $v['name'], 'filter' => ['venue_id' => (int)$v['id']]];
if (hr_staff_company_supported()) {
    foreach (hr_company_names() as $cid => $cname) {
        if ($scope !== null && !in_array($cid, $companyIds ?? [], true)) continue;
        $groups['c' . $cid] = ['label' => 'Company: ' . $cname, 'filter' => ['company_id' => $cid]];
    }
}
if ($scope === null) $groups['none'] = ['label' => 'No property (office, restaurant, plots, kite school…)', 'filter' => ['no_venue' => true]];

$group = (string)($_POST['group'] ?? $_GET['group'] ?? '');
if ($group === '' && (int)($_GET['venue'] ?? 0) > 0) $group = 'v' . (int)$_GET['venue'];
if (!isset($groups[$group])) $group = (string)(array_key_first($groups) ?? '');   // foreign key ignored

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// ── POST: reissue one person's card token ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'reissue') {
        $sid  = (int)($_POST['id'] ?? 0);
        $staf = $sid ? fetch_hr_staff_row($sid) : false;
        $ok = $staf && hr_staff_in_venue_scope($sid, $staf['venue_id'] !== null ? (int)$staf['venue_id'] : null, $scope, $companyIds);
        if ($ok) {
            clock_reissue_token($sid);
            audit_log('attendance_device.card_reissue', 'hr_staff', $sid, (string)$staf['full_name']);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'New card issued for ' . $staf['full_name'] . '. The old printed card stops working immediately — reprint and hand out the new one.'];
        } else {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That staff member isn’t yours to reissue.'];
        }
    }
    header('Location: /admin/attendance-cards.php?group=' . urlencode($group)); exit;
}

$pageTitle  = 'Clock cards';
$activeMenu = 'attendance_cards';

$people = $group !== ''
    ? fetch_hr_staff($scope, $groups[$group]['filter'] + ['status' => 'active'], $companyIds)
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
  <div class="filter-field"><span>Print cards for</span>
    <select name="group" class="eselect" onchange="this.form.submit()">
      <?php if (!$groups): ?><option value="">Nobody to print for</option><?php endif; ?>
      <?php foreach ($groups as $k => $g): ?>
      <option value="<?= e($k) ?>" <?= $group === $k ? 'selected' : '' ?>><?= e($g['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="button" class="btn-primary btn-sm" onclick="window.print()"><?= admin_icon('download', 15) ?> Print all</button>
</form>

<?php if ($group === ''): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No property or company available to print cards for.</p></div></div>
<?php elseif (!$people): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No active staff here. Add them in the <a href="/admin/staff.php?tab=directory">directory</a> first.</p></div></div>
<?php else: ?>
<div class="cards-grid">
  <?php foreach ($people as $p):
    $sid = (int)$p['id'];
    $tok = clock_ensure_token($sid);   // mints only when missing — never regenerates on view
  ?>
  <div class="qr-card">
    <div data-qr="<?= e($tok) ?>"></div>
    <div class="qr-card__name"><?= e($p['full_name']) ?></div>
    <div class="qr-card__pos"><?= e($p['position'] ?: '—') ?><?php if (!empty($p['payroll_no'])): ?> · No. <?= e($p['payroll_no']) ?><?php endif; ?></div>
    <form method="POST" action="/admin/attendance-cards.php" class="no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reissue">
      <input type="hidden" name="id" value="<?= $sid ?>">
      <input type="hidden" name="group" value="<?= e($group) ?>">
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
