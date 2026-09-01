<?php require_once 'includes/schema.php'; ?>
<?php
/* ═══ SEO ═══ */
$page_title  = 'Tribal Dunes · Kilifi\'s Beachfront Community · Kenya · Tribal Sand';
$page_desc   = 'Tribal Dunes is Kilifi\'s beachfront community — boutique hotel, eco retreat, coworking, restaurant, café and kite school on one solar-powered property.';
$page_url    = 'https://tribalsand.com/tribal-dunes.php';
$page_image  = asset_url('images/maya-kobe/Aerial/mayakobe-2.webp');
$page_preload = 'images/maya-kobe/Aerial/mayakobe-2.webp';

/* ═══ SCHEMA ═══ */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',         'url' => 'https://tribalsand.com/'],
    ['name' => 'Tribal Dunes', 'url' => 'https://tribalsand.com/tribal-dunes.php'],
]);

$rr_venue_slug = 'tribal-dunes';
?>
<?php include 'includes/head.php'; ?>
<style>
/* ── DESIGN TOKENS ── */
:root{
  --sand:#e8dcc8;--deep-sand:#c9b99a;--sand-gold:#D4B07A;
  --ocean:#2b6b7a;--ocean-d:#1a4a56;--ocean-m:#3d8a9c;
  --foam:#f5f0e8;--drift:#7a6a55;
  --night:#1c1a18;--white:#fdfaf5;
  --border:rgba(201,185,154,.28);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;background:var(--white);color:var(--night);font-weight:400;overflow-x:hidden;-webkit-font-smoothing:antialiased;}
img{display:block;object-fit:cover;}
a{color:var(--ocean);text-decoration:none;}
a:hover{text-decoration:underline;}
::-webkit-scrollbar{width:3px;}::-webkit-scrollbar-thumb{background:var(--border);}

/* ── HERO ── */
.td-hero{
  position:relative;
  min-height:100vh;
  background:#0e2d38 url('images/maya-kobe/Aerial/mayakobe-2.webp') center 35% / cover no-repeat;
  display:flex;align-items:flex-end;
  padding:0 6vw 10vh;
  overflow:hidden;
}
.td-hero::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(to bottom,rgba(10,28,36,.5) 0%,rgba(10,28,36,.72) 55%,rgba(10,28,36,.9) 100%);
}
.td-hero-shape{position:absolute;bottom:0;left:0;width:100%;height:28%;background:var(--white);clip-path:ellipse(55% 100% at 15% 100%);opacity:.06;}
.td-hero-shape2{position:absolute;bottom:0;right:-5%;width:65%;height:38%;background:var(--sand);clip-path:ellipse(75% 100% at 80% 100%);opacity:.05;}
.td-hero-eyebrow{font-size:.58rem;letter-spacing:.32em;text-transform:uppercase;color:rgba(232,220,200,.82);font-weight:500;margin-bottom:1.1rem;}
.td-hero-content{position:relative;z-index:2;max-width:800px;}
.td-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.4rem,5vw,4.4rem);
  font-weight:300;color:#fff;
  line-height:1.1;margin-bottom:1.4rem;
  text-shadow:0 2px 24px rgba(0,0,0,.28);
}
.td-hero h1 em{font-style:italic;color:var(--sand-gold);}
.td-hero-sub{font-size:.88rem;color:rgba(255,255,255,.84);line-height:1.9;max-width:520px;margin-bottom:2.2rem;font-weight:400;}
.td-hero-meta{display:flex;gap:1.8rem;align-items:center;flex-wrap:wrap;}
.td-hero-badge{
  display:inline-flex;align-items:center;gap:.5rem;
  font-size:.56rem;letter-spacing:.22em;text-transform:uppercase;
  padding:.5rem 1.2rem;border:1px solid rgba(232,220,200,.3);
  color:rgba(232,220,200,.82);background:rgba(0,0,0,.2);
  backdrop-filter:blur(6px);
}
.td-hero-scroll{
  font-size:.52rem;letter-spacing:.22em;text-transform:uppercase;
  color:rgba(255,255,255,.82);
}

