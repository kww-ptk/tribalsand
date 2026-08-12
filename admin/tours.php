<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/services.php';        // is_priced(), format_price()
require_login();
require_owner();

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

$pg        = paginate_params();               // q / ajax (per is fixed below — reorderable list)
$catFilter = trim($_GET['category'] ?? '');

// ── WHERE: category filter + free-text search ────────────────────────
$params = [];
$conds  = [];
if ($catFilter !== '') { $conds[] = 't.category = :cat'; $params[':cat'] = $catFilter; }
$sw = search_where(['t.name', "COALESCE(t.category,'')", "COALESCE(t.duration,'')"], $pg['q'], $params);
if ($sw !== '') $conds[] = $sw;
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$base  = "FROM tours t $where";
$total = (int) db_query("SELECT COUNT(*) $base", $params)->fetchColumn();

// Drag variant: every row on one page so a reorder spans the whole list.
$meta  = paginate_meta($total, 1, 500);

$tours = db_query(
    "SELECT t.*,
        (SELECT filename FROM tour_images WHERE tour_id = t.id AND is_hero = TRUE LIMIT 1) AS hero_img,
        (SELECT string_agg(v.name, ', ' ORDER BY v.name)
           FROM tour_venues tv JOIN venues v ON v.id = tv.venue_id
          WHERE tv.tour_id = t.id) AS venue_names
     FROM tours t $where
     ORDER BY t.sort_order ASC
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $params
)->fetchAll();

$categories = db_query("SELECT DISTINCT category FROM tours WHERE category IS NOT NULL AND category <> '' ORDER BY category")
    ->fetchAll(PDO::FETCH_COLUMN);

// Reorder is only correct over the full, unfiltered list.
$reorderable = ($pg['q'] === '' && $catFilter === '');
$filtered    = !$reorderable;

// ── Render the swappable body (card + pager) once, reuse for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__head">
    <span class="card__title">All Tours</span>
    <?php if ($reorderable): ?>
    <span class="text-muted" style="font-size:12px;display:inline-flex;align-items:center;gap:6px"><?= admin_icon('grip', 14) ?> Drag rows to reorder</span>
    <?php else: ?>
    <span class="text-muted" style="font-size:12px">Clear filters to reorder</span>
    <?php endif; ?>
  </div>
  <div class="card__body" style="padding:0">
    <?php if (!$tours): ?>
    <?php dt_empty($filtered ? 'No tours match your filters.' : 'No tours yet.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <?php if ($reorderable): ?><th style="width:32px"></th><?php endif; ?>
            <th style="width:64px">Photo</th>
            <th>Name</th>
            <th>Category</th>
            <th>Price</th>
            <th>Offered at</th>
            <th>Published</th>
            <th style="width:1%;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody data-dt-drag data-reorder-url="/admin/tours.php" data-reorder-locked="<?= $reorderable ? '0' : '1' ?>">
          <?php foreach ($tours as $tour): ?>
          <tr data-id="<?= e($tour['id']) ?>" class="draggable-row">
            <?php if ($reorderable): ?>
            <td class="dt-grip" aria-label="Drag to reorder"><?= admin_icon('grip', 18) ?></td>
            <?php endif; ?>
            <td>
              <?php if ($tour['hero_img']): ?>
              <img src="<?= e(storage_url($tour['hero_img'])) ?>" class="room-thumb" alt="<?= e($tour['name']) ?>">
              <?php else: ?>
              <span class="thumb-empty" title="No photo" aria-label="No photo"><?= admin_icon('image', 18) ?></span>
              <?php endif; ?>
            </td>
            <td><strong><?= e($tour['name']) ?></strong></td>
            <td><span class="badge badge--blue"><?= e(ucfirst($tour['category'])) ?></span></td>
            <td>
              <?php if (is_priced($tour['price_amount'] ?? null)): ?>
                <?= e(format_price((float)$tour['price_amount'])) ?><?php if (!empty($tour['price_per_person']) && $tour['price_per_person'] !== 'f'): ?> <span class="text-muted" style="font-size:12px">/pp</span><?php endif; ?>
              <?php else: ?>
                <span class="badge badge--orange">no price</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= $tour['venue_names'] !== null ? e($tour['venue_names']) : 'All properties' ?></td>
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
            <td style="text-align:right">
              <span class="dt-actions">
                <a href="/admin/tour-edit.php?id=<?= e($tour['id']) ?>" class="btn-icon btn-icon--outline" title="Edit" aria-label="Edit"><?= admin_icon('edit') ?></a>
                <a href="/tour.php?slug=<?= e($tour['slug']) ?>" class="btn-icon btn-icon--outline" title="View live" aria-label="View live" target="_blank" rel="noopener"><?= admin_icon('external-link') ?></a>
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

$pageTitle  = 'Tours';
$activeMenu = 'tours';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Tours &amp; Excursions</h1>
  <a href="/admin/tour-edit.php" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add Tour</a>
</div>

<!-- Category filter + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/tours.php" class="filters">
      <input type="hidden" name="q" value="<?= e($pg['q']) ?>">
      <div class="filter-field">
        <span>Category</span>
        <select name="category" class="filter-select" aria-label="Filter by category" onchange="this.form.submit()">
          <option value="">All categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= e($c) ?>" <?= $catFilter === $c ? 'selected' : '' ?>><?= e(ucfirst($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($catFilter): ?>
      <a href="/admin/tours.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search tours…', 'per_page' => false]); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
