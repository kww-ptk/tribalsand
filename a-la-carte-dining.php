<?php
/**
 * À la carte dining — the collection's dining hub.
 *
 * This URL ranks, so it keeps its address (/a-la-carte-dining) and its
 * "à la carte dining Watamu / Kilifi" framing. What it no longer does is
 * describe dining in the abstract: the two restaurants that are actually
 * open to the public — Zuri (Garoda Beach, Watamu) and Tribal Table (Bofa
 * Beach, Kilifi) — are the spine of the page, and every claim on it is
 * sourced from those two pages rather than written for atmosphere.
 *
 * Photos: the Zuri tiles read the SAME admin-editable slots as
 * zuri-restaurant.php (page_image('zuri-restaurant','gal_N')), so the owner
 * swaps a restaurant photo once and both pages follow. Tribal Table reuses
 * the Maya Kobe / Tribal Dunes imagery the nav and tribal-table.php already
 * use for it. Nothing here points at an invented file.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/page-content.php';

/* ── Restaurant photography ──────────────────────────────────────────────
   Zuri: the live, admin-managed restaurant gallery. Tribal Table: the same
   Tribal Dunes photos the nav thumbnail and tribal-table.php already serve. */
$zuriShots = [
    [page_image('zuri-restaurant', 'gal_1'), 'The beachfront terrace at Zuri, Garoda Beach, Watamu'],
    [page_image('zuri-restaurant', 'gal_2'), 'Poolside lunch setting at Zuri'],
    [page_image('zuri-restaurant', 'gal_3'), 'Zuri seen from the air on Garoda Beach'],
    [page_image('zuri-restaurant', 'gal_4'), 'Garden dining tables at Zuri'],
    [page_image('zuri-restaurant', 'gal_5'), 'The Indian Ocean shoreline in front of Zuri'],
    [page_image('zuri-restaurant', 'gal_6'), 'Evening service at Zuri restaurant'],
];
$zuriShots = array_values(array_filter($zuriShots, fn($s) => $s[0] !== ''));

// This folder has spaces in its name. Browsers encode them, but a raw space in
// an href is still an invalid URL — encode each segment, leaving the slashes.
$__enc  = fn(string $p) => implode('/', array_map('rawurlencode', explode('/', $p)));
$ttHero = asset_url($__enc('images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg'));
$ttCard = asset_url($__enc('images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg'));
$heroImg = asset_url('images/hero-zuri.jpg');

/* ── FAQ — rendered on the page AND emitted as FAQPage schema ───────────── */
$faqs = [
    ['Can I eat at Tribal Sand without staying here?',
     'Yes. Both Zuri Restaurant in Watamu and Tribal Table in Kilifi are open to the public. Zuri seats a small number of covers, so it takes outside guests by reservation only; Tribal Table handles its own bookings on tribaltablekenya.com.'],
    ['What does à la carte dining mean at Tribal Sand?',
     'You order from a menu, dish by dish, at a time that suits you — there is no buffet and no fixed sitting. Menus follow the day\'s catch and what the coast is growing, so they change rather than repeat.'],
    ['How do I book a table at Zuri?',
     'Send a request through the Zuri Restaurant page and the team confirms within 24 hours. No payment is taken when you request a table. You can also call +254 115 115 247.'],
    ['Where exactly are the two restaurants?',
     'Zuri is on Garoda Beach in Watamu. Tribal Table is on Bofa Beach in Kilifi, inside the Tribal Dunes development — roughly an hour apart on Kenya\'s North Coast.'],
    ['Is there dining at the private villas?',
     'The villas are self-catering, with fully equipped kitchens. At My Amani a private chef can be arranged on request, at additional cost.'],
];

/* ═══ SEO ═══ */
$page_title = 'À La Carte Dining in Watamu & Kilifi · Zuri & Tribal Table · Tribal Sand';
$page_desc  = 'À la carte dining on the Kenya coast at two beachfront restaurants open to the public: Zuri on Garoda Beach, Watamu, and Tribal Table on Bofa Beach, Kilifi. Menus, locations and how to book a table.';
$page_url   = 'https://tribalsand.com/a-la-carte-dining.php';
$page_image = $heroImg;
$page_preload = $heroImg;

