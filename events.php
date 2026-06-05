<?php
require_once 'includes/schema.php';

/* ── SEO Variables ── */
$page_title  = 'Events · Weddings & Celebrations · Tribal Sand Kenya';
$page_desc   = 'Host your wedding, celebration or corporate event on Kenya\'s North Coast with Tribal Sand. Beachfront venues in Kilifi and Watamu — full planning support.';
$page_url    = 'https://tribalsand.com/events.php';
$page_image  = 'https://tribalsand.com/images/Maya-Kobe-1-hero.webp';

/* ── Structured Data ── */
$page_schema =
    ts_schema_org() .
    ts_schema_breadcrumb([
        ['name' => 'Home',   'url' => 'https://tribalsand.com/'],
        ['name' => 'Events', 'url' => 'https://tribalsand.com/events.php'],
    ]);

include 'includes/head.php';
?>

<style>
/* ── PAGE TOKENS (mirrors main.css) ── */
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;--sand-faint:#FAF6EE;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.14);
  --ts-sand:#B8965A;--ts-sand-lt:#D4B07A;--ts-teal:#1E5C6B;--ts-teal-d:#102F3A;
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;font-size:1rem;font-weight:400;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;overflow-x:hidden;line-height:1.7;}
img{display:block;object-fit:cover;max-width:100%;}
a{text-decoration:none;color:inherit;}
::-webkit-scrollbar{width:3px;}::-webkit-scrollbar-thumb{background:var(--border);}
.ev-hero-h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.2rem,5vw,3.8rem);font-weight:300;color:#fff;line-height:1.1;margin-bottom:1rem;}

/* ── EVENTS HERO ── */
.ev-hero{
  background:var(--teal-d);
  padding:108px 5vw 80px;
  text-align:center;
  position:relative;
  overflow:hidden;
}
.ev-hero::before{
  content:'';
  position:absolute;inset:0;
  background:radial-gradient(ellipse at 40% 100%, rgba(184,150,90,.08) 0%, transparent 65%),
             radial-gradient(ellipse at 80% 10%, rgba(30,92,107,.7) 0%, transparent 60%);
  pointer-events:none;
}
.ev-hero::after{
  content:'';
  position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(to right, transparent, rgba(184,150,90,.2), transparent);
}
.ev-hero-inner{
  position:relative;z-index:1;
  max-width:800px;margin:0 auto;
}
.ev-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.85);
  margin-bottom:1.2rem;
}
.ev-h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.4rem,5vw,4.2rem);
  font-weight:300;color:#fff;
  line-height:1.05;margin-bottom:1.4rem;
}
.ev-h1 em{font-style:italic;color:var(--sand-lt);}
.ev-sub{
  font-size:1rem;font-weight:400;
  color:rgba(212,196,172,.78);
  line-height:1.88;max-width:600px;margin:0 auto 2.2rem;
}
.ev-hero-ctas{
  display:flex;flex-wrap:wrap;gap:1rem;justify-content:center;
}
.btn-sand{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;
  background:var(--sand);color:var(--teal-d);
  border:1px solid var(--sand);
  transition:background .22s,border-color .22s;
}
.btn-sand:hover{background:var(--sand-lt);border-color:var(--sand-lt);color:var(--teal-d);}
.btn-outline{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:400;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;background:none;
  border:1px solid rgba(255,255,255,.28);
  color:rgba(255,255,255,.8);
  transition:border-color .22s,color .22s;
}
.btn-outline:hover{border-color:rgba(255,255,255,.65);color:#fff;}

/* ── BREADCRUMB ── */
.breadcrumb{
  padding:.9rem 52px;background:var(--white);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;list-style:none;
}
.breadcrumb a{font-family:'Jost',sans-serif;font-size:.75rem;letter-spacing:.1em;color:var(--light);transition:color .2s;}
.breadcrumb a:hover{color:var(--teal);}
.breadcrumb-sep{font-size:.75rem;color:rgba(184,150,90,.4);}
.breadcrumb-curr{font-family:'Jost',sans-serif;font-size:.75rem;letter-spacing:.1em;color:var(--sand);}

/* ── INTRO SECTION ── */
.ev-intro{
  padding:80px 5vw 72px;
  background:var(--white);
}
.ev-intro-inner{
  max-width:1200px;margin:0 auto;
  display:grid;grid-template-columns:1fr 1fr;gap:5rem;align-items:start;
}
.ev-intro-text .sec-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);display:flex;align-items:center;gap:.65rem;
  margin-bottom:.65rem;
}
.ev-intro-text .sec-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-intro-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.9rem,3vw,3rem);
  font-weight:400;color:var(--dark);line-height:1.05;
  margin-bottom:1.4rem;
}
.ev-intro-h em{font-style:italic;color:var(--teal);}
.ev-intro-p{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.88;margin-bottom:1rem;}
.ev-intro-features{
  display:flex;flex-direction:column;gap:.75rem;
  margin-top:1.8rem;
}
.ev-feat{
  display:flex;align-items:flex-start;gap:.9rem;
  padding:.95rem 1.1rem;
  border:1px solid var(--border);
  background:var(--off);
  transition:border-color .2s;
}
.ev-feat:hover{border-color:rgba(184,150,90,.28);}
.ev-feat-icon{font-size:1.1rem;flex-shrink:0;margin-top:.05rem;}
.ev-feat-text{font-size:1rem;font-weight:400;color:var(--mid);line-height:1.65;}
.ev-feat-text strong{color:var(--dark);font-weight:500;}

