<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_owner();

$pg         = paginate_params();   // page / per (default 10) / q / offset / ajax
$filterPub  = $_GET['pub'] ?? '';  // '' all · '1' published · '0' draft

// ── WHERE: published filter + free-text search ───────────────────────
$params = [];
$conds  = [];
if ($filterPub === '1')      { $conds[] = 'v.is_published = TRUE'; }
elseif ($filterPub === '0')  { $conds[] = 'v.is_published = FALSE'; }
$sw = search_where(['v.name', 'v.slug', "COALESCE(v.location,'')"], $pg['q'], $params);
if ($sw !== '') $conds[] = $sw;
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$base  = "FROM venues v $where";
$total = (int) db_query("SELECT COUNT(*) $base", $params)->fetchColumn();
$meta  = paginate_meta($total, $pg['page'], $pg['per']);

$venues = db_query(
    "SELECT v.*,
           (SELECT count(*) FROM rooms r WHERE r.venue_id = v.id) AS room_count,
           (SELECT filename FROM venue_images vi WHERE vi.venue_id = v.id AND vi.is_hero = TRUE  LIMIT 1) AS hero_image,
           (SELECT filename FROM venue_images vi WHERE vi.venue_id = v.id ORDER BY sort_order LIMIT 1) AS first_image
     $base
     ORDER BY v.sort_order
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $params
)->fetchAll();

$filtered = ($pg['q'] !== '' || $filterPub !== '');

// ── Render the swappable body (grid + pager) once, reuse for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$venues): ?>
    <?php dt_empty($filtered ? 'No properties match your filters.' : 'No properties yet.', 'home'); ?>
    <?php else: ?>
    <div class="prop-grid">
      <?php foreach ($venues as $v):
        $imgFile = $v['hero_image'] ?? $v['first_image'] ?? null;
        $imgSrc  = $imgFile ? storage_url($imgFile) : null;
      ?>
      <div class="prop-card">
        <div class="prop-card__media">
          <?php if ($imgSrc): ?>
            <img class="prop-card__img" src="<?= e($imgSrc) ?>" alt="<?= e($v['name']) ?>">
          <?php else: ?>
            <div class="prop-card__img-placeholder">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            </div>
          <?php endif; ?>
          <div class="prop-card__actions">
            <a href="/admin/venue-edit.php?id=<?= (int)$v['id'] ?>" class="prop-card__action" title="Edit" aria-label="Edit"><?= admin_icon('edit', 16) ?></a>
            <a href="/<?= e($v['slug']) ?>" class="prop-card__action" title="View live" aria-label="View live" target="_blank" rel="noopener"><?= admin_icon('external-link', 16) ?></a>
          </div>
        </div>

        <div class="prop-card__body">
          <div class="prop-card__top">
            <div class="prop-card__name"><?= e($v['name']) ?></div>
            <?= $v['is_published']
                ? '<span class="badge badge--green">Published</span>'
                : '<span class="badge badge--grey">Draft</span>' ?>
          </div>

          <div class="prop-card__meta">
            <?php if (!empty($v['location'])): ?>
            <span style="display:flex;align-items:center;gap:4px">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <?= e($v['location']) ?>
            </span>
            <?php endif; ?>
            <span style="display:flex;align-items:center;gap:4px">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18V8h13a5 5 0 0 1 5 5v5M3 14h18"/><path d="M6 12h4a2 2 0 0 1 2 2"/></svg>
              <?= (int)$v['room_count'] ?> <?= (int)$v['room_count'] === 1 ? 'room' : 'rooms' ?>
            </span>
          </div>

          <div><span class="prop-card__slug"><?= e($v['slug']) ?></span></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

// AJAX fragment: emit only the swappable body and stop.
if ($pg['ajax']) { echo $dtBody; exit; }

$pageTitle  = 'Properties';
$activeMenu = 'venues';
include __DIR__ . '/_layout.php';
?>

<style>
.prop-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px;
  padding: 20px;
}
.prop-card {
  background: var(--white);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  border: 1px solid var(--border);
  transition: box-shadow .18s, transform .18s;
}
.prop-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.10); transform: translateY(-1px); }

/* P2: media wrapper hosts the hover/touch action overlay (top-right) */
.prop-card__media { position: relative; }
.prop-card__img {
  width: 100%; height: 160px; object-fit: cover; display: block;
  background: #f0ece6;
}
.prop-card__img-placeholder {
  width: 100%; height: 160px;
  display: flex; align-items: center; justify-content: center;
  background: #f0ece6; color: #b0a090; font-size: 13px;
}
.prop-card__actions {
  position: absolute; top: 8px; right: 8px;
  display: flex; gap: 6px;
  opacity: 0; transform: translateY(-3px);
  transition: opacity .16s ease, transform .16s ease;
}
.prop-card:hover .prop-card__actions,
.prop-card:focus-within .prop-card__actions { opacity: 1; transform: none; }
.prop-card__action {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 7px;
  background: rgba(255,255,255,.95); color: var(--brand);
  box-shadow: 0 2px 8px rgba(16,47,58,.22); text-decoration: none;
  transition: background .15s, color .15s;
}
.prop-card__action:hover { background: var(--brand); color: #fff; }
/* Touch devices can't hover — keep the actions visible */
@media (hover: none) {
  .prop-card__actions { opacity: 1; transform: none; }
}

.prop-card__body { padding: 16px 18px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
.prop-card__top  { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.prop-card__name { font-size: 15px; font-weight: 600; color: var(--text); }
.prop-card__meta { display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--muted); }
.prop-card__meta svg { opacity: .55; }
.prop-card__slug { font-family: monospace; font-size: 11.5px; background: #f4f0ea; color: var(--muted); padding: 2px 7px; border-radius: 4px; display: inline-block; }
</style>

<div class="page-header">
  <h1>Properties</h1>
  <a href="/admin/venue-edit.php" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> New Property</a>
</div>

<!-- Status filter + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/venues.php" class="filters">
      <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field">
        <span>Status</span>
        <select name="pub" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <option value="1" <?= $filterPub === '1' ? 'selected' : '' ?>>Published</option>
          <option value="0" <?= $filterPub === '0' ? 'selected' : '' ?>>Draft</option>
        </select>
      </div>
      <?php if ($filterPub !== ''): ?>
      <a href="/admin/venues.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, slug or location…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
