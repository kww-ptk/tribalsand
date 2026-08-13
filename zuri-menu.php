<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0"/>
<title>Zuri Boutique Hotel · Menu · Watamu</title>
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
.menu-logo{font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:300;color:var(--teal-d);line-height:1;margin-bottom:.2rem;}
.menu-sub{font-size:.68rem;letter-spacing:.35em;text-transform:uppercase;color:var(--sand);margin-bottom:.9rem;}
.menu-tagline{font-size:1.02rem;color:var(--light);line-height:1.7;max-width:300px;margin:0 auto 1.2rem;font-style:italic;font-family:'Cormorant Garamond',serif;}
.menu-location{font-size:.74rem;color:var(--light);letter-spacing:.12em;}

/* ── TABS ── */
.tabs{display:flex;background:var(--white);border-bottom:2px solid var(--border);position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.06);}
.tab{flex:1;padding:.75rem .5rem;text-align:center;font-size:1.14rem;letter-spacing:.18em;text-transform:uppercase;color:var(--light);cursor:pointer;transition:all .2s;border-bottom:2px solid transparent;margin-bottom:-2px;font-weight:500;}
.tab.on{color:var(--teal);border-bottom-color:var(--teal);}

/* ── SECTION ── */
.menu-section{display:none;}
.menu-section.on{display:block;}

/* ── CATEGORY ── */
.cat-header{padding:1.6rem 1.4rem .6rem;display:flex;align-items:flex-end;justify-content:space-between;gap:.5rem;}
.cat-name{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:300;color:var(--teal-d);line-height:1;}
.cat-name em{font-style:italic;color:var(--sand);}
.cat-tag{font-size:.5rem;letter-spacing:.2em;text-transform:uppercase;color:var(--light);}
.cat-rule{height:1px;background:var(--border);margin:0 1.4rem .2rem;}

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
.badge.gf{background:rgba(76,150,80,.08);color:#3a7a3d;}
.item.sig-item{border-left:2px solid var(--sand);}

/* ── SIDES / SIMPLE ITEMS ── */
.sides-grid{padding:.5rem 1.4rem;display:flex;flex-direction:column;gap:0;background:var(--white);}
.side-row{display:flex;justify-content:space-between;align-items:center;padding:.65rem 0;border-bottom:1px solid rgba(184,150,90,.08);}
.side-row:last-child{border-bottom:none;}
.side-name{font-size:.96rem;color:var(--dark);}
.side-price{font-family:'Cormorant Garamond',serif;font-size:1.08rem;color:var(--teal);}

/* ── ALLERGEN LEGEND ── */
.legend{background:var(--cream);padding:1.2rem 1.4rem;margin:.5rem 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);}
.legend-title{font-size:.66rem;letter-spacing:.24em;text-transform:uppercase;color:var(--sand);margin-bottom:.65rem;}
.legend-items{display:flex;flex-wrap:wrap;gap:.4rem;}
.legend-note{font-size:.78rem;color:var(--light);line-height:1.7;margin-top:.75rem;font-style:italic;}

/* ── SPACING ── */
.cat-gap{height:.75rem;background:var(--off);}

/* ── DRINKS ── */
.drink-item{padding:.85rem 1.4rem;border-bottom:1px solid rgba(184,150,90,.08);background:var(--white);}
.drink-name{font-size:1.14rem;font-weight:500;color:var(--dark);margin-bottom:.2rem;}
.drink-desc{font-size:.84rem;color:var(--light);line-height:1.65;margin-bottom:.2rem;}
.drink-price{font-family:'Cormorant Garamond',serif;font-size:1.08rem;color:var(--teal);}

/* ── FOOTER ── */
.menu-footer{background:var(--teal-d);padding:2rem 1.5rem;text-align:center;margin-top:.5rem;}
.footer-text{font-family:'Cormorant Garamond',serif;font-size:1.14rem;font-style:italic;color:rgba(212,196,172,.65);line-height:1.7;margin-bottom:.65rem;}
.footer-sub{font-size:1.14rem;letter-spacing:.14em;text-transform:uppercase;color:rgba(184,150,90,.4);}
.footer-thank{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;color:rgba(255,255,255,.55);margin:.75rem 0 .25rem;}
.footer-web{font-size:.74rem;color:rgba(184,150,90,.4);letter-spacing:.12em;}