/* ── EVENT TYPE CARDS ── */
.ev-types{
  padding:88px 5vw;background:var(--off);
}
.ev-types-inner{max-width:1200px;margin:0 auto;}
.ev-types-header{text-align:center;margin-bottom:3.5rem;}
.ev-types-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);display:inline-flex;align-items:center;gap:.65rem;
  margin-bottom:.65rem;
}
.ev-types-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-types-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:400;color:var(--dark);line-height:1.05;
}
.ev-types-h em{font-style:italic;color:var(--teal);}
.ev-cards-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(310px,1fr));
  gap:1.6rem;
}
.ev-card{
  background:var(--white);
  border:1px solid var(--border);
  padding:2.2rem 2rem 2rem;
  transition:box-shadow .22s,border-color .22s;
  display:flex;flex-direction:column;gap:.7rem;
  position:relative;overflow:hidden;
}
.ev-card::before{
  content:'';
  position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(to right, var(--teal-d), var(--teal));
  opacity:0;transition:opacity .25s;
}
.ev-card:hover{
  box-shadow:0 8px 36px rgba(16,47,58,.11);
  border-color:rgba(30,92,107,.22);
}
.ev-card:hover::before{opacity:1;}
.ev-card-icon{font-size:1.8rem;line-height:1;}
.ev-card-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.35rem;font-weight:400;color:var(--dark);
}
.ev-card-desc{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.78;flex:1;}
.ev-card-link{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--sand);
  border-bottom:1px solid rgba(184,150,90,.3);
  padding-bottom:.08rem;
  align-self:flex-start;
  transition:color .2s,border-color .2s;
  margin-top:.3rem;
}
.ev-card-link:hover{color:var(--teal);border-color:var(--teal);}

/* ── VENUES SECTION ── */
.ev-venues{
  padding:88px 5vw;
  background:var(--white);
}
.ev-venues-inner{max-width:1200px;margin:0 auto;}
.ev-venues-header{margin-bottom:3rem;}
.ev-venues-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);display:flex;align-items:center;gap:.65rem;margin-bottom:.65rem;
}
.ev-venues-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-venues-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.9rem,3vw,2.8rem);
  font-weight:400;color:var(--dark);line-height:1.05;
}
.ev-venues-h em{font-style:italic;color:var(--teal);}
.ev-venues-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(270px,1fr));
  gap:1.5rem;
}
.ev-venue{
  border:1px solid var(--border);
  background:var(--off);
  padding:1.9rem 1.7rem;
  transition:box-shadow .22s,border-color .22s;
}
.ev-venue:hover{box-shadow:0 6px 28px rgba(16,47,58,.09);border-color:rgba(184,150,90,.28);}
.ev-venue-tag{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.7rem;font-weight:500;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--teal-d);
  background:var(--sand-pale);
  padding:.28rem .7rem;
  margin-bottom:.9rem;
}
.ev-venue-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.3rem;font-weight:400;color:var(--dark);
  margin-bottom:.2rem;
}
.ev-venue-loc{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:400;letter-spacing:.1em;
  color:var(--light);text-transform:uppercase;
  margin-bottom:.9rem;
}
.ev-venue-desc{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.75;margin-bottom:1rem;}
.ev-venue-pills{
  display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:1.1rem;
}
.ev-pill{
  font-family:'Jost',sans-serif;
  font-size:.7rem;font-weight:400;letter-spacing:.08em;
  color:var(--mid);
  border:1px solid var(--border);
  padding:.22rem .65rem;
  background:var(--white);
}
.ev-venue-link{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--sand);
  border-bottom:1px solid rgba(184,150,90,.3);
  padding-bottom:.08rem;
  transition:color .2s,border-color .2s;
}
.ev-venue-link:hover{color:var(--teal);border-color:var(--teal);}

