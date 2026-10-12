<?php
/**
 * Admin: POS stock — receive deliveries, correct counts, read the ledger.
 * Owner, or a manager for their property's outlets (pos_manageable_outlet_ids()).
 *
 * Stock is the outlet's shelf in the shared inventory: every change is an
 * inv_moves row written by inv_move() (via pos_stock_move()). Nothing here edits
 * a quantity directly. A count correction records the difference as a
 * found/missing move with a required reason.
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
    $back = $self . '?outlet=' . $oid . ($iid ? '&open=stock-' . $iid : '');   // reopens the item's side drawer
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
            // Whose goods these are, and on what terms — decided per delivery.
            $terms = null;
            if (pos_v2_supported() && isset($_POST['terms_mode'])) {
                $terms = pos_consign_terms(['mode' => (string)$_POST['terms_mode'], 'consignor_id' => $_POST['consignor_id'] ?? 0,
                    'value' => (string)$_POST['terms_mode'] === 'fixed' ? ($_POST['terms_fixed'] ?? '') : ($_POST['terms_pct'] ?? '')],
                    array_map(fn($c) => (int)$c['id'], pos_fetch_consignors(true)));
                if (is_string($terms)) throw new PosRefusal($terms);
            }
            $n = pos_stock_receive($iid, (int)$qty, $cost === '' ? null : round((float)$cost, 2), $note, (int)$me['id'], $terms);
            audit_log('pos.stock_receive', 'pos_item', $iid, "+{$qty} → {$n}" . ($terms && $terms['consignor_id'] ? ' (consignment)' : ''));
            $_SESSION['poss_flash'] = ['type' => 'success', 'msg' => "Received {$qty} × {$item['name']} — {$n} on hand."];
        } elseif ($act === 'count') {
            $counted = (string)($_POST['counted'] ?? '');
            if (!ctype_digit($counted)) throw new PosRefusal('Enter the counted quantity as a whole number.');
            $before = (int)$item['stock_on_hand'];
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
$isLow   = fn(array $i) => (int)$i['stock_on_hand'] <= 0 || ($i['low_stock_at'] !== null && (int)$i['stock_on_hand'] <= (int)$i['low_stock_at']);
$low     = array_values(array_filter($items, $isLow));
$pickId  = (int)($_GET['item'] ?? 0);
$picked  = null;
foreach ($items as $i) if ((int)$i['id'] === $pickId) { $picked = $i; break; }
$moves   = $picked ? pos_stock_moves((int)$picked['id'], 100) : [];
$consignors = $supported ? pos_fetch_consignors(true) : [];
$v2 = $supported && pos_v2_supported();
// Current terms per item, so the form pre-selects them when an item is picked.
$termsOf = [];
foreach ($items as $i) {
    $termsOf[(int)$i['id']] = [
        'mode' => empty($i['consignor_id']) ? 'own' : ($i['consignor_cost'] !== null ? 'fixed' : 'commission'),
        'consignor_id' => (int)($i['consignor_id'] ?? 0),
        'pct' => $i['consign_pct'] ?? ($i['consignor_commission_pct'] ?? null),
        'fixed' => $i['consignor_cost'],
    ];
}
$defaultPct = []; foreach ($consignors as $c) $defaultPct[(int)$c['id']] = (float)$c['commission_pct'];
$cur     = $outlet ? strtoupper((string)$outlet['currency']) : 'USD';
$REASONS = ['receive' => ['Received', 'badge--green'], 'opening' => ['Opening', 'badge--green'], 'sale' => ['Sale', 'badge--blue'],
            'void' => ['Void', 'badge--orange'], 'adjust' => ['Count', 'badge--purple'], 'found' => ['Count +', 'badge--purple'],
            'missing' => ['Count −', 'badge--purple'], 'return' => ['Return', 'badge--teal'], 'transfer' => ['Transfer', 'badge--teal'],
            'assign' => ['Assigned', 'badge--teal'], 'replaced' => ['Replacement', 'badge--teal'], 'broken' => ['Broken', 'badge--red'],
            'stolen' => ['Stolen', 'badge--red'], 'written_off' => ['Written off', 'badge--red']];
$isDrawer = !empty($_GET['drawer']);
$mode     = ($_GET['mode'] ?? '') === 'count' ? 'count' : 'receive';
$link     = fn(string $extra) => $self . '?outlet=' . $oid . $extra;

/**
 * Receive / count form for one item (or with an item picker) + its history — the
 * side-drawer fragment, or a card on the page without JavaScript.
 */
