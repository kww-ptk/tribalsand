<?php
require_once 'includes/schema.php';

$page_title   = 'Hotels in Kilifi Kenya · Boutique Beach Accommodation · Tribal Sand';
$page_desc    = 'Boutique hotels and private villas on Kilifi\'s Bofa Beach — Maya Kobe, Maya Ilai, Enkare Bofa, Sandbox and Off Duty, all within Tribal Dunes, Kenya.';
$page_url     = 'https://tribalsand.com/kilifi.php';
$page_image   = asset_url('images/Maya-Kobe-1-hero.webp');

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',         'url' => 'https://tribalsand.com/'],
    ['name' => 'Destinations', 'url' => 'https://tribalsand.com/'],
    ['name' => 'Kilifi',       'url' => 'https://tribalsand.com/kilifi.php'],
]);
$page_schema .= ts_schema_local_business();
$page_schema .= ts_schema_faq([
    [
        'q' => 'What are the best hotels in Kilifi Kenya?',
        'a' => 'The best hotels in Kilifi are found within Tribal Dunes on Bofa Beach: Maya Kobe (boutique hotel, 5 suites), Maya Ilai (eco compound), Off Duty (coworking hotel), Enkare Bofa (private villa, 5 rooms) and Sandbox (private villa, 4 rooms). All are operated by Tribal Sand and offer direct Indian Ocean beach access.',
    ],
    [
        'q' => 'Is Kilifi good for a beach holiday?',
        'a' => 'Yes. Kilifi\'s Bofa Beach is one of Kenya\'s finest stretches of Indian Ocean shoreline — wide white sand, calm turquoise water, and a backdrop of ancient baobabs. The Kilifi Creek estuary adds a dramatic natural setting, and the town itself is a lively cultural hub with restaurants, markets and nightlife.',
    ],
    [
        'q' => 'How far is Kilifi from Nairobi?',
        'a' => 'Kilifi is approximately 4 hours by road from Nairobi (via the A109 highway). The nearest airport is Mombasa\'s Moi International (MBA), which is about 1 hour\'s drive from Kilifi, with daily flights from Nairobi (under 1 hour) and connections from Malindi.',
    ],
    [
        'q' => 'What is the best time to visit Kilifi?',
        'a' => 'The best time to visit Kilifi is June to October (the long dry season) for calm seas, reliable sunshine and cooler evenings — ideal for kitesurfing, snorkelling and beach days. December to February is also excellent: warm, dry weather with fewer crowds. April and May are the rainy season.',
    ],
]);

include 'includes/head.php';
?>

<style>
/* ── KILIFI PAGE STYLES ── */

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

/* Intro 2-col */
.loc-intro {
  padding: 88px 5vw;
  background: var(--off);
}
.loc-intro-grid {
  display: grid;
  grid-template-columns: 1.5fr 1fr;
  gap: 4rem;
  align-items: start;
  max-width: 1260px;
  margin: 0 auto;
}
.loc-intro-body p {
  margin-bottom: 1.2rem;
}
.loc-intro-body p:last-child { margin-bottom: 0; }

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

/* Properties grid */
.loc-props {
  padding: 88px 5vw;
  background: var(--white);
}
.loc-props-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.2rem;
  max-width: 1260px;
  margin: 2.5rem auto 0;
}
.prop-card {
  background: var(--off);
  border: 1px solid var(--border);
  overflow: hidden;
  transition: transform .25s, box-shadow .25s;
}
.prop-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 40px rgba(0,0,0,.1);
}
.prop-card-img {
  width: 100%;
  aspect-ratio: 4/3;
  object-fit: cover;
  display: block;
  transition: transform .5s;
}
.prop-card:hover .prop-card-img { transform: scale(1.04); }
.prop-card-img-wrap { overflow: hidden; }
.prop-card-body { padding: 1.4rem; }
.prop-card-tag {
  font-family: 'Jost', sans-serif;
  font-size: .6rem;
  letter-spacing: .2em;
  text-transform: uppercase;
  color: var(--sand);
  margin-bottom: .55rem;
  display: block;
}
.prop-card-name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.35rem;
  font-weight: 400;
  color: var(--dark);
  margin-bottom: .5rem;
  line-height: 1.1;
}
.prop-card-desc {
  font-size: .86rem;
  color: var(--mid);
  line-height: 1.75;
  margin-bottom: 1.2rem;
}
.prop-card-link {
  display: inline-block;
  font-family: 'Jost', sans-serif;
  font-size: .68rem;
  letter-spacing: .18em;
  text-transform: uppercase;
  color: var(--teal);
  border-bottom: 1px solid rgba(30,92,107,.3);
  padding-bottom: .1rem;
  transition: color .2s, border-color .2s;
}
.prop-card-link:hover { color: var(--teal-d); border-color: var(--teal-d); }

