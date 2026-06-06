<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$venues = db_query("
    SELECT v.*,
           (SELECT count(*) FROM rooms r WHERE r.venue_id = v.id) AS room_count,
           (SELECT filename FROM venue_images vi WHERE vi.venue_id = v.id AND vi.is_hero = TRUE  LIMIT 1) AS hero_image,
           (SELECT filename FROM venue_images vi WHERE vi.venue_id = v.id ORDER BY sort_order LIMIT 1) AS first_image
    FROM venues v ORDER BY sort_order
")->fetchAll();

$pageTitle  = 'Properties';
$activeMenu = 'venues';
include __DIR__ . '/_layout.php';
?>

<style>
.prop-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px;
  margin-top: 4px;
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
.prop-card__img {
  width: 100%; height: 160px; object-fit: cover; display: block;
  background: #f0ece6;
}
.prop-card__img-placeholder {
  width: 100%; height: 160px;
  display: flex; align-items: center; justify-content: center;
  background: #f0ece6; color: #b0a090; font-size: 13px;
}
.prop-card__body { padding: 16px 18px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
.prop-card__top  { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.prop-card__name { font-size: 15px; font-weight: 600; color: var(--text); }
.prop-card__meta { display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--muted); }
.prop-card__meta svg { opacity: .55; }
.prop-card__slug { font-family: monospace; font-size: 11.5px; background: #f4f0ea; color: var(--muted); padding: 2px 7px; border-radius: 4px; display: inline-block; }
.prop-card__footer {
  padding: 12px 18px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 8px;
}
.prop-card__footer .btn-sm { flex: 1; text-align: center; justify-content: center; }
</style>

<div class="page-header">
  <h1>Properties</h1>
  <a href="/admin/venue-edit.php" class="btn-primary btn-sm">+ New Property</a>
</div>

<?php if (!$venues): ?>
<div class="card">
  <div class="card__body">
    <p style="padding:40px;text-align:center;color:var(--muted)">No properties found.</p>
  </div>
</div>
<?php else: ?>
<div class="prop-grid">
  <?php foreach ($venues as $v):
    $imgFile = $v['hero_image'] ?? $v['first_image'] ?? null;
    $imgSrc  = $imgFile ? storage_url($imgFile) : null;
  ?>
  <div class="prop-card">

    <?php if ($imgSrc): ?>
      <img class="prop-card__img" src="<?= e($imgSrc) ?>" alt="<?= e($v['name']) ?>">
    <?php else: ?>
      <div class="prop-card__img-placeholder">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      </div>
    <?php endif; ?>

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

    <div class="prop-card__footer">
      <a href="/admin/venue-edit.php?id=<?= (int)$v['id'] ?>" class="btn-sm btn-outline">Edit</a>
      <a href="/<?= e($v['slug']) ?>" class="btn-sm btn-outline" target="_blank" rel="noopener">View</a>
    </div>

  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
