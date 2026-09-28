<?php
/**
 * Reports page — the Point of sale section (shop, spa, kite school, experiences).
 * Expects $pos (pos_report_summarize() or null), $qs, $monthLabel, $fSource.
 * Figures exclude tips (they belong to staff) and voided sales; per currency only.
 */
if (!pos_supported()) return;
?>
<div class="rp-pos" id="pos">
  <div class="rp-pos__head">
    <h2>Point of sale</h2>
    <?php if ($pos && $pos['currencies']): ?>
    <a href="/admin/reports.php?<?= e($qs(['export' => 'pos_csv'])) ?>" class="btn-sm btn-outline" data-tip="Download POS takings as CSV"><?= admin_icon('download', 15) ?> Export CSV</a>
    <?php endif; ?>
  </div>
  <?php if ($fSource !== ''): ?>
    <p class="text-muted rp-pos__note">POS takings have no booking source — clear the Source filter to see them.</p>
  <?php elseif (!$pos || !$pos['currencies']): ?>
    <div class="card"><div class="card__body" style="padding:24px;text-align:center;color:var(--muted)">No till sales in this range.</div></div>
  <?php else: ?>
    <p class="text-muted rp-pos__note">Shop, spa, kite school and experiences, by the day of the sale. Sales exclude tips (they belong to staff) and voided sales. Room-charged sales are included here and also appear on the guest's room bill.</p>
    <?php foreach ($pos['currencies'] as $cur => $t): ?>
    <div class="rp-curblock">
      <p class="rp-curblock__cur"><?= e($cur) ?></p>
      <div class="rp-kpis">
        <div class="rp-kpi rp-kpi--rev"><div class="n"><?= e(bookings_money($t['sales'] / 100, $cur)) ?></div><div class="l">Sales (<?= (int)$t['n'] ?>)</div></div>
        <div class="rp-kpi"><div class="n"><?= e(bookings_money($t['net'] / 100, $cur)) ?></div><div class="l">Net of VAT</div></div>
        <div class="rp-kpi"><div class="n"><?= e(bookings_money($t['vat'] / 100, $cur)) ?></div><div class="l">VAT</div></div>
        <div class="rp-kpi"><div class="n"><?= e(bookings_money($t['tips'] / 100, $cur)) ?></div><div class="l">Tips (for staff)</div></div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="rp-grid">
      <div class="card">
        <div class="card__head"><span class="card__title">By outlet</span></div>
        <div class="card__body" style="padding:0"><div class="table-wrap">
          <table class="data-table"><thead><tr><th>Outlet</th><th>Currency</th><th style="text-align:right">Sales</th><th style="text-align:right">Net of VAT</th><th style="text-align:right">Room-charged</th><th style="text-align:right">Sales count</th></tr></thead>
          <tbody>
          <?php foreach ($pos['by_outlet'] as $name => $byCur): foreach ($byCur as $cur => $t): ?>
            <tr><td><strong><?= e($name) ?></strong></td><td><?= e($cur) ?></td>
                <td style="text-align:right"><?= e(bookings_money($t['sales'] / 100, $cur)) ?></td>
                <td style="text-align:right"><?= e(bookings_money($t['net'] / 100, $cur)) ?></td>
                <td style="text-align:right"><?= e(bookings_money($t['room_charged'] / 100, $cur)) ?></td>
                <td style="text-align:right"><?= (int)$t['n'] ?></td></tr>
          <?php endforeach; endforeach; ?>
          </tbody></table>
        </div></div>
      </div>
      <div class="card">
        <div class="card__head"><span class="card__title">By month</span></div>
        <div class="card__body" style="padding:0"><div class="table-wrap">
          <table class="data-table"><thead><tr><th>Month</th><th>Currency</th><th style="text-align:right">Sales</th><th style="text-align:right">Net of VAT</th><th style="text-align:right">Sales count</th></tr></thead>
          <tbody>
          <?php foreach ($pos['by_month'] as $ym => $byCur): foreach ($byCur as $cur => $t): ?>
            <tr><td><strong><?= e($monthLabel($ym)) ?></strong></td><td><?= e($cur) ?></td>
                <td style="text-align:right"><?= e(bookings_money($t['sales'] / 100, $cur)) ?></td>
                <td style="text-align:right"><?= e(bookings_money($t['net'] / 100, $cur)) ?></td>
                <td style="text-align:right"><?= (int)$t['n'] ?></td></tr>
          <?php endforeach; endforeach; ?>
          </tbody></table>
        </div></div>
      </div>
    </div>
  <?php endif; ?>
</div>
<style>
.rp-pos{margin-top:30px;padding-top:22px;border-top:1px solid var(--border,#e7ded7)}
.rp-pos__head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px}
.rp-pos__head h2{font-size:17px;font-weight:600;margin:0}
.rp-pos__note{font-size:12px;margin:0 0 14px;max-width:860px}
</style>
