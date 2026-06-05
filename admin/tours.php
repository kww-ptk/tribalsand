<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish'])) {
    verify_csrf();
    $id  = (int)$_POST['tour_id'];
    $val = $_POST['is_published'] === '1' ? 'FALSE' : 'TRUE';
    db_query("UPDATE tours SET is_published = {$val}, updated_at = NOW() WHERE id = :id", [':id' => $id]);
    header('Location: /admin/tours.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    header('Content-Type: application/json');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    foreach ($ids as $order => $id) {
        db_query('UPDATE tours SET sort_order = :order WHERE id = :id', [':order' => $order + 1, ':id' => (int)$id]);
    }
    exit(json_encode(['ok' => true]));
}

$pageTitle  = 'Tours';
$activeMenu = 'tours';

$tours = db_query(
    "SELECT t.*,
        (SELECT filename FROM tour_images WHERE tour_id = t.id AND is_hero = TRUE LIMIT 1) AS hero_img
     FROM tours t ORDER BY t.sort_order ASC"
)->fetchAll();

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Tours &amp; Excursions</h1>
  <a href="/admin/tour-edit.php" class="btn-primary btn-sm">+ Add Tour</a>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">All Tours</span>
    <span class="text-muted" style="font-size:12px">Drag rows to reorder</span>
  </div>
  <div class="card__body">
    <table class="data-table" id="toursTable">
      <thead>
        <tr>
          <th style="width:32px"></th>
          <th style="width:60px">Photo</th>
          <th>Name</th>
          <th>Category</th>
          <th>Duration</th>
          <th>Published</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="toursTbody">
        <?php foreach ($tours as $tour): ?>
        <tr data-id="<?= e($tour['id']) ?>" class="draggable-row">
          <td style="cursor:grab;color:var(--muted);font-size:18px;text-align:center">&#8942;&#8942;</td>
          <td>
            <?php if ($tour['hero_img']): ?>
            <img src="<?= e(storage_url($tour['hero_img'])) ?>" class="room-thumb" alt="<?= e($tour['name']) ?>">
            <?php else: ?>
            <div style="width:52px;height:40px;background:var(--border);border-radius:4px"></div>
            <?php endif; ?>
          </td>
          <td><strong><?= e($tour['name']) ?></strong></td>
          <td><span class="badge badge--blue"><?= e(ucfirst($tour['category'])) ?></span></td>
          <td class="text-muted"><?= e($tour['duration'] ?: '—') ?></td>
          <td>
            <form method="POST" action="/admin/tours.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="toggle_publish" value="1">
              <input type="hidden" name="tour_id" value="<?= e($tour['id']) ?>">
              <input type="hidden" name="is_published" value="<?= $tour['is_published'] ? '1' : '0' ?>">
              <button type="submit" class="badge <?= $tour['is_published'] ? 'badge--green' : 'badge--red' ?>" style="border:none;cursor:pointer">
                <?= $tour['is_published'] ? 'Live' : 'Hidden' ?>
              </button>
            </form>
          </td>
          <td style="white-space:nowrap">
            <a href="/admin/tour-edit.php?id=<?= e($tour['id']) ?>" class="btn-sm btn-outline">Edit</a>
            <a href="/tour.php?slug=<?= e($tour['slug']) ?>" class="btn-sm btn-outline" target="_blank">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$tours): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No tours yet. <a href="/admin/tour-edit.php">Add your first tour</a>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  const tbody = document.getElementById('toursTbody');
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
    fetch('/admin/tours.php', { method: 'POST', body: fd });
  }
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
