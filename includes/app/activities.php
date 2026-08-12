<?php /** Activities view. Expects $hold, $ref, $status. */ ?>
<?php
// Degrade gracefully if the tours catalog is unavailable (mirrors home.php).
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__acts = fetch_portal_activities($__venue); } catch (Throwable $e) { $__acts = []; }
$__cur = setting('site_currency', 'USD');
$__ci  = (string)$hold['check_in']; $__co = (string)$hold['check_out'];
try { $__cats = fetch_tour_categories(); }  catch (Throwable $e) { $__cats = []; }
$__active = in_array($status ?? '', ['pending','confirmed'], true);
?>
<h2 class="pa-h2">Experiences</h2>
<p class="pa-sub">Browse and request activities — our team confirms availability and pricing.</p>

<?php if ($__cats): ?>
<div class="pa-chips" id="paCatChips">
  <button type="button" class="pa-chip is-active" data-cat="all" aria-pressed="true">All</button>
  <?php foreach ($__cats as $c): ?>
  <button type="button" class="pa-chip" data-cat="<?= e($c['key']) ?>" aria-pressed="false"><?= e($c['label']) ?></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$__acts): ?>
  <p class="pa-sub">Experiences will appear here soon.</p>
<?php else: ?>
<div class="pa-grid">
<?php foreach ($__acts as $a):
    $img = trim((string)($a['hero'] ?? ''));
    $mediaClass = 'pa-media pa-media--' . preg_replace('/[^a-z]/','',strtolower((string)$a['category']));
    $style = $img !== '' ? 'background-image:url(\'' . e(storage_url($img)) . '\')' : '';
?>
  <div class="pa-card" data-cat="<?= e($a['category']) ?>">
    <div class="<?= e($mediaClass) ?>" style="<?= $style ?>">
      <?php if (!empty($a['tag_label'])): ?><span class="pa-media__tag"><?= e($a['tag_label']) ?></span><?php endif; ?>
    </div>
    <div class="pa-card__body">
      <p class="pa-card__title"><?= e($a['name']) ?></p>
      <div class="pa-card__meta">
        <?php if (!empty($a['duration'])): ?><span><?= e($a['duration']) ?></span><?php endif; ?>
        <?php
          $__pp = ($a['price_per_person'] ?? false);
          $__perPerson = ($__pp === true || $__pp === 't' || $__pp === '1' || $__pp === 1 || $__pp === 'true');
          $__amt = ($a['price_amount'] ?? null);
          if ($__amt !== null && $__amt !== '' && (float)$__amt > 0):
        ?><span style="font-weight:600;color:var(--pa-ink)"><?= e(format_price((float)$__amt, $__cur)) ?><?= $__perPerson ? ' / person' : '' ?></span><?php endif; ?>
        <?php if (!empty($a['short_desc'])): ?><span style="flex-basis:100%;margin-top:4px;color:var(--pa-muted)"><?= e($a['short_desc']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($a['whats_included'])): ?>
      <div style="margin-top:8px;font-size:13px;color:var(--pa-muted)"><strong style="color:var(--pa-ink);font-weight:600">What’s included:</strong> <?= e($a['whats_included']) ?></div>
      <?php endif; ?>
      <?php if ($__active): ?>
      <?php $__cap = (int)($a['max_pax'] ?? 0); $__cap = $__cap > 0 ? $__cap : 8; ?>
      <button type="button" class="pa-btn pa-btn--primary act-toggle" style="margin-top:12px">Request</button>
      <form data-bm action="/api/booking-addon.php" class="act-form" style="display:none;margin-top:10px"
            data-price="<?= e((string)($__amt !== null ? (float)$__amt : 0)) ?>" data-perperson="<?= $__perPerson ? '1' : '0' ?>" data-cur="<?= e($__cur) ?>">
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <input type="hidden" name="kind" value="tour">
        <input type="hidden" name="tour_slug" value="<?= e($a['slug']) ?>">
        <label class="pa-field">Guests
          <select name="pax" class="act-pax"><?php for ($__i = 1; $__i <= $__cap; $__i++): ?><option value="<?= $__i ?>"><?= $__i ?></option><?php endfor; ?></select>
        </label>
        <label class="pa-field">Date
          <input type="date" name="at_date" required min="<?= e($__ci) ?>" max="<?= e($__co) ?>" value="<?= e($__ci) ?>">
        </label>
        <label class="pa-field">Notes (optional)<input type="text" name="details"></label>
        <?php if ($__amt !== null && (float)$__amt > 0): ?><p class="act-total" style="font-weight:600;margin:2px 0 8px"></p><?php endif; ?>
        <button type="submit" class="pa-btn pa-btn--primary">Request activity</button>
        <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?></div><?php endif; ?>

<script>
(function(){
  var chips=document.querySelectorAll('#paCatChips .pa-chip');
  var cards=document.querySelectorAll('.pa-card[data-cat]');
  chips.forEach(function(ch){ch.addEventListener('click',function(){
    chips.forEach(function(x){x.classList.remove('is-active');x.setAttribute('aria-pressed','false');}); ch.classList.add('is-active'); ch.setAttribute('aria-pressed','true');
    var cat=ch.getAttribute('data-cat');
    cards.forEach(function(cd){ cd.style.display=(cat==='all'||cd.getAttribute('data-cat')===cat)?'':'none'; });
  });});

  // Activity request: reveal the form and keep a live total.
  function fmtTotal(form){
    var t = form.querySelector('.act-total'); if(!t) return;
    var unit = parseFloat(form.dataset.price)||0, per = form.dataset.perperson==='1';
    var pax = parseInt(form.querySelector('.act-pax').value,10)||1;
    var total = per ? unit*pax : unit;
    t.textContent = 'Total: ' + form.dataset.cur + ' ' + total.toLocaleString();
  }
  document.querySelectorAll('.pa-card .act-toggle').forEach(function(btn){
    var form = btn.parentNode.querySelector('.act-form');
    var card = btn.closest('.pa-card');
    btn.addEventListener('click', function(){
      var open = form.style.display !== 'none';
      form.style.display = open ? 'none' : 'block';
      // The CSS keys on is-open to span the card across both mobile columns.
      if (card) card.classList.toggle('is-open', !open);
      if(!open){ fmtTotal(form); form.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    });
  });
  document.querySelectorAll('.act-form').forEach(function(form){
    var pax = form.querySelector('.act-pax');
    if(pax) pax.addEventListener('change', function(){ fmtTotal(form); });
  });
})();
</script>
