<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'Tribal Dunes Interactive Site Map · Kilifi · Tribal Sand';
$page_desc   = 'Explore the full grounds of Tribal Dunes in Kilifi, Kenya. Tap any marker to discover accommodation, pools, dining, sports and wellness facilities.';
$page_url    = 'https://tribalsand.com/interactive-site-map.php';
$page_image  = asset_url('images/tribal-dunes-map.jpg');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Tribal Dunes','url'=>'https://tribalsand.com/tribal-dunes.php'],
    ['name'=>'Interactive Site Map','url'=>'https://tribalsand.com/interactive-site-map.php'],
]);
require_once 'includes/head.php';
?>
<?php
// Auto-extract map image from reference if not yet available
$mapImg = 'images/tribal-dunes-map.jpg';
if (!file_exists($mapImg)) {
    $refFile = 'reference/tribalsand_sitemap.html';
    if (file_exists($refFile)) {
        $html = file_get_contents($refFile);
        if (preg_match('/data:image\/(\w+);base64,([^"\'>\s]+)/', $html, $m)) {
            @file_put_contents($mapImg, base64_decode($m[2]));
        }
    }
}
?>
<body class="ts-sitemap-page">
<?php include 'includes/header.php'; ?>

<style>
/* Tribal Dunes Site Map — page-specific overrides */
.sitemap-hero {
  background: var(--teal-d, #102F3A);
  padding: calc(var(--nav-h, 72px) + 2.5rem) var(--px, 5vw) 2rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.sitemap-eyebrow {
  font-size: .5rem;
  letter-spacing: .42em;
  text-transform: uppercase;
  color: var(--sand, #B8965A);
  margin-bottom: .6rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .65rem;
}
.sitemap-eyebrow::before, .sitemap-eyebrow::after {
  content: "";
  width: 20px;
  height: 1px;
  background: var(--sand, #B8965A);
}
.sitemap-h {
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(2.2rem, 4.5vw, 3.8rem);
  font-weight: 300;
  color: #fff;
  line-height: .95;
  margin-bottom: .6rem;
}
.sitemap-h em { font-style: italic; color: #D4B07A; }
.sitemap-sub {
  font-size: .7rem;
  color: rgba(212,196,172,.55);
  letter-spacing: .06em;
  line-height: 1.9;
  max-width: 440px;
}
.hero-rule {
  width: 1px;
  height: 30px;
  background: linear-gradient(to bottom, rgba(184,150,90,.45), transparent);
  margin: 1.2rem auto 0;
}

/* Map Layout */
.map-layout {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0 var(--px, 5vw) 60px;
  background: var(--teal-d, #102F3A);
  max-width: 1200px;
  margin: 0 auto;
  width: 100%;
}

/* Filter Bar */
.sidebar { width: 100%; padding: 0; margin-bottom: 1.2rem; }
.sidebar-panel {
  background: rgba(0,0,0,.3);
  border: 1px solid rgba(184,150,90,.18);
  border-radius: 6px;
  padding: 1rem 1.2rem;
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-wrap: wrap;
}
.sidebar-title {
  font-size: .52rem;
  letter-spacing: .3em;
  text-transform: uppercase;
  color: var(--sand, #B8965A);
  padding-right: 1rem;
  border-right: 1px solid rgba(184,150,90,.2);
  margin-right: .3rem;
  white-space: nowrap;
  flex-shrink: 0;
}
.filter-btn {
  display: flex;
  align-items: center;
  gap: .55rem;
  font-size: .68rem;
  letter-spacing: .06em;
  text-transform: uppercase;
  padding: .62rem 1.1rem;
  border: 1px solid rgba(184,150,90,.18);
  background: rgba(255,255,255,.03);
  color: rgba(242,232,214,.6);
  cursor: pointer;
  transition: all .22s;
  border-radius: 3px;
  white-space: nowrap;
}
.filter-btn:hover {
  color: #fff;
  background: rgba(184,150,90,.12);
  border-color: rgba(184,150,90,.5);
}
.filter-btn.on {
  color: #fff;
  background: var(--teal, #1E5C6B);
  border-color: var(--teal, #1E5C6B);
}
.filter-btn .f-ico { font-size: 1.05rem; line-height: 1; flex-shrink: 0; }
.filter-count {
  font-size: .58rem;
  font-weight: 500;
  color: rgba(184,150,90,.6);
  background: rgba(184,150,90,.12);
  padding: 1px 7px;
  border-radius: 20px;
  min-width: 22px;
  text-align: center;
}
.filter-btn.on .filter-count { color: var(--teal-d, #102F3A); background: #D4B07A; }
.dev-toggle {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-size: .6rem;
  letter-spacing: .08em;
  text-transform: uppercase;
  padding: .6rem 1rem;
  border: 1px dashed rgba(184,150,90,.22);
  background: none;
  color: rgba(184,150,90,.45);
  cursor: pointer;
  transition: all .2s;
  border-radius: 3px;
  white-space: nowrap;
  margin-left: auto;
}
.dev-toggle:hover { color: rgba(184,150,90,.9); border-color: rgba(184,150,90,.55); background: rgba(184,150,90,.06); }
.dev-toggle.on { background: rgba(233,69,96,.12); border-color: rgba(233,69,96,.5); color: rgba(233,69,96,.9); }

/* Map Container */
.map-container { width: 100%; display: flex; flex-direction: column; gap: 1rem; }
.map-frame {
  position: relative;
  border: 1px solid rgba(184,150,90,.2);
  box-shadow: 0 20px 60px rgba(0,0,0,.55);
  cursor: crosshair;
  width: 100%;
  padding-bottom: 67.65%;
  height: 0;
  overflow: hidden;
}
.map-frame.normal-cursor { cursor: default; }
.map-img {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  object-fit: cover;
  object-position: center center;
  display: block;
  user-select: none;
  pointer-events: none;
}
.map-frame::before, .map-frame::after {
  content: "";
  position: absolute;
  width: 16px; height: 16px;
  z-index: 10;
  pointer-events: none;
}
.map-frame::before { top: -1px; left: -1px; border-top: 1px solid var(--sand, #B8965A); border-left: 1px solid var(--sand, #B8965A); }
.map-frame::after { bottom: -1px; right: -1px; border-bottom: 1px solid var(--sand, #B8965A); border-right: 1px solid var(--sand, #B8965A); }

/* Dev coords tooltip */
.dev-coords {
  position: absolute;
  background: rgba(220,60,60,.92);
  color: #fff;
  font-size: .55rem;
  letter-spacing: .08em;
  padding: 5px 12px;
  pointer-events: none;
  opacity: 0;
  transition: opacity .15s;
  z-index: 300;
  white-space: nowrap;
  border-radius: 2px;
}
.dev-coords.show { opacity: 1; }

/* CTA row */
.map-cta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: .8rem;
  padding-top: .4rem;
}
.map-note { font-size: .54rem; color: rgba(184,150,90,.35); letter-spacing: .08em; }

/* DOTS */
.dot {
  position: absolute;
  width: 30px; height: 30px;
  border-radius: 50%;
  transform: translate(-50%, -50%);
  cursor: pointer;
  border: 2.5px solid rgba(255,255,255,.9);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  line-height: 1;
  transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s, opacity .3s;
  z-index: 10;
  box-shadow: 0 2px 10px rgba(0,0,0,.5), 0 0 0 0 rgba(255,255,255,.1);
}
.dot::after {
  content: "";
  position: absolute;
  inset: -6px;
  border-radius: 50%;
  background: inherit;
  opacity: .2;
  animation: ripple 2.8s ease-in-out infinite;
  z-index: -1;
}
@keyframes ripple { 0%,100%{transform:scale(1);opacity:.2} 55%{transform:scale(2.4);opacity:0} }
.dot:hover { transform: translate(-50%,-50%) scale(1.3); box-shadow: 0 0 0 6px rgba(255,255,255,.12), 0 6px 20px rgba(0,0,0,.5); z-index: 20; }
.dot.active { transform: translate(-50%,-50%) scale(1.35); z-index: 30; }
.dot.faded { opacity: .12; pointer-events: none; }

/* DOT LABEL */
.dot-lbl {
  position: absolute;
  bottom: calc(100% + 10px);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  background: #0a1820;
  color: #F2E8D6;
  font-size: .5rem;
  font-weight: 500;
  letter-spacing: .16em;
  text-transform: uppercase;
  white-space: nowrap;
  padding: 5px 12px;
  border: 1px solid rgba(184,150,90,.45);
  box-shadow: 0 4px 16px rgba(0,0,0,.6);
  opacity: 0;
  pointer-events: none;
  transition: opacity .18s, transform .18s;
}
.dot:hover .dot-lbl, .dot.active .dot-lbl { opacity: 1; transform: translateX(-50%) translateY(0); }

/* POPUP */
.popup {
  position: absolute;
  width: 252px;
  background: #0d1f2b;
  border: 1px solid rgba(184,150,90,.35);
  box-shadow: 0 24px 60px rgba(0,0,0,.75), 0 0 0 1px rgba(0,0,0,.3);
  z-index: 200;
  opacity: 0;
  transform: scale(.93) translateY(7px);
  transition: opacity .22s, transform .22s cubic-bezier(.34,1.56,.64,1);
  pointer-events: none;
}
.popup.visible { opacity: 1; transform: scale(1) translateY(0); pointer-events: auto; }
.popup-bar { height: 3px; }
.popup-body { padding: 18px 18px 16px; }
.popup-icon-row { display: flex; align-items: center; gap: 10px; margin-bottom: .65rem; }
.popup-big-ico {
  width: 38px; height: 38px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  border: 1.5px solid rgba(255,255,255,.15);
}
.popup-eyebrow {
  font-size: .46rem;
  letter-spacing: .28em;
  text-transform: uppercase;
  margin-bottom: .22rem;
  font-weight: 500;
}
.popup-title {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.25rem;
  font-weight: 500;
  color: #F2E8D6;
  line-height: 1.1;
}
.popup-rule { width: 22px; height: 1px; background: var(--sand, #B8965A); margin: .65rem 0 .7rem; opacity: .6; }
.popup-desc { font-size: .69rem; color: #b8a898; line-height: 1.78; margin-bottom: 1rem; }
.popup-meta { display: flex; gap: 1.2rem; border-top: 1px solid rgba(184,150,90,.18); padding-top: .85rem; }
.popup-meta-item { display: flex; flex-direction: column; gap: .2rem; }
.popup-meta-val { font-family: 'Cormorant Garamond', serif; font-size: 1rem; color: #D4B07A; font-weight: 500; }
.popup-meta-lbl { font-size: .44rem; letter-spacing: .2em; text-transform: uppercase; color: rgba(184,150,90,.55); }
.popup-close {
  position: absolute; top: 11px; right: 13px;
  background: none; border: none;
  color: rgba(212,196,172,.45);
  font-size: 16px; cursor: pointer; line-height: 1;
  transition: color .15s;
}
.popup-close:hover { color: #F2E8D6; }

/* Compass */
.compass { position: absolute; bottom: 1rem; right: 1rem; width: 40px; height: 40px; opacity: .38; pointer-events: none; z-index: 5; }

@media(max-width:900px){
  .map-layout { padding: 0 1rem 48px; }
  .sidebar-panel { padding: .8rem 1rem; gap: .4rem; }
  .sidebar-title { display: none; }
  .filter-btn { font-size: .6rem; padding: .52rem .85rem; }
  .dev-toggle { margin-left: 0; }
}
</style>

<!-- HERO -->
<div class="sitemap-hero">
  <div class="sitemap-eyebrow">Kenya's North Coast &middot; Kilifi</div>
  <h1 class="sitemap-h">Interactive <em>Site Map</em></h1>
  <p class="sitemap-sub">Explore the full grounds of Tribal Dunes. Tap any marker to discover each space.</p>
  <div class="hero-rule"></div>
</div>

<!-- MAP LAYOUT -->
<div class="map-layout">

  <!-- FILTER BAR -->
  <aside class="sidebar">
    <div class="sidebar-panel">
      <span class="sidebar-title">Filter by</span>

      <button class="filter-btn on" data-cat="all">
        <span class="f-ico"><span class="map-ico">M</span></span>
        <span class="filter-label">All</span>
        <span class="filter-count">28</span>
      </button>
      <button class="filter-btn" data-cat="accommodation">
        <span class="f-ico"><span class="map-ico">H</span></span>
        <span class="filter-label">Accommodation</span>
        <span class="filter-count">4</span>
      </button>
      <button class="filter-btn" data-cat="pool">
        <span class="f-ico"><span class="map-ico">P</span></span>
        <span class="filter-label">Pools</span>
        <span class="filter-count">4</span>
      </button>
      <button class="filter-btn" data-cat="sport">
        <span class="f-ico"><span class="map-ico">S</span></span>
        <span class="filter-label">Sport &amp; Wellness</span>
        <span class="filter-count">6</span>
      </button>
      <button class="filter-btn" data-cat="food">
        <span class="f-ico"><span class="map-ico">F</span></span>
        <span class="filter-label">Food &amp; Drink</span>
        <span class="filter-count">4</span>
      </button>
      <button class="filter-btn" data-cat="facilities">
        <span class="f-ico"><span class="map-ico">PK</span></span>
        <span class="filter-label">Facilities</span>
        <span class="filter-count">6</span>
      </button>
      <button class="filter-btn" data-cat="propfacilities">
        <span class="f-ico"><span class="map-ico">M</span></span>
        <span class="filter-label">Property Facilities</span>
        <span class="filter-count">4</span>
      </button>

      <button class="dev-toggle" id="devToggle">
        Position Mode
      </button>
    </div>
  </aside>

  <!-- MAP + CTA -->
  <div class="map-container">
    <div class="map-frame normal-cursor" id="mapFrame">
      <img class="map-img" id="mapImg" src="images/tribal-dunes-map.jpg" alt="Tribal Dunes Site Map — Kilifi, Kenya">

      <!-- Dev coords tooltip -->
      <div class="dev-coords" id="devCoords"></div>

      <!-- DOTS — Accommodation -->
      <div class="dot" style="background:#7B3A1E;left:17.8%;top:36.6%;" data-id="0" data-cat="accommodation"><span class="dot-lbl">Maya Ilai</span></div>
      <div class="dot" style="background:#7B3A1E;left:71.5%;top:52.8%;" data-id="1" data-cat="accommodation"><span class="dot-lbl">Maya Kobe</span></div>
      <div class="dot" style="background:#7B3A1E;left:57.7%;top:67.6%;" data-id="2" data-cat="accommodation"><span class="dot-lbl">MK Prestige Suite</span></div>
      <div class="dot" style="background:#7B3A1E;left:80.7%;top:22.8%;" data-id="3" data-cat="accommodation"><span class="dot-lbl">Off Duty</span></div>

      <!-- DOTS — Pools -->
      <div class="dot" style="background:#1E5C6B;left:19.5%;top:61.2%;" data-id="4" data-cat="pool"><span class="map-ico">P</span><span class="dot-lbl">Maya Ilai Pool</span></div>
      <div class="dot" style="background:#1E5C6B;left:63.8%;top:68.4%;" data-id="6" data-cat="pool"><span class="map-ico">P</span><span class="dot-lbl">Private Pool</span></div>
      <div class="dot" style="background:#1E5C6B;left:77.0%;top:58.0%;" data-id="7" data-cat="pool"><span class="map-ico">P</span><span class="dot-lbl">Main Pool</span></div>
      <div class="dot" style="background:#1E5C6B;left:87.1%;top:25.1%;" data-id="8" data-cat="pool"><span class="map-ico">P</span><span class="dot-lbl">Off Duty Pool</span></div>

      <!-- DOTS — Sport & Wellness -->
      <div class="dot" style="background:#2A6B2A;left:36.2%;top:42.7%;" data-id="9" data-cat="sport"><span class="map-ico">S</span><span class="dot-lbl">Pickleball Court</span></div>
      <div class="dot" style="background:#2A6B2A;left:89.5%;top:38.5%;" data-id="10" data-cat="sport"><span class="map-ico">S</span><span class="dot-lbl">Kite &amp; Water Sport</span></div>
      <div class="dot" style="background:#2A6B2A;left:82.5%;top:51.5%;" data-id="11" data-cat="sport"><span class="map-ico">S</span><span class="dot-lbl">Beach Volley Court</span></div>
      <div class="dot" style="background:#2A6B2A;left:84.0%;top:31.0%;" data-id="12" data-cat="sport"><span class="map-ico">S</span><span class="dot-lbl">Yoga Deck</span></div>
      <div class="dot" style="background:#2A6B2A;left:75.4%;top:27.7%;" data-id="13" data-cat="sport"><span class="map-ico">S</span><span class="dot-lbl">Gym, Spa &amp; Shops</span></div>
      <div class="dot" style="background:#2A6B2A;left:57.3%;top:40.0%;" data-id="37" data-cat="sport"><span class="map-ico">S</span><span class="dot-lbl">Coral Restoration Lab</span></div>

      <!-- DOTS — Facilities -->
      <div class="dot" style="background:#3A4A58;left:28%;top:11%;" data-id="19" data-cat="facilities"><span class="map-ico">PK</span><span class="dot-lbl">Parking A</span></div>
      <div class="dot" style="background:#3A4A58;left:54%;top:10%;" data-id="20" data-cat="facilities"><span class="map-ico">PK</span><span class="dot-lbl">Parking B</span></div>
      <div class="dot" style="background:#3A4A58;left:58.8%;top:27.8%;" data-id="21" data-cat="facilities"><span class="map-ico">PK</span><span class="dot-lbl">Main Entrance</span></div>
      <div class="dot" style="background:#3A4A58;left:26.5%;top:77.3%;" data-id="30" data-cat="facilities"><span class="map-ico">PK</span><span class="dot-lbl">Maya Kobe Gate</span></div>
      <div class="dot" style="background:#3A4A58;left:37.1%;top:22.5%;" data-id="31" data-cat="facilities"><span class="map-ico">H</span><span class="dot-lbl">Welcome Desk</span></div>
      <div class="dot" style="background:#3A4A58;left:64.7%;top:26.0%;" data-id="32" data-cat="facilities"><span class="map-ico">AD</span><span class="dot-lbl">Admin Office</span></div>

      <!-- DOTS — Food & Drink -->
      <div class="dot" style="background:#9C7A2A;left:70.3%;top:32.3%;" data-id="15" data-cat="food"><span class="map-ico">F</span><span class="dot-lbl">Tribal Table</span></div>
      <div class="dot" style="background:#9C7A2A;left:90.0%;top:20.9%;" data-id="16" data-cat="food"><span class="map-ico">F</span><span class="dot-lbl">Somewhere Cafe</span></div>
      <div class="dot" style="background:#9C7A2A;left:32.6%;top:35.5%;" data-id="17" data-cat="food"><span class="map-ico">F</span><span class="dot-lbl">Agora Boatel Bar</span></div>
      <div class="dot" style="background:#9C7A2A;left:18.0%;top:47.5%;" data-id="18" data-cat="food"><span class="map-ico">F</span><span class="dot-lbl">Moon Bar</span></div>

      <!-- DOTS — Property Facilities -->
      <div class="dot" style="background:#5A3A6A;left:40.3%;top:65.1%;" data-id="33" data-cat="propfacilities"><span class="map-ico">M</span><span class="dot-lbl">Staff House</span></div>
      <div class="dot" style="background:#5A3A6A;left:43.3%;top:55.1%;" data-id="34" data-cat="propfacilities"><span class="map-ico">L</span><span class="dot-lbl">Laundry</span></div>
      <div class="dot" style="background:#5A3A6A;left:8.0%;top:45.1%;" data-id="35" data-cat="propfacilities"><span class="map-ico">S</span><span class="dot-lbl">Energy &amp; Water Hub</span></div>
      <div class="dot" style="background:#5A3A6A;left:36.2%;top:52.4%;" data-id="36" data-cat="propfacilities"><span class="map-ico">SC</span><span class="dot-lbl">Security Control Room</span></div>

      <!-- POPUP -->
      <div class="popup" id="popup">
        <div class="popup-bar" id="popupBar"></div>
        <div class="popup-body">
          <div class="popup-icon-row">
            <div class="popup-big-ico" id="popupIco"></div>
            <div>
              <div class="popup-eyebrow" id="popupCat"></div>
              <div class="popup-title" id="popupTitle"></div>
            </div>
          </div>
          <div class="popup-rule"></div>
          <div class="popup-desc" id="popupDesc"></div>
          <div class="popup-meta" id="popupMeta"></div>
        </div>
        <button class="popup-close" id="popupClose" aria-label="Close">✕</button>
      </div>

      <!-- Compass SVG -->
      <svg class="compass" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="20" cy="20" r="19" stroke="rgba(184,150,90,.4)" stroke-width="1"/>
        <path d="M20 4l3 12h-6z" fill="rgba(184,150,90,.7)"/>
        <path d="M20 36l-3-12h6z" fill="rgba(255,255,255,.3)"/>
        <circle cx="20" cy="20" r="2" fill="rgba(184,150,90,.6)"/>
        <text x="20" y="8" text-anchor="middle" font-size="4" fill="rgba(184,150,90,.8)" font-family="Jost,sans-serif">N</text>
      </svg>
    </div>

    <!-- CTA Row -->
    <div class="map-cta">
      <span class="map-note">Tap any marker to explore · Filter by category above</span>
      <div style="display:flex;gap:.75rem;">
        <a href="tribal-dunes.php" class="btn-ghost" style="font-size:.55rem;letter-spacing:.22em;text-transform:uppercase;padding:.75rem 1.8rem;background:none;border:1px solid rgba(184,150,90,.3);color:#D4B07A;text-decoration:none;transition:all .2s;">← Back to Tribal Dunes</a>
        <a href="trip-builder.php" class="btn-primary" style="font-size:.55rem;letter-spacing:.22em;text-transform:uppercase;padding:.75rem 1.8rem;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);text-decoration:none;transition:background .2s;border:none;">Plan Your Stay</a>
      </div>
    </div>
  </div>

</div><!-- /.map-layout -->

<script>
var locations = [
  {id:0,  name:"Maya Ilai",           cat:"Accommodation",       ico:"H", color:"#9B5A2E", desc:"Boutique hotel set within lush tropical gardens on the north coast. A curated collection of rooms and suites with refined coastal character.", meta:[["Type","Boutique Hotel"],["View","Garden"]]},
  {id:1,  name:"Maya Kobe",           cat:"Accommodation",       ico:"H", color:"#9B5A2E", desc:"Beachfront property with direct ocean access, offering a range of suites and villa rooms each finished with an earthy, artisan aesthetic.", meta:[["Type","Beachfront"],["View","Ocean"]]},
  {id:2,  name:"MK Prestige Suite",   cat:"Accommodation",       ico:"H", color:"#9B5A2E", desc:"The most exclusive suite at Maya Kobe. Oversized living spaces, a private terrace and bespoke butler service for the ultimate indulgence.", meta:[["Type","Suite"],["Service","Butler"]]},
  {id:3,  name:"Off Duty",            cat:"Accommodation",       ico:"H", color:"#9B5A2E", desc:"A relaxed, free-spirited villa concept designed for those who want privacy, nature and barefoot luxury all to themselves.", meta:[["Type","Villa"],["Style","Private"]]},
  {id:4,  name:"Maya Ilai Pool",      cat:"Pool",                ico:"P", color:"#1E7A8C", desc:"Serene freshwater pool nestled within the Maya Ilai gardens. Surrounded by palms with shaded loungers, attentive pool service and a hot tub for the perfect post-swim soak.", meta:[["Hours","7am–9pm"],["Hot Tub","Yes"]]},
  {id:6,  name:"Private Pool",        cat:"Pool",                ico:"P", color:"#1E7A8C", desc:"Exclusive pool reserved for Prestige Suite and selected villa guests. Complete privacy amid tropical planting.", meta:[["Access","Private"],["Style","Villa"]]},
  {id:7,  name:"Main Pool",           cat:"Pool",                ico:"P", color:"#1E7A8C", desc:"The social heart of Maya Kobe — a generous pool deck with sunbeds, a pool bar and uninterrupted views toward the Indian Ocean.", meta:[["Hours","7am–10pm"],["Bar","Poolside"]]},
  {id:8,  name:"Off Duty Pool",       cat:"Pool",                ico:"P", color:"#1E7A8C", desc:"Private pool for Off Duty guests. Surrounded by mature tropical planting for complete seclusion and maximum relaxation.", meta:[["Access","Exclusive"],["View","Garden"]]},
  {id:9,  name:"Pickleball Court",    cat:"Sport & Wellness",    ico:"S", color:"#2E7A2E", desc:"A full-size pickleball court set within the grounds. Paddles and balls available from reception. Open for casual play and organised sessions.", meta:[["Courts","1"],["Equipment","Provided"]]},
  {id:10, name:"Kite & Water Sport",  cat:"Sport & Wellness",    ico:"S", color:"#2E7A2E", desc:"The water sports hub managed by Tribal Kite School. Kite surfing, stand-up paddleboarding, snorkelling and more on Kenya's best kite coast.", meta:[["Beach","Private"],["School","On-site"]]},
  {id:11, name:"Beach Volley Court",  cat:"Sport & Wellness",    ico:"S", color:"#2E7A2E", desc:"Beachside volleyball court open to all guests. Perfect for afternoon matches with the Indian Ocean as your backdrop.", meta:[["Location","Beachfront"],["Open","Daily"]]},
  {id:12, name:"Yoga Deck",           cat:"Sport & Wellness",    ico:"S", color:"#2E7A2E", desc:"Open-air yoga and meditation platform overlooking the ocean. Morning sessions guided by resident instructors. Mats and props provided.", meta:[["Sessions","Daily"],["Level","All"]]},
  {id:13, name:"Gym, Spa & Shops",    cat:"Sport & Wellness",    ico:"S", color:"#2E7A2E", desc:"Open-air fitness area, spa sanctuary with massages and body treatments, plus a curated selection of boutique shops — all in one vibrant wellness hub.", meta:[["Gym","6am–8pm"],["Spa","9am–7pm"]]},
  {id:15, name:"Tribal Table",        cat:"Food & Drink",        ico:"F", color:"#B8965A", desc:"The signature dining experience at Tribal Sand. Locally sourced coastal cuisine served in an open-air setting — a celebration of Kenya's flavours.", meta:[["Style","Fine Dining"],["Hours","7pm–10pm"]]},
  {id:16, name:"Somewhere Cafe",      cat:"Food & Drink",        ico:"F", color:"#B8965A", desc:"A laid-back cafe for morning coffee, light lunches and afternoon bites — and a smart working hub for digital nomads. Fast Wi-Fi, quiet corners and a relaxed creative atmosphere.", meta:[["Hours","7am–6pm"],["WiFi","Smart Working"]]},
  {id:17, name:"Agora Boatel Bar",    cat:"Food & Drink",        ico:"F", color:"#B8965A", desc:"The social hub of the property — a gathering space to meet, talk, watch the game and unwind. Upstairs bar, live events, sports screenings and pure good vibes all week long.", meta:[["Hours","12pm–Late"],["Style","Events & Bar"]]},
  {id:18, name:"Moon Bar",            cat:"Food & Drink",        ico:"F", color:"#B8965A", desc:"Romantic open-air bar illuminated by lanterns and moonlight. The place for evening cocktails, live music and unforgettable sunsets.", meta:[["Hours","5pm–late"],["Style","Sunset Bar"]]},
  {id:19, name:"Parking A",           cat:"Facilities",          ico:"PK", color:"#4A5A6A", desc:"North-west parking area with 20 spaces, including a covered section for villa guests. Direct pathway to the main lobby.", meta:[["Spaces","20"],["Covered","Yes"]]},
  {id:20, name:"Parking B",           cat:"Facilities",          ico:"PK", color:"#4A5A6A", desc:"Primary north parking lot adjacent to the entrance road. Valet service and guest shuttle transfers available on request.", meta:[["Spaces","35"],["Valet","Yes"]]},
  {id:21, name:"Main Entrance",       cat:"Facilities",          ico:"PK", color:"#4A5A6A", desc:"Gated arrival courtyard with welcome pavilion, security post and shuttle drop-off zone. Where the Tribal Sand experience begins.", meta:[["Hours","24 hrs"],["Transfer","Yes"]]},
  {id:30, name:"Maya Kobe Gate",      cat:"Facilities",          ico:"PK", color:"#4A5A6A", desc:"Main gated entrance to the Maya Kobe side of the property. Security-controlled access for guests and vehicles.", meta:[["Hours","24 hrs"],["Access","Gated"]]},
  {id:31, name:"Welcome Desk",        cat:"Facilities",          ico:"H", color:"#4A5A6A", desc:"Guest welcome desk for arrivals, enquiries and concierge assistance. First point of contact on property.", meta:[["Hours","24 hrs"],["Service","Concierge"]]},
  {id:32, name:"Admin Office",        cat:"Facilities",          ico:"AD", color:"#4A5A6A", desc:"Administrative offices for property management and operations team. Staff access only.", meta:[["Access","Staff"],["Function","Admin"]]},
  {id:33, name:"Staff House",         cat:"Property Facilities", ico:"M", color:"#5A3A6A", desc:"Staff accommodation and rest facilities, supporting the dedicated team that keeps the property running smoothly.", meta:[["Access","Staff Only"],["Type","Residential"]]},
  {id:34, name:"Laundry",             cat:"Property Facilities", ico:"L", color:"#5A3A6A", desc:"On-site laundry and linen management facility servicing all rooms, villas and F&B outlets across the property.", meta:[["Hours","Daily"],["Access","Staff"]]},
  {id:35, name:"Energy & Water Hub",  cat:"Property Facilities", ico:"S", color:"#5A3A6A", desc:"Central energy and water management facility — solar systems, water treatment and sustainability infrastructure.", meta:[["System","Solar+Water"],["Type","Infrastructure"]]},
  {id:36, name:"Security Control Room",cat:"Property Facilities",ico:"SC", color:"#5A3A6A", desc:"24-hour security monitoring and control centre managing all access points and CCTV systems across the estate.", meta:[["Hours","24 hrs"],["Coverage","Full Estate"]]},
  {id:37, name:"Coral Restoration Lab",cat:"Sport & Wellness",   ico:"S", color:"#2E7A2E", desc:"Tribal Sand's marine conservation programme. Guests can participate in coral restoration activities and learn about reef ecosystems.", meta:[["Open","Daily"],["Activity","Guided"]]},
];

var popup  = document.getElementById("popup");
var pClose = document.getElementById("popupClose");
var active = null;
var devMode = false;

function openPopup(dot) {
  var id  = parseInt(dot.dataset.id);
  var loc = locations.find(function(l){ return l.id === id; });
  var frame = document.getElementById("mapFrame");
  var fRect = frame.getBoundingClientRect();
  var dRect = dot.getBoundingClientRect();

  document.getElementById("popupBar").style.background = "linear-gradient(90deg,"+loc.color+",transparent)";
  var ico = document.getElementById("popupIco");
  ico.textContent = loc.ico;
  ico.style.background = loc.color + "33";
  ico.style.borderColor = loc.color + "55";
  document.getElementById("popupCat").textContent  = loc.cat;
  document.getElementById("popupCat").style.color  = "#D4B07A";
  document.getElementById("popupTitle").textContent = loc.name;
  document.getElementById("popupDesc").textContent  = loc.desc;
  document.getElementById("popupMeta").innerHTML = loc.meta.map(function(m){
    return "<div class='popup-meta-item'><div class='popup-meta-val'>"+m[1]+"</div><div class='popup-meta-lbl'>"+m[0]+"</div></div>";
  }).join("");

  var left = dRect.left - fRect.left + dRect.width / 2;
  var top  = dRect.top  - fRect.top  + dRect.height + 10;
  if (left + 262 > frame.offsetWidth)  left = left - 266;
  if (left < 4) left = 4;
  if (top + 240 > frame.offsetHeight) top = dRect.top - fRect.top - 245;
  if (top < 4) top = 4;
  popup.style.left = left + "px";
  popup.style.top  = top  + "px";
  popup.classList.add("visible");
}

document.querySelectorAll(".dot").forEach(function(dot){
  dot.addEventListener("click", function(e){
    e.stopPropagation();
    if (devMode) return;
    if (active === dot && popup.classList.contains("visible")){ closePopup(); return; }
    if (active) active.classList.remove("active");
    active = dot; dot.classList.add("active");
    openPopup(dot);
  });
});

function closePopup(){
  popup.classList.remove("visible");
  if(active){ active.classList.remove("active"); active = null; }
}
pClose.addEventListener("click", closePopup);
document.getElementById("mapFrame").addEventListener("click", function(e){
  if(e.target === this || e.target.id === "mapImg") closePopup();
});
window.addEventListener("resize", function(){ if(active) openPopup(active); });

/* Filters */
document.querySelectorAll(".filter-btn[data-cat]").forEach(function(btn){
  btn.addEventListener("click", function(){
    document.querySelectorAll(".filter-btn[data-cat]").forEach(function(b){ b.classList.remove("on"); });
    btn.classList.add("on");
    var cat = btn.dataset.cat.toLowerCase();
    closePopup();
    document.querySelectorAll(".dot").forEach(function(dot){
      var id  = parseInt(dot.dataset.id);
      var loc = locations.find(function(l){ return l.id === id; });
      var catMap = {
        "accommodation": "accommodation",
        "pool": "pool",
        "sport": "sport & wellness",
        "food": "food & drink",
        "facilities": "facilities",
        "propfacilities": "property facilities"
      };
      var mapped = catMap[cat] || cat;
      var match = cat === "all" || loc.cat.toLowerCase() === mapped;
      dot.classList.toggle("faded", !match);
    });
  });
});

/* Position Mode */
var devCoords = document.getElementById("devCoords");
var devToggle = document.getElementById("devToggle");
var frame     = document.getElementById("mapFrame");

devToggle.addEventListener("click", function(){
  devMode = !devMode;
  devToggle.classList.toggle("on", devMode);
  frame.classList.toggle("normal-cursor", !devMode);
  frame.style.cursor = devMode ? "crosshair" : "default";
  if (!devMode) devCoords.classList.remove("show");
  closePopup();
});

frame.addEventListener("mousemove", function(e){
  if (!devMode) return;
  var r = frame.getBoundingClientRect();
  var x = ((e.clientX - r.left) / r.width * 100).toFixed(1);
  var y = ((e.clientY - r.top)  / r.height * 100).toFixed(1);
  devCoords.textContent = "left:" + x + "%  top:" + y + "% — click to copy";
  var cx = e.clientX - r.left;
  var cy = e.clientY - r.top - 38;
  devCoords.style.left = Math.min(cx, r.width - 230) + "px";
  devCoords.style.top  = Math.max(cy, 4) + "px";
  devCoords.style.transform = "none";
  devCoords.classList.add("show");
});

frame.addEventListener("mouseleave", function(){
  devCoords.classList.remove("show");
});

frame.addEventListener("click", function(e){
  if (!devMode) return;
  var r = frame.getBoundingClientRect();
  var x = ((e.clientX - r.left) / r.width * 100).toFixed(1);
  var y = ((e.clientY - r.top)  / r.height * 100).toFixed(1);
  var txt = "left:" + x + "%  top:" + y + "%";
  navigator.clipboard.writeText(txt).catch(function(){});
  devCoords.textContent = "Copied: " + txt;
  devCoords.style.background = "rgba(30,92,107,.95)";
  setTimeout(function(){ devCoords.style.background = ""; }, 1400);
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
