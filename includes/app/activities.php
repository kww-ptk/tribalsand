<?php /** Activities view. Expects $hold, $ref, $status. */ ?>
<?php
// Degrade gracefully if the tours catalog is unavailable (mirrors home.php).
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__acts = fetch_portal_activities($__venue); } catch (Throwable $e) { $__acts = []; }
$__cur = setting('site_currency', 'USD');
$__ci  = (string)$hold['check_in']; $__co = (string)$hold['check_out'];
try { $__cats = fetch_tour_categories(); }  catch (Throwable $e) { $__cats = []; }
$__active = in_array($status ?? '', ['pending','confirmed'], true);
// Stay nights as Y-m-d => 'D j M' for the styled (non-native) date dropdown.
$__stayDays = [];
try { for ($__d = new DateTime($__ci); $__d <= new DateTime($__co); $__d->modify('+1 day')) { $__stayDays[$__d->format('Y-m-d')] = $__d->format('D j M'); } } catch (Throwable $e) {}
?>
<h2 class="pa-h2">Experiences</h2>
<p class="pa-sub">Browse and request activities — our team confirms availability and pricing.</p>

<?php if (!$__acts): ?>
  <p class="pa-sub">Experiences will appear here soon.</p>
<?php else: ?>
<!-- Category tabs and the pager share one row (pager on the right, smaller). -->
<div class="pa-tabsrow">
  <?php if ($__cats): ?>
  <div class="pa-tabs" id="paCatChips" role="tablist" aria-label="Activity categories">
    <button type="button" class="pa-tab is-active" data-cat="all" role="tab" aria-selected="true">All</button>
    <?php foreach ($__cats as $c): ?>
    <button type="button" class="pa-tab" data-cat="<?= e($c['key']) ?>" role="tab" aria-selected="false"><?= e($c['label']) ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="pa-pager pa-pager--inline" id="paPager" hidden>
    <button type="button" class="pa-pager__btn" id="paPrev" aria-label="Previous activities">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <span class="pa-pager__info" id="paInfo" aria-live="polite"></span>
    <button type="button" class="pa-pager__btn" id="paNext" aria-label="More activities">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
    </button>
  </div>
</div>
<!-- Request form for the tapped activity opens here, just under the tabs. -->
<div id="actFormHost" class="act-host" hidden></div>
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
        <div class="pa-formgrid">
          <label class="pa-field">Guests
            <select name="pax" class="act-pax"><?php for ($__i = 1; $__i <= $__cap; $__i++): ?><option value="<?= $__i ?>"><?= $__i ?></option><?php endfor; ?></select>
          </label>
          <label class="pa-field">Date
            <select name="at_date" required><?php foreach ($__stayDays as $__dv=>$__dl): ?><option value="<?= e($__dv) ?>"><?= e($__dl) ?></option><?php endforeach; ?></select>
          </label>
          <label class="pa-field pa-field--full">Notes (optional)<input type="text" name="details"></label>
        </div>
        <?php if ($__amt !== null && (float)$__amt > 0): ?><p class="act-total" style="font-weight:600;margin:2px 0 8px"></p><?php endif; ?>
        <button type="submit" class="pa-btn pa-btn--primary">Request activity</button>
        <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?></div>
<?php endif; ?>

<script>
(function(){
  var chips=document.querySelectorAll('#paCatChips .pa-tab');
  var cards=Array.prototype.slice.call(document.querySelectorAll('.pa-card[data-cat]'));
  // ── Pagination: show 6 at a time, arrows to page through the filtered set ──
  var PAGE=6, page=0, cat='all';
  var pager=document.getElementById('paPager'),
      prev=document.getElementById('paPrev'),
      next=document.getElementById('paNext'),
      info=document.getElementById('paInfo');
  var host=document.getElementById('actFormHost');
  function filtered(){ return cards.filter(function(cd){ return cat==='all'||cd.getAttribute('data-cat')===cat; }); }
  function render(){
    closeForm();   // a filter/page change closes any open request form
    var list=filtered();
    var pages=Math.max(1, Math.ceil(list.length/PAGE));
    if(page>pages-1) page=pages-1; if(page<0) page=0;
    cards.forEach(function(cd){ cd.style.display='none'; });
    list.slice(page*PAGE,(page+1)*PAGE).forEach(function(cd){ cd.style.display=''; });
    if(!pager) return;
    if(pages<=1){ pager.hidden=true; }
    else { pager.hidden=false; info.textContent=(page+1)+' / '+pages; prev.disabled=(page===0); next.disabled=(page===pages-1); }
  }
  if(prev) prev.addEventListener('click',function(){ page--; render(); });
  if(next) next.addEventListener('click',function(){ page++; render(); });
  chips.forEach(function(ch){ch.addEventListener('click',function(){
    chips.forEach(function(x){x.classList.remove('is-active');x.setAttribute('aria-selected','false');}); ch.classList.add('is-active'); ch.setAttribute('aria-selected','true');
    cat=ch.getAttribute('data-cat'); page=0; render();
  });});

  // Live total on the request form.
  function fmtTotal(form){
    var t = form.querySelector('.act-total'); if(!t) return;
    var unit = parseFloat(form.dataset.price)||0, per = form.dataset.perperson==='1';
    var pax = parseInt(form.querySelector('.act-pax').value,10)||1;
    var total = per ? unit*pax : unit;
    t.textContent = 'Total: ' + form.dataset.cur + ' ' + total.toLocaleString();
  }
  // The tapped activity's form is hoisted into the host below the tabs (not
  // expanded in place). Move it back to its card when closed.
  function closeForm(){
    if(!host || !host._form) return;
    host._form.style.display='none';
    if(host._body) host._body.appendChild(host._form);
    host._form=null; host._body=null; host.innerHTML=''; host.hidden=true;
  }
  document.querySelectorAll('.pa-card .act-toggle').forEach(function(btn){
    var card=btn.closest('.pa-card');
    var form=card.querySelector('.act-form');
    var body=form.parentNode;
    var name=(card.querySelector('.pa-card__title')||{}).textContent||'';
    btn.addEventListener('click', function(){
      var wasOpen = host && host._form===form;
      closeForm();
      if(wasOpen) return;
      if(!host){ form.style.display='block'; fmtTotal(form); form.scrollIntoView({behavior:'smooth',block:'nearest'}); return; }
      var head=document.createElement('div'); head.className='act-host__head';
      var title=document.createElement('span'); title.className='act-host__title'; title.textContent=name;
      var x=document.createElement('button'); x.type='button'; x.className='act-host__x'; x.setAttribute('aria-label','Close'); x.innerHTML='&times;';
      x.addEventListener('click',closeForm);
      head.appendChild(title); head.appendChild(x);
      host.appendChild(head); host.appendChild(form);
      host._form=form; host._body=body; host.hidden=false;
      form.style.display='block'; fmtTotal(form);
      host.scrollIntoView({behavior:'smooth',block:'nearest'});
    });
  });
  render();
  document.querySelectorAll('.act-form').forEach(function(form){
    var pax = form.querySelector('.act-pax');
    if(pax) pax.addEventListener('change', function(){ fmtTotal(form); });
  });
})();
</script>
