<?php
/** Enquiry form partial — the booking box for rooms in "Enquiry form" mode (booking-widget.php).
 *  Submitted by the script below to /api/submit-enquiry, like the Live availability widget. */
$room_slug = $room['slug'] ?? '';
$room_name = $room['name'] ?? '';
?>
<form class="room-enquiry-form" id="roomEnquiryForm" method="post" novalidate
      data-room-slug="<?= e($room_slug) ?>"
      data-room-name="<?= e($room_name) ?>">

  <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

  <div class="booking-field">
    <span>Check in</span>
    <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="roomEnq" data-dp-target="enqRoomCheckin" data-dp-placeholder="Check-in date">Check-in date</button>
    <input type="hidden" id="enqRoomCheckin" name="checkin" required>
  </div>
  <div class="booking-field">
    <span>Check out</span>
    <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="roomEnq" data-dp-target="enqRoomCheckout" data-dp-placeholder="Check-out date">Check-out date</button>
    <input type="hidden" id="enqRoomCheckout" name="checkout" required>
  </div>

  <div class="booking-field booking-field--row">
    <span>Adult <small>(18+ years)</small></span>
    <div class="booking-step">
      <button type="button" data-bk="adult" data-dir="-1" aria-label="Decrease adults">&minus;</button>
      <span data-bk-count="adult">1</span>
      <button type="button" data-bk="adult" data-dir="1" aria-label="Increase adults">+</button>
    </div>
    <input type="hidden" name="adults" value="1">
  </div>
  <?php if (empty($bk_hide_children)): ?>
  <div class="booking-field booking-field--row">
    <span>Children <small>+$15</small></span>
    <div class="booking-step">
      <button type="button" data-bk="child" data-dir="-1" aria-label="Decrease children">&minus;</button>
      <span data-bk-count="child">0</span>
      <button type="button" data-bk="child" data-dir="1" aria-label="Increase children">+</button>
    </div>
    <input type="hidden" name="children" value="0">
  </div>
  <?php else: /* adults-only property — drop the stepper, keep the field at 0 so
                 the submit payload is identical either way (same contract as
                 the availability branch in booking-widget.php) */ ?>
  <input type="hidden" name="children" value="0">
  <?php endif; ?>

  <?php if (!empty($upsells)): ?>
  <div class="bk-ups">
    <div class="bk-ups__head">Add to your stay <span>optional</span></div>
    <?php foreach ($upsells as $__fu): $__fpl = upsell_price_label($__fu); ?>
    <label class="bk-up">
      <input type="checkbox" name="upsell[]" value="<?= (int)$__fu['id'] ?>" data-bk-upsell>
      <span class="bk-up__tick" aria-hidden="true"></span>
      <span class="bk-up__body">
        <span class="bk-up__name"><?= e((string)$__fu['name']) ?></span>
        <span class="bk-up__meta">
          <?php if (trim((string)($__fu['duration'] ?? '')) !== ''): ?><span><?= e((string)$__fu['duration']) ?></span><?php endif; ?>
          <?php if ($__fpl !== ''): ?><span class="bk-up__price"><?= e($__fpl) ?></span><?php endif; ?>
        </span>
      </span>
    </label>
    <?php endforeach; ?>
    <p class="bk-ups__note">Nothing is charged now &mdash; we&rsquo;ll confirm availability and pricing by email.</p>
  </div>
  <?php endif; ?>

  <label class="booking-field">
    <span>Your name</span>
    <input type="text" name="name" placeholder="Full name" required>
  </label>
  <label class="booking-field">
    <span>Email</span>
    <input type="email" name="email" placeholder="Your email" required>
  </label>
  <label class="booking-field">
    <span>Phone</span>
    <input type="tel" name="phone" placeholder="Your phone">
  </label>
  <label class="booking-field">
    <span>Message</span>
    <textarea name="message" rows="3" placeholder="Any special requests?"></textarea>
  </label>

  <div class="form-feedback" id="roomEnquiryFeedback" hidden></div>

  <?php if (captcha_site_key()): ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
  <?php endif; ?>

  <button type="submit" class="btn btn--primary booking-card__submit">
    Request to Book <span aria-hidden="true">&rsaquo;</span>
  </button>
