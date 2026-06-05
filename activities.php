<?php
require_once 'includes/schema.php';

/* ── SEO Variables ── */
$page_title  = 'Activities & Experiences · Kenya Coast · Tribal Sand';
$page_desc   = 'Things to do on Kenya\'s North Coast — kitesurfing Kilifi, snorkelling Watamu Marine Park, safaris, deep sea fishing, dhow cruises and wellness. Arranged by your concierge.';
$page_url    = 'https://tribalsand.com/activities.php';
$page_image  = 'https://tribalsand.com/images/34t.jpg';

/* ── Structured Data ── */
$page_schema =
    ts_schema_org() .
    ts_schema_breadcrumb([
        ['name' => 'Home',       'url' => 'https://tribalsand.com/'],
        ['name' => 'Activities', 'url' => 'https://tribalsand.com/activities.php'],
    ]) .
    ts_schema_item_list([
        ['name' => 'Snorkelling — Watamu Marine Park',  'url' => 'https://tribalsand.com/activities.php#water'],
        ['name' => 'Scuba Diving',                      'url' => 'https://tribalsand.com/activities.php#water'],
        ['name' => 'Deep Sea Fishing',                  'url' => 'https://tribalsand.com/activities.php#water'],
        ['name' => 'Kitesurfing Kilifi & Watamu',       'url' => 'https://tribalsand.com/activities.php#water'],
        ['name' => 'Sunset Dhow Cruise Kilifi Creek',   'url' => 'https://tribalsand.com/activities.php#water'],
        ['name' => 'Tsavo East Safari',                 'url' => 'https://tribalsand.com/activities.php#safari'],
        ['name' => 'Amboseli Safari',                   'url' => 'https://tribalsand.com/activities.php#safari'],
        ['name' => 'Private Yoga Sessions',             'url' => 'https://tribalsand.com/activities.php#wellness'],
        ['name' => 'Vipingo Ridge Golf',                'url' => 'https://tribalsand.com/activities.php#golf'],
        ['name' => 'Gede Ruins Cultural Tour',          'url' => 'https://tribalsand.com/activities.php#culture'],
        ['name' => 'Private Beach Dinners',             'url' => 'https://tribalsand.com/activities.php#dining'],
        ['name' => 'Airport Transfers',                 'url' => 'https://tribalsand.com/activities.php#transfers'],
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

/* ── COMPACT PAGE HERO ── */
.act-hero{
  background:var(--teal-d);
  padding:100px 5vw 72px;
  text-align:center;
  position:relative;
  overflow:hidden;
}
.act-hero::before{
  content:'';
  position:absolute;inset:0;
  background:radial-gradient(ellipse at 60% 0%, rgba(30,92,107,.55) 0%, transparent 70%);
  pointer-events:none;
}
.act-hero-inner{
  position:relative;
  z-index:1;
  max-width:780px;
  margin:0 auto;
}
.act-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.85);
  margin-bottom:1.1rem;
}
.act-h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.4rem,5vw,4rem);
  font-weight:300;
  color:#fff;
  line-height:1.05;
  margin-bottom:1.3rem;
}
.act-h1 em{
  font-style:italic;
  color:var(--sand-lt);
}
.act-sub{
  font-size:1rem;font-weight:400;
  color:rgba(212,196,172,.88);
  line-height:1.85;
  max-width:580px;
  margin:0 auto 2rem;
}
.act-hero-cta{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;
  background:var(--sand);color:var(--teal-d);
  border:1px solid var(--sand);
  transition:background .22s,border-color .22s;
}
.act-hero-cta:hover{background:var(--sand-lt);border-color:var(--sand-lt);color:var(--teal-d);}

/* ── BREADCRUMB ── */
.breadcrumb{
  padding:.9rem 52px;
  background:var(--white);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;
  list-style:none;
}
.breadcrumb a{font-family:'Jost',sans-serif;font-size:.75rem;letter-spacing:.1em;color:var(--light);transition:color .2s;}
.breadcrumb a:hover{color:var(--teal);}
.breadcrumb-sep{font-size:.75rem;color:rgba(184,150,90,.4);}
.breadcrumb-curr{font-family:'Jost',sans-serif;font-size:.75rem;letter-spacing:.1em;color:var(--sand);}

