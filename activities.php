<?php
require_once 'includes/schema.php';
require_once 'includes/db.php';

/* ── SEO ── */
$page_title  = 'Activities & Experiences · Kenya Coast · Tribal Sand';
$page_desc   = 'Things to do on Kenya\'s North Coast — snorkelling & diving in Watamu Marine Park, kitesurfing Kilifi, deep sea fishing, dhow cruises, safaris, golf and wellness. Arranged by your concierge.';
$page_url    = 'https://tribalsand.com/activities.php';
$page_image  = 'https://tribalsand.com/images/hero-maya-kobe.jpg';
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',       'url' => 'https://tribalsand.com/'],
    ['name' => 'Activities', 'url' => 'https://tribalsand.com/activities.php'],
]);

/* ── Activity categories (order + labels + look) ── */
$CATS = [
  'marine'      => ['label' => 'Marine & Ocean',    'icon' => '🌊', 'grad' => 'linear-gradient(135deg,#1E5C6B,#0d2b33)'],
  'watersports' => ['label' => 'Watersports & Kite','icon' => '🪁', 'grad' => 'linear-gradient(135deg,#2D7A8C,#12333a)'],
  'nature'      => ['label' => 'Nature & Culture',  'icon' => '🌿', 'grad' => 'linear-gradient(135deg,#4a6b3a,#1d2b16)'],
  'wellness'    => ['label' => 'Wellness',          'icon' => '🧘', 'grad' => 'linear-gradient(135deg,#8a6d3b,#3a2c14)'],
  'golf'        => ['label' => 'Golf',              'icon' => '⛳', 'grad' => 'linear-gradient(135deg,#5a7d52,#233318)'],
];

/* ── Load activities from the DB (managed in admin → Tours) ── */
try {
    $__acts = db_query(
        "SELECT t.*, (SELECT filename FROM tour_images WHERE tour_id = t.id AND is_hero = TRUE LIMIT 1) AS hero
         FROM tours t
         WHERE t.is_published = TRUE AND t.category IN ('marine','watersports','nature','wellness','golf')
         ORDER BY t.sort_order ASC"
    )->fetchAll();
} catch (Throwable $e) {
    $__acts = [];
}
$grouped = [];
foreach ($__acts as $a) { $grouped[$a['category']][] = $a; }

