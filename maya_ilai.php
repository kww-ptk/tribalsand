<?php require_once 'includes/schema.php'; ?>
<?php
/* ═══ SEO ═══ */
$page_title   = 'Maya Ilai · Eco Resort · Kilifi Kenya · Tribal Sand';
$page_desc    = 'Maya Ilai is a solar-powered eco resort within Tribal Dunes, Kilifi. 16 units including villas and studios. Communal pool. Adults 16+ only.';
$page_url     = 'https://tribalsand.com/maya_ilai.php';
$page_image   = asset_url('images/maya_illai/Best1.jpg');
$page_preload = 'images/maya_illai/Best1.jpg';

/* ═══ FAQS ═══ */
$faqs = [
    ['q' => 'What is the age policy at Maya Ilai?',
     'a' => 'Maya Ilai is an adults-only property with a minimum age of 16. Guests aged 16 to 17 may stay without a parent or guardian. If guests aged 16 to 17 wish to consume alcohol, a parent or legal guardian must sign a consent form at check-in.'],
    ['q' => 'What types of accommodation are available at Maya Ilai?',
     'a' => 'Maya Ilai has 16 units — 8 three-bedroom villas and 8 studio apartments. Villa rooms are configured as doubles (rooms 1 and 2) or bunk beds (room 3). All units share access to the communal pool, bar and garden spaces.'],
    ['q' => 'Is Maya Ilai suitable for corporate retreats?',
     'a' => 'Yes. Maya Ilai is designed for group stays — corporate retreats, kitesurf camps, wellness retreats and large wedding groups. The compound offers flexibility in unit combinations and shared facilities.'],
    ['q' => 'How far is Maya Ilai from the beach?',
     'a' => 'Maya Ilai is at the rear of the Tribal Dunes property. The beach is approximately 5 minutes walk through Somewhere Café. Electric bikes and golf carts are available for guests.'],
    ['q' => 'Is Maya Ilai eco-friendly?',
     'a' => 'Maya Ilai runs entirely on solar power and uses a desalinated ocean water system — no freshwater depletion. It is part of the Tribal Dunes ecosystem, which also operates rainwater harvesting and a coral restoration project.'],
];

/* ═══ SCHEMA ═══ */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_lodging([
    'name'            => 'Maya Ilai Eco Retreat',
    'description'     => 'Solar-powered eco retreat compound within Tribal Dunes, Kilifi. 16 units (villas and studios), communal pool. Adults 16+ only.',
    'url'             => 'https://tribalsand.com/maya_ilai.php',
    'image'           => [asset_url('images/maya_illai/Best1.jpg')],
    'addressLocality' => 'Kilifi',
    'addressRegion'   => 'Kilifi County',
    'lat'             => -3.6340,
    'lng'             => 39.8503,
    'numberOfRooms'   => 16,
    'priceRange'      => '$$',
    'amenities'       => ['Solar Powered','Desalinated Water','Communal Pool','Garden','Bar','Electric Bikes','Free WiFi','Air Conditioning'],
]);
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',          'url' => 'https://tribalsand.com/'],
    ['name' => 'Tribal Dunes',  'url' => 'https://tribalsand.com/tribal-dunes.php'],
    ['name' => 'Maya Ilai',     'url' => 'https://tribalsand.com/maya_ilai.php'],
]);
$page_schema .= ts_schema_faq($faqs);

