<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'Our Story · Tribal Sand · Kenya\'s Sustainable Coast';
$page_desc   = 'Tribal Sand is a sustainable coastal hospitality ecosystem across Watamu, Kilifi and Vipingo, Kenya. Luxury boutique hotels, private villas and the Tribal Dunes eco village.';
$page_url    = 'https://tribalsand.com/tribalsandstory.php';
$page_image  = 'https://tribalsand.com/images/New-hero-banner.jpg';
$page_preload = 'images/New-hero-banner.jpg';

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',       'url' => 'https://tribalsand.com/'],
    ['name' => 'Our Story',  'url' => 'https://tribalsand.com/tribalsandstory.php'],
]);

require_once 'includes/head.php';
?>
<style>
/* ── OUR STORY PAGE ── */

/* HERO */
.story-hero{
  position:relative;height:100vh;min-height:600px;
  background:#0a1c24 url('images/New-hero-banner.jpg') center center / cover no-repeat;
  display:flex;align-items:flex-end;
  padding:0 6vw 10vh;
  overflow:hidden;
}
.story-hero::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(to bottom,rgba(10,28,36,.3) 0%,rgba(10,28,36,.55) 50%,rgba(10,28,36,.88) 100%);
}
.story-hero-content{position:relative;z-index:2;max-width:720px;}
.story-hero-eyebrow{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.32em;text-transform:uppercase;
  color:rgba(184,150,90,.85);margin-bottom:1.2rem;
}
.story-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.6rem,5.5vw,4.4rem);font-weight:300;
  color:#fff;line-height:1.06;margin-bottom:1.4rem;
}
.story-hero h1 em{font-style:italic;color:var(--sand);}
.story-hero-sub{
  font-family:'Jost',sans-serif;font-size:.9rem;
  color:rgba(255,255,255,.75);line-height:1.85;
  max-width:480px;
}

/* INTRO */
.story-intro{background:#fff;padding:7rem 6vw;}
.story-intro-inner{max-width:920px;margin:0 auto;}
.story-pullquote{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.6rem,3vw,2.5rem);font-weight:300;
  color:var(--teal-d);line-height:1.25;
  font-style:italic;
  border-left:3px solid var(--sand);
  padding-left:2rem;
  margin-bottom:3rem;
}
.story-intro p{
  font-family:'Jost',sans-serif;font-size:.94rem;
  color:var(--mid);line-height:1.88;margin-bottom:1.2rem;
  max-width:740px;
}

/* TIMELINE */
.story-timeline{
  background:var(--off);padding:7rem 6vw;
}
.story-timeline-inner{max-width:960px;margin:0 auto;}
.story-tl-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;
}
.story-tl-heading{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);margin-bottom:3.5rem;
}
.story-tl-list{
  position:relative;
  padding-left:3rem;
  border-left:1px solid rgba(184,150,90,.22);
  display:flex;flex-direction:column;gap:0;
}
.story-tl-item{
  position:relative;
  padding:0 0 3.5rem 2rem;
}
.story-tl-item:last-child{padding-bottom:0;}
.story-tl-dot{
  position:absolute;left:-3.4rem;top:.35rem;
  width:14px;height:14px;border-radius:50%;
  background:var(--sand);
  border:3px solid var(--off);
  box-shadow:0 0 0 1px rgba(184,150,90,.35);
}
.story-tl-year{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.4rem;
}
.story-tl-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.3rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.6rem;
}
.story-tl-body{
  font-family:'Jost',sans-serif;font-size:.88rem;
  color:var(--mid);line-height:1.8;max-width:600px;
}

/* VALUES */
.story-values{background:var(--teal-d);padding:7rem 6vw;}
.story-values-inner{max-width:1100px;margin:0 auto;}
.story-values-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.75);margin-bottom:.8rem;text-align:center;
}
.story-values h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:#fff;text-align:center;margin-bottom:3.5rem;
}
.story-values-grid{
  display:grid;grid-template-columns:repeat(4,1fr);gap:1.5rem;
}
.story-val-card{
  border:1px solid rgba(184,150,90,.14);
  padding:2rem 1.5rem;text-align:center;
  transition:border-color .22s,background .22s;
}
.story-val-card:hover{
  border-color:rgba(184,150,90,.35);
  background:rgba(184,150,90,.05);
}
.story-val-icon{
  font-size:1.8rem;color:var(--sand);margin-bottom:1rem;
}
.story-val-title{
  font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-weight:500;
  color:#fff;margin-bottom:.6rem;
}
.story-val-desc{
  font-family:'Jost',sans-serif;font-size:.78rem;
  color:rgba(255,255,255,.85);line-height:1.75;
}

