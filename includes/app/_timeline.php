<?php
/**
 * My trip — design 1 "day by day" (Oct 2026). ONE timeline for the stay:
 * check-in / check-out, every extra the guest asked for on its day with its
 * status (Waiting / Confirmed / Done), the guest's and staff's own plan items
 * (itinerary_items), and free days folded together with one extra suggested for
 * that gap (opens the shared add sheet). Extras with no day yet sit above.
 * Expects $hold, $ref, $status. Replaces the old _added.php + _trip.php.
 */
$__tu     = '/booking.php?ref=' . urlencode($ref);
$__active = in_array($status ?? '', ['pending', 'confirmed'], true);
$__vname  = trim((string)($hold['venue_name'] ?? '')) ?: 'Tribal Sand';
$__nights = max(1, (int)((strtotime((string)$hold['check_out']) - strtotime((string)$hold['check_in'])) / 86400));
$__pill   = ['pending' => ['Awaiting confirmation', 'pend'], 'confirmed' => ['Confirmed', 'ok'],
             'cancelled' => ['Cancelled', 'no'], 'expired' => ['Expired', 'no']][$status] ?? ['', ''];

// Days: anchors + plan items from fetch_itinerary(); requests are added below with
// their status (fetch_itinerary() only knows confirmed ones).
$__days = [];
foreach (fetch_itinerary($hold) as $__d) {
    $__days[$__d['date']] = ['date' => $__d['date'], 'today' => $__d['is_today'],
        'items' => array_values(array_filter($__d['items'], fn($i) => ($i['source'] ?? '') !== 'request'))];
}
$__undated = [];
try { $__addons = array_values(array_filter(fetch_booking_addons((int)$hold['id']), fn($a) => !in_array($a['status'] ?? '', ['cancelled'], true))); } catch (Throwable $e) { $__addons = []; }
foreach ($__addons as $__a) {
    $__ts = !empty($__a['scheduled_for']) ? strtotime((string)$__a['scheduled_for']) : false;
    $__k  = $__ts ? date('Y-m-d', $__ts) : '';
    $__row = ['title' => addon_label($__a), 'status' => (string)$__a['status'], 'kind' => (string)$__a['kind'],
              'time' => $__ts && date('H:i', $__ts) !== '00:00' ? date('H:i', $__ts) : null,
              'sort' => $__ts ? 100 + (int)date('G', $__ts) * 60 + (int)date('i', $__ts) : 1000,
              'pax' => (int)($__a['pax'] ?? 0), 'price' => is_priced($__a['price_amount'] ?? null) ? (float)$__a['price_amount'] : null, 'source' => 'extra'];
    if ($__k !== '' && isset($__days[$__k])) $__days[$__k]['items'][] = $__row; else $__undated[] = $__row;
}
foreach ($__days as $__k => $__d) usort($__days[$__k]['items'], fn($x, $y) => ($x['sort'] ?? 0) <=> ($y['sort'] ?? 0));

// Fold runs of empty days (a day with only nothing on it) into one "free" row.
$__rows = []; $__run = [];
$__flush = function () use (&$__rows, &$__run) { if ($__run) { $__rows[] = ['free' => $__run]; $__run = []; } };
foreach ($__days as $__d) {
    if (!$__d['items']) { $__run[] = $__d; continue; }
    $__flush(); $__rows[] = ['day' => $__d];
}
$__flush();

// Suggestions for free time: the property's extras not asked for yet, phase-appropriate.
$__xList = guest_extras_for_hold($hold);
// (Transfers belong to arrival/departure, not a free day in the middle — leave them out.)
$__sugg  = $__active ? array_values(array_filter($__xList, fn($x) => ($x['status'] ?? '') === '' && $x['kind'] !== 'transfer')) : [];
$__si = 0;
$__ico = [
    'checkin'  => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
    'checkout' => '<path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"/><path d="M14 17l5-5-5-5"/><path d="M19 12H7"/>',
    'transfer' => '<path d="M5 13l1.6-4.6A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.4L19 13v4h-2v-2H7v2H5z"/>',
    'tour'     => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
    'dining'   => '<path d="M6 3v7a2 2 0 0 0 4 0V3M8 10v11"/><path d="M17 3c-1.5 0-3 1.8-3 4.5S15.5 12 17 12v9"/>',
    'other'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v4l2.5 2.5"/>',
];
$__icon = fn(string $k) => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($__ico[$k] ?? $__ico['other']) . '</svg>';
$__item = function (array $it) use ($__icon) {
    $cat = $it['source'] === 'extra' ? ($it['kind'] === 'transfer' ? 'transfer' : ($it['kind'] === 'tour' ? 'tour' : 'other')) : (string)($it['category'] ?? 'other');
    $meta = [];
    if (!empty($it['pax'])) $meta[] = $it['pax'] . ' ' . ($it['pax'] === 1 ? 'person' : 'people');
    if (isset($it['price']) && $it['price'] !== null) $meta[] = format_price((float)$it['price']);
    if (($it['detail'] ?? '') !== '' && $it['source'] !== 'extra') $meta[] = (string)$it['detail'];
    [$sl, $st] = $it['source'] === 'extra' ? guest_extra_status_view($it['status']) : ['', ''];
    $h = '<div class="pa-tl__ev' . (in_array($cat, ['checkin', 'checkout'], true) ? ' is-anchor' : '') . '"><span class="pa-tl__ico">' . $__icon($cat) . '</span>'
       . '<span class="pa-tl__t"><b>' . ($it['time'] ? e($it['time']) . ' · ' : '') . e((string)$it['title']) . '</b>'
       . ($meta ? '<span>' . e(implode(' · ', $meta)) . '</span>' : '') . '</span>'
       . ($sl !== '' ? '<span class="pa-pill pa-pill--' . e($st) . '">' . e($sl) . '</span>' : '') . '</div>';
    return $h;
};
?>
<div class="pa-tlhead">
  <div class="pa-tlhead__eyebrow"><?= e($__vname) ?> · <?= e((string)$hold['room_name']) ?></div>
  <div class="pa-tlhead__dates"><?= e(date('j M', strtotime((string)$hold['check_in']))) ?> – <?= e(date('j M Y', strtotime((string)$hold['check_out']))) ?> · <?= $__nights ?> night<?= $__nights === 1 ? '' : 's' ?></div>
  <div class="pa-tlhead__row">
    <?php if ($__pill[0] !== ''): ?><span class="pa-pill pa-pill--<?= e($__pill[1]) ?>"><?= e($__pill[0]) ?></span><?php endif; ?>
    <?php if ($status === 'pending' && !empty($hold['expires_at'])): ?><span class="pa-tlhead__hold">Dates held for <b id="bkCountdown" data-expires="<?= (int)strtotime((string)$hold['expires_at']) * 1000 ?>">…</b></span><?php endif; ?>
    <?php if (share_reservation_on($hold)): ?><a class="pa-tlhead__bill" href="<?= e($__tu) ?>&amp;view=bill">Bill →</a><?php endif; ?>
  </div>