/* ── WHY SECTION ── */
.ev-why{
  background:var(--teal-d);
  padding:88px 5vw;
  position:relative;overflow:hidden;
}
.ev-why::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 20% 50%, rgba(30,92,107,.6) 0%, transparent 60%);
  pointer-events:none;
}
.ev-why-inner{max-width:1100px;margin:0 auto;position:relative;z-index:1;}
.ev-why-header{text-align:center;margin-bottom:3.5rem;}
.ev-why-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:rgba(184,150,90,.85);
  display:inline-flex;align-items:center;gap:.65rem;
  margin-bottom:.8rem;
}
.ev-why-eyebrow::before{content:'';width:18px;height:1px;background:rgba(184,150,90,.85);flex-shrink:0;}
.ev-why-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:300;color:#fff;line-height:1.05;
}
.ev-why-h em{font-style:italic;color:var(--sand-lt);}
.ev-why-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
  gap:1.4rem;
}
.ev-why-item{
  background:rgba(255,255,255,.04);
  border:1px solid rgba(184,150,90,.12);
  padding:1.8rem 1.6rem;
  transition:background .2s,border-color .2s;
}
.ev-why-item:hover{background:rgba(255,255,255,.07);border-color:rgba(184,150,90,.22);}
.ev-why-num{
  font-family:'Cormorant Garamond',serif;
  font-size:2rem;font-weight:300;
  color:rgba(184,150,90,.3);
  margin-bottom:.5rem;line-height:1;
}
.ev-why-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.15rem;font-weight:400;
  color:#fff;margin-bottom:.5rem;
}
.ev-why-text{font-size:1rem;font-weight:400;color:rgba(212,196,172,.7);line-height:1.75;}

/* ── BOTTOM CTA ── */
.ev-cta{
  background:var(--off);
  padding:88px 5vw;
  border-top:1px solid var(--border);
}
.ev-cta-inner{
  max-width:700px;margin:0 auto;text-align:center;
}
.ev-cta-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);
  display:inline-flex;align-items:center;gap:.65rem;
  margin-bottom:.9rem;
}
.ev-cta-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-cta-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:400;color:var(--dark);line-height:1.05;margin-bottom:.9rem;
}
.ev-cta-h em{font-style:italic;color:var(--teal);}
.ev-cta-p{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.88;margin-bottom:.5rem;}
.ev-cta-note{
  font-family:'Jost',sans-serif;
  font-size:.8rem;font-weight:400;
  color:var(--light);letter-spacing:.05em;
  margin-bottom:2rem;
}
.ev-cta-btns{
  display:flex;flex-wrap:wrap;gap:1rem;justify-content:center;margin-bottom:1.6rem;
}
.btn-teal{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;
  background:var(--teal-d);color:var(--sand-lt);
  border:1px solid var(--teal-d);
  transition:background .22s,border-color .22s;
}
.btn-teal:hover{background:var(--teal);border-color:var(--teal);}
.btn-teal-ghost{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:400;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;background:none;
  border:1px solid var(--border);
  color:var(--teal);
  transition:border-color .22s,background .22s,color .22s;
}
.btn-teal-ghost:hover{border-color:var(--teal);background:rgba(30,92,107,.05);color:var(--teal-d);}
.ev-cta-wa{
  display:inline-flex;align-items:center;gap:.55rem;
  font-family:'Jost',sans-serif;
  font-size:.85rem;font-weight:400;
  color:var(--mid);
  transition:color .2s;
}
.ev-cta-wa:hover{color:var(--teal);}
.ev-cta-wa-dot{
  width:8px;height:8px;border-radius:50%;
  background:#25D366;flex-shrink:0;
}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .ev-intro-inner{grid-template-columns:1fr;gap:2.5rem;}
}
@media(max-width:768px){
  .ev-hero{padding:88px 5vw 60px;}
  .ev-types{padding:60px 5vw;}
  .ev-venues{padding:60px 5vw;}
  .ev-why{padding:64px 5vw;}
  .ev-cta{padding:64px 5vw;}
  .ev-cards-grid{grid-template-columns:1fr;}
  .ev-venues-grid{grid-template-columns:1fr;}
  .ev-why-grid{grid-template-columns:1fr;}
  .breadcrumb{padding:.9rem 20px;}
}
@media(max-width:480px){
  .ev-hero{padding:84px 5vw 52px;}
  .ev-hero-ctas{flex-direction:column;align-items:center;}
  .ev-cta-btns{flex-direction:column;align-items:center;}
}
</style>
</head>