/* ── INTRO STRIP ── */
.td-intro{
  background:var(--ocean-d);
  padding:3.2rem 6vw;
  text-align:center;
}
.td-intro-label{font-size:.56rem;letter-spacing:.3em;text-transform:uppercase;color:rgba(232,220,200,.82);margin-bottom:.8rem;}
.td-intro h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.6rem);
  font-weight:300;color:#fff;
  line-height:1.2;margin-bottom:1rem;
}
.td-intro h2 em{font-style:italic;color:var(--sand-gold);}
.td-intro-p{font-size:.88rem;color:rgba(255,255,255,.85);line-height:1.92;max-width:680px;margin:0 auto;}

/* ── LAYOUT WRAPPER ── */
.td-outer{max-width:1260px;margin:0 auto;padding:0 5vw;}

/* ── SECTION LABELS ── */
.td-sec-label{font-size:.6rem;letter-spacing:.3em;text-transform:uppercase;color:var(--ocean);font-weight:500;margin-bottom:.7rem;}
.td-sec-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,2.8vw,2.5rem);
  font-weight:400;color:var(--night);
  line-height:1.15;margin-bottom:.5rem;
}
.td-sec-h em{font-style:italic;color:var(--ocean);}
.td-sec-rule{width:22px;height:1px;background:var(--deep-sand);margin-bottom:1.6rem;}
.td-divider{height:1px;background:var(--border);margin:4rem 0;}
.td-body-p{font-size:.96rem;line-height:1.92;color:rgba(28,26,24,.84);margin-bottom:1.5rem;font-weight:400;}
.td-intro-p-body{font-size:1.05rem;line-height:1.9;color:rgba(28,26,24,.8);margin-bottom:1.8rem;font-weight:300;}

/* ── VENUES SECTION ── */
.td-venues-section{padding:5rem 0 2rem;}
.td-venue-grid{display:flex;flex-direction:column;gap:1px;background:var(--border);margin:2.2rem 0;}
.td-venue-row{
  background:var(--white);
  padding:1.6rem 1.8rem;
  display:flex;gap:1.4rem;align-items:flex-start;
  transition:background .18s;
}
.td-venue-row:hover{background:var(--foam);}
.td-venue-ico{font-size:1.4rem;flex-shrink:0;margin-top:.05rem;line-height:1;}
.td-venue-body{flex:1;}
.td-venue-header{display:flex;align-items:baseline;gap:1rem;flex-wrap:wrap;margin-bottom:.3rem;}
.td-venue-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.25rem;font-weight:400;color:var(--night);
}
.td-venue-desc{font-size:.8rem;color:#4a3d2e;line-height:1.82;}
.td-venue-tags{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.65rem;}
.td-venue-tag{
  font-size:.5rem;letter-spacing:.14em;text-transform:uppercase;
  padding:.22rem .6rem;border:1px solid rgba(43,107,122,.35);color:var(--ocean);
}
.td-venue-status{
  font-size:.48rem;letter-spacing:.16em;text-transform:uppercase;
  padding:.18rem .55rem;font-weight:500;
}
.td-venue-status.active{background:rgba(43,107,122,.1);color:var(--ocean);border:1px solid rgba(43,107,122,.3);}
.td-venue-status.soon{background:rgba(201,185,154,.18);color:var(--drift);border:1px solid rgba(201,185,154,.4);}

/* ── PULL QUOTE ── */
.td-quote{
  margin:3rem 0;padding:2rem 2.4rem 2rem 2.8rem;
  border-left:2px solid var(--ocean);
  background:rgba(43,107,122,.04);
}
.td-quote p{
  font-family:'Cormorant Garamond',serif;
  font-size:1.4rem;font-weight:400;font-style:italic;
  color:var(--ocean-d);line-height:1.65;
}
.td-quote cite{
  display:block;font-family:'Jost',sans-serif;
  font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;
  color:var(--drift);margin-top:.9rem;font-style:normal;opacity:.75;
}

/* ── STAT BAR ── */
.td-stat-bar{
  display:flex;background:var(--ocean-d);
  margin:3rem 0;
}
.td-stat-item{
  flex:1;padding:1.6rem 1.2rem;
  border-right:1px solid rgba(232,220,200,.08);
  text-align:center;
}
.td-stat-item:last-child{border-right:none;}
.td-stat-n{
  font-family:'Cormorant Garamond',serif;
  font-size:1.8rem;font-weight:300;color:var(--sand);line-height:1;
}
.td-stat-l{
  font-size:.52rem;letter-spacing:.2em;text-transform:uppercase;
  color:rgba(232,220,200,.82);margin-top:.3rem;
}

/* ── SUSTAINABILITY ── */
.td-sustain-section{padding:4rem 0;}
.td-sustain-block{
  background:linear-gradient(135deg,var(--ocean-d) 0%,var(--ocean) 100%);
  padding:3.5rem 4rem;margin:2.5rem 0;
}
.td-sustain-eyebrow{
  font-size:.54rem;letter-spacing:.3em;text-transform:uppercase;
  color:rgba(232,220,200,.82);margin-bottom:.9rem;
}
.td-sustain-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.6rem,2.5vw,2.2rem);
  font-weight:300;color:#fff;margin-bottom:.6rem;line-height:1.18;
}
.td-sustain-h em{font-style:italic;color:var(--sand-gold);}
.td-sustain-sub{font-size:.82rem;color:rgba(255,255,255,.85);line-height:1.8;margin-bottom:2.4rem;max-width:580px;}
.td-sustain-pillars{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;}
.td-pillar{
  background:rgba(255,255,255,.06);
  border:1px solid rgba(232,220,200,.1);
  padding:1.6rem 1.4rem;
}
.td-pillar-ico{font-size:1.4rem;margin-bottom:.7rem;line-height:1;}
.td-pillar-h{
  font-family:'Cormorant Garamond',serif;
  font-size:1.08rem;font-weight:400;color:#fff;margin-bottom:.4rem;
}
.td-pillar-p{font-size:.76rem;color:rgba(255,255,255,.85);line-height:1.8;}
.td-pillar-data{
  display:inline-block;margin-top:.75rem;
  font-size:.54rem;letter-spacing:.18em;text-transform:uppercase;
  color:var(--sand-gold);font-weight:500;
}

