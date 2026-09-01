<?php
require_once 'includes/schema.php';
require_once __DIR__ . '/includes/page-content.php'; // editable slots (Admin → Page Content)

/* ── SEO Variables ── */
$page_title  = 'Beachfront Wedding Venues in Kilifi & Watamu · Kenya Coast · Tribal Sand';
$page_desc   = 'Beachfront wedding venues on Kenya\'s North Coast. Private beach ceremonies in Kilifi, Watamu and Vipingo — exclusive-use properties, full wedding planning and accommodation for your guests. Enquire for availability and pricing.';
$page_url    = 'https://tribalsand.com/events.php';
$page_image  = asset_url('images/event-gallery/AfricanNight-260.jpg');

/* ── Structured Data ── */
$page_schema =
    ts_schema_org() .
    ts_schema_breadcrumb([
        ['name' => 'Home',     'url' => 'https://tribalsand.com/'],
        ['name' => 'Weddings', 'url' => 'https://tribalsand.com/events.php'],
    ]) .
    // FAQ schema earns the expandable answers in Google's results — the questions
    // are the ones people actually search before booking a coast wedding.
    ts_schema_faq($EV_FAQS = [
        ['q' => 'What is the best beachfront wedding venue in Kilifi, Kenya?',
         'a' => 'Tribal Sand operates several beachfront wedding venues on Kenya\'s North Coast. Maya Kobe in Kilifi offers a private beach setting on Bofa Beach with five ocean-facing suites and a 20m pool, while Maya Ilai hosts larger celebrations across sixteen units. Zuri in Watamu suits intimate ceremonies of up to fourteen guests, and My Amani in Vipingo is a fully private beachfront villa estate.'],
        ['q' => 'Can we have a private beach ceremony in Kenya?',
         'a' => 'Yes. All Tribal Sand coastal properties sit directly on the Indian Ocean and are available for exclusive use, so your ceremony, reception and guest accommodation happen on one private site with no other guests present.'],
        ['q' => 'How many guests can a Tribal Sand wedding host?',
         'a' => 'Intimate ceremonies from ten guests up to celebrations of around a hundred. Zuri suits up to fourteen guests on full buyout, Maya Kobe up to roughly forty, and Maya Ilai\'s sixteen-unit compound handles the largest groups.'],
        ['q' => 'Do you provide accommodation for wedding guests?',
         'a' => 'Yes. Every venue includes on-site accommodation, so your wedding party stays where the celebration happens. Exclusive-use buyouts are available so the whole property belongs to your group.'],
        ['q' => 'What is the best time of year for a beach wedding on the Kenya coast?',
         'a' => 'The most reliable months are December to March and July to October, when the coast is dry and sunny. April, May and November are the wetter months. Our team advises on dates once we know your preferred season.'],
        ['q' => 'What does a beachfront wedding in Kenya include?',
         'a' => 'Our concierge team coordinates venue preparation, catering, decor, photography, music, transfers and guest accommodation. You receive a tailored proposal covering every element after your enquiry, with no payment required at enquiry stage.'],
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
.ev-hero-h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.2rem,5vw,3.8rem);font-weight:300;color:#fff;line-height:1.1;margin-bottom:1rem;}

/* ── EVENTS HERO ── */
.ev-hero{
  background:var(--teal-d);   /* photo is applied inline so admin can change it */
  background-size:cover;background-position:center 35%;background-repeat:no-repeat;
  padding:108px 5vw 80px;
  text-align:center;
  position:relative;
  overflow:hidden;
}
.ev-hero::before{
  content:'';
  position:absolute;inset:0;
  /* Darkened so the headline stays legible over the photograph */
  /* Light enough to see the photograph, dark enough to keep the headline legible */
  /* Photo stays visible; a soft vignette plus a centre wash keeps the copy legible
     over a busy image (this hero sits on top of a night-time crowd shot). */
  background:radial-gradient(ellipse at 50% 48%, rgba(10,28,36,.72) 0%, rgba(10,28,36,.30) 62%, transparent 78%),
             linear-gradient(to bottom, rgba(10,28,36,.62) 0%, rgba(10,28,36,.34) 42%, rgba(10,28,36,.82) 100%);
  pointer-events:none;
}
.ev-hero-trust{
  display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.55rem .9rem;
  margin-top:1.6rem;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;
  color:rgba(232,222,205,.92);
}
.ev-hero-dot{width:3px;height:3px;border-radius:50%;background:rgba(184,150,90,.55);}
.ev-hero::after{
  content:'';
  position:absolute;bottom:0;left:0;right:0;height:1px;
  background:linear-gradient(to right, transparent, rgba(184,150,90,.2), transparent);
}
.ev-hero-inner{
  position:relative;z-index:1;
  text-shadow:0 1px 14px rgba(6,20,26,.55);
  max-width:800px;margin:0 auto;
}
.ev-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.85);
  margin-bottom:1.2rem;
}
.ev-h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.4rem,5vw,4.2rem);
  font-weight:300;color:#fff;
  line-height:1.05;margin-bottom:1.4rem;
}
.ev-h1 em{font-style:italic;color:var(--sand-lt);}
.ev-sub{
  font-size:1rem;font-weight:400;
  color:rgba(212,196,172,.78);
  line-height:1.88;max-width:600px;margin:0 auto 2.2rem;
}
.ev-hero-ctas{
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
.btn-sand:hover{background:var(--sand-lt);border-color:var(--sand-lt);color:var(--teal-d);}
.btn-outline{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:400;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;background:none;
  border:1px solid rgba(255,255,255,.28);
  color:rgba(255,255,255,.8);
  transition:border-color .22s,color .22s;
}
.btn-outline:hover{border-color:rgba(255,255,255,.65);color:#fff;}

/* ── BREADCRUMB ── */
.breadcrumb{
  padding:.9rem 52px;background:var(--white);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;list-style:none;
}
.breadcrumb a{font-family:'Jost',sans-serif;font-size:.75rem;letter-spacing:.1em;color:var(--light);transition:color .2s;}
.breadcrumb a:hover{color:var(--teal);}
.breadcrumb-sep{font-size:.75rem;color:rgba(184,150,90,.4);}
.breadcrumb-curr{font-family:'Jost',sans-serif;font-size:.75rem;letter-spacing:.1em;color:var(--sand);}

/* ── INTRO SECTION ── */
.ev-intro{
  padding:80px 5vw 72px;
  background:var(--white);
}
.ev-intro-inner{
  max-width:1200px;margin:0 auto;
  display:grid;grid-template-columns:1fr 1fr;gap:5rem;align-items:start;
}
.ev-intro-text .sec-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);display:flex;align-items:center;gap:.65rem;
  margin-bottom:.65rem;
}
.ev-intro-text .sec-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-intro-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.9rem,3vw,3rem);
  font-weight:400;color:var(--dark);line-height:1.05;
  margin-bottom:1.4rem;
}
.ev-intro-h em{font-style:italic;color:var(--teal);}
.ev-intro-p{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.88;margin-bottom:1rem;}
.ev-intro-features{
  display:flex;flex-direction:column;gap:.75rem;
  margin-top:1.8rem;
}
.ev-feat{
  display:flex;align-items:flex-start;gap:.9rem;
  padding:.95rem 1.1rem;
  border:1px solid var(--border);
  background:var(--off);
  transition:border-color .2s;
}
.ev-feat:hover{border-color:rgba(184,150,90,.28);}
.ev-feat-icon{font-size:1.1rem;flex-shrink:0;margin-top:.05rem;}
.ev-feat-text{font-size:1rem;font-weight:400;color:var(--mid);line-height:1.65;}
.ev-feat-text strong{color:var(--dark);font-weight:500;}

