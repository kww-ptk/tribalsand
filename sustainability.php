<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'Sustainable Hotels Kenya · Eco Luxury at Tribal Sand';
$page_desc   = 'Discover how Tribal Sand powers Kenya\'s North Coast with solar energy, desalinated water and ocean conservation. 27.59 MWh generated · 21.88T CO₂ avoided.';
$page_url    = 'https://tribalsand.com/sustainability.php';
$page_image  = 'https://tribalsand.com/images/maya_illai/Best1.jpg';

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
    ['name' => 'Sustainability', 'url' => 'https://tribalsand.com/sustainability.php'],
]);
$page_schema .= ts_schema_faq([
    [
        'q' => 'Is Tribal Sand eco friendly?',
        'a' => 'Yes. All Tribal Sand properties run on solar energy, use desalinated water, conduct weekly beach cleanups and support turtle conservation and coral restoration programmes.',
    ],
    [
        'q' => 'What makes Tribal Sand sustainable?',
        'a' => 'We generate 27.59 MWh of solar power annually, avoiding 21.88 tonnes of CO₂. We collect approximately 30kg of beach trash weekly, restore coral reef and protect nesting sea turtles.',
    ],
    [
        'q' => 'Does Tribal Sand use renewable energy?',
        'a' => 'Yes. Our properties — especially Tribal Dunes in Kilifi — are 100% solar powered with battery storage, eliminating diesel dependency.',
    ],
    [
        'q' => 'What is the water source at Tribal Dunes?',
        'a' => 'Tribal Dunes uses 100% desalinated ocean water, making it completely independent of the municipal water supply.',
    ],
]);

require_once 'includes/head.php';
?>
<style>
/* ── SUSTAINABILITY PAGE ── */

/* HERO */
.sus-hero{
  position:relative;min-height:40vh;
  background:var(--teal-d);
  display:flex;align-items:center;
  padding:0 6vw;
  overflow:hidden;
}
.sus-hero::after{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 70% 50%,rgba(30,92,107,.35) 0%,transparent 70%);
  pointer-events:none;
}
.sus-hero-content{position:relative;z-index:2;max-width:720px;padding:5rem 0;}
.sus-eyebrow-sm{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.32em;text-transform:uppercase;
  color:rgba(184,150,90,.8);margin-bottom:1.2rem;
}
.sus-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.2rem,4.5vw,3.8rem);font-weight:300;
  color:#fff;line-height:1.08;margin-bottom:1.4rem;
}
.sus-hero h1 em{font-style:italic;color:var(--sand);}
.sus-hero-sub{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:rgba(255,255,255,.85);line-height:1.9;letter-spacing:.04em;
}

/* STATS STRIP */
.sus-stats{background:var(--teal);padding:3.5rem 6vw;}
.sus-stats-grid{
  display:grid;grid-template-columns:repeat(4,1fr);
  gap:2rem;max-width:1200px;margin:0 auto;text-align:center;
}
.sus-stat-num{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,4vw,3.2rem);font-weight:300;
  color:var(--sand);line-height:1;margin-bottom:.5rem;
}
.sus-stat-label{
  font-family:'Jost',sans-serif;font-size:.7rem;
  letter-spacing:.14em;text-transform:uppercase;
  color:rgba(255,255,255,.82);
}

/* SECTIONS */
.sus-section-wrap{padding:6rem 6vw;}
.sus-section-wrap.bg-off{background:var(--off);}
.sus-section-wrap.bg-white{background:#fff;}
.sus-section-inner{
  display:grid;grid-template-columns:1fr 1fr;
  gap:5rem;align-items:center;
  max-width:1200px;margin:0 auto;
}
.sus-section-inner.reverse{direction:rtl;}
.sus-section-inner.reverse > *{direction:ltr;}
.sus-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:1rem;
}
.sus-section-inner h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);line-height:1.1;margin-bottom:1.4rem;
}
.sus-section-inner h2 em{font-style:italic;color:var(--sand);}
.sus-section-inner p{
  font-family:'Jost',sans-serif;font-size:.94rem;
  color:var(--mid);line-height:1.88;margin-bottom:1rem;
}
.sus-icon-box{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:var(--teal-d);
  min-height:280px;
  border:1px solid rgba(184,150,90,.1);
}
.sus-icon-box .sus-icon-fa{font-size:5rem;color:var(--sand);margin-bottom:1rem;}
.sus-icon-box-label{
  font-family:'Jost',sans-serif;font-size:.62rem;
  letter-spacing:.22em;text-transform:uppercase;
  color:rgba(184,150,90,.75);
}

