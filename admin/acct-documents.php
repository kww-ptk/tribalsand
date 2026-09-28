<?php
/**
 * Admin: invoices, credit notes and payments — per company, per month, with
 * per-currency totals and a CSV for the accountant. Owner + manager (managers see
 * their properties' bookings only). Read-only. Accounting P2a — includes/acct.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/acct.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_manager();

$pageTitle  = 'Invoices & payments';
$activeMenu = 'acct_documents';
$supported  = acct_supported();
$scope      = admin_venue_ids();   // null = owner (everything)

$ymd  = fn(string $v): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
$from = $ymd((string)($_GET['from'] ?? '')) ?? date('Y-m-01');
$to   = $ymd((string)($_GET['to'] ?? '')) ?? date('Y-m-t');
if ($to < $from) [$from, $to] = [$to, $from];
$view = ($_GET['view'] ?? '') === 'payments' ? 'payments' : 'documents';
$type = isset(ACCT_DOC_TYPES[$_GET['type'] ?? '']) ? (string)$_GET['type'] : '';

$companies = [];
if ($supported) {
    $companies = $scope === null ? company_fetch_all()
        : ($scope ? db_query('SELECT DISTINCT c.* FROM companies c JOIN venues v ON v.company_id = c.id WHERE v.id IN (' . implode(',', array_map('intval', $scope)) . ') ORDER BY c.name')->fetchAll() : []);
}
$coIds = array_map(fn($c) => (int)$c['id'], $companies);
$coId  = in_array((int)($_GET['company'] ?? 0), $coIds, true) ? (int)$_GET['company'] : 0;
$filter = ['from' => $from, 'to' => $to, 'company_id' => $coId, 'doc_type' => $type];

$docs = $supported ? acct_documents_list($filter, $scope) : [];
$pays = $supported ? acct_payments_list($filter, $scope) : [];

// ── CSV (same rows as the page) ──
if ($supported && isset($_GET['export'])) {
    $which = $_GET['export'] === 'payments' ? 'payments' : 'documents';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $which . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    $put = fn(array $row) => fputcsv($out, $row, ',', '"', '');   // explicit escape: RFC 4180, and no PHP 8.4 deprecation
    fwrite($out, "\xEF\xBB\xBF");   // Excel reads UTF-8
    if ($which === 'documents') {
        $put(['Company', 'Company KRA PIN', 'Type', 'Number', 'Date', 'Customer', 'Customer KRA PIN', 'Property', 'Currency', 'Net', 'VAT', 'Total', 'Paid', 'Rate to home', 'Total in home currency', 'Credits / credited by', 'Reason']);
        $pins = []; foreach ($companies as $c) $pins[(int)$c['id']] = $c['kra_pin'];
        foreach (array_reverse($docs) as $d) {
            $sign = $d['doc_type'] === 'credit_note' ? -1 : 1;
            $put([$d['company_name'], $pins[(int)$d['company_id']] ?? '', ACCT_DOC_TYPES[$d['doc_type']] ?? $d['doc_type'], $d['number'],
                date('Y-m-d', strtotime((string)$d['issued_at'])), $d['customer_name'], $d['customer_pin'], $d['venue_name'] ?? '', $d['currency'],
                number_format($sign * (float)$d['subtotal'], 2, '.', ''), number_format($sign * (float)$d['vat_amount'], 2, '.', ''),
                number_format($sign * (float)$d['total'], 2, '.', ''), number_format((float)$d['allocated'], 2, '.', ''), $d['fx_to_home'],
                number_format($sign * (float)$d['total'] * (float)$d['fx_to_home'], 2, '.', ''),
                $d['credits_number'] ?: ($d['credited_by_number'] ?: ''), $d['reason']]);
        }
    } else {
        $put(['Company', 'Date', 'Kind', 'Security deposit', 'Method', 'Account', 'Reference', 'Payer', 'Property', 'Currency', 'Amount', 'Rate to home', 'Amount in home currency', 'Reason']);
        foreach (array_reverse($pays) as $p) {
            $sign = $p['kind'] === 'refund' ? -1 : 1;
            $put([$p['company_name'], date('Y-m-d', strtotime((string)$p['received_at'])), $p['kind'] === 'refund' ? 'Refund' : 'Receipt',
                companies_bool($p['is_security_deposit']) ? 'yes' : '', ACCT_PAY_METHODS[$p['method']] ?? $p['method'], $p['account_label'], $p['reference'],
                $p['payer_name'], $p['venue_name'] ?? '', $p['currency'], number_format($sign * (float)$p['amount'], 2, '.', ''), $p['fx_to_home'],
                number_format($sign * (float)$p['amount'] * (float)$p['fx_to_home'], 2, '.', ''), $p['reason']]);
        }
    }
    fclose($out); exit;
}

$summary = acct_documents_summary($docs);
$paySum = [];
foreach ($pays as $p) {
    $k = $p['company_name'] . '|' . $p['currency'];
    $paySum[$k] ??= ['company' => $p['company_name'], 'currency' => $p['currency'], 'in' => 0, 'out' => 0, 'deposits' => 0];
    if ($p['kind'] === 'refund') $paySum[$k]['out'] += acct_cents($p['amount']);
    elseif (companies_bool($p['is_security_deposit'])) $paySum[$k]['deposits'] += acct_cents($p['amount']);
    else $paySum[$k]['in'] += acct_cents($p['amount']);
}
ksort($paySum);
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['view' => $view, 'company' => $coId ?: null, 'type' => $type ?: null, 'from' => $from, 'to' => $to], $over), fn($v) => $v !== null && $v !== ''));

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Invoices &amp; payments</h1>
  <?php if ($supported): ?><a href="<?= e($qs(['export' => $view])) ?>" class="btn-outline btn-sm"><?= admin_icon('download', 15) ?> CSV for the accountant</a><?php endif; ?>
</div>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_companies.sql</code> and <code>add_acct_documents.sql</code> migrations (Admin → Migrations) first.</div>
<?php else: ?>

<form method="GET" class="ad-filters card">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <label class="wsf"><span>Company</span>
    <select name="company" class="inp inp--sm"><option value="">All companies</option><?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $coId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
  <?php if ($view === 'documents'): ?>
  <label class="wsf"><span>Type</span>
    <select name="type" class="inp inp--sm"><option value="">Invoices &amp; credit notes</option><option value="invoice" <?= $type === 'invoice' ? 'selected' : '' ?>>Tax invoices</option><option value="credit_note" <?= $type === 'credit_note' ? 'selected' : '' ?>>Credit notes</option></select></label>
  <?php endif; ?>
  <label class="wsf"><span>From</span>
    <button type="button" class="dp-btn ad-date" data-dp-target="adFrom" data-dp-past data-dp-placeholder="From"><?= e(date('j M Y', strtotime($from))) ?></button>
    <input type="hidden" id="adFrom" name="from" value="<?= e($from) ?>"></label>
  <label class="wsf"><span>To</span>
    <button type="button" class="dp-btn ad-date" data-dp-target="adTo" data-dp-past data-dp-placeholder="To"><?= e(date('j M Y', strtotime($to))) ?></button>
    <input type="hidden" id="adTo" name="to" value="<?= e($to) ?>"></label>
  <button type="submit" class="btn-primary btn-sm"><?= admin_icon('filter', 15) ?> Show</button>
  <span class="ad-months">
    <?php for ($i = 0; $i < 3; $i++): $m = strtotime(date('Y-m-01') . " -{$i} month"); ?>
    <a href="<?= e($qs(['from' => date('Y-m-01', $m), 'to' => date('Y-m-t', $m)])) ?>" class="optchip <?= $from === date('Y-m-01', $m) && $to === date('Y-m-t', $m) ? 'is-on' : '' ?>"><?= e(date('M Y', $m)) ?></a>
    <?php endfor; ?>
  </span>
</form>

<div class="tabs">
  <a class="tab-btn <?= $view === 'documents' ? 'is-active' : '' ?>" href="<?= e($qs(['view' => 'documents'])) ?>">Invoices &amp; credit notes <span class="tab-btn__count"><?= count($docs) ?></span></a>
  <a class="tab-btn <?= $view === 'payments' ? 'is-active' : '' ?>" href="<?= e($qs(['view' => 'payments', 'type' => null])) ?>">Payments <span class="tab-btn__count"><?= count($pays) ?></span></a>
</div>

<?php if ($view === 'documents'): ?>
  <?php if ($summary): ?>
  <div class="ad-sums">
    <?php foreach ($summary as $s): ?>
    <div class="card ad-sum"><div class="ad-sum__co"><?= e($s['company']) ?> · <?= e($s['currency']) ?></div>
      <div class="ad-sum__tot"><?= e(acct_money($s['total'], $s['currency'])) ?></div>
      <div class="text-muted ad-sum__sub">Net <?= e(acct_money($s['net'], $s['currency'])) ?> · VAT <?= e(acct_money($s['vat'], $s['currency'])) ?> · <?= (int)$s['count'] ?> document<?= $s['count'] === 1 ? '' : 's' ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="card">
    <?php if (!$docs): dt_empty('No invoices or credit notes in this period.', 'inbox'); else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Number</th><th>Date</th><th>Customer</th><th>Property</th><th class="num">Net</th><th class="num">VAT</th><th class="num">Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d): $cn = $d['doc_type'] === 'credit_note'; $bal = $cn ? 0 : acct_cents($d['total']) - acct_cents($d['allocated']); ?>
        <tr>
          <td><a href="/admin/acct-document-print.php?id=<?= (int)$d['id'] ?>" target="_blank" class="ad-num"><?= e($d['number']) ?></a><div class="text-muted ad-small"><?= e($d['company_name']) ?></div></td>
          <td><?= e(date('j M Y', strtotime((string)$d['issued_at']))) ?></td>
          <td><?= e($d['customer_name']) ?><?php if ($d['hold_id']): ?> <a href="/admin/booking.php?hold=<?= (int)$d['hold_id'] ?>&amp;tab=bill#folio" class="ad-small" data-shell-link>booking</a><?php endif; ?></td>
          <td><?= e($d['venue_name'] ?? '—') ?></td>
          <td class="num"><?= $cn ? '− ' : '' ?><?= e(number_format((float)$d['subtotal'], 2)) ?></td>
          <td class="num"><?= $cn ? '− ' : '' ?><?= e(number_format((float)$d['vat_amount'], 2)) ?></td>
          <td class="num"><strong><?= $cn ? '− ' : '' ?><?= e(acct_money(acct_cents($d['total']), $d['currency'])) ?></strong></td>
          <td>
            <?php if ($cn): ?><span class="badge badge--grey">Credit note</span> <span class="text-muted ad-small">for <?= e($d['credits_number']) ?></span>
            <?php elseif ($d['credited_by_number']): ?><span class="badge badge--orange">Credited</span> <span class="text-muted ad-small"><?= e($d['credited_by_number']) ?></span>
            <?php elseif ($bal > 0): ?><span class="badge badge--orange">Due <?= e(acct_money($bal, $d['currency'])) ?></span>
            <?php else: ?><span class="badge badge--green">Paid</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php if ($paySum): ?>
  <div class="ad-sums">
    <?php foreach ($paySum as $s): ?>
    <div class="card ad-sum"><div class="ad-sum__co"><?= e($s['company']) ?> · <?= e($s['currency']) ?></div>
      <div class="ad-sum__tot"><?= e(acct_money($s['in'] - $s['out'], $s['currency'])) ?></div>
      <div class="text-muted ad-sum__sub">Received <?= e(acct_money($s['in'], $s['currency'])) ?><?= $s['out'] ? ' · refunded ' . e(acct_money($s['out'], $s['currency'])) : '' ?><?= $s['deposits'] ? ' · deposits held ' . e(acct_money($s['deposits'], $s['currency'])) : '' ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="card">
    <?php if (!$pays): dt_empty('No payments in this period.', 'inbox'); else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Date</th><th>Guest</th><th>How</th><th>Into</th><th>Reference</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($pays as $p): $ref = $p['kind'] === 'refund'; ?>
        <tr>
          <td><?= e(date('j M Y', strtotime((string)$p['received_at']))) ?></td>
          <td><?= e($p['payer_name'] ?: ($p['guest_name'] ?? '')) ?><?php if ($p['hold_id']): ?> <a href="/admin/booking.php?hold=<?= (int)$p['hold_id'] ?>&amp;tab=bill#folio" class="ad-small" data-shell-link>booking</a><?php endif; ?>
            <div class="text-muted ad-small"><?= e($p['company_name']) ?><?= $p['venue_name'] ? ' · ' . e($p['venue_name']) : '' ?></div></td>
          <td><?= e(ACCT_PAY_METHODS[$p['method']] ?? $p['method']) ?><?php if ($ref): ?> <span class="badge badge--orange">Refund</span><?php endif; ?><?php if (companies_bool($p['is_security_deposit'])): ?> <span class="badge badge--teal">Deposit</span><?php endif; ?></td>
          <td><?= e($p['account_label']) ?></td>
          <td class="text-muted"><?= e($p['reference'] ?: ($p['reason'] ?: '—')) ?></td>
          <td class="num"><strong><?= $ref ? '− ' : '' ?><?= e(acct_money(acct_cents($p['amount']), $p['currency'])) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<style>
.ad-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;padding:14px 18px;margin-bottom:18px}
.ad-date{min-width:130px}
.ad-months{display:flex;flex-wrap:wrap;gap:6px;margin-left:auto}
.ad-months .optchip{text-decoration:none}
.ad-months .optchip.is-on{background:var(--brand);border-color:var(--brand);color:#fff}
.ad-sums{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:12px;margin-bottom:16px}
.ad-sum{padding:14px 16px}
.ad-sum__co{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
.ad-sum__tot{font-size:20px;font-weight:600;margin:4px 0 2px;font-variant-numeric:tabular-nums}
.ad-sum__sub{font-size:12px}
.ad-num{font-family:ui-monospace,monospace;font-weight:600}
.ad-small{font-size:12px}
.data-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
@media (max-width:640px){
  .ad-filters{flex-direction:column;align-items:stretch}
  .ad-filters .wsf,.ad-date{width:100%}
  .ad-months{margin-left:0}
}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
