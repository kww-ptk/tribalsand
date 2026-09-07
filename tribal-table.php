<?php
/**
 * Tribal Table — the beachfront restaurant and bar at Tribal Dunes, Bofa Beach.
 *
 * Division of labour: the restaurant runs its own site (tribaltablekenya.com)
 * for menus, opening hours and bookings, so this page never duplicates a menu
 * or takes a reservation. It is the Tribal Sand-side story and photography,
 * and every route to "book" sends the visitor to the restaurant's own site.
 *
 * Copy and photos are editable in Admin → Content → Pages → Tribal Table
 * (page_content_registry()['tribal-table']); each photo slot's hint names the
 * photograph that belongs there.
 *
 * Only the hero ships with a stand-in photo, because the layout needs one. Every
 * other photo slot starts EMPTY and the page adapts: a feature section with no
 * photo renders as centred text instead of a broken half-grid, and the gallery
 * hides itself below three tiles. A placeholder from another property under a
 * caption like "the chef at work" would be a small lie — the same lie this
 * rebuild was meant to clear off the dining pages — so the page would rather
 * show less until the real photographs are in.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/page-content.php';

const TT_SITE = 'https://www.tribaltablekenya.com';

$heroImg = page_image('tribal-table', 'hero_image');

/* Gallery — an unset slot resolves to '' and drops out of the grid. */
$gallery = [];
foreach ([
    ['gal_1', 'A plated dish at Tribal Table, Kilifi'],
    ['gal_2', 'Tribal Table seen from the garden at Tribal Dunes'],
    ['gal_3', 'A cocktail from the Tribal Table bar'],
    ['gal_4', 'Guests dining at Tribal Table after dark'],
    ['gal_5', 'The dining room and bar at Tribal Table'],
    ['gal_6', 'Tribal Table, Bofa Beach, Kilifi'],
] as [$slot, $alt]) {
    $src = page_image('tribal-table', $slot);
    if ($src !== '') $gallery[] = [$src, $alt];
}

$hasGallery = count($gallery) >= 3;

/* The three story sections. Each renders as a photo/text split once its photo is
   set, and as a centred text block until then. */
$features = [
    ['kitchen', 'The Kitchen', 'The chef at work in the open kitchen at Tribal Table', false],
    ['bar',     'The Bar',     'A cocktail being finished at the Tribal Table bar',    true],
    ['team',    'The Team',    'The Tribal Table team at the bar',                     false],
];

$pillars = ['Coastal Fine Dining', 'Craft Cocktails', 'Seafood & Grill',
            'Sunset Terrace', 'Open to Public', 'Private Events'];

/* ═══ SEO ═══ */
$page_title = 'Tribal Table · Beachfront Restaurant & Bar · Bofa Beach, Kilifi · Tribal Sand';
$page_desc  = 'Tribal Table is a beachfront restaurant and cocktail bar at Tribal Dunes on Bofa Beach, Kilifi. Open to the public — seafood and grill, craft cocktails and a sunset terrace. Menus and bookings at tribaltablekenya.com.';
$page_url   = 'https://tribalsand.com/tribal-table.php';
$page_image = page_image('tribal-table', 'og_image');
$page_preload = $heroImg;

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',         'url' => 'https://tribalsand.com/'],
    ['name' => 'Tribal Dunes', 'url' => 'https://tribalsand.com/tribal-dunes.php'],
    ['name' => 'Tribal Table', 'url' => 'https://tribalsand.com/tribal-table.php'],
]);
$page_schema .= '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'Restaurant',
    'name'     => 'Tribal Table',
    'description' => 'Beachfront restaurant and cocktail bar at Tribal Dunes, Bofa Beach, Kilifi.',
    'servesCuisine' => ['Coastal', 'Seafood', 'Grill'],
    'address'  => ['@type' => 'PostalAddress', 'streetAddress' => 'Bofa Beach, Tribal Dunes',
                   'addressLocality' => 'Kilifi', 'addressRegion' => 'Kilifi County', 'addressCountry' => 'KE'],
    'acceptsReservations' => 'True',
    'url'      => 'https://tribalsand.com/tribal-table',
    'sameAs'   => [TT_SITE],
    'image'    => $heroImg,
    'parentOrganization' => ['@type' => 'Organization', 'name' => 'Tribal Sand', 'url' => 'https://tribalsand.com'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
?>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;
  --teal:#1E5C6B;--teal-d:#102F3A;
  --dark:#141412;--off:#FAF8F4;--mid:#6B6050;--border:rgba(184,150,90,.16);
}
.tt *{box-sizing:border-box;}
.tt img{display:block;object-fit:cover;}
.tt a{text-decoration:none;color:inherit;}

