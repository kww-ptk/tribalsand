<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'My Amani Gallery · Vipingo Beachfront Villa · Tribal Sand';
$page_desc  = 'Browse photos of My Amani villa — Kenya\'s most romantic beachfront villa in Vipingo. Infinity pool, hot tub, 5 bedrooms and private chef.';
$page_url   = 'https://tribalsand.com/my-amani-gallery.php';
$page_image = asset_url('images/my-amani/Aerial/myamani-11.webp');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'My Amani','url'=>'https://tribalsand.com/my-amani.php'],
    ['name'=>'Gallery','url'=>'https://tribalsand.com/my-amani-gallery.php'],
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
  <div class="gal-hero-bg" style="background-image:url('images/my-amani/Aerial/myamani-11.webp');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title">My Amani · <em>Gallery</em></h1>
  </div>
</section>

<!-- Filter Tabs -->
<div class="gal-filters">
  <button class="gal-btn on" data-cat="all">All</button>
  <button class="gal-btn" data-cat="aerial">Aerial</button>
  <button class="gal-btn" data-cat="bedrooms">Bedrooms</button>
  <button class="gal-btn" data-cat="outdoors">Outdoors</button>
</div>

<!-- Grid -->
<div class="gal-grid">

  <!-- Aerial -->
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-2.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani beachfront aerial">
      <img src="images/my-amani/Aerial/myamani-2.webp" alt="My Amani beachfront aerial" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-3.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani villa from above">
      <img src="images/my-amani/Aerial/myamani-3.webp" alt="My Amani villa from above" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-4.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-4.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-5.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-5.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-6.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-6.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-7.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-7.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-8.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-8.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-9.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-9.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-10.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-10.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-11.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-11.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="aerial">
    <a class="glightbox" href="images/my-amani/Aerial/myamani-12.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani aerial view">
      <img src="images/my-amani/Aerial/myamani-12.webp" alt="My Amani aerial view" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Bedrooms -->
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%201/myamani.bedroom1.bath.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani bedroom ensuite bathroom">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.bedroom1.bath.webp" alt="My Amani bedroom ensuite bathroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%201/myamani.bedroomlowerright-2.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani bedroom">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.bedroomlowerright-2.webp" alt="My Amani bedroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%201/myamani.night.bedroom1.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani bedroom at night">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 1/myamani.night.bedroom1.webp" alt="My Amani bedroom at night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%202/myamani.bedroom1.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani bedroom 2">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.webp" alt="My Amani bedroom 2" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%202/myamani.bedroom1.bath.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani bedroom 2 bathroom">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 2/myamani.bedroom1.bath.webp" alt="My Amani bedroom 2 bathroom" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%203/myamani.night.twinbed.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani twin bedroom at night">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 3/myamani.night.twinbed.webp" alt="My Amani twin bedroom at night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%204/myamani.twinbedroom.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani twin bedroom 4">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 4/myamani.twinbedroom.webp" alt="My Amani twin bedroom 4" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="bedrooms">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Bedrooms/Bedroom%205/myamani.bedroom5-2.webp" data-gallery="my-amani-gallery" data-glightbox="title: My Amani bedroom 5">
      <img src="images/my-amani/My Amani - Bedrooms/Bedroom 5/myamani.bedroom5-2.webp" alt="My Amani bedroom 5" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Outdoors -->
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Outdoor/My%20Amani%20Outdoor%20Day/My%20Amani%20Best18.jpg" data-gallery="my-amani-gallery" data-glightbox="title: My Amani outdoor terrace">
      <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best18.jpg" alt="My Amani outdoor terrace" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Outdoor/My%20Amani%20Outdoor%20Day/My%20Amani%20Best20.jpg" data-gallery="my-amani-gallery" data-glightbox="title: My Amani outdoor pool area">
      <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best20.jpg" alt="My Amani outdoor pool area" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Outdoor/My%20Amani%20Outdoor%20Day/My%20Amani%20Best21.jpg" data-gallery="my-amani-gallery" data-glightbox="title: My Amani beachfront outdoor">
      <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best21.jpg" alt="My Amani beachfront outdoor" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Outdoor/My%20Amani%20Outdoor%20Day/My%20Amani%20Best25.jpg" data-gallery="my-amani-gallery" data-glightbox="title: My Amani infinity pool">
      <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best25.jpg" alt="My Amani infinity pool" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Outdoor/My%20Amani%20Outdoor%20Day/My%20Amani%20Best31.jpg" data-gallery="my-amani-gallery" data-glightbox="title: My Amani outdoor living">
      <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best31.jpg" alt="My Amani outdoor living" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Outdoor/My%20Amani%20Outdoor%20Night/My%20Amani%20Best34.jpg" data-gallery="my-amani-gallery" data-glightbox="title: My Amani outdoor at night">
      <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Night/My Amani Best34.jpg" alt="My Amani outdoor at night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="outdoors">
    <a class="glightbox" href="images/my-amani/My%20Amani%20-%20Outdoor/My%20Amani%20Outdoor%20Night/My%20Amani%20Best36.jpg" data-gallery="my-amani-gallery" data-glightbox="title: My Amani night terrace">
      <img src="images/my-amani/My Amani - Outdoor/My Amani Outdoor Night/My Amani Best36.jpg" alt="My Amani night terrace" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

</div>

<!-- Back Link -->
<a class="gal-back" href="my-amani.php">&larr; Back to My Amani</a>

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