/* ── WHO STAYS HERE ── */
.td-audience-section{padding:4rem 0;}
.td-audience-intro{font-size:.96rem;line-height:1.9;color:rgba(28,26,24,.8);margin-bottom:2.2rem;font-weight:400;max-width:700px;}
.td-callout-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1px;background:var(--border);margin:2rem 0;}
.td-callout-item{background:var(--foam);padding:1.8rem 1.6rem;}
.td-callout-ico{font-size:1.3rem;margin-bottom:.65rem;line-height:1;}
.td-callout-h{
  font-family:'Cormorant Garamond',serif;
  font-size:1.08rem;font-weight:400;color:var(--night);margin-bottom:.4rem;
}
.td-callout-p{font-size:.78rem;color:#4a3d2e;line-height:1.82;}
.td-callout-link{
  display:inline-block;margin-top:.8rem;
  font-size:.54rem;letter-spacing:.16em;text-transform:uppercase;
  color:var(--ocean);border-bottom:1px solid rgba(43,107,122,.3);
  padding-bottom:.1rem;
}

/* ── PHOTO GRID ── */
.td-photo-section{padding:2rem 0 4rem;}
.td-photo-grid{
  display:grid;
  grid-template-columns:1.4fr 1fr 1fr;
  grid-template-rows:260px 260px;
  gap:4px;margin:2rem 0;
}
.td-photo-grid img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease;}
.td-photo-grid img:hover{transform:scale(1.02);}
.td-photo-main{grid-row:span 2;}

/* ── BREADCRUMB ── */
.td-breadcrumb{
  padding:1.2rem 6vw;
  font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;
  color:var(--drift);border-bottom:1px solid var(--border);
  background:var(--white);
}
.td-breadcrumb a{color:var(--drift);}
.td-breadcrumb a:hover{color:var(--ocean);text-decoration:none;}
.td-breadcrumb-sep{margin:0 .6rem;opacity:.4;}

