<?php
/**
 * Emails — the catalogue of every email the system sends (owner-only).
 *
 * One card per template from email_registry(): what triggers it, who gets it,
 * on/off, the staff-alert recipient override, last sent + 30-day counts, and
 * links to preview, reword and the log. Triggers stay in code — a brand-new
 * trigger is a code change; the owner controls whether, to whom and in what words.
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
$grouped = email_registry_grouped();
$staffTo = email_staff_address();
$logOn   = email_log_supported();

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

<p class="text-muted" style="margin:-6px 0 18px;font-size:13px;max-width:86ch">Every email the website sends, what makes it go out, and who gets it. You can switch an email off, change who receives a staff alert, preview it and change its wording. What <em>triggers</em> an email is part of the booking system and can’t be changed here.</p>

<?php foreach (['guest' => 'Emails to guests', 'staff' => 'Emails to the team'] as $aud => $title): ?>
<h2 class="em-h2"><?= e($title) ?></h2>
<div class="em-grid">
  <?php foreach ($grouped[$aud] as $key):
    $t  = email_template($key);
    $st = $stats[$key] ?? null;
    $on = email_template_enabled($key);
    $ov = email_recipient_override($key);
    $locked = !empty($t['locked']);
  ?>
  <div class="card em-card<?= $on ? '' : ' is-off' ?>" id="<?= e($key) ?>">
    <div class="card__head">
      <span class="card__title"><?= e($t['name']) ?></span>
      <?php if ($locked): ?>
        <span class="badge badge--grey" data-tip="<?= e($t['note'] ?? 'Always on.') ?>">Always on</span>
      <?php else: ?>
        <form method="POST" action="/admin/emails.php" class="em-toggle">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="key" value="<?= e($key) ?>">
          <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
          <label class="togglerow" data-tip="<?= $on ? 'Switch this email off' : 'Switch this email on' ?>"><span class="toggle"><input type="checkbox" <?= $on ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="<?= $on ? 'On' : 'Off' ?>"><span class="toggle-slider"></span></span><span><?= $on ? 'On' : 'Off' ?></span></label>
        </form>
      <?php endif; ?>
    </div>
    <div class="card__body em-body">
      <div class="em-topic"><?= e($t['topic']) ?></div>
      <dl class="em-dl">
        <dt>Sent when</dt><dd><?= e($t['trigger']) ?></dd>
        <dt>Goes to</dt><dd><?php if ($aud === 'staff' && !$locked): ?><?= e($ov ? implode(', ', $ov) : $staffTo) ?><?php if (!$ov): ?> <span class="text-muted">(Settings → notification address)</span><?php endif; ?><?php else: ?><?= e($t['recipients']) ?><?php endif; ?></dd>
        <?php if (!empty($t['note']) && !$locked): ?><dt>Note</dt><dd><?= e($t['note']) ?></dd><?php endif; ?>
      </dl>
      <div class="em-stats">
        <?php if ($logOn): ?>
        <span><strong><?= (int)($st['sent_30'] ?? 0) ?></strong> sent in 30 days</span>
        <?php if ((int)($st['failed_30'] ?? 0) > 0): ?><a href="/admin/email-log.php?template=<?= e($key) ?>&status=problems" class="em-bad"><strong><?= (int)$st['failed_30'] ?></strong> failed / bounced</a><?php endif; ?>
        <?php if ((int)($st['held_30'] ?? 0) > 0): ?><a href="/admin/email-log.php?template=<?= e($key) ?>&status=skipped" class="text-muted"><strong><?= (int)$st['held_30'] ?></strong> not sent</a><?php endif; ?>
        <span class="text-muted">Last sent: <?= !empty($st['last_sent']) ? e(date('j M Y, H:i', strtotime((string)$st['last_sent']))) : 'never' ?></span>
        <?php endif; ?>
      </div>
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
      <div class="em-actions">
        <a href="/admin/email-preview.php?key=<?= e($key) ?>" class="btn-outline btn-sm"><?= admin_icon('eye', 14) ?> Preview</a>
        <a href="/admin/email-edit.php?key=<?= e($key) ?>" class="btn-outline btn-sm"><?= admin_icon('edit', 14) ?> Edit wording</a>
        <?php if ($logOn): ?><a href="/admin/email-log.php?template=<?= e($key) ?>" class="btn-outline btn-sm"><?= admin_icon('inbox', 14) ?> Log</a><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<style>
.em-h2{font-size:15px;margin:22px 0 10px;color:var(--text)}
.em-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr));gap:14px}
.em-card.is-off{opacity:.78}
.em-card .card__head{display:flex;justify-content:space-between;align-items:center;gap:10px}
.em-toggle{margin:0}
.em-body{padding:14px 18px;display:flex;flex-direction:column;gap:12px}
.em-topic{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.em-dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:6px 12px;margin:0;font-size:13px}
.em-dl dt{color:var(--muted);white-space:nowrap}.em-dl dd{margin:0;overflow-wrap:anywhere}
.em-stats{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12.5px}
.em-stats a{text-decoration:none}.em-bad{color:var(--red,#b42318)}
.em-rcpt summary{cursor:pointer;font-size:12.5px;color:var(--brand)}
.em-rcpt-form{display:flex;gap:8px;margin-top:8px}.em-rcpt-form .inp{flex:1;min-width:0}
.em-actions{display:flex;flex-wrap:wrap;gap:8px}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
