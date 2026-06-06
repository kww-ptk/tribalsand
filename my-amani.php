<?php
require_once 'includes/schema.php';

/* ── SEO ── */
$page_title  = 'My Amani · Luxury Private Beachfront Villa · Vipingo Kenya · Tribal Sand';
$page_desc   = 'Luxury private beachfront villa in Vipingo, Kenya. Five bedrooms, infinity pool, hot tub, private chef. Exclusive use only. Book direct.';
$page_url    = 'https://tribalsand.com/my-amani.php';
$page_image  = 'https://tribalsand.com/images/my-amani/Aerial/myamani-11.webp';
$page_preload = 'images/my-amani/Aerial/myamani-11.webp';

/* ── SCHEMA ── */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_lodging([
    'name'            => 'My Amani Private Villa',
    'description'     => 'Ultra-private five-bedroom beachfront villa in Vipingo, Kenya. Infinity pool, private hot tub, chef on request. Entire property rental only.',
    'url'             => 'https://tribalsand.com/my-amani.php',
    'image'           => ['https://tribalsand.com/images/my-amani/Aerial/myamani-11.webp'],
    'addressLocality' => 'Vipingo',
    'addressRegion'   => 'Kilifi County',
    'lat'             => -3.8200,
    'lng'             => 39.7900,
    'numberOfRooms'   => 5,
    'priceRange'      => '$$$$',
    'amenities'       => ['Infinity Pool','Hot Tub','Private Chef','Beachfront','24-Hour Security','Free WiFi','Air Conditioning','Daily Housekeeping'],
]);
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
    ['name' => 'Accommodations', 'url' => 'https://tribalsand.com/#properties'],
    ['name' => 'My Amani',       'url' => 'https://tribalsand.com/my-amani.php'],
]);
$page_schema .= ts_schema_faq([
    ['q' => 'Can I book My Amani for a partial group?',           'a' => 'No. My Amani is available as an entire property only — all 5 bedrooms for up to 10 guests. This ensures complete privacy for every stay.'],
    ['q' => 'Is a private chef included at My Amani?',           'a' => 'A private chef is available on request at an additional cost. The villa also has a fully-equipped kitchen for self-catering.'],
    ['q' => 'What is the conservation project at My Amani?',     'a' => "My Amani's beach is an active nesting ground for endangered sea turtles. The property operates a year-round conservation programme with protected nesting areas and regular beach cleanups."],
    ['q' => 'How far is Vipingo Ridge Golf Course from My Amani?','a' => 'Vipingo Ridge — an 18-hole PGA-accredited course — is approximately 5 minutes from My Amani. We can arrange tee times through our concierge team.'],
    ['q' => 'What is the security deposit for My Amani?',        'a' => 'A refundable security deposit of USD 500 applies to all bookings. Check-in is 2:00 PM and check-out is 10:00 AM.'],
]);

$page_rooms_rates = true;
$rr_venue_slug = 'my-amani';
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
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;overflow-x:hidden;font-size:1rem;font-weight:400;}
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

/* ── SECTION ── */
.sec{margin-bottom:2.8rem;}
.sec-label{font-size:.55rem;letter-spacing:.28em;text-transform:uppercase;color:var(--sand);margin-bottom:.55rem;display:flex;align-items:center;gap:.5rem;}
.sec-label::before{content:'';width:14px;height:1px;background:var(--sand);}
.sec-h{font-family:'Cormorant Garamond',serif;font-size:clamp(1.5rem,2.5vw,2rem);font-weight:300;color:var(--dark);line-height:1.15;margin-bottom:.45rem;}
.sec-h em{font-style:italic;color:var(--teal);}
.sec-rule{width:20px;height:1px;background:var(--sand);margin-bottom:1.2rem;}
.sec-p{font-size:1rem;line-height:1.92;color:var(--mid);margin-bottom:1rem;font-weight:400;}
.divider{height:1px;background:var(--border);margin:2.4rem 0;}

