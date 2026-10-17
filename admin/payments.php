<?php
/**
 * Admin: Payments — the payment-link generator and every link (Finance → Accounting
 * → Payments). Make a link for a room bill, an activity, a deposit or any amount;
 * share it by WhatsApp / email / copy / QR; see whether it was paid. Links made at
 * the till and from the Bill tab are listed here too.
 * Owner, managers, reception and front-desk staff (require_pay_links()); everyone
 * sees only the companies of their own properties. Logic: includes/pay-links.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/acct.php';
require_once __DIR__ . '/../includes/pay-links.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_pay_links();

$pageTitle  = 'Payments';
$activeMenu = 'payments';
$supported  = paystack_supported();
$self       = '/admin/payments.php';

$flash = $_SESSION['pl_flash'] ?? null; unset($_SESSION['pl_flash']);
$old   = $_SESSION['pl_old'] ?? null;   unset($_SESSION['pl_old']);
$back  = function (string $qs = ''): never { global $self; header('Location: ' . $self . ($qs !== '' ? '?' . $qs : '')); exit; };

if ($supported && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $uid = (int)($_SESSION['admin_id'] ?? 0) ?: null;
    try {
        if ($act === 'create') {
            $id = pay_link_create($_POST, $uid);
            $back('created=' . $id);
        }
        $l = pay_link_fetch((int)($_POST['link_id'] ?? 0));
        $scope = pay_link_company_scope();
        if (!$l || ($scope !== null && !in_array((int)$l['company_id'], $scope, true))) throw new PayLinkRefusal('That link is not one of yours.');
        if ($act === 'cancel') {
            if (!pay_link_can_cancel($l)) throw new PayLinkRefusal('Only the person who made it, a manager or the owner can cancel a link.');
            pay_link_cancel((int)$l['id'], $uid);
            $_SESSION['pl_flash'] = ['type' => 'success', 'msg' => pay_link_number((int)$l['id']) . ' cancelled — the guest can no longer pay it.'];
        }
        if ($act === 'check') {
            $l2 = pay_link_check((int)$l['id'], true);
            $st = $l2 ? pay_link_effective_status($l2) : 'waiting';
            $_SESSION['pl_flash'] = $st === 'paid'
                ? ['type' => 'success', 'msg' => pay_link_number((int)$l['id']) . ' is paid. ' . (string)($l2['note'] ?? '')]
                : ['type' => 'info', 'msg' => empty($l['started_at']) ? 'The guest hasn’t opened the payment page yet.' : 'Paystack shows no completed payment yet.'];
        }
    } catch (PayLinkRefusal $e) {
        if ($act === 'create') { $_SESSION['pl_old'] = $_POST; $_SESSION['pl_flash'] = ['type' => 'error', 'msg' => $e->getMessage()]; $back('new=1'); }
        $_SESSION['pl_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    $back((string)($_POST['ret'] ?? ''));
}

// ── Page data ──
$ready    = $supported ? pay_link_ready_companies() : [];
$usable   = array_filter($ready, fn($r) => $r['cfg']['ready']);
$scope    = $supported ? pay_link_company_scope() : [];
$status   = isset(PAY_LINK_STATUSES[$_GET['status'] ?? '']) ? (string)$_GET['status'] : '';
$q        = trim((string)($_GET['q'] ?? ''));
$links    = $supported ? pay_link_list(['status' => $status, 'q' => $q], $scope) : [];
$all      = $supported ? ($status === '' && $q === '' ? $links : pay_link_list([], $scope)) : [];
$sum      = pay_link_summary($all);
$created  = $supported && isset($_GET['created']) ? pay_link_fetch((int)$_GET['created']) : false;
if ($created && $scope !== null && !in_array((int)$created['company_id'], $scope, true)) $created = false;

// Prefill: from the Bill tab (?hold=) or a refused form.
$form = $old ?? [];
$hold = null; $holdDue = [];
$holdQ = (string)($form['hold'] ?? $_GET['hold'] ?? '');
if ($supported && $holdQ !== '' && ($h = pay_link_find_hold($holdQ)) && staff_can_hold((int)$h['id'])) {
    $hold = $h; $holdDue = pay_link_hold_due((int)$h['id']);
}
$purpose  = (string)($form['purpose'] ?? ($_GET['purpose'] ?? ($hold ? 'bill' : 'activity')));
if (!isset(PAY_LINK_PURPOSES[$purpose]) || $purpose === 'pos') $purpose = 'activity';
$newOpen  = $old !== null || $hold !== null || isset($_GET['new']) || (!$links && $status === '' && $q === '');
$tours    = $supported ? db_query("SELECT id, name, price_amount FROM tours WHERE is_published ORDER BY sort_order, name")->fetchAll() : [];
$siteCur  = strtoupper(setting('site_currency', 'USD'));
$coCurs   = [];
foreach ($usable as $cid => $r) $coCurs[$cid] = array_keys($r['cfg']['accounts']);
$holdCo   = $hold && $hold['company_id'] ? (int)$hold['company_id'] : 0;
$fv       = fn(string $k, string $d = ''): string => e((string)($form[$k] ?? $d));
$qs       = fn(array $o): string => $self . '?' . http_build_query(array_filter(array_merge(['status' => $status, 'q' => $q], $o), fn($v) => $v !== null && $v !== ''));
$retQs    = http_build_query(array_filter(['status' => $status, 'q' => $q]));
$badge    = ['waiting' => 'badge--orange', 'paid' => 'badge--green', 'expired' => 'badge--grey', 'cancelled' => 'badge--grey'];

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Payments</h1>
  <?php if ($supported && $usable): ?><button type="button" class="btn-primary btn-sm" id="plNewBtn" data-help="pl-new" aria-controls="plNew" aria-expanded="<?= $newOpen ? 'true' : 'false' ?>"><?= admin_icon('plus', 15) ?> New payment link</button><?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_paystack.sql</code> migration (Admin → Migrations) first.</div>
<?php else: ?>

<?php if (!$usable): ?>
  <div class="alert alert--info pl-setup">
    <strong>No company is connected to Paystack yet.</strong>
    <?php if (is_owner()): ?> Set it up in Accounting → Companies → <em>company</em> → Paystack:
      <ul><?php foreach ($ready as $cid => $r): ?><li><a href="/admin/company-edit.php?id=<?= (int)$cid ?>&amp;tab=paystack"><?= e($r['row']['name']) ?></a> — <?= e($r['cfg']['problem']) ?></li><?php endforeach; ?></ul>
    <?php else: ?> Ask the owner to connect it (Accounting → Companies → Paystack).<?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($created): $cUrl = pay_link_url((string)$created['token']); $cText = pay_link_share_text($created, (string)$created['company_name']); ?>
<div class="card pl-ready" id="plReady">
  <div class="card__head"><span class="card__title"><?= admin_icon('check', 16) ?> Link ready · <?= e(pay_link_number((int)$created['id'])) ?></span>
    <?php if ($created['mode'] !== 'live'): ?><span class="badge badge--orange"><?= e(PAYSTACK_MODES[$created['mode']]) ?> — no real money</span><?php endif; ?></div>
  <div class="card__body pl-ready__body">
    <div class="pl-qr" data-pl-qr="<?= e($cUrl) ?>" aria-label="QR code for the payment link"></div>
    <div class="pl-ready__main">
      <div class="pl-ready__amt"><?= e(pay_link_money($created['amount'], (string)$created['currency'])) ?></div>
      <div class="text-muted"><?= e((string)$created['description']) ?><?= $created['customer_name'] !== '' ? ' · ' . e((string)$created['customer_name']) : '' ?> · <?= e((string)$created['company_name']) ?></div>
      <div class="pl-url"><input readonly value="<?= e($cUrl) ?>" class="co-mono" id="plUrl" onclick="this.select()"></div>
      <div class="pl-share">
        <a class="btn-primary btn-sm" target="_blank" rel="noopener" href="<?= e(pay_link_whatsapp_url((string)$created['customer_phone'], $cText)) ?>"><?= admin_icon('send', 14) ?> WhatsApp</a>
        <a class="btn-outline btn-sm" href="mailto:<?= e(rawurlencode((string)$created['customer_email'])) ?>?subject=<?= e(rawurlencode('Your payment link — ' . $created['company_name'])) ?>&amp;body=<?= e(rawurlencode($cText)) ?>"><?= admin_icon('message', 14) ?> Email</a>
        <button type="button" class="btn-outline btn-sm" data-pl-copy="<?= e($cUrl) ?>"><?= admin_icon('copy', 14) ?> Copy link</button>
        <a class="btn-outline btn-sm" href="<?= e($cUrl) ?>" target="_blank" rel="noopener"><?= admin_icon('external-link', 14) ?> Open</a>
      </div>
      <p class="text-muted pl-small">When the guest pays, it shows as Paid here<?= $created['hold_id'] ? ' and on the booking’s Bill tab' : '' ?>. Valid until <?= e(date('j M Y, H:i', strtotime((string)$created['expires_at']))) ?>.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($usable): ?>
<div class="card pl-new" id="plNew" data-inline-form<?= $newOpen ? '' : ' hidden' ?>>
  <div class="card__head"><span class="card__title">New payment link</span></div>
  <div class="card__body pl-body">
    <form method="POST" action="<?= e($self) ?>" id="plForm" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="pl-sub">What is it for?</div>
      <div class="co-chips pl-purpose">
        <?php foreach (PAY_LINK_PURPOSES as $k => $lbl): if ($k === 'pos') continue; ?>
        <label class="optchip"><input type="radio" name="purpose" value="<?= e($k) ?>" <?= $purpose === $k ? 'checked' : '' ?>><?= e($lbl) ?></label>
        <?php endforeach; ?>
      </div>

      <div class="pl-grid">
        <div class="field" data-pl-for="bill deposit"><label>Booking</label>
          <?php if ($hold): ?>
            <input type="hidden" name="hold" value="<?= (int)$hold['id'] ?>">
            <div class="pl-hold"><strong><?= e((string)$hold['guest_name']) ?></strong> · <?= e(trim(($hold['room_name'] ?? '') . ' · ' . ($hold['venue_name'] ?? ''), ' ·')) ?>
              <div class="text-muted pl-small"><?= e(date('j M', strtotime((string)$hold['check_in'])) . ' – ' . date('j M Y', strtotime((string)$hold['check_out']))) ?> ·
                <?= $holdDue ? 'Balance due ' . e(implode(' + ', array_map(fn($c, $v) => pay_link_money($v / 100, $c), array_keys($holdDue), $holdDue))) : 'No balance on the folio' ?> ·
                <a href="<?= e($self . '?new=1&purpose=' . $purpose) ?>">another booking</a></div></div>
          <?php else: ?>
            <input name="hold" value="<?= $fv('hold') ?>" placeholder="Booking number or booking code" maxlength="20">
            <span class="field-hint">Or open the booking → Bill tab → Payment link.</span>
          <?php endif; ?>
        </div>
        <div class="field" data-pl-for="activity"><label>Activity</label>
          <select name="tour_id" id="plTour" data-site-cur="<?= e($siteCur) ?>"><option value="">Pick an activity…</option>
            <?php foreach ($tours as $t): ?><option value="<?= (int)$t['id'] ?>" data-price="<?= $t['price_amount'] !== null ? e((string)(float)$t['price_amount']) : '' ?>" data-name="<?= e($t['name']) ?>" <?= (int)($form['tour_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['price_amount'] !== null && (float)$t['price_amount'] > 0 ? ' · ' . e(pay_link_money($t['price_amount'], $siteCur)) : '' ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" data-pl-for="activity other"><label>Company (who gets paid)</label>
          <select name="company_id" id="plCo"><?php foreach ($usable as $cid => $r): ?><option value="<?= (int)$cid ?>" data-curs="<?= e(implode(',', $coCurs[$cid])) ?>" <?= (int)($form['company_id'] ?? 0) === (int)$cid ? 'selected' : '' ?>><?= e($r['row']['name']) ?><?= $r['cfg']['mode'] !== 'live' ? ' (' . e(PAYSTACK_MODES[$r['cfg']['mode']]) . ')' : '' ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Currency</label>
          <select name="currency" id="plCur" data-hold-curs="<?= e($holdCo && isset($coCurs[$holdCo]) ? implode(',', $coCurs[$holdCo]) : '') ?>">
            <?php foreach (PAYSTACK_CURRENCIES as $c): ?><option value="<?= e($c) ?>" data-due="<?= isset($holdDue[$c]) ? e((string)($holdDue[$c] / 100)) : '' ?>" <?= ($form['currency'] ?? ($holdDue ? array_key_first($holdDue) : ($purpose === 'activity' ? $siteCur : 'KES'))) === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Amount</label><input name="amount" id="plAmt" inputmode="decimal" value="<?= $fv('amount', $hold && $holdDue ? (string)(reset($holdDue) / 100) : '') ?>" placeholder="<?= $hold ? 'Blank = balance due' : '0.00' ?>"></div>
        <div class="field pl-wide"><label>Shown to the guest as</label><input name="description" id="plDesc" maxlength="200" value="<?= $fv('description') ?>" placeholder="<?= $hold ? e(pay_link_hold_description($hold, $purpose)) : 'e.g. Deep tissue massage 60 min' ?>"></div>
        <div class="field"><label>Guest name</label><input name="customer_name" maxlength="160" value="<?= $fv('customer_name', $hold['guest_name'] ?? '') ?>"></div>
        <div class="field"><label>Phone <span class="text-muted">(for WhatsApp)</span></label><input name="customer_phone" maxlength="40" inputmode="tel" value="<?= $fv('customer_phone', $hold['guest_phone'] ?? '') ?>" placeholder="+254 7…"></div>
        <div class="field"><label>Email <span class="text-muted">(optional)</span></label><input name="customer_email" type="email" maxlength="160" value="<?= $fv('customer_email', $hold['guest_email'] ?? '') ?>"></div>
        <div class="field"><label>Link expires</label>
          <select name="expires_in"><?php foreach (PAY_LINK_EXPIRY as $h => $lbl): ?><option value="<?= $h ?>" <?= (int)($form['expires_in'] ?? 48) === $h ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="pl-foot">
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('link', 15) ?> Create link</button>
        <span class="text-muted pl-small">Creating a link sends nothing — you share it next.</span>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="ad-sums pl-sums">
  <div class="card ad-sum"><div class="ad-sum__co">Waiting</div><div class="ad-sum__tot"><?= (int)$sum['waiting'] ?></div></div>
  <div class="card ad-sum"><div class="ad-sum__co">Paid this month</div><div class="ad-sum__tot pl-paidsum"><?= $sum['paid_month'] ? e(implode(' · ', array_map(fn($c, $v) => pay_link_money($v / 100, $c), array_keys($sum['paid_month']), $sum['paid_month']))) : '—' ?></div></div>
  <div class="card ad-sum"><div class="ad-sum__co">Expired</div><div class="ad-sum__tot"><?= (int)$sum['expired'] ?></div></div>
</div>

<div class="pl-bar" data-help="pl-list">
  <nav class="segswitch" aria-label="Which links">
    <?php foreach (['' => 'All'] + PAY_LINK_STATUSES as $k => $lbl): ?>
    <a class="<?= $status === $k ? 'is-on' : '' ?>" href="<?= e($qs(['status' => $k])) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </nav>
  <form method="GET" action="<?= e($self) ?>" class="pl-search"><input type="hidden" name="status" value="<?= e($status) ?>">
    <input name="q" value="<?= e($q) ?>" placeholder="Search guest, what for, reference" class="inp inp--sm"><button class="btn-outline btn-sm" type="submit"><?= admin_icon('search', 14) ?></button></form>
</div>

<div class="card">
  <?php if (!$links): dt_empty($q !== '' || $status !== '' ? 'No links match.' : 'No payment links yet.', 'inbox'); else: ?>
  <div class="table-wrap">
  <table class="data-table" id="plTable" data-paginate="25">
    <thead><tr><th>Ref</th><th>For</th><th>Company</th><th class="num">Amount</th><th>Status</th><th>Made</th><th class="pl-act"></th></tr></thead>
    <tbody>
    <?php foreach ($links as $l): $st = pay_link_effective_status($l); $url = pay_link_url((string)$l['token']); ?>
      <tr>
        <td><span class="ad-num"><?= e(pay_link_number((int)$l['id'])) ?></span>
          <div class="text-muted ad-small"><?= e(PAY_LINK_PURPOSES[$l['purpose']] ?? $l['purpose']) ?><?= $l['mode'] !== 'live' ? ' · ' . e(PAYSTACK_MODES[$l['mode']]) : '' ?></div></td>
        <td><?= e((string)$l['description']) ?>
          <div class="text-muted ad-small"><?= e((string)$l['customer_name']) ?>
            <?php if ($l['hold_id']): ?> <a href="/admin/booking.php?hold=<?= (int)$l['hold_id'] ?>&amp;tab=bill">booking</a><?php endif; ?>
            <?php if ($l['pos_sale_id']): ?> <a href="/admin/pos-sales.php?sale=<?= (int)$l['pos_sale_id'] ?>">sale</a><?php endif; ?></div>
          <?php if (trim((string)$l['note']) !== ''): ?><div class="ad-small pl-note"><?= e((string)$l['note']) ?></div><?php endif; ?></td>
        <td><?= e((string)$l['company_name']) ?></td>
        <td class="num"><strong><?= e(pay_link_money($l['amount'], (string)$l['currency'])) ?></strong></td>
        <td><span class="badge <?= $badge[$st] ?? 'badge--grey' ?>"><?= e(PAY_LINK_STATUSES[$st] ?? $st) ?></span>
          <?php if ($st === 'paid' && $l['paid_at']): ?><div class="text-muted ad-small"><?= e(date('j M, H:i', strtotime((string)$l['paid_at']))) ?><?= $l['paid_channel'] !== '' ? ' · ' . e(str_replace('_', ' ', (string)$l['paid_channel'])) : '' ?></div>
          <?php elseif ($st === 'waiting'): ?><div class="text-muted ad-small"><?= $l['started_at'] ? 'Opened by the guest' : 'Not opened yet' ?></div><?php endif; ?></td>
        <td><span class="ad-small"><?= e(date('j M, H:i', strtotime((string)$l['created_at']))) ?></span><div class="text-muted ad-small"><?= e((string)($l['created_by_name'] ?? '')) ?></div></td>
        <td class="pl-act">
          <?php if ($st === 'waiting'): ?>
            <button type="button" class="btn-icon" data-pl-copy="<?= e($url) ?>" data-tip="Copy link" aria-label="Copy link"><?= admin_icon('copy', 15) ?></button>
            <a class="btn-icon" href="<?= e($self . '?created=' . (int)$l['id']) ?>" data-tip="Share / QR" aria-label="Share"><?= admin_icon('send', 15) ?></a>
            <?php if ($l['started_at'] && $l['mode'] !== 'practice'): ?>
            <form method="POST" action="<?= e($self) ?>" class="pl-inline"><?= csrf_field() ?><input type="hidden" name="action" value="check"><input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="ret" value="<?= e($retQs) ?>">
              <button type="submit" class="btn-icon" data-tip="Check with Paystack now" aria-label="Check now"><?= admin_icon('rotate', 15) ?></button></form>
            <?php endif; ?>
            <?php if (pay_link_can_cancel($l)): ?>
            <form method="POST" action="<?= e($self) ?>" class="pl-inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="ret" value="<?= e($retQs) ?>">
              <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Cancel <?= e(pay_link_number((int)$l['id'])) ?>? The guest won't be able to pay it." data-tip="Cancel link" aria-label="Cancel link"><?= admin_icon('x', 15) ?></button></form>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn-icon" href="<?= e($url) ?>" target="_blank" rel="noopener" data-tip="Open the guest's page" aria-label="Open"><?= admin_icon('external-link', 15) ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<style>
.pl-setup ul{margin:8px 0 0 18px;padding:0}
.pl-ready{margin-bottom:16px;border-color:var(--brand)}
.pl-ready__body{display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;padding:18px}
.pl-qr{width:156px;height:156px;flex:none;background:#fff;border:1px solid var(--border);border-radius:10px;padding:6px;display:flex;align-items:center;justify-content:center}
.pl-qr svg,.pl-qr img{width:100%;height:100%}
.pl-ready__main{flex:1;min-width:min(100%,260px)}
.pl-ready__amt{font-size:24px;font-weight:700}
.pl-url{margin:12px 0 10px}.pl-url input{width:100%;font-size:12.5px}
.pl-share{display:flex;flex-wrap:wrap;gap:8px}
.pl-small{font-size:12.5px}
.pl-new{margin-bottom:16px}
.pl-body{padding:18px 20px}
.pl-sub{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:0 0 8px}
.pl-purpose{margin-bottom:16px}
.pl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:0 16px}
.pl-grid .field{min-width:0}
.pl-wide{grid-column:1/-1}
.pl-grid [data-pl-for][hidden]{display:none}
.pl-hold{border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-size:13.5px;background:#f9fafb}
.pl-foot{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:8px}
.pl-sums{margin-top:4px}
.pl-paidsum{font-size:17px}
.pl-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px}
.pl-search{display:flex;gap:6px;margin:0}.pl-search input{min-width:min(100%,240px)}
.pl-note{color:var(--muted);margin-top:3px;max-width:420px}
.pl-act{text-align:right;white-space:nowrap;width:1%}
.pl-inline{display:inline;margin:0}
.ad-sums{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:12px;margin-bottom:16px}
.ad-sum{padding:14px 16px}
.ad-sum__co{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
.ad-sum__tot{font-size:20px;font-weight:600;margin:4px 0 2px;font-variant-numeric:tabular-nums}
.ad-num{font-family:ui-monospace,monospace;font-weight:600}
.ad-small{font-size:12px}
.data-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
@media (max-width:640px){.pl-qr{width:132px;height:132px}.pl-bar{flex-direction:column;align-items:stretch}.pl-search input{flex:1}}
</style>
<script src="/js/vendor/qrcode.js?v=<?= @filemtime(__DIR__ . '/../js/vendor/qrcode.js') ?: 1 ?>"></script>
<script>
(function () {
  var root = document.querySelector('.pl-bar'); if (!root || root.dataset.ready) return; root.dataset.ready = '1';
  // QR for the link just made.
  document.querySelectorAll('[data-pl-qr]').forEach(function (el) {
    if (el.firstChild || !window.qrcode) return;
    var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-pl-qr')); qr.make();
    el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
  });
  // Copy buttons.
  document.querySelectorAll('[data-pl-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.getAttribute('data-pl-copy');
      var done = function () { if (window.tsToast) window.tsToast('Link copied'); };
      if (navigator.clipboard) navigator.clipboard.writeText(v).then(done, function () { prompt('Copy the link', v); });
      else prompt('Copy the link', v);
    });
  });
  // New link: open/close, and show only the fields for the chosen purpose.
  var btn = document.getElementById('plNewBtn'), card = document.getElementById('plNew');
  if (btn && card) btn.addEventListener('click', function () { var open = card.hidden; card.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); });
  var form = document.getElementById('plForm'); if (!form) return;
  var co = document.getElementById('plCo'), cur = document.getElementById('plCur'), amt = document.getElementById('plAmt'),
      desc = document.getElementById('plDesc'), tour = document.getElementById('plTour');
  function purpose() { var r = form.querySelector('[name="purpose"]:checked'); return r ? r.value : ''; }
  function syncCurs() {
    var p = purpose(), list;
    if (p === 'bill' || p === 'deposit') list = cur.dataset.holdCurs ? cur.dataset.holdCurs.split(',') : null;
    else { var o = co && co.options[co.selectedIndex]; list = o ? o.dataset.curs.split(',') : null; }
    Array.prototype.forEach.call(cur.options, function (o) { o.disabled = !!(list && list.indexOf(o.value) < 0); });
    if (cur.options[cur.selectedIndex] && cur.options[cur.selectedIndex].disabled) {
      var first = Array.prototype.filter.call(cur.options, function (o) { return !o.disabled; })[0]; if (first) cur.value = first.value;
    }
    cur.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function sync() {
    var p = purpose();
    form.querySelectorAll('[data-pl-for]').forEach(function (f) { f.hidden = f.dataset.plFor.split(' ').indexOf(p) < 0; });
    syncCurs();
  }
  form.addEventListener('change', function (e) {
    if (e.target.name === 'purpose') sync();
    if (e.target === co) syncCurs();
    if (e.target === cur && purpose() === 'bill') { var d = cur.options[cur.selectedIndex].dataset.due; if (d) amt.value = d; }
    if (e.target === tour) {
      var o = tour.options[tour.selectedIndex];
      // The catalogue price is in the site currency: fill it only when the link is in that currency.
      if (o && o.dataset.price && cur.value === tour.dataset.siteCur) amt.value = o.dataset.price;
      else if (o && o.dataset.price && window.tsToast) window.tsToast('Its price is ' + tour.dataset.siteCur + ' ' + o.dataset.price + ' — type the ' + cur.value + ' amount.');
      if (o && o.value && !desc.value) desc.value = o.dataset.name;
    }
  });
  sync();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
