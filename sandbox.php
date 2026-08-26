<?php require_once 'includes/schema.php'; ?>
<?php
$page_title   = 'Sandbox · Beachfront Self-Catering Villa · Kilifi Kenya · Tribal Sand';
$page_desc    = 'Sandbox is a self-catering beachfront villa on Kilifi\'s Bofa Road. Four bedrooms, sleeps 8, pool, direct beach access. Private and flexible.';
$page_url     = 'https://tribalsand.com/sandbox.php';
$page_image   = asset_url('images/hero-sandbox.jpg');
$page_preload = 'images/hero-sandbox.jpg';

$faqs = [
    ['q' => 'Is Sandbox self-catering?',                            'a' => 'Yes. Sandbox is a fully self-catering villa. The property has a well-equipped kitchen. If you would prefer a cook, we can recommend options through our concierge team at additional cost.'],
    ['q' => 'How many guests can Sandbox accommodate?',             'a' => 'Sandbox sleeps up to 8 guests across 4 bedrooms with 3 bathrooms. The spacious outdoor areas make it ideal for groups who want privacy and room to relax.'],
    ['q' => 'Where is Sandbox located?',                            'a' => 'Sandbox is on Bofa Road, Kilifi — the same strip as Maya Kobe and Enkare Bofa. Direct beach access and a relaxed coastal setting.'],
    ['q' => 'What is the difference between Sandbox and Enkare Bofa?', 'a' => 'Enkare Bofa includes an in-house cook and sleeps up to 10 guests across 5 bedrooms. Sandbox is self-catering, sleeps up to 8 and is slightly more affordable — ideal for groups who prefer independence.'],
    ['q' => 'What activities are available near Sandbox?',           'a' => 'Kilifi offers kitesurfing (Tribal Kite School is nearby), dhow cruises on Kilifi Creek, deep sea fishing, and day safari trips. Our concierge arranges everything with a 24-hour quote.'],
];

$page_schema =
    ts_schema_org() .
    ts_schema_lodging([
        'name'            => 'Sandbox Villa',
        'description'     => 'Self-catering beachfront villa on Kilifi\'s Bofa Road. Four bedrooms, sleeps 8, pool and direct beach access.',
        'url'             => 'https://tribalsand.com/sandbox.php',
        'image'           => [asset_url('images/hero-sandbox.jpg')],
        'addressLocality' => 'Kilifi',
        'addressRegion'   => 'Kilifi County',
        'lat'             => -3.6340,
        'lng'             => 39.8503,
        'numberOfRooms'   => 4,
        'priceRange'      => '$$$',
        'amenities'       => ['Pool','Beachfront','Full Kitchen','Self-Catering','Free WiFi','Air Conditioning'],
    ]) .
    ts_schema_breadcrumb([
        ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
        ['name' => 'Properties',     'url' => 'https://tribalsand.com/#properties'],
        ['name' => 'Private Villas', 'url' => 'https://tribalsand.com/#properties'],
        ['name' => 'Sandbox',        'url' => ''],
    ]) .
    ts_schema_faq($faqs);

$page_rooms_rates = true;
$rr_venue_slug = 'sandbox';
?>
<?php include 'includes/head.php'; ?>
</head>
<body class="ts-nav-transparent">

<?php include 'includes/header.php'; ?>

<style>
/* ── PAGE TOKENS ── */
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;--sand-faint:#FAF6EE;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.14);
}

/* ── GALLERY HERO ── */
.gallery{display:grid;grid-template-columns:1.65fr 1fr;grid-template-rows:1fr 1fr;height:92vh;max-height:640px;gap:3px;position:relative;}
.gallery-main{grid-row:span 2;position:relative;overflow:hidden;cursor:pointer;}
.gallery-main img{width:100%;height:100%;object-fit:cover;transition:transform .6s ease;}
.gallery-main:hover img{transform:scale(1.02);}
.gallery-thumb{position:relative;overflow:hidden;cursor:pointer;}
.gallery-thumb img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease,opacity .3s;}
.gallery-thumb:hover img{transform:scale(1.04);opacity:.9;}
.gallery-thumb.last::after{
  content:'See all photos';position:absolute;inset:0;
  background:rgba(16,47,58,.5);backdrop-filter:blur(2px);
  display:flex;align-items:center;justify-content:center;
  font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.22em;
  text-transform:uppercase;color:#fff;
}
.gallery-badge{
  position:absolute;bottom:1.2rem;left:1.2rem;z-index:2;
  background:rgba(16,47,58,.82);backdrop-filter:blur(12px);
  border:1px solid rgba(184,150,90,.2);
  padding:.38rem .85rem;
  font-size:.52rem;letter-spacing:.2em;text-transform:uppercase;color:rgba(184,150,90,.8);
}

