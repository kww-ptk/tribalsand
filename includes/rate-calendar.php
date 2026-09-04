<?php
/**
 * Read-only month grid of nightly prices for ONE room.
 *
 * Config before include:
 *   $rc_room_id       int
 *   $rc_default_price float   the room's own price_amount
 *   $rc_month         string  'Y-m' (default: this month)
 *   $rc_currency      string  default 'USD'
 *   $rc_base_url      string  URL the prev/next links rebuild, WITHOUT rate_month
 *
 * Prices come from rates_nightly_map(), the same resolver room_stay_quote()
 * sums — so this grid always shows what a guest would actually be charged.
 * Month navigation is a plain link (rate_month=YYYY-MM), so it works with JS off.
 */
require_once __DIR__ . '/rates.php';

$rc_month         = $rc_month         ?? date('Y-m');
$rc_currency      = $rc_currency      ?? 'USD';
$rc_default_price = (float)($rc_default_price ?? 0);
$rc_base_url      = $rc_base_url      ?? '';

$__rcFirst = $rc_month . '-01';
if (!strtotime($__rcFirst)) { $rc_month = date('Y-m'); $__rcFirst = $rc_month . '-01'; }

$__rcStart = date('Y-m-01', strtotime($__rcFirst));
$__rcEndEx = date('Y-m-01', strtotime($__rcFirst . ' +1 month'));
$__rcPrev  = date('Y-m',    strtotime($__rcFirst . ' -1 month'));
$__rcNext  = date('Y-m',    strtotime($__rcFirst . ' +1 month'));
$__rcMap   = rates_nightly_map((int)$rc_room_id, $rc_default_price, $__rcStart, $__rcEndEx);
$__rcLead  = (int)date('N', strtotime($__rcStart)) - 1;   // Monday = 0
$__rcSep   = str_contains($rc_base_url, '?') ? '&' : '?';

/** Money for a grid cell: no decimals when the figure is whole. */
$__rcMoney = function (float $v): string {
    return number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2);
};
?>
<style>
.rcal__head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
.rcal__title { font-size:13px; font-weight:700; }
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
.rcal__legend { font-size:11px; color:var(--muted); margin-top:10px; }
</style>

<div class="rcal">
  <div class="rcal__head">
    <span class="rcal__title"><?= e(date('F Y', strtotime($__rcStart))) ?></span>
    <span style="display:flex;gap:6px">
      <a class="btn-sm btn-outline" href="<?= e($rc_base_url . $__rcSep . 'rate_month=' . $__rcPrev) ?>">
        <?= admin_icon('chevron-left', 14) ?> Prev
      </a>
      <a class="btn-sm btn-outline" href="<?= e($rc_base_url . $__rcSep . 'rate_month=' . $__rcNext) ?>">
        Next <?= admin_icon('chevron-right', 14) ?>
      </a>
    </span>
  </div>

  <div class="rcal__grid">
    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $__d): ?>
      <div class="rcal__dow"><?= $__d ?></div>
    <?php endforeach; ?>

    <?php for ($i = 0; $i < $__rcLead; $i++): ?>
      <div class="rcal__cell rcal__cell--blank"></div>
    <?php endfor; ?>

    <?php foreach ($__rcMap as $__ymd => $__n):
      $__cls = 'rcal__cell'
             . ($__n['is_override'] ? ' rcal__cell--rate' : '')
             . ($__ymd === date('Y-m-d') ? ' rcal__cell--today' : '');
      $__title = $__n['is_override']
        ? ($__n['label'] !== null ? $__n['label'] . ' — override' : 'Rate override')
        : 'Default room price';
    ?>
      <div class="<?= $__cls ?>" title="<?= e(date('D j M', strtotime($__ymd)) . ' · ' . $__title) ?>">
        <div class="rcal__day"><?= (int)date('j', strtotime($__ymd)) ?></div>
        <div class="rcal__price<?= (float)$__n['price'] <= 0 ? ' rcal__price--unset' : '' ?>"><?php
          // A room with no default price would otherwise render "0" on every
          // night, which reads as broken rather than as "nothing set yet".
          echo (float)$__n['price'] > 0 ? e($__rcMoney((float)$__n['price'])) : '—';
        ?></div>
        <?php if ($__n['label'] !== null): ?>
          <div class="rcal__lbl"><?= e($__n['label']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="rcal__legend">
    <?php if ($rc_default_price > 0): ?>
      Prices in <?= e($rc_currency) ?> per night. Yellow nights are overrides; the rest
      use the room's default price of <?= e($rc_currency) ?> <?= e($__rcMoney($rc_default_price)) ?>.
    <?php else: ?>
      Yellow nights are overrides. This room has <strong>no default price</strong>, so every
      other night shows “—” — set one under the room's <strong>Details</strong> tab, or these
      nights will quote as free.
    <?php endif; ?>
  </p>
</div>