/* SUB CARDS */
.sus-sub-cards{
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:1.2rem;margin-top:2rem;
}
.sus-sub-card{
  background:#fff;border:1px solid var(--border);padding:1.4rem;
}
.sus-sub-card-title{
  font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.5rem;
}
.sus-sub-card-desc{
  font-family:'Jost',sans-serif;font-size:.76rem;
  color:var(--mid);line-height:1.7;
}

/* FAQ */
.sus-faq{background:var(--teal-d);padding:6rem 6vw;}
.sus-faq-inner{max-width:860px;margin:0 auto;}
.sus-faq h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.6rem);font-weight:400;
  color:#fff;margin-bottom:.75rem;
}
.sus-faq-intro{
  font-family:'Jost',sans-serif;font-size:.86rem;
  color:rgba(255,255,255,.85);margin-bottom:3rem;
}
.sus-faq-item{border-bottom:1px solid rgba(184,150,90,.14);}
.sus-faq-trigger{
  width:100%;background:none;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:space-between;
  padding:1.4rem 0;gap:1rem;text-align:left;
}
.sus-faq-q{
  font-family:'Jost',sans-serif;font-size:.92rem;
  font-weight:500;color:rgba(255,255,255,.9);
  letter-spacing:.02em;line-height:1.5;
}
.sus-faq-icon{
  flex-shrink:0;width:28px;height:28px;
  border:1px solid rgba(184,150,90,.35);
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;color:var(--sand);
  transition:transform .28s,background .2s;
  font-style:normal;
}
.sus-faq-item.open .sus-faq-icon{
  transform:rotate(45deg);
  background:rgba(184,150,90,.12);
}
.sus-faq-panel{
  font-family:'Jost',sans-serif;font-size:.88rem;
  color:rgba(255,255,255,.85);line-height:1.88;
  max-height:0;overflow:hidden;
  transition:max-height .36s cubic-bezier(.4,0,.2,1),padding .28s;
}
.sus-faq-item.open .sus-faq-panel{max-height:400px;padding-bottom:1.6rem;}

/* CTA */
.sus-cta{
  background:var(--off);padding:5.5rem 6vw;
  text-align:center;border-top:1px solid var(--border);
}
.sus-cta p{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.5rem,2.8vw,2.2rem);font-weight:300;
  color:var(--teal-d);margin-bottom:2rem;font-style:italic;
}
.sus-btn{
  display:inline-block;font-family:'Jost',sans-serif;font-size:.72rem;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2.4rem;background:var(--teal-d);color:#fff;
  border:1px solid var(--teal-d);transition:background .22s,border-color .22s;
}
.sus-btn:hover{background:var(--teal);border-color:var(--teal);}

/* RESPONSIVE */
@media(max-width:900px){
  .sus-section-inner{grid-template-columns:1fr;gap:2.5rem;}
  .sus-section-inner.reverse{direction:ltr;}
  .sus-icon-box{min-height:180px;}
  .sus-sub-cards{grid-template-columns:1fr;}
}
@media(max-width:700px){
  .sus-stats-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:420px){
  .sus-stats-grid{grid-template-columns:1fr;}
}
</style>

<body>
<?php include 'includes/header.php'; ?>

