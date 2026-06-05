<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

// Handle publish toggle via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish'])) {
    verify_csrf();
    $id  = (int)$_POST['property_id'];
    $val = $_POST['is_published'] === '1' ? 'FALSE' : 'TRUE';
    db_query("UPDATE properties SET is_published = {$val}, updated_at = NOW() WHERE id = :id", [':id' => $id]);
    header('Location: /admin/properties.php');
    exit;
}

// Handle sort order update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    header('Content-Type: application/json');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    foreach ($ids as $order => $id) {
        db_query('UPDATE properties SET sort_order = :order WHERE id = :id', [':order' => $order + 1, ':id' => (int)$id]);
    }
    exit(json_encode(['ok' => true]));
}

$pageTitle  = 'For Sale';
$activeMenu = 'properties';

$TYPE_LABELS = [
    'villa' => 'Villa', 'apartment' => 'Apartment', 'house' => 'House',
    'land' => 'Land', 'commercial' => 'Commercial',
];
$STATUS_LABELS = [
    'for_sale' => 'For Sale', 'under_offer' => 'Under Offer', 'sold' => 'Sold',
];
$STATUS_BADGE = [
    'for_sale' => 'badge--green', 'under_offer' => 'badge--orange', 'sold' => 'badge--grey',
];

$properties = db_query(
    "SELECT p.*,
        (SELECT filename FROM property_images WHERE property_id = p.id AND is_hero = TRUE LIMIT 1) AS hero_img
     FROM properties p ORDER BY p.sort_order ASC"
)->fetchAll();

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>For Sale</h1>
  <a href="/admin/property-edit.php" class="btn-primary btn-sm">+ Add Property</a>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">All Properties</span>
    <span class="text-muted" style="font-size:12px">Drag rows to reorder</span>
  </div>
  <div class="card__body">
    <table class="data-table" id="propsTable">
      <thead>
        <tr>
          <th style="width:32px"></th>
          <th style="width:60px">Photo</th>
          <th>Title</th>
          <th>Type</th>
          <th>Status</th>
          <th>Price</th>
          <th>Published</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="propsTbody">
        <?php foreach ($properties as $p): ?>
        <tr data-id="<?= e($p['id']) ?>" class="draggable-row">
          <td style="cursor:grab;color:var(--muted);font-size:18px;text-align:center">&#8942;&#8942;</td>
          <td>
            <?php if ($p['hero_img']): ?>
            <img src="<?= e(storage_url($p['hero_img'])) ?>" class="room-thumb" alt="<?= e($p['title']) ?>">
            <?php else: ?>
            <div style="width:52px;height:40px;background:var(--border);border-radius:4px"></div>
            <?php endif; ?>
          </td>
          <td><strong><?= e($p['title']) ?></strong><?php if ($p['location']): ?><br><span class="text-muted" style="font-size:12px"><?= e($p['location']) ?></span><?php endif; ?></td>
          <td class="text-muted"><?= e($TYPE_LABELS[$p['property_type']] ?? ucfirst((string)$p['property_type'])) ?></td>
          <td><span class="badge <?= e($STATUS_BADGE[$p['status']] ?? 'badge--grey') ?>"><?= e($STATUS_LABELS[$p['status']] ?? ucfirst((string)$p['status'])) ?></span></td>
          <td>
            <?php if ((float)$p['price_amount'] > 0): ?>
            <?= e($p['price_currency']) ?> <?= e(number_format((float)$p['price_amount'], 0)) ?>
            <?php else: ?>
            <span class="text-muted">On request</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" action="/admin/properties" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="toggle_publish" value="1">
              <input type="hidden" name="property_id" value="<?= e($p['id']) ?>">
              <input type="hidden" name="is_published" value="<?= $p['is_published'] ? '1' : '0' ?>">
              <button type="submit" class="badge <?= $p['is_published'] ? 'badge--green' : 'badge--red' ?>" style="border:none;cursor:pointer">
                <?= $p['is_published'] ? 'Live' : 'Hidden' ?>
              </button>
            </form>
          </td>
          <td style="white-space:nowrap">
            <a href="/admin/property-edit.php?id=<?= e($p['id']) ?>" class="btn-sm btn-outline">Edit</a>
            <a href="/property.php?slug=<?= e($p['slug']) ?>" class="btn-sm btn-outline" target="_blank">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  const tbody = document.getElementById('propsTbody');
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
    fetch('/admin/properties.php', { method: 'POST', body: fd });
  }
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