/* Tribal Dunes section */
.loc-dunes {
  padding: 88px 5vw;
  background: var(--teal-d);
}
.loc-dunes-inner {
  max-width: 820px;
  margin: 0 auto;
  text-align: center;
}
.loc-dunes-inner .sec-h--light {
  margin-bottom: 1.4rem;
}
.loc-dunes-inner p {
  color: rgba(212,196,172,.78);
  font-size: .98rem;
  line-height: 1.9;
  margin-bottom: 1rem;
}
.loc-dunes-inner p:last-of-type { margin-bottom: 2rem; }

/* Activities */
.loc-activities {
  padding: 88px 5vw;
  background: var(--off);
}
.loc-activities-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.2rem;
  max-width: 1260px;
  margin: 2.5rem auto 0;
}
.act-card {
  background: var(--white);
  border: 1px solid var(--border);
  padding: 2rem 1.8rem;
  display: flex;
  flex-direction: column;
  gap: .6rem;
  transition: border-color .2s, box-shadow .2s;
}
.act-card:hover {
  border-color: rgba(184,150,90,.4);
  box-shadow: 0 6px 24px rgba(0,0,0,.06);
}
.act-card-icon {
  font-size: 1.8rem;
  margin-bottom: .4rem;
}
.act-card-name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.3rem;
  font-weight: 400;
  color: var(--dark);
}
.act-card-desc {
  font-size: .86rem;
  color: var(--mid);
  line-height: 1.75;
}
.act-card-link {
  margin-top: auto;
  font-family: 'Jost', sans-serif;
  font-size: .68rem;
  letter-spacing: .16em;
  text-transform: uppercase;
  color: var(--sand);
  transition: color .2s;
}
.act-card-link:hover { color: var(--teal); }

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
  .loc-props-grid { grid-template-columns: repeat(2,1fr); }
  .loc-intro-grid { grid-template-columns: 1fr; }
  .loc-facts { position: static; }
}
@media(max-width:768px){
  .loc-props-grid { grid-template-columns: 1fr; }
  .loc-activities-grid { grid-template-columns: 1fr; }
  .loc-intro, .loc-props, .loc-dunes, .loc-activities, .loc-faq { padding: 56px 20px; }
  .loc-cta { padding: 56px 20px; }
  .loc-breadcrumb { padding: .9rem 20px; }
}
</style>

</head>
<body>

<?php include 'includes/header.php'; ?>

<!-- ═══ HERO ═══ -->
<section class="loc-hero" aria-label="Kilifi Kenya hotels and beachfront accommodation">
  <div class="loc-hero-bg">
    <img
      src="<?= asset_url('images/Maya-Kobe-1-hero.webp') ?>"
      alt="Bofa Beach Kilifi — Tribal Dunes beachfront"
      width="1600" height="900"
      loading="eager"
    >
  </div>
  <div class="loc-hero-overlay"></div>
  <div class="loc-hero-inner">
    <span class="loc-hero-eyebrow">Bofa Beach &middot; Kilifi County &middot; Kenya Coast</span>
    <h1 class="loc-hero-h1">Kilifi's Most Exciting<br><em style="font-style:italic;color:var(--sand-lt);">Beachfront Address</em></h1>
    <p class="loc-hero-sub">Five interconnected properties on one stretch of Indian Ocean beach — boutique hotels, private villas, restaurants, a kite school and Kenya's most ambitious new coastal destination.</p>
  </div>
</section>

<!-- Breadcrumb -->
<nav aria-label="Breadcrumb">
  <ol class="loc-breadcrumb">
    <li><a href="./">Home</a></li>
    <li class="loc-breadcrumb-sep">/</li>
    <li><a href="./">Destinations</a></li>
    <li class="loc-breadcrumb-sep">/</li>
    <li class="loc-breadcrumb-curr">Kilifi</li>
  </ol>
</nav>