/* ── EVENT TYPE CARDS ── */
.ev-types{
  padding:88px 5vw;background:var(--off);
}
.ev-types-inner{max-width:1200px;margin:0 auto;}
.ev-types-header{text-align:center;margin-bottom:3.5rem;}
.ev-types-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);display:inline-flex;align-items:center;gap:.65rem;
  margin-bottom:.65rem;
}
.ev-types-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-types-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:400;color:var(--dark);line-height:1.05;
}
.ev-types-h em{font-style:italic;color:var(--teal);}
.ev-cards-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(310px,1fr));
  gap:1.6rem;
}
.ev-card{
  background:var(--white);
  border:1px solid var(--border);
  padding:2.2rem 2rem 2rem;
  transition:box-shadow .22s,border-color .22s;
  display:flex;flex-direction:column;gap:.7rem;
  position:relative;overflow:hidden;
}
.ev-card::before{
  content:'';
  position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(to right, var(--teal-d), var(--teal));
  opacity:0;transition:opacity .25s;
}
.ev-card:hover{
  box-shadow:0 8px 36px rgba(16,47,58,.11);
  border-color:rgba(30,92,107,.22);
}
.ev-card:hover::before{opacity:1;}
.ev-card-icon{font-size:1.8rem;line-height:1;}
.ev-card-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.35rem;font-weight:400;color:var(--dark);
}
.ev-card-desc{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.78;flex:1;}
.ev-card-link{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--sand);
  border-bottom:1px solid rgba(184,150,90,.3);
  padding-bottom:.08rem;
  align-self:flex-start;
  transition:color .2s,border-color .2s;
  margin-top:.3rem;
}
.ev-card-link:hover{color:var(--teal);border-color:var(--teal);}

