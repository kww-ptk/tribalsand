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
$__regUrl = site_url('/pos/register.php');
$__tab = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/></svg>';
$__nOn = count(array_filter($terms ?? [], fn($t) => pos_bool($t['is_active'])));
?>
<div class="page-header"><h1>Terminals</h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Settings → Your account → Migrations) to set up the POS.</div>
<?php else: ?>
<div class="card postm-reg">
  <span class="postm-ic postm-ic--brand"><?= $__tab ?></span>
  <div class="postm-reg__t">
    <b>Register a tablet as a till</b>
    <span>On the tablet, sign in to admin and open <code><?= e($__regUrl) ?></code>. Your sign-in ends there once it’s set up — from then on staff unlock it with their own PIN. The tablet keeps a key only it holds, so this has to happen on the device.</span>
  </div>
  <button type="button" class="btn-outline btn-sm copy-link" data-link="<?= e($__regUrl) ?>">Copy the link</button>
</div>

<?php if (!$terms): ?>
  <?php dt_empty('No tablets registered yet.'); ?>
<?php else: ?>
<div class="pos-ribbon">
  <div><span>Tablets</span><b><?= count($terms) ?></b></div>
  <div><span>Active</span><b><?= $__nOn ?></b></div>
  <div><span>Revoked</span><b><?= count($terms) - $__nOn ?></b></div>
</div>
<div class="postm-list">
  <?php foreach ($terms as $t): $tOut = pos_terminal_outlet_ids((int)$t['id']); $on = pos_bool($t['is_active']);
        $seen = $t['last_seen_at'] ? strtotime((string)$t['last_seen_at']) : 0; $idle = $on && (!$seen || $seen < time() - 3 * 86400); ?>
  <div class="card postm<?= $on ? '' : ' is-off' ?>">
    <form method="POST" action="<?= $self ?>" class="postm__body">
      <?= csrf_field() ?><input type="hidden" name="terminal_id" value="<?= (int)$t['id'] ?>">
      <div class="postm__top">
        <span class="postm-ic"><?= $__tab ?></span>
        <input name="name" class="inp postm__name" maxlength="120" value="<?= e($t['name']) ?>" aria-label="Terminal name" <?= $on ? '' : 'disabled' ?>>
        <?= !$on ? '<span class="badge badge--grey">Revoked</span>' : ($idle ? '<span class="badge badge--orange">Idle</span>' : '<span class="badge badge--green">Active</span>') ?>
      </div>
      <div class="text-muted postm__meta">
        <?= $seen ? 'Last used ' . e(date('j M, H:i', $seen)) : 'Never used' ?> · <?= e($t['venue_name'] ?? 'Shared') ?> ·
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
        <button type="submit" name="action" value="revoke" class="btn-outline btn-sm postm-revoke" data-confirm="Revoke <?= e($t['name']) ?>? It stops working as a till immediately." data-confirm-label="Revoke"><?= admin_icon('ban', 15) ?> Revoke</button>
        <button type="submit" name="action" value="save" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save</button>
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
.card__head{flex-wrap:wrap;gap:8px 12px}
.postm-reg{display:flex;align-items:center;gap:14px;padding:16px 18px;margin-bottom:16px;flex-wrap:wrap}
.postm-reg__t{flex:1;min-width:240px}
.postm-reg__t b{display:block}
.postm-reg__t span{display:block;font-size:12.5px;color:var(--muted);margin-top:2px}
.postm-reg__t code{font-size:12px}
.postm-ic{width:42px;height:42px;border-radius:11px;border:1px solid var(--border);display:inline-grid;place-items:center;color:var(--muted);flex:none;background:var(--white)}
.postm-ic--brand{background:#e6f0f1;color:var(--brand);border:0}
.postm-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(340px,100%),1fr));gap:14px}
.postm.is-off{opacity:.7}
.postm__body{padding:16px 18px;margin:0;display:flex;flex-direction:column;height:100%}
.postm__top{display:flex;align-items:center;gap:10px}
.postm__name{flex:1;min-width:0;font-weight:600}
.postm__meta{font-size:12.5px;margin:10px 0 2px}
.postm__sub{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:14px 0 8px}
.postm__chips{display:flex;flex-wrap:wrap;gap:8px}
.postm__actions{display:flex;gap:8px;margin-top:auto;padding-top:16px}
.postm__actions .btn-primary{margin-left:auto}
.postm-revoke{color:var(--red)}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