<!-- ═══ LOCATION INTRO ═══ -->
<section class="loc-intro">
  <div class="loc-intro-grid">

    <!-- Body copy -->
    <div class="loc-intro-body">
      <span class="eyebrow">About Kilifi</span>
      <h2 class="sec-h" style="margin-bottom:1.4rem;">A Creek, a Beach, and a<br><em>Coast Like No Other</em></h2>
      <p>Kilifi sits at the southern end of Kilifi County on Kenya's North Coast, where the Indian Ocean meets the dramatic natural harbour of Kilifi Creek. It is one of those rare places where genuine wildness and relaxed sophistication coexist without apology — ancient baobab forests framing white-sand beaches, dhows crossing a waterway that has been a trading route for millennia, and sunsets that turn the creek copper every evening without fail.</p>
      <p>Bofa Beach, where Tribal Dunes is located, is widely regarded as the finest stretch of shoreline in the Kilifi area — a long, uncrowded sweep of white sand with warm, swimmable water year-round. The kite-friendly geography and consistent south-easterly winds also make Bofa one of the most sought-after kitesurfing locations on the East African coast.</p>
      <p>Kilifi Town is a 10-minute drive from Bofa Beach and offers an authentic, unhurried Swahili-coast experience: fresh seafood markets, vibrant restaurants, the famous Kilifi Boatyard social scene, and the archaeological ruins of Mnarani at the creek's mouth. It is a town that rewards those who want more than just a beach — culture, community and a sense of place.</p>
      <p>Getting here is straightforward. Kilifi is approximately 90 minutes by road from Mombasa's Moi International Airport (MBA), which receives multiple daily flights from Nairobi (under one hour). From Nairobi by road, the journey is approximately 4 hours along the well-maintained A109 coastal highway. Malindi Airport, 90 minutes north, offers an alternative with direct regional connections.</p>
    </div>

    <!-- Facts box -->
    <aside class="loc-facts" aria-label="Kilifi location facts">
      <span class="loc-facts-title">Location at a Glance</span>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Coordinates</span>
        <span class="loc-facts-val">3°37&prime;S, 39°51&prime;E</span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Nearest Airport</span>
        <span class="loc-facts-val">Mombasa (MBA)<br><span style="font-size:.74rem;color:rgba(184,150,90,.5);">~90 min drive</span></span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">From Nairobi</span>
        <span class="loc-facts-val">~4 hrs road<br><span style="font-size:.74rem;color:rgba(184,150,90,.5);">or fly to Mombasa</span></span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Ocean Temp</span>
        <span class="loc-facts-val">26–29°C year-round</span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Climate</span>
        <span class="loc-facts-val">Tropical coastal<br><span style="font-size:.74rem;color:rgba(184,150,90,.5);">Low humidity Jun–Oct</span></span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Best Time</span>
        <span class="loc-facts-val">Jun–Oct &amp; Dec–Feb</span>
      </div>
      <div class="loc-facts-row">
        <span class="loc-facts-label">Tribal Dunes</span>
        <span class="loc-facts-val">Bofa Beach, Kilifi</span>
      </div>
    </aside>

  </div>
</section>

