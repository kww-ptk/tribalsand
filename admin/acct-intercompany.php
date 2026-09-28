<?php
/**
 * Admin: what the companies owe each other — net balance per pair per currency,
 * the ledger behind it (room charges collected on behalf, re-invoices, stock
 * transfers, settlements) and a settlement form (owner). Owner + manager (managers
 * see pairs involving the companies of their properties). Accounting P2b.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/acct.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_manager();

$pageTitle  = 'Between companies';
$activeMenu = 'acct_intercompany';
$supported  = acct_ops_supported();
$self       = '/admin/acct-intercompany.php';
$scope      = admin_venue_ids();
$coScope    = $scope === null ? null
    : ($scope ? array_map('intval', db_query('SELECT DISTINCT company_id FROM venues WHERE company_id IS NOT NULL AND id IN (' . implode(',', array_map('intval', $scope)) . ')')->fetchAll(PDO::FETCH_COLUMN)) : []);

$flash = $_SESSION['ic_flash'] ?? null; unset($_SESSION['ic_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    try {
        if (!is_owner()) throw new AcctRefusal('Only the owner records settlements between companies.');
        acct_ic_settle((int)($_POST['payer'] ?? 0), (int)($_POST['payee'] ?? 0), (string)($_POST['amount'] ?? ''), (int)($_POST['account_id'] ?? 0),
            (string)($_POST['reference'] ?? ''), (int)($_SESSION['admin_id'] ?? 0) ?: null);
        audit_log('acct.ic_settlement', 'company', (int)($_POST['payer'] ?? 0), (string)($_POST['reference'] ?? ''));
        $_SESSION['ic_flash'] = ['type' => 'success', 'msg' => 'Settlement recorded.'];
    } catch (AcctRefusal|CompanyRefusal $e) {
        $_SESSION['ic_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$names = [];
foreach ($supported ? company_fetch_all() : [] as $c) $names[(int)$c['id']] = $c['name'];
$balances = $supported ? acct_ic_balances($coScope) : [];
$entries  = $supported ? acct_ic_entries_list($coScope) : [];
$banks    = [];
if ($supported && is_owner()) {
    foreach (db_query("SELECT a.*, c.name AS company_name FROM company_accounts a JOIN companies c ON c.id = a.company_id
                        WHERE a.is_active AND a.kind = 'bank' ORDER BY c.name, a.currency, a.label")->fetchAll() as $a) $banks[] = $a;
}
$kindLabel = ['room_charge' => 'Room charge collected on behalf', 'ic_invoice' => 'Re-invoiced room charge', 'stock_transfer' => 'Stock transfer', 'settlement' => 'Settlement'];

include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Between companies</h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_acct_p2b.sql</code> migration (Admin → Migrations) first.</div>
<?php else: ?>
<p class="text-muted ic-intro">When one company collects money that belongs to another — a spa treatment charged to a Zuri room, stock sent from Main stock to a property owned by another company — the app records who owes whom. This is the running balance; pay it with a bank transfer between the companies and record it below.</p>

<div class="ic-bal">
  <?php if (!$balances): ?><div class="card ic-none"><?php dt_empty('Nothing is owed between companies.', 'inbox'); ?></div><?php endif; ?>
  <?php foreach ($balances as $b): ?>
  <div class="card ic-card">
    <div class="ic-card__pair"><strong><?= e($names[$b['debtor']] ?? '?') ?></strong> <span class="text-muted">owes</span> <strong><?= e($names[$b['creditor']] ?? '?') ?></strong></div>
    <div class="ic-card__amt"><?= e(acct_money($b['cents'], $b['currency'])) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (is_owner()): ?>
<div class="card ic-settle">
  <div class="card__head"><span class="card__title">Record a settlement</span></div>
  <div class="card__body ic-settle__body">
    <?php if (!$banks): ?><p class="text-muted" style="margin:0">Add a bank account to the company being paid (Accounting → Companies → Money accounts) first.</p><?php else: ?>
    <form method="POST" action="<?= e($self) ?>" class="ws-addform">
      <?= csrf_field() ?>
      <label class="wsf"><span>Paid by</span><select name="payer" class="inp inp--sm"><?php foreach ($names as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label class="wsf"><span>Paid to</span><select name="payee" class="inp inp--sm" data-ic-payee><?php foreach ($names as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label class="wsf"><span>Into account</span><select name="account_id" class="inp inp--sm" data-ic-acct><?php foreach ($banks as $a): ?><option value="<?= (int)$a['id'] ?>" data-co="<?= (int)$a['company_id'] ?>"><?= e($a['company_name'] . ' · ' . $a['label'] . ' · ' . $a['currency']) ?></option><?php endforeach; ?></select></label>
      <label class="wsf"><span>Amount</span><input name="amount" type="number" step="0.01" min="0.01" class="inp inp--sm inp--num no-spin" style="width:130px" required></label>
      <label class="wsf"><span>Bank reference</span><input name="reference" class="inp inp--sm" maxlength="80" required></label>
      <button type="submit" class="btn-primary btn-sm" data-confirm="Record this settlement? It reduces what the paying company owes."><?= admin_icon('check', 15) ?> Record</button>
    </form>
    <p class="text-muted ic-hint">The amount is in the account's currency and can't be more than is owed in that currency.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Ledger</span><span class="text-muted ic-hint" style="margin:0">Newest first · last 200</span></div>
  <?php if (!$entries): dt_empty('No inter-company entries yet.', 'inbox'); else: ?>
  <div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Date</th><th>Owes</th><th>To</th><th>What</th><th>Document</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($entries as $x): $neg = (float)$x['amount'] < 0; ?>
      <tr>
        <td><?= e(date('j M Y', strtotime((string)$x['created_at']))) ?></td>
        <td><?= e($x['from_name']) ?></td>
        <td><?= e($x['to_name']) ?></td>
        <td><?= e($kindLabel[$x['source_kind']] ?? $x['source_kind']) ?><?php if ($x['note'] !== ''): ?><div class="text-muted ic-small"><?= e($x['note']) ?></div><?php endif; ?></td>
        <td><?php if ($x['doc_number']): ?><a href="/admin/acct-document-print.php?id=<?= (int)$x['document_id'] ?>" target="_blank" class="ic-num"><?= e($x['doc_number']) ?></a><?php elseif ($x['transfer_ref']): ?><span class="ic-num"><?= e($x['transfer_ref']) ?></span><?php else: ?>—<?php endif; ?></td>
        <td class="num"><strong><?= $neg ? '− ' : '' ?><?= e(acct_money(abs(acct_cents($x['amount'])), $x['currency'])) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<style>
.ic-intro{margin:-6px 0 18px;font-size:13px;max-width:860px}
.ic-bal{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:12px;margin-bottom:18px}
.ic-none{grid-column:1/-1}
.ic-card{padding:14px 16px}
.ic-card__pair{font-size:13.5px}
.ic-card__amt{font-size:20px;font-weight:600;margin-top:4px;font-variant-numeric:tabular-nums}
.ic-settle{margin-bottom:18px}
.ic-settle__body{padding:16px 20px}
.ic-hint{font-size:12.5px;margin:8px 0 0}
.ic-small{font-size:12px}
.ic-num{font-family:ui-monospace,monospace;font-size:12.5px}
.data-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
@media (max-width:640px){ .ic-settle .ws-addform{flex-direction:column;align-items:stretch} .ic-settle .wsf{width:100%} .ic-settle .wsf input{width:100% !important} }
</style>
<script>
(function(){
  // Show only the payee's bank accounts.
  var payee = document.querySelector('[data-ic-payee]'), acct = document.querySelector('[data-ic-acct]');
  if (!payee || !acct) return;
  function sync(){
    var first = null;
    [].forEach.call(acct.options, function(o){ var ok = o.dataset.co === payee.value; o.disabled = !ok; o.hidden = !ok; if (ok && first === null) first = o; });
    if (acct.selectedOptions[0] && acct.selectedOptions[0].disabled && first) { acct.value = first.value; acct.dispatchEvent(new Event('change', {bubbles:true})); }
  }
  payee.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
