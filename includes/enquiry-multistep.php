<?php
/**
 * Tribal Sand — multi-step enquiry form (Seven Islands style).
 *
 * Self-contained 3-step wizard: (1) dates + guests, (2) contact + Turnstile,
 * (3) thank-you. Posts to /api/submit-enquiry.php and creates a website enquiry.
 *
 * Optional variables (set before including):
 *   $enq_room_slug  — pre-target a specific room/villa (rooms.slug). Default ''.
 *   $enq_room_name  — label shown to the guest. Default ''.
 *   $enq_tour_slug  — pre-target a specific tour/activity (tours.slug). Default ''.
 *   $enq_tour_name  — tour label shown to the guest. Default ''.
 *   $enq_heading    — section heading. Default 'Send an Enquiry'.
 *   $enq_intro      — sub-heading line. Default provided.
 *
 * Requires includes/turnstile.php (loaded via head.php) for captcha_site_key().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/turnstile.php';

$enq_room_slug = $enq_room_slug ?? '';
$enq_room_name = $enq_room_name ?? '';
$enq_tour_slug = $enq_tour_slug ?? '';
$enq_tour_name = $enq_tour_name ?? '';
$enq_heading   = $enq_heading   ?? 'Send an Enquiry';
$enq_intro     = $enq_intro     ?? 'Tell us your dates and we’ll reply within 24 hours with availability and a tailored quote.';
// What the guest is enquiring about (villa takes precedence over tour for the label)
$enq_subject_name = $enq_room_name ?: $enq_tour_name;
$enq_uid       = 'enqms_' . substr(md5($enq_room_slug . microtime()), 0, 6);
$enq_today     = date('Y-m-d');
?>
<section class="enqms" id="<?= e($enq_uid) ?>" aria-label="Enquiry form">
  <style>
  /* ── Multi-step enquiry (scoped to .enqms) ─────────────────────────── */
  .enqms{--e-sand:#B8965A;--e-sand-lt:#D4B07A;--e-teal:#1E5C6B;--e-teal-d:#102F3A;--e-dark:#141412;--e-off:#FAF8F4;--e-mid:#6B6050;--e-light:#A89880;--e-border:rgba(184,150,90,.22);--e-white:#fff;
    max-width:640px;margin:0 auto;padding:0 5vw;}
  .enqms__head{text-align:center;margin-bottom:1.6rem;}
  .enqms__eyebrow{font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.28em;text-transform:uppercase;color:var(--e-sand);margin-bottom:.7rem;}
  .enqms__title{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:clamp(1.9rem,4.5vw,2.7rem);color:var(--e-dark);margin:0 0 .6rem;}
  .enqms__intro{font-family:'Jost',sans-serif;color:var(--e-mid);font-size:.98rem;line-height:1.7;max-width:460px;margin:0 auto;}
  .enqms__card{background:var(--e-white);border:1px solid var(--e-border);border-radius:12px;box-shadow:0 18px 50px rgba(10,30,40,.10);padding:1.9rem 1.7rem;}
  /* progress */
  .enqms__steps{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-bottom:1.6rem;font-family:'Jost',sans-serif;}
  .enqms__dot{display:flex;align-items:center;gap:.5rem;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:var(--e-light);font-weight:600;}
  .enqms__dot i{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;border:1.5px solid var(--e-border);font-style:normal;font-size:.72rem;color:var(--e-light);background:var(--e-off);transition:.25s;}
  .enqms__dot.is-active i{background:var(--e-teal-d);border-color:var(--e-teal-d);color:#fff;}
  .enqms__dot.is-done i{background:var(--e-sand);border-color:var(--e-sand);color:var(--e-teal-d);}
  .enqms__bar{width:26px;height:1.5px;background:var(--e-border);}
  /* fields */
  .enqms__step{display:block;}
  .enqms__step[hidden]{display:none;}
  .enqms__grid{display:grid;gap:1rem;}
  .enqms__row2{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;}
  .enqms__field{display:flex;flex-direction:column;gap:.3rem;text-align:left;}
  .enqms__field label,.enqms__field>span{font-family:'Jost',sans-serif;font-size:.64rem;letter-spacing:.14em;text-transform:uppercase;color:var(--e-sand);font-weight:600;}
  .enqms__field input,.enqms__field textarea{font-family:'Jost',sans-serif;font-size:.98rem;color:var(--e-dark);background:var(--e-off);border:1px solid var(--e-border);border-radius:7px;padding:.75rem .85rem;width:100%;transition:border-color .2s,box-shadow .2s;}
  .enqms__field input:focus,.enqms__field textarea:focus{outline:none;border-color:var(--e-sand);box-shadow:0 0 0 3px rgba(184,150,90,.14);}
  .enqms__field input.is-invalid{border-color:#c0392b;box-shadow:0 0 0 3px rgba(192,57,43,.12);}
  /* Styled datepicker trigger (dp-btn) — match the enqms input look, override booking.css */
  .enqms .dp-btn{font-family:'Jost',sans-serif;font-size:.98rem;color:var(--e-light);background:var(--e-off);border:1px solid var(--e-border);border-radius:7px;padding:.75rem .85rem;width:100%;text-align:left;cursor:pointer;transition:border-color .2s,box-shadow .2s;}
  .enqms .dp-btn:hover,.enqms .dp-btn:focus{outline:none;border-color:var(--e-sand);box-shadow:0 0 0 3px rgba(184,150,90,.14);}
  .enqms .dp-btn--active{color:var(--e-dark);font-weight:500;}
  /* guests */
  .enqms__guests{border:1px solid var(--e-border);border-radius:7px;background:var(--e-off);padding:.2rem .95rem;}
  .enqms__grow{display:flex;align-items:center;justify-content:space-between;padding:.8rem 0;border-bottom:1px solid var(--e-border);}
  .enqms__grow:last-child{border-bottom:none;}
  .enqms__grow strong{display:block;font-family:'Jost',sans-serif;font-size:.92rem;color:var(--e-dark);}
  .enqms__grow small{font-family:'Jost',sans-serif;font-size:.72rem;color:var(--e-light);}
  .enqms__step-ctrl{display:flex;align-items:center;gap:1rem;}
  .enqms__step-ctrl button{width:32px;height:32px;border-radius:50%;border:1.5px solid var(--e-border);background:var(--e-white);color:var(--e-teal);font-size:1.1rem;line-height:1;cursor:pointer;display:grid;place-items:center;transition:.2s;}
  .enqms__step-ctrl button:hover{border-color:var(--e-sand);background:var(--e-sand);color:#fff;}
  .enqms__step-ctrl span{min-width:1.2ch;text-align:center;font-family:'Jost',sans-serif;font-weight:600;color:var(--e-dark);}
  /* buttons */
  .enqms__btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;font-family:'Jost',sans-serif;font-size:.66rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;border:none;border-radius:6px;padding:.95rem 1.6rem;cursor:pointer;transition:.2s;width:100%;}
  .enqms__btn--primary{background:var(--e-teal-d);color:#fff;}
  .enqms__btn--primary:hover{background:var(--e-teal);}
  .enqms__btn--primary:disabled{opacity:.6;cursor:default;}
  .enqms__back{background:none;border:none;font-family:'Jost',sans-serif;font-size:.78rem;color:var(--e-mid);cursor:pointer;display:inline-flex;align-items:center;gap:.35rem;padding:0;margin-bottom:1.1rem;}
  .enqms__back:hover{color:var(--e-teal);}
  .enqms__back svg{width:15px;height:15px;}
  .enqms__error{font-family:'Jost',sans-serif;font-size:.85rem;color:#c0392b;background:rgba(192,57,43,.07);border:1px solid rgba(192,57,43,.2);border-radius:6px;padding:.6rem .8rem;margin-bottom:.9rem;}
  .enqms__note{font-family:'Jost',sans-serif;font-size:.72rem;color:var(--e-light);text-align:center;margin-top:.9rem;}
  .enqms .cf-turnstile{margin:0 0 1rem;}
  /* done */
  .enqms__done{text-align:center;padding:1.4rem .5rem;}
  .enqms__done-ico{width:56px;height:56px;border-radius:50%;background:var(--e-sand);color:var(--e-teal-d);display:grid;place-items:center;margin:0 auto 1.1rem;}
  .enqms__done-ico svg{width:28px;height:28px;}
  .enqms__done h3{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.7rem;color:var(--e-dark);margin:0 0 .5rem;}
  .enqms__done p{font-family:'Jost',sans-serif;color:var(--e-mid);line-height:1.7;margin:0;}
  @media(max-width:520px){.enqms__row2{grid-template-columns:1fr;}.enqms__card{padding:1.5rem 1.15rem;}}
  </style>

  <div class="enqms__head">
    <div class="enqms__eyebrow">Tribal Sand · Kenya’s North Coast</div>
    <h2 class="enqms__title"><?= e($enq_heading) ?></h2>
    <p class="enqms__intro"><?= e($enq_intro) ?><?php if ($enq_subject_name): ?> <strong style="color:var(--e-teal)"><?= e($enq_subject_name) ?></strong><?php endif; ?></p>
  </div>

  <div class="enqms__card">
    <div class="enqms__steps" data-enq-progress>
      <span class="enqms__dot is-active" data-dot="1"><i>1</i> Dates</span>
      <span class="enqms__bar"></span>
      <span class="enqms__dot" data-dot="2"><i>2</i> Details</span>
      <span class="enqms__bar"></span>
      <span class="enqms__dot" data-dot="3"><i>&#10003;</i> Done</span>
    </div>

    <form class="enqms__form" data-enq-form novalidate
          data-room-slug="<?= e($enq_room_slug) ?>" data-room-name="<?= e($enq_room_name) ?>">
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <input type="hidden" name="room_slug" value="<?= e($enq_room_slug) ?>">
      <input type="hidden" name="tour_slug" value="<?= e($enq_tour_slug) ?>">
      <input type="hidden" name="adults"   value="2" data-enq-adults>
      <input type="hidden" name="children" value="0" data-enq-children>

      <!-- ── STEP 1: dates + guests ── -->
      <div class="enqms__step" data-step="1">
        <div class="enqms__grid">
          <div class="enqms__row2">
            <div class="enqms__field">
              <span>Check in</span>
              <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="<?= $enq_uid ?>" data-dp-target="<?= $enq_uid ?>_ci" data-dp-placeholder="Check-in date">Check-in date</button>
              <input type="hidden" id="<?= $enq_uid ?>_ci" name="checkin">
            </div>
            <div class="enqms__field">
              <span>Check out</span>
              <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="<?= $enq_uid ?>" data-dp-target="<?= $enq_uid ?>_co" data-dp-placeholder="Check-out date">Check-out date</button>
              <input type="hidden" id="<?= $enq_uid ?>_co" name="checkout">
            </div>
          </div>
          <div class="enqms__field">
            <span>Guests</span>
            <div class="enqms__guests">
              <div class="enqms__grow">
                <div><strong>Adults</strong><small>Age 18+</small></div>
                <div class="enqms__step-ctrl">
                  <button type="button" data-g="adult" data-dir="-1" aria-label="Fewer adults">&minus;</button>
                  <span data-g-count="adult">2</span>
                  <button type="button" data-g="adult" data-dir="1" aria-label="More adults">+</button>
                </div>
              </div>
              <div class="enqms__grow">
                <div><strong>Children</strong><small>Age 0–17</small></div>
                <div class="enqms__step-ctrl">
                  <button type="button" data-g="child" data-dir="-1" aria-label="Fewer children">&minus;</button>
                  <span data-g-count="child">0</span>
                  <button type="button" data-g="child" data-dir="1" aria-label="More children">+</button>
                </div>
              </div>
            </div>
          </div>
          <div class="enqms__error" data-enq-error hidden></div>
          <button type="button" class="enqms__btn enqms__btn--primary" data-enq-next>Continue <span aria-hidden="true">&rsaquo;</span></button>
        </div>
      </div>

      <!-- ── STEP 2: contact + captcha ── -->
      <div class="enqms__step" data-step="2" hidden>
        <button type="button" class="enqms__back" data-enq-back>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
          Back to dates
        </button>
        <div class="enqms__grid">
          <div class="enqms__row2">
            <div class="enqms__field">
              <label for="<?= $enq_uid ?>_name">Full name</label>
              <input type="text" id="<?= $enq_uid ?>_name" name="name" placeholder="Your name" required>
            </div>
            <div class="enqms__field">
              <label for="<?= $enq_uid ?>_phone">Phone</label>
              <input type="tel" id="<?= $enq_uid ?>_phone" name="phone" placeholder="+254 700 000 000">
            </div>
          </div>
          <div class="enqms__field">
            <label for="<?= $enq_uid ?>_email">Email</label>
            <input type="email" id="<?= $enq_uid ?>_email" name="email" placeholder="you@email.com" required>
          </div>
          <div class="enqms__field">
            <label for="<?= $enq_uid ?>_msg">Message</label>
            <textarea id="<?= $enq_uid ?>_msg" name="message" rows="3" placeholder="Tell us about your stay, group size, or any special requests"></textarea>
          </div>
          <div class="enqms__error" data-enq-error hidden></div>
          <?php if (captcha_site_key()): ?>
          <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
          <?php endif; ?>
          <button type="submit" class="enqms__btn enqms__btn--primary" data-enq-send>Send Enquiry <span aria-hidden="true">&rsaquo;</span></button>
        </div>
        <p class="enqms__note">No payment now · Free 24-hour hold · We reply within 24 hours</p>
      </div>

      <!-- ── STEP 3: thank you ── -->
      <div class="enqms__step" data-step="3" hidden>
        <div class="enqms__done">
          <div class="enqms__done-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <h3>Thank you for your enquiry</h3>
          <p>We’ve received your message and will reply within 24 hours with availability and a tailored quote.</p>
        </div>
      </div>
    </form>
  </div>
</section>

<script>
(function(){
  var root = document.getElementById('<?= $enq_uid ?>');
  if (!root || root.dataset.enqInit) return;
  root.dataset.enqInit = '1';

  var form   = root.querySelector('[data-enq-form]');
  var steps  = root.querySelectorAll('.enqms__step');
  var dots   = root.querySelectorAll('.enqms__dot');
  var adultsH = form.querySelector('[data-enq-adults]');
  var childH  = form.querySelector('[data-enq-children]');

  function show(n){
    steps.forEach(function(s){ s.hidden = s.dataset.step !== String(n); });
    dots.forEach(function(d){
      var i = parseInt(d.dataset.dot,10);
      d.classList.toggle('is-active', i === n);
      d.classList.toggle('is-done', i < n);
    });
    root.scrollIntoView({behavior:'smooth', block:'nearest'});
  }

  function err(step, msg){
    var box = form.querySelector('[data-step="'+step+'"] [data-enq-error]');
    if (!box) return;
    if (msg){ box.textContent = msg; box.hidden = false; }
    else { box.hidden = true; box.textContent = ''; }
  }

  // Guest steppers
  form.querySelectorAll('[data-g]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var key = btn.dataset.g;
      var min = key === 'adult' ? 1 : 0;
      var countEl = form.querySelector('[data-g-count="'+key+'"]');
      var hidden  = key === 'adult' ? adultsH : childH;
      var val = Math.max(min, Math.min(20, parseInt(countEl.textContent,10) + parseInt(btn.dataset.dir,10)));
      countEl.textContent = val;
      hidden.value = val;
    });
  });

  // Step 1 → 2 (dates optional but if both set, checkout must be after checkin)
  form.querySelector('[data-enq-next]').addEventListener('click', function(){
    var ci = form.querySelector('[name=checkin]').value;
    var co = form.querySelector('[name=checkout]').value;
    if (ci && co && co <= ci){ err(1, 'Check-out must be after check-in.'); return; }
    err(1, '');
    show(2);
  });

  form.querySelector('[data-enq-back]').addEventListener('click', function(){ err(2,''); show(1); });

  function resetCaptcha(){
    try { if (window.turnstile) window.turnstile.reset(); } catch(e){}
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    err(2, '');
    var name  = form.querySelector('[name=name]');
    var email = form.querySelector('[name=email]');
    name.classList.remove('is-invalid'); email.classList.remove('is-invalid');

    if (!name.value.trim()){ name.classList.add('is-invalid'); err(2,'Please enter your name.'); name.focus(); return; }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.value.trim())){ email.classList.add('is-invalid'); err(2,'Please enter a valid email.'); email.focus(); return; }

    var tokenEl = form.querySelector("[name='cf-turnstile-response']");
    if (form.querySelector('.cf-turnstile') && (!tokenEl || !tokenEl.value)){
      err(2, 'Please complete the security check.');
      return;
    }

    var btn = form.querySelector('[data-enq-send]');
    btn.disabled = true; btn.textContent = 'Sending…';

    var payload = {};
    new FormData(form).forEach(function(v,k){ payload[k] = v; });
    payload.adults   = parseInt(adultsH.value,10);
    payload.children = parseInt(childH.value,10);

    fetch('/api/submit-enquiry.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    })
    .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
    .then(function(json){
      if (json && json.ok){ show(3); return; }
      resetCaptcha();
      btn.disabled = false; btn.innerHTML = 'Send Enquiry <span aria-hidden="true">&rsaquo;</span>';
      var msg = (json && (json.error || (json.errors && Object.values(json.errors).filter(Boolean).join(' ')))) || 'Something went wrong. Please try again.';
      err(2, msg);
    })
    .catch(function(){
      resetCaptcha();
      btn.disabled = false; btn.innerHTML = 'Send Enquiry <span aria-hidden="true">&rsaquo;</span>';
      err(2, 'Network error. Please check your connection and try again.');
    });
  });
})();
</script>