<body>

<?php include 'includes/header.php'; ?>

<!-- ═══ ELEGANT HERO ═══ -->
<section class="ev-hero">
  <div class="ev-hero-inner">
    <h1 class="ev-hero-h1">Weddings &amp; Celebrations on <em>Kenya's Coast</em></h1>
    <div class="ev-eyebrow">Watamu &middot; Kilifi &middot; Vipingo &middot; Kenya</div>
    <h1 class="ev-h1">Events &amp; Celebrations &middot; <em>Kenya's North Coast</em></h1>
    <p class="ev-sub">From intimate beachfront weddings to corporate retreats and private celebrations — Tribal Sand's concierge team handles every detail on Kenya's most beautiful coastline.</p>
    <div class="ev-hero-ctas">
      <a href="trip-builder.html" class="btn-sand">Tell Us About Your Event &rarr;</a>
      <a href="contact.php" class="btn-outline">Contact the Team</a>
    </div>
  </div>
</section>

<!-- ═══ BREADCRUMB ═══ -->
<nav class="breadcrumb" aria-label="Breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">&rsaquo;</span>
  <span class="breadcrumb-curr">Events &amp; Celebrations</span>
</nav>

<!-- ═══ INTRO ═══ -->
<section class="ev-intro">
  <div class="ev-intro-inner">
    <div class="ev-intro-text">
      <div class="sec-eyebrow">Full Planning Support</div>
      <h2 class="ev-intro-h">Private events on <em>the Kenya Coast</em></h2>
      <p class="ev-intro-p">Whether you are planning an intimate beachfront wedding for twenty or a corporate retreat for a hundred, Tribal Sand's three coastal locations offer extraordinary settings with full service support behind them.</p>
      <p class="ev-intro-p">Our concierge team coordinates every element — venue preparation, catering, décor, photography, music, logistics and accommodation. You focus on the occasion. We handle the rest.</p>
      <p class="ev-intro-p">From the coral-fringed shores of <a href="zuri.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.22);">Zuri in Watamu</a> to the creek-side elegance of <a href="maya-kobe.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.22);">Maya Kobe in Kilifi</a> and the ultra-private estate of <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.22);">My Amani in Vipingo</a> — the Kenya coast provides a backdrop unlike any other.</p>
    </div>
    <div class="ev-intro-features">
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>24-hour quote response</strong> — tell us your dates, group size and vision. We come back with a full proposal within one working day.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>No payment required at enquiry</strong> — explore options, compare properties and receive detailed quotes before committing to anything.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>Trusted supplier network</strong> — established relationships with the best caterers, photographers, florists, DJs and event stylists on the Kenya coast.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>Three coastal locations</strong> — Watamu, Kilifi and Vipingo. Each distinct in character, all offering the same uncompromising level of service.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>Full buyout available</strong> — Zuri, Maya Kobe and My Amani can each be taken on exclusive use, giving your group complete privacy.</div>
      </div>
    </div>
  </div>
</section>

