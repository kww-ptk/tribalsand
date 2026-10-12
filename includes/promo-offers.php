<?php
/**
 * Homepage Offers & Specials strip (Tribal Sand). Include once on index.php.
 *
 * Renders published, in-window offers (includes/offers.php). 3+ offers → an
 * gently auto-scrolling carousel with ‹ › arrows (TRI-77), swipeable, pausing on
 * hover / touch / an arrow press; 1–2 → a static centered row. Each card is a <button>
 * that opens the shared "Request this offer" modal (includes/offer-modal.php).
 * Pre-migration-safe: fetch_published_offers() returns [] before the migration,
 * so this whole block renders nothing.
 */
require_once __DIR__ . '/offers.php';

$__offers = [];
try { $__offers = fetch_published_offers(); } catch (Throwable $e) { $__offers = []; }
if (empty($__offers)) return;

$__offerMarquee = count($__offers) > 2;
?>
<!-- ═══ OFFERS & SPECIALS ═══ -->
<section class="offers-strip<?= $__offerMarquee ? ' offers-strip--marquee' : '' ?>" aria-label="Current offers and specials">
  <div class="offers-strip__head">
    <div class="offers-strip__eyebrow">Offers &amp; Specials</div>
    <h2 class="offers-strip__title">This season at Tribal Sand</h2>
    <p class="offers-strip__lead">Stay deals, dining treats and curated experiences &mdash; updated by our team.</p>
  </div>
  <div class="offers-carousel">
  <?php if ($__offerMarquee): ?>
  <button type="button" class="offers-nav offers-nav--prev" data-offers-nav="-1" aria-label="Previous offers">
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
  </button>
  <button type="button" class="offers-nav offers-nav--next" data-offers-nav="1" aria-label="Next offers">
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
  </button>
  <?php endif; ?>
  <div class="offers-marquee">
    <div class="offers-marquee__track"<?= $__offerMarquee ? ' style="--n:' . count($__offers) . '"' : '' ?>>
      <?php
        // Duplicate the list once in marquee mode so the scroll loops seamlessly.
        $__list = $__offerMarquee ? array_merge($__offers, $__offers) : $__offers;
        foreach ($__list as $__i => $o):
          $__dupe  = $__offerMarquee && $__i >= count($__offers);
          $__img   = offer_img_url($o['image_key'] ?? '');
          $__title = (string) ($o['title'] ?? '');
          $__reqTitle = $__title . (!empty($o['subtitle']) ? ' — ' . $o['subtitle'] : '');
      ?>
      <button type="button" class="offer-slide" data-offer-request
              data-offer-title="<?= e($__reqTitle) ?>"<?= $__dupe ? ' aria-hidden="true" tabindex="-1"' : '' ?>>
        <span class="offer-slide__media">
          <?php if ($__img): ?><img src="<?= e($__img) ?>" alt="<?= e($__title) ?>" loading="lazy"><?php endif; ?>
          <?php if (!empty($o['badge'])): ?><span class="offer-slide__badge"><?= e($o['badge']) ?></span><?php endif; ?>
          <span class="offer-slide__cat"><?= e(offer_category_label($o['category'] ?? 'special')) ?></span>
        </span>
        <span class="offer-slide__body">
          <span class="offer-slide__title"><?= e($__title) ?></span>
          <?php if (!empty($o['subtitle'])): ?><span class="offer-slide__sub"><?= e($o['subtitle']) ?></span><?php endif; ?>
          <span class="offer-slide__cta">Request this offer <span aria-hidden="true">&rsaquo;</span></span>
        </span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
  </div>
