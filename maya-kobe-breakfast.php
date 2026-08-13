<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0"/>
<title>Maya Kobe · Breakfast · Tribal Sand Kilifi</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;
  --teal:#1E5C6B;--teal-d:#102F3A;
  --dark:#141412;--off:#FAF8F4;--cream:#F5EFE3;--white:#fff;
  --mid:#5a4a38;--light:#8C7A60;--border:rgba(184,150,90,.18);
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;max-width:480px;margin:0 auto;}

/* ── HEADER ── */
.menu-header{background:var(--cream);text-align:center;padding:2rem 1.5rem 1.5rem;border-bottom:1px solid var(--border);}
.menu-logo{font-size:.78rem;letter-spacing:.35em;text-transform:uppercase;color:var(--teal-d);margin-bottom:.35rem;}
.menu-title{font-family:'Cormorant Garamond',serif;font-size:2.6rem;font-weight:300;color:var(--teal-d);line-height:1;margin-bottom:.35rem;}
.menu-sub{font-size:.68rem;letter-spacing:.35em;text-transform:uppercase;color:var(--sand);margin-bottom:.9rem;}
.menu-rule{width:44px;height:1px;background:var(--sand);margin:0 auto 1rem;}
.menu-tagline{font-size:1.02rem;color:var(--light);line-height:1.7;max-width:320px;margin:0 auto 1.2rem;font-style:italic;font-family:'Cormorant Garamond',serif;}
.menu-location{font-size:.74rem;color:var(--light);letter-spacing:.12em;}

/* ── SECTION ── */
.menu-section{display:none;}
.menu-section.on{display:block;}

/* ── SECTION BANNER ── */
.sec-banner{background:var(--teal-d);text-align:center;padding:1.05rem 1.4rem;}
.sec-banner-t{font-size:.66rem;letter-spacing:.24em;text-transform:uppercase;color:var(--sand-lt);}
.sec-banner-s{font-size:.62rem;letter-spacing:.12em;color:rgba(212,196,172,.55);margin-top:.3rem;font-style:italic;font-family:'Cormorant Garamond',serif;}

/* ── CATEGORY ── */
.cat-header{padding:1.6rem 1.4rem .6rem;display:flex;align-items:flex-end;justify-content:space-between;gap:.5rem;}
.cat-name{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:300;color:var(--teal-d);line-height:1;}
.cat-name em{font-style:italic;color:var(--sand);}
.cat-tag{font-size:.5rem;letter-spacing:.2em;text-transform:uppercase;color:var(--light);}
.cat-rule{height:1px;background:var(--border);margin:0 1.4rem .2rem;}

/* ── INCLUDED GROUPS ── */
.inc-block{background:var(--white);padding:1.05rem 1.4rem;border-bottom:1px solid rgba(184,150,90,.08);margin-bottom:1px;}
.inc-label{font-size:.6rem;letter-spacing:.22em;text-transform:uppercase;color:var(--sand);margin-bottom:.6rem;}
.inc-list{display:flex;flex-direction:column;gap:.4rem;}
.inc-item{font-size:1.02rem;color:var(--dark);line-height:1.5;display:flex;gap:.6rem;align-items:baseline;}
.inc-item::before{content:'◆';color:var(--sand-lt);font-size:.5rem;flex-shrink:0;}

