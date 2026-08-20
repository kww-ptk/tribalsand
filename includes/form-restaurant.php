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

$__rSlug  = 'zuri';
$__rHours = restaurant_hours($__rSlug);
?>
<form class="rbook" id="rbookForm" novalidate>
  <input type="text" name="website" class="rbook__hp" tabindex="-1" autocomplete="off" aria-hidden="true">

  <div class="rbook__row">
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookDateBtn">Date</label>
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
    <div class="rbook__slots" id="rbookSlots" role="radiogroup" aria-label="Available times">
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

  <p class="rbook__err" id="rbookErr" hidden></p>
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

  // Field-level error hookup: label -> input element, so a per-field message
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

  function clearFieldErrs() {
    form.querySelectorAll('.rbook__field-err').forEach(function (n) { n.remove(); });
    form.querySelectorAll('.rbook__input.is-err').forEach(function (n) { n.classList.remove('is-err'); });
  }

  function showFieldErrs(errors) {
    clearFieldErrs();
    Object.keys(errors).forEach(function (key) {
      var target = FIELD_INPUTS[key];
      if (!target) return;
      if (target.classList) target.classList.add('is-err');
      var p = document.createElement('p');
      p.className = 'rbook__field-err';
      p.textContent = errors[key];
      target.parentNode.insertBefore(p, target.nextSibling);
    });
  }

  // Mirrors restaurant_slots_for() in includes/restaurant.php: `from` inclusive,
  // `to` exclusive. Convenience only — the server re-derives this set.
  function slotsFor(ymd) {
    var d = new Date(ymd + 'T00:00:00');
    if (isNaN(d) || HOURS.days.indexOf(d.getDay()) === -1) return [];
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
      b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', 'false');
      b.addEventListener('click', function () {
        slots.querySelectorAll('.rbook__slot').forEach(function (o) {
          o.classList.remove('is-on'); o.setAttribute('aria-checked', 'false');
        });
        b.classList.add('is-on'); b.setAttribute('aria-checked', 'true');
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
        form.innerHTML = '<div style="text-align:center;padding:1.5rem 0">'
          + '<p style="font-family:Cormorant Garamond,serif;font-size:1.6rem;color:#102F3A;margin:0 0 .5rem">Thank you</p>'
          + '<p style="font-size:.95rem;color:#5a4a38;line-height:1.7;margin:0">We have your request and will confirm by email within 24 hours.<br>Your reference is <strong>' + (j.reference || '') + '</strong>.</p>'
          + '</div>';
        return;
      }
      if (j && j.errors && typeof j.errors === 'object') {
        showFieldErrs(j.errors);
        var first = Object.keys(j.errors).map(function (k) { return j.errors[k]; })[0];
        showErr(first || 'Please check the highlighted fields.');
      } else {
        showErr((j && j.error) || 'Something went wrong. Please try again.');
      }
      btn.disabled = false; btn.textContent = 'Request a Table';
      if (window.turnstile) window.turnstile.reset();
    })
    .catch(function () {
      showErr('Network error. Please try again.');
      btn.disabled = false; btn.textContent = 'Request a Table';
    });
  });
})();
</script>
