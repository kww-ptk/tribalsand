<?php require_once 'includes/schema.php'; ?>
<?php
$page_title  = 'For Travel Agents · Tribal Sand Kenya · Commission & FAM';
$page_desc   = 'Partner with Tribal Sand. Competitive commission for travel agents, complimentary FAM trips, dedicated support and quick response on all bookings.';
$page_url    = 'https://tribalsand.com/for-agents.php';
$page_image  = 'https://tribalsand.com/images/New-hero-banner.jpg';
$page_preload = 'images/New-hero-banner.jpg';

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',       'url' => 'https://tribalsand.com/'],
    ['name' => 'For Agents', 'url' => 'https://tribalsand.com/for-agents.php'],
]);

require_once 'includes/head.php';
?>
<style>
/* ── FOR AGENTS PAGE ── */

/* HERO */
.ag-hero{
  position:relative;height:60vh;min-height:480px;
  background:#0a1c24 url('images/New-hero-banner.jpg') center center / cover no-repeat;
  display:flex;align-items:center;
  padding:0 6vw;overflow:hidden;
}
.ag-hero::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(to right,rgba(10,28,36,.82) 0%,rgba(10,28,36,.45) 60%,rgba(10,28,36,.2) 100%);
}
.ag-hero-content{position:relative;z-index:2;max-width:620px;}
.ag-eyebrow{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.32em;text-transform:uppercase;
  color:rgba(184,150,90,.85);margin-bottom:1.2rem;
}
.ag-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.4rem,5vw,4rem);font-weight:300;
  color:#fff;line-height:1.06;margin-bottom:1.3rem;
}
.ag-hero h1 em{font-style:italic;color:var(--sand);}
.ag-hero-sub{
  font-family:'Jost',sans-serif;font-size:.88rem;
  color:rgba(255,255,255,.78);line-height:1.75;
}

/* INTRO DARK */
.ag-intro{background:var(--teal-d);padding:5.5rem 6vw;}
.ag-intro-inner{max-width:780px;margin:0 auto;text-align:center;}
.ag-intro-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.75);margin-bottom:1rem;
}
.ag-intro h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.6rem);font-weight:400;
  color:#fff;margin-bottom:1.4rem;
}
.ag-intro p{
  font-family:'Jost',sans-serif;font-size:.92rem;
  color:rgba(255,255,255,.85);line-height:1.9;
}

/* BENEFITS GRID */
.ag-benefits{background:var(--off);padding:6rem 6vw;}
.ag-benefits-inner{max-width:1100px;margin:0 auto;}
.ag-benefits-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;
}
.ag-benefits h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);margin-bottom:3rem;
}
.ag-benefits-grid{
  display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;
}
.ag-benefit-card{
  background:#fff;border:1px solid var(--border);
  padding:2rem 1.6rem;
  transition:box-shadow .22s,border-color .22s;
}
.ag-benefit-card:hover{
  box-shadow:0 8px 28px rgba(20,20,18,.08);
  border-color:rgba(184,150,90,.3);
}
.ag-benefit-icon{
  font-size:1.5rem;color:var(--sand);margin-bottom:1rem;
}
.ag-benefit-title{
  font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.6rem;
}
.ag-benefit-desc{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:var(--mid);line-height:1.78;
}

