<?php
require_once 'includes/schema.php';

$page_title   = 'Tribal Sand · Luxury Beachfront Hotels & Villas · Kenya\'s North Coast';
$page_desc    = 'Tribal Sand — luxury beachfront boutique hotels and private villas in Watamu, Kilifi and Vipingo, Kenya. Zuri, Maya Kobe, My Amani and more. Book direct.';
$page_url     = 'https://tribalsand.com/';
$page_image   = 'https://tribalsand.com/images/Maya-Kobe-1-hero.webp';
$page_preload = 'images/New-hero-banner.jpg';

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_item_list([
    ['name' => 'Zuri Boutique Hotel',       'url' => 'https://tribalsand.com/zuri.php'],
    ['name' => 'Maya Kobe Boutique Hotel',  'url' => 'https://tribalsand.com/maya-kobe.php'],
    ['name' => 'My Amani Private Villa',    'url' => 'https://tribalsand.com/my-amani.php'],
    ['name' => 'Enkare Bofa Villa',         'url' => 'https://tribalsand.com/enkare-bofa.php'],
    ['name' => 'Sandbox Villa',             'url' => 'https://tribalsand.com/sandbox.php'],
    ['name' => 'Maya Ilai Eco Compound',    'url' => 'https://tribalsand.com/maya_ilai.php'],
]);

include 'includes/head.php';
?>

<style>
/* ── HOMEPAGE-SPECIFIC STYLES ── */

/* ── HERO ── */
.hero { position:relative; height:100vh; min-height:640px; overflow:hidden; }
.hero-bg { position:absolute; inset:0; }
/* Slides */
.hero-slide { position:absolute; inset:0; opacity:0; transition:opacity 1.5s ease; }
.hero-slide.active { opacity:1; }
.hero-slide img { width:100%; height:100%; object-fit:cover; object-position:center 35%; display:block; }
.hero-slide.active img { animation:kbZoom 14s ease-out forwards; }
@keyframes kbZoom { from{transform:scale(1);} to{transform:scale(1.09);} }
/* Overlay */
.hero-overlay { position:absolute; inset:0; z-index:1; background:linear-gradient(to bottom,rgba(10,30,40,.38) 0%,rgba(10,30,40,.52) 55%,rgba(10,30,40,.84) 100%); pointer-events:none; }
/* Inner */
.hero-inner { position:relative; z-index:2; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; padding-top:68px; padding-bottom:5vh; text-align:center; padding-left:20px; padding-right:20px; }
/* Text animations */
.hero-eyebrow { animation:heroUp .9s cubic-bezier(.22,1,.36,1) .25s both; }
.hero-h1 { animation:heroUp 1.1s cubic-bezier(.22,1,.36,1) .5s both; }
.hero-sub { animation:heroUp .9s cubic-bezier(.22,1,.36,1) .82s both; }
.hero-btns { animation:heroUp .9s cubic-bezier(.22,1,.36,1) 1.08s both; }
@keyframes heroUp { from{opacity:0;transform:translateY(24px);} to{opacity:1;transform:translateY(0);} }
/* Dot indicators */
.hero-indicators { position:absolute; bottom:3.2rem; left:50%; transform:translateX(-50%); display:flex; gap:.55rem; z-index:3; }
.hero-dot { width:22px; height:2px; background:rgba(255,255,255,.25); border:none; padding:0; cursor:pointer; transition:all .35s ease; }
.hero-dot.active { width:38px; background:rgba(184,150,90,.88); }
/* Property badge */
.hero-prop { position:absolute; bottom:3rem; right:5vw; z-index:3; font-size:.58rem; letter-spacing:.26em; text-transform:uppercase; color:rgba(184,150,90,.72); transition:opacity .55s ease; pointer-events:none; }
/* Progress bar */
.hero-progress { position:absolute; bottom:0; left:0; height:2px; background:rgba(184,150,90,.5); z-index:3; width:0; pointer-events:none; }
.hero-progress.running { animation:heroProgress 6s linear forwards; }
@keyframes heroProgress { from{width:0;} to{width:100%;} }

