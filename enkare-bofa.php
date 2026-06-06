<?php require_once 'includes/schema.php'; ?>
<?php
$page_title   = 'Enkare Bofa · Beachfront Villa · Bofa Road Kilifi · Tribal Sand';
$page_desc    = 'Enkare Bofa is a five-bedroom beachfront private villa on Kilifi\'s Bofa Road, Kenya. In-house cook, pool, beach access. Ideal for families and groups.';
$page_url     = 'https://tribalsand.com/enkare-bofa.php';
$page_image   = 'https://tribalsand.com/images/hero-enkare-bofa.jpg';
$page_preload = 'images/hero-enkare-bofa.jpg';

$faqs = [
    ['q' => 'Is an in-house cook included at Enkare Bofa?',          'a' => 'Yes. An in-house cook is included in your stay. The villa also has a fully equipped kitchen for self-catering if preferred.'],
    ['q' => 'Can Enkare Bofa accommodate large families?',            'a' => 'Yes. With 5 bedrooms sleeping up to 10 guests, Enkare Bofa is ideal for family gatherings and friendship groups looking for a relaxed beachfront base.'],
    ['q' => 'Where is Enkare Bofa located?',                          'a' => 'Enkare Bofa is on Bofa Road, Kilifi — one of Kenya\'s most desirable coastal addresses. It is within walking distance of the beach and close to Kilifi Creek.'],
    ['q' => 'What is the minimum stay at Enkare Bofa?',               'a' => 'Standard season: 2 nights minimum. Peak season: 5 nights minimum. A refundable security deposit of USD 500 applies.'],
    ['q' => 'What activities are available near Enkare Bofa Kilifi?', 'a' => 'Kilifi offers kitesurfing via Tribal Kite School, dhow cruises on Kilifi Creek, deep sea fishing, and day safaris to Tsavo. Our team arranges everything — see our activities page.'],
];

$page_schema =
    ts_schema_org() .
    ts_schema_lodging([
        'name'            => 'Enkare Bofa Villa',
        'description'     => 'Five-bedroom beachfront private villa on Kilifi\'s Bofa Road. In-house cook, pool, direct beach access. Sleeps up to 10 guests.',
        'url'             => 'https://tribalsand.com/enkare-bofa.php',
        'image'           => ['https://tribalsand.com/images/hero-enkare-bofa.jpg'],
        'addressLocality' => 'Kilifi',
        'addressRegion'   => 'Kilifi County',
        'lat'             => -3.6340,
        'lng'             => 39.8503,
        'numberOfRooms'   => 5,
        'priceRange'      => '$$$',
        'amenities'       => ['Pool','Beachfront','In-House Cook','Full Kitchen','Free WiFi','Air Conditioning'],
    ]) .
    ts_schema_breadcrumb([
        ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
        ['name' => 'Properties',     'url' => 'https://tribalsand.com/#properties'],
        ['name' => 'Private Villas', 'url' => 'https://tribalsand.com/#properties'],
        ['name' => 'Enkare Bofa',    'url' => ''],
    ]) .
    ts_schema_faq($faqs);

$page_rooms_rates = true;
$rr_venue_slug = 'enkare-bofa';
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

/* ── FAQ ── */
.faq-item{border-bottom:1px solid var(--border);}
.faq-q{display:flex;justify-content:space-between;align-items:center;padding:1rem 0;font-size:.8rem;font-weight:400;color:var(--dark);cursor:pointer;user-select:none;gap:1rem;}
.faq-ico{color:var(--sand);font-size:1.1rem;transition:transform .22s;flex-shrink:0;}
.faq-item.open .faq-ico{transform:rotate(45deg);}
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
.book-card{background:var(--white);border:1px solid var(--border);overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.09);}
.book-head{position:relative;overflow:hidden;background:var(--teal-d);height:140px;}
.book-head-bg{position:absolute;inset:0;background:url('images/hero-enkare-bofa.jpg') center/cover;opacity:.25;}
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
.book-pol-row{display:flex;justify-content:space-between;align-items:center;padding:.55rem .85rem;border-bottom:1px solid var(--border);font-size:.68rem;}
.book-pol-row:last-child{border-bottom:none;}
.book-pol-toggle{border-top:1px solid var(--border);margin-top:1rem;padding-top:.1rem;}
.book-pol-btn{display:flex;width:100%;justify-content:space-between;align-items:center;padding:.65rem 0;background:none;border:none;cursor:pointer;font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mid);font-family:'Jost',sans-serif;font-weight:500;}
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
.book-features{padding:.9rem 1.4rem;border-top:1px solid var(--border);background:var(--sand-faint);display:flex;flex-direction:column;gap:.38rem;}
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

