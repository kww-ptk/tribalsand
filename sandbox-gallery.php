<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'Sandbox Villa Gallery · Kilifi · Tribal Sand';
$page_desc  = 'Browse photos of Sandbox Villa in Kilifi — 4 bedrooms, private pool, self-catering. Perfect for families and small groups.';
$page_url   = 'https://tribalsand.com/sandbox-gallery.php';
$page_image = asset_url('images/hero-sandbox.jpg');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Sandbox','url'=>'https://tribalsand.com/sandbox.php'],
    ['name'=>'Gallery','url'=>'https://tribalsand.com/sandbox-gallery.php'],
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
  <div class="gal-hero-bg" style="background-image:url('images/Sandbox/outdoors/IMG-20251117-WA0091.jpg');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title">Sandbox · <em>Gallery</em></h1>
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
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0043.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0043.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0044.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0044.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0053.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0053.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0058.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0058.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0074.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0074.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0075.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0075.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0076.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0076.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0089.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0089.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0090.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0090.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/Sandbox/outdoors/IMG-20251117-WA0091.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa outdoor area">
      <img src="images/Sandbox/outdoors/IMG-20251117-WA0091.jpg" alt="Sandbox Villa outdoor area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Pool -->
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0054.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa swimming pool">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0054.jpg" alt="Sandbox Villa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0055.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa swimming pool">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0055.jpg" alt="Sandbox Villa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0056.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa swimming pool">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0056.jpg" alt="Sandbox Villa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0057.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa swimming pool">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0057.jpg" alt="Sandbox Villa swimming pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0059.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa pool area">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0059.jpg" alt="Sandbox Villa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0080.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa pool area">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0080.jpg" alt="Sandbox Villa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0081.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa pool area">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0081.jpg" alt="Sandbox Villa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0083.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa pool area">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0083.jpg" alt="Sandbox Villa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0084.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa pool area">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0084.jpg" alt="Sandbox Villa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0085.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa pool area">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0085.jpg" alt="Sandbox Villa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool">
    <a class="glightbox" href="images/Sandbox/swimmingpool/IMG-20251117-WA0088.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa pool area">
      <img src="images/Sandbox/swimmingpool/IMG-20251117-WA0088.jpg" alt="Sandbox Villa pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Bedrooms -->
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0077.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0077.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0078.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0078.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0079.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0079.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0082.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0082.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0086.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0086.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0087.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0087.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0094.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0094.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/Sandbox/Bedroom/IMG-20251117-WA0096.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa bedroom">
      <img src="images/Sandbox/Bedroom/IMG-20251117-WA0096.jpg" alt="Sandbox Villa bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Living & Dining -->
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0045.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0045.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0046.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0046.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0047.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0047.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0048.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0048.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0049.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0049.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0050.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0050.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0051.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0051.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0052.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0052.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0069.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0069.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0071.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0071.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="living-dining">
    <a class="glightbox" href="images/Sandbox/living-dining/IMG-20251117-WA0073.jpg" data-gallery="sandbox-gallery" data-glightbox="title: Sandbox Villa living and dining">
      <img src="images/Sandbox/living-dining/IMG-20251117-WA0073.jpg" alt="Sandbox Villa living and dining" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

</div>

<!-- Back Link -->
<a class="gal-back" href="sandbox.php">&larr; Back to Sandbox</a>

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