$__faqSchema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
foreach ($faqs as [$q, $a]) {
    $__faqSchema['mainEntity'][] = [
        '@type' => 'Question', 'name' => $q,
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
    ];
}

$page_schema  = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',
    'name'     => 'À la carte restaurants on the Kenya coast · Tribal Sand',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'item' => [
            '@type' => 'Restaurant', 'name' => 'Zuri Restaurant',
            'servesCuisine' => ['Coastal', 'Swahili', 'Seafood'],
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Garoda Beach',
                          'addressLocality' => 'Watamu', 'addressRegion' => 'Kilifi County', 'addressCountry' => 'KE'],
            'telephone' => '+254115115247', 'acceptsReservations' => 'True',
            'url' => 'https://tribalsand.com/zuri-restaurant', 'image' => $heroImg,
        ]],
        ['@type' => 'ListItem', 'position' => 2, 'item' => [
            '@type' => 'Restaurant', 'name' => 'Tribal Table',
            'servesCuisine' => ['Coastal', 'Seafood', 'Grill'],
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Bofa Beach',
                          'addressLocality' => 'Kilifi', 'addressRegion' => 'Kilifi County', 'addressCountry' => 'KE'],
            'acceptsReservations' => 'True',
            'url' => 'https://tribalsand.com/tribal-table', 'image' => $ttHero,
        ]],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
$page_schema .= '<script type="application/ld+json">'
              . json_encode($__faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
?>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;
  --teal:#1E5C6B;--teal-d:#102F3A;
  --dark:#141412;--off:#FAF8F4;--mid:#6B6050;--border:rgba(184,150,90,.16);
}
.alc *{box-sizing:border-box;}
.alc img{display:block;object-fit:cover;}
.alc a{text-decoration:none;color:inherit;}

/* ── HERO ── */
.alc-hero{position:relative;min-height:72vh;display:flex;align-items:flex-end;padding:0 6vw 3.5rem;overflow:hidden;}
.alc-hero-bg{position:absolute;inset:0;}
.alc-hero-bg img{width:100%;height:100%;}
.alc-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,rgba(16,47,58,.34) 0%,rgba(16,47,58,.62) 45%,rgba(16,47,58,.84) 72%,rgba(16,47,58,.95) 100%);}
.alc-hero-in{position:relative;z-index:2;max-width:820px;}
.alc-eyebrow{font-size:.58rem;letter-spacing:.3em;text-transform:uppercase;color:rgba(232,220,200,.75);margin-bottom:.9rem;}
.alc-hero h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.5rem,6vw,4.4rem);font-weight:300;color:#fff;line-height:1.02;margin:0;}
.alc-hero h1 em{font-style:italic;color:var(--sand-lt);}
.alc-hero-sub{margin-top:1.1rem;font-size:1rem;color:rgba(212,196,172,.94);line-height:1.75;font-weight:300;max-width:600px;}
.alc-cta{display:flex;flex-wrap:wrap;gap:.8rem;margin-top:2rem;}
.alc-btn{display:inline-flex;align-items:center;gap:.55rem;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;font-weight:500;padding:1rem 1.8rem;transition:all .2s;border:1px solid transparent;}
.alc-btn--gold{background:var(--sand);color:var(--teal-d);}
.alc-btn--gold:hover{background:var(--sand-lt);}
.alc-btn--ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.5);}
.alc-btn--ghost:hover{border-color:#fff;background:rgba(255,255,255,.1);}
.alc-btn--ink{background:transparent;color:var(--teal-d);border-color:rgba(184,150,90,.55);}
.alc-btn--ink:hover{background:var(--sand);color:var(--teal-d);border-color:var(--sand);}

/* ── SHARED SECTION FURNITURE ── */
.alc-sec{padding:4.5rem 5vw;}
.alc-sec--pale{background:var(--sand-pale);}
.alc-in{max-width:1120px;margin:0 auto;}
.alc-in--narrow{max-width:760px;}
.alc-lbl{font-size:.56rem;letter-spacing:.3em;text-transform:uppercase;color:var(--teal);font-weight:500;margin-bottom:1rem;}
.alc-h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.8rem,3.4vw,2.6rem);font-weight:400;line-height:1.2;margin:0 0 1.2rem;color:var(--dark);}
.alc-h2 em{font-style:italic;color:var(--sand);}
.alc-p{font-size:.95rem;color:var(--mid);line-height:1.9;font-weight:300;margin:0 0 1rem;}
.alc-center{text-align:center;}
.alc-center .alc-p{margin-left:auto;margin-right:auto;max-width:640px;}

