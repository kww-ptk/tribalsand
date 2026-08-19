<?php require_once 'includes/schema.php'; ?>
<?php
/* ═══ SEO ═══ */
$page_title   = 'Maya Kobe · Eco Beachfront Boutique Hotel · Kilifi · Tribal Sand';
$page_desc    = 'Eco beachfront boutique hotel in Kilifi, Kenya. Five ocean suites, private pool, à la carte dining and direct beach access at Tribal Dunes, Bofa Beach.';
$page_url     = 'https://tribalsand.com/maya-kobe.php';
$page_image   = asset_url('images/hero-maya-kobe.jpg');
$page_preload = 'images/hero-maya-kobe.jpg';

/* ═══ FAQS ═══ */
$faqs = [
    ['q' => 'What is the Prestige Suite at Maya Kobe?',
     'a' => 'The Prestige Suite is a private two-bedroom suite with its own pool and open-air bathtub, sleeping up to 4 guests. It can be booked independently or as part of a full property buyout.'],
    ['q' => 'Is Maya Kobe part of Tribal Dunes?',
     'a' => 'Yes. Maya Kobe sits at the heart of Tribal Dunes — Kilifi\'s beachfront community. Guests have walking access to Off Duty coworking hotel, Tribal Table restaurant (coming soon), Somewhere Café (coming soon) and the Tribal Kite School.'],
    ['q' => 'Can I book just one suite at Maya Kobe?',
     'a' => 'Yes. Individual suite bookings are available for couples and small groups. The full property can also be bought out for up to 12 guests (16 with the Prestige Suite).'],
    ['q' => 'What is the nearest airport to Maya Kobe Kilifi?',
     'a' => 'Mombasa Moi International Airport (MBA) is approximately 1 hour away. Nairobi JKIA is the main international gateway. Airport transfers are arranged by our team on request.'],
    ['q' => 'What activities are available near Maya Kobe?',
     'a' => 'Kitesurfing is directly accessible via Tribal Kite School Kilifi. Our concierge team arranges deep sea fishing, snorkelling, dhow cruises on Kilifi Creek, safaris to Tsavo and wellness sessions — all quoted within 24 hours.'],
];

/* ═══ SCHEMA ═══ */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_lodging([
    'name'            => 'Maya Kobe Boutique Hotel',
    'description'     => 'Balinese-inspired luxury beachfront boutique hotel within Tribal Dunes, Kilifi. Five ocean suites, 20m pool, chef-led dining on Bofa Beach.',
    'url'             => 'https://tribalsand.com/maya-kobe.php',
    'image'           => [asset_url('images/hero-maya-kobe.jpg')],
    'addressLocality' => 'Kilifi',
    'addressRegion'   => 'Kilifi County',
    'lat'             => -3.6340,
    'lng'             => 39.8503,
    'numberOfRooms'   => 5,
    'priceRange'      => '$$$$',
    'amenities'       => ['20m Pool','Beachfront','À La Carte Dining','Ocean Suites','Balinese Architecture','Massage Huts','Free WiFi'],
]);
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
    ['name' => 'Accommodations', 'url' => 'https://tribalsand.com/#properties'],
    ['name' => 'Maya Kobe',      'url' => 'https://tribalsand.com/maya-kobe.php'],
]);
$page_schema .= ts_schema_faq($faqs);
$page_rooms_rates = true;
$rr_venue_slug = 'maya-kobe';
?>
<?php include 'includes/head.php'; ?>
<style>
/* ── TOKENS ── */
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;--sand-faint:#FAF6EE;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.14);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;overflow-x:hidden;}
body{font-family:'Jost',sans-serif;font-size:1rem;font-weight:400;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;}
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
  font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.22em;
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
.breadcrumb a{font-size:.75rem;letter-spacing:.1em;color:var(--light);transition:color .2s;}
.breadcrumb a:hover{color:var(--teal);}
.breadcrumb-sep{font-size:.75rem;color:rgba(184,150,90,.3);}
.breadcrumb-curr{font-size:.75rem;letter-spacing:.1em;color:var(--sand);}

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:1280px;margin:0 auto;padding:0 52px;display:grid;grid-template-columns:1fr 380px;gap:4rem;padding-top:2.8rem;padding-bottom:6rem;}
.page-main{min-width:0;}
.page-side{position:sticky;top:80px;height:fit-content;}

/* ── LISTING HEADER ── */
.listing-eyebrow{font-size:.75rem;letter-spacing:.32em;text-transform:uppercase;color:var(--sand);margin-bottom:.5rem;display:flex;align-items:center;gap:.55rem;}
.listing-eyebrow::before{content:'';width:16px;height:1px;background:var(--sand);}
.listing-h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.4rem,4vw,3.6rem);font-weight:400;line-height:1;color:var(--dark);margin-bottom:.5rem;}
.listing-h1 em{font-style:italic;color:var(--teal);}
.listing-sub{font-size:.82rem;color:var(--light);display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;}
.listing-sub svg{color:var(--sand);flex-shrink:0;}

