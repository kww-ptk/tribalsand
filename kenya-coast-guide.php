<?php
require_once 'includes/schema.php';

/* ── SEO ── */
$page_title  = 'Kenya Coast Travel Guide · Watamu · Kilifi · Vipingo · Tribal Sand';
$page_desc   = 'The complete travel guide to Kenya\'s North Coast — Watamu, Kilifi and Vipingo. The best beaches, hotels, activities and travel tips from Tribal Sand.';
$page_url    = 'https://tribalsand.com/kenya-coast-guide.php';
$page_image  = asset_url('images/zuri/Aerial/zuri-3.webp');
$page_preload = 'images/zuri/Aerial/zuri-3.webp';

/* ── FAQ data ── */
$faqs = [
    [
        'q' => 'Is the Kenya coast safe for tourists?',
        'a' => 'Yes. Kenya\'s North Coast — Watamu, Kilifi and Vipingo — is very safe and welcoming for tourists. The coastal communities are accustomed to international visitors and the relaxed, hospitable atmosphere is one of the most frequently praised aspects of the region. Standard travel precautions apply. Our team provides up-to-date local guidance to all guests.',
    ],
    [
        'q' => 'What is the best beach in Kenya?',
        'a' => 'Watamu Beach and Bofa Beach in Kilifi are consistently rated among Kenya\'s finest. Watamu is renowned for its pristine white sand, crystal-clear water and immediate proximity to the Watamu Marine National Park. Bofa Beach in Kilifi offers a longer, quieter stretch of sand with a more local, unhurried character. Both are exceptional.',
    ],
    [
        'q' => 'What language is spoken on the Kenya coast?',
        'a' => 'Swahili (Kiswahili) is the primary local language on the Kenya coast and forms the basis of a rich, centuries-old coastal culture. English is widely spoken at hotels, restaurants and in tourist areas. You will find the Swahili phrase "Karibu" (welcome) is offered everywhere — it reflects the genuine hospitality of the region.',
    ],
    [
        'q' => 'Do I need a visa to visit Kenya?',
        'a' => 'Yes. Most nationalities require a visa to enter Kenya, but the process is straightforward. Kenya operates an Electronic Travel Authorisation (eTA) system, applied for online at etakenya.go.ke before travel. The fee is USD 30. Processing is typically completed within 72 hours. Check the latest requirements with your local Kenyan embassy or consulate.',
    ],
    [
        'q' => 'What currency is used in Kenya?',
        'a' => 'The Kenyan Shilling (KES) is the official currency. US Dollars are widely accepted at hotels, restaurants and tourist businesses along the North Coast. Major credit cards are accepted at Tribal Sand properties. ATMs are available in Watamu, Kilifi and Malindi. We recommend carrying some Shillings for local markets and smaller purchases.',
    ],
];

/* ── Schema ── */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',               'url' => 'https://tribalsand.com/'],
    ['name' => 'Kenya Coast Guide',  'url' => 'https://tribalsand.com/kenya-coast-guide.php'],
]);
$page_schema .= ts_schema_faq($faqs);

require_once 'includes/head.php';
?>
<style>
/* ── TOKENS ── */
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--border:rgba(184,150,90,.14);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;font-size:1rem;font-weight:400;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;overflow-x:hidden;}
img{display:block;object-fit:cover;}
a{text-decoration:none;color:inherit;}
::-webkit-scrollbar{width:3px;}::-webkit-scrollbar-thumb{background:var(--border);}

/* ── HERO ── */
.kg-hero{
  position:relative;height:55vh;min-height:480px;
  background:var(--teal-d) url('images/zuri/Aerial/zuri-3.webp') center 35% / cover no-repeat;
  display:flex;align-items:center;justify-content:center;
  text-align:center;overflow:hidden;
}
.kg-hero::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(to bottom,rgba(16,47,58,.4) 0%,rgba(16,47,58,.78) 100%);
}
.kg-hero-content{position:relative;z-index:2;max-width:820px;padding:0 5vw;}
.kg-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.34em;text-transform:uppercase;
  color:rgba(184,150,90,.9);margin-bottom:1.3rem;
}
.kg-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.4rem,5vw,4rem);font-weight:300;
  color:#fff;line-height:1.06;margin-bottom:1.4rem;
}
.kg-hero-sub{
  font-family:'Jost',sans-serif;font-size:.9rem;
  color:rgba(255,255,255,.82);line-height:1.88;
  max-width:600px;margin:0 auto;
}

/* ── BREADCRUMB ── */
.kg-breadcrumb{
  background:#fff;padding:.85rem 5vw;
  border-bottom:1px solid var(--border);
}
.kg-breadcrumb nav{
  max-width:1200px;margin:0 auto;
  display:flex;align-items:center;gap:.5rem;
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.05em;color:rgba(107,96,80,.65);
}
.kg-breadcrumb a{color:var(--sand);transition:color .2s;}
.kg-breadcrumb a:hover{color:var(--teal-d);}

