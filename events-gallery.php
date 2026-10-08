<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'Events Gallery · Private Events Kenya · Tribal Sand';
$page_desc  = 'Photos from private events, birthdays, NYE celebrations and African nights hosted at Tribal Sand properties on Kenya\'s North Coast.';
$page_url   = 'https://tribalsand.com/events-gallery.php';
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Events','url'=>'https://tribalsand.com/events.php'],
    ['name'=>'Gallery','url'=>'https://tribalsand.com/events-gallery.php'],
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
  <div class="gal-hero-bg" style="background-image:url('images/event-gallery/AfricanNight-2.jpg');"></div>
  <div class="gal-hero-content">
    <p class="gal-eyebrow">Gallery</p>
    <h1 class="gal-title">Events · <em>Gallery</em></h1>
  </div>
</section>

<!-- Filter Tabs -->
<div class="gal-filters">
  <button class="gal-btn on" data-cat="all">All</button>
  <button class="gal-btn" data-cat="african-night">African Night</button>
  <button class="gal-btn" data-cat="nye">NYE</button>
  <button class="gal-btn" data-cat="birthdays">Birthdays</button>
  <button class="gal-btn" data-cat="pool-parties">Pool Parties</button>
</div>

<!-- Grid -->
<div class="gal-grid">

  <!-- African Night -->
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-2.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-2.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-5.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-5.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-24.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-24.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-27.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-27.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-50.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-50.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-51.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-51.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-58.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-58.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-61.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-61.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-66.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-66.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-68.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-68.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-260.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-260.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-396.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-396.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-486.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-486.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/AfricanNight-505.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand African Night">
      <img src="images/event-gallery/AfricanNight-505.jpg" alt="Tribal Sand African Night" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="african-night">
    <a class="glightbox" href="images/event-gallery/BRUNCH.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand Sunday Brunch">
      <img src="images/event-gallery/BRUNCH.jpg" alt="Tribal Sand Sunday Brunch" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- NYE -->
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-4.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-4.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-8.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-8.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-16.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-16.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-25.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-25.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-35.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-35.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-46.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-46.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-53.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-53.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-66.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-66.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-78.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-78.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-87.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-87.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-88.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-88.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-376.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-376.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="nye">
    <a class="glightbox" href="images/event-gallery/NYE-658.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand New Year's Eve">
      <img src="images/event-gallery/NYE-658.jpg" alt="Tribal Sand New Year's Eve" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Birthdays -->
  <div class="gal-item" data-cat="birthdays">
    <a class="glightbox" href="images/event-gallery/Birthday29th-43.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand birthday celebration">
      <img src="images/event-gallery/Birthday29th-43.jpg" alt="Tribal Sand birthday celebration" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="birthdays">
    <a class="glightbox" href="images/event-gallery/Birthday29th-56.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand birthday celebration">
      <img src="images/event-gallery/Birthday29th-56.jpg" alt="Tribal Sand birthday celebration" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="birthdays">
    <a class="glightbox" href="images/event-gallery/Birthday29th-60.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand birthday celebration">
      <img src="images/event-gallery/Birthday29th-60.jpg" alt="Tribal Sand birthday celebration" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="birthdays">
    <a class="glightbox" href="images/event-gallery/Birthday29th-481.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand birthday celebration">
      <img src="images/event-gallery/Birthday29th-481.jpg" alt="Tribal Sand birthday celebration" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="birthdays">
    <a class="glightbox" href="images/event-gallery/Birthday29th-578.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand birthday celebration">
      <img src="images/event-gallery/Birthday29th-578.jpg" alt="Tribal Sand birthday celebration" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="birthdays">
    <a class="glightbox" href="images/event-gallery/Birthday29th-589.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand birthday celebration">
      <img src="images/event-gallery/Birthday29th-589.jpg" alt="Tribal Sand birthday celebration" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="birthdays">
    <a class="glightbox" href="images/event-gallery/Birthday29th-637.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand birthday celebration">
      <img src="images/event-gallery/Birthday29th-637.jpg" alt="Tribal Sand birthday celebration" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

  <!-- Pool Parties -->
  <div class="gal-item" data-cat="pool-parties">
    <a class="glightbox" href="images/event-gallery/PoolParty30th-11.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand pool party">
      <img src="images/event-gallery/PoolParty30th-11.jpg" alt="Tribal Sand pool party" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool-parties">
    <a class="glightbox" href="images/event-gallery/PoolParty30th-16.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand pool party">
      <img src="images/event-gallery/PoolParty30th-16.jpg" alt="Tribal Sand pool party" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool-parties">
    <a class="glightbox" href="images/event-gallery/PoolParty30th-23.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand pool party">
      <img src="images/event-gallery/PoolParty30th-23.jpg" alt="Tribal Sand pool party" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool-parties">
    <a class="glightbox" href="images/event-gallery/PoolParty30th-252.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand pool party">
      <img src="images/event-gallery/PoolParty30th-252.jpg" alt="Tribal Sand pool party" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>
  <div class="gal-item" data-cat="pool-parties">
    <a class="glightbox" href="images/event-gallery/PoolParty30th-302.jpg" data-gallery="events-gallery" data-glightbox="title: Tribal Sand pool party">
      <img src="images/event-gallery/PoolParty30th-302.jpg" alt="Tribal Sand pool party" loading="lazy">
      <div class="gal-item-overlay"><i class="fas fa-expand"></i></div>
    </a>
  </div>

</div>

<!-- Back Link -->
<a class="gal-back" href="events.php">&larr; Back to Events</a>

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