/* ── QUICK STATS ── */
.quick-stats{display:flex;gap:0;border:1px solid var(--border);background:var(--white);margin-bottom:2rem;overflow:hidden;}
.qs{flex:1;padding:1rem .8rem;text-align:center;border-right:1px solid var(--border);}
.qs:last-child{border-right:none;}
.qs-n{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:400;color:var(--dark);line-height:1;}
.qs-l{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;color:var(--light);margin-top:.18rem;}

/* ── PILLS ── */
.pill-row{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:2rem;}
.pill{font-size:.75rem;letter-spacing:.1em;text-transform:uppercase;padding:.24rem .65rem;border:1px solid var(--border);color:var(--mid);}
.pill.hi{border-color:rgba(30,92,107,.3);color:var(--teal);background:rgba(30,92,107,.04);}

/* ── SECTION ── */
.sec{margin-bottom:2.8rem;}
.sec-label{font-size:.75rem;letter-spacing:.28em;text-transform:uppercase;color:var(--sand);margin-bottom:.55rem;display:flex;align-items:center;gap:.5rem;}
.sec-label::before{content:'';width:14px;height:1px;background:var(--sand);}
.sec-h{font-family:'Cormorant Garamond',serif;font-size:clamp(1.5rem,2.5vw,2rem);font-weight:400;color:var(--dark);line-height:1.15;margin-bottom:.45rem;}
.sec-h em{font-style:italic;color:var(--teal);}
.sec-rule{width:20px;height:1px;background:var(--sand);margin-bottom:1.2rem;}
.sec-p{font-size:1rem;font-weight:400;line-height:1.92;color:var(--mid);margin-bottom:1rem;}
.divider{height:1px;background:var(--border);margin:2.4rem 0;}

/* ── AMENITIES ── */
.amenities-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--border);overflow:hidden;}
.amenity{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);border-right:1px solid var(--border);font-size:1rem;font-weight:400;color:var(--mid);transition:background .18s;}
.amenity:hover{background:var(--sand-faint);}
.amenity:nth-child(even){border-right:none;}
.amenity:nth-last-child(-n+2){border-bottom:none;}
.amenity-ico{font-size:1rem;flex-shrink:0;width:1.2rem;text-align:center;}

/* ── PHOTO GRID ── */
.photo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;margin-bottom:.5rem;}
.photo-grid img{width:100%;aspect-ratio:4/3;cursor:pointer;transition:opacity .25s,transform .4s;}
.photo-grid img:hover{opacity:.88;transform:scale(1.02);}
.photo-grid-cap{font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);text-align:right;margin-bottom:2rem;}

/* ── SUITES ── */
.suites-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1.4rem;}
.suite-card{border:1px solid var(--border);background:var(--white);overflow:hidden;transition:box-shadow .2s;}
.suite-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.06);}
.suite-card-body{padding:1rem 1.1rem;}
.suite-card-name{font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-weight:400;color:var(--dark);margin-bottom:.2rem;}
.suite-card-meta{font-size:.82rem;color:var(--light);margin-bottom:.5rem;}
.suite-card-desc{font-size:1rem;font-weight:400;color:var(--mid);line-height:1.75;}
.suite-badge{display:inline-block;font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;color:var(--sand);border:1px solid rgba(184,150,90,.3);padding:.18rem .55rem;margin-top:.55rem;}

/* ── EXPERIENCES ── */
.exp-list{display:flex;flex-direction:column;gap:1px;background:var(--border);border:1px solid var(--border);}
.exp-row{background:var(--white);display:flex;align-items:center;gap:1rem;padding:.85rem 1.1rem;transition:background .18s;}
.exp-row:hover{background:var(--sand-faint);}
.exp-ico{font-size:1.1rem;flex-shrink:0;}
.exp-name{font-size:1rem;font-weight:400;color:var(--dark);flex:1;}
.exp-price{font-size:.82rem;color:var(--light);white-space:nowrap;}
.exp-cta{margin-top:1.2rem;}