/* ── OVERVIEW ── */
.kg-overview{background:#fff;padding:6rem 5vw;}
.kg-overview-inner{max-width:900px;margin:0 auto;}
.kg-eyebrow-sm{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;
}
.kg-overview h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);line-height:1.12;margin-bottom:1.8rem;
}
.kg-overview p{
  font-family:'Jost',sans-serif;font-size:.9rem;
  color:var(--mid);line-height:1.88;margin-bottom:1.1rem;
}
.kg-overview p:last-child{margin-bottom:0;}
.kg-overview p a{color:var(--sand);transition:color .2s;}
.kg-overview p a:hover{color:var(--teal-d);}

/* ── DESTINATIONS ── */
.kg-destinations{background:var(--off);padding:6rem 5vw;}
.kg-destinations-inner{max-width:1200px;margin:0 auto;}
.kg-section-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;text-align:center;
}
.kg-section-h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);text-align:center;margin-bottom:3rem;
}
.kg-dest-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.8rem;}
.kg-dest-card{
  background:#fff;border:1px solid var(--border);
  overflow:hidden;transition:box-shadow .25s,transform .25s;
  display:flex;flex-direction:column;
}
.kg-dest-card:hover{box-shadow:0 12px 40px rgba(16,47,58,.1);transform:translateY(-3px);}
.kg-dest-img{height:220px;overflow:hidden;position:relative;}
.kg-dest-img img{width:100%;height:100%;object-fit:cover;transition:transform .55s ease;}
.kg-dest-card:hover .kg-dest-img img{transform:scale(1.04);}
.kg-dest-badge{
  position:absolute;bottom:.9rem;left:.9rem;
  background:rgba(16,47,58,.82);backdrop-filter:blur(8px);
  border:1px solid rgba(184,150,90,.2);
  font-family:'Jost',sans-serif;font-size:.5rem;
  letter-spacing:.2em;text-transform:uppercase;
  color:rgba(184,150,90,.85);padding:.32rem .7rem;
}
.kg-dest-body{padding:1.6rem;flex:1;display:flex;flex-direction:column;}
.kg-dest-region{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.18em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.3rem;
}
.kg-dest-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.5rem;font-weight:400;
  color:var(--teal-d);margin-bottom:.8rem;
}
.kg-dest-desc{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:var(--mid);line-height:1.8;flex:1;margin-bottom:1.4rem;
}
.kg-dest-link{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.64rem;letter-spacing:.18em;text-transform:uppercase;
  color:var(--teal-d);border:1px solid var(--border);
  padding:.62rem 1.2rem;text-align:center;align-self:flex-start;
  transition:background .22s,border-color .22s,color .22s;
}
.kg-dest-link:hover{background:var(--teal-d);border-color:var(--teal-d);color:#fff;}

/* ── BEACHES ── */
.kg-beaches{background:#fff;padding:6rem 5vw;}
.kg-beaches-inner{max-width:1100px;margin:0 auto;}
.kg-beach-grid{display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-top:3rem;}
.kg-beach-item{
  border-left:3px solid var(--sand);
  padding:1.5rem 1.5rem 1.5rem 1.8rem;
  background:var(--off);
}
.kg-beach-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.2rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.2rem;
}
.kg-beach-sub{
  font-family:'Jost',sans-serif;font-size:.62rem;
  letter-spacing:.12em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.7rem;
}
.kg-beach-desc{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:var(--mid);line-height:1.78;
}

/* ── WHEN TO VISIT ── */
.kg-when{background:var(--off);padding:6rem 5vw;}
.kg-when-inner{max-width:1100px;margin:0 auto;}
.kg-when-grid{display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-top:3rem;}
.kg-season{padding:2.2rem;border:1px solid var(--border);}
.kg-season-good{background:#fff;border-top:3px solid #4CAF82;}
.kg-season-avoid{background:#fff;border-top:3px solid rgba(184,150,90,.4);}
.kg-season-label{
  font-family:'Jost',sans-serif;font-size:.54rem;
  letter-spacing:.24em;text-transform:uppercase;margin-bottom:.5rem;
}
.kg-season-good .kg-season-label{color:#4CAF82;}
.kg-season-avoid .kg-season-label{color:rgba(184,150,90,.65);}
.kg-season-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.4rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.9rem;
}
.kg-season ul{list-style:none;display:flex;flex-direction:column;gap:.45rem;}
.kg-season li{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:var(--mid);display:flex;align-items:flex-start;gap:.6rem;line-height:1.5;
}
.kg-season li::before{
  content:'';width:5px;height:5px;border-radius:50%;
  flex-shrink:0;margin-top:.4rem;
}
.kg-season-good li::before{background:#4CAF82;}
.kg-season-avoid li::before{background:rgba(184,150,90,.5);}

/* ── GETTING THERE ── */
.kg-getting{background:#fff;padding:6rem 5vw;}
.kg-getting-inner{max-width:1100px;margin:0 auto;}
.kg-routes{display:grid;grid-template-columns:repeat(3,1fr);gap:1.8rem;margin-top:3rem;}
.kg-route{
  padding:2rem;border:1px solid var(--border);
  text-align:center;background:var(--off);
}
.kg-route-icon{font-size:2rem;color:var(--sand);margin-bottom:1rem;}
.kg-route-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.15rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.6rem;
}
.kg-route-desc{
  font-family:'Jost',sans-serif;font-size:.8rem;
  color:var(--mid);line-height:1.72;
}
.kg-route-desc a{color:var(--sand);transition:color .2s;}
.kg-route-desc a:hover{color:var(--teal-d);}
.kg-getting-note{
  margin-top:2rem;padding:1.4rem 2rem;
  background:rgba(184,150,90,.06);border:1px solid rgba(184,150,90,.18);
  text-align:center;
}
.kg-getting-note p{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:var(--mid);line-height:1.7;
}
.kg-getting-note a{color:var(--sand);transition:color .2s;}
.kg-getting-note a:hover{color:var(--teal-d);}

/* ── WHERE TO STAY ── */
.kg-stay{background:var(--teal-d);padding:6rem 5vw;}
.kg-stay-inner{max-width:1200px;margin:0 auto;}
.kg-stay-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.8);margin-bottom:.8rem;text-align:center;
}
.kg-stay h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:#fff;text-align:center;margin-bottom:.8rem;
}
.kg-stay-sub{
  font-family:'Jost',sans-serif;font-size:.84rem;
  color:rgba(255,255,255,.65);text-align:center;
  margin-bottom:3rem;line-height:1.7;
}
.kg-stay-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:rgba(184,150,90,.12);}
.kg-stay-prop{
  background:rgba(16,47,58,.5);padding:1.6rem 2rem;
  display:flex;align-items:flex-start;gap:1.2rem;
  transition:background .22s;
}
.kg-stay-prop:hover{background:rgba(184,150,90,.07);}
.kg-stay-prop-img{
  width:72px;height:56px;flex-shrink:0;overflow:hidden;
  border:1px solid rgba(184,150,90,.15);
}
.kg-stay-prop-img img{width:100%;height:100%;object-fit:cover;opacity:.8;transition:opacity .2s;}
.kg-stay-prop:hover .kg-stay-prop-img img{opacity:1;}
.kg-stay-prop-info{}
.kg-stay-prop-type{
  font-family:'Jost',sans-serif;font-size:.52rem;
  letter-spacing:.2em;text-transform:uppercase;
  color:rgba(184,150,90,.7);margin-bottom:.25rem;
}
.kg-stay-prop-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.1rem;font-weight:400;color:#fff;margin-bottom:.2rem;
}
.kg-stay-prop-loc{
  font-family:'Jost',sans-serif;font-size:.68rem;
  color:rgba(255,255,255,.5);margin-bottom:.5rem;
}
.kg-stay-prop-link{
  font-family:'Jost',sans-serif;font-size:.6rem;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--sand-lt);transition:color .2s;
}
.kg-stay-prop-link:hover{color:#fff;}

/* ── ACTIVITIES ── */
.kg-activities{background:var(--off);padding:6rem 5vw;}
.kg-activities-inner{max-width:1200px;margin:0 auto;}
.kg-act-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;margin-top:3rem;}
.kg-act-tile{
  background:#fff;border:1px solid var(--border);
  padding:2rem 1.6rem;text-align:center;
  transition:border-color .22s,box-shadow .22s;
}
.kg-act-tile:hover{border-color:var(--sand);box-shadow:0 6px 24px rgba(16,47,58,.07);}
.kg-act-icon{font-size:1.7rem;color:var(--sand);margin-bottom:.9rem;}
.kg-act-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.1rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.5rem;
}
.kg-act-desc{
  font-family:'Jost',sans-serif;font-size:.76rem;
  color:var(--mid);line-height:1.72;
}
.kg-activities-more{
  text-align:center;margin-top:2.2rem;
  font-family:'Jost',sans-serif;font-size:.84rem;color:var(--mid);
}
.kg-activities-more a{color:var(--sand);transition:color .2s;}
.kg-activities-more a:hover{color:var(--teal-d);}

