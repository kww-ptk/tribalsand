<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_login();

// Read-only, so reception may look but not touch — editing lives on the
// owner-only property and room pages. Scoped so a reception account only sees
// rates for its own properties.
$scope = admin_venue_ids();                 // null = owner (every venue)

$venues = $scope === null
    ? db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll()
    : ($scope
        ? db_query('SELECT id, name FROM venues WHERE id IN (' . implode(',', array_map('intval', $scope)) . ')
                    ORDER BY sort_order ASC, name ASC')->fetchAll()
        : []);

$venueId = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
if ($venueId && !in_array($venueId, array_map(fn($v) => (int)$v['id'], $venues), true)) $venueId = 0;
if (!$venueId && $venues) $venueId = (int)$venues[0]['id'];

$rooms = $venueId
    ? db_query('SELECT id, name, price_amount, price_currency FROM rooms
                 WHERE venue_id = :v ORDER BY sort_order ASC, id ASC', [':v' => $venueId])->fetchAll()
    : [];

$rateMonth = isset($_GET['rate_month']) && strtotime($_GET['rate_month'] . '-01')
    ? substr((string)$_GET['rate_month'], 0, 7)
    : date('Y-m');

$pageTitle  = 'Rates';
$activeMenu = 'rates';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Rates</h1>
  <form method="GET" style="display:flex;gap:8px;align-items:center;margin:0">
    <input type="hidden" name="rate_month" value="<?= e($rateMonth) ?>">
    <select name="venue" class="eselect" onchange="this.form.submit()">
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $venueId ? ' selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$venues): ?>
<div class="alert alert--info">No properties are assigned to your account.</div>
<?php elseif (!$rooms): ?>
<div class="alert alert--info">This property has no rooms yet.</div>
<?php else: ?>

<?php foreach ($rooms as $r): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card__head">
    <span class="card__title"><?= e($r['name']) ?></span>
    <?php if (is_owner()): ?>
    <a class="btn-sm btn-outline" href="/admin/room-edit.php?id=<?= (int)$r['id'] ?>">Edit rates <?= admin_icon('chevron-right', 14) ?></a>
    <?php endif; ?>
  </div>
  <div class="card__body" style="padding:20px">
    <?php
      $rc_room_id       = (int)$r['id'];
      $rc_default_price = (float)$r['price_amount'];
      $rc_currency      = (string)$r['price_currency'];
      $rc_month         = $rateMonth;
      $rc_base_url      = '/admin/rates.php?venue=' . $venueId;
      include __DIR__ . '/../includes/rate-calendar.php';
    ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