/* ── TRIBAL DUNES CONTEXT ── */
.td-box{background:var(--teal-d);padding:2.2rem;margin-bottom:2rem;border-left:3px solid var(--sand);}
.td-eyebrow{font-size:.75rem;letter-spacing:.28em;text-transform:uppercase;color:rgba(184,150,90,.6);margin-bottom:.5rem;}
.td-h{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:400;color:#fff;margin-bottom:.6rem;line-height:1.15;}
.td-h em{font-style:italic;color:var(--sand-lt);}
.td-p{font-size:1rem;font-weight:400;color:rgba(255,255,255,.58);line-height:1.82;margin-bottom:1.2rem;}
.td-nodes{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1px;background:rgba(184,150,90,.12);border:1px solid rgba(184,150,90,.12);}
.td-node{background:rgba(255,255,255,.03);padding:.85rem 1rem;transition:background .18s;}
.td-node:hover{background:rgba(184,150,90,.07);}
.td-node-ico{font-size:1.1rem;margin-bottom:.3rem;}
.td-node-name{font-size:.82rem;font-weight:500;color:rgba(255,255,255,.82);margin-bottom:.12rem;}
.td-node-tag{font-size:.75rem;color:rgba(184,150,90,.55);letter-spacing:.05em;}
.td-node-soon{font-size:.75rem;color:rgba(184,150,90,.4);letter-spacing:.1em;}
.td-link{display:inline-flex;align-items:center;gap:.5rem;margin-top:1.2rem;font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;color:var(--sand-lt);border:1px solid rgba(184,150,90,.3);padding:.6rem 1.2rem;transition:all .22s;}
.td-link:hover{background:rgba(184,150,90,.12);border-color:var(--sand);}

/* ── REVIEWS ── */
.review-bar{display:flex;align-items:center;gap:2rem;padding:1.4rem 1.6rem;background:var(--teal-d);margin-bottom:1.2rem;}
.review-score{font-family:'Cormorant Garamond',serif;font-size:3.2rem;font-weight:400;color:#fff;line-height:1;}
.review-stars{color:var(--sand);font-size:.85rem;letter-spacing:.12rem;margin-bottom:.2rem;}
.review-count{font-size:.75rem;color:rgba(255,255,255,.4);letter-spacing:.1em;}
.review-summary-text{font-size:1rem;font-weight:400;color:rgba(255,255,255,.55);line-height:1.75;flex:1;padding-left:2rem;border-left:1px solid rgba(255,255,255,.08);}
.reviews-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);}
.review-card{background:var(--white);padding:1.4rem 1.5rem;border-top:2px solid var(--sand);}
.review-stars-sm{color:var(--sand);font-size:.75rem;letter-spacing:.12rem;margin-bottom:.65rem;}
.review-text{font-family:'Cormorant Garamond',serif;font-size:1rem;font-style:italic;color:var(--mid);line-height:1.75;margin-bottom:.8rem;}
.review-author{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;color:var(--light);}

/* ── FAQ ── */
.faq-item{border-bottom:1px solid var(--border);}
.faq-q{display:flex;justify-content:space-between;align-items:center;padding:1rem 0;font-size:1rem;font-weight:400;color:var(--dark);cursor:pointer;user-select:none;gap:1rem;}
.faq-a{display:none;padding:0 0 1rem;font-size:1rem;font-weight:400;color:var(--mid);line-height:1.88;}
.faq-item.open .faq-a{display:block;}

/* ── MAP ── */
.map-block{background:var(--teal-d);padding:2rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;margin-bottom:2rem;}
.map-eyebrow{font-size:.75rem;letter-spacing:.28em;text-transform:uppercase;color:rgba(184,150,90,.5);margin-bottom:.4rem;}
.map-h{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:400;color:#fff;margin-bottom:.3rem;}
.map-p{font-size:1rem;font-weight:400;color:rgba(255,255,255,.4);line-height:1.7;}
.btn-map{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;padding:.65rem 1.4rem;border:1px solid rgba(184,150,90,.3);color:var(--sand-lt);background:none;cursor:pointer;transition:all .22s;white-space:nowrap;display:inline-block;}
.btn-map:hover{background:var(--sand);border-color:var(--sand);color:var(--teal-d);}

/* ── OTHER PROPERTIES ── */
.other-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem;}
.other-card{background:var(--white);border:1px solid var(--border);overflow:hidden;transition:box-shadow .25s,transform .25s;display:block;}
.other-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.08);transform:translateY(-2px);}
.other-img{width:100%;height:160px;}
.other-body{padding:.9rem 1rem;}
.other-loc{font-size:.75rem;letter-spacing:.22em;text-transform:uppercase;color:var(--sand);margin-bottom:.25rem;}
.other-name{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--dark);margin-bottom:.28rem;}
.other-meta{font-size:.82rem;color:var(--light);}
.other-foot{padding:.7rem 1rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.other-type{font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);}
.other-link{font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;color:var(--teal);}

