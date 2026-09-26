<?php
/**
 * Admin: POS sales — history, one sale (with void), CSV export, and the daily
 * Z-report. Owner, or a manager scoped to the outlets they manage
 * (pos_manageable_outlet_ids()); a posted/linked outlet or sale outside that
 * scope is ignored. Money is always shown PER CURRENCY — never summed across.
 *
 *   ?view=list (default)  filters: from/to (Nairobi days), outlet, staff, method, status, q
 *   ?sale=<id>            one sale: lines, payment, booking link, receipt, void
 *   ?view=z&date=&outlet= Z-report: by outlet × payment method, items, staff, voids
 *   ?export=csv           the filtered list as CSV
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pos.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_manager();

$pageTitle  = 'POS sales';
$activeMenu = 'pos_sales';
$supported  = pos_supported();
$self       = '/admin/pos-sales.php';
$me         = current_admin();
$scope      = $supported ? pos_manageable_outlet_ids($me) : [];
$outlets    = $supported ? pos_fetch_outlets($scope, false) : [];
$today      = frontdesk_today_ymd();
$ymd        = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : null;
$flash      = $_SESSION['poss2_flash'] ?? null; unset($_SESSION['poss2_flash']);

// ── Void (POST) ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $sid = (int)($_POST['sale_id'] ?? 0);
    $s = pos_fetch_sale($sid);
    if (!$s || !in_array((int)$s['outlet_id'], $scope, true)) {
        $_SESSION['poss2_flash'] = ['type' => 'error', 'msg' => 'That sale is not at an outlet you manage.'];
        header('Location: ' . $self); exit;
    }
    $r = pos_void_sale($sid, (string)($_POST['reason'] ?? ''), (int)$me['id']);
    $_SESSION['poss2_flash'] = $r['ok']
        ? ['type' => 'success', 'msg' => "{$s['reference']} voided — stock restored" . ($s['payment_method'] === 'room_charge' ? ' and the charge removed from the guest’s bill.' : '.')]
        : ['type' => 'error', 'msg' => $r['error']];
    header('Location: ' . $self . '?sale=' . $sid); exit;
}

// ── Filters ────────────────────────────────────────────────────────────────
$from   = $ymd($_GET['from'] ?? '') ?? $today;
$to     = $ymd($_GET['to'] ?? '') ?? $from;
if ($to < $from) [$from, $to] = [$to, $from];
$fOut   = (int)($_GET['outlet'] ?? 0);
$fOut   = in_array($fOut, $scope, true) ? $fOut : 0;
$fUser  = (int)($_GET['user'] ?? 0);
$fMeth  = isset(POS_PAYMENT_METHODS[$_GET['method'] ?? '']) ? (string)$_GET['method'] : '';
$fStat  = in_array($_GET['status'] ?? '', ['completed', 'voided'], true) ? (string)$_GET['status'] : '';
$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$filter = ['outlet_ids' => $fOut ? [$fOut] : $scope, 'from' => $from, 'to' => $to, 'user_id' => $fUser, 'method' => $fMeth, 'status' => $fStat, 'q' => $q];

// ── CSV export ─────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv' && $supported) {
    $res = pos_sales_query($filter, 1000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pos-sales-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference', 'Date', 'Time', 'Outlet', 'Staff', 'Customer', 'Type', 'Payment', 'Payment ref', 'Currency', 'Subtotal', 'Service', 'Total', 'Status', 'Void reason']);
    foreach ($res['rows'] as $r) {
        $t = strtotime((string)$r['created_at']);
        fputcsv($out, [$r['reference'], date('Y-m-d', $t), date('H:i', $t), $r['outlet_name'], $r['user_name'], $r['customer_name'], $r['customer_type'],
            POS_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method'], $r['payment_ref'], $r['currency'],
            $r['subtotal'], $r['service_charge'], $r['total'], $r['status'], $r['void_reason']]);
    }
    fclose($out); exit;
}

$view = isset($_GET['sale']) ? 'sale' : (($_GET['view'] ?? '') === 'z' ? 'z' : 'list');
$sale = null; $hold = null; $canVoid = false;
if ($view === 'sale' && $supported) {
    $sale = pos_fetch_sale((int)$_GET['sale']);
    if (!$sale || !in_array((int)$sale['outlet_id'], $scope, true)) $sale = null;
    if ($sale) {
        $hold = $sale['hold_id'] ? pos_fetch_hold((int)$sale['hold_id']) : null;
        $canVoid = $sale['status'] === 'completed' && pos_user_manages_outlet($me, (int)$sale['outlet_id']);
    }
}

$pg = paginate_params(25);
$res = ['rows' => [], 'total' => 0, 'sums' => []];
$meta = paginate_meta(0, 1, $pg['per']);
if ($view === 'list' && $supported && $scope) {
    $count = pos_sales_query($filter, 1);
    $meta = paginate_meta($count['total'], $pg['page'], $pg['per']);
    $res = pos_sales_query($filter, $meta['per'], $meta['offset']);
}

$zDate = $ymd($_GET['date'] ?? '') ?? $today;
$z = ($view === 'z' && $supported) ? pos_z_report($fOut ? [$fOut] : $scope, $zDate) : null;
$staffList = [];
if ($supported && $scope) {   // only people who sold at outlets this account manages
    $sp = []; $sph = [];
    foreach ($scope as $i => $o) { $sph[] = ":so{$i}"; $sp[":so{$i}"] = $o; }
    $staffList = db_query('SELECT DISTINCT a.id, COALESCE(a.name, a.email) AS name FROM pos_sales s JOIN admin_users a ON a.id = s.admin_user_id
                            WHERE s.outlet_id IN (' . implode(',', $sph) . ') ORDER BY 2', $sp)->fetchAll();
}
$m = fn($v, $c) => pos_money((float)$v, (string)$c);
$qs = fn(array $o) => $self . '?' . http_build_query(array_filter(array_merge(['from' => $from, 'to' => $to, 'outlet' => $fOut ?: null, 'user' => $fUser ?: null, 'method' => $fMeth ?: null, 'status' => $fStat ?: null, 'q' => $q ?: null], $o), fn($v) => $v !== null && $v !== ''));

// The list body is AJAX-swappable (dt toolkit): pager + search fetch &ajax=1.
ob_start();
if ($view === 'list'): ?>
  <?php if (!$res['rows']): ?>
    <?php dt_empty('No sales match these filters.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>When</th><th>Receipt</th><th>Outlet</th><th>Customer</th><th>Staff</th><th>Paid</th><th class="num">Total</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($res['rows'] as $r): $void = $r['status'] === 'voided'; ?>
      <tr class="dt-rowlink<?= $void ? ' pss-void' : '' ?>" data-href="<?= $self ?>?sale=<?= (int)$r['id'] ?>">
        <td class="text-muted"><?= e(date('j M, H:i', strtotime((string)$r['created_at']))) ?></td>
        <td><a href="<?= $self ?>?sale=<?= (int)$r['id'] ?>"><?= e($r['reference']) ?></a></td>
        <td><?= e($r['outlet_name']) ?></td>
        <td><?= e($r['customer_name']) ?><?php if ($r['customer_type'] === 'inhouse'): ?> <span class="badge badge--blue">Guest</span><?php endif; ?></td>
        <td class="text-muted"><?= e($r['user_name'] ?? '—') ?></td>
        <td><?= e(POS_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method']) ?></td>
        <td class="num"><?= e($m($r['total'], $r['currency'])) ?></td>
        <td><?= $void ? '<span class="badge badge--red">Voided</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php dt_pager($meta); ?>
<?php endif;
$listBody = ob_get_clean();
if ($pg['ajax'] && $view === 'list') { echo $listBody; exit; }

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $view === 'z' ? 'Daily close (Z-report)' : ($view === 'sale' ? 'Sale' : 'POS sales') ?></h1>
  <div class="actions">
    <?php if ($view === 'list'): ?>
      <a href="<?= e($qs(['view' => 'z', 'date' => $to])) ?>" class="btn-outline btn-sm"><?= admin_icon('calendar', 15) ?> Daily close</a>
      <a href="<?= e($qs(['export' => 'csv'])) ?>" class="btn-outline btn-sm"><?= admin_icon('download', 15) ?> CSV</a>
    <?php else: ?>
      <a href="<?= e($qs([])) ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> All sales</a>
    <?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Admin → Migrations) to set up the POS.</div>
<?php elseif (!$scope): ?>
  <?php dt_empty('You do not manage any POS outlets yet.'); ?>

<?php elseif ($view === 'sale'): ?>
  <?php if (!$sale): ?>
    <?php dt_empty('That sale doesn’t exist or isn’t at an outlet you manage.'); ?>
  <?php else: $cur = $sale['currency']; ?>
  <div class="pss-grid">
    <div class="card">
      <div class="card__head"><span class="card__title"><?= e($sale['reference']) ?></span>
        <?= $sale['status'] === 'voided' ? '<span class="badge badge--red">Voided</span>' : '<span class="badge badge--green">Completed</span>' ?></div>
      <div class="table-wrap"><table class="data-table pss-lines">
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($sale['lines'] as $l): ?>
          <tr><td><?= e($l['name']) ?>
              <?php if ((int)$l['owning_outlet_id'] !== (int)$sale['outlet_id']): ?><span class="badge badge--grey">cross-sold</span><?php endif; ?>
              <?php if (!empty($l['consignor_id'])): ?><span class="badge badge--purple">Consignment · owes <?= e($m(pos_consignor_owed($l), $cur)) ?></span><?php endif; ?></td>
            <td class="num"><?= (int)$l['qty'] ?></td><td class="num"><?= e($m($l['unit_price'], $cur)) ?></td><td class="num"><?= e($m($l['line_total'], $cur)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="3">Subtotal</td><td class="num"><?= e($m($sale['subtotal'], $cur)) ?></td></tr>
          <?php if ((float)$sale['service_charge'] > 0): ?><tr><td colspan="3">Service charge</td><td class="num"><?= e($m($sale['service_charge'], $cur)) ?></td></tr><?php endif; ?>
          <tr class="pss-total"><td colspan="3">Total</td><td class="num"><?= e($m($sale['total'], $cur)) ?></td></tr>
        </tfoot>
      </table></div>
    </div>
    <div>
      <div class="card"><div class="card__body pss-facts">
        <div><span>When</span><strong><?= e(date('j M Y, H:i', strtotime((string)$sale['created_at']))) ?></strong></div>
        <div><span>Outlet</span><strong><?= e($sale['outlet_name']) ?></strong></div>
        <div><span>Served by</span><strong><?= e($sale['user_name'] ?? '—') ?></strong></div>
        <div><span>Customer</span><strong><?= e($sale['customer_name']) ?></strong></div>
        <?php if ($hold): ?><div><span>Booking</span><strong><a href="/admin/booking.php?hold=<?= (int)$hold['id'] ?>&tab=bill"><?= e($hold['guest_name']) ?> · <?= e($hold['unit_name'] ?: $hold['room_name']) ?></a></strong></div><?php endif; ?>
        <div><span>Payment</span><strong><?= e(POS_PAYMENT_METHODS[$sale['payment_method']] ?? $sale['payment_method']) ?><?= $sale['payment_ref'] ? ' · ' . e($sale['payment_ref']) : '' ?></strong></div>
        <?php if ($sale['cash_tendered'] !== null): ?><div><span>Cash / change</span><strong><?= e($m($sale['cash_tendered'], $cur)) ?> / <?= e($m(max(0, pos_from_cents(pos_cents($sale['cash_tendered']) - pos_cents($sale['total']))), $cur)) ?></strong></div><?php endif; ?>
        <?php if ($sale['status'] === 'voided'): ?><div><span>Voided</span><strong><?= e(date('j M Y, H:i', strtotime((string)$sale['voided_at']))) ?> — <?= e((string)$sale['void_reason']) ?></strong></div><?php endif; ?>
        <a href="/pos/receipt.php?sale=<?= (int)$sale['id'] ?>" target="_blank" rel="noopener" class="btn-outline btn-sm" style="margin-top:6px"><?= admin_icon('external-link', 14) ?> Receipt</a>
      </div></div>
      <?php if ($canVoid): ?>
      <div class="card" style="margin-top:14px"><div class="card__head"><span class="card__title">Void this sale</span></div>
        <div class="card__body" style="padding:14px 18px">
          <p class="text-muted" style="font-size:12.5px;margin:0 0 10px">Puts the stock back<?= $sale['payment_method'] === 'room_charge' ? ' and removes the charge from the guest’s bill' : '' ?>. It can’t be undone — ring a new sale if needed.</p>
          <form method="POST" action="<?= $self ?>">
            <?= csrf_field() ?><input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">
            <input name="reason" class="inp" maxlength="200" placeholder="Reason, e.g. wrong item" required minlength="3" style="width:100%">
            <button type="submit" class="btn-outline btn-sm pss-voidbtn" data-confirm="Void <?= e($sale['reference']) ?>?"><?= admin_icon('ban', 14) ?> Void sale</button>
          </form>
        </div></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>
  <!-- Filters (shared by the list and the Z-report) -->
  <form method="GET" action="<?= $self ?>" class="pss-filters" id="pssFilters">
    <?php if ($view === 'z'): ?>
      <input type="hidden" name="view" value="z">
      <span class="text-muted">Day</span>
      <button type="button" class="dp-btn inp" data-dp-target="pssDate" data-dp-past data-dp-placeholder="Day"><?= e(date('j M Y', strtotime($zDate))) ?></button>
      <input type="hidden" id="pssDate" name="date" value="<?= e($zDate) ?>" data-autosubmit>
    <?php else: ?>
      <?php $chips = ['Today' => [$today, $today], 'Yesterday' => [date('Y-m-d', strtotime($today . ' -1 day')), date('Y-m-d', strtotime($today . ' -1 day'))],
                      'Last 7 days' => [date('Y-m-d', strtotime($today . ' -6 days')), $today], 'This month' => [date('Y-m-01', strtotime($today)), $today]]; ?>
      <?php foreach ($chips as $lbl => [$cf, $ct]): ?>
        <a href="<?= e($qs(['from' => $cf, 'to' => $ct, 'page' => null])) ?>" class="pss-chip<?= $from === $cf && $to === $ct ? ' is-on' : '' ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
      <button type="button" class="dp-btn inp" data-dp-target="pssFrom" data-dp-past data-dp-placeholder="From"><?= e(date('j M Y', strtotime($from))) ?></button>
      <input type="hidden" id="pssFrom" name="from" value="<?= e($from) ?>" data-autosubmit>
      <span class="text-muted">to</span>
      <button type="button" class="dp-btn inp" data-dp-target="pssTo" data-dp-past data-dp-placeholder="To"><?= e(date('j M Y', strtotime($to))) ?></button>
      <input type="hidden" id="pssTo" name="to" value="<?= e($to) ?>" data-autosubmit>
    <?php endif; ?>
    <?php if (count($outlets) > 1): ?>
    <select name="outlet" class="eselect" onchange="this.form.submit()" aria-label="Outlet">
      <option value="0">All outlets</option>
      <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $fOut === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($view === 'list'): ?>
    <select name="method" class="eselect" onchange="this.form.submit()" aria-label="Payment">
      <option value="">All payments</option>
      <?php foreach (POS_PAYMENT_METHODS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $fMeth === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
    </select>
    <?php if ($staffList): ?>
    <select name="user" class="eselect" onchange="this.form.submit()" aria-label="Staff">
      <option value="0">All staff</option>
      <?php foreach ($staffList as $sf): ?><option value="<?= (int)$sf['id'] ?>" <?= $fUser === (int)$sf['id'] ? 'selected' : '' ?>><?= e($sf['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="status" class="eselect" onchange="this.form.submit()" aria-label="Status">
      <option value="">Completed + voided</option>
      <option value="completed" <?= $fStat === 'completed' ? 'selected' : '' ?>>Completed</option>
      <option value="voided" <?= $fStat === 'voided' ? 'selected' : '' ?>>Voided</option>
    </select>
    <?php endif; ?>
  </form>

  <?php if ($view === 'list'): ?>
    <div class="pss-kpis">
      <?php if (!$res['sums']): ?><div class="pss-kpi"><div class="n">0</div><div class="l">Completed sales</div></div><?php endif; ?>
      <?php foreach ($res['sums'] as $su): ?>
        <div class="pss-kpi"><div class="n"><?= e($m($su['total'], $su['currency'])) ?></div><div class="l"><?= (int)$su['n'] ?> sale<?= (int)$su['n'] === 1 ? '' : 's' ?> · <?= e($su['currency']) ?><?= (float)$su['service'] > 0 ? ' · incl. ' . e($m($su['service'], $su['currency'])) . ' service' : '' ?></div></div>
      <?php endforeach; ?>
    </div>
    <div class="card dt" data-dt>
      <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Receipt # or customer…']); ?>
      <div class="dt-body" data-dt-body><?= $listBody ?></div>
    </div>

  <?php else: /* Z-report */ ?>
    <?php if (!$z['by_method'] && !$z['voids']): ?>
      <?php dt_empty('No sales on ' . date('j M Y', strtotime($zDate)) . '.'); ?>
    <?php else:
      $byOutlet = []; $tot = [];
      foreach ($z['by_method'] as $r) { $byOutlet[$r['outlet']][] = $r; $tot[$r['currency']][$r['payment_method']] = ($tot[$r['currency']][$r['payment_method']] ?? 0) + (float)$r['total']; } ?>
      <div class="pss-kpis">
        <?php foreach ($tot as $c => $methods): ?>
        <div class="pss-kpi"><div class="n"><?= e($m(array_sum($methods), $c)) ?></div><div class="l"><?= e($c) ?> taken · <?= e(implode(' · ', array_map(fn($k, $v) => (POS_PAYMENT_METHODS[$k] ?? $k) . ' ' . $m($v, $c), array_keys($methods), $methods))) ?></div></div>
        <?php endforeach; ?>
        <?php foreach ($z['voids'] as $vd): ?><div class="pss-kpi pss-kpi--warn"><div class="n"><?= (int)$vd['n'] ?> void<?= (int)$vd['n'] === 1 ? '' : 's' ?></div><div class="l"><?= e($m($vd['total'], $vd['currency'])) ?> reversed</div></div><?php endforeach; ?>
      </div>
      <div class="pss-grid">
        <div class="card">
          <div class="card__head"><span class="card__title">By outlet and payment</span><span class="text-muted" style="font-size:12.5px">Count the drawer against Cash; card slips against Card</span></div>
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Outlet</th><th>Payment</th><th class="num">Sales</th><th class="num">Service</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($byOutlet as $on => $rs): foreach ($rs as $i => $r): ?>
              <tr><td><?= $i === 0 ? '<strong>' . e($on) . '</strong>' : '' ?></td><td><?= e(POS_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method']) ?></td>
                <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= e($m($r['service'], $r['currency'])) ?></td><td class="num"><strong><?= e($m($r['total'], $r['currency'])) ?></strong></td></tr>
            <?php endforeach; endforeach; ?>
            </tbody>
          </table></div>
        </div>
        <div class="card">
          <div class="card__head"><span class="card__title">By staff</span></div>
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Staff</th><th class="num">Sales</th><th class="num">Total</th></tr></thead>
            <tbody><?php foreach ($z['staff'] as $r): ?><tr><td><?= e($r['name']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= e($m($r['total'], $r['currency'])) ?></td></tr><?php endforeach; ?></tbody>
          </table></div>
        </div>
      </div>
      <div class="card" style="margin-top:18px">
        <div class="card__head"><span class="card__title">Items sold</span><span class="text-muted" style="font-size:12.5px">Grouped by the outlet that owns the item</span></div>
        <div class="table-wrap"><table class="data-table">
          <thead><tr><th>Item</th><th>Outlet</th><th class="num">Qty</th><th class="num">Gross</th></tr></thead>
          <tbody><?php foreach ($z['items'] as $r): ?><tr><td><?= e($r['name']) ?></td><td class="text-muted"><?= e($r['outlet']) ?></td><td class="num"><?= (int)$r['qty'] ?></td><td class="num"><?= e($m($r['gross'], $r['currency'])) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<style>
.pss-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:-6px 0 18px;font-size:13px}
.pss-filters .dp-btn{width:auto;min-width:130px;flex:0 0 auto}
.pss-chip{padding:7px 13px;border-radius:18px;border:1px solid var(--border);background:var(--white,#fff);color:var(--muted);text-decoration:none;font-size:12.5px}
.pss-chip.is-on{background:var(--brand);border-color:var(--brand);color:#fff}
.pss-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;margin-bottom:18px}
.pss-kpi{background:var(--white,#fff);border:1px solid var(--border);border-radius:12px;padding:14px 16px}
.pss-kpi .n{font-size:22px;font-weight:600;font-variant-numeric:tabular-nums}
.pss-kpi .l{font-size:12.5px;color:var(--muted);margin-top:2px}
.pss-kpi--warn .n{color:var(--red)}
.pss-void td{opacity:.6}
.pss-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);gap:18px;align-items:start}
@media (max-width:900px){.pss-grid{grid-template-columns:1fr}}
.pss-grid .table-wrap .data-table{min-width:0}
.data-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.pss-lines tfoot td{padding:8px 14px;color:var(--muted)}
.pss-total td{color:var(--text)!important;font-weight:600;font-size:15px}
.pss-facts{display:flex;flex-direction:column;gap:10px;padding:16px 18px}
.pss-facts div{display:flex;justify-content:space-between;gap:14px;font-size:13.5px}
.pss-facts span{color:var(--muted)}
.pss-facts strong{text-align:right;font-weight:500}
.pss-voidbtn{margin-top:10px}
</style>
<script>
document.querySelectorAll('#pssFilters [data-autosubmit]').forEach(function (i) {
  i.addEventListener('change', function () { var f = document.getElementById('pssFilters'); var p = f.querySelector('input[name=page]'); if (p) p.value = 1; f.submit(); });
});
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
