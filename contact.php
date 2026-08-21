<?php require_once 'includes/schema.php'; ?>
<?php
/* ═══ SEO ═══ */
$page_title = 'Contact · Reservations · Tribal Sand Kenya';
$page_desc  = 'Contact Tribal Sand for reservations, enquiries and trip planning. Phone, WhatsApp, email or use our Trip Builder for a personalised Kenya coast quote.';
$page_url   = 'https://tribalsand.com/contact.php';
$page_image = asset_url('images/whitelogo11.png');

/* ═══ SCHEMA ═══ */
$page_schema  = ts_schema_org();
$page_schema .= ts_schema_local_business();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',    'url' => 'https://tribalsand.com/'],
    ['name' => 'Contact', 'url' => 'https://tribalsand.com/contact.php'],
]);
?>
<?php include 'includes/head.php'; ?>
<style>
/* ── DESIGN TOKENS ── */
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;--sand-faint:#FAF6EE;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.14);
  --ocean:#2b6b7a;--ocean-d:#1a4a56;
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;overflow-x:hidden;}
img{display:block;object-fit:cover;}
a{text-decoration:none;color:inherit;}
::-webkit-scrollbar{width:3px;}::-webkit-scrollbar-thumb{background:var(--border);}

/* ── PAGE HERO ── */
.ct-hero{
  background:var(--teal-d);
  height:300px;
  display:flex;align-items:flex-end;
  padding:0 6vw 2.8rem;
  position:relative;
  overflow:hidden;
}
.ct-hero::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,var(--ocean-d) 0%,var(--ocean) 100%);
  opacity:.9;
}
.ct-hero-pattern{
  position:absolute;inset:0;
  background-image:
    radial-gradient(circle at 20% 50%,rgba(184,150,90,.06) 0%,transparent 50%),
    radial-gradient(circle at 80% 20%,rgba(45,122,140,.1) 0%,transparent 50%);
}
.ct-hero-content{position:relative;z-index:2;max-width:700px;}
.ct-hero-eyebrow{
  font-size:.56rem;letter-spacing:.32em;text-transform:uppercase;
  color:rgba(232,220,200,.5);margin-bottom:.9rem;
}
.ct-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,3.5vw,3rem);
  font-weight:300;color:#fff;line-height:1.15;
}
.ct-hero h1 em{font-style:italic;color:var(--sand-lt);}

/* ── BREADCRUMB ── */
.ct-breadcrumb{
  padding:1rem 6vw;
  font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;
  color:var(--mid);border-bottom:1px solid var(--border);
  background:var(--white);
}
.ct-breadcrumb a{color:var(--mid);}
.ct-breadcrumb a:hover{color:var(--teal);}
.ct-breadcrumb-sep{margin:0 .6rem;opacity:.4;}

/* ── MAIN LAYOUT ── */
.ct-outer{
  max-width:1200px;margin:0 auto;
  padding:4rem 5vw 5rem;
  display:grid;
  grid-template-columns:1fr 1.15fr;
  gap:4rem;
  align-items:start;
}

/* ── LEFT COLUMN ── */
.ct-left-head{margin-bottom:2.4rem;}
.ct-left-label{
  font-size:.56rem;letter-spacing:.28em;text-transform:uppercase;
  color:var(--teal);font-weight:500;margin-bottom:.5rem;
}
.ct-left-h{
  font-family:'Cormorant Garamond',serif;
  font-size:1.8rem;font-weight:400;color:var(--dark);
  line-height:1.2;margin-bottom:.5rem;
}
.ct-left-p{font-size:.84rem;color:var(--mid);line-height:1.88;font-weight:300;}

