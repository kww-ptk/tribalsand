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

$pg          = paginate_params();              // q / ajax (per is fixed below — reorderable list)
$venues      = db_query("SELECT id, name FROM venues ORDER BY sort_order")->fetchAll();
$filterVenue = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;

// ── WHERE: venue filter + free-text search ───────────────────────────
$params = [];
$conds  = [];
if ($filterVenue) { $conds[] = 'r.venue_id = :vid'; $params[':vid'] = $filterVenue; }
$sw = search_where(['r.name', 'r.slug', "COALESCE(v.name,'')"], $pg['q'], $params);
if ($sw !== '') $conds[] = $sw;
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$base  = "FROM rooms r LEFT JOIN venues v ON v.id = r.venue_id $where";
$total = (int) db_query("SELECT COUNT(*) $base", $params)->fetchColumn();

// Drag variant: every row on one page so a reorder spans the whole list.
$meta  = paginate_meta($total, 1, 500);

$rooms = db_query(
    "SELECT r.*, v.name AS venue_name, v.slug AS venue_slug,
        (SELECT filename FROM room_images WHERE room_id = r.id AND is_hero = TRUE LIMIT 1) AS hero_img
     $base
     ORDER BY r.sort_order ASC
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $params
)->fetchAll();

// Reorder is only correct over the full, unfiltered list.
$reorderable = ($pg['q'] === '' && $filterVenue === 0);
$filtered    = !$reorderable;

// ── Render the swappable body (card + pager) once, reuse for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__head">
    <span class="card__title">All Rooms</span>
    <?php if ($reorderable): ?>
    <span class="text-muted" style="font-size:12px;display:inline-flex;align-items:center;gap:6px"><?= admin_icon('grip', 14) ?> Drag rows to reorder</span>
    <?php else: ?>
    <span class="text-muted" style="font-size:12px">Clear filters to reorder</span>
    <?php endif; ?>
  </div>
  <div class="card__body" style="padding:0">
    <?php if (!$rooms): ?>
    <?php dt_empty($filtered ? 'No rooms match your filters.' : 'No rooms yet.'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <?php if ($reorderable): ?><th style="width:32px"></th><?php endif; ?>
            <th style="width:64px">Photo</th>
            <th>Name</th>
            <th>Property</th>
            <th>Slug</th>
            <th>Price</th>
            <th>Published</th>
            <th style="width:1%;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody data-dt-drag data-reorder-url="/admin/rooms.php" data-reorder-locked="<?= $reorderable ? '0' : '1' ?>">
          <?php foreach ($rooms as $room): ?>
          <tr data-id="<?= e($room['id']) ?>" class="draggable-row">
            <?php if ($reorderable): ?>
            <td class="dt-grip" aria-label="Drag to reorder"><?= admin_icon('grip', 18) ?></td>
            <?php endif; ?>
            <td>
              <?php if ($room['hero_img']): ?>
              <img src="<?= e(storage_url($room['hero_img'])) ?>" class="room-thumb" alt="<?= e($room['name']) ?>">
              <?php else: ?>
              <span class="thumb-empty" title="No photo" aria-label="No photo"><?= admin_icon('image', 18) ?></span>
              <?php endif; ?>
            </td>
            <td><strong><?= e($room['name']) ?></strong></td>
            <td class="text-muted"><?= e($room['venue_name'] ?? '—') ?></td>
            <td class="text-muted"><?= e($room['slug']) ?></td>
            <td><?= e($room['price_currency']) ?> <?= e(number_format((float)$room['price_amount'], 0)) ?> <span class="text-muted"><?= e($room['price_unit']) ?></span></td>
            <td>
              <form method="POST" action="/admin/rooms.php" style="display:inline" data-shell-form>
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_publish" value="1">
                <input type="hidden" name="room_id" value="<?= e($room['id']) ?>">
                <input type="hidden" name="is_published" value="<?= $room['is_published'] ? '1' : '0' ?>">
                <button type="submit" class="badge <?= $room['is_published'] ? 'badge--green' : 'badge--red' ?>" style="border:none;cursor:pointer">
                  <?= $room['is_published'] ? 'Live' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td style="text-align:right">
              <span class="dt-actions">
                <a href="/admin/room-edit.php?id=<?= e($room['id']) ?>" class="btn-icon btn-icon--outline" title="Edit" aria-label="Edit"><?= admin_icon('edit') ?></a>
<?php // Rooms have no per-room page — they render on their venue's property page. Link there, anchored to the room card.
                    $roomLive = !empty($room['venue_slug']) ? '/' . rawurlencode($room['venue_slug']) . '#room-' . rawurlencode($room['slug']) : ''; ?>
                <?php if ($roomLive !== ''): ?>
                <a href="<?= e($roomLive) ?>" class="btn-icon btn-icon--outline" title="View live" aria-label="View live" target="_blank" rel="noopener"><?= admin_icon('external-link') ?></a>
                <?php else: ?>
                <span class="btn-icon btn-icon--outline" title="No live page (room has no property)" aria-label="No live page" style="opacity:.4;cursor:not-allowed"><?= admin_icon('external-link') ?></span>
                <?php endif; ?>
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

$pageTitle  = 'Rooms';
$activeMenu = 'rooms';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Rooms</h1>
  <a href="/admin/room-edit.php" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add Room</a>
</div>

<!-- Property filter + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/rooms.php" class="filters">
      <input type="hidden" name="q" value="<?= e($pg['q']) ?>">
      <div class="filter-field">
        <span>Property</span>
        <select name="venue" class="filter-select" aria-label="Filter by property" onchange="this.form.submit()">
          <option value="0">All properties</option>
          <?php foreach ($venues as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= $filterVenue === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($filterVenue): ?>
      <a href="/admin/rooms.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search rooms…', 'per_page' => false]); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