/* ── CTA SECTION ── */
.td-cta-section{
  background:var(--foam);
  border-top:1px solid var(--border);
  padding:5rem 6vw;text-align:center;
}
.td-cta-eyebrow{
  font-size:.56rem;letter-spacing:.3em;text-transform:uppercase;
  color:var(--drift);opacity:.6;margin-bottom:.8rem;
}
.td-cta h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,2.8vw,2.6rem);
  font-weight:300;color:var(--night);margin-bottom:1rem;line-height:1.2;
}
.td-cta h2 em{font-style:italic;color:var(--ocean);}
.td-cta-p{
  font-size:.86rem;color:#4a3d2e;line-height:1.92;
  max-width:500px;margin:0 auto 2.5rem;font-weight:300;
}
.td-cta-btns{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;}
.td-btn-primary{
  display:inline-block;
  font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;
  padding:.85rem 2.2rem;background:var(--ocean);color:#fff;
  transition:background .22s;
}
.td-btn-primary:hover{background:var(--ocean-d);text-decoration:none;}
.td-btn-ghost{
  display:inline-block;
  font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;
  padding:.85rem 2.2rem;border:1px solid rgba(43,107,122,.35);color:var(--ocean);
  transition:all .22s;
}
.td-btn-ghost:hover{border-color:var(--ocean);background:rgba(43,107,122,.05);text-decoration:none;}

/* ── FULL IMAGE ── */
.td-full-img-wrap{margin:2.5rem 0;position:relative;}
.td-full-img{width:100%;height:340px;object-fit:cover;object-position:center 40%;}
.td-full-img-cap{
  font-size:.56rem;letter-spacing:.14em;text-transform:uppercase;
  color:var(--drift);padding:.55rem 0 0;opacity:.5;
}

/* ── RESPONSIVE ── */
@media(max-width:960px){
  .td-sustain-pillars{grid-template-columns:1fr;}
  .td-callout-grid{grid-template-columns:1fr 1fr;}
  .td-sustain-block{padding:2.5rem 2rem;}
  .td-photo-grid{
    grid-template-columns:1fr 1fr;
    grid-template-rows:220px 220px 220px;
  }
  .td-photo-main{grid-row:span 1;}
}
@media(max-width:720px){
  .td-hero{padding:0 5vw 12vh;}
  .td-callout-grid{grid-template-columns:1fr;}
  .td-stat-bar{flex-wrap:wrap;}
  .td-stat-item{flex:0 0 50%;}
  .td-photo-grid{
    grid-template-columns:1fr;
    grid-template-rows:auto;
  }
  .td-photo-grid img{height:220px;}
  .td-photo-main{grid-row:span 1;}
  .td-venue-row{flex-direction:column;gap:.8rem;}
}
</style>
<body class="ts-nav-transparent">

<?php include 'includes/header.php'; ?>

<!-- ═══════════════════════════════════════════════ HERO -->
<section class="td-hero">
  <div class="td-hero-shape"></div>
  <div class="td-hero-shape2"></div>
  <div class="td-hero-content">
    <div class="td-hero-eyebrow">Tribal Dunes · Kilifi · Kenya Coast</div>
    <h1>Kilifi's beachfront community for travellers who want more than <em>a place to sleep.</em></h1>
    <p class="td-hero-sub">One large solar-powered beachfront property on Bofa Road, Kilifi. A boutique hotel, an eco compound, a coworking hotel, a restaurant, a café and a kite school — each one distinct, all within walking distance of each other.</p>
    <div class="td-hero-meta">
      <span class="td-hero-badge">6 venues · one plot</span>
      <span class="td-hero-badge">100% solar powered</span>
      <span class="td-hero-badge">Bofa Beach · Kilifi</span>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════ BREADCRUMB -->
<nav class="td-breadcrumb" aria-label="Breadcrumb">
  <a href="index.php">Home</a>
  <span class="td-breadcrumb-sep">›</span>
  <span>Tribal Dunes</span>
</nav>