/* ── HERO ── */
.tt-hero{position:relative;min-height:76vh;display:flex;align-items:flex-end;padding:0 6vw 3.5rem;overflow:hidden;}
.tt-hero-bg{position:absolute;inset:0;}
.tt-hero-bg img{width:100%;height:100%;}
.tt-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,rgba(16,47,58,.32) 0%,rgba(16,47,58,.6) 45%,rgba(16,47,58,.85) 74%,rgba(16,47,58,.95) 100%);}
.tt-hero-in{position:relative;z-index:2;max-width:800px;}
.tt-badge{display:inline-flex;align-items:center;gap:.5rem;font-size:.5rem;letter-spacing:.34em;text-transform:uppercase;color:var(--sand-lt);border:1px solid rgba(212,176,122,.5);padding:.45rem 1.1rem;margin-bottom:1.4rem;}
.tt-badge::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--sand-lt);animation:ttp 2s ease-in-out infinite;}
@keyframes ttp{0%,100%{opacity:1}50%{opacity:.3}}
.tt-eyebrow{font-size:.58rem;letter-spacing:.3em;text-transform:uppercase;color:rgba(232,220,200,.72);margin-bottom:.9rem;}
.tt-hero h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.6rem,6.5vw,4.8rem);font-weight:300;color:#fff;line-height:1;margin:0;}
.tt-hero h1 em{font-style:italic;color:var(--sand-lt);}
.tt-hero-sub{margin-top:1.1rem;font-size:1rem;color:rgba(212,196,172,.94);line-height:1.75;font-weight:300;max-width:560px;}
.tt-cta{display:flex;flex-wrap:wrap;gap:.8rem;margin-top:2rem;}
.tt-btn{display:inline-flex;align-items:center;gap:.55rem;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;font-weight:500;padding:1rem 1.8rem;transition:all .2s;border:1px solid transparent;}
.tt-btn--gold{background:var(--sand);color:var(--teal-d);}
.tt-btn--gold:hover{background:var(--sand-lt);}
.tt-btn--ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.5);}
.tt-btn--ghost:hover{border-color:#fff;background:rgba(255,255,255,.1);}
.tt-btn--ink{background:transparent;color:var(--teal-d);border-color:rgba(184,150,90,.55);}
.tt-btn--ink:hover{background:var(--sand);border-color:var(--sand);}

/* ── SECTIONS ── */
.tt-sec{padding:4.5rem 5vw;}
.tt-sec--pale{background:var(--sand-pale);}
.tt-in{max-width:1120px;margin:0 auto;}
.tt-in--narrow{max-width:760px;}
.tt-lbl{font-size:.56rem;letter-spacing:.3em;text-transform:uppercase;color:var(--teal);font-weight:500;margin-bottom:1rem;}
.tt-h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.8rem,3.4vw,2.6rem);font-weight:400;line-height:1.2;margin:0 0 1.2rem;color:var(--dark);}
.tt-h2 em{font-style:italic;color:var(--sand);}
.tt-p{font-size:.95rem;color:var(--mid);line-height:1.9;font-weight:300;margin:0 0 1rem;}
.tt-center{text-align:center;}
.tt-center .tt-p{margin-left:auto;margin-right:auto;max-width:640px;}
.tt-pillars{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin-top:2rem;}
.tt-pill{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;padding:.5rem 1.1rem;border:1px solid var(--border);color:var(--mid);}