/* ── CONTACT CARDS ── */
.ct-cards{display:flex;flex-direction:column;gap:.8rem;margin-bottom:2.8rem;}
.ct-card{
  display:flex;align-items:center;gap:1.1rem;
  padding:1.1rem 1.3rem;
  background:var(--white);
  border:1px solid var(--border);
  transition:border-color .2s,box-shadow .2s;
}
.ct-card:hover{border-color:rgba(43,107,122,.3);box-shadow:0 2px 16px rgba(43,107,122,.06);}
.ct-card-ico{
  width:42px;height:42px;flex-shrink:0;
  background:rgba(43,107,122,.08);
  display:flex;align-items:center;justify-content:center;
  font-size:1rem;color:var(--teal);
}
a.ct-card:hover .ct-card-ico{background:rgba(43,107,122,.14);}
.ct-card-body{flex:1;min-width:0;}
.ct-card-label{
  font-size:.52rem;letter-spacing:.2em;text-transform:uppercase;
  color:var(--teal);margin-bottom:.18rem;font-weight:500;
}
.ct-card-value{
  font-size:.88rem;color:var(--dark);font-weight:400;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.ct-card-note{font-size:.68rem;color:var(--mid);margin-top:.12rem;}
.ct-card-arrow{font-size:.8rem;color:var(--teal);opacity:.5;flex-shrink:0;}
a.ct-card{text-decoration:none;color:inherit;display:flex;}
a.ct-card:hover .ct-card-value{color:var(--teal);}
a.ct-card:hover .ct-card-arrow{opacity:1;}

/* ── HOW WE WORK ── */
.ct-how{margin-bottom:2.4rem;}
.ct-how-h{
  font-size:.54rem;letter-spacing:.26em;text-transform:uppercase;
  color:var(--teal);margin-bottom:1.2rem;font-weight:500;
}
.ct-steps{display:flex;flex-direction:column;gap:0;}
.ct-step{
  display:flex;gap:1rem;align-items:flex-start;
  padding:1rem 0;
  border-bottom:1px solid var(--border);
}
.ct-step:last-child{border-bottom:none;}
.ct-step-num{
  width:28px;height:28px;flex-shrink:0;
  background:var(--teal);color:#fff;
  font-size:.68rem;font-weight:500;
  display:flex;align-items:center;justify-content:center;
  border-radius:50%;
}
.ct-step-body{padding-top:.2rem;}
.ct-step-title{font-size:.82rem;font-weight:500;color:var(--dark);margin-bottom:.18rem;}
.ct-step-note{font-size:.74rem;color:var(--mid);line-height:1.7;}

/* ── TRIP BUILDER NUDGE ── */
.ct-builder-nudge{
  padding:1.2rem 1.4rem;
  background:rgba(43,107,122,.06);
  border:1px solid rgba(43,107,122,.18);
  border-left:3px solid var(--teal);
}
.ct-builder-nudge-p{
  font-size:.8rem;color:var(--mid);line-height:1.8;
}
.ct-builder-nudge-p a{color:var(--teal);font-weight:500;}
.ct-builder-nudge-p a:hover{text-decoration:underline;}

/* ── FORM COLUMN ── */
.ct-form-wrap{
  background:var(--white);
  border:1px solid var(--border);
  padding:2.4rem 2.2rem;
  position:sticky;top:100px;
}
.ct-form-head{margin-bottom:2rem;}
.ct-form-label-top{
  font-size:.54rem;letter-spacing:.26em;text-transform:uppercase;
  color:var(--teal);margin-bottom:.5rem;font-weight:500;
}
.ct-form-h{
  font-family:'Cormorant Garamond',serif;
  font-size:1.5rem;font-weight:400;color:var(--dark);
  margin-bottom:.4rem;
}
.ct-form-p{font-size:.78rem;color:var(--mid);line-height:1.7;}

.ct-form .ct-field{margin-bottom:1.2rem;}
.ct-form label{
  display:block;
  font-size:.56rem;letter-spacing:.2em;text-transform:uppercase;
  color:var(--mid);margin-bottom:.38rem;font-weight:500;
}
.ct-form input,
.ct-form select,
.ct-form textarea{
  width:100%;
  font-family:'Jost',sans-serif;
  font-size:.88rem;font-weight:400;
  color:var(--dark);
  background:var(--off);
  border:1px solid var(--border);
  padding:.7rem .9rem;
  outline:none;
  transition:border-color .2s,background .2s;
  appearance:none;-webkit-appearance:none;
}
.ct-form input:focus,
.ct-form select:focus,
.ct-form textarea:focus{
  border-color:rgba(43,107,122,.45);
  background:var(--white);
}
.ct-form select{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236B6050' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right .9rem center;
  padding-right:2.4rem;
  cursor:pointer;
}
.ct-form textarea{resize:vertical;min-height:110px;line-height:1.6;}
.ct-form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}

/* Honeypot */
.ct-hp{display:none !important;visibility:hidden;position:absolute;}

