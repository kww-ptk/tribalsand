<?php
require_once 'includes/schema.php';

$page_title   = 'Hotels in Watamu Kenya · Beachfront Boutique Hotel · Tribal Sand';
$page_desc    = 'Stay at Zuri, Watamu\'s most intimate beachfront boutique hotel. Six ocean-facing suites on Kenya\'s North Coast, inside a marine national park, with direct beach access.';
$page_url     = 'https://tribalsand.com/watamu.php';
$page_image   = 'https://tribalsand.com/images/zuri/Aerial/zuri-3.webp';

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',         'url' => 'https://tribalsand.com/'],
    ['name' => 'Destinations', 'url' => 'https://tribalsand.com/'],
    ['name' => 'Watamu',       'url' => 'https://tribalsand.com/watamu.php'],
]);
$page_schema .= ts_schema_local_business();
$page_schema .= ts_schema_faq([
    [
        'q' => 'What is the best hotel in Watamu Kenya?',
        'a' => 'Zuri is Watamu\'s most intimate beachfront boutique hotel — six ocean-facing suites with direct beach access, inside the Watamu Marine National Park on Kenya\'s North Coast. Owned and operated by Tribal Sand, Zuri offers personalised service, exceptional food, and some of the best snorkelling in the world on your doorstep.',
    ],
    [
        'q' => 'Is Watamu safe for tourists?',
        'a' => 'Yes. Watamu is a relaxed, welcoming beach village on Kenya\'s North Coast with a long-established international tourism community. It has an excellent safety record and a strong local hospitality culture. As with any destination, standard travel precautions apply.',
    ],
    [
        'q' => 'What is Watamu known for?',
        'a' => 'Watamu is internationally famous for its marine national park and world-class snorkelling, its turtle nesting beaches (protected by Local Ocean Conservation), the whale shark aggregation season (October to February), kitesurfing at Blue Bay, deep-sea fishing, and the adjacent Arabuko-Sokoke Forest — Africa\'s largest intact coastal dry forest.',
    ],
    [
        'q' => 'When is the best time to visit Watamu?',
        'a' => 'The best time to visit Watamu is June to October (dry season) for calm seas, reliable sunshine and excellent snorkelling visibility. November to February is peak whale shark season — a unique ocean encounter found nowhere else on the Kenyan coast. March–May is the rainy season and generally best avoided.',
    ],
]);

include 'includes/head.php';
?>

<style>
/* ── WATAMU PAGE STYLES ── */

/* Hero */
.loc-hero {
  position: relative;
  height: 55vh;
  min-height: 480px;
  overflow: hidden;
  background: var(--teal-d);
  display: flex;
  align-items: center;
  justify-content: center;
}
.loc-hero-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(160deg, rgba(16,47,58,.82) 0%, rgba(16,47,58,.6) 60%, rgba(16,47,58,.75) 100%);
  z-index: 1;
}
.loc-hero-bg {
  position: absolute;
  inset: 0;
}
.loc-hero-bg img {
  width: 100%; height: 100%; object-fit: cover; object-position: center 40%;
}
.loc-hero-inner {
  position: relative;
  z-index: 2;
  text-align: center;
  padding: 0 24px;
  max-width: 880px;
}
.loc-hero-eyebrow {
  font-family: 'Jost', sans-serif;
  font-size: .75rem;
  font-weight: 500;
  letter-spacing: .36em;
  text-transform: uppercase;
  color: rgba(184,150,90,.9);
  margin-bottom: 1.2rem;
  display: block;
}
.loc-hero-h1 {
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(2.4rem, 5.5vw, 5rem);
  font-weight: 300;
  color: #fff;
  line-height: 1;
  margin-bottom: 1.4rem;
}
.loc-hero-sub {
  font-size: .98rem;
  color: rgba(212,196,172,.82);
  line-height: 1.8;
  max-width: 620px;
  margin: 0 auto;
}

/* Breadcrumb */
.loc-breadcrumb {
  padding: .9rem 5vw;
  background: var(--white);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-wrap: wrap;
  list-style: none;
  margin: 0;
}
.loc-breadcrumb a {
  font-family: 'Jost', sans-serif;
  font-size: .75rem;
  letter-spacing: .1em;
  color: var(--light);
  transition: color .2s;
}
.loc-breadcrumb a:hover { color: var(--teal); }
.loc-breadcrumb-sep { font-size: .75rem; color: rgba(184,150,90,.4); }
.loc-breadcrumb-curr {
  font-family: 'Jost', sans-serif;
  font-size: .75rem;
  letter-spacing: .1em;
  color: var(--sand);
}