/* ── RESTAURANT CARDS ── */
.alc-rest{display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-top:2.5rem;}
.alc-card{background:#fff;border:1px solid var(--border);display:flex;flex-direction:column;}
.alc-card-img{position:relative;aspect-ratio:16/10;overflow:hidden;background:var(--sand-pale);}
.alc-card-img img{width:100%;height:100%;transition:transform .6s ease;}
.alc-card:hover .alc-card-img img{transform:scale(1.05);}
.alc-tag{position:absolute;top:1rem;left:1rem;font-size:.52rem;letter-spacing:.22em;text-transform:uppercase;padding:.4rem .9rem;background:var(--sand);color:var(--teal-d);font-weight:600;}
.alc-card-body{padding:2rem 2rem 2.2rem;display:flex;flex-direction:column;flex:1;}
.alc-card-loc{font-size:.56rem;letter-spacing:.24em;text-transform:uppercase;color:var(--sand);margin-bottom:.7rem;}
.alc-card h3{font-family:'Cormorant Garamond',serif;font-size:1.9rem;font-weight:400;margin:0 0 .9rem;color:var(--dark);}
.alc-card .alc-p{font-size:.9rem;margin-bottom:1.2rem;}
.alc-facts{display:flex;flex-wrap:wrap;gap:.4rem;margin:0 0 1.6rem;padding:0;list-style:none;}
.alc-facts li{font-size:.58rem;letter-spacing:.11em;text-transform:uppercase;padding:.42rem .9rem;border:1px solid var(--border);color:var(--mid);}
.alc-links{margin-top:auto;display:flex;flex-wrap:wrap;gap:.6rem;}
.alc-link{font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;padding:.85rem 1.4rem;border:1px solid rgba(184,150,90,.5);color:var(--teal-d);transition:all .2s;}
.alc-link:hover{background:var(--sand);border-color:var(--sand);}
.alc-link--solid{background:var(--teal-d);border-color:var(--teal-d);color:#fff;}
.alc-link--solid:hover{background:var(--teal);border-color:var(--teal);color:#fff;}

/* ── PHOTO STRIP ── */
.alc-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:.7rem;margin-top:2.2rem;}
.alc-strip figure{margin:0;position:relative;aspect-ratio:4/3;overflow:hidden;background:var(--sand-pale);}
.alc-strip img{width:100%;height:100%;transition:transform .6s ease;}
.alc-strip figure:hover img{transform:scale(1.06);}

/* ── SPLIT ── */
.alc-split{display:grid;grid-template-columns:1fr 1fr;gap:3.5rem;align-items:center;}
.alc-split-img{aspect-ratio:4/3;overflow:hidden;}
.alc-split-img img{width:100%;height:100%;}
.alc-ul{margin:1.2rem 0 0;padding:0;list-style:none;}
.alc-ul li{position:relative;padding-left:1.1rem;margin-bottom:.7rem;font-size:.92rem;color:var(--mid);line-height:1.75;font-weight:300;}
.alc-ul li::before{content:'';position:absolute;left:0;top:.62em;width:4px;height:4px;background:var(--sand);}

/* ── FAQ ── */
.alc-faq{border-top:1px solid var(--border);}
.alc-faq details{border-bottom:1px solid var(--border);}
.alc-faq summary{cursor:pointer;list-style:none;padding:1.3rem 2.2rem 1.3rem 0;position:relative;font-size:1rem;color:var(--dark);font-weight:400;}
.alc-faq summary::-webkit-details-marker{display:none;}
.alc-faq summary::after{content:'+';position:absolute;right:.4rem;top:50%;transform:translateY(-50%);color:var(--sand);font-size:1.2rem;line-height:1;}
.alc-faq details[open] summary::after{content:'–';}
.alc-faq p{margin:0 0 1.4rem;font-size:.92rem;color:var(--mid);line-height:1.85;font-weight:300;max-width:640px;}

/* ── CLOSING ── */
.alc-end{background:var(--teal-d);padding:5rem 5vw;text-align:center;}
.alc-end .alc-lbl{color:var(--sand-lt);}
.alc-end h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.9rem,3.6vw,2.8rem);font-weight:300;color:#fff;margin:0 0 1rem;}
.alc-end h2 em{font-style:italic;color:var(--sand-lt);}
.alc-end p{font-size:.92rem;color:rgba(212,196,172,.85);line-height:1.85;font-weight:300;max-width:560px;margin:0 auto 2rem;}

@media(max-width:900px){
  .alc-rest,.alc-split{grid-template-columns:1fr;gap:2rem;}
  .alc-strip{grid-template-columns:repeat(2,1fr);}
  .alc-hero{min-height:64vh;}
}
@media(max-width:520px){
  .alc-strip{grid-template-columns:1fr 1fr;}
  .alc-card-body{padding:1.5rem 1.4rem 1.8rem;}
  .alc-links{flex-direction:column;}
  .alc-link{text-align:center;}
}
</style>
<body class="ts-nav-transparent">
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="alc">

  <!-- ── HERO ── -->
  <section class="alc-hero">
    <div class="alc-hero-bg">
      <img src="<?= e($heroImg) ?>" alt="Beachfront dining at Zuri on Garoda Beach, Watamu" width="1920" height="1080" loading="eager">
    </div>
    <div class="alc-hero-in">
      <p class="alc-eyebrow">Watamu &amp; Kilifi · Kenya Coast</p>
      <h1>À La Carte Dining<br>on the <em>Kenya Coast</em></h1>
      <p class="alc-hero-sub">
        Two beachfront restaurants, both open to the public — Zuri on Garoda Beach in Watamu,
        and Tribal Table on Bofa Beach in Kilifi. Order dish by dish, at your own hour.
      </p>
      <div class="alc-cta">
        <a class="alc-btn alc-btn--gold" href="zuri-restaurant.php">Zuri Restaurant, Watamu</a>
        <a class="alc-btn alc-btn--ghost" href="tribal-table.php">Tribal Table, Kilifi</a>
      </div>
    </div>
  </section>

  <!-- ── WHAT IT MEANS ── -->
  <section class="alc-sec">
    <div class="alc-in alc-in--narrow alc-center">
      <p class="alc-lbl">The Difference</p>
      <h2 class="alc-h2">No buffet. No sitting. <em>No hurry.</em></h2>
      <p class="alc-p">
        À la carte means you order dish by dish, when you are ready — not from a warming tray at
        an hour someone else picked. Menus follow the morning's catch and what the coast is
        growing, so what is on the card in April is not what is on it in October.
      </p>
      <p class="alc-p">
        Both of our restaurants sit on the sand. Neither is large. That is the whole idea.
      </p>
    </div>
  </section>

  <!-- ── THE TWO RESTAURANTS ── -->
  <section class="alc-sec alc-sec--pale" id="restaurants">
    <div class="alc-in">
      <div class="alc-center">
        <p class="alc-lbl">Open to the Public</p>
        <h2 class="alc-h2">Where to <em>Eat With Us</em></h2>
        <p class="alc-p">
          You do not need to be staying with us to book either table.
        </p>
      </div>

      <div class="alc-rest">

        <!-- Zuri -->
        <article class="alc-card">
          <div class="alc-card-img">
            <img src="<?= e($zuriShots[0][0] ?? $heroImg) ?>" alt="<?= e($zuriShots[0][1] ?? 'Zuri Restaurant, Watamu') ?>" loading="lazy">
            <span class="alc-tag">Now Open</span>
          </div>
          <div class="alc-card-body">
            <p class="alc-card-loc">Garoda Beach · Watamu</p>
            <h3>Zuri Restaurant</h3>
            <p class="alc-p">
              Our beachfront kitchen in Watamu, open to outside guests as well as to the house.
              A relaxed lunch by the pool, or dinner a few steps from the sand. Seating is
              intimate, so Zuri takes guests by reservation only — send a request and the team
              confirms within 24 hours. No payment is taken at that stage.
            </p>
            <ul class="alc-facts">
              <li>À la carte</li>
              <li>Beachfront terrace</li>
              <li>Fresh seafood</li>
              <li>Lunch &amp; dinner</li>
              <li>Reservation only</li>
            </ul>
            <div class="alc-links">
              <a class="alc-link alc-link--solid" href="reserve.php?venue=zuri">Book a table →</a>
              <a class="alc-link" href="menu.php?m=zuri">View the menu</a>
              <a class="alc-link" href="zuri-restaurant.php">About Zuri</a>
            </div>
          </div>
        </article>

        <!-- Tribal Table -->
        <article class="alc-card">
          <div class="alc-card-img">
            <img src="<?= e($ttCard) ?>" alt="Tribal Table, the beachfront restaurant and bar at Tribal Dunes, Bofa Beach, Kilifi" loading="lazy">
            <span class="alc-tag">Now Open</span>
          </div>
          <div class="alc-card-body">
            <p class="alc-card-loc">Bofa Beach · Kilifi</p>
            <h3>Tribal Table</h3>
            <p class="alc-p">
              An elevated beachside restaurant and cocktail bar at Tribal Dunes, an hour up the
              coast in Kilifi. Coastal cooking, seafood and grill, and a terrace built around
              the sunset. Open to the public, and available for private events. Menus, hours
              and bookings live on the restaurant's own site.
            </p>
            <ul class="alc-facts">
              <li>Coastal fine dining</li>
              <li>Craft cocktails</li>
              <li>Seafood &amp; grill</li>
              <li>Sunset terrace</li>
              <li>Private events</li>
            </ul>
            <div class="alc-links">
              <a class="alc-link alc-link--solid" href="https://www.tribaltablekenya.com" target="_blank" rel="noopener">Book at Tribal Table →</a>
              <a class="alc-link" href="tribal-table.php">About Tribal Table</a>
              <a class="alc-link" href="tribal-dunes.php">Tribal Dunes</a>
            </div>
          </div>
        </article>

      </div>
    </div>
  </section>

  <!-- ── ZURI PHOTOGRAPHY ── -->
  <?php if (count($zuriShots) >= 3): ?>
  <section class="alc-sec">
    <div class="alc-in">
      <div class="alc-center">
        <p class="alc-lbl">A Taste of the Setting</p>
        <h2 class="alc-h2">The <em>Zuri</em> Table</h2>
      </div>
      <div class="alc-strip">
        <?php foreach (array_slice($zuriShots, 0, 6) as [$src, $alt]): ?>
        <figure><img src="<?= e($src) ?>" alt="<?= e($alt) ?>" loading="lazy"></figure>
        <?php endforeach; ?>
      </div>
      <div class="alc-center" style="margin-top:2rem">
        <a class="alc-btn alc-btn--ink" href="zuri-restaurant.php">See the full Zuri page →</a>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── ON THE PLATE ── -->
  <section class="alc-sec alc-sec--pale">
    <div class="alc-in alc-split">
      <div class="alc-split-img">
        <img src="<?= e($zuriShots[2][0] ?? $ttHero) ?>" alt="<?= e($zuriShots[2][1] ?? 'The Kenya coast at Tribal Sand') ?>" loading="lazy">
      </div>
      <div>
        <p class="alc-lbl">On the Plate</p>
        <h2 class="alc-h2">Coastal cooking, <em>Swahili roots</em></h2>
        <p class="alc-p">
          This is a coastline that traded in spice for a thousand years, and the kitchens cook
          like it — coconut, tamarind, cardamom, chilli, and whatever came off the boats that
          morning. Seafood leads, but neither restaurant is only a fish restaurant.
        </p>
        <ul class="alc-ul">
          <li>The day's catch from Watamu and Kilifi landings</li>
          <li>Grill and open-fire cooking at Tribal Table</li>
          <li>Vegetarian and dietary requests handled — tell us when you book</li>
          <li>Menus change with the season and the catch</li>
        </ul>
        <div class="alc-links" style="margin-top:1.8rem">
          <a class="alc-link" href="menu.php?m=zuri">Zuri menu</a>
          <a class="alc-link" href="menu.php?m=maya-kobe-breakfast">Maya Kobe breakfast</a>
        </div>
      </div>
    </div>
  </section>

  <!-- ── IF YOU'RE STAYING ── -->
  <section class="alc-sec">
    <div class="alc-in alc-split">
      <div>
        <p class="alc-lbl">If You're Staying With Us</p>
        <h2 class="alc-h2">Dining where <em>you sleep</em></h2>
        <p class="alc-p">
          Guests at the boutique hotels eat à la carte at the property — breakfast at your own
          hour, lunch and dinner without leaving the sand. The private villas work differently:
          they are self-catering, with full kitchens.
        </p>
        <ul class="alc-ul">
          <li><a href="zuri.php" style="border-bottom:1px solid rgba(184,150,90,.5)">Zuri, Watamu</a> — à la carte on the beachfront, the same kitchen that serves the restaurant</li>
          <li><a href="maya-kobe.php" style="border-bottom:1px solid rgba(184,150,90,.5)">Maya Kobe, Kilifi</a> — breakfast menu on site, with Tribal Table on Bofa Beach nearby</li>
          <li><a href="my-amani.php" style="border-bottom:1px solid rgba(184,150,90,.5)">My Amani, Vipingo</a> — self-catering, with a private chef available on request at additional cost</li>
          <li><a href="enkare-bofa.php" style="border-bottom:1px solid rgba(184,150,90,.5)">Enkare Bofa</a> and <a href="sandbox.php" style="border-bottom:1px solid rgba(184,150,90,.5)">Sandbox</a>, Kilifi — self-catering villas, minutes from Tribal Table</li>
        </ul>
      </div>
      <div class="alc-split-img">
        <img src="<?= e($ttHero) ?>" alt="Beachfront setting at Tribal Dunes, Bofa Beach, Kilifi" loading="lazy">
      </div>
    </div>
  </section>

  <!-- ── FAQ ── -->
  <section class="alc-sec alc-sec--pale">
    <div class="alc-in alc-in--narrow">
      <div class="alc-center">
        <p class="alc-lbl">Good to Know</p>
        <h2 class="alc-h2">Dining <em>questions</em></h2>
      </div>
      <div class="alc-faq">
        <?php foreach ($faqs as $i => [$q, $a]): ?>
        <details<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= e($q) ?></summary>
          <p><?= e($a) ?></p>
        </details>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── CLOSING ── -->
  <section class="alc-end">
    <p class="alc-lbl">Reserve</p>
    <h2>Come and <em>sit down</em></h2>
    <p>
      Zuri takes table requests online and confirms within 24 hours. Tribal Table books through
      its own site. Either way, no payment is taken to hold a table.
    </p>
    <div class="alc-cta" style="justify-content:center">
      <a class="alc-btn alc-btn--gold" href="reserve.php?venue=zuri">Book a table at Zuri</a>
      <a class="alc-btn alc-btn--ghost" href="https://www.tribaltablekenya.com" target="_blank" rel="noopener">Book at Tribal Table</a>
    </div>
    <p style="margin-top:1.8rem;font-size:.72rem;letter-spacing:.1em;color:rgba(212,196,172,.7)">
      Or call us on <a href="tel:+254115115247" style="color:var(--sand-lt)">+254 115 115 247</a>
    </p>
  </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