/* ── LIGHTBOX ── */
.lb{display:none;position:fixed;inset:0;z-index:9999;background:rgba(5,15,20,.96);flex-direction:column;align-items:center;justify-content:center;}
.lb.show{display:flex;}
.lb-close{position:absolute;top:1.4rem;right:1.8rem;font-size:1.4rem;color:rgba(255,255,255,.5);cursor:pointer;background:none;border:none;}
.lb-img{max-width:92vw;max-height:84vh;object-fit:contain;}
.lb-nav{display:flex;gap:1.2rem;margin-top:1.2rem;}
.lb-btn{font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.45);background:none;border:1px solid rgba(255,255,255,.12);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'Jost',sans-serif;}
.lb-btn:hover{color:#fff;border-color:rgba(255,255,255,.4);}
.lb-count{font-size:.56rem;color:rgba(255,255,255,.22);margin-top:.6rem;letter-spacing:.12em;}

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
}
</style>

<!-- ═══ GALLERY HERO ═══ -->
<?php $pg_venue_slug = 'enkare-bofa'; include __DIR__ . '/includes/property-gallery.php'; ?>


<!-- ═══ BREADCRUMB ═══ -->
<div class="breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">›</span>
  <a href="./#properties">Properties</a>
  <span class="breadcrumb-sep">›</span>
  <a href="./#properties">Private Villas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-curr">Enkare Bofa</span>
</div>