/* ── BREADCRUMB ── */
.breadcrumb{padding:.9rem 52px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
.breadcrumb a{font-size:.6rem;letter-spacing:.1em;color:var(--light);transition:color .2s;}
.breadcrumb a:hover{color:var(--teal);}
.breadcrumb-sep{font-size:.55rem;color:rgba(184,150,90,.3);}
.breadcrumb-curr{font-size:.6rem;letter-spacing:.1em;color:var(--sand);}

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:1280px;margin:0 auto;padding:0 52px;display:grid;grid-template-columns:1fr 380px;gap:4rem;padding-top:2.8rem;padding-bottom:6rem;}
.page-main{min-width:0;}
.page-side{position:sticky;top:80px;height:fit-content;}

/* ── LISTING HEADER ── */
.listing-eyebrow{font-size:.54rem;letter-spacing:.32em;text-transform:uppercase;color:var(--sand);margin-bottom:.5rem;display:flex;align-items:center;gap:.55rem;}
.listing-eyebrow::before{content:'';width:16px;height:1px;background:var(--sand);}
.listing-h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.4rem,4vw,3.6rem);font-weight:300;line-height:1;color:var(--dark);margin-bottom:.5rem;}
.listing-h1 em{font-style:italic;color:var(--teal);}
.listing-sub{font-size:.72rem;color:var(--light);display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;}
.listing-sub svg{color:var(--sand);flex-shrink:0;}

/* ── QUICK STATS ── */
.quick-stats{display:flex;gap:0;border:1px solid var(--border);background:var(--white);margin-bottom:2rem;overflow:hidden;}
.qs{flex:1;padding:1rem .8rem;text-align:center;border-right:1px solid var(--border);}
.qs:last-child{border-right:none;}
.qs-n{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;color:var(--dark);line-height:1;}
.qs-l{font-size:.48rem;letter-spacing:.18em;text-transform:uppercase;color:var(--light);margin-top:.18rem;}

/* ── PILLS ── */
.pill-row{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:2rem;}
.pill{font-size:.54rem;letter-spacing:.1em;text-transform:uppercase;padding:.24rem .65rem;border:1px solid var(--border);color:var(--mid);}
.pill.hi{border-color:rgba(30,92,107,.3);color:var(--teal);background:rgba(30,92,107,.04);}

/* ── SECTIONS ── */
.sec{margin-bottom:2.8rem;}
.sec-label{font-size:.55rem;letter-spacing:.28em;text-transform:uppercase;color:var(--sand);margin-bottom:.55rem;display:flex;align-items:center;gap:.5rem;}
.sec-label::before{content:'';width:14px;height:1px;background:var(--sand);}
.sec-h{font-family:'Cormorant Garamond',serif;font-size:clamp(1.5rem,2.5vw,2rem);font-weight:300;color:var(--dark);line-height:1.15;margin-bottom:.45rem;}
.sec-h em{font-style:italic;color:var(--teal);}
.sec-rule{width:20px;height:1px;background:var(--sand);margin-bottom:1.2rem;}
.sec-p{font-size:.82rem;line-height:1.92;color:var(--mid);margin-bottom:1rem;}
.divider{height:1px;background:var(--border);margin:2.4rem 0;}

/* ── AMENITIES ── */
.amenities-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--border);overflow:hidden;}
.amenity{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);border-right:1px solid var(--border);font-size:.75rem;color:var(--mid);transition:background .18s;}
.amenity:hover{background:var(--sand-faint);}
.amenity:nth-child(even){border-right:none;}
.amenity:nth-last-child(-n+2){border-bottom:none;}
.amenity-ico{font-size:1rem;flex-shrink:0;width:1.2rem;text-align:center;}

