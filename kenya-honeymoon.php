<?php
require_once 'includes/schema.php';

/* ── SEO ── */
$page_title  = 'Kenya Honeymoon · Romantic Beachfront Hotels & Villas · Tribal Sand';
$page_desc   = 'Plan the perfect Kenya honeymoon — private beachfront villas and boutique hotels on the Indian Ocean. Concierge-led, ultra-private and unforgettable.';
$page_url    = 'https://tribalsand.com/kenya-honeymoon.php';
$page_image  = asset_url('images/Maya-Kobe-1-hero.webp');
$page_preload = 'images/Maya-Kobe-1-hero.webp';

/* ── FAQ data ── */
$faqs = [
    [
        'q' => 'Is Kenya a good honeymoon destination?',
        'a' => 'Yes — Kenya is an exceptional honeymoon destination. The North Coast offers year-round warm weather, pristine private beaches, world-class boutique hotels and an intimacy that mass-market resort destinations like the Maldives cannot match. Add optional wildlife safaris to Tsavo and you have the most complete honeymoon on earth.',
    ],
    [
        'q' => 'Which is the most romantic hotel on the Kenya coast?',
        'a' => 'For total privacy, My Amani in Vipingo is the ultimate choice — an entire five-bedroom villa rented exclusively by one party, with a private chef, infinity pool and hot tub. For boutique romance, Maya Kobe on Bofa Beach Kilifi offers Balinese-inspired suites, a plunge pool and couples massages. Zuri in Watamu is perfect for intimate smaller-group honeymoons with direct beach access.',
    ],
    [
        'q' => 'What is the best time for a honeymoon in Kenya?',
        'a' => 'December to February and June to October are the two best windows. Both offer dry, warm weather with low humidity. June to October brings cooler ocean breezes and excellent visibility for snorkelling. December to February sees the warmest seas and the best whale shark sightings. Avoid March to May (long rains) and November (short rains).',
    ],
    [
        'q' => 'How much does a honeymoon in Kenya cost?',
        'a' => 'Costs vary by property and season. Boutique hotel suites typically start from USD 400 per night for two people including breakfast. Private villa rentals for exclusive use begin from USD 900 per night. We offer bespoke honeymoon packages — please contact our team for a personalised quote tailored to your dates and wishes.',
    ],
    [
        'q' => 'Can you arrange honeymoon extras at Tribal Sand?',
        'a' => 'Absolutely. Our concierge team specialises in honeymoon preparation. We arrange flower arrangements in your suite, surprise champagne setups, private beach dinners under the stars, sunset dhow cruises, couples spa treatments, wildlife safaris and airport transfers — all managed so you arrive to everything in place.',
    ],
];