<!-- ═══ PAGE WRAP ═══ -->
<div class="page-wrap">

  <!-- ══ MAIN ══ -->
  <div class="page-main">

    <!-- Listing header -->
    <div class="listing-eyebrow">Beachfront Private Villa · Kilifi</div>
    <h1 class="listing-h1">Enkare Bofa · Beachfront Private Villa · <em>Kilifi</em></h1>
    <div class="listing-sub">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="2.5"/><path d="M8 1.5C5.24 1.5 3 3.74 3 6.5c0 4 5 8.5 5 8.5s5-4.5 5-8.5c0-2.76-2.24-5-5-5z"/></svg>
      Bofa Road · Kilifi County · Kenya's North Coast
    </div>

    <!-- Quick stats -->
    <div class="quick-stats">
      <div class="qs"><div class="qs-n">5</div><div class="qs-l">Bedrooms</div></div>
      <div class="qs"><div class="qs-n">10</div><div class="qs-l">Guests</div></div>
      <div class="qs"><div class="qs-n">4</div><div class="qs-l">Bathrooms</div></div>
      <div class="qs"><div class="qs-n">1</div><div class="qs-l">Pool</div></div>
      <div class="qs"><div class="qs-n">24h</div><div class="qs-l">Security</div></div>
    </div>

    <!-- Pills -->
    <div class="pill-row">
      <span class="pill hi">Beachfront</span>
      <span class="pill hi">In-House Cook</span>
      <span class="pill hi">Pool</span>
      <span class="pill">Direct Beach Access</span>
      <span class="pill">Full Kitchen</span>
      <span class="pill">Free Wi-Fi</span>
      <span class="pill">Air Conditioning</span>
      <span class="pill">24hr Security</span>
      <span class="pill">Airport Transfer</span>
      <span class="pill">Family-Friendly</span>
    </div>

    <div class="divider"></div>

    <!-- About -->
    <div class="sec">
      <div class="sec-label">About the Property</div>
      <h2 class="sec-h">Best Beachfront Villa on <em>Bofa Road, Kilifi</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Enkare Bofa sits on one of Kenya's most sought-after coastal addresses — Bofa Road, Kilifi. A comfortable, stylish five-bedroom beachfront villa, it offers everything a family or group needs for a genuine coastal escape: direct beach access, a private pool, a garden, and the ease of an in-house cook from the moment you arrive.</p>
      <p class="sec-p">This is Kilifi done right — relaxed, unpretentious and genuinely on the water — without the ultra-premium price tag. Whether you are travelling from Nairobi, Johannesburg, or further afield, Enkare Bofa gives you a full kitchen, a flexible self-catering option, and a comfortable base for everything Kilifi has to offer.</p>
      <p class="sec-p">The villa takes the whole group — up to 10 guests across 5 bedrooms and 4 bathrooms — so there is space to breathe, gather and properly unwind.</p>
    </div>

    <div class="divider"></div>

    <!-- Amenities -->
    <div class="sec">
      <div class="sec-label">Amenities & Features</div>
      <h2 class="sec-h">Everything <em>Included</em></h2>
      <div class="sec-rule"></div>
      <div class="amenities-grid">
        <div class="amenity"> Direct Beach Access</div>
        <div class="amenity"> Private Pool</div>
        <div class="amenity"> In-House Cook Included</div>
        <div class="amenity"> Fully Equipped Kitchen</div>
        <div class="amenity"> 5 Bedrooms</div>
        <div class="amenity"> Sleeps Up to 10</div>
        <div class="amenity"> 4 Bathrooms</div>
        <div class="amenity"> Free WiFi Throughout</div>
        <div class="amenity"> Air Conditioning</div>
        <div class="amenity"> 24-Hour Security</div>
        <div class="amenity"> Tropical Garden</div>
        <div class="amenity"> Airport Transfers Available</div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Photo gallery -->
    <div class="sec">
      <div class="sec-label">Photo Gallery</div>
      <h2 class="sec-h">Explore <em>Enkare Bofa</em></h2>
      <div class="sec-rule"></div>
      <div class="photo-grid">
        <img src="images/hero-enkare-bofa.jpg" alt="Enkare Bofa outdoor and beachfront" onclick="openLb(0)">
        <img src="images/hero-enkare-bofa.jpg" alt="Enkare Bofa pool" onclick="openLb(1)">
        <img src="images/hero-enkare-bofa.jpg" alt="Enkare Bofa garden" onclick="openLb(2)">
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge</div>
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
      <p class="sec-p">Bofa Road is Kilifi's most coveted coastal strip — a quiet, leafy lane running alongside the Indian Ocean, within walking distance of the beach and minutes from Kilifi Creek. Enkare Bofa sits directly on it, giving guests genuine beachfront living alongside easy access to Kilifi town, restaurants and activities.</p>
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
        <div class="faq-q"><?= htmlspecialchars($faq['q']) ?> <span class="faq-ico">+</span></div>
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
        <a href="maya-kobe.php" class="other-card">
          <img class="other-img" src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe boutique hotel Kilifi">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Maya Kobe</div>
            <div class="other-meta">5 Suites · Up to 12 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Boutique Hotel</span><span class="other-link">View →</span></div>
        </a>
        <a href="sandbox.php" class="other-card">
          <img class="other-img" src="images/Sandbox/outdoors/IMG-20251117-WA0091.jpg" alt="Sandbox self-catering villa Kilifi">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Sandbox</div>
            <div class="other-meta">4 Bedrooms · Sleeps 8</div>
          </div>
          <div class="other-foot"><span class="other-type">Private Villa</span><span class="other-link">View →</span></div>
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
          <div class="book-eyebrow">Beachfront Private Villa · Cook Included</div>
          <div class="book-name">Enkare Bofa</div>
          <div class="book-loc">Bofa Road · Kilifi · Kenya</div>
        </div>
      </div>

      <div class="book-body">

        <!-- Enquiry form -->
        <div class="book-enquiry" id="enqWrap">
          <!-- Step 1: Trip details -->
          <div class="enq-step" id="enqStep1">
            <div class="book-enq-label">Quick Enquiry</div>
            <div class="enq-row">
              <div class="book-field">
                <label class="book-lbl" for="enqArrival">Arrival</label>
                <input type="date" class="book-inp" id="enqArrival">
              </div>
              <div class="book-field">
                <label class="book-lbl" for="enqDeparture">Departure</label>
                <input type="date" class="book-inp" id="enqDeparture">
              </div>
            </div>
            <div class="enq-row">
              <div class="book-field">
                <label class="book-lbl" for="enqAdults">Adults</label>
                <select class="book-inp" id="enqAdults">
                  <option value="">—</option>
                  <option>1</option><option>2</option><option>3</option><option>4</option>
                  <option>5</option><option>6</option><option>7</option><option>8</option>
                  <option>9</option><option>10+</option>
                </select>
              </div>
              <div class="book-field">
                <label class="book-lbl" for="enqChildren">Children</label>
                <select class="book-inp" id="enqChildren">
                  <option value="">—</option>
                  <option>0</option><option>1</option><option>2</option><option>3</option>
                  <option>4</option><option>5</option><option>6+</option>
                </select>
              </div>
            </div>
            <div class="book-field">
              <label class="book-lbl" for="enqRooms">Villas</label>
              <select class="book-inp" id="enqRooms">
                <option value="">—</option>
                <option>1 Villa</option><option>2 Villas</option><option>3 Villas</option><option>Full Property</option>
              </select>
            </div>
            <button class="btn-book-full" id="enqNext">Next →</button>
          </div>

          <!-- Step 2: Personal details -->
          <div class="enq-step" id="enqStep2" style="display:none">
            <div class="book-enq-label">Your Details</div>
            <div class="book-field">
              <label class="book-lbl" for="enqName">Name <span style="color:var(--sand)">*</span></label>
              <input type="text" class="book-inp" id="enqName" placeholder="Jane Smith" autocomplete="name">
            </div>
            <div class="book-field">
              <label class="book-lbl" for="enqEmail">Email <span style="color:var(--sand)">*</span></label>
              <input type="email" class="book-inp" id="enqEmail" placeholder="jane@example.com" autocomplete="email">
            </div>
            <div class="book-field">
              <label class="book-lbl" for="enqPhone">Phone / WhatsApp</label>
              <input type="tel" class="book-inp" id="enqPhone" placeholder="+254 xxx xxx xxx" autocomplete="tel">
            </div>
            <div class="book-field">
              <label class="book-lbl" for="enqMessage">Message</label>
              <textarea class="book-inp" id="enqMessage" rows="3" placeholder="Anything specific we should know…" style="resize:vertical"></textarea>
            </div>
            <button class="btn-book-full" id="btnEnquire">Send Enquiry →</button>
            <button type="button" class="enq-back-btn" id="enqBack">← Back</button>
            <div class="book-enq-msg" id="enqMsg"></div>
          </div>
        </div>

      </div>

      <!-- Policy accordion -->
      <div class="book-pol-toggle">
        <button class="book-pol-btn" id="polBtn">Property Policies <span class="pol-chevron">▾</span></button>
        <div class="book-pol-body" id="polBody">
          <div class="book-pol-row"><span class="book-pol-k">Check-in</span><span class="book-pol-v">From 2:00 PM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Check-out</span><span class="book-pol-v">By 10:00 AM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Min. Stay</span><span class="book-pol-v">2 nights · 5 peak</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Security Deposit</span><span class="book-pol-v">USD 500</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Smoking</span><span class="book-pol-v">Non-smoking</span></div>
        </div>
      </div>

      <div class="book-features">
        <div class="book-feat"><span class="book-feat-ico">·</span> In-house cook included in stay</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Entire villa — no shared spaces</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Activities arranged by concierge</div>
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
    <div class="sticky-cta-info">Enkare Bofa</div>
    <div class="sticky-cta-sub">5 Bedrooms · Sleeps 10 · Cook Included</div>
  </div>
  <a href="#rrBar" class="sticky-cta-btn">Book Now</a>