/* SIGN-UP FORM */
.ag-form-section{background:var(--teal-d);padding:6rem 6vw;}
.ag-form-inner{max-width:620px;margin:0 auto;}
.ag-form-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:rgba(184,150,90,.75);margin-bottom:.8rem;
}
.ag-form-section h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.6rem);font-weight:400;
  color:#fff;margin-bottom:.8rem;
}
.ag-form-section > .ag-form-inner > p{
  font-family:'Jost',sans-serif;font-size:.86rem;
  color:rgba(255,255,255,.85);margin-bottom:2.5rem;
}
.ag-form{display:flex;flex-direction:column;gap:1.1rem;}
.ag-field{display:flex;flex-direction:column;gap:.4rem;}
.ag-label{
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.14em;text-transform:uppercase;
  color:rgba(255,255,255,.82);
}
.ag-input{
  font-family:'Jost',sans-serif;font-size:.88rem;
  padding:.85rem 1rem;
  background:rgba(255,255,255,.06);
  border:1px solid rgba(184,150,90,.2);
  color:#fff;outline:none;
  transition:border-color .2s,background .2s;
}
.ag-input::placeholder{color:rgba(255,255,255,.3);}
.ag-input:focus{border-color:rgba(184,150,90,.5);background:rgba(255,255,255,.09);}
.ag-honeypot{position:absolute;overflow:hidden;width:1px;height:1px;opacity:0;pointer-events:none;}
.ag-submit{
  font-family:'Jost',sans-serif;font-size:.72rem;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2rem;background:var(--sand);
  color:var(--teal-d);border:1px solid var(--sand);
  font-weight:500;cursor:pointer;align-self:flex-start;
  transition:background .22s,border-color .22s;
}
.ag-submit:hover:not(:disabled){background:#D4B07A;border-color:#D4B07A;}
.ag-submit:disabled{opacity:.6;cursor:default;}
.ag-success{
  display:none;font-family:'Jost',sans-serif;font-size:.9rem;
  color:#4CAF82;line-height:1.75;
  border:1px solid rgba(76,175,130,.25);
  background:rgba(76,175,130,.08);
  padding:1.5rem;margin-top:.5rem;
}

/* PROPERTIES GRID */
.ag-properties{background:#fff;padding:6rem 6vw;}
.ag-properties-inner{max-width:1100px;margin:0 auto;}
.ag-properties-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;
}
.ag-properties h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);margin-bottom:3rem;
}
.ag-prop-grid{
  display:grid;grid-template-columns:repeat(5,1fr);gap:1.2rem;
}
.ag-prop-card{
  border:1px solid var(--border);padding:1.4rem 1.2rem;
  text-align:center;
  transition:border-color .22s,box-shadow .22s;
}
.ag-prop-card:hover{border-color:rgba(184,150,90,.35);box-shadow:0 4px 18px rgba(20,20,18,.07);}
.ag-prop-name{
  font-family:'Cormorant Garamond',serif;font-size:1.05rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.3rem;
}
.ag-prop-loc{
  font-family:'Jost',sans-serif;font-size:.64rem;
  letter-spacing:.1em;color:var(--sand);margin-bottom:.6rem;
}
.ag-prop-spec{
  font-family:'Jost',sans-serif;font-size:.76rem;
  color:var(--mid);line-height:1.6;margin-bottom:1rem;
}
.ag-prop-link{
  font-family:'Jost',sans-serif;font-size:.62rem;
  letter-spacing:.14em;text-transform:uppercase;
  color:var(--teal);transition:color .2s;
}
.ag-prop-link:hover{color:var(--teal-d);}

/* RESPONSIVE */
@media(max-width:960px){
  .ag-benefits-grid{grid-template-columns:1fr 1fr;}
  .ag-prop-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:640px){
  .ag-benefits-grid{grid-template-columns:1fr;}
  .ag-prop-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:420px){
  .ag-prop-grid{grid-template-columns:1fr;}
}
</style>

<body class="ts-nav-transparent">
<?php include 'includes/header.php'; ?>

<!-- HERO -->
<section class="ag-hero">
  <div class="ag-hero-content">
    <p class="ag-eyebrow">For Travel Agents</p>
    <h1>Partner with <em>Tribal Sand</em></h1>
    <p class="ag-hero-sub">Earn competitive commission on every Tribal Sand booking.</p>
  </div>
</section>

<!-- INTRO -->
<section class="ag-intro">
  <div class="ag-intro-inner">
    <p class="ag-intro-eyebrow">Something Exciting is Coming</p>
    <h2>Join the Tribal Sand Agent Programme</h2>
    <p>Are you passionate about unique travel experiences and looking to be part of something extraordinary? At Tribal Sand, we're building an exclusive agent programme designed just for you. Stay tuned as we put together an opportunity that will let you become part of the Tribal Sand journey.</p>
  </div>
</section>

<!-- BENEFITS GRID -->
<section class="ag-benefits">
  <div class="ag-benefits-inner">
    <p class="ag-benefits-eyebrow">What You Get</p>
    <h2>Agent Benefits</h2>
    <div class="ag-benefits-grid">

      <div class="ag-benefit-card">
        <div class="ag-benefit-icon"><i class="fas fa-percent"></i></div>
        <div class="ag-benefit-title">Competitive Commission</div>
        <div class="ag-benefit-desc">Earn generous commission on every confirmed booking across all Tribal Sand properties.</div>
      </div>

      <div class="ag-benefit-card">
        <div class="ag-benefit-icon"><i class="fas fa-headset"></i></div>
        <div class="ag-benefit-title">Dedicated Agent Support</div>
        <div class="ag-benefit-desc">Your own point of contact for availability, pricing and bespoke guest requests.</div>
      </div>

      <div class="ag-benefit-card">
        <div class="ag-benefit-icon"><i class="fas fa-plane"></i></div>
        <div class="ag-benefit-title">FAM Trips</div>
        <div class="ag-benefit-desc">Complimentary familiarisation trips so you can experience our properties first-hand.</div>
      </div>

      <div class="ag-benefit-card">
        <div class="ag-benefit-icon"><i class="fas fa-clock"></i></div>
        <div class="ag-benefit-title">24-Hour Response</div>
        <div class="ag-benefit-desc">We reply to all agent enquiries within 24 hours, including bespoke itinerary pricing.</div>
      </div>

      <div class="ag-benefit-card">
        <div class="ag-benefit-icon"><i class="fas fa-star"></i></div>
        <div class="ag-benefit-title">Co-Marketing</div>
        <div class="ag-benefit-desc">Featured placement on tribalsand.com for qualifying partners.</div>
      </div>

      <div class="ag-benefit-card">
        <div class="ag-benefit-icon"><i class="fas fa-book-open"></i></div>
        <div class="ag-benefit-title">Training Resources</div>
        <div class="ag-benefit-desc">Property fact sheets, photography and rates — everything you need to sell with confidence.</div>
      </div>

    </div>
  </div>