/* ── FAQ ── */
.kg-faq{background:#fff;padding:6rem 5vw;}
.kg-faq-inner{max-width:820px;margin:0 auto;}
.kg-faq h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.6rem);font-weight:400;
  color:var(--teal-d);margin-bottom:2.5rem;text-align:center;
}
.kg-faq-item{
  border-bottom:1px solid var(--border);
  overflow:hidden;
}
.kg-faq-item:first-of-type{border-top:1px solid var(--border);}
.kg-faq-btn{
  width:100%;background:none;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:1.3rem 0;text-align:left;
}
.kg-faq-q{
  font-family:'Jost',sans-serif;font-size:.9rem;
  font-weight:500;color:var(--teal-d);line-height:1.4;
}
.kg-faq-icon{
  font-size:1.1rem;color:var(--sand);flex-shrink:0;
  font-weight:300;line-height:1;font-family:'Jost',sans-serif;
  transition:transform .2s;
}
.kg-faq-body{
  display:none;padding:0 0 1.3rem;
}
.kg-faq-body.open{display:block;}
.kg-faq-body p{
  font-family:'Jost',sans-serif;font-size:.85rem;
  color:var(--mid);line-height:1.88;
}

/* ── CTA STRIP ── */
.kg-cta-strip{
  background:var(--teal-d);padding:5.5rem 5vw;text-align:center;
  position:relative;overflow:hidden;
}
.kg-cta-strip::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 50% 50%,rgba(184,150,90,.08) 0%,transparent 65%);
  pointer-events:none;
}
.kg-cta-strip-inner{position:relative;z-index:1;}
.kg-cta-strip p{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.5rem,2.8vw,2.4rem);font-weight:300;
  color:#fff;font-style:italic;
  margin-bottom:.75rem;
}
.kg-cta-strip-sub{
  font-family:'Jost',sans-serif;font-size:.85rem;
  color:rgba(255,255,255,.7);margin-bottom:2.2rem;
}
.kg-cta-btn{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2.4rem;background:var(--sand);
  color:var(--teal-d);border:1px solid var(--sand);
  font-weight:500;transition:all .22s;
}
.kg-cta-btn:hover{background:var(--sand-lt);border-color:var(--sand-lt);}

