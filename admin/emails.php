<?php
/**
 * Emails — the catalogue of every email the system sends (owner-only).
 *
 * Grouped by topic (Bookings, Enquiries, Restaurant, Point of sale, Staff
 * alerts), one compact row per template from email_registry(): what triggers
 * it, who gets it, on/off, the staff-alert recipient override, 30-day stats,
 * and preview / reword / log actions. Filter tabs + a search box narrow the
 * list client-side. Triggers stay in code — the owner controls whether, to
 * whom and in what words.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/icons.php';
require_owner();

$flash = $_SESSION['emails_flash'] ?? null; unset($_SESSION['emails_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $key = (string)($_POST['key'] ?? '');
    $t   = email_template($key);
    $act = (string)($_POST['action'] ?? '');
    try {
        if (!$t) throw new InvalidArgumentException('Unknown email.');
        if ($act === 'toggle') {
            $on = ($_POST['on'] ?? '0') === '1';
            email_template_set_enabled($key, $on);
            audit_log('email.' . ($on ? 'enable' : 'disable'), 'email_template', 0, $key);
            $_SESSION['emails_flash'] = ['type' => 'success', 'msg' => '“' . $t['name'] . '” is now ' . ($on ? 'on.' : 'off — it will be logged as “Switched off” instead of sent.')];
        } elseif ($act === 'recipients') {
            $list = email_set_recipient_override($key, (string)($_POST['to'] ?? ''));
            audit_log('email.recipients', 'email_template', 0, $key . ': ' . ($list ? implode(', ', $list) : 'default'));
            $_SESSION['emails_flash'] = ['type' => 'success', 'msg' => $list
                ? '“' . $t['name'] . '” now goes to ' . implode(', ', $list) . '.'
                : '“' . $t['name'] . '” goes to the notification address in Settings again.'];
        }
    } catch (InvalidArgumentException $e) {
        $_SESSION['emails_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: /admin/emails.php#' . rawurlencode($key));
    exit;
}

$stats   = email_log_template_stats();
$staffTo = email_staff_address();
$logOn   = email_log_supported();

// Group by topic, in a fixed, readable order (bookings first — the ones that matter most).
$topicOrder = ['Bookings', 'Enquiries & requests', 'Restaurant', 'Point of sale', 'Staff alerts'];
$sections = [];
foreach (email_registry() as $key => $t) $sections[$t['topic']][] = $key;
uksort($sections, function ($a, $b) use ($topicOrder) {
    $ia = array_search($a, $topicOrder, true); $ib = array_search($b, $topicOrder, true);
    return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
});
$counts = ['all' => 0, 'guest' => 0, 'staff' => 0, 'off' => 0, 'problems' => 0];
$enabled = [];
foreach (email_registry() as $key => $t) {
    $enabled[$key] = email_template_enabled($key);
    $counts['all']++; $counts[$t['audience']]++;
    if (!$enabled[$key]) $counts['off']++;
    if ((int)($stats[$key]['failed_30'] ?? 0) > 0) $counts['problems']++;
}

$pageTitle  = 'Emails';
$activeMenu = 'emails';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Emails</h1>
  <a href="/admin/email-log.php" class="btn-outline btn-sm"><?= admin_icon('inbox', 14) ?> Email log</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$logOn): ?><div class="alert alert--error">The email log isn’t switched on yet — run <code>add_email_log.sql</code> in Admin → Migrations. Emails still send; they just can’t be logged.</div><?php endif; ?>
<?php if (!email_templates_supported()): ?><div class="alert alert--info">Rewording emails needs the <code>add_email_templates.sql</code> migration. Until then every email uses its built-in wording.</div><?php endif; ?>

<p class="text-muted" style="margin:-6px 0 16px;font-size:13px;max-width:86ch">Every email the website sends, what makes it go out, and who gets it. Switch an email off, change who receives a staff alert, preview it or change its wording. What <em>triggers</em> an email is part of the booking system and can’t be changed here.</p>

<div class="em-toolbar">
  <div class="em-tabs" role="radiogroup" aria-label="Show">
    <?php foreach (['all' => 'All', 'guest' => 'To guests', 'staff' => 'To the team', 'off' => 'Switched off', 'problems' => 'Problems'] as $k => $lbl):
      if (in_array($k, ['off', 'problems'], true) && !$counts[$k]) continue; ?>
    <label class="optchip"><input type="radio" name="em-filter" value="<?= $k ?>" <?= $k === 'all' ? 'checked' : '' ?>><?= e($lbl) ?> <span class="em-count"><?= (int)$counts[$k] ?></span></label>
    <?php endforeach; ?>
  </div>
  <label class="em-search"><?= admin_icon('search', 15) ?><input type="search" class="inp" id="emSearch" placeholder="Find an email…" aria-label="Find an email"></label>
</div>

<?php foreach ($sections as $topic => $keys): ?>
<section class="card em-section" data-em-section>
  <div class="card__head"><span class="card__title"><?= e($topic) ?></span><span class="text-muted" style="font-size:12px"><?= count($keys) ?> email<?= count($keys) === 1 ? '' : 's' ?></span></div>
  <div class="em-list">
  <?php foreach ($keys as $key):
    $t      = email_template($key);
    $aud    = $t['audience'];
    $st     = $stats[$key] ?? null;
    $on     = $enabled[$key];
    $ov     = email_recipient_override($key);
    $locked = !empty($t['locked']);
    $bad    = (int)($st['failed_30'] ?? 0);
    $to     = ($aud === 'staff' && !$locked) ? ($ov ? implode(', ', $ov) : $staffTo . ' (Settings)') : $t['recipients'];
  ?>
    <div class="em-row<?= $on ? '' : ' is-off' ?>" id="<?= e($key) ?>" data-aud="<?= e($aud) ?>" data-off="<?= $on ? '0' : '1' ?>" data-bad="<?= $bad ? '1' : '0' ?>"
         data-text="<?= e(mb_strtolower($t['name'] . ' ' . $t['trigger'] . ' ' . $to)) ?>">
      <div class="em-main">
        <div class="em-name">
          <strong><?= e($t['name']) ?></strong>
          <span class="badge <?= $aud === 'staff' ? 'badge--blue' : 'badge--green' ?>"><?= $aud === 'staff' ? 'Team' : 'Guest' ?></span>
          <?php if ($bad): ?><a href="/admin/email-log.php?template=<?= e($key) ?>&status=problems" class="badge badge--red"><?= $bad ?> failed</a><?php endif; ?>
        </div>
        <div class="em-trigger"><?= e($t['trigger']) ?></div>
        <div class="em-meta">
          <span><span class="text-muted">To:</span> <?= e($to) ?></span>
          <?php if ($logOn): ?>
          <span class="text-muted"><?= (int)($st['sent_30'] ?? 0) ?> sent in 30 days · last <?= !empty($st['last_sent']) ? e(date('j M, H:i', strtotime((string)$st['last_sent']))) : 'never' ?><?php if ((int)($st['held_30'] ?? 0) > 0): ?> · <a href="/admin/email-log.php?template=<?= e($key) ?>&status=skipped"><?= (int)$st['held_30'] ?> not sent</a><?php endif; ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($t['note']) && !$locked): ?><div class="em-note"><?= e($t['note']) ?></div><?php endif; ?>
        <?php if ($aud === 'staff' && !$locked): ?>
        <details class="em-rcpt"<?= $ov ? ' open' : '' ?>>
          <summary>Send to someone else</summary>
          <form method="POST" action="/admin/emails.php" class="em-rcpt-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="recipients"><input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="text" name="to" class="inp" value="<?= e(implode(', ', $ov)) ?>" placeholder="e.g. frontdesk@tribalsand.com, manager@tribalsand.com" aria-label="Recipients">
            <button type="submit" class="btn-icon btn-icon--primary" data-tip="Save recipients" aria-label="Save recipients"><?= admin_icon('check') ?></button>
          </form>
          <p class="text-muted" style="font-size:11.5px;margin:6px 0 0">Separate several addresses with commas. Leave empty to use the notification address in Settings.</p>
        </details>
        <?php endif; ?>
      </div>
      <div class="em-side">
        <?php if ($locked): ?>
          <span class="badge badge--grey" data-tip="<?= e($t['note'] ?? 'Always on.') ?>">Always on</span>
        <?php else: ?>
          <form method="POST" action="/admin/emails.php" class="em-toggle">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
            <label class="togglerow" data-tip="<?= $on ? 'Switch this email off' : 'Switch this email on' ?>"><span class="toggle"><input type="checkbox" <?= $on ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="<?= $on ? 'On' : 'Off' ?>"><span class="toggle-slider"></span></span><span><?= $on ? 'On' : 'Off' ?></span></label>
          </form>
        <?php endif; ?>
        <div class="em-actions">
          <a href="/admin/email-preview.php?key=<?= e($key) ?>" class="btn-icon btn-icon--outline" data-tip="Preview" aria-label="Preview"><?= admin_icon('eye') ?></a>
          <a href="/admin/email-edit.php?key=<?= e($key) ?>" class="btn-icon btn-icon--outline" data-tip="Edit wording" aria-label="Edit wording"><?= admin_icon('edit') ?></a>
          <?php if ($logOn): ?><a href="/admin/email-log.php?template=<?= e($key) ?>" class="btn-icon btn-icon--outline" data-tip="Log" aria-label="Log"><?= admin_icon('inbox') ?></a><?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<div class="card" id="emEmpty" hidden><div class="card__body text-muted" style="padding:18px">No email matches.</div></div>

<style>
.em-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.em-tabs{display:flex;gap:6px;flex-wrap:wrap}
.em-tabs .optchip{cursor:pointer}
.em-count{opacity:.7;font-size:11.5px;margin-left:4px}
.em-search{position:relative;display:flex;align-items:center;flex:0 1 280px;min-width:200px}
.em-search svg{position:absolute;left:11px;color:var(--muted);pointer-events:none}
.em-search .inp{width:100%;padding-left:34px}
.em-section{margin-bottom:16px}
.em-list{display:flex;flex-direction:column}
.em-row{display:flex;justify-content:space-between;gap:18px;padding:14px 18px;border-top:1px solid var(--border)}
.em-row:first-child{border-top:0}
.em-row[hidden],.em-section[hidden]{display:none}
.em-row.is-off .em-main{opacity:.6}
.em-main{min-width:0;flex:1;display:flex;flex-direction:column;gap:5px}
.em-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.em-name strong{font-size:14.5px}
.em-name .badge{text-decoration:none}
.em-trigger{font-size:13px;color:var(--text);max-width:90ch}
.em-meta{display:flex;flex-wrap:wrap;gap:4px 16px;font-size:12.5px;overflow-wrap:anywhere}
.em-note{font-size:12px;color:#b45309}
.em-side{display:flex;flex-direction:column;align-items:flex-end;gap:10px;flex:0 0 auto}
.em-toggle{margin:0}
.em-actions{display:flex;gap:6px}
.em-rcpt summary{cursor:pointer;font-size:12.5px;color:var(--brand)}
.em-rcpt-form{display:flex;gap:8px;margin-top:8px;max-width:560px}.em-rcpt-form .inp{flex:1;min-width:0}
@media (max-width:640px){.em-row{flex-direction:column;gap:10px}.em-side{flex-direction:row;align-items:center;justify-content:space-between}}
</style>
<script>
(function () {
  var search = document.getElementById('emSearch'), empty = document.getElementById('emEmpty');
  if (!search) return;
  function apply() {
    var f = (document.querySelector('input[name="em-filter"]:checked') || {}).value || 'all';
    var q = (search.value || '').trim().toLowerCase(), shown = 0;
    document.querySelectorAll('[data-em-section]').forEach(function (sec) {
      var any = 0;
      sec.querySelectorAll('.em-row').forEach(function (r) {
        var ok = f === 'all' ? true
               : (f === 'guest' || f === 'staff') ? r.dataset.aud === f
               : f === 'off' ? r.dataset.off === '1' : r.dataset.bad === '1';
        if (ok && q) ok = r.dataset.text.indexOf(q) !== -1;
        r.hidden = !ok; if (ok) any++;
      });
      sec.hidden = !any; shown += any;
    });
    empty.hidden = shown > 0;
  }
  document.querySelectorAll('input[name="em-filter"]').forEach(function (r) { r.addEventListener('change', apply); });
  search.addEventListener('input', apply);
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
