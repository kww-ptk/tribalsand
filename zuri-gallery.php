<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'Zuri Gallery · Watamu Boutique Hotel · Tribal Sand';
$page_desc  = 'Browse photos of Zuri Boutique Hotel in Watamu — 6 suites, beachfront pool, lush gardens and Kenya\'s most pristine marine park coast.';
$page_url   = 'https://tribalsand.com/zuri-gallery.php';
$page_image = asset_url('images/hero-zuri.jpg');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Zuri','url'=>'https://tribalsand.com/zuri.php'],
    ['name'=>'Gallery','url'=>'https://tribalsand.com/zuri-gallery.php'],
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
  <div class="gal-hero-bg" style="background-image:url('images/zuri/Aerial/zuri-3.webp');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title">Zuri · <em>Gallery</em></h1>
  </div>
</section>

<!-- Filter Tabs -->
<div class="gal-filters">
  <button class="gal-btn on" data-cat="all">All</button>
  <button class="gal-btn" data-cat="aerial">Aerial</button>
  <button class="gal-btn" data-cat="garden-pool">Garden &amp; Pool</button>
  <button class="gal-btn" data-cat="beach">Beach</button>
</div>

<!-- Grid -->
<div class="gal-grid">

  <!-- Aerial -->
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-2.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-2.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-3.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-3.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-4.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-4.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-5.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-5.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-6.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-6.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-7.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-7.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-8.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-8.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-9.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-9.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-11.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-11.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-12.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-12.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-13.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-13.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-14.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-14.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/zuri/Aerial/zuri-15.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu aerial view">
      <img src="images/zuri/Aerial/zuri-15.webp" alt="Zuri Watamu aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Garden & Pool -->
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-3.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-3.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-10.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-10.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-11.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-11.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-13.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-13.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-14.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-14.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-17.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-17.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-19.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-19.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-21.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-21.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-28.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-28.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-30.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-30.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.pool-31.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri morning pool">
      <img src="images/zuri/Garden/zuri.watamu.morning.pool-31.webp" alt="Zuri morning pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.morning.upstares.outdoor.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri upstairs outdoor terrace">
      <img src="images/zuri/Garden/zuri.watamu.morning.upstares.outdoor.webp" alt="Zuri upstairs outdoor terrace" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.entryoutdoor.garden-2.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri entry garden">
      <img src="images/zuri/Garden/zuri.watamu.entryoutdoor.garden-2.webp" alt="Zuri entry garden" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.entryoutdoor.garden-3.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri entry garden">
      <img src="images/zuri/Garden/zuri.watamu.entryoutdoor.garden-3.webp" alt="Zuri entry garden" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="garden-pool">
    <a class="glightbox" href="images/zuri/Garden/zuri.watamu.entryoutdoor.garden-4.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri entry garden">
      <img src="images/zuri/Garden/zuri.watamu.entryoutdoor.garden-4.webp" alt="Zuri entry garden" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Beach -->
  <div class="gal-item" data-cat="beach">
    <a class="glightbox" href="images/zuri/Beach/zuri.watamu.beach.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu beach">
      <img src="images/zuri/Beach/zuri.watamu.beach.webp" alt="Zuri Watamu beach" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="beach">
    <a class="glightbox" href="images/zuri/Beach/zuri.watamu.beach-2.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu beach">
      <img src="images/zuri/Beach/zuri.watamu.beach-2.webp" alt="Zuri Watamu beach" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="beach">
    <a class="glightbox" href="images/zuri/Beach/zuri.watamu.beach-3.webp" data-gallery="zuri-gallery" data-glightbox="title: Zuri Watamu beach">
      <img src="images/zuri/Beach/zuri.watamu.beach-3.webp" alt="Zuri Watamu beach" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

</div>

<!-- Back Link -->
<a class="gal-back" href="zuri.php">&larr; Back to Zuri</a>

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