/* About Watamu section */
.loc-about {
  padding: 88px 5vw;
  background: var(--off);
}
.loc-about-inner {
  max-width: 1260px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 1.5fr 1fr;
  gap: 4rem;
  align-items: start;
}
.loc-about-body p {
  margin-bottom: 1.2rem;
}
.loc-about-body p:last-child { margin-bottom: 0; }

.loc-facts {
  background: var(--teal-d);
  padding: 2.2rem;
  position: sticky;
  top: 88px;
}
.loc-facts-title {
  font-family: 'Jost', sans-serif;
  font-size: .62rem;
  letter-spacing: .28em;
  text-transform: uppercase;
  color: rgba(184,150,90,.75);
  margin-bottom: 1.4rem;
  display: block;
}
.loc-facts-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: .68rem 0;
  border-bottom: 1px solid rgba(184,150,90,.1);
  gap: 1rem;
}
.loc-facts-row:last-child { border-bottom: none; }
.loc-facts-label {
  font-family: 'Jost', sans-serif;
  font-size: .72rem;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: rgba(184,150,90,.6);
  flex-shrink: 0;
}
.loc-facts-val {
  font-family: 'Jost', sans-serif;
  font-size: .82rem;
  color: rgba(212,196,172,.88);
  text-align: right;
  line-height: 1.5;
}

/* Zuri feature block */
.loc-zuri {
  padding: 88px 5vw;
  background: var(--white);
}
.loc-zuri-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4rem;
  align-items: center;
  max-width: 1260px;
  margin: 2.5rem auto 0;
}
.loc-zuri-img-wrap {
  position: relative;
  overflow: hidden;
}
.loc-zuri-img {
  width: 100%;
  aspect-ratio: 4/3;
  object-fit: cover;
  display: block;
  transition: transform .5s;
}
.loc-zuri-img-wrap:hover .loc-zuri-img { transform: scale(1.03); }
.loc-zuri-tag {
  position: absolute;
  bottom: 1.2rem;
  left: 1.2rem;
  background: var(--teal-d);
  color: rgba(184,150,90,.9);
  font-family: 'Jost', sans-serif;
  font-size: .6rem;
  letter-spacing: .22em;
  text-transform: uppercase;
  padding: .45rem .85rem;
}
.loc-zuri-body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.loc-zuri-body h3 {
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(1.8rem, 3vw, 2.6rem);
  font-weight: 300;
  color: var(--dark);
  line-height: 1.05;
}
.loc-zuri-body h3 em { font-style: italic; color: var(--teal); }
.loc-zuri-body p {
  font-size: .96rem;
  color: var(--mid);
  line-height: 1.88;
}
.loc-zuri-detail-row {
  display: flex;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 1rem 0;
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}
.loc-zuri-detail {
  display: flex;
  flex-direction: column;
  gap: .18rem;
}
.loc-zuri-detail-n {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.6rem;
  font-weight: 300;
  color: var(--teal);
  line-height: 1;
}
.loc-zuri-detail-l {
  font-family: 'Jost', sans-serif;
  font-size: .64rem;
  letter-spacing: .16em;
  text-transform: uppercase;
  color: var(--light);
}

/* Things to do */
.loc-todo {
  padding: 88px 5vw;
  background: var(--off);
}
.loc-todo-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.2rem;
  max-width: 1260px;
  margin: 2.5rem auto 0;
}
.exp-card {
  background: var(--white);
  border: 1px solid var(--border);
  padding: 1.8rem 1.6rem;
  display: flex;
  flex-direction: column;
  gap: .55rem;
  transition: border-color .2s, box-shadow .2s;
}
.exp-card:hover {
  border-color: rgba(184,150,90,.38);
  box-shadow: 0 6px 24px rgba(0,0,0,.06);
}
.exp-card-icon {
  font-size: 1.7rem;
  margin-bottom: .3rem;
}
.exp-card-name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.2rem;
  font-weight: 400;
  color: var(--dark);
}
.exp-card-desc {
  font-size: .86rem;
  color: var(--mid);
  line-height: 1.75;
}