/* ── Schema ── */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',             'url' => 'https://tribalsand.com/'],
    ['name' => 'Kenya Honeymoon',  'url' => 'https://tribalsand.com/kenya-honeymoon.php'],
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
.kh-hero{
  position:relative;height:60vh;min-height:520px;
  background:var(--teal-d) url('images/Maya-Kobe-1-hero.webp') center 40% / cover no-repeat;
  display:flex;align-items:center;justify-content:center;
  text-align:center;overflow:hidden;
}
.kh-hero::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(to bottom,rgba(16,47,58,.45) 0%,rgba(16,47,58,.72) 100%);
}
.kh-hero-content{position:relative;z-index:2;max-width:780px;padding:0 5vw;}
.kh-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.36em;text-transform:uppercase;
  color:rgba(184,150,90,.9);margin-bottom:1.3rem;
}
.kh-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.5rem,5.5vw,4.2rem);font-weight:300;
  color:#fff;line-height:1.06;margin-bottom:1.4rem;
}
.kh-hero h1 em{font-style:italic;color:var(--sand-lt);}
.kh-hero-sub{
  font-family:'Jost',sans-serif;font-size:.9rem;
  color:rgba(255,255,255,.82);line-height:1.88;
  max-width:560px;margin:0 auto 2.2rem;
}
.kh-hero-ctas{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;}
.kh-btn-primary{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2rem;background:var(--sand);color:var(--teal-d);
  border:1px solid var(--sand);font-weight:500;transition:all .22s;
}
.kh-btn-primary:hover{background:var(--sand-lt);border-color:var(--sand-lt);}
.kh-btn-outline{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2rem;background:transparent;
  color:#fff;border:1px solid rgba(255,255,255,.45);transition:all .22s;
}
.kh-btn-outline:hover{border-color:#fff;background:rgba(255,255,255,.08);}

/* ── BREADCRUMB ── */
.kh-breadcrumb{
  background:#fff;padding:.85rem 5vw;
  border-bottom:1px solid var(--border);
}
.kh-breadcrumb nav{
  max-width:1200px;margin:0 auto;
  display:flex;align-items:center;gap:.5rem;
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.05em;color:rgba(107,96,80,.65);
}
.kh-breadcrumb a{color:var(--sand);transition:color .2s;}
.kh-breadcrumb a:hover{color:var(--teal-d);}

/* ── WHY KENYA ── */
.kh-why{background:#fff;padding:6rem 5vw;}
.kh-why-inner{max-width:1200px;margin:0 auto;display:grid;grid-template-columns:1.1fr 1fr;gap:5rem;align-items:start;}
.kh-why-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;
}
.kh-why h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);line-height:1.12;margin-bottom:1.8rem;
}
.kh-why p{
  font-family:'Jost',sans-serif;font-size:.88rem;
  color:var(--mid);line-height:1.88;margin-bottom:1rem;
}
.kh-why p:last-child{margin-bottom:0;}
.kh-stats{
  display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;
  padding-top:.5rem;
}
.kh-stat{
  border:1px solid var(--border);padding:1.8rem 1.5rem;
  background:var(--off);text-align:center;
}
.kh-stat-num{
  font-family:'Cormorant Garamond',serif;
  font-size:2.4rem;font-weight:300;
  color:var(--teal-d);line-height:1;margin-bottom:.4rem;
}
.kh-stat-num span{font-size:1.2rem;color:var(--sand);}
.kh-stat-label{
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.1em;text-transform:uppercase;
  color:var(--mid);line-height:1.4;
}

/* ── PROPERTIES ── */
.kh-props{background:var(--off);padding:6rem 5vw;}
.kh-props-inner{max-width:1200px;margin:0 auto;}
.kh-section-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;text-align:center;
}
.kh-section-h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);text-align:center;margin-bottom:3rem;
}
.kh-props-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.8rem;}
.kh-prop-card{
  background:#fff;border:1px solid var(--border);
  overflow:hidden;transition:box-shadow .25s,transform .25s;display:flex;flex-direction:column;
}
.kh-prop-card:hover{box-shadow:0 12px 40px rgba(16,47,58,.1);transform:translateY(-3px);}
.kh-prop-img{height:240px;overflow:hidden;position:relative;}
.kh-prop-img img{width:100%;height:100%;object-fit:cover;transition:transform .55s ease;}
.kh-prop-card:hover .kh-prop-img img{transform:scale(1.04);}
.kh-prop-tag{
  position:absolute;bottom:.9rem;left:.9rem;
  background:rgba(16,47,58,.82);backdrop-filter:blur(8px);
  border:1px solid rgba(184,150,90,.2);
  font-family:'Jost',sans-serif;font-size:.5rem;
  letter-spacing:.2em;text-transform:uppercase;
  color:rgba(184,150,90,.85);padding:.32rem .7rem;
}
.kh-prop-body{padding:1.6rem;flex:1;display:flex;flex-direction:column;}
.kh-prop-location{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.18em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.4rem;
}
.kh-prop-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.45rem;font-weight:400;
  color:var(--teal-d);margin-bottom:.3rem;
}
.kh-prop-tagline{
  font-family:'Cormorant Garamond',serif;
  font-size:.95rem;font-style:italic;
  color:var(--mid);margin-bottom:1rem;line-height:1.4;
}
.kh-prop-features{
  list-style:none;margin-bottom:1.4rem;flex:1;
  display:flex;flex-direction:column;gap:.35rem;
}
.kh-prop-features li{
  font-family:'Jost',sans-serif;font-size:.76rem;
  color:var(--mid);display:flex;align-items:center;gap:.55rem;
}
.kh-prop-features li::before{
  content:'';width:5px;height:5px;border-radius:50%;
  background:var(--sand);flex-shrink:0;
}
.kh-prop-link{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.64rem;letter-spacing:.18em;text-transform:uppercase;
  color:var(--teal-d);border:1px solid var(--border);
  padding:.62rem 1.2rem;text-align:center;
  transition:background .22s,border-color .22s,color .22s;
}
.kh-prop-link:hover{background:var(--teal-d);border-color:var(--teal-d);color:#fff;}

/* ── EXPERIENCES ── */
.kh-exp{background:#fff;padding:6rem 5vw;}
.kh-exp-inner{max-width:1200px;margin:0 auto;}
.kh-exp-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.5rem;margin-top:3rem;}
.kh-exp-tile{
  border:1px solid var(--border);padding:2.2rem 1.6rem;
  text-align:center;transition:border-color .22s,background .22s;
  background:var(--off);
}
.kh-exp-tile:hover{border-color:var(--sand);background:#fff;}
.kh-exp-icon{font-size:1.8rem;color:var(--sand);margin-bottom:1rem;}
.kh-exp-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.15rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.5rem;
}
.kh-exp-desc{
  font-family:'Jost',sans-serif;font-size:.76rem;
  color:var(--mid);line-height:1.72;
}

/* ── PROMISE STRIP ── */
.kh-promise{
  background:var(--teal-d);padding:6rem 5vw;
  position:relative;overflow:hidden;
}
.kh-promise::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 70% 50%,rgba(184,150,90,.08) 0%,transparent 65%);
  pointer-events:none;
}
.kh-promise-inner{max-width:900px;margin:0 auto;text-align:center;position:relative;z-index:1;}
.kh-promise-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.32em;text-transform:uppercase;
  color:rgba(184,150,90,.8);margin-bottom:1rem;
}
.kh-promise h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:300;
  color:#fff;line-height:1.12;margin-bottom:1.6rem;
}
.kh-promise h2 em{font-style:italic;color:var(--sand-lt);}
.kh-promise p{
  font-family:'Jost',sans-serif;font-size:.88rem;
  color:rgba(255,255,255,.82);line-height:1.88;
  max-width:680px;margin:0 auto 2.2rem;
}
.kh-promise-items{
  display:flex;justify-content:center;flex-wrap:wrap;gap:1rem;
  margin-bottom:2.5rem;
}
.kh-promise-item{
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.1em;text-transform:uppercase;
  color:rgba(184,150,90,.85);
  border:1px solid rgba(184,150,90,.25);
  padding:.5rem 1rem;
}
.kh-promise-btn{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2.2rem;background:var(--sand);
  color:var(--teal-d);border:1px solid var(--sand);
  font-weight:500;transition:all .22s;
}
.kh-promise-btn:hover{background:var(--sand-lt);border-color:var(--sand-lt);}