</form>

<?php if (empty($GLOBALS['__room_enquiry_js'])): $GLOBALS['__room_enquiry_js'] = true; ?>
<style>
.room-enquiry-form .form-feedback{margin:4px 0 10px;padding:10px 12px;border-radius:8px;font-size:13.5px;background:#fdecea;color:#9b1c1c}
.room-enquiry-form .form-feedback.is-ok{background:#e8f5ee;color:#1f6b45}
</style>
<script>
/* Enquiry-mode booking form (rooms set to "Enquiry form"). Sends the request to
   /api/submit-enquiry — the same endpoint, payload and success message as the
   Live availability widget (js/booking-widget.js) — so it is saved in
   Enquiries and both emails go out. Also drives the adult/child steppers. */
(function () {
  var form = document.getElementById('roomEnquiryForm');
  if (!form || form.dataset.enqBound) return;
  form.dataset.enqBound = '1';
  var fb = document.getElementById('roomEnquiryFeedback');
  var btn = form.querySelector('[type=submit]');
  function msg(text, ok) { fb.hidden = !text; fb.textContent = text || ''; fb.classList.toggle('is-ok', !!ok); }
  function val(n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ''; }

  form.querySelectorAll('[data-bk]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kind = b.getAttribute('data-bk');
      var input = form.querySelector('[name="' + (kind === 'adult' ? 'adults' : 'children') + '"]');
      var span = form.querySelector('[data-bk-count="' + kind + '"]');
      var min = kind === 'adult' ? 1 : 0;
      var n = Math.max(min, Math.min(20, (parseInt(input.value, 10) || min) + parseInt(b.getAttribute('data-dir'), 10)));
      input.value = n; if (span) span.textContent = n;
    });
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msg('');
    if (!val('checkin') || !val('checkout')) return msg('Please choose your check-in and check-out dates.');
    if (val('checkout') <= val('checkin')) return msg('Check-out must be after check-in.');
    if (!val('name')) return msg('Please enter your name.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val('email'))) return msg('Please enter a valid email address.');

    var label = btn.innerHTML;
    btn.disabled = true; btn.textContent = 'Sending…';
    var data = {
      room_slug: form.getAttribute('data-room-slug') || '',
      checkin: val('checkin'), checkout: val('checkout'),
      name: val('name'), email: val('email'), phone: val('phone'), message: val('message'),
      adults: parseInt(val('adults'), 10) || 1, children: parseInt(val('children'), 10) || 0,
      website: val('website'),
      'cf-turnstile-response': val('cf-turnstile-response'),
      upsell: Array.prototype.map.call(form.querySelectorAll('[data-bk-upsell]:checked'), function (c) { return parseInt(c.value, 10); }),
      embed_from: window.TS_EMBED_FROM || undefined
    };
    try {
      var res = await fetch('/api/submit-enquiry', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
      var json = await res.json();
      if (json.ok) {
        if (window.showSuccessModal) {
          if (json.mode === 'hold') window.showSuccessModal('Your dates are held!', 'Good news — those dates are available. We\'ve put a 24-hour hold on your booking and will confirm by email shortly.', true);
          else window.showSuccessModal('Request received', 'Thank you — we\'ve received your booking request. Our reservations team will reply by email shortly. Nothing is charged now.', false);
        } else {
          msg('Thank you — we\'ve received your request and will reply by email shortly.', true);
        }
        return;
      }
      if (json.errors) msg(Object.values(json.errors).filter(Boolean).join(' '));
      else msg(json.error || 'Something went wrong. Please try again.');
    } catch (err) {
      msg('Network error. Check your connection and try again.');
    } finally {
      btn.disabled = false; btn.innerHTML = label;
    }
  });
})();
</script>
<?php endif; ?>