/* ── RESPONSIVE ── */
@media(max-width:980px){
  .kg-dest-grid{grid-template-columns:1fr 1fr;}
  .kg-routes{grid-template-columns:1fr 1fr;}
  .kg-stay-grid{grid-template-columns:1fr;}
  .kg-act-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:660px){
  .kg-dest-grid{grid-template-columns:1fr;}
  .kg-beach-grid{grid-template-columns:1fr;}
  .kg-when-grid{grid-template-columns:1fr;}
  .kg-routes{grid-template-columns:1fr;}
  .kg-act-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:420px){
  .kg-act-grid{grid-template-columns:1fr;}
}
</style>

<body>
<?php include 'includes/header.php'; ?>

<!-- HERO -->
<section class="kg-hero">
  <div class="kg-hero-content">
    <p class="kg-eyebrow">Watamu · Kilifi · Vipingo · Malindi</p>
    <h1>The Kenya Coast Travel Guide</h1>
    <p class="kg-hero-sub">White sand beaches, warm Indian Ocean water, ancient Swahili culture and some of East Africa's most extraordinary boutique hospitality — this is the Kenya coast.</p>
  </div>
</section>

<!-- BREADCRUMB -->
<div class="kg-breadcrumb">
  <nav aria-label="Breadcrumb">
    <a href="./">Home</a>
    <span aria-hidden="true">›</span>
    <span>Kenya Coast Guide</span>
  </nav>
</div>

<!-- OVERVIEW -->
<section class="kg-overview">
  <div class="kg-overview-inner">
    <p class="kg-eyebrow-sm">Kenya's North Coast</p>
    <h2>Everything You Need to Plan Your Trip</h2>
    <p>Kenya's North Coast stretches from Mombasa to Lamu — a sweep of Indian Ocean shoreline that has been drawing travellers for centuries. Ancient Arab and Portuguese trading routes carved the towns that line this coast, and Swahili culture remains its living soul: in the food, the architecture, the music and the warmth of the people you will meet.</p>
    <p>Three areas define what we at Tribal Sand know best. <a href="watamu.php">Watamu</a> — a UNESCO biosphere reserve with a pristine marine national park and some of the finest snorkelling on the continent. <a href="kilifi.php">Kilifi</a> — a creek town growing into one of East Africa's most creatively alive destinations, with Bofa Beach, a thriving arts and food scene and the Tribal Dunes community at its heart. <a href="my-amani.php">Vipingo</a> — ultra-private, north of Kilifi, with long empty beaches and a sense of true remoteness that is increasingly rare on any Indian Ocean coast.</p>
    <p>These three areas have different characters, different appeals and different visitors — but all three are exceptional. This guide covers everything you need: when to come, how to get here, where to stay, the best beaches, the best activities and the questions every first-time Kenya coast visitor asks. Use it to plan the trip you will never stop talking about.</p>
  </div>
