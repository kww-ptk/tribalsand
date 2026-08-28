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

// Listing section shows at most the first 15 photos; the rest live on the full
// gallery page (gallery.php?venue=<slug>). The slice keeps 0-based indices intact,
// so tile i still addresses image i in the shared pgOpenLb lightbox (which holds
// the FULL ordered list) — never re-sort or filter here, only truncate the tail.
$__pgrid_total = count($__pgrid['images']);
$__pgrid_shown = array_slice($__pgrid['images'], 0, 15);
$__pgrid_more  = $__pgrid_total > 15;

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
<?php foreach ($__pgrid_shown as $__i => $__im): ?>
        <img src="<?= e($__im['url']) ?>" alt="<?= e($__im['alt']) ?>" onclick="pgOpenLb(<?= $__i ?>)" loading="lazy">
<?php endforeach; ?>
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge<?= $__pgrid_caption_extra ?></div>
<?php if ($__pgrid_more): ?>
      <div style="text-align:center;margin:.4rem 0 2rem;">
        <a href="gallery.php?venue=<?= e($pg_venue_slug) ?>" style="display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.6rem;border:1px solid var(--sand,#B8965A);color:var(--teal,#1E5C6B);font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;transition:background .2s,color .2s;" onmouseover="this.style.background='var(--teal,#1E5C6B)';this.style.color='#fff';" onmouseout="this.style.background='transparent';this.style.color='var(--teal,#1E5C6B)';">See all <?= $__pgrid_total ?> photos &rarr;</a>
      </div>
<?php endif; ?>
    </div>

    <div class="divider"></div>
