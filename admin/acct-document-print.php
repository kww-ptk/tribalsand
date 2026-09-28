<?php
/**
 * Printable tax invoice / credit note (?id=<document>) or pro-forma (?hold=<id>&proforma=1).
 * Company name, registered name, KRA PIN, address, logo, number, VAT per line and a
 * VAT summary; bank / M-Pesa details from the company's default accounts in the
 * document currency. A pro-forma is NOT a tax document and is never numbered.
 * Access: owner, or staff who can see the booking and take payments. Accounting P2a.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/acct.php';
require_login();

if (!acct_supported()) { http_response_code(404); exit('Invoicing is not set up yet.'); }
$docId  = (int)($_GET['id'] ?? 0);
$holdId = (int)($_GET['hold'] ?? 0);

if ($docId) {
    $doc = db_query('SELECT * FROM acct_documents WHERE id = :d', [':d' => $docId])->fetch();
    if (!$doc) { http_response_code(404); exit('Document not found.'); }
    $holdId = (int)($doc['hold_id'] ?? 0);
}
// Scope: the booking's property (every document in P2a belongs to a booking).
if (!$holdId || !(is_owner() || (staff_can_hold($holdId) && (acct_can_take_payments() || is_manager())))) {
    http_response_code(403); exit('Not your property.');
}

$lines = []; $allocs = []; $credits = null;
if ($docId) {
    $co    = company_fetch((int)$doc['company_id']);
    $lines = db_query('SELECT * FROM acct_document_lines WHERE document_id = :d ORDER BY id', [':d' => $docId])->fetchAll();
    foreach ($lines as &$l) { $l['net_cents'] = acct_cents($l['net_amount']); $l['vat_cents'] = acct_cents($l['vat_amount']); $l['gross_cents'] = acct_cents($l['line_total']); }
    unset($l);
    $allocs  = db_query('SELECT COALESCE(SUM(amount),0) FROM acct_allocations WHERE document_id = :d', [':d' => $docId])->fetchColumn();
    $credits = $doc['credits_document_id'] ? db_query('SELECT number, issued_at FROM acct_documents WHERE id = :d', [':d' => $doc['credits_document_id']])->fetch() : null;
    $creditedBy = db_query('SELECT number FROM acct_documents WHERE credits_document_id = :d', [':d' => $docId])->fetchColumn();
    $groups = [$doc['currency'] => $lines];
    $title  = $doc['doc_type'] === 'credit_note' ? 'Credit note' : 'Tax invoice';
    $hold   = acct_hold_context($holdId)['hold'];
} else {
    // Pro-forma: what is open on the folio right now (not stored, not numbered).
    $ctx = acct_hold_context($holdId);
    if (!$ctx['company']) { http_response_code(404); exit($ctx['reason'] ?: 'No company for this property.'); }
    $co = $ctx['company']; $hold = $ctx['hold'];
    $open = acct_open_sources($ctx);
    $groups = [];
    foreach ($open['sources'] as $s) $groups[$s['currency']][] = acct_build_line($s, $co);
    ksort($groups);
    $title = 'Pro-forma';
    $doc = null; $creditedBy = false;
}
if (!$co) { http_response_code(404); exit('Company not found.'); }
$logo = company_logo_url($co['logo_key'] ?? null);
$bankFor = function (string $cur) use ($co): array {
    return db_query("SELECT * FROM company_accounts WHERE company_id = :c AND is_active AND currency = :cur AND kind IN ('bank','mpesa_till','mpesa_paybill')
                      ORDER BY is_default DESC, sort_order, id", [':c' => $co['id'], ':cur' => $cur])->fetchAll();
};
$customer = $doc ? $doc['customer_name'] : (string)($hold['guest_name'] ?? '');
$vatRegistered = companies_bool($co['vat_registered']);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= e($title . ($doc ? ' ' . $doc['number'] : '') . ' · ' . $co['name']) ?></title>
<style>
  html{background:#fff}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1b2a2f;background:#fff;max-width:760px;margin:24px auto;padding:24px 20px;font-size:14px}
  .top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;flex-wrap:wrap}
  .co{max-width:360px}
  .co img{max-height:64px;max-width:220px;object-fit:contain;display:block;margin-bottom:8px}
  .co h2{margin:0;font-size:17px}
  .muted{color:#6b7280;font-size:12.5px;line-height:1.5}
  .doc{text-align:right}
  .doc h1{margin:0;font-size:22px;letter-spacing:.02em;text-transform:uppercase}
  .doc .num{font-family:ui-monospace,monospace;font-size:15px;margin-top:4px}
  .warn{margin:14px 0 0;padding:8px 12px;background:#fff7ed;color:#9a3412;border-radius:6px;font-size:13px}
  .bill{display:flex;justify-content:space-between;gap:24px;margin:22px 0 4px;flex-wrap:wrap}
  .bill h3{margin:0 0 4px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280}
  table{width:100%;border-collapse:collapse;margin:14px 0 6px}
  th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #e5e7eb;vertical-align:top}
  th{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280}
  .r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  .tot{margin-left:auto;width:min(100%,320px)}
  .tot div{display:flex;justify-content:space-between;padding:4px 6px;font-variant-numeric:tabular-nums}
  .tot .grand{font-weight:700;font-size:16px;border-top:2px solid #1b2a2f;margin-top:4px;padding-top:8px}
  .foot{margin-top:28px;display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
  .foot h3{margin:0 0 4px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280}
  .printbtn{margin:0 0 18px;padding:10px 18px;background:#102F3A;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px}
  @media print{.printbtn{display:none}body{margin:0;max-width:none}}
</style></head><body>
<button class="printbtn" onclick="window.print()">Print / Save PDF</button>

<div class="top">
  <div class="co">
    <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
    <h2><?= e($co['legal_name'] !== '' ? $co['legal_name'] : $co['name']) ?></h2>
    <div class="muted">
      <?php if ($co['kra_pin'] !== ''): ?>KRA PIN: <strong><?= e($co['kra_pin']) ?></strong><br><?php endif; ?>
      <?= nl2br(e($co['address'])) ?><?= $co['address'] !== '' ? '<br>' : '' ?>
      <?= e(implode(' · ', array_filter([$co['email'], $co['phone']]))) ?>
    </div>
  </div>
  <div class="doc">
    <h1><?= e($title) ?></h1>
    <?php if ($doc): ?>
      <div class="num"><?= e($doc['number']) ?></div>
      <div class="muted">Date: <?= e(date('j M Y', strtotime((string)$doc['issued_at']))) ?></div>
      <?php if ($credits): ?><div class="muted">Credits invoice <?= e($credits['number']) ?> of <?= e(date('j M Y', strtotime((string)$credits['issued_at']))) ?></div><?php endif; ?>
    <?php else: ?>
      <div class="muted">Prepared <?= e(date('j M Y')) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php if (!$doc): ?><div class="warn">This pro-forma is not a tax invoice. The tax invoice is issued at check-out.</div><?php endif; ?>
<?php if ($creditedBy): ?><div class="warn">This invoice has been cancelled by credit note <?= e($creditedBy) ?>.</div><?php endif; ?>
<?php if ($doc && $doc['doc_type'] === 'credit_note' && $doc['reason'] !== ''): ?><div class="muted" style="margin-top:10px">Reason: <?= e($doc['reason']) ?></div><?php endif; ?>

<div class="bill">
  <div><h3>Billed to</h3><strong><?= e($customer) ?></strong>
    <?php if ($doc && $doc['customer_pin'] !== ''): ?><div class="muted">KRA PIN: <?= e($doc['customer_pin']) ?></div><?php endif; ?></div>
  <?php if ($hold): ?>
  <div><h3>Stay</h3><?= e(trim(($hold['venue_name'] ?? '') . ' · ' . ($hold['room_name'] ?? ''), ' ·')) ?>
    <div class="muted"><?= e(date('j M Y', strtotime((string)$hold['check_in']))) ?> – <?= e(date('j M Y', strtotime((string)$hold['check_out']))) ?><?= !empty($hold['access_code']) ? ' · Ref ' . e($hold['access_code']) : '' ?></div></div>
  <?php endif; ?>
</div>

<?php if (!$groups): ?><p class="muted">Nothing to show — every charge on this booking is already invoiced.</p><?php endif; ?>
<?php foreach ($groups as $cur => $ls): $t = acct_totals($ls); $bands = []; ?>
<table>
  <thead><tr><th>Description</th><?php if ($vatRegistered): ?><th class="r">VAT</th><th class="r">Net</th><?php endif; ?><th class="r">Amount (<?= e($cur) ?>)</th></tr></thead>
  <tbody>
  <?php foreach ($ls as $l):
      $band = (string)$l['tax_band']; $rate = (float)$l['vat_rate'];
      $bands[$band . '|' . $rate] ??= ['band' => $band, 'rate' => $rate, 'net' => 0, 'vat' => 0];
      $bands[$band . '|' . $rate]['net'] += $l['net_cents']; $bands[$band . '|' . $rate]['vat'] += $l['vat_cents']; ?>
    <tr>
      <td><?= e($l['description']) ?><?php if (companies_bool($l['is_disbursement'])): ?><div class="muted">Collected on behalf of another company — not our supply</div><?php endif; ?></td>
      <?php if ($vatRegistered): ?>
      <td class="r"><?= $l['vat_cents'] ? e(number_format($l['vat_cents'] / 100, 2)) . ' <span class="muted">(' . e(rtrim(rtrim(number_format($rate, 2), '0'), '.')) . '%)</span>' : '<span class="muted">' . e($band) . '</span>' ?></td>
      <td class="r"><?= e(number_format($l['net_cents'] / 100, 2)) ?></td>
      <?php endif; ?>
      <td class="r"><?= e(number_format($l['gross_cents'] / 100, 2)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<div class="tot">
  <?php if ($vatRegistered): ?>
  <div><span>Net</span><span><?= e(acct_money($t['net'], $cur)) ?></span></div>
  <?php foreach ($bands as $b): if (!$b['vat']) continue; ?>
  <div><span>VAT <?= e(rtrim(rtrim(number_format($b['rate'], 2), '0'), '.')) ?>% (band <?= e($b['band']) ?>)</span><span><?= e(acct_money($b['vat'], $cur)) ?></span></div>
  <?php endforeach; ?>
  <?php endif; ?>
  <div class="grand"><span><?= $doc && $doc['doc_type'] === 'credit_note' ? 'Total credited' : 'Total' ?></span><span><?= e(acct_money($t['gross'], $cur)) ?></span></div>
  <?php if ($doc && $doc['doc_type'] === 'invoice' && !$creditedBy): $paid = acct_cents($allocs); ?>
  <div><span>Paid</span><span><?= e(acct_money($paid, $cur)) ?></span></div>
  <div class="grand"><span>Balance due</span><span><?= e(acct_money(max(0, $t['gross'] - $paid), $cur)) ?></span></div>
  <?php endif; ?>
</div>
<?php $banks = $bankFor($cur); if ($banks && (!$doc || $doc['doc_type'] === 'invoice')): ?>
<div class="foot">
  <?php foreach ($banks as $a): ?>
  <div><h3><?= e(COMPANY_ACCOUNT_KINDS[$a['kind']] ?? 'Account') ?> · <?= e($a['currency']) ?></h3>
    <div class="muted">
      <?php if ($a['kind'] === 'bank'): ?><?= e($a['bank_name']) ?><?= $a['branch'] !== '' ? ', ' . e($a['branch']) : '' ?><br>A/c <?= e($a['account_number']) ?><?= $a['swift_code'] !== '' ? '<br>SWIFT ' . e($a['swift_code']) : '' ?>
      <?php else: ?><?= $a['kind'] === 'mpesa_till' ? 'Till' : 'Paybill' ?> <?= e($a['account_number']) ?><?php endif; ?>
      <br>Name: <?= e($co['legal_name'] !== '' ? $co['legal_name'] : $co['name']) ?>
    </div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
</body></html>