/* Submit */
.ct-submit{
  width:100%;
  font-family:'Jost',sans-serif;
  font-size:.58rem;letter-spacing:.24em;text-transform:uppercase;
  font-weight:500;
  padding:.9rem 2rem;
  background:var(--teal);color:#fff;
  border:none;cursor:pointer;
  transition:background .22s;
  margin-top:.4rem;
}
.ct-submit:hover{background:var(--ocean-d);}
.ct-form-footer{
  margin-top:.9rem;text-align:center;
  font-size:.66rem;color:var(--light);line-height:1.6;
}

/* ── INTERNAL LINKS ── */
.ct-props-strip{
  max-width:1200px;margin:0 auto;
  padding:0 5vw 4rem;
}
.ct-props-label{
  font-size:.54rem;letter-spacing:.26em;text-transform:uppercase;
  color:var(--teal);margin-bottom:1.2rem;font-weight:500;
}
.ct-props-row{
  display:flex;gap:1rem;flex-wrap:wrap;
}
.ct-prop-link{
  display:inline-flex;align-items:center;gap:.55rem;
  padding:.6rem 1.1rem;
  border:1px solid var(--border);
  font-size:.7rem;color:var(--mid);
  transition:all .2s;
}
.ct-prop-link:hover{border-color:rgba(43,107,122,.3);color:var(--teal);}

/* ── RESPONSIVE ── */
@media(max-width:960px){
  .ct-outer{grid-template-columns:1fr;gap:2.5rem;}
  .ct-form-wrap{position:static;}
}
@media(max-width:640px){
  .ct-hero{height:240px;padding:0 5vw 2.2rem;}
  .ct-outer{padding:3rem 5vw 4rem;}
  .ct-form-wrap{padding:1.8rem 1.4rem;}
  .ct-form-row{grid-template-columns:1fr;}
}
</style>
<body>

<?php include 'includes/header.php'; ?>

<!-- ═══════════════════════════════════════════════ HERO -->
<section class="ct-hero">
  <div class="ct-hero-pattern"></div>
  <div class="ct-hero-content">
    <div class="ct-hero-eyebrow">Reservations & Enquiries · Tribal Sand Kenya</div>
    <h1>Get in Touch · <em>We Respond Within 24 Hours</em></h1>
  </div>
</section>

<!-- ═══════════════════════════════════════════════ BREADCRUMB -->
<nav class="ct-breadcrumb" aria-label="Breadcrumb">
  <a href="index.php">Home</a>
  <span class="ct-breadcrumb-sep">›</span>
  <span>Contact</span>
</nav>

