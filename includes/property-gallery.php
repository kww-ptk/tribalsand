<?php
/**
 * DB-driven property hero gallery + lightbox.
 * Usage: $pg_venue_slug = 'zuri'; include __DIR__ . '/includes/property-gallery.php';
 * Renders nothing if the venue has no images (page can keep a fallback gallery).
 */
require_once __DIR__ . '/db.php';
$pg_venue_slug = $pg_venue_slug ?? '';
$__pgv = $pg_venue_slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $pg_venue_slug])->fetch() : false;
if (!$__pgv) { return; }
$__imgs = fetch_venue_images((int)$__pgv['id']);
if (!$__imgs) { return; }
$__badge  = trim($__pgv['name'] . (!empty($__pgv['location']) ? ' · ' . $__pgv['location'] : ''));
$__urls   = array_map(fn($im) => storage_url($im['filename']), $__imgs);
$__thumbs = array_slice($__imgs, 1, 2);
$__more   = max(0, count($__imgs) - 3);
?>
<div class="gallery<?= count($__imgs) === 1 ? ' pg-single' : (count($__imgs) === 2 ? ' pg-double' : '') ?>" style="margin-top:0;">
  <div class="gallery-main" onclick="pgOpenLb(0)">
    <img src="<?= e(storage_url($__imgs[0]['filename'])) ?>" alt="<?= e($__imgs[0]['alt_text'] ?: $__pgv['name']) ?>" loading="eager">
    <?php if ($__badge): ?><div class="gallery-badge"><?= e($__badge) ?></div><?php endif; ?>
  </div>
  <?php foreach ($__thumbs as $ti => $t): $idx = $ti + 1; $isLast = ($idx === 2 && $__more > 0); ?>
  <div class="gallery-thumb<?= $isLast ? ' last' : '' ?>" onclick="pgOpenLb(<?= $idx ?>)">
    <img src="<?= e(storage_url($t['filename'])) ?>" alt="<?= e($t['alt_text'] ?: $__pgv['name']) ?>">
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