/* ── SPLITS ── */
.tt-split{display:grid;grid-template-columns:1fr 1fr;gap:3.5rem;align-items:center;}
.tt-split-img{aspect-ratio:4/3;overflow:hidden;background:var(--sand-pale);}
.tt-split-img img{width:100%;height:100%;transition:transform .7s ease;}
.tt-split-img:hover img{transform:scale(1.04);}
.tt-split--flip .tt-split-img{order:-1;}

/* ── GALLERY ── */
.tt-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.7rem;margin-top:2.2rem;}
.tt-grid figure{margin:0;position:relative;aspect-ratio:4/3;overflow:hidden;background:var(--sand-pale);}
.tt-grid img{width:100%;height:100%;transition:transform .6s ease;}
.tt-grid figure:hover img{transform:scale(1.06);}

/* ── VISIT ── */
.tt-visit{display:grid;grid-template-columns:repeat(3,1fr);gap:1.6rem;margin-top:2.4rem;}
.tt-visit div{border:1px solid var(--border);padding:1.8rem 1.6rem;background:#fff;}
.tt-visit h3{font-family:'Cormorant Garamond',serif;font-size:1.35rem;font-weight:400;margin:0 0 .6rem;color:var(--dark);}
.tt-visit p{font-size:.88rem;color:var(--mid);line-height:1.8;font-weight:300;margin:0;}

/* ── CLOSING ── */
.tt-end{background:var(--teal-d);padding:5rem 5vw;text-align:center;}
.tt-end .tt-lbl{color:var(--sand-lt);}
.tt-end h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.9rem,3.6vw,2.8rem);font-weight:300;color:#fff;margin:0 0 1rem;}
.tt-end h2 em{font-style:italic;color:var(--sand-lt);}
.tt-end p{font-size:.92rem;color:rgba(212,196,172,.85);line-height:1.85;font-weight:300;max-width:540px;margin:0 auto 2rem;}
.tt-back{display:inline-block;margin-top:1.8rem;font-size:.58rem;letter-spacing:.15em;text-transform:uppercase;color:rgba(184,150,90,.7);transition:color .2s;}
.tt-back:hover{color:var(--sand-lt);}