/* ── FAQ ── */
.kh-faq{background:var(--off);padding:6rem 5vw;}
.kh-faq-inner{max-width:820px;margin:0 auto;}
.kh-faq h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.6rem);font-weight:400;
  color:var(--teal-d);margin-bottom:2.5rem;text-align:center;
}
.kh-faq-item{
  border-bottom:1px solid var(--border);
  overflow:hidden;
}
.kh-faq-item:first-of-type{border-top:1px solid var(--border);}
.kh-faq-btn{
  width:100%;background:none;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:1.3rem 0;text-align:left;
}
.kh-faq-q{
  font-family:'Jost',sans-serif;font-size:.9rem;
  font-weight:500;color:var(--teal-d);line-height:1.4;
}
.kh-faq-icon{
  font-size:1.1rem;color:var(--sand);flex-shrink:0;
  font-weight:300;line-height:1;font-family:'Jost',sans-serif;
  transition:transform .2s;
}
.kh-faq-body{
  display:none;padding:0 0 1.3rem;
}
.kh-faq-body.open{display:block;}
.kh-faq-body p{
  font-family:'Jost',sans-serif;font-size:.85rem;
  color:var(--mid);line-height:1.88;
}

/* ── CTA STRIP ── */
.kh-cta-strip{
  background:#fff;padding:5rem 5vw;text-align:center;
  border-top:1px solid var(--border);
}
.kh-cta-strip p{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.5rem,2.5vw,2.2rem);font-weight:300;
  color:var(--teal-d);font-style:italic;
  margin-bottom:.75rem;
}
.kh-cta-strip-sub{
  font-family:'Jost',sans-serif;font-size:.85rem;
  color:var(--mid);margin-bottom:2rem;
}
.kh-cta-buttons{display:flex;justify-content:center;gap:1rem;flex-wrap:wrap;}
.kh-btn-dark{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2rem;background:var(--teal-d);
  color:#fff;border:1px solid var(--teal-d);
  font-weight:500;transition:all .22s;
}
.kh-btn-dark:hover{background:#0c2430;border-color:#0c2430;}
.kh-btn-sand{
  display:inline-block;font-family:'Jost',sans-serif;
  font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2rem;background:var(--sand);
  color:var(--teal-d);border:1px solid var(--sand);
  font-weight:500;transition:all .22s;
}
.kh-btn-sand:hover{background:var(--sand-lt);border-color:var(--sand-lt);}

/* ── RESPONSIVE ── */
@media(max-width:1000px){
  .kh-why-inner{grid-template-columns:1fr;gap:3rem;}
  .kh-props-grid{grid-template-columns:1fr 1fr;}
  .kh-exp-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:660px){
  .kh-props-grid{grid-template-columns:1fr;}
  .kh-exp-grid{grid-template-columns:1fr 1fr;}
  .kh-stats{grid-template-columns:1fr 1fr;}
}
@media(max-width:420px){
  .kh-exp-grid{grid-template-columns:1fr;}
}
</style>

<body>
<?php include 'includes/header.php'; ?>

<!-- HERO -->
<section class="kh-hero">
  <div class="kh-hero-content">
    <p class="kh-eyebrow">Romantic Stays · Kenya's North Coast</p>
    <h1>Your Kenya Honeymoon <em>Starts Here</em></h1>
    <p class="kh-hero-sub">Private villas, ocean-facing suites and the warm waters of the Indian Ocean. We specialise in honeymoons that feel as effortless as they are unforgettable.</p>
    <div class="kh-hero-ctas">
      <a href="#properties" class="kh-btn-primary">Browse Properties</a>
      <a href="trip-builder.php" class="kh-btn-outline">Plan Your Honeymoon</a>
    </div>
  </div>
</section>

<!-- BREADCRUMB -->
<div class="kh-breadcrumb">
  <nav aria-label="Breadcrumb">
    <a href="./">Home</a>
    <span aria-hidden="true">›</span>
    <span>Kenya Honeymoon</span>
  </nav>
</div>

<!-- WHY KENYA FOR A HONEYMOON -->
<section class="kh-why">
  <div class="kh-why-inner">
    <div>
      <p class="kh-why-eyebrow">Why Kenya</p>
      <h2>An Ocean Honeymoon Unlike Any Other</h2>
      <p>Kenya's North Coast is one of the world's best-kept secrets for honeymooners. Where the Maldives can feel like a conveyor belt of resort islands, Kenya offers something rarer: genuine privacy, extraordinary scenery and an intimacy that feels earned rather than manufactured.</p>
      <p>The Indian Ocean here runs warm year-round — between 26°C and 30°C — and bathes coastlines of powdery white sand that remain largely free of the crowds that have swamped other Indian Ocean destinations. With 300 days of sunshine annually, the weather overwhelmingly works in your favour.</p>
      <p>Our private villas — rented exclusively by one couple or group — mean you have your own chef, your own pool and your own piece of coast. Nobody else's alarm call. Nobody else's children. The boutique hotels in our collection offer ocean-facing suites where the sound of the Indian Ocean is your only wake-up call.</p>
      <p>Beyond the beach, Kenya's proximity to world-class wildlife parks like Tsavo makes the ultimate add-on: a safari leg to your honeymoon, bookable through our concierge team. Swahili culture, ancient coastal ruins and the warmth of local Kenyan hospitality add texture and soul that no manicured resort destination can replicate.</p>
      <p>Sustainable luxury is woven into everything we do — solar power, local sourcing and community involvement — so your honeymoon leaves a positive mark on a place that deserves protecting.</p>
    </div>
    <div>
      <div class="kh-stats">
        <div class="kh-stat">
          <div class="kh-stat-num">27<span>°C</span></div>
          <div class="kh-stat-label">Average ocean temperature year-round</div>
        </div>
        <div class="kh-stat">
          <div class="kh-stat-num">300</div>
          <div class="kh-stat-label">Days of sunshine per year on the coast</div>
        </div>
        <div class="kh-stat">
          <div class="kh-stat-num">5</div>
          <div class="kh-stat-label">Private villas available for exclusive use</div>
        </div>
        <div class="kh-stat">
          <div class="kh-stat-num">1</div>
          <div class="kh-stat-label">Dedicated concierge for your entire stay</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- PROPERTIES -->
<section class="kh-props" id="properties">
  <div class="kh-props-inner">
    <p class="kh-section-eyebrow">Our Collection</p>
    <h2 class="kh-section-h2">Our Most Romantic Properties</h2>
    <div class="kh-props-grid">

      <!-- My Amani -->
      <div class="kh-prop-card">
        <div class="kh-prop-img">
          <img src="images/my-amani/Aerial/myamani-11.webp" alt="My Amani private villa Kenya honeymoon" width="600" height="400" loading="lazy">
          <span class="kh-prop-tag">Exclusive Villa Rental</span>
        </div>
        <div class="kh-prop-body">
          <div class="kh-prop-location">Vipingo, North Coast</div>
          <div class="kh-prop-name">My Amani</div>
          <div class="kh-prop-tagline">"The ultimate private villa"</div>
          <ul class="kh-prop-features">
            <li>Entire villa for your exclusive use</li>
            <li>Infinity pool overlooking the Indian Ocean</li>
            <li>Private hot tub on the deck</li>
            <li>Personal chef on request</li>
            <li>5 bedrooms — perfect for a bridal party</li>
            <li>Pristine private beachfront</li>
          </ul>
          <a href="my-amani.php" class="kh-prop-link">Explore My Amani →</a>
        </div>
      </div>

      <!-- Maya Kobe -->
      <div class="kh-prop-card">
        <div class="kh-prop-img">
          <img src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe Kilifi Kenya romantic boutique hotel" width="600" height="400" loading="lazy">
          <span class="kh-prop-tag">Boutique Hotel</span>
        </div>
        <div class="kh-prop-body">
          <div class="kh-prop-location">Bofa Beach, Kilifi</div>
          <div class="kh-prop-name">Maya Kobe</div>
          <div class="kh-prop-tagline">"Balinese romance on Bofa Beach"</div>
          <ul class="kh-prop-features">
            <li>Ocean-facing suites with private plunge pool</li>
            <li>À la carte dining on the beachfront</li>
            <li>Couples massage at the spa huts</li>
            <li>Balinese-inspired design and architecture</li>
            <li>20m beachfront pool</li>
            <li>Sunset cocktails on Bofa Beach</li>
          </ul>
          <a href="maya-kobe.php" class="kh-prop-link">Explore Maya Kobe →</a>
        </div>
      </div>

      <!-- Zuri -->
      <div class="kh-prop-card">
        <div class="kh-prop-img">
          <img src="images/zuri/Aerial/zuri-3.webp" alt="Zuri boutique hotel Watamu Kenya honeymoon" width="600" height="400" loading="lazy">
          <span class="kh-prop-tag">Intimate Boutique</span>
        </div>
        <div class="kh-prop-body">
          <div class="kh-prop-location">Watamu, Marine Coast</div>
          <div class="kh-prop-name">Zuri</div>
          <div class="kh-prop-tagline">"Intimate boutique hotel, Watamu"</div>
          <ul class="kh-prop-features">
            <li>Only 6 suites — blissfully intimate</li>
            <li>Direct beach access and private pool</li>
            <li>Bespoke honeymooner packages</li>
            <li>Adjacent to Watamu Marine National Park</li>
            <li>Private chef and à la carte dining</li>
            <li>Snorkelling on the reef at your door</li>
          </ul>
          <a href="zuri.php" class="kh-prop-link">Explore Zuri →</a>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- EXPERIENCES -->
<section class="kh-exp">
  <div class="kh-exp-inner">
    <p class="kh-section-eyebrow">Curated for Couples</p>
    <h2 class="kh-section-h2">Honeymoon Experiences</h2>
    <div class="kh-exp-grid">
      <div class="kh-exp-tile">
        <div class="kh-exp-icon"><i class="fas fa-sailboat"></i></div>
        <div class="kh-exp-title">Sunset Dhow Cruise</div>
        <div class="kh-exp-desc">Sail the Indian Ocean at golden hour aboard a traditional wooden dhow with champagne, fresh fruit and a Swahili crew. Pure magic.</div>
      </div>
      <div class="kh-exp-tile">
        <div class="kh-exp-icon"><i class="fas fa-utensils"></i></div>
        <div class="kh-exp-title">Private Beach Dinner</div>
        <div class="kh-exp-desc">A table for two set directly on the sand, lanterns in the dunes, a private chef and a menu designed around your evening. We handle every detail.</div>
      </div>
      <div class="kh-exp-tile">
        <div class="kh-exp-icon"><i class="fas fa-spa"></i></div>
        <div class="kh-exp-title">Couples Spa Treatment</div>
        <div class="kh-exp-desc">Side-by-side massages in our beachside treatment huts, using local botanicals and traditional Swahili wellness techniques. Book as an arrival surprise.</div>
      </div>
      <div class="kh-exp-tile">
        <div class="kh-exp-icon"><i class="fas fa-fish"></i></div>
        <div class="kh-exp-title">Snorkelling & Marine Park</div>
        <div class="kh-exp-desc">Explore the coral gardens of Watamu Marine National Park — sea turtles, tropical fish and pristine reef, all within minutes of shore. No crowds. No queues.</div>
      </div>
    </div>
    <p style="text-align:center;margin-top:2rem;font-family:'Jost',sans-serif;font-size:.8rem;color:var(--mid);">
      See all experiences → <a href="activities.php" style="color:var(--sand);">Activities & Experiences</a>
    </p>
  </div>
</section>

<!-- THE TRIBAL SAND HONEYMOON PROMISE -->
<section class="kh-promise">
  <div class="kh-promise-inner">
    <p class="kh-promise-eyebrow">The Tribal Sand Promise</p>
    <h2>Your Honeymoon, <em>Perfectly Prepared</em></h2>
    <p>We believe the best honeymoons happen when you arrive to find everything already in place. Your concierge team begins working the moment your booking is confirmed. Airport transfers are organised. Your room is prepared with flowers, candles or a chilled bottle — whatever you have requested. Private dinners are booked. Safari days are arranged. All you do is arrive.</p>
    <p>We have been doing this on Kenya's North Coast for over a decade. We know the suppliers, the guides, the sunset spots and the small details that transform a good stay into an unforgettable one. Whether you want a week of total stillness or an adventure-filled fortnight, we shape it around you.</p>
    <div class="kh-promise-items">
      <span class="kh-promise-item">Airport Transfers</span>
      <span class="kh-promise-item">Suite Flower Setup</span>
      <span class="kh-promise-item">Private Beach Dinners</span>
      <span class="kh-promise-item">Couples Treatments</span>
      <span class="kh-promise-item">Safari Arrangements</span>
      <span class="kh-promise-item">Surprise Setups</span>
    </div>
    <a href="contact.php" class="kh-promise-btn">Talk to Our Concierge</a>
  </div>
</section>

<!-- FAQ -->
<section class="kh-faq">
  <div class="kh-faq-inner">
    <h2>Kenya Honeymoon · Common Questions</h2>
    <?php foreach($faqs as $i => $faq): ?>
    <div class="kh-faq-item">
      <button class="kh-faq-btn" aria-expanded="false" aria-controls="kh-faq-body-<?= $i ?>">
        <span class="kh-faq-q"><?= htmlspecialchars($faq['q']) ?></span>
        <span class="kh-faq-icon" aria-hidden="true">+</span>
      </button>
      <div class="kh-faq-body" id="kh-faq-body-<?= $i ?>" role="region">
        <p><?= htmlspecialchars($faq['a']) ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- CTA STRIP -->
<section class="kh-cta-strip">
  <p>"The coast is waiting for you both."</p>
  <p class="kh-cta-strip-sub">Tell us your dates and we will build your perfect Kenya honeymoon.</p>
  <div class="kh-cta-buttons">
    <a href="trip-builder.php" class="kh-btn-sand">Start Planning</a>
    <a href="contact.php" class="kh-btn-dark">Contact Us</a>
  </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
(function(){
  var items = document.querySelectorAll('.kh-faq-item');
  items.forEach(function(item){
    var btn  = item.querySelector('.kh-faq-btn');
    var body = item.querySelector('.kh-faq-body');
    var icon = item.querySelector('.kh-faq-icon');
    btn.addEventListener('click', function(){
      var isOpen = body.classList.contains('open');
      /* close all */
      items.forEach(function(it){
        it.querySelector('.kh-faq-body').classList.remove('open');
        it.querySelector('.kh-faq-icon').textContent = '+';
        it.querySelector('.kh-faq-btn').setAttribute('aria-expanded','false');
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