<!-- HERO -->
<section class="sus-hero">
  <div class="sus-hero-content">
    <p class="sus-eyebrow-sm">Our Commitment</p>
    <h1>Sustaining Kenya's <em>Coastline</em></h1>
    <p class="sus-hero-sub">Solar powered &middot; Ocean water &middot; Zero single-use plastic &middot; Beach clean &middot; Coral restoration &middot; Turtle conservation</p>
  </div>
</section>

<!-- STATS STRIP -->
<section class="sus-stats">
  <div class="sus-stats-grid">
    <div>
      <div class="sus-stat-num">27.59 MWh</div>
      <div class="sus-stat-label">Solar Energy Generated</div>
    </div>
    <div>
      <div class="sus-stat-num">21.88T</div>
      <div class="sus-stat-label">CO&#8322; Emissions Avoided</div>
    </div>
    <div>
      <div class="sus-stat-num">~30kg</div>
      <div class="sus-stat-label">Beach Waste Collected Weekly</div>
    </div>
    <div>
      <div class="sus-stat-num">100%</div>
      <div class="sus-stat-label">Desalinated Water at Tribal Dunes</div>
    </div>
  </div>
</section>

<!-- SECTION 1: SOLAR POWER -->
<div class="sus-section-wrap bg-white">
  <div class="sus-section-inner">
    <div>
      <p class="sus-eyebrow">Energy</p>
      <h2>Solar-Powered <em>Hospitality</em></h2>
      <p>Our properties run on photovoltaic solar panels with battery storage. At Tribal Dunes, we have achieved full energy independence — eliminating diesel generators and powering everything from kitchen to air conditioning with the African sun.</p>
      <p>In 2024 our system generated 27.59 MWh of clean electricity, avoiding 21.88 tonnes of CO&#8322; that would otherwise have entered the atmosphere. This makes us one of Africa's leading <strong>solar powered hotel</strong> operations — a model for <strong>sustainable hotel Kenya</strong>.</p>
    </div>
    <div class="sus-icon-box">
      <i class="fas fa-sun sus-icon-fa"></i>
      <span class="sus-icon-box-label">Solar Powered</span>
    </div>
  </div>
</div>

<!-- SECTION 2: WATER -->
<div class="sus-section-wrap bg-off">
  <div class="sus-section-inner reverse">
    <div>
      <p class="sus-eyebrow">Water</p>
      <h2>Every Drop from <em>the Ocean</em></h2>
      <p>Tribal Dunes in Kilifi draws 100% of its water from the Indian Ocean through a reverse osmosis desalination system. This means no municipal water dependency, no borehole depletion, and no plastic bottles — just clean, mineral-rich water produced on-site.</p>
      <p>This commitment to water independence is a cornerstone of our <strong>eco friendly accommodation Kenya</strong> vision.</p>
    </div>
    <div class="sus-icon-box" style="background:var(--teal);">
      <i class="fas fa-water sus-icon-fa"></i>
      <span class="sus-icon-box-label">Desalinated Ocean Water</span>
    </div>
  </div>
</div>

<!-- SECTION 3: OCEAN CONSERVATION -->
<div class="sus-section-wrap bg-white">
  <div class="sus-section-inner">
    <div>
      <p class="sus-eyebrow">Conservation</p>
      <h2>Protecting the <em>Reef &amp; Shore</em></h2>
      <p>Our team collects approximately 30kg of beach waste every week along Bofa Beach and the Watamu coastline. We support active coral restoration projects, transplanting fragments to help rebuild Kenya's degraded reef systems.</p>
      <p>At Vipingo, My Amani participates in turtle monitoring and nest protection — a programme that has helped dozens of green and hawksbill turtles reach the ocean safely.</p>
      <div class="sus-sub-cards">
        <div class="sus-sub-card">
          <div class="sus-sub-card-title">Beach Cleanups</div>
          <div class="sus-sub-card-desc">~30kg/week removed from Kenya's coastline</div>
        </div>
        <div class="sus-sub-card">
          <div class="sus-sub-card-title">Coral Restoration</div>
          <div class="sus-sub-card-desc">Fragments replanted to rebuild reef ecosystems</div>
        </div>
        <div class="sus-sub-card">
          <div class="sus-sub-card-title">Turtle Conservation</div>
          <div class="sus-sub-card-desc">Nest monitoring at Vipingo beach</div>
        </div>
      </div>
    </div>
    <div class="sus-icon-box">
      <i class="fas fa-fish sus-icon-fa"></i>
      <span class="sus-icon-box-label">Ocean Conservation</span>
    </div>
  </div>
