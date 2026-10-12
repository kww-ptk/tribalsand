<?php
/**
 * Admin: POS consignment suppliers — people whose goods we sell and pay out for.
 * Owner or manager. Each supplier has a default commission % (what WE keep);
 * an item can instead carry a fixed "cost to us" (set on the item). Sale lines
 * snapshot whichever applied, so editing a supplier never rewrites past sales.
 * The Statement card shows, per supplier and currency, what was sold, what we
 * keep, what we owe and what was paid (pos_consignment_statement()); "Paid"
 * records a pos_consignor_payouts row for the period.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pos.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();
require_manager();

$pageTitle  = 'POS suppliers';
$activeMenu = 'pos_consignors';
$supported  = pos_supported();
$self       = '/admin/pos-consignors.php';

$flash = $_SESSION['posc_flash'] ?? null; unset($_SESSION['posc_flash']);
$errs  = $_SESSION['posc_errors'] ?? [];  unset($_SESSION['posc_errors']);
$old   = $_SESSION['posc_old'] ?? null;   unset($_SESSION['posc_old']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    $row = $id ? db_query('SELECT * FROM pos_consignors WHERE id = :id', [':id' => $id])->fetch() : false;
    if ($id && !$row) { $_SESSION['posc_flash'] = ['type' => 'error', 'msg' => 'That supplier no longer exists.']; header('Location: ' . $self); exit; }

    if ($act === 'save') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $pct   = trim((string)($_POST['commission_pct'] ?? '0'));
        $e = [];
        if ($name === '' || mb_strlen($name) > 120)                   $e['name'] = 'Give the supplier a name (up to 120 characters).';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'That email address does not look right.';
        if (!is_numeric($pct) || (float)$pct < 0 || (float)$pct > 100) $e['commission_pct'] = 'Commission is a percentage from 0 to 100.';
        if ($e) {
            $_SESSION['posc_errors'] = $e; $_SESSION['posc_old'] = $_POST;
            header('Location: ' . $self . ($id ? '?edit=' . $id : '') . '#form'); exit;
        }
        $p = [':n' => $name, ':ph' => $phone !== '' ? mb_substr($phone, 0, 40) : null, ':e' => $email !== '' ? mb_substr($email, 0, 160) : null,
              ':c' => round((float)$pct, 2), ':no' => trim((string)($_POST['notes'] ?? '')) ?: null, ':a' => !empty($_POST['is_active']) ? 'TRUE' : 'FALSE'];
        if ($id) {
            db_query('UPDATE pos_consignors SET name = :n, phone = :ph, email = :e, commission_pct = :c, notes = :no, is_active = :a WHERE id = :id', $p + [':id' => $id]);
        } else {
            db_query('INSERT INTO pos_consignors (name, phone, email, commission_pct, notes, is_active) VALUES (:n, :ph, :e, :c, :no, :a)', $p);
            $id = (int) db()->lastInsertId();
        }
        audit_log('pos.consignor_save', 'pos_consignor', $id, $name);
        $_SESSION['posc_flash'] = ['type' => 'success', 'msg' => "{$name} saved."];
        header('Location: ' . $self); exit;
    }

    if ($act === 'payout' && $row) {
        $back = $self . '?from=' . urlencode((string)($_POST['from'] ?? '')) . '&to=' . urlencode((string)($_POST['to'] ?? '')) . '#statement';
        try {
            $pid = pos_record_payout($id, (string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''), (float)($_POST['amount'] ?? 0),
                                     (string)($_POST['currency'] ?? ''), (string)($_POST['note'] ?? ''), (int)current_admin()['id']);
            audit_log('pos.consignor_payout', 'pos_consignor', $id, ($_POST['currency'] ?? '') . ' ' . ($_POST['amount'] ?? ''));
            $_SESSION['posc_flash'] = ['type' => 'success', 'msg' => "Payment to {$row['name']} recorded."];
        } catch (PosRefusal $e) {
            $_SESSION['posc_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
        }
        header('Location: ' . $back); exit;
    }

    if ($act === 'delete' && $row) {
        $used = db_query('SELECT (SELECT COUNT(*) FROM pos_items WHERE consignor_id = :a) + (SELECT COUNT(*) FROM pos_sale_lines WHERE consignor_id = :b)',
            [':a' => $id, ':b' => $id])->fetchColumn();
        if ((int)$used > 0) {
            db_query('UPDATE pos_consignors SET is_active = FALSE WHERE id = :id', [':id' => $id]);
            $_SESSION['posc_flash'] = ['type' => 'info', 'msg' => "{$row['name']} has items or sales, so they were marked inactive instead of deleted."];
        } else {
            db_query('DELETE FROM pos_consignors WHERE id = :id', [':id' => $id]);
            $_SESSION['posc_flash'] = ['type' => 'success', 'msg' => "{$row['name']} deleted."];
        }
        audit_log('pos.consignor_delete', 'pos_consignor', $id, (string)$row['name']);
        header('Location: ' . $self); exit;
    }
    header('Location: ' . $self); exit;
}

$rows = [];
if ($supported) {
    $rows = db_query(
        "SELECT c.*, (SELECT COUNT(*) FROM pos_items i WHERE i.consignor_id = c.id AND i.is_active = TRUE) AS item_count
           FROM pos_consignors c ORDER BY c.is_active DESC, c.name"
    )->fetchAll();
}
$editId = (int)($_GET['edit'] ?? 0);
$form = ['id' => 0, 'name' => '', 'phone' => '', 'email' => '', 'commission_pct' => '0', 'notes' => '', 'is_active' => true];
foreach ($rows as $r) if ((int)$r['id'] === $editId) { $form = $r; break; }
if ($old) $form = array_merge($form, array_intersect_key($old, $form), ['is_active' => !empty($old['is_active'])]);
$isEdit = (int)$form['id'] > 0;
$formOpen = $isEdit || !empty($_GET['new']) || $errs;
$pctTxt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') ?: '0';

// Statement period — defaults to this month so far (Nairobi).
$valid  = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : null;
$today  = frontdesk_today_ymd();
$stFrom = $valid($_GET['from'] ?? '') ?? date('Y-m-01', strtotime($today));
$stTo   = $valid($_GET['to'] ?? '') ?? $today;
if ($stTo < $stFrom) [$stFrom, $stTo] = [$stTo, $stFrom];
$statement = $supported ? pos_consignment_statement($stFrom, $stTo) : [];

// Per supplier: their statement lines (one per currency). Totals per currency.
$stBy = []; $tot = [];
foreach ($statement as $st) {
    $stBy[(int)$st['consignor_id']][] = $st;
    $c = (string)$st['currency'];
    $tot[$c] = $tot[$c] ?? ['owe' => 0.0, 'paid' => 0.0, 'gross' => 0.0];
    $tot[$c]['owe']   += max(0, (float)$st['balance']);
    $tot[$c]['paid']  += (float)$st['paid'];
    $tot[$c]['gross'] += (float)$st['gross'];
}
$initials = fn(string $n) => mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2))));
$periodTxt = date('j M', strtotime($stFrom)) . ' – ' . date('j M Y', strtotime($stTo));

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Suppliers</h1>
  <?php if ($supported): ?>
  <div class="actions">
    <form method="GET" action="<?= $self ?>" class="posc-period" id="poscPeriod">
      <button type="button" class="dp-btn inp" data-dp-target="poscFrom" data-dp-past data-dp-placeholder="From"><?= e(date('j M Y', strtotime($stFrom))) ?></button>
      <input type="hidden" id="poscFrom" name="from" value="<?= e($stFrom) ?>">
      <span class="text-muted">to</span>
      <button type="button" class="dp-btn inp" data-dp-target="poscTo" data-dp-past data-dp-placeholder="To"><?= e(date('j M Y', strtotime($stTo))) ?></button>
      <input type="hidden" id="poscTo" name="to" value="<?= e($stTo) ?>">
    </form>
    <button type="button" class="btn-primary btn-sm" id="poscAddBtn" aria-controls="form" aria-expanded="<?= $formOpen && !$isEdit ? 'true' : 'false' ?>"><?= admin_icon('plus', 15) ?> Add supplier</button>
  </div>
  <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Settings → Your account → Migrations) to set up the POS.</div>
<?php else: ?>

<div class="card posc-formcard" id="form" data-inline-form<?= $isEdit ? ' data-close-href="' . e($self) . '"' : '' ?><?= $formOpen ? '' : ' hidden' ?>>
  <div class="card__head"><span class="card__title"><?= $isEdit ? 'Edit ' . e($form['name']) : 'Add a supplier' ?></span></div>
  <div class="card__body" style="padding:18px 20px">
    <form method="POST" action="<?= $self ?>" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
      <div class="posc-fields">
        <div class="field"><label>Name</label><input name="name" class="inp<?= isset($errs['name']) ? ' is-invalid' : '' ?>" maxlength="120" value="<?= e((string)$form['name']) ?>" required>
          <?php if (isset($errs['name'])): ?><div class="field-error"><?= e($errs['name']) ?></div><?php endif; ?></div>
        <div class="field"><label>Phone</label><input name="phone" class="inp" maxlength="40" value="<?= e((string)$form['phone']) ?>"></div>
        <div class="field"><label>Email</label><input name="email" type="email" class="inp<?= isset($errs['email']) ? ' is-invalid' : '' ?>" maxlength="160" value="<?= e((string)$form['email']) ?>">
          <?php if (isset($errs['email'])): ?><div class="field-error"><?= e($errs['email']) ?></div><?php endif; ?></div>
        <div class="field"><label>We keep (%)</label><input name="commission_pct" type="number" class="inp inp--num no-spin<?= isset($errs['commission_pct']) ? ' is-invalid' : '' ?>" min="0" max="100" step="0.01" value="<?= e($pctTxt($form['commission_pct'])) ?>">
          <?php if (isset($errs['commission_pct'])): ?><div class="field-error"><?= e($errs['commission_pct']) ?></div><?php endif; ?></div>
      </div>
      <div class="field" style="margin-top:12px"><label>Notes <span class="text-muted">(payment terms, M-Pesa number…)</span></label><textarea name="notes" class="inp inp--area" rows="2" style="width:100%"><?= e((string)$form['notes']) ?></textarea></div>
      <div class="posc-formfoot">
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_active" value="1" <?= pos_bool($form['is_active']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Active</span></label>
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $isEdit ? 'Save supplier' : 'Add supplier' ?></button>
      </div>
      <p class="text-muted" style="font-size:12px;margin:10px 0 0">What we keep is our share; the rest is owed to the supplier. An item can instead carry a fixed amount we owe. Each sale remembers the terms it was sold on.</p>
    </form>
  </div>
</div>

<div class="pos-ribbon">
  <?php if (!$tot): ?>
    <div><span>We owe</span><b>—</b></div><div><span>Paid</span><b>—</b></div><div><span>Sold for suppliers</span><b>—</b></div>
  <?php else: foreach ($tot as $c => $t): ?>
    <div><span>We owe<?= count($tot) > 1 ? ' · ' . e($c) : '' ?></span><b class="<?= $t['owe'] > 0 ? 'posc-due' : '' ?>"><?= e(pos_money($t['owe'], $c)) ?></b></div>
    <div><span>Paid<?= count($tot) > 1 ? ' · ' . e($c) : '' ?></span><b><?= e(pos_money($t['paid'], $c)) ?></b></div>
    <div><span>Sold for suppliers<?= count($tot) > 1 ? ' · ' . e($c) : '' ?></span><b><?= e(pos_money($t['gross'], $c)) ?></b></div>
  <?php endforeach; endif; ?>
  <div><span>Period</span><b style="font-size:14px"><?= e($periodTxt) ?></b></div>
</div>

<?php if (!$rows): ?>
  <?php dt_empty('No suppliers yet — press Add supplier.'); ?>
<?php else: ?>
<div class="posc-cards">
  <?php foreach ($rows as $r): $sid = (int)$r['id']; $lines = $stBy[$sid] ?? []; $owe = array_filter($lines, fn($l) => (float)$l['balance'] > 0); $active = pos_bool($r['is_active']); ?>
  <article class="card posc-card<?= $active ? '' : ' is-off' ?>" id="sup-<?= $sid ?>">
    <header class="posc-card__head">
      <span class="posc-av"><?= e($initials((string)$r['name'])) ?></span>
      <span class="posc-card__who"><b><?= e($r['name']) ?></b>
        <small><?= e(implode(' · ', array_filter([(string)$r['phone'], (string)$r['email']]))) ?: 'No contact details' ?></small></span>
      <?php if (!$active): ?><span class="badge badge--grey">Inactive</span>
      <?php elseif ($owe): ?><span class="badge badge--orange">Owe <?= e(implode(' + ', array_map(fn($l) => pos_money((float)$l['balance'], (string)$l['currency']), $owe))) ?></span>
      <?php else: ?><span class="badge badge--green">Settled</span><?php endif; ?>
    </header>
    <div class="posc-mini">
      <div><span>We keep</span><b><?= e($pctTxt($r['commission_pct'])) ?>%</b></div>
      <div><span>Items</span><b><?= (int)$r['item_count'] ?></b></div>
      <div><span>Sold (period)</span><b><?= $lines ? e(implode(' + ', array_map(fn($l) => pos_money((float)$l['gross'], (string)$l['currency']), $lines))) : '—' ?></b></div>
    </div>
    <?php if (!empty($r['notes'])): ?><p class="posc-note"><?= e($r['notes']) ?></p><?php endif; ?>
    <?php if ($lines): ?>
    <dl class="posc-stmt">
      <?php foreach ($lines as $l): $c = (string)$l['currency']; ?>
      <dt><?= (int)$l['qty'] ?> sold · we keep <?= e(pos_money((float)$l['kept'], $c)) ?></dt><dd>owed <?= e(pos_money((float)$l['owed'], $c)) ?> · paid <?= e(pos_money((float)$l['paid'], $c)) ?></dd>
      <?php endforeach; ?>
    </dl>
    <?php endif; ?>
    <?php foreach ($owe as $l): $c = (string)$l['currency']; ?>
    <form method="POST" action="<?= $self ?>" class="posc-pay">
      <?= csrf_field() ?><input type="hidden" name="action" value="payout"><input type="hidden" name="id" value="<?= $sid ?>">
      <input type="hidden" name="from" value="<?= e($stFrom) ?>"><input type="hidden" name="to" value="<?= e($stTo) ?>"><input type="hidden" name="currency" value="<?= e($c) ?>">
      <span class="inp-money"><span class="inp-money__cur"><?= e($c) ?></span><input name="amount" type="number" class="inp inp--sm inp--num no-spin" min="0.01" step="0.01" value="<?= e(number_format((float)$l['balance'], 2, '.', '')) ?>" aria-label="Amount paid"></span>
      <input name="note" class="inp inp--sm" maxlength="200" placeholder="M-Pesa code / note" aria-label="Note">
      <button type="submit" class="btn-primary btn-sm" data-confirm="Record this payment to <?= e($r['name']) ?>?" data-confirm-label="Record payment"><?= admin_icon('check', 14) ?> Paid</button>
    </form>
    <?php endforeach; ?>
    <footer class="posc-card__foot">
      <a href="<?= $self ?>?edit=<?= $sid ?>#form" class="btn-outline btn-sm"><?= admin_icon('edit', 14) ?> Edit</a>
      <form method="POST" action="<?= $self ?>" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $sid ?>">
        <button type="submit" class="btn-icon btn-icon--outline btn-icon--danger" data-confirm="Delete <?= e($r['name']) ?>? A supplier with items or sales is made inactive instead." data-confirm-label="Delete" data-tip="Delete" aria-label="Delete <?= e($r['name']) ?>"><?= admin_icon('trash', 15) ?></button>
      </form>
    </footer>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function () {
  document.querySelectorAll('#poscPeriod input[type=hidden]').forEach(function (i) { i.addEventListener('change', function () { document.getElementById('poscPeriod').submit(); }); });
  var b = document.getElementById('poscAddBtn'), c = document.getElementById('form');
  if (b && c) b.addEventListener('click', function () { var open = c.hidden; c.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); if (open) { var f = c.querySelector('[name=name]'); if (f) f.focus(); } });
})();
</script>

<style>
.posc-period{display:inline-flex;align-items:center;gap:8px;margin:0;font-size:13px}
.posc-period .dp-btn{width:auto;min-width:126px;flex:0 0 auto}
.posc-formcard{margin-bottom:16px}
.posc-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0 14px}
.posc-fields .inp{width:100%}
.posc-formfoot{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:8px}
.posc-formfoot .btn-primary{margin-left:auto}
.posc-due{color:#b45309}
.posc-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
.posc-card{padding:16px 18px;display:flex;flex-direction:column;gap:12px}
.posc-card.is-off{opacity:.65}
.posc-card__head{display:flex;align-items:center;gap:10px}
.posc-av{width:38px;height:38px;border-radius:50%;background:var(--accent);color:#fff;display:inline-grid;place-items:center;font-weight:700;font-size:13px;flex:none}
.posc-card__who{flex:1;min-width:0}
.posc-card__who b{display:block}
.posc-card__who small{display:block;color:var(--muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.posc-mini{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border:1px solid var(--border);border-radius:10px;overflow:hidden}
.posc-mini > div{padding:8px 10px;border-right:1px solid var(--border);min-width:0}
.posc-mini > div:last-child{border-right:0}
.posc-mini span{display:block;font-size:10.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.posc-mini b{font-size:14px;overflow-wrap:anywhere}
.posc-note{margin:0;font-size:12.5px;color:var(--muted)}
.posc-stmt{margin:0;display:grid;grid-template-columns:1fr auto;gap:4px 10px;font-size:12.5px}
.posc-stmt dt{color:var(--muted)}.posc-stmt dd{margin:0;text-align:right}
.posc-pay{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:0;padding:10px;background:#fdf7ec;border-radius:10px}
.posc-pay .inp-money{flex:0 0 140px;display:flex}.posc-pay .inp-money .inp{flex:1;min-width:0}
.posc-pay > .inp{flex:1 1 120px;min-width:0}
.posc-card__foot{display:flex;gap:8px;align-items:center;margin-top:auto}
.posc-card__foot form{margin-left:auto}
@media (max-width:560px){.posc-cards{grid-template-columns:1fr}.posc-period{flex-wrap:wrap}}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