/* ── CONSERVATION HIGHLIGHT ── */
.conservation-block{
  background:rgba(30,92,107,.05);
  border:1px solid rgba(30,92,107,.18);
  border-left:3px solid var(--teal);
  padding:1.4rem 1.6rem;
  margin-bottom:1.2rem;
  display:flex;align-items:flex-start;gap:1rem;
}
.conservation-icon{font-size:1.6rem;flex-shrink:0;line-height:1;}
.conservation-body{}
.conservation-label{font-size:.5rem;letter-spacing:.28em;text-transform:uppercase;color:var(--teal);margin-bottom:.3rem;}
.conservation-h{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--teal-d);margin-bottom:.4rem;}
.conservation-p{font-size:1rem;line-height:1.85;color:var(--mid);font-weight:400;}

/* ── AMENITIES ── */
.amenities-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--border);overflow:hidden;}
.amenity{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);border-right:1px solid var(--border);font-size:1rem;color:var(--mid);transition:background .18s;font-weight:400;}
.amenity:hover{background:var(--sand-faint);}
.amenity:nth-child(even){border-right:none;}
.amenity:nth-last-child(-n+2){border-bottom:none;}
.amenity-ico{font-size:1rem;flex-shrink:0;width:1.2rem;text-align:center;}

/* ── PHOTO GRID ── */
.photo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;margin-bottom:.5rem;}
.photo-grid img{width:100%;aspect-ratio:4/3;cursor:pointer;transition:opacity .25s,transform .4s;}
.photo-grid img:hover{opacity:.88;transform:scale(1.02);}
.photo-grid-cap{font-size:.55rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);text-align:right;margin-bottom:2rem;}

/* ── EXPERIENCES ── */
.exp-list{display:flex;flex-direction:column;gap:1px;background:var(--border);border:1px solid var(--border);}
.exp-row{background:var(--white);display:flex;align-items:center;gap:1rem;padding:.85rem 1.1rem;transition:background .18s;}
.exp-row:hover{background:var(--sand-faint);}
.exp-ico{font-size:1.1rem;flex-shrink:0;}
.exp-name{font-size:1rem;color:var(--dark);flex:1;font-weight:400;}
.exp-price{font-size:.64rem;color:var(--light);white-space:nowrap;}
.exp-cta{margin-top:1.2rem;}

/* ── REVIEWS ── */
.review-bar{display:flex;align-items:center;gap:2rem;padding:1.4rem 1.6rem;background:var(--teal-d);margin-bottom:1.2rem;}
.review-score{font-family:'Cormorant Garamond',serif;font-size:3.2rem;font-weight:300;color:#fff;line-height:1;}
.review-stars{color:var(--sand);font-size:.85rem;letter-spacing:.12rem;margin-bottom:.2rem;}
.review-count{font-size:.6rem;color:rgba(255,255,255,.4);letter-spacing:.1em;}
.review-summary-text{font-size:1rem;color:rgba(255,255,255,.55);line-height:1.75;flex:1;padding-left:2rem;border-left:1px solid rgba(255,255,255,.08);font-weight:400;}
.reviews-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);}
.review-card{background:var(--white);padding:1.4rem 1.5rem;border-top:2px solid var(--sand);}
.review-stars-sm{color:var(--sand);font-size:.6rem;letter-spacing:.12rem;margin-bottom:.65rem;}
.review-text{font-family:'Cormorant Garamond',serif;font-size:.96rem;font-style:italic;color:var(--mid);line-height:1.75;margin-bottom:.8rem;}
.review-author{font-size:.54rem;letter-spacing:.18em;text-transform:uppercase;color:var(--light);}

/* ── FAQ ── */
.faq-item{border-bottom:1px solid var(--border);}
.faq-q{display:flex;justify-content:space-between;align-items:center;padding:1rem 0;font-size:1rem;font-weight:400;color:var(--dark);cursor:pointer;user-select:none;gap:1rem;}
.faq-ico{color:var(--sand);font-size:1.1rem;transition:transform .22s;flex-shrink:0;}
.faq-item.open .faq-ico{transform:rotate(45deg);}
.faq-a{display:none;padding:0 0 1rem;font-size:1rem;color:var(--mid);line-height:1.88;font-weight:400;}
.faq-item.open .faq-a{display:block;}