</div>

<!-- SECTION 4: COMMUNITY -->
<div class="sus-section-wrap bg-off">
  <div class="sus-section-inner reverse">
    <div>
      <p class="sus-eyebrow">Community</p>
      <h2>Supporting Local <em>Kenya</em></h2>
      <p>Over 80% of our team are from local coastal communities. We source fresh produce from local farmers and fishermen, fund school initiatives, and run skills training programmes for young Kenyans in hospitality, marine conservation and renewable energy.</p>
      <p>True <strong>eco luxury Kenya coast</strong> means investing in the people who make the coast what it is.</p>
    </div>
    <div class="sus-icon-box" style="background:var(--teal);">
      <i class="fas fa-hands-helping sus-icon-fa"></i>
      <span class="sus-icon-box-label">80% Local Team</span>
    </div>
  </div>
</div>

<!-- SECTION 5: ZERO PLASTIC -->
<div class="sus-section-wrap bg-white">
  <div class="sus-section-inner">
    <div>
      <p class="sus-eyebrow">Materials</p>
      <h2>No Single-Use <em>Plastic</em></h2>
      <p>Single-use plastics are banned across all Tribal Sand properties. Guests use refillable glass bottles, bamboo toiletry dispensers and organic linen. Our packaging is biodegradable, and kitchen waste is composted on-site.</p>
      <p>This is the standard we hold ourselves to as Kenya's most responsible <strong>eco friendly accommodation</strong> provider.</p>
    </div>
    <div class="sus-icon-box">
      <i class="fas fa-leaf sus-icon-fa"></i>
      <span class="sus-icon-box-label">Zero Single-Use Plastic</span>
    </div>
  </div>
</div>

<!-- FAQ ACCORDION -->
<section class="sus-faq">
  <div class="sus-faq-inner">
    <h2>Common Questions</h2>
    <p class="sus-faq-intro">Everything you want to know about our sustainability practices.</p>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">Is Tribal Sand eco friendly?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">Yes. All Tribal Sand properties run on solar energy, use desalinated water, conduct weekly beach cleanups and support turtle conservation and coral restoration programmes.</div>
    </div>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">What makes Tribal Sand sustainable?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">We generate 27.59 MWh of solar power annually, avoiding 21.88 tonnes of CO&#8322;. We collect approximately 30kg of beach trash weekly, restore coral reef and protect nesting sea turtles.</div>
    </div>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">Does Tribal Sand use renewable energy?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">Yes. Our properties — especially Tribal Dunes in Kilifi — are 100% solar powered with battery storage, eliminating diesel dependency.</div>
    </div>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">What is the water source at Tribal Dunes?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">Tribal Dunes uses 100% desalinated ocean water, making it completely independent of the municipal water supply.</div>
    </div>

  </div>
</section>

<!-- CTA STRIP -->
<section class="sus-cta">
  <p>"Stay where sustainability is not a compromise."</p>
  <a href="/#properties" class="sus-btn">Explore Our Properties</a>
</section>

<script>
(function(){
  document.querySelectorAll('.sus-faq-trigger').forEach(function(btn){
    btn.addEventListener('click',function(){
      var item = btn.closest('.sus-faq-item');
      var isOpen = item.classList.contains('open');
      document.querySelectorAll('.sus-faq-item.open').forEach(function(el){
        el.classList.remove('open');
        el.querySelector('.sus-faq-trigger').setAttribute('aria-expanded','false');
      });
      if(!isOpen){
        item.classList.add('open');
        btn.setAttribute('aria-expanded','true');
      }
    });
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
</body>