$page_rooms_rates = true;
$rr_venue_slug = 'maya_ilai';
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
.pill.eco{border-color:rgba(46,125,50,.35);color:#2E7D32;background:rgba(46,125,50,.04);}

/* ── SECTION ── */
.sec{margin-bottom:2.8rem;}
.sec-label{font-size:.75rem;letter-spacing:.28em;text-transform:uppercase;color:var(--sand);margin-bottom:.55rem;display:flex;align-items:center;gap:.5rem;}
.sec-label::before{content:'';width:14px;height:1px;background:var(--sand);}
.sec-h{font-family:'Cormorant Garamond',serif;font-size:clamp(1.5rem,2.5vw,2rem);font-weight:400;color:var(--dark);line-height:1.15;margin-bottom:.45rem;}
.sec-h em{font-style:italic;color:var(--teal);}
.sec-rule{width:20px;height:1px;background:var(--sand);margin-bottom:1.2rem;}
.sec-p{font-size:1rem;font-weight:400;line-height:1.92;color:var(--mid);margin-bottom:1rem;}
.divider{height:1px;background:var(--border);margin:2.4rem 0;}

/* ── AGE POLICY CALLOUT ── */
.age-policy{
  border:2px solid var(--sand);
  background:linear-gradient(135deg,#FDF6E3 0%,#FAF0D0 100%);
  padding:1.8rem 2rem;
  margin-bottom:2rem;
  position:relative;
}
.age-policy::before{
  content:'';
  position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--sand),var(--sand-lt),var(--sand));
}
.age-policy-badge{
  display:inline-flex;align-items:center;gap:.5rem;
  font-size:.6rem;letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);border:1px solid rgba(184,150,90,.4);
  padding:.25rem .75rem;margin-bottom:.9rem;
  background:rgba(184,150,90,.08);
}
.age-policy-h{
  font-family:'Cormorant Garamond',serif;
  font-size:1.35rem;font-weight:400;
  color:var(--dark);margin-bottom:.75rem;line-height:1.2;
}
.age-policy-list{list-style:none;display:flex;flex-direction:column;gap:.55rem;}
.age-policy-list li{
  display:flex;align-items:flex-start;gap:.7rem;
  font-size:.9rem;line-height:1.65;color:var(--mid);
}
.age-policy-list li::before{
  content:'—';color:var(--sand);flex-shrink:0;font-weight:500;
}
.age-policy-note{
  margin-top:1rem;padding-top:.85rem;border-top:1px solid rgba(184,150,90,.2);
  font-size:.78rem;color:var(--light);font-style:italic;line-height:1.6;
}

/* ── AMENITIES ── */
.amenities-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--border);overflow:hidden;}
.amenity{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-bottom:1px solid var(--border);border-right:1px solid var(--border);font-size:1rem;font-weight:400;color:var(--mid);transition:background .18s;}
.amenity:hover{background:var(--sand-faint);}
.amenity:nth-child(even){border-right:none;}
.amenity:nth-last-child(-n+2){border-bottom:none;}
.amenity-ico{font-size:1rem;flex-shrink:0;width:1.2rem;text-align:center;}

/* ── VILLAS GRID ── */
.units-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.6rem;margin-bottom:1.4rem;}
.unit-card{border:1px solid var(--border);background:var(--white);overflow:hidden;transition:box-shadow .3s;}
.unit-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.09);}
.unit-card-img{height:220px;overflow:hidden;position:relative;}
.unit-card-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .5s ease;}
.unit-card:hover .unit-card-img img{transform:scale(1.05);}
.unit-card-tag{position:absolute;bottom:.75rem;left:.75rem;font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;background:rgba(16,47,58,.82);color:rgba(184,150,90,.9);padding:.28rem .75rem;backdrop-filter:blur(6px);}
.unit-card-body{padding:1.1rem 1.2rem 1.25rem;}
.unit-card-name{font-family:'Cormorant Garamond',serif;font-size:1.22rem;font-weight:400;color:var(--dark);margin-bottom:.18rem;}
.unit-card-meta{font-size:.78rem;letter-spacing:.08em;color:var(--light);margin-bottom:.6rem;}
.unit-card-desc{font-size:.95rem;font-weight:400;color:var(--mid);line-height:1.78;}
.unit-badge{display:inline-block;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:var(--sand);border:1px solid rgba(184,150,90,.3);padding:.18rem .55rem;margin-top:.6rem;}

/* ── BOOKING OPTIONS ── */
.booking-options{display:flex;flex-direction:column;gap:1.1rem;margin-bottom:.5rem;}
.bo-item{display:flex;align-items:flex-start;gap:.9rem;}
.bo-bar{width:3px;min-height:44px;background:var(--teal);border-radius:2px;flex-shrink:0;margin-top:.18rem;}
.bo-name{font-size:.95rem;font-weight:500;color:var(--dark);margin-bottom:.2rem;}
.bo-desc{font-size:.9rem;color:var(--mid);line-height:1.7;}

