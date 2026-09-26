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
$pctTxt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') ?: '0';

// Statement period — defaults to this month so far (Nairobi).
$valid  = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : null;
$today  = frontdesk_today_ymd();
$stFrom = $valid($_GET['from'] ?? '') ?? date('Y-m-01', strtotime($today));
$stTo   = $valid($_GET['to'] ?? '') ?? $today;
if ($stTo < $stFrom) [$stFrom, $stTo] = [$stTo, $stFrom];
$statement = $supported ? pos_consignment_statement($stFrom, $stTo) : [];

include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>POS suppliers</h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Admin → Migrations) to set up the POS.</div>
<?php else: ?>
<p class="text-muted" style="margin:-6px 0 18px;font-size:13px;max-width:780px">Consignment suppliers — local makers whose goods we sell and pay out for. <strong>Commission</strong> is the share we keep; the rest is owed to the supplier. An item can instead carry a fixed "cost to us". Each sale remembers the terms it was sold on.</p>

<div class="posc-grid">
  <div class="card">
    <div class="card__head"><span class="card__title"><?= count($rows) ?> supplier<?= count($rows) === 1 ? '' : 's' ?></span></div>
    <?php if (!$rows): ?>
      <?php dt_empty('No suppliers yet — add the first one.'); ?>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Supplier</th><th>Contact</th><th class="posc-num">We keep</th><th class="posc-num">Items</th><th style="width:1%"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= pos_bool($r['is_active']) ? '' : 'posc-off' ?>">
          <td><strong><?= e($r['name']) ?></strong><?php if (!pos_bool($r['is_active'])): ?> <span class="badge badge--grey">Inactive</span><?php endif; ?>
            <?php if (!empty($r['notes'])): ?><div class="text-muted posc-note"><?= e($r['notes']) ?></div><?php endif; ?></td>
          <td class="text-muted"><?= e(implode(' · ', array_filter([(string)$r['phone'], (string)$r['email']]))) ?: '—' ?></td>
          <td class="posc-num"><?= e($pctTxt($r['commission_pct'])) ?>%</td>
          <td class="posc-num"><?= (int)$r['item_count'] ?></td>
          <td class="posc-act">
            <a href="<?= $self ?>?edit=<?= (int)$r['id'] ?>#form" class="btn-icon" data-tip="Edit" aria-label="Edit <?= e($r['name']) ?>"><?= admin_icon('edit', 15) ?></a>
            <form method="POST" action="<?= $self ?>" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Delete <?= e($r['name']) ?>? A supplier with items or sales is made inactive instead." data-tip="Delete" aria-label="Delete <?= e($r['name']) ?>"><?= admin_icon('trash', 15) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card" id="form">
    <div class="card__head"><span class="card__title"><?= $isEdit ? 'Edit ' . e($form['name']) : 'Add a supplier' ?></span>
      <?php if ($isEdit): ?><a href="<?= $self ?>" class="btn-icon" data-tip="Close" aria-label="Close"><?= admin_icon('x', 16) ?></a><?php endif; ?></div>
    <div class="card__body" style="padding:18px 20px">
      <form method="POST" action="<?= $self ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="field"><label>Name</label><input name="name" class="inp<?= isset($errs['name']) ? ' is-invalid' : '' ?>" maxlength="120" value="<?= e((string)$form['name']) ?>" style="width:100%" required>
          <?php if (isset($errs['name'])): ?><div class="field-error"><?= e($errs['name']) ?></div><?php endif; ?></div>
        <div class="posc-two">
          <div class="field"><label>Phone</label><input name="phone" class="inp" maxlength="40" value="<?= e((string)$form['phone']) ?>" style="width:100%"></div>
          <div class="field"><label>Email</label><input name="email" type="email" class="inp<?= isset($errs['email']) ? ' is-invalid' : '' ?>" maxlength="160" value="<?= e((string)$form['email']) ?>" style="width:100%">
            <?php if (isset($errs['email'])): ?><div class="field-error"><?= e($errs['email']) ?></div><?php endif; ?></div>
        </div>
        <div class="field"><label>Commission we keep (%)</label><input name="commission_pct" type="number" class="inp inp--num no-spin<?= isset($errs['commission_pct']) ? ' is-invalid' : '' ?>" min="0" max="100" step="0.01" value="<?= e($pctTxt($form['commission_pct'])) ?>" style="width:140px">
          <?php if (isset($errs['commission_pct'])): ?><div class="field-error"><?= e($errs['commission_pct']) ?></div><?php endif; ?></div>
        <div class="field"><label>Notes <span class="text-muted">(payment terms, M-Pesa number…)</span></label><textarea name="notes" class="inp inp--area" rows="3" style="width:100%"><?= e((string)$form['notes']) ?></textarea></div>
        <label class="togglerow" style="margin-bottom:16px"><span class="toggle"><input type="checkbox" name="is_active" value="1" <?= pos_bool($form['is_active']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Active</span></label>
        <div><button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $isEdit ? 'Save supplier' : 'Add supplier' ?></button></div>
      </form>
    </div>
  </div>
</div>

<div class="card" id="statement" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Statement — what we owe</span>
    <form method="GET" action="<?= $self ?>" class="posc-period" id="poscPeriod">
      <button type="button" class="dp-btn inp" data-dp-target="poscFrom" data-dp-past data-dp-placeholder="From"><?= e(date('j M Y', strtotime($stFrom))) ?></button>
      <input type="hidden" id="poscFrom" name="from" value="<?= e($stFrom) ?>">
      <span class="text-muted">to</span>
      <button type="button" class="dp-btn inp" data-dp-target="poscTo" data-dp-past data-dp-placeholder="To"><?= e(date('j M Y', strtotime($stTo))) ?></button>
      <input type="hidden" id="poscTo" name="to" value="<?= e($stTo) ?>">
    </form></div>
  <?php if (!$statement): ?>
    <?php dt_empty('No consignment sales or payments in this period.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Supplier</th><th class="posc-num">Units</th><th class="posc-num">Sold for</th><th class="posc-num">We keep</th><th class="posc-num">Owed</th><th class="posc-num">Paid</th><th class="posc-num">Balance</th><th>Record a payment</th></tr></thead>
    <tbody>
    <?php foreach ($statement as $st): $c = $st['currency']; ?>
      <tr>
        <td><strong><?= e($st['consignor']) ?></strong></td>
        <td class="posc-num"><?= (int)$st['qty'] ?></td>
        <td class="posc-num"><?= e(pos_money($st['gross'], $c)) ?></td>
        <td class="posc-num text-muted"><?= e(pos_money($st['kept'], $c)) ?></td>
        <td class="posc-num"><?= e(pos_money($st['owed'], $c)) ?></td>
        <td class="posc-num text-muted"><?= e(pos_money($st['paid'], $c)) ?></td>
        <td class="posc-num"><strong class="<?= $st['balance'] > 0 ? 'posc-due' : '' ?>"><?= e(pos_money($st['balance'], $c)) ?></strong></td>
        <td>
          <?php if ($st['balance'] > 0): ?>
          <form method="POST" action="<?= $self ?>" class="posc-pay">
            <?= csrf_field() ?><input type="hidden" name="action" value="payout"><input type="hidden" name="id" value="<?= (int)$st['consignor_id'] ?>">
            <input type="hidden" name="from" value="<?= e($stFrom) ?>"><input type="hidden" name="to" value="<?= e($stTo) ?>"><input type="hidden" name="currency" value="<?= e($c) ?>">
            <span class="inp-money"><span class="inp-money__cur"><?= e($c) ?></span><input name="amount" type="number" class="inp inp--sm inp--num no-spin" min="0.01" step="0.01" value="<?= e(number_format($st['balance'], 2, '.', '')) ?>" style="width:100px" aria-label="Amount paid"></span>
            <input name="note" class="inp inp--sm" maxlength="200" placeholder="M-Pesa code / note" style="width:150px" aria-label="Note">
            <button type="submit" class="btn-outline btn-sm" data-confirm="Record this payment to <?= e($st['consignor']) ?>?"><?= admin_icon('check', 14) ?> Paid</button>
          </form>
          <?php else: ?><span class="badge badge--green">Settled</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="text-muted" style="font-size:12px;margin:0;padding:10px 16px">Owed is worked out from the terms each item was sold on. A payment counts toward every period it overlaps, so record it against the period you are settling.</p>
  <?php endif; ?>
</div>
<script>
document.querySelectorAll('#poscPeriod input[type=hidden]').forEach(function (i) { i.addEventListener('change', function () { document.getElementById('poscPeriod').submit(); }); });
</script>

<style>
.posc-period{display:flex;align-items:center;gap:8px;margin:0;font-size:13px}
.posc-period .dp-btn{width:auto;min-width:130px;flex:0 0 auto}
.posc-pay{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:0}
.posc-due{color:var(--red)}
.posc-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);gap:18px;align-items:start}
@media (max-width:900px){.posc-grid{grid-template-columns:1fr}}
.posc-grid .table-wrap .data-table{min-width:0}
.posc-num{text-align:right;white-space:nowrap}
.posc-act{display:flex;gap:4px;justify-content:flex-end}
.posc-off td{opacity:.6}
.posc-note{font-size:12px;margin-top:2px}
.posc-two{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