</section>

<!-- DESTINATIONS -->
<section class="kg-destinations">
  <div class="kg-destinations-inner">
    <p class="kg-section-eyebrow">Three Areas · One Coast</p>
    <h2 class="kg-section-h2">The Destinations We Know Best</h2>
    <div class="kg-dest-grid">

      <!-- Watamu -->
      <div class="kg-dest-card">
        <div class="kg-dest-img">
          <img src="images/zuri/Aerial/zuri-3.webp" alt="Watamu Kenya beach and marine park" width="600" height="400" loading="lazy">
          <span class="kg-dest-badge">UNESCO Biosphere</span>
        </div>
        <div class="kg-dest-body">
          <div class="kg-dest-region">Kilifi County</div>
          <div class="kg-dest-name">Watamu</div>
          <div class="kg-dest-desc">Home to Kenya's finest marine national park, Watamu is where the reef begins at your feet. A UNESCO-designated biosphere reserve, it shelters green and hawksbill turtle nesting beaches, extraordinary coral gardens and the clearest water on the North Coast. The town itself remains small and unhurried — a boutique village rather than a resort destination. Kitesurfers converge on the kite beach, and the dining scene punches well above its size.</div>
          <a href="zuri.php" class="kg-dest-link">Stay at Zuri, Watamu →</a>
        </div>
      </div>

      <!-- Kilifi -->
      <div class="kg-dest-card">
        <div class="kg-dest-img">
          <img src="images/Maya-Kobe-1-hero.webp" alt="Kilifi Bofa Beach Kenya boutique hotel" width="600" height="400" loading="lazy">
          <span class="kg-dest-badge">Bofa Beach · Tribal Dunes</span>
        </div>
        <div class="kg-dest-body">
          <div class="kg-dest-region">Kilifi County</div>
          <div class="kg-dest-name">Kilifi</div>
          <div class="kg-dest-desc">Kilifi is the Kenya coast in transition: a historic creek town that is becoming one of East Africa's most interesting places to visit. Bofa Beach is its crown — a broad, relatively uncrowded stretch of white sand at the mouth of Kilifi Creek. The Tribal Dunes community, built around Maya Kobe, is the social heartbeat: a solar-powered beachfront village with a boutique hotel, kite school, café and co-working space. The Arabuko-Sokoke Forest nearby offers rare forest safaris.</div>
          <a href="maya-kobe.php" class="kg-dest-link">Stay at Maya Kobe, Kilifi →</a>
        </div>
      </div>

      <!-- Vipingo -->
      <div class="kg-dest-card">
        <div class="kg-dest-img">
          <img src="images/my-amani/Aerial/myamani-11.webp" alt="Vipingo Kenya private villa beach" width="600" height="400" loading="lazy">
          <span class="kg-dest-badge">Ultra-Private</span>
        </div>
        <div class="kg-dest-body">
          <div class="kg-dest-region">Kilifi County</div>
          <div class="kg-dest-name">Vipingo</div>
          <div class="kg-dest-desc">North of Kilifi and a world away from the tourist trail, Vipingo is the Kenya coast for those who value solitude above all else. The beaches here are long and often entirely empty. The Vipingo Ridge Golf Club — an 18-hole PGA-accredited course with ocean views — draws golfers from across the world. My Amani private villa sits on its own beachfront here: five bedrooms, an infinity pool and a hot tub, available exclusively to one group at a time. The definition of private.</div>
          <a href="my-amani.php" class="kg-dest-link">Stay at My Amani, Vipingo →</a>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- BEST BEACHES -->
