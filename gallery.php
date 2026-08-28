<?php require_once 'includes/schema.php'; ?>
<?php
/**
 * Shared, DB-driven property gallery page.
 *
 *   gallery.php?venue=<slug>
 *
 * Reads the SAME venue_images table the listing hero (includes/property-gallery.php)
 * and the listing "Photo Gallery" section (includes/property-photo-grid.php) use, so
 * editing a property's photos in Admin → Venues → Gallery updates all three at once.
 *
 * The six legacy per-property pages (maya-kobe-gallery.php, zuri-gallery.php, …) are
 * now thin 301 redirects to this page — don't reintroduce hardcoded galleries.
 */
$slug  = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['venue'] ?? ''));
$venue = $slug
    ? db_query('SELECT * FROM venues WHERE slug = :s AND is_published = TRUE', [':s' => $slug])->fetch()
    : false;

// Unknown / unpublished venue → home, never a blank page.
if (!$venue) { header('Location: /'); exit; }

$rows   = fetch_venue_images((int) $venue['id']);
$images = [];
foreach ($rows as $r) {
    $images[] = [
        'url' => storage_url($r['filename']),
        'alt' => ($r['alt_text'] ?: $venue['name']),
    ];
}

// Every property listing page is named after its slug (maya-kobe.php, zuri.php, …).
$prop_url = $slug . '.php';
$hero_img = $images[0]['url'] ?? asset_url('images/hero-' . $slug . '.jpg');
$loc      = trim((string) ($venue['location'] ?? ''));

$page_title = $venue['name'] . ' Gallery · Tribal Sand';
$page_desc  = 'Browse the full photo gallery of ' . $venue['name']
            . ($loc ? ' · ' . $loc : '') . ' — Tribal Sand, Kenya\'s North Coast.';
$page_url   = 'https://tribalsand.com/gallery.php?venue=' . $slug;
$page_image = $hero_img;
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
    ['name' => $venue['name'],   'url' => 'https://tribalsand.com/' . $prop_url],
    ['name' => 'Gallery',        'url' => $page_url],
]);
require_once 'includes/head.php';
?>
<body>
<?php include 'includes/header.php'; ?>

