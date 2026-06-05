<?php require_once 'includes/schema.php'; ?>
<?php
/* ═══ SEO ═══ */
$page_title = 'Somewhere Café · Beachfront Café & Co-working · Kilifi · Coming Soon';
$page_desc  = 'Somewhere Café is a beachfront café at Tribal Dunes, Kilifi — healthy food, wood-fired pizza, great coffee, live music, pool access and WiFi. Coming soon.';
$page_url   = 'https://tribalsand.com/somewhere-cafe.php';

/* ═══ SCHEMA ═══ */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',            'url' => 'https://tribalsand.com/'],
    ['name' => 'Tribal Dunes',    'url' => 'https://tribalsand.com/tribal-dunes.php'],
    ['name' => 'Somewhere Café',  'url' => 'https://tribalsand.com/somewhere-cafe.php'],
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
.cs-form-label{font-size:.58rem;letter-spacing:.25em;text-transform:uppercase;color:rgba(184,150,90,.9);margin-bottom:1rem;display:block;}
.cs-form{display:flex;gap:.5rem;max-width:440px;margin:0 auto 1rem;}
.cs-inp{flex:1;padding:.9rem 1.1rem;border:1px solid rgba(184,150,90,.4);background:rgba(255,255,255,.08);color:#fff;font-size:.9rem;font-family:'Jost',sans-serif;outline:none;transition:border-color .2s;}
.cs-inp::placeholder{color:rgba(255,255,255,.4);}
.cs-inp:focus{border-color:rgba(184,150,90,.8);}
.cs-btn{padding:.9rem 1.6rem;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);border:none;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;cursor:pointer;font-family:'Jost',sans-serif;font-weight:500;white-space:nowrap;transition:background .2s;flex-shrink:0;}
.cs-btn:hover{background:var(--sand-lt,#D4B07A);}
.cs-note{font-size:.6rem;color:rgba(184,150,90,.6);margin-bottom:2.5rem;}
.cs-success{display:none;font-size:.8rem;color:rgba(212,196,172,.9);border:1px solid rgba(184,150,90,.3);padding:1rem 1.5rem;margin-bottom:1rem;}
.cs-back{font-size:.58rem;letter-spacing:.15em;text-transform:uppercase;color:rgba(184,150,90,.65);text-decoration:none;transition:color .2s;}
.cs-back:hover{color:rgba(184,150,90,1);}
@media(max-width:480px){.cs-form{flex-direction:column;}.cs-btn{width:100%;}}
</style>
<body class="ts-nav-transparent">
<?php include 'includes/header.php'; ?>

<section class="cs-wrap">
  <div class="cs-bg" style="background-image:url('images/maya_illai/SITE PHOTOS BAR AND POOL-images-1.jpg');"></div>

  <div class="cs-content">

    <div class="cs-badge">Coming Soon</div>

    <p class="cs-eyebrow">Tribal Dunes · Kilifi · Kenya</p>

    <h1 class="cs-h">Somewhere <em>Café</em></h1>

    <p class="cs-tagline">
      A beachfront café where you can work, eat, swim<br>
      and stay longer than you planned.
    </p>

    <p class="cs-location">Bofa Beach · Kilifi · Kenya's North Coast</p>

    <div class="cs-rule"></div>

    <div class="cs-pillars">
      <span class="cs-pill">Healthy Food</span>
      <span class="cs-pill">Wood-Fired Pizza</span>
      <span class="cs-pill">Specialty Coffee</span>
      <span class="cs-pill">Live Music</span>
      <span class="cs-pill">Fast WiFi</span>
      <span class="cs-pill">Pool Access</span>
      <span class="cs-pill">Smoothies &amp; Juices</span>
      <span class="cs-pill">Ocean Views</span>
    </div>

    <label class="cs-form-label" for="csEmail">Join the list — we'll let you know when we open.</label>

    <form class="cs-form" id="csForm" novalidate>
      <input class="cs-inp" type="email" id="csEmail" name="email" placeholder="your@email.com" required>
      <button class="cs-btn" type="submit" id="csBtn">Join Waitlist</button>
    </form>

    <p class="cs-success" id="csSuccess">&#10022; &nbsp; You're on the list. See you at the beach soon.</p>

    <p class="cs-note">No spam. Just a heads-up when doors open.</p>

    <a class="cs-back" href="tribal-dunes.php">&larr; Back to Tribal Dunes</a>

  </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
document.getElementById('csForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var email = document.getElementById('csEmail').value.trim();
  if (!email) return;
  var btn = document.getElementById('csBtn');
  btn.textContent = '…';
  btn.disabled = true;
  fetch('https://services.leadconnectorhq.com/hooks/cBTrngnK5Q4lTkFUwhlo/webhook-trigger/ad7f1a2d-9c2a-4f9a-9049-c30b144643e5', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      email: email,
      source: 'somewhere-cafe-waitlist',
      tags: ['somewhere-cafe-waitlist', 'coming-soon'],
      note: 'Somewhere Café waitlist signup from tribalsand.com/somewhere-cafe.php'
    })
  })
  .then(function() {
    document.getElementById('csForm').style.display = 'none';
    document.getElementById('csSuccess').style.display = 'block';
  })
  .catch(function() {
    btn.textContent = 'Join Waitlist';
    btn.disabled = false;
  });
});
</script>
</body>