<!-- ═══════════════════════════════════════════════ INTRO STRIP -->
<section class="td-intro">
  <div class="td-intro-label">The Concept</div>
  <h2>One address. <em>Many reasons to stay.</em></h2>
  <p class="td-intro-p">Most hospitality projects start with a brief. Tribal Dunes started with a different question: what could a stretch of Kenyan coastline become if you gave it enough time, enough thought, and enough commitment to the people and ecosystem already there? The result is a beachfront property that operates more like a small coastal town than a hotel complex.</p>
</section>

<!-- ═══════════════════════════════════════════════ VENUES -->
<section class="td-venues-section">
  <div class="td-outer">
    <div class="td-sec-label">The Ecosystem</div>
    <h2 class="td-sec-h">What you will find at <em>Tribal Dunes</em></h2>
    <div class="td-sec-rule"></div>
    <p class="td-body-p">Six distinct operations. One shared plot. All within walking distance. Each one was designed independently — all of them work better because of the others.</p>

    <div class="td-venue-grid">

      <div class="td-venue-row">
        <div class="td-venue-ico"><span class="map-ico">H</span></div>
        <div class="td-venue-body">
          <div class="td-venue-header">
            <div class="td-venue-name">Maya Kobe</div>
            <span class="td-venue-status active">Active</span>
          </div>
          <p class="td-venue-desc">Luxury beachfront boutique hotel with five ocean-facing suites, a 20m pool and chef-led dining. Balinese-influenced architecture. Designed for couples, honeymooners and small groups who want a fully serviced stay directly on the Indian Ocean — with a restaurant, café, kite school and eco compound within walking distance.</p>
          <div class="td-venue-tags">
            <span class="td-venue-tag">Boutique Hotel</span>
            <span class="td-venue-tag">5 Suites</span>
            <span class="td-venue-tag">20m Pool</span>
            <span class="td-venue-tag">Chef-Led Dining</span>
          </div>
        </div>
      </div>

      <div class="td-venue-row">
        <div class="td-venue-ico"><span class="map-ico">W</span></div>
        <div class="td-venue-body">
          <div class="td-venue-header">
            <div class="td-venue-name">Maya Ilai</div>
            <span class="td-venue-status active">Active</span>
          </div>
          <p class="td-venue-desc">An adults-only eco retreat compound with 16 units — eight three-bedroom villas and eight studio apartments — fully solar powered and running entirely on desalinated ocean water. Communal pool, bar, gardens and electric bikes. Walking distance to the beach through Somewhere Café. Designed for large groups: retreats, weddings, corporate offsites, kitesurf camps. Adults 16+.</p>
          <div class="td-venue-tags">
            <span class="td-venue-tag">Eco Compound</span>
            <span class="td-venue-tag">16 Units</span>
            <span class="td-venue-tag">Solar Powered</span>
            <span class="td-venue-tag">Adults 16+</span>
          </div>
        </div>
      </div>

      <div class="td-venue-row">
        <div class="td-venue-ico"><span class="map-ico">WK</span></div>
        <div class="td-venue-body">
          <div class="td-venue-header">
            <div class="td-venue-name">Off Duty</div>
            <span class="td-venue-status soon">Coming Soon</span>
          </div>
          <p class="td-venue-desc">A beachfront coworking hotel built around people who work well and live actively. Strong Wi-Fi, well-designed rooms, direct beach access. The coworking space is open to non-hotel guests — local entrepreneurs and remote workers in Kilifi use it daily. The Tribal Kite School is thirty metres from the front door.</p>
          <div class="td-venue-tags">
            <span class="td-venue-tag">Coworking Hotel</span>
            <span class="td-venue-tag">Digital Nomads</span>
            <span class="td-venue-tag">Beachfront</span>
          </div>
        </div>
      </div>

      <div class="td-venue-row">
        <div class="td-venue-ico"><span class="map-ico">S</span></div>
        <div class="td-venue-body">
          <div class="td-venue-header">
            <div class="td-venue-name">Tribal Kite School Kilifi</div>
            <span class="td-venue-status active">Active</span>
          </div>
          <p class="td-venue-desc">Located directly in front of Off Duty on Bofa Beach. Lessons, equipment rental and water safety for beginners and experienced riders. The kite school is not an amenity — it is the reason a significant portion of the Tribal Dunes community exists at all. Kitesurfers come for the school and stay for everything else.</p>
          <div class="td-venue-tags">
            <span class="td-venue-tag">Ocean Sports</span>
            <span class="td-venue-tag">Kitesurfing Kilifi</span>
            <span class="td-venue-tag">All Levels</span>
          </div>
        </div>
      </div>

      <div class="td-venue-row">
        <div class="td-venue-ico"><span class="map-ico">F</span></div>
        <div class="td-venue-body">
          <div class="td-venue-header">
            <div class="td-venue-name">Tribal Table</div>
            <span class="td-venue-status active">Now Open</span>
          </div>
          <p class="td-venue-desc">An elevated restaurant and cocktail bar beside Maya Kobe. Open to the public. Local ingredients, considered design and the kind of setting that makes a Tuesday feel like a reason to dress well. A destination in its own right — the dining anchor for Tribal Dunes and the wider Kilifi community.</p>
          <div class="td-venue-tags">
            <span class="td-venue-tag">Restaurant & Bar</span>
            <span class="td-venue-tag">Open to Public</span>
          </div>
          <a href="https://www.tribaltablekenya.com" class="td-callout-link" target="_blank" rel="noopener">Visit tribaltablekenya.com &rarr;</a>
        </div>
      </div>

      <div class="td-venue-row">
        <div class="td-venue-ico"><span class="map-ico">C</span></div>
        <div class="td-venue-body">
          <div class="td-venue-header">
            <div class="td-venue-name">Somewhere Café</div>
            <span class="td-venue-status soon">Coming Soon</span>
          </div>
          <p class="td-venue-desc">Beachfront lifestyle café serving healthy food, pizza, good coffee and fresh juices — with a pool, live music and the kind of light that makes everything look better. The social centre of the Tribal Dunes ecosystem. Equally useful for a focused work morning or a long, slow afternoon doing nothing at the beach.</p>
          <div class="td-venue-tags">
            <span class="td-venue-tag">Beachfront Café</span>
            <span class="td-venue-tag">Pool</span>
            <span class="td-venue-tag">Live Music</span>
          </div>
        </div>
      </div>

    </div><!-- /venue-grid -->

    <div class="td-quote">
      <p>"The best hospitality doesn't just give you a room. It gives you a place. Tribal Dunes is our attempt at building that — not as a concept, but as something you can actually walk around in."</p>
      <cite>— Tribal Sand · Kilifi</cite>
    </div>

    <div class="td-divider"></div>

    <!-- STAT BAR -->
    <div class="td-stat-bar">
      <div class="td-stat-item"><div class="td-stat-n">6</div><div class="td-stat-l">Venues on one plot</div></div>
      <div class="td-stat-item"><div class="td-stat-n">16</div><div class="td-stat-l">Maya Ilai units</div></div>
      <div class="td-stat-item"><div class="td-stat-n">5</div><div class="td-stat-l">Maya Kobe suites</div></div>
      <div class="td-stat-item"><div class="td-stat-n">100%</div><div class="td-stat-l">Solar powered</div></div>
    </div>

  </div>