<style>
.gal-hero{position:relative;height:45vh;min-height:300px;overflow:hidden;display:flex;align-items:flex-end;padding-bottom:3rem;}
.gal-hero-bg{position:absolute;inset:0;background-size:cover;background-position:center;}
.gal-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to top,rgba(16,47,58,.85) 0%,rgba(16,47,58,.35) 100%);}
.gal-hero-content{position:relative;z-index:1;padding:0 var(--px,5vw);}
.gal-eyebrow{font-size:.55rem;letter-spacing:.35em;text-transform:uppercase;color:rgba(184,150,90,.7);margin-bottom:.5rem;}
.gal-title{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,5vw,3.2rem);font-weight:300;color:#fff;line-height:1.05;}
.gal-title em{font-style:italic;color:var(--sand-lt,#D4B07A);}
.gal-count{margin-top:.5rem;font-size:.7rem;letter-spacing:.18em;text-transform:uppercase;color:rgba(212,196,172,.7);}
.gal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;padding:3px;}
.gal-item{position:relative;overflow:hidden;aspect-ratio:4/3;cursor:pointer;background:rgba(16,47,58,.06);}
.gal-item img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease;}
.gal-item:hover img{transform:scale(1.05);}
.gal-item-overlay{position:absolute;inset:0;background:rgba(16,47,58,0);transition:background .3s;display:flex;align-items:center;justify-content:center;}
.gal-item:hover .gal-item-overlay{background:rgba(16,47,58,.28);}
.gal-empty{padding:5rem var(--px,5vw);text-align:center;color:var(--mid,#6B6050);font-size:.9rem;}
.gal-back{display:inline-flex;align-items:center;gap:.5rem;font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--teal,#1E5C6B);text-decoration:none;padding:2rem var(--px,5vw);}
@media(max-width:640px){.gal-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:400px){.gal-grid{grid-template-columns:1fr;}}
/* Lightbox */
.gal-lb{position:fixed;inset:0;z-index:9999;background:rgba(20,20,18,.92);display:flex;align-items:center;justify-content:center;}
.gal-lb[hidden]{display:none;}
.gal-lb__stage{margin:0;max-width:90vw;max-height:86vh;}
.gal-lb__stage img{max-width:90vw;max-height:86vh;object-fit:contain;display:block;}
.gal-lb__close{position:absolute;top:18px;right:24px;background:none;border:none;color:#fff;font-size:2.4rem;line-height:1;cursor:pointer;}
.gal-lb__nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.12);border:none;color:#fff;font-size:2rem;width:52px;height:52px;border-radius:50%;cursor:pointer;}
.gal-lb__prev{left:24px;}.gal-lb__next{right:24px;}
.gal-lb__count{position:absolute;bottom:22px;left:50%;transform:translateX(-50%);color:#fff;font-size:.85rem;letter-spacing:.1em;}
</style>

<!-- Hero -->
<section class="gal-hero">
  <div class="gal-hero-bg" style="background-image:url('<?= e($hero_img) ?>');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title"><?= e($venue['name']) ?> · <em>Gallery</em></h1>
    <?php if ($images): ?><div class="gal-count"><?= count($images) ?> Photos</div><?php endif; ?>
  </div>
</section>

<?php if ($images): ?>
<!-- Grid -->
<div class="gal-grid">
<?php foreach ($images as $i => $im): ?>
  <div class="gal-item" onclick="galOpenLb(<?= $i ?>)">
    <img src="<?= e($im['url']) ?>" alt="<?= e($im['alt']) ?>" loading="lazy">
    <div class="gal-item-overlay"></div>
  </div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="gal-empty">No photos have been added for <?= e($venue['name']) ?> yet.</div>
<?php endif; ?>

<!-- Back Link -->
<a class="gal-back" href="<?= e($prop_url) ?>">&larr; Back to <?= e($venue['name']) ?></a>

<?php if ($images): ?>
<!-- Lightbox -->
<div class="gal-lb" id="galLb" role="dialog" aria-label="Photo gallery" hidden>
  <button class="gal-lb__close" type="button" data-gal-close aria-label="Close">&times;</button>
  <button class="gal-lb__nav gal-lb__prev" type="button" data-gal-prev aria-label="Previous">&#8249;</button>
  <figure class="gal-lb__stage"><img id="galLbImg" alt=""></figure>
  <button class="gal-lb__nav gal-lb__next" type="button" data-gal-next aria-label="Next">&#8250;</button>
  <span class="gal-lb__count" id="galLbCount"></span>
</div>
<script>
(function () {
  var imgs = <?= json_encode(array_map(fn($g) => $g['url'], $images), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
  var lb = document.getElementById('galLb'), img = document.getElementById('galLbImg'), cnt = document.getElementById('galLbCount'), i = 0;
  window.galOpenLb = function (n) { i = (n + imgs.length) % imgs.length; render(); lb.hidden = false; document.body.style.overflow = 'hidden'; };
  function render() { img.src = imgs[i]; cnt.textContent = (i + 1) + ' / ' + imgs.length; }
  function close() { lb.hidden = true; document.body.style.overflow = ''; }
  function nav(d) { i = (i + d + imgs.length) % imgs.length; render(); }
  lb.querySelector('[data-gal-close]').addEventListener('click', close);
  lb.querySelector('[data-gal-prev]').addEventListener('click', function (e) { e.stopPropagation(); nav(-1); });
  lb.querySelector('[data-gal-next]').addEventListener('click', function (e) { e.stopPropagation(); nav(1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
  document.addEventListener('keydown', function (e) { if (lb.hidden) return; if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') nav(-1); else if (e.key === 'ArrowRight') nav(1); });
})();
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
</body>
