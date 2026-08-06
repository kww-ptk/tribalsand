<?php
/**
 * Admin: Concierge Desk — every guest request across all bookings, with filters + inline actions.
 * Actions post to booking-request-action.php (validated transitions + audit_log).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();

$pageTitle  = 'Concierge desk';
$activeMenu = 'concierge_desk';

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$KINDS  = ['tour','transfer','laundry','housekeeping','amenities','maintenance','restaurant','itinerary','other'];
$STATUS_SETS = [
    'open'      => ['requested','confirmed'],
    'requested' => ['requested'],
    'confirmed' => ['confirmed'],
    'completed' => ['completed'],
    'declined'  => ['declined'],
    'cancelled' => ['cancelled'],
];
$statusKey = $_GET['status'] ?? 'open';
if ($statusKey !== 'all' && !isset($STATUS_SETS[$statusKey])) $statusKey = 'open';
$kindKey = $_GET['kind'] ?? 'all';
if ($kindKey !== 'all' && !in_array($kindKey, $KINDS, true)) $kindKey = 'all';

$where = [];
$params = [];
if ($statusKey !== 'all') {
    $set = $STATUS_SETS[$statusKey];
    $names = [];
    foreach ($set as $i => $s) { $n = ":st{$i}"; $names[] = $n; $params[$n] = $s; }
    $where[] = 'ba.status IN (' . implode(',', $names) . ')';
}
if ($kindKey !== 'all') { $where[] = 'ba.kind = :kind'; $params[':kind'] = $kindKey; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = db_query(
    "SELECT ba.*, t.name AS tour_name,
            h.guest_name, h.check_in, h.check_out,
            r.name AS room_name, v.name AS venue_name
     FROM booking_addons ba
     JOIN holds h  ON h.id = ba.hold_id
     JOIN units u  ON u.id = h.unit_id
     JOIN rooms r  ON r.id = u.room_id
     LEFT JOIN venues v ON v.id = r.venue_id
     LEFT JOIN tours t  ON t.id = ba.tour_id
     {$whereSql}
     ORDER BY ba.created_at DESC",
    $params
)->fetchAll();

$statusTabs = ['open'=>'Open','all'=>'All','requested'=>'Requested','confirmed'=>'In progress','completed'=>'Done','declined'=>'Declined','cancelled'=>'Cancelled'];

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Concierge desk</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= e($flash['type']) ?> alert--dismissible" id="deskFlash" role="status">
  <span class="alert__ico" aria-hidden="true"><?= $flash['type'] === 'success' ? '✓' : '⚠' ?></span>
  <span><?= e($flash['msg']) ?></span>
  <button type="button" class="alert__x" aria-label="Dismiss" onclick="this.closest('.alert').remove()">&times;</button>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card__body filter-row">
    <span class="filter-row__label">Status</span>
    <?php foreach ($statusTabs as $sk=>$sl): ?>
      <a href="?status=<?= e($sk) ?>&kind=<?= e($kindKey) ?>" class="btn-sm <?= $statusKey===$sk?'btn-primary':'btn-outline' ?>"><?= e($sl) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card__body filter-row filter-row--divided">
    <span class="filter-row__label">Service</span>
    <a href="?status=<?= e($statusKey) ?>&kind=all" class="btn-sm <?= $kindKey==='all'?'btn-primary':'btn-outline' ?>">All</a>
    <?php foreach ($KINDS as $k): ?>
      <a href="?status=<?= e($statusKey) ?>&kind=<?= e($k) ?>" class="btn-sm <?= $kindKey===$k?'btn-primary':'btn-outline' ?>" style="text-transform:capitalize"><?= e($k) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Guest</th><th>Service</th><th>Request</th><th>Preferred time</th><th>Submitted</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No requests match this filter.</td></tr>
        <?php else: foreach ($rows as $a): ?>
        <tr>
          <td>
            <strong><?= e($a['guest_name'] ?: 'Guest') ?></strong><br>
            <span class="text-muted" style="font-size:12px"><?= e(trim(($a['venue_name'] ?? '') . ' · ' . ($a['room_name'] ?? ''), ' ·')) ?></span>
          </td>
          <td style="text-transform:capitalize"><?= e($a['kind']) ?></td>
          <td><?= e(addon_label($a)) ?></td>
          <td><?= !empty($a['scheduled_for']) ? e(date('D j M, H:i', strtotime((string)$a['scheduled_for']))) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted" style="font-size:12px"><?= e(date('j M, H:i', strtotime((string)$a['created_at']))) ?></td>
          <td><?= status_badge($a['status'], 'badge') ?></td>
          <?php $__who = e(($a['kind'] ?? 'service') . ' request from ' . ($a['guest_name'] ?: 'guest')); ?>
          <td style="text-align:right;white-space:nowrap">
            <?php if ($a['status'] === 'requested'): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="confirmed" class="btn-primary btn-sm" aria-label="Accept <?= $__who ?>">Accept</button></form>
            <?php endif; ?>
            <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="completed" class="btn-outline btn-sm" aria-label="Mark <?= $__who ?> as done">Mark done</button></form>
            <?php endif; ?>
            <?php if ($a['status'] === 'requested'): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline" data-confirm="Decline this request? This cannot be undone." data-confirm-yes="Decline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="declined" class="btn-danger btn-sm" aria-label="Decline <?= $__who ?>">Decline</button></form>
            <?php endif; ?>
            <?php if (!in_array($a['status'], ['requested','confirmed'], true)): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Immediate feedback while the action POST round-trips: dim the row, show "…".
document.querySelectorAll('.data-table form').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;                                   // confirm dialog cancelled
    if (f.hasAttribute('data-confirm') && !f.dataset.confirmed) return; // wait for the custom confirm
    var btn = f.querySelector('button');
    if (btn) btn.textContent = '…';
    var cell = f.closest('td');
    // Defer disabling: the form's entry list is built right after this handler, and a
    // disabled submit button would be dropped from the POST (losing name="status"). By the
    // time this timeout runs, the request is already serialized and on its way.
    setTimeout(function () {
      if (cell) cell.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    }, 0);
  });
});
// Auto-dismiss the flash after a few seconds.
(function () {
  var flash = document.getElementById('deskFlash');
  if (!flash) return;
  setTimeout(function () {
    flash.style.transition = 'opacity .3s';
    flash.style.opacity = '0';
    setTimeout(function () { flash.remove(); }, 300);
  }, 4000);
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