/* FAQ */
.loc-faq {
  padding: 88px 5vw;
  background: var(--white);
}
.loc-faq-inner {
  max-width: 820px;
  margin: 0 auto;
}
.faq-list {
  margin-top: 2.5rem;
  border-top: 1px solid var(--border);
}
.faq-item {
  border-bottom: 1px solid var(--border);
}
.faq-question {
  width: 100%;
  background: none;
  border: none;
  padding: 1.4rem 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.5rem;
  cursor: pointer;
  text-align: left;
}
.faq-question-text {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.18rem;
  font-weight: 400;
  color: var(--dark);
  line-height: 1.35;
}
.faq-icon {
  font-family: 'Jost', sans-serif;
  font-size: 1.4rem;
  color: var(--sand);
  flex-shrink: 0;
  line-height: 1;
  transition: transform .2s;
  width: 22px;
  text-align: center;
}
.faq-answer {
  overflow: hidden;
  max-height: 0;
  transition: max-height .35s cubic-bezier(.4,0,.2,1), padding .3s;
  padding: 0 0;
}
.faq-answer p {
  font-size: .96rem;
  color: var(--mid);
  line-height: 1.88;
  padding-bottom: 1.4rem;
}
.faq-item.open .faq-answer {
  max-height: 400px;
}
.faq-item.open .faq-icon {
  transform: rotate(45deg);
}

/* CTA strip */
.loc-cta {
  padding: 72px 5vw;
  background: var(--teal-d);
  text-align: center;
}
.loc-cta-eyebrow {
  font-family: 'Jost', sans-serif;
  font-size: .75rem;
  letter-spacing: .32em;
  text-transform: uppercase;
  color: rgba(184,150,90,.8);
  margin-bottom: 1rem;
  display: block;
}
.loc-cta h2 {
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(2rem, 4vw, 3.4rem);
  font-weight: 300;
  color: #fff;
  margin-bottom: 1rem;
}
.loc-cta h2 em { font-style: italic; color: var(--sand-lt); }
.loc-cta p {
  color: rgba(212,196,172,.7);
  font-size: .98rem;
  line-height: 1.85;
  max-width: 480px;
  margin: 0 auto 2.2rem;
}
.loc-cta-btns {
  display: flex;
  gap: .9rem;
  justify-content: center;
  flex-wrap: wrap;
}

/* Section header util */
.section-header { max-width: 1260px; margin: 0 auto 0; }

/* Responsive */
@media(max-width:1100px){
  .loc-about-inner { grid-template-columns: 1fr; }
  .loc-facts { position: static; }
  .loc-zuri-grid { grid-template-columns: 1fr; }
  .loc-todo-grid { grid-template-columns: repeat(2,1fr); }
}
@media(max-width:768px){
  .loc-todo-grid { grid-template-columns: 1fr; }
  .loc-about, .loc-zuri, .loc-todo, .loc-faq { padding: 56px 20px; }
  .loc-cta { padding: 56px 20px; }
  .loc-breadcrumb { padding: .9rem 20px; }
  .loc-zuri-detail-row { gap: 1rem; }
}
</style>

</head>
<body>

<?php include 'includes/header.php'; ?>

<!-- ═══ HERO ═══ -->
<section class="loc-hero" aria-label="Watamu Kenya hotels and beachfront accommodation">
  <div class="loc-hero-bg">
    <img
      src="https://tribalsand.com/images/zuri/Aerial/zuri-3.webp"
      alt="Zuri beachfront hotel Watamu Kenya"
      width="1600" height="900"
      loading="eager"
    >
  </div>
  <div class="loc-hero-overlay"></div>
  <div class="loc-hero-inner">
    <span class="loc-hero-eyebrow">Watamu &middot; Kilifi County &middot; Kenya's North Coast</span>
    <h1 class="loc-hero-h1">Watamu · Kenya's<br><em style="font-style:italic;color:var(--sand-lt);">Most Beautiful Beach Town</em></h1>
    <p class="loc-hero-sub">A UNESCO biosphere reserve, a marine national park, and some of the whitest sand on the Indian Ocean — welcome to Watamu.</p>
  </div>
</section>

<!-- Breadcrumb -->
<nav aria-label="Breadcrumb">
  <ol class="loc-breadcrumb">
    <li><a href="./">Home</a></li>
    <li class="loc-breadcrumb-sep">/</li>
    <li><a href="./">Destinations</a></li>
    <li class="loc-breadcrumb-sep">/</li>
    <li class="loc-breadcrumb-curr">Watamu</li>
  </ol>