$stockPanel = function (?array $picked, bool $inDrawer) use ($items, $moves, $consignors, $v2, $cur, $REASONS, $mode, $oid, $self, $link): void {
    $q = $picked ? (int)$picked['stock_on_hand'] : 0;
    ?>
    <div class="adrawer__head">
      <h2><?= $picked ? e($picked['name']) : 'Receive or count' ?></h2>
      <p><?= $picked ? 'On hand: <strong>' . $q . '</strong>' . ($picked['low_stock_at'] !== null ? ' · alert at ' . (int)$picked['low_stock_at'] : '') : 'Pick the item, then what happened.' ?></p>
      <?php if ($picked): ?><div class="badges">
        <?php if ($q <= 0): ?><span class="badge badge--red">Out</span><?php elseif ($picked['low_stock_at'] !== null && $q <= (int)$picked['low_stock_at']): ?><span class="badge badge--orange">Low</span><?php endif; ?>
        <?php if (!empty($picked['consignor_id'])): ?><span class="badge badge--purple">Consignment</span><?php endif; ?>
        <?php if (!pos_bool($picked['is_active'])): ?><span class="badge badge--grey">Hidden</span><?php endif; ?>
      </div><?php endif; ?>
    </div>
    <div class="adrawer__body">
      <form method="POST" action="<?= $self ?>" class="poss-form" id="possForm" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <?php if ($picked): ?><input type="hidden" name="item_id" value="<?= (int)$picked['id'] ?>">
        <?php else: ?>
        <div class="field"><label>Item</label>
          <select name="item_id" class="eselect eselect--block" required>
            <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['name']) ?> — <?= (int)$i['stock_on_hand'] ?> on hand</option><?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <div class="field"><label>What happened</label>
          <div class="poss-chips">
            <label class="optchip"><input type="radio" name="action" value="receive"<?= $mode === 'receive' ? ' checked' : '' ?>>Delivery arrived</label>
            <label class="optchip"><input type="radio" name="action" value="count"<?= $mode === 'count' ? ' checked' : '' ?>>Shelf count</label>
          </div></div>
        <div class="poss-row" data-when="receive">
          <div class="field"><label>Quantity received</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" placeholder="0"<?= $mode === 'receive' ? ' autofocus' : '' ?>></div>
          <div class="field"><label>Unit cost <span class="text-muted">(optional)</span></label>
            <span class="inp-money"><span class="inp-money__cur"><?= e($cur) ?></span><input name="unit_cost" type="number" class="inp inp--num no-spin" min="0" step="0.01" placeholder="—"></span></div>
        </div>
        <div class="poss-row" data-when="count" hidden>
          <div class="field"><label>Counted on the shelf</label><input name="counted" type="number" class="inp inp--num no-spin" min="0" step="1" placeholder="0"<?= $mode === 'count' ? ' autofocus' : '' ?>></div>
        </div>
        <?php if ($v2): ?>
        <div data-when="receive">
          <div class="field"><label>Whose stock is this?</label>
            <div class="poss-chips" data-poss-terms>
              <label class="optchip"><input type="radio" name="terms_mode" value="own" checked>Ours</label>
              <?php if ($consignors): ?>
              <label class="optchip"><input type="radio" name="terms_mode" value="commission">Consignment — we keep a %</label>
              <label class="optchip"><input type="radio" name="terms_mode" value="fixed">Consignment — fixed per item</label>
              <?php endif; ?>
            </div>
            <?php if (!$consignors): ?><div class="text-muted" style="font-size:12px;margin-top:6px">Add suppliers under Catalogue & stock → Suppliers to receive consignment stock.</div><?php endif; ?>
          </div>
          <div class="poss-cons" data-poss-cons hidden>
            <div class="field"><label>Supplier</label>
              <select name="consignor_id" class="eselect eselect--block" data-poss-consignor>
                <?php foreach ($consignors as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select></div>
            <div class="field" data-terms="commission"><label>We keep (%)</label>
              <input name="terms_pct" data-poss-pct type="number" class="inp inp--num no-spin" min="0" max="100" step="0.01" style="width:100%"></div>
            <div class="field" data-terms="fixed" hidden><label>We owe per item</label>
              <span class="inp-money"><span class="inp-money__cur"><?= e($cur) ?></span><input name="terms_fixed" data-poss-fixed type="number" class="inp inp--num no-spin" min="0" step="0.01"></span></div>
            <div class="text-muted" style="font-size:12px;margin:-6px 0 6px">Applies to this item from now on. Earlier sales keep their own terms.</div>
          </div>
        </div>
        <?php endif; ?>
        <div class="field"><label>Note <span class="text-muted" data-when="receive">(optional — supplier, invoice #)</span><span class="text-muted" data-when="count" hidden>(required — e.g. stock take, damaged)</span></label>
          <input name="note" class="inp" maxlength="500" style="width:100%"></div>
      </form>

      <?php if ($picked): ?>
      <div class="adrawer__sec">
        <h3>History</h3>
        <?php if (!$moves): ?><p class="text-muted" style="margin:0;font-size:13px">No stock movements yet.</p>
        <?php else: ?>
        <div class="poss-hist">
          <?php foreach ($moves as $m): [$lbl, $cls] = $REASONS[$m['reason']] ?? [$m['reason'], 'badge--grey']; $dq = (int)$m['qty_delta']; ?>
          <div>
            <span class="text-muted"><?= e(date('j M, H:i', strtotime((string)$m['created_at']))) ?></span>
            <span><span class="badge <?= e($cls) ?>"><?= e($lbl) ?></span> <?= e((string)($m['note'] ?: ($m['sale_reference'] ?? ''))) ?><?php if (!empty($m['consignor_id'])): ?> <span class="badge badge--purple"><?= e($m['consignor_name'] ?? 'Consignment') ?> · <?= $m['consignor_cost'] !== null ? e(pos_money((float)$m['consignor_cost'], $cur)) . '/item' : e(rtrim(rtrim((string)$m['consign_pct'], '0'), '.')) . '% ours' ?></span><?php endif; ?><?= $m['unit_cost'] !== null ? ' <span class="text-muted">@ ' . e(pos_money((float)$m['unit_cost'], $cur)) . '</span>' : '' ?><?= !empty($m['user_name']) ? ' <span class="text-muted">· ' . e($m['user_name']) . '</span>' : '' ?></span>
            <b class="<?= $dq < 0 ? 'poss-neg' : 'poss-pos' ?>"><?= $dq > 0 ? '+' : '' ?><?= $dq ?></b>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="adrawer__foot">
      <?php if ($inDrawer): ?><button type="button" class="btn-outline btn-sm" data-adrawer-close>Close</button>
      <?php else: ?><a href="<?= e($link('')) ?>" class="btn-outline btn-sm">Close</a><?php endif; ?>
      <button type="submit" form="possForm" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Record</button>
    </div>
    <?php
};

if ($isDrawer) {
    if (!$outlet || !$items || ($pickId && !$picked)) { echo '<div class="adrawer__head"><h2>Not available</h2><p>Pick a stock-tracked item of this outlet.</p></div>'; exit; }
    $stockPanel($picked, true);
    exit;
}
$totalUnits = array_sum(array_map(fn($i) => max(0, (int)$i['stock_on_hand']), $items));
$nOut = count(array_filter($items, fn($i) => (int)$i['stock_on_hand'] <= 0));

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Stock</h1>
  <?php if ($outlet): ?>
  <div class="actions">
    <?php if (count($outlets) > 1): ?>
    <form method="GET" action="<?= $self ?>" style="display:inline-flex">
      <select name="outlet" class="eselect" onchange="this.form.submit()" aria-label="Outlet">
        <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $oid ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <?php if ($items): ?><a href="<?= e($link('&receive=1')) ?>" class="btn-primary btn-sm" data-drawer="<?= e($link('&receive=1')) ?>" data-drawer-key="stock-new"><?= admin_icon('plus', 15) ?> Receive delivery</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Settings → Your account → Migrations) to set up the POS.</div>
