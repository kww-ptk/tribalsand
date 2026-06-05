<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'Journal · Tribal Sand · Kenya Coast Stories';
$page_desc   = 'Explore stories from Kenya\'s North Coast — travel guides, property features, conservation updates and inspiration from the Tribal Sand team.';
$page_url    = 'https://tribalsand.com/blog.php';
$page_image  = 'https://tribalsand.com/images/maya-kobe/Aerial/mayakobe-2.webp';

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',    'url' => 'https://tribalsand.com/'],
    ['name' => 'Journal', 'url' => 'https://tribalsand.com/blog.php'],
]);
$page_schema .= ts_schema_item_list([
    ['name' => 'Tribal Dunes · Kilifi\'s Beachfront Community', 'url' => 'https://tribalsand.com/tribal-dunes.php'],
    ['name' => 'Sustainable Hotels Kenya · Eco Luxury at Tribal Sand', 'url' => 'https://tribalsand.com/sustainability.php'],
]);

require_once 'includes/head.php';
?>
<style>
/* ── BLOG / JOURNAL PAGE ── */

/* PAGE HEADER */
.blog-header{
  position:relative;min-height:50vh;
  background:var(--teal-d);
  display:flex;align-items:center;
  padding:0 6vw;overflow:hidden;
}
.blog-header::after{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 60% 40%,rgba(30,92,107,.4) 0%,transparent 65%);
  pointer-events:none;
}
.blog-header-content{position:relative;z-index:2;max-width:680px;padding:5.5rem 0;}
.blog-eyebrow{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.32em;text-transform:uppercase;
  color:rgba(184,150,90,.8);margin-bottom:1.2rem;
}
.blog-header h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.2rem,4.5vw,3.8rem);font-weight:300;
  color:#fff;line-height:1.08;
}
.blog-header h1 em{font-style:italic;color:var(--sand);}

/* FEATURED POST */
.blog-featured{padding:5rem 6vw 0;max-width:1200px;margin:0 auto;}
.blog-section-label{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:1.6rem;
}
.blog-feat-card{
  display:grid;grid-template-columns:1fr 1fr;
  border:1px solid var(--border);overflow:hidden;
  transition:box-shadow .22s;
}
.blog-feat-card:hover{box-shadow:0 12px 40px rgba(20,20,18,.1);}
.blog-feat-img{
  aspect-ratio:4/3;
  background:#c9b99a url('images/maya-kobe/Aerial/mayakobe-2.webp') center / cover no-repeat;
}
.blog-feat-body{
  background:#fff;padding:3rem 2.5rem;
  display:flex;flex-direction:column;justify-content:center;
}
.blog-feat-pill{
  display:inline-block;font-family:'Jost',sans-serif;font-size:.54rem;
  letter-spacing:.22em;text-transform:uppercase;
  color:var(--teal);background:rgba(30,92,107,.08);
  padding:.28rem .7rem;margin-bottom:1.4rem;
  border:1px solid rgba(30,92,107,.14);align-self:flex-start;
}
.blog-feat-title{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.5rem,2.5vw,2.2rem);font-weight:400;
  color:var(--teal-d);line-height:1.2;margin-bottom:1rem;
}
.blog-feat-excerpt{
  font-family:'Jost',sans-serif;font-size:.88rem;
  color:var(--mid);line-height:1.8;margin-bottom:1.8rem;
  flex-grow:1;
}
.blog-read-btn{
  display:inline-block;font-family:'Jost',sans-serif;font-size:.7rem;
  letter-spacing:.18em;text-transform:uppercase;
  padding:.75rem 1.8rem;background:var(--teal-d);color:#fff;
  border:1px solid var(--teal-d);align-self:flex-start;
  transition:background .22s,border-color .22s;
}
.blog-read-btn:hover{background:var(--teal);border-color:var(--teal);}

/* POST GRID */
.blog-grid-section{padding:4rem 6vw 6rem;max-width:1200px;margin:0 auto;}
.blog-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:2rem;}
.blog-card{
  background:#fff;border:1px solid var(--border);
  display:flex;flex-direction:column;
  transition:box-shadow .22s,transform .22s;
  overflow:hidden;
}
.blog-card:hover{
  box-shadow:0 8px 32px rgba(20,20,18,.09);
  transform:translateY(-3px);
}
.blog-card-img{
  aspect-ratio:16/9;overflow:hidden;
}
.blog-card-img img{
  width:100%;height:100%;object-fit:cover;
  transition:transform .4s;display:block;
}
.blog-card:hover .blog-card-img img{transform:scale(1.04);}
.blog-card-body{padding:1.6rem;flex-grow:1;display:flex;flex-direction:column;}
.blog-card-pill{
  display:inline-block;font-family:'Jost',sans-serif;font-size:.5rem;
  letter-spacing:.22em;text-transform:uppercase;
  color:var(--sand);border:1px solid rgba(184,150,90,.28);
  padding:.22rem .6rem;margin-bottom:.9rem;align-self:flex-start;
}
.blog-card h3{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.1rem,1.8vw,1.4rem);font-weight:400;
  color:var(--teal-d);line-height:1.25;margin-bottom:.75rem;
}
.blog-card-excerpt{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:var(--mid);line-height:1.75;margin-bottom:1.2rem;flex-grow:1;
}
.blog-card-link{
  font-family:'Jost',sans-serif;font-size:.7rem;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--teal);transition:color .2s;align-self:flex-start;
}
.blog-card-link:hover{color:var(--teal-d);}

