<?php
require_once 'includes/schema.php';

/* ── SEO Variables ── */
$page_title   = 'Zuri · Beachfront Boutique Hotel · Garoda Beach Watamu · Tribal Sand';
$page_desc    = 'Beachfront boutique hotel on Garoda Beach, Watamu. Six ocean-facing suites, private pool and direct beach access inside Watamu Marine National Park, Kenya.';
$page_url     = 'https://tribalsand.com/zuri.php';
$page_image   = 'https://tribalsand.com/images/hero-zuri.jpg';
$page_preload = 'images/hero-zuri.jpg';

/* ── FAQ data ── */
$faqs = [
    ['q' => 'Can I book just one suite at Zuri?',
     'a' => 'Yes. Zuri accommodates individual suite bookings or a full property buyout for up to 14 guests. Contact our team for availability and personalised pricing.'],
    ['q' => 'What is included in the stay at Zuri?',
     'a' => 'All suites include daily housekeeping, à la carte dining, access to the private pool and beachfront. A private chef is part of the fully-serviced experience.'],
    ['q' => 'How far is Zuri from Watamu Marine Park?',
     'a' => 'Zuri is located directly on Watamu\'s beachfront, with easy access to the Watamu Marine National Reserve — Kenya\'s UNESCO-listed marine park. Snorkelling trips can be arranged through our concierge team.'],
    ['q' => 'What is the closest airport to Zuri Watamu?',
     'a' => 'Malindi Airport (MYD) is the closest airport, approximately 20 minutes away. Mombasa Moi International (MBA) is the main hub, roughly 2 hours drive. We arrange all airport transfers on request.'],
    ['q' => 'What is the minimum stay at Zuri?',
     'a' => 'Standard season: 2 nights minimum. Peak season: 5 nights minimum.'],
];

/* ── Structured Data ── */
$page_schema =
    ts_schema_org() .
    ts_schema_lodging([
        'name'            => 'Zuri Boutique Hotel',
        'description'     => 'Luxury beachfront boutique hotel in Watamu, Kenya. Six ocean-facing suites, private pool and chef-led dining on the Indian Ocean shoreline.',
        'url'             => 'https://tribalsand.com/zuri.php',
        'image'           => ['https://tribalsand.com/images/hero-zuri.jpg'],
        'addressLocality' => 'Garoda Beach, Watamu',
        'addressRegion'   => 'Kilifi County',
        'lat'             => -3.3689,
        'lng'             => 40.0150,
        'numberOfRooms'   => 6,
        'priceRange'      => '$$$$',
        'amenities'       => ['Private Pool','Beachfront','À La Carte Dining','Private Chef','Ocean Views','Free WiFi','Air Conditioning'],
    ]) .
    ts_schema_breadcrumb([
        ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
        ['name' => 'Accommodations', 'url' => 'https://tribalsand.com/#properties'],
        ['name' => 'Zuri',           'url' => 'https://tribalsand.com/zuri.php'],
    ]) .
    ts_schema_faq($faqs);

$page_rooms_rates = true;
$rr_venue_slug = 'zuri';

include 'includes/head.php';
?>

<style>
/* ── TOKENS ── */
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;--sand-faint:#FAF6EE;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.14);
  --ts-sand:#B8965A;--ts-sand-lt:#D4B07A;--ts-teal:#1E5C6B;--ts-teal-d:#102F3A;
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;overflow-x:hidden;}
img{display:block;object-fit:cover;}
a{text-decoration:none;color:inherit;}
::-webkit-scrollbar{width:3px;}::-webkit-scrollbar-thumb{background:var(--border);}

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
  font-family:'Jost',sans-serif;font-size:.75rem;letter-spacing:.22em;
  text-transform:uppercase;color:#fff;
}
.gallery-badge{
  position:absolute;bottom:1.2rem;left:1.2rem;z-index:2;
  background:rgba(16,47,58,.82);backdrop-filter:blur(12px);
  border:1px solid rgba(184,150,90,.2);
  padding:.38rem .85rem;
  font-size:.75rem;letter-spacing:.2em;text-transform:uppercase;color:rgba(184,150,90,.8);
}