<?php elseif (!$outlets): ?>
  <?php dt_empty('You do not manage any POS outlets yet.'); ?>
<?php elseif (!$items): ?>
  <?php dt_empty('Nothing at this outlet tracks stock. Turn on "Track stock" for a product in the Catalogue.'); ?>
<?php else: ?>

<?php if (empty($_GET['open']) && ($picked || !empty($_GET['receive']))): ?>
<div class="card poss-panel" data-inline-form data-close-href="<?= e($link('')) ?>"><?php $stockPanel($picked, false); ?></div>
<?php endif; ?>

<div class="pos-kpis">
  <div class="pos-kpi pos-kpi--dark"><span>Items tracked</span><b><?= count($items) ?></b><small>at <?= e($outlet['name']) ?></small></div>
  <div class="pos-kpi"><span>Units on hand</span><b><?= $totalUnits ?></b><small>across all tracked items</small></div>
  <div class="pos-kpi<?= count($low) - $nOut > 0 ? ' pos-kpi--warn' : '' ?>"><span>Low</span><b><?= count($low) - $nOut ?></b><small>at or under the alert</small></div>
  <div class="pos-kpi<?= $nOut ? ' pos-kpi--bad' : '' ?>"><span>Out</span><b><?= $nOut ?></b><small>the till won’t sell these</small></div>
