<?php
/**
 * Zuri restaurant — booking form widget.
 *
 * Self-contained: markup + its own JS, mirroring includes/form-enquiry.php.
 * Slot chips are generated client-side from the venue's hours config purely for
 * convenience — api/restaurant-book.php re-derives and re-validates them.
 *
 * Requires: css/datepicker.css, js/datepicker.js (single-date mode).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/restaurant.php';
require_once __DIR__ . '/turnstile.php';

$__rSlug = 'zuri';
// restaurant_hours() -> setting() -> db_query() can throw (pooler hiccup, DB
// blip). Unguarded, that throws mid-render after the page shell is already
// flushed to the browser, leaving a half-rendered page with no form at all.
// Fall back to the same defaults restaurant_hours() itself would use for a
// missing/blank setting — the form still renders and the server re-validates
// on submit regardless (see includes/cross-sell-tours.php for the same
// pattern).
try {
    $__rHours = restaurant_hours($__rSlug);
} catch (Throwable $e) {
    $__rHours = restaurant_default_hours();
}
?>
<form class="rbook" id="rbookForm" novalidate>
  <input type="text" name="website" class="rbook__hp" tabindex="-1" autocomplete="off" aria-hidden="true">

  <div class="rbook__row">
    <div class="rbook__field">
      <span class="rbook__lbl">Date</span>
      <button type="button" class="dp-btn rbook__input" id="rbookDateBtn" data-dp-target="rbookDate">Choose a date</button>
      <input type="hidden" id="rbookDate" name="date">
    </div>
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookParty">Guests</label>
      <select class="rbook__input" id="rbookParty" name="party_size">
        <?php for ($g = RESTAURANT_PARTY_MIN; $g <= RESTAURANT_PARTY_MAX; $g++): ?>
        <option value="<?= $g ?>"<?= $g === 2 ? ' selected' : '' ?>><?= $g ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>

  <div class="rbook__field">
    <span class="rbook__lbl">Time</span>
    <div class="rbook__slots" id="rbookSlots" role="group" aria-label="Available times">
      <p class="rbook__hint">Choose a date to see available times.</p>
    </div>
    <input type="hidden" id="rbookTime" name="time">
  </div>

  <div class="rbook__field">
    <label class="rbook__lbl" for="rbookOccasion">Occasion <em>(optional)</em></label>
    <select class="rbook__input" id="rbookOccasion" name="occasion">
      <option value="">Just dinner</option>
      <?php foreach (restaurant_occasions() as $o): ?>
      <option value="<?= e($o) ?>"><?= e(ucfirst($o)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="rbook__row">
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookName">Name</label>
      <input class="rbook__input" type="text" id="rbookName" name="name" autocomplete="name" required>
    </div>
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookEmail">Email</label>
      <input class="rbook__input" type="email" id="rbookEmail" name="email" autocomplete="email" inputmode="email" required>
    </div>
  </div>

  <div class="rbook__field">
    <label class="rbook__lbl" for="rbookPhone">Phone <em>(optional)</em></label>
    <input class="rbook__input" type="tel" id="rbookPhone" name="phone" autocomplete="tel" inputmode="tel">
  </div>

  <div class="rbook__field">
    <label class="rbook__lbl" for="rbookNotes">Anything we should know? <em>(optional)</em></label>
    <textarea class="rbook__input" id="rbookNotes" name="notes" rows="3" placeholder="Allergies, dietary needs, a quiet table…"></textarea>
  </div>

  <?php if (captcha_site_key()): ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
  <?php endif; ?>

  <p class="rbook__err" id="rbookErr" role="alert" hidden></p>
  <button type="submit" class="rbook__submit">Request a Table</button>
  <p class="rbook__note">We confirm every table by email within 24 hours. No payment now.</p>
</form>

<style>
.rbook{max-width:560px;margin:0 auto;text-align:left}
.rbook__hp{position:absolute!important;left:-9999px;width:1px;height:1px;opacity:0}
.rbook__row{display:flex;gap:1rem}
.rbook__row .rbook__field{flex:1;min-width:0}
.rbook__field{margin-bottom:1rem}
.rbook__lbl{display:block;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--sand,#B8965A);font-weight:600;margin-bottom:.35rem}
.rbook__lbl em{font-style:normal;letter-spacing:0;text-transform:none;color:var(--light,#8C7A60);font-weight:400}
.rbook__input{width:100%;box-sizing:border-box;padding:.7rem .85rem;border:1px solid var(--border,rgba(184,150,90,.28));border-radius:4px;background:#fff;font-family:inherit;font-size:16px;color:var(--dark,#141412);text-align:left}
.rbook__input:focus{outline:none;border-color:var(--teal,#1E5C6B);box-shadow:0 0 0 3px rgba(30,92,107,.12)}
.rbook__slots{display:flex;flex-wrap:wrap;gap:.4rem}
.rbook__hint{font-size:.85rem;color:var(--light,#8C7A60);margin:0}
.rbook__slot{padding:.5rem .9rem;border:1px solid var(--border,rgba(184,150,90,.28));border-radius:4px;background:#fff;font-family:inherit;font-size:.9rem;color:var(--dark,#141412);cursor:pointer}
.rbook__slot.is-on{background:var(--teal-d,#102F3A);border-color:var(--teal-d,#102F3A);color:#fff}
.rbook__err{background:#fbe6e6;border:1px solid #f0c2c2;color:#a12;border-radius:4px;padding:.6rem .8rem;font-size:.85rem;margin:0 0 .8rem}
.rbook__err[hidden]{display:none}
.rbook__field-err{color:#a12;font-size:.78rem;margin:.35rem 0 0}
.rbook__input.is-err{border-color:#c94747}
.rbook__slots.is-err{border:1px solid #c94747;border-radius:4px;padding:.5rem}
.rbook__submit{width:100%;background:var(--teal-d,#102F3A);color:#fff;border:0;border-radius:4px;padding:.95rem 1.2rem;font-family:inherit;font-size:.82rem;letter-spacing:.08em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s}
.rbook__submit:hover{background:var(--teal,#1E5C6B)}
.rbook__submit:disabled{opacity:.6;cursor:default}
.rbook__note{text-align:center;font-size:.75rem;color:var(--light,#8C7A60);margin:.8rem 0 0}
@media(max-width:560px){.rbook__row{flex-wrap:wrap;gap:0}.rbook__row .rbook__field{flex:1 1 100%}}
</style>

<script>
(function () {
  var HOURS = <?= json_encode($__rHours, JSON_UNESCAPED_SLASHES) ?>;
  var form  = document.getElementById('rbookForm');
  if (!form) return;
  var dateIn = document.getElementById('rbookDate');
  var timeIn = document.getElementById('rbookTime');
  var slots  = document.getElementById('rbookSlots');
  var err    = document.getElementById('rbookErr');

  // Field-level error hookup: key -> input element, so a per-field message
  // from the server can be shown right where the guest needs to fix it.
  var FIELD_INPUTS = {
    name: form.querySelector('[name="name"]'),
    email: form.querySelector('[name="email"]'),
    phone: form.querySelector('[name="phone"]'),
    party_size: form.querySelector('[name="party_size"]'),
    date: document.getElementById('rbookDateBtn'),
    time: slots,
    occasion: form.querySelector('[name="occasion"]'),
    notes: form.querySelector('[name="notes"]')
  };

  // Selects on the generic `.is-err` marker (not `.rbook__input.is-err`) so it
  // also catches the time field, whose target is #rbookSlots — a
  // `.rbook__slots`, not a `.rbook__input`. Also drops the aria-invalid /
  // aria-describedby pair set below so a resolved error stops being announced.
  function clearFieldErrs() {
    form.querySelectorAll('.rbook__field-err').forEach(function (n) { n.remove(); });
    form.querySelectorAll('.is-err').forEach(function (n) {
      n.classList.remove('is-err');
      n.removeAttribute('aria-invalid');
      n.removeAttribute('aria-describedby');
    });
  }

  // Returns the {key, msg} pairs for any error key with no FIELD_INPUTS
  // mapping, so the caller can still surface them (in the banner) instead of
  // letting them silently render nowhere.
  function showFieldErrs(errors) {
    clearFieldErrs();
    var unmapped = [];
    Object.keys(errors).forEach(function (key) {
      var msg = errors[key];
      var target = FIELD_INPUTS[key];
      if (!target) { unmapped.push(msg); return; }
      var id = 'rbookFieldErr_' + key;
      target.classList.add('is-err');
      target.setAttribute('aria-invalid', 'true');
      target.setAttribute('aria-describedby', id);
      var p = document.createElement('p');
      p.className = 'rbook__field-err';
      p.id = id;
      p.textContent = msg;
      target.parentNode.insertBefore(p, target.nextSibling);
    });
    return unmapped;
  }

  function fmtLocal(d) {
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }

  // Mirrors restaurant_slots_for() in includes/restaurant.php: `from` inclusive,
  // `to` exclusive. Convenience only — the server re-derives this set.
  function slotsFor(ymd) {
    var d = new Date(ymd + 'T00:00:00');
    // Round-trip through LOCAL Y/M/D (never toISOString, which is UTC and
    // would shift the date for any timezone east of UTC — including this
    // site's own Africa/Nairobi) so an impossible date such as 2026-02-30
    // (silently rolled over by the Date constructor to March 2) is rejected
    // here exactly as restaurant_slots_for() rejects it server-side, instead
    // of quietly returning slots for the rolled-over date. Both sides of the
    // comparison stay in local time, so no timezone conversion happens at all.
    if (isNaN(d) || fmtLocal(d) !== ymd) return [];
    if (HOURS.days.indexOf(d.getDay()) === -1) return [];
    if (!(HOURS.step > 0)) return []; // guard a malformed 0/negative step config
    var f = HOURS.from.split(':'), t = HOURS.to.split(':');
    var start = (+f[0]) * 60 + (+f[1]), end = (+t[0]) * 60 + (+t[1]), out = [];
    for (var m = start; m < end; m += HOURS.step) {
      out.push(('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2));
    }
    return out;
  }

  function renderSlots() {
    timeIn.value = '';
    var list = dateIn.value ? slotsFor(dateIn.value) : [];
    if (!dateIn.value)  { slots.innerHTML = '<p class="rbook__hint">Choose a date to see available times.</p>'; return; }
    if (!list.length)   { slots.innerHTML = '<p class="rbook__hint">We are closed that day — please choose another date.</p>'; return; }
    slots.innerHTML = '';
    list.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'rbook__slot'; b.textContent = t;
      // Plain toggle buttons, not a radiogroup: there is no roving-tabindex /
      // arrow-key navigation here, so role="radio" would announce a keyboard
      // contract this widget doesn't implement. aria-pressed describes what
      // it actually is — a set of independently focusable toggle buttons.
      b.setAttribute('aria-pressed', 'false');
      b.addEventListener('click', function () {
        slots.querySelectorAll('.rbook__slot').forEach(function (o) {
          o.classList.remove('is-on'); o.setAttribute('aria-pressed', 'false');
        });
        b.classList.add('is-on'); b.setAttribute('aria-pressed', 'true');
        timeIn.value = t;
      });
      slots.appendChild(b);
    });
  }

  // datepicker.js fires `change` on the hidden input when a date is picked.
  dateIn.addEventListener('change', renderSlots);

  function showErr(m) { err.textContent = m; err.hidden = false; }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.hidden = true;
    clearFieldErrs();
    var btn = form.querySelector('.rbook__submit');
    if (!dateIn.value) { showErr('Please choose a date.'); return; }
    if (!timeIn.value) { showErr('Please choose a time.'); return; }

    var tokenEl = form.querySelector('[name="cf-turnstile-response"]');
    var body = {
      name:       form.name.value.trim(),
      email:      form.email.value.trim(),
      phone:      form.phone.value.trim(),
      party_size: parseInt(form.party_size.value, 10),
      date:       dateIn.value,
      time:       timeIn.value,
      occasion:   form.occasion.value,
      notes:      form.notes.value.trim(),
      website:    form.website.value,
      'cf-turnstile-response': tokenEl ? tokenEl.value : ''
    };
    if (!body.name || !body.email) { showErr('Please enter your name and a valid email.'); return; }

    btn.disabled = true; btn.textContent = 'Sending…';
    fetch('/api/restaurant-book.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin', body: JSON.stringify(body)
    })
    .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
    .then(function (j) {
      if (j && j.ok) {
        // Built with createElement/textContent rather than innerHTML: j.reference
        // is server data and must never be treated as trusted markup, even
        // though the only writer today (restaurant_make_reference()) uses a
        // restricted alphabet — that guarantee lives three files away from
        // this sink and nothing here should depend on it holding forever.
        var wrap = document.createElement('div');
        wrap.style.textAlign = 'center';
        wrap.style.padding = '1.5rem 0';

        var heading = document.createElement('p');
        heading.style.fontFamily = "'Cormorant Garamond', serif";
        heading.style.fontSize = '1.6rem';
        heading.style.color = '#102F3A';
        heading.style.margin = '0 0 .5rem';
        heading.textContent = 'Thank you';

        var body_ = document.createElement('p');
        body_.style.fontSize = '.95rem';
        body_.style.color = '#5a4a38';
        body_.style.lineHeight = '1.7';
        body_.style.margin = '0';
        body_.appendChild(document.createTextNode('We have your request and will confirm by email within 24 hours.'));
        body_.appendChild(document.createElement('br'));
        body_.appendChild(document.createTextNode('Your reference is '));
        var strong = document.createElement('strong');
        strong.textContent = j.reference || '';
        body_.appendChild(strong);
        body_.appendChild(document.createTextNode('.'));

        wrap.appendChild(heading);
        wrap.appendChild(body_);
        form.innerHTML = '';
        form.appendChild(wrap);
        return;
      }
      if (j && j.errors && typeof j.errors === 'object') {
        var unmapped = showFieldErrs(j.errors);
        // An unmapped key would otherwise only surface if it happened to be
        // first in the object — join any unmapped messages into the banner
        // so a future field added to the endpoint can't fail silently.
        if (unmapped.length) {
          showErr(unmapped.join(' '));
        } else {
          var first = Object.keys(j.errors).map(function (k) { return j.errors[k]; })[0];
          showErr(first || 'Please check the highlighted fields.');
        }
      } else {
        showErr((j && j.error) || 'Something went wrong. Please try again.');
      }
      btn.disabled = false; btn.textContent = 'Request a Table';
      if (window.turnstile) window.turnstile.reset();
    })
    .catch(function () {
      showErr('Network error. Please try again.');
      btn.disabled = false; btn.textContent = 'Request a Table';
      if (window.turnstile) window.turnstile.reset();
    });
  });
})();
</script>