/* ── ITEM ── */
.item{padding:.95rem 1.4rem;border-bottom:1px solid rgba(184,150,90,.08);background:var(--white);margin-bottom:1px;}
.item:last-child{border-bottom:none;}
.item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.28rem;}
.item-name{font-size:1.02rem;font-weight:500;color:var(--dark);line-height:1.25;flex:1;}
.item-price{font-family:'Cormorant Garamond',serif;font-size:1.14rem;font-weight:400;color:var(--teal);white-space:nowrap;flex-shrink:0;}
.item-desc{font-size:1.14rem;color:var(--light);line-height:1.72;}
.item-badges{display:flex;gap:.3rem;margin-top:.35rem;flex-wrap:wrap;}
.badge{font-size:.66rem;padding:.15rem .45rem;border-radius:2px;letter-spacing:.06em;}
.badge.veg{background:rgba(76,150,80,.1);color:#3a7a3d;}
.badge.vegan{background:rgba(46,125,50,.1);color:#2e7d32;}
.badge.spicy{background:rgba(211,47,47,.08);color:#c62828;}
.badge.nuts{background:rgba(184,150,90,.12);color:#8C7A60;}
.badge.gluten{background:rgba(120,100,60,.1);color:#6d5c2a;}
.badge.sig{background:rgba(30,92,107,.1);color:var(--teal);}
.item.sig-item{border-left:2px solid var(--sand);}

/* ── SIDES / SIMPLE ITEMS ── */
.sides-grid{padding:.5rem 1.4rem;display:flex;flex-direction:column;gap:0;background:var(--white);}
.side-row{display:flex;justify-content:space-between;align-items:center;padding:.65rem 0;border-bottom:1px solid rgba(184,150,90,.08);gap:.75rem;}
.side-row:last-child{border-bottom:none;}
.side-name{font-size:.96rem;color:var(--dark);}
.side-price{font-family:'Cormorant Garamond',serif;font-size:1.08rem;color:var(--teal);white-space:nowrap;}

/* ── ALLERGEN LEGEND ── */
.legend{background:var(--cream);padding:1.2rem 1.4rem;margin:.5rem 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);}
.legend-title{font-size:.66rem;letter-spacing:.24em;text-transform:uppercase;color:var(--sand);margin-bottom:.65rem;}
.legend-items{display:flex;flex-wrap:wrap;gap:.4rem;}
.legend-note{font-size:.78rem;color:var(--light);line-height:1.7;margin-top:.75rem;font-style:italic;}

/* ── SPACING ── */
.cat-gap{height:.75rem;background:var(--off);}

/* ── FOOTER ── */
.menu-footer{background:var(--teal-d);padding:2rem 1.5rem;text-align:center;margin-top:.5rem;}
.footer-text{font-family:'Cormorant Garamond',serif;font-size:1.14rem;font-style:italic;color:rgba(212,196,172,.65);line-height:1.7;margin-bottom:.65rem;}
.footer-sub{font-size:1.14rem;letter-spacing:.14em;text-transform:uppercase;color:rgba(184,150,90,.4);}
.footer-thank{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;color:rgba(255,255,255,.55);margin:.75rem 0 .25rem;}
.footer-web{font-size:.74rem;color:rgba(184,150,90,.4);letter-spacing:.12em;}
.footer-fine{font-size:.7rem;color:rgba(212,196,172,.45);line-height:1.7;margin-top:.9rem;}

/* ── CATEGORY SLIDER ── */
.cat-slider-wrap{
  position:sticky;top:0;z-index:90;
  background:var(--cream);
  border-bottom:1px solid var(--border);
  overflow:hidden;
  box-shadow:0 2px 12px rgba(0,0,0,.06);
}
.cat-slider{
  display:flex;gap:0;
  overflow-x:auto;
  scroll-snap-type:x mandatory;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:none;
  padding:0;
}
.cat-slider::-webkit-scrollbar{display:none;}
.cat-pill{
  flex-shrink:0;
  scroll-snap-align:start;
  padding:.65rem 1.1rem;
  font-size:.7rem;
  letter-spacing:.1em;
  text-transform:uppercase;
  color:var(--light);
  cursor:pointer;
  white-space:nowrap;
  border-bottom:2px solid transparent;
  transition:all .2s;
  font-family:'Jost',sans-serif;
  font-weight:500;
}
.cat-pill.on{color:var(--teal);border-bottom-color:var(--teal);background:rgba(30,92,107,.04);}
.cat-pill:active{background:rgba(30,92,107,.06);}
</style>
</head>
<body>

<!-- HEADER -->
<div class="menu-header">
  <div class="menu-logo">Maya Kobe</div>
  <div class="menu-title">Breakfast</div>
  <div class="menu-sub">Tribal Sand · Kilifi</div>
  <div class="menu-rule"></div>
  <div class="menu-tagline">Crafted each morning from fresh, seasonal ingredients — a gentle start to the day, served with the warmth of the coast.</div>
  <div class="menu-location">📍 Bofa Beach · Kilifi · Kenya's North Coast</div>
</div>

<!-- ══════════ BREAKFAST ══════════ -->
<div class="menu-section on" id="breakfast">

  <!-- CATEGORY SLIDER -->
  <div class="cat-slider-wrap" id="catSliderWrap">
    <div class="cat-slider" id="catSlider">
      <div class="cat-pill on" onclick="scrollToCat('cat-included',this)">✓ Included</div>
      <div class="cat-pill" onclick="scrollToCat('cat-signature',this)">★ Signature</div>
      <div class="cat-pill" onclick="scrollToCat('cat-additions',this)">➕ Additions</div>
      <div class="cat-pill" onclick="scrollToCat('cat-coffee',this)">☕ Coffee</div>
      <div class="cat-pill" onclick="scrollToCat('cat-tea',this)">🍵 Tea</div>
    </div>
  </div>

  <!-- ══ INCLUDED IN YOUR BREAKFAST ══ -->
  <div id="cat-included"></div>
  <div class="sec-banner">
    <div class="sec-banner-t">Included in Your Breakfast</div>
  </div>

  <div class="inc-block">
    <div class="inc-label">Eggs, Prepared to Order</div>
    <div class="inc-list">
      <div class="inc-item">Scrambled Eggs</div>
      <div class="inc-item">Hard-Boiled Eggs</div>
      <div class="inc-item">Fried Eggs</div>
      <div class="inc-item">Spanish Omelette</div>
      <div class="inc-item">Plain Omelette</div>
    </div>
  </div>

  <div class="inc-block">
    <div class="inc-label">From the Griddle</div>
    <div class="inc-list">
      <div class="inc-item">Pancake</div>
      <div class="inc-item">Crepe</div>
    </div>
  </div>

  <div class="inc-block">
    <div class="inc-label">Sides</div>
    <div class="inc-list">
      <div class="inc-item">Bacon</div>
      <div class="inc-item">Beef / Pork / Chicken Sausage</div>
    </div>
  </div>

  <div class="inc-block">
    <div class="inc-label">Fruits &amp; Grains</div>
    <div class="inc-list">
      <div class="inc-item">Granola with Yogurt</div>
      <div class="inc-item">Fresh Season Fruits</div>
    </div>
  </div>

  <div class="inc-block">
    <div class="inc-label">Beverages</div>
    <div class="inc-list">
      <div class="inc-item">Milk, French Press Coffee, Tea, Fresh Juice</div>
    </div>
  </div>

  <div class="cat-gap"></div>

  <!-- ══ EXTRA ADDITIONS ══ -->
  <div class="sec-banner">
    <div class="sec-banner-t">Extra Additions</div>
    <div class="sec-banner-s">Charged separately</div>
  </div>

  <!-- LEGEND -->
  <div class="legend">
    <div class="legend-title">Key</div>
    <div class="legend-items">
      <span class="badge veg">🌿 Vegetarian</span>
      <span class="badge vegan">🌱 Vegan</span>
      <span class="badge spicy">🌶 Spicy</span>
      <span class="badge nuts">🥜 Contains Nuts</span>
      <span class="badge gluten">🌾 Contains Gluten</span>
    </div>
    <div class="legend-note">Kindly inform your host of any allergies or dietary requirements before ordering.</div>
  </div>

  <!-- SIGNATURE PLATES -->
  <div id="cat-signature"></div>
  <div class="cat-header"><div class="cat-name">Signature<br><em>Plates</em></div><div class="cat-tag">Charged Separately</div></div>
  <div class="cat-rule"></div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Poached Egg &amp; Smashed Avocado Toast</div><div class="item-price">KSH 600</div></div>
    <div class="item-desc">Toasted sourdough, smashed avocado, delicately poached egg, toasted mixed seeds and a drizzle of chilli oil and extra virgin olive oil.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Shakshuka</div><div class="item-price">KSH 480</div></div>
    <div class="item-desc">Slow-simmered tomato, red pepper, onion and garlic, warmed with cumin and sweet paprika, finished with fresh coriander.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Lemon Ricotta Pancakes</div><div class="item-price">KSH 600</div></div>
    <div class="item-desc">Fluffy ricotta pancakes scented with lemon zest, layered with seasonal fresh fruit and honey butter.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Coconut Chia &amp; Mango Bowl</div><div class="item-price">KSH 800</div></div>
    <div class="item-desc">Chia seeds gently soaked in coconut milk, layered with mango, passion fruit, toasted cashew nuts and almond flakes.</div>
    <div class="item-badges"><span class="badge vegan">🌱 Vegan</span><span class="badge nuts">🥜</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- ADDITIONS -->
  <div id="cat-additions"></div>
  <div class="cat-header"><div class="cat-name"><em>Additions</em></div><div class="cat-tag">Sides</div></div>
  <div class="cat-rule"></div>
  <div class="sides-grid">
    <div class="side-row"><span class="side-name">Sautéed Mushrooms</span><span class="side-price">KSH 300</span></div>
    <div class="side-row"><span class="side-name">Hash Browns</span><span class="side-price">KSH 600</span></div>
    <div class="side-row"><span class="side-name">Bacon</span><span class="side-price">KSH 300</span></div>
    <div class="side-row"><span class="side-name">Seared Tuna</span><span class="side-price">KSH 800</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- COFFEE -->
  <div id="cat-coffee"></div>
  <div class="cat-header"><div class="cat-name"><em>Coffee</em></div><div class="cat-tag">Freshly Brewed</div></div>
  <div class="cat-rule"></div>
  <div class="sides-grid">
    <div class="side-row"><span class="side-name">Single Espresso</span><span class="side-price">KSH 250</span></div>
    <div class="side-row"><span class="side-name">Double Espresso</span><span class="side-price">KSH 350</span></div>
    <div class="side-row"><span class="side-name">Cappuccino</span><span class="side-price">KSH 420</span></div>
    <div class="side-row"><span class="side-name">Mocha</span><span class="side-price">KSH 550</span></div>
    <div class="side-row"><span class="side-name">Americano</span><span class="side-price">KSH 200</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- TEA -->
  <div id="cat-tea"></div>
  <div class="cat-header"><div class="cat-name"><em>Tea</em></div><div class="cat-tag">A Coastal Ritual</div></div>
  <div class="cat-rule"></div>
  <div class="sides-grid">
    <div class="side-row"><span class="side-name">English Breakfast Tea</span><span class="side-price">KSH 200</span></div>
    <div class="side-row"><span class="side-name">Kerico Gold Kenya Tea</span><span class="side-price">KSH 200</span></div>
    <div class="side-row"><span class="side-name">Home Made Masala Tea</span><span class="side-price">KSH 300</span></div>
  </div>

</div>

<!-- FOOTER -->
<div class="menu-footer">
  <div class="footer-text">Maya Kobe is part of the Tribal Sand collection — coastal properties in Watamu, Kilifi and Vipingo, created with deep respect for Kenya's natural beauty, local communities and the environment.</div>
  <div class="footer-sub">Maya Kobe · Tribal Sand</div>
  <div class="footer-thank">Truly, Thank You.</div>
  <div class="footer-web">tribalsand.com · reservations@tribalsand.com</div>
  <div class="footer-fine">Kindly inform your host of any allergies or dietary requirements before ordering.<br>All prices are in Kenya Shillings (KSH) and inclusive of applicable taxes.</div>
</div>

<script>
function scrollToCat(id, el){
  document.querySelectorAll('#catSlider .cat-pill').forEach(p=>p.classList.remove('on'));
  el.classList.add('on');
  el.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
  var target = document.getElementById(id);
  if(target){
    var offset = target.getBoundingClientRect().top + window.scrollY - 100;
    window.scrollTo({top:offset, behavior:'smooth'});
  }
}

// Update active pill on scroll
window.addEventListener('scroll', function(){
  var cats = ['cat-included','cat-signature','cat-additions','cat-coffee','cat-tea'];
  var pills = document.querySelectorAll('#catSlider .cat-pill');
  var current = 0;
  cats.forEach(function(id, i){
    var el = document.getElementById(id);
    if(el && el.getBoundingClientRect().top < 120) current = i;
  });
  pills.forEach(function(p,i){ p.classList.toggle('on', i===current); });
}, {passive:true});
</script>
</body>
</html>