</div>

<div class="pos-toolbar">
  <div class="pos-chips" id="possChips">
    <button type="button" class="pos-chip is-on" data-poss-filter="all">All<b><?= count($items) ?></b></button>
    <?php if (count($low) - $nOut > 0): ?><button type="button" class="pos-chip" data-poss-filter="low">Low<b><?= count($low) - $nOut ?></b></button><?php endif; ?>
    <?php if ($nOut): ?><button type="button" class="pos-chip" data-poss-filter="out">Out<b><?= $nOut ?></b></button><?php endif; ?>
  </div>
  <span class="sp"></span>
  <span class="dt-search" style="width:260px;max-width:100%"><span class="dt-search__icon" aria-hidden="true"><?= admin_icon('search', 16) ?></span><input type="search" id="possSearch" class="dt-search__input" placeholder="Search items…" autocomplete="off" aria-label="Search items"></span>
</div>

<div class="card">
  <div class="table-wrap"><table class="data-table poss-table" id="possTable" data-paginate="25">
    <thead><tr><th>Item</th><th class="poss-num">On hand</th><th class="poss-num">Alert at</th><th>Whose</th><th style="width:1%"></th></tr></thead>
    <tbody id="possRows">
    <?php foreach ($items as $i): $q = (int)$i['stock_on_hand']; $out = $q <= 0; $isL = !$out && $isLow($i); $dUrl = $link('&item=' . (int)$i['id']); ?>
      <tr data-drawer="<?= e($dUrl) ?>" data-drawer-key="stock-<?= (int)$i['id'] ?>" data-tags="<?= $out ? 'out' : ($isL ? 'low' : '') ?>" data-find="<?= e(mb_strtolower((string)$i['name'] . ' ' . ($i['sku'] ?? ''))) ?>">
        <td><div class="pos-who">
          <?php if (!empty($i['image_key'])): ?><img src="<?= e(storage_url((string)$i['image_key'])) ?>" alt="" class="pos-thumb" loading="lazy"><?php else: ?><span class="pos-thumb"><?= admin_icon('image', 14) ?></span><?php endif; ?>
          <span><a href="<?= e($dUrl) ?>" data-drawer="<?= e($dUrl) ?>" data-drawer-key="stock-<?= (int)$i['id'] ?>" style="color:inherit;text-decoration:none"><b><?= e($i['name']) ?></b></a>
            <?php if (!pos_bool($i['is_active'])): ?><small>Hidden from the till</small><?php elseif (!empty($i['sku'])): ?><small>SKU <?= e($i['sku']) ?></small><?php endif; ?></span></div></td>
        <td class="poss-num"><?php if ($out): ?><span class="badge badge--red"><?= $q ?> · out</span><?php elseif ($isL): ?><span class="badge badge--orange"><?= $q ?> · low</span><?php else: ?><span class="badge badge--green"><?= $q ?></span><?php endif; ?></td>
        <td class="poss-num text-muted"><?= $i['low_stock_at'] === null ? '—' : (int)$i['low_stock_at'] ?></td>
        <td><?= !empty($i['consignor_id']) ? '<span class="badge badge--purple">Consignment</span>' : '<span class="badge badge--grey">Ours</span>' ?></td>
        <td style="white-space:nowrap;text-align:right">
          <a href="<?= e($dUrl) ?>" class="btn-outline btn-sm" data-drawer="<?= e($dUrl) ?>" data-drawer-key="stock-<?= (int)$i['id'] ?>">Receive</a>
          <a href="<?= e($dUrl . '&mode=count') ?>" class="btn-outline btn-sm" data-drawer="<?= e($dUrl . '&mode=count') ?>" data-drawer-key="stock-<?= (int)$i['id'] ?>">Count</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="text-muted" id="possNone" style="padding:28px;text-align:center;margin:0" hidden>No items match.</p>
</div>
<?php endif; ?>

