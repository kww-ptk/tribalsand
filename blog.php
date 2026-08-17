<?php require_once 'includes/schema.php'; ?>
<?php
require_once 'includes/articles.php';

$page_title  = 'Journal · Tribal Sand · Kenya Coast Stories';
$page_desc   = 'Explore stories from Kenya\'s North Coast — travel guides, property features, conservation updates and inspiration from the Tribal Sand team.';
$page_url    = 'https://tribalsand.com/blog.php';
$page_image  = asset_url('images/maya-kobe/Aerial/mayakobe-2.webp');

$articles = ts_articles();

/* Flagship pillar is shown as the featured post; the rest fill the grid. */
$featured_slug = 'why-kenya-coast-best-luxury-destination-africa';
$featured      = $articles[$featured_slug] ?? null;
$grid          = $articles;
unset($grid[$featured_slug]);

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',    'url' => 'https://tribalsand.com/'],
    ['name' => 'Journal', 'url' => 'https://tribalsand.com/blog.php'],
]);
$page_schema .= ts_schema_item_list(array_map(
    fn($slug, $a) => ['name' => $a['title'], 'url' => 'https://tribalsand.com/' . $slug . '.php'],
    array_keys($articles),
    array_values($articles)
));

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
  aspect-ratio:4/3;background:#c9b99a center / cover no-repeat;
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

/* CATEGORY FILTER */
.blog-filter{
  display:flex;flex-wrap:wrap;gap:.6rem;
  max-width:1200px;margin:0 auto;padding:3.5rem 6vw 0;
}
.blog-filter-btn{
  font-family:'Jost',sans-serif;font-size:.62rem;
  letter-spacing:.16em;text-transform:uppercase;
  color:var(--mid);background:#fff;
  border:1px solid var(--border);
  padding:.55rem 1.2rem;cursor:pointer;
  transition:all .2s;
}
.blog-filter-btn:hover{border-color:var(--sand);color:var(--teal-d);}
.blog-filter-btn.active{background:var(--teal-d);color:#fff;border-color:var(--teal-d);}

/* POST GRID */
.blog-grid-section{padding:2.4rem 6vw 6rem;max-width:1200px;margin:0 auto;}
.blog-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:2rem;}
.blog-card{
  background:#fff;border:1px solid var(--border);
  display:flex;flex-direction:column;
  transition:box-shadow .22s,transform .22s;
  overflow:hidden;text-decoration:none;
}
.blog-card:hover{
  box-shadow:0 8px 32px rgba(20,20,18,.09);
  transform:translateY(-3px);
}
.blog-card.is-hidden{display:none;}
.blog-card-img{aspect-ratio:16/9;overflow:hidden;background:#e9e0d0;}
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
.blog-card:hover .blog-card-link{color:var(--teal-d);}

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

<?php if ($featured): ?>
<!-- FEATURED POST -->
<div style="background:var(--off);padding:5rem 0 3rem;">
  <div class="blog-featured">
    <p class="blog-section-label">Featured</p>
    <a href="<?= e($featured_slug) ?>.php" class="blog-feat-card" style="display:grid;text-decoration:none;">
      <div class="blog-feat-img" role="img" aria-label="<?= e($featured['title']) ?>" style="background-image:url('<?= e($featured['image']) ?>');"></div>
      <div class="blog-feat-body">
        <span class="blog-feat-pill">Featured &middot; <?= e($featured['category']) ?></span>
        <h2 class="blog-feat-title"><?= e($featured['title']) ?></h2>
        <p class="blog-feat-excerpt"><?= e($featured['excerpt']) ?></p>
        <span class="blog-read-btn">Read More</span>
      </div>
    </a>
  </div>
</div>
<?php endif; ?>

<!-- CATEGORY FILTER -->
<div style="background:var(--off);">
  <div class="blog-filter" id="blogFilter">
    <button class="blog-filter-btn active" data-cat="all">All</button>
    <?php foreach (TS_ARTICLE_CATEGORIES as $cat): ?>
      <button class="blog-filter-btn" data-cat="<?= e($cat) ?>"><?= e($cat) ?></button>
    <?php endforeach; ?>
  </div>

  <!-- POST GRID -->
  <div class="blog-grid-section">
    <p class="blog-section-label">From the Journal</p>
    <div class="blog-grid" id="blogGrid">
      <?php foreach ($grid as $slug => $a): ?>
      <a href="<?= e($slug) ?>.php" class="blog-card" data-cat="<?= e($a['category']) ?>">
        <div class="blog-card-img">
          <img src="<?= e($a['image']) ?>" alt="<?= e($a['title']) ?>" loading="lazy">
        </div>
        <div class="blog-card-body">
          <span class="blog-card-pill"><?= e($a['category']) ?></span>
          <h3><?= e($a['title']) ?></h3>
          <p class="blog-card-excerpt"><?= e($a['excerpt']) ?></p>
          <span class="blog-card-link">Read &rarr;</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
(function(){
  var filter = document.getElementById('blogFilter');
  var cards  = Array.prototype.slice.call(document.querySelectorAll('#blogGrid .blog-card'));
  if(!filter) return;
  filter.addEventListener('click', function(e){
    var btn = e.target.closest('.blog-filter-btn');
    if(!btn) return;
    var cat = btn.getAttribute('data-cat');
    filter.querySelectorAll('.blog-filter-btn').forEach(function(b){ b.classList.toggle('active', b===btn); });
    cards.forEach(function(c){
      c.classList.toggle('is-hidden', cat!=='all' && c.getAttribute('data-cat')!==cat);
    });
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
</body>
