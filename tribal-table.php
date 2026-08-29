<?php require_once 'includes/schema.php'; ?>
<?php
/* ═══ SEO ═══ */
$page_title = 'Tribal Table · Beachfront Dining Restaurant · Kilifi · Now Open · Tribal Sand';
$page_desc  = 'Beachfront dining restaurant in Kilifi, Kenya. Locally sourced coastal cuisine, craft cocktails and a sunset terrace at Tribal Dunes, Bofa Beach. Now open — book a table at tribaltablekenya.com.';
$page_url   = 'https://tribalsand.com/tribal-table.php';
$page_image = asset_url('images/maya-kobe/Aerial/mayakobe-2.webp');

/* ═══ SCHEMA ═══ */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',         'url' => 'https://tribalsand.com/'],
    ['name' => 'Tribal Dunes', 'url' => 'https://tribalsand.com/tribal-dunes.php'],
    ['name' => 'Tribal Table', 'url' => 'https://tribalsand.com/tribal-table.php'],
]);
?>
<?php include 'includes/head.php'; ?>
<style>
.cs-wrap{min-height:100vh;background:var(--teal-d,#102F3A);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem var(--px,5vw);position:relative;overflow:hidden;text-align:center;}
.cs-bg{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.7;}
.cs-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,rgba(16,47,58,.82) 0%,rgba(16,47,58,.68) 50%,rgba(16,47,58,.85) 100%);}
.cs-content{position:relative;z-index:1;max-width:640px;width:100%;}
.cs-badge{display:inline-flex;align-items:center;gap:.5rem;font-size:.5rem;letter-spacing:.38em;text-transform:uppercase;color:var(--sand,#B8965A);border:1px solid rgba(184,150,90,.5);padding:.45rem 1.1rem;margin-bottom:2rem;}
.cs-badge::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--sand,#B8965A);animation:pulse 2s ease-in-out infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.cs-eyebrow{font-size:.55rem;letter-spacing:.38em;text-transform:uppercase;color:rgba(184,150,90,.9);margin-bottom:.75rem;display:flex;align-items:center;justify-content:center;gap:.65rem;}
.cs-eyebrow::before,.cs-eyebrow::after{content:'';width:20px;height:1px;background:rgba(184,150,90,.6);}
.cs-h{font-family:'Cormorant Garamond',serif;font-size:clamp(3rem,7vw,5rem);font-weight:300;color:#fff;line-height:.9;margin-bottom:1rem;}
.cs-h em{font-style:italic;color:var(--sand-lt,#D4B07A);}
.cs-tagline{font-size:1.05rem;color:rgba(212,196,172,.92);line-height:1.8;margin-bottom:.5rem;}
.cs-location{font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:rgba(184,150,90,.9);margin-bottom:2.5rem;}
.cs-rule{width:40px;height:1px;background:rgba(184,150,90,.5);margin:0 auto 2.5rem;}
.cs-pillars{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin-bottom:2.5rem;}
.cs-pill{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;padding:.45rem 1rem;border:1px solid rgba(184,150,90,.45);color:rgba(212,196,172,.88);}
.cs-cta{display:inline-block;padding:.95rem 2.2rem;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);text-decoration:none;font-size:.62rem;letter-spacing:.2em;text-transform:uppercase;font-weight:500;transition:background .2s;}
.cs-cta:hover{background:var(--sand-lt,#D4B07A);}
@media(max-width:480px){.cs-cta{display:block;}}
.cs-cta-note{font-size:.62rem;letter-spacing:.04em;color:rgba(212,196,172,.7);margin:1rem auto 2.5rem;max-width:420px;}
.cs-back{font-size:.58rem;letter-spacing:.15em;text-transform:uppercase;color:rgba(184,150,90,.65);text-decoration:none;transition:color .2s;}
.cs-back:hover{color:rgba(184,150,90,1);}
</style>
<body class="ts-nav-transparent">
<?php include 'includes/header.php'; ?>

<section class="cs-wrap">
  <div class="cs-bg" style="background-image:url('images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg');"></div>

  <div class="cs-content">

    <div class="cs-badge">Now Open</div>

    <p class="cs-eyebrow">Tribal Dunes · Kilifi · Kenya</p>

    <h1 class="cs-h">Tribal <em>Table</em> · Beachfront Dining Restaurant</h1>

    <p class="cs-tagline">
      An elevated beachside restaurant and cocktail bar.<br>
      Open to the public. Open to everything the coast has to offer.
    </p>

    <p class="cs-location">Bofa Beach · Kilifi · Kenya's North Coast</p>

    <div class="cs-rule"></div>

    <div class="cs-pillars">
      <span class="cs-pill">Coastal Fine Dining</span>
      <span class="cs-pill">Craft Cocktails</span>
      <span class="cs-pill">Seafood &amp; Grill</span>
      <span class="cs-pill">Sunset Terrace</span>
      <span class="cs-pill">Open to Public</span>
      <span class="cs-pill">Private Events</span>
    </div>

    <a class="cs-cta" href="https://www.tribaltablekenya.com" target="_blank" rel="noopener">Visit Tribal Table &rarr;</a>

    <p class="cs-cta-note">Menus, opening hours and table bookings live on the restaurant's own site, tribaltablekenya.com.</p>

    <a class="cs-back" href="tribal-dunes.php">&larr; Back to Tribal Dunes</a>

  </div>
</section>

<?php include 'includes/footer.php'; ?>

</body>
