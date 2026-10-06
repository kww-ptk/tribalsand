<?php
/**
 * The "Add to my stay" bottom sheet + its data — printed once per page (Home,
 * Extras and My trip all open it). js/portal-extras.js fills it from
 * #paExtrasData and posts to /api/booking-addon.php, the portal's one request
 * path. Expects $hold, $ref, $status and $__xList (guest_extras_for_hold()).
 */
if (!empty($GLOBALS['__pa_xsheet_done'])) return;
$GLOBALS['__pa_xsheet_done'] = true;

$__xToday = date('Y-m-d');
$__xFrom  = max((string)$hold['check_in'], $__xToday);
$__xDays  = [];
try {
    for ($__d = new DateTime($__xFrom); $__d <= new DateTime((string)$hold['check_out']); $__d->modify('+1 day')) {
        $__xDays[] = ['v' => $__d->format('Y-m-d'), 'l' => $__d->format('D j M')];
    }
} catch (Throwable $e) {}
$__xData = [
    'ref'    => (string)$ref,
    'active' => in_array($status ?? '', ['pending', 'confirmed'], true),
    'days'   => $__xDays,
    'parts'  => array_map(fn($p) => $p[0], GUEST_EXTRAS_PARTS_OF_DAY),
    'items'  => array_map('guest_extra_payload', $__xList ?? []),
    'cur'    => setting('site_currency', 'USD'),
];
?>
<div class="pa-sheet" id="paSheet" hidden>
  <div class="pa-sheet__back" data-sheet-close></div>
  <div class="pa-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="paSheetTitle">
    <div class="pa-sheet__grab" aria-hidden="true"></div>
    <button type="button" class="pa-sheet__x" data-sheet-close aria-label="Close">×</button>
    <div class="pa-sheet__img" id="paSheetImg"></div>
    <div class="pa-sheet__head"><h3 id="paSheetTitle"></h3><b id="paSheetPrice"></b></div>
    <p class="pa-sheet__meta" id="paSheetMeta"></p>
    <p class="pa-sheet__desc" id="paSheetDesc"></p>
    <div class="pa-sheet__label" id="paSheetDaysLabel">Which day?</div>
    <div class="pa-chiprow" id="paSheetDays"></div>
    <div class="pa-sheet__label">Time of day</div>
    <div class="pa-chiprow" id="paSheetParts"></div>
    <div class="pa-sheet__row" id="paSheetPeopleRow">
      <span>People</span>
      <span class="pa-stepper"><button type="button" data-pax="-1" aria-label="One person fewer">−</button><b id="paSheetPax" aria-live="polite">2</b><button type="button" data-pax="1" aria-label="One more person">+</button></span>
    </div>
    <label class="pa-field" style="margin:4px 0 0">
      <span id="paSheetNoteLabel">Notes (optional)</span>
      <input type="text" id="paSheetNote" maxlength="300" autocomplete="off">
    </label>
    <button type="button" class="pa-btn pa-btn--primary pa-sheet__go" id="paSheetGo">Add to my stay</button>
    <p class="pa-sheet__fine">Our team confirms within a few hours. It goes on your bill once confirmed — nothing is paid now.</p>
    <p class="pa-sheet__err" id="paSheetErr" aria-live="polite"></p>
  </div>
</div>
<script type="application/json" id="paExtrasData"><?= json_encode($__xData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/js/portal-extras.js?v=<?= @filemtime(__DIR__ . '/../../js/portal-extras.js') ?: '1' ?>" defer></script>
