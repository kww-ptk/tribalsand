<?php /** Rendered inside booking.php for actionable holds. Expects $hold, $ref, $tours. */ ?>
<div class="bk-card" style="margin-bottom:20px">
  <div class="bk-card__body">
    <h3 style="margin:0 0 6px;font-family:'Cormorant Garamond',serif;font-weight:500">Manage your trip</h3>
    <p style="margin:0;color:#6b7280;font-size:14px">Request changes or add tours, transfers and itinerary items — our team confirms availability and pricing by email.</p>
    <!-- change form (Task 5) -->
    <form data-bm action="/api/booking-change.php" class="bm-form" style="margin-top:16px">
      <h4 style="margin:0 0 8px;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280">Request a change</h4>
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <label style="display:block;margin:0 0 10px;font-size:13px">New check-in (optional)<input type="date" name="check_in" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></label>
      <label style="display:block;margin:0 0 10px;font-size:13px">New check-out (optional)<input type="date" name="check_out" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></label>
      <label style="display:block;margin:0 0 10px;font-size:13px">Guests (optional)<input type="number" name="guests" min="1" max="30" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></label>
      <label style="display:block;margin:0 0 10px;font-size:13px">Notes<textarea name="note" rows="3" placeholder="Tell us what you’d like to change" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></textarea></label>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
      <button type="submit" class="bk-btn-cancel" style="background:var(--teal-d,#102F3A)">Send change request</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>
    <!-- add-on forms (Task 6) will be added here -->
  </div>
</div>
