<?php
// Set 404 status
http_response_code(404);
require_once 'includes/schema.php';
$page_title = 'Page Not Found · Tribal Sand Kenya';
$page_desc  = 'The page you were looking for could not be found. Explore Tribal Sand properties and experiences on Kenya\'s North Coast.';
$page_url   = 'https://tribalsand.com/404';
$page_schema = ts_schema_org();
require_once 'includes/head.php';
?>
<body>
<?php include 'includes/header.php'; ?>

<style>
.e404-wrap{min-height:85vh;background:var(--teal-d,#102F3A);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:calc(var(--nav-h,72px) + 3rem) var(--px,5vw) 4rem;text-align:center;position:relative;overflow:hidden;}
.e404-bg{position:absolute;inset:0;background:radial-gradient(ellipse at 60% 40%,rgba(30,92,107,.4) 0%,transparent 70%);}
.e404-num{font-family:'Cormorant Garamond',serif;font-size:clamp(6rem,20vw,12rem);font-weight:300;line-height:1;color:rgba(184,150,90,.15);position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);pointer-events:none;user-select:none;letter-spacing:-.02em;}
.e404-content{position:relative;z-index:1;max-width:560px;}
.e404-eyebrow{font-size:.52rem;letter-spacing:.38em;text-transform:uppercase;color:rgba(184,150,90,.55);margin-bottom:1rem;display:flex;align-items:center;justify-content:center;gap:.65rem;}
.e404-eyebrow::before,.e404-eyebrow::after{content:'';width:20px;height:1px;background:rgba(184,150,90,.3);}
.e404-h{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,5vw,3.2rem);font-weight:300;color:#fff;line-height:1.1;margin-bottom:.75rem;}
.e404-h em{font-style:italic;color:var(--sand-lt,#D4B07A);}
.e404-p{font-size:.85rem;color:rgba(212,196,172,.45);line-height:1.85;margin-bottom:2.5rem;max-width:400px;margin-left:auto;margin-right:auto;}
.e404-cta{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:3rem;}
.e404-btn-primary{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;padding:.85rem 2rem;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);text-decoration:none;transition:background .2s;display:inline-block;}
.e404-btn-primary:hover{background:var(--sand-lt,#D4B07A);}
.e404-btn-ghost{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;padding:.85rem 2rem;border:1px solid rgba(184,150,90,.3);color:rgba(212,196,172,.6);text-decoration:none;transition:all .2s;display:inline-block;}
.e404-btn-ghost:hover{border-color:rgba(184,150,90,.6);color:#fff;}
.e404-divider{width:40px;height:1px;background:rgba(184,150,90,.2);margin:0 auto 2rem;}
.e404-props-label{font-size:.5rem;letter-spacing:.3em;text-transform:uppercase;color:rgba(184,150,90,.3);margin-bottom:1.2rem;}
.e404-props{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;}
.e404-prop{font-size:.65rem;color:rgba(212,196,172,.4);text-decoration:none;padding:.4rem .85rem;border:1px solid rgba(184,150,90,.12);transition:all .2s;}
.e404-prop:hover{color:rgba(212,196,172,.8);border-color:rgba(184,150,90,.35);}
</style>

<div class="e404-wrap">
  <div class="e404-bg"></div>
  <div class="e404-num" aria-hidden="true">404</div>
  <div class="e404-content">
    <div class="e404-eyebrow">Page Not Found</div>
    <h1 class="e404-h">Lost somewhere<br>on the <em>coast.</em></h1>
    <p class="e404-p">The page you were looking for has drifted away. Let us help you find your footing — or just start planning your stay.</p>

    <div class="e404-cta">
      <a href="/" class="e404-btn-primary">Go Home</a>
      <a href="trip-builder.php" class="e404-btn-ghost">Plan Your Trip</a>
    </div>

    <div class="e404-divider"></div>

    <div class="e404-props-label">Explore our properties</div>
    <div class="e404-props">
      <a href="zuri.php" class="e404-prop">Zuri · Watamu</a>
      <a href="maya-kobe.php" class="e404-prop">Maya Kobe · Kilifi</a>
      <a href="my-amani.php" class="e404-prop">My Amani · Vipingo</a>
      <a href="enkare-bofa.php" class="e404-prop">Enkare Bofa · Kilifi</a>
      <a href="sandbox.php" class="e404-prop">Sandbox · Kilifi</a>
      <a href="maya_ilai.php" class="e404-prop">Maya Ilai · Kilifi</a>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
</body>