</div>

<?php if ($__undated): ?>
<div class="pa-tl__undated">
  <div class="pa-tl__label">Added, day to be arranged</div>
  <?php foreach ($__undated as $__u) echo $__item($__u); ?>
</div>
<?php endif; ?>

<div class="pa-tl">
<?php foreach ($__rows as $__r):
    if (isset($__r['day'])): $__d = $__r['day']; $__ts = strtotime($__d['date']); ?>
  <div class="pa-tl__day<?= $__d['today'] ? ' is-today' : '' ?>">
    <div class="pa-tl__date"><?= e(date('D', $__ts)) ?><b><?= e(date('j', $__ts)) ?></b><?php if ($__d['today']): ?><em>Today</em><?php endif; ?></div>
    <div class="pa-tl__items"><?php foreach ($__d['items'] as $__it) echo $__item($__it); ?></div>
  </div>
<?php else: $__run = $__r['free']; $__a = strtotime($__run[0]['date']); $__b = strtotime($__run[count($__run) - 1]['date']);
      $__s = $__sugg ? $__sugg[$__si++ % count($__sugg)] : null; ?>
  <div class="pa-tl__day">
    <div class="pa-tl__date"><?= e(date('D', $__a)) ?><b><?= e(date('j', $__a)) ?></b><?php if (count($__run) > 1): ?><em>– <?= e(date('D j', $__b)) ?></em><?php endif; ?></div>
    <div class="pa-tl__items">
      <?php if ($__s): ?>
      <button type="button" class="pa-tl__slot" data-extra="<?= e($__s['key']) ?>">
        <span><b><?= count($__run) > 1 ? count($__run) . ' free days' : 'Free day' ?></b><span><?= e($__s['name']) ?> · <?= e($__s['price_label']) ?></span></span>
        <span class="pa-addc" aria-hidden="true">+</span>
      </button>
      <?php else: ?>
      <div class="pa-tl__free"><?= count($__run) > 1 ? count($__run) . ' free days' : 'Free day' ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; endforeach; ?>
</div>

<?php if ($__active): ?>
<div class="pa-tl__own">
  <button type="button" class="pa-btn" id="planAddBtn" style="width:auto;padding:9px 16px">+ Add your own plan</button>
  <?php
    $__pdays = [];
    for ($__d = new DateTime((string)$hold['check_in']); $__d <= new DateTime((string)$hold['check_out']); $__d->modify('+1 day')) $__pdays[$__d->format('Y-m-d')] = $__d->format('D j M');
    $__ptimes = [];
    for ($__h = 6; $__h <= 22; $__h++) foreach ([0, 30] as $__m) { if ($__h === 22 && $__m > 0) break; $__tk = sprintf('%02d:%02d', $__h, $__m); $__ptimes[$__tk] = date('g:i A', strtotime($__tk)); }
  ?>
  <form data-bm data-bm-success="Added to your plan." action="/api/itinerary.php" id="planAddForm" style="display:none;margin-top:12px">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="category" value="note">
    <div class="pa-formgrid">
      <label class="pa-field">Day<select name="day" required><?php foreach ($__pdays as $__dv => $__dl): ?><option value="<?= e($__dv) ?>"><?= e($__dl) ?></option><?php endforeach; ?></select></label>
      <label class="pa-field">Time (optional)<select name="at_time"><option value="">Any time</option><?php foreach ($__ptimes as $__tv => $__tl): ?><option value="<?= e($__tv) ?>"><?= e($__tl) ?></option><?php endforeach; ?></select></label>
      <label class="pa-field pa-field--full">What<input type="text" name="title" required placeholder="e.g. Flight lands 12:30, dinner in town"></label>
    </div>
    <button type="submit" class="pa-btn pa-btn--primary">Add to plan</button>
    <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
  </form>
  <p class="pa-sub" style="margin-top:8px">Add your flight or your own plans so the team knows. To book something, use <a href="<?= e($__tu) ?>&amp;view=extras">Extras</a>.</p>
</div>
<script>
(function(){
  var b=document.getElementById('planAddBtn'),f=document.getElementById('planAddForm');
  if(b&&f)b.addEventListener('click',function(){var open=f.style.display!=='none';f.style.display=open?'none':'block';if(!open)f.scrollIntoView({behavior:'smooth',block:'nearest'});});
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_extras_sheet.php'; ?>
