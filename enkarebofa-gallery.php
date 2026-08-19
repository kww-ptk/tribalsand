<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'Enkare Bofa Gallery · Kilifi Villa · Tribal Sand';
$page_desc  = 'Browse photos of Enkare Villa on Bofa Beach, Kilifi. 5 bedrooms, private pool and in-house cook included.';
$page_url   = 'https://tribalsand.com/enkarebofa-gallery.php';
$page_image = asset_url('images/hero-enkare-bofa.jpg');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Enkare Bofa','url'=>'https://tribalsand.com/enkare-bofa.php'],
    ['name'=>'Gallery','url'=>'https://tribalsand.com/enkarebofa-gallery.php'],
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
  <div class="gal-hero-bg" style="background-image:url('images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title">Enkare Bofa · <em>Gallery</em></h1>
  </div>
</section>

<!-- Filter Tabs -->
<div class="gal-filters">
  <button class="gal-btn on" data-cat="all">All</button>
  <button class="gal-btn" data-cat="outdoors">Outdoors</button>
  <button class="gal-btn" data-cat="pool">Pool</button>
  <button class="gal-btn" data-cat="bedrooms">Bedrooms</button>
  <button class="gal-btn" data-cat="living-dining">Living &amp; Dining</button>
</div>

<!-- Grid -->
<div class="gal-grid">

  <!-- Outdoors -->
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0007.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0007.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0009.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0009.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0010.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0010.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0012.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0012.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0013.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0013.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0027.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa beachfront">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0027.jpg" alt="Enkare Bofa beachfront" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0028.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor terrace">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0028.jpg" alt="Enkare Bofa outdoor terrace" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0030.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0030.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0031.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0031.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0034.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0034.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0035.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0035.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/enkare-bofa/Outdoors/IMG-20251117-WA0041.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa outdoor area">
      <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0041.jpg" alt="Enkare Bofa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Pool -->
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0008.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa swimming pool">
      <img src="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0008.jpg" alt="Enkare Bofa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0011.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa swimming pool">
      <img src="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0011.jpg" alt="Enkare Bofa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0014.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa swimming pool">
      <img src="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0014.jpg" alt="Enkare Bofa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0033.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa swimming pool">
      <img src="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0033.jpg" alt="Enkare Bofa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0037.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa pool area">
      <img src="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0037.jpg" alt="Enkare Bofa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0038.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa pool area">
      <img src="images/enkare-bofa/SwimmingPool/IMG-20251117-WA0038.jpg" alt="Enkare Bofa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Bedrooms -->
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0002.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0002.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0004.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0004.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0005.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0005.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0006.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0006.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0018.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0018.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0019.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0019.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0020.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0020.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0021.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0021.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0022.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0022.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0023.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0023.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/enkare-bofa/Bedrooms/IMG-20251117-WA0024.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa bedroom">
      <img src="images/enkare-bofa/Bedrooms/IMG-20251117-WA0024.jpg" alt="Enkare Bofa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Living & Dining -->
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/enkare-bofa/Living-Dining/IMG-20251117-WA0001.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa living and dining">
      <img src="images/enkare-bofa/Living-Dining/IMG-20251117-WA0001.jpg" alt="Enkare Bofa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/enkare-bofa/Living-Dining/IMG-20251117-WA0003.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa living and dining">
      <img src="images/enkare-bofa/Living-Dining/IMG-20251117-WA0003.jpg" alt="Enkare Bofa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/enkare-bofa/Living-Dining/IMG-20251117-WA0015.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa living and dining">
      <img src="images/enkare-bofa/Living-Dining/IMG-20251117-WA0015.jpg" alt="Enkare Bofa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/enkare-bofa/Living-Dining/IMG-20251117-WA0016.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa living and dining">
      <img src="images/enkare-bofa/Living-Dining/IMG-20251117-WA0016.jpg" alt="Enkare Bofa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/enkare-bofa/Living-Dining/IMG-20251117-WA0017.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa living and dining">
      <img src="images/enkare-bofa/Living-Dining/IMG-20251117-WA0017.jpg" alt="Enkare Bofa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/enkare-bofa/Living-Dining/IMG-20251117-WA0025.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa living and dining">
      <img src="images/enkare-bofa/Living-Dining/IMG-20251117-WA0025.jpg" alt="Enkare Bofa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/enkare-bofa/Living-Dining/IMG-20251117-WA0029.jpg" data-gallery="enkare-bofa-gallery" data-glightbox="title: Enkare Bofa living and dining">
      <img src="images/enkare-bofa/Living-Dining/IMG-20251117-WA0029.jpg" alt="Enkare Bofa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

</div>

<!-- Back Link -->
<a class="gal-back" href="enkare-bofa.php">&larr; Back to Enkare Bofa</a>

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