/* ── UNIT DIRECTORY ── */
.units-sub-label{font-size:.75rem;letter-spacing:.25em;text-transform:uppercase;color:var(--sand);margin-bottom:.6rem;display:flex;align-items:center;gap:.5rem;}
.units-sub-label::before{content:'';width:12px;height:1px;background:var(--sand);}
.units-dir-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:.5rem;}
.unit-dir-card{border:1px solid var(--border);background:var(--white);overflow:hidden;transition:box-shadow .25s;}
.unit-dir-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.08);}
.unit-dir-img{height:140px;overflow:hidden;}
.unit-dir-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .45s ease;}
.unit-dir-card:hover .unit-dir-img img{transform:scale(1.06);}
.unit-dir-body{padding:.65rem .8rem .75rem;}
.unit-dir-name{font-family:'Cormorant Garamond',serif;font-size:1.05rem;font-weight:400;color:var(--dark);margin-bottom:.15rem;}
.unit-dir-rooms{font-size:.72rem;letter-spacing:.1em;color:var(--light);}

/* ── SUSTAINABILITY ── */
.sustain-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1px;background:var(--border);border:1px solid var(--border);margin-bottom:1.2rem;}
.sustain-item{background:var(--white);padding:1.2rem 1.1rem;transition:background .18s;}
.sustain-item:hover{background:var(--sand-faint);}
.sustain-ico{font-size:1.4rem;margin-bottom:.45rem;}
.sustain-h{font-size:.82rem;font-weight:500;color:var(--dark);margin-bottom:.25rem;}
.sustain-p{font-size:.8rem;color:var(--mid);line-height:1.7;}

/* ── IDEAL GUESTS ── */
.ideal-list{display:flex;flex-direction:column;gap:1px;background:var(--border);border:1px solid var(--border);}
.ideal-row{background:var(--white);display:flex;align-items:center;gap:1rem;padding:.85rem 1.1rem;transition:background .18s;}
.ideal-row:hover{background:var(--sand-faint);}
.ideal-ico{font-size:1.1rem;flex-shrink:0;}
.ideal-name{font-size:1rem;font-weight:400;color:var(--dark);flex:1;}

/* ── PHOTO GRID ── */
.photo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;margin-bottom:.5rem;}
.photo-grid img{width:100%;aspect-ratio:4/3;cursor:pointer;transition:opacity .25s,transform .4s;}
.photo-grid img:hover{opacity:.88;transform:scale(1.02);}
.photo-grid-cap{font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);text-align:right;margin-bottom:2rem;}

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
.book-head-bg{position:absolute;inset:0;background:url('images/maya_illai/Best1.jpg') center 40%/cover;opacity:.28;}
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

/* ── SIDEBAR AGE NOTICE ── */
.sidebar-age-notice{
  margin:0 1.4rem .9rem;
  padding:.75rem 1rem;
  background:linear-gradient(135deg,#FDF6E3,#FAF0D0);
  border:1px solid rgba(184,150,90,.5);
  border-left:3px solid var(--sand);
  font-size:.78rem;color:var(--mid);line-height:1.55;
}
.sidebar-age-notice strong{color:var(--dark);}

/* ── STICKY CTA (mobile) ── */
.sticky-cta{
  display:none;position:fixed;bottom:0;left:0;right:0;z-index:400;
  background:var(--white);border-top:1px solid var(--border);
  padding:.9rem 1.2rem;align-items:center;justify-content:space-between;gap:.75rem;
}
.sticky-cta-info{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:400;color:var(--dark);}
.sticky-cta-sub{font-size:.82rem;color:var(--light);}
.sticky-cta-btn{font-size:.75rem;letter-spacing:.2em;text-transform:uppercase;padding:.75rem 1.4rem;background:var(--sand);color:var(--teal-d);border:none;cursor:pointer;font-family:'Jost',sans-serif;font-weight:500;white-space:nowrap;}

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
  .units-grid{grid-template-columns:1fr;}
  .unit-card-img{height:200px;}
  .units-dir-grid{grid-template-columns:repeat(2,1fr);}
  .unit-dir-img{height:120px;}
  .other-grid{grid-template-columns:1fr 1fr;}
  .td-nodes{grid-template-columns:1fr 1fr;}
  .age-policy{padding:1.3rem 1.2rem;}
}
@media(max-width:480px){
  .other-grid{grid-template-columns:1fr;}
  .td-nodes{grid-template-columns:1fr;}
  .units-dir-grid{grid-template-columns:repeat(2,1fr);}
}
</style>
</head>
<body class="ts-nav-transparent">

<?php include 'includes/header.php'; ?>