<!-- ═══ PROPERTIES GRID ═══ -->
<section class="loc-props">
  <div class="section-header">
    <span class="eyebrow">Where to Stay</span>
    <h2 class="sec-h">Five Properties,<br><em>One Beach</em></h2>
    <p class="sec-p" style="margin-top:.6rem;">All four Tribal Sand Kilifi properties sit within Tribal Dunes — Kenya's most ambitious new beachfront address — sharing a single stretch of Bofa Beach with direct ocean access.</p>
  </div>
  <div class="loc-props-grid">

    <!-- Maya Kobe -->
    <article class="prop-card">
      <div class="prop-card-img-wrap">
        <img class="prop-card-img" src="<?= asset_url('images/Maya-Kobe-1-hero.webp') ?>" alt="Maya Kobe boutique hotel Kilifi" width="640" height="480" loading="lazy">
      </div>
      <div class="prop-card-body">
        <span class="prop-card-tag">Boutique Hotel &middot; 5 Suites</span>
        <h3 class="prop-card-name">Maya Kobe</h3>
        <p class="prop-card-desc">The flagship of Tribal Dunes — a refined boutique hotel of five ocean-facing suites, a pool deck, an award-winning restaurant, and the kind of quiet luxury that still feels genuinely Kenyan.</p>
        <a href="maya-kobe.php" class="prop-card-link">View Property →</a>
      </div>
    </article>

    <!-- Maya Ilai -->
    <article class="prop-card">
      <div class="prop-card-img-wrap">
        <img class="prop-card-img" src="<?= asset_url('images/maya_illai/Best1.jpg') ?>" alt="Maya Ilai eco compound Kilifi" width="640" height="480" loading="lazy">
      </div>
      <div class="prop-card-body">
        <span class="prop-card-tag">Eco Compound &middot; 16 Units</span>
        <h3 class="prop-card-name">Maya Ilai</h3>
        <p class="prop-card-desc">A thoughtfully designed eco compound of studios, garden cottages and family units nestled behind the dunes. Relaxed, communal and a few steps from the beach.</p>
        <a href="maya_ilai.php" class="prop-card-link">View Property →</a>
      </div>
    </article>

    <!-- Enkare Bofa -->
    <article class="prop-card">
      <div class="prop-card-img-wrap">
        <img class="prop-card-img" src="<?= asset_url('images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg') ?>" alt="Enkare Bofa private villa Kilifi" width="640" height="480" loading="lazy">
      </div>
      <div class="prop-card-body">
        <span class="prop-card-tag">Private Villa &middot; 5 Rooms</span>
        <h3 class="prop-card-name">Enkare Bofa</h3>
        <p class="prop-card-desc">A full-rental private beachfront villa sleeping up to 10 guests. Exclusive use, a private pool, a dedicated team and direct access to Bofa Beach — ideal for families and small groups.</p>
        <a href="enkare-bofa.php" class="prop-card-link">View Property →</a>
      </div>
    </article>

    <!-- Sandbox -->
    <article class="prop-card">
      <div class="prop-card-img-wrap">
        <img class="prop-card-img" src="<?= asset_url('images/Sandbox/outdoors/IMG-20251117-WA0091.jpg') ?>" alt="Sandbox private villa Kilifi" width="640" height="480" loading="lazy">
      </div>
      <div class="prop-card-body">
        <span class="prop-card-tag">Private Villa &middot; 4 Rooms</span>
        <h3 class="prop-card-name">Sandbox</h3>
        <p class="prop-card-desc">The most playful address at Tribal Dunes — a four-bedroom private villa with its own pool, open social spaces and a personality that matches its name perfectly.</p>
        <a href="sandbox.php" class="prop-card-link">View Property →</a>
      </div>
    </article>

  </div>
</section>

<!-- ═══ TRIBAL DUNES SECTION ═══ -->
<section class="loc-dunes" aria-label="About Tribal Dunes">
  <div class="loc-dunes-inner">
    <span class="eyebrow eyebrow-dark" style="justify-content:center;">The Destination</span>
    <h2 class="sec-h sec-h--light" style="text-align:center;margin-bottom:1.4rem;">Welcome to <em>Tribal Dunes</em></h2>
    <p>Tribal Dunes is the name for the interconnected beachfront village that Tribal Sand has been building on Bofa Beach, Kilifi, since 2019. Where most coastal destinations offer a single hotel, Tribal Dunes offers an entire address — multiple properties, dining venues, an ocean-sports school, coworking spaces, events and a community of like-minded travellers, all within walking distance of each other along one of Kenya's most beautiful stretches of beach.</p>
    <p>Every property within Tribal Dunes shares a philosophy: luxury without pretension, sustainability without sacrifice, and a genuine connection to the Kenyan coast and its culture. Guests at any Tribal Sand property can access all communal spaces, attend events, dine across venues and build a stay that is entirely their own.</p>
    <a href="tribal-dunes.php" class="btn-ghost--sand" style="display:inline-block;font-family:'Jost',sans-serif;font-size:.72rem;font-weight:400;letter-spacing:.2em;text-transform:uppercase;padding:.88rem 2.2rem;background:none;border:1px solid rgba(184,150,90,.38);color:var(--sand-lt);transition:all .22s;">Discover Tribal Dunes →</a>
  </div>
</section>

