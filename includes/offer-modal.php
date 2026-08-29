<?php
/**
 * Shared "Request this offer" enquiry modal (Tribal Sand).
 * Rendered once by includes/promo-offers.php. The offer title is injected per
 * trigger via JS (buttons carry data-offer-request + data-offer-title). The form
 * posts to /api/submit-contact.php with a "subject" of "Offer enquiry: <title>",
 * reusing the existing contact pipeline (Turnstile fail-closed + IP rate-limit +
 * honeypot server-side). Wiring is inline at the bottom of promo-offers.php.
 */
?>
<div class="offer-modal" id="offerModal" hidden role="dialog" aria-modal="true" aria-labelledby="offerModalTitle">
  <div class="offer-modal__overlay" data-offer-close></div>
  <div class="offer-modal__card">
    <button type="button" class="offer-modal__close" data-offer-close aria-label="Close">&times;</button>
    <div class="offer-modal__eyebrow">Enquiry</div>
    <h3 class="offer-modal__title" id="offerModalTitle">Request this offer</h3>
    <p class="offer-modal__offer" id="offerModalName"></p>
    <form class="offer-form" id="offerEnquiryForm" novalidate>
      <input type="text" name="website" class="offer-form__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <input type="hidden" name="subject" id="offerSubject">
      <div class="offer-form__row">
        <label class="offer-form__field"><span>Full name</span><input type="text" name="name" placeholder="Your name" required></label>
        <label class="offer-form__field"><span>Email</span><input type="email" name="email" placeholder="you@email.com" required></label>
      </div>
      <label class="offer-form__field"><span>Phone <em>(optional)</em></span><input type="tel" name="phone" placeholder="Your phone"></label>
      <label class="offer-form__field"><span>Message</span><textarea name="message" rows="4" placeholder="Tell us your dates or questions" required></textarea></label>
      <div class="offer-form__feedback" id="offerFeedback" hidden></div>
      <?php if (captcha_site_key()): ?>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:2px 0"></div>
      <?php endif; ?>
      <button type="submit" class="btn-primary offer-form__submit">Send request <span aria-hidden="true">&rsaquo;</span></button>
    </form>
  </div>
</div>
