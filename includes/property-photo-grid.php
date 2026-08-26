<?php
/**
 * DB-driven bottom "Photo Gallery" section for a property page.
 *
 * Include AFTER includes/property-gallery.php — that include defines the
 * pgOpenLb() lightbox these tiles open, and both read the same ordered list,
 * so tile index i addresses image i.
 *
 * Usage:
 *   $pg_venue_slug       = 'my-amani';               // required (already set by the hero include)
 *   $pgrid_heading       = 'Explore <em>My Amani</em>';
 *   $pgrid_caption_extra = '';                       // optional
 *   include __DIR__ . '/includes/property-photo-grid.php';
 *
 * $pgrid_heading and $pgrid_caption_extra are both raw page-authored HTML and
 * are echoed unescaped — never pass user- or DB-sourced text through either.
 *
 * Renders nothing when the venue has no images in the DB. There is deliberately
 * no static fallback: the section disappears rather than showing stale photos.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/property-gallery-data.php';

$pg_venue_slug         = $pg_venue_slug ?? '';
$__pgrid_heading       = $pgrid_heading ?? '';
$__pgrid_caption_extra = $pgrid_caption_extra ?? '';
unset($pgrid_heading, $pgrid_caption_extra); // read once above; cleared immediately so a second include on the same page can't inherit them

$__pgrid = pg_gallery($pg_venue_slug);
if (!$__pgrid['images']) { return; }

// $__pgrid_heading is either trusted page-authored HTML, or (when unset) the
// venue name alone from "Name · Location" — escaped here, once, so the raw
// echo below always means "already-safe HTML," never "escape me first."
$__pgrid_h = $__pgrid_heading !== ''
    ? $__pgrid_heading
    : e(trim(explode('·', $__pgrid['badge'])[0]));
?>
    <!-- Photo Gallery -->
    <div class="sec">
      <div class="sec-label">Photo Gallery</div>
<?php if ($__pgrid_h !== ''): ?>
      <h2 class="sec-h"><?= $__pgrid_h ?></h2>
<?php endif; ?>
      <div class="sec-rule"></div>
      <div class="photo-grid">
<?php foreach ($__pgrid['images'] as $__i => $__im): ?>
        <img src="<?= e($__im['url']) ?>" alt="<?= e($__im['alt']) ?>" onclick="pgOpenLb(<?= $__i ?>)" loading="lazy">
<?php endforeach; ?>
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge<?= $__pgrid_caption_extra ?></div>
    </div>

    <div class="divider"></div>