/* RESPONSIVE */
@media(max-width:900px){
  .blog-feat-card{grid-template-columns:1fr;}
  .blog-feat-img{aspect-ratio:16/9;}
  .blog-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:600px){
  .blog-grid{grid-template-columns:1fr;}
}
</style>

<body>
<?php include 'includes/header.php'; ?>

<!-- PAGE HEADER -->
<section class="blog-header">
  <div class="blog-header-content">
    <p class="blog-eyebrow">Journal</p>
    <h1>Stories from <em>the Coast</em></h1>
  </div>
</section>

<!-- FEATURED POST -->
<div style="background:var(--off);padding:5rem 0 3rem;">
  <div class="blog-featured">
    <p class="blog-section-label">Featured</p>
    <a href="tribal-dunes.php" class="blog-feat-card" style="display:grid;text-decoration:none;">
      <div class="blog-feat-img" role="img" aria-label="Tribal Dunes aerial view"></div>
      <div class="blog-feat-body">
        <span class="blog-feat-pill">Featured &middot; Tribal Dunes</span>
        <h2 class="blog-feat-title">Kilifi's Beachfront Community for Travellers Who Want More Than a Place to Sleep</h2>
        <p class="blog-feat-excerpt">Inside Tribal Dunes — the solar-powered beachfront village redefining what it means to stay on Kenya's North Coast.</p>
        <span class="blog-read-btn">Read More</span>
      </div>
    </a>
  </div>
</div>

<!-- POST GRID -->
<div style="background:var(--off);">
  <div class="blog-grid-section">
    <p class="blog-section-label">From the Journal</p>
    <div class="blog-grid">

      <article class="blog-card">
        <div class="blog-card-img">
          <img src="images/maya_illai/Best1.jpg" alt="Solar panels at Tribal Sand" loading="lazy">
        </div>
        <div class="blog-card-body">
          <span class="blog-card-pill">Sustainability</span>
          <h3>27.59 MWh: How Solar Power is Changing Luxury Hospitality in Kenya</h3>
          <p class="blog-card-excerpt">A look at our solar energy journey and what it means for guests and planet.</p>
          <a href="sustainability.php" class="blog-card-link">Read &rarr;</a>
        </div>
      </article>

      <article class="blog-card">
        <div class="blog-card-img">
          <img src="images/my-amani/Aerial/myamani-11.webp" alt="My Amani aerial view" loading="lazy">
        </div>
        <div class="blog-card-body">
          <span class="blog-card-pill">Properties</span>
          <h3>My Amani: Kenya's Most Romantic Beachfront Villa</h3>
          <p class="blog-card-excerpt">Five bedrooms, an infinity pool and the Indian Ocean at your doorstep.</p>
          <a href="my-amani.php" class="blog-card-link">Read &rarr;</a>
        </div>
      </article>

      <article class="blog-card">
        <div class="blog-card-img">
          <img src="images/zuri/Aerial/zuri-3.webp" alt="Watamu Marine Park" loading="lazy">
        </div>
        <div class="blog-card-body">
          <span class="blog-card-pill">Guides</span>
          <h3>Watamu Marine Park: A Snorkeller's Guide</h3>
          <p class="blog-card-excerpt">The best snorkelling spots, marine life and how to explore the reef ethically.</p>
          <a href="activities.php" class="blog-card-link">Read &rarr;</a>
        </div>
      </article>

      <article class="blog-card">
        <div class="blog-card-img">
          <img src="images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg" alt="Enkare Bofa villa" loading="lazy">
        </div>
        <div class="blog-card-body">
          <span class="blog-card-pill">Group Travel</span>
          <h3>Planning a Group Holiday on Kenya's North Coast</h3>
          <p class="blog-card-excerpt">Kilifi's best private villas for group bookings — space, privacy and a private chef.</p>
          <a href="enkare-bofa.php" class="blog-card-link">Read &rarr;</a>
        </div>
      </article>

      <article class="blog-card">
        <div class="blog-card-img">
          <img src="images/New-hero-banner.jpg" alt="Tribal Sand Kenya coast" loading="lazy">
        </div>
        <div class="blog-card-body">
          <span class="blog-card-pill">About Us</span>
          <h3>The Story of Tribal Sand</h3>
          <p class="blog-card-excerpt">From a single beachfront villa to Kenya's most complete coastal hospitality ecosystem.</p>
          <a href="tribalsandstory.php" class="blog-card-link">Read &rarr;</a>
        </div>
      </article>

    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
</body>