<!-- ═══ EVENT TYPE CARDS ═══ -->
<section class="ev-types" id="event-types">
  <div class="ev-types-inner">
    <div class="ev-types-header">
      <div class="ev-types-eyebrow">What We Arrange</div>
      <h2 class="ev-types-h">Every kind of <em>celebration</em></h2>
    </div>
    <div class="ev-cards-grid">

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Destination Weddings</div>
        <p class="ev-card-desc">Boutique beachfront weddings on Kenya's North Coast. Barefoot ceremonies in the sand, candlelit dinners under the stars, intimate settings for 10 to 50+ guests. <a href="zuri.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">Zuri</a>, <a href="maya-kobe.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">Maya Kobe</a> and <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">My Amani</a> all offer full property buyouts with dedicated event support.</p>
        <a href="trip-builder.html" class="ev-card-link">Enquire about weddings &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Corporate Retreats</div>
        <p class="ev-card-desc">Off-site meetings, leadership retreats, product launches and team building on the Kenya coast. <a href="maya_ilai.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">Maya Ilai eco compound</a> is ideal for larger groups; <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">My Amani</a> for executive retreats requiring complete privacy. Reliable connectivity and event space throughout.</p>
        <a href="trip-builder.html" class="ev-card-link">Enquire about retreats &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Private Celebrations</div>
        <p class="ev-card-desc">Milestone birthdays, anniversaries, family reunions and surprise parties. Full decoration coordination, custom menus, personalised styling and entertainment — all arranged by your concierge with complete discretion.</p>
        <a href="trip-builder.html" class="ev-card-link">Enquire about celebrations &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Honeymoons</div>
        <p class="ev-card-desc">Curated honeymoon packages across Tribal Sand properties — private beach dinners, in-villa massage, sunset dhow cruises on Kilifi Creek, champagne arrivals and complete seclusion. Kenya's coast is one of Africa's most romantic destinations.</p>
        <a href="trip-builder.html" class="ev-card-link">Plan your honeymoon &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Group Getaways</div>
        <p class="ev-card-desc">Full property buyouts for friend groups and extended families. Coordinated activities — kitesurfing, safaris, deep sea fishing, dhow cruises, bush dinners — all arranged through a single concierge point of contact for your whole group.</p>
        <a href="activities.php" class="ev-card-link">See all activities &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Private Dining Events</div>
        <p class="ev-card-desc">Beach dinners with white-linen service and the Indian Ocean at your feet. Swahili feasts, sunset cocktail receptions on Kilifi Creek, rooftop starlight suppers. The Tribal Table concept — coming soon — will offer elevated private dining experiences across all locations.</p>
        <a href="trip-builder.html" class="ev-card-link">Arrange a dinner &rarr;</a>
      </div>

    </div>
  </div>
</section>

<!-- ═══ VENUES / PROPERTIES ═══ -->
<section class="ev-venues" id="venues">
  <div class="ev-venues-inner">
    <div class="ev-venues-header">
      <div class="ev-venues-eyebrow">Event Venues</div>
      <h2 class="ev-venues-h">Properties best suited <em>for events</em></h2>
    </div>
    <div class="ev-venues-grid">

      <div class="ev-venue">
        <div class="ev-venue-tag">Intimate Weddings &middot; Honeymoons</div>
        <div class="ev-venue-name">Zuri</div>
        <div class="ev-venue-loc">Watamu, Kilifi County</div>
        <p class="ev-venue-desc">Six ocean-facing suites on a private stretch of Watamu beach. Full buyout for up to 14 guests makes Zuri the perfect setting for intimate destination weddings, honeymoon escapes and small group celebrations.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">6 Suites</span>
          <span class="ev-pill">Up to 14 guests</span>
          <span class="ev-pill">Buyout available</span>
          <span class="ev-pill">Watamu Marine Park</span>
        </div>
        <a href="zuri.php" class="ev-venue-link">View Zuri &rarr;</a>
      </div>

      <div class="ev-venue">
        <div class="ev-venue-tag">Boutique Weddings &middot; Group Celebrations</div>
        <div class="ev-venue-name">Maya Kobe</div>
        <div class="ev-venue-loc">Bofa Beach, Kilifi</div>
        <p class="ev-venue-desc">Five suites plus the Prestige Suite on a dramatic elevated beachfront above Kilifi Creek. Rooftop terrace, lush gardens and a Swahili architectural aesthetic make Maya Kobe the most romantic event venue on the coast.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">6 Suites inc. Prestige</span>
          <span class="ev-pill">Rooftop terrace</span>
          <span class="ev-pill">Buyout available</span>
          <span class="ev-pill">Creek views</span>
        </div>
        <a href="maya-kobe.php" class="ev-venue-link">View Maya Kobe &rarr;</a>
      </div>

      <div class="ev-venue">
        <div class="ev-venue-tag">Ultra-Private &middot; Executive Retreats</div>
        <div class="ev-venue-name">My Amani</div>
        <div class="ev-venue-loc">Vipingo, Kilifi County</div>
        <p class="ev-venue-desc">A full private villa estate on Vipingo's secluded beachfront. Adjacent to Vipingo Ridge Golf Club. Ideal for executive retreats, ultra-private family celebrations and exclusive group buyouts with total seclusion.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">Full villa estate</span>
          <span class="ev-pill">Golf course adjacent</span>
          <span class="ev-pill">Total privacy</span>
          <span class="ev-pill">Turtle conservation</span>
        </div>
        <a href="my-amani.php" class="ev-venue-link">View My Amani &rarr;</a>
      </div>

      <div class="ev-venue">
        <div class="ev-venue-tag">Large Weddings &middot; Corporate Groups</div>
        <div class="ev-venue-name">Maya Ilai</div>
        <div class="ev-venue-loc">Kilifi Creek</div>
        <p class="ev-venue-desc">An expansive eco-compound on Kilifi Creek — ideal for larger wedding groups, corporate team retreats and multi-day events. Multiple accommodation units, communal spaces and direct creek access in a lush, shaded setting.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">Large group capacity</span>
          <span class="ev-pill">Eco-compound</span>
          <span class="ev-pill">Creek access</span>
          <span class="ev-pill">Corporate ready</span>
        </div>
        <a href="maya_ilai.php" class="ev-venue-link">View Maya Ilai &rarr;</a>
      </div>

    </div>
  </div>