</div>

<!-- ═══ LIGHTBOX ═══ -->
<div class="lb" id="lb">
  <button class="lb-close" onclick="closeLb()">&#10005;</button>
  <img class="lb-img" id="lbImg" src="" alt="">
  <div class="lb-nav">
    <button class="lb-btn" onclick="lbPrev()">&#8592; Prev</button>
    <button class="lb-btn" onclick="lbNext()">Next &#8594;</button>
  </div>
  <div class="lb-count" id="lbCount"></div>
</div>

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

/* ── Lightbox ── */
var lbImages = [
  'images/hero-enkare-bofa.jpg',
  'images/hero-enkare-bofa.jpg',
  'images/hero-enkare-bofa.jpg'
];
var lbIdx = 0;
function openLb(i){
  lbIdx = i;
  document.getElementById('lbImg').src = lbImages[i];
  document.getElementById('lbCount').textContent = (i + 1) + ' / ' + lbImages.length;
  document.getElementById('lb').classList.add('show');
  document.body.style.overflow = 'hidden';
}
function closeLb(){
  document.getElementById('lb').classList.remove('show');
  document.body.style.overflow = '';
}
function lbNext(){ lbIdx = (lbIdx + 1) % lbImages.length; openLb(lbIdx); }
function lbPrev(){ lbIdx = (lbIdx - 1 + lbImages.length) % lbImages.length; openLb(lbIdx); }
document.getElementById('lb').addEventListener('click', function(e){ if (e.target === this) closeLb(); });
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeLb();
  if (e.key === 'ArrowRight') lbNext();
  if (e.key === 'ArrowLeft') lbPrev();
});