</section>
<?php if ($__offerMarquee): ?>
<script>
// Offers carousel: a slow auto-scroll over repeated copies of the offers (it
// wraps after one copy, so it never runs out), ‹ › arrows that move one card, swipe /
// trackpad scrolling. Pauses on hover, focus, touch and for a few seconds after
// an arrow; no auto-scroll with reduced motion or a hidden tab.
(function () {
  var box = document.querySelector('.offers-strip--marquee .offers-marquee');
  if (!box || box.dataset.ready) return;
  box.dataset.ready = '1';
  var track = box.querySelector('.offers-marquee__track');
  var n = parseInt(getComputedStyle(track).getPropertyValue('--n'), 10) || (track.children.length / 2);
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var SPEED = 32;                       // px per second
  var hover = false, holdUntil = 0, pos = 0, last = 0;
  function slides() { return track.querySelectorAll('.offer-slide'); }
  // Width of ONE copy of the offers (the loop length).
  function copyW() { var s = slides(); return s[n] ? s[n].offsetLeft - s[0].offsetLeft : track.scrollWidth / 2; }
  function step() { var s = slides(); return s[1] ? s[1].offsetLeft - s[0].offsetLeft : 340; }
  // The row must be at least one copy + a screen + a card long, or it would stop
  // at its end (3 offers on a wide screen). Add copies as needed.
  function fill() {
    var guard = 0;
    while (track.scrollWidth < copyW() + box.clientWidth + step() && guard++ < 8) {
      for (var i = 0; i < n; i++) {
        var c = slides()[i].cloneNode(true);
        c.setAttribute('aria-hidden', 'true'); c.setAttribute('tabindex', '-1');
        track.appendChild(c);
      }
    }
  }
  function wrap() { var w = copyW(); if (w > 0 && box.scrollLeft >= w) box.scrollLeft -= w; pos = box.scrollLeft; }
  function tick(t) {
    var dt = last ? Math.min(64, t - last) / 1000 : 0; last = t;
    if (!still && !hover && !document.hidden && t > holdUntil) {
      pos += SPEED * dt;
      var w = copyW();
      if (w > 0 && pos >= w) pos -= w;
      box.scrollLeft = pos;
    } else {
      pos = box.scrollLeft;
    }
    requestAnimationFrame(tick);
  }
  fill();
  window.addEventListener('resize', fill);
  box.addEventListener('mouseenter', function () { hover = true; });
  box.addEventListener('mouseleave', function () { hover = false; });
  box.addEventListener('focusin', function () { hover = true; });
  box.addEventListener('focusout', function () { hover = false; });
  box.addEventListener('touchstart', function () { holdUntil = performance.now() + 6000; }, { passive: true });
  box.addEventListener('wheel', function () { holdUntil = performance.now() + 6000; }, { passive: true });
  box.addEventListener('scrollend', wrap);   // after a swipe, step back into the first copy (same picture)
  document.querySelectorAll('[data-offers-nav]').forEach(function (b) {
    b.addEventListener('click', function () {
      var dir = parseInt(b.getAttribute('data-offers-nav'), 10), w = copyW();
      holdUntil = performance.now() + 6000;
      // Shift by one copy first (an identical picture) so the glide never hits an end.
      if (dir > 0 && box.scrollLeft >= w) box.scrollLeft -= w;
      if (dir < 0 && box.scrollLeft - step() < 0) box.scrollLeft += w;
      box.scrollTo({ left: box.scrollLeft + dir * step(), behavior: still ? 'auto' : 'smooth' });
    });
  });
  requestAnimationFrame(tick);
})()
</script>
<?php endif; ?>
<?php include __DIR__ . '/offer-modal.php'; ?>
<script>
(function () {
  var form = document.getElementById('offerEnquiryForm');
  if (!form) return;
  var modal    = document.getElementById('offerModal');
  var nameLine = document.getElementById('offerModalName');
  var subject  = document.getElementById('offerSubject');
  var feedback = document.getElementById('offerFeedback');
  var msgField = form.querySelector('[name=message]');

  function open(title) {
    subject.value = 'Offer enquiry: ' + title;
    nameLine.textContent = title;
    if (msgField && !msgField.value.trim()) {
      msgField.value = 'Hi, I\'m interested in the offer "' + title + '". Please send me more information.';
    }
    feedback.hidden = true; feedback.textContent = '';
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    var first = form.querySelector('[name=name]');
    if (first) first.focus();
  }
  function close() { modal.hidden = true; document.body.style.overflow = ''; }

  document.querySelectorAll('[data-offer-request]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      open(btn.getAttribute('data-offer-title') || 'this offer');
    });
  });
  modal.querySelectorAll('[data-offer-close]').forEach(function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) close();
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('[type=submit]');
    var fields = {};
    new FormData(form).forEach(function (v, k) { fields[k] = v; });
    // Turnstile token (widget writes a hidden input named cf-turnstile-response)
    var tok = form.querySelector('[name="cf-turnstile-response"]');
    if (tok) fields['cf-turnstile-response'] = tok.value;

    feedback.hidden = true;
    if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Sending…'; }

    fetch('/api/submit-contact.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(fields)
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || 'Send request'; }
        if (res.ok && res.d && res.d.ok) {
          close();
          form.reset();
          if (typeof window.showSuccessModal === 'function') {
            window.showSuccessModal('Request received',
              'Thank you! Our team will contact you shortly about this offer.');
          }
        } else {
          var msg = (res.d && (res.d.error || (res.d.errors && Object.values(res.d.errors)[0]))) ||
                    'Something went wrong. Please try again.';
          feedback.textContent = msg; feedback.hidden = false;
          if (window.turnstile) { try { window.turnstile.reset(); } catch (e) {} }
        }
      }).catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || 'Send request'; }
        feedback.textContent = 'Network error. Please try again.'; feedback.hidden = false;
      });
  });
})();
</script>
