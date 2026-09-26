<?php
/**
 * Admin: POS terminals — the tablets registered as tills.
 * Owner, or a manager for terminals at their property / selling their outlets.
 *
 * Registration happens ON the tablet (/pos/register.php): the token is generated
 * there and lives only in that browser's httpOnly cookie. This page renames a
 * terminal, changes which outlets it sells for, and revokes it (the tablet drops
 * to "no longer a till" on its next request). A manager can only add or remove
 * outlets they manage; outlets outside their scope are left as they were.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pos-auth.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();
require_manager();

$pageTitle  = 'POS terminals';
$activeMenu = 'pos_terminals';
$supported  = pos_supported();
$self       = '/admin/pos-terminals.php';
$me         = current_admin();
$mine       = $supported ? pos_manageable_outlet_ids($me) : [];

/** Terminals this account may manage. */
function post_visible_terminals(array $me, array $mine): array {
    if (!pos_supported()) return [];
    $rows = db_query("SELECT t.*, v.name AS venue_name, a.name AS created_by_name
                        FROM pos_terminals t LEFT JOIN venues v ON v.id = t.venue_id LEFT JOIN admin_users a ON a.id = t.created_by
                       ORDER BY t.is_active DESC, t.name")->fetchAll();
    if (($me['role'] ?? '') === 'owner') return $rows;
    $venues = array_map('intval', db_query('SELECT venue_id FROM admin_user_venues WHERE admin_user_id = :u', [':u' => (int)$me['id']])->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($rows, fn($t) =>
        (int)$t['created_by'] === (int)$me['id']
        || ($t['venue_id'] !== null && in_array((int)$t['venue_id'], $venues, true))
        || array_intersect(pos_terminal_outlet_ids((int)$t['id']), $mine)));
}

$flash = $_SESSION['post_flash'] ?? null; unset($_SESSION['post_flash']);
$terms = post_visible_terminals($me, $mine);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $tid = (int)($_POST['terminal_id'] ?? 0);
    $t = null; foreach ($terms as $x) if ((int)$x['id'] === $tid) { $t = $x; break; }
    if (!$t) { $_SESSION['post_flash'] = ['type' => 'error', 'msg' => 'That terminal is not one you manage.']; header('Location: ' . $self); exit; }
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) { $_SESSION['post_flash'] = ['type' => 'error', 'msg' => 'Give the terminal a name.']; header('Location: ' . $self); exit; }
        $want = array_values(array_intersect(array_map('intval', (array)($_POST['outlets'] ?? [])), $mine));
        pos_tx(function () use ($tid, $name, $want, $mine): void {
            db_query('UPDATE pos_terminals SET name = :n WHERE id = :i', [':n' => $name, ':i' => $tid]);
            // Only the outlets this account manages are rewritten; others stay as they were.
            foreach ($mine as $o) {
                if (in_array($o, $want, true)) db_query('INSERT INTO pos_terminal_outlets (terminal_id, outlet_id) VALUES (:t, :o) ON CONFLICT DO NOTHING', [':t' => $tid, ':o' => $o]);
                else db_query('DELETE FROM pos_terminal_outlets WHERE terminal_id = :t AND outlet_id = :o', [':t' => $tid, ':o' => $o]);
            }
        });
        audit_log('pos.terminal_save', 'pos_terminal', $tid, $name);
        $_SESSION['post_flash'] = ['type' => 'success', 'msg' => "{$name} saved."];
    } elseif ($act === 'revoke' || $act === 'restore') {
        db_query('UPDATE pos_terminals SET is_active = :a WHERE id = :i', [':a' => $act === 'restore' ? 'TRUE' : 'FALSE', ':i' => $tid]);
        audit_log('pos.terminal_' . $act, 'pos_terminal', $tid, (string)$t['name']);
        $_SESSION['post_flash'] = ['type' => 'success', 'msg' => $act === 'revoke' ? "{$t['name']} revoked — it stops working as a till immediately." : "{$t['name']} restored."];
    }
    header('Location: ' . $self); exit;
}