@media(max-width:900px){
  .tt-split{grid-template-columns:1fr;gap:2rem;}
  .tt-split--flip .tt-split-img{order:0;}
  .tt-grid,.tt-visit{grid-template-columns:repeat(2,1fr);}
  .tt-hero{min-height:66vh;}
}
@media(max-width:560px){
  .tt-grid{grid-template-columns:1fr 1fr;}
  .tt-visit{grid-template-columns:1fr;}
  .tt-cta{flex-direction:column;align-items:stretch;}
  .tt-btn{justify-content:center;}
}
</style>
<body class="ts-nav-transparent">
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="tt">

  <!-- ── HERO ── -->
  <section class="tt-hero">
    <div class="tt-hero-bg">
      <img src="<?= e($heroImg) ?>" alt="Tribal Dunes, Bofa Beach, Kilifi — home of Tribal Table" width="1920" height="1080" loading="eager">
    </div>
    <div class="tt-hero-in">
      <div class="tt-badge"><?= page_text('tribal-table','hero_badge') ?></div>
      <p class="tt-eyebrow"><?= page_text('tribal-table','hero_eyebrow') ?></p>
      <h1><?= page_html('tribal-table','hero_title') ?></h1>
      <p class="tt-hero-sub"><?= page_html('tribal-table','hero_sub') ?></p>
      <div class="tt-cta">
        <a class="tt-btn tt-btn--gold" href="<?= e(TT_SITE) ?>" target="_blank" rel="noopener">Menus &amp; bookings →</a>
        <a class="tt-btn tt-btn--ghost" href="<?= $hasGallery ? '#gallery' : '#kitchen' ?>">See the restaurant</a>
      </div>
    </div>
  </section>

  <!-- ── INTRO ── -->
  <section class="tt-sec">
    <div class="tt-in tt-in--narrow tt-center">
      <p class="tt-lbl"><?= page_text('tribal-table','info_eyebrow') ?></p>
      <h2 class="tt-h2"><?= page_html('tribal-table','info_title') ?></h2>
      <p class="tt-p"><?= page_html('tribal-table','info_body') ?></p>
      <div class="tt-pillars">
        <?php foreach ($pillars as $p): ?><span class="tt-pill"><?= e($p) ?></span><?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── STORY SECTIONS (photo split, or text-only until the photo lands) ── -->
  <?php foreach ($features as $i => [$key, $label, $alt, $flip]):
    $img  = page_image('tribal-table', $key . '_image');
    $pale = $i % 2 === 0; ?>
  <section class="tt-sec<?= $pale ? ' tt-sec--pale' : '' ?>" id="<?= e($key) ?>">
    <?php if ($img !== ''): ?>
    <div class="tt-in tt-split<?= $flip ? ' tt-split--flip' : '' ?>">
      <div class="tt-split-img">
        <img src="<?= e($img) ?>" alt="<?= e($alt) ?>" loading="lazy">
      </div>
      <div>
        <p class="tt-lbl"><?= e($label) ?></p>
        <h2 class="tt-h2"><?= page_html('tribal-table', $key . '_title') ?></h2>
        <p class="tt-p"><?= page_html('tribal-table', $key . '_body') ?></p>
      </div>
    </div>
    <?php else: ?>
    <div class="tt-in tt-in--narrow tt-center">
      <p class="tt-lbl"><?= e($label) ?></p>
      <h2 class="tt-h2"><?= page_html('tribal-table', $key . '_title') ?></h2>
      <p class="tt-p"><?= page_html('tribal-table', $key . '_body') ?></p>
    </div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <!-- ── GALLERY — hidden until there are enough real photographs ── -->
  <?php if ($hasGallery): ?>
  <section class="tt-sec tt-sec--pale" id="gallery">
    <div class="tt-in">
      <div class="tt-center">
        <p class="tt-lbl"><?= page_text('tribal-table','gal_eyebrow') ?></p>
        <h2 class="tt-h2"><?= page_html('tribal-table','gal_title') ?></h2>
      </div>
      <div class="tt-grid">
        <?php foreach ($gallery as [$src, $galAlt]): ?>
        <figure><img src="<?= e($src) ?>" alt="<?= e($galAlt) ?>" loading="lazy"></figure>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── PLANNING A VISIT ── -->
  <section class="tt-sec tt-sec--pale">
    <div class="tt-in">
      <div class="tt-center">
        <p class="tt-lbl">Planning a Visit</p>
        <h2 class="tt-h2">Finding <em>the table</em></h2>
      </div>
      <div class="tt-visit">
        <div>
          <h3>Where</h3>
          <p>Bofa Beach, Kilifi — inside <a href="tribal-dunes.php" style="border-bottom:1px solid rgba(184,150,90,.5)">Tribal Dunes</a>, on Kenya's North Coast. About an hour north of Watamu.</p>
        </div>
        <div>
          <h3>Booking</h3>
          <p>Menus, opening hours and table bookings all live on the restaurant's own site, <a href="<?= e(TT_SITE) ?>" target="_blank" rel="noopener" style="border-bottom:1px solid rgba(184,150,90,.5)">tribaltablekenya.com</a>.</p>
        </div>
        <div>
          <h3>Private events</h3>
          <p>The restaurant takes private events and group bookings. Ask through the restaurant's site, or talk to us about the wider <a href="events.php" style="border-bottom:1px solid rgba(184,150,90,.5)">Tribal Sand events</a> side.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ── CLOSING ── -->
  <section class="tt-end">
    <p class="tt-lbl">Reserve</p>
    <h2>Book a table at <em>Tribal Table</em></h2>
    <p>
      Everything you need to plan the evening — the current menu, opening hours and the
      booking form — is on the restaurant's own site.
    </p>
    <div class="tt-cta" style="justify-content:center">
      <a class="tt-btn tt-btn--gold" href="<?= e(TT_SITE) ?>" target="_blank" rel="noopener">Visit tribaltablekenya.com →</a>
      <a class="tt-btn tt-btn--ghost" href="a-la-carte-dining.php">Dining across the coast</a>
    </div>
    <a class="tt-back" href="tribal-dunes.php">← Back to Tribal Dunes</a>
  </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