/* ── BREADCRUMB ── */
.breadcrumb{padding:.9rem 52px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
.breadcrumb a{font-size:.82rem;letter-spacing:.1em;color:var(--light);transition:color .2s;}
.breadcrumb a:hover{color:var(--teal);}
.breadcrumb-sep{font-size:.82rem;color:rgba(184,150,90,.3);}
.breadcrumb-curr{font-size:.82rem;letter-spacing:.1em;color:var(--sand);}

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:1280px;margin:0 auto;padding:0 52px;display:grid;grid-template-columns:1fr 380px;gap:4rem;padding-top:2.8rem;padding-bottom:6rem;}
.page-main{min-width:0;}
.page-side{position:sticky;top:80px;height:fit-content;}

/* ── LISTING HEADER ── */
.listing-eyebrow{font-size:.75rem;letter-spacing:.32em;text-transform:uppercase;color:var(--sand);margin-bottom:.5rem;display:flex;align-items:center;gap:.55rem;}
.listing-eyebrow::before{content:'';width:16px;height:1px;background:var(--sand);}
.listing-h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.4rem,4vw,3.6rem);font-weight:400;line-height:1.05;color:var(--dark);margin-bottom:.5rem;}
.listing-h1 em{font-style:italic;color:var(--teal);}
.listing-sub{font-size:1rem;color:var(--light);display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;font-weight:400;}
.listing-sub svg{color:var(--sand);flex-shrink:0;}

/* ── QUICK STATS ── */
.quick-stats{display:flex;gap:0;border:1px solid var(--border);background:var(--white);margin-bottom:2rem;overflow:hidden;}
.qs{flex:1;padding:1rem .8rem;text-align:center;border-right:1px solid var(--border);}
.qs:last-child{border-right:none;}
.qs-n{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:400;color:var(--dark);line-height:1;}
.qs-l{font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;color:var(--light);margin-top:.18rem;font-weight:400;}

/* ── PILLS ── */
.pill-row{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:2rem;}
.pill{font-size:.75rem;letter-spacing:.1em;text-transform:uppercase;padding:.24rem .65rem;border:1px solid var(--border);color:var(--mid);font-weight:400;}
.pill.hi{border-color:rgba(30,92,107,.3);color:var(--teal);background:rgba(30,92,107,.04);}

/* ── SECTION ── */
.sec{margin-bottom:2.8rem;}
.sec-label{font-size:.75rem;letter-spacing:.28em;text-transform:uppercase;color:var(--sand);margin-bottom:.55rem;display:flex;align-items:center;gap:.5rem;}
.sec-label::before{content:'';width:14px;height:1px;background:var(--sand);}
.sec-h{font-family:'Cormorant Garamond',serif;font-size:clamp(1.5rem,2.5vw,2rem);font-weight:400;color:var(--dark);line-height:1.15;margin-bottom:.45rem;}
.sec-h em{font-style:italic;color:var(--teal);}
.sec-rule{width:20px;height:1px;background:var(--sand);margin-bottom:1.2rem;}
.sec-p{font-size:1rem;line-height:1.92;color:var(--mid);margin-bottom:1rem;font-weight:400;}
.divider{height:1px;background:var(--border);margin:2.4rem 0;}

/* ── AMENITIES ── */
.amenities-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--border);overflow:hidden;}
.amenity{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);border-right:1px solid var(--border);font-size:1rem;color:var(--mid);font-weight:400;transition:background .18s;}
.amenity:hover{background:var(--sand-faint);}
.amenity:nth-child(even){border-right:none;}
.amenity:nth-last-child(-n+2){border-bottom:none;}
.amenity-ico{font-size:1rem;flex-shrink:0;width:1.2rem;text-align:center;}

/* ── PHOTO GRID ── */
.photo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;margin-bottom:.5rem;}
.photo-grid img{width:100%;aspect-ratio:4/3;cursor:pointer;transition:opacity .25s,transform .4s;}
.photo-grid img:hover{opacity:.88;transform:scale(1.02);}
.photo-grid-cap{font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);text-align:right;margin-bottom:2rem;font-weight:400;}