</nav>

<!-- ═══ ABOUT WATAMU ═══ -->
<section class="loc-about">
  <div class="loc-about-inner">

    <!-- Body copy -->
    <div class="loc-about-body">
      <span class="eyebrow">About Watamu</span>
      <h2 class="sec-h" style="margin-bottom:1.4rem;">Where the Ocean<br><em>Meets the Forest</em></h2>
      <p>Watamu is a small beach village on Kenya's North Coast that has, over the decades, quietly become one of Africa's most remarkable natural destinations. It sits at the northern edge of the Watamu Marine National Park — a UNESCO-listed biosphere reserve that protects some of the richest coral reef ecosystems on the planet. The result is a place where you can snorkel with sea turtles in the morning, walk through an ancient indigenous forest in the afternoon, and watch the Indian Ocean turn violet at sunset.</p>
      <p>The marine park is the centrepiece of Watamu's appeal. Established in 1968 as Kenya's first marine park, it encompasses 10 sq km of protected ocean containing over 600 species of fish, extensive coral gardens, dolphins, and the seasonal presence of whale sharks (Rhincodon typus) between October and February — one of the most extraordinary wildlife encounters available anywhere on earth. Big Blue Ocean Conservation operates daily snorkelling trips directly from the beach.</p>
      <p>On land, Watamu is bordered by the Arabuko-Sokoke Forest — Africa's largest intact fragment of coastal dry forest, home to the critically endangered Sokoke Scops Owl, the golden-rumped elephant shrew, and over 230 species of birds. The forest is 20 minutes by road and a world apart from the beach.</p>
      <p>Watamu Village itself is small, characterful and genuinely unhurried: fresh seafood restaurants, the famous Blue Bay beach for kitesurfers, a local turtle sanctuary run by Local Ocean Conservation, and a coastal community that has welcomed travellers for generations. Malindi, with its historic Portuguese fort, Gede ruins and wider restaurant scene, is 20 minutes north. Mombasa International Airport (MBA) is approximately 1.5 hours south — with multiple daily flights from Nairobi taking under one hour.</p>
    </div>

    <!-- Facts box -->
    <aside class="loc-facts" aria-label="Watamu location facts">
      <span class="loc-facts-title">Location at a Glance</span>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Coordinates</span>
        <span class="loc-facts-val">3°21&prime;S, 40°01&prime;E</span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Nearest Airport</span>
        <span class="loc-facts-val">Mombasa (MBA)<br><span style="font-size:.74rem;color:rgba(184,150,90,.5);">~1.5 hrs drive</span></span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Malindi Airport</span>
        <span class="loc-facts-val">MYD<br><span style="font-size:.74rem;color:rgba(184,150,90,.5);">~20 min drive</span></span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Ocean Temp</span>
        <span class="loc-facts-val">26–29°C year-round</span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Marine Park</span>
        <span class="loc-facts-val">Watamu MNP<br><span style="font-size:.74rem;color:rgba(184,150,90,.5);">UNESCO Biosphere</span></span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Whale Sharks</span>
        <span class="loc-facts-val">Oct – Feb season</span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Best Time</span>
        <span class="loc-facts-val">Jun–Oct &amp; Nov–Feb</span>
      </div>
    </aside>

  </div>
</section>

