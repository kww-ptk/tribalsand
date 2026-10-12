<?php
/**
 * Admin: POS sales — history, one sale (with void), CSV export, and the daily
 * Z-report. Owner, or a manager scoped to the outlets they manage
 * (pos_manageable_outlet_ids()); a posted/linked outlet or sale outside that
 * scope is ignored. Money is always shown PER CURRENCY — never summed across.
 *
 *   ?view=list (default)  filters: from/to (Nairobi days), outlet, staff, method, status, q
 *   ?sale=<id>            one sale: lines, payment, booking link, receipt, void
 *                         (&drawer=1 → just the side-drawer fragment the list opens)
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
    // From the side drawer: back to the same filtered list, with the sale open again.
    $ret = (string)($_POST['ret'] ?? '');
    if (preg_match('~^/admin/pos-sales\.php(\?[^\s#]*)?$~', $ret)) {
        $u = parse_url($ret); parse_str($u['query'] ?? '', $qp); $qp['open'] = 'sale-' . $sid;
        header('Location: ' . $self . '?' . http_build_query($qp)); exit;
    }
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
    fputcsv($out, ['Reference', 'Date', 'Time', 'Outlet', 'Staff', 'Customer', 'Type', 'Payment', 'Payment ref', 'Currency', 'Subtotal', 'Service', 'VAT', 'VAT included', 'Tip', 'Total',
                   'Room bill currency', 'Room bill amount', 'FX rate', 'Status', 'Void reason'], ',', '"', '');
    foreach ($res['rows'] as $r) {
        $t = strtotime((string)$r['created_at']);
        fputcsv($out, [$r['reference'], date('Y-m-d', $t), date('H:i', $t), $r['outlet_name'], $r['user_name'], $r['customer_name'], $r['customer_type'],
            POS_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method'], $r['payment_ref'], $r['currency'],
            $r['subtotal'], $r['service_charge'], $r['vat_amount'] ?? 0, pos_bool($r['vat_inclusive'] ?? true) ? 'yes' : 'no', $r['tip_amount'] ?? 0, $r['total'],
            $r['bill_currency'] ?? '', $r['bill_amount'] ?? '', $r['fx_rate'] ?? '', $r['status'], $r['void_reason']], ',', '"', '');
    }
    fclose($out); exit;
}

$view = isset($_GET['sale']) ? 'sale' : (($_GET['view'] ?? '') === 'z' ? 'z' : 'list');
$sale = null; $hold = null; $canVoid = false; $sig = null;
if ($view === 'sale' && $supported) {
    $sale = pos_fetch_sale((int)$_GET['sale']);
    if (!$sale || !in_array((int)$sale['outlet_id'], $scope, true)) $sale = null;
    if ($sale) {
        $hold = $sale['hold_id'] ? pos_fetch_hold((int)$sale['hold_id']) : null;
        $canVoid = $sale['status'] === 'completed' && pos_user_manages_outlet($me, (int)$sale['outlet_id']);
        $sig = $sale['signed'] ? pos_sale_signature((int)$sale['id']) : null;
    }
}
$m = fn($v, $c) => pos_money((float)$v, (string)$c);

/**
 * One sale as the side-drawer fragment (head · body · foot) — the list opens it with
 * ?sale=<id>&drawer=1; the full ?sale=<id> page shows the same thing in a card.
 */
