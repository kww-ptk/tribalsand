<?php require_once 'includes/schema.php'; ?>
<?php
$page_title = 'Off Duty · Beachfront Active Coworking Hotel · Kilifi · Coming Soon · Tribal Sand';
$page_desc  = 'Beachfront active coworking hotel in Kilifi, Kenya. Fast WiFi, private desks, kitesurf beach, pool and solar power at Tribal Dunes. Join the waitlist.';
$page_url   = 'https://tribalsand.com/off-duty.php';
$page_image = asset_url('images/maya-kobe/Aerial/mayakobe-2.webp');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name'=>'Home','url'=>'https://tribalsand.com/'],
    ['name'=>'Tribal Dunes','url'=>'https://tribalsand.com/tribal-dunes.php'],
    ['name'=>'Off Duty','url'=>'https://tribalsand.com/off-duty.php'],
]);
require_once 'includes/head.php';
?>
<body class="ts-nav-transparent">
<?php include 'includes/header.php'; ?>

<style>
.cs-wrap{min-height:100vh;background:var(--teal-d,#102F3A);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem var(--px,5vw);position:relative;overflow:hidden;text-align:center;}
.cs-bg{position:absolute;inset:0;background-image:url('images/maya-kobe/Aerial/mayakobe-2.webp');background-size:cover;background-position:center;opacity:.7;}
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
.cs-pill{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;padding:.45rem 1rem;border:1px solid rgba(184,150,90,.45);color:rgba(212,196,172,.88);border-radius:0;}
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

<div class="cs-wrap">
  <div class="cs-bg"></div>
  <div class="cs-content">

    <div class="cs-badge">Coming Soon</div>

    <div class="cs-eyebrow">Tribal Dunes · Kilifi · Kenya</div>
    <h1 class="cs-h">Off <em>Duty</em> · Beachfront Active Coworking Hotel</h1>
    <p class="cs-tagline">A beachfront coworking hotel for people<br>who work differently — and live fully.</p>
    <p class="cs-location">Bofa Beach · Kilifi · Kenya's North Coast</p>

    <div class="cs-rule"></div>

    <div class="cs-pillars">
      <span class="cs-pill">Fast WiFi</span>
      <span class="cs-pill">Private Desks</span>
      <span class="cs-pill">Kitesurf Beach</span>
      <span class="cs-pill">Pool Access</span>
      <span class="cs-pill">Café & Bar</span>
      <span class="cs-pill">Solar Powered</span>
      <span class="cs-pill">Monthly Rates</span>
      <span class="cs-pill">Private Rooms</span>
    </div>

    <span class="cs-form-label">Join the waitlist — be first to know when we open.</span>

    <div class="cs-success" id="csSuccess">
      ✦ &nbsp; You're on the list. We'll be in touch soon.
    </div>

    <form class="cs-form" id="csForm" novalidate>
      <div style="position:absolute;opacity:0;pointer-events:none;"><input type="text" name="name-email" tabindex="-1" autocomplete="off"></div>
      <input type="email" class="cs-inp" id="csEmail" name="email" placeholder="your@email.com" required>
      <button type="submit" class="cs-btn" id="csBtn">Join Waitlist</button>
    </form>
    <p class="cs-note">No spam. Just the launch announcement when we're ready.</p>

    <a href="tribal-dunes.php" class="cs-back">← Back to Tribal Dunes</a>

  </div>
</div>

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
      source: 'off-duty-waitlist',
      tags: ['off-duty-waitlist', 'coming-soon'],
      note: 'Off Duty waitlist signup from tribalsand.com/off-duty.php'
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

<?php include 'includes/footer.php'; ?>
</body>