</section>

<!-- ═══════════════════════════════════════════════ SUSTAINABILITY -->
<section class="td-sustain-section">
  <div class="td-outer">
    <div class="td-sec-label">The Infrastructure</div>
    <h2 class="td-sec-h">Powered by the sun. <em>Run on the ocean.</em></h2>
    <div class="td-sec-rule"></div>
    <p class="td-body-p">Tribal Dunes is fully solar powered. The desalination system converts seawater into fresh water across the entire site — Maya Kobe, Off Duty, Somewhere Café, Tribal Table, the kite school and Maya Ilai all operate on it. Rainwater is harvested and managed. These are not press release commitments. They are the infrastructure the property runs on every day.</p>
    <p class="td-body-p">The coral growing garden is a longer-term project — a reef restoration initiative to replant coral along the section of coastline the property shares. It sits alongside the beach cleanup programme and community education work that runs with local residents. The coastline is the asset. A hospitality brand that depletes it is working against itself.</p>

    <div class="td-sustain-block">
      <div class="td-sustain-eyebrow">Sustainability at Tribal Dunes · Kilifi</div>
      <h3 class="td-sustain-h">The things that <em>actually matter.</em></h3>
      <p class="td-sustain-sub">Infrastructure-led sustainability — building systems that make environmental responsibility the path of least resistance — is a better approach than voluntary pledges that depend on individual behaviour.</p>

      <div class="td-sustain-pillars">
        <div class="td-pillar">
          <div class="td-pillar-ico"></div>
          <div class="td-pillar-h">Fully Solar Powered</div>
          <p class="td-pillar-p">All six operations at Tribal Dunes run on solar energy. Zero reliance on Kenya's national grid. Your stay is powered by the sun — every meal, every checkout, every light left on.</p>
          <span class="td-pillar-data">100% solar · all venues</span>
        </div>
        <div class="td-pillar">
          <div class="td-pillar-ico"></div>
          <div class="td-pillar-h">Desalinated Ocean Water</div>
          <p class="td-pillar-p">Seawater is converted into fresh water on site. No reliance on Kenya's freshwater reserves. Luxury without freshwater depletion — from the swimming pools to the restaurant kitchen to the showers.</p>
          <span class="td-pillar-data">0 freshwater reliance</span>
        </div>
        <div class="td-pillar">
          <div class="td-pillar-ico"></div>
          <div class="td-pillar-h">Coral Growing Garden</div>
          <p class="td-pillar-p">Active reef restoration project replanting coral along the coastline the property shares. Community education programmes on marine ecosystem protection run alongside the restoration work.</p>
          <span class="td-pillar-data">Active restoration · ongoing</span>
        </div>
        <div class="td-pillar">
          <div class="td-pillar-ico"></div>
          <div class="td-pillar-h">Rainwater Harvesting</div>
          <p class="td-pillar-p">Comprehensive rainwater harvesting and management across the full property. Combined with desalination and solar, Tribal Dunes operates a near self-sufficient water and energy system on Bofa Beach.</p>
          <span class="td-pillar-data">Full property · year-round</span>
        </div>
      </div>
    </div>

    <div class="td-full-img-wrap">
      <img class="td-full-img" src="images/maya-kobe/Aerial/mayakobe-2.webp" alt="Tribal Dunes from above · Bofa Beach, Kilifi · Kenya">
      <div class="td-full-img-cap">Tribal Dunes from above · Bofa Beach · Kilifi, Kenya</div>
    </div>

  </div>