</section>

<!-- ═══ WHY TRIBAL SAND ═══ -->
<section class="ev-why" id="why-tribal-sand">
  <div class="ev-why-inner">
    <div class="ev-why-header">
      <div class="ev-why-eyebrow">Why Tribal Sand</div>
      <h2 class="ev-why-h">What makes the <em>difference</em></h2>
    </div>
    <div class="ev-why-grid">

      <div class="ev-why-item">
        <div class="ev-why-num">01</div>
        <div class="ev-why-title">Dedicated concierge team</div>
        <p class="ev-why-text">One point of contact handles every element of your event — from initial enquiry through to checkout day. No handoffs, no gaps, no surprises.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">02</div>
        <div class="ev-why-title">No payment at enquiry</div>
        <p class="ev-why-text">Explore all options, receive full proposals and compare venues before committing to anything. We believe the planning process should be enjoyable, not pressured.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">03</div>
        <div class="ev-why-title">24-hour quote response</div>
        <p class="ev-why-text">Tell us your vision, group size and preferred dates. We respond with a detailed, itemised quote within one working day — faster during quieter periods.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">04</div>
        <div class="ev-why-title">Trusted supplier network</div>
        <p class="ev-why-text">We work with the best caterers, photographers, florists, sound engineers and event stylists on Kenya's North Coast — vetted, experienced and fully briefed on our standards.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">05</div>
        <div class="ev-why-title">Three distinct coastal locations</div>
        <p class="ev-why-text">Watamu, Kilifi and Vipingo — each with its own character, all accessible from Mombasa or Malindi airports. We can help you choose the right property for your event type and size.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">06</div>
        <div class="ev-why-title">Activities for your whole group</div>
        <p class="ev-why-text">Beyond the event itself — kitesurfing, safaris, deep sea fishing, dhow cruises and more. A full programme of <a href="activities.php" style="color:var(--sand-lt);border-bottom:1px solid rgba(184,150,90,.3);">activities on Kenya's coast</a> arranged around your event schedule.</p>
      </div>

    </div>
  </div>
</section>

<!-- ═══ BOTTOM CTA ═══ -->
<section class="ev-cta">
  <div class="ev-cta-inner">
    <div class="ev-cta-eyebrow">Start the Conversation</div>
    <h2 class="ev-cta-h">Tell us about your <em>event</em></h2>
    <p class="ev-cta-p">Whether your plans are firm or still forming — reach out. We respond within 24 hours with venue options, availability and a full proposal tailored to your occasion.</p>
    <div class="ev-cta-note">No payment required at the enquiry stage.</div>
    <div class="ev-cta-btns">
      <a href="trip-builder.html" class="btn-teal">Plan Your Event &rarr;</a>
      <a href="contact.php" class="btn-teal-ghost">Contact Us</a>
    </div>
    <a href="https://wa.me/254115115247" class="ev-cta-wa" target="_blank" rel="noopener noreferrer">
      <span class="ev-cta-wa-dot"></span>
      WhatsApp the team &rarr;
    </a>
  </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
/* ── SCROLL-BASED NAV BEHAVIOUR ── */
(function(){
  var nav = document.querySelector('.ts-nav');
  if(!nav) return;
  function onScroll(){
    if(window.scrollY > 60) nav.classList.add('scrolled60');
    else nav.classList.remove('scrolled60');
  }
  window.addEventListener('scroll', onScroll, {passive:true});
  onScroll();
})();
</script>

</body>
</html>
