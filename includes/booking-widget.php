<?php
/**
 * Tribal Sand booking widget.
 *
 * Usage on a property/room page (before including this file):
 *   $booking_slug = 'maya-kobe-prestige';   // must match a rooms.slug
 *   include __DIR__ . '/includes/booking-widget.php';
 *
 * Form mode is read from the room record (rooms.form_mode):
 *   'availability' → live calendar + blocked dates + 24h hold on submit
 *   'enquiry'      → simple date text inputs + enquiry email only
 *   NULL           → falls back to global setting('form_mode'), default 'enquiry'
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/turnstile.php';

$booking_slug = $booking_slug ?? '';

try {
    $__room = $booking_slug ? fetch_room_by_slug($booking_slug) : false;
} catch (Throwable $e) {
    $__room = false;
}

if (!$__room) { return; }

// Determine form mode — per-room overrides global setting
try {
    $__form_mode = !empty($__room['form_mode'])
        ? $__room['form_mode']
        : setting('form_mode', 'enquiry');
} catch (Throwable $e) {
    $__form_mode = 'enquiry';
}

// Safety: availability mode requires at least one unit seeded.
// If none exist, fall back to enquiry so the calendar doesn't
// accept dates then fail every submission silently.
if ($__form_mode === 'availability') {
    try {
        $__units = fetch_units_by_room((int)$__room['id']);
        if (count($__units) === 0) $__form_mode = 'enquiry';
    } catch (Throwable $e) {
        $__form_mode = 'enquiry';
    }
}

// Booking-flow add-ons offered by this room's property (empty unless the
// property's master switch is on and activities are placed on the enquiry
// surface). Resolved for both form modes below.
require_once __DIR__ . '/upsells.php';
$__upsells = fetch_upsell_items(((int)($__room['venue_id'] ?? 0)) ?: null, 'enquiry');

$room_slug  = $__room['slug'];
$room_name  = $__room['name'];
$room_price = (float)($__room['price_amount'] ?? 0);
$room_curr  = $__room['price_currency'] ?? 'USD';

// ── ENQUIRY MODE ──────────────────────────────────────────────────────────────
if ($__form_mode !== 'availability') {
    $room = $__room; // form-enquiry.php expects $room
    $upsells = $__upsells;
    include __DIR__ . '/form-enquiry.php';
    return;
}
?>

<?php // ── AVAILABILITY MODE ─────────────────────────────────────────────────── ?>
<div class="bk-avail" id="availCalendar"
     data-slug="<?= e($room_slug) ?>"
     data-price="<?= e($room_price) ?>"
     data-currency="<?= e($room_curr) ?>">

  <div class="bk-room-label" id="bkRoomLabel"><?= e($room_name) ?></div>

  <form id="availForm" class="bk-form" novalidate data-room-slug="<?= e($room_slug) ?>">
    <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
    <input type="hidden" name="checkin"  id="availCheckin">
    <input type="hidden" name="checkout" id="availCheckout">
    <input type="hidden" name="adults"   id="availAdults"   value="2">
    <input type="hidden" name="children" id="availChildren" value="0">

    <!-- Check-in / Check-out triggers -->
    <div class="bk-dates-row">
      <button type="button" class="bk-date-trigger" id="bkCiBtn" aria-haspopup="dialog" aria-expanded="false">
        <span class="bk-date-trigger__label">Check-in</span>
        <span class="bk-date-trigger__value is-empty" id="bkCiValue">Add date</span>
      </button>
      <button type="button" class="bk-date-trigger" id="bkCoBtn" aria-haspopup="dialog" aria-expanded="false">
        <span class="bk-date-trigger__label">Check-out</span>
        <span class="bk-date-trigger__value is-empty" id="bkCoValue">Add date</span>
      </button>
    </div>

    <!-- Date picker popover — two months side by side -->
    <div class="bk-pop" id="bkDatesPop" role="dialog" aria-label="Select dates" hidden>
      <div class="bk-cal-months">
        <!-- Left month -->
        <div class="bk-cal bk-cal--left">
          <div class="bk-cal__head">
            <button type="button" class="bk-cal__nav" id="bkPrevMonth" aria-label="Previous month"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></button>
            <span class="bk-cal__title" id="bkMonthLabel"></span>
            <button type="button" class="bk-cal__nav bk-cal__nav--hidden" aria-hidden="true" tabindex="-1"></button>
          </div>
          <div class="bk-cal__dow"><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>
          <div class="bk-cal__grid" id="bkCalGrid"></div>
        </div>
        <!-- Right month (desktop only) -->
        <div class="bk-cal bk-cal--right">
          <div class="bk-cal__head">
            <button type="button" class="bk-cal__nav bk-cal__nav--hidden" aria-hidden="true" tabindex="-1"></button>
            <span class="bk-cal__title" id="bkMonthLabel2"></span>
            <button type="button" class="bk-cal__nav" id="bkNextMonth" aria-label="Next month"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
          </div>
          <div class="bk-cal__dow"><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>
          <div class="bk-cal__grid" id="bkCalGrid2"></div>
        </div>
      </div>
      <div class="bk-pop__footer">
        <span class="bk-pop__hint" id="bkDatesHint">Select your check-in date</span>
        <button type="button" class="bk-pop__cta" id="bkDatesDone">Done</button>
      </div>
    </div>

    <!-- Guests -->
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
      <div class="bk-pop__footer bk-pop__footer--end">
        <button type="button" class="bk-pop__cta" id="bkGuestsDone">Done</button>
      </div>
    </div>

    <!-- Price summary -->
    <div class="bk-total" id="bkTotal" hidden>
      <div class="bk-total__row">
        <span class="bk-total__label" id="bkTotalLabel">— nights</span>
        <span class="bk-total__price" id="bkTotalPrice">—</span>
      </div>
      <div class="bk-total__hint">Final price confirmed by email</div>
    </div>

    <?php if ($__upsells): ?>
    <!-- Optional add-ons -->
    <div class="bk-ups">
      <div class="bk-ups__head">Add to your stay <span>optional</span></div>
      <?php foreach ($__upsells as $__bu): $__bpl = upsell_price_label($__bu); ?>
      <label class="bk-up">
        <input type="checkbox" name="upsell[]" value="<?= (int)$__bu['id'] ?>" data-bk-upsell>
        <span class="bk-up__tick" aria-hidden="true"></span>
        <span class="bk-up__body">
          <span class="bk-up__name"><?= e((string)$__bu['name']) ?></span>
          <span class="bk-up__meta">
            <?php if (trim((string)($__bu['duration'] ?? '')) !== ''): ?><span><?= e((string)$__bu['duration']) ?></span><?php endif; ?>
            <?php if ($__bpl !== ''): ?><span class="bk-up__price"><?= e($__bpl) ?></span><?php endif; ?>
          </span>
        </span>
      </label>
      <?php endforeach; ?>
      <p class="bk-ups__note">Nothing is charged now &mdash; we&rsquo;ll confirm availability and pricing by email.</p>
    </div>
    <?php endif; ?>

    <!-- Guest details -->
    <div class="bk-fields">
      <label class="bk-field"><span>Your name</span><input type="text" name="name" placeholder="Full name" required></label>
      <label class="bk-field"><span>Email</span><input type="email" name="email" placeholder="you@example.com" required></label>
      <label class="bk-field"><span>Phone <small>(optional)</small></span><input type="tel" name="phone" placeholder="+254 …"></label>
      <label class="bk-field"><span>Message <small>(optional)</small></span><textarea name="message" rows="2" placeholder="Special requests, arrival time, etc."></textarea></label>
    </div>

    <div class="bk-feedback" id="availFeedback" hidden></div>
    <?php if (captcha_site_key()): ?>
    <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
    <?php endif; ?>
    <button type="submit" class="bk-submit">
      <span class="bk-submit__label">Check availability</span>
      <svg class="bk-submit__arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
    </button>
    <p class="bk-hold-note">Dates are held for 24 hours pending confirmation</p>
  </form>
</div>
