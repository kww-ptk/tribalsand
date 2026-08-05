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
if (is_staff()) {
    $vids = admin_venue_ids() ?: [-1];
    $ph = []; foreach ($vids as $i=>$v) { $n = ":sv$i"; $ph[] = $n; $params[$n] = (int)$v; }
    $where[] = 'r.venue_id IN (' . implode(',', $ph) . ')';
}
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

/** Map an addon status to the admin badge colour class. */
$badgeClass = fn(string $s): string => [
    'requested'=>'badge--orange','confirmed'=>'badge--blue','completed'=>'badge--green',
    'declined'=>'badge--red','cancelled'=>'badge--grey',
][$s] ?? 'badge--grey';

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Concierge desk</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <span class="text-muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em">Status</span>
    <?php foreach ($statusTabs as $sk=>$sl): ?>
      <a href="?status=<?= e($sk) ?>&kind=<?= e($kindKey) ?>" class="btn-sm <?= $statusKey===$sk?'btn-primary':'btn-outline' ?>"><?= e($sl) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card__body" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;border-top:1px solid #eee">
    <span class="text-muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em">Service</span>
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
          <td><?= e(addon_label($a)) ?><?php if (($a['kind'] ?? '') === 'tour' && !empty($a['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$a['pax'] ?> pax</span><?php endif; ?><?php if (isset($a['price_amount']) && $a['price_amount'] !== null && (float)$a['price_amount'] > 0): ?> <span class="badge badge--grey"><?= e(format_price((float)$a['price_amount'])) ?></span><?php endif; ?></td>
          <td><?= !empty($a['scheduled_for']) ? e(date('D j M, H:i', strtotime((string)$a['scheduled_for']))) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted" style="font-size:12px"><?= e(date('j M, H:i', strtotime((string)$a['created_at']))) ?></td>
          <td><span class="badge <?= $badgeClass($a['status']) ?>"><?= e(addon_status_label($a['status'])) ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="/admin/messages.php?hold=<?= (int)$a['hold_id'] ?>&thread=<?= (int)$a['id'] ?>" class="btn-outline btn-sm">Message</a>
            <a href="/admin/booking.php?hold=<?= (int)$a['hold_id'] ?>" class="btn-outline btn-sm">Manage</a>
            <?php if ($a['status'] === 'requested'): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="confirmed" class="btn-primary btn-sm">Accept</button></form>
            <?php endif; ?>
            <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="completed" class="btn-outline btn-sm">Mark done</button></form>
            <?php endif; ?>
            <?php if ($a['status'] === 'requested'): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="declined" class="btn-danger btn-sm">Decline</button></form>
            <?php endif; ?>
            <?php if (!in_array($a['status'], ['requested','confirmed'], true)): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
