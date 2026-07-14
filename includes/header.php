<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500&display=swap" rel="stylesheet">

<!-- ═══════════════════════════════════════════════════════
     TRIBAL SAND · Navigation Header
     Drop into: includes/header.php
     ═══════════════════════════════════════════════════════ -->

<style>
/* ── TOKENS ── */
:root{
  --ts-sand:#B8965A;--ts-sand-lt:#D4B07A;
  --ts-teal:#1E5C6B;--ts-teal-d:#102F3A;
  --ts-border:rgba(184,150,90,.13);
}
.ts-nav *{box-sizing:border-box;margin:0;padding:0;}
.ts-nav a{text-decoration:none;}

/* ── MAIN NAV ── */
.ts-nav{
  position:fixed;top:0;left:0;right:0;z-index:9000;
  height:68px;display:flex;align-items:center;padding:0 44px;
  background:rgba(16,47,58,.97);
  transition:background .35s,height .3s,backdrop-filter .35s;
  border-bottom:1px solid rgba(184,150,90,.1);
}
.ts-nav.scrolled60{
  background:rgba(16,47,58,.97);
  backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-bottom-color:rgba(184,150,90,.1);
  height:60px;
}
.ts-nav.nav-open{background:rgba(16,47,58,.99);border-bottom-color:rgba(184,150,90,.1);}

/* ── LOGO ── */
.ts-logo{display:flex;align-items:center;flex-shrink:0;margin-right:auto;}
.ts-logo img{height:38px;width:auto;filter:brightness(0) invert(1);opacity:.88;transition:opacity .2s;}
.ts-logo:hover img{opacity:1;}

