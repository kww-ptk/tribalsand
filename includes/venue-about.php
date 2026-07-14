<?php
/**
 * Editable "About the Property" section.
 * Renders venues.about_heading / about_body when set in the admin,
 * otherwise the page's built-in fallback text.
 *
 * Usage on a property page:
 *   $va_slug = 'my-amani';
 *   $va_heading_fallback = 'Best Beachfront Villa in <em>Vipingo, Kenya</em>';  // may contain <em>
 *   $va_body_fallback = "First paragraph.\n\nSecond paragraph.\n\nThird.";
 *   include __DIR__ . '/includes/venue-about.php';
 *
 * In the admin About-heading field, wrap a word in *asterisks* to italicise it.
 */
require_once __DIR__ . '/db.php';

$va_slug   = $va_slug ?? '';
$va_label  = $va_label ?? 'About the Property';

$__heading_db = ts_venue_text($va_slug, 'about_heading', '');
$__body_db    = ts_venue_text($va_slug, 'about_body', '');

$__heading = $__heading_db !== '' ? ts_emphasis(e($__heading_db)) : ($va_heading_fallback ?? '');
$__body    = $__body_db    !== '' ? $__body_db    : ($va_body_fallback ?? '');
?>
<div class="sec">
  <div class="sec-label"><?= e($va_label) ?></div>
  <h2 class="sec-h"><?= $__heading ?></h2>
  <div class="sec-rule"></div>
  <?php foreach (preg_split('/\n\s*\n/', trim((string)$__body)) as $__p): if (trim($__p) === '') continue; ?>
  <p class="sec-p"><?= nl2br(e(trim($__p))) ?></p>
  <?php endforeach; ?>
</div>