/* ── CATEGORY SECTION ── */
.cat-section{
  padding:72px 5vw;
  border-bottom:1px solid var(--border);
}
.cat-section:nth-child(even){background:var(--white);}
.cat-section:nth-child(odd){background:var(--off);}
.cat-header{max-width:1200px;margin:0 auto 2.5rem;}
.cat-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);
  display:flex;align-items:center;gap:.65rem;
  margin-bottom:.65rem;
}
.cat-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.cat-title{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.6rem,2.8vw,2.4rem);
  font-weight:400;color:var(--dark);line-height:1.05;
}
.cat-title em{font-style:italic;color:var(--teal);}

/* ── ACTIVITY GRID ── */
.act-grid{
  max-width:1200px;margin:0 auto;
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
  gap:1.5rem;
}
.act-card{
  background:var(--white);
  border:1px solid var(--border);
  padding:1.8rem 1.6rem 1.6rem;
  transition:box-shadow .22s,border-color .22s;
  display:flex;flex-direction:column;
  gap:.6rem;
}
.cat-section:nth-child(even) .act-card{background:var(--off);}
.act-card:hover{box-shadow:0 6px 28px rgba(16,47,58,.09);border-color:rgba(184,150,90,.28);}
.act-icon{font-size:1.6rem;line-height:1;}
.act-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.2rem;font-weight:400;
  color:var(--dark);
}
.act-desc{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.75;flex:1;}
.act-price{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.1em;text-transform:uppercase;
  color:var(--teal);margin-top:.3rem;
}

/* ── INLINE CTA LINK ── */
.act-link{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--sand);
  border-bottom:1px solid rgba(184,150,90,.3);
  padding-bottom:.08rem;
  transition:border-color .2s,color .2s;
  align-self:flex-start;
}
.act-link:hover{color:var(--teal);border-color:var(--teal);}

/* ── BOTTOM CTA SECTION ── */
.act-cta{
  background:var(--teal-d);
  padding:88px 5vw;
  text-align:center;
}
.act-cta-inner{max-width:640px;margin:0 auto;}
.act-cta-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:rgba(184,150,90,.85);
  margin-bottom:1rem;
}
.act-cta-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:300;color:#fff;
  line-height:1.05;margin-bottom:.9rem;
}
.act-cta-h em{font-style:italic;color:var(--sand-lt);}
.act-cta-p{
  font-size:1rem;font-weight:400;
  color:rgba(212,196,172,.88);
  line-height:1.85;margin-bottom:2rem;
}
.act-cta-btns{
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
.btn-sand:hover{background:var(--sand-lt);border-color:var(--sand-lt);}
.btn-outline{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:400;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;
  background:none;
  border:1px solid rgba(255,255,255,.28);
  color:rgba(255,255,255,.8);
  transition:border-color .22s,color .22s;
}
.btn-outline:hover{border-color:rgba(255,255,255,.65);color:#fff;}

/* ── PROPERTIES STRIP ── */
.prop-strip{
  background:var(--white);
  padding:60px 5vw;
  border-top:1px solid var(--border);
  border-bottom:1px solid var(--border);
}
.prop-strip-inner{max-width:1200px;margin:0 auto;}
.prop-strip-label{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--light);
  margin-bottom:1.4rem;
  text-align:center;
}
.prop-strip-grid{
  display:flex;flex-wrap:wrap;gap:1rem;justify-content:center;
}
.prop-pill{
  display:inline-flex;align-items:center;gap:.55rem;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.12em;text-transform:uppercase;
  padding:.65rem 1.3rem;
  border:1px solid var(--border);
  color:var(--teal);
  background:var(--off);
  transition:border-color .2s,background .2s,color .2s;
}
.prop-pill:hover{border-color:var(--teal);background:rgba(30,92,107,.04);color:var(--teal-d);}
.prop-pill-loc{font-weight:400;color:var(--light);font-size:.7rem;letter-spacing:.08em;}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .act-hero{padding:88px 5vw 56px;}
  .cat-section{padding:52px 5vw;}
  .act-grid{grid-template-columns:1fr;}
  .breadcrumb{padding:.9rem 20px;}
  .act-cta{padding:64px 5vw;}
}
@media(max-width:480px){
  .act-hero{padding:84px 5vw 48px;}
  .act-cta-btns{flex-direction:column;align-items:center;}
}
</style>
</head>

<body>

<?php include 'includes/header.php'; ?>