/* ── SIDEBAR ── */
.book-card{background:var(--white);border:1px solid var(--border);overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.09);max-height:calc(100vh - 100px);overflow-y:auto;}
.book-head{position:relative;overflow:hidden;background:var(--teal-d);padding:0;height:140px;}
.book-head-bg{position:absolute;inset:0;background:url('images/hero-maya-kobe.jpg') center 30%/cover;opacity:.25;}
.book-head-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(16,47,58,.9) 0%,rgba(16,47,58,.3) 100%);}
.book-head-inner{position:relative;z-index:1;padding:1.2rem 1.4rem;height:100%;display:flex;flex-direction:column;justify-content:flex-end;}
.book-eyebrow{font-size:.75rem;letter-spacing:.26em;text-transform:uppercase;color:rgba(184,150,90,.6);margin-bottom:.3rem;}
.book-name{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:400;color:#fff;line-height:1;margin-bottom:.2rem;}
.book-loc{font-size:.82rem;color:rgba(255,255,255,.45);letter-spacing:.06em;}
.book-body{padding:1.3rem 1.4rem 1rem;}
.book-field{margin-bottom:.75rem;}
.book-lbl{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;color:var(--mid);margin-bottom:.3rem;display:block;}
.book-date-val{font-size:.82rem;color:var(--dark);}
.book-inp{width:100%;padding:.65rem .8rem;border:1px solid var(--border);background:var(--off);font-size:.82rem;color:var(--dark);font-family:'Jost',sans-serif;transition:border-color .2s;}
.book-inp:focus{outline:none;border-color:var(--teal);}
select.book-inp{-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23A89880' stroke-width='1.5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .8rem center;}
.book-pol-row{display:flex;justify-content:space-between;align-items:center;padding:.55rem 1.6rem .55rem 1.7rem;border-bottom:1px solid var(--border);font-size:.82rem;}
.book-pol-row:last-child{border-bottom:none;}
.book-pol-toggle{border-top:1px solid var(--border);margin-top:1rem;padding-top:.1rem;}
.book-pol-btn{display:flex;width:100%;justify-content:space-between;align-items:center;padding:.65rem 1.6rem .65rem 1.7rem;background:none;border:none;cursor:pointer;font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mid);font-family:'Jost',sans-serif;font-weight:500;}
.book-pol-btn:hover{color:var(--teal);}
.pol-chevron{transition:transform .22s ease;display:inline-block;}
.book-pol-body{display:none;}
.book-pol-body.open{display:block;}
.book-pol-k{color:var(--light);}
.book-pol-v{color:var(--dark);font-weight:500;text-align:right;}
.btn-book-full{display:block;width:100%;padding:.92rem;font-size:.75rem;letter-spacing:.2em;text-transform:uppercase;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;transition:background .22s;font-family:'Jost',sans-serif;font-weight:500;text-align:center;}
.btn-book-full:hover{background:var(--sand-lt);}
.btn-ghost-full{display:block;width:100%;padding:.8rem;font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;background:none;border:1px solid var(--border);color:var(--teal);cursor:pointer;transition:all .22s;margin-top:.5rem;font-family:'Jost',sans-serif;text-align:center;}
.btn-ghost-full:hover{border-color:var(--teal);background:rgba(30,92,107,.04);}
.book-note{font-size:.82rem;color:var(--light);text-align:center;margin-top:.7rem;line-height:1.65;padding:0 .5rem;}

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
.book-feat{display:flex;align-items:center;gap:.55rem;font-size:.82rem;color:var(--mid);}
.book-feat-ico{color:var(--teal);font-size:.75rem;flex-shrink:0;width:14px;text-align:center;}
.book-whatsapp{display:flex;align-items:center;justify-content:center;gap:.6rem;padding:.85rem 1.4rem;border-top:1px solid var(--border);background:var(--white);font-size:.82rem;color:var(--teal);font-weight:500;transition:background .2s;}
.book-whatsapp:hover{background:rgba(30,92,107,.04);}
.book-whatsapp i{font-size:.95rem;color:#25D366;}

/* ── STICKY CTA (mobile) ── */
.sticky-cta{
  display:none;position:fixed;bottom:0;left:0;right:0;z-index:400;
  background:var(--white);border-top:1px solid var(--border);
  padding:.9rem 1.2rem;align-items:center;justify-content:space-between;gap:.75rem;
}
.sticky-cta-info{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:400;color:var(--dark);}
.sticky-cta-sub{font-size:.82rem;color:var(--light);}
.sticky-cta-btn{font-size:.75rem;letter-spacing:.2em;text-transform:uppercase;padding:.75rem 1.4rem;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;font-family:'Jost',sans-serif;font-weight:500;white-space:nowrap;}

/* ── LIGHTBOX ── */
.lb{display:none;position:fixed;inset:0;z-index:9999;background:rgba(5,15,20,.96);flex-direction:column;align-items:center;justify-content:center;}
.lb.show{display:flex;}
.lb-close{position:absolute;top:1.4rem;right:1.8rem;font-size:1.4rem;color:rgba(255,255,255,.5);cursor:pointer;background:none;border:none;}
.lb-img{max-width:92vw;max-height:84vh;object-fit:contain;}
.lb-nav{display:flex;gap:1.2rem;margin-top:1.2rem;}
.lb-btn{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.45);background:none;border:1px solid rgba(255,255,255,.12);padding:.5rem 1.2rem;cursor:pointer;transition:all .2s;font-family:'Jost',sans-serif;}
.lb-btn:hover{color:#fff;border-color:rgba(255,255,255,.4);}
.lb-count{font-size:.75rem;color:rgba(255,255,255,.22);margin-top:.6rem;letter-spacing:.12em;}

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
  .suites-grid{grid-template-columns:1fr;}
  .reviews-grid{grid-template-columns:1fr;}
  .other-grid{grid-template-columns:1fr 1fr;}
  .td-nodes{grid-template-columns:1fr 1fr;}
}
@media(max-width:480px){
  .other-grid{grid-template-columns:1fr;}
  .td-nodes{grid-template-columns:1fr;}
}
</style>
</head>
<body class="ts-nav-transparent">