<!-- ═══ ACTIVITIES ═══ -->
<section class="loc-activities">
  <div class="section-header">
    <span class="eyebrow">Nearby</span>
    <h2 class="sec-h">Things to Do<br><em>in Kilifi</em></h2>
  </div>
  <div class="loc-activities-grid">

    <div class="act-card">
      <div class="act-card-icon"></div>
      <h3 class="act-card-name">Kitesurfing</h3>
      <p class="act-card-desc">Bofa Beach is one of East Africa's premier kitesurfing locations. Consistent south-easterly winds, a flat water lagoon at low tide and a BKSA-certified school on site make Kilifi a world-class kite destination for all levels.</p>
      <a href="activities.php" class="act-card-link">Learn more →</a>
    </div>

    <div class="act-card">
      <div class="act-card-icon"></div>
      <h3 class="act-card-name">Dhow Cruise</h3>
      <p class="act-card-desc">A sunset dhow cruise on Kilifi Creek is one of the coast's most magical experiences — the ancient estuary turning amber and rose as the wooden dhow drifts between mangroves, with champagne or Dawa cocktails in hand.</p>
      <a href="activities.php" class="act-card-link">Learn more →</a>
    </div>

    <div class="act-card">
      <div class="act-card-icon"></div>
      <h3 class="act-card-name">Arabuko-Sokoke Forest</h3>
      <p class="act-card-desc">Africa's largest fragment of coastal dry forest is 45 minutes north of Kilifi. Home to the endangered Golden-rumped Elephant Shrew, Clarke's Weaver and six globally threatened species, it is one of Kenya's most important biodiversity hotspots.</p>
      <a href="activities.php" class="act-card-link">Learn more →</a>
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
        <button class="faq-question" aria-expanded="false" aria-controls="faq-k-1">
          <span class="faq-question-text">What are the best hotels in Kilifi Kenya?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-k-1" role="region">
          <p>The standout properties in Kilifi are all found within Tribal Dunes on Bofa Beach: <a href="maya-kobe.php" style="color:var(--teal);">Maya Kobe</a> (boutique hotel, 5 ocean-facing suites), <a href="maya_ilai.php" style="color:var(--teal);">Maya Ilai</a> (eco compound, studios and cottages), <a href="enkare-bofa.php" style="color:var(--teal);">Enkare Bofa</a> (private villa, 5 rooms, full rental) and <a href="sandbox.php" style="color:var(--teal);">Sandbox</a> (private villa, 4 rooms). All are operated by Tribal Sand and offer direct Indian Ocean beach access, exceptional service and a genuinely Kenyan atmosphere.</p>
        </div>
      </div>

      <div class="faq-item" role="listitem">
        <button class="faq-question" aria-expanded="false" aria-controls="faq-k-2">
          <span class="faq-question-text">Is Kilifi good for a beach holiday?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-k-2" role="region">
          <p>Absolutely. Kilifi's Bofa Beach is consistently rated among the finest beaches on Kenya's coast — wide white sand, clear turquoise Indian Ocean water, and year-round warmth (sea temperatures of 26–29°C). The Kilifi Creek estuary adds a dramatic natural backdrop, and the area combines beach life with cultural depth: Swahili coast architecture, fresh seafood, a buzzing local restaurant scene and nearby archaeological sites at Mnarani.</p>
        </div>
      </div>

      <div class="faq-item" role="listitem">
        <button class="faq-question" aria-expanded="false" aria-controls="faq-k-3">
          <span class="faq-question-text">How far is Kilifi from Nairobi?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-k-3" role="region">
          <p>Kilifi is approximately 4 hours by road from Nairobi via the A109 coastal highway. The fastest route is to fly: Nairobi to Mombasa's Moi International Airport (MBA) takes under one hour, with multiple daily flights on Kenya Airways, Jambojet and SafariLink. Kilifi is then 90 minutes by road from Mombasa airport. Alternatively, Malindi Airport (MYD) is about 90 minutes north of Kilifi, with connections from Nairobi via SafariLink.</p>
        </div>
      </div>

      <div class="faq-item" role="listitem">
        <button class="faq-question" aria-expanded="false" aria-controls="faq-k-4">
          <span class="faq-question-text">What is the best time to visit Kilifi?</span>
          <span class="faq-icon" aria-hidden="true">+</span>
        </button>
        <div class="faq-answer" id="faq-k-4" role="region">
          <p>The best time to visit Kilifi is <strong>June to October</strong> — the long dry season brings reliably sunny days, calm seas, cooler evenings and the strongest, most consistent winds for kitesurfing. <strong>December to February</strong> is also excellent, with warm dry weather, lower humidity and fewer crowds than the peak European holiday season. April and May are the long rains and best avoided; November brings short rains but the coast often stays sunny.</p>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ═══ CTA STRIP ═══ -->
<section class="loc-cta">
  <span class="loc-cta-eyebrow">Ready to Visit</span>
  <h2>Plan Your <em>Kilifi Stay</em></h2>
  <p>Tell us who you are, what you're looking for and when you're thinking of visiting — we'll match you with the perfect Tribal Sand property on Bofa Beach.</p>
  <div class="loc-cta-btns">
    <a href="trip-builder.php" class="btn-primary">Plan Your Trip</a>
    <a href="contact.php" class="btn-ghost">Contact Us</a>
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