<section class="kg-beaches">
  <div class="kg-beaches-inner">
    <p class="kg-section-eyebrow">Sun, Sand & Sea</p>
    <h2 class="kg-section-h2">Kenya's Most Beautiful Beaches</h2>
    <div class="kg-beach-grid">
      <div class="kg-beach-item">
        <div class="kg-beach-name">Bofa Beach</div>
        <div class="kg-beach-sub">Kilifi · 14km stretch</div>
        <div class="kg-beach-desc">One of Kenya's longest and most beautiful uninterrupted beaches. Bofa runs south from Kilifi Creek mouth through Tribal Dunes territory — broad white sand, gentle waves and spectacular views of the Indian Ocean. The home beach for Maya Kobe and Enkare Bofa. Excellent for walking, swimming and watching the dhow traffic on the creek.</div>
      </div>
      <div class="kg-beach-item">
        <div class="kg-beach-name">Watamu Beach</div>
        <div class="kg-beach-sub">Watamu · Marine Park Access</div>
        <div class="kg-beach-desc">Consistently rated among the top beaches in Africa, Watamu Beach sits at the edge of the Marine National Reserve with coral reefs accessible directly from shore. The water is an exceptional blue-green and the sand is powdery white. Home to Zuri boutique hotel and ideal for snorkelling, swimming and turtle spotting along the shore at night.</div>
      </div>
      <div class="kg-beach-item">
        <div class="kg-beach-name">Vipingo Ridge Beach</div>
        <div class="kg-beach-sub">Vipingo · Private &amp; Undiscovered</div>
        <div class="kg-beach-desc">The beach fronting Vipingo is arguably the most pristine on the entire North Coast — wide, white and virtually empty. No beach bars, no jet skis, no vendors. Just the Indian Ocean, the sound of waves and the occasional fishing boat on the horizon. The private beach at My Amani occupies a particularly spectacular stretch where sea turtle nesting is a regular sight.</div>
      </div>
      <div class="kg-beach-item">
        <div class="kg-beach-name">Turtle Bay</div>
        <div class="kg-beach-sub">Watamu Marine Park · UNESCO</div>
        <div class="kg-beach-desc">Turtle Bay is the jewel of Watamu Marine National Park — a sheltered bay where sea turtles come to feed on the seagrass beds and green turtles nest on the sand between October and March. The snorkelling over the coral gardens here is extraordinary. Glass-bottomed boat trips depart from the bay daily. One of Kenya's most iconic natural sites and a UNESCO biosphere reserve.</div>
      </div>
    </div>
  </div>
</section>

<!-- WHEN TO VISIT -->
<section class="kg-when">
  <div class="kg-when-inner">
    <p class="kg-section-eyebrow">Seasonal Guide</p>
    <h2 class="kg-section-h2">When to Visit the Kenya Coast</h2>
    <div class="kg-when-grid">
      <div class="kg-season kg-season-good">
        <div class="kg-season-label">Best Times to Visit</div>
        <div class="kg-season-title">June – October &amp; December – February</div>
        <ul>
          <li>June to October: cool, dry southeast trade winds keep humidity low. Perfect kite season with consistent 15–25 knot winds. Excellent water visibility for snorkelling and diving.</li>
          <li>December to February: warm and sunny with calm seas. Water temperatures peak at 29–30°C. Whale sharks visit the Watamu Marine Park. Christmas and New Year are busy — book early.</li>
          <li>Both windows offer clear skies, very low rainfall and ideal beach conditions throughout.</li>
          <li>Wildlife safaris to Tsavo are excellent in both periods — dry conditions bring animals to waterholes.</li>
        </ul>
      </div>
      <div class="kg-season kg-season-avoid">
        <div class="kg-season-label">Shoulder &amp; Rainy Seasons</div>
        <div class="kg-season-title">March – May &amp; November</div>
        <ul>
          <li>March to May is the long rains season (masika) — heavy daily downpours, high humidity and overcast skies. Some hotels close. Not recommended for beach holidays.</li>
          <li>November brings the short rains (vuli) — less severe than the long rains but still unpredictable. Shorter showers, often clearing by afternoon.</li>
          <li>Rates are lower in both shoulder periods. If budget is the priority and you accept some rain, November can still offer good days.</li>
          <li>Ocean conditions during rains: lower visibility, stronger currents, less suitable for snorkelling and diving.</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- GETTING THERE -->
<section class="kg-getting">
  <div class="kg-getting-inner">
    <p class="kg-section-eyebrow">Journey Planning</p>
    <h2 class="kg-section-h2">Getting to the Kenya Coast</h2>
    <div class="kg-routes">
      <div class="kg-route">
        <div class="kg-route-icon"><i class="fas fa-plane"></i></div>
        <div class="kg-route-title">Fly to Mombasa</div>
        <div class="kg-route-desc">Moi International Airport (MBA) in Mombasa is the main gateway to the North Coast, served by Kenya Airways and several international carriers via Nairobi. Transfer time to Watamu is approximately 2 hours; Kilifi 1 hour. Our team arranges all airport transfers — <a href="contact.php">contact us</a> to organise.</div>
      </div>
      <div class="kg-route">
        <div class="kg-route-icon"><i class="fas fa-car"></i></div>
        <div class="kg-route-title">Drive from Nairobi</div>
        <div class="kg-route-desc">The A109 highway connects Nairobi to Mombasa — a well-maintained 480km route taking 4–5 hours by car. Continue north along the B8 coast road to reach Kilifi, Watamu and Vipingo. Car hire is recommended for flexibility. We can advise on trusted hire companies on request.</div>
      </div>
      <div class="kg-route">
        <div class="kg-route-icon"><i class="fas fa-helicopter"></i></div>
        <div class="kg-route-title">Charter or Fly to Malindi</div>
        <div class="kg-route-desc">Malindi Airport (MYD), 20 minutes from Watamu, receives daily flights from Nairobi Wilson Airport via SafariLink and AirKenya. Charter flights are also available. This is the fastest and most seamless option for guests heading directly to Watamu or Kilifi.</div>
      </div>
    </div>
    <div class="kg-getting-note">
      <p>We arrange airport and inter-property transfers for all Tribal Sand guests. <a href="contact.php">Contact our team</a> with your flight details and we will handle the rest.</p>
    </div>
  </div>
