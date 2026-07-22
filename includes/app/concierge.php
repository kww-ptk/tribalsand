<?php /** Concierge view. Expects $hold, $ref, $status. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__addons = fetch_booking_addons((int)$hold['id']);
$__kinds = ['housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','other'=>'Something else'];
// Tile grid order: service categories + Transfer (structured), then "Something else".
$__tiles = ['housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','transfer'=>'Transfer','other'=>'Something else'];
?>
<style>
.cx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.cx-tile{background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px;padding:14px;text-align:left;font:inherit;cursor:pointer;font-size:14px;font-weight:600;color:var(--pa-ink)}
.cx-tile[aria-expanded=true]{border-color:var(--pa-teal-d)}
.cx-form{display:none;margin-top:10px;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px;padding:14px}
.cx-form.open{display:block}
</style>
<p style="margin:0 0 16px"><a href="<?= e($__u) ?>" class="pa-back">&larr; Back to home</a></p>
<h2 class="pa-h2">Concierge</h2>
<p class="pa-sub">Tap what you need — our team confirms by return.</p>

<div class="cx-grid">
  <?php foreach ($__tiles as $k=>$label): ?>
  <button type="button" class="cx-tile" data-cx="<?= e($k) ?>" aria-expanded="false" aria-controls="cx-form-<?= e($k) ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
</div>

<?php foreach ($__kinds as $k=>$label): ?>
<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-<?= e($k) ?>">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="<?= e($k) ?>">
  <label class="pa-field"><?= e($label) ?> — what do you need?
    <textarea name="details" rows="2" required></textarea>
  </label>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="pa-btn pa-btn--primary">Send request</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>
<?php endforeach; ?>

<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-transfer">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="transfer">
  <label class="pa-field">Transfer
    <select name="transfer" required>
      <option value="">— select —</option>
      <?php foreach (TRANSFER_OPTIONS as $__tk => $__tl): ?><option value="<?= e($__tk) ?>"><?= e($__tl) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="pa-field">Details (flight no., time, pickup…)<textarea name="details" rows="2"></textarea></label>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="pa-btn pa-btn--primary">Request transfer</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>

<?php if ($__addons): ?>
<div style="margin-top:20px">
  <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--pa-muted);margin-bottom:8px">Recent requests</div>
  <?php foreach ($__addons as $a): ?>
  <div style="display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid var(--pa-line);font-size:14px">
    <strong style="text-transform:capitalize"><?= e($a['kind']) ?></strong>
    <span style="color:var(--pa-muted)"><?= e(trim(($a['tour_name'] ?? '') . ' ' . $a['details'])) ?></span>
    <span class="pa-pill pa-pill--<?= e($a['status']) ?>" style="margin-left:auto"><?= e($a['status']) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.cx-tile[data-cx]').forEach(function(b){
  b.addEventListener('click',function(){
    var k=b.getAttribute('data-cx'); var f=document.getElementById('cx-form-'+k);
    var open=f.classList.contains('open');
    document.querySelectorAll('.cx-form').forEach(function(x){x.classList.remove('open')});
    document.querySelectorAll('.cx-tile[data-cx]').forEach(function(x){x.setAttribute('aria-expanded','false')});
    if(!open){f.classList.add('open'); b.setAttribute('aria-expanded','true'); f.scrollIntoView({behavior:'smooth',block:'nearest'});}
  });
});
</script>