/* ── EXPERIENCES ── */
.exp-list{display:flex;flex-direction:column;gap:1px;background:var(--border);border:1px solid var(--border);}
.exp-row{background:var(--white);display:flex;align-items:center;gap:1rem;padding:.85rem 1.1rem;transition:background .18s;}
.exp-row:hover{background:var(--sand-faint);}
.exp-ico{font-size:1.1rem;flex-shrink:0;}
.exp-name{font-size:1rem;color:var(--dark);flex:1;font-weight:400;}
.exp-price{font-size:.82rem;color:var(--light);white-space:nowrap;font-weight:400;}
.exp-cta{margin-top:1.2rem;display:flex;flex-wrap:wrap;gap:.75rem;}

/* ── FAQ ── */
.faq-item{border-bottom:1px solid var(--border);}
.faq-q{display:flex;justify-content:space-between;align-items:center;padding:1rem 0;font-size:1rem;font-weight:400;color:var(--dark);cursor:pointer;user-select:none;gap:1rem;}
.faq-ico{color:var(--sand);font-size:1.1rem;transition:transform .22s;flex-shrink:0;}
.faq-item.open .faq-ico{transform:rotate(45deg);}
.faq-a{display:none;padding:0 0 1rem;font-size:1rem;color:var(--mid);line-height:1.88;font-weight:400;}
.faq-item.open .faq-a{display:block;}

/* ── MAP BLOCK ── */
.map-block{background:var(--teal-d);padding:2rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;margin-bottom:2rem;}
.map-eyebrow{font-size:.75rem;letter-spacing:.28em;text-transform:uppercase;color:rgba(184,150,90,.5);margin-bottom:.4rem;}
.map-h{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:400;color:#fff;margin-bottom:.3rem;}
.map-p{font-size:1rem;color:rgba(255,255,255,.5);line-height:1.7;font-weight:400;}
.btn-map{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;padding:.65rem 1.4rem;border:1px solid rgba(184,150,90,.3);color:var(--sand-lt);background:none;cursor:pointer;transition:all .22s;white-space:nowrap;font-family:'Jost',sans-serif;font-weight:400;display:inline-block;}
.btn-map:hover{background:var(--sand);border-color:var(--sand);color:var(--teal-d);}

/* ── OTHER PROPERTIES ── */
.other-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem;}
.other-card{background:var(--white);border:1px solid var(--border);overflow:hidden;transition:box-shadow .25s,transform .25s;display:block;}
.other-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.08);transform:translateY(-2px);}
.other-img{width:100%;height:160px;}
.other-body{padding:.9rem 1rem;}
.other-loc{font-size:.75rem;letter-spacing:.22em;text-transform:uppercase;color:var(--sand);margin-bottom:.25rem;}
.other-name{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--dark);margin-bottom:.28rem;}
.other-meta{font-size:1rem;color:var(--light);font-weight:400;}
.other-foot{padding:.7rem 1rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.other-type{font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);}
.other-link{font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;color:var(--teal);font-weight:400;}

/* ── SIDEBAR ── */
.book-card{background:var(--white);border:1px solid var(--border);overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.09);max-height:calc(100vh - 100px);overflow-y:auto;}

