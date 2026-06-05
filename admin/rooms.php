<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

// Handle publish toggle via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish'])) {
    verify_csrf();
    $id  = (int)$_POST['room_id'];
    $val = $_POST['is_published'] === '1' ? 'FALSE' : 'TRUE';
    db_query("UPDATE rooms SET is_published = {$val}, updated_at = NOW() WHERE id = :id", [':id' => $id]);
    header('Location: /admin/rooms.php');
    exit;
}

// Handle sort order update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    header('Content-Type: application/json');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    foreach ($ids as $order => $id) {
        db_query('UPDATE rooms SET sort_order = :order WHERE id = :id', [':order' => $order + 1, ':id' => (int)$id]);
    }
    exit(json_encode(['ok' => true]));
}

$pageTitle  = 'Rooms';
$activeMenu = 'rooms';

$venues       = db_query("SELECT id, name FROM venues ORDER BY sort_order")->fetchAll();
$filterVenue  = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
$rooms = db_query(
    "SELECT r.*, v.name AS venue_name,
        (SELECT filename FROM room_images WHERE room_id = r.id AND is_hero = TRUE LIMIT 1) AS hero_img
     FROM rooms r
     LEFT JOIN venues v ON v.id = r.venue_id" .
     ($filterVenue ? " WHERE r.venue_id = :vid" : "") .
     " ORDER BY r.sort_order ASC",
    $filterVenue ? [':vid' => $filterVenue] : []
)->fetchAll();

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Rooms</h1>
  <form method="GET" style="margin-left:auto;display:flex;gap:8px;align-items:center">
    <select name="venue" onchange="this.form.submit()" style="padding:6px 8px">
      <option value="0">All properties</option>
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>" <?= $filterVenue === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <a href="/admin/room-edit.php" class="btn-primary btn-sm">+ Add Room</a>
  </form>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">All Rooms</span>
    <span class="text-muted" style="font-size:12px">Drag rows to reorder</span>
  </div>
  <div class="card__body">
    <table class="data-table" id="roomsTable">
      <thead>
        <tr>
          <th style="width:32px"></th>
          <th style="width:60px">Photo</th>
          <th>Name</th>
          <th>Property</th>
          <th>Slug</th>
          <th>Price</th>
          <th>Published</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="roomsTbody">
        <?php foreach ($rooms as $room): ?>
        <tr data-id="<?= e($room['id']) ?>" class="draggable-row">
          <td style="cursor:grab;color:var(--muted);font-size:18px;text-align:center">&#8942;&#8942;</td>
          <td>
            <?php if ($room['hero_img']): ?>
            <img src="<?= e(storage_url($room['hero_img'])) ?>" class="room-thumb" alt="<?= e($room['name']) ?>">
            <?php else: ?>
            <div style="width:52px;height:40px;background:var(--border);border-radius:4px"></div>
            <?php endif; ?>
          </td>
          <td><strong><?= e($room['name']) ?></strong></td>
          <td class="text-muted"><?= e($room['venue_name'] ?? '—') ?></td>
          <td class="text-muted"><?= e($room['slug']) ?></td>
          <td><?= e($room['price_currency']) ?> <?= e(number_format((float)$room['price_amount'], 0)) ?> <span class="text-muted"><?= e($room['price_unit']) ?></span></td>
          <td>
            <form method="POST" action="/admin/rooms.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="toggle_publish" value="1">
              <input type="hidden" name="room_id" value="<?= e($room['id']) ?>">
              <input type="hidden" name="is_published" value="<?= $room['is_published'] ? '1' : '0' ?>">
              <button type="submit" class="badge <?= $room['is_published'] ? 'badge--green' : 'badge--red' ?>" style="border:none;cursor:pointer">
                <?= $room['is_published'] ? 'Live' : 'Hidden' ?>
              </button>
            </form>
          </td>
          <td style="white-space:nowrap">
            <a href="/admin/room-edit.php?id=<?= e($room['id']) ?>" class="btn-sm btn-outline">Edit</a>
            <a href="/<?= e($room['slug']) ?>" class="btn-sm btn-outline" target="_blank">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  const tbody = document.getElementById('roomsTbody');
  if (!tbody) return;

  let dragged = null;

  tbody.querySelectorAll('.draggable-row').forEach(row => {
    row.draggable = true;
    row.addEventListener('dragstart', () => { dragged = row; row.style.opacity = '.4'; });
    row.addEventListener('dragend',   () => { dragged = null; row.style.opacity = ''; saveOrder(); });
    row.addEventListener('dragover',  e => { e.preventDefault(); });
    row.addEventListener('dragenter', e => {
      e.preventDefault();
      if (dragged && dragged !== row) {
        const rows = [...tbody.querySelectorAll('.draggable-row')];
        const di = rows.indexOf(dragged), ri = rows.indexOf(row);
        tbody.insertBefore(dragged, di < ri ? row.nextSibling : row);
      }
    });
  });

  function saveOrder() {
    const ids = [...tbody.querySelectorAll('.draggable-row')].map(r => r.dataset.id);
    const fd = new FormData();
    fd.append('reorder', '1');
    fd.append('ids', JSON.stringify(ids));
    fetch('/admin/rooms.php', { method: 'POST', body: fd });
  }
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