</section>

<!-- SIGN-UP FORM -->
<section class="ag-form-section">
  <div class="ag-form-inner">
    <p class="ag-form-eyebrow">Stay Informed</p>
    <h2>Be the First to Know</h2>
    <p>Register your interest and we'll notify you the moment our agent programme launches.</p>

    <form class="ag-form" id="agent-signup-form" novalidate>
      <!-- Honeypot -->
      <div class="ag-honeypot" aria-hidden="true">
        <label for="name-email">Leave this field empty</label>
        <input type="text" id="name-email" name="name-email" tabindex="-1" autocomplete="off">
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-name">Your Name</label>
        <input class="ag-input" type="text" id="agent-name" name="agent_name" placeholder="Jane Smith" required>
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-email">Email Address</label>
        <input class="ag-input" type="email" id="agent-email" name="agent_email" placeholder="jane@travelagency.com" required>
      </div>

      <?php if (captcha_site_key()): ?>
      <div class="h-captcha" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin-bottom:1rem"></div>
      <?php endif; ?>

      <button class="ag-submit" type="submit" id="submitBtn">Register Interest</button>
    </form>

    <div class="ag-success" id="successMsg" role="status">
      Thank you — we'll be in touch soon.
    </div>
  </div>
</section>

<!-- PROPERTIES -->
<section class="ag-properties">
  <div class="ag-properties-inner">
    <p class="ag-properties-eyebrow">The Portfolio</p>
    <h2>Properties You'll Sell</h2>
    <div class="ag-prop-grid">

      <div class="ag-prop-card">
        <div class="ag-prop-name">Zuri</div>
        <div class="ag-prop-loc">Watamu</div>
        <div class="ag-prop-spec">Boutique Hotel &middot; 6 Suites &middot; Marine Park</div>
        <a href="zuri.php" class="ag-prop-link">View Property &rarr;</a>
      </div>

      <div class="ag-prop-card">
        <div class="ag-prop-name">Maya Kobe</div>
        <div class="ag-prop-loc">Kilifi · Bofa Beach</div>
        <div class="ag-prop-spec">Boutique Hotel &middot; 5 Suites &middot; Solar Powered</div>
        <a href="maya-kobe.php" class="ag-prop-link">View Property &rarr;</a>
      </div>

      <div class="ag-prop-card">
        <div class="ag-prop-name">My Amani</div>
        <div class="ag-prop-loc">Vipingo</div>
        <div class="ag-prop-spec">Private Villa &middot; 5 Rooms &middot; Infinity Pool</div>
        <a href="my-amani.php" class="ag-prop-link">View Property &rarr;</a>
      </div>

      <div class="ag-prop-card">
        <div class="ag-prop-name">Enkare Bofa</div>
        <div class="ag-prop-loc">Kilifi</div>
        <div class="ag-prop-spec">Private Villa &middot; 5 Rooms &middot; Beachfront</div>
        <a href="enkare-bofa.php" class="ag-prop-link">View Property &rarr;</a>
      </div>

      <div class="ag-prop-card">
        <div class="ag-prop-name">Sandbox</div>
        <div class="ag-prop-loc">Kilifi</div>
        <div class="ag-prop-spec">Private Villa &middot; 4 Rooms &middot; Pool</div>
        <a href="sandbox.php" class="ag-prop-link">View Property &rarr;</a>
      </div>

    </div>
  </div>
</section>

<script>
document.getElementById('agent-signup-form').addEventListener('submit', function(e) {
  e.preventDefault();
  var form = this;
  var btn = document.getElementById('submitBtn');
  var honeypot = document.getElementById('name-email');
  if (honeypot && honeypot.value) return; // spam
  btn.textContent = 'Sending\u2026';
  btn.disabled = true;
  var fd = new FormData(form);
  var nm = (fd.get('agent_name') || '').toString().trim();
  var payload = {
    name:    nm,
    email:   (fd.get('agent_email') || '').toString().trim(),
    phone:   '',
    agency:  nm,
    country: '',
    message: 'Travel agent registration enquiry',
    website: (fd.get('name-email') || '').toString(),
    'h-captcha-response': (form.querySelector("[name='h-captcha-response']")||{}).value || ''
  };
  fetch('/api/submit-agency.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data.ok) {
      form.style.display = 'none';
      document.getElementById('successMsg').style.display = 'block';
    } else {
      btn.textContent = 'Submit';
      btn.disabled = false;
    }
  })
  .catch(function(){
    btn.textContent = 'Submit';
    btn.disabled = false;
  });
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