<!-- ═══════════════════════════════════════════════ MAIN CONTENT -->
<div class="ct-outer">

  <!-- LEFT COLUMN -->
  <div class="ct-left">

    <div class="ct-left-head">
      <div class="ct-left-label">Contact Tribal Sand</div>
      <h2 class="ct-left-h">We're here to help you plan the right trip.</h2>
      <p class="ct-left-p">Whether you're ready to book or just starting to explore, our team will put together a personalised quote covering transfers, activities and accommodations across any of our Kenya coast properties. No pressure at enquiry stage.</p>
    </div>

    <!-- CONTACT CARDS -->
    <div class="ct-cards">

      <a href="tel:+254115115247" class="ct-card">
        <div class="ct-card-ico"><i class="fa-solid fa-phone" aria-hidden="true"></i></div>
        <div class="ct-card-body">
          <div class="ct-card-label">Phone</div>
          <div class="ct-card-value">+254 115 115 247</div>
          <div class="ct-card-note">Call us directly · Mon–Sun 08:00–20:00</div>
        </div>
        <span class="ct-card-arrow">→</span>
      </a>

      <a href="https://wa.me/254115115247" target="_blank" rel="noopener" class="ct-card">
        <div class="ct-card-ico"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></div>
        <div class="ct-card-body">
          <div class="ct-card-label">WhatsApp</div>
          <div class="ct-card-value">+254 115 115 247</div>
          <div class="ct-card-note">Fastest response · message us anytime</div>
        </div>
        <span class="ct-card-arrow">→</span>
      </a>

      <a href="mailto:reservations@tribalsand.com" class="ct-card">
        <div class="ct-card-ico"><i class="fa-solid fa-envelope" aria-hidden="true"></i></div>
        <div class="ct-card-body">
          <div class="ct-card-label">Email</div>
          <div class="ct-card-value">reservations@tribalsand.com</div>
          <div class="ct-card-note">We reply within 24 hours · always</div>
        </div>
        <span class="ct-card-arrow">→</span>
      </a>

      <a href="/booking" class="ct-card">
        <div class="ct-card-ico"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></div>
        <div class="ct-card-body">
          <div class="ct-card-label">Book Online</div>
          <div class="ct-card-value">tribalsand.com/booking</div>
          <div class="ct-card-note">Check availability · confirm instantly</div>
        </div>
        <span class="ct-card-arrow">→</span>
      </a>

    </div><!-- /ct-cards -->

    <!-- HOW WE WORK -->
    <div class="ct-how">
      <div class="ct-how-h">How We Work</div>
      <div class="ct-steps">
        <div class="ct-step">
          <div class="ct-step-num">1</div>
          <div class="ct-step-body">
            <div class="ct-step-title">Send us your enquiry</div>
            <div class="ct-step-note">Use the form, WhatsApp, email or call — whichever works for you. Tell us which property interests you and your rough dates.</div>
          </div>
        </div>
        <div class="ct-step">
          <div class="ct-step-num">2</div>
          <div class="ct-step-body">
            <div class="ct-step-title">We respond within 24 hours with a personalised quote</div>
            <div class="ct-step-note">Rates, availability, transfer options and any activities you've asked about. Everything in one clear reply.</div>
          </div>
        </div>
        <div class="ct-step">
          <div class="ct-step-num">3</div>
          <div class="ct-step-body">
            <div class="ct-step-title">Confirm when you're ready — no pressure</div>
            <div class="ct-step-note">Nothing is required at enquiry stage. Confirm and pay your deposit only when you're happy to proceed.</div>
          </div>
        </div>
      </div>
    </div><!-- /ct-how -->

    <!-- TRIP BUILDER NUDGE -->
    <div class="ct-builder-nudge">
      <p class="ct-builder-nudge-p">For a detailed multi-property or multi-day trip plan, use our <a href="trip-builder.php">Trip Builder →</a> — tell us where you want to go and we'll build a personalised Kenya coast itinerary with pricing.</p>
    </div>

  </div><!-- /ct-left -->

  <!-- RIGHT COLUMN — FORM -->
  <div class="ct-right">
    <div class="ct-form-wrap">
      <div class="ct-form-head">
        <div class="ct-form-label-top">Send an Enquiry</div>
        <h2 class="ct-form-h">Tell us about your trip</h2>
        <p class="ct-form-p">Fill in what you know — we'll work out the rest and come back to you within 24 hours.</p>
      </div>

      <form class="ct-form" id="ctForm">

        <!-- Honeypot -->
        <div class="ct-hp" aria-hidden="true">
          <label for="website">Website</label>
          <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <input type="hidden" name="form_time" value="<?= time() ?>">
        <input type="hidden" name="form_source" value="contact.php">

        <div class="ct-form-row">
          <div class="ct-field">
            <label for="ct_name">Your Name <span style="color:var(--teal)">*</span></label>
            <input type="text" id="ct_name" name="name" placeholder="Jane Smith" required autocomplete="name">
          </div>
          <div class="ct-field">
            <label for="ct_email">Email Address <span style="color:var(--teal)">*</span></label>
            <input type="email" id="ct_email" name="email" placeholder="jane@example.com" required autocomplete="email">
          </div>
        </div>

        <div class="ct-form-row">
          <div class="ct-field">
            <label for="ct_phone">Phone / WhatsApp</label>
            <input type="tel" id="ct_phone" name="phone" placeholder="+44 7700 000000" autocomplete="tel">
          </div>
          <div class="ct-field">
            <label for="ct_property" id="ct_property-lbl">Property of Interest</label>
            <select id="ct_property" name="property" data-nice>
              <option value="">All Properties</option>
              <option value="Maya Kobe">Maya Kobe — Boutique Hotel, Kilifi</option>
              <option value="Maya Ilai">Maya Ilai — Eco Compound, Kilifi</option>
              <option value="Zuri">Zuri — Watamu</option>
              <option value="My Amani">My Amani — Kilifi</option>
              <option value="Enkare Bofa">Enkare Bofa — Kilifi</option>
              <option value="Sandbox">Sandbox — Vipingo</option>
            </select>
          </div>
        </div>

        <div class="ct-field">
          <label for="ct_message">Your Message <span style="color:var(--teal)">*</span></label>
          <textarea id="ct_message" name="message" placeholder="Tell us your approximate travel dates, number of guests and anything else that's useful — we'll take it from there." required></textarea>
        </div>

        <?php if (captcha_site_key()): ?>
        <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin-bottom:1rem"></div>
        <?php endif; ?>

        <button type="submit" class="ct-submit">Send Enquiry →</button>

        <p class="ct-form-footer">We respond within 24 hours · No payment required at enquiry stage · English, French, German, Italian, Swahili spoken</p>

      </form>
    </div>
  </div><!-- /ct-right -->

