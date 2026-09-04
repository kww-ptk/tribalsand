<?php
require_once 'includes/schema.php';
require_once 'includes/db.php';
require_once 'includes/services.php';   // is_priced(), format_price() — price fallback below
require_once 'includes/booking.php';    // tour_normalize_location()

/* Load the styled datepicker + booking CSS/JS for the on-page enquiry modal. */
$page_booking = true;

/* ── SEO ── */
$page_title  = 'Activities & Experiences · Kenya Coast · Tribal Sand';
$page_desc   = 'Things to do on Kenya\'s North Coast — snorkelling and diving in Watamu Marine Park, kitesurfing, deep-sea fishing, dhow cruises, safaris and golf.';
$page_url    = 'https://tribalsand.com/activities.php';
$page_image  = asset_url('images/hero-maya-kobe.jpg');
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
  // Safari categories (admin "Safaris" optgroup) — rendered here too so every published tour has a live home + anchor.
  'classic'     => ['label' => 'Classic Safari',    'icon' => '🦁', 'grad' => 'linear-gradient(135deg,#8a6a3a,#3a2a12)'],
  'custom'      => ['label' => 'Custom Journeys',   'icon' => '🧭', 'grad' => 'linear-gradient(135deg,#7d5a34,#2f2010)'],
  'excursion'   => ['label' => 'Day Excursions',    'icon' => '🚙', 'grad' => 'linear-gradient(135deg,#6b5230,#241a0c)'],
];

