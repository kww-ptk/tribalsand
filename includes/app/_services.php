<?php /** Concierge service tiles + forms. Expects $hold, $ref, $status. */ ?>
<?php
$__kinds = ['housekeeping'=>'Housekeeping','maintenance'=>'Maintenance','restaurant'=>'Restaurant','other'=>'Your request'];
// Tile grid: laundry + services + transfer (structured), then "Make a request".
$__tiles = ['laundry'=>'Laundry','housekeeping'=>'Housekeeping','other'=>'Make a request','maintenance'=>'Maintenance','restaurant'=>'Restaurant','transfer'=>'Transfer'];
$__icons = [
  'laundry'      => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="13" r="4"/><line x1="7" y1="6" x2="7.01" y2="6"/></svg>',
  'housekeeping' => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4"/><path d="M3 18h18M4 12V8a2 2 0 0 1 2-2h5v6"/></svg>',
  'maintenance'  => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-2.4z"/></svg>',
  'restaurant'   => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v7a2 2 0 0 0 4 0V3M8 10v11"/><path d="M17 3c-1.5 0-3 1.8-3 4.5S15.5 12 17 12v9"/></svg>',
  'transfer'     => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l1.6-4.6A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.4L19 13v4h-2v-2H7v2H5z"/><circle cx="8" cy="16" r="1"/><circle cx="16" cy="16" r="1"/></svg>',
  'other'        => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
];
// Shared optional preferred-time field markup.
$__sched = '<label class="pa-field">Preferred time (optional)<input type="datetime-local" name="scheduled_for"></label>';
?>
<style>
.cx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media (min-width:720px){.cx-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
.cx-tile{display:flex;flex-direction:column;align-items:flex-start;gap:11px;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:16px;padding:17px 16px;text-align:left;font:inherit;cursor:pointer;font-size:15px;font-weight:600;color:var(--pa-ink)}
.cx-tile svg{color:var(--pa-teal);width:26px;height:26px}
/* An expanded tile — and the form that follows it — span the full grid width so
   the form opens directly beneath the tapped tile (no jump to the bottom, no gap). */
.cx-tile[aria-expanded=true]{border-color:var(--pa-teal-d);grid-column:1 / -1}
.cx-form{display:none;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:14px;padding:15px;grid-column:1 / -1}
.cx-form.open{display:block}
</style>
<h2 class="pa-h2">Need something?</h2>
<p class="pa-sub">Tap what you need — it opens a chat with our team.</p>

<div class="cx-grid">
  <?php foreach ($__tiles as $k=>$label): ?>
  <button type="button" class="cx-tile" data-cx="<?= e($k) ?>" aria-expanded="false" aria-controls="cx-form-<?= e($k) ?>">
    <span aria-hidden="true"><?= $__icons[$k] ?? '' ?></span>
    <?= e($label) ?>
  </button>
  <form data-bm data-bm-success="Request sent — opening your chat…" action="/api/booking-addon.php" class="cx-form" id="cx-form-<?= e($k) ?>">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <input type="hidden" name="kind" value="<?= e($k) ?>">
    <?php if ($k === 'laundry'): ?>
      <label class="pa-field">Laundry service
        <select name="service" required>
          <option value="">— select —</option>
          <?php $__laundry = fetch_service_options('laundry'); ?>
          <?php if (!$__laundry): ?><option value="" disabled>— none available —</option><?php endif; ?>
          <?php foreach ($__laundry as $__o): ?><option value="<?= (int)$__o['id'] ?>"><?= e($__o['label'] . ((float)$__o['price_amount'] > 0 ? ' — ' . format_price($__o['price_amount']) : '')) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="pa-field">Notes (items, instructions…)<textarea name="details" rows="2"></textarea></label>
    <?php elseif ($k === 'transfer'): ?>
      <label class="pa-field">Transfer
        <select name="transfer" required>
          <option value="">— select —</option>
          <?php $__transfer = fetch_service_options('transfer'); ?>
          <?php if (!$__transfer): ?><option value="" disabled>— none available —</option><?php endif; ?>
          <?php foreach ($__transfer as $__o): ?><option value="<?= (int)$__o['id'] ?>"><?= e($__o['label'] . ((float)$__o['price_amount'] > 0 ? ' — ' . format_price($__o['price_amount']) : '')) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="pa-field">Details (flight no., time, pickup…)<textarea name="details" rows="2"></textarea></label>
    <?php else: ?>
      <label class="pa-field"><?= e($__kinds[$k] ?? 'Your request') ?> — what do you need?
        <textarea name="details" rows="2" required></textarea>
      </label>
    <?php endif; ?>
    <?= $__sched ?>
    <button type="submit" class="pa-btn pa-btn--primary"><?= $k === 'laundry' ? 'Request laundry' : ($k === 'transfer' ? 'Request transfer' : 'Send request') ?></button>
  </form>
  <?php endforeach; ?>
</div>

<?php
$__unreadMsg = 0;
try { $__unreadMsg = count_unread_guest((int)$hold['id']); } catch (Throwable $e) { $__unreadMsg = 0; }
$__refU = urlencode($ref);
?>
<div class="cx-grid" style="margin-top:10px">
  <a class="cx-tile cx-tile--nav" href="/booking.php?ref=<?= e($__refU) ?>&amp;view=activities">
    <span aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/></svg></span>
    Activities
    <span class="cx-tile__go" aria-hidden="true">→</span>
  </a>
  <a class="cx-tile cx-tile--nav" href="/booking.php?ref=<?= e($__refU) ?>&amp;view=messages">
    <span aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H8l-4 4z"/></svg></span>
    Messages
    <?php if ($__unreadMsg > 0): ?><span class="cx-tile__badge"><?= (int)$__unreadMsg ?></span><?php endif; ?>
  </a>
</div>

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