<style>
.card__head{flex-wrap:wrap;gap:8px 12px}
.poss-panel{margin-bottom:16px;display:flex;flex-direction:column;max-width:640px}
.poss-num{text-align:right;white-space:nowrap}
.poss-form{display:flex;flex-direction:column;gap:2px}
.poss-chips{display:flex;flex-wrap:wrap;gap:8px}
.poss-row{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
.poss-row .inp{width:100%}
@media (max-width:560px){.poss-row{grid-template-columns:1fr}}
.field .inp-money{display:flex;width:100%}.field .inp-money .inp{flex:1;min-width:0;width:auto}
.poss-row[hidden],[data-when][hidden],.poss-cons[hidden],[data-terms][hidden]{display:none}
.poss-neg{color:var(--red)}
.poss-pos{color:var(--green)}
.poss-hist{display:flex;flex-direction:column}
.poss-hist > div{display:grid;grid-template-columns:86px minmax(0,1fr) auto;gap:10px;padding:8px 0;border-bottom:1px dashed var(--border);font-size:13px;align-items:baseline}
.poss-hist > div:last-child{border-bottom:0}
</style>
<script>
(function(){
  var TERMS = <?= json_encode($termsOf) ?>, DEF = <?= json_encode($defaultPct) ?>;
  // The receive / count form — on the page and in the side drawer.
  function formInit(root){
    var form = root.querySelector('.poss-form'); if (!form || form.dataset.ready) return; form.dataset.ready = '1';
    function sync(){ var m = form.querySelector('input[name=action]:checked').value;
      root.querySelectorAll('[data-when]').forEach(function(el){ el.hidden = el.getAttribute('data-when') !== m; }); }
    form.querySelectorAll('input[name=action]').forEach(function(r){ r.addEventListener('change', sync); });
    sync();
    var box = form.querySelector('[data-poss-cons]'); if (!box) return;
    var sel = form.querySelector('[name=item_id]'), cons = form.querySelector('[data-poss-consignor]');
    var pct = form.querySelector('[data-poss-pct]'), fixed = form.querySelector('[data-poss-fixed]');
    function mode(){ var m = form.querySelector('input[name=terms_mode]:checked'); return m ? m.value : 'own'; }
    function show(){ var m = mode(); box.hidden = m === 'own';
      box.querySelectorAll('[data-terms]').forEach(function(el){ el.hidden = el.getAttribute('data-terms') !== m; }); }
    function load(){ var t = TERMS[sel.value]; if (!t) return;
      var r = form.querySelector('input[name=terms_mode][value="' + t.mode + '"]'); if (r) r.checked = true;
      if (t.consignor_id && cons) { cons.value = t.consignor_id; cons.dispatchEvent(new Event('change')); }
      pct.value = t.pct !== null && t.pct !== undefined ? t.pct : (DEF[cons && cons.value] !== undefined ? DEF[cons.value] : '');
      fixed.value = t.fixed !== null && t.fixed !== undefined ? t.fixed : '';
      show(); }
    form.querySelectorAll('input[name=terms_mode]').forEach(function(r){ r.addEventListener('change', function(){
      if (mode() === 'commission' && pct.value === '' && cons) pct.value = DEF[cons.value] !== undefined ? DEF[cons.value] : '';
      show(); }); });
    if (cons) cons.addEventListener('change', function(){ if (mode() === 'commission' && pct.value === '') pct.value = DEF[cons.value] !== undefined ? DEF[cons.value] : ''; });
    if (sel) { sel.addEventListener('change', load); load(); }
  }
  formInit(document);
  window.__possFormInit = formInit;
  if (!window.__possDrawerBound) {
    window.__possDrawerBound = true;
    document.addEventListener('adrawer:loaded', function (e) { if (e.detail && e.detail.panel && window.__possFormInit) window.__possFormInit(e.detail.panel); });
  }

  // Filter chips + search over the table (the pager pages what is left).
  var rows = [].slice.call(document.querySelectorAll('#possRows tr')), chips = document.getElementById('possChips'),
      search = document.getElementById('possSearch'), none = document.getElementById('possNone'), want = 'all';
  function apply(){
    var q = (search && search.value || '').trim().toLowerCase(), shown = 0;
    rows.forEach(function(r){ var ok = (want === 'all' || r.dataset.tags === want) && (!q || r.dataset.find.indexOf(q) >= 0); r.hidden = !ok; if (ok) shown++; });
    if (none) none.hidden = shown > 0;
    if (window.tsTableFit) window.tsTableFit();
  }
  if (chips) chips.addEventListener('click', function(e){ var b = e.target.closest('[data-poss-filter]'); if (!b) return;
    want = b.dataset.possFilter; chips.querySelectorAll('.pos-chip').forEach(function(c){ c.classList.toggle('is-on', c === b); }); apply(); });
  if (search) search.addEventListener('input', apply);
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
