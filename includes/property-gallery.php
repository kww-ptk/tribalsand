<?php
/**
 * DB-driven property hero gallery + lightbox.
 * Usage: $pg_venue_slug = 'zuri'; include __DIR__ . '/includes/property-gallery.php';
 * Renders nothing if the venue has no images (page can keep a fallback gallery).
 * Optional: $pg_fallback (list of 'path.jpg' or ['src'=>…,'alt'=>…]) and
 * $pg_fallback_badge — used only when the venue has no images in the DB.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/property-gallery-data.php';

$pg_venue_slug = $pg_venue_slug ?? '';

$__pg      = pg_gallery(
    $pg_venue_slug,
    (!empty($pg_fallback) && is_array($pg_fallback)) ? $pg_fallback : [],
    $pg_fallback_badge ?? ''
);
$__badge   = $__pg['badge'];
$__gallery = $__pg['images'];

if (!$__gallery) { return; }

$__urls   = array_map(fn($g) => $g['url'], $__gallery);
$__count  = count($__gallery);
$__thumbs = array_slice($__gallery, 1, 2);
$__more   = max(0, $__count - 3);
?>
<div class="gallery<?= $__count === 1 ? ' pg-single' : ($__count === 2 ? ' pg-double' : '') ?>" style="margin-top:0;">
  <div class="gallery-main" onclick="pgOpenLb(0)">
    <img src="<?= e($__gallery[0]['url']) ?>" alt="<?= e($__gallery[0]['alt']) ?>" loading="eager">
    <?php if ($__badge): ?><div class="gallery-badge"><?= e($__badge) ?></div><?php endif; ?>
    <button type="button" class="gallery-viewall" onclick="event.stopPropagation();pgOpenLb(0)" aria-label="View all photos">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      View all <?= $__count ?> photos
    </button>
  </div>
  <?php foreach ($__thumbs as $ti => $t): $idx = $ti + 1; $isLast = ($idx === 2 && $__more > 0); ?>
  <div class="gallery-thumb<?= $isLast ? ' last' : '' ?>" onclick="pgOpenLb(<?= $idx ?>)">
    <img src="<?= e($t['url']) ?>" alt="<?= e($t['alt']) ?>">
  </div>
  <?php endforeach; ?>
</div>

<style>
.gallery.pg-single{grid-template-columns:1fr;grid-template-rows:1fr}
.gallery.pg-double{grid-template-rows:1fr}
</style>
<?php
// Lightbox markup + behaviour is shared with the restaurant pages; the tiles
// above call the pgOpenLb() it defines. Index i addresses image i in this list.
$lb_urls = $__urls;
include __DIR__ . '/photo-lightbox.php';
?>