<!-- ═══ GALLERY ═══ -->
<?php
$pg_venue_slug   = 'maya_ilai';
$pg_fallback_badge = 'Maya Ilai · Kilifi · Tribal Dunes';
$pg_fallback = [
  ['src' => 'images/maya_illai/Best1.jpg',  'alt' => 'Maya Ilai eco compound — Kilifi'],
  ['src' => 'images/maya_illai/Best 2.jpg', 'alt' => 'Maya Ilai communal pool and gardens'],
  ['src' => 'images/maya_illai/Best4.jpg',  'alt' => 'Maya Ilai eco retreat units'],
];
include __DIR__ . '/includes/property-gallery.php';
?>

<!-- ═══ BREADCRUMB ═══ -->
<nav class="breadcrumb" aria-label="Breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">›</span>
  <a href="tribal-dunes.php">Tribal Dunes</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-curr">Maya Ilai</span>
</nav>

<!-- ═══ PAGE WRAP ═══ -->
<div class="page-wrap">

  <!-- ══ MAIN ══ -->
  <div class="page-main">

    <!-- Header -->
    <div class="listing-eyebrow"><?= e(ts_venue_text('maya_ilai', 'tagline', 'Eco Retreat Compound · Solar Powered · Adults 16+')) ?></div>
    <h1 class="listing-h1">Maya Ilai · Eco Resort · <em>Bofa Beach Kilifi</em></h1>
    <div class="listing-sub">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="6" r="2.5"/><path d="M8 1.5C5.24 1.5 3 3.74 3 6.5c0 4 5 8.5 5 8.5s5-4.5 5-8.5c0-2.76-2.24-5-5-5z"/></svg>
      Tribal Dunes · Bofa Road · Kilifi · 5 min walk to beach via Somewhere Café
    </div>

    <!-- Quick stats -->
    <div class="quick-stats">
      <div class="qs"><div class="qs-n">16</div><div class="qs-l">Total Units</div></div>
      <div class="qs"><div class="qs-n">8</div><div class="qs-l">Villas</div></div>
      <div class="qs"><div class="qs-n">8</div><div class="qs-l">Studios</div></div>
      <div class="qs"><div class="qs-n">3</div><div class="qs-l">Bed Villas</div></div>
      <div class="qs"><div class="qs-n">100%</div><div class="qs-l">Solar</div></div>
    </div>

    <!-- Pills -->
    <div class="pill-row">
      <span class="pill hi">Adults 16+ Only</span>
      <span class="pill hi">Communal Pool</span>
      <span class="pill eco">Fully Solar Powered</span>
      <span class="pill eco">Desalinated Water</span>
      <span class="pill">8 Three-Bedroom Villas</span>
      <span class="pill">8 Studio Apartments</span>
      <span class="pill">Electric Bikes & Golf Carts</span>
      <span class="pill">Bar & Garden</span>
      <span class="pill">Group Retreats</span>
      <span class="pill">Kitesurf Groups</span>
      <span class="pill">Free Wi-Fi</span>
      <span class="pill">Air Conditioning</span>
    </div>

    <div class="divider"></div>

    <!-- AGE POLICY CALLOUT — must be prominent -->
    <div class="age-policy">
      <div class="age-policy-badge">Age Policy · Important</div>
      <div class="age-policy-h">Adults Only — Minimum Age 16</div>
      <ul class="age-policy-list">
        <li>Maya Ilai is an adults-only property. The minimum age to stay is <strong>16 years</strong>.</li>
        <li>Guests aged 16–17 <strong>may stay without a parent or guardian</strong> present.</li>
        <li>If a guest aged 16–17 wishes to consume alcohol, a <strong>parent or legal guardian must sign a consent form</strong> at check-in.</li>
        <li>This policy is in place to preserve the peaceful, adult-focused atmosphere of the compound.</li>
      </ul>
      <div class="age-policy-note">Guests who do not meet the minimum age requirement will not be permitted to check in. We appreciate your understanding — this policy helps us maintain the retreat environment our guests value.</div>
    </div>

    <div class="divider"></div>

    <!-- About (editable in admin → Properties → Page Content; falls back to text below) -->
    <?php
    $va_slug = 'maya_ilai';
    $va_heading_fallback = 'Best Eco Retreat Compound in <em>Kilifi, Kenya</em>';
    $va_body_fallback = "Maya Ilai is a solar-powered eco retreat compound nestled at the back of the Tribal Dunes property in Kilifi — Kenya's most exciting beachfront destination. With 16 units across three-bedroom villas and studio apartments, it is designed for groups, conscious travellers and anyone seeking flexible, affordable longer-term coastal living without sacrificing comfort or community.\n\nThe beach is a short five-minute walk through Somewhere Café. Electric bikes and golf carts are on hand for guests who prefer a ride. A large communal pool, bar and garden spaces form the heart of the compound — places where corporate retreat delegates, kitesurf campers, and wellness seekers naturally gather.\n\nEvery kilowatt of energy here is generated by the sun. Fresh water is desalinated from the ocean. Rainwater is harvested. And the nearby coral restoration project is part of an active commitment to the ocean that surrounds this place. Maya Ilai is sustainable hospitality done with integrity.";
    include __DIR__ . '/includes/venue-about.php';
    ?>

    <div class="divider"></div>

    <!-- Bookable room types (DB-driven rates + availability) -->
    <?php $rr_venue_slug = 'maya_ilai'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>

    <div class="divider"></div>

    <!-- Unit directory (physical layout) -->
    <div class="sec">
      <div class="sec-label">The Compound</div>
      <h2 class="sec-h">16 Units — <em>Villas &amp; Studios</em></h2>
      <div class="sec-rule"></div>

      <!-- Booking options -->
      <div class="booking-options">
        <div class="bo-item">
          <div class="bo-bar"></div>
          <div>
            <div class="bo-name">Room Only</div>
            <div class="bo-desc">Perfect for solo travellers or couples seeking a peaceful retreat with all essential amenities.</div>
          </div>
        </div>
        <div class="bo-item">
          <div class="bo-bar"></div>
          <div>
            <div class="bo-name">Room with Living Area</div>
            <div class="bo-desc">Enhanced comfort with dedicated living space — ideal for extended stays and longer coastal living.</div>
          </div>
        </div>
        <div class="bo-item">
          <div class="bo-bar"></div>
          <div>
            <div class="bo-name">Full Villa</div>
            <div class="bo-desc">Complete three-bedroom villa with full kitchen and shared living spaces — designed for adult groups seeking privacy and comfort.</div>
          </div>
        </div>
      </div>

      <div class="divider" style="margin:2rem 0;"></div>

      <!-- Villas -->
      <div class="units-sub-label">Villas · 8 Three-Bedroom Villas</div>
      <p class="sec-p" style="margin-bottom:1.2rem;">Rooms ending in <strong>1 &amp; 2</strong> are double rooms. Room ending in <strong>3</strong> has bunk beds — ideal for groups and kitesurf camps.</p>
      <div class="units-dir-grid">
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best9.jpg" alt="Villa 1 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 1</div><div class="unit-dir-rooms">101 &middot; 102 &middot; 103</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best1.jpg" alt="Villa 2 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 2</div><div class="unit-dir-rooms">201 &middot; 202 &middot; 203</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best 2.jpg" alt="Villa 3 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 3</div><div class="unit-dir-rooms">301 &middot; 302 &middot; 303</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best4.jpg" alt="Villa 4 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 4</div><div class="unit-dir-rooms">401 &middot; 402 &middot; 403</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/best5.jpg" alt="Villa 5 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 5</div><div class="unit-dir-rooms">501 &middot; 502 &middot; 503</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/best6.jpg" alt="Villa 6 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 6</div><div class="unit-dir-rooms">601 &middot; 602 &middot; 603</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best9.jpg" alt="Villa 7 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 7</div><div class="unit-dir-rooms">701 &middot; 702 &middot; 703</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best1.jpg" alt="Villa 8 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Villa 8</div><div class="unit-dir-rooms">801 &middot; 802 &middot; 803</div></div>
        </div>
      </div>

      <div class="divider" style="margin:2rem 0;"></div>

      <!-- Studios -->
      <div class="units-sub-label">Studios · 8 Private Studios</div>
      <p class="sec-p" style="margin-bottom:1.2rem;">Self-contained studios designed for simplicity, comfort and relaxed coastal living. Communal pool, bar and gardens included.</p>
      <div class="units-dir-grid">
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Studios/Studio1.jpeg" alt="Studio 1 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 1</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Studios/Studio4.jpeg" alt="Studio 2 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 2</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best4.jpg" alt="Studio 3 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 3</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/best5.jpg" alt="Studio 4 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 4</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/best6.jpg" alt="Studio 5 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 5</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Studios/Studio11.jpeg" alt="Studio 6 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 6</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best1.jpg" alt="Studio 7 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 7</div></div>
        </div>
        <div class="unit-dir-card">
          <div class="unit-dir-img"><img src="images/maya_illai/Best9.jpg" alt="Studio 8 — Maya Ilai" loading="lazy"></div>
          <div class="unit-dir-body"><div class="unit-dir-name">Studio 8</div></div>
        </div>
      </div>

      <p class="sec-p" style="margin-top:1.4rem;">All units have access to the communal pool, bar and garden spaces. Electric bikes and golf carts are available to the whole compound for easy movement to the beach and around Tribal Dunes.</p>
    </div>

    <div class="divider"></div>

    <!-- Amenities -->
    <div class="sec">
      <div class="sec-label">Amenities & Features</div>
      <h2 class="sec-h">Everything <em>Included</em></h2>
      <div class="sec-rule"></div>
      <div class="amenities-grid">
        <div class="amenity"> Fully solar powered · 100% renewable</div>
        <div class="amenity"> Desalinated ocean water system</div>
        <div class="amenity"> Large communal swimming pool</div>
        <div class="amenity"> 16 units · Villas and studios</div>
        <div class="amenity"> Three-bedroom villas available</div>
        <div class="amenity"> Studio apartments available</div>
        <div class="amenity"> Electric bikes for guests</div>
        <div class="amenity"> Golf carts for compound access</div>
        <div class="amenity"> Bar and garden spaces</div>
        <div class="amenity"> On-site bar · Garden lounging</div>
        <div class="amenity"> Free Wi-Fi throughout</div>
        <div class="amenity"> Air conditioning · All units</div>
        <div class="amenity"> 24-hour security on-site</div>
        <div class="amenity"> Beach access · 5 min walk</div>
        <div class="amenity"> Rainwater harvesting system</div>
        <div class="amenity"> Coral restoration project nearby</div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Sustainability -->
    <div class="sec">
      <div class="sec-label">Sustainability</div>
      <h2 class="sec-h">Your Stay is Powered by <em>the Sun</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Maya Ilai is one of Kenya's most genuinely sustainable retreat properties. No greenwashing — the systems are real and the commitment runs through every element of the operation.</p>
      <div class="sustain-grid">
        <div class="sustain-item">
          <div class="sustain-ico"></div>
          <div class="sustain-h">Fully Solar Powered</div>
          <p class="sustain-p">Every unit, the pool, the bar, the lighting. Your stay generates zero grid electricity demand. The sun does the work.</p>
        </div>
        <div class="sustain-item">
          <div class="sustain-ico"></div>
          <div class="sustain-h">Desalinated Water</div>
          <p class="sustain-p">Luxury without freshwater depletion. Water is drawn from the Indian Ocean and desalinated on-site — no pressure on the local freshwater table.</p>
        </div>
        <div class="sustain-item">
          <div class="sustain-ico"></div>
          <div class="sustain-h">Rainwater Harvesting</div>
          <p class="sustain-p">Every rainfall is captured and stored as part of the Tribal Dunes water management system, reducing reliance on pumped water further.</p>
        </div>
        <div class="sustain-item">
          <div class="sustain-ico"></div>
          <div class="sustain-h">Coral Restoration</div>
          <p class="sustain-p">An active coral restoration project operates near Tribal Dunes. Guests can observe or participate — a living contribution to the Indian Ocean reef ecosystem.</p>
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Ideal Guests -->
    <div class="sec">
      <div class="sec-label">Who Stays Here</div>
      <h2 class="sec-h">Made for <em>Groups & Conscious Travellers</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p" style="margin-bottom:1.2rem;">Maya Ilai's layout — 16 flexible units, a communal pool, shared bar and garden — is purpose-built for group retreats and multi-unit bookings. The solar and water systems appeal to the environmentally conscious. The adults-only policy ensures calm for all.</p>
      <div class="ideal-list">
        <div class="ideal-row"><div class="ideal-ico"></div><div class="ideal-name">Corporate retreats and meeting groups seeking an off-grid, focused environment</div></div>
        <div class="ideal-row"><div class="ideal-ico"></div><div class="ideal-name">Large wedding guest groups requiring multiple units in a single compound</div></div>
        <div class="ideal-row"><div class="ideal-ico"></div><div class="ideal-name">Kitesurf groups and camps using Tribal Kite School directly on-site</div></div>
        <div class="ideal-row"><div class="ideal-ico"></div><div class="ideal-name">Wellness retreat organisers needing flexible, quiet space with pool access</div></div>
        <div class="ideal-row"><div class="ideal-ico"></div><div class="ideal-name">Conscious travellers seeking affordable, sustainable longer-term stays</div></div>
        <div class="ideal-row"><div class="ideal-ico"></div><div class="ideal-name">Large travel groups needing multiple units in a single managed location</div></div>
      </div>
      <div style="margin-top:1.4rem;">
        <a href="trip-builder.php" style="display:inline-flex;align-items:center;gap:.5rem;font-size:.75rem;letter-spacing:.2em;text-transform:uppercase;padding:.78rem 1.6rem;background:var(--teal);color:#fff;font-family:'Jost',sans-serif;transition:background .22s;" onmouseover="this.style.background='#102F3A'" onmouseout="this.style.background='#1E5C6B'">Plan My Group Retreat →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Photo Gallery — photos come from Admin → Venues → Maya Ilai → Gallery -->
