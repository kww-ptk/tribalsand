<?php
/**
 * Stock count — the phone/tablet check. The person responsible for a place, or
 * anyone who works at its property, counts it: one card per item with the Expected
 * number and a big number box; a card turns red when the count differs. Submitting
 * saves the numbers — stock does NOT change; a manager resolves gaps on
 * Inventory → Counts. No params: the places you can count. ?location=ID: start
 * (or continue) that place's count. ?count=ID: the count itself.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_login();

$self      = '/admin/inventory-count.php';
$me        = current_admin();
$meId      = (int)$me['id'];
$role      = (string)($me['role'] ?? 'staff');
$vids      = admin_venue_ids();
$supported = inv_supported();
$today     = frontdesk_today_ymd();
$flash     = $_SESSION['invc_flash'] ?? null; unset($_SESSION['invc_flash']);

function invc_go(string $url): never { header('Location: ' . $url); exit; }
$countable = fn(array $sheetOrLoc): bool => inv_can_count(['kind' => $sheetOrLoc['kind'], 'venue_id' => $sheetOrLoc['venue_id'],
                                                           'count_assignee_id' => $sheetOrLoc['count_assignee_id']], $meId, $role, $vids);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = $self;
    try {
        if ($act === 'start') {
            $loc = inv_fetch_location((int)($_POST['location_id'] ?? 0));
            if (!$loc || !$countable($loc)) throw new InvRefusal('You can’t count that place.');
            $back = $self . '?location=' . (int)$loc['id'];
            invc_go($self . '?count=' . inv_count_start((int)$loc['id'], $meId));
        }
        if ($act === 'submit') {
            $sheet = inv_count_sheet((int)($_POST['count_id'] ?? 0));
            if (!$sheet || !$countable($sheet)) throw new InvRefusal('You can’t count that place.');
            $back    = $self . '?count=' . (int)$sheet['id'];
            $counted = is_array($_POST['counted'] ?? null) ? array_map(fn($v) => trim((string)$v), $_POST['counted']) : [];
            $res     = inv_count_submit((int)$sheet['id'], $counted, $meId);
            audit_log('inv.count_submit', 'inv_location', (int)$sheet['location_id'], "count #{$sheet['id']}: {$res['gaps']} gap(s)");
            $_SESSION['invc_flash'] = ['type' => 'success', 'msg' => $res['gaps']
                ? "Thanks — {$res['gaps']} item" . ($res['gaps'] === 1 ? '' : 's') . ' didn’t match. A manager will check.'
                : 'Thanks — everything matched.'];
            invc_go($back);
        }
    } catch (InvRefusal $e) {
        $_SESSION['invc_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    invc_go($back);
}

$sheet = $supported && isset($_GET['count']) ? inv_count_sheet((int)$_GET['count']) : null;
if ($sheet && !$countable($sheet)) $sheet = null;
$loc = (!$sheet && $supported && isset($_GET['location'])) ? inv_fetch_location((int)$_GET['location']) : false;
if ($loc && (!$countable($loc) || !inv_bool($loc['is_active']))) $loc = false;
$places = (!$sheet && !$loc && $supported) ? inv_countable_locations($meId, $role, $vids, $today) : [];
$notFound = (isset($_GET['count']) && !$sheet) || (isset($_GET['location']) && !$loc);
$locState = $loc ? inv_count_location_state((int)$loc['id']) : null;
// An open count started on an earlier day holds a stale snapshot: inv_count_submit()
// refuses it, so it is never shown as cards — only the way to start again.
$stale = $sheet && $sheet['status'] === 'open' && date('Y-m-d', strtotime((string)$sheet['started_at'])) !== $today;
$draftKey = $sheet ? 'invc-draft-' . (int)$sheet['id'] : '';

$pageTitle  = 'Stock count';
$activeMenu = 'inventory_count';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $sheet ? e(inv_location_label(['kind' => $sheet['kind'], 'name' => $sheet['location_name'], 'parent_name' => $sheet['parent_name']])) : ($loc ? e(inv_location_label($loc + ['parent_name' => $loc['parent_id'] ? (inv_fetch_location((int)$loc['parent_id'])['name'] ?? '') : ''])) : 'Stock count') ?></h1>
  <?php if ($sheet || $loc): ?><a href="<?= $self ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> All places</a><?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Inventory isn’t set up yet.</div>
<?php elseif ($notFound): ?>
  <?php dt_empty('That count isn’t available to you.'); ?>

<?php elseif ($stale): ?>
  <div class="card"><div class="card__body" style="padding:18px">
    <p style="margin:0 0 14px">This count was started on an earlier day — start again.</p>
    <form method="POST" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="location_id" value="<?= (int)$sheet['location_id'] ?>">
      <button type="submit" class="btn-primary"><?= admin_icon('check', 16) ?> Start counting</button></form>
  </div></div>
  <script>try{localStorage.removeItem(<?= json_encode($draftKey) ?>)}catch(e){}</script>

<?php elseif ($sheet && $sheet['status'] === 'open'): $lines = $sheet['lines']; ?>
  <?php if (!$lines): ?>
    <?php dt_empty('Nothing is expected here yet — ask a manager to set what this place should have.'); ?>
  <?php else: ?>
  <p class="text-muted" style="margin:-4px 0 14px;font-size:13px">Count what is actually there. Stock doesn’t change until a manager checks any difference.</p>
  <form method="POST" action="<?= $self ?>" id="invcForm" data-count="<?= (int)$sheet['id'] ?>" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="submit"><input type="hidden" name="count_id" value="<?= (int)$sheet['id'] ?>">
    <div class="invc-grid">
      <?php foreach ($lines as $l): $exp = (int)$l['expected']; ?>
      <div class="invc-card" data-expected="<?= $exp ?>">
        <div class="invc-card__top"><?= inv_thumb_html($l, 48) ?>
          <div><strong><?= e($l['name']) ?></strong>
            <span class="inv-sub">Expected <b><?= $exp ?></b> <?= e((string)$l['unit_label']) ?></span></div></div>
        <div class="invc-step">
          <button type="button" class="invc-btn" data-step="-1" aria-label="One less <?= e($l['name']) ?>">−</button>
          <input name="counted[<?= (int)$l['item_id'] ?>]" type="number" inputmode="numeric" min="0" step="1" class="inp inp--num no-spin invc-inp" aria-label="Counted <?= e($l['name']) ?>">
          <button type="button" class="invc-btn" data-step="1" aria-label="One more <?= e($l['name']) ?>">+</button>
          <?php if ($exp >= 0): ?><button type="button" class="invc-same" data-same aria-label="Same as expected (<?= $exp ?>)">= <?= $exp ?></button><?php endif; ?>
        </div>
        <div class="invc-diff" aria-live="polite"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="invc-bar">
      <span id="invcProgress" class="text-muted">0 of <?= count($lines) ?> counted</span>
      <span id="invcAlert" role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap"></span>
      <button type="submit" class="btn-primary"><?= admin_icon('check', 16) ?> Submit count</button>
    </div>
  </form>
  <?php endif; ?>

<?php elseif ($sheet): ?>
  <script>try{localStorage.removeItem(<?= json_encode($draftKey) ?>)}catch(e){}</script>
  <div class="card">
    <div class="card__head"><span class="card__title"><?= $sheet['status'] === 'resolved' ? 'Count checked' : ($sheet['status'] === 'cancelled' ? 'Count closed' : 'Waiting for a manager') ?></span>
      <span class="text-muted" style="font-size:12.5px"><?= $sheet['submitted_at'] ? 'Submitted ' . e(date('j M, H:i', strtotime((string)$sheet['submitted_at']))) : '' ?><?= $sheet['counted_by_name'] ? ' by ' . e($sheet['counted_by_name']) : '' ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="inv-num">Expected</th><th class="inv-num">Counted</th><th>Result</th></tr></thead>
      <tbody>
      <?php foreach ($sheet['lines'] as $l): $g = $l['counted'] === null ? null : inv_count_line_gap($l)['gap']; ?>
        <tr>
          <td><span class="inv-name"><?= inv_thumb_html($l, 28) ?><span><?= e($l['name']) ?></span></span></td>
          <td class="inv-num"><?= (int)$l['expected'] ?></td>
          <td class="inv-num"><strong><?= $l['counted'] === null ? '—' : (int)$l['counted'] ?></strong></td>
          <td><?php if ($l['resolution']): ?><span class="badge <?= $l['resolution'] === 'accepted' ? 'badge--green' : 'badge--grey' ?>"><?= e(INV_RESOLUTION_LABELS[$l['resolution']] ?? $l['resolution']) ?></span>
              <?php elseif ($g !== null && $g !== 0): ?><span class="badge badge--orange"><?= $g > 0 ? '+' . $g : $g ?> — to check</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

<?php elseif ($loc): $stat = inv_count_status($loc['last_counted_at'], $loc['count_every_days'] !== null ? (int)$loc['count_every_days'] : null, $today);
    [$sl, $sc] = $locState['line_count'] === 0 ? ['Nothing to count', 'badge--grey'] : INV_COUNT_STATUS_LABELS[$stat]; ?>
  <div class="card"><div class="card__body" style="padding:18px">
    <p style="margin:0 0 6px"><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
      <span class="text-muted" style="font-size:13px"><?= $loc['last_counted_at'] ? 'Last counted ' . e(date('j M', strtotime((string)$loc['last_counted_at']))) : 'Never counted' ?></span></p>
    <?php if ($locState['open_count_id']): ?>
    <p class="text-muted" style="font-size:13px;margin:0 0 14px">A count of this place was started today and isn’t submitted yet.</p>
    <a href="<?= $self ?>?count=<?= (int)$locState['open_count_id'] ?>" class="btn-primary"><?= admin_icon('check', 16) ?> Continue counting</a>
    <?php elseif ($locState['line_count'] === 0): ?>
    <p class="text-muted" style="font-size:13px;margin:0">Nothing is expected here yet, so there is nothing to count — ask a manager to set what this place should have.</p>
    <?php else: ?>
    <p class="text-muted" style="font-size:13px;margin:0 0 14px">You’ll see every item this place should have, with the number the system expects. Count each one and submit.</p>
    <form method="POST" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
      <button type="submit" class="btn-primary"><?= admin_icon('check', 16) ?> Start counting</button></form>
    <?php endif; ?>
  </div></div>

<?php else: ?>
  <?php if (!$places): ?>
    <?php dt_empty('There’s nothing for you to count.'); ?>
  <?php else: ?>
  <div class="card"><div class="card__body" style="padding:0">
    <?php foreach ($places as $r): $n = (int)$r['line_count'];
      [$sl, $sc] = $r['open_count_id'] ? ['In progress', INV_COUNT_STATUS_LABELS[$r['count_status']][1]]
                 : ($n === 0 ? ['Nothing to count', 'badge--grey'] : INV_COUNT_STATUS_LABELS[$r['count_status']]); ?>
    <a href="<?= $self ?>?<?= $r['open_count_id'] ? 'count=' . (int)$r['open_count_id'] : 'location=' . (int)$r['id'] ?>" class="invc-place">
      <span><strong><?= e($r['label']) ?></strong>
        <span class="inv-sub"><?= $n ?> item<?= $n === 1 ? '' : 's' ?><?= $r['last_counted_at'] ? ' · last ' . e(date('j M', strtotime((string)$r['last_counted_at']))) : '' ?></span></span>
      <span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
    </a>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.invc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;padding-bottom:96px}
.invc-card{background:var(--white);border:2px solid var(--border);border-radius:var(--radius);padding:14px;display:grid;gap:12px;min-width:0}
.invc-card.is-off{border-color:var(--red)}
.invc-card.is-ok{border-color:var(--green)}
.invc-card__top{display:flex;gap:12px;align-items:center;min-width:0}
.invc-card__top > div{min-width:0;overflow-wrap:anywhere}
.invc-step{display:grid;grid-template-columns:48px minmax(0,1fr) 48px auto;gap:8px;align-items:center}
.invc-btn{height:48px;border-radius:10px;border:1px solid var(--border);background:var(--bg);font-size:24px;line-height:1;cursor:pointer}
.invc-inp{height:48px;font-size:22px;text-align:center;width:100%}
.invc-same{height:48px;padding:0 12px;border-radius:10px;border:1px solid var(--border);background:var(--white);cursor:pointer;font-weight:600;white-space:nowrap}
.invc-diff{font-size:13px;font-weight:600;color:var(--red);min-height:1em}
.invc-card.is-ok .invc-diff{color:var(--green)}
.invc-bar{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;padding-bottom:calc(12px + env(safe-area-inset-bottom));background:var(--white);border-top:1px solid var(--border);box-shadow:var(--shadow)}
@media (min-width:769px){.invc-bar{left:var(--sidebar-w)}}
.invc-place{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border-top:1px solid var(--border);color:inherit;text-decoration:none}
.invc-place:first-child{border-top:0}
.invc-warn{color:var(--red);font-weight:600}
.invc-place:hover{background:var(--bg)}
</style>
<script>
(function () {
  var form = document.getElementById('invcForm'); if (!form) return;
  // The draft survives a refused submit; it is cleared only once the server has
  // accepted the count (the submitted page removes it).
  var key = 'invc-draft-' + form.getAttribute('data-count');
  var cards = Array.prototype.slice.call(form.querySelectorAll('.invc-card'));
  var pr = document.getElementById('invcProgress');
  var alertBox = document.getElementById('invcAlert');   // announced only when a submit is blocked
  var draft = {};
  try { draft = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) { draft = {}; }
  function whole(v) { return /^\d+$/.test(v); }
  function expected(c) { return parseInt(c.getAttribute('data-expected'), 10) || 0; }
  function save() {
    var d = {};
    cards.forEach(function (c) { var i = c.querySelector('.invc-inp'); if (i.value !== '') d[i.name] = i.value; });
    try { localStorage.setItem(key, JSON.stringify(d)); } catch (e) {}
  }
  function paint(c) {
    var i = c.querySelector('.invc-inp'), exp = expected(c), out = c.querySelector('.invc-diff');
    c.classList.remove('is-off', 'is-ok'); out.textContent = '';
    if (i.value === '') return;
    if (!whole(i.value)) { c.classList.add('is-off'); out.textContent = 'Whole numbers only'; return; }
    var n = parseInt(i.value, 10);
    if (n === exp) { c.classList.add('is-ok'); out.textContent = '✓ Matches'; }
    else { c.classList.add('is-off'); out.textContent = n < exp ? (exp - n) + ' short' : (n - exp) + ' extra'; }
  }
  function progress() {
    var done = cards.filter(function (c) { return c.querySelector('.invc-inp').value !== ''; }).length;
    pr.textContent = done + ' of ' + cards.length + ' counted';
    pr.classList.remove('invc-warn');
  }
  cards.forEach(function (c) {
    var i = c.querySelector('.invc-inp');
    if (draft[i.name] !== undefined) i.value = draft[i.name];
    i.addEventListener('input', function () { paint(c); progress(); save(); });
    c.querySelectorAll('[data-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        var n = parseInt(i.value === '' ? String(Math.max(0, expected(c))) : i.value, 10) || 0;
        i.value = Math.max(0, n + parseInt(b.getAttribute('data-step'), 10));
        paint(c); progress(); save();
      });
    });
    var same = c.querySelector('[data-same]');
    if (same) same.addEventListener('click', function () { i.value = Math.max(0, expected(c)); paint(c); progress(); save(); });
    paint(c);
  });
  progress();
  form.addEventListener('submit', function (ev) {
    var missing = cards.filter(function (c) { return c.querySelector('.invc-inp').value === ''; });
    var bad = cards.filter(function (c) { var v = c.querySelector('.invc-inp').value; return v !== '' && !whole(v); });
    if (!missing.length && !bad.length) return;
    ev.preventDefault();
    var first = cards.filter(function (c) { return missing.indexOf(c) !== -1 || bad.indexOf(c) !== -1; })[0];
    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    first.querySelector('.invc-inp').focus();
    bad.forEach(paint);
    var warn = missing.length
      ? missing.length + ' still to count — enter 0 if there are none'
      : bad.length + (bad.length === 1 ? ' count is' : ' counts are') + ' not a whole number';
    pr.textContent = warn;
    pr.classList.add('invc-warn');
    alertBox.textContent = '';                                   // clear first so a repeat is announced again
    setTimeout(function () { alertBox.textContent = warn; }, 50);
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