/* ── Load activities from the DB (managed in admin → Tours) ── */
try {
    $__acts = db_query(
        "SELECT t.*, (SELECT filename FROM tour_images WHERE tour_id = t.id AND is_hero = TRUE LIMIT 1) AS hero
         FROM tours t
         WHERE t.is_published = TRUE AND t.category IN ('marine','watersports','nature','wellness','golf','classic','custom','excursion')
         ORDER BY t.sort_order ASC"
    )->fetchAll();
} catch (Throwable $e) {
    $__acts = [];
}
$grouped = [];
$hasFav  = false;   // any Guest Favourite? — controls the favourites chip
$hasLoc  = false;   // any tour with a set (non-'all') location? — controls the location row
$LOC_LABELS = ['watamu' => 'Watamu', 'kilifi' => 'Kilifi', 'vipingo' => 'Vipingo'];
foreach ($__acts as &$a) {
    $a['location'] = tour_normalize_location($a['location'] ?? 'all');
    $a['is_fav']   = !empty($a['is_guest_favourite']) && ($a['is_guest_favourite'] !== 'f');
    if ($a['is_fav'])           $hasFav = true;
    if ($a['location'] !== 'all') $hasLoc = true;
    $grouped[$a['category']][] = $a;
}
unset($a);

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
.act-card__fav{position:absolute;top:.7rem;right:.7rem;display:inline-flex;align-items:center;gap:.28rem;font-size:.52rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:var(--teal-d);background:var(--sand-lt);padding:.28rem .55rem;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.18);}
.act-card__fav svg{width:11px;height:11px;}
/* second (location) filter row */
.act-filters--loc{top:auto;position:static;padding-top:.4rem;padding-bottom:1.1rem;border-bottom:none;background:none;backdrop-filter:none;}
.act-filters__lbl{align-self:center;font-family:'Jost',sans-serif;font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;color:var(--light);margin-right:.2rem;}
.act-card.is-hidden{display:none;}
.act-group.is-hidden{display:none;}
.act-card__body{padding:1.1rem 1.2rem 1.3rem;display:flex;flex-direction:column;flex:1;}
.act-card__name{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:1.22rem;line-height:1.2;color:var(--dark);margin-bottom:.4rem;}
.act-card__desc{font-size:.9rem;line-height:1.65;color:var(--mid);margin-bottom:.9rem;flex:1;}
.act-card__meta{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;font-size:.72rem;color:var(--light);margin-bottom:1rem;}
.act-card__dur{display:inline-flex;align-items:center;gap:.3rem;}
.act-card__price{margin-left:auto;font-family:'Cormorant Garamond',serif;font-size:1.05rem;color:var(--teal);font-weight:500;}
.act-card__ctas{display:flex;gap:.5rem;}
.act-card__cta{flex:1;display:block;text-align:center;background:var(--sand);color:var(--teal-d);border:none;border-radius:5px;padding:.7rem;font-family:'Jost',sans-serif;font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;text-decoration:none;transition:background .2s;}
.act-card__cta:hover{background:var(--sand-lt);}
.act-card__cta--wa{flex:0 0 auto;background:#25D366;color:#fff;display:inline-flex;align-items:center;gap:.35rem;}
.act-card__cta--wa:hover{background:#1eb457;}
.act-card__cta--wa svg{width:14px;height:14px;}
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
    <button type="button" class="act-chip is-active" data-cat-filter="all">All</button>
    <?php if ($hasFav): ?>
    <button type="button" class="act-chip" data-cat-filter="favourite">★ Guest Favourites</button>
    <?php endif; ?>
    <?php foreach ($CATS as $key => $c): if (empty($grouped[$key])) continue; ?>
    <button type="button" class="act-chip" data-cat-filter="<?= e($key) ?>"><?= e($c['label']) ?></button>
    <?php endforeach; ?>
  </div>
  <?php if ($hasLoc): ?>
  <div class="act-filters act-filters--loc">
    <span class="act-filters__lbl">Location</span>
    <button type="button" class="act-chip is-active" data-loc-filter="all">All</button>
    <?php foreach ($LOC_LABELS as $lk => $ll): ?>
    <button type="button" class="act-chip" data-loc-filter="<?= e($lk) ?>"><?= e($ll) ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

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
        <article class="act-card" id="tour-<?= e($a['slug']) ?>"
                 data-cat="<?= e($key) ?>" data-loc="<?= e($a['location']) ?>" data-fav="<?= $a['is_fav'] ? '1' : '0' ?>">
          <div class="act-card__img" style="background:<?= e($c['grad']) ?>">
            <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($a['name']) ?>" loading="lazy"><?php else: ?><span class="act-card__icon"><?= $c['icon'] ?></span><?php endif; ?>
            <?php if (!empty($a['tag_label'])): ?><span class="act-card__tag"><?= e($a['tag_label']) ?></span><?php endif; ?>
            <?php if ($a['is_fav']): ?><span class="act-card__fav"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.9 6.6 7.1.6-5.4 4.7 1.6 7L12 17.8 5.8 21l1.6-7L2 9.2l7.1-.6z"/></svg>Guest Favourite</span><?php endif; ?>
          </div>
          <div class="act-card__body">
            <h3 class="act-card__name"><?= e($a['name']) ?></h3>
            <p class="act-card__desc"><?= e($a['short_desc']) ?></p>
            <div class="act-card__meta">
              <?php if (!empty($a['duration'])): ?><span class="act-card__dur">⏱ <?= e($a['duration']) ?></span><?php endif; ?>
              <?php
                // The legacy free-text price wins when set — it carries marketing
                // nuance ("From $60 / person") a bare number can't. But admin no
                // longer writes that column, so fall back to the numeric price the
                // booking flow uses. Without this fallback a newly-priced tour
                // would show a price in the guest app and none here.
                // Both paths run through the display-currency converters so tour
                // prices switch currency like everything else ($-amounts only;
                // "On Request" etc. pass through). Tour numeric prices are USD.
                $__txt = trim((string)($a['price'] ?? ''));
                if ($__txt !== '') {
                    $__priceHtml = money_text_html($__txt);
                } elseif (is_priced($a['price_amount'] ?? null)) {
                    $__priceHtml = money_html((float)$a['price_amount'], 'USD')
                                 . (!empty($a['price_per_person']) && $a['price_per_person'] !== 'f' ? ' / person' : '');
                } else {
                    $__priceHtml = '';
                }
              ?>
              <?php if ($__priceHtml !== ''): ?><span class="act-card__price"><?= $__priceHtml ?></span><?php endif; ?>
            </div>
            <div class="act-card__ctas">
              <a class="act-card__cta" href="/enquire.php?tour=<?= e($a['slug']) ?>" data-act-enquire data-tour-slug="<?= e($a['slug']) ?>" data-tour-name="<?= e($a['name']) ?>">Enquire →</a>
              <a class="act-card__cta act-card__cta--wa" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="Enquire about <?= e($a['name']) ?> on WhatsApp">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm5.8 14.16c-.24.68-1.42 1.32-1.95 1.36-.5.05-.97.24-3.26-.68-2.76-1.09-4.5-3.92-4.64-4.11-.14-.19-1.11-1.48-1.11-2.82 0-1.34.7-2 .95-2.27.24-.27.53-.34.71-.34.18 0 .36.002.51.01.16.007.38-.06.6.46.24.56.8 1.94.87 2.08.07.14.12.3.02.49-.1.19-.14.3-.29.46-.14.16-.3.36-.43.48-.14.14-.29.29-.12.57.17.28.76 1.25 1.63 2.02 1.12.99 2.06 1.3 2.35 1.44.29.14.46.12.63-.07.18-.19.72-.85.91-1.14.19-.29.38-.24.64-.14.26.1 1.64.77 1.92.91.28.14.47.21.53.33.07.12.07.68-.17 1.36Z"/></svg>
                WhatsApp
              </a>
            </div>
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
  var catChips = Array.prototype.slice.call(document.querySelectorAll('[data-cat-filter]'));
  var locChips = Array.prototype.slice.call(document.querySelectorAll('[data-loc-filter]'));
  var cards    = Array.prototype.slice.call(document.querySelectorAll('.act-card'));
  var groups   = Array.prototype.slice.call(document.querySelectorAll('.act-group'));
  var catMode = 'all', locMode = 'all';

  function apply(){
    cards.forEach(function(card){
      var okCat = catMode === 'all'
        || (catMode === 'favourite' ? card.dataset.fav === '1' : card.dataset.cat === catMode);
      var okLoc = locMode === 'all' || card.dataset.loc === locMode;
      card.classList.toggle('is-hidden', !(okCat && okLoc));
    });
    // Hide any category section left with no visible cards.
    groups.forEach(function(g){
      var visible = g.querySelectorAll('.act-card:not(.is-hidden)').length;
      g.classList.toggle('is-hidden', visible === 0);
    });
  }

  function bind(chips, isCat){
    chips.forEach(function(chip){
      chip.addEventListener('click', function(){
        chips.forEach(function(c){ c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        if (isCat) catMode = chip.dataset.catFilter; else locMode = chip.dataset.locFilter;
        apply();
      });
    });
  }
  bind(catChips, true);
  bind(locChips, false);
})();
</script>
<?php include 'includes/activity-enquiry-modal.php'; ?>

<?php include 'includes/footer.php'; ?>