$saleDetail = function (array $sale, ?array $hold, bool $canVoid, ?array $sig, bool $inDrawer) use ($m, $self): void {
    $cur = (string)$sale['currency'];
    $vA = (float)($sale['vat_amount'] ?? 0); $vIn = pos_bool($sale['vat_inclusive'] ?? true);
    $vP = rtrim(rtrim((string)($sale['vat_pct'] ?? '0'), '0'), '.');
    ?>
    <div class="adrawer__head">
      <h2><?= e($sale['reference']) ?> · <?= e($m($sale['total'], $cur)) ?></h2>
      <p><?= e(date('j M Y, H:i', strtotime((string)$sale['created_at']))) ?> · <?= e($sale['outlet_name']) ?></p>
      <div class="badges">
        <?= $sale['status'] === 'voided' ? '<span class="badge badge--red">Voided</span>' : '<span class="badge badge--green">Completed</span>' ?>
        <span class="badge <?= $sale['payment_method'] === 'room_charge' ? 'badge--blue' : 'badge--grey' ?>"><?= e(POS_PAYMENT_METHODS[$sale['payment_method']] ?? $sale['payment_method']) ?></span>
        <?php if (!empty($sale['offline_sold_at'])): ?><span class="badge badge--grey" data-tip="Saved on the till without a connection and sent later — this is when it really happened">Rung up offline <?= e(date('j M H:i', strtotime((string)$sale['offline_sold_at']))) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="adrawer__body">
      <div class="adrawer__sec">
        <h3>Items</h3>
        <div class="adrawer__lines">
          <?php foreach ($sale['lines'] as $l): ?>
          <div><span><?= e($l['name']) ?> × <?= (int)$l['qty'] ?>
            <?php if ((int)$l['owning_outlet_id'] !== (int)$sale['outlet_id']): ?> <span class="badge badge--grey">cross-sold</span><?php endif; ?>
            <?php if (!empty($l['consignor_id'])): ?> <span class="badge badge--purple">Consignment · owes <?= e($m(pos_consignor_owed($l), $cur)) ?></span><?php endif; ?></span>
            <span class="num"><?= e($m($l['line_total'], $cur)) ?></span></div>
          <?php endforeach; ?>
          <div class="is-sub"><span>Subtotal</span><span><?= e($m($sale['subtotal'], $cur)) ?></span></div>
          <?php if ((float)$sale['service_charge'] > 0): ?><div class="is-sub"><span>Service charge<?= (float)($sale['service_pct'] ?? 0) > 0 ? ' (' . e(rtrim(rtrim((string)$sale['service_pct'], '0'), '.')) . '%)' : '' ?></span><span><?= e($m($sale['service_charge'], $cur)) ?></span></div><?php endif; ?>
          <?php if ($vA > 0 && !$vIn): ?><div class="is-sub"><span>VAT (<?= e($vP) ?>%)</span><span><?= e($m($vA, $cur)) ?></span></div><?php endif; ?>
          <?php if ((float)($sale['tip_amount'] ?? 0) > 0): ?><div class="is-sub"><span>Tip</span><span><?= e($m($sale['tip_amount'], $cur)) ?></span></div><?php endif; ?>
          <div class="is-total"><span>Total</span><span><?= e($m($sale['total'], $cur)) ?></span></div>
          <?php if ($vA > 0 && $vIn): ?><div class="is-sub"><span>Includes VAT <?= e($vP) ?>%</span><span><?= e($m($vA, $cur)) ?></span></div><?php endif; ?>
        </div>
      </div>
      <div class="adrawer__sec">
        <h3>Sale</h3>
        <dl class="adrawer__kv">
          <dt>Served by</dt><dd><?= e($sale['user_name'] ?? '—') ?></dd>
          <dt>Customer</dt><dd><?= e($sale['customer_name']) ?></dd>
          <?php if ($hold): ?>
          <dt>Booking</dt><dd><a href="/admin/booking.php?hold=<?= (int)$hold['id'] ?>&tab=bill"><?= e($hold['guest_name']) ?> · <?= e($hold['unit_name'] ?: $hold['room_name']) ?></a></dd>
          <dt>Staying at</dt><dd><?= e($sale['guest_venue_name'] ?? ($hold['venue_name'] ?? '—')) ?></dd>
          <?php endif; ?>
          <?php if (!empty($sale['bill_currency']) && $sale['bill_currency'] !== $cur && $sale['bill_amount'] !== null): ?>
          <dt>On the room bill</dt><dd><?= e(pos_money((float)$sale['bill_amount'], (string)$sale['bill_currency'])) ?> <span class="text-muted">(1 <?= e($sale['bill_currency']) ?> = <?= e(rtrim(rtrim(number_format((float)$sale['fx_rate'], 4, '.', ''), '0'), '.')) ?> <?= e($cur) ?>)</span></dd>
          <?php endif; ?>
          <dt>Payment</dt><dd><?= e(POS_PAYMENT_METHODS[$sale['payment_method']] ?? $sale['payment_method']) ?><?= $sale['payment_ref'] ? ' · ' . e($sale['payment_ref']) : '' ?></dd>
          <?php if ($sale['cash_tendered'] !== null): ?><dt>Cash / change</dt><dd><?= e($m($sale['cash_tendered'], $cur)) ?> / <?= e($m(max(0, pos_from_cents(pos_cents($sale['cash_tendered']) - pos_cents($sale['total']))), $cur)) ?></dd><?php endif; ?>
          <?php if ($sale['status'] === 'voided'): ?><dt>Voided</dt><dd><?= e(date('j M Y, H:i', strtotime((string)$sale['voided_at']))) ?> — <?= e((string)$sale['void_reason']) ?></dd><?php endif; ?>
        </dl>
      </div>
      <?php if ($sig): ?>
      <div class="adrawer__sec pss-sig">
        <h3>Guest signature</h3>
        <img src="<?= e($sig['signature']) ?>" alt="Guest signature">
        <em><?= e($sig['signer_name'] ?? '') ?> · <?= e(date('j M Y, H:i', strtotime((string)$sig['signed_at']))) ?></em>
      </div>
      <?php endif; ?>
      <?php if ($canVoid): ?>
      <form method="POST" action="<?= $self ?>" class="adrawer__sec pss-void" id="pssVoid<?= (int)$sale['id'] ?>">
        <h3>Void this sale</h3>
        <p class="text-muted" style="font-size:12.5px;margin:0">Puts the stock back<?= $sale['payment_method'] === 'room_charge' ? ' and removes the charge from the guest’s bill' : '' ?>. It can’t be undone — ring a new sale if needed.</p>
        <?= csrf_field() ?><input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">
        <?php if ($inDrawer): ?><input type="hidden" name="ret" value="" data-drawer-ret><?php endif; ?>
        <input name="reason" class="inp" maxlength="200" placeholder="Reason, e.g. wrong item" required minlength="3" style="width:100%">
      </form>
      <?php endif; ?>
    </div>
    <div class="adrawer__foot">
      <a href="/pos/receipt.php?sale=<?= (int)$sale['id'] ?>" target="_blank" rel="noopener" class="btn-outline btn-sm"><?= admin_icon('external-link', 14) ?> Receipt</a>
      <?php if ($canVoid): ?><button type="submit" form="pssVoid<?= (int)$sale['id'] ?>" class="btn-danger btn-sm" data-confirm="Void <?= e($sale['reference']) ?>?" data-confirm-title="Void this sale?" data-confirm-label="Void sale"><?= admin_icon('ban', 14) ?> Void sale</button><?php endif; ?>
    </div>
    <?php
};

