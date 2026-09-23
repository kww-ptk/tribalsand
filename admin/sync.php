<?php
/**
 * Admin: Zuri sync monitor (S§9) — owner-only.
 *
 * Switches, our queue health, Zuri's own /health (on demand — a signed call that
 * can take a few seconds when Zuri is slow), failed pushes with Retry, rejected
 * inbound events with Re-queue, open conflicts side by side with "Mark reviewed",
 * and the last reconcile report (Run now). Helpers: includes/sync-monitor.php.
 * All actions are PRG + CSRF.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/sync-monitor.php';
require_login();
require_owner();

$pageTitle  = 'Zuri sync';
$activeMenu = 'sync';

$flash = $_SESSION['sync_flash'] ?? null; unset($_SESSION['sync_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && sync_supported()) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    $msg = null;
    switch ($act) {
        case 'retry_outbox':
            $n = sync_retry_outbox($id ?: null);
            $msg = $n ? "Queued {$n} event" . ($n === 1 ? '' : 's') . ' to send again.' : 'Nothing to retry.';
            audit_log('sync.retry_outbox', 'sync_outbox', $id, (string)$n);
            break;
        case 'retry_inbox':
            $msg = sync_retry_inbox($id) ? 'Event re-queued — it applies on the next pass.' : 'That event is no longer rejected.';
            audit_log('sync.retry_inbox', 'sync_inbox', $id, '');
            break;
        case 'conflict_reviewed':
            $msg = sync_mark_conflict_reviewed($id) ? 'Conflict marked reviewed.' : 'Already reviewed.';
            audit_log('sync.conflict_reviewed', 'sync_conflict', $id, '');
            break;
        case 'reconcile':
            $r = sync_reconcile_report();
            $msg = $r['ok'] ? 'Reconcile ran — no drift found.' : 'Reconcile ran — drift found, see below.';
            break;
    }
    if ($msg) $_SESSION['sync_flash'] = ['type' => 'success', 'msg' => $msg];
    header('Location: /admin/sync.php'); exit;
}

$sw        = sync_monitor_switches();
$health    = sync_health();
$failed    = sync_failed_outbox();
$rejected  = sync_rejected_inbox();
$conflicts = sync_open_conflicts();
$openConf  = sync_open_conflict_count();
$recon     = sync_reconcile_last();
$peer      = (isset($_GET['peer']) && $sw['peer_url'] !== '' && $sw['secret_set']) ? sync_peer_health() : null;

/** Short uuid for tables; full value in the tooltip. */
function sync_short(string $uuid): string { return substr($uuid, 0, 8) . '…'; }
/** Pretty JSON for the side-by-side conflict view. */
function sync_pretty(mixed $json): string {
    $v = is_string($json) ? json_decode($json, true) : $json;
    return (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
/** A Nairobi-local "23 Sep 14:05" from a timestamptz. */
function sync_when(?string $ts): string { return $ts ? date('j M H:i', strtotime($ts)) : '—'; }

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Zuri sync</h1>
  <a href="/admin/sync.php?peer=1" class="btn-outline btn-sm"><?= admin_icon('rotate', 15) ?> Check Zuri now</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!sync_supported()): ?>
  <div class="alert alert--info">Run the <code>add_restaurant_sync.sql</code> migration (Admin → Migrations) to set up the Zuri sync.</div>
<?php else: ?>

<!-- ── Switches + our queues ── -->
<div class="sy-grid">
  <div class="card">
    <div class="card__head"><span class="card__title">Switches</span><span class="text-muted" style="font-size:12px">Set in the ECS environment</span></div>
    <div class="card__body" style="padding:0">
      <ul class="sy-list">
        <?php foreach (['SYNC_ENABLED' => 'Master switch', 'SYNC_SHADOW' => 'Shadow (log only)', 'SYNC_TS_TO_ZURI' => 'Menu, tables, hours → Zuri',
                        'SYNC_ZURI_TO_TS' => 'Sold out, customers, bookings ← Zuri', 'SYNC_RESERVATIONS' => 'Bookings via Zuri /reserve'] as $k => $lbl): ?>
        <li><span><?= e($lbl) ?> <code class="text-muted"><?= e($k) ?></code></span><span class="badge <?= $sw[$k] ? 'badge--green' : 'badge--grey' ?>"><?= $sw[$k] ? 'On' : 'Off' ?></span></li>
        <?php endforeach; ?>
        <li><span>Shared secret</span><span class="badge <?= $sw['secret_set'] ? 'badge--green' : 'badge--red' ?>"><?= $sw['secret_set'] ? 'Set' : 'Missing' ?></span></li>
        <li><span>Zuri URL</span><span class="text-muted" style="font-size:12px"><?= $sw['peer_url'] !== '' ? e($sw['peer_url']) : '— not set' ?></span></li>
        <li><span>Synced property</span><code><?= e($sw['venue']) ?></code></li>
      </ul>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Our queues</span><span class="text-muted" style="font-size:12px">as of <?= e(date('H:i')) ?></span></div>
    <div class="card__body sy-kpis">
      <div class="sy-kpi"><div class="n"><?= (int)$health['outbox_pending'] ?></div><div class="l">To send</div></div>
      <div class="sy-kpi<?= $health['outbox_failed'] ? ' is-bad' : '' ?>"><div class="n"><?= (int)$health['outbox_failed'] ?></div><div class="l">Failed sends</div></div>
      <div class="sy-kpi"><div class="n"><?= (int)$health['inbox_pending'] ?></div><div class="l">To apply</div></div>
      <div class="sy-kpi<?= $health['inbox_rejected'] ? ' is-bad' : '' ?>"><div class="n"><?= (int)$health['inbox_rejected'] ?></div><div class="l">Rejected in</div></div>
      <div class="sy-kpi<?= $openConf ? ' is-warn' : '' ?>"><div class="n"><?= $openConf ?></div><div class="l">Open conflicts</div></div>
      <div class="sy-kpi"><div class="n sy-small"><?= $health['oldest_pending_secs'] ? e(gmdate('H:i:s', (int)$health['oldest_pending_secs'])) : '—' ?></div><div class="l">Oldest unsent</div></div>
    </div>
    <div class="text-muted sy-foot">Last sent <?= e(sync_when($health['last_sent_at'])) ?> · last applied <?= e(sync_when($health['last_applied_at'])) ?></div>
  </div>
</div>

<!-- ── Zuri's health ── -->
<?php if ($peer !== null): ?>
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Zuri's health</span>
    <span class="badge <?= ($peer['code'] === 200 && !empty($peer['body']['ok'])) ? 'badge--green' : 'badge--red' ?>"><?= $peer['reachable'] ? 'HTTP ' . (int)$peer['code'] : 'Unreachable' ?></span>
  </div>
  <div class="card__body" style="padding:18px">
    <?php if ($peer['alerts']): ?>
      <p style="margin:0 0 8px;font-weight:600">Zuri reports:</p>
      <ul class="sy-alerts"><?php foreach ($peer['alerts'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
    <?php elseif ($peer['code'] === 200): ?>
      <p class="text-muted" style="margin:0">No alerts from Zuri.</p>
    <?php else: ?>
      <p class="text-muted" style="margin:0">No answer from Zuri — check the URL, the shared secret and that Zuri's endpoint is up.</p>
    <?php endif; ?>
    <?php if (is_array($peer['body'])): ?>
    <details style="margin-top:10px"><summary class="text-muted" style="cursor:pointer;font-size:12px">Full response</summary><pre class="sy-pre"><?= e(sync_pretty($peer['body'])) ?></pre></details>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($sw['peer_url'] === '' || !$sw['secret_set']): ?>
<div class="alert alert--info" style="margin-top:16px">Set <code>SYNC_PEER_URL</code> and <code>SYNC_SHARED_SECRET</code> to check Zuri's side from here.</div>
<?php endif; ?>

<!-- ── Failed pushes ── -->
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Failed sends to Zuri</span>
    <?php if ($failed): ?>
    <form method="POST" action="/admin/sync.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="retry_outbox"><button class="btn-outline btn-sm"><?= admin_icon('rotate', 14) ?> Retry all</button></form>
    <?php endif; ?>
  </div>
  <div class="card__body" style="padding:<?= $failed ? '0' : '18px' ?>">
    <?php if (!$failed): ?><p class="text-muted" style="margin:0;font-size:13px">Nothing failed.</p><?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Entity</th><th>Record</th><th>Op · v</th><th>Why</th><th>Queued</th><th style="width:1%"></th></tr></thead>
      <tbody><?php foreach ($failed as $f): ?>
        <tr>
          <td><?= e($f['entity']) ?></td>
          <td><code data-tip="<?= e($f['sync_uuid']) ?>"><?= e(sync_short((string)$f['sync_uuid'])) ?></code></td>
          <td class="text-muted"><?= e($f['operation']) ?> · <?= (int)$f['version'] ?></td>
          <td class="sy-err"><?= e((string)$f['last_error']) ?></td>
          <td class="text-muted" style="white-space:nowrap"><?= e(sync_when($f['created_at'])) ?></td>
          <td><form method="POST" action="/admin/sync.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="retry_outbox"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn-icon" data-tip="Retry" aria-label="Retry"><?= admin_icon('rotate', 15) ?></button></form></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Rejected inbound ── -->
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Rejected from Zuri</span></div>
  <div class="card__body" style="padding:<?= $rejected ? '0' : '18px' ?>">
    <?php if (!$rejected): ?><p class="text-muted" style="margin:0;font-size:13px">Nothing rejected.</p><?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Entity</th><th>Record</th><th>Op · v</th><th>Why</th><th>Received</th><th style="width:1%"></th></tr></thead>
      <tbody><?php foreach ($rejected as $r): ?>
        <tr>
          <td><?= e($r['entity']) ?></td>
          <td><code data-tip="<?= e($r['sync_uuid']) ?>"><?= e(sync_short((string)$r['sync_uuid'])) ?></code></td>
          <td class="text-muted"><?= e($r['operation']) ?> · <?= (int)$r['version'] ?></td>
          <td class="sy-err"><?= e((string)$r['error']) ?></td>
          <td class="text-muted" style="white-space:nowrap"><?= e(sync_when($r['received_at'])) ?></td>
          <td><form method="POST" action="/admin/sync.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="retry_inbox"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-icon" data-tip="Apply again" aria-label="Apply again"><?= admin_icon('rotate', 15) ?></button></form></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Conflicts ── -->
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Open conflicts</span><span class="text-muted" style="font-size:12px">Both sides are kept; the system already applied the winner</span></div>
  <div class="card__body" style="padding:<?= $conflicts ? '0' : '18px' ?>">
    <?php if (!$conflicts): ?><p class="text-muted" style="margin:0;font-size:13px">No open conflicts.</p><?php else: ?>
    <?php foreach ($conflicts as $c): ?>
    <div class="sy-conf">
      <div class="sy-conf__head">
        <span><strong><?= e($c['entity']) ?></strong> <code data-tip="<?= e($c['sync_uuid']) ?>"><?= e(sync_short((string)$c['sync_uuid'])) ?></code>
          <span class="badge badge--orange" style="margin-left:6px"><?= e((string)$c['resolution']) ?></span>
          <span class="text-muted" style="font-size:12px;margin-left:6px"><?= e(sync_when($c['created_at'])) ?></span></span>
        <form method="POST" action="/admin/sync.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="conflict_reviewed"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-outline btn-sm"><?= admin_icon('check', 14) ?> Mark reviewed</button></form>
      </div>
      <div class="sy-conf__sides">
        <div><div class="sy-side-l">Ours · v<?= (int)$c['local_version'] ?></div><pre class="sy-pre"><?= e(sync_pretty($c['local_data'])) ?></pre></div>
        <div><div class="sy-side-l">Zuri · v<?= (int)$c['remote_version'] ?></div><pre class="sy-pre"><?= e(sync_pretty($c['remote_data'])) ?></pre></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Reconcile ── -->
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Reconcile</span>
    <form method="POST" action="/admin/sync.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="reconcile"><button class="btn-outline btn-sm"><?= admin_icon('rotate', 14) ?> Run now</button></form>
  </div>
  <div class="card__body" style="padding:<?= $recon ? '0' : '18px' ?>">
    <?php if (!$recon): ?><p class="text-muted" style="margin:0;font-size:13px">Not run yet. It runs daily while sync is on, or press Run now.</p><?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Entity</th><th>Rows</th><th>Not delivered</th><th>Checksum</th><th>Zuri</th></tr></thead>
      <tbody><?php foreach ($recon['entities'] ?? [] as $ent => $x): ?>
        <tr>
          <td><?= e($ent) ?></td>
          <td><?= (int)$x['count'] ?></td>
          <td><?= $x['undelivered'] ? '<span class="badge badge--orange">' . (int)$x['undelivered'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
          <td><code class="text-muted" style="font-size:11px"><?= e(substr((string)$x['checksum'], 0, 12)) ?></code></td>
          <td><?= $x['peer_match'] === null ? '<span class="text-muted">—</span>' : ($x['peer_match'] ? '<span class="badge badge--green">Match</span>' : '<span class="badge badge--red">Mismatch</span>') ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <div class="text-muted sy-foot">Ran <?= e(sync_when($recon['generated_at'] ?? null)) ?>.
      <?= empty($recon['peer_checksums']) ? 'Zuri doesn’t publish checksums yet, so “Not delivered” is the drift check.' : '' ?>
      Rows not delivered after a shadow period are normal — run <code>php bin/sync-requeue.php</code> at go-live.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<style>
.sy-grid{display:grid;grid-template-columns:minmax(280px,1fr) 1.4fr;gap:16px;align-items:start}
@media (max-width:900px){.sy-grid{grid-template-columns:1fr}}
.sy-list{list-style:none;margin:0;padding:0}
.sy-list li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 16px;border-top:1px solid var(--border,#e7ded7);font-size:13px}
.sy-list li:first-child{border-top:0}
.sy-list code{font-size:11px;margin-left:4px}
.sy-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:16px}
@media (max-width:520px){.sy-kpis{grid-template-columns:1fr 1fr}}
.sy-kpi{border:1px solid var(--border,#e7ded7);border-radius:10px;padding:10px 12px}
.sy-kpi .n{font-size:22px;font-weight:800;color:#102F3A;line-height:1.1}
.sy-kpi .n.sy-small{font-size:16px}
.sy-kpi .l{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:4px}
.sy-kpi.is-bad .n{color:#b91c1c}.sy-kpi.is-warn .n{color:#b45309}
.sy-foot{font-size:12px;padding:0 16px 14px}
.sy-err{font-size:12px;max-width:360px;word-break:break-word}
.sy-alerts{margin:0;padding-left:18px;font-size:13px}
.sy-pre{margin:0;background:var(--bg-soft,#faf6f2);border:1px solid var(--border,#e7ded7);border-radius:8px;padding:10px;font-size:11.5px;max-height:260px;overflow:auto;white-space:pre-wrap;word-break:break-word}
.sy-conf{padding:14px 16px;border-top:1px solid var(--border,#e7ded7)}
.sy-conf:first-child{border-top:0}
.sy-conf__head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.sy-conf__sides{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:640px){.sy-conf__sides{grid-template-columns:1fr}}
.sy-side-l{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px}
</style>

<?php include __DIR__ . '/_layout_end.php'; ?>