/* ── PHOTO GRID ── */
.photo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;margin-bottom:.5rem;}
.photo-grid img{width:100%;aspect-ratio:4/3;object-fit:cover;cursor:pointer;transition:opacity .25s,transform .4s;}
.photo-grid img:hover{opacity:.88;transform:scale(1.02);}
.photo-grid-cap{font-size:.55rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);text-align:right;margin-bottom:2rem;}

/* ── EXPERIENCES ── */
.exp-list{display:flex;flex-direction:column;gap:1px;background:var(--border);border:1px solid var(--border);}
.exp-row{background:var(--white);display:flex;align-items:center;gap:1rem;padding:.85rem 1.1rem;transition:background .18s;}
.exp-row:hover{background:var(--sand-faint);}
.exp-ico{font-size:1.1rem;flex-shrink:0;}
.exp-name{font-size:.78rem;color:var(--dark);flex:1;font-weight:400;}
.exp-price{font-size:.64rem;color:var(--light);white-space:nowrap;}
.exp-cta{margin-top:1.2rem;}

/* ── COMPARE CALLOUT ── */
.compare-block{background:var(--sand-faint);border:1px solid var(--border);padding:1.4rem 1.6rem;display:flex;align-items:flex-start;gap:1.4rem;margin-bottom:2rem;}
.compare-icon{font-size:1.6rem;flex-shrink:0;line-height:1;}
.compare-body{}
.compare-h{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--dark);margin-bottom:.3rem;}
.compare-p{font-size:.75rem;color:var(--mid);line-height:1.75;margin-bottom:.5rem;}
.compare-link{font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;color:var(--teal);text-decoration:none;transition:color .2s;}
.compare-link:hover{color:var(--teal-d);}

/* ── FAQ ── */
.faq-item{border-bottom:1px solid var(--border);}
.faq-q{display:flex;justify-content:space-between;align-items:center;padding:1rem 0;font-size:.8rem;font-weight:400;color:var(--dark);cursor:pointer;user-select:none;gap:1rem;}
.faq-a{display:none;padding:0 0 1rem;font-size:.76rem;color:var(--mid);line-height:1.88;}
.faq-item.open .faq-a{display:block;}

/* ── MAP ── */
.map-block{background:var(--teal-d);padding:2rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;margin-bottom:2rem;}
.map-eyebrow{font-size:.5rem;letter-spacing:.28em;text-transform:uppercase;color:rgba(184,150,90,.5);margin-bottom:.4rem;}
.map-h{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;color:#fff;margin-bottom:.3rem;}
.map-p{font-size:.7rem;color:rgba(255,255,255,.4);line-height:1.7;}
.btn-map{font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;padding:.65rem 1.4rem;border:1px solid rgba(184,150,90,.3);color:var(--sand-lt);background:none;cursor:pointer;transition:all .22s;white-space:nowrap;text-decoration:none;display:inline-block;}
.btn-map:hover{background:var(--sand);border-color:var(--sand);color:var(--teal-d);}

/* ── OTHER PROPERTIES ── */
.other-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem;}
.other-card{background:var(--white);border:1px solid var(--border);overflow:hidden;transition:box-shadow .25s,transform .25s;text-decoration:none;display:block;}
.other-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.08);transform:translateY(-2px);}
.other-img{width:100%;height:160px;object-fit:cover;display:block;}
.other-body{padding:.9rem 1rem;}
.other-loc{font-size:.5rem;letter-spacing:.22em;text-transform:uppercase;color:var(--sand);margin-bottom:.25rem;}
.other-name{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--dark);margin-bottom:.28rem;}
.other-meta{font-size:.62rem;color:var(--light);}
.other-foot{padding:.7rem 1rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.other-type{font-size:.5rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);}
.other-link{font-size:.54rem;letter-spacing:.12em;text-transform:uppercase;color:var(--teal);}

