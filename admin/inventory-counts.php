<?php
/**
 * Inventory → Counts — the manager's review. Every submitted count with a
 * difference, line by line: expected, counted, the gap and its value. Resolving
 * a short line as Missing / Broken / Stolen records the loss (with value); an
 * extra line as Found adds it back; Recount closes the line with no change. Only
 * the owner or a manager of that property resolves (inv_can_resolve()); the core
 * refuses while stock has moved since the count. Also lists the places due.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_login();
require_manager();

$self      = '/admin/inventory-counts.php';
$me        = current_admin();
$meId      = (int)$me['id'];
$role      = (string)($me['role'] ?? 'manager');
$vids      = admin_venue_ids();
$supported = inv_supported();
$flash     = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $back = $self;
    try {
        $lineId = (int)($_POST['line_id'] ?? 0);
        $res    = (string)($_POST['resolution'] ?? '');
        $loc    = inv_count_line_location($lineId);
        if (!$loc || !inv_location_visible($loc, $vids) || !inv_can_resolve($loc, $role, $vids)) {
            throw new InvRefusal('Only the owner or a manager of this property can resolve it.');
        }
        $back   = $self . '#c' . (int)$loc['count_id'];   // back to the same count card
        $note   = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        $moveId = inv_count_resolve_line($lineId, $res, $meId, $note);
        $msg = 'Marked for recount — nothing changed.';
        if ($moveId !== null) {
            $m = db_query('SELECT m.qty, m.reason, m.value, m.currency, i.name FROM inv_moves m JOIN inv_items i ON i.id = m.item_id WHERE m.id = :m', [':m' => $moveId])->fetch();
            $msg = "Recorded {$m['qty']} × {$m['name']} " . mb_strtolower(INV_RESOLUTION_LABELS[$m['reason']] ?? $m['reason']) . ' at ' . inv_location_label($loc)
                 . ($m['value'] !== null ? ' (' . inv_money((float)$m['value'], (string)$m['currency']) . ')' : '') . '.';
        }
        audit_log('inv.count_resolve', 'inv_location', (int)$loc['id'], "line {$lineId}: {$res}");
        $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $msg];
    } catch (InvRefusal $e) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $back); exit;
}

const INVQ_SHOW_MAX = 50;   // counts rendered at once, oldest first
$queue  = $supported ? inv_count_queue($vids) : [];
$more   = max(0, count($queue) - INVQ_SHOW_MAX);
$sheets = array_map(fn(array $q): ?array => inv_count_sheet((int)$q['id']), array_slice($queue, 0, INVQ_SHOW_MAX));
$places = $supported ? inv_counts_due($meId, $role, $vids, frontdesk_today_ymd()) : [];

$pageTitle  = 'Stock counts';
$activeMenu = 'inventory_counts';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Stock counts</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/inventory-count.php" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Count a place</a>
    <a href="/admin/inventory-locations.php" class="btn-outline btn-sm">Schedules</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>

<div class="inv-grid">
  <div class="inv-stack">
    <?php if (!$sheets): ?>
      <div class="card"><?php dt_empty('No differences waiting — every submitted count matched or has been checked.', 'check'); ?></div>
    <?php endif; ?>
    <?php if ($more): ?>
      <div class="alert alert--info">Showing the <?= INVQ_SHOW_MAX ?> oldest counts — <?= $more ?> more <?= $more === 1 ? 'is' : 'are' ?> waiting. Resolve these first.</div>
    <?php endif; ?>
    <?php foreach ($sheets as $s): if (!$s) continue;
      $canResolve = inv_can_resolve(['kind' => $s['kind'], 'venue_id' => $s['venue_id']], $role, $vids);
      $label = inv_location_label(['kind' => $s['kind'], 'name' => $s['location_name'], 'parent_name' => $s['parent_name']]); ?>
    <div class="card" id="c<?= (int)$s['id'] ?>">
      <div class="card__head"><span class="card__title"><?= e($label) ?></span>
        <span class="text-muted" style="font-size:12.5px">Counted by <?= e($s['counted_by_name'] ?? '—') ?> · <?= e(date('j M, H:i', strtotime((string)$s['submitted_at']))) ?></span></div>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Item</th><th class="inv-num">Expected</th><th class="inv-num">Counted</th><th class="inv-num">Gap</th><th>What happened</th></tr></thead>
        <tbody>
        <?php foreach ($s['lines'] as $l): if ($l['resolution'] !== null) continue; $g = inv_count_line_gap($l); ?>
          <tr>
            <td><a href="/admin/inventory-item.php?id=<?= (int)$l['item_id'] ?>" class="inv-name"><?= inv_thumb_html($l, 28) ?><span><?= e($l['name']) ?></span></a></td>
            <td class="inv-num"><?= (int)$l['expected'] ?></td>
            <td class="inv-num"><strong><?= (int)$l['counted'] ?></strong></td>
            <td class="inv-num"><span class="badge <?= $g['gap'] < 0 ? 'badge--red' : 'badge--green' ?>"><?= $g['gap'] > 0 ? '+' . $g['gap'] : $g['gap'] ?></span>
              <div class="inv-sub"><?= e(inv_money($g['value'], (string)$l['currency'])) ?></div></td>
            <td>
              <?php if (!$canResolve): ?><span class="text-muted" style="font-size:12.5px">The owner checks this place</span>
              <?php else: $serialLine = $l['tracking'] === 'serial'; ?>
              <form method="POST" action="<?= $self ?>" class="invq-form">
                <?= csrf_field() ?><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
                <input name="note" class="inp" maxlength="500" placeholder="Note (optional)" aria-label="Note for <?= e($l['name']) ?> (optional)">
                <div class="invq-btns">
                  <?php if ($serialLine): /* a serial unit is reported from its item page; inv_count_resolve_line() refuses a move here */ ?>
                  <?php elseif ($g['gap'] < 0): foreach (['missing', 'broken', 'stolen'] as $r): ?>
                  <button type="submit" name="resolution" value="<?= $r ?>" class="btn-outline btn-sm"
                    data-confirm="<?= e('Record ' . abs($g['gap']) . ' × ' . $l['name'] . ' as ' . mb_strtolower(INV_RESOLUTION_LABELS[$r]) . ($g['value'] !== null ? ' (' . inv_money($g['value'], (string)$l['currency']) . ')' : '') . '?') ?>"><?= e(INV_RESOLUTION_LABELS[$r]) ?></button>
                  <?php endforeach; else: ?>
                  <button type="submit" name="resolution" value="found" class="btn-outline btn-sm">Found</button>
                  <?php endif; ?>
                  <button type="submit" name="resolution" value="recount" class="btn-outline btn-sm">Recount</button>
                </div>
                <?php if ($serialLine): ?><span class="text-muted" style="font-size:12.5px">Report the unit from its item page</span><?php endif; ?>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="inv-stack">
    <div class="card">
      <div class="card__head"><span class="card__title">Due to count</span></div>
      <?php if (!$places): ?>
        <?php dt_empty('Nothing due. Set schedules on the Locations page.'); ?>
      <?php else: ?>
      <div class="card__body" style="padding:0">
        <?php foreach ($places as $r): [$sl, $sc] = INV_COUNT_STATUS_LABELS[$r['count_status']]; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;border-top:1px solid var(--border)">
          <span><strong><?= e($r['label']) ?></strong> <span class="badge <?= e($sc) ?>"><?= e($sl) ?></span></span>
          <a href="/admin/inventory-count.php?<?= $r['open_count_id'] ? 'count=' . (int)$r['open_count_id'] : 'location=' . (int)$r['id'] ?>" class="btn-outline btn-sm"><?= $r['open_count_id'] ? 'Continue' : 'Count' ?></a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.invq-form{display:grid;gap:6px;min-width:220px}
.invq-form .inp{width:100%}
.invq-btns{display:flex;flex-wrap:wrap;gap:6px}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
