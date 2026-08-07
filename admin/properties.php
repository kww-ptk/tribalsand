<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_owner();

// Handle publish toggle via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish'])) {
    verify_csrf();
    $id  = (int)$_POST['property_id'];
    $val = $_POST['is_published'] === '1' ? 'FALSE' : 'TRUE';
    db_query("UPDATE properties SET is_published = {$val}, updated_at = NOW() WHERE id = :id", [':id' => $id]);
    header('Location: /admin/properties.php');
    exit;
}

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

$pg           = paginate_params();   // page / per (default 10) / q / offset / ajax
$filterType   = trim($_GET['ptype'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

// ── WHERE: type + status filters + free-text search ──────────────────
$params = [];
$conds  = [];
if ($filterType   !== '' && isset($TYPE_LABELS[$filterType]))     { $conds[] = 'p.property_type = :pt'; $params[':pt'] = $filterType; }
if ($filterStatus !== '' && isset($STATUS_LABELS[$filterStatus])) { $conds[] = 'p.status = :st';        $params[':st'] = $filterStatus; }
$sw = search_where(['p.title', "COALESCE(p.location,'')", 'p.slug'], $pg['q'], $params);
if ($sw !== '') $conds[] = $sw;
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$base  = "FROM properties p $where";
$total = (int) db_query("SELECT COUNT(*) $base", $params)->fetchColumn();
$meta  = paginate_meta($total, $pg['page'], $pg['per']);

$properties = db_query(
    "SELECT p.*,
        (SELECT filename FROM property_images WHERE property_id = p.id AND is_hero = TRUE LIMIT 1) AS hero_img
     FROM properties p $where
     ORDER BY p.sort_order ASC
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $params
)->fetchAll();

$filtered = ($pg['q'] !== '' || $filterType !== '' || $filterStatus !== '');

// ── Render the swappable body (card + pager) once, reuse for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$properties): ?>
    <?php dt_empty($filtered ? 'No listings match your filters.' : 'No listings yet.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:64px">Photo</th>
            <th>Title</th>
            <th>Type</th>
            <th>Status</th>
            <th>Price</th>
            <th>Published</th>
            <th style="width:1%;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($properties as $p): ?>
          <tr>
            <td>
              <?php if ($p['hero_img']): ?>
              <img src="<?= e(storage_url($p['hero_img'])) ?>" class="room-thumb" alt="<?= e($p['title']) ?>">
              <?php else: ?>
              <span class="thumb-empty" title="No photo" aria-label="No photo"><?= admin_icon('image', 18) ?></span>
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
              <form method="POST" action="/admin/properties.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_publish" value="1">
                <input type="hidden" name="property_id" value="<?= e($p['id']) ?>">
                <input type="hidden" name="is_published" value="<?= $p['is_published'] ? '1' : '0' ?>">
                <button type="submit" class="badge <?= $p['is_published'] ? 'badge--green' : 'badge--red' ?>" style="border:none;cursor:pointer">
                  <?= $p['is_published'] ? 'Live' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td style="text-align:right">
              <span class="dt-actions">
                <a href="/admin/property-edit.php?id=<?= e($p['id']) ?>" class="btn-icon btn-icon--outline" title="Edit" aria-label="Edit"><?= admin_icon('edit') ?></a>
                <a href="/property.php?slug=<?= e($p['slug']) ?>" class="btn-icon btn-icon--outline" title="View live" aria-label="View live" target="_blank" rel="noopener"><?= admin_icon('external-link') ?></a>
              </span>
            </td>
          </tr>
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

$pageTitle  = 'For Sale';
$activeMenu = 'properties';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>For Sale</h1>
  <a href="/admin/property-edit.php" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add Property</a>
</div>

<!-- Type + status filters + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/properties.php" class="filters">
      <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field">
        <span>Type</span>
        <select name="ptype" class="filter-select" aria-label="Filter by type" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach ($TYPE_LABELS as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <span>Status</span>
        <select name="status" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach ($STATUS_LABELS as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($filterType || $filterStatus): ?>
      <a href="/admin/properties.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search title, location or slug…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