</section>

<!-- ═══════════════════════════════════════════════ WHO STAYS HERE -->
<section class="td-audience-section">
  <div class="td-outer">
    <div class="td-divider"></div>
    <div class="td-sec-label">Who Tribal Dunes Is For</div>
    <h2 class="td-sec-h">It depends on <em>what you need.</em></h2>
    <div class="td-sec-rule"></div>
    <p class="td-audience-intro">This is one of the more useful things about the Tribal Dunes format: the same property works very differently depending on why you are here. The kitesurfer at Somewhere Café and the honeymooners at Maya Kobe and the team from Nairobi at Maya Ilai are all part of the same place — and they make it more interesting for each other without ever being in each other's way.</p>

    <div class="td-callout-grid">
      <div class="td-callout-item">
        <div class="td-callout-ico"><span class="map-ico">T</span></div>
        <div class="td-callout-h">Couples & Honeymooners</div>
        <p class="td-callout-p">Maya Kobe gives you privacy, excellent service and a fully curated stay. Tribal Table is a five-minute walk. The ocean is twenty metres away. You don't need a car or a plan.</p>
        <a href="maya-kobe.php" class="td-callout-link">Explore Maya Kobe →</a>
      </div>
      <div class="td-callout-item">
        <div class="td-callout-ico"><span class="map-ico">WK</span></div>
        <div class="td-callout-h">Digital Nomads & Remote Workers</div>
        <p class="td-callout-p">Off Duty was designed around this. Strong Wi-Fi, a coworking space open to hotel guests and the public, Somewhere Café below and the kite school directly in front for when you're done for the day.</p>
        <a href="contact.php" class="td-callout-link">Enquire about Off Duty →</a>
      </div>
      <div class="td-callout-item">
        <div class="td-callout-ico"><span class="map-ico">S</span></div>
        <div class="td-callout-h">Kitesurfers</div>
        <p class="td-callout-p">The kite school is at the beach. Off Duty is directly behind it. Somewhere Café is between them. The entire rhythm of the day — water, food, work, water, food, sunset — is already built in.</p>
        <a href="activities.php" class="td-callout-link">About the kite school →</a>
      </div>
      <div class="td-callout-item">
        <div class="td-callout-ico"><span class="map-ico">E</span></div>
        <div class="td-callout-h">Retreats & Large Groups</div>
        <p class="td-callout-p">Maya Ilai's 16 units handle very large groups — weddings, corporate offsites, wellness retreats, kitesurf camps. The communal spaces, pool and proximity to the restaurant and café make it self-contained.</p>
        <a href="maya_ilai.php" class="td-callout-link">Explore Maya Ilai →</a>
      </div>
      <div class="td-callout-item">
        <div class="td-callout-ico"><span class="map-ico">W</span></div>
        <div class="td-callout-h">Conscious Travellers</div>
        <p class="td-callout-p">Fully solar powered, desalinated water, active coral restoration and a majority local team. Tribal Dunes is built for travellers who want the experience to leave the coastline better than they found it.</p>
        <a href="sustainability.php" class="td-callout-link">Our sustainability story →</a>
      </div>
      <div class="td-callout-item">
        <div class="td-callout-ico"><span class="map-ico">S</span></div>
        <div class="td-callout-h">Families & Wellness Groups</div>
        <p class="td-callout-p">Multiple properties, multiple vibes, one concierge team. Families can spread across Maya Ilai's villas. Wellness retreats get communal facilities, private programming space and the Indian Ocean on the doorstep.</p>
        <a href="trip-builder.php" class="td-callout-link">Build your trip →</a>
      </div>
    </div>

  </div>
