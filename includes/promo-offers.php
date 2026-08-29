<?php
/**
 * Homepage Offers & Specials strip (Tribal Sand). Include once on index.php.
 *
 * Renders published, in-window offers (includes/offers.php). 3+ offers → an
 * auto-scrolling marquee; 1–2 → a static centered row. Each card is a <button>
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
</section>
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