// GHL enquiry submit
(function(){
  // ── Step navigation ──
  var step1 = document.getElementById('enqStep1');
  var step2 = document.getElementById('enqStep2');
  var nextBtn = document.getElementById('enqNext');
  var backBtn = document.getElementById('enqBack');
  if (!step1 || !step2) return;

  nextBtn.addEventListener('click', function(){
    step1.style.display = 'none';
    step2.style.display = '';
    document.getElementById('enqName').focus();
  });
  backBtn.addEventListener('click', function(){
    step2.style.display = 'none';
    step1.style.display = '';
  });

  // ── Submit ──
  document.getElementById('btnEnquire').addEventListener('click', function(){
    var name    = (document.getElementById('enqName').value    || '').trim();
    var email   = (document.getElementById('enqEmail').value   || '').trim();
    var phone   = (document.getElementById('enqPhone').value   || '').trim();
    var message = (document.getElementById('enqMessage').value || '').trim();
    var arrival   = (document.getElementById('enqArrival')   ? document.getElementById('enqArrival').value   : '');
    var departure = (document.getElementById('enqDeparture') ? document.getElementById('enqDeparture').value : '');
    var adults    = (document.getElementById('enqAdults')    ? document.getElementById('enqAdults').value    : '');
    var children  = (document.getElementById('enqChildren')  ? document.getElementById('enqChildren').value  : '');
    var rooms     = (document.getElementById('enqRooms')     ? document.getElementById('enqRooms').value     : '');
    var msgEl     = document.getElementById('enqMsg');

    if (!name || !email) {
      msgEl.className = 'book-enq-msg show error';
      msgEl.textContent = 'Please enter your name and email.';
      return;
    }
    var btn = document.getElementById('btnEnquire');
    btn.textContent = 'Sending…'; btn.disabled = true;
    msgEl.className = 'book-enq-msg'; msgEl.textContent = '';

    var parts = name.split(' ');
    var noteLines = ['Enkare Bofa Enquiry'];
    if (arrival)   noteLines.push('Arrival: '   + arrival);
    if (departure) noteLines.push('Departure: ' + departure);
    if (adults)    noteLines.push('Adults: '    + adults);
    if (children)  noteLines.push('Children: '  + children);
    if (rooms)     noteLines.push('Rooms/Unit: '+ rooms);
    if (phone)     noteLines.push('Phone: '     + phone);
    if (message)   noteLines.push('\nMessage:\n' + message);

    fetch('/ghl-submit', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
      guest:   { firstName: parts[0]||name, lastName: parts.slice(1).join(' ')||'', email: email, phone: phone },
      trip:    { prop: 'Enkare Bofa', arrival: arrival, departure: departure, adults: adults, children: children, rooms: rooms },
      message: message,
      tags:    ['website-enquiry', 'enkare-bofa-enquiry'],
      opportunity: { source: 'Website - Enkare Bofa' },
      note:    noteLines.join('\n'),
      ref:     'WEB-' + Date.now()
    })})
    .then(function(r){ return r.json(); })
    .then(function(r){
      if (r.ok) {
        msgEl.className = 'book-enq-msg show success';
        msgEl.textContent = 'Thank you — we\'ll be in touch within 24 hours.';
        btn.textContent = 'Enquiry Sent';
      } else { throw new Error(r.error || 'error'); }
    })
    .catch(function(){
      msgEl.className = 'book-enq-msg show error';
      msgEl.textContent = 'Something went wrong — please WhatsApp us on +254 115 115 247';
      btn.textContent = 'Send Enquiry →'; btn.disabled = false;
    });
  });

  // ── Policy accordion ──
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