include 'includes/head.php';
include 'includes/header.php';
?>
<style>
:root{--sand:#B8965A;--sand-lt:#D4B07A;--sand-faint:#FAF6EE;--teal:#1E5C6B;--teal-d:#102F3A;--dark:#141412;--off:#FAF8F4;--white:#fff;--mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.16);}
.act{background:var(--off);}
.act-hero{background:var(--teal-d);padding:120px 5vw 44px;text-align:center;}
.act-hero__eyebrow{font-size:.62rem;letter-spacing:.28em;text-transform:uppercase;color:var(--sand-lt);margin-bottom:.7rem;}
.act-hero__title{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:clamp(2.2rem,5vw,3.4rem);color:#fff;margin:0 0 .8rem;}
.act-hero__sub{color:rgba(255,255,255,.7);max-width:640px;margin:0 auto;font-size:1rem;line-height:1.7;}
/* filter chips */
.act-filters{position:sticky;top:0;z-index:20;background:rgba(250,248,244,.96);backdrop-filter:blur(8px);border-bottom:1px solid var(--border);padding:.9rem 5vw;display:flex;gap:.5rem;flex-wrap:wrap;justify-content:center;}
.act-chip{font-family:'Jost',sans-serif;font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;font-weight:600;color:var(--mid);background:none;border:1px solid var(--border);border-radius:40px;padding:.5rem 1.1rem;cursor:pointer;transition:all .2s;}
.act-chip:hover{border-color:var(--sand);color:var(--teal);}
.act-chip.is-active{background:var(--teal-d);border-color:var(--teal-d);color:#fff;}
/* sections */
.act-wrap{max-width:1180px;margin:0 auto;padding:2.6rem 5vw 5rem;}
.act-group{margin-bottom:3rem;}
.act-group__head{display:flex;align-items:baseline;gap:.8rem;margin-bottom:1.3rem;}
.act-group__title{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.7rem;color:var(--dark);}
.act-group__count{font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);}
.act-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.2rem;}
.act-card{display:flex;flex-direction:column;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;transition:box-shadow .25s,transform .25s;}
.act-card:hover{box-shadow:0 12px 34px rgba(10,30,40,.1);transform:translateY(-2px);}
.act-card__img{position:relative;height:170px;display:flex;align-items:center;justify-content:center;}
.act-card__img img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
.act-card__icon{font-size:2.6rem;opacity:.9;filter:drop-shadow(0 2px 6px rgba(0,0,0,.25));}
.act-card__tag{position:absolute;top:.7rem;left:.7rem;font-size:.52rem;letter-spacing:.16em;text-transform:uppercase;color:#fff;background:rgba(16,47,58,.72);backdrop-filter:blur(6px);padding:.28rem .6rem;border-radius:4px;}
.act-card__body{padding:1.1rem 1.2rem 1.3rem;display:flex;flex-direction:column;flex:1;}
.act-card__name{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:1.22rem;line-height:1.2;color:var(--dark);margin-bottom:.4rem;}
.act-card__desc{font-size:.9rem;line-height:1.65;color:var(--mid);margin-bottom:.9rem;flex:1;}
.act-card__meta{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;font-size:.72rem;color:var(--light);margin-bottom:1rem;}
.act-card__dur{display:inline-flex;align-items:center;gap:.3rem;}
.act-card__price{margin-left:auto;font-family:'Cormorant Garamond',serif;font-size:1.05rem;color:var(--teal);font-weight:500;}
.act-card__cta{display:block;text-align:center;background:var(--sand);color:var(--teal-d);border:none;border-radius:5px;padding:.7rem;font-family:'Jost',sans-serif;font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;text-decoration:none;transition:background .2s;}
.act-card__cta:hover{background:var(--sand-lt);}
.act-empty{text-align:center;color:var(--light);padding:3rem 1rem;}
.act-cta-band{background:var(--sand-faint);border:1px solid var(--border);border-radius:10px;padding:2rem;text-align:center;margin-top:1rem;}
.act-cta-band h3{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.5rem;color:var(--dark);margin:0 0 .4rem;}
.act-cta-band p{color:var(--mid);margin:0 0 1.2rem;}
.act-cta-band a{display:inline-block;background:var(--teal-d);color:#fff;text-decoration:none;padding:.8rem 1.8rem;border-radius:5px;font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;}
@media(max-width:600px){.act-hero{padding-top:96px;}}
</style>

<div class="act">
  <div class="act-hero">
    <div class="act-hero__eyebrow">Tribal Sand · Kenya's North Coast</div>
    <h1 class="act-hero__title">Activities &amp; Experiences</h1>
    <p class="act-hero__sub">Ocean adventures, cultural encounters and coastal wellness — every experience arranged and guided by your Tribal Sand concierge.</p>
  </div>

  <?php if ($__acts): ?>
  <div class="act-filters">
    <button type="button" class="act-chip is-active" data-filter="all">All</button>
    <?php foreach ($CATS as $key => $c): if (empty($grouped[$key])) continue; ?>
    <button type="button" class="act-chip" data-filter="<?= e($key) ?>"><?= e($c['label']) ?></button>
    <?php endforeach; ?>
  </div>

  <div class="act-wrap">
    <?php foreach ($CATS as $key => $c): if (empty($grouped[$key])) continue; ?>
    <section class="act-group" data-cat="<?= e($key) ?>">
      <div class="act-group__head">
        <h2 class="act-group__title"><?= e($c['label']) ?></h2>
        <span class="act-group__count"><?= count($grouped[$key]) ?> experience<?= count($grouped[$key]) !== 1 ? 's' : '' ?></span>
      </div>
      <div class="act-grid">
        <?php foreach ($grouped[$key] as $a):
          $img = !empty($a['hero']) ? storage_url($a['hero']) : '';
          $wa  = 'https://wa.me/254115115247?text=' . rawurlencode("Hi Tribal Sand, I'd like to enquire about: " . $a['name']);
        ?>
        <article class="act-card">
          <div class="act-card__img" style="background:<?= e($c['grad']) ?>">
            <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($a['name']) ?>" loading="lazy"><?php else: ?><span class="act-card__icon"><?= $c['icon'] ?></span><?php endif; ?>
            <?php if (!empty($a['tag_label'])): ?><span class="act-card__tag"><?= e($a['tag_label']) ?></span><?php endif; ?>
          </div>
          <div class="act-card__body">
            <h3 class="act-card__name"><?= e($a['name']) ?></h3>
            <p class="act-card__desc"><?= e($a['short_desc']) ?></p>
            <div class="act-card__meta">
              <?php if (!empty($a['duration'])): ?><span class="act-card__dur">⏱ <?= e($a['duration']) ?></span><?php endif; ?>
              <?php if (!empty($a['price'])): ?><span class="act-card__price"><?= e($a['price']) ?></span><?php endif; ?>
            </div>
            <a class="act-card__cta" href="<?= e($wa) ?>" target="_blank" rel="noopener">Enquire →</a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>

    <div class="act-cta-band">
      <h3>Not sure what to book?</h3>
      <p>Tell us your dates and interests — we'll build the perfect coastal itinerary around your stay.</p>
      <a href="/trip-builder.php">Build My Itinerary →</a>
    </div>
  </div>
  <?php else: ?>
  <div class="act-wrap"><div class="act-empty">
    <p style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:var(--teal)">Our experiences are being updated</p>
    <p>Please <a href="/contact.php" style="color:var(--teal)">contact us</a> and we'll arrange anything you have in mind.</p>
  </div></div>
  <?php endif; ?>
</div>

<script>
(function(){
  var chips = document.querySelectorAll('.act-chip');
  var groups = document.querySelectorAll('.act-group');
  chips.forEach(function(chip){
    chip.addEventListener('click', function(){
      chips.forEach(function(c){ c.classList.remove('is-active'); });
      chip.classList.add('is-active');
      var f = chip.dataset.filter;
      groups.forEach(function(g){ g.style.display = (f === 'all' || g.dataset.cat === f) ? '' : 'none'; });
    });
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
