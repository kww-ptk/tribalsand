<?php
/**
 * Read-only nightly-price calendar for ONE room, rendered as N month grids.
 *
 * Config before include:
 *   $rc_room_id       int
 *   $rc_default_price float   the room's own price_amount
 *   $rc_month         string  'Y-m' — the FIRST month shown (default: this month)
 *   $rc_months        int     how many months to show (default 3, min 1)
 *   $rc_compact       bool    smaller cells (default: on whenever $rc_months > 1)
 *   $rc_currency      string  default 'USD'
 *   $rc_base_url      string  URL the prev/next links rebuild, WITHOUT rate_month
 *
 * Prices come from rates_nightly_map(), the same resolver room_stay_quote()
 * sums — so this grid always shows what a guest would actually be charged.
 *
 * ONE query per room, not one per month. rates_nightly_map()'s documented call
 * pattern is to ask for the widest window the page needs and slice it in the
 * view; a page listing 8 rooms × 3 months would otherwise fire 24 queries.
 *
 * Navigation is a plain link (rate_month=YYYY-MM) so it works with JS off, and
 * it steps by a whole block so Next never re-shows a month already on screen.
 */
require_once __DIR__ . '/rates.php';

$rc_month         = $rc_month         ?? date('Y-m');
$rc_currency      = $rc_currency      ?? 'USD';
$rc_default_price = (float)($rc_default_price ?? 0);
$rc_base_url      = $rc_base_url      ?? '';
$rc_months        = max(1, (int)($rc_months ?? 3));
$rc_compact       = $rc_compact ?? ($rc_months > 1);

$__rcFirst = $rc_month . '-01';
if (!strtotime($__rcFirst)) { $rc_month = date('Y-m'); $__rcFirst = $rc_month . '-01'; }

$__rcStart = date('Y-m-01', strtotime($__rcFirst));
$__rcEndEx = date('Y-m-01', strtotime($__rcFirst . " +{$rc_months} month"));
$__rcPrev  = date('Y-m',    strtotime($__rcFirst . " -{$rc_months} month"));
$__rcNext  = date('Y-m',    strtotime($__rcFirst . " +{$rc_months} month"));
$__rcSep   = str_contains($rc_base_url, '?') ? '&' : '?';
$__rcToday = date('Y-m-d');

// One resolve for the whole span; each month grid slices its own days out.
$__rcMap = rates_nightly_map((int)$rc_room_id, $rc_default_price, $__rcStart, $__rcEndEx);

/** Full money, for the legend. No decimals when the figure is whole. */
$__rcMoney = function (float $v): string {
    return number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2);
};

/** Cell money. Compact cells are ~30px wide, so thousands are abbreviated. */
$__rcCell = function (float $v) use ($rc_compact, $__rcMoney): string {
    if ($v <= 0)     return '—';
    if (!$rc_compact) return $__rcMoney($v);
    if ($v < 1000)   return $__rcMoney($v);
    $k = $v / 1000;
    return rtrim(rtrim(number_format($k, $k < 100 ? 1 : 0), '0'), '.') . 'k';
};
?>
<?php if (empty($GLOBALS['__rc_css_done'])): $GLOBALS['__rc_css_done'] = true; ?>
<style>
/* Emitted once per page — admin/rates.php includes this partial once per room. */
.rcal__head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
.rcal__title { font-size:13px; font-weight:700; }
.rcal__months { display:grid; gap:18px; grid-template-columns:repeat(auto-fit, minmax(215px, 1fr)); }
.rcal__mname { font-size:11px; font-weight:700; letter-spacing:.03em; text-transform:uppercase;
  color:var(--muted); margin-bottom:6px; }
