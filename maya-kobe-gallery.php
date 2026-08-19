<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'Maya Kobe Gallery · Kilifi Boutique Hotel · Tribal Sand';
$page_desc  = 'Browse photos of Maya Kobe Boutique Hotel — Balinese-inspired luxury on Bofa Beach, Kilifi. 5 suites, 20m pool, ocean views.';
$page_url   = 'https://tribalsand.com/maya-kobe-gallery.php';
$page_image = asset_url('images/hero-maya-kobe.jpg');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Maya Kobe','url'=>'https://tribalsand.com/maya-kobe.php'],
    ['name'=>'Gallery','url'=>'https://tribalsand.com/maya-kobe-gallery.php'],
]);
require_once 'includes/head.php';
?>
<body>
<?php include 'includes/header.php'; ?>

<style>
.gal-hero{position:relative;height:45vh;min-height:300px;overflow:hidden;display:flex;align-items:flex-end;padding-bottom:3rem;}
.gal-hero-bg{position:absolute;inset:0;background-size:cover;background-position:center;}
.gal-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to top,rgba(16,47,58,.85) 0%,rgba(16,47,58,.35) 100%);}
.gal-hero-content{position:relative;z-index:1;padding:0 var(--px,5vw);}
.gal-eyebrow{font-size:.55rem;letter-spacing:.35em;text-transform:uppercase;color:rgba(184,150,90,.7);margin-bottom:.5rem;}
.gal-title{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,5vw,3.2rem);font-weight:300;color:#fff;line-height:1.05;}
.gal-filters{display:flex;gap:.5rem;flex-wrap:wrap;padding:1.5rem var(--px,5vw);border-bottom:1px solid var(--border);}
.gal-btn{padding:.55rem 1.1rem;border:1px solid var(--border);background:transparent;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:all .2s;color:var(--mid);}
.gal-btn.on{background:var(--teal);border-color:var(--teal);color:#fff;}
.gal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;padding:3px;}
.gal-item{position:relative;overflow:hidden;aspect-ratio:4/3;cursor:pointer;}
.gal-item img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease;}
.gal-item:hover img{transform:scale(1.05);}
.gal-item-overlay{position:absolute;inset:0;background:rgba(16,47,58,0);transition:background .3s;display:flex;align-items:center;justify-content:center;}
.gal-item:hover .gal-item-overlay{background:rgba(16,47,58,.35);}
.gal-item-overlay i{color:rgba(255,255,255,0);font-size:1.5rem;transition:color .3s;}
.gal-item:hover .gal-item-overlay i{color:#fff;}
.gal-back{display:inline-flex;align-items:center;gap:.5rem;font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:var(--teal);text-decoration:none;padding:2rem var(--px,5vw);display:block;}
@media(max-width:640px){.gal-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:400px){.gal-grid{grid-template-columns:1fr;}}
</style>

<!-- Hero -->
<section class="gal-hero">
  <div class="gal-hero-bg" style="background-image:url('images/maya-kobe/Aerial/mayakobe-2.webp');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title">Maya Kobe · <em>Gallery</em></h1>
  </div>
</section>

<!-- Filter Tabs -->
<div class="gal-filters">
  <button class="gal-btn on" data-cat="all">All</button>
  <button class="gal-btn" data-cat="aerial">Aerial</button>
  <button class="gal-btn" data-cat="suites">Suites</button>
  <button class="gal-btn" data-cat="pool-outdoors">Pool &amp; Outdoors</button>
</div>

<!-- Grid -->
<div class="gal-grid">

  <!-- Aerial -->
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-2.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-2.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-3.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-3.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-4.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-4.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-5.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-5.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-6.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-6.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-7.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-7.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-8.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-8.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-9.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-9.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/maya-kobe/Aerial/mayakobe-10.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe aerial view">
      <img src="images/maya-kobe/Aerial/mayakobe-10.webp" alt="Maya Kobe aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Suites -->
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Bedrooms/Bedroom%201/mayakobe.bedroomrightfrontsunrise.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe suite sunrise view">
      <img src="images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 1/mayakobe.bedroomrightfrontsunrise.webp" alt="Maya Kobe suite sunrise view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Bedrooms/Bedroom%201/mayakobe.bedroomrightfrontsunrise-2.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe suite sunrise view">
      <img src="images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 1/mayakobe.bedroomrightfrontsunrise-2.webp" alt="Maya Kobe suite sunrise view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Bedrooms/Bedroom%201/mayakobe.night.bedroom1.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe suite at night">
      <img src="images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 1/mayakobe.night.bedroom1.webp" alt="Maya Kobe suite at night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Bedrooms/Bedroom%201/mayakobe.night.bedroom1-2.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe suite at night">
      <img src="images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 1/mayakobe.night.bedroom1-2.webp" alt="Maya Kobe suite at night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Bedrooms/Bedroom%203/mayakobe.bedroomleftfrontsunrise.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe suite left front sunrise">
      <img src="images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 3/mayakobe.bedroomleftfrontsunrise.webp" alt="Maya Kobe suite left front sunrise" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Bedrooms/Bedroom%203/mayakobe.bedroomleftfrontsunrise-2.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe suite left front sunrise">
      <img src="images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 3/mayakobe.bedroomleftfrontsunrise-2.webp" alt="Maya Kobe suite left front sunrise" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Bedrooms/Bedroom%204/mayakobe.bedroomleftfrontsunrise2-2.webp" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe bedroom 4 sunrise">
      <img src="images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 4/mayakobe.bedroomleftfrontsunrise2-2.webp" alt="Maya Kobe bedroom 4 sunrise" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Cottage/Cottage%20Bedrooms/Maya%20Kobe%20prestige%20suite.jpg" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe prestige suite">
      <img src="images/maya-kobe/Maya Kobe - Cottage/Cottage Bedrooms/Maya Kobe prestige suite.jpg" alt="Maya Kobe prestige suite" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="suites">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Cottage/Cottage%20Bedrooms/Maya%20Kobe%20prestige%20suite%207.jpg" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe prestige suite">
      <img src="images/maya-kobe/Maya Kobe - Cottage/Cottage Bedrooms/Maya Kobe prestige suite 7.jpg" alt="Maya Kobe prestige suite" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Pool & Outdoors -->
  <div class="gal-item" data-cat="pool-outdoors">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Day%20Outdoor,%20Pool,%20Beach/Maya%20Kobe%20Best3.jpg" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe pool and beach">
      <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg" alt="Maya Kobe pool and beach" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool-outdoors">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Day%20Outdoor,%20Pool,%20Beach/Maya%20Kobe%20Best4.jpg" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe outdoor pool">
      <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg" alt="Maya Kobe outdoor pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool-outdoors">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Day%20Outdoor,%20Pool,%20Beach/Maya%20Kobe%20Best12.jpg" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe outdoor terrace">
      <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best12.jpg" alt="Maya Kobe outdoor terrace" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool-outdoors">
    <a class="glightbox" href="images/maya-kobe/Maya%20Kobe%20-%20Day%20Outdoor,%20Pool,%20Beach/Maya%20Kobe%20Best14.jpg" data-gallery="maya-kobe-gallery" data-glightbox="title: Maya Kobe beachfront">
      <img src="images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best14.jpg" alt="Maya Kobe beachfront" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

</div>

<!-- Back Link -->
<a class="gal-back" href="maya-kobe.php">&larr; Back to Maya Kobe</a>

<?php include 'includes/footer.php'; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
<script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
<script>
const lightbox = GLightbox({selector:'.glightbox',touchNavigation:true,loop:true});
document.querySelectorAll('.gal-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.querySelectorAll('.gal-btn').forEach(b=>b.classList.remove('on'));
    this.classList.add('on');
    const cat=this.dataset.cat;
    document.querySelectorAll('.gal-item').forEach(item=>{
      item.style.display=(cat==='all'||item.dataset.cat===cat)?'':'none';
    });
  });
});
</script>
</body>