/* ── DESKTOP LINKS ── */
.ts-links{display:flex;align-items:center;}
.ts-item{position:relative;}
.ts-link{
  display:flex;align-items:center;gap:.35rem;
  font-family:'Jost',sans-serif;
  font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;
  color:rgba(255,255,255,.78);
  padding:.5rem .9rem;height:68px;
  background:none;border:none;cursor:pointer;
  transition:color .2s;white-space:nowrap;
}
.ts-link:hover,.ts-item:hover>.ts-link{color:#fff;}
.ts-link svg{width:8px;height:8px;flex-shrink:0;transition:transform .2s;opacity:.45;}
.ts-item:hover>.ts-link svg{transform:rotate(180deg);opacity:.8;}
.ts-nav.scrolled60 .ts-link{height:60px;}

/* ── DROPDOWN ── */
.ts-drop{
  position:absolute;top:calc(100% - 1px);left:0;
  background:rgba(14,42,54,.98);
  backdrop-filter:blur(24px);
  border:1px solid rgba(184,150,90,.12);
  border-top:2px solid var(--ts-sand);
  min-width:220px;padding:.5rem 0;
  opacity:0;visibility:hidden;transform:translateY(-8px);
  transition:all .22s cubic-bezier(.4,0,.2,1);
  pointer-events:none;
}
.ts-item:hover .ts-drop{opacity:1;visibility:visible;transform:none;pointer-events:all;}
.ts-drop-lbl{
  font-family:'Jost',sans-serif;font-size:.5rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.6);
  padding:.6rem 1.2rem .2rem;display:block;
}
.ts-drop a{
  display:flex;align-items:center;gap:.75rem;
  padding:.62rem 1.2rem;
  font-family:'Jost',sans-serif;font-size:.72rem;letter-spacing:.06em;
  color:rgba(212,196,172,.82);
  transition:color .18s,background .18s;
}
.ts-drop a:hover{color:#fff;background:rgba(184,150,90,.08);}
.ts-drop-div{height:1px;background:rgba(184,150,90,.1);margin:.35rem 0;}

/* Wide dropdown */
.ts-drop.wide{min-width:680px;display:grid;grid-template-columns:1fr 1fr 1fr;}
.ts-drop.wide-2{min-width:560px;display:grid;grid-template-columns:1fr 1fr;}
.ts-drop-col{padding:.6rem 0;display:flex;flex-direction:column;}
.ts-drop-col:first-child{border-right:1px solid rgba(184,150,90,.08);}
.ts-drop-col-footer{margin-top:auto;}

/* Property rows with thumbnail */
.ts-prop-row{
  display:flex;align-items:center;gap:.85rem;
  padding:.6rem 1.3rem;
  transition:background .18s;cursor:pointer;
}
.ts-prop-row:hover{background:rgba(184,150,90,.07);}
.ts-prop-row img{width:46px;height:36px;object-fit:cover;flex-shrink:0;opacity:.78;transition:opacity .2s;border:1px solid rgba(184,150,90,.1);}
.ts-prop-row:hover img{opacity:1;}
.ts-prop-name{font-family:'Jost',sans-serif;font-size:.72rem;letter-spacing:.05em;color:rgba(212,196,172,.9);line-height:1.2;white-space:nowrap;}
.ts-prop-loc{font-size:.6rem;letter-spacing:.08em;color:rgba(184,150,90,.65);margin-top:.06rem;}

/* ── RIGHT ACTIONS ── */
.ts-actions{display:flex;align-items:center;gap:.6rem;flex-shrink:0;}

.ts-tel{font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.1em;color:rgba(255,255,255,.5);transition:color .2s;display:none;}
.ts-tel:hover{color:var(--ts-sand-lt);}
.ts-btn-plan{
  font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;
  padding:.46rem 1.1rem;background:none;
  border:1px solid rgba(184,150,90,.38);color:var(--ts-sand-lt);
  transition:all .22s;cursor:pointer;white-space:nowrap;
}
.ts-btn-plan:hover{background:rgba(184,150,90,.12);border-color:var(--ts-sand);}
.ts-btn-book{
  font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;
  padding:.46rem 1.25rem;background:var(--ts-sand);
  border:1px solid var(--ts-sand);color:#102F3A;
  transition:all .22s;cursor:pointer;white-space:nowrap;font-weight:500;
}
.ts-btn-book:hover{background:var(--ts-sand-lt);border-color:var(--ts-sand-lt);}

/* ── HAMBURGER ── */
.ts-burger{
  display:none;position:relative;
  width:40px;height:40px;background:none;border:none;
  cursor:pointer;padding:0;margin-left:.5rem;
  -webkit-appearance:none;appearance:none;
}
/* Bars are absolutely positioned with an EXPLICIT px width so iOS/WebKit
   can never collapse them to 0 (empty flex children rendered invisible on
   iOS — the previous flex + width:100% approach did not hold on real devices). */
.ts-burger span{
  position:absolute;left:9px;width:22px;height:2px;border-radius:2px;
  background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.35);
  transition:transform .28s,opacity .28s;transform-origin:center;
}
.ts-burger span:nth-child(1){top:14px;}
.ts-burger span:nth-child(2){top:20px;}
.ts-burger span:nth-child(3){top:26px;}
.ts-burger.open span:nth-child(1){top:20px;transform:rotate(45deg);}
.ts-burger.open span:nth-child(2){opacity:0;}
.ts-burger.open span:nth-child(3){top:20px;transform:rotate(-45deg);}

