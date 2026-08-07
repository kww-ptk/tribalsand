<?php
/** Admin: guest ↔ staff messages. Thread list + conversation + reply (PRG). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_frontdesk();   // ops & gate-security have a focused interface with no messaging

$pageTitle  = 'Messages';
$activeMenu = 'messages';

$holdId  = isset($_GET['hold']) ? (int)$_GET['hold'] : 0;
$threadP = $_GET['thread'] ?? null;
$addonId = ($threadP === null || $threadP === 'general') ? null : (int)$threadP;
$inThread = $holdId > 0 && $threadP !== null;

if ($inThread && !is_owner() && !staff_can_hold($holdId)) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your property.'];
    header('Location: /admin/messages.php'); exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ph = (int)($_POST['hold_id'] ?? 0);
    $pa = ($_POST['addon_id'] ?? '') === '' ? null : (int)$_POST['addon_id'];
    $body = trim((string)($_POST['body'] ?? ''));
    // Staff and managers may only reply on bookings at their own properties.
    if (!is_owner() && !staff_can_hold($ph)) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your property.'];
        header('Location: /admin/messages.php'); exit;
    }
    // Validate the target: hold must exist, and a targeted addon must belong to it.
    $holdOk  = $ph > 0 && db_query("SELECT 1 FROM holds WHERE id=:h", [':h'=>$ph])->fetchColumn();
    $addonOk = $pa === null || db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$pa, ':h'=>$ph])->fetchColumn();
    if ($holdOk && $addonOk && $body !== '') {
        if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
        db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,:a,'admin',:b,FALSE,TRUE)",
            [':h'=>$ph, ':a'=>$pa, ':b'=>$body]);
        audit_log('booking_message.admin_reply', 'hold', $ph, '');
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Reply sent.'];
    } elseif ($body !== '') {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That conversation no longer exists.'];
    }
    $q = '?hold=' . $ph . '&thread=' . ($pa === null ? 'general' : $pa);
    header('Location: /admin/messages.php' . $q); exit;
}

if ($inThread) { mark_thread_read_by_admin($holdId, $addonId); }

// ── Thread list mode: filter + search + pagination over the toolkit ──────────
if (!$inThread) {
    $pg           = paginate_params();          // page / per (default 10) / q / offset / ajax
    $filterUnread = ($_GET['view'] ?? '') === 'unread';

    $allThreads = fetch_admin_threads(admin_venue_ids());

    if ($filterUnread) {
        $allThreads = array_values(array_filter($allThreads, static fn($t) => (int)$t['unread_admin'] > 0));
    }
    if ($pg['q'] !== '') {
        $needle = mb_strtolower($pg['q']);
        $allThreads = array_values(array_filter($allThreads, static function ($t) use ($needle) {
            $hay = mb_strtolower(($t['guest_name'] ?? '') . ' ' . thread_title($t) . ' '
                 . ($t['last_body'] ?? '') . ' ' . ($t['venue_name'] ?? ''));
            return mb_strpos($hay, $needle) !== false;
        }));
    }

    $total   = count($allThreads);
    $meta    = paginate_meta($total, $pg['page'], $pg['per']);
    $threads = array_slice($allThreads, $meta['offset'], $meta['per']);
    $filtered = ($pg['q'] !== '' || $filterUnread);

    ob_start(); ?>
    <div class="card"><div class="card__body" style="padding:0">
      <?php if (!$threads): ?>
      <?php dt_empty($filtered ? 'No conversations match your filters.' : 'No messages yet.', 'message'); ?>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Guest</th><th>Thread</th><th>Latest</th><th>Unread</th></tr></thead>
          <tbody>
            <?php foreach ($threads as $t):
              $tid  = $t['addon_id'] === null ? 'general' : (int)$t['addon_id'];
              $href = '/admin/messages.php?hold=' . (int)$t['hold_id'] . '&thread=' . $tid;
            ?>
            <tr class="dt-rowlink" data-href="<?= e($href) ?>">
              <td>
                <strong><?= e($t['guest_name'] ?: 'Guest') ?></strong>
                <?php if (!empty($t['venue_name'])): ?><br><span class="text-muted" style="font-size:12px"><?= e($t['venue_name']) ?></span><?php endif; ?>
              </td>
              <td><a href="<?= e($href) ?>"><?= e(thread_title($t)) ?></a></td>
              <td class="text-muted" style="font-size:13px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string)($t['last_body'] ?? '')) ?></td>
              <td><?php if ((int)$t['unread_admin'] > 0): ?><span class="badge badge--orange"><?= (int)$t['unread_admin'] ?></span><?php else: ?>—<?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php dt_pager($meta); ?>
    </div></div>
    <?php
    $dtBody = ob_get_clean();

    // AJAX fragment: emit only the swappable body and stop.
    if ($pg['ajax']) { echo $dtBody; exit; }
}

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Messages</h1>
  <?php if ($inThread): ?>
  <a href="/admin/messages.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> All threads</a>
  <?php else: ?>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
  <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$inThread): ?>
<!-- View filter + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/messages.php" class="filters">
      <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field">
        <span>View</span>
        <select name="view" class="filter-select" aria-label="Filter threads" onchange="this.form.submit()">
          <option value="">All threads</option>
          <option value="unread" <?= $filterUnread ? 'selected' : '' ?>>Unread only</option>
        </select>
      </div>
      <?php if ($filterUnread): ?>
      <a href="/admin/messages.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search guest, thread or message…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php else:
  $msgs = fetch_thread_messages($holdId, $addonId);
  $ctx  = db_query("SELECT h.guest_name FROM holds h WHERE h.id=:h", [':h'=>$holdId])->fetch();
?>
<div class="card"><div class="card__body" style="padding:20px">
  <p style="margin:0 0 12px;font-weight:600"><?= e($ctx['guest_name'] ?? 'Guest') ?></p>
  <?php $lastId = $msgs ? (int)$msgs[count($msgs)-1]['id'] : 0; ?>
  <div id="amThread" class="am-thread"
       data-poll-url="/admin/messages-poll"
       data-hold="<?= (int)$holdId ?>"
       data-thread="<?= $addonId === null ? 'general' : (int)$addonId ?>"
       data-last="<?= $lastId ?>"
       style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
    <p class="text-muted am-empty"<?= $msgs ? ' style="display:none"' : '' ?>>No messages in this thread yet.</p>
    <?php foreach ($msgs as $m): $adminMsg = $m['sender'] === 'admin'; ?>
    <div class="am-msg" data-mid="<?= (int)$m['id'] ?>" style="max-width:80%;<?= $adminMsg ? 'align-self:flex-end;background:#102F3A;color:#fff' : 'align-self:flex-start;background:#f3f4f6;color:#111' ?>;border-radius:12px;padding:9px 12px;font-size:14px;line-height:1.5">
      <?= e($m['body']) ?>
      <div style="font-size:11px;margin-top:4px;opacity:.7"><?= $adminMsg ? 'Staff' : 'Guest' ?> · <?= e(message_time_label($m['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <form id="amForm" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
    <input type="hidden" name="addon_id" value="<?= $addonId === null ? '' : (int)$addonId ?>">
    <textarea name="body" rows="3" required placeholder="Reply to the guest…" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:16px"></textarea>
    <button type="submit" class="btn-primary" style="margin-top:8px">Send reply</button>
    <span class="am-status text-muted" aria-live="polite" style="margin-left:10px;font-size:13px"></span>
  </form>
</div></div>
<script src="/admin/assets/admin-chat.js?v=<?= @filemtime(__DIR__ . '/assets/admin-chat.js') ?: '1' ?>" defer></script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