<!-- ═══ ZURI FEATURE ═══ -->
<section class="loc-zuri" aria-label="Zuri boutique hotel Watamu">
  <div class="section-header">
    <span class="eyebrow">Where to Stay</span>
    <h2 class="sec-h">Zuri — <em>Watamu's Most Intimate<br>Beachfront Hotel</em></h2>
  </div>
  <div class="loc-zuri-grid">

    <div class="loc-zuri-img-wrap">
      <img class="loc-zuri-img" src="https://tribalsand.com/images/zuri/Aerial/zuri-3.webp" alt="Zuri boutique hotel Watamu aerial view" width="800" height="600" loading="lazy">
      <span class="loc-zuri-tag">Watamu Marine National Park</span>
    </div>

    <div class="loc-zuri-body">
      <h3>Zuri<br><em>Boutique Hotel, Watamu</em></h3>
      <p>Zuri is Tribal Sand's most intimate property — six ocean-facing suites arranged around a garden and pool, positioned directly on Watamu's beach and inside the marine national park. The name means "beautiful" in Swahili, and the hotel earns it: thoughtful design, exceptional locally-sourced food, and the kind of personalised service that makes guests feel like they are staying at a friend's house rather than a hotel.</p>
      <p>Every suite at Zuri has a direct view of the Indian Ocean. The house snorkelling reef is a few minutes' swim from the beach. Sea turtles nest on the beach seasonally. Whale sharks visit the waters just offshore from October to February. This is one of the finest wildlife and beach combinations available anywhere on the East African coast.</p>
      <div class="loc-zuri-detail-row">
        <div class="loc-zuri-detail">
          <span class="loc-zuri-detail-n">6</span>
          <span class="loc-zuri-detail-l">Ocean Suites</span>
        </div>
        <div class="loc-zuri-detail">
          <span class="loc-zuri-detail-n">5★</span>
          <span class="loc-zuri-detail-l">Boutique Rating</span>
        </div>
        <div class="loc-zuri-detail">
          <span class="loc-zuri-detail-n">Direct</span>
          <span class="loc-zuri-detail-l">Beach Access</span>
        </div>
        <div class="loc-zuri-detail">
          <span class="loc-zuri-detail-n">MNP</span>
          <span class="loc-zuri-detail-l">Inside Marine Park</span>
        </div>
      </div>
      <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.5rem;">
        <a href="zuri.php" class="btn-primary">View Zuri →</a>
        <a href="trip-builder.php" style="display:inline-block;font-family:'Jost',sans-serif;font-size:.75rem;font-weight:400;letter-spacing:.2em;text-transform:uppercase;padding:.88rem 2.2rem;background:none;border:1px solid rgba(30,92,107,.35);color:var(--teal);transition:all .22s;">Plan Your Stay</a>
      </div>
    </div>

  </div>
</section>

<!-- ═══ THINGS TO DO ═══ -->
<section class="loc-todo">
  <div class="section-header">
    <span class="eyebrow">Experiences</span>
    <h2 class="sec-h">Things to Do<br><em>in Watamu</em></h2>
  </div>
  <div class="loc-todo-grid">

    <div class="exp-card">
      <div class="exp-card-icon"></div>
      <h3 class="exp-card-name">Snorkelling &amp; Marine Park</h3>
      <p class="exp-card-desc">The Watamu Marine National Park is one of the world's best accessible snorkelling sites — pristine coral gardens, 600+ fish species, sea turtles and vibrant reef life just minutes from Zuri's beach. Big Blue Ocean Conservation leads guided trips daily.</p>
    </div>

    <div class="exp-card">
      <div class="exp-card-icon"></div>
      <h3 class="exp-card-name">Whale Shark Season</h3>
      <p class="exp-card-desc">From October to February, whale sharks aggregate in the waters off Watamu in extraordinary numbers — up to 200 individuals at peak. Swimming alongside the world's largest fish is a bucket-list experience found at very few places on earth.</p>
    </div>

    <div class="exp-card">
      <div class="exp-card-icon"></div>
      <h3 class="exp-card-name">Dhow Cruise</h3>
      <p class="exp-card-desc">A traditional wooden dhow cruise along the Watamu and Malindi coastline at sunset is one of the coast's defining experiences — calm water, golden light, dolphins and the rhythm of a sailing tradition that stretches back centuries.</p>
    </div>

    <div class="exp-card">
      <div class="exp-card-icon"></div>
      <h3 class="exp-card-name">Arabuko-Sokoke Forest</h3>
      <p class="exp-card-desc">Africa's largest remaining coastal dry forest begins just 20 minutes from Zuri. Guided forest walks reveal endangered birds, elephant shrews, and a biodiversity that rivals the coral reef — making Watamu one of the world's rare dual nature destinations.</p>
    </div>

    <div class="exp-card">
      <div class="exp-card-icon"></div>
      <h3 class="exp-card-name">Deep-Sea Fishing</h3>
      <p class="exp-card-desc">The waters off Watamu are among East Africa's richest for offshore game fishing — marlin, sailfish, wahoo and yellowfin tuna are regularly caught. Full and half-day fishing charters can be arranged from Watamu or Malindi.</p>
    </div>

    <div class="exp-card">
      <div class="exp-card-icon"></div>
      <h3 class="exp-card-name">Kitesurfing at Blue Bay</h3>
      <p class="exp-card-desc">Watamu's Blue Bay is a dedicated kite beach with reliable south-easterly winds June through October and a flat-water lagoon ideal for beginners. A BKSA-certified school operates from the beach throughout the kite season.</p>
    </div>

  </div>
