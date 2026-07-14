<?php
/**
 * DB-driven property hero gallery + lightbox.
 * Usage: $pg_venue_slug = 'zuri'; include __DIR__ . '/includes/property-gallery.php';
 * Renders nothing if the venue has no images (page can keep a fallback gallery).
 */
require_once __DIR__ . '/db.php';
$pg_venue_slug = $pg_venue_slug ?? '';

// ── Gather images from the DB (primary) ───────────────────────────────
$__pgv  = false;
$__imgs = [];
try {
    $__pgv  = $pg_venue_slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $pg_venue_slug])->fetch() : false;
    $__imgs = $__pgv ? fetch_venue_images((int)$__pgv['id']) : [];
} catch (Throwable $e) {
    $__pgv = false; $__imgs = [];
}

// ── Build a unified [url, alt] list — DB first, else static fallback ───
$__gallery = [];
$__badge   = '';
if ($__imgs) {
    $__badge = trim(($__pgv['name'] ?? '') . (!empty($__pgv['location']) ? ' · ' . $__pgv['location'] : ''));
    foreach ($__imgs as $im) {
        $__gallery[] = ['url' => storage_url($im['filename']), 'alt' => ($im['alt_text'] ?: ($__pgv['name'] ?? ''))];
    }
} elseif (!empty($pg_fallback) && is_array($pg_fallback)) {
    // Static fallback so the gallery hero renders without the DB (Render-safe)
    $__badge = $pg_fallback_badge ?? '';
    foreach ($pg_fallback as $fb) {
        if (is_string($fb))      $__gallery[] = ['url' => $fb, 'alt' => ($pg_fallback_badge ?? 'Property photo')];
        elseif (is_array($fb))   $__gallery[] = ['url' => $fb['src'] ?? ($fb['url'] ?? ''), 'alt' => $fb['alt'] ?? ($pg_fallback_badge ?? 'Property photo')];
    }
    $__gallery = array_values(array_filter($__gallery, fn($g) => $g['url'] !== ''));
}

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

<div class="pg-lb" id="pgLb" hidden>
  <button class="pg-lb__close" type="button" data-pg-close aria-label="Close">&times;</button>
  <button class="pg-lb__nav pg-lb__prev" type="button" data-pg-prev aria-label="Previous">&#8249;</button>
  <figure class="pg-lb__stage"><img id="pgLbImg" alt=""></figure>
  <button class="pg-lb__nav pg-lb__next" type="button" data-pg-next aria-label="Next">&#8250;</button>
  <span class="pg-lb__count" id="pgLbCount"></span>
</div>
<style>
.pg-lb{position:fixed;inset:0;z-index:9999;background:rgba(20,20,18,.92);display:flex;align-items:center;justify-content:center}
.pg-lb[hidden]{display:none}
.pg-lb__stage{margin:0;max-width:90vw;max-height:86vh}
.pg-lb__stage img{max-width:90vw;max-height:86vh;object-fit:contain;display:block}
.pg-lb__close{position:absolute;top:18px;right:24px;background:none;border:none;color:#fff;font-size:2.4rem;line-height:1;cursor:pointer}
.pg-lb__nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.12);border:none;color:#fff;font-size:2rem;width:52px;height:52px;border-radius:50%;cursor:pointer}
.pg-lb__prev{left:24px}.pg-lb__next{right:24px}
.pg-lb__count{position:absolute;bottom:22px;left:50%;transform:translateX(-50%);color:#fff;font-size:.85rem;letter-spacing:.1em}
.gallery.pg-single{grid-template-columns:1fr;grid-template-rows:1fr}
.gallery.pg-double{grid-template-rows:1fr}
</style>
<script>
(function () {
  var imgs = <?= json_encode($__urls, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
  var lb = document.getElementById('pgLb'), img = document.getElementById('pgLbImg'), cnt = document.getElementById('pgLbCount'), i = 0;
  window.pgOpenLb = function (n) { i = (n + imgs.length) % imgs.length; render(); lb.hidden = false; document.body.style.overflow = 'hidden'; };
  function render() { img.src = imgs[i]; cnt.textContent = (i + 1) + ' / ' + imgs.length; }
  function close() { lb.hidden = true; document.body.style.overflow = ''; }
  function nav(d) { i = (i + d + imgs.length) % imgs.length; render(); }
  lb.querySelector('[data-pg-close]').addEventListener('click', close);
  lb.querySelector('[data-pg-prev]').addEventListener('click', function (e) { e.stopPropagation(); nav(-1); });
  lb.querySelector('[data-pg-next]').addEventListener('click', function (e) { e.stopPropagation(); nav(1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
  document.addEventListener('keydown', function (e) { if (lb.hidden) return; if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') nav(-1); else if (e.key === 'ArrowRight') nav(1); });
})();
</script>