/* ── CATEGORY SLIDER ── */
.cat-slider-wrap{
  position:sticky;top:46px;z-index:90;
  background:var(--cream);
  border-bottom:1px solid var(--border);
  overflow:hidden;
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

/* drink slider */
.drink-slider-wrap{
  position:sticky;top:46px;z-index:90;
  background:var(--cream);
  border-bottom:1px solid var(--border);
  overflow:hidden;
}
.drink-slider{
  display:flex;gap:0;
  overflow-x:auto;
  scroll-snap-type:x mandatory;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:none;
  padding:0;
}
.drink-slider::-webkit-scrollbar{display:none;}
</style>
</head>
<body>

<!-- HEADER -->
<div class="menu-header">
  <div class="menu-logo">Zuri</div>
  <div class="menu-sub">Boutique Hotel · Watamu</div>
  <div class="menu-tagline">Mediterranean simplicity, Italian craftsmanship, Indian traditions and the richness of the Kenyan coast.</div>
  <div class="menu-location">📍 Watamu · Kenya's North Coast</div>
</div>

<!-- TABS -->
<div class="tabs">
  <div class="tab on" onclick="switchTab('food',this)">🍽 Food</div>
  <div class="tab" onclick="switchTab('drinks',this)">🍹 Drinks</div>
</div>

<!-- ══════════ FOOD ══════════ -->
<div class="menu-section on" id="food">

  <!-- LEGEND -->
  <div class="legend">
    <div class="legend-title">Key</div>
    <div class="legend-items">
      <span class="badge veg">🌿 Vegetarian</span>
      <span class="badge vegan">🌱 Vegan</span>
      <span class="badge spicy">🌶 Spicy</span>
      <span class="badge nuts">🥜 Contains Nuts</span>
      <span class="badge gluten">🌾 Contains Gluten</span>
      <span class="badge sig">★ Zuri Signature</span>
    </div>
    <div class="legend-note">Some dishes may contain nuts, gluten, shellfish or spices. Please inform our team of any allergies or dietary requirements — we will be delighted to prepare an alternative.</div>
  </div>

  <!-- FOOD CATEGORY SLIDER -->
  <div class="cat-slider-wrap" id="foodSliderWrap">
    <div class="cat-slider" id="foodSlider">
      <div class="cat-pill on" onclick="scrollToFood('cat-soups',this)">🍲 Soups</div>
      <div class="cat-pill" onclick="scrollToFood('cat-starters',this)">🥗 Starters</div>
      <div class="cat-pill" onclick="scrollToFood('cat-salads',this)">🥙 Salads</div>
      <div class="cat-pill" onclick="scrollToFood('cat-pasta',this)">🍝 Pasta</div>
      <div class="cat-pill" onclick="scrollToFood('cat-seafood',this)">🐟 Seafood</div>
      <div class="cat-pill" onclick="scrollToFood('cat-meat',this)">🥩 Meat</div>
      <div class="cat-pill" onclick="scrollToFood('cat-indian',this)">🍛 Indian</div>
      <div class="cat-pill" onclick="scrollToFood('cat-sides',this)">🍟 Sides</div>
      <div class="cat-pill" onclick="scrollToFood('cat-desserts',this)">🍮 Desserts</div>
    </div>
  </div>

  <!-- SOUPS -->
  <div id="cat-soups"></div>
  <div class="cat-header"><div class="cat-name">Starter<br><em>Soups</em></div><div class="cat-tag">First Course</div></div>
  <div class="cat-rule"></div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Zuri Garden Velouté</div><div class="item-price">600 Kes</div></div>
    <div class="item-desc">A smooth blend of seasonal vegetables finished with extra virgin olive oil and served with toasted artisan croutons.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span><span class="badge sig">★</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Rustic White Bean Soup</div><div class="item-price">600 Kes</div></div>
    <div class="item-desc">A comforting white bean soup enriched with red spring onions and served with toasted bread.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Garden Harvest Soup</div><div class="item-price">600 Kes</div></div>
    <div class="item-desc">A light vegetable broth prepared with seasonal garden vegetables and finished with a delicate touch of chilli.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Creamy Zucchini Velouté</div><div class="item-price">600 Kes</div></div>
    <div class="item-desc">Silky zucchini soup served with crispy zucchini, walnuts and fresh stracciatella cheese.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge nuts">🥜</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Chilled Coastal Tomato Gazpacho</div><div class="item-price">600 Kes</div></div>
    <div class="item-desc">A refreshing cold tomato soup bursting with Mediterranean flavours.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- FROM OUR GARDEN & OCEAN -->
  <div id="cat-starters"></div>
  <div class="cat-header"><div class="cat-name">From Our<br><em>Garden & Ocean</em></div><div class="cat-tag">Starters</div></div>
  <div class="cat-rule"></div>
  <div class="item">
    <div class="item-top"><div class="item-name">Trio of Samosas</div><div class="item-price">600 Kes</div></div>
    <div class="item-desc">A selection of feta, vegetable and meat samosas served with Zuri's signature dipping sauce.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Zuri Signature Bruschetta</div><div class="item-price">600 Kes</div></div>
    <div class="item-desc">Toasted artisan bread layered with black olive pâté, garlic-marinated cherry tomatoes and fresh mozzarella pearls.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span><span class="badge sig">★</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Golden Garden Croquettes</div><div class="item-price">800 Kes</div></div>
    <div class="item-desc">Golden croquettes with eggplant and potato or zucchini and cheese, accompanied by tempura vegetables and Zuri fries.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Zuri Crispy Octopus</div><div class="item-price">1,000 Kes</div></div>
    <div class="item-desc">Grilled octopus over wild rocket leaves, cherry tomatoes, fresh stracciatella cheese and a vibrant rocket pesto.</div>
    <div class="item-badges"><span class="badge sig">★</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Ocean Tuna Carpaccio</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Delicately sliced tuna accompanied by sesame seeds, orange segments, teriyaki glaze and fresh rocket.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Oceanic Pink Prawn Tartare</div><div class="item-price">1,500 Kes</div></div>
    <div class="item-desc">Fresh pink prawns seasoned with chilli, anchovy, julienned zucchini and crispy sesame.</div>
    <div class="item-badges"><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Indian Ocean Prawn Cocktail</div><div class="item-price">1,500 Kes</div></div>
    <div class="item-desc">Succulent prawns served with a refreshing house cocktail dressing.</div>
  </div>

  <div class="cat-gap"></div>

  <!-- SALADS -->
  <div id="cat-salads"></div>
  <div class="cat-header"><div class="cat-name">Fresh &<br><em>Vibrant</em></div><div class="cat-tag">Salads</div></div>
  <div class="cat-rule"></div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Zuri Exotic Tropical Salad</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">A colourful medley of tropical fruits, crisp greens and roasted cashew nuts with a light citrus dressing.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge nuts">🥜</span><span class="badge sig">★</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Mediterranean Brown Rice Salad</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Brown rice tossed with crunchy vegetables, sun-dried tomatoes and feta cheese.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Zuri Rice Salad</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">A signature rice salad with eggplant cream, potato cubes, caramelised onions and wholemeal bread crumble.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Smoky BBQ Chicken Salad</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Tender chicken strips served with crunchy vegetables and a smoky BBQ dressing.</div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Octopus & Potato Salad</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Tender octopus combined with potatoes, herbs and a light citrus dressing.</div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Zuri Prawn Salad</div><div class="item-price">1,500 Kes</div></div>
    <div class="item-desc">Fresh garden leaves topped with succulent prawns and seasonal accompaniments.</div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Greek Garden Salad</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Soft lettuce, black olives, feta cheese, onions, tomatoes and toasted bread crumble.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Sun-Kissed Chickpea Salad</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">A wholesome chickpea salad inspired by Mediterranean flavours.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- PASTA -->
  <div id="cat-pasta"></div>
  <div class="cat-header"><div class="cat-name">Italian Dishes<br><em>Done Right</em></div><div class="cat-tag">Pasta · Gluten-free available on request</div></div>
  <div class="cat-rule"></div>
  <div class="item">
    <div class="item-top"><div class="item-name">Linguine Alfredo</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Linguine tossed with mushrooms, cream and parmesan cheese.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Spaghetti al Pomodoro & Basilico</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">A timeless Italian classic prepared with slow-cooked tomato sauce and fresh basil.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Rigatoni alla Norma</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Rigatoni served with tomato sauce, eggplant and parmesan cheese.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Rigatoni Nerano</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">A Southern Italian classic of rigatoni tossed in silky zucchini cream, finished with crispy zucchini, aged parmesan and toasted bread crumble.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Spaghetti with Pesto & Peanut Crumble</div><div class="item-price">1,200 Kes</div></div>
    <div class="item-desc">Spaghetti tossed in fresh basil pesto and finished with a crunchy peanut crumble.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span><span class="badge nuts">🥜</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Paccheri Bolognese</div><div class="item-price">1,400 Kes</div></div>
    <div class="item-desc">Large Paccheri coated in a slow-cooked beef ragù finished with Parmigiano Reggiano.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Gnocchi Mediterraneo</div><div class="item-price">1,400 Kes</div></div>
    <div class="item-desc">Potato gnocchi with fried eggplant, cherry tomatoes and crispy bacon.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Spinach & Ricotta Ravioli</div><div class="item-price">1,600 Kes</div></div>
    <div class="item-desc">Delicate homemade ravioli served over a silky red cabbage cream.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Indian Ocean Lobster Linguine</div><div class="item-price">2,200 Kes</div></div>
    <div class="item-desc">Fresh lobster served with linguine in a delicate seafood sauce.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Maltagliati ai Frutti di Mare</div><div class="item-price">2,200 Kes</div></div>
    <div class="item-desc">Fresh pasta ribbons served in a rich shellfish sauce.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span><span class="badge sig">★</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- MAINS OCEAN -->
  <div id="cat-seafood"></div>
  <div class="cat-header"><div class="cat-name">Fresh from<br><em>the Ocean</em></div><div class="cat-tag">Mains · Seafood</div></div>
  <div class="cat-rule"></div>
  <div class="item">
    <div class="item-top"><div class="item-name">Pepper & Ginger White Fish</div><div class="item-price">1,800 Kes</div></div>
    <div class="item-desc">Ocean fish marinated with pepper and ginger, grilled to perfection and served with a curried mayonnaise.</div>
  </div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Zuri Fish & Chips</div><div class="item-price">1,800 Kes</div></div>
    <div class="item-desc">Golden battered fish served with a leek and chilli infused sauce.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span><span class="badge sig">★</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Braised Octopus Guazzetto</div><div class="item-price">1,800 Kes</div></div>
    <div class="item-desc">Tender octopus served over a silky potato velouté with toasted bread.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Seared Tuna Fillet</div><div class="item-price">2,200 Kes</div></div>
    <div class="item-desc">Perfectly seared tuna fillet served with seasonal accompaniments.</div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Curried Prawn Tails</div><div class="item-price">2,500 Kes</div></div>
    <div class="item-desc">Curried prawns served with crispy basmati rice medallions.</div>
    <div class="item-badges"><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Crispy Calamari & Prawns</div><div class="item-price">2,500 Kes</div></div>
    <div class="item-desc">Lightly fried calamari and prawns accompanied by tempura vegetables.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Catalan Lobster</div><div class="item-price">3,200 Kes</div></div>
    <div class="item-desc">Fresh lobster prepared with tomatoes, onions, parsley and a lemon dressing.</div>
  </div>

  <div class="cat-gap"></div>

  <!-- MAINS MEAT -->
  <div id="cat-meat"></div>
  <div class="cat-header"><div class="cat-name">For the<br><em>Meat Lovers</em></div><div class="cat-tag">Mains · Meat & Poultry</div></div>
  <div class="cat-rule"></div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Zuri Signature Beef Burger</div><div class="item-price">1,500 Kes</div></div>
    <div class="item-desc">A 180g beef burger with tomatoes, red wine onions, scamorza cheese, crisp lettuce and chilli mayonnaise.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Coastal Chicken Curry</div><div class="item-price">1,800 Kes</div></div>
    <div class="item-desc">Tender chicken cooked in a fragrant curry sauce and served with basmati rice.</div>
    <div class="item-badges"><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Crispy Panko Chicken Cutlet</div><div class="item-price">1,800 Kes</div></div>
    <div class="item-desc">Golden panko-crusted chicken served with garlic aioli and crispy zucchini.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Chargrilled Chicken & Zuri Fries</div><div class="item-price">1,800 Kes</div></div>
    <div class="item-desc">Simply grilled chicken served with our signature fries.</div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Rosemary Beef Tenderloin</div><div class="item-price">2,200 Kes</div></div>
    <div class="item-desc">Sliced beef fillet infused with rosemary and served with buttered potato wedges and lime yoghurt sauce.</div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Beef Fillet with Mushroom Cream</div><div class="item-price">2,500 Kes</div></div>
    <div class="item-desc">Premium beef fillet served with a rich mushroom cream and a potato basket.</div>
  </div>

  <div class="cat-gap"></div>

  <!-- INDIAN -->
  <div id="cat-indian"></div>
  <div class="cat-header"><div class="cat-name"><em>Indian</em><br>Inspired</div><div class="cat-tag">Mains</div></div>
  <div class="cat-rule"></div>
  <div class="item">
    <div class="item-top"><div class="item-name">Masala Dosa Trio</div><div class="item-price">1,400 Kes</div></div>
    <div class="item-desc">Traditional dosas served with two flavourful fillings inspired by Southern India.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge gluten">🌾</span><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Aloo & Egg Curry</div><div class="item-price">1,400 Kes</div></div>
    <div class="item-desc">Potato and egg curry accompanied by vegetable paratha.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Vegetable Korma</div><div class="item-price">1,400 Kes</div></div>
    <div class="item-desc">Seasonal vegetables cooked in a fragrant coconut curry and served with coconut rice.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Paneer Butter Masala</div><div class="item-price">1,600 Kes</div></div>
    <div class="item-desc">Soft paneer gently simmered in a velvety tomato and butter sauce infused with aromatic spices, served with fragrant jeera rice.</div>
    <div class="item-badges"><span class="badge veg">🌿 Veg</span><span class="badge spicy">🌶</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Chicken Tikka Masala</div><div class="item-price">1,800 Kes</div></div>
    <div class="item-desc">Tender chicken cooked in a creamy tomato and curry sauce.</div>
    <div class="item-badges"><span class="badge spicy">🌶</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- SIDES -->
  <div id="cat-sides"></div>
  <div class="cat-header"><div class="cat-name">Tasty<br><em>Sides</em></div><div class="cat-tag">All 500 Kes</div></div>
  <div class="cat-rule"></div>
  <div class="sides-grid">
    <div class="side-row"><span class="side-name">Zuri Fries</span><span class="side-price">500 Kes</span></div>
    <div class="side-row"><span class="side-name">Mixed Garden Salad</span><span class="side-price">500 Kes</span></div>
    <div class="side-row"><span class="side-name">Grilled Vegetables</span><span class="side-price">500 Kes</span></div>
    <div class="side-row"><span class="side-name">Buttered Potato Wedges</span><span class="side-price">500 Kes</span></div>
    <div class="side-row"><span class="side-name">Sautéed Spinach</span><span class="side-price">500 Kes</span></div>
  </div>

  <div class="cat-gap"></div>

  <!-- DESSERTS -->
  <div id="cat-desserts"></div>
  <div class="cat-header"><div class="cat-name">Sweet<br><em>Endings</em></div><div class="cat-tag">Desserts</div></div>
  <div class="cat-rule"></div>
  <div class="item">
    <div class="item-top"><div class="item-name">Tropical Fruit Selection</div><div class="item-price">500 Kes</div></div>
    <div class="item-desc">A refreshing selection of freshly cut seasonal fruits.</div>
    <div class="item-badges"><span class="badge vegan">🌱 Vegan</span></div>
  </div>
  <div class="item sig-item">
    <div class="item-top"><div class="item-name">Zurimisu'</div><div class="item-price">800 Kes</div></div>
    <div class="item-desc">Zuri's signature interpretation of the classic Italian tiramisu.</div>
    <div class="item-badges"><span class="badge sig">★</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Chocolate Brownie</div><div class="item-price">800 Kes</div></div>
    <div class="item-desc">Warm chocolate brownie served with walnut ice cream and melted chocolate.</div>
    <div class="item-badges"><span class="badge nuts">🥜</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Mini Cheesecake</div><div class="item-price">800 Kes</div></div>
    <div class="item-desc">Creamy cheesecake served in an elegant individual portion.</div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Apple Tartlet</div><div class="item-price">800 Kes</div></div>
    <div class="item-desc">Classic apple tartlet with a delicate pastry crust.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Exotic Tart</div><div class="item-price">800 Kes</div></div>
    <div class="item-desc">A tropical fruit tart inspired by the flavours of the coast.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>
  <div class="item">
    <div class="item-top"><div class="item-name">Crêpe with Fresh Fruits & Chocolate Sauce</div><div class="item-price">800 Kes</div></div>
    <div class="item-desc">Freshly prepared crêpe topped with seasonal fruits and chocolate sauce.</div>
    <div class="item-badges"><span class="badge gluten">🌾</span></div>
  </div>

</div>

<!-- ══════════ DRINKS ══════════ -->
<div class="menu-section" id="drinks">

  <div class="legend">
    <div class="legend-title">Drinks Menu · Zuri</div>
    <div class="legend-note">Enjoy, you deserve it. · Prices in Kenyan Shillings (Kes).</div>
  </div>

  <!-- DRINKS CATEGORY SLIDER -->
  <div class="drink-slider-wrap">
    <div class="drink-slider">
      <div class="cat-pill on" onclick="scrollToDrink('dcat-cocktails',this)">🍸 Cocktails</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-mocktails',this)">🥤 Mocktails</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-juices',this)">🍊 Juices</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-beer',this)">🍺 Beer</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-soft',this)">💧 Soft</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-white',this)">🥂 White</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-red',this)">🍷 Red</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-rose',this)">🌸 Rosé</div>
      <div class="cat-pill" onclick="scrollToDrink('dcat-sparkling',this)">✨ Sparkling</div>
    </div>
  </div>

  <!-- SIGNATURE COCKTAILS -->
  <div id="dcat-cocktails"></div>
  <div class="cat-header"><div class="cat-name">Signature<br><em>Cocktails</em></div><div class="cat-tag">All 1,200 Kes</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Dawa</div><div class="drink-desc">Kenya's iconic cocktail with vodka, lime, honey and crushed ice.</div><div class="drink-price">1,200 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Mojito</div><div class="drink-desc">White rum, fresh mint, lime and soda water.</div><div class="drink-price">1,200 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Piña Colada</div><div class="drink-desc">White rum, coconut cream and pineapple juice.</div><div class="drink-price">1,200 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Gin & Tonic</div><div class="drink-desc">A timeless classic served over ice with fresh lime.</div><div class="drink-price">1,200 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Margarita</div><div class="drink-desc">Tequila, lime juice and orange liqueur with a salted rim.</div><div class="drink-price">1,200 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Strawberry Daiquiri</div><div class="drink-desc">Fresh strawberries, white rum and lime juice.</div><div class="drink-price">1,200 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Aperol Spritz</div><div class="drink-desc">Aperol, prosecco and soda water with orange.</div><div class="drink-price">1,200 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Campari Spritz</div><div class="drink-desc">Campari, prosecco and soda water with orange.</div><div class="drink-price">1,200 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- MOCKTAILS -->
  <div id="dcat-mocktails"></div>
  <div class="cat-header"><div class="cat-name">Refreshing<br><em>Mocktails</em></div><div class="cat-tag">All 800 Kes · Alcohol-Free</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Virgin Mojito</div><div class="drink-desc">Mint, lime and soda water.</div><div class="drink-price">800 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Tropical Sunrise</div><div class="drink-desc">Orange juice, grenadine and soda water.</div><div class="drink-price">800 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Coconut Cooler</div><div class="drink-desc">Coconut water, lime and honey.</div><div class="drink-price">800 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Mango Ginger Fizz</div><div class="drink-desc">Mango purée, fresh ginger and soda water.</div><div class="drink-price">800 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Fruit Punch</div><div class="drink-desc">A tropical blend of fresh fruit juices.</div><div class="drink-price">800 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- FRESH JUICES -->
  <div id="dcat-juices"></div>
  <div class="cat-header"><div class="cat-name">Fresh<br><em>Juices</em></div><div class="cat-tag">All 450 Kes</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Pineapple Juice</div><div class="drink-price">450 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Passion Fruit Juice</div><div class="drink-price">450 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Mango Juice</div><div class="drink-price">450 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Orange Juice</div><div class="drink-price">450 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- BEER & CIDER -->
  <div id="dcat-beer"></div>
  <div class="cat-header"><div class="cat-name">Beer &<br><em>Cider</em></div><div class="cat-tag">All 700 Kes</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Craft Kenyan Beer</div><div class="drink-price">700 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Corona Extra</div><div class="drink-price">700 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Desperados</div><div class="drink-price">700 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Savanna Dry Cider</div><div class="drink-price">700 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- SOFT DRINKS -->
  <div id="dcat-soft"></div>
  <div class="cat-header"><div class="cat-name">Soft<br><em>Drinks</em></div><div class="cat-tag">Water & Sodas</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Sparkling Water 330ml</div><div class="drink-price">300 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Sparkling Water 700ml</div><div class="drink-price">400 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Soda</div><div class="drink-desc">Coca-Cola, Coca-Cola Light, Fanta, Sprite, Lemon Krest, Stoney, Tonic Water, Soda Water.</div><div class="drink-price">250 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- WHITE WINES -->
  <div id="dcat-white"></div>
  <div class="cat-header"><div class="cat-name">White<br><em>Wines</em></div><div class="cat-tag">Bottle</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Bruce Jack Chenin Blanc <span style="font-size:.78rem;color:var(--light);">South Africa</span></div><div class="drink-desc">Fresh, fruity, easy with notes of peaches.</div><div class="drink-price">3,600 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Hesketh Sauvignon Blanc <span style="font-size:.78rem;color:var(--light);">Australia</span></div><div class="drink-desc">Citrusy and aromatic with tropical fruit notes.</div><div class="drink-price">4,000 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Clear Water Cove Sauvignon Blanc <span style="font-size:.78rem;color:var(--light);">New Zealand</span></div><div class="drink-desc">Bright, crisp, refreshingly dry.</div><div class="drink-price">4,500 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Bruce Jack Reserve Chardonnay <span style="font-size:.78rem;color:var(--light);">South Africa</span></div><div class="drink-desc">Smooth, elegant with subtle oak notes.</div><div class="drink-price">4,800 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Fantinel Borgo Tesis Pinot Grigio <span style="font-size:.78rem;color:var(--light);">Italy</span></div><div class="drink-desc">Light, crisp and mineral-driven.</div><div class="drink-price">5,500 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- RED WINES -->
  <div id="dcat-red"></div>
  <div class="cat-header"><div class="cat-name">Red<br><em>Wines</em></div><div class="cat-tag">Bottle</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Bruce Jack Pinotage Malbec <span style="font-size:.78rem;color:var(--light);">South Africa</span></div><div class="drink-desc">Rich dark berries with hints of chocolate.</div><div class="drink-price">3,600 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Santa Cristina Le Maestrelle <span style="font-size:.78rem;color:var(--light);">Italy</span></div><div class="drink-desc">Elegant, balanced with notes of cherry and soft spice.</div><div class="drink-price">5,800 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- ROSÉ WINES -->
  <div id="dcat-rose"></div>
  <div class="cat-header"><div class="cat-name">Rosé<br><em>Wines</em></div><div class="cat-tag">Bottle</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Bruce Jack Sauvignon Blush <span style="font-size:.78rem;color:var(--light);">South Africa</span></div><div class="drink-desc">Light, fresh and fruity.</div><div class="drink-price">3,600 Kes</div></div>
  <div class="drink-item"><div class="drink-name">Côte des Roses Rosé <span style="font-size:.78rem;color:var(--light);">France</span></div><div class="drink-desc">Elegant and aromatic with delicate berry notes.</div><div class="drink-price">5,800 Kes</div></div>

  <div class="cat-gap"></div>

  <!-- SPARKLING -->
  <div id="dcat-sparkling"></div>
  <div class="cat-header"><div class="cat-name">Sparkling<br><em>Wines</em></div><div class="cat-tag">Bottle</div></div>
  <div class="cat-rule"></div>
  <div class="drink-item"><div class="drink-name">Fantinel Spumante Cuvée Prestige <span style="font-size:.78rem;color:var(--light);">Italy</span></div><div class="drink-desc">Fresh and delicate with floral aromas.</div><div class="drink-price">4,000 Kes</div></div>
  <div class="drink-item"><div class="drink-name">The Independent Prosecco Brut <span style="font-size:.78rem;color:var(--light);">Italy</span></div><div class="drink-desc">Crisp and lively with fine bubbles.</div><div class="drink-price">6,200 Kes</div></div>

  <div style="padding:1.2rem 1.4rem;background:var(--cream);border-top:1px solid var(--border);margin-top:.5rem;">
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.14rem;font-style:italic;color:var(--light);line-height:1.65;text-align:center;">"A good holiday is remembered in moments, places, and flavours.<br>Let each glass become part of your Zuri experience."</div>
  </div>