<?php
$pg_venue_slug       = 'maya_ilai';                     // same venue as the hero gallery above (underscore, not a hyphen)
$pgrid_heading       = 'Explore <em>Maya Ilai</em>';
$pgrid_caption_extra = ' · <a href="maya-ilai-gallery.php" style="color:var(--teal);">View full gallery →</a>';
include __DIR__ . '/includes/property-photo-grid.php';
?>

    <!-- Tribal Dunes Context -->
    <div class="sec" id="tribal-dunes">
      <div class="sec-label">The Bigger Picture</div>
      <h2 class="sec-h">Part of <em>Tribal Dunes</em></h2>
      <div class="sec-rule"></div>
      <div class="td-box">
        <div class="td-eyebrow">Tribal Dunes · Kilifi Beachfront Ecosystem</div>
        <div class="td-h">More Than a Retreat — <em>A Community</em></div>
        <p class="td-p">Maya Ilai sits at the back of Tribal Dunes — Kilifi's integrated beachfront lifestyle destination. Walk through Somewhere Café to reach the beach. The Tribal Kite School is steps away. Maya Kobe boutique hotel is your neighbour. Everything is connected, everything is within walking distance.</p>
        <div class="td-nodes">
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">W</span></div>
            <div class="td-node-name">Maya Ilai</div>
            <div class="td-node-tag">Eco Compound · You are here</div>
          </div>
          <div class="td-node">
            <div class="td-node-ico"><span class="map-ico">H</span></div>
            <div class="td-node-name"><a href="maya-kobe.php" style="color:rgba(255,255,255,.82);text-decoration:none;">Maya Kobe</a></div>
            <div class="td-node-tag">Boutique Hotel · Kilifi</div>
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
            <div class="td-node-tag">Ocean Sports · Active</div>
          </div>
        </div>
        <a href="tribal-dunes.php" class="td-link">Discover Tribal Dunes →</a>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Location -->
    <div class="sec">
      <div class="sec-label">Location</div>
      <h2 class="sec-h">Tribal Dunes · <em>Bofa Beach Kilifi</em></h2>
      <div class="sec-rule"></div>
      <p class="sec-p">Maya Ilai occupies the rear section of the Tribal Dunes property on Bofa Road, Kilifi. The beach is approximately five minutes on foot, passing through Somewhere Café and the heart of the ecosystem. Mombasa Moi International Airport is roughly one hour by road. Nairobi JKIA is the main international gateway, with a short connection to the coast.</p>
      <div class="map-block">
        <div class="map-info">
          <div class="map-eyebrow">Directions</div>
          <div class="map-h">Bofa Road · Kilifi · Kilifi County · Kenya</div>
          <div class="map-p">~1 hr from Mombasa Moi International (MBA) · Nairobi JKIA direct air link available</div>
        </div>
        <a href="https://maps.google.com/?q=Tribal+Dunes+Kilifi+Kenya" target="_blank" class="btn-map">Open in Google Maps →</a>
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
        <a href="maya-kobe.php" class="other-card">
          <img class="other-img" src="images/Maya-Kobe-1-hero.webp" alt="Maya Kobe boutique hotel Kilifi">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Maya Kobe</div>
            <div class="other-meta">5 Ocean Suites · Beachfront</div>
          </div>
          <div class="other-foot"><span class="other-type">Boutique Hotel</span><span class="other-link">View →</span></div>
        </a>
        <a href="tribal-dunes.php" class="other-card">
          <img class="other-img" src="images/maya_illai/SITE PHOTOS BAR AND POOL-images-1.jpg" alt="Tribal Dunes Kilifi ecosystem">
          <div class="other-body">
            <div class="other-loc">Kilifi</div>
            <div class="other-name">Tribal Dunes</div>
            <div class="other-meta">Beachfront Ecosystem · Kilifi</div>
          </div>
          <div class="other-foot"><span class="other-type">Community</span><span class="other-link">Discover →</span></div>
        </a>
        <a href="activities.php" class="other-card">
          <img class="other-img" src="images/maya_illai/best5.jpg" alt="Activities and experiences Kilifi">
          <div class="other-body">
            <div class="other-loc">Kilifi · Kenya Coast</div>
            <div class="other-name">Activities</div>
            <div class="other-meta">Kitesurfing · Safaris · Water Sports</div>
          </div>
          <div class="other-foot"><span class="other-type">Experiences</span><span class="other-link">Explore →</span></div>
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
          <div class="book-eyebrow">Eco Retreat Compound · Kilifi</div>
          <div class="book-name">Maya Ilai</div>
          <div class="book-loc">Tribal Dunes · Bofa Road · Kilifi · Kenya</div>
        </div>
      </div>

      <!-- Body -->
      <div class="book-body" style="padding:0">
        <!-- Age notice in sidebar -->
        <div class="sidebar-age-notice" style="padding:1rem 1.4rem .5rem;font-size:.72rem;color:var(--mid);border-bottom:1px solid var(--border)">
          <strong>Adults only — min. age 16.</strong> Guests 16–17 may stay unaccompanied. Alcohol consent form required for under-18s.
        </div>
        <?php $booking_slug = 'superior-suite'; include __DIR__ . '/includes/booking-widget.php'; ?>
      </div>

      <!-- Policy accordion -->
      <div class="book-pol-toggle">
        <button class="book-pol-btn" id="polBtn">Property Policies <span class="pol-chevron"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span></button>
        <div class="book-pol-body" id="polBody">
          <div class="book-pol-row"><span class="book-pol-k">Check-in</span><span class="book-pol-v">From 2:00 PM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Check-out</span><span class="book-pol-v">By 10:00 AM</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Min. Age</span><span class="book-pol-v">16 years</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Min. Stay</span><span class="book-pol-v">2 nights</span></div>
          <div class="book-pol-row"><span class="book-pol-k">Smoking</span><span class="book-pol-v">Designated areas</span></div>
        </div>
      </div>

      <!-- Features -->
      <div class="book-features">
        <div class="book-feat"><span class="book-feat-ico">·</span> Solar powered — 100% renewable energy</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Communal pool · Bar · Garden</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Electric bikes &amp; golf carts included</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Full compound buyout available</div>
        <div class="book-feat"><span class="book-feat-ico">·</span> Beach 5 min walk via Somewhere Café</div>
      </div>

      <!-- WhatsApp -->
      <a href="https://wa.me/254115115247" class="book-whatsapp">
        <i class="fab fa-whatsapp"></i> Chat on WhatsApp · +254 115 115 247
      </a>

    </div>
  </aside>