/* ── INTRO STRIP ── */
.intro-strip { background:var(--teal-d); padding:2.8rem 5vw; display:grid; grid-template-columns:1fr auto; align-items:center; gap:3rem; border-bottom:1px solid rgba(184,150,90,.12); }
.intro-tagline { font-family:'Cormorant Garamond',serif; font-size:clamp(1.4rem,2.2vw,1.9rem); font-weight:300; font-style:italic; color:rgba(212,196,172,.9); margin-bottom:1.1rem; line-height:1.3; }
.intro-sub { font-size:.85rem; color:rgba(255,255,255,.45); letter-spacing:.06em; line-height:1.7; }
.intro-right { display:flex; flex-direction:column; gap:1rem; align-items:flex-end; }
.intro-stats { display:flex; gap:2.5rem; flex-wrap:wrap; justify-content:flex-end; }
.stat { text-align:center; }
.stat-n { font-family:'Cormorant Garamond',serif; font-size:2.7rem; font-weight:300; color:var(--sand-lt); line-height:1; }
.stat-l { font-size:.75rem; letter-spacing:.18em; text-transform:uppercase; color:rgba(184,150,90,.6); margin-top:.25rem; }
.intro-trust { display:flex; align-items:center; gap:.65rem; background:rgba(255,255,255,.05); border:1px solid rgba(184,150,90,.15); padding:.55rem 1rem; }
.intro-trust-dot { width:8px; height:8px; border-radius:50%; background:#4CAF82; flex-shrink:0; animation:trustPulse 2s ease infinite; }
@keyframes trustPulse { 0%,100%{box-shadow:0 0 0 0 rgba(76,175,130,.5);}50%{box-shadow:0 0 0 6px rgba(76,175,130,0);} }
.intro-trust-msg { font-size:.85rem; color:rgba(255,255,255,.78); }
.intro-trust-msg strong { color:#D4B07A; }

/* ── PROPERTIES ── */
.props-section { padding:88px 5vw; background:var(--off); }
.props-header { display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:1.8rem; flex-wrap:wrap; gap:1.2rem; }
.prop-filters { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:2rem; }
.prop-filter { font-size:.75rem; letter-spacing:.16em; text-transform:uppercase; padding:.46rem 1rem; border:1px solid var(--border); background:none; color:var(--light); cursor:pointer; transition:all .2s; font-family:'Jost',sans-serif; }
.prop-filter.on { background:var(--teal); border-color:var(--teal); color:#fff; }
.props-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1.2rem; }

/* ── TRIBAL DUNES FEATURE ── */
.tribal-section { background:var(--teal-d); padding:88px 5vw; }
.tribal-grid { display:grid; grid-template-columns:1fr 1fr; gap:4rem; align-items:center; }
.tribal-img-stack { position:relative; }
.tribal-img-main { width:100%; aspect-ratio:4/3; object-fit:cover; }
.tribal-img-accent { position:absolute; bottom:-1.5rem; right:-1.5rem; width:52%; aspect-ratio:4/3; border:3px solid var(--teal-d); object-fit:cover; }
.tribal-venues { display:flex; flex-direction:column; gap:0; margin-bottom:2rem; }
.tribal-venue { display:flex; align-items:center; gap:.85rem; padding:.72rem 0; border-bottom:1px solid rgba(184,150,90,.1); }
.tribal-venue:last-child { border-bottom:none; }
.tribal-venue-ico { font-size:1rem; flex-shrink:0; width:1.4rem; text-align:center; }
.tribal-venue-name { font-size:.9rem; color:rgba(212,196,172,.9); }
.tribal-venue-tag { font-size:.75rem; letter-spacing:.1em; text-transform:uppercase; color:rgba(184,150,90,.7); margin-left:auto; }

/* ── HOW IT WORKS ── */
.how-section { padding:88px 5vw; background:var(--white); }
.how-grid { display:grid; grid-template-columns:1fr 1fr; gap:5rem; align-items:center; }
.how-img-wrap { position:relative; }
.how-img { width:100%; aspect-ratio:4/5; object-fit:cover; }
.how-img-accent { position:absolute; bottom:-1.5rem; right:-1.5rem; width:54%; aspect-ratio:4/3; border:4px solid var(--white); box-shadow:0 10px 40px rgba(0,0,0,.1); object-fit:cover; }
.how-steps { display:flex; flex-direction:column; gap:1.8rem; margin:2rem 0; }
.how-step { display:flex; gap:1.2rem; align-items:flex-start; }
.how-num { width:2.2rem; height:2.2rem; border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-family:'Cormorant Garamond',serif; font-size:1rem; color:var(--sand); flex-shrink:0; }
.how-step-h { font-family:'Cormorant Garamond',serif; font-size:1.2rem; font-weight:400; color:var(--dark); margin-bottom:.3rem; }
.how-step-p { font-size:.9rem; color:#4a3d2e; line-height:1.82; }
.how-btns { display:flex; gap:.75rem; flex-wrap:wrap; margin-top:2rem; }

/* ── SUSTAINABILITY ── */
.sustain-section { padding:88px 5vw; background:var(--sand-faint); }
.sustain-grid { display:grid; grid-template-columns:1fr 1fr; gap:4rem; align-items:start; }
.sustain-pillars { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border); margin-top:2rem; }
.sustain-pillar { background:var(--white); padding:1.4rem 1.2rem; }
.sustain-pillar-ico { font-size:1.4rem; margin-bottom:.65rem; }
.sustain-pillar-h { font-family:'Cormorant Garamond',serif; font-size:1.15rem; font-weight:400; color:var(--dark); margin-bottom:.35rem; }
.sustain-pillar-p { font-size:.85rem; color:#4a3d2e; line-height:1.78; }
.sustain-data-row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-top:.75rem; }
.sustain-data-card { background:none; padding:1.4rem 1.3rem; border:1px solid var(--border); }
.sustain-data-n { font-family:'Cormorant Garamond',serif; font-size:2rem; font-weight:300; color:var(--teal); line-height:1; margin-bottom:.2rem; }
.sustain-data-n span { font-size:1rem; }
.sustain-data-l { font-size:.75rem; letter-spacing:.14em; text-transform:uppercase; color:var(--sand); }
.sustain-data-note { font-size:.78rem; color:var(--mid); margin-top:.2rem; }
.sustain-live { display:flex; align-items:center; gap:.45rem; margin-bottom:.5rem; }
.sustain-live-dot { width:7px; height:7px; border-radius:50%; background:#4CAF82; flex-shrink:0; animation:sustainPulse 2s ease infinite; }
@keyframes sustainPulse { 0%,100%{box-shadow:0 0 0 0 rgba(76,175,130,.5);}50%{box-shadow:0 0 0 6px rgba(76,175,130,0);} }
.sustain-live-lbl { font-size:.75rem; letter-spacing:.16em; text-transform:uppercase; color:#4CAF82; }
.sustain-quote { background:var(--teal-d); padding:2.4rem; margin-top:2rem; }
.sustain-quote-text { font-family:'Cormorant Garamond',serif; font-size:1.35rem; font-style:italic; color:rgba(212,196,172,.88); line-height:1.65; margin-bottom:.8rem; }
.sustain-quote-attr { font-size:.75rem; letter-spacing:.18em; text-transform:uppercase; color:rgba(184,150,90,.55); }

/* ── EXPERIENCES ── */
.exp-section { padding:88px 5vw; background:var(--teal-d); }
.exp-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1px; background:rgba(184,150,90,.1); margin-top:2.5rem; }
.exp-item { background:var(--teal-d); padding:1.8rem 1.5rem; transition:background .2s; border-top:1px solid rgba(184,150,90,.08); }
.exp-item:hover { background:rgba(255,255,255,.03); }
.exp-ico { font-size:1.6rem; margin-bottom:.85rem; }
.exp-name { font-family:'Cormorant Garamond',serif; font-size:1.2rem; font-weight:400; color:rgba(212,196,172,.95); margin-bottom:.38rem; }
.exp-desc { font-size:.85rem; color:rgba(212,196,172,.55); line-height:1.78; }

/* ── GALLERY STRIP ── */
.gallery-strip { display:grid; grid-template-columns:repeat(5,1fr); height:310px; gap:2px; overflow:hidden; }
.gal-img { width:100%; height:100%; transition:opacity .3s,transform .5s; object-fit:cover; }
.gal-img:hover { opacity:.85; transform:scale(1.03); }

/* ── REVIEWS ── */
.reviews-section { padding:88px 5vw; background:var(--white); }
.reviews-header { text-align:center; margin-bottom:3rem; }
.reviews-header .eyebrow { justify-content:center; }
.reviews-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1.4rem; }

/* ── TRUST BAR ── */
.trust-bar { background:var(--white); border-top:1px solid var(--border); border-bottom:1px solid var(--border); padding:1.8rem 5vw; display:flex; align-items:center; justify-content:center; gap:3rem; flex-wrap:wrap; }
.trust-item { display:flex; align-items:center; gap:.75rem; }
.trust-item-ico { font-size:1.3rem; flex-shrink:0; }
.trust-item-n { font-family:'Cormorant Garamond',serif; font-size:1.35rem; font-weight:400; color:var(--dark); line-height:1; }
.trust-item-l { font-size:.75rem; letter-spacing:.12em; text-transform:uppercase; color:var(--light); margin-top:.1rem; }
.trust-divider { width:1px; height:36px; background:var(--border); }
.trust-platforms { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
.trust-platform { display:flex; align-items:center; gap:.35rem; padding:.35rem .75rem; border:1px solid var(--border); font-size:.75rem; letter-spacing:.05em; color:var(--mid); }
.trust-platform-stars { color:#F4B942; font-size:.75rem; }

/* ── CTA BANNER ── */
.cta-section { position:relative; height:490px; overflow:hidden; }
.cta-bg { position:absolute; inset:0; }
.cta-bg img { width:100%; height:100%; object-fit:cover; object-position:center 40%; }
.cta-bg::after { content:''; position:absolute; inset:0; background:rgba(10,30,40,.65); }
.cta-inner { position:relative; z-index:1; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:2rem; }
.cta-eyebrow { font-size:.75rem; letter-spacing:.36em; text-transform:uppercase; color:rgba(184,150,90,.82); margin-bottom:.9rem; }
.cta-h { font-family:'Cormorant Garamond',serif; font-size:clamp(2.3rem,4.5vw,3.9rem); font-weight:300; color:#fff; line-height:1; margin-bottom:.9rem; }
.cta-h em { font-style:italic; color:var(--sand-lt); }
.cta-p { font-size:.9rem; color:rgba(212,196,172,.75); max-width:400px; line-height:1.85; margin-bottom:2rem; }
.cta-btns { display:flex; gap:.8rem; flex-wrap:wrap; justify-content:center; }

/* ── RESPONSIVE ── */
@media(max-width:1100px){
  .props-grid { grid-template-columns:1fr 1fr; }
  .tribal-grid,.how-grid,.sustain-grid { grid-template-columns:1fr; gap:3rem; }
  .how-img-wrap { max-width:480px; }
  .exp-grid { grid-template-columns:repeat(2,1fr); }
}
@media(max-width:768px){
  .intro-strip { grid-template-columns:1fr; gap:1.5rem; }
  .intro-right { align-items:flex-start; }
  .props-section,.tribal-section,.how-section,.sustain-section,.exp-section,.reviews-section { padding:56px 20px; }
  .props-grid { grid-template-columns:1fr; }
  .reviews-grid { grid-template-columns:1fr; }
  .gallery-strip { grid-template-columns:repeat(3,1fr); height:200px; }
  .gallery-strip .gal-img:nth-child(n+4) { display:none; }
  .sustain-pillars { grid-template-columns:1fr; }
  .intro-stats { gap:1.5rem; }
  .trust-bar { gap:1.5rem; }
}
</style>

</head>
<body class="ts-nav-transparent">

<?php include 'includes/header.php'; ?>

<!-- ═══ HERO ═══ -->
<section class="hero" aria-label="Tribal Sand — Luxury beachfront hotels and villas on Kenya's North Coast">

  <div class="hero-bg">
    <div class="hero-slide active" data-prop="Tribal Dunes · Kilifi">
      <img src="images/New-hero-banner.jpg"
           alt="Tribal Dunes — beachfront community, Bofa Beach, Kilifi Kenya"
           width="1920" height="1080" loading="eager" decoding="sync">
    </div>
    <div class="hero-slide" data-prop="Maya Kobe · Kilifi">
      <img src="images/hero-maya-kobe.jpg"
           alt="Maya Kobe — eco beachfront boutique hotel, Kilifi Kenya" loading="lazy">
    </div>
    <div class="hero-slide" data-prop="Zuri · Watamu">
      <img src="images/hero-zuri.jpg"
           alt="Zuri — beachfront boutique hotel, Garoda Beach, Watamu Kenya" loading="lazy">
    </div>
    <div class="hero-slide" data-prop="My Amani · Vipingo">
      <img src="images/my-amani/Aerial/myamani-11.webp"
           alt="My Amani — luxury private beachfront villa, Vipingo Kenya" loading="lazy">
    </div>
    <div class="hero-slide" data-prop="Enkare Bofa · Kilifi">
      <img src="images/hero-enkare-bofa.jpg"
           alt="Enkare Bofa — beachfront villa, Bofa Road, Kilifi Kenya" loading="lazy">
    </div>
  </div>

  <div class="hero-overlay"></div>

  <div class="hero-inner">
    <div class="hero-eyebrow">Kenya's North Coast &nbsp;·&nbsp; Watamu &nbsp;·&nbsp; Kilifi &nbsp;·&nbsp; Vipingo</div>
    <h1 class="hero-h1">Luxury Beachfront<br><em>Hotels &amp; Villas</em><br>in Kenya</h1>
    <p class="hero-sub">An interconnected collection of boutique hotels, private villas and lifestyle venues on the Kenyan coast, Africa.</p>
    <div class="hero-btns">
      <a href="/booking" class="btn-primary">Book Now</a>
      <a href="trip-builder.php" class="btn-ghost">Plan Your Trip</a>
    </div>
  </div>

  <div class="hero-indicators" aria-label="Hero navigation">
    <button class="hero-dot active" data-idx="0" aria-label="Slide 1: Tribal Dunes"></button>
    <button class="hero-dot" data-idx="1" aria-label="Slide 2: Maya Kobe"></button>
    <button class="hero-dot" data-idx="2" aria-label="Slide 3: Zuri"></button>
    <button class="hero-dot" data-idx="3" aria-label="Slide 4: My Amani"></button>
    <button class="hero-dot" data-idx="4" aria-label="Slide 5: Enkare Bofa"></button>
  </div>

  <div class="hero-prop" id="heroPropBadge">Tribal Dunes · Kilifi</div>
  <div class="hero-progress" id="heroProgress"></div>

</section>

<!-- ═══ INTRO STRIP ═══ -->
<div class="intro-strip">
  <div class="intro-left">
    <div class="intro-tagline">"Kenya as it was meant to be experienced."</div>
    <div class="intro-sub">Watamu &nbsp;·&nbsp; Kilifi &nbsp;·&nbsp; Vipingo &nbsp;&nbsp;·&nbsp;&nbsp; Boutique hotels, private villas and an integrated beachfront ecosystem on Kenya's North Coast.</div>
  </div>
  <div class="intro-right">
    <div class="intro-stats">
      <div class="stat"><div class="stat-n">6</div><div class="stat-l">Properties</div></div>
      <div class="stat"><div class="stat-n">3</div><div class="stat-l">Locations</div></div>
      <div class="stat"><div class="stat-n">100%</div><div class="stat-l">Solar · Tribal Dunes</div></div>
      <div class="stat"><div class="stat-n">24h</div><div class="stat-l">Concierge</div></div>
    </div>
    <div class="intro-trust">
      <div class="intro-trust-dot"></div>
      <div class="intro-trust-msg" id="introTrustMsg">Booked <strong>7 times</strong> in the last 24h</div>
    </div>
  </div>
</div>

<!-- ═══ PROPERTIES ═══ -->
<section class="props-section" id="properties" aria-labelledby="props-heading">
  <div class="props-header">
    <div>
      <div class="eyebrow">Our Properties</div>
      <h2 class="sec-h" id="props-heading">Exclusive Stays Along<br><em>Kenya's Coastline</em></h2>
      <div class="sec-rule"></div>
    </div>
    <a href="/booking" class="btn-primary" style="flex-shrink:0;">View All Properties</a>
  </div>

  <div class="prop-filters" role="group" aria-label="Filter properties">
    <button class="prop-filter on" data-filter="all">All Properties</button>
    <button class="prop-filter" data-filter="hotel">Boutique Hotels</button>
    <button class="prop-filter" data-filter="villa">Private Villas</button>
    <button class="prop-filter" data-filter="watamu">Watamu</button>
    <button class="prop-filter" data-filter="kilifi">Kilifi</button>
    <button class="prop-filter" data-filter="vipingo">Vipingo</button>
  </div>

  <div class="props-grid">

    <!-- My Amani -->
    <div class="prop-card" data-type="villa" data-loc="vipingo">
      <div class="prop-img-wrap">
        <img class="prop-img" src="images/my-amani/Aerial/myamani-11.webp"
             alt="My Amani private beachfront villa aerial view — Vipingo, Kenya"
             width="900" height="506" loading="lazy" decoding="async">
        <div class="prop-type-badge">Private Villa</div>
      </div>
      <div class="prop-body">
        <div class="prop-location">Vipingo · Kilifi County</div>
        <div class="prop-name">My Amani</div>
        <div class="prop-desc">Vipingo's best-kept secret — a five-bedroom beachfront retreat with an infinity pool overlooking the Indian Ocean. Ultra private, concierge-led, available as entire villa only.</div>
        <div class="prop-pills">
          <span class="prop-pill">Infinity Pool</span>
          <span class="prop-pill">Private Chef</span>
          <span class="prop-pill">Beachfront</span>
          <span class="prop-pill">Hot Tub</span>
        </div>
      </div>
      <div class="prop-footer">
        <div class="prop-stats">
          <div class="prop-stat"><div class="prop-stat-n">5</div><div class="prop-stat-l">Bedrooms</div></div>
          <div class="prop-stat"><div class="prop-stat-n">10</div><div class="prop-stat-l">Guests</div></div>
        </div>
        <a href="my-amani.php" class="prop-btn">View Property</a>
      </div>
    </div>

    <!-- Maya Kobe -->
    <div class="prop-card" data-type="hotel" data-loc="kilifi">
      <div class="prop-img-wrap">
        <img class="prop-img" src="images/hero-maya-kobe.jpg"
             alt="Maya Kobe boutique hotel pool — Bofa Beach, Kilifi, Kenya"
             width="600" height="450" loading="lazy" decoding="async">
        <div class="prop-type-badge">Boutique Hotel</div>
      </div>
      <div class="prop-body">
        <div class="prop-location">Bofa Road · Kilifi</div>
        <div class="prop-name">Maya Kobe</div>
        <div class="prop-desc">Balinese-inspired boutique hotel at the heart of Tribal Dunes. Five ocean-facing suites, a 20m pool, chef-led dining and direct access to Tribal Table and Somewhere Café.</div>
        <div class="prop-pills">
          <span class="prop-pill">Ocean Suites</span>
          <span class="prop-pill">Pool</span>
          <span class="prop-pill">Chef Dining</span>
        </div>
      </div>
      <div class="prop-footer">
        <div class="prop-stats">
          <div class="prop-stat"><div class="prop-stat-n">5</div><div class="prop-stat-l">Suites</div></div>
          <div class="prop-stat"><div class="prop-stat-n">12</div><div class="prop-stat-l">Guests</div></div>
        </div>
        <a href="maya-kobe.php" class="prop-btn">View Property</a>
      </div>
    </div>

    <!-- Zuri -->
    <div class="prop-card" data-type="hotel" data-loc="watamu">
      <div class="prop-img-wrap">
        <img class="prop-img" src="images/hero-zuri.jpg"
             alt="Zuri boutique hotel aerial view — Watamu, Kenya"
             width="900" height="506" loading="lazy" decoding="async">
        <div class="prop-type-badge">Boutique Hotel</div>
      </div>
      <div class="prop-body">
        <div class="prop-location">Watamu · Kilifi County</div>
        <div class="prop-name">Zuri</div>
        <div class="prop-desc">Six beachfront suites directly on Watamu's Indian Ocean shoreline. Book a single suite or the entire property — fully serviced with à la carte dining and curated hospitality.</div>
        <div class="prop-pills">
          <span class="prop-pill">Beachfront</span>
          <span class="prop-pill">6 Suites</span>
          <span class="prop-pill">Full Buyout</span>
          <span class="prop-pill">Watamu Marine Park</span>
        </div>
      </div>
      <div class="prop-footer">
        <div class="prop-stats">
          <div class="prop-stat"><div class="prop-stat-n">6</div><div class="prop-stat-l">Suites</div></div>
          <div class="prop-stat"><div class="prop-stat-n">14</div><div class="prop-stat-l">Guests</div></div>
        </div>
        <a href="zuri.php" class="prop-btn">View Property</a>
      </div>
    </div>

    <!-- Enkare Bofa -->
    <div class="prop-card" data-type="villa" data-loc="kilifi">
      <div class="prop-img-wrap">
        <img class="prop-img" src="images/hero-enkare-bofa.jpg"
             alt="Enkare Bofa beachfront villa — Bofa Road, Kilifi, Kenya"
             width="600" height="450" loading="lazy" decoding="async">
        <div class="prop-type-badge">Private Villa</div>
      </div>
      <div class="prop-body">
        <div class="prop-location">Bofa Road · Kilifi</div>
        <div class="prop-name">Enkare Bofa</div>
        <div class="prop-desc">Five-bedroom beachfront villa on Kilifi's Bofa Road. In-house cook, spacious outdoor areas and relaxed coastal living — ideal for families and groups.</div>
        <div class="prop-pills">
          <span class="prop-pill">In-House Cook</span>
          <span class="prop-pill">Pool</span>
          <span class="prop-pill">Beach Access</span>
        </div>
      </div>
      <div class="prop-footer">
        <div class="prop-stats">
          <div class="prop-stat"><div class="prop-stat-n">5</div><div class="prop-stat-l">Bedrooms</div></div>
          <div class="prop-stat"><div class="prop-stat-n">10</div><div class="prop-stat-l">Guests</div></div>
        </div>
        <a href="enkare-bofa.php" class="prop-btn">View Property</a>
      </div>
    </div>

    <!-- Sandbox -->
    <div class="prop-card" data-type="villa" data-loc="kilifi">
      <div class="prop-img-wrap">
        <img class="prop-img" src="images/hero-sandbox.jpg"
             alt="Sandbox beachfront self-catering villa — Bofa Road, Kilifi, Kenya"
             width="600" height="450" loading="lazy" decoding="async">
        <div class="prop-type-badge">Private Villa</div>
      </div>
      <div class="prop-body">
        <div class="prop-location">Bofa Road · Kilifi</div>
        <div class="prop-name">Sandbox</div>
        <div class="prop-desc">Self-catering beachfront villa for groups who want privacy and flexibility. Four bedrooms, easy beach access and a genuinely relaxed atmosphere on Bofa Road.</div>
        <div class="prop-pills">
          <span class="prop-pill">Self-Catering</span>
          <span class="prop-pill">Pool</span>
          <span class="prop-pill">Beach Nearby</span>
        </div>
      </div>
      <div class="prop-footer">
        <div class="prop-stats">
          <div class="prop-stat"><div class="prop-stat-n">4</div><div class="prop-stat-l">Bedrooms</div></div>
          <div class="prop-stat"><div class="prop-stat-n">8</div><div class="prop-stat-l">Guests</div></div>
        </div>
        <a href="sandbox.php" class="prop-btn">View Property</a>
      </div>
    </div>

    <!-- Maya Ilai -->
    <div class="prop-card" data-type="hotel" data-loc="kilifi">
      <div class="prop-img-wrap">
        <img class="prop-img" src="images/maya_illai/Best1.jpg"
             alt="Maya Ilai eco retreat compound pool — Kilifi, Kenya"
             width="600" height="450" loading="lazy" decoding="async">
        <div class="prop-type-badge">Eco Compound</div>
      </div>
      <div class="prop-body">
        <div class="prop-location">Kilifi · Tribal Dunes</div>
        <div class="prop-name">Maya Ilai</div>
        <div class="prop-desc">Sixteen-unit eco retreat compound within Tribal Dunes — solar powered, desalinated water, communal pool and gardens. Ideal for retreats, weddings and large groups. Adults 16+.</div>
        <div class="prop-pills">
          <span class="prop-pill">Solar Powered</span>
          <span class="prop-pill">16 Units</span>
          <span class="prop-pill">Communal Pool</span>
        </div>
      </div>
      <div class="prop-footer">
        <div class="prop-stats">
          <div class="prop-stat"><div class="prop-stat-n">16</div><div class="prop-stat-l">Units</div></div>
          <div class="prop-stat"><div class="prop-stat-n">16+</div><div class="prop-stat-l">Age Policy</div></div>
        </div>
        <a href="maya_ilai.php" class="prop-btn">View Property</a>
      </div>
    </div>

  </div>
</section>

<!-- ═══ TRIBAL DUNES ═══ -->
<section class="tribal-section" aria-labelledby="tribal-heading">
  <div class="tribal-grid">
    <div class="tribal-img-stack">
      <img class="tribal-img-main" src="images/maya-kobe/Aerial/mayakobe-2.webp"
           alt="Tribal Dunes beachfront village aerial view — Bofa Beach, Kilifi, Kenya"
           width="700" height="525" loading="lazy" decoding="async">
      <img class="tribal-img-accent" src="images/maya_illai/best6.jpg"
           alt="Tribal Dunes lifestyle — Kilifi, Kenya"
           width="360" height="270" loading="lazy" decoding="async">
    </div>
    <div class="tribal-content">
      <div class="eyebrow" style="color:var(--sand);">Kilifi · Kenya</div>
      <h2 class="sec-h sec-h--light" id="tribal-heading">Tribal Dunes —<br><em>One Address,<br>Many Reasons to Stay</em></h2>
      <div class="sec-rule" style="background:rgba(184,150,90,.35);"></div>
      <p class="sec-p sec-p--light" style="margin-bottom:1.6rem;">One large beachfront property in Kilifi where a boutique hotel, an eco compound, a coworking hotel, a restaurant, a café and a kite school all share the same shore. Fully solar powered. Desalinated ocean water. Built for like-minded people.</p>
      <div class="tribal-venues" role="list">
        <div class="tribal-venue" role="listitem"><span class="tribal-venue-ico"><span class="map-ico">H</span></span><span class="tribal-venue-name">Maya Kobe</span><span class="tribal-venue-tag">Boutique Hotel</span></div>
        <div class="tribal-venue" role="listitem"><span class="tribal-venue-ico"><span class="map-ico">W</span></span><span class="tribal-venue-name">Maya Ilai</span><span class="tribal-venue-tag">Eco Compound</span></div>
        <div class="tribal-venue" role="listitem"><span class="tribal-venue-ico"><span class="map-ico">WK</span></span><span class="tribal-venue-name">Off Duty</span><span class="tribal-venue-tag">Coworking Hotel</span></div>
        <div class="tribal-venue" role="listitem"><span class="tribal-venue-ico"><span class="map-ico">F</span></span><span class="tribal-venue-name">Tribal Table</span><span class="tribal-venue-tag">Coming Soon</span></div>
        <div class="tribal-venue" role="listitem"><span class="tribal-venue-ico"><span class="map-ico">C</span></span><span class="tribal-venue-name">Somewhere Café</span><span class="tribal-venue-tag">Coming Soon</span></div>
        <div class="tribal-venue" role="listitem"><span class="tribal-venue-ico"><span class="map-ico">S</span></span><span class="tribal-venue-name">Tribal Kite School</span><span class="tribal-venue-tag">Ocean Sports</span></div>
      </div>
      <a href="tribal-dunes.php" class="btn-ghost btn-ghost--sand">Discover Tribal Dunes →</a>
    </div>
  </div>
</section>

<!-- ═══ HOW IT WORKS ═══ -->
<section class="how-section" aria-labelledby="how-heading">
  <div class="how-grid">
    <div class="how-img-wrap">
      <img class="how-img" src="images/My-Amani-8.jpg"
           alt="Tribal Sand concierge service — Kenya coast"
           width="600" height="750" loading="lazy" decoding="async">
      <img class="how-img-accent" src="images/Pool-Image_Edited.jpg"
           alt="Tribal Sand pool — Kenya"
           width="320" height="240" loading="lazy" decoding="async">
    </div>
    <div>
      <div class="eyebrow">How It Works</div>
      <h2 class="sec-h" id="how-heading">We Handle<br><em>Every Detail</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Navigating a Kenya coast holiday can feel overwhelming — logistics, pricing, activities, transfers. Our concierge team takes care of everything so you arrive and simply enjoy.</p>
      <div class="how-steps">
        <div class="how-step">
          <div class="how-num">1</div>
          <div>
            <div class="how-step-h">Choose Your Property</div>
            <div class="how-step-p">Our team helps match the right property to your group, travel style and budget — from ultra-private villas to boutique hotel suites.</div>
          </div>
        </div>
        <div class="how-step">
          <div class="how-num">2</div>
          <div>
            <div class="how-step-h">Plan With Your Concierge</div>
            <div class="how-step-p">Use our Trip Builder to outline activities, transfers and dining. We send a personalised quote within 24 hours — no payment required at this stage.</div>
          </div>
        </div>
        <div class="how-step">
          <div class="how-num">3</div>
          <div>
            <div class="how-step-h">Arrive and Experience Kenya</div>
            <div class="how-step-p">Your on-site host greets you from the moment you land. Everything is taken care of — you just experience the coast.</div>
          </div>
        </div>
      </div>
      <div class="how-btns">
        <a href="trip-builder.php" class="btn-primary">Plan Your Trip</a>
        <a href="https://wa.me/254115115247" class="btn-ghost btn-ghost--teal" target="_blank" rel="noopener">WhatsApp Us</a>
      </div>
    </div>
  </div>
</section>

<!-- ═══ SUSTAINABILITY ═══ -->
<section class="sustain-section" aria-labelledby="sustain-heading">
  <div class="sustain-grid">
    <div>
      <div class="eyebrow">Sustainability</div>
      <h2 class="sec-h" id="sustain-heading">We Exist Because<br>of <em>This Coastline</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Sustainability at Tribal Sand is infrastructure, not marketing. Every property is designed around it — from solar panels to desalinated water to active marine conservation.</p>
      <div class="sustain-quote">
        <div class="sustain-quote-text">"Your stay supports local people and projects that keep this coastline thriving."</div>
        <div class="sustain-quote-attr">— Tribal Sand · Kenya's North Coast</div>
      </div>
      <div style="margin-top:1.8rem;">
        <a href="sustainability.php" class="btn-primary">Our Sustainability Commitment</a>
      </div>
    </div>
    <div>
      <div class="sustain-pillars">
        <div class="sustain-pillar">
          <div class="sustain-pillar-ico"></div>
          <div class="sustain-pillar-h">Solar Power</div>
          <div class="sustain-pillar-p">Tribal Dunes runs entirely on solar energy. Your stay is powered by the sun, not the grid.</div>
        </div>
        <div class="sustain-pillar">
          <div class="sustain-pillar-ico"></div>
          <div class="sustain-pillar-h">Desalinated Water</div>
          <div class="sustain-pillar-p">Ocean water desalinated on-site. Luxury without depleting Kenya's freshwater reserves.</div>
        </div>
        <div class="sustain-pillar">
          <div class="sustain-pillar-ico"></div>
          <div class="sustain-pillar-h">Turtle Conservation</div>
          <div class="sustain-pillar-p">My Amani's beach is an active conservation zone protecting endangered sea turtle nesting grounds.</div>
        </div>
        <div class="sustain-pillar">
          <div class="sustain-pillar-ico"></div>
          <div class="sustain-pillar-h">Coral Restoration</div>
          <div class="sustain-pillar-p">Active coral growing garden and reef restoration project along the Kilifi coastline.</div>
        </div>
      </div>
      <div class="sustain-data-row">
        <div class="sustain-data-card">
          <div class="sustain-live"><div class="sustain-live-dot"></div><span class="sustain-live-lbl">Live Data</span></div>
          <div class="sustain-data-n">27.59 <span>MWh</span></div>
          <div class="sustain-data-l">Solar Energy Produced</div>
          <div class="sustain-data-note">Tribal Dunes · updated weekly</div>
        </div>
        <div class="sustain-data-card">
          <div class="sustain-live"><div class="sustain-live-dot"></div><span class="sustain-live-lbl">Live Data</span></div>
          <div class="sustain-data-n">21.88 <span>T</span></div>
          <div class="sustain-data-l">CO₂ Emissions Reduced</div>
          <div class="sustain-data-note">= 1,503 trees equivalent</div>
        </div>
        <div class="sustain-data-card">
          <div class="sustain-data-n">~30 <span>kg</span></div>
          <div class="sustain-data-l">Beach Trash Collected</div>
          <div class="sustain-data-note">Per week · every week</div>
        </div>
        <div class="sustain-data-card">
          <div class="sustain-data-n">100 <span>%</span></div>
          <div class="sustain-data-l">Desalinated Water</div>
          <div class="sustain-data-note">No freshwater depletion</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══ EXPERIENCES ═══ -->
<section class="exp-section" aria-labelledby="exp-heading">
  <div style="max-width:580px;margin-bottom:0;">
    <div class="eyebrow" style="color:var(--sand);">Experiences</div>
    <h2 class="sec-h sec-h--light" id="exp-heading">Activities on<br><em>Kenya's North Coast</em></h2>
    <div class="sec-rule" style="background:rgba(184,150,90,.3);"></div>
    <p class="sec-p sec-p--light">From snorkelling Watamu Marine Park to day safaris in Tsavo, kiteboarding to private beach dinners — every activity is arranged by your concierge team and quoted in your 24-hour response.</p>
  </div>
  <div class="exp-grid">
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Ocean &amp; Marine</div><div class="exp-desc">Snorkelling, scuba, deep sea fishing, dolphin watching, dhow cruises and paddleboarding.</div></div>
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Safari &amp; Bush</div><div class="exp-desc">Day trips to Tsavo East and West, Shimba Hills, Amboseli overnight and Arabuko Sokoke night safaris.</div></div>
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Kite &amp; Watersports</div><div class="exp-desc">Kiteboarding courses and rental with Tribal Kite School at both Kilifi and Watamu locations.</div></div>
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Golf</div><div class="exp-desc">Vipingo Ridge — 18-hole PGA-accredited course, five minutes from My Amani villa.</div></div>
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Wellness</div><div class="exp-desc">Private yoga, sunrise meditation, in-villa massage and couples spa treatments on request.</div></div>
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Dining &amp; Celebrations</div><div class="exp-desc">Private beach dinners, rooftop starlight suppers, proposals, anniversaries and milestone events.</div></div>
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Nature &amp; Culture</div><div class="exp-desc">Arabuko Sokoke forest, Gede Ruins, turtle conservation, Swahili cooking and local village visits.</div></div>
    <div class="exp-item"><div class="exp-ico"></div><div class="exp-name">Transfers &amp; Logistics</div><div class="exp-desc">Airport transfers, inter-property moves, driver hire and helicopter charters — all arranged.</div></div>
  </div>
  <div style="text-align:center;margin-top:2.5rem;">
    <a href="activities.php" class="btn-primary">View All Experiences</a>
  </div>
</section>

<!-- ═══ GALLERY STRIP ═══ -->
<div class="gallery-strip" role="complementary" aria-label="Property photo gallery">
  <img class="gal-img" src="images/Maya-Kobe-1.jpeg" alt="Maya Kobe boutique hotel — Kilifi, Kenya" width="400" height="310" loading="lazy" decoding="async">
  <img class="gal-img" src="images/My-Amani-5.jpg"   alt="My Amani beachfront villa — Vipingo, Kenya" width="400" height="310" loading="lazy" decoding="async">
  <img class="gal-img" src="images/updated-hero-banner.jpg" alt="Tribal Sand Kenya coast" width="400" height="310" loading="lazy" decoding="async">
  <img class="gal-img" src="images/My-Amani-1.jpg"   alt="My Amani pool — Vipingo, Kenya" width="400" height="310" loading="lazy" decoding="async">
  <img class="gal-img" src="images/34t.jpg"           alt="Kenya coast activities — Tribal Sand" width="400" height="310" loading="lazy" decoding="async">
</div>

<!-- ═══ REVIEWS ═══ -->
<section class="reviews-section" aria-labelledby="reviews-heading">
  <div class="reviews-header">
    <div class="eyebrow">Guest Reviews</div>
    <h2 class="sec-h" id="reviews-heading">What Our <em>Guests Say</em></h2>
    <div class="sec-rule" style="margin:0 auto 0;"></div>
  </div>
  <div class="reviews-grid">
    <div class="review-card">
      <div class="review-stars" aria-label="5 stars">★★★★★</div>
      <div class="review-text">"Stunning place, great staff — friendly and attentive. They made our visit perfect. Fabulous location, we will be coming back again and again."</div>
      <div class="review-author">— Mr &amp; Mrs B Vyas</div>
    </div>
    <div class="review-card">
      <div class="review-stars" aria-label="5 stars">★★★★★</div>
      <div class="review-text">"We will always remember this stunning place — its vibrance, its calm aura, its unbelievably kind staff. Our five days were filled with astonishment."</div>
      <div class="review-author">— Sonal Patel</div>
    </div>
    <div class="review-card">
      <div class="review-stars" aria-label="5 stars">★★★★★</div>
      <div class="review-text">"Thank you for helping create the most unforgettable birthday. A visual masterpiece — the staff made us feel like family. Every detail was perfect."</div>
      <div class="review-author">— Erin</div>
    </div>
  </div>
</section>

<!-- ═══ TRUST BAR ═══ -->
<div class="trust-bar" role="complementary" aria-label="Guest trust signals">
  <div class="trust-item">
    <div class="trust-item-ico"></div>
    <div>
      <div class="trust-item-n">1,300+</div>
      <div class="trust-item-l">Guests Hosted</div>
    </div>
  </div>
  <div class="trust-divider"></div>
  <div class="trust-item">
    <div class="trust-item-ico">⭐</div>
    <div>
      <div class="trust-item-n">4.8</div>
      <div class="trust-item-l">Average Rating</div>
    </div>
  </div>
  <div class="trust-divider"></div>
  <div class="trust-item">
    <div class="trust-item-ico"></div>
    <div>
      <div class="trust-item-n">40+</div>
      <div class="trust-item-l">Countries Represented</div>
    </div>
  </div>
  <div class="trust-divider"></div>
  <div class="trust-item">
    <div>
      <div class="trust-item-l" style="margin-bottom:.5rem;">Reviewed on</div>
      <div class="trust-platforms">
        <div class="trust-platform">
          <span style="color:#FF5A5F;font-weight:600;font-size:.75rem;">Airbnb</span>
          <span class="trust-platform-stars">★★★★★</span>
        </div>
        <div class="trust-platform">
          <span style="color:#003580;font-weight:600;font-size:.75rem;">Booking</span>
          <span class="trust-platform-stars">★★★★★</span>
        </div>
        <div class="trust-platform">
          <span style="color:#00355F;font-weight:600;font-size:.75rem;">Expedia</span>
          <span class="trust-platform-stars">★★★★★</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ CTA ═══ -->
<section class="cta-section" aria-label="Book your Kenya holiday with Tribal Sand">
  <div class="cta-bg">
    <img src="images/D8.jpg" alt="Tribal Sand Kenya beachfront" width="1920" height="490" loading="lazy" decoding="async">
  </div>
  <div class="cta-inner">
    <div class="cta-eyebrow">Ready to Experience Kenya?</div>
    <h2 class="cta-h">Your <em>Perfect Stay</em><br>Awaits</h2>
    <p class="cta-p">No payment at enquiry stage. Our concierge team reviews every request personally and responds within 24 hours with a tailored quote.</p>
    <div class="cta-btns">
      <a href="trip-builder.php" class="btn-primary">Plan Your Trip</a>
      <a href="contact.php" class="btn-ghost">Contact Us</a>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
// Trust badge rotation
(function(){
  var msgs = [
    'Booked <strong>7 times</strong> in the last 24h',
    '<strong>3 people</strong> viewing properties now',
    '<strong>My Amani</strong> just received an enquiry',
    'Last booking <strong>2 hours ago</strong>',
    '<strong>Zuri</strong> is popular this season',
    '<strong>5 enquiries</strong> received today',
  ];
  var el = document.getElementById('introTrustMsg');
  if (!el) return;
  var i = 0;
  setInterval(function(){
    i = (i + 1) % msgs.length;
    el.style.opacity = '0';
    el.style.transition = 'opacity .3s';
    setTimeout(function(){ el.innerHTML = msgs[i]; el.style.opacity = '1'; }, 300);
  }, 7000);
})();

// Property filter
document.querySelectorAll('.prop-filter').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.querySelectorAll('.prop-filter').forEach(function(b){ b.classList.remove('on'); });
    btn.classList.add('on');
    var f = btn.getAttribute('data-filter');
    document.querySelectorAll('.prop-card').forEach(function(card){
      card.style.display = (f === 'all' || card.getAttribute('data-type') === f || card.getAttribute('data-loc') === f) ? 'flex' : 'none';
    });
  });
});

// Hero slideshow
(function(){
  var slides   = document.querySelectorAll('.hero-slide');
  var dots     = document.querySelectorAll('.hero-dot');
  var badge    = document.getElementById('heroPropBadge');
  var progress = document.getElementById('heroProgress');
  var n = slides.length, cur = 0, timer;
  var DURATION = 6000;

  function startProgress(){
    if (!progress) return;
    progress.classList.remove('running');
    void progress.offsetWidth;
    progress.classList.add('running');
  }

  function goTo(idx){
    slides[cur].classList.remove('active');
    dots[cur].classList.remove('active');
    cur = idx;
    slides[cur].classList.add('active');
    dots[cur].classList.add('active');
    // restart ken burns
    var img = slides[cur].querySelector('img');
    img.style.animation = 'none';
    void img.offsetWidth;
    img.style.animation = '';
    // fade badge
    if (badge){
      badge.style.opacity = '0';
      setTimeout(function(){ badge.textContent = slides[cur].dataset.prop; badge.style.opacity = '1'; }, 320);
    }
    startProgress();
  }

  dots.forEach(function(dot){
    dot.addEventListener('click', function(){
      clearInterval(timer);
      goTo(parseInt(this.dataset.idx));
      timer = setInterval(function(){ goTo((cur+1)%n); }, DURATION);
    });
  });

  startProgress();
  timer = setInterval(function(){ goTo((cur+1)%n); }, DURATION);
})();

// Scroll reveal
var obs = new IntersectionObserver(function(entries){
  entries.forEach(function(e){
    if (e.isIntersecting){ e.target.style.opacity = '1'; e.target.style.transform = 'none'; }
  });
}, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });
document.querySelectorAll('.prop-card,.review-card,.exp-item,.how-step,.sustain-pillar,.tribal-venue').forEach(function(el, i){
  el.style.opacity = '0';
  el.style.transform = 'translateY(18px)';
  el.style.transition = 'opacity .48s ' + (i * 0.05) + 's ease, transform .48s ' + (i * 0.05) + 's ease';
  obs.observe(el);
});
</script>

</body>
</html>