.book-head{position:relative;overflow:hidden;background:var(--teal-d);padding:0;height:140px;}
.book-head-bg{position:absolute;inset:0;background:url('images/hero-zuri.jpg') center 40%/cover;opacity:.25;}
.book-head-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(16,47,58,.9) 0%,rgba(16,47,58,.3) 100%);}
.book-head-inner{position:relative;z-index:1;padding:1.2rem 1.4rem;height:100%;display:flex;flex-direction:column;justify-content:flex-end;}
.book-eyebrow{font-size:.75rem;letter-spacing:.26em;text-transform:uppercase;color:rgba(184,150,90,.6);margin-bottom:.3rem;}
.book-name{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:400;color:#fff;line-height:1;margin-bottom:.2rem;}
.book-loc{font-size:1rem;color:rgba(255,255,255,.5);letter-spacing:.06em;font-weight:400;}

.book-body{padding:1.3rem 1.4rem 1rem;}
.book-field{margin-bottom:.75rem;}
.book-lbl{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;color:var(--mid);margin-bottom:.3rem;display:block;font-weight:400;}

.book-date-val{font-size:1rem;color:var(--dark);font-weight:400;}
.book-inp{width:100%;padding:.65rem .8rem;border:1px solid var(--border);background:var(--off);font-size:1rem;color:var(--dark);font-family:'Jost',sans-serif;font-weight:400;transition:border-color .2s;}
.book-inp:focus{outline:none;border-color:var(--teal);}
select.book-inp{-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23A89880' stroke-width='1.5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .8rem center;}

.book-pol-row{display:flex;justify-content:space-between;align-items:center;padding:.55rem .85rem;border-bottom:1px solid var(--border);font-size:1rem;}
.book-pol-row:last-child{border-bottom:none;}
.book-pol-toggle{border-top:1px solid var(--border);margin-top:1rem;padding-top:.1rem;}
.book-pol-btn{display:flex;width:100%;justify-content:space-between;align-items:center;padding:.65rem 0;background:none;border:none;cursor:pointer;font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mid);font-family:'Jost',sans-serif;font-weight:500;}
.book-pol-btn:hover{color:var(--teal);}
.pol-chevron{transition:transform .22s ease;display:inline-block;}
.book-pol-body{display:none;}
.book-pol-body.open{display:block;}
.book-pol-k{color:var(--light);font-weight:400;}
.book-pol-v{color:var(--dark);font-weight:500;text-align:right;}

.btn-book-full{display:block;width:100%;padding:.92rem;font-size:.82rem;letter-spacing:.2em;text-transform:uppercase;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;transition:background .22s;font-family:'Jost',sans-serif;font-weight:500;text-align:center;}
.btn-book-full:hover{background:var(--sand-lt);}
.btn-ghost-full{display:block;width:100%;padding:.8rem;font-size:.82rem;letter-spacing:.18em;text-transform:uppercase;background:none;border:1px solid var(--border);color:var(--teal);cursor:pointer;transition:all .22s;margin-top:.5rem;font-family:'Jost',sans-serif;text-align:center;font-weight:400;}
.btn-ghost-full:hover{border-color:var(--teal);background:rgba(30,92,107,.04);}
.book-note{font-size:1rem;color:var(--light);text-align:center;margin-top:.7rem;line-height:1.65;padding:0 .5rem;font-weight:400;}

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
.book-feat{display:flex;align-items:center;gap:.55rem;font-size:1rem;color:var(--mid);font-weight:400;}
.book-feat-ico{color:var(--teal);font-size:.75rem;flex-shrink:0;width:14px;text-align:center;}
.book-feat a{color:var(--teal);}

.book-whatsapp{display:flex;align-items:center;justify-content:center;gap:.6rem;padding:.85rem 1.4rem;border-top:1px solid var(--border);background:var(--white);font-size:1rem;color:var(--teal);font-weight:500;transition:background .2s;}
.book-whatsapp:hover{background:rgba(30,92,107,.04);}
.book-whatsapp i{font-size:.95rem;color:#25D366;}

/* ── STICKY CTA (mobile) ── */
.sticky-cta{
  display:none;position:fixed;bottom:0;left:0;right:0;z-index:400;
  background:var(--white);border-top:1px solid var(--border);
  padding:.9rem 1.2rem;align-items:center;justify-content:space-between;gap:.75rem;
}
.sticky-cta-info{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--dark);}
.sticky-cta-sub{font-size:1rem;color:var(--light);font-weight:400;}
.sticky-cta-btn{font-size:.82rem;letter-spacing:.2em;text-transform:uppercase;padding:.75rem 1.4rem;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;font-family:'Jost',sans-serif;font-weight:500;white-space:nowrap;}

