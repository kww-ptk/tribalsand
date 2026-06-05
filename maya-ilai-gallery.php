<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'Maya Ilai Gallery · Kilifi Eco Compound · Tribal Sand';
$page_desc  = 'Browse photos of Maya Ilai — Kilifi\'s adults-only eco compound. 8 three-bedroom villas, 8 studios, solar powered and beachfront.';
$page_url   = 'https://tribalsand.com/maya-ilai-gallery.php';
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Maya Ilai','url'=>'https://tribalsand.com/maya_ilai.php'],
    ['name'=>'Gallery','url'=>'https://tribalsand.com/maya-ilai-gallery.php'],
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
  <div class="gal-hero-bg" style="background-image:url('images/maya_illai/Best1.jpg');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title">Maya Ilai · <em>Gallery</em></h1>
  </div>
</section>

<!-- Filter Tabs -->
<div class="gal-filters">
  <button class="gal-btn on" data-cat="all">All</button>
  <button class="gal-btn" data-cat="villas">Villas</button>
  <button class="gal-btn" data-cat="studios">Studios</button>
  <button class="gal-btn" data-cat="site-grounds">Site &amp; Grounds</button>
</div>

<!-- Grid -->
<div class="gal-grid">

  <!-- Villas -->
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa1.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa1.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa2.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa2.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa3.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa3.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa4.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa4.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa5.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa5.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa6.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa6.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa7.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa7.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa8.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa8.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa9.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa9.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa10.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa10.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa11.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa11.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa12.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa12.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa13.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa13.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa14.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa14.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa15.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa15.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa16.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa16.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa17.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa17.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa18.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa18.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa19.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa19.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="villas">
    <a class="glightbox" href="images/maya_illai/Villa/Villa20.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai villa interior">
      <img src="images/maya_illai/Villa/Villa20.jpeg" alt="Maya Ilai villa interior" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Studios -->
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio1.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio1.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio2.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio2.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio3.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio3.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio4.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio4.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio5.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio5.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio6.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio6.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio7.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio7.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio8.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio8.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio9.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio9.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio10.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio10.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio11.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio11.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio12.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio12.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio13.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio13.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio14.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio14.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="studios">
    <a class="glightbox" href="images/maya_illai/Studios/Studio15.jpeg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai studio">
      <img src="images/maya_illai/Studios/Studio15.jpeg" alt="Maya Ilai studio" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Site & Grounds -->
  <div class="gal-item" data-cat="site-grounds">
    <a class="glightbox" href="images/maya_illai/Best1.jpg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai site and grounds">
      <img src="images/maya_illai/Best1.jpg" alt="Maya Ilai site and grounds" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="site-grounds">
    <a class="glightbox" href="images/maya_illai/best5.jpg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai site and grounds">
      <img src="images/maya_illai/best5.jpg" alt="Maya Ilai site and grounds" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="site-grounds">
    <a class="glightbox" href="images/maya_illai/best6.jpg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai site and grounds">
      <img src="images/maya_illai/best6.jpg" alt="Maya Ilai site and grounds" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="site-grounds">
    <a class="glightbox" href="images/maya_illai/Best9.jpg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai site and grounds">
      <img src="images/maya_illai/Best9.jpg" alt="Maya Ilai site and grounds" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="site-grounds">
    <a class="glightbox" href="images/maya_illai/SITE%20PHOTOS%20BAR%20AND%20POOL-images-1.jpg" data-gallery="maya-ilai-gallery" data-glightbox="title: Maya Ilai bar and pool">
      <img src="images/maya_illai/SITE PHOTOS BAR AND POOL-images-1.jpg" alt="Maya Ilai bar and pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

</div>

<!-- Back Link -->
<a class="gal-back" href="maya_ilai.php">&larr; Back to Maya Ilai</a>

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
