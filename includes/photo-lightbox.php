<?php
/**
 * Shared photo lightbox (#pgLb) + the window.pgOpenLb(i) opener.
 *
 * Usage — set the ordered list of image URLs, then include:
 *     $lb_urls = ['https://…/a.jpg', 'https://…/b.jpg'];
 *     include __DIR__ . '/photo-lightbox.php';
 *
 * Then open it from any tile: onclick="pgOpenLb(<index>)". Index i must address
 * image i in $lb_urls, so a caller may truncate the tail of its tile list but
 * must never re-sort or filter one side only.
 *
 * This was lifted out of property-gallery.php so a page that is not a property
 * page can reuse it — the restaurant pages had raw <a href="photo.jpg"> tiles,
 * which navigate to the image file instead of opening a gallery. There is
 * deliberately ONE lightbox in this codebase; per-page copies were removed
 * before and should not come back.
 *
 * Emits once per page: property pages include the hero gallery and the photo
 * grid, and both drive this same instance.
 */
if (!empty($GLOBALS['__pg_lb_done'])) { return; }
$GLOBALS['__pg_lb_done'] = true;

$lb_urls = (isset($lb_urls) && is_array($lb_urls)) ? array_values($lb_urls) : [];
if (!$lb_urls) { return; }
?>
<div class="pg-lb" id="pgLb" role="dialog" aria-label="Photo gallery" hidden>
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
</style>
<script>
(function () {
  var imgs = <?= json_encode($lb_urls, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
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
