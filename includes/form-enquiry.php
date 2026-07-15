<?php
/** Enquiry form partial — included inside room.php booking card */
$room_slug = $room['slug'] ?? '';
$room_name = $room['name'] ?? '';
?>
<form class="room-enquiry-form" id="roomEnquiryForm" novalidate
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
  <div class="booking-field booking-field--row">
    <span>Children <small>+$15</small></span>
    <div class="booking-step">
      <button type="button" data-bk="child" data-dir="-1" aria-label="Decrease children">&minus;</button>
      <span data-bk-count="child">0</span>
      <button type="button" data-bk="child" data-dir="1" aria-label="Increase children">+</button>
    </div>
    <input type="hidden" name="children" value="0">
  </div>

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