/* ── MAP ── */
.map-block{background:var(--teal-d);padding:2rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;margin-bottom:2rem;}
.map-eyebrow{font-size:.5rem;letter-spacing:.28em;text-transform:uppercase;color:rgba(184,150,90,.5);margin-bottom:.4rem;}
.map-h{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:300;color:#fff;margin-bottom:.3rem;}
.map-p{font-size:1rem;color:rgba(255,255,255,.4);line-height:1.7;font-weight:400;}
.btn-map{font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;padding:.65rem 1.4rem;border:1px solid rgba(184,150,90,.3);color:var(--sand-lt);background:none;cursor:pointer;transition:all .22s;white-space:nowrap;display:inline-block;}
.btn-map:hover{background:var(--sand);border-color:var(--sand);color:var(--teal-d);}

/* ── NEARBY ── */
.nearby-list{display:flex;flex-direction:column;gap:1px;background:var(--border);border:1px solid var(--border);}
.nearby-row{background:var(--white);display:flex;align-items:center;gap:1rem;padding:.85rem 1.1rem;}
.nearby-ico{font-size:1rem;flex-shrink:0;}
.nearby-name{font-size:1rem;color:var(--dark);flex:1;font-weight:400;}
.nearby-dist{font-size:.64rem;color:var(--light);white-space:nowrap;}

/* ── OTHER PROPERTIES ── */
.other-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem;}
.other-card{background:var(--white);border:1px solid var(--border);overflow:hidden;transition:box-shadow .25s,transform .25s;}
.other-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.08);transform:translateY(-2px);}
.other-img{width:100%;height:160px;}
.other-body{padding:.9rem 1rem;}
.other-loc{font-size:.5rem;letter-spacing:.22em;text-transform:uppercase;color:var(--sand);margin-bottom:.25rem;}
.other-name{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:400;color:var(--dark);margin-bottom:.28rem;}
.other-meta{font-size:.62rem;color:var(--light);}
.other-foot{padding:.7rem 1rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.other-type{font-size:.5rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);}
.other-link{font-size:.54rem;letter-spacing:.12em;text-transform:uppercase;color:var(--teal);}

