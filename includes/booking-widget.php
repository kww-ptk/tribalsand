<?php
/**
 * Tribal Sand booking widget.
 * Usage on a property/room page (before including this file):
 *   $booking_slug = 'zuri';            // must match a rooms.slug
 *   include __DIR__ . '/includes/booking-widget.php';
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/turnstile.php';

$booking_slug = $booking_slug ?? '';
$__room = $booking_slug ? fetch_room_by_slug($booking_slug) : false;
if (!$__room) { return; } // no matching room → render nothing (page unaffected)

$room_slug  = $__room['slug'];
$room_name  = $__room['name'];
$room_price = (float)($__room['price_amount'] ?? 0);
$room_curr  = $__room['price_currency'] ?? 'USD';
?>
<div class="bk-avail" id="availCalendar"
     data-slug="<?= e($room_slug) ?>"
     data-price="<?= e($room_price) ?>"
     data-currency="<?= e($room_curr) ?>">
  <form id="availForm" class="bk-form" novalidate data-room-slug="<?= e($room_slug) ?>">
    <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
    <input type="hidden" name="checkin"  id="availCheckin">
    <input type="hidden" name="checkout" id="availCheckout">
    <input type="hidden" name="adults"   id="availAdults"   value="2">
    <input type="hidden" name="children" id="availChildren" value="0">

    <button type="button" class="bk-pill" id="bkDatesBtn" aria-haspopup="dialog" aria-expanded="false">
      <span class="bk-pill__label">Dates</span>
      <span class="bk-pill__value" id="bkDatesValue">Add dates</span>
      <svg class="bk-pill__chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
    </button>
    <div class="bk-pop" id="bkDatesPop" role="dialog" aria-label="Select dates" hidden>
      <div class="bk-cal">
        <div class="bk-cal__head">
          <button type="button" class="bk-cal__nav" id="bkPrevMonth" aria-label="Previous month">&#8249;</button>
          <span class="bk-cal__title" id="bkMonthLabel"></span>
          <button type="button" class="bk-cal__nav" id="bkNextMonth" aria-label="Next month">&#8250;</button>
        </div>
        <div class="bk-cal__dow"><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>
        <div class="bk-cal__grid" id="bkCalGrid"></div>
      </div>
      <div class="bk-pop__footer">
        <span class="bk-pop__hint" id="bkDatesHint">Select your check-in date</span>
        <button type="button" class="bk-pop__cta" id="bkDatesDone">Done</button>
      </div>
    </div>

    <button type="button" class="bk-pill" id="bkGuestsBtn" aria-haspopup="dialog" aria-expanded="false">
      <span class="bk-pill__label">Guests</span>
      <span class="bk-pill__value" id="bkGuestsValue">2 adults</span>
      <svg class="bk-pill__chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
    </button>
    <div class="bk-pop bk-pop--narrow" id="bkGuestsPop" role="dialog" aria-label="Select guests" hidden>
      <div class="bk-stepper-row">
        <div class="bk-stepper-row__label"><strong>Adults</strong><small>Age 18+</small></div>
        <div class="bk-stepper">
          <button type="button" data-bk="adult" data-dir="-1" aria-label="Decrease adults">&minus;</button>
          <span data-bk-count="adult">2</span>
          <button type="button" data-bk="adult" data-dir="1" aria-label="Increase adults">+</button>
        </div>
      </div>
      <div class="bk-stepper-row">
        <div class="bk-stepper-row__label"><strong>Children</strong><small>Age 0–17</small></div>
        <div class="bk-stepper">
          <button type="button" data-bk="child" data-dir="-1" aria-label="Decrease children">&minus;</button>
          <span data-bk-count="child">0</span>
          <button type="button" data-bk="child" data-dir="1" aria-label="Increase children">+</button>
        </div>
      </div>
      <div class="bk-pop__footer bk-pop__footer--end"><button type="button" class="bk-pop__cta" id="bkGuestsDone">Done</button></div>
    </div>

    <div class="bk-total" id="bkTotal" hidden>
      <div class="bk-total__row"><span class="bk-total__label" id="bkTotalLabel">— nights</span><span class="bk-total__price" id="bkTotalPrice">—</span></div>
      <div class="bk-total__hint">Final price confirmed by email</div>
    </div>

    <div class="bk-fields">
      <label class="bk-field"><span>Your name</span><input type="text" name="name" placeholder="Full name" required></label>
      <label class="bk-field"><span>Email</span><input type="email" name="email" placeholder="you@example.com" required></label>
      <label class="bk-field"><span>Phone <small>(optional)</small></span><input type="tel" name="phone" placeholder="+254 …"></label>
      <label class="bk-field"><span>Message <small>(optional)</small></span><textarea name="message" rows="2" placeholder="Special requests, arrival time, etc."></textarea></label>
    </div>

    <div class="bk-feedback" id="availFeedback" hidden></div>
    <?php if (captcha_site_key()): ?><div class="h-captcha" data-sitekey="<?= e(captcha_site_key()) ?>"></div><?php endif; ?>
    <button type="submit" class="bk-submit"><span class="bk-submit__label">Check availability</span></button>
    <p class="bk-hold-note">Dates are held for 24 hours pending confirmation</p>
  </form>
</div>
