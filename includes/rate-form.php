<?php
/**
 * Rate entry form — one room, N date ranges, one price, one label.
 *
 * Config before include:
 *   $rf_room_id  int|null  fixed room (room page), or null to render a selector
 *   $rf_rooms    array     rooms for the selector: rows with id + name
 *   $rf_action   string    where to POST (default: the current URL)
 *
 * Posts action=rates_save with room_id, price, rate_label and parallel
 * range_from[] / range_to[] arrays. "To" is the LAST NIGHT — the handler calls
 * rates_ranges_from_post(), which converts to exclusive storage.
 *
 * The date pickers are the shared js/datepicker.js already loaded by
 * admin/_layout.php: each row is a ci/co pair sharing a unique data-dp-pair.
 * Rows are cloned from a <template> (never from a live node — a live node
 * carries data-dp-bound and the clone would never bind).
 */
$rf_room_id = $rf_room_id ?? null;
$rf_rooms   = $rf_rooms   ?? [];
$rf_action  = $rf_action  ?? '';
?>
<style>
.rate-form__ranges { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
.rate-range { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.rate-range .field { margin:0; }
.rate-range .dp-btn { min-width:150px; }
.rate-form__row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:4px; }
.rate-form__hint { font-size:12px; color:var(--muted); margin-top:10px; }
</style>

<form method="POST" action="<?= e($rf_action) ?>" class="rate-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="rates_save">

  <?php if ($rf_room_id !== null): ?>
    <input type="hidden" name="room_id" value="<?= (int)$rf_room_id ?>">
  <?php else: ?>
    <div class="field" style="max-width:280px">
      <label>Room</label>
      <select name="room_id" class="eselect" required>
        <?php foreach ($rf_rooms as $r): ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <div class="rate-form__ranges" id="rateRanges"></div>

  <button type="button" class="btn-sm btn-outline" id="rateAddRange">
    <?= admin_icon('plus', 14) ?> Add another date range
  </button>

  <div class="rate-form__row">
    <div class="field" style="margin:0">
      <label>Price / night</label>
      <input class="inp" type="number" name="price" step="0.01" min="1" placeholder="450" required style="width:110px">
    </div>
    <div class="field" style="margin:0">
      <label>Label</label>
      <input class="inp" type="text" name="rate_label" placeholder="Peak Season" style="width:170px">
    </div>
    <button type="submit" class="btn-primary btn-sm">Save rate</button>
  </div>

  <p class="rate-form__hint">
    The price and label apply to every date range above. Nights already covered by
    another rate are re-priced — each night ends up on exactly one rate.
  </p>
</form>

<template id="rateRangeTpl">
  <div class="rate-range">
    <div class="field">
      <label>From (first night)</label>
      <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="__PAIR__"
              data-dp-target="rf_from___N__" data-dp-placeholder="Pick a date">Pick a date</button>
      <input type="hidden" id="rf_from___N__" name="range_from[]">
    </div>
    <div class="field">
      <label>To (last night)</label>
      <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="__PAIR__"
              data-dp-target="rf_to___N__" data-dp-placeholder="Pick a date">Pick a date</button>
      <input type="hidden" id="rf_to___N__" name="range_to[]">
    </div>
    <button type="button" class="btn-icon rate-range__rm" title="Remove this range" aria-label="Remove this range">
      <?= admin_icon('trash', 15) ?>
    </button>
  </div>
</template>

<script>
(function () {
  var wrap = document.getElementById('rateRanges');
  var tpl  = document.getElementById('rateRangeTpl');
  var add  = document.getElementById('rateAddRange');
  if (!wrap || !tpl || !add) return;
  var n = 0;

  function addRow() {
    n++;
    var html = tpl.innerHTML.replace(/__N__/g, String(n)).replace(/__PAIR__/g, 'rfp' + n);
    var host = document.createElement('div');
    host.innerHTML = html;
    wrap.appendChild(host.firstElementChild);
    if (window.initDatepickers) window.initDatepickers();
    syncRemove();
  }

  // The last remaining row cannot be removed — the form always posts one range.
  function syncRemove() {
    var rows = wrap.querySelectorAll('.rate-range');
    rows.forEach(function (r) {
      var btn = r.querySelector('.rate-range__rm');
      if (btn) btn.style.visibility = rows.length > 1 ? 'visible' : 'hidden';
    });
  }

  add.addEventListener('click', addRow);
  wrap.addEventListener('click', function (e) {
    var btn = e.target.closest('.rate-range__rm');
    if (!btn) return;
    btn.closest('.rate-range').remove();
    syncRemove();
  });

  addRow();
})();
</script>
