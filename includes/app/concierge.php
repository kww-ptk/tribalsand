<?php /** Concierge view. Expects $hold, $ref, $status. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__addons = fetch_booking_addons((int)$hold['id']);
$__kinds = ['housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','other'=>'Something else'];
?>
<style>
.cx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.cx-tile{background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:14px;text-align:left;font:inherit;cursor:pointer;font-size:14px;font-weight:600;color:#1a1a1a}
.cx-tile[aria-expanded=true]{border-color:#102F3A}
.cx-form{display:none;margin-top:10px;background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:14px}
.cx-form.open{display:block}
.cx-submit{background:#102F3A;color:#fff;border:0;padding:11px 18px;border-radius:8px;font-weight:600;cursor:pointer}
.cx-pill{margin-left:auto;font-size:12px;padding:2px 9px;border-radius:999px;text-transform:capitalize}
.cx-pill-requested{background:#fff7e6;color:#8a5a00}.cx-pill-confirmed{background:#e6eefb;color:#1a4a9c}
.cx-pill-completed{background:#e6f6ec;color:#146c37}.cx-pill-declined,.cx-pill-cancelled{background:#fbe6e6;color:#a12}
</style>
<p style="margin:0 0 16px"><a href="<?= e($__u) ?>" style="font-size:13px;color:var(--teal,#1E5C6B)">&larr; Back to home</a></p>
<h2 style="font-family:'Cormorant Garamond',serif;font-weight:500;margin:0 0 4px">Concierge</h2>
<p style="margin:0 0 14px;font-size:13px;color:#6b7280">Tap what you need — our team confirms by return.</p>

<div class="cx-grid">
  <?php foreach ($__kinds as $k=>$label): ?>
  <button type="button" class="cx-tile" data-cx="<?= e($k) ?>" aria-expanded="false" aria-controls="cx-form-<?= e($k) ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
  <a href="<?= e($__u) ?>&view=manage" class="cx-tile" style="display:block;text-decoration:none">Transfer</a>
  <a href="<?= e($__u) ?>&view=manage" class="cx-tile" style="display:block;text-decoration:none">Activity</a>
</div>

<?php foreach ($__kinds as $k=>$label): ?>
<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-<?= e($k) ?>">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="<?= e($k) ?>">
  <label style="display:block;font-size:13px;margin-bottom:8px"><?= e($label) ?> — what do you need?
    <textarea name="details" rows="2" required style="display:block;width:100%;margin-top:4px;padding:9px;border:1px solid #d1d5db;border-radius:6px;font:inherit"></textarea>
  </label>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="cx-submit">Send request</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>
<?php endforeach; ?>

<?php if ($__addons): ?>
<div style="margin-top:20px">
  <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9ca3af;margin-bottom:8px">Recent requests</div>
  <?php foreach ($__addons as $a): ?>
  <div style="display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid #eee;font-size:14px">
    <strong style="text-transform:capitalize"><?= e($a['kind']) ?></strong>
    <span style="color:#555"><?= e(trim(($a['tour_name'] ?? '') . ' ' . $a['details'])) ?></span>
    <span class="cx-pill cx-pill-<?= e($a['status']) ?>"><?= e($a['status']) ?></span>
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