<!-- ═══ COMPACT HERO ═══ -->
<section class="act-hero">
  <div class="act-hero-inner">
    <div class="act-eyebrow">Kenya's North Coast &middot; Watamu &middot; Kilifi &middot; Vipingo</div>
    <h1 class="act-h1">Experiences on <em>Kenya's North Coast</em></h1>
    <p class="act-sub">Every activity is arranged by your concierge team. Tell us what you want to do — we quote within 24 hours, no payment required.</p>
    <a href="trip-builder.html" class="act-hero-cta">Plan Your Trip &rarr;</a>
  </div>
</section>

<!-- ═══ BREADCRUMB ═══ -->
<nav class="breadcrumb" aria-label="Breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">&rsaquo;</span>
  <span class="breadcrumb-curr">Activities &amp; Experiences</span>
</nav>

<!-- ═══ WATER & OCEAN ═══ -->
<section class="cat-section" id="water">
  <div class="cat-header">
    <div class="cat-eyebrow">Ocean Adventures</div>
    <h2 class="cat-title">Water &amp; <em>Ocean</em></h2>
  </div>
  <div class="act-grid">

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Snorkelling — Watamu Marine Park</div>
      <p class="act-desc">Kenya's UNESCO marine park. Vibrant coral reefs, sea turtles and tropical fish in the crystal-clear waters off Watamu beach. One of the best snorkelling experiences on the continent.</p>
      <div class="act-price">From $40 per person</div>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Scuba Diving</div>
      <p class="act-desc">Full equipment, guided dives, all levels welcome. Explore Kenya's coast underwater — from shallow coral gardens to deeper wall dives. Certification dives arranged on request.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Deep Sea Fishing</div>
      <p class="act-desc">The Kenya coast offers world-class sport fishing — marlin, tuna and sailfish year-round. Full or half day charters, experienced local captains, all gear included.</p>
      <div class="act-price">From $550 per boat</div>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Kitesurfing — Kilifi &amp; Watamu</div>
      <p class="act-desc">Tribal Kite School operates in both Kilifi Creek and Watamu. Beginner lessons, advanced coaching and equipment rental. Kenya's kite surfing scene is among the best in East Africa.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Stand-Up Paddleboarding</div>
      <p class="act-desc">Calm lagoon waters make the Kenya coast ideal for SUP. Guided sessions available, equipment provided. A relaxed way to explore the shoreline at your own pace.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Sunset Dhow Cruise — Kilifi Creek</div>
      <p class="act-desc">Drift along Kilifi Creek at sunset on a traditional hand-built wooden dhow. Drinks and light refreshments included. One of the most celebrated things to do on Kenya's coast.</p>
      <div class="act-price">From $45 per person</div>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Dolphin Watching</div>
      <p class="act-desc">Early morning ocean trips to spot spinner and bottlenose dolphins in their natural habitat. A magical, unhurried experience off the Watamu and Kilifi coastline.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

  </div>
</section>

<!-- ═══ SAFARI & BUSH ═══ -->
<section class="cat-section" id="safari">
  <div class="cat-header">
    <div class="cat-eyebrow">Bush &amp; Wildlife</div>
    <h2 class="cat-title">Safari &amp; <em>Bush</em></h2>
  </div>
  <div class="act-grid">

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Tsavo East Safari</div>
      <p class="act-desc">Day trip to Kenya's largest national park — vast red-dust plains, elephant herds, lions and the iconic red elephants. One of the great safari experiences on the African continent.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Tsavo West Safari</div>
      <p class="act-desc">Dramatic volcanic landscape, crystal-clear Mzima Springs and diverse wildlife. A day trip that offers a completely different feel to Tsavo East — lush, green and striking.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Shimba Hills Safari</div>
      <p class="act-desc">Kenya's coastal forest reserve — home to the rare sable antelope, elephants and over 100 bird species. A hidden gem within easy reach of the Kilifi coast.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Amboseli Safari — Overnight</div>
      <p class="act-desc">Iconic views of Kilimanjaro above vast elephant herds. An overnight safari from the coast — one of Africa's most spectacular wildlife destinations, accessible year-round.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Arabuko Sokoke Night Safari</div>
      <p class="act-desc">Kenya's largest intact coastal forest after dark — rare owls, bushbabies and nocturnal insects. An atmospheric and unusual safari experience unlike anything else on the coast.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

  </div>
</section>

