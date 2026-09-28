<?php
/**
 * Workspace Bill tab — the folio (Accounting P2a): balance due per currency,
 * record a payment, issue the tax invoice, credit notes and refunds.
 * Expects $hold, $holdId. Rules: includes/acct.php. Actions: admin/booking.php (acct_*).
 */
if (!acct_supported()) return;
$__f   = acct_folio($holdId);
$__ctx = $__f['ctx'];
if (!$__ctx['live']) {
    if ($__ctx['reason'] !== '' && (is_owner() || is_manager())) {
        echo '<p class="text-muted fo-off">' . admin_icon('clock', 14) . ' Invoicing: ' . e($__ctx['reason']) . '</p>';
        echo '<style>.fo-off{display:flex;align-items:center;gap:6px;font-size:12.5px;margin:0 0 14px}</style>';
    }
    return;
}
$__co      = $__ctx['company'];
$__canPay  = acct_can_take_payments();
$__canRev  = acct_can_reverse($__ctx['venue_id']);
$__accts   = company_accounts((int)$__co['id'], true);
$__self    = '/admin/booking.php?hold=' . $holdId . '&tab=bill';
$__hasOpen = (bool)$__f['lines'];
$__vatOn   = companies_bool($__co['vat_registered']);
?>
<div class="card fo" id="folio">
  <div class="card__head fo__head">
    <span class="card__title">Folio <span class="text-muted fo__co">· <?= e($__co['name']) ?><?= $__vatOn ? ' · VAT registered' : '' ?></span></span>
    <a href="/admin/acct-document-print.php?hold=<?= $holdId ?>&amp;proforma=1" target="_blank" class="btn-sm btn-outline">Pro-forma <?= admin_icon('external-link', 14) ?></a>
  </div>
  <div class="card__body fo__body">

    <?php if (!$__f['by_cur']): ?>
      <p class="text-muted fo__empty">Nothing charged or paid yet.</p>
    <?php endif; ?>
    <div class="fo__sums">
      <?php foreach ($__f['by_cur'] as $__c => $__b): ?>
      <div class="fo__sum">
        <div class="fo__due <?= $__b['due'] > 0 ? 'is-due' : ($__b['due'] < 0 ? 'is-credit' : 'is-clear') ?>">
          <span><?= $__b['due'] > 0 ? 'Balance due' : ($__b['due'] < 0 ? 'In credit' : 'Settled') ?></span>
          <strong><?= e(acct_money(abs($__b['due']), $__c)) ?></strong>
        </div>
        <dl class="fo__parts">
          <?php if ($__b['open']): ?><div><dt>Not yet invoiced</dt><dd><?= e(acct_money($__b['open'], $__c)) ?></dd></div><?php endif; ?>
          <?php if ($__b['invoiced_due']): ?><div><dt>Invoiced, unpaid</dt><dd><?= e(acct_money($__b['invoiced_due'], $__c)) ?></dd></div><?php endif; ?>
          <?php if ($__b['unapplied']): ?><div><dt>Paid in advance</dt><dd>− <?= e(acct_money($__b['unapplied'], $__c)) ?></dd></div><?php endif; ?>
          <?php if ($__b['deposits']): ?><div><dt>Security deposit held</dt><dd><?= e(acct_money($__b['deposits'], $__c)) ?></dd></div><?php endif; ?>
        </dl>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($__f['by_cur']) > 1): ?><p class="text-muted fo__hint">A payment in one currency is applied to an invoice in another at the day's exchange rate when the invoice is issued.</p><?php endif; ?>

    <?php foreach ($__f['blockers'] as $__m): ?><div class="alert alert--error fo__alert"><?= e($__m) ?></div><?php endforeach; ?>
    <?php foreach ($__f['notes'] as $__m): ?><p class="text-muted fo__hint"><?= e($__m) ?></p><?php endforeach; ?>

    <?php if ($__hasOpen): ?>
    <div class="fo__sub">Not yet invoiced</div>
    <div class="table-wrap fo__tw">
    <table class="data-table fo__table">
      <thead><tr><th>Charge</th><th>Type</th><th class="num">VAT</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($__f['lines'] as $__l): ?>
        <tr>
          <td><?= e($__l['description']) ?></td>
          <td class="text-muted"><?= e(ACCT_CATEGORY_LABELS[$__l['category']] ?? $__l['category']) ?></td>
          <td class="num text-muted"><?= $__l['vat_cents'] ? e(acct_money($__l['vat_cents'], $__l['currency'])) : '—' ?></td>
          <td class="num"><?= e(acct_money($__l['gross_cents'], $__l['currency'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($__canPay): ?>
    <form method="POST" action="<?= e($__self) ?>" class="ws-addform fo__issue">
      <?= csrf_field() ?><input type="hidden" name="action" value="acct_issue"><input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <label class="wsf"><span>Buyer's KRA PIN <span class="text-muted">(optional)</span></span>
        <input name="buyer_pin" class="inp inp--sm fo__pin" maxlength="14" placeholder="e.g. P051234567X" autocomplete="off"></label>
      <button type="submit" class="btn-primary btn-sm" <?= $__f['blockers'] ? 'disabled' : '' ?>
        data-confirm="Issue the tax invoice now? Once issued it can't be edited — only credited."><?= admin_icon('check', 15) ?> Issue invoice</button>
    </form>
    <p class="text-muted fo__hint">Usually done at check-out. One invoice per currency; anything paid in advance is applied automatically.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($__canPay): ?>
    <div class="fo__sub">Record a payment</div>
    <?php if (!$__accts): ?>
      <p class="text-muted fo__hint"><?= e($__co['name']) ?> has no money accounts yet — the owner adds them in Accounting → Companies.</p>
    <?php else: ?>
    <form method="POST" action="<?= e($__self) ?>" class="ws-addform fo__pay">
      <?= csrf_field() ?><input type="hidden" name="action" value="acct_pay"><input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <label class="wsf"><span>Amount</span><input name="amount" type="number" step="0.01" min="0.01" class="inp inp--sm inp--num no-spin fo__amt" required placeholder="0.00"></label>
      <label class="wsf"><span>Into</span>
        <select name="account_id" class="inp inp--sm fo__acct"><?php foreach ($__accts as $__a): ?><option value="<?= (int)$__a['id'] ?>" data-kind="<?= e($__a['kind']) ?>" <?= $__a['is_default'] ? 'selected' : '' ?>><?= e($__a['label'] . ' · ' . $__a['currency']) ?></option><?php endforeach; ?></select></label>
      <label class="wsf"><span>How</span>
        <select name="method" class="inp inp--sm" data-fo-method data-fits='<?= e(json_encode(ACCT_METHOD_ACCOUNTS)) ?>'><?php foreach (ACCT_PAY_METHODS as $__k => $__lbl): ?><option value="<?= e($__k) ?>"><?= e($__lbl) ?></option><?php endforeach; ?></select></label>
      <label class="wsf"><span>Reference</span><input name="reference" class="inp inp--sm" maxlength="80" placeholder="M-Pesa code, card slip…"></label>
      <label class="wsf"><span>Received</span>
        <button type="button" class="dp-btn fo__date" data-dp-target="foRecv<?= $holdId ?>" data-dp-past data-dp-placeholder="Today">Today</button>
        <input type="hidden" id="foRecv<?= $holdId ?>" name="received_on" value=""></label>
      <label class="togglerow fo__dep"><span class="toggle"><input type="checkbox" name="is_security_deposit" value="1"><span class="toggle-slider"></span></span><span>Security deposit <span class="text-muted">(held, refunded later)</span></span></label>
      <button type="submit" class="btn-outline btn-sm"><?= admin_icon('plus', 15) ?> Record payment</button>
    </form>
    <p class="text-muted fo__hint">The amount is in the account's currency.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($__f['docs']): ?>
    <div class="fo__sub">Invoices &amp; credit notes</div>
    <div class="fo__list">
      <?php foreach ($__f['docs'] as $__d): $__isInv = $__d['doc_type'] === 'invoice'; ?>
      <div class="fo__row">
        <div class="fo__rowmain">
          <a href="/admin/acct-document-print.php?id=<?= (int)$__d['id'] ?>" target="_blank" class="fo__num"><?= e($__d['number']) ?></a>
          <span class="badge <?= $__isInv ? 'badge--blue' : 'badge--grey' ?>"><?= $__isInv ? 'Invoice' : 'Credit note' ?></span>
          <span class="text-muted fo__date2"><?= e(date('j M Y', strtotime((string)$__d['issued_at']))) ?></span>
          <?php if ($__isInv && $__d['fully_credited']): ?><span class="badge badge--orange">Credited by <?= e($__d['credited_by_number']) ?></span>
          <?php elseif ($__isInv && $__d['balance_cents'] > 0): ?><span class="badge badge--orange">Due <?= e(acct_money($__d['balance_cents'], $__d['currency'])) ?></span>
          <?php elseif ($__isInv): ?><span class="badge badge--green">Paid</span><?php endif; ?>
          <?php if ($__isInv && !$__d['fully_credited'] && $__d['credited_cents'] > 0): ?><span class="badge badge--grey">Part-credited <?= e($__d['credited_by_number']) ?></span><?php endif; ?>
          <?php if (!$__isInv && $__d['reason'] !== ''): ?><span class="text-muted fo__why"><?= e($__d['reason']) ?></span><?php endif; ?>
        </div>
        <strong class="fo__amtcell"><?= $__isInv ? '' : '− ' ?><?= e(acct_money(acct_cents($__d['total']), $__d['currency'])) ?></strong>
        <?php if ($__isInv && !$__d['fully_credited'] && $__canRev): $__dl = array_values(array_filter(acct_document_lines_status((int)$__d['id']), fn($x) => !$x['credited'])); ?>
        <details class="fo__rev">
          <summary class="btn-icon" data-tip="Credit this invoice (all or some lines)" aria-label="Credit this invoice"><?= admin_icon('rotate', 15) ?></summary>
          <form method="POST" action="<?= e($__self) ?>" class="fo__revform fo__credit">
            <?= csrf_field() ?><input type="hidden" name="action" value="acct_credit"><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="document_id" value="<?= (int)$__d['id'] ?>">
            <div class="fo__clines">
              <?php foreach ($__dl as $__x): ?>
              <label class="ckwrap"><input type="checkbox" name="line_ids[]" value="<?= (int)$__x['id'] ?>" checked><span class="ck"></span>
                <span><?= e($__x['description']) ?> <span class="text-muted">· <?= e(acct_money(acct_cents($__x['line_total']), $__d['currency'])) ?></span></span></label>
              <?php endforeach; ?>
            </div>
            <input name="reason" class="inp inp--sm" maxlength="300" required placeholder="Why? e.g. wrong minibar amount">
            <button type="submit" class="btn-sm btn-outline" data-confirm="Issue a credit note for the ticked lines of <?= e($__d['number']) ?>? Those charges go back on the bill to correct and re-invoice.">Issue credit note</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($__f['payments']): ?>
    <div class="fo__sub">Payments</div>
    <div class="fo__list">
      <?php foreach ($__f['payments'] as $__p): $__isRef = $__p['kind'] === 'refund'; ?>
      <div class="fo__row">
        <div class="fo__rowmain">
          <strong><?= e(ACCT_PAY_METHODS[$__p['method']] ?? $__p['method']) ?></strong>
          <?php if ($__isRef): ?><span class="badge badge--orange">Refund</span><?php endif; ?>
          <?php if (companies_bool($__p['is_security_deposit'])): ?><span class="badge badge--teal">Security deposit</span><?php endif; ?>
          <span class="text-muted fo__date2"><?= e(date('j M Y', strtotime((string)$__p['received_at']))) ?> · <?= e($__p['account_label']) ?><?= $__p['reference'] !== '' ? ' · ' . e($__p['reference']) : '' ?></span>
          <?php if ($__isRef && $__p['reason'] !== ''): ?><span class="text-muted fo__why"><?= e($__p['reason']) ?></span><?php endif; ?>
          <?php if (!$__isRef && $__p['available_cents'] > 0 && !companies_bool($__p['is_security_deposit'])): ?><span class="badge badge--grey">Not yet applied <?= e(acct_money($__p['available_cents'], $__p['currency'])) ?></span><?php endif; ?>
        </div>
        <strong class="fo__amtcell"><?= $__isRef ? '− ' : '' ?><?= e(acct_money(acct_cents($__p['amount']), $__p['currency'])) ?></strong>
        <?php if (!$__isRef && $__p['available_cents'] > 0 && $__canRev && companies_bool($__p['is_security_deposit']) && array_filter($__f['docs'], fn($x) => $x['balance_cents'] > 0)): ?>
        <form method="POST" action="<?= e($__self) ?>" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="acct_apply_deposit"><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="payment_id" value="<?= (int)$__p['id'] ?>">
          <button type="submit" class="btn-sm btn-outline" data-confirm="Use this security deposit to pay the open invoice (e.g. damages)? What is left can still be refunded.">Apply to invoice</button>
        </form>
        <?php endif; ?>
        <?php if (!$__isRef && $__p['available_cents'] > 0 && $__canRev): ?>
        <details class="fo__rev">
          <summary class="btn-icon" data-tip="Refund" aria-label="Refund"><?= admin_icon('rotate', 15) ?></summary>
          <form method="POST" action="<?= e($__self) ?>" class="fo__revform">
            <?= csrf_field() ?><input type="hidden" name="action" value="acct_refund"><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="payment_id" value="<?= (int)$__p['id'] ?>">
            <input name="amount" type="number" step="0.01" min="0.01" class="inp inp--sm inp--num no-spin" value="<?= e(number_format($__p['available_cents'] / 100, 2, '.', '')) ?>" aria-label="Refund amount">
            <input name="reason" class="inp inp--sm" maxlength="300" required placeholder="Why? e.g. deposit returned">
            <button type="submit" class="btn-sm btn-outline" data-confirm="Record this refund? It can't be undone.">Refund</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  // Choosing how it was paid picks a matching account (M-Pesa → till, card → merchant/bank…). The server enforces it too.
  document.querySelectorAll('[data-fo-method]').forEach(function(m){
    if (m.dataset.foBound) return; m.dataset.foBound = '1';
    var fits = JSON.parse(m.dataset.fits || '{}'), acct = m.form.querySelector('[name=account_id]');
    m.addEventListener('change', function(){
      var ok = fits[m.value] || [], cur = acct.options[acct.selectedIndex];
      if (cur && ok.indexOf(cur.dataset.kind) >= 0) return;
      for (var i = 0; i < acct.options.length; i++) if (ok.indexOf(acct.options[i].dataset.kind) >= 0) { acct.selectedIndex = i; acct.dispatchEvent(new Event('change', {bubbles:true})); break; }
    });
  });
})();
</script>
<style>
.fo{margin-bottom:16px}
.fo__head{flex-wrap:wrap;gap:8px 12px}
.fo__co{font-weight:400;font-size:13px}
.fo__body{padding:16px 20px}
.fo__empty{margin:0 0 6px;font-size:13px}
.fo__sums{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:12px}
.fo__sum{border:1px solid var(--border);border-radius:10px;padding:12px 14px}
.fo__due{display:flex;justify-content:space-between;align-items:baseline;gap:10px}
.fo__due span{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
.fo__due strong{font-size:20px;font-variant-numeric:tabular-nums}
.fo__due.is-due strong{color:#b45309}
.fo__due.is-clear strong{color:var(--green)}
.fo__parts{margin:8px 0 0;display:grid;gap:3px}
.fo__parts div{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:var(--muted)}
.fo__parts dd{margin:0;font-variant-numeric:tabular-nums}
.fo__hint{font-size:12.5px;margin:8px 0 0}
.fo__alert{margin:12px 0 0;font-size:13px}
.fo__sub{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:20px 0 8px}
.fo__tw .data-table{min-width:0}
.fo__table td,.fo__table th{padding:8px 10px}
.fo__table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.fo__issue{margin-top:12px}
.fo__pin{width:150px;text-transform:uppercase}
.fo__amt{width:120px}
.fo__acct{min-width:170px}
.fo__date{min-width:130px}
.fo__dep{align-self:center}
.fo__list{display:grid;gap:0;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.fo__row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:10px 14px;border-bottom:1px solid var(--border)}
.fo__row:last-child{border-bottom:none}
.fo__rowmain{flex:1 1 260px;min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px}
.fo__num{font-weight:600;font-family:ui-monospace,monospace;font-size:13px}
.fo__date2,.fo__why{font-size:12.5px}
.fo__amtcell{font-variant-numeric:tabular-nums;white-space:nowrap}
.fo__rev{position:relative}
.fo__rev>summary{list-style:none;cursor:pointer}
.fo__rev>summary::-webkit-details-marker{display:none}
.fo__rev[open]{flex-basis:100%}
.fo__rev[open]>summary{display:none}
.fo__revform{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.fo__revform .inp{flex:1 1 160px;min-width:0}
.fo__revform .inp--num{flex:0 0 110px}
.fo__clines{display:grid;gap:6px;flex-basis:100%;padding:4px 0 6px}
.fo__clines .ckwrap{align-items:flex-start}
@media (max-width:640px){
  .fo__body{padding:14px}
  .fo__issue,.fo__pay{flex-direction:column;align-items:stretch}
  .fo__issue .wsf,.fo__pay .wsf,.fo__pin,.fo__amt,.fo__acct,.fo__date{width:100%;min-width:0}
}
</style>
