<?php
/**
 * Admin: POS stock — receive deliveries, correct counts, read the ledger.
 * Owner, or a manager for their property's outlets (pos_manageable_outlet_ids()).
 *
 * Stock is a LEDGER: every change is a pos_stock_moves row and pos_items.stock_qty
 * is its cached total, written together under a row lock (pos_stock_move()).
 * Nothing here edits stock_qty directly. A count correction records the
 * difference as an "adjust" move with a required reason.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pos.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();
require_manager();

$pageTitle  = 'POS stock';
$activeMenu = 'pos_stock';
$supported  = pos_supported();
$self       = '/admin/pos-stock.php';
$me         = current_admin();

$allowed = $supported ? pos_manageable_outlet_ids($me) : [];
$outlets = $supported ? pos_fetch_outlets($allowed, false) : [];
$oid     = (int)($_POST['outlet_id'] ?? $_GET['outlet'] ?? 0);
if (!in_array($oid, $allowed, true)) $oid = $allowed[0] ?? 0;
$outlet  = $oid ? pos_fetch_outlet($oid) : false;

$flash = $_SESSION['poss_flash'] ?? null; unset($_SESSION['poss_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported && $outlet) {
    verify_csrf();
    $act  = (string)($_POST['action'] ?? '');
    $iid  = (int)($_POST['item_id'] ?? 0);
    $item = pos_fetch_item($iid);
    $back = $self . '?outlet=' . $oid . ($iid ? '&item=' . $iid : '');
    // The item must be a stock-tracked item of THIS outlet.
    if (!$item || (int)$item['outlet_id'] !== $oid || !pos_bool($item['track_stock'])) {
        $_SESSION['poss_flash'] = ['type' => 'error', 'msg' => 'Pick a stock-tracked item of this outlet.'];
        header('Location: ' . $self . '?outlet=' . $oid); exit;
    }
    try {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($act === 'receive') {
            $qty  = (string)($_POST['qty'] ?? '');
            $cost = trim((string)($_POST['unit_cost'] ?? ''));
            if (!ctype_digit($qty)) throw new PosRefusal('Enter how many arrived as a whole number.');
            if ($cost !== '' && !is_numeric($cost)) throw new PosRefusal('Unit cost must be a number.');
            $n = pos_stock_receive($iid, (int)$qty, $cost === '' ? null : round((float)$cost, 2), $note, (int)$me['id']);
            audit_log('pos.stock_receive', 'pos_item', $iid, "+{$qty} → {$n}");
            $_SESSION['poss_flash'] = ['type' => 'success', 'msg' => "Received {$qty} × {$item['name']} — {$n} on hand."];
        } elseif ($act === 'count') {
            $counted = (string)($_POST['counted'] ?? '');
            if (!ctype_digit($counted)) throw new PosRefusal('Enter the counted quantity as a whole number.');
            $before = (int)$item['stock_qty'];
            $n = pos_stock_adjust($iid, (int)$counted, $note, (int)$me['id']);
            audit_log('pos.stock_adjust', 'pos_item', $iid, "{$before} → {$n}: {$note}");
            $_SESSION['poss_flash'] = ['type' => 'success', 'msg' => $n === $before
                ? "{$item['name']} count matches — nothing changed."
                : "{$item['name']} corrected from {$before} to {$n}."];
        }
    } catch (PosRefusal $e) {
        $_SESSION['poss_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $back); exit;
}

$items   = $outlet ? array_values(array_filter(pos_fetch_items($oid, false, false), fn($i) => pos_bool($i['track_stock']))) : [];
$isLow   = fn(array $i) => (int)$i['stock_qty'] <= 0 || ($i['low_stock_at'] !== null && (int)$i['stock_qty'] <= (int)$i['low_stock_at']);
$low     = array_values(array_filter($items, $isLow));
$pickId  = (int)($_GET['item'] ?? 0);
$picked  = null;
foreach ($items as $i) if ((int)$i['id'] === $pickId) { $picked = $i; break; }
$moves   = $picked ? pos_stock_moves((int)$picked['id'], 100) : [];
$cur     = $outlet ? strtoupper((string)$outlet['currency']) : 'USD';
$REASONS = ['receive' => ['Received', 'badge--green'], 'sale' => ['Sale', 'badge--blue'], 'void' => ['Void', 'badge--orange'],
            'adjust' => ['Count', 'badge--purple'], 'return' => ['Return', 'badge--teal']];

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>POS stock</h1>
  <?php if ($outlet): ?><a href="/admin/pos-items.php?outlet=<?= $oid ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Catalogue</a><?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Admin → Migrations) to set up the POS.</div>
<?php elseif (!$outlets): ?>
  <?php dt_empty('You do not manage any POS outlets yet.'); ?>
<?php else: ?>

<form method="GET" action="<?= $self ?>" class="poss-pick">
  <span class="text-muted">Outlet</span>
  <select name="outlet" class="eselect" onchange="this.form.submit()" aria-label="Outlet">
    <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $oid ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
  </select>
  <?php if ($low): ?><span class="badge badge--orange"><?= count($low) ?> low or out</span><?php endif; ?>
</form>

<?php if (!$items): ?>
  <?php dt_empty('Nothing at this outlet tracks stock. Turn on "Track stock" for a product in the catalogue.'); ?>
<?php else: ?>
<div class="poss-grid">
  <div class="card">
    <div class="card__head"><span class="card__title">On hand</span><span class="text-muted" style="font-size:12.5px">Tap an item for its history</span></div>
    <div class="table-wrap"><table class="data-table poss-table">
      <thead><tr><th>Item</th><th class="poss-num">On hand</th><th class="poss-num">Alert at</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): $q = (int)$i['stock_qty']; ?>
        <tr class="<?= (int)$i['id'] === $pickId ? 'is-picked' : '' ?>">
          <td><a href="<?= $self ?>?outlet=<?= $oid ?>&item=<?= (int)$i['id'] ?>#ledger" class="poss-link"><?= e($i['name']) ?></a>
            <?php if (!empty($i['consignor_id'])): ?><span class="badge badge--purple">Consignment</span><?php endif; ?>
            <?php if (!pos_bool($i['is_active'])): ?><span class="badge badge--grey">Hidden</span><?php endif; ?></td>
          <td class="poss-num"><strong><?= $q ?></strong></td>
          <td class="poss-num text-muted"><?= $i['low_stock_at'] === null ? '—' : (int)$i['low_stock_at'] ?></td>
          <td><?php if ($q <= 0): ?><span class="badge badge--red">Out</span><?php elseif ($isLow($i)): ?><span class="badge badge--orange">Low</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="card" id="form">
    <div class="card__head"><span class="card__title">Receive or count</span></div>
    <div class="card__body" style="padding:18px 20px">
      <form method="POST" action="<?= $self ?>" class="poss-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <div class="field"><label>Item</label>
          <select name="item_id" class="eselect eselect--block" required>
            <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" <?= (int)$i['id'] === $pickId ? 'selected' : '' ?>><?= e($i['name']) ?> — <?= (int)$i['stock_qty'] ?> on hand</option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>What happened</label>
          <div class="poss-chips">
            <label class="optchip"><input type="radio" name="action" value="receive" checked data-mode="receive">Delivery arrived</label>
            <label class="optchip"><input type="radio" name="action" value="count" data-mode="count">Shelf count</label>
          </div></div>
        <div class="poss-row" data-when="receive">
          <div class="field"><label>Quantity received</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" placeholder="0"></div>
          <div class="field"><label>Unit cost <span class="text-muted">(optional)</span></label>
            <span class="inp-money"><span class="inp-money__cur"><?= e($cur) ?></span><input name="unit_cost" type="number" class="inp inp--num no-spin" min="0" step="0.01" placeholder="—"></span></div>
        </div>
        <div class="poss-row" data-when="count" hidden>
          <div class="field"><label>Counted on the shelf</label><input name="counted" type="number" class="inp inp--num no-spin" min="0" step="1" placeholder="0"></div>
        </div>
        <div class="field"><label>Note <span class="text-muted" data-when="receive">(optional — supplier, invoice #)</span><span class="text-muted" data-when="count" hidden>(required — e.g. stock take, damaged)</span></label>
          <input name="note" class="inp" maxlength="500" style="width:100%"></div>
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Record</button>
      </form>
    </div>
  </div>
</div>

<?php if ($picked): ?>
<div class="card" id="ledger" style="margin-top:18px">
  <div class="card__head"><span class="card__title"><?= e($picked['name']) ?> — history</span><span class="text-muted" style="font-size:12.5px"><?= (int)$picked['stock_qty'] ?> on hand</span></div>
  <?php if (!$moves): ?>
    <?php dt_empty('No stock movements yet.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>When</th><th>What</th><th class="poss-num">Change</th><th>Detail</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($moves as $m): [$lbl, $cls] = $REASONS[$m['reason']] ?? [$m['reason'], 'badge--grey']; $dq = (int)$m['qty_delta']; ?>
      <tr>
        <td class="text-muted"><?= e(date('j M Y, H:i', strtotime((string)$m['created_at']))) ?></td>
        <td><span class="badge <?= e($cls) ?>"><?= e($lbl) ?></span></td>
        <td class="poss-num"><strong class="<?= $dq < 0 ? 'poss-neg' : 'poss-pos' ?>"><?= $dq > 0 ? '+' : '' ?><?= $dq ?></strong></td>
        <td><?= e((string)($m['note'] ?: ($m['sale_reference'] ?? ''))) ?><?= $m['unit_cost'] !== null ? ' <span class="text-muted">@ ' . e(pos_money((float)$m['unit_cost'], $cur)) . '</span>' : '' ?></td>
        <td class="text-muted"><?= e($m['user_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.poss-pick{display:flex;align-items:center;gap:10px;margin:-6px 0 18px;flex-wrap:wrap;font-size:13px}
.poss-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,1fr);gap:18px;align-items:start}
@media (max-width:900px){.poss-grid{grid-template-columns:1fr}}
.poss-grid .table-wrap .data-table{min-width:0}
.poss-num{text-align:right;white-space:nowrap}
.poss-table tr.is-picked td{background:rgba(30,92,107,.06)}
.poss-link{font-weight:500}
.poss-chips{display:flex;flex-wrap:wrap;gap:8px}
.poss-row{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
.poss-row .inp{width:100%}
.field .inp-money{display:flex;width:100%}.field .inp-money .inp{flex:1;min-width:0;width:auto}
.poss-row[hidden],[data-when][hidden]{display:none}
.poss-neg{color:var(--red)}
.poss-pos{color:var(--green)}
</style>
<script>
(function(){
  var form = document.querySelector('.poss-form'); if (!form) return;
  function sync(){ var m = form.querySelector('input[name=action]:checked').value;
    form.querySelectorAll('[data-when]').forEach(function(el){ el.hidden = el.getAttribute('data-when') !== m; }); }
  form.querySelectorAll('input[name=action]').forEach(function(r){ r.addEventListener('change', sync); });
  sync();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