/* ── LIGHTBOX ── */
.lb{display:none;position:fixed;inset:0;z-index:9999;background:rgba(5,15,20,.96);flex-direction:column;align-items:center;justify-content:center;}
.lb.show{display:flex;}
.lb-close{position:absolute;top:1.4rem;right:1.8rem;font-size:1.4rem;color:rgba(255,255,255,.5);cursor:pointer;background:none;border:none;}
.lb-img{max-width:92vw;max-height:84vh;object-fit:contain;}
.lb-nav{display:flex;gap:1.2rem;margin-top:1.2rem;}
.lb-btn{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.45);background:none;border:1px solid rgba(255,255,255,.12);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'Jost',sans-serif;font-weight:400;}
.lb-btn:hover{color:#fff;border-color:rgba(255,255,255,.4);}
.lb-count{font-size:1rem;color:rgba(255,255,255,.25);margin-top:.6rem;letter-spacing:.12em;font-weight:400;}

/* ── SUITES ── */
.suites-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.6rem;margin-bottom:1.4rem;}
.suite-card{border:1px solid var(--border);background:var(--white);overflow:hidden;transition:box-shadow .3s;}
.suite-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.09);}
.suite-card-img{height:220px;overflow:hidden;position:relative;}
.suite-card-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .5s ease;}
.suite-card:hover .suite-card-img img{transform:scale(1.05);}
.suite-card-tag{position:absolute;bottom:.75rem;left:.75rem;font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;background:rgba(16,47,58,.82);color:rgba(184,150,90,.9);padding:.28rem .75rem;backdrop-filter:blur(6px);}
.suite-card-body{padding:1.1rem 1.2rem 1.25rem;}
.suite-card-name{font-family:'Cormorant Garamond',serif;font-size:1.22rem;font-weight:400;color:var(--dark);margin-bottom:.18rem;}
.suite-card-meta{font-size:.78rem;letter-spacing:.08em;color:var(--light);margin-bottom:.6rem;}
.suite-card-desc{font-size:.95rem;font-weight:400;color:var(--mid);line-height:1.78;}
.suite-badge{display:inline-block;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:var(--sand);border:1px solid rgba(184,150,90,.3);padding:.18rem .55rem;margin-top:.6rem;}

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
  .exp-cta{flex-direction:column;}
}
@media(max-width:640px){
  .suites-grid{grid-template-columns:1fr;}
  .suite-card-img{height:200px;}
}
@media(max-width:480px){
  .other-grid{grid-template-columns:1fr;}
}
</style>
</head>

<body class="ts-nav-transparent">

<?php include 'includes/header.php'; ?>

<!-- ═══ GALLERY ═══ -->
<?php $pg_venue_slug = 'zuri'; include __DIR__ . '/includes/property-gallery.php'; ?>


<!-- ═══ BREADCRUMB ═══ -->
<nav class="breadcrumb" aria-label="Breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">›</span>
  <a href="./#properties">Accommodations</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-curr">Zuri</span>
</nav>

<!-- ═══ PAGE WRAP ═══ -->
<div class="page-wrap">

  <!-- ══ MAIN ══ -->
  <div class="page-main">

    <!-- Listing Header -->
    <div class="listing-eyebrow">Luxury Boutique Hotel · Direct Beachfront</div>
    <h1 class="listing-h1">Zuri · Beachfront Boutique Hotel · <em>Garoda Beach Watamu</em></h1>
    <div class="listing-sub">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="2.5"/><path d="M8 1.5C5.24 1.5 3 3.74 3 6.5c0 4 5 8.5 5 8.5s5-4.5 5-8.5c0-2.76-2.24-5-5-5z"/></svg>
      Watamu · Kilifi County · Kenya's North Coast
    </div>

    <!-- Quick Stats -->
    <div class="quick-stats">
      <div class="qs"><div class="qs-n">6</div><div class="qs-l">Suites</div></div>
      <div class="qs"><div class="qs-n">14</div><div class="qs-l">Guests</div></div>
      <div class="qs"><div class="qs-n">6</div><div class="qs-l">Bathrooms</div></div>
      <div class="qs"><div class="qs-n">1</div><div class="qs-l">Private Pool</div></div>
      <div class="qs"><div class="qs-n">24h</div><div class="qs-l">Security</div></div>
    </div>

    <!-- Pills -->
    <div class="pill-row">
      <span class="pill hi">Direct Beachfront</span>
      <span class="pill hi">Private Pool</span>
      <span class="pill hi">Ocean-Facing Suites</span>
      <span class="pill">À La Carte Dining</span>
      <span class="pill">Private Chef</span>
      <span class="pill">Property Buyout</span>
      <span class="pill">Free Wi-Fi</span>
      <span class="pill">24hr Security</span>
      <span class="pill">Airport Transfers</span>
    </div>

    <div class="divider"></div>

    <!-- About -->
    <div class="sec">
      <div class="sec-label">About the Property</div>
      <h2 class="sec-h">Best Boutique Hotel in <em>Watamu, Kenya</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Zuri is Tribal Sand's luxury beachfront boutique hotel, set directly on the white-sand shoreline of Watamu — one of Kenya's most celebrated coastal destinations. Six elegantly appointed ocean-facing suites are arranged around a private pool, each designed to frame the shifting blues of the Indian Ocean at every hour of the day.</p>
      <p class="sec-p">With an elevated culinary offering — including à la carte dining and private chef experiences — Zuri redefines what a boutique beach hotel can be on Kenya's North Coast. Whether you book a single suite for a romantic escape or take the entire property for a boutique destination wedding or intimate family gathering, Zuri delivers a fully-serviced, immersive experience.</p>
      <p class="sec-p">Located within easy reach of the Watamu Marine National Reserve — Kenya's UNESCO-listed marine park — and approximately 120 km north of Mombasa, Zuri places guests at the heart of one of East Africa's most extraordinary natural environments. Malindi Airport (MYD) is just 20 minutes away.</p>
    </div>

    <div class="divider"></div>