</div><!-- /page-wrap -->

<?php
$sbb_name = 'Maya Ilai';
$sbb_loc  = 'Kilifi · Tribal Dunes';
$sbb_meta = '16 Units · Adults 16+';
$sbb_cta  = 'Check Availability';
include __DIR__ . '/includes/sticky-book-bar.php';
?>

<?php include 'includes/footer.php'; ?>

<!-- Mobile sticky CTA -->
<div class="sticky-cta" id="stickyCta">
  <div>
    <div class="sticky-cta-info">Maya Ilai · Kilifi</div>
    <div class="sticky-cta-sub">16 Units · Solar Powered · Adults 16+</div>
  </div>
  <a href="#book" class="sticky-cta-btn">Book →</a>
</div>

<script>
// FAQ accordion
document.querySelectorAll('.faq-q').forEach(function(q){
  q.addEventListener('click', function(){
    q.closest('.faq-item').classList.toggle('open');
  });
});

// Scroll reveal
var obs = new IntersectionObserver(function(entries){
  entries.forEach(function(e){
    if(e.isIntersecting){ e.target.style.opacity='1'; e.target.style.transform='none'; }
  });
}, {threshold: 0.06});
document.querySelectorAll('.ideal-row,.amenity,.faq-item,.unit-card,.td-node,.sustain-item,.other-card').forEach(function(el, i){
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