</div><!-- /ct-outer -->

<!-- ═══════════════════════════════════════════════ PROPERTY LINKS -->
<div class="ct-props-strip">
  <div class="ct-props-label">Our Properties</div>
  <div class="ct-props-row">
    <a href="zuri.php" class="ct-prop-link">Zuri · Watamu →</a>
    <a href="maya-kobe.php" class="ct-prop-link">Maya Kobe · Kilifi →</a>
    <a href="my-amani.php" class="ct-prop-link">My Amani · Kilifi →</a>
    <a href="enkare-bofa.php" class="ct-prop-link">Enkare Bofa · Kilifi →</a>
    <a href="sandbox.php" class="ct-prop-link">Sandbox · Vipingo →</a>
    <a href="maya_ilai.php" class="ct-prop-link">Maya Ilai · Kilifi →</a>
    <a href="tribal-dunes.php" class="ct-prop-link">Tribal Dunes · Community →</a>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
<script>
// Contact form GHL submit
(function(){
  var form = document.getElementById('ctForm');
  if (!form) return;
  form.addEventListener('submit', function(e){
    e.preventDefault();
    if (document.getElementById('website').value) return; // honeypot
    var name     = (document.getElementById('ct_name').value    || '').trim();
    var email    = (document.getElementById('ct_email').value   || '').trim();
    var phone    = (document.getElementById('ct_phone').value   || '').trim();
    var property = (document.getElementById('ct_property').value|| '').trim();
    var message  = (document.getElementById('ct_message').value || '').trim();
    if (!name || !email || !message) return;
    var btn = form.querySelector('[type=submit]');
    btn.textContent = 'Sending…'; btn.disabled = true;
    var parts = name.split(' ');
    var payload = {
      guest: { firstName: parts[0]||name, lastName: parts.slice(1).join(' ')||'', email: email, phone: phone },
      customData: { property: property, enquiry_message: message },
      tags: ['website-enquiry','contact-page'],
      opportunity: { name: name+(property?' · '+property:''), source: 'Website - Contact Page' },
      note: 'Contact Form Enquiry\nProperty: '+(property||'—')+'\nPhone: '+(phone||'—')+'\n\nMessage:\n'+message,
      ref: 'WEB-'+Date.now(),
      website: (document.getElementById('website')||{}).value || '',
      'cf-turnstile-response': (form.querySelector("[name='cf-turnstile-response']")||{}).value || ''
    };
    fetch('/ghl-submit',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
    .then(function(r){ return r.json(); })
    .then(function(r){
      if(r.ok){
        btn.textContent = 'Send Enquiry →'; btn.disabled = false;
        form.reset();
        if (typeof window.showSuccessModal === 'function') {
          window.showSuccessModal(
            'Message Sent!',
            'Thank you — a member of the Tribal Sand team will be in touch within 24 hours. For urgent matters, WhatsApp us on <a href="https://wa.me/254115115247" style="color:var(--teal);">+254 115 115 247</a>.',
            false
          );
        }
      } else { throw new Error(r.error||'error'); }
    })
    .catch(function(){
      btn.textContent = 'Send Enquiry →'; btn.disabled = false;
      var e = document.getElementById('ctFormErr') || document.createElement('p');
      e.id = 'ctFormErr'; e.style.cssText = 'color:#9B3B2A;font-size:.85rem;margin-top:.75rem;';
      e.textContent = 'Something went wrong — please WhatsApp us on +254 115 115 247';
      if (!document.getElementById('ctFormErr')) btn.insertAdjacentElement('afterend', e);
    });
  });
})();
</script>
</body>
</html>