<?php $rr_venue_slug = 'zuri'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>

    <div class="divider"></div>

    <!-- Amenities -->
    <div class="sec">
      <div class="sec-label">Amenities &amp; Features</div>
      <h2 class="sec-h">Everything <em>Included</em></h2>
      <div class="sec-rule"></div>
      <div class="amenities-grid">
        <div class="amenity"> Direct Beachfront Access</div>
        <div class="amenity"> Private Pool</div>
        <div class="amenity"> À La Carte Dining</div>
        <div class="amenity"> Private Chef</div>
        <div class="amenity"> 6 Ocean-Facing Suites</div>
        <div class="amenity"> Full Property Buyout Option</div>
        <div class="amenity"> Ocean Views</div>
        <div class="amenity"> Watamu Marine Park nearby</div>
        <div class="amenity"> Free WiFi</div>
        <div class="amenity"> Air Conditioning</div>
        <div class="amenity"> 24-Hour Security</div>
        <div class="amenity"> Airport Transfers</div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Photo Grid -->
    <div class="sec">
      <div class="sec-label">Photo Gallery</div>
      <h2 class="sec-h">Explore <em>Zuri</em></h2>
      <div class="sec-rule"></div>
      <div class="photo-grid">
        <img src="images/hero-zuri.jpg" alt="Zuri aerial — beachfront, Watamu" onclick="openLb(0)">
        <img src="images/hero-zuri.jpg" alt="Zuri ocean-facing suites" onclick="openLb(1)">
        <img src="images/hero-zuri.jpg" alt="Zuri private pool and Indian Ocean" onclick="openLb(2)">
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge · Zuri · Watamu, Kenya</div>
    </div>

    <div class="divider"></div>

    <!-- Nearby Experiences -->
    <div class="sec">
      <div class="sec-label">Nearby Experiences</div>
      <h2 class="sec-h">Your Gateway to <em>Watamu</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p" style="margin-bottom:1.2rem;">All experiences bookable through your Tribal Sand concierge — arranged and quoted within 24 hours.</p>
      <div class="exp-list">
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Snorkelling · Watamu Marine National Reserve (UNESCO)</div><div class="exp-price">From $40/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Sea Turtle Conservation Watching · Watamu</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Deep Sea Fishing · Full or Half Day</div><div class="exp-price">From $550/boat</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Sunset Dhow Cruise · Watamu Creek</div><div class="exp-price">From $45/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Kiteboarding · Tribal Kite School</div><div class="exp-price">From $130/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Tsavo East National Park · Day Safari</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Yoga &amp; Wellness Sessions</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Beach Dinner</div><div class="exp-price">On Request</div></div>
      </div>
      <div class="exp-cta">
        <a href="activities.php"
           style="display:inline-flex;align-items:center;gap:.5rem;font-size:.82rem;letter-spacing:.2em;text-transform:uppercase;padding:.78rem 1.6rem;background:var(--teal);color:#fff;font-family:'Jost',sans-serif;font-weight:500;transition:background .22s;"
           onmouseover="this.style.background='#102F3A'" onmouseout="this.style.background='#1E5C6B'">All Activities →</a>
        <a href="trip-builder.php"
           style="display:inline-flex;align-items:center;gap:.5rem;font-size:.82rem;letter-spacing:.2em;text-transform:uppercase;padding:.78rem 1.6rem;background:none;border:1px solid var(--border);color:var(--teal);font-family:'Jost',sans-serif;font-weight:400;transition:all .22s;"
           onmouseover="this.style.borderColor='var(--teal)'" onmouseout="this.style.borderColor='var(--border)'">Build Your Itinerary →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Location -->
    <div class="sec">
      <div class="sec-label">Location</div>
      <h2 class="sec-h">Watamu · <em>Kilifi County</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Watamu is one of Kenya's most celebrated coastal destinations — a distinctive, characterful town on the Indian Ocean shoreline, approximately 120 km north of Mombasa. Malindi Airport (MYD) is just 20 minutes away. The Watamu Marine National Reserve, a UNESCO-listed Biosphere Reserve, is right at Zuri's door, offering world-class snorkelling, diving and marine wildlife encounters directly from the beach.</p>
      <div class="map-block">
        <div class="map-info">
          <div class="map-eyebrow">Directions</div>
          <div class="map-h">Watamu · Kilifi County · Kenya</div>
          <div class="map-p">20 min from Malindi Airport (MYD) · ~2 hrs from Mombasa Moi (MBA)</div>
        </div>
        <a href="https://maps.google.com/?q=Watamu+Kenya" target="_blank" rel="noopener" class="btn-map">Open in Google Maps →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- FAQ -->
    <div class="sec" id="faq">
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

    <!-- Other Properties -->
    <div class="sec">
      <div class="sec-label">Explore More</div>
      <h2 class="sec-h">Other <em>Properties</em></h2>
      <div class="sec-rule"></div>
      <div class="other-grid">

        <a href="my-amani.php" class="other-card">
          <img class="other-img" src="images/my-amani/Aerial/myamani-11.webp" alt="My Amani private villa — Vipingo Kenya">
          <div class="other-body">
            <div class="other-loc">Vipingo</div>
            <div class="other-name">My Amani</div>
            <div class="other-meta">5 Bedrooms · Up to 10 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Private Villa</span><span class="other-link">View →</span></div>
        </a>

        <a href="maya-kobe.php" class="other-card">
          <img class="other-img" src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe boutique hotel — Kilifi Kenya">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Maya Kobe</div>
            <div class="other-meta">5 Suites · Up to 12 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Boutique Hotel</span><span class="other-link">View →</span></div>
        </a>

        <a href="trip-builder.php" class="other-card">
          <img class="other-img" src="images/hero-zuri.jpg" alt="Plan your Tribal Sand trip — Kenya's North Coast">
          <div class="other-body">
            <div class="other-loc">Kenya's North Coast</div>
            <div class="other-name">Trip Builder</div>
            <div class="other-meta">Tailored itineraries · All properties</div>
          </div>
          <div class="other-foot"><span class="other-type">Trip Planning</span><span class="other-link">Plan Your Trip →</span></div>
        </a>

      </div>
    </div>

  </div><!-- /page-main -->

  <!-- ══ SIDEBAR ══ -->
  <aside class="page-side">
    <div class="book-card">

      <!-- Header with image -->
      <div class="book-head">
        <div class="book-head-bg"></div>
        <div class="book-head-overlay"></div>
        <div class="book-head-inner">
          <div class="book-eyebrow">Boutique Hotel · Watamu</div>
          <div class="book-name">Zuri</div>
          <div class="book-loc">Watamu · Kilifi County · Kenya</div>
        </div>
      </div>

      <!-- Body -->
      <div class="book-body" style="padding:0">
        <?php $booking_slug = 'zuri'; include __DIR__ . '/includes/booking-widget.php'; ?>
      </div>

      <!-- Policy accordion -->
      <div class="book-pol-toggle">
        <button class="book-pol-btn" id="polBtn">Property Policies <span class="pol-chevron">▾</span></button>
        <div class="book-pol-body" id="polBody">
          <div class="book-pol-row"><span class="book-pol-k">Check-in</span><span class="book-pol-v">2:00 PM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Check-out</span><span class="book-pol-v">10:00 AM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Min Stay</span><span class="book-pol-v">2 nights · 5 peak</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Deposit</span><span class="book-pol-v">USD 500</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Smoking</span><span class="book-pol-v">Non-smoking</span></div>
        </div>
      </div>

      <!-- Features -->
      <div class="book-features">
        <div class="book-feat"><span class="book-feat-ico">·</span> Individual suite or full property buyout</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Chef-led à la carte dining included</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Private pool &amp; direct beachfront</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Airport transfers arranged on request</div>
      </div>

      <!-- WhatsApp -->
      <a href="https://wa.me/254115115247" class="book-whatsapp" target="_blank" rel="noopener">
        <i class="fab fa-whatsapp"></i> Chat on WhatsApp · +254 115 115 247
      </a>

    </div>
  </aside>