</div>

<!-- FOOTER -->
<div class="menu-footer">
  <div class="footer-text">Zuri is part of the Tribal Sand collection — coastal properties in Watamu, Kilifi and Vipingo, created with deep respect for Kenya's natural beauty, local communities and the environment.</div>
  <div class="footer-sub">Every table is part of a bigger story.</div>
  <div class="footer-thank">Truly, Thank You.</div>
  <div class="footer-web">tribalsand.com · reservations@tribalsand.com</div>
</div>

<script>
function scrollToFood(id, el){
  document.querySelectorAll('#foodSlider .cat-pill').forEach(p=>p.classList.remove('on'));
  el.classList.add('on');
  el.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
  var target = document.getElementById(id);
  if(target){
    var offset = target.getBoundingClientRect().top + window.scrollY - 100;
    window.scrollTo({top:offset, behavior:'smooth'});
  }
}

function scrollToDrink(id, el){
  document.querySelectorAll('.drink-slider .cat-pill').forEach(p=>p.classList.remove('on'));
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
  var foodCats = ['cat-soups','cat-starters','cat-salads','cat-pasta','cat-seafood','cat-meat','cat-indian','cat-sides','cat-desserts'];
  var drinkCats = ['dcat-cocktails','dcat-mocktails','dcat-juices','dcat-beer','dcat-soft','dcat-white','dcat-red','dcat-rose','dcat-sparkling'];
  var cats = document.getElementById('food').classList.contains('on') ? foodCats : drinkCats;
  var pills = document.querySelectorAll(document.getElementById('food').classList.contains('on') ? '#foodSlider .cat-pill' : '.drink-slider .cat-pill');
  var current = 0;
  cats.forEach(function(id, i){
    var el = document.getElementById(id);
    if(el && el.getBoundingClientRect().top < 120) current = i;
  });
  pills.forEach(function(p,i){ p.classList.toggle('on', i===current); });
}, {passive:true});

function switchTab(id, el){
  document.querySelectorAll('.menu-section').forEach(s=>s.classList.remove('on'));
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));
  document.getElementById(id).classList.add('on');
  el.classList.add('on');
  window.scrollTo({top:0,behavior:'smooth'});
  // Reset all pills to first
  document.querySelectorAll('#foodSlider .cat-pill, .drink-slider .cat-pill').forEach(function(p,i){
    p.classList.toggle('on', i===0 || (p.closest('.drink-slider') && document.querySelectorAll('.drink-slider .cat-pill')[0]===p));
  });
}
</script>
</body>
</html>