// The side drawer asks for just the fragment.
if ($view === 'sale' && !empty($_GET['drawer'])) {
    if (!$sale) { echo '<div class="adrawer__head"><h2>Sale not found</h2><p>It doesn’t exist or isn’t at an outlet you manage.</p></div>'; exit; }
    $saleDetail($sale, $hold, $canVoid, $sig, true);
    exit;
}

$pg = paginate_params(25);
$res = ['rows' => [], 'total' => 0, 'sums' => [], 'by_method' => [], 'voided' => []];
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
$qs = fn(array $o) => $self . '?' . http_build_query(array_filter(array_merge(['from' => $from, 'to' => $to, 'outlet' => $fOut ?: null, 'user' => $fUser ?: null, 'method' => $fMeth ?: null, 'status' => $fStat ?: null, 'q' => $q ?: null], $o), fn($v) => $v !== null && $v !== ''));

// The list body is AJAX-swappable (dt toolkit): pager + search fetch &ajax=1.
ob_start();
if ($view === 'list'): ?>
  <?php if (!$res['rows']): ?>
    <?php dt_empty('No sales match these filters.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>When</th><th>Receipt</th><th>Outlet</th><th>Customer</th><th>Staff</th><th>Paid</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($res['rows'] as $r): $void = $r['status'] === 'voided'; $dUrl = $self . '?sale=' . (int)$r['id']; ?>
      <tr class="<?= $void ? 'pss-void' : '' ?>" data-drawer="<?= e($dUrl) ?>" data-drawer-key="sale-<?= (int)$r['id'] ?>">
        <td class="text-muted" style="white-space:nowrap"><?= e(date($from === $to ? 'H:i' : 'j M, H:i', strtotime((string)$r['created_at']))) ?></td>
        <td><a href="<?= e($dUrl) ?>" data-drawer="<?= e($dUrl) ?>" data-drawer-key="sale-<?= (int)$r['id'] ?>"><strong><?= e($r['reference']) ?></strong></a></td>
        <td><?= e($r['outlet_name']) ?></td>
        <td><?= e($r['customer_name']) ?><?php if ($r['customer_type'] === 'inhouse'): ?> <span class="badge badge--blue">Guest</span><?php endif; ?></td>
        <td class="text-muted"><?= e($r['user_name'] ?? '—') ?></td>
        <td><span class="badge <?= $r['payment_method'] === 'room_charge' ? 'badge--blue' : 'badge--grey' ?>"><?= e(POS_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method']) ?></span></td>
        <td class="num"><strong><?= e($m($r['total'], $r['currency'])) ?></strong><?= $void ? ' <span class="badge badge--red">Voided</span>' : '' ?></td>
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

// Summary tiles: per currency (money is never summed across currencies).
$methodColors = ['room_charge' => 'var(--brand)', 'card' => '#7fa9b1', 'mobile_money' => 'var(--accent)', 'cash' => '#cbbfb4', 'other' => '#e2d9cd'];
$byCur = [];
foreach ($res['sums'] as $su) $byCur[$su['currency']] = ['sum' => $su, 'methods' => [], 'voided' => null];
foreach ($res['by_method'] as $bm) if (isset($byCur[$bm['currency']])) $byCur[$bm['currency']]['methods'][$bm['payment_method']] = $bm;
foreach ($res['voided'] as $vd) { $byCur[$vd['currency']] = ($byCur[$vd['currency']] ?? ['sum' => ['currency' => $vd['currency'], 'n' => 0, 'total' => 0, 'service' => 0, 'vat' => 0, 'tips' => 0], 'methods' => []]); $byCur[$vd['currency']]['voided'] = $vd; }
$periodLabel = $from === $to ? ($from === $today ? 'today' : date('j M', strtotime($from))) : date('j M', strtotime($from)) . ' – ' . date('j M', strtotime($to));
?>
<div class="page-header">
  <h1><?= $view === 'z' ? 'Daily close' : ($view === 'sale' ? 'Sale' : 'Sales') ?></h1>
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
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Settings → Your account → Migrations) to set up the POS.</div>
<?php elseif (!$scope): ?>
  <?php dt_empty('You do not manage any POS outlets yet.'); ?>

<?php elseif ($view === 'sale'): ?>
  <?php if (!$sale): ?>
    <?php dt_empty('That sale doesn’t exist or isn’t at an outlet you manage.'); ?>
  <?php else: ?>
  <div class="card pss-page"><?php $saleDetail($sale, $hold, $canVoid, $sig, false); ?></div>
  <?php endif; ?>

<?php else: ?>
  <!-- Filters (shared by the list and the daily close) -->
  <form method="GET" action="<?= $self ?>" class="pos-toolbar" id="pssFilters">
    <?php if ($view === 'z'): ?>
      <input type="hidden" name="view" value="z">
      <span class="text-muted">Day</span>
      <button type="button" class="dp-btn inp" data-dp-target="pssDate" data-dp-past data-dp-placeholder="Day"><?= e(date('j M Y', strtotime($zDate))) ?></button>
      <input type="hidden" id="pssDate" name="date" value="<?= e($zDate) ?>" data-autosubmit>
    <?php else: ?>
      <?php $chips = ['Today' => [$today, $today], 'Yesterday' => [date('Y-m-d', strtotime($today . ' -1 day')), date('Y-m-d', strtotime($today . ' -1 day'))],
                      '7 days' => [date('Y-m-d', strtotime($today . ' -6 days')), $today], 'This month' => [date('Y-m-01', strtotime($today)), $today]]; ?>
      <div class="pos-chips">
        <?php foreach ($chips as $lbl => [$cf, $ct]): ?>
          <a href="<?= e($qs(['from' => $cf, 'to' => $ct, 'page' => null])) ?>" class="pos-chip<?= $from === $cf && $to === $ct ? ' is-on' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
      </div>
      <button type="button" class="dp-btn inp" data-dp-target="pssFrom" data-dp-past data-dp-placeholder="From"><?= e(date('j M Y', strtotime($from))) ?></button>
      <input type="hidden" id="pssFrom" name="from" value="<?= e($from) ?>" data-autosubmit>
      <span class="text-muted">to</span>
      <button type="button" class="dp-btn inp" data-dp-target="pssTo" data-dp-past data-dp-placeholder="To"><?= e(date('j M Y', strtotime($to))) ?></button>
      <input type="hidden" id="pssTo" name="to" value="<?= e($to) ?>" data-autosubmit>
    <?php endif; ?>
    <span class="sp"></span>
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
    <div class="pos-kpis">
      <?php if (!$byCur): ?>
        <div class="pos-kpi pos-kpi--dark"><span>Takings <?= e($periodLabel) ?></span><b>0</b><small>No completed sales</small></div>
      <?php endif; ?>
      <?php foreach ($byCur as $cur => $bc): $su = $bc['sum']; $n = (int)$su['n']; $room = (float)($bc['methods']['room_charge']['total'] ?? 0); ?>
        <div class="pos-kpi pos-kpi--dark"><span>Takings <?= e($periodLabel) ?><?= count($byCur) > 1 ? ' · ' . e($cur) : '' ?></span><b><?= e($m($su['total'], $cur)) ?></b>
          <small><?= $n ?> sale<?= $n === 1 ? '' : 's' ?><?= $bc['voided'] ? ' · ' . (int)$bc['voided']['n'] . ' voided' : '' ?><?= (float)$su['tips'] > 0 ? ' · ' . e($m($su['tips'], $cur)) . ' tips' : '' ?></small></div>
        <div class="pos-kpi"><span>Average sale</span><b><?= $n ? e($m((float)$su['total'] / $n, $cur)) : '—' ?></b>
          <small><?= (float)$su['vat'] > 0 ? e($m($su['vat'], $cur)) . ' VAT' : 'No VAT' ?><?= (float)$su['service'] > 0 ? ' · ' . e($m($su['service'], $cur)) . ' service' : '' ?></small></div>
        <div class="pos-kpi"><span>Charged to rooms</span><b><?= e($m($room, $cur)) ?></b>
          <small><?= (int)($bc['methods']['room_charge']['n'] ?? 0) ?> sale<?= (int)($bc['methods']['room_charge']['n'] ?? 0) === 1 ? '' : 's' ?> · on the guests’ bills</small></div>
        <?php if ((float)$su['total'] > 0): ?>
        <div class="pos-kpi"><span>How it was paid</span>
          <div class="pos-split"><?php foreach ($bc['methods'] as $k => $bm): ?><i style="width:<?= round((float)$bm['total'] / (float)$su['total'] * 100, 2) ?>%;background:<?= $methodColors[$k] ?? '#e2d9cd' ?>" data-tip="<?= e((POS_PAYMENT_METHODS[$k] ?? $k) . ' ' . $m($bm['total'], $cur)) ?>"></i><?php endforeach; ?></div>
          <div class="pos-legend"><?php foreach ($bc['methods'] as $k => $bm): ?><span><i style="background:<?= $methodColors[$k] ?? '#e2d9cd' ?>"></i><?= e(POS_PAYMENT_METHODS[$k] ?? $k) ?> <?= round((float)$bm['total'] / (float)$su['total'] * 100) ?>%</span><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="card dt" data-dt>
      <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Receipt # or customer…']); ?>
      <div class="dt-body" data-dt-body><?= $listBody ?></div>
    </div>

  <?php else: /* Daily close (Z-report) */ ?>
    <?php if (!$z['by_method'] && !$z['voids']): ?>
      <?php dt_empty('No sales on ' . date('j M Y', strtotime($zDate)) . '.'); ?>
    <?php else:
      $byOutlet = []; $tot = [];
      foreach ($z['by_method'] as $r) { $byOutlet[$r['outlet']][] = $r; $tot[$r['currency']][$r['payment_method']] = ($tot[$r['currency']][$r['payment_method']] ?? 0) + (float)$r['total']; } ?>
      <div class="pos-kpis">
        <?php foreach ($tot as $c => $methods): ?>
        <div class="pos-kpi pos-kpi--dark"><span>Taken · <?= e($c) ?></span><b><?= e($m(array_sum($methods), $c)) ?></b><small><?= e(implode(' · ', array_map(fn($k, $v) => (POS_PAYMENT_METHODS[$k] ?? $k) . ' ' . $m($v, $c), array_keys($methods), $methods))) ?></small></div>
        <?php endforeach; ?>
        <?php foreach ($z['voids'] as $vd): ?><div class="pos-kpi pos-kpi--bad"><span>Voids</span><b><?= (int)$vd['n'] ?></b><small><?= e($m($vd['total'], $vd['currency'])) ?> reversed</small></div><?php endforeach; ?>
      </div>
      <div class="pss-grid">
        <div class="card">
          <div class="card__head"><span class="card__title">By outlet and payment</span></div>
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Outlet</th><th>Payment</th><th class="num">Sales</th><th class="num">Service</th><th class="num">VAT</th><th class="num">Tips</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($byOutlet as $on => $rs): foreach ($rs as $i => $r): ?>
              <tr><td><?= $i === 0 ? '<strong>' . e($on) . '</strong>' : '' ?></td><td><?= e(POS_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method']) ?></td>
                <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= e($m($r['service'], $r['currency'])) ?></td><td class="num"><?= e($m($r['vat'], $r['currency'])) ?></td><td class="num"><?= e($m($r['tips'], $r['currency'])) ?></td><td class="num"><strong><?= e($m($r['total'], $r['currency'])) ?></strong></td></tr>
            <?php endforeach; endforeach; ?>
            </tbody>
          </table></div>
        </div>
        <div class="card">
          <div class="card__head"><span class="card__title">By staff</span></div>
          <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Staff</th><th class="num">Sales</th><th class="num">Tips</th><th class="num">Total</th></tr></thead>
            <tbody><?php foreach ($z['staff'] as $r): ?><tr><td><?= e($r['name']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= e($m($r['tips'], $r['currency'])) ?></td><td class="num"><?= e($m($r['total'], $r['currency'])) ?></td></tr><?php endforeach; ?></tbody>
          </table></div>
        </div>
      </div>
      <div class="card" style="margin-top:18px">
        <div class="card__head"><span class="card__title">Items sold</span></div>
        <div class="table-wrap"><table class="data-table">
          <thead><tr><th>Item</th><th>Outlet</th><th class="num">Qty</th><th class="num">Gross</th></tr></thead>
          <tbody><?php foreach ($z['items'] as $r): ?><tr><td><?= e($r['name']) ?></td><td class="text-muted"><?= e($r['outlet']) ?></td><td class="num"><?= (int)$r['qty'] ?></td><td class="num"><?= e($m($r['gross'], $r['currency'])) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<style>
.pos-toolbar .dp-btn{width:auto;min-width:130px;flex:0 0 auto}
.pss-void td{opacity:.6}
.pss-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);gap:18px;align-items:start}
@media (max-width:900px){.pss-grid{grid-template-columns:minmax(0,1fr)}}
.card__head{flex-wrap:wrap;gap:8px 12px}
.data-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.pss-page{max-width:640px;display:flex;flex-direction:column}
.pss-sig img{max-width:100%;max-height:110px;object-fit:contain;background:#fffdf8;border:1px solid var(--border);border-radius:8px}
.pss-sig em{font-size:12px;color:var(--muted);font-style:normal}
@media (max-width:640px){ .pos-toolbar .eselect{flex:1 1 45%} }
</style>
<script>
document.querySelectorAll('#pssFilters [data-autosubmit]').forEach(function (i) {
  i.addEventListener('change', function () { var f = document.getElementById('pssFilters'); var p = f.querySelector('input[name=page]'); if (p) p.value = 1; f.submit(); });
});
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