<!-- ═══ WELLNESS ═══ -->
<section class="cat-section" id="wellness">
  <div class="cat-header">
    <div class="cat-eyebrow">Rest &amp; Restoration</div>
    <h2 class="cat-title">Wellness &amp; <em>Wellbeing</em></h2>
  </div>
  <div class="act-grid">

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Private Yoga Sessions</div>
      <p class="act-desc">Beachfront or in-villa yoga with experienced instructors. Sunrise or sunset sessions tailored to your level — from restorative flows to dynamic vinyasa practice.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Sunrise Meditation</div>
      <p class="act-desc">Guided beachfront meditation sessions as the sun rises over the Indian Ocean. A deeply restorative way to begin your day on the Kenya coast.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">In-Villa Massage</div>
      <p class="act-desc">Professional therapists deliver Swedish, deep tissue and traditional Swahili massages at your villa or suite. Couples packages and extended sessions available on request.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Couples Spa Treatments</div>
      <p class="act-desc">Coordinated spa journeys for two — synchronised massages, body wraps, scrubs and relaxation packages. Arranged by your concierge for any of our properties.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Sound Baths</div>
      <p class="act-desc">Immersive relaxation through resonant sound — Tibetan bowls, crystal bowls and gongs. A deeply meditative experience for individuals or small groups, arranged on request.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

  </div>
</section>

<!-- ═══ GOLF ═══ -->
<section class="cat-section" id="golf">
  <div class="cat-header">
    <div class="cat-eyebrow">On the Fairway</div>
    <h2 class="cat-title">Golf at <em>Vipingo Ridge</em></h2>
  </div>
  <div class="act-grid" style="grid-template-columns:1fr;">

    <div class="act-card" style="flex-direction:row;align-items:flex-start;gap:1.8rem;max-width:720px;">
      <div style="flex-shrink:0;">
        <div class="act-icon"></div>
      </div>
      <div style="flex:1;">
        <div class="act-name">Vipingo Ridge Golf Club</div>
        <p class="act-desc">An 18-hole PGA-accredited course set in a dramatic baobab landscape just five minutes from <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.25);">My Amani</a>. One of East Africa's finest golf experiences, with Indian Ocean views from several holes. Tee times, buggy hire and caddy arrangements all handled by your concierge.</p>
        <a href="trip-builder.html" class="act-link" style="margin-top:.6rem;display:inline-block;">Arrange tee time &rarr;</a>
      </div>
    </div>

  </div>
</section>

<!-- ═══ CULTURE & NATURE ═══ -->
<section class="cat-section" id="culture">
  <div class="cat-header">
    <div class="cat-eyebrow">Heritage &amp; Nature</div>
    <h2 class="cat-title">Culture &amp; <em>Discovery</em></h2>
  </div>
  <div class="act-grid">

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Gede Ruins</div>
      <p class="act-desc">An ancient Swahili city buried in coastal forest — UNESCO-listed and eerily beautiful. Guided walks through the ruins reveal centuries of East African maritime history and culture.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Sea Turtle Conservation</div>
      <p class="act-desc">The <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.2);">My Amani</a> beach runs an active sea turtle nesting programme. Guests can observe and participate in conservation efforts directly on the beachfront — a genuinely meaningful experience.</p>
      <a href="my-amani.php" class="act-link">Learn about My Amani &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Swahili Cooking Class</div>
      <p class="act-desc">Learn the spices, techniques and traditions behind Swahili cuisine — coconut curries, pilau rice, fresh seafood prepared with local ingredients. Hands-on and fully personalised.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Local Village Visits</div>
      <p class="act-desc">Community-led cultural experiences in the villages surrounding Kilifi and Watamu. Meet local craftspeople, visit community schools and understand life on Kenya's North Coast.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Arabuko Sokoke Forest Tours</div>
      <p class="act-desc">Guided nature walks through Kenya's largest intact coastal forest — home to endemic bird species, rare mammals and ancient trees. Essential for wildlife and nature enthusiasts.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

  </div>
</section>