</div><!-- /page-wrap -->

<?php include 'includes/footer.php'; ?>

<!-- ═══ MOBILE STICKY CTA ═══ -->
<div class="sticky-cta" id="stickyCta">
  <div>
    <div class="sticky-cta-info">Zuri · Watamu</div>
    <div class="sticky-cta-sub">6 Suites · Up to 14 Guests</div>
  </div>
  <a href="#rrBar" class="sticky-cta-btn">Book Now →</a>
</div>

<!-- ═══ LIGHTBOX ═══ -->
<div class="lb" id="lb">
  <button class="lb-close" onclick="closeLb()">✕</button>
  <img class="lb-img" id="lbImg" src="" alt="">
  <div class="lb-nav">
    <button class="lb-btn" onclick="lbPrev()">← Prev</button>
    <button class="lb-btn" onclick="lbNext()">Next →</button>
  </div>
  <div class="lb-count" id="lbCount"></div>
</div>

<script>
// ── FAQ accordion ──
document.querySelectorAll('.faq-q').forEach(function(q){
  q.addEventListener('click', function(){
    q.closest('.faq-item').classList.toggle('open');
  });
});

// ── Lightbox ──
var IMGS = [
  'images/hero-zuri.jpg',
  'images/hero-zuri.jpg',
  'images/hero-zuri.jpg',
];
var lbIdx = 0;
function openLb(i){
  lbIdx = i;
  document.getElementById('lbImg').src = IMGS[i];
  document.getElementById('lbCount').textContent = (i + 1) + ' / ' + IMGS.length;
  document.getElementById('lb').classList.add('show');
}
function closeLb(){ document.getElementById('lb').classList.remove('show'); }
function lbNext(){ lbIdx = (lbIdx + 1) % IMGS.length; openLb(lbIdx); }
function lbPrev(){ lbIdx = (lbIdx - 1 + IMGS.length) % IMGS.length; openLb(lbIdx); }
document.getElementById('lb').addEventListener('click', function(e){ if(e.target === this) closeLb(); });
document.addEventListener('keydown', function(e){
  if(e.key === 'ArrowRight') lbNext();
  if(e.key === 'ArrowLeft')  lbPrev();
  if(e.key === 'Escape')     closeLb();
});

// ── Scroll reveal ──
var obs = new IntersectionObserver(function(entries){
  entries.forEach(function(e){
    if(e.isIntersecting){
      e.target.style.opacity = '1';
      e.target.style.transform = 'none';
    }
  });
}, { threshold: 0.06 });

document.querySelectorAll('.exp-row,.other-card,.amenity,.faq-item').forEach(function(el, i){
  el.style.opacity = '0';
  el.style.transform = 'translateY(10px)';
  el.style.transition = 'opacity .28s ' + (i * 0.025) + 's ease, transform .28s ' + (i * 0.025) + 's ease';
  obs.observe(el);
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