$outlets = $supported ? pos_fetch_outlets(null, false) : [];
$outletName = []; foreach ($outlets as $o) $outletName[(int)$o['id']] = $o['name'];

include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>POS terminals</h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Admin → Migrations) to set up the POS.</div>
<?php else: ?>
<div class="card" style="margin-bottom:18px"><div class="card__body" style="padding:16px 20px;font-size:13.5px">
  <p style="margin:0 0 6px"><strong>To register a tablet as a till:</strong> sign in to admin on the tablet, then open <code><?= e(site_url('/pos/register.php')) ?></code> and follow the steps. Your admin sign-in ends on that tablet once it’s set up — from then on staff unlock it with their PIN.</p>
  <p class="text-muted" style="margin:0;font-size:12.5px">The tablet keeps a secret key only it holds, so registration has to happen on the device. Revoking below cuts it off at once.</p>
</div></div>

<?php if (!$terms): ?>
  <?php dt_empty('No tablets registered yet.'); ?>
<?php else: ?>
<div class="postm-list">
  <?php foreach ($terms as $t): $tOut = pos_terminal_outlet_ids((int)$t['id']); $on = pos_bool($t['is_active']); ?>
  <div class="card postm <?= $on ? '' : 'is-off' ?>">
    <form method="POST" action="<?= $self ?>" class="postm__body">
      <?= csrf_field() ?><input type="hidden" name="terminal_id" value="<?= (int)$t['id'] ?>">
      <div class="postm__top">
        <input name="name" class="inp postm__name" maxlength="120" value="<?= e($t['name']) ?>" aria-label="Terminal name" <?= $on ? '' : 'disabled' ?>>
        <?= $on ? '<span class="badge badge--green">Active</span>' : '<span class="badge badge--grey">Revoked</span>' ?>
      </div>
      <div class="text-muted postm__meta">
        <?= e($t['venue_name'] ?? 'Shared') ?> ·
        <?= $t['last_seen_at'] ? 'last used ' . e(date('j M, H:i', strtotime((string)$t['last_seen_at']))) : 'never used' ?> ·
        registered <?= e(date('j M Y', strtotime((string)$t['created_at']))) ?><?= $t['created_by_name'] ? ' by ' . e($t['created_by_name']) : '' ?>
      </div>
      <div class="postm__sub">Sells for</div>
      <div class="postm__chips">
        <?php foreach ($outlets as $o): $oid = (int)$o['id']; $can = in_array($oid, $mine, true); if (!$can && !in_array($oid, $tOut, true)) continue; ?>
        <label class="optchip"><input type="checkbox" name="outlets[]" value="<?= $oid ?>" <?= in_array($oid, $tOut, true) ? 'checked' : '' ?> <?= $can && $on ? '' : 'disabled' ?>><?= e($o['name']) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="postm__actions">
        <?php if ($on): ?>
        <button type="submit" name="action" value="save" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save</button>
        <button type="submit" name="action" value="revoke" class="btn-outline btn-sm" data-confirm="Revoke <?= e($t['name']) ?>? It stops working as a till immediately."><?= admin_icon('ban', 15) ?> Revoke</button>
        <?php else: ?>
        <button type="submit" name="action" value="restore" class="btn-outline btn-sm"><?= admin_icon('rotate', 15) ?> Restore</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.postm-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
.postm.is-off{opacity:.7}
.postm__body{padding:16px 18px;margin:0}
.postm__top{display:flex;align-items:center;gap:10px}
.postm__name{flex:1;font-weight:600}
.postm__meta{font-size:12.5px;margin:8px 0 2px}
.postm__sub{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:14px 0 8px}
.postm__chips{display:flex;flex-wrap:wrap;gap:8px}
.postm__actions{display:flex;gap:8px;margin-top:16px}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