</section>

<!-- ═══ FAQ ═══ -->
<section class="loc-faq">
  <div class="loc-faq-inner">
    <span class="eyebrow">FAQs</span>
    <h2 class="sec-h">Frequently Asked<br><em>Questions</em></h2>

    <div class="faq-list" role="list">

      <div class="faq-item" role="listitem">
        <button class="faq-question" aria-expanded="false" aria-controls="faq-w-1">
          <span class="faq-question-text">What is the best hotel in Watamu Kenya?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-w-1" role="region">
          <p><a href="zuri.php" style="color:var(--teal);">Zuri</a> is widely regarded as Watamu's most intimate and beautifully positioned beachfront boutique hotel. Six ocean-facing suites with direct beach access, inside the Watamu Marine National Park, with exceptional personalised service and some of the finest food on the coast. Owned and operated by Tribal Sand.</p>
        </div>
      </div>

      <div class="faq-item" role="listitem">
        <button class="faq-question" aria-expanded="false" aria-controls="faq-w-2">
          <span class="faq-question-text">Is Watamu safe for tourists?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-w-2" role="region">
          <p>Yes — Watamu is a relaxed and welcoming beach village with a long-established international tourism community and an excellent safety record. The village is small, friendly and well-oriented towards visitors. As with any destination, standard travel precautions apply: secure your valuables, use registered transport and follow local guidance. Our team at <a href="contact.php" style="color:var(--teal);">Tribal Sand</a> is always happy to advise on the current situation.</p>
        </div>
      </div>

      <div class="faq-item" role="listitem">
        <button class="faq-question" aria-expanded="false" aria-controls="faq-w-3">
          <span class="faq-question-text">What is Watamu known for?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-w-3" role="region">
          <p>Watamu is internationally known for its <strong>marine national park</strong> (one of Africa's best snorkelling sites), its <strong>turtle nesting beaches</strong> (protected by Local Ocean Conservation), its seasonal <strong>whale shark aggregation</strong> (October to February), and the adjacent <strong>Arabuko-Sokoke Forest</strong>. It is also famous for the Blue Bay kite beach, deep-sea fishing out of Malindi, and the archaeological ruins at Gede, 10 minutes inland. It was designated a UNESCO Biosphere Reserve in 1979.</p>
        </div>
      </div>

      <div class="faq-item" role="listitem">
        <button class="faq-question" aria-expanded="false" aria-controls="faq-w-4">
          <span class="faq-question-text">When is the best time to visit Watamu?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-w-4" role="region">
          <p>The best time for <strong>beach and snorkelling</strong> is <strong>June to October</strong> — the long dry season brings calm seas, clear underwater visibility and reliable sunshine. <strong>November to February</strong> is the peak season for <strong>whale sharks</strong>, making it one of the world's best wildlife encounters if that is on your list. December and January are popular family holiday months with warm, dry conditions. March to May is the long rainy season; November can have short rains but the coast often stays sunny.</p>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ═══ CTA STRIP ═══ -->
<section class="loc-cta">
  <span class="loc-cta-eyebrow">Ready to Visit</span>
  <h2>Book Your <em>Watamu Stay</em></h2>
  <p>Six ocean-facing suites, direct beach access and some of the world's best snorkelling on your doorstep. Availability at Zuri is limited — book early.</p>
  <div class="loc-cta-btns">
    <a href="zuri.php" class="btn-primary">View Zuri</a>
    <a href="trip-builder.php" class="btn-ghost">Plan Your Trip</a>
  </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
(function(){
  // FAQ accordion — single open at a time
  var items = document.querySelectorAll('.faq-item');
  items.forEach(function(item){
    var btn = item.querySelector('.faq-question');
    btn.addEventListener('click', function(){
      var isOpen = item.classList.contains('open');
      // close all
      items.forEach(function(i){
        i.classList.remove('open');
        i.querySelector('.faq-question').setAttribute('aria-expanded','false');
        i.querySelector('.faq-icon').textContent = '+';
      });
      // open clicked (if it was closed)
      if(!isOpen){
        item.classList.add('open');
        btn.setAttribute('aria-expanded','true');
        item.querySelector('.faq-icon').textContent = '−';
      }
    });
  });
})();
</script>

</body>
</html>