/* CTA */
.story-cta{
  background:var(--off);padding:6rem 6vw;text-align:center;
  border-top:1px solid var(--border);
}
.story-cta p{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.4rem,2.5vw,2rem);font-weight:300;
  color:var(--teal-d);font-style:italic;margin-bottom:.75rem;
}
.story-cta-sub{
  font-family:'Jost',sans-serif;font-size:.84rem;
  color:var(--mid);margin-bottom:2.2rem;
}
.story-btn{
  display:inline-block;font-family:'Jost',sans-serif;font-size:.72rem;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2.4rem;background:var(--sand);
  color:var(--teal-d);border:1px solid var(--sand);
  font-weight:500;transition:background .22s,border-color .22s;
}
.story-btn:hover{background:var(--sand-lt,#D4B07A);border-color:var(--sand-lt,#D4B07A);}

/* RESPONSIVE */
@media(max-width:900px){
  .story-values-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:560px){
  .story-values-grid{grid-template-columns:1fr;}
  .story-tl-list{padding-left:2rem;}
  .story-tl-dot{left:-2.4rem;}
}
</style>

<body class="ts-nav-transparent">
<?php include 'includes/header.php'; ?>

<!-- HERO -->
<section class="story-hero">
  <div class="story-hero-content">
    <p class="story-hero-eyebrow">Tribal Sand</p>
    <h1>Kenya as It Was <em>Meant to Be</em></h1>
    <p class="story-hero-sub">Our story begins at the edge of the Indian Ocean.</p>
  </div>
</section>

<!-- INTRO -->
<section class="story-intro">
  <div class="story-intro-inner">
    <p class="story-pullquote">"We believe luxury and responsibility are not opposites."</p>
    <p>Tribal Sand is a sustainable coastal hospitality ecosystem operating across Watamu, Kilifi and Vipingo, Kenya. It combines luxury boutique hotels, private beachfront villas, an eco retreat compound, coworking spaces, elevated dining and watersport experiences within an interconnected, solar-powered and environmentally responsible system.</p>
    <p>At its centre is Tribal Dunes in Kilifi — a fully solar-powered beachfront village operating on desalinated ocean water and built around wellbeing, sport, community and refined coastal living. With different accommodation and dining options to suit different needs, Tribal Dunes is a destination in itself.</p>
    <p>Tribal Sand serves affluent travellers who seek privacy, design and meaningful impact. Guests enjoy beachfront luxury powered by renewable energy, supported by local communities and rooted in environmental responsibility. Kenya as it was meant to be experienced.</p>
  </div>
</section>

<!-- TIMELINE -->
<section class="story-timeline">
  <div class="story-timeline-inner">
    <p class="story-tl-eyebrow">Our Journey</p>
    <h2 class="story-tl-heading">A Decade on the Coast</h2>

    <div class="story-tl-list">

      <div class="story-tl-item">
        <div class="story-tl-dot"></div>
        <div class="story-tl-year">2014 · The Beginning</div>
        <div class="story-tl-title">My Amani</div>
        <div class="story-tl-body">My Amani villa opens in Vipingo. A private beachfront escape, self-sufficient and community-rooted — the foundation of everything that followed.</div>
      </div>

      <div class="story-tl-item">
        <div class="story-tl-dot"></div>
        <div class="story-tl-year">2017 · Watamu</div>
        <div class="story-tl-title">Zuri Boutique Hotel</div>
        <div class="story-tl-body">Zuri opens in Watamu, Kenya's most pristine marine park coast. Six handcrafted suites overlooking the Indian Ocean, steps from the reef.</div>
      </div>

      <div class="story-tl-item">
        <div class="story-tl-dot"></div>
        <div class="story-tl-year">2019 · Kilifi</div>
        <div class="story-tl-title">Maya Kobe</div>
        <div class="story-tl-body">Maya Kobe arrives on Bofa Beach, Kilifi. Balinese-inspired design meets the Indian Ocean — five suites and a beachfront pool that became the heart of Tribal Dunes.</div>
      </div>

      <div class="story-tl-item">
        <div class="story-tl-dot"></div>
        <div class="story-tl-year">2022 · The Ecosystem</div>
        <div class="story-tl-title">Enkare, Sandbox &amp; Maya Ilai</div>
        <div class="story-tl-body">Enkare and Sandbox villas join the portfolio, bringing private villa capacity to Kilifi. Maya Ilai eco compound opens — sixteen units for long-stay guests, digital nomads and community living.</div>
      </div>

      <div class="story-tl-item">
        <div class="story-tl-dot"></div>
        <div class="story-tl-year">2024 · Tribal Dunes</div>
        <div class="story-tl-title">The Vision Realised</div>
        <div class="story-tl-body">The vision fully realised. Tribal Dunes — a solar-powered beachfront village in Kilifi — unites Maya Kobe, Maya Ilai, Off Duty coworking, Tribal Table restaurant, Somewhere Café and the Tribal Kite School into one integrated coastal community.</div>
      </div>

    </div>
  </div>
</section>

<!-- VALUES -->
<section class="story-values">
  <div class="story-values-inner">
    <p class="story-values-eyebrow">What We Stand For</p>
    <h2>Our Values</h2>
    <div class="story-values-grid">
      <div class="story-val-card">
        <div class="story-val-icon"><i class="fas fa-sun"></i></div>
        <div class="story-val-title">Solar Powered</div>
        <div class="story-val-desc">100% renewable energy at Tribal Dunes. 27.59 MWh generated annually, 21.88T CO&#8322; avoided.</div>
      </div>
      <div class="story-val-card">
        <div class="story-val-icon"><i class="fas fa-fish"></i></div>
        <div class="story-val-title">Ocean Conservation</div>
        <div class="story-val-desc">Weekly beach cleanups, coral restoration and turtle nest protection along Kenya's North Coast.</div>
      </div>
      <div class="story-val-card">
        <div class="story-val-icon"><i class="fas fa-hands-helping"></i></div>
        <div class="story-val-title">Local Community</div>
        <div class="story-val-desc">Over 80% of our team from local coastal communities. Local sourcing, skills training and school support.</div>
      </div>
      <div class="story-val-card">
        <div class="story-val-icon"><i class="fas fa-map-marker-alt"></i></div>
        <div class="story-val-title">Authentic Kenya</div>
        <div class="story-val-desc">No resort walls. Real coast. Real people. Kenya as it was meant to be experienced.</div>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="story-cta">
  <p>"The coast is waiting."</p>
  <p class="story-cta-sub">Plan your stay across Watamu, Kilifi and Vipingo.</p>
  <a href="trip-builder.php" class="story-btn">Experience the Coast</a>
</section>

<?php include 'includes/footer.php'; ?>
</body>