</section>

<!-- WHERE TO STAY -->
<section class="kg-stay">
  <div class="kg-stay-inner">
    <p class="kg-stay-eyebrow">Our Collection</p>
    <h2>Our Tribal Sand Properties</h2>
    <p class="kg-stay-sub">Six properties across three locations — boutique hotels and private villas, all beachfront, all extraordinary.</p>
    <div class="kg-stay-grid">
      <a href="zuri.php" class="kg-stay-prop">
        <div class="kg-stay-prop-img"><img src="images/zuri/Aerial/zuri-3.webp" alt="Zuri Watamu" width="72" height="56" loading="lazy"></div>
        <div class="kg-stay-prop-info">
          <div class="kg-stay-prop-type">Boutique Hotel · 6 Suites</div>
          <div class="kg-stay-prop-name">Zuri</div>
          <div class="kg-stay-prop-loc">Watamu · Marine Park Coast</div>
          <div class="kg-stay-prop-link">View property →</div>
        </div>
      </a>
      <a href="maya-kobe.php" class="kg-stay-prop">
        <div class="kg-stay-prop-img"><img src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe Kilifi" width="72" height="56" loading="lazy"></div>
        <div class="kg-stay-prop-info">
          <div class="kg-stay-prop-type">Boutique Hotel · 5 Suites</div>
          <div class="kg-stay-prop-name">Maya Kobe</div>
          <div class="kg-stay-prop-loc">Bofa Beach, Kilifi · Tribal Dunes</div>
          <div class="kg-stay-prop-link">View property →</div>
        </div>
      </a>
      <a href="my-amani.php" class="kg-stay-prop">
        <div class="kg-stay-prop-img"><img src="images/my-amani/Aerial/myamani-11.webp" alt="My Amani Vipingo" width="72" height="56" loading="lazy"></div>
        <div class="kg-stay-prop-info">
          <div class="kg-stay-prop-type">Private Villa · 5 Bedrooms</div>
          <div class="kg-stay-prop-name">My Amani</div>
          <div class="kg-stay-prop-loc">Vipingo · Exclusive Use</div>
          <div class="kg-stay-prop-link">View property →</div>
        </div>
      </a>
      <a href="enkare-bofa.php" class="kg-stay-prop">
        <div class="kg-stay-prop-img"><img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" alt="Enkare Bofa Kilifi" width="72" height="56" loading="lazy"></div>
        <div class="kg-stay-prop-info">
          <div class="kg-stay-prop-type">Private Villa · 5 Bedrooms</div>
          <div class="kg-stay-prop-name">Enkare Bofa</div>
          <div class="kg-stay-prop-loc">Bofa Beach, Kilifi</div>
          <div class="kg-stay-prop-link">View property →</div>
        </div>
      </a>
      <a href="sandbox.php" class="kg-stay-prop">
        <div class="kg-stay-prop-img"><img src="images/Sandbox/outdoors/IMG-20251117-WA0091.jpg" alt="Sandbox Kilifi" width="72" height="56" loading="lazy"></div>
        <div class="kg-stay-prop-info">
          <div class="kg-stay-prop-type">Private Villa · 4 Bedrooms</div>
          <div class="kg-stay-prop-name">Sandbox</div>
          <div class="kg-stay-prop-loc">Kilifi · Tribal Dunes</div>
          <div class="kg-stay-prop-link">View property →</div>
        </div>
      </a>
      <a href="maya_ilai.php" class="kg-stay-prop">
        <div class="kg-stay-prop-img"><img src="images/maya_illai/Best1.jpg" alt="Maya Ilai Kilifi" width="72" height="56" loading="lazy"></div>
        <div class="kg-stay-prop-info">
          <div class="kg-stay-prop-type">Eco Compound · Long Stay</div>
          <div class="kg-stay-prop-name">Maya Ilai</div>
          <div class="kg-stay-prop-loc">Kilifi · Tribal Dunes</div>
          <div class="kg-stay-prop-link">View property →</div>
        </div>
      </a>
    </div>
  </div>
</section>