/* ── MOBILE DRAWER ── */
.ts-drawer{
  display:none;position:fixed;
  top:60px;left:0;right:0;bottom:0;
  background:rgba(14,42,54,.99);
  overflow-y:auto;z-index:8999;
  transform:translateX(100%);
  transition:transform .32s cubic-bezier(.4,0,.2,1);
  padding:1rem 0 5rem;
}
.ts-drawer.open{transform:none;}
.ts-mob-lbl{font-family:'Jost',sans-serif;font-size:.54rem;letter-spacing:.26em;text-transform:uppercase;color:rgba(184,150,90,.6);padding:.65rem 1.5rem .25rem;display:block;}
.ts-mob-link{
  display:flex;align-items:center;justify-content:space-between;
  padding:.88rem 1.5rem;font-family:'Jost',sans-serif;
  font-size:.84rem;letter-spacing:.07em;
  color:rgba(212,196,172,.85);
  border-bottom:1px solid rgba(184,150,90,.07);
  transition:color .18s,background .18s;
}
.ts-mob-link:hover{color:#fff;background:rgba(184,150,90,.06);}
.ts-mob-arr{font-size:.65rem;color:rgba(184,150,90,.5);}
.ts-mob-prop{
  display:flex;align-items:center;gap:.9rem;
  padding:.72rem 1.5rem;
  border-bottom:1px solid rgba(184,150,90,.07);
  transition:background .18s;
}
.ts-mob-prop:hover{background:rgba(184,150,90,.06);}
.ts-mob-prop img{width:46px;height:36px;object-fit:cover;opacity:.72;}
.ts-mob-prop-name{font-family:'Jost',sans-serif;font-size:.78rem;color:rgba(212,196,172,.92);}
.ts-mob-prop-loc{font-size:.62rem;color:rgba(184,150,90,.65);margin-top:.06rem;}
.ts-mob-div{height:1px;background:rgba(184,150,90,.09);margin:.5rem 1.5rem;}
.ts-mob-actions{padding:1.4rem 1.5rem 0;display:flex;flex-direction:column;gap:.6rem;}
.ts-mob-btn{
  display:block;text-align:center;font-family:'Jost',sans-serif;
  font-size:.62rem;letter-spacing:.2em;text-transform:uppercase;padding:.88rem;transition:all .22s;
}
.ts-mob-btn-plan{border:1px solid rgba(184,150,90,.38);color:var(--ts-sand-lt);}
.ts-mob-btn-plan:hover{background:rgba(184,150,90,.1);}
.ts-mob-btn-book{background:var(--ts-sand);color:#102F3A;border:none;font-weight:500;}
.ts-mob-btn-book:hover{background:var(--ts-sand-lt);}


/* ── RESPONSIVE ── */
@media(max-width:1100px){
  .ts-links{display:none;}
  .ts-burger{display:block!important;position:relative;z-index:3;}
  .ts-drawer{display:block;}
  .ts-social,.ts-tel{display:none!important;}
  .ts-nav{padding:0 20px;}
  /* Plan Your Trip lives in the drawer on mobile; keep Book Now compact */
  .ts-btn-plan{display:none;}
  .ts-btn-book{padding:.44rem .8rem;font-size:.58rem;letter-spacing:.1em;}
  .ts-actions{gap:.5rem;}
  /* Language switcher lives in the drawer footer on mobile — keep the nav clear so the menu button is prominent */
  .ts-actions .gtranslate_wrapper{display:none;}
  .ts-burger{width:40px;height:40px;margin-left:.25rem;}
}
@media(max-width:360px){
  .ts-btn-book{display:none;} /* very small screens only: Book Now is in the drawer + sticky bar */
}
@media(min-width:1101px){.ts-drawer{display:none!important;}}
</style>

<nav class="ts-nav" id="tsNav">

  <a href="./" class="ts-logo"><img src="images/whitelogo11.png" alt="Tribal Sand"></a>

  <!-- Desktop links -->
  <div class="ts-links">

    <!-- Accommodations -->
    <div class="ts-item">
      <button class="ts-link">Accommodations
        <svg viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4 4-4"/></svg>
      </button>
      <div class="ts-drop wide">
        <div class="ts-drop-col">
          <span class="ts-drop-lbl">Beachfront Boutique Hotels</span>
          <a href="zuri.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/zuri/Aerial/zuri-3.webp" alt="Zuri">
            <div><div class="ts-prop-name">Zuri</div><div class="ts-prop-loc">Watamu · 6 Suites</div></div>
          </a>
          <a href="maya-kobe.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/Maya-Kobe-1-hero.webp" alt="Maya Kobe">
            <div><div class="ts-prop-name">Maya Kobe</div><div class="ts-prop-loc">Kilifi · 5 Suites</div></div>
          </a>
        </div>
        <div class="ts-drop-col">
          <span class="ts-drop-lbl">Beachfront Private Villas</span>
          <a href="my-amani.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/my-amani/Aerial/myamani-11.webp" alt="My Amani">
            <div><div class="ts-prop-name">My Amani</div><div class="ts-prop-loc">Vipingo · 5 Rooms</div></div>
          </a>
          <a href="enkare-bofa.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" alt="Enkare Bofa">
            <div><div class="ts-prop-name">Enkare Bofa</div><div class="ts-prop-loc">Kilifi · 5 Rooms</div></div>
          </a>
          <a href="sandbox.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/Sandbox/outdoors/IMG-20251117-WA0091.jpg" alt="Sandbox">
            <div><div class="ts-prop-name">Sandbox</div><div class="ts-prop-loc">Kilifi · 4 Rooms</div></div>
          </a>
        </div>
        <div class="ts-drop-col">
          <span class="ts-drop-lbl">Tribal Dunes · Kilifi</span>
          <a href="maya-kobe.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/Maya-Kobe-1-hero.webp" alt="Maya Kobe">
            <div><div class="ts-prop-name">Maya Kobe</div><div class="ts-prop-loc">Boutique Hotel</div></div>
          </a>
          <a href="maya_ilai.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/maya_illai/Best1.jpg" alt="Maya Ilai">
            <div><div class="ts-prop-name">Maya Ilai</div><div class="ts-prop-loc">Eco Compound</div></div>
          </a>
          <a href="off-duty.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/maya_illai/Studios/Studio1.jpeg" alt="Off Duty">
            <div><div class="ts-prop-name">Off Duty</div><div class="ts-prop-loc">Coworking Hotel</div></div>
          </a>
        </div>
      </div>
    </div>

    <!-- Experiences -->
    <div class="ts-item">
      <button class="ts-link">Experiences
        <svg viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4 4-4"/></svg>
      </button>
      <div class="ts-drop">
        <a href="activities.php">Activities</a>
        <a href="http://tribalkiteschool.com/" target="_blank">Kite School</a>
        <a href="content/TRIBAL-SAND-MENU.pdf" target="_blank">Dining Menu</a>
        <div class="ts-drop-div"></div>
        <span class="ts-drop-lbl">Tribal Dunes · Kilifi</span>
        <a href="tribal-table.php">Tribal Table</a>
        <a href="somewhere-cafe.php">Somewhere Café</a>
      </div>
    </div>

    <!-- Tribal Dunes -->
    <div class="ts-item">
      <button class="ts-link">Tribal Dunes
        <svg viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4 4-4"/></svg>
      </button>
      <div class="ts-drop wide-2">
        <div class="ts-drop-col">
          <span class="ts-drop-lbl">Kilifi · Beachfront</span>
          <a href="maya-kobe.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/Maya-Kobe-1-hero.webp" alt="Maya Kobe">
            <div><div class="ts-prop-name">Maya Kobe</div><div class="ts-prop-loc">Boutique Hotel · Kilifi</div></div>
          </a>
          <a href="maya_ilai.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/maya_illai/Best1.jpg" alt="Maya Ilai">
            <div><div class="ts-prop-name">Maya Ilai</div><div class="ts-prop-loc">Eco Compound · Kilifi</div></div>
          </a>
          <a href="off-duty.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/maya_illai/Studios/Studio1.jpeg" alt="Off Duty">
            <div><div class="ts-prop-name">Off Duty</div><div class="ts-prop-loc">Coworking Hotel · Kilifi</div></div>
          </a>
          <div class="ts-drop-col-footer">
            <div class="ts-drop-div"></div>
            <a href="tribal-dunes.php" style="font-size:.62rem;letter-spacing:.12em;color:rgba(184,150,90,.6);padding:.55rem 1.2rem;display:block;transition:color .2s;" onmouseover="this.style.color='#D4B07A'" onmouseout="this.style.color='rgba(184,150,90,.6)'">Read the Tribal Dunes story →</a>
          </div>
        </div>
        <div class="ts-drop-col">
          <span class="ts-drop-lbl">Dining & Lifestyle</span>
          <a href="tribal-table.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg" alt="Tribal Table">
            <div><div class="ts-prop-name">Tribal Table <span style="font-size:.54rem;color:rgba(184,150,90,.4);">— Soon</span></div><div class="ts-prop-loc">Restaurant & Bar · Kilifi</div></div>
          </a>
          <a href="somewhere-cafe.php" class="ts-prop-row">
            <img src="https://tribalsand.com/images/maya_illai/best6.jpg" alt="Somewhere Cafe">
            <div><div class="ts-prop-name">Somewhere Café <span style="font-size:.54rem;color:rgba(184,150,90,.4);">— Soon</span></div><div class="ts-prop-loc">Beachfront Café · Kilifi</div></div>
          </a>
          <a href="#" class="ts-prop-row">
            <img src="https://tribalsand.com/images/34t.jpg" alt="Kite School">
            <div><div class="ts-prop-name">Kite & Watersport School <span style="font-size:.54rem;color:rgba(184,150,90,.4);">— Soon</span></div><div class="ts-prop-loc">Ocean Sports · Kilifi</div></div>
          </a>
          <div class="ts-drop-col-footer">
            <div class="ts-drop-div"></div>
            <a href="interactive-site-map.php" style="font-size:.62rem;letter-spacing:.12em;color:rgba(184,150,90,.6);padding:.55rem 1.2rem;display:block;transition:color .2s;" onmouseover="this.style.color='#D4B07A'" onmouseout="this.style.color='rgba(184,150,90,.6)'">View Interactive Site Map →</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Events -->
    <div class="ts-item">
      <a href="events.php" class="ts-link">Events</a>
    </div>

    <!-- Gallery -->
    <div class="ts-item">
      <button class="ts-link">Gallery
        <svg viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4 4-4"/></svg>
      </button>
      <div class="ts-drop">
        <a href="my-amani-gallery.php">My Amani</a>
        <a href="maya-kobe-gallery.php">Maya Kobe</a>
        <a href="maya-ilai-gallery.php">Maya Ilai</a>
        <a href="zuri-gallery.php">Zuri</a>
        <a href="enkarebofa-gallery.php">Enkare Bofa</a>
        <a href="sandbox-gallery.php">Sandbox</a>
        <div class="ts-drop-div"></div>
        <a href="events-gallery.php">Events Gallery</a>
      </div>
    </div>

    <!-- About -->
    <div class="ts-item">
      <button class="ts-link">About
        <svg viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4 4-4"/></svg>
      </button>
      <div class="ts-drop">
        <a href="tribalsandstory.php">Our Story</a>
        <a href="sustainability.php">Sustainability</a>
        <a href="blog.php">Journal</a>
        <div class="ts-drop-div"></div>
        <span class="ts-drop-lbl">Destinations</span>
        <a href="kilifi.php">Kilifi Guide</a>
        <a href="watamu.php">Watamu Guide</a>
        <a href="kenya-coast-guide.php">Kenya Coast Guide</a>
        <a href="kenya-honeymoon.php">Honeymoon in Kenya</a>
        <div class="ts-drop-div"></div>
        <a href="https://tribalsand.com/wp-content/uploads/2024/12/Watamu-Kenya-COMETA-2025.pdf" target="_blank">Press · Cometa</a>
        <a href="for-agents.php">For Agents</a>
        <a href="contact.php">Contact Us</a>
      </div>
    </div>

  </div>

  <!-- Right actions -->
  <div class="ts-actions">

    <a href="tel:+254115115247" class="ts-tel">+254 115 115 247</a>
    <a href="trip-builder.php" class="ts-btn-plan">Plan Your Trip</a>
    <a href="/#properties" class="ts-btn-book">Book Now</a>
    <!-- Language switcher -->
    <div class="gtranslate_wrapper" id="gtranslate_wrapper"></div>
    <button class="ts-burger" id="tsBurger" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </div>

</nav>

<!-- Mobile drawer -->
<div class="ts-drawer" id="tsDrawer">

  <div>
    <span class="ts-mob-lbl">Boutique Hotels</span>
    <a href="zuri.php" class="ts-mob-prop"><img src="https://tribalsand.com/images/zuri/Aerial/zuri-3.webp" alt="Zuri"><div><div class="ts-mob-prop-name">Zuri</div><div class="ts-mob-prop-loc">Watamu · 6 Suites</div></div></a>
    <a href="maya-kobe.php" class="ts-mob-prop"><img src="https://tribalsand.com/images/Maya-Kobe-1-hero.webp" alt="Maya Kobe"><div><div class="ts-mob-prop-name">Maya Kobe</div><div class="ts-mob-prop-loc">Kilifi · 5 Suites</div></div></a>
    <a href="maya_ilai.php" class="ts-mob-prop"><img src="https://tribalsand.com/images/maya_illai/Best1.jpg" alt="Maya Ilai"><div><div class="ts-mob-prop-name">Maya Ilai</div><div class="ts-mob-prop-loc">Kilifi · 16 Units</div></div></a>
  </div>

  <div>
    <span class="ts-mob-lbl">Private Villas</span>
    <a href="my-amani.php" class="ts-mob-prop"><img src="https://tribalsand.com/images/my-amani/Aerial/myamani-11.webp" alt="My Amani"><div><div class="ts-mob-prop-name">My Amani</div><div class="ts-mob-prop-loc">Vipingo · 5 Rooms</div></div></a>
    <a href="enkare-bofa.php" class="ts-mob-prop"><img src="https://tribalsand.com/images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" alt="Enkare Bofa"><div><div class="ts-mob-prop-name">Enkare Bofa</div><div class="ts-mob-prop-loc">Kilifi · 5 Rooms</div></div></a>
    <a href="sandbox.php" class="ts-mob-prop"><img src="https://tribalsand.com/images/Sandbox/outdoors/IMG-20251117-WA0091.jpg" alt="Sandbox"><div><div class="ts-mob-prop-name">Sandbox</div><div class="ts-mob-prop-loc">Kilifi · 4 Rooms</div></div></a>
  </div>

  <div class="ts-mob-div"></div>

  <div>
    <span class="ts-mob-lbl">Explore</span>
    <a href="activities.php" class="ts-mob-link">Activities <span class="ts-mob-arr">→</span></a>
    <a href="http://tribalkiteschool.com/" class="ts-mob-link" target="_blank">Kite School <span class="ts-mob-arr">→</span></a>
    <a href="events.php" class="ts-mob-link">Events <span class="ts-mob-arr">→</span></a>
    <a href="tribal-dunes.php" class="ts-mob-link">Tribal Dunes <span class="ts-mob-arr">→</span></a>
    <a href="off-duty.php" class="ts-mob-link">Off Duty <span class="ts-mob-arr">→</span></a>
    <a href="tribal-table.php" class="ts-mob-link">Tribal Table <span class="ts-mob-arr">→</span></a>
    <a href="somewhere-cafe.php" class="ts-mob-link">Somewhere Café <span class="ts-mob-arr">→</span></a>
    <a href="interactive-site-map.php" class="ts-mob-link">Interactive Site Map <span class="ts-mob-arr">→</span></a>
  </div>

  <div>
    <span class="ts-mob-lbl">Gallery</span>
    <a href="my-amani-gallery.php" class="ts-mob-link">My Amani <span class="ts-mob-arr">→</span></a>
    <a href="maya-kobe-gallery.php" class="ts-mob-link">Maya Kobe <span class="ts-mob-arr">→</span></a>
    <a href="zuri-gallery.php" class="ts-mob-link">Zuri <span class="ts-mob-arr">→</span></a>
    <a href="enkarebofa-gallery.php" class="ts-mob-link">Enkare Bofa <span class="ts-mob-arr">→</span></a>
    <a href="events-gallery.php" class="ts-mob-link">Events <span class="ts-mob-arr">→</span></a>
  </div>

  <div>
    <span class="ts-mob-lbl">Company</span>
    <a href="tribalsandstory.php" class="ts-mob-link">Our Story <span class="ts-mob-arr">→</span></a>
    <a href="sustainability.php" class="ts-mob-link">Sustainability <span class="ts-mob-arr">→</span></a>
    <a href="blog.php" class="ts-mob-link">Journal <span class="ts-mob-arr">→</span></a>
    <a href="kilifi.php" class="ts-mob-link">Kilifi Guide <span class="ts-mob-arr">→</span></a>
    <a href="watamu.php" class="ts-mob-link">Watamu Guide <span class="ts-mob-arr">→</span></a>
    <a href="kenya-coast-guide.php" class="ts-mob-link">Kenya Coast Guide <span class="ts-mob-arr">→</span></a>
    <a href="kenya-honeymoon.php" class="ts-mob-link">Honeymoon in Kenya <span class="ts-mob-arr">→</span></a>
    <a href="https://tribalsand.com/wp-content/uploads/2024/12/Watamu-Kenya-COMETA-2025.pdf" class="ts-mob-link" target="_blank">Press <span class="ts-mob-arr">→</span></a>
    <a href="contact.php" class="ts-mob-link">Contact Us <span class="ts-mob-arr">→</span></a>
    <a href="for-agents.php" class="ts-mob-link">For Agents <span class="ts-mob-arr">→</span></a>
  </div>

  <div class="ts-mob-actions">
    <a href="trip-builder.php" class="ts-mob-btn ts-mob-btn-plan">Plan Your Trip</a>
    <a href="/#properties" class="ts-mob-btn ts-mob-btn-book">Book Now</a>
    <a href="tel:+254115115247" style="display:block;text-align:center;font-family:'Jost',sans-serif;font-size:.64rem;color:rgba(184,150,90,.5);letter-spacing:.1em;margin-top:.4rem;">+254 115 115 247</a>
    <div style="margin-top:.9rem;padding-top:.8rem;border-top:1px solid rgba(184,150,90,.09);">
      <span style="display:block;font-family:'Jost',sans-serif;font-size:.54rem;letter-spacing:.22em;text-transform:uppercase;color:rgba(184,150,90,.5);margin-bottom:.5rem;">Language</span>
      <div class="gtranslate_wrapper" id="gtranslate_drawer_wrapper"></div>
    </div>
  </div>



</div>

<!-- ── GTRANSLATE ── -->
<script>
window.gtranslateSettings = {
    "default_language": "en",
    "languages": ["en","fr","sw","de","hi","it","zh-TW"],
    "wrapper_selector": ".gtranslate_wrapper",
    "flag_size": 14,
    "switcher_horizontal_position": "inline"
};
</script>
<script src="https://cdn.gtranslate.net/widgets/latest/dwf.js" defer></script>
<style>
/* ── LANGUAGE SWITCHER ── */
/* Shared base — both nav and drawer instances */
.gtranslate_wrapper{display:inline-flex;align-items:center;flex-shrink:0;}
.gtranslate_wrapper .gt_switcher,
.gtranslate_wrapper .gt_switcher .gt_selected,
.gtranslate_wrapper .gt_switcher .gt_selected > a{
  width:auto!important;min-width:0!important;max-width:none!important;float:none!important;
}
.gtranslate_wrapper .gt_switcher{font-family:'Jost',sans-serif!important;}

/* Trigger button */
.gtranslate_wrapper .gt_switcher .gt_selected{
  display:inline-flex!important;align-items:center;height:auto!important;line-height:1!important;
  background:transparent!important;border:1px solid rgba(184,150,90,.3)!important;
  border-radius:3px;transition:border-color .2s,background .2s;
}
.gtranslate_wrapper .gt_switcher .gt_selected:hover{
  border-color:rgba(184,150,90,.7)!important;background:rgba(184,150,90,.08)!important;
}
.gtranslate_wrapper .gt_switcher .gt_selected > a{
  display:inline-flex!important;align-items:center;gap:.38rem;
  padding:.4rem .55rem!important;margin:0!important;text-decoration:none!important;
  font-family:'Jost',sans-serif!important;font-size:.58rem!important;
  letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.75)!important;white-space:nowrap;
}
.gtranslate_wrapper .gt_switcher .gt_selected:hover > a{color:#fff!important;}
.gtranslate_wrapper .gt_switcher .gt_selected img{margin:0!important;border-radius:1px;flex-shrink:0;opacity:.85;}
.gtranslate_wrapper .gt_switcher .gt_selected .gt_arrow{
  border-top-color:rgba(184,150,90,.6)!important;margin-left:.15rem!important;
  transition:transform .2s;
}

/* Dropdown panel — fixed to viewport so it never clips or overflows */
.gtranslate_wrapper .gt_switcher .gt_option{
  background:rgba(10,32,42,.97)!important;
  backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border:1px solid rgba(184,150,90,.18)!important;
  border-top:2px solid var(--ts-sand)!important;
  border-radius:0 0 4px 4px;
  width:auto!important;min-width:160px!important;
  position:fixed!important;
  top:68px!important;right:44px!important;left:auto!important;
  padding:.3rem 0!important;
  box-shadow:0 20px 50px rgba(0,0,0,.45);
  margin:0!important;z-index:100000!important;
}
.ts-nav.scrolled60 ~ * .gt_option,
.scrolled60 .gt_option{top:60px!important;}
.gtranslate_wrapper .gt_switcher .gt_option a{
  display:flex!important;align-items:center;gap:.65rem;
  padding:.55rem 1rem!important;
  font-family:'Jost',sans-serif!important;font-size:.68rem!important;
  letter-spacing:.07em;text-transform:uppercase;
  color:rgba(212,196,172,.78)!important;
  background:transparent!important;text-decoration:none!important;
  transition:color .15s,background .15s;
}
.gtranslate_wrapper .gt_switcher .gt_option a:hover{
  color:#fff!important;background:rgba(184,150,90,.1)!important;
}
.gtranslate_wrapper .gt_switcher .gt_option a img{border-radius:2px;opacity:.8;}
.gtranslate_wrapper .gt_switcher .gt_option a:hover img{opacity:1;}

/* Nav instance — hide on mobile */
#gtranslate_wrapper{margin-left:.1rem;}
@media(max-width:1100px){#gtranslate_wrapper{display:none!important;}}

/* Drawer instance — normal relative positioning, opens upward */
#gtranslate_drawer_wrapper .gt_switcher .gt_option{
  position:absolute!important;
  bottom:calc(100% + 4px)!important;top:auto!important;
  left:0!important;right:auto!important;
  margin:0!important;
}
</style>

<script>
(function(){
  var nav    = document.getElementById('tsNav');
  var burger = document.getElementById('tsBurger');
  var drawer = document.getElementById('tsDrawer');
  var open   = false;

  /* ── Scroll ── */
  function onScroll(){nav.classList.toggle('scrolled60', window.scrollY > 40);}
  window.addEventListener('scroll', onScroll, {passive:true});
  onScroll();

  /* ── Hamburger ── */
  burger.addEventListener('click', function(){
    open = !open;
    burger.classList.toggle('open', open);
    drawer.classList.toggle('open', open);
    nav.classList.toggle('nav-open', open);
    document.body.style.overflow = open ? 'hidden' : '';
  });

  /* ── Close drawer on link tap ── */
  drawer.querySelectorAll('a').forEach(function(a){
    a.addEventListener('click', function(){
      open = false;
      burger.classList.remove('open');
      drawer.classList.remove('open');
      nav.classList.remove('nav-open');
      document.body.style.overflow = '';
    });
  });

  /* ── Body padding for fixed nav ── */
  document.body.style.paddingTop = '68px';
})();
</script>