<?php include 'includes/header.php'; ?>

<!-- ═══ GALLERY ═══ -->
<?php
$pg_venue_slug   = 'maya-kobe';
$pg_fallback_badge = 'Maya Kobe · Bofa Road · Kilifi';
$pg_fallback = [
  ['src' => 'images/hero-maya-kobe.jpg', 'alt' => 'Maya Kobe boutique hotel — Bofa Beach, Kilifi'],
  ['src' => 'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg', 'alt' => 'Maya Kobe pool and beach'],
  ['src' => 'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg', 'alt' => 'Maya Kobe outdoor lounge'],
];
include __DIR__ . '/includes/property-gallery.php';
?>

<!-- ═══ BREADCRUMB ═══ -->
<nav class="breadcrumb" aria-label="Breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">›</span>
  <a href="./#properties">Accommodations</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-curr">Maya Kobe</span>
</nav>

<!-- ═══ PAGE WRAP ═══ -->
<div class="page-wrap">

  <!-- ══ MAIN ══ -->
  <div class="page-main">

    <!-- Header -->
    <div class="listing-eyebrow"><?= e(ts_venue_text('maya-kobe', 'tagline', 'Luxury Beachfront Boutique Hotel · Balinese-Inspired')) ?></div>
    <h1 class="listing-h1">Maya Kobe · Eco Beachfront Boutique Hotel · <em>Kilifi</em></h1>
    <div class="listing-sub">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="2.5"/><path d="M8 1.5C5.24 1.5 3 3.74 3 6.5c0 4 5 8.5 5 8.5s5-4.5 5-8.5c0-2.76-2.24-5-5-5z"/></svg>
      Bofa Road · Kilifi · Kenya's North Coast · Within Tribal Dunes
    </div>

    <!-- Quick stats -->
    <div class="quick-stats">
      <div class="qs"><div class="qs-n">5</div><div class="qs-l">Ocean Suites</div></div>
      <div class="qs"><div class="qs-n">12</div><div class="qs-l">Guests</div></div>
      <div class="qs"><div class="qs-n">6</div><div class="qs-l">Bathrooms</div></div>
      <div class="qs"><div class="qs-n">20m</div><div class="qs-l">Pool</div></div>
      <div class="qs"><div class="qs-n">∞</div><div class="qs-l">Ocean Views</div></div>
    </div>

    <!-- Pills -->
    <div class="pill-row">
      <span class="pill hi">Direct Beachfront</span>
      <span class="pill hi">20m Pool</span>
      <span class="pill hi">Balinese Architecture</span>
      <span class="pill">À La Carte Dining</span>
      <span class="pill">Chef-Led Dining</span>
      <span class="pill">Prestige Suite</span>
      <span class="pill">Private Pool Suite</span>
      <span class="pill">Beach Massage Huts</span>
      <span class="pill">Ocean Gazebo</span>
      <span class="pill">Full Buyout Available</span>
      <span class="pill">Free Wi-Fi</span>
      <span class="pill">Air Conditioning</span>
    </div>

    <div class="divider"></div>

    <!-- About (editable in admin → Properties → Page Content; falls back to text below) -->
    <?php
    $va_slug = 'maya-kobe';
    $va_heading_fallback = 'Best Boutique Hotel on <em>Bofa Beach, Kilifi</em>';
    $va_body_fallback = "Maya Kobe is a Balinese-inspired luxury boutique hotel sitting directly on Bofa Beach in Kilifi — one of Kenya's most breathtaking stretches of coastline. Five ocean suites are wrapped in rich textures, natural materials and panoramic Indian Ocean views, creating a setting that feels both intimate and effortlessly indulgent.\n\nAt the heart of the property, a 20-metre beachfront swimming pool leads directly onto the white sand beach. A spacious gazebo hangs over the water's edge, while private beachfront massage huts offer wellness without leaving the estate. Every detail — from the Balinese craftsmanship to the chef-led dining — is curated to surpass expectation.\n\nMaya Kobe is available for individual suite stays — perfect for couples and intimate groups — or as a full property buyout for up to 12 guests. The Prestige Suite, a self-contained two-bedroom sanctuary with its own private pool and open-air bathtub, adds another four guests to make a total of 16 when the whole estate is yours.";
    include __DIR__ . '/includes/venue-about.php';
    ?>

    <div class="divider"></div>

    <!-- Rooms & Availability (same as Zuri) -->
    <?php $rr_venue_slug = 'maya-kobe'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>

    <div class="divider"></div>

    <!-- Amenities -->
    <div class="sec">
      <div class="sec-label">Amenities & Features</div>
      <h2 class="sec-h">Everything <em>Included</em></h2>
      <div class="sec-rule"></div>
      <div class="amenities-grid">
        <div class="amenity"> Direct beachfront access · Bofa Beach</div>
        <div class="amenity"> 20m beachfront swimming pool</div>
        <div class="amenity"> À la carte menu · All-day dining</div>
        <div class="amenity"> Chef-led dining experience</div>
        <div class="amenity"> Five individual ocean suites</div>
        <div class="amenity"> Full property buyout available</div>
        <div class="amenity"> Beachfront private massage huts</div>
        <div class="amenity"> Balinese-inspired architecture</div>
        <div class="amenity"> Ocean gazebo · Indian Ocean views</div>
        <div class="amenity"> Prestige Suite private pool</div>
        <div class="amenity"> Prestige Suite open-air bathtub</div>
        <div class="amenity"> Daily housekeeping · Full service</div>
        <div class="amenity"> Free Wi-Fi throughout</div>
        <div class="amenity"> Air conditioning · All suites</div>
        <div class="amenity"> Concierge experiences on request</div>
        <div class="amenity"> Airport transfers available</div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Photo Gallery -->
    <div class="sec">
      <div class="sec-label">Photo Gallery</div>
      <h2 class="sec-h">Explore <em>Maya Kobe</em></h2>
      <div class="sec-rule"></div>
      <div class="photo-grid">
        <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg" alt="Maya Kobe pool" onclick="openLb(1)">
        <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg" alt="Maya Kobe beach" onclick="openLb(2)">
        <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best12.jpg" alt="Maya Kobe outdoors" onclick="openLb(3)">
        <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best14.jpg" alt="Maya Kobe ocean view" onclick="openLb(4)">
        <img src="images/hero-maya-kobe.jpg" alt="Maya Kobe hero" onclick="openLb(0)">
        <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best12.jpg" alt="Maya Kobe terrace" onclick="openLb(3)">
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge · 5 photos total</div>
    </div>

    <div class="divider"></div>

    <!-- Tribal Dunes Context -->
    <div class="sec" id="tribal-dunes">
      <div class="sec-label">The Bigger Picture</div>
      <h2 class="sec-h">Part of <em>Tribal Dunes</em></h2>
      <div class="sec-rule"></div>
      <div class="td-box">
        <div class="td-eyebrow">Tribal Dunes · Kilifi Beachfront Ecosystem</div>
        <div class="td-h">More Than a Hotel — <em>A Community</em></div>
        <p class="td-p">Maya Kobe sits at the centre of Tribal Dunes — Kilifi's integrated beachfront lifestyle destination. Step beyond the hotel and you are already inside an ecosystem of properties, dining, coworking and ocean sport, all within walking distance. Kenya as it was meant to be experienced.</p>
        <div class="td-nodes">
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">H</span></div>
            <div class="td-node-name">Maya Kobe</div>
            <div class="td-node-tag">Boutique Hotel · You are here</div>
          </div>
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">W</span></div>
            <div class="td-node-name">Maya Ilai</div>
            <div class="td-node-tag">Eco Compound · Kilifi</div>
          </div>
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">WK</span></div>
            <div class="td-node-name">Off Duty</div>
            <div class="td-node-tag">Coworking Hotel · Kilifi</div>
          </div>
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">F</span></div>
            <div class="td-node-name">Tribal Table</div>
            <div class="td-node-tag"><span class="td-node-soon">Coming Soon</span></div>
          </div>
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">C</span></div>
            <div class="td-node-name">Somewhere Café</div>
            <div class="td-node-tag"><span class="td-node-soon">Coming Soon</span></div>
          </div>
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">S</span></div>
            <div class="td-node-name">Tribal Kite School</div>
            <div class="td-node-tag">Ocean Sports · Kilifi</div>
          </div>
        </div>
        <a href="tribal-dunes.php" class="td-link">Discover Tribal Dunes →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Experiences -->
    <div class="sec">
      <div class="sec-label">Nearby Experiences</div>
      <h2 class="sec-h">Your Home Base for <em>Adventure</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p" style="margin-bottom:1.2rem;">All activities are arranged by your Tribal Sand concierge and quoted within 24 hours of request.</p>
      <div class="exp-list">
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Kitesurfing · Tribal Kite School Kilifi</div><div class="exp-price">From $130/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Snorkelling · Kilifi Reef & Marine Life</div><div class="exp-price">From $40/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Deep Sea Fishing · Full or Half Day</div><div class="exp-price">From $550/boat</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Sunset Dhow Cruise · Kilifi Creek</div><div class="exp-price">From $45/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Tsavo East · Day Safari</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Yoga & Wellness Sessions</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Beach Dinner</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Beachfront Spa & Massage · On-site</div><div class="exp-price">On Request</div></div>
      </div>
      <div class="exp-cta">
        <a href="trip-builder.php" style="display:inline-flex;align-items:center;gap:.5rem;font-size:.75rem;letter-spacing:.2em;text-transform:uppercase;padding:.78rem 1.6rem;background:var(--teal);color:#fff;font-family:'Jost',sans-serif;transition:background .22s;" onmouseover="this.style.background='#102F3A'" onmouseout="this.style.background='#1E5C6B'">Build Your Itinerary →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Location -->
    <div class="sec">
      <div class="sec-label">Location</div>
      <h2 class="sec-h">Bofa Road · <em>Kilifi</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Maya Kobe occupies a privileged position on Bofa Beach, one of Kilifi's finest stretches of coastline. The town of Kilifi — with its iconic creek bridge and vibrant local character — is minutes away, while Mombasa Moi International Airport is approximately one hour by road. Nairobi JKIA connects international arrivals in under 90 minutes by air.</p>
      <div class="map-block">
        <div class="map-info">
          <div class="map-eyebrow">Directions</div>
          <div class="map-h">Bofa Road · Kilifi · Kilifi County · Kenya</div>
          <div class="map-p">~1 hr from Mombasa Moi International (MBA) · Nairobi JKIA direct air link</div>
        </div>
        <a href="https://maps.google.com/?q=Maya+Kobe+Kilifi+Kenya" target="_blank" class="btn-map">Open in Google Maps →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Reviews -->
    <div class="sec">
      <div class="sec-label">Guest Reviews</div>
      <h2 class="sec-h">What Our <em>Guests Say</em></h2>
      <div class="sec-rule"></div>
      <div class="review-bar">
        <div>
          <div class="review-score">5.0</div>
          <div class="review-stars">★★★★★</div>
          <div class="review-count">Verified guest stays</div>
        </div>
        <div class="review-summary-text">Guests at Maya Kobe consistently praise the Balinese atmosphere, the exceptional pool, the attentive service and the effortless sense of escape — all within a vibrant beachfront ecosystem unique to Kilifi.</div>
      </div>
      <div class="reviews-grid">
        <div class="review-card">
          <div class="review-stars-sm">★★★★★</div>
          <div class="review-text">"Absolutely magical. The Balinese design, the sound of the ocean from our suite, the pool at golden hour — we never wanted to leave. Staff went above and beyond every single day."</div>
          <div class="review-author">— Amara & David K.</div>
        </div>
        <div class="review-card">
          <div class="review-stars-sm">★★★★★</div>
          <div class="review-text">"We took the Prestige Suite for our anniversary and it was beyond perfect. The private pool and open-air bath looking out to the Indian Ocean — nothing compares."</div>
          <div class="review-author">— Sophie M.</div>
        </div>
        <div class="review-card">
          <div class="review-stars-sm">★★★★★</div>
          <div class="review-text">"Kilifi's hidden gem. The whole Tribal Dunes experience — kite school, the café, the hotel — felt like discovering a place the world hasn't found yet. Truly special."</div>
          <div class="review-author">— Tarquin & Lex</div>
        </div>
        <div class="review-card">
          <div class="review-stars-sm">★★★★★</div>
          <div class="review-text">"We booked the full property for a boutique wedding celebration. The team executed everything flawlessly. Our guests are still talking about it months later."</div>
          <div class="review-author">— Farai & Nadia</div>
        </div>
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

    <!-- Other Properties -->
    <div class="sec">
      <div class="sec-label">Explore More</div>
      <h2 class="sec-h">Other <em>Properties</em></h2>
      <div class="sec-rule"></div>
      <div class="other-grid">
        <a href="my-amani.php" class="other-card">
          <img class="other-img" src="images/my-amani/Aerial/myamani-11.webp" alt="My Amani private villa Vipingo">
          <div class="other-body">
            <div class="other-loc">Vipingo</div>
            <div class="other-name">My Amani</div>
            <div class="other-meta">5 Bedrooms · Up to 10 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Private Villa</span><span class="other-link">View →</span></div>
        </a>
        <a href="zuri.php" class="other-card">
          <img class="other-img" src="images/zuri/Aerial/zuri-3.webp" alt="Zuri boutique hotel Watamu">
          <div class="other-body">
            <div class="other-loc">Watamu</div>
            <div class="other-name">Zuri</div>
            <div class="other-meta">6 Suites · Up to 14 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Boutique Hotel</span><span class="other-link">View →</span></div>
        </a>
        <a href="tribal-dunes.php" class="other-card">
          <img class="other-img" src="images/hero-maya-kobe.jpg" alt="Tribal Dunes Kilifi ecosystem">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Tribal Dunes</div>
            <div class="other-meta">Beachfront Ecosystem · Kilifi</div>
          </div>
          <div class="other-foot"><span class="other-type">Community</span><span class="other-link">Discover →</span></div>
        </a>
      </div>
    </div>

  </div><!-- /page-main -->

  <!-- ══ SIDEBAR ══ -->
  <aside class="page-side" id="book" style="scroll-margin-top:90px">
    <div class="book-card">

      <!-- Header image -->
      <div class="book-head">
        <div class="book-head-bg"></div>
        <div class="book-head-overlay"></div>
        <div class="book-head-inner">
          <div class="book-eyebrow">Boutique Hotel · Kilifi</div>
          <div class="book-name">Maya Kobe</div>
          <div class="book-loc">Bofa Road · Kilifi · Kenya</div>
        </div>
      </div>

      <!-- Body -->
      <div class="book-body" style="padding:0">
        <?php $booking_slug = 'maya-kobe-prestige'; include __DIR__ . '/includes/booking-widget.php'; ?>
      </div>

      <!-- Policy accordion -->
      <div class="book-pol-toggle">
        <button class="book-pol-btn" id="polBtn">Property Policies <span class="pol-chevron"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span></button>
        <div class="book-pol-body" id="polBody">
          <div class="book-pol-row"><span class="book-pol-k">Check-in</span><span class="book-pol-v">From 2:00 PM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Check-out</span><span class="book-pol-v">By 10:00 AM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Min. Stay</span><span class="book-pol-v">2 nights</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Deposit</span><span class="book-pol-v">USD 500</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Smoking</span><span class="book-pol-v">Non-smoking</span></div>
        </div>
      </div>

      <!-- Features -->
      <div class="book-features">
        <div class="book-feat"><span class="book-feat-ico">·</span> Individual suite or full buyout</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> 20m beachfront pool included</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Chef-led dining on request</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Tribal Dunes ecosystem access</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Airport transfers available</div>
      </div>

      <!-- WhatsApp -->
      <a href="https://wa.me/254115115247" class="book-whatsapp">
        <i class="fab fa-whatsapp"></i> Chat on WhatsApp · +254 115 115 247
      </a>

    </div>
  </aside>

