<?php
/**
 * DB-driven bottom "Photo Gallery" section for a property page.
 *
 * Include AFTER includes/property-gallery.php — that include defines the
 * pgOpenLb() lightbox these tiles open, and both read the same ordered list,
 * so tile index i addresses image i.
 *
 * Usage:
 *   $pgrid_heading       = 'Explore <em>My Amani</em>';
 *   $pgrid_caption_extra = '';                       // optional
 *   include __DIR__ . '/includes/property-photo-grid.php';
 *
 * Renders nothing when the venue has no images in the DB. There is deliberately
 * no static fallback: the section disappears rather than showing stale photos.
 */
require_once __DIR__ . '/property-gallery-data.php';

$pg_venue_slug       = $pg_venue_slug ?? '';
$pgrid_heading       = $pgrid_heading ?? '';
$pgrid_caption_extra = $pgrid_caption_extra ?? '';

$__pgrid = pg_gallery($pg_venue_slug);
if (!$__pgrid['images']) { return; }

// Default heading: the venue name alone, from "Name · Location".
if ($pgrid_heading === '') {
    $pgrid_heading = e(trim(explode('·', $__pgrid['badge'])[0]));
}
?>
    <!-- Photo Gallery -->
    <div class="sec">
      <div class="sec-label">Photo Gallery</div>
      <h2 class="sec-h"><?= $pgrid_heading ?></h2>
      <div class="sec-rule"></div>
      <div class="photo-grid">
<?php foreach ($__pgrid['images'] as $i => $im): ?>
        <img src="<?= e($im['url']) ?>" alt="<?= e($im['alt']) ?>" onclick="pgOpenLb(<?= $i ?>)" loading="lazy">
<?php endforeach; ?>
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge<?= $pgrid_caption_extra ?></div>
    </div>

    <div class="divider"></div>
