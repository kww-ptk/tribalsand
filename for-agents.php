<?php require_once 'includes/schema.php'; ?>
<?php require_once 'includes/partners.php'; ?>
<?php $__partners = fetch_published_partners(); ?>
<?php
$page_title  = 'For Travel Agents · Tribal Sand Kenya · Commission & FAM';
$page_desc   = 'Partner with Tribal Sand. Competitive commission for travel agents, complimentary FAM trips, dedicated support and quick response on all bookings.';
$page_url    = 'https://tribalsand.com/for-agents.php';
$page_image  = asset_url('images/New-hero-banner.jpg');
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
.ag-hero-cta{
  display:inline-block;margin-top:1.8rem;
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.2em;text-transform:uppercase;font-weight:500;
  padding:.9rem 2.2rem;background:var(--sand);color:var(--teal-d);
  text-decoration:none;border:1px solid var(--sand);
  transition:background .22s,border-color .22s;
}
.ag-hero-cta:hover{background:#D4B07A;border-color:#D4B07A;}

/* PARTNER TICKER */
.ag-partners{background:var(--cream, #F5EFE3);padding:4.5rem 0 5rem;overflow:hidden;}
.ag-partners-inner{max-width:1100px;margin:0 auto;padding:0 6vw;text-align:center;}
.ag-partners-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:.8rem;
}
.ag-partners h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.6rem,3vw,2.4rem);font-weight:400;
  color:var(--teal-d);margin-bottom:2.6rem;
}
.ag-ticker{
  position:relative;width:100%;overflow:hidden;
  -webkit-mask-image:linear-gradient(to right,transparent,#000 8%,#000 92%,transparent);
          mask-image:linear-gradient(to right,transparent,#000 8%,#000 92%,transparent);
}
.ag-ticker-track{display:flex;width:max-content;animation:ag-scroll 40s linear infinite;}
.ag-ticker:hover .ag-ticker-track{animation-play-state:paused;}
.ag-ticker-item{
  flex:0 0 auto;display:flex;align-items:center;justify-content:center;
  height:80px;padding:0 2.6rem;
}
.ag-ticker-item img{
  max-height:64px;max-width:170px;object-fit:contain;
  filter:grayscale(1);opacity:.7;transition:filter .25s,opacity .25s;
}
.ag-ticker-item a:hover img{filter:grayscale(0);opacity:1;}
@keyframes ag-scroll{from{transform:translateX(0);}to{transform:translateX(-50%);}}
@media(prefers-reduced-motion:reduce){.ag-ticker-track{animation:none;flex-wrap:wrap;justify-content:center;width:100%;}}

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
.ag-file{padding:.7rem 1rem;color:rgba(255,255,255,.7);cursor:pointer;}
.ag-file::file-selector-button{
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.14em;text-transform:uppercase;
  margin-right:.9rem;padding:.5rem 1rem;cursor:pointer;
  background:rgba(184,150,90,.18);color:var(--sand);
  border:1px solid rgba(184,150,90,.35);
}
.ag-hint{
  font-family:'Jost',sans-serif;font-size:.74rem;
  color:rgba(255,255,255,.45);line-height:1.6;margin:.1rem 0 0;
}
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
    <h1>Become Our <em>Partner</em></h1>
    <p class="ag-hero-sub">Register your agency and earn competitive commission on every Tribal Sand booking.</p>
    <a href="#register" class="ag-hero-cta">Become Our Partner</a>
  </div>
</section>

<!-- INTRO -->
<section class="ag-intro">
  <div class="ag-intro-inner">
    <p class="ag-intro-eyebrow">Become Our Partner</p>
    <h2>Join the Tribal Sand Agent Programme</h2>
    <p>Travel agencies can register to become official Tribal Sand partners — earning competitive commission across our collection of beachfront boutique hotels and private villas on Kenya's North Coast. Register below and our team will set you up with rates, availability and everything you need to start selling.</p>
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

<!-- BECOME OUR PARTNER FORM -->
<section class="ag-form-section" id="register">
  <div class="ag-form-inner">
    <p class="ag-form-eyebrow">Register Your Agency</p>
    <h2>Become Our Partner</h2>
    <p>Complete the form to register your travel agency. Share your website and logo and — once approved — we'll feature your agency in our partner section with a link back to your site.</p>

    <form class="ag-form" id="agent-signup-form" novalidate>
      <!-- Honeypot -->
      <div class="ag-honeypot" aria-hidden="true">
        <label for="name-email">Leave this field empty</label>
        <input type="text" id="name-email" name="name-email" tabindex="-1" autocomplete="off">
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-agency">Agency Name</label>
        <input class="ag-input" type="text" id="agent-agency" name="agency_name" placeholder="Safari Travel Co." required>
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-name">Contact Name</label>
        <input class="ag-input" type="text" id="agent-name" name="agent_name" placeholder="Jane Smith" required>
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-email">Email Address</label>
        <input class="ag-input" type="email" id="agent-email" name="agent_email" placeholder="jane@travelagency.com" required>
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-phone">Phone <span style="opacity:.6;text-transform:none;letter-spacing:0">(optional)</span></label>
        <input class="ag-input" type="tel" id="agent-phone" name="agent_phone" placeholder="+254 …">
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-website">Agency Website <span style="opacity:.6;text-transform:none;letter-spacing:0">(optional)</span></label>
        <input class="ag-input" type="text" id="agent-website" name="agency_website" placeholder="https://youragency.com">
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-logo">Agency Logo <span style="opacity:.6;text-transform:none;letter-spacing:0">(optional · PNG, JPG or WebP, max 2MB)</span></label>
        <input class="ag-input ag-file" type="file" id="agent-logo" name="agency_logo" accept="image/png,image/jpeg,image/webp">
        <p class="ag-hint">A transparent PNG works best. Once approved, your logo appears in our partner section linked to your website.</p>
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-country">Country <span style="opacity:.6;text-transform:none;letter-spacing:0">(optional)</span></label>
        <input class="ag-input" type="text" id="agent-country" name="agent_country" placeholder="Kenya">
      </div>

      <div class="ag-field">
        <label class="ag-label" for="agent-message">Anything else? <span style="opacity:.6;text-transform:none;letter-spacing:0">(optional)</span></label>
        <textarea class="ag-input" id="agent-message" name="agent_message" rows="3" placeholder="Tell us about your agency, IATA number, markets you serve…"></textarea>
      </div>

      <?php if (captcha_site_key()): ?>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin-bottom:1rem"></div>
      <?php endif; ?>

      <button class="ag-submit" type="submit" id="submitBtn">Register My Agency</button>
    </form>

    <div class="ag-success" id="successMsg" role="status">
      Thank you for registering — our team will review your details and be in touch to set up your partnership. Once you're approved, your logo appears in our partner section with a link back to your site.
    </div>
  </div>
</section>

<?php if ($__partners): ?>
<!-- PARTNER TICKER -->
<section class="ag-partners">
  <div class="ag-partners-inner">
    <p class="ag-partners-eyebrow">Trusted By</p>
    <h2>Our Travel Partners</h2>
  </div>
  <div class="ag-ticker" aria-label="Our travel partners">
    <div class="ag-ticker-track">
      <?php
        // The -50% keyframe scrolls exactly half the track, so the track has to
        // be the list repeated an EVEN number of times for the loop to be
        // seamless. Repeat enough to overflow a wide screen too — with two or
        // three partners a single pair leaves a gap and then visibly jumps.
        // Tiles are ~230px, so ten of them fill ~2300px.
        $__reps = max(2, (int) ceil(10 / max(1, count($__partners))));
        if ($__reps % 2 !== 0) $__reps++;
        $__n = count($__partners);
        for ($i = 0; $i < $__reps * $__n; $i++):
          $p    = $__partners[$i % $__n];
          $logo = partner_logo_url($p['logo_key']);
          if ($logo === '') continue;
          $href = partner_website_href($p['website_url']);
          // Only the first pass is real content — the repeats exist purely to
          // fill the marquee, so hide them from screen readers and the tab order.
          $dup  = $i >= $__n;
      ?>
      <div class="ag-ticker-item"<?= $dup ? ' aria-hidden="true"' : '' ?>>
        <?php if ($href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener"<?= $dup ? ' tabindex="-1"' : '' ?> title="<?= e($p['name']) ?>"><img src="<?= e($logo) ?>" alt="<?= $dup ? '' : e($p['name']) ?>" loading="lazy"></a>
        <?php else: ?><img src="<?= e($logo) ?>" alt="<?= $dup ? '' : e($p['name']) ?>" loading="lazy"><?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</section>
<?php endif; ?>

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
  var raw = new FormData(form);
  var nm  = (raw.get('agent_name')  || '').toString().trim();
  var agy = (raw.get('agency_name') || '').toString().trim();
  var str = function (k) { return (raw.get(k) || '').toString().trim(); };

  // Posted as multipart (not JSON) because the agency may attach a logo file.
  // api/submit-agency.php reads $_POST first, falling back to a JSON body.
  var body = new FormData();
  body.append('name',    nm);
  body.append('email',   str('agent_email'));
  body.append('phone',   str('agent_phone'));
  body.append('agency',  agy || nm);
  body.append('country', str('agent_country'));
  body.append('agency_website', str('agency_website'));
  body.append('message', str('agent_message') || 'Become our partner \u2014 agency registration');
  body.append('website', (raw.get('name-email') || '').toString());   // honeypot
  body.append('cf-turnstile-response', (form.querySelector("[name='cf-turnstile-response']")||{}).value || '');

  var logo = document.getElementById('agent-logo');
  if (logo && logo.files && logo.files[0]) body.append('agency_logo', logo.files[0]);

  // No Content-Type header — the browser sets the multipart boundary itself.
  fetch('/api/submit-agency.php', { method: 'POST', body: body })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data.ok) {
      form.style.display = 'none';
      document.getElementById('successMsg').style.display = 'block';
    } else {
      btn.textContent = 'Register My Agency';
      btn.disabled = false;
    }
  })
  .catch(function(){
    btn.textContent = 'Register My Agency';
    btn.disabled = false;
  });
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