/* ── SIDEBAR ── */
.book-card{background:var(--white);border:1px solid var(--border);overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.09);max-height:calc(100vh - 100px);overflow-y:auto;}
.book-head{position:relative;overflow:hidden;background:var(--teal-d);height:140px;}
.book-head-bg{position:absolute;inset:0;background:url('images/hero-sandbox.jpg') center/cover;opacity:.25;}
.book-head-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(16,47,58,.9) 0%,rgba(16,47,58,.3) 100%);}
.book-head-inner{position:relative;z-index:1;padding:1.2rem 1.4rem;height:100%;display:flex;flex-direction:column;justify-content:flex-end;}
.book-eyebrow{font-size:.48rem;letter-spacing:.26em;text-transform:uppercase;color:rgba(184,150,90,.6);margin-bottom:.3rem;}
.book-name{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:300;color:#fff;line-height:1;margin-bottom:.2rem;}
.book-loc{font-size:.58rem;color:rgba(255,255,255,.45);letter-spacing:.06em;}
.book-body{padding:1.3rem 1.4rem 1rem;}
.book-field{margin-bottom:.75rem;}
.book-lbl{font-size:.5rem;letter-spacing:.18em;text-transform:uppercase;color:var(--mid);margin-bottom:.3rem;display:block;}
.book-inp{width:100%;padding:.65rem .8rem;border:1px solid var(--border);background:var(--off);font-size:.78rem;color:var(--dark);font-family:'Jost',sans-serif;transition:border-color .2s;}
.book-inp:focus{outline:none;border-color:var(--teal);}
select.book-inp{-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23A89880' stroke-width='1.5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .8rem center;}
.book-pol-row{display:flex;justify-content:space-between;align-items:center;padding:.55rem 1.6rem .55rem 1.7rem;border-bottom:1px solid var(--border);font-size:.68rem;}
.book-pol-row:last-child{border-bottom:none;}
.book-pol-toggle{border-top:1px solid var(--border);margin-top:1rem;padding-top:.1rem;}
.book-pol-btn{display:flex;width:100%;justify-content:space-between;align-items:center;padding:.65rem 1.6rem .65rem 1.7rem;background:none;border:none;cursor:pointer;font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mid);font-family:'Jost',sans-serif;font-weight:500;}
.book-pol-btn:hover{color:var(--teal);}
.pol-chevron{transition:transform .22s ease;display:inline-block;}
.book-pol-body{display:none;}
.book-pol-body.open{display:block;}
.book-pol-k{color:var(--light);}
.book-pol-v{color:var(--dark);font-weight:500;text-align:right;}
.btn-book-full{display:block;width:100%;padding:.92rem;font-size:.62rem;letter-spacing:.2em;text-transform:uppercase;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;transition:background .22s;font-family:'Jost',sans-serif;font-weight:500;text-align:center;text-decoration:none;}
.btn-book-full:hover{background:var(--sand-lt);}
.btn-ghost-full{display:block;width:100%;padding:.8rem;font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;background:none;border:1px solid var(--border);color:var(--teal);cursor:pointer;transition:all .22s;margin-top:.5rem;font-family:'Jost',sans-serif;text-align:center;text-decoration:none;}
.btn-ghost-full:hover{border-color:var(--teal);background:rgba(30,92,107,.04);}
.book-note{font-size:.6rem;color:var(--light);text-align:center;margin-top:.7rem;line-height:1.65;padding:0 .5rem;}