<!-- ACTIVITIES -->
<section class="kg-activities">
  <div class="kg-activities-inner">
    <p class="kg-section-eyebrow">On Land &amp; Ocean</p>
    <h2 class="kg-section-h2">Activities &amp; Experiences</h2>
    <div class="kg-act-grid">
      <div class="kg-act-tile">
        <div class="kg-act-icon"><i class="fas fa-wind"></i></div>
        <div class="kg-act-title">Kitesurfing</div>
        <div class="kg-act-desc">The Kenya coast is one of the world's top kitesurfing destinations. Consistent trade winds, shallow turquoise water and the Tribal Kite School Kilifi make it accessible to beginners and irresistible to experts.</div>
      </div>
      <div class="kg-act-tile">
        <div class="kg-act-icon"><i class="fas fa-water"></i></div>
        <div class="kg-act-title">Snorkelling</div>
        <div class="kg-act-desc">The Watamu Marine National Park reef system is one of Kenya's great natural treasures. Sea turtles, moray eels, lionfish and hundreds of reef species are visible from shore. Glass-bottom boats also available.</div>
      </div>
      <div class="kg-act-tile">
        <div class="kg-act-icon"><i class="fas fa-sailboat"></i></div>
        <div class="kg-act-title">Dhow Cruise</div>
        <div class="kg-act-desc">Sail Kilifi Creek or the open Indian Ocean aboard a traditional Swahili wooden dhow. Sunset cruises with sundowners are among the most beautiful experiences the coast offers. Arranged by our concierge team.</div>
      </div>
      <div class="kg-act-tile">
        <div class="kg-act-icon"><i class="fas fa-fish"></i></div>
        <div class="kg-act-title">Deep-Sea Fishing</div>
        <div class="kg-act-desc">The Indian Ocean off Kenya's coast holds marlin, sailfish, wahoo, kingfish and yellowfin tuna. Sportfishing charters depart from Watamu and Kilifi. Catch-and-release trips are strongly encouraged. Book through our team.</div>
      </div>
      <div class="kg-act-tile">
        <div class="kg-act-icon"><i class="fas fa-tree"></i></div>
        <div class="kg-act-title">Forest Safaris</div>
        <div class="kg-act-desc">Arabuko-Sokoke Forest Reserve — adjacent to Watamu — is one of East Africa's last coastal forests and home to rare birds, elephants and the endangered Ader's duiker. Guided forest walks arranged from our Kilifi properties.</div>
      </div>
      <div class="kg-act-tile">
        <div class="kg-act-icon"><i class="fas fa-mosque"></i></div>
        <div class="kg-act-title">Culture &amp; Markets</div>
        <div class="kg-act-desc">Explore the ruins of Gedi — an ancient Swahili town buried in the forest — or the vibrant Kilifi market. Swahili cuisine, local crafts, traditional music and the warmth of coastal Kenyan culture are always close at hand.</div>
      </div>
    </div>
    <p class="kg-activities-more">Full activities guide → <a href="activities.php">Activities & Experiences</a></p>
  </div>
</section>

<!-- FAQ -->
<section class="kg-faq">
  <div class="kg-faq-inner">
    <h2>Kenya Coast · Common Questions</h2>
    <?php foreach($faqs as $i => $faq): ?>
    <div class="kg-faq-item">
      <button class="kg-faq-btn" aria-expanded="false" aria-controls="kg-faq-body-<?= $i ?>">
        <span class="kg-faq-q"><?= htmlspecialchars($faq['q']) ?></span>
        <span class="kg-faq-icon" aria-hidden="true">+</span>
      </button>
      <div class="kg-faq-body" id="kg-faq-body-<?= $i ?>" role="region">
        <p><?= htmlspecialchars($faq['a']) ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- CTA STRIP -->
<section class="kg-cta-strip">
  <div class="kg-cta-strip-inner">
    <p>"Ready to Visit the Kenya Coast?"</p>
    <p class="kg-cta-strip-sub">Browse our properties across Watamu, Kilifi and Vipingo — or let us plan your perfect itinerary.</p>
    <a href="trip-builder.php" class="kg-cta-btn">Plan Your Trip</a>
  </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
(function(){
  var items = document.querySelectorAll('.kg-faq-item');
  items.forEach(function(item){
    var btn  = item.querySelector('.kg-faq-btn');
    var body = item.querySelector('.kg-faq-body');
    var icon = item.querySelector('.kg-faq-icon');
    btn.addEventListener('click', function(){
      var isOpen = body.classList.contains('open');
      /* close all */
      items.forEach(function(it){
        it.querySelector('.kg-faq-body').classList.remove('open');
        it.querySelector('.kg-faq-icon').textContent = '+';
        it.querySelector('.kg-faq-btn').setAttribute('aria-expanded','false');
      });
      if(!isOpen){
        body.classList.add('open');
        icon.textContent = '−';
        btn.setAttribute('aria-expanded','true');
      }
    });
  });
})();
</script>
</body>