<!-- ═══ DINING & CELEBRATIONS ═══ -->
<section class="cat-section" id="dining">
  <div class="cat-header">
    <div class="cat-eyebrow">Private Events &amp; Dining</div>
    <h2 class="cat-title">Dining &amp; <em>Celebrations</em></h2>
  </div>
  <div class="act-grid">

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Private Beach Dinners</div>
      <p class="act-desc">A table set on the sand, under the stars, with the Indian Ocean as your backdrop. Chef-arranged menus, candlelit atmosphere, full service — available at all Tribal Sand properties.</p>
      <a href="events.php" class="act-link">See Events &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon">⭐</div>
      <div class="act-name">Rooftop Starlight Suppers</div>
      <p class="act-desc">Elevated dining experiences under a vast African sky. Rooftop terraces at <a href="maya-kobe.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.2);">Maya Kobe</a> and other properties offer dramatic ocean and creek views for unforgettable evenings.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Birthdays &amp; Anniversaries</div>
      <p class="act-desc">Full decoration coordination, custom menus, private catering and event styling — all arranged through your concierge. No detail left to chance for your milestone celebration.</p>
      <a href="events.php" class="act-link">See Events &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Proposal Coordination</div>
      <p class="act-desc">Private sunset proposals arranged with discretion and care — the right location, the right light, the right moment. Our concierge team has helped make many engagements unforgettable.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Swahili Feast</div>
      <p class="act-desc">A traditional multi-course Swahili dining experience — fresh caught seafood, slow-cooked curries, coastal spices and fresh coconut. Arranged for groups at any of our properties.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

  </div>
</section>

<!-- ═══ TRANSFERS & LOGISTICS ═══ -->
<section class="cat-section" id="transfers">
  <div class="cat-header">
    <div class="cat-eyebrow">Getting Around</div>
    <h2 class="cat-title">Transfers &amp; <em>Logistics</em></h2>
  </div>
  <div class="act-grid">

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Airport Transfers</div>
      <p class="act-desc">Smooth arrivals and departures from all relevant airports — Mombasa (MBA), Malindi (MYD), Nairobi Wilson (WIL) and JKIA (NBO). Private vehicles, meet and greet service.</p>
      <a href="trip-builder.html" class="act-link">Arrange transfer &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Driver Hire</div>
      <p class="act-desc">Full day or multi-day dedicated driver hire with knowledge of the Kenya coast. Ideal for exploring local markets, day trips and activities beyond the property.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Inter-Property Transfers</div>
      <p class="act-desc">Travelling between <a href="zuri.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.2);">Zuri</a>, <a href="maya-kobe.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.2);">Maya Kobe</a> and <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.2);">My Amani</a>? We coordinate all transfers between our properties — seamless, private, comfortable.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

    <div class="act-card">
      <div class="act-icon"></div>
      <div class="act-name">Helicopter Charter</div>
      <p class="act-desc">Private scenic helicopter flights along the Kenya coast — an extraordinary way to arrive, depart or explore. Spectacular aerial views of coral reefs, creeks and the Indian Ocean shoreline.</p>
      <a href="trip-builder.html" class="act-link">Enquire &rarr;</a>
    </div>

  </div>
</section>

<!-- ═══ PROPERTY LINKS STRIP ═══ -->
<div class="prop-strip">
  <div class="prop-strip-inner">
    <div class="prop-strip-label">Activities arranged at all Tribal Sand properties</div>
    <div class="prop-strip-grid">
      <a href="zuri.php" class="prop-pill">
        Zuri <span class="prop-pill-loc">Watamu</span>
      </a>
      <a href="maya-kobe.php" class="prop-pill">
        Maya Kobe <span class="prop-pill-loc">Kilifi</span>
      </a>
      <a href="my-amani.php" class="prop-pill">
        My Amani <span class="prop-pill-loc">Vipingo</span>
      </a>
      <a href="maya_ilai.php" class="prop-pill">
        Maya Ilai <span class="prop-pill-loc">Kilifi</span>
      </a>
    </div>
  </div>
</div>

<!-- ═══ BOTTOM CTA ═══ -->
<section class="act-cta">
  <div class="act-cta-inner">
    <div class="act-cta-eyebrow">No Payment Required at Enquiry</div>
    <h2 class="act-cta-h">Every activity is arranged through your <em>concierge team</em></h2>
    <p class="act-cta-p">Tell us what you want to experience on Kenya's North Coast. We'll quote within 24 hours — no obligation, no payment required at the enquiry stage.</p>
    <div class="act-cta-btns">
      <a href="trip-builder.html" class="btn-sand">Plan Your Trip &rarr;</a>
      <a href="contact.php" class="btn-outline">Contact Us</a>
    </div>
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