/* ── ENQUIRY FORM ── */
.book-enquiry{padding:.9rem 0 0;margin-top:.9rem;border-top:1px solid var(--border);}
.book-enq-label{font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;color:var(--light);margin-bottom:.85rem;text-align:center;}
.book-enq-msg{font-size:.82rem;margin-top:.6rem;padding:.55rem .8rem;text-align:center;display:none;}
.book-enq-msg.show{display:block;}
.book-enq-msg.success{background:rgba(76,175,130,.08);color:#2D7A5F;border:1px solid rgba(76,175,130,.2);}
.book-enq-msg.error{background:rgba(200,80,60,.06);color:#9B3B2A;border:1px solid rgba(200,80,60,.15);}
.enq-row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;}
.enq-back-btn{background:none;border:none;color:var(--mid);font-size:.75rem;letter-spacing:.05em;cursor:pointer;margin-top:.5rem;padding:0;display:block;}
.enq-back-btn:hover{color:var(--teal);}
input[type="date"].book-inp{cursor:pointer;}
select.book-inp{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .75rem center;padding-right:2rem;}
.book-features{padding:1rem 1.6rem 1rem 1.7rem;border-top:1px solid var(--border);background:var(--sand-faint);display:flex;flex-direction:column;gap:.42rem;}
.book-feat{display:flex;align-items:center;gap:.55rem;font-size:.68rem;color:var(--mid);}
.book-feat-ico{color:var(--teal);font-size:.65rem;flex-shrink:0;width:14px;text-align:center;}
.book-whatsapp{display:flex;align-items:center;justify-content:center;gap:.6rem;padding:.85rem 1.4rem;border-top:1px solid var(--border);background:var(--white);font-size:.68rem;color:var(--teal);font-weight:500;transition:background .2s;text-decoration:none;}
.book-whatsapp:hover{background:rgba(30,92,107,.04);}
.book-whatsapp i{font-size:.95rem;color:#25D366;}

/* ── STICKY CTA (mobile) ── */
.sticky-cta{display:none;position:fixed;bottom:0;left:0;right:0;z-index:400;background:var(--white);border-top:1px solid var(--border);padding:.9rem 1.2rem;align-items:center;justify-content:space-between;gap:.75rem;}
.sticky-cta-info{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:300;color:var(--dark);}
.sticky-cta-sub{font-size:.6rem;color:var(--light);}
.sticky-cta-btn{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;padding:.75rem 1.4rem;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;font-family:'Jost',sans-serif;font-weight:500;white-space:nowrap;text-decoration:none;display:inline-block;}

/* ── RESPONSIVE ── */
@media(max-width:1100px){
  .page-wrap{grid-template-columns:1fr;padding:0 28px 4rem;}
  .page-side{position:static;}
  .sticky-cta{display:flex;}
  body{padding-bottom:72px;}
}
@media(max-width:768px){
  .gallery{grid-template-columns:1fr;height:60vw;max-height:380px;}
  .gallery-main{grid-row:span 1;}
  .gallery-thumb{display:none;}
  .breadcrumb{padding:.75rem 20px;}
  .page-wrap{padding:0 16px 4rem;}
  .quick-stats{flex-wrap:wrap;}
  .qs{flex:0 0 33%;}
  .amenities-grid{grid-template-columns:1fr;}
  .amenity{border-right:none!important;}
  .amenity:nth-last-child(-n+2){border-bottom:1px solid var(--border);}
  .amenity:last-child{border-bottom:none;}
  .photo-grid{grid-template-columns:1fr 1fr;}
  .other-grid{grid-template-columns:1fr 1fr;}
  .compare-block{flex-direction:column;gap:.8rem;}
}
</style>

<!-- ═══ GALLERY HERO ═══ -->
<?php
$pg_venue_slug   = 'sandbox';
$pg_fallback_badge = 'Sandbox · Bofa Road · Kilifi';
$pg_fallback = [
  ['src' => 'images/hero-sandbox.jpg', 'alt' => 'Sandbox self-catering beachfront villa — Bofa Road, Kilifi'],
];
include __DIR__ . '/includes/property-gallery.php';
?>


<!-- ═══ BREADCRUMB ═══ -->
<div class="breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">›</span>
  <a href="./#properties">Properties</a>
  <span class="breadcrumb-sep">›</span>
  <a href="./#properties">Private Villas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-curr">Sandbox</span>
</div>

<!-- ═══ PAGE WRAP ═══ -->
<div class="page-wrap">

  <!-- ══ MAIN ══ -->
  <div class="page-main">

    <!-- Listing header -->
    <div class="listing-eyebrow"><?= e(ts_venue_text('sandbox', 'tagline', 'Beachfront Self-Catering Villa · Kilifi')) ?></div>
    <h1 class="listing-h1">Sandbox · Beachfront Self-Catering Villa · <em>Kilifi</em></h1>
    <div class="listing-sub">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="2.5"/><path d="M8 1.5C5.24 1.5 3 3.74 3 6.5c0 4 5 8.5 5 8.5s5-4.5 5-8.5c0-2.76-2.24-5-5-5z"/></svg>
      Bofa Road · Kilifi County · Kenya's North Coast
    </div>

    <!-- Quick stats -->
    <div class="quick-stats">
      <div class="qs"><div class="qs-n">4</div><div class="qs-l">Bedrooms</div></div>
      <div class="qs"><div class="qs-n">8</div><div class="qs-l">Guests</div></div>
      <div class="qs"><div class="qs-n">3</div><div class="qs-l">Bathrooms</div></div>
      <div class="qs"><div class="qs-n">1</div><div class="qs-l">Pool</div></div>
      <div class="qs"><div class="qs-n">24h</div><div class="qs-l">Security</div></div>
    </div>

    <!-- Pills -->
    <div class="pill-row">
      <span class="pill hi">Beachfront</span>
      <span class="pill hi">Self-Catering</span>
      <span class="pill hi">Pool</span>
      <span class="pill">Direct Beach Access</span>
      <span class="pill">Full Kitchen</span>
      <span class="pill">Free Wi-Fi</span>
      <span class="pill">Air Conditioning</span>
      <span class="pill">24hr Security</span>
      <span class="pill">Airport Transfer</span>
      <span class="pill">Private & Flexible</span>
    </div>

    <div class="divider"></div>

    <!-- About (editable in admin → Properties → Page Content; falls back to text below) -->
    <?php
    $va_slug = 'sandbox';
    $va_heading_fallback = 'Best Self-Catering Villa in <em>Kilifi, Kenya</em>';
    $va_body_fallback = "Sandbox is a self-catering beachfront villa on Kilifi's coveted Bofa Road — the same stretch of coast as Maya Kobe and Enkare Bofa. Four bedrooms, three bathrooms, a private pool, and direct beach access make it an ideal base for groups who want privacy, flexibility and genuine coastal living.\n\nThe villa is fully equipped for self-catering: a spacious, well-appointed kitchen, generous outdoor living areas, and the kind of easy, informal atmosphere that makes holidays feel effortless. No cook is included — you bring your own or self-cater — which also keeps the price point accessible for groups who value independence over full service.\n\nSandbox sleeps up to 8 guests comfortably. Whether you are coming from Nairobi for a long weekend, or travelling from South Africa or beyond for a week on the coast, it delivers exactly what Kilifi does best: warm water, wide skies and no agenda.";
    include __DIR__ . '/includes/venue-about.php';
    ?>

    <div class="divider"></div>

    <!-- Rooms & Availability (same as Zuri) -->
    <?php $rr_venue_slug = 'sandbox'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>

    <div class="divider"></div>

    <!-- Amenities -->
    <div class="sec">
      <div class="sec-label">Amenities & Features</div>
      <h2 class="sec-h">What's <em>Included</em></h2>
      <div class="sec-rule"></div>
      <div class="amenities-grid">
        <div class="amenity"> Direct Beach Access</div>
        <div class="amenity"> Private Pool</div>
        <div class="amenity"> Fully Equipped Kitchen</div>
        <div class="amenity"> Self-Catering Setup</div>
        <div class="amenity"> 4 Bedrooms</div>
        <div class="amenity"> Sleeps Up to 8</div>
        <div class="amenity"> 3 Bathrooms</div>
        <div class="amenity"> Free WiFi Throughout</div>
        <div class="amenity"> Air Conditioning</div>
        <div class="amenity"> Spacious Outdoor Areas</div>
        <div class="amenity"> 24-Hour Security</div>
        <div class="amenity"> Airport Transfers Available</div>
      </div>
    </div>

    <div class="divider"></div>

<?php
// Photo Gallery — photos come from Admin → Venues → Sandbox → Gallery
$pg_venue_slug = 'sandbox';                     // same venue as the hero gallery above
$pgrid_heading = 'Explore <em>Sandbox</em>';
include __DIR__ . '/includes/property-photo-grid.php';
?>

    <!-- Compare callout -->
    <div class="sec">
      <div class="sec-label">Compare Properties</div>
      <h2 class="sec-h">Sandbox vs <em>Enkare Bofa</em></h2>
      <div class="sec-rule"></div>
      <div class="compare-block">
        <div class="compare-icon"></div>
        <div class="compare-body">
          <div class="compare-h">Need a cook included and more space?</div>
          <div class="compare-p">Enkare Bofa is also on Bofa Road. It includes an in-house cook, has 5 bedrooms and sleeps up to 10 — ideal if your group is larger or you would prefer full service. Sandbox is the more independent, slightly smaller option with the same beachfront setting.</div>
          <a href="enkare-bofa.php" class="compare-link">View Enkare Bofa →</a>
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Experiences -->
    <div class="sec">
      <div class="sec-label">Nearby Experiences</div>
      <h2 class="sec-h">Your Base for <em>Adventure</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p" style="margin-bottom:1.2rem;">All activities arranged through your Tribal Sand concierge — quoted within 24 hours of enquiry.</p>
      <div class="exp-list">
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Kitesurfing · Tribal Kite School · Kilifi</div><div class="exp-price">From $130/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Sunset Dhow Cruise · Kilifi Creek</div><div class="exp-price">From $45/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Deep Sea Fishing · Full or Half Day</div><div class="exp-price">From $550/boat</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Tsavo East · Day Safari</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Snorkelling · Watamu Marine Park</div><div class="exp-price">From $40/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Yoga & Wellness Sessions</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Beach Dinner</div><div class="exp-price">On Request</div></div>
      </div>
      <div class="exp-cta">
        <a href="trip-builder.php" style="display:inline-flex;align-items:center;gap:.5rem;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;padding:.78rem 1.6rem;background:var(--teal);color:#fff;font-family:'Jost',sans-serif;transition:background .22s;" onmouseover="this.style.background='#102F3A'" onmouseout="this.style.background='#1E5C6B'">Build Your Itinerary →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Location -->
    <div class="sec">
      <div class="sec-label">Location</div>
      <h2 class="sec-h">Bofa Road · <em>Kilifi</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Sandbox sits on Bofa Road — Kilifi's most desirable coastal address, shared with Maya Kobe and Enkare Bofa. The road runs parallel to the beach with direct ocean access, and is within easy reach of Kilifi Creek, local restaurants and the town centre. Mombasa Moi Airport is approximately 60 minutes by road.</p>
      <div class="map-block">
        <div>
          <div class="map-eyebrow">Directions</div>
          <div class="map-h">Bofa Road · Kilifi · Kenya</div>
          <div class="map-p">Approx. 60 min from Mombasa Moi Airport · Direct coast road transfer available</div>
        </div>
        <a href="https://maps.google.com/?q=Bofa+Road+Kilifi+Kenya" target="_blank" class="btn-map">Open in Google Maps →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- FAQ -->
    <div class="sec">
      <div class="sec-label">Good to Know</div>
      <h2 class="sec-h">Frequently <em>Asked</em></h2>
      <div class="sec-rule"></div>
      <?php foreach ($faqs as $faq): ?>
      <div class="faq-item">
        <div class="faq-q"><?= htmlspecialchars($faq['q']) ?></div>
        <div class="faq-a"><?= htmlspecialchars($faq['a']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="divider"></div>

    <!-- Other properties -->
    <div class="sec">
      <div class="sec-label">Explore More</div>
      <h2 class="sec-h">Other <em>Properties</em></h2>
      <div class="sec-rule"></div>
      <div class="other-grid">
        <a href="enkare-bofa.php" class="other-card">
          <img class="other-img" src="images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" alt="Enkare Bofa beachfront villa Kilifi">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Enkare Bofa</div>
            <div class="other-meta">5 Bedrooms · Sleeps 10 · Cook Included</div>
          </div>
          <div class="other-foot"><span class="other-type">Private Villa</span><span class="other-link">View →</span></div>
        </a>
        <a href="maya-kobe.php" class="other-card">
          <img class="other-img" src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe boutique hotel Kilifi">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Maya Kobe</div>
            <div class="other-meta">5 Suites · Up to 12 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Boutique Hotel</span><span class="other-link">View →</span></div>
        </a>
        <a href="trip-builder.php" class="other-card">
          <img class="other-img" src="images/Maya-Kobe-1-hero.webp" alt="Plan your Kenya coast trip with Tribal Sand">
          <div class="other-body">
            <div class="other-loc">Kenya's North Coast</div>
            <div class="other-name">Plan Your Trip</div>
            <div class="other-meta">Custom itineraries · 24-hour quote</div>
          </div>
          <div class="other-foot"><span class="other-type">Trip Builder</span><span class="other-link">Start →</span></div>
        </a>
      </div>
    </div>

  </div><!-- /page-main -->

  <!-- ══ SIDEBAR ══ -->
  <aside class="page-side">
    <div class="book-card">

      <div class="book-head">
        <div class="book-head-bg"></div>
        <div class="book-head-overlay"></div>
        <div class="book-head-inner">
          <div class="book-eyebrow">Self-Catering Beachfront Villa</div>
          <div class="book-name">Sandbox</div>
          <div class="book-loc">Bofa Road · Kilifi · Kenya</div>
        </div>
      </div>

      <div class="book-body" style="padding:0">
        <?php $booking_slug = 'sandbox'; include __DIR__ . '/includes/booking-widget.php'; ?>
      </div>

      <!-- Policy accordion -->
      <div class="book-pol-toggle">
        <button class="book-pol-btn" id="polBtn">Property Policies <span class="pol-chevron"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span></button>
        <div class="book-pol-body" id="polBody">
          <div class="book-pol-row"><span class="book-pol-k">Check-in</span><span class="book-pol-v">From 2:00 PM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Check-out</span><span class="book-pol-v">By 10:00 AM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Min. Stay</span><span class="book-pol-v">2 nights · 5 peak</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Security Deposit</span><span class="book-pol-v">USD 500</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Smoking</span><span class="book-pol-v">Non-smoking</span></div>
        </div>
      </div>

      <div class="book-features">
        <div class="book-feat"><span class="book-feat-ico">·</span> Self-catering — full kitchen</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Entire villa — no shared spaces</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Cook available via concierge on request</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Airport transfers available</div>
      </div>

      <a href="https://wa.me/254115115247" class="book-whatsapp">
        <i class="fab fa-whatsapp"></i> Chat on WhatsApp · +254 115 115 247
      </a>

    </div>
  </aside>

</div><!-- /page-wrap -->

<!-- ═══ STICKY CTA (mobile) ═══ -->
<div class="sticky-cta">
  <div>
    <div class="sticky-cta-info">Sandbox</div>
    <div class="sticky-cta-sub">4 Bedrooms · Sleeps 8 · Self-Catering</div>
  </div>
  <a href="#rrBar" class="sticky-cta-btn">Book Now</a>
</div>

<?php
$sbb_name = 'Sandbox';
$sbb_loc  = 'Bofa Road · Kilifi';
$sbb_meta = '4 Bedrooms · Up to 8 guests';
$sbb_cta  = 'Check Availability';
include __DIR__ . '/includes/sticky-book-bar.php';
?>

<?php include 'includes/footer.php'; ?>

<script>
/* ── FAQ accordion ── */
document.querySelectorAll('.faq-q').forEach(function(q){
  q.addEventListener('click', function(){
    var item = this.closest('.faq-item');
    var wasOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach(function(i){ i.classList.remove('open'); });
    if (!wasOpen) item.classList.add('open');
  });
});

// ── Policy accordion ──
(function(){
  var polBtn  = document.getElementById('polBtn');
  var polBody = document.getElementById('polBody');
  var polChev = polBtn ? polBtn.querySelector('.pol-chevron') : null;
  if (polBtn && polBody) {
    polBtn.addEventListener('click', function(){
      var open = polBody.classList.toggle('open');
      if (polChev) polChev.style.transform = open ? 'rotate(180deg)' : '';
    });
  }
})();
</script>

</body>
</html>
