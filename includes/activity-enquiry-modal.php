<?php
/**
 * Tribal Sand — on-page activity enquiry modal (shared by activities.php).
 *
 * Opened by any [data-act-enquire] trigger (the "Enquire" button on an activity
 * card), which carries data-tour-slug / data-tour-name. Unlike the room enquiry
 * wizard (includes/enquiry-multistep.php) this form is NOT dates-first — it asks
 * for an OPTIONAL single preferred date, so guests enquiring about an activity
 * aren't forced to pick check-in/check-out. It posts to /api/submit-enquiry.php
 * with a tour_slug and no checkin/checkout, which lands in the plain 'enquiry'
 * branch there (no hold) — no server change required.
 *
 * Requires the styled datepicker (js/datepicker.js) + booking.css, loaded on the
 * host page via $page_booking. Requires includes/turnstile.php for captcha_site_key()
 * (already loaded through head.php).
 */
require_once __DIR__ . '/turnstile.php';
?>
<div class="aem" id="aem" hidden aria-hidden="true">
  <style>
  .aem{position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;padding:1.2rem;background:rgba(16,28,34,.62);backdrop-filter:blur(3px);
    --a-sand:#B8965A;--a-sand-lt:#D4B07A;--a-teal:#1E5C6B;--a-teal-d:#102F3A;--a-dark:#141412;--a-off:#FAF8F4;--a-mid:#6B6050;--a-light:#A89880;--a-border:rgba(184,150,90,.22);}
  .aem.is-open{display:flex;}
  .aem__card{background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(10,30,40,.32);width:100%;max-width:460px;max-height:92vh;overflow:auto;padding:1.7rem 1.6rem 1.9rem;position:relative;font-family:'Jost',sans-serif;}
  .aem__close{position:absolute;top:.8rem;right:.9rem;width:34px;height:34px;border:none;background:var(--a-off);border-radius:50%;color:var(--a-mid);cursor:pointer;display:grid;place-items:center;transition:.2s;}
  .aem__close:hover{background:var(--a-sand);color:#fff;}
  .aem__close svg{width:17px;height:17px;}
  .aem__eyebrow{font-size:.6rem;letter-spacing:.24em;text-transform:uppercase;color:var(--a-sand);margin-bottom:.4rem;}
  .aem__title{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.7rem;color:var(--a-dark);margin:0 0 .3rem;line-height:1.15;}
  .aem__subj{color:var(--a-teal);font-weight:600;}
  .aem__intro{font-size:.9rem;color:var(--a-mid);line-height:1.6;margin:0 0 1.3rem;}
  .aem__grid{display:grid;gap:.9rem;}
  .aem__row2{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;}
  .aem__field{display:flex;flex-direction:column;gap:.3rem;text-align:left;}
  .aem__field label{font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--a-sand);font-weight:600;}
  .aem__field input,.aem__field textarea{font-family:'Jost',sans-serif;font-size:.96rem;color:var(--a-dark);background:var(--a-off);border:1px solid var(--a-border);border-radius:7px;padding:.72rem .82rem;width:100%;transition:border-color .2s,box-shadow .2s;}
  .aem__field input:focus,.aem__field textarea:focus{outline:none;border-color:var(--a-sand);box-shadow:0 0 0 3px rgba(184,150,90,.14);}
  .aem__field input.is-invalid{border-color:#c0392b;box-shadow:0 0 0 3px rgba(192,57,43,.12);}
  /* datepicker trigger — match the field look, override booking.css defaults */
  .aem .dp-btn{font-family:'Jost',sans-serif;font-size:.96rem;color:var(--a-light);background:var(--a-off);border:1px solid var(--a-border);border-radius:7px;padding:.72rem .82rem;width:100%;text-align:left;cursor:pointer;transition:border-color .2s,box-shadow .2s;}
  .aem .dp-btn:hover,.aem .dp-btn:focus{outline:none;border-color:var(--a-sand);box-shadow:0 0 0 3px rgba(184,150,90,.14);}
  .aem .dp-btn--active{color:var(--a-dark);font-weight:500;}
  .aem__guests{display:flex;align-items:center;justify-content:space-between;border:1px solid var(--a-border);border-radius:7px;background:var(--a-off);padding:.55rem .85rem;}
  .aem__guests strong{font-size:.92rem;color:var(--a-dark);font-weight:600;}
  .aem__step-ctrl{display:flex;align-items:center;gap:.9rem;}
  .aem__step-ctrl button{width:32px;height:32px;border-radius:50%;border:1.5px solid var(--a-border);background:#fff;color:var(--a-teal);font-size:1.1rem;line-height:1;cursor:pointer;display:grid;place-items:center;transition:.2s;}
  .aem__step-ctrl button:hover{border-color:var(--a-sand);background:var(--a-sand);color:#fff;}
  .aem__step-ctrl span{min-width:1.2ch;text-align:center;font-weight:600;color:var(--a-dark);}
  .aem__error{font-size:.85rem;color:#c0392b;background:rgba(192,57,43,.07);border:1px solid rgba(192,57,43,.2);border-radius:6px;padding:.55rem .75rem;}
  .aem .cf-turnstile{margin:.2rem 0;}
  .aem__btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;font-size:.64rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;border:none;border-radius:6px;padding:.92rem 1.6rem;cursor:pointer;transition:.2s;width:100%;background:var(--a-teal-d);color:#fff;}
  .aem__btn:hover{background:var(--a-teal);}
  .aem__btn:disabled{opacity:.6;cursor:default;}
  .aem__note{font-size:.72rem;color:var(--a-light);text-align:center;margin:.2rem 0 0;}
  .aem__done{text-align:center;padding:1rem .5rem;}
  .aem__done-ico{width:54px;height:54px;border-radius:50%;background:var(--a-sand);color:var(--a-teal-d);display:grid;place-items:center;margin:0 auto 1rem;}
  .aem__done-ico svg{width:27px;height:27px;}
  .aem__done h3{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.6rem;color:var(--a-dark);margin:0 0 .5rem;}
  .aem__done p{color:var(--a-mid);line-height:1.65;margin:0;}
  @media(max-width:480px){.aem__row2{grid-template-columns:1fr;}.aem__card{padding:1.5rem 1.2rem 1.6rem;}}
  /* iOS Safari zooms a focused field under 16px */
  @media(max-width:768px){.aem__field input,.aem__field textarea{font-size:16px;}.aem .dp-btn{font-size:16px;}}
  </style>

  <div class="aem__card" role="dialog" aria-modal="true" aria-labelledby="aemTitle">
    <button type="button" class="aem__close" data-aem-close aria-label="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>

    <!-- form view -->
    <div data-aem-formview>
      <div class="aem__eyebrow">Tribal Sand · Enquire</div>
      <h2 class="aem__title" id="aemTitle">Enquire about <span class="aem__subj" data-aem-subject>this experience</span></h2>
      <p class="aem__intro">Tell us a little about what you'd like and we'll reply within 24 hours with availability and a tailored quote.</p>

      <form data-aem-form novalidate>
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
        <input type="hidden" name="tour_slug" data-aem-slug value="">
        <div class="aem__grid">
          <div class="aem__row2">
            <div class="aem__field">
              <label for="aemName">Full name</label>
              <input type="text" id="aemName" name="name" placeholder="Your name" required>
            </div>
            <div class="aem__field">
              <label for="aemPhone">Phone <span style="color:var(--a-light)">(optional)</span></label>
              <input type="tel" id="aemPhone" name="phone" placeholder="+254 700 000 000">
            </div>
          </div>
          <div class="aem__field">
            <label for="aemEmail">Email</label>
            <input type="email" id="aemEmail" name="email" placeholder="you@email.com" required>
          </div>
          <div class="aem__row2">
            <div class="aem__field">
              <label>Preferred date <span style="color:var(--a-light)">(optional)</span></label>
              <button type="button" class="dp-btn" data-dp-target="aemDate" data-dp-placeholder="Choose a date">Choose a date</button>
              <input type="hidden" id="aemDate" name="preferred_date">
            </div>
            <div class="aem__field">
              <label>Guests</label>
              <div class="aem__guests">
                <strong>Guests</strong>
                <div class="aem__step-ctrl">
                  <button type="button" data-aem-g="-1" aria-label="Fewer guests">&minus;</button>
                  <span data-aem-gcount>2</span>
                  <button type="button" data-aem-g="1" aria-label="More guests">+</button>
                </div>
              </div>
            </div>
          </div>
          <div class="aem__field">
            <label for="aemMsg">Message <span style="color:var(--a-light)">(optional)</span></label>
            <textarea id="aemMsg" name="message" rows="3" placeholder="Group size, interests, special requests…"></textarea>
          </div>
          <div class="aem__error" data-aem-error hidden></div>
          <?php if (captcha_site_key()): ?>
          <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
          <?php endif; ?>
          <button type="submit" class="aem__btn" data-aem-send>Send Enquiry <span aria-hidden="true">&rsaquo;</span></button>
          <p class="aem__note">No payment now · We reply within 24 hours</p>
        </div>
      </form>
    </div>

    <!-- thank-you view -->
    <div data-aem-doneview hidden>
      <div class="aem__done">
        <div class="aem__done-ico">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h3>Thank you for your enquiry</h3>
        <p>We've received your message and will reply within 24 hours with availability and a tailored quote.</p>
        <button type="button" class="aem__btn" data-aem-close style="margin-top:1.4rem">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var modal = document.getElementById('aem');
  if (!modal || modal.dataset.init) return;
  modal.dataset.init = '1';

  var form     = modal.querySelector('[data-aem-form]');
  var formView = modal.querySelector('[data-aem-formview]');
  var doneView = modal.querySelector('[data-aem-doneview]');
  var subjEl   = modal.querySelector('[data-aem-subject]');
  var slugEl   = modal.querySelector('[data-aem-slug]');
  var errBox   = modal.querySelector('[data-aem-error]');
  var gCount   = modal.querySelector('[data-aem-gcount]');
  var dateBtn  = modal.querySelector('.dp-btn');
  var dateInp  = modal.querySelector('#aemDate');
  var guests   = 2;

  function err(msg){
    if (msg){ errBox.textContent = msg; errBox.hidden = false; }
    else { errBox.hidden = true; errBox.textContent = ''; }
  }
  function resetCaptcha(){ try { if (window.turnstile) window.turnstile.reset(); } catch(e){} }

  function open(slug, name){
    slugEl.value = slug || '';
    subjEl.textContent = name || 'this experience';
    // reset to a clean form each open
    form.reset();
    slugEl.value = slug || '';           // form.reset() wipes hidden fields too
    guests = 2; gCount.textContent = '2';
    dateInp.value = '';
    if (dateBtn){ dateBtn.textContent = dateBtn.dataset.dpPlaceholder || 'Choose a date'; dateBtn.classList.remove('dp-btn--active'); }
    err('');
    resetCaptcha();
    formView.hidden = false; doneView.hidden = true;
    modal.hidden = false; modal.setAttribute('aria-hidden','false');
    requestAnimationFrame(function(){ modal.classList.add('is-open'); });
    // Ensure the datepicker button is wired (survives if the picker loaded late).
    if (window.initDatepickers) window.initDatepickers();
  }
  function close(){
    modal.classList.remove('is-open');
    modal.hidden = true; modal.setAttribute('aria-hidden','true');
  }

  // Triggers — intercept the card's Enquire link, keep href as a no-JS fallback.
  document.querySelectorAll('[data-act-enquire]').forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      open(t.dataset.tourSlug, t.dataset.tourName);
    });
  });

  modal.querySelectorAll('[data-aem-close]').forEach(function(b){ b.addEventListener('click', close); });
  modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !modal.hidden) close(); });

  modal.querySelectorAll('[data-aem-g]').forEach(function(btn){
    btn.addEventListener('click', function(){
      guests = Math.max(1, Math.min(30, guests + parseInt(btn.dataset.aemG, 10)));
      gCount.textContent = guests;
    });
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    err('');
    var name  = form.querySelector('[name=name]');
    var email = form.querySelector('[name=email]');
    name.classList.remove('is-invalid'); email.classList.remove('is-invalid');
    if (!name.value.trim()){ name.classList.add('is-invalid'); err('Please enter your name.'); name.focus(); return; }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.value.trim())){ email.classList.add('is-invalid'); err('Please enter a valid email.'); email.focus(); return; }

    var tokenEl = form.querySelector("[name='cf-turnstile-response']");
    if (form.querySelector('.cf-turnstile') && (!tokenEl || !tokenEl.value)){ err('Please complete the security check.'); return; }

    var msg = form.querySelector('[name=message]').value.trim();
    if (dateInp.value){ msg = 'Preferred date: ' + dateInp.value + (msg ? '\n\n' + msg : ''); }

    var payload = {
      tour_slug: slugEl.value,
      name: name.value.trim(),
      email: email.value.trim(),
      phone: form.querySelector('[name=phone]').value.trim(),
      message: msg,
      adults: guests,
      children: 0
    };
    if (tokenEl && tokenEl.value) payload['cf-turnstile-response'] = tokenEl.value;

    var btn = form.querySelector('[data-aem-send]');
    btn.disabled = true; btn.textContent = 'Sending…';

    fetch('/api/submit-enquiry.php', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    })
    .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
    .then(function(json){
      if (json && json.ok){ formView.hidden = true; doneView.hidden = false; return; }
      resetCaptcha();
      btn.disabled = false; btn.innerHTML = 'Send Enquiry <span aria-hidden="true">&rsaquo;</span>';
      err((json && (json.error || (json.errors && Object.values(json.errors).filter(Boolean).join(' ')))) || 'Something went wrong. Please try again.');
    })
    .catch(function(){
      resetCaptcha();
      btn.disabled = false; btn.innerHTML = 'Send Enquiry <span aria-hidden="true">&rsaquo;</span>';
      err('Network error. Please check your connection and try again.');
    });
  });
})();
</script>