</div><!-- /page-wrap -->

<?php
$sbb_name = 'Maya Kobe';
$sbb_loc  = 'Bofa Road · Kilifi';
$sbb_meta = '5 Suites · Up to 12 guests';
$sbb_cta  = 'Check Availability';
include __DIR__ . '/includes/sticky-book-bar.php';
?>

<?php include 'includes/footer.php'; ?>

<!-- Mobile sticky CTA -->
<div class="sticky-cta" id="stickyCta">
  <div>
    <div class="sticky-cta-info">Maya Kobe · Kilifi</div>
    <div class="sticky-cta-sub">5 Ocean Suites · Up to 16 guests</div>
  </div>
  <a href="#book" class="sticky-cta-btn">Book →</a>
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
// FAQ accordion
document.querySelectorAll('.faq-q').forEach(function(q){
  q.addEventListener('click', function(){
    q.closest('.faq-item').classList.toggle('open');
  });
});

// Lightbox images
var IMGS = [
  'images/hero-maya-kobe.jpg',
  'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg',
  'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg',
  'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best12.jpg',
  'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best14.jpg',
];
var lbIdx = 0;
function openLb(i) {
  lbIdx = i;
  document.getElementById('lbImg').src = IMGS[i];
  document.getElementById('lbCount').textContent = (i + 1) + ' / ' + IMGS.length;
  document.getElementById('lb').classList.add('show');
}
function closeLb() { document.getElementById('lb').classList.remove('show'); }
function lbNext() { lbIdx = (lbIdx + 1) % IMGS.length; openLb(lbIdx); }
function lbPrev() { lbIdx = (lbIdx - 1 + IMGS.length) % IMGS.length; openLb(lbIdx); }
document.getElementById('lb').addEventListener('click', function(e){ if(e.target === this) closeLb(); });
document.addEventListener('keydown', function(e){
  if(e.key === 'ArrowRight') lbNext();
  if(e.key === 'ArrowLeft')  lbPrev();
  if(e.key === 'Escape')     closeLb();
});

// Scroll reveal
var obs = new IntersectionObserver(function(entries){
  entries.forEach(function(e){
    if(e.isIntersecting){ e.target.style.opacity='1'; e.target.style.transform='none'; }
  });
}, {threshold: 0.06});
document.querySelectorAll('.exp-row,.review-card,.other-card,.amenity,.faq-item,.suite-card,.td-node').forEach(function(el, i){
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