/* ── VENUES SECTION ── */
.ev-venues{
  padding:88px 5vw;
  background:var(--white);
}
.ev-venues-inner{max-width:1200px;margin:0 auto;}
.ev-venues-header{margin-bottom:3rem;}
.ev-venues-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);display:flex;align-items:center;gap:.65rem;margin-bottom:.65rem;
}
.ev-venues-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-venues-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.9rem,3vw,2.8rem);
  font-weight:400;color:var(--dark);line-height:1.05;
}
.ev-venues-h em{font-style:italic;color:var(--teal);}
/* ── BEACHFRONT WEDDING VENUES ── */
.ev-wed{background:#fff;padding:5rem 5vw;}
.ev-wed-inner{max-width:1120px;margin:0 auto;display:grid;grid-template-columns:1.1fr .9fr;gap:3.4rem;align-items:center;}
.ev-wed-eyebrow{font-size:.6rem;letter-spacing:.3em;text-transform:uppercase;color:var(--sand);margin-bottom:.8rem;}
.ev-wed-h{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:clamp(1.9rem,3.6vw,2.8rem);color:var(--dark,#141412);line-height:1.14;margin-bottom:1.1rem;}
.ev-wed-h em{font-style:italic;color:var(--teal,#1E5C6B);}
.ev-wed-p{color:var(--mid,#6B6050);font-size:.98rem;line-height:1.85;margin-bottom:1rem;}
.ev-wed-pills{display:flex;flex-wrap:wrap;gap:.5rem;margin:1.4rem 0 1.5rem;}
.ev-wed-media{border-radius:8px;overflow:hidden;background:#0e2a36;}
.ev-wed-media img{width:100%;aspect-ratio:4/5;object-fit:cover;display:block;}
@media(max-width:900px){.ev-wed-inner{grid-template-columns:1fr;gap:2rem;}.ev-wed-media{order:-1;}}

/* ── FAQ ── */
.ev-faq{background:#fff;padding:5rem 5vw;}
.ev-faq-inner{max-width:820px;margin:0 auto;}
.ev-faq-header{text-align:center;margin-bottom:2.2rem;}
.ev-faq-item{border-bottom:1px solid rgba(184,150,90,.18);}
.ev-faq-item summary{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  cursor:pointer;list-style:none;padding:1.15rem 0;
  font-family:'Jost',sans-serif;font-size:1rem;color:var(--dark,#141412);
}
.ev-faq-item summary::-webkit-details-marker{display:none;}
.ev-faq-item summary::marker{content:'';}
.ev-faq-chev{color:var(--sand);font-size:.8rem;transition:transform .25s;flex-shrink:0;}
.ev-faq-item[open] summary{color:var(--teal,#1E5C6B);}
.ev-faq-item[open] .ev-faq-chev{transform:rotate(180deg);}
.ev-faq-item p{margin:0 0 1.2rem;color:var(--mid,#6B6050);font-size:.94rem;line-height:1.8;padding-right:2rem;}

/* ── ENQUIRY FORM ── */
.ev-form-sec{background:var(--teal-d,#102F3A);padding:5rem 5vw;}
.ev-form-wrap{max-width:1080px;margin:0 auto;display:grid;grid-template-columns:1fr 1.15fr;gap:3rem;align-items:start;}
.ev-form-eyebrow{font-size:.6rem;letter-spacing:.3em;text-transform:uppercase;color:var(--sand);margin-bottom:.8rem;}
.ev-form-h{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:clamp(1.9rem,3.4vw,2.7rem);color:#fff;line-height:1.14;margin-bottom:.9rem;}
.ev-form-h em{font-style:italic;color:var(--sand);}
.ev-form-p{color:rgba(212,196,172,.82);font-size:.95rem;line-height:1.75;margin-bottom:1.4rem;}
.ev-form-list{list-style:none;padding:0;margin:0 0 1.6rem;}
.ev-form-list li{color:rgba(212,196,172,.75);font-size:.86rem;padding:.4rem 0 .4rem 1.3rem;position:relative;}
.ev-form-list li::before{content:'';position:absolute;left:0;top:.95rem;width:6px;height:6px;border-radius:50%;background:var(--sand);}
.ev-form-cross{margin-top:1.4rem;font-size:.86rem;color:rgba(212,196,172,.6);}
.ev-form-cross a{color:var(--sand);border-bottom:1px solid rgba(184,150,90,.3);}
.ev-form-wa{display:inline-block;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--sand);border-bottom:1px solid rgba(184,150,90,.35);padding-bottom:2px;}
.ev-form{background:#fff;border-radius:10px;padding:1.9rem;box-shadow:0 24px 60px rgba(0,0,0,.28);}
.ev-f-row{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;}
.ev-f{margin-bottom:.9rem;display:flex;flex-direction:column;}
.ev-f label{font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--mid,#6B6050);margin-bottom:.4rem;}
.ev-f label span{color:var(--sand);}
.ev-opt{color:rgba(107,96,80,.5)!important;letter-spacing:.08em;text-transform:none;font-size:.66rem;}
.ev-f input,.ev-f select,.ev-f textarea{
  width:100%;padding:.72rem .85rem;border:1px solid rgba(184,150,90,.28);border-radius:5px;
  font-family:'Jost',sans-serif;font-size:.95rem;color:var(--dark,#141412);background:#fff;
  transition:border-color .2s,box-shadow .2s;-webkit-appearance:none;appearance:none;
}
.ev-f select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23B8965A' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .85rem center;padding-right:2rem;}
.ev-f input:focus,.ev-f select:focus,.ev-f textarea:focus{outline:none;border-color:var(--teal,#1E5C6B);box-shadow:0 0 0 3px rgba(30,92,107,.09);}
.ev-f input.err,.ev-f textarea.err{border-color:#c0392b;}
.ev-hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;opacity:0!important;}
.ev-f-btn{width:100%;margin-top:.5rem;background:var(--sand,#B8965A);color:#102F3A;border:none;border-radius:5px;padding:.95rem;
  font-family:'Jost',sans-serif;font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s;}
.ev-f-btn:hover{background:#C9A86A;}
.ev-f-btn[disabled]{opacity:.6;cursor:default;}
.ev-f-note{text-align:center;font-size:.72rem;color:rgba(107,96,80,.7);margin-top:.7rem;}
.ev-f-msg{margin-top:.8rem;font-size:.86rem;text-align:center;}
.ev-f-msg.ok{color:#1E7A4C;}
.ev-f-msg.bad{color:#c0392b;}
@media(max-width:860px){.ev-form-wrap{grid-template-columns:1fr;gap:2rem;}.ev-f-row{grid-template-columns:1fr;}}

/* ── WEDDING GALLERY ── */
.ev-gal{background:var(--off,#FAF8F4);padding:5rem 5vw;}
.ev-gal-inner{max-width:1200px;margin:0 auto;}
.ev-gal-header{text-align:center;max-width:640px;margin:0 auto 2.4rem;}
.ev-gal-eyebrow{font-size:.6rem;letter-spacing:.3em;text-transform:uppercase;color:var(--sand);margin-bottom:.7rem;}
.ev-gal-h{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:clamp(1.8rem,3.4vw,2.6rem);color:var(--dark);line-height:1.15;margin-bottom:.6rem;}
.ev-gal-h em{font-style:italic;color:var(--teal);}
.ev-gal-p{color:var(--mid);font-size:.95rem;line-height:1.7;}
.ev-gal-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;}
.ev-gal-item{display:block;overflow:hidden;border-radius:4px;background:#0e2a36;}
.ev-gal-item img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;transition:transform .45s cubic-bezier(.22,1,.36,1);}
.ev-gal-item:hover img{transform:scale(1.05);}
.ev-gal-more{text-align:center;margin-top:1.8rem;}
@media(max-width:900px){.ev-gal-grid{grid-template-columns:repeat(2,1fr);}}

.ev-venue-img{margin:-1.6rem -1.6rem 1.2rem;border-radius:8px 8px 0 0;overflow:hidden;background:#0e2a36;}
.ev-venue-img img{width:100%;aspect-ratio:16/10;object-fit:cover;display:block;}
.ev-venues-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(270px,1fr));
  gap:1.5rem;
}
.ev-venue{
  border:1px solid var(--border);
  background:var(--off);
  padding:1.9rem 1.7rem;
  transition:box-shadow .22s,border-color .22s;
}
.ev-venue:hover{box-shadow:0 6px 28px rgba(16,47,58,.09);border-color:rgba(184,150,90,.28);}
.ev-venue-tag{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.7rem;font-weight:500;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--teal-d);
  background:var(--sand-pale);
  padding:.28rem .7rem;
  margin-bottom:.9rem;
}
.ev-venue-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.3rem;font-weight:400;color:var(--dark);
  margin-bottom:.2rem;
}
.ev-venue-loc{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:400;letter-spacing:.1em;
  color:var(--light);text-transform:uppercase;
  margin-bottom:.9rem;
}
.ev-venue-desc{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.75;margin-bottom:1rem;}
.ev-venue-pills{
  display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:1.1rem;
}
.ev-pill{
  font-family:'Jost',sans-serif;
  font-size:.7rem;font-weight:400;letter-spacing:.08em;
  color:var(--mid);
  border:1px solid var(--border);
  padding:.22rem .65rem;
  background:var(--white);
}
.ev-venue-link{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--sand);
  border-bottom:1px solid rgba(184,150,90,.3);
  padding-bottom:.08rem;
  transition:color .2s,border-color .2s;
}
.ev-venue-link:hover{color:var(--teal);border-color:var(--teal);}

/* ── WHY SECTION ── */
.ev-why{
  background:var(--teal-d);
  padding:88px 5vw;
  position:relative;overflow:hidden;
}
.ev-why::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 20% 50%, rgba(30,92,107,.6) 0%, transparent 60%);
  pointer-events:none;
}
.ev-why-inner{max-width:1100px;margin:0 auto;position:relative;z-index:1;}
.ev-why-header{text-align:center;margin-bottom:3.5rem;}
.ev-why-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:rgba(184,150,90,.85);
  display:inline-flex;align-items:center;gap:.65rem;
  margin-bottom:.8rem;
}
.ev-why-eyebrow::before{content:'';width:18px;height:1px;background:rgba(184,150,90,.85);flex-shrink:0;}
.ev-why-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:300;color:#fff;line-height:1.05;
}
.ev-why-h em{font-style:italic;color:var(--sand-lt);}
.ev-why-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
  gap:1.4rem;
}
.ev-why-item{
  background:rgba(255,255,255,.04);
  border:1px solid rgba(184,150,90,.12);
  padding:1.8rem 1.6rem;
  transition:background .2s,border-color .2s;
}
.ev-why-item:hover{background:rgba(255,255,255,.07);border-color:rgba(184,150,90,.22);}
.ev-why-num{
  font-family:'Cormorant Garamond',serif;
  font-size:2rem;font-weight:300;
  color:rgba(184,150,90,.3);
  margin-bottom:.5rem;line-height:1;
}
.ev-why-title{
  font-family:'Cormorant Garamond',serif;
  font-size:1.15rem;font-weight:400;
  color:#fff;margin-bottom:.5rem;
}
.ev-why-text{font-size:1rem;font-weight:400;color:rgba(212,196,172,.7);line-height:1.75;}

/* ── BOTTOM CTA ── */
.ev-cta{
  background:var(--off);
  padding:88px 5vw;
  border-top:1px solid var(--border);
}
.ev-cta-inner{
  max-width:700px;margin:0 auto;text-align:center;
}
.ev-cta-eyebrow{
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.28em;text-transform:uppercase;
  color:var(--sand);
  display:inline-flex;align-items:center;gap:.65rem;
  margin-bottom:.9rem;
}
.ev-cta-eyebrow::before{content:'';width:18px;height:1px;background:var(--sand);flex-shrink:0;}
.ev-cta-h{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:400;color:var(--dark);line-height:1.05;margin-bottom:.9rem;
}
.ev-cta-h em{font-style:italic;color:var(--teal);}
.ev-cta-p{font-size:1rem;font-weight:400;color:#4a3d2e;line-height:1.88;margin-bottom:.5rem;}
.ev-cta-note{
  font-family:'Jost',sans-serif;
  font-size:.8rem;font-weight:400;
  color:var(--light);letter-spacing:.05em;
  margin-bottom:2rem;
}
.ev-cta-btns{
  display:flex;flex-wrap:wrap;gap:1rem;justify-content:center;margin-bottom:1.6rem;
}
.btn-teal{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:500;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;
  background:var(--teal-d);color:var(--sand-lt);
  border:1px solid var(--teal-d);
  transition:background .22s,border-color .22s;
}
.btn-teal:hover{background:var(--teal);border-color:var(--teal);}
.btn-teal-ghost{
  display:inline-block;
  font-family:'Jost',sans-serif;
  font-size:.75rem;font-weight:400;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.88rem 2.2rem;background:none;
  border:1px solid var(--border);
  color:var(--teal);
  transition:border-color .22s,background .22s,color .22s;
}
.btn-teal-ghost:hover{border-color:var(--teal);background:rgba(30,92,107,.05);color:var(--teal-d);}
.ev-cta-wa{
  display:inline-flex;align-items:center;gap:.55rem;
  font-family:'Jost',sans-serif;
  font-size:.85rem;font-weight:400;
  color:var(--mid);
  transition:color .2s;
}
.ev-cta-wa:hover{color:var(--teal);}
.ev-cta-wa-dot{
  width:8px;height:8px;border-radius:50%;
  background:#25D366;flex-shrink:0;
}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .ev-intro-inner{grid-template-columns:1fr;gap:2.5rem;}
}
@media(max-width:768px){
  .ev-hero{padding:88px 5vw 60px;}
  .ev-types{padding:60px 5vw;}
  .ev-venues{padding:60px 5vw;}
  .ev-why{padding:64px 5vw;}
  .ev-cta{padding:64px 5vw;}
  .ev-cards-grid{grid-template-columns:1fr;}
  .ev-venues-grid{grid-template-columns:1fr;}
  .ev-why-grid{grid-template-columns:1fr;}
  .breadcrumb{padding:.9rem 20px;}
}
@media(max-width:480px){
  .ev-hero{padding:84px 5vw 52px;}
  .ev-hero-ctas{flex-direction:column;align-items:center;}
  .ev-cta-btns{flex-direction:column;align-items:center;}
}
</style>
</head>

<body>

<?php include 'includes/header.php'; ?>

<!-- ═══ ELEGANT HERO ═══ -->
<section class="ev-hero" style="background-image:url('<?= e(page_image('weddings','hero_image')) ?>')">
  <div class="ev-hero-inner">
    <div class="ev-eyebrow"><?= page_text('weddings','hero_eyebrow') ?></div>
    <h1 class="ev-hero-h1"><?= page_html('weddings','hero_title') ?></h1>
    <p class="ev-sub"><?= page_text('weddings','hero_sub') ?></p>
    <div class="ev-hero-ctas">
      <a href="#enquire" class="btn-sand">Check Your Date &rarr;</a>
      <a href="#venues" class="btn-outline">See the Venues</a>
    </div>
    <div class="ev-hero-trust">
      <span>Reply within 24 hours</span><span class="ev-hero-dot"></span>
      <span>No payment at enquiry</span><span class="ev-hero-dot"></span>
      <span>Exclusive-use buyouts</span>
    </div>
  </div>
</section>

<!-- ═══ BREADCRUMB ═══ -->
<nav class="breadcrumb" aria-label="Breadcrumb">
  <a href="./">Home</a>
  <span class="breadcrumb-sep">&rsaquo;</span>
  <span class="breadcrumb-curr">Beachfront Weddings</span>
</nav>


<!-- ═══ BEACHFRONT WEDDING VENUES (primary keyword section) ═══ -->
<section class="ev-wed" id="wedding-venues">
  <div class="ev-wed-inner">
    <div class="ev-wed-text">
      <div class="ev-wed-eyebrow"><?= page_text('weddings','wed_eyebrow') ?></div>
      <h2 class="ev-wed-h"><?= page_html('weddings','wed_title') ?></h2>
      <p class="ev-wed-p"><?= page_text('weddings','wed_body1') ?></p>
      <p class="ev-wed-p"><?= page_text('weddings','wed_body2') ?></p>
      <div class="ev-wed-pills">
        <span class="ev-pill">Private beach ceremonies</span>
        <span class="ev-pill">Exclusive-use buyouts</span>
        <span class="ev-pill">10&ndash;100 guests</span>
        <span class="ev-pill">Guest accommodation on site</span>
      </div>
      <a href="#enquire" class="ev-venue-link">Check your date &rarr;</a>
    </div>
    <div class="ev-wed-media">
      <img src="<?= e(page_image('weddings','wed_image')) ?>"
           alt="Beachfront wedding venue on the sand at Tribal Sand, Kilifi, Kenya"
           loading="lazy" decoding="async" width="600" height="750">
    </div>
  </div>
</section>

<!-- ═══ INTRO ═══ -->
<section class="ev-intro">
  <div class="ev-intro-inner">
    <div class="ev-intro-text">
      <div class="sec-eyebrow">Full Planning Support</div>
      <h2 class="ev-intro-h">Private beachfront weddings in <em>Kilifi &amp; Watamu</em></h2>
      <p class="ev-intro-p">Whether you are planning an intimate beachfront wedding for twenty or a celebration for a hundred, Tribal Sand's coastal properties in Kilifi, Watamu and Vipingo offer some of the best beachfront wedding venues in Kenya — with full service support behind them.</p>
      <p class="ev-intro-p">Our concierge team coordinates every element — venue preparation, catering, décor, photography, music, logistics and accommodation. You focus on the occasion. We handle the rest.</p>
      <p class="ev-intro-p">From the coral-fringed shores of <a href="zuri.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.22);">Zuri in Watamu</a> to the creek-side elegance of <a href="maya-kobe.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.22);">Maya Kobe in Kilifi</a> and the ultra-private estate of <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.22);">My Amani in Vipingo</a> — the Kenya coast provides a backdrop unlike any other.</p>
    </div>
    <div class="ev-intro-features">
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>24-hour quote response</strong> — tell us your dates, group size and vision. We come back with a full proposal within one working day.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>No payment required at enquiry</strong> — explore options, compare properties and receive detailed quotes before committing to anything.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>Trusted supplier network</strong> — established relationships with the best caterers, photographers, florists, DJs and event stylists on the Kenya coast.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>Three coastal locations</strong> — Watamu, Kilifi and Vipingo. Each distinct in character, all offering the same uncompromising level of service.</div>
      </div>
      <div class="ev-feat">
        <div class="ev-feat-icon"></div>
        <div class="ev-feat-text"><strong>Full buyout available</strong> — Zuri, Maya Kobe and My Amani can each be taken on exclusive use, giving your group complete privacy.</div>
      </div>
    </div>
  </div>
</section>

<!-- ═══ EVENT TYPE CARDS ═══ -->
<section class="ev-types" id="event-types">
  <div class="ev-types-inner">
    <div class="ev-types-header">
      <div class="ev-types-eyebrow">What We Arrange</div>
      <h2 class="ev-types-h">Every kind of <em>wedding &amp; celebration</em></h2>
    </div>
    <div class="ev-cards-grid">

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Destination Weddings</div>
        <p class="ev-card-desc">Boutique beachfront weddings on Kenya's North Coast. Barefoot ceremonies in the sand, candlelit dinners under the stars, intimate settings for 10 to 50+ guests. <a href="zuri.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">Zuri</a>, <a href="maya-kobe.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">Maya Kobe</a> and <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">My Amani</a> all offer full property buyouts with dedicated event support.</p>
        <a href="trip-builder" class="ev-card-link">Enquire about weddings &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Corporate Retreats</div>
        <p class="ev-card-desc">Off-site meetings, leadership retreats, product launches and team building on the Kenya coast. <a href="maya_ilai.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">Maya Ilai eco compound</a> is ideal for larger groups; <a href="my-amani.php" style="color:var(--teal);border-bottom:1px solid rgba(30,92,107,.18);">My Amani</a> for executive retreats requiring complete privacy. Reliable connectivity and event space throughout.</p>
        <a href="trip-builder" class="ev-card-link">Enquire about retreats &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Private Celebrations</div>
        <p class="ev-card-desc">Milestone birthdays, anniversaries, family reunions and surprise parties. Full decoration coordination, custom menus, personalised styling and entertainment — all arranged by your concierge with complete discretion.</p>
        <a href="trip-builder" class="ev-card-link">Enquire about celebrations &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Honeymoons</div>
        <p class="ev-card-desc">Curated honeymoon packages across Tribal Sand properties — private beach dinners, in-villa massage, sunset dhow cruises on Kilifi Creek, champagne arrivals and complete seclusion. Kenya's coast is one of Africa's most romantic destinations.</p>
        <a href="trip-builder" class="ev-card-link">Plan your honeymoon &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Group Getaways</div>
        <p class="ev-card-desc">Full property buyouts for friend groups and extended families. Coordinated activities — kitesurfing, safaris, deep sea fishing, dhow cruises, bush dinners — all arranged through a single concierge point of contact for your whole group.</p>
        <a href="activities.php" class="ev-card-link">See all activities &rarr;</a>
      </div>

      <div class="ev-card">
        <div class="ev-card-icon"></div>
        <div class="ev-card-title">Private Dining Events</div>
        <p class="ev-card-desc">Beach dinners with white-linen service and the Indian Ocean at your feet. Swahili feasts, sunset cocktail receptions on Kilifi Creek, rooftop starlight suppers. The Tribal Table concept — now open in Kilifi — offers elevated private dining experiences across all locations.</p>
        <a href="trip-builder" class="ev-card-link">Arrange a dinner &rarr;</a>
      </div>

    </div>
  </div>
</section>

<!-- ═══ VENUES / PROPERTIES ═══ -->
<section class="ev-venues" id="venues">
  <div class="ev-venues-inner">
    <div class="ev-venues-header">
      <div class="ev-venues-eyebrow">Wedding Venues &middot; Kilifi, Watamu &amp; Vipingo</div>
      <h2 class="ev-venues-h">Our beachfront <em>wedding venues</em></h2>
    </div>
    <div class="ev-venues-grid">

      <div class="ev-venue">
        <div class="ev-venue-img"><img src="<?= e(venue_hero_url('zuri', '')) ?>" alt="Beachfront wedding venue at Zuri, Watamu — Kenya North Coast" loading="lazy" decoding="async"></div>
        <div class="ev-venue-tag">Intimate Weddings &middot; Honeymoons</div>
        <div class="ev-venue-name">Zuri</div>
        <div class="ev-venue-loc">Watamu, Kilifi County</div>
        <p class="ev-venue-desc">Six ocean-facing suites on a private stretch of Watamu beach. Full buyout for up to 14 guests makes Zuri the perfect setting for intimate destination weddings, honeymoon escapes and small group celebrations.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">6 Suites</span>
          <span class="ev-pill">Up to 14 guests</span>
          <span class="ev-pill">Buyout available</span>
          <span class="ev-pill">Watamu Marine Park</span>
        </div>
        <a href="zuri.php" class="ev-venue-link">View Zuri &rarr;</a>
      </div>

      <div class="ev-venue">
        <div class="ev-venue-img"><img src="<?= e(venue_hero_url('maya-kobe', '')) ?>" alt="Beachfront wedding venue at Maya Kobe, Kilifi — Kenya North Coast" loading="lazy" decoding="async"></div>
        <div class="ev-venue-tag">Boutique Weddings &middot; Group Celebrations</div>
        <div class="ev-venue-name">Maya Kobe</div>
        <div class="ev-venue-loc">Bofa Beach, Kilifi</div>
        <p class="ev-venue-desc">Five suites plus the Prestige Suite on a dramatic elevated beachfront above Kilifi Creek. Rooftop terrace, lush gardens and a Swahili architectural aesthetic make Maya Kobe the most romantic event venue on the coast.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">6 Suites inc. Prestige</span>
          <span class="ev-pill">Rooftop terrace</span>
          <span class="ev-pill">Buyout available</span>
          <span class="ev-pill">Creek views</span>
        </div>
        <a href="maya-kobe.php" class="ev-venue-link">View Maya Kobe &rarr;</a>
      </div>

      <div class="ev-venue">
        <div class="ev-venue-img"><img src="<?= e(venue_hero_url('my-amani', '')) ?>" alt="Beachfront wedding venue at My Amani, Vipingo — Kenya North Coast" loading="lazy" decoding="async"></div>
        <div class="ev-venue-tag">Ultra-Private &middot; Executive Retreats</div>
        <div class="ev-venue-name">My Amani</div>
        <div class="ev-venue-loc">Vipingo, Kilifi County</div>
        <p class="ev-venue-desc">A full private villa estate on Vipingo's secluded beachfront. Adjacent to Vipingo Ridge Golf Club. Ideal for executive retreats, ultra-private family celebrations and exclusive group buyouts with total seclusion.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">Full villa estate</span>
          <span class="ev-pill">Golf course adjacent</span>
          <span class="ev-pill">Total privacy</span>
          <span class="ev-pill">Turtle conservation</span>
        </div>
        <a href="my-amani.php" class="ev-venue-link">View My Amani &rarr;</a>
      </div>

      <div class="ev-venue">
        <div class="ev-venue-img"><img src="<?= e(venue_hero_url('maya_ilai', '')) ?>" alt="Beachfront wedding venue at Maya Ilai, Kilifi — Kenya North Coast" loading="lazy" decoding="async"></div>
        <div class="ev-venue-tag">Large Weddings &middot; Corporate Groups</div>
        <div class="ev-venue-name">Maya Ilai</div>
        <div class="ev-venue-loc">Kilifi Creek</div>
        <p class="ev-venue-desc">An expansive eco-compound on Kilifi Creek — ideal for larger wedding groups, corporate team retreats and multi-day events. Multiple accommodation units, communal spaces and direct creek access in a lush, shaded setting.</p>
        <div class="ev-venue-pills">
          <span class="ev-pill">Large group capacity</span>
          <span class="ev-pill">Eco-compound</span>
          <span class="ev-pill">Creek access</span>
          <span class="ev-pill">Corporate ready</span>
        </div>
        <a href="maya_ilai.php" class="ev-venue-link">View Maya Ilai &rarr;</a>
      </div>

    </div>
  </div>
</section>

<!-- ═══ WHY TRIBAL SAND ═══ -->

<!-- ═══ WEDDING GALLERY ═══ -->
<section class="ev-gal" id="gallery">
  <div class="ev-gal-inner">
    <div class="ev-gal-header">
      <div class="ev-gal-eyebrow">Real Celebrations</div>
      <h2 class="ev-gal-h">Weddings and celebrations <em>on our beaches</em></h2>
      <p class="ev-gal-p">Every one of these took place at a Tribal Sand property on Kenya's North Coast.</p>
    </div>
    <div class="ev-gal-grid">
      <?php foreach ([
        ['gal_1', 'Beachfront wedding reception at night, Kilifi, Kenya'],
        ['gal_2', 'Wedding guests celebrating on the beach at Tribal Sand, Kenya'],
        ['gal_3', 'Private beach celebration table setting, Kenya North Coast'],
        ['gal_4', 'Evening celebration on the Indian Ocean shoreline, Kilifi'],
        ['gal_5', 'Beachfront dining set-up for a wedding party in Kenya'],
        ['gal_6', 'Guests at a private beachfront event, Watamu Kenya'],
        ['gal_7', 'Celebration on the beach at sunset, Kenya coast'],
        ['gal_8', 'Live music at a beachfront wedding reception in Kilifi'],
      ] as $i => $g): $__src = page_image('weddings', $g[0]); if ($__src === '') continue; ?>
      <a class="ev-gal-item" href="events-gallery.php" aria-label="<?= e($g[1]) ?>">
        <img src="<?= e($__src) ?>" alt="<?= e($g[1]) ?>"
             loading="<?= $i < 2 ? 'eager' : 'lazy' ?>" decoding="async" width="600" height="450">
      </a>
      <?php endforeach; ?>
    </div>
    <div class="ev-gal-more"><a href="events-gallery.php" class="ev-venue-link">See the full events gallery &rarr;</a></div>
  </div>
</section>

<section class="ev-why" id="why-tribal-sand">
  <div class="ev-why-inner">
    <div class="ev-why-header">
      <div class="ev-why-eyebrow">Why Tribal Sand</div>
      <h2 class="ev-why-h">What makes the <em>difference</em></h2>
    </div>
    <div class="ev-why-grid">

      <div class="ev-why-item">
        <div class="ev-why-num">01</div>
        <div class="ev-why-title">Dedicated concierge team</div>
        <p class="ev-why-text">One point of contact handles every element of your event — from initial enquiry through to checkout day. No handoffs, no gaps, no surprises.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">02</div>
        <div class="ev-why-title">No payment at enquiry</div>
        <p class="ev-why-text">Explore all options, receive full proposals and compare venues before committing to anything. We believe the planning process should be enjoyable, not pressured.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">03</div>
        <div class="ev-why-title">24-hour quote response</div>
        <p class="ev-why-text">Tell us your vision, group size and preferred dates. We respond with a detailed, itemised quote within one working day — faster during quieter periods.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">04</div>
        <div class="ev-why-title">Trusted supplier network</div>
        <p class="ev-why-text">We work with the best caterers, photographers, florists, sound engineers and event stylists on Kenya's North Coast — vetted, experienced and fully briefed on our standards.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">05</div>
        <div class="ev-why-title">Three distinct coastal locations</div>
        <p class="ev-why-text">Watamu, Kilifi and Vipingo — each with its own character, all accessible from Mombasa or Malindi airports. We can help you choose the right property for your event type and size.</p>
      </div>

      <div class="ev-why-item">
        <div class="ev-why-num">06</div>
        <div class="ev-why-title">Activities for your whole group</div>
        <p class="ev-why-text">Beyond the event itself — kitesurfing, safaris, deep sea fishing, dhow cruises and more. A full programme of <a href="activities.php" style="color:var(--sand-lt);border-bottom:1px solid rgba(184,150,90,.3);">activities on Kenya's coast</a> arranged around your event schedule.</p>
      </div>

    </div>
  </div>
</section>

<!-- ═══ BOTTOM CTA ═══ -->

<!-- ═══ ENQUIRY FORM (the conversion point for paid traffic) ═══ -->

<!-- ═══ FAQ — same $EV_FAQS array the FAQPage schema is built from, so the
     structured data can never drift from what the page actually says ═══ -->
<section class="ev-faq" id="faq">
  <div class="ev-faq-inner">
    <div class="ev-faq-header">
      <div class="ev-gal-eyebrow">Common Questions</div>
      <h2 class="ev-gal-h">Planning a wedding <em>on the Kenya coast</em></h2>
    </div>
    <?php foreach ($EV_FAQS as $f): ?>
    <details class="ev-faq-item">
      <summary><?= e($f['q']) ?><span class="ev-faq-chev" aria-hidden="true">&#9662;</span></summary>
      <p><?= e($f['a']) ?></p>
    </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="ev-form-sec" id="enquire">
  <div class="ev-form-wrap">
    <div class="ev-form-aside">
      <div class="ev-form-eyebrow">Check Your Date</div>
      <h2 class="ev-form-h"><?= page_html('weddings','form_title') ?></h2>
      <p class="ev-form-p"><?= page_text('weddings','form_body') ?></p>
      <ul class="ev-form-list">
        <li>Reply within 24 hours</li>
        <li>No payment at enquiry stage</li>
        <li>Exclusive-use buyouts available</li>
        <li>Accommodation for your whole party</li>
      </ul>
      <a href="https://wa.me/254115115247" class="ev-form-wa" target="_blank" rel="noopener noreferrer">Prefer WhatsApp? Message the team &rarr;</a>
      <p class="ev-form-cross">Planning a group retreat instead? See our <a href="retreats.php">beachfront retreat venues &rarr;</a></p>
    </div>

    <form class="ev-form" id="evForm" novalidate>
      <div class="ev-f-row">
        <div class="ev-f"><label for="ev_name">Your name <span>*</span></label>
          <input type="text" id="ev_name" name="name" required autocomplete="name"></div>
        <div class="ev-f"><label for="ev_email">Email <span>*</span></label>
          <input type="email" id="ev_email" name="email" required autocomplete="email"></div>
      </div>
      <div class="ev-f-row">
        <div class="ev-f"><label for="ev_phone">Phone / WhatsApp</label>
          <input type="tel" id="ev_phone" name="phone" autocomplete="tel"></div>
        <div class="ev-f"><label for="ev_type">Occasion</label>
          <select id="ev_type" name="event_type">
            <option value="Wedding">Wedding</option>
            <option value="Wedding + reception">Wedding &amp; reception</option>
            <option value="Honeymoon">Honeymoon</option>
            <option value="Anniversary">Anniversary</option>
            <option value="Birthday / celebration">Birthday / celebration</option>
            <option value="Corporate / retreat">Corporate / retreat</option>
            <option value="Other">Other</option>
          </select></div>
      </div>
      <div class="ev-f-row">
        <div class="ev-f"><label for="ev_date">Preferred date</label>
          <input type="text" id="ev_date" name="event_date" placeholder="e.g. July 2027, or flexible"></div>
        <div class="ev-f"><label for="ev_guests">Approx. guests</label>
          <input type="text" id="ev_guests" name="guest_count" inputmode="numeric" placeholder="e.g. 40"></div>
      </div>
      <div class="ev-f-row">
        <div class="ev-f"><label for="ev_venue">Venue of interest</label>
          <select id="ev_venue" name="venue">
            <option value="">Not sure yet — advise me</option>
            <option value="Maya Kobe · Kilifi">Maya Kobe &middot; Kilifi</option>
            <option value="Maya Ilai · Kilifi">Maya Ilai &middot; Kilifi</option>
            <option value="Zuri · Watamu">Zuri &middot; Watamu</option>
            <option value="My Amani · Vipingo">My Amani &middot; Vipingo</option>
            <option value="Enkare Bofa · Kilifi">Enkare Bofa &middot; Kilifi</option>
          </select></div>
        <div class="ev-f"><label for="ev_budget">Budget range <span class="ev-opt">optional</span></label>
          <select id="ev_budget" name="budget">
            <option value="">Prefer not to say</option>
            <option value="Under $5,000">Under $5,000</option>
            <option value="$5,000 – $15,000">$5,000 &ndash; $15,000</option>
            <option value="$15,000 – $30,000">$15,000 &ndash; $30,000</option>
            <option value="$30,000+">$30,000+</option>
          </select></div>
      </div>
      <div class="ev-f"><label for="ev_msg">Anything else? <span class="ev-opt">optional</span></label>
        <textarea id="ev_msg" name="message" rows="3" placeholder="Ceremony on the beach, live music, guests flying in from…"></textarea></div>

      <input type="text" name="website" class="ev-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:.4rem 0 .2rem"></div>

      <button type="submit" class="ev-f-btn" id="evSubmit">Send Enquiry &rarr;</button>
      <div class="ev-f-note">We reply within 24 hours. No payment is taken at this stage.</div>
      <div class="ev-f-msg" id="evMsg" role="status" aria-live="polite"></div>
    </form>
  </div>
</section>

<section class="ev-cta">
  <div class="ev-cta-inner">
    <div class="ev-cta-eyebrow">Start the Conversation</div>
    <h2 class="ev-cta-h">Ready to start <em>planning</em>?</h2>
    <p class="ev-cta-p">Whether your plans are firm or still forming — reach out. We respond within 24 hours with venue options, availability and a full proposal tailored to your occasion.</p>
    <div class="ev-cta-note">No payment required at the enquiry stage.</div>
    <div class="ev-cta-btns">
      <a href="#enquire" class="btn-teal">Enquire About Your Date &rarr;</a>
      <a href="https://wa.me/254115115247" class="btn-teal-ghost" target="_blank" rel="noopener">WhatsApp Us</a>
    </div>
    <a href="https://wa.me/254115115247" class="ev-cta-wa" target="_blank" rel="noopener noreferrer">
      <span class="ev-cta-wa-dot"></span>
      WhatsApp the team &rarr;
    </a>
  </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
/* ── ENQUIRY FORM ── */
(function(){
  var form = document.getElementById('evForm');
  if (!form) return;
  var btn = document.getElementById('evSubmit');
  var msg = document.getElementById('evMsg');
  var val = function(n){ var el = form.querySelector('[name="'+n+'"]'); return el ? el.value.trim() : ''; };

  function say(text, cls){ msg.textContent = text; msg.className = 'ev-f-msg ' + (cls || ''); }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    say('', '');

    // Only name + email are required; everything else is qualification detail.
    var bad = [];
    ['name','email'].forEach(function(n){
      var el = form.querySelector('[name="'+n+'"]');
      var ok = el.value.trim() !== '' && (n !== 'email' || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(el.value.trim()));
      el.classList.toggle('err', !ok);
      if (!ok) bad.push(el);
    });
    if (bad.length) { bad[0].focus(); say('Please add your name and a valid email.', 'bad'); return; }

    var original = btn.textContent;
    btn.textContent = 'Sending…'; btn.disabled = true;

    fetch('/api/submit-event.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        name: val('name'), email: val('email'), phone: val('phone'),
        event_type: val('event_type'), event_date: val('event_date'),
        guest_count: val('guest_count'), venue: val('venue'),
        budget: val('budget'), message: val('message'),
        website: val('website'),
        'cf-turnstile-response': (form.querySelector("[name='cf-turnstile-response']")||{}).value || ''
      })
    })
    .then(function(r){ return r.json(); })
    .then(function(r){
      btn.textContent = original; btn.disabled = false;
      if (!r || !r.ok) {
        var first = r && r.errors ? r.errors[Object.keys(r.errors)[0]] : null;
        throw new Error(first || (r && r.error) || 'error');
      }
      form.reset();
      if (window.turnstile && typeof window.turnstile.reset === 'function') window.turnstile.reset();
      if (typeof window.showSuccessModal === 'function') {
        window.showSuccessModal(
          'Enquiry Sent',
          'Thank you — our events team will come back to you within 24 hours with venue options and availability. For anything urgent, WhatsApp us on <a href="https://wa.me/254115115247" style="color:var(--teal);">+254 115 115 247</a>.',
          false
        );
        say('', '');
      } else {
        say('Thank you — we will reply within 24 hours.', 'ok');
      }
    })
    .catch(function(err){
      btn.textContent = original; btn.disabled = false;
      say(err.message && err.message !== 'error'
          ? err.message
          : 'Something went wrong. Please try again, or WhatsApp us on +254 115 115 247.', 'bad');
    });
  });
})();

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