</section>

<!-- ═══════════════════════════════════════════════ PHOTO GRID -->
<section class="td-photo-section">
  <div class="td-outer">
    <div class="td-divider"></div>
    <div class="td-sec-label">The Property</div>
    <h2 class="td-sec-h">Tribal Dunes · <em>Kilifi, Kenya</em></h2>
    <div class="td-sec-rule"></div>

    <div class="td-photo-grid">
      <img class="td-photo-main" src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe · Tribal Dunes beachfront community · Kilifi Kenya">
      <img src="images/maya_illai/Best1.jpg" alt="Maya Ilai eco compound · Kilifi">
      <img src="images/maya_illai/best6.jpg" alt="Maya Ilai · Tribal Dunes · Kenya Coast">
      <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg" alt="Maya Kobe pool and beach · Bofa Beach Kilifi">
    </div>
    <div class="td-full-img-cap" style="text-align:right;">Maya Kobe · Maya Ilai · Bofa Beach · Kilifi · Kenya</div>

  </div>
</section>

<!-- ═══════════════════════════════════════════════ CTA -->
<section class="td-cta-section">
  <div class="td-cta-eyebrow">Plan Your Stay at Tribal Dunes · Kilifi</div>
  <h2 class="td-cta">Kenya as it was <em>meant to be experienced.</em></h2>
  <p class="td-cta-p">Whether you're coming for a week at Maya Kobe, a month at Off Duty or a long weekend at Maya Ilai — our concierge team will handle everything from airport transfer to daily experiences. We respond within 24 hours. No payment required at enquiry stage.</p>
  <div class="td-cta-btns">
    <a href="maya-kobe.php" class="td-btn-primary">Explore Maya Kobe</a>
    <a href="interactive-site-map.php" class="td-btn-ghost">View Interactive Map →</a>
    <a href="trip-builder.php" class="td-btn-ghost">Plan Your Trip →</a>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
</body>
</html>
