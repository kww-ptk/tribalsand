<?php /** Rendered inside booking.php for actionable holds. Expects $hold, $ref, $tours. */ ?>
<style>
.bm-submit { background:#102F3A; color:#fff; border:0; padding:12px 20px; border-radius:8px; font-weight:600; font-size:14px; cursor:pointer; }
.bm-submit:hover { background:#0b2229; }
.bm-submit:disabled { opacity:.6; cursor:default; }
.bm-pill { font-size:12px; padding:2px 9px; border-radius:999px; text-transform:capitalize; margin-left:auto; white-space:nowrap; }
.bm-pill-requested { background:#fff7e6; color:#8a5a00; }
.bm-pill-confirmed { background:#e6f6ec; color:#146c37; }
.bm-pill-declined, .bm-pill-cancelled { background:#fbe6e6; color:#a12; }
.bm-pill-handled { background:#e6eefb; color:#1a4a9c; }
</style>
<div class="bk-card" style="margin-bottom:20px">
  <div class="bk-card__body">
    <h3 style="margin:0 0 6px;font-family:'Cormorant Garamond',serif;font-weight:500">Manage your trip</h3>
    <p style="margin:0;color:#6b7280;font-size:14px">Request changes or add tours, transfers and itinerary items — our team confirms availability and pricing by email.</p>
    <?php
      $bm_addons  = fetch_booking_addons((int)$hold['id']);
      $bm_changes = fetch_booking_change_requests((int)$hold['id']);
    ?>
    <?php if ($bm_addons || $bm_changes): ?>
    <div style="margin-top:16px;border-top:1px solid #f0ede7;padding-top:14px">
      <h4 style="margin:0 0 8px;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280">Your requests</h4>
      <ul style="list-style:none;margin:0;padding:0">
        <?php foreach ($bm_addons as $a): ?>
          <li style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:7px 0;font-size:14px;border-bottom:1px solid #f5f2ec">
            <strong><?= e(ucfirst($a['kind'])) ?></strong>
            <span style="color:#555"><?= e(trim(($a['tour_name'] ?? '') . ' ' . $a['details'])) ?></span>
            <span class="bm-pill bm-pill-<?= e($a['status']) ?>"><?= e($a['status']) ?></span>
          </li>
        <?php endforeach; ?>
        <?php foreach ($bm_changes as $c): ?>
          <li style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:7px 0;font-size:14px;border-bottom:1px solid #f5f2ec">
            <strong>Change</strong>
            <span style="color:#555">
              <?php
                $parts = [];
                if ($c['requested_check_in'] || $c['requested_check_out'])
                    $parts[] = trim((string)($c['requested_check_in'] ?? '') . ' → ' . (string)($c['requested_check_out'] ?? ''), ' →');
                if ($c['requested_guests']) $parts[] = (int)$c['requested_guests'] . ' guests';
                if (($c['note'] ?? '') !== '') $parts[] = $c['note'];
                echo e(implode(' · ', $parts));
              ?>
            </span>
            <span class="bm-pill bm-pill-<?= e($c['status']) ?>"><?= e($c['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <!-- change form (Task 5) -->
    <form data-bm action="/api/booking-change.php" class="bm-form" style="margin-top:16px">
      <h4 style="margin:0 0 8px;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280">Request a change</h4>
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <label style="display:block;margin:0 0 10px;font-size:13px">New check-in (optional)<input type="date" name="check_in" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></label>
      <label style="display:block;margin:0 0 10px;font-size:13px">New check-out (optional)<input type="date" name="check_out" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></label>
      <label style="display:block;margin:0 0 10px;font-size:13px">Guests (optional)<input type="number" name="guests" min="1" max="30" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></label>
      <label style="display:block;margin:0 0 10px;font-size:13px">Notes<textarea name="note" rows="3" placeholder="Tell us what you’d like to change" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></textarea></label>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
      <button type="submit" class="bm-submit">Send change request</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>
    <?php $__tours = (isset($tours) && is_array($tours)) ? $tours : []; ?>
    <?php if ($__tours): ?>
    <form data-bm action="/api/booking-addon.php" class="bm-form" style="margin-top:20px;border-top:1px solid #f0ede7;padding-top:16px">
      <h4 style="margin:0 0 8px;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280">Add a tour</h4>
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <input type="hidden" name="kind" value="tour">
      <label style="display:block;margin:0 0 10px;font-size:13px">Choose a tour
        <select name="tour_slug" required style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px">
          <option value="">— select —</option>
          <?php foreach ($__tours as $t): ?>
            <option value="<?= e($t['slug']) ?>"><?= e($t['name']) ?><?= !empty($t['tag_label']) ? ' · ' . e($t['tag_label']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:block;margin:0 0 10px;font-size:13px">Notes (optional)<textarea name="details" rows="2" placeholder="Preferred date, group size…" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></textarea></label>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
      <button type="submit" class="bm-submit">Request this tour</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>
    <?php endif; ?>

    <form data-bm action="/api/booking-addon.php" class="bm-form" style="margin-top:20px;border-top:1px solid #f0ede7;padding-top:16px">
      <h4 style="margin:0 0 8px;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280">Add a transfer</h4>
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <input type="hidden" name="kind" value="transfer">
      <label style="display:block;margin:0 0 10px;font-size:13px">Transfer
        <select name="transfer" required style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px">
          <option value="">— select —</option>
          <?php foreach (TRANSFER_OPTIONS as $k => $label): ?>
            <option value="<?= e($k) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:block;margin:0 0 10px;font-size:13px">Details (flight no., time, pickup…)<textarea name="details" rows="2" style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></textarea></label>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
      <button type="submit" class="bm-submit">Request transfer</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>

    <form data-bm action="/api/booking-addon.php" class="bm-form" style="margin-top:20px;border-top:1px solid #f0ede7;padding-top:16px">
      <h4 style="margin:0 0 8px;font-weight:700;font-size:13px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280">Itinerary request</h4>
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <input type="hidden" name="kind" value="itinerary">
      <label style="display:block;margin:0 0 10px;font-size:13px">What would you like to add to your trip?<textarea name="details" rows="3" required style="display:block;width:100%;padding:9px;margin-top:3px;border:1px solid #d1d5db;border-radius:6px"></textarea></label>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
      <button type="submit" class="bm-submit">Send itinerary request</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>
  </div>
</div>