/* ── SIDEBAR ── */
.book-card{background:var(--white);border:1px solid var(--border);overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.09);}
.book-head{position:relative;overflow:hidden;background:var(--teal-d);padding:0;height:140px;}
.book-head-bg{position:absolute;inset:0;background:url('images/my-amani/Aerial/myamani-11.webp') center 30%/cover;opacity:.25;}
.book-head-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(16,47,58,.9) 0%,rgba(16,47,58,.3) 100%);}
.book-head-inner{position:relative;z-index:1;padding:1.2rem 1.4rem;height:100%;display:flex;flex-direction:column;justify-content:flex-end;}
.book-eyebrow{font-size:.48rem;letter-spacing:.26em;text-transform:uppercase;color:rgba(184,150,90,.6);margin-bottom:.3rem;}
.book-name{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:300;color:#fff;line-height:1;margin-bottom:.2rem;}
.book-loc{font-size:.58rem;color:rgba(255,255,255,.45);letter-spacing:.06em;}
.book-body{padding:1.3rem 1.4rem 1rem;}
.book-field{margin-bottom:.75rem;}
.book-lbl{font-size:.5rem;letter-spacing:.18em;text-transform:uppercase;color:var(--mid);margin-bottom:.3rem;display:block;}
.book-date-val{font-size:1rem;color:var(--dark);font-weight:400;}
.book-inp{width:100%;padding:.65rem .8rem;border:1px solid var(--border);background:var(--off);font-size:1rem;color:var(--dark);font-family:'Jost',sans-serif;transition:border-color .2s;font-weight:400;}
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
.btn-book-full{display:block;width:100%;padding:.92rem;font-size:.62rem;letter-spacing:.2em;text-transform:uppercase;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;transition:background .22s;font-family:'Jost',sans-serif;font-weight:500;text-align:center;}
.btn-book-full:hover{background:var(--sand-lt);}
.btn-ghost-full{display:block;width:100%;padding:.8rem;font-size:.6rem;letter-spacing:.18em;text-transform:uppercase;background:none;border:1px solid var(--border);color:var(--teal);cursor:pointer;transition:all .22s;margin-top:.5rem;font-family:'Jost',sans-serif;text-align:center;}
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
.book-feat{display:flex;align-items:center;gap:.55rem;font-size:1rem;color:var(--mid);font-weight:400;}
.book-feat-ico{color:var(--teal);font-size:.65rem;flex-shrink:0;width:14px;text-align:center;}
.book-whatsapp{display:flex;align-items:center;justify-content:center;gap:.6rem;padding:.85rem 1.4rem;border-top:1px solid var(--border);background:var(--white);font-size:1rem;color:var(--teal);font-weight:500;transition:background .2s;}
.book-whatsapp:hover{background:rgba(30,92,107,.04);}
.book-whatsapp i{font-size:.95rem;color:#25D366;}

/* ── STICKY CTA (mobile) ── */
.sticky-cta{
  display:none;position:fixed;bottom:0;left:0;right:0;z-index:400;
  background:var(--white);border-top:1px solid var(--border);
  padding:.9rem 1.2rem;
  align-items:center;justify-content:space-between;gap:.75rem;
}
.sticky-cta-info{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:300;color:var(--dark);}
.sticky-cta-sub{font-size:.6rem;color:var(--light);}
.sticky-cta-btn{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;padding:.75rem 1.4rem;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;font-family:'Jost',sans-serif;font-weight:500;white-space:nowrap;display:inline-block;}

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
  .reviews-grid{grid-template-columns:1fr;}
  .other-grid{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body class="ts-nav-transparent">

<?php include 'includes/header.php'; ?>

<!-- ═══ GALLERY ═══ -->
<?php $pg_venue_slug = 'my-amani'; include __DIR__ . '/includes/property-gallery.php'; ?>

<!-- ═══ BREADCRUMB ═══ -->
<nav class="breadcrumb" aria-label="Breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep" aria-hidden="true">›</span>
  <a href="properties.php">Accommodations</a>
  <span class="breadcrumb-sep" aria-hidden="true">›</span>
  <span class="breadcrumb-curr" aria-current="page">My Amani</span>
</nav>

<!-- ═══ PAGE WRAP ═══ -->
<div class="page-wrap">

  <!-- ══ MAIN ══ -->
  <main class="page-main">

    <!-- Listing Header -->
    <div class="listing-eyebrow">Ultra-Luxury Private Beachfront Villa · Entire Property Only</div>
    <h1 class="listing-h1">My Amani · Luxury Private Beachfront Villa · <em>Vipingo</em></h1>
    <div class="listing-sub">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="6" r="2.5"/><path d="M8 1.5C5.24 1.5 3 3.74 3 6.5c0 4 5 8.5 5 8.5s5-4.5 5-8.5c0-2.76-2.24-5-5-5z"/></svg>
      Vipingo · Kilifi County · Kenya's North Coast
    </div>

    <!-- Quick Stats -->
    <div class="quick-stats">
      <div class="qs"><div class="qs-n">5</div><div class="qs-l">Bedrooms</div></div>
      <div class="qs"><div class="qs-n">10</div><div class="qs-l">Guests</div></div>
      <div class="qs"><div class="qs-n">5</div><div class="qs-l">Bathrooms</div></div>
      <div class="qs"><div class="qs-n">∞</div><div class="qs-l">Pool</div></div>
      <div class="qs"><div class="qs-n">24h</div><div class="qs-l">Security</div></div>
    </div>

    <!-- Pills -->
    <div class="pill-row">
      <span class="pill hi">Beachfront</span>
      <span class="pill hi">Infinity Pool</span>
      <span class="pill">Private Hot Tub</span>
      <span class="pill">Private Chef</span>
      <span class="pill">Beach Access</span>
      <span class="pill">Free Wi-Fi</span>
      <span class="pill">24hr Security</span>
      <span class="pill">Airport Transfer</span>
      <span class="pill">Self-Catering Option</span>
      <span class="pill">Non-Smoking</span>
    </div>

    <div class="divider"></div>

    <!-- About -->
    <div class="sec">
      <div class="sec-label">About the Property</div>
      <h2 class="sec-h">Best Beachfront Villa in <em>Vipingo, Kenya</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Along the north coast of Kenya, overlooking the Indian Ocean, lies Vipingo's best-kept secret — My Amani. A tastefully furnished five-bedroom retreat with endless views of the Indian Ocean at one end and lush indigenous gardens at the other. Slumbering up to 10 guests across five en-suite bedrooms, My Amani is available exclusively as a full private retreat.</p>
      <p class="sec-p">My Amani was recycled from an existing home and renovated with the finest local craftsmanship. The infinity pool overlooks the Indian Ocean, a private outdoor hot tub sits within the verdant garden, and a spacious ocean-view gazebo opens to dual decks made for outdoor hosting and relaxation. The entire property is yours — no shared spaces, no compromise.</p>
      <p class="sec-p">A private chef is available on request at additional cost, while the state-of-the-art kitchen is fully equipped for self-catering. Immaculate daily housekeeping, 24-hour on-site security, free Wi-Fi throughout, and air conditioning in all rooms ensure every comfort is met from arrival to departure.</p>
    </div>

    <div class="divider"></div>

    <!-- Conservation Highlight -->
    <div class="sec">
      <div class="sec-label">Conservation</div>
      <h2 class="sec-h">Protecting the <em>Sea Turtles</em></h2>
      <div class="sec-rule"></div>
      <div class="conservation-block">
        <div class="conservation-icon"></div>
        <div class="conservation-body">
          <div class="conservation-label">Active Conservation Zone</div>
          <div class="conservation-h">My Amani Sea Turtle Nesting Programme</div>
          <p class="conservation-p">My Amani's beach is an active conservation zone for endangered sea turtles. Nesting grounds are protected year-round, regular beach cleanups are conducted by our team and guests, and community education programmes focus on marine ecosystem protection. Staying at My Amani means being part of something greater.</p>
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Amenities -->
    <div class="sec">
      <div class="sec-label">Amenities &amp; Features</div>
      <h2 class="sec-h">Everything <em>Included</em></h2>
      <div class="sec-rule"></div>
      <div class="amenities-grid">
        <div class="amenity"> Direct beach access · exclusive untouched beach</div>
        <div class="amenity"> Infinity pool overlooking the Indian Ocean</div>
        <div class="amenity"> Private outdoor hot tub</div>
        <div class="amenity"> Private Chef on request (additional cost)</div>
        <div class="amenity"> Immaculate daily housekeeping</div>
        <div class="amenity"> State-of-the-art kitchen · self-catering option</div>
        <div class="amenity"> Verdant garden · indigenous trees &amp; plants</div>
        <div class="amenity"> Private compound · 24-hour security</div>
        <div class="amenity"> Spacious gazebo opening to ocean views</div>
        <div class="amenity"> Dual outdoor decks for hosting &amp; relaxation</div>
        <div class="amenity"> Dual private entrances</div>
        <div class="amenity"> Free Wi-Fi throughout</div>
        <div class="amenity"> Air conditioning in all rooms</div>
        <div class="amenity"> Airport transfers available on request</div>
        <div class="amenity"> Sea turtle conservation beach</div>
        <div class="amenity"> Vipingo Ridge Golf · 5 minutes</div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Photo Gallery -->
    <div class="sec">
      <div class="sec-label">Photo Gallery</div>
      <h2 class="sec-h">Explore <em>My Amani</em></h2>
      <div class="sec-rule"></div>
      <div class="photo-grid">
        <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best21.jpg" alt="My Amani outdoor garden" onclick="openLb(3)" loading="lazy">
        <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best25.jpg" alt="My Amani pool deck" onclick="openLb(4)" loading="lazy">
        <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best31.jpg" alt="My Amani outdoor terrace" onclick="openLb(5)" loading="lazy">
        <img src="images/my-amani/My Amani - Bedrooms/Bedroom 1/My Amani Best47.jpg" alt="My Amani master bedroom" onclick="openLb(6)" loading="lazy">
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge · More photos in the gallery</div>
    </div>

    <div class="divider"></div>

    <!-- Nearby Experiences -->
    <div class="sec">
      <div class="sec-label">Nearby Experiences</div>
      <h2 class="sec-h">Your Home Base for <em>Adventure</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p" style="margin-bottom:1.2rem;">All activities bookable through your Tribal Sand concierge — quoted within 24 hours of your request.</p>
      <div class="exp-list">
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Vipingo Ridge Golf · 18-hole PGA-accredited course</div><div class="exp-price">5 min · On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Snorkelling · Watamu Marine Park</div><div class="exp-price">From $40/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Deep Sea Fishing · Full or Half Day</div><div class="exp-price">From $550/boat</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Kiteboarding · Tribal Kite School</div><div class="exp-price">From $130/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Sunset Dhow Cruise · Kilifi Creek</div><div class="exp-price">From $45/pp</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Tsavo East Safari · Day Trip</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Yoga &amp; Wellness Sessions</div><div class="exp-price">On Request</div></div>
        <div class="exp-row"><div class="exp-ico"></div><div class="exp-name">Private Beach Dinner</div><div class="exp-price">On Request</div></div>
      </div>
      <div class="exp-cta">
        <a href="activities.php" style="display:inline-flex;align-items:center;gap:.5rem;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;padding:.78rem 1.6rem;background:var(--teal);color:#fff;font-family:'Jost',sans-serif;transition:background .22s;margin-right:.6rem;" onmouseover="this.style.background='#102F3A'" onmouseout="this.style.background='#1E5C6B'">See All Activities →</a>
        <a href="trip-builder.php" style="display:inline-flex;align-items:center;gap:.5rem;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;padding:.78rem 1.6rem;border:1px solid var(--border);color:var(--teal);font-family:'Jost',sans-serif;transition:all .22s;" onmouseover="this.style.borderColor='var(--teal)'" onmouseout="this.style.borderColor='var(--border)'">Build My Itinerary →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Location -->
    <div class="sec">
      <div class="sec-label">Location</div>
      <h2 class="sec-h">Vipingo · <em>Kilifi County</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Located on the pristine stretch between Kilifi and Mombasa — one of Kenya's most exclusive coastal addresses. My Amani sits on a direct Indian Ocean frontage in Vipingo, with an 18-hole PGA-accredited golf course just 5 minutes away, and easy access from Mombasa Moi International Airport (MBA) in approximately 45 minutes. Kilifi Creek activities are nearby, and Watamu Marine Park is within easy reach.</p>
      <div class="nearby-list" style="margin-bottom:1.4rem;">
        <div class="nearby-row"><div class="nearby-ico"></div><div class="nearby-name">Vipingo Ridge Golf Course (18-hole PGA)</div><div class="nearby-dist">~5 minutes</div></div>
        <div class="nearby-row"><div class="nearby-ico"></div><div class="nearby-name">Mombasa Moi International Airport (MBA)</div><div class="nearby-dist">~45 minutes</div></div>
        <div class="nearby-row"><div class="nearby-ico"></div><div class="nearby-name">Kilifi Creek activities</div><div class="nearby-dist">Nearby</div></div>
        <div class="nearby-row"><div class="nearby-ico"></div><div class="nearby-name">Watamu Marine Park</div><div class="nearby-dist">Nearby</div></div>
      </div>
      <div class="map-block">
        <div class="map-info">
          <div class="map-eyebrow">Directions</div>
          <div class="map-h">Vipingo · Kilifi County · Kenya</div>
          <div class="map-p">45 min from Mombasa Moi Airport · 5 min from Vipingo Ridge Golf</div>
        </div>
        <a href="https://maps.google.com/?q=Vipingo+Kenya" target="_blank" rel="noopener" class="btn-map">Open in Google Maps →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Guest Reviews -->
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
        <div class="review-summary-text">Guests consistently praise My Amani for its impeccable staff, stunning Indian Ocean setting, and the feeling of complete seclusion — while remaining just minutes from Vipingo Ridge golf and the coast's finest activities.</div>
      </div>
      <div class="reviews-grid">
        <div class="review-card">
          <div class="review-stars-sm">★★★★★</div>
          <div class="review-text">"Stunning place, great staff — friendly and attentive. They made our visit perfect. We will be coming back again and again."</div>
          <div class="review-author">— Mr &amp; Mrs B Vyas</div>
        </div>
        <div class="review-card">
          <div class="review-stars-sm">★★★★★</div>
          <div class="review-text">"We will always remember this stunning place — its vibrance, its calm aura, its unbelievably kind staff. Five days of astonishment."</div>
          <div class="review-author">— Sonal Patel</div>
        </div>
        <div class="review-card">
          <div class="review-stars-sm">★★★★☆</div>
          <div class="review-text">"The perfect place to relax and unwind. We loved the house, the pool, the outdoor shower, the beach… everything!"</div>
          <div class="review-author">— Viv &amp; Peter Thairu</div>
        </div>
        <div class="review-card">
          <div class="review-stars-sm">★★★★★</div>
          <div class="review-text">"Thank you for helping create the most unforgettable birthday. A visual masterpiece — the staff made us feel like family."</div>
          <div class="review-author">— Erin</div>
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- FAQ -->
    <div class="sec" id="faq">
      <div class="sec-label">Good to Know</div>
      <h2 class="sec-h">Frequently <em>Asked</em></h2>
      <div class="sec-rule"></div>

      <div class="faq-item">
        <div class="faq-q">Can I book My Amani for a partial group? <span class="faq-ico" aria-hidden="true">+</span></div>
        <div class="faq-a">No. My Amani is available as an entire property only — all 5 bedrooms for up to 10 guests. This ensures complete privacy for every stay.</div>
      </div>

      <div class="faq-item">
        <div class="faq-q">Is a private chef included at My Amani? <span class="faq-ico" aria-hidden="true">+</span></div>
        <div class="faq-a">A private chef is available on request at an additional cost. The villa also has a fully-equipped kitchen for self-catering.</div>
      </div>

      <div class="faq-item">
        <div class="faq-q">What is the conservation project at My Amani? <span class="faq-ico" aria-hidden="true">+</span></div>
        <div class="faq-a">My Amani's beach is an active nesting ground for endangered sea turtles. The property operates a year-round conservation programme with protected nesting areas and regular beach cleanups.</div>
      </div>

      <div class="faq-item">
        <div class="faq-q">How far is Vipingo Ridge Golf Course from My Amani? <span class="faq-ico" aria-hidden="true">+</span></div>
        <div class="faq-a">Vipingo Ridge — an 18-hole PGA-accredited course — is approximately 5 minutes from My Amani. We can arrange tee times through our concierge team.</div>
      </div>

      <div class="faq-item">
        <div class="faq-q">What is the security deposit for My Amani? <span class="faq-ico" aria-hidden="true">+</span></div>
        <div class="faq-a">A refundable security deposit of USD 500 applies to all bookings. Check-in is 2:00 PM and check-out is 10:00 AM.</div>
      </div>

      <div class="faq-item">
        <div class="faq-q">What is the minimum stay at My Amani? <span class="faq-ico" aria-hidden="true">+</span></div>
        <div class="faq-a">The standard minimum stay is 2 nights. During peak season a 5-night minimum applies. My Amani is a non-smoking property throughout.</div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Other Properties -->
    <div class="sec">
      <div class="sec-label">Explore More</div>
      <h2 class="sec-h">Other <em>Properties</em></h2>
      <div class="sec-rule"></div>
      <div class="other-grid">
        <a href="zuri.php" class="other-card">
          <img class="other-img" src="images/zuri/Aerial/zuri-3.webp" alt="Zuri Boutique Hotel Watamu" loading="lazy">
          <div class="other-body">
            <div class="other-loc">Watamu</div>
            <div class="other-name">Zuri</div>
            <div class="other-meta">6 Suites · Up to 14 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Boutique Hotel</span><span class="other-link">View →</span></div>
        </a>
        <a href="maya-kobe.php" class="other-card">
          <img class="other-img" src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe Boutique Hotel Kilifi" loading="lazy">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Maya Kobe</div>
            <div class="other-meta">5 Suites · Up to 12 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Boutique Hotel</span><span class="other-link">View →</span></div>
        </a>
        <a href="enkare-bofa.php" class="other-card">
          <img class="other-img" src="images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" alt="Enkare Bofa Private Villa Kilifi" loading="lazy">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Enkare Bofa</div>
            <div class="other-meta">5 Rooms · Up to 10 guests</div>
          </div>
          <div class="other-foot"><span class="other-type">Private Villa</span><span class="other-link">View →</span></div>
        </a>
      </div>
    </div>

  </main><!-- /page-main -->

  <!-- ══ SIDEBAR ══ -->
  <aside class="page-side" aria-label="Booking widget">
    <div class="book-card">

      <!-- Header image -->
      <div class="book-head">
        <div class="book-head-bg"></div>
        <div class="book-head-overlay"></div>
        <div class="book-head-inner">
          <div class="book-eyebrow">Private Villa · Entire Retreat Only</div>
          <div class="book-name">My Amani</div>
          <div class="book-loc">Vipingo · Kilifi County · Kenya</div>
        </div>
      </div>

      <!-- Booking widget (mode driven by DB: enquiry or availability) -->
      <div class="book-body" style="padding:0">
        <?php $booking_slug = 'my-amani-full-rental'; include __DIR__ . '/includes/booking-widget.php'; ?>
      </div>

      <!-- Policy accordion -->
      <div class="book-pol-toggle">
        <button class="book-pol-btn" id="polBtn">Property Policies <span class="pol-chevron">▾</span></button>
        <div class="book-pol-body" id="polBody">
          <div class="book-pol-row"><span class="book-pol-k">Check-in</span><span class="book-pol-v">From 2:00 PM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Check-out</span><span class="book-pol-v">By 10:00 AM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Min. Stay</span><span class="book-pol-v">2 nights · 5 peak</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Security Deposit</span><span class="book-pol-v">USD 500</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Availability</span><span class="book-pol-v">Entire property only</span></div>
        </div>
      </div>

      <!-- Features -->
      <div class="book-features">
        <div class="book-feat"><span class="book-feat-ico">·</span> Entire villa — no shared spaces</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> 24hr on-site concierge &amp; security</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Experiences arranged by your host</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Airport transfers available</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Non-smoking property</div>
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
<div class="sticky-cta" id="stickyCta" aria-hidden="true">
  <div>
    <div class="sticky-cta-info">My Amani · Vipingo</div>
    <div class="sticky-cta-sub">5 Bedrooms · Full Private Retreat</div>
  </div>
  <a href="#book" class="sticky-cta-btn">Request →</a>
</div>

<!-- ═══ LIGHTBOX ═══ -->
<div class="lb" id="lb" role="dialog" aria-modal="true" aria-label="Photo lightbox">
  <button class="lb-close" onclick="closeLb()" aria-label="Close lightbox">✕</button>
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
  'images/my-amani/Aerial/myamani-11.webp',
  'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best18.jpg',
  'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best20.jpg',
  'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best21.jpg',
  'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best25.jpg',
  'images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best31.jpg',
  'images/my-amani/My Amani - Bedrooms/Bedroom 1/My Amani Best47.jpg',
];
var lbIdx = 0;

function openLb(i) {
  lbIdx = i;
  document.getElementById('lbImg').src = IMGS[i];
  document.getElementById('lbCount').textContent = (i + 1) + ' / ' + IMGS.length;
  document.getElementById('lb').classList.add('show');
  document.body.style.overflow = 'hidden';
}
function closeLb() {
  document.getElementById('lb').classList.remove('show');
  document.body.style.overflow = '';
}
function lbNext() { lbIdx = (lbIdx + 1) % IMGS.length; openLb(lbIdx); }
function lbPrev() { lbIdx = (lbIdx - 1 + IMGS.length) % IMGS.length; openLb(lbIdx); }

document.getElementById('lb').addEventListener('click', function(e) {
  if (e.target === this) closeLb();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'ArrowRight') lbNext();
  if (e.key === 'ArrowLeft')  lbPrev();
  if (e.key === 'Escape')     closeLb();
});

// ── Scroll reveal ──
var obs = new IntersectionObserver(function(entries) {
  entries.forEach(function(e) {
    if (e.isIntersecting) {
      e.target.style.opacity = '1';
      e.target.style.transform = 'none';
    }
  });
}, { threshold: 0.06 });

document.querySelectorAll('.exp-row,.review-card,.other-card,.amenity,.faq-item,.nearby-row').forEach(function(el, i) {
  el.style.opacity = '0';
  el.style.transform = 'translateY(10px)';
  el.style.transition = 'opacity .28s ' + (i * 0.025) + 's ease, transform .28s ' + (i * 0.025) + 's ease';
  obs.observe(el);
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
    var noteLines = ['My Amani Enquiry'];
    if (arrival)   noteLines.push('Arrival: '   + arrival);
    if (departure) noteLines.push('Departure: ' + departure);
    if (adults)    noteLines.push('Adults: '    + adults);
    if (children)  noteLines.push('Children: '  + children);
    if (rooms)     noteLines.push('Rooms/Unit: '+ rooms);
    if (phone)     noteLines.push('Phone: '     + phone);
    if (message)   noteLines.push('\nMessage:\n' + message);

    fetch('/ghl-submit', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
      guest:   { firstName: parts[0]||name, lastName: parts.slice(1).join(' ')||'', email: email, phone: phone },
      trip:    { prop: 'My Amani', arrival: arrival, departure: departure, adults: adults, children: children, rooms: rooms },
      message: message,
      tags:    ['website-enquiry', 'my-amani-enquiry'],
      opportunity: { source: 'Website - My Amani' },
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