.rcal__grid { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
.rcal__dow { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
  color:var(--muted); text-align:center; padding:4px 0; }
.rcal__cell { min-height:52px; border:1px solid var(--border); border-radius:4px; padding:4px 6px; background:#fff; }
.rcal__cell--blank { border:none; background:transparent; }
.rcal__cell--rate { background:#fefce8; border-color:#fde68a; }
.rcal__cell--today { box-shadow:inset 0 0 0 2px #0369a1; }
.rcal__day { font-size:10px; color:var(--muted); }
.rcal__price { font-size:12px; font-weight:700; color:#102F3A; }
.rcal__price--unset { color:#b6c2c8; font-weight:600; }
.rcal__cell--rate .rcal__price { color:#92400e; }
.rcal__lbl { font-size:9px; color:#92400e; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rcal__legend { font-size:11px; color:var(--muted); margin-top:12px; }
.rcal.is-loading { opacity:.45; pointer-events:none; transition:opacity .1s; }

/* Compact: several months side by side. Labels drop to the tooltip. */
.rcal--sm .rcal__grid { gap:2px; }
.rcal--sm .rcal__dow { font-size:8px; padding:2px 0; }
.rcal--sm .rcal__cell { min-height:30px; padding:2px 3px; border-radius:3px; text-align:center; }
.rcal--sm .rcal__day { font-size:8px; line-height:1.1; }
.rcal--sm .rcal__price { font-size:10px; line-height:1.2; }
.rcal--sm .rcal__lbl { display:none; }
</style>
<?php endif; ?>

<div class="rcal<?= $rc_compact ? ' rcal--sm' : '' ?>"
     data-rcal
     data-room-id="<?= (int)$rc_room_id ?>"
     data-months="<?= (int)$rc_months ?>"
     data-compact="<?= $rc_compact ? '1' : '0' ?>"
     data-base="<?= e($rc_base_url) ?>">
  <div class="rcal__head">
    <span class="rcal__title"><?php
      $__lastName = date('M Y', strtotime($__rcEndEx . ' -1 day'));
      echo e($rc_months > 1
          ? date('M Y', strtotime($__rcStart)) . ' – ' . $__lastName
          : date('F Y', strtotime($__rcStart)));
    ?></span>
    <span style="display:flex;gap:6px">
      <a class="btn-sm btn-outline rcal__nav" data-month="<?= e($__rcPrev) ?>"
         href="<?= e($rc_base_url . $__rcSep . 'rate_month=' . $__rcPrev) ?>">
        <?= admin_icon('chevron-left', 14) ?> Prev
      </a>
      <a class="btn-sm btn-outline rcal__nav" data-month="<?= e($__rcNext) ?>"
         href="<?= e($rc_base_url . $__rcSep . 'rate_month=' . $__rcNext) ?>">
        Next <?= admin_icon('chevron-right', 14) ?>
      </a>
    </span>
  </div>

  <div class="rcal__months">
    <?php for ($__m = 0; $__m < $rc_months; $__m++):
      $__mFirst = date('Y-m-01', strtotime($__rcStart . " +{$__m} month"));
      $__mKey   = date('Y-m', strtotime($__mFirst));
      $__lead   = (int)date('N', strtotime($__mFirst)) - 1;   // Monday = 0
    ?>
    <div class="rcal__month">
      <?php if ($rc_months > 1): ?>
        <div class="rcal__mname"><?= e(date('F Y', strtotime($__mFirst))) ?></div>
      <?php endif; ?>
      <div class="rcal__grid">
        <?php foreach (['M','T','W','T','F','S','S'] as $__i => $__d): ?>
          <div class="rcal__dow"><?= $rc_compact ? $__d : ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][$__i] ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $__lead; $i++): ?>
          <div class="rcal__cell rcal__cell--blank"></div>
        <?php endfor; ?>

        <?php foreach ($__rcMap as $__ymd => $__n):
          if (strncmp($__ymd, $__mKey, 7) !== 0) continue;      // this month's days only
          $__cls = 'rcal__cell'
                 . ($__n['is_override'] ? ' rcal__cell--rate' : '')
                 . ($__ymd === $__rcToday ? ' rcal__cell--today' : '');
          $__price = (float)$__n['price'];
          $__title = date('D j M', strtotime($__ymd)) . ' · '
                   . ($__price > 0 ? $rc_currency . ' ' . $__rcMoney($__price) : 'no price set')
                   . ' · ' . ($__n['is_override']
                        ? ($__n['label'] !== null ? $__n['label'] : 'Rate override')
                        : 'Default room price');
        ?>
          <div class="<?= $__cls ?>" title="<?= e($__title) ?>">
            <div class="rcal__day"><?= (int)date('j', strtotime($__ymd)) ?></div>
            <div class="rcal__price<?= $__price <= 0 ? ' rcal__price--unset' : '' ?>"><?php
              // A room with no default price would otherwise render "0" on every
              // night, which reads as broken rather than as "nothing set yet".
              echo e($__rcCell($__price));
            ?></div>
            <?php if ($__n['label'] !== null): ?>
              <div class="rcal__lbl"><?= e($__n['label']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endfor; ?>
  </div>

  <p class="rcal__legend">
    <?php if ($rc_default_price > 0): ?>
      Prices in <?= e($rc_currency) ?> per night<?= $rc_compact ? ', thousands shortened (30k = 30,000)' : '' ?>.
      Yellow nights are overrides; the rest use the room's default price of
      <?= e($rc_currency) ?> <?= e($__rcMoney($rc_default_price)) ?>.
    <?php else: ?>
      Yellow nights are overrides. This room has <strong>no default price</strong>, so every
      other night shows “—” — set one under the room's <strong>Details</strong> tab, or these
      nights will quote as free.
    <?php endif; ?>
  </p>
</div>

<?php if (empty($GLOBALS['__rc_js_done'])): $GLOBALS['__rc_js_done'] = true; ?>
<script>
/* Prev/Next swap just the calendar instead of reloading the admin page.
   Delegated once per page: admin/rates.php renders one calendar per room, and
   each must step independently.

   The links stay real URLs on purpose — with JS off, or if the fetch fails, the
   browser follows them and the calendar still works. That fallback is why this
   is an enhancement rather than a rewrite of the navigation. */
(function () {
  if (window.__rcalSwapBound) return;      // survives an admin shell content swap
  window.__rcalSwapBound = true;

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a.rcal__nav');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;

    var cal = a.closest('[data-rcal]');
    if (!cal || !cal.dataset.roomId) return;   // no data to re-request with — let the link navigate

    e.preventDefault();
    if (cal.classList.contains('is-loading')) return;
    cal.classList.add('is-loading');

    var qs = new URLSearchParams({
      room:    cal.dataset.roomId,
      month:   a.dataset.month || '',
      months:  cal.dataset.months  || '3',
      compact: cal.dataset.compact || '1',
      base:    cal.dataset.base    || ''
    });

    fetch('/admin/rate-calendar-frag.php?' + qs.toString(), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var fresh = tmp.querySelector('[data-rcal]');
        if (!fresh) throw new Error('no calendar in response');
        cal.replaceWith(fresh);
      })
      .catch(function () {
        window.location.href = a.href;   // session expired, offline, 404 — fall back to a real load
      });
  });
})();
</script>
<?php endif; ?>
