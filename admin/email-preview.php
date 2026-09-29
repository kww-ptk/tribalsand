<?php
/**
 * Preview one email with sample data (owner-only).
 *
 * The preview calls the email's REAL sender in capture mode — nothing is sent or
 * logged — so it shows exactly what a guest would get with the current wording.
 *
 *   GET  ?key=<template>[&venue=<id>]    the preview page (desktop / mobile width)
 *   POST action=render (+ fields)        JSON {subject, html} — the editor's live preview
 *   POST action=test                     send the sample to the signed-in owner, logged
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/icons.php';
require_owner();

$src   = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$key   = (string)($src['key'] ?? '');
$t     = email_template($key);
$venue = (int)($src['venue'] ?? 0) ?: null;
if (!$t) { header('Location: /admin/emails.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'render') {
        header('Content-Type: application/json');
        $draft = [];
        foreach (EMAIL_TEMPLATE_FIELDS as $f) if (isset($_POST[$f])) $draft[$f] = (string)$_POST[$f];
        $errors = email_validate_fields($key, $draft);
        foreach ($errors as $f => $_) unset($draft[$f]);   // preview what is valid; show the errors beside it
        $m = email_render_sample($key, $venue, $draft);
        echo json_encode(['ok' => (bool)$m, 'subject' => $m['subject'] ?? '', 'html' => $m['html'] ?? '', 'errors' => $errors]);
        exit;
    }

    if ($act === 'test') {
        $me = current_admin();
        $to = trim((string)($me['email'] ?? ''));
        $m  = email_render_sample($key, $venue);
        if (!$m || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['emails_flash'] = ['type' => 'error', 'msg' => 'Couldn’t send a test — your account has no valid email address.'];
        } else {
            $r = mail_send($key, ['to' => $to, 'subject' => '[TEST] ' . $m['subject'], 'text' => $m['text'], 'html' => $m['html'], 'reply_to' => $m['reply_to']],
                           ['force' => true, 'trigger' => 'test', 'note' => 'Test send with sample data (Admin → Emails).', 'venue_id' => $venue]);
            $_SESSION['emails_flash'] = $r['ok']
                ? ['type' => 'success', 'msg' => 'Test sent to ' . $to . '. It’s in the email log too.']
                : ['type' => 'error', 'msg' => 'The test didn’t send: ' . ($r['error'] ?: $r['status'])];
        }
        header('Location: /admin/email-preview.php?key=' . rawurlencode($key) . ($venue ? '&venue=' . $venue : ''));
        exit;
    }
    header('Location: /admin/email-preview.php?key=' . rawurlencode($key));
    exit;
}

$flash  = $_SESSION['emails_flash'] ?? null; unset($_SESSION['emails_flash']);
$m      = email_render_sample($key, $venue);
$venues = [];
try { $venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll(); } catch (Throwable $e) {}
$me     = current_admin();

$pageTitle  = 'Preview · ' . $t['name'];
$activeMenu = 'emails';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($t['name']) ?></h1>
  <span style="display:inline-flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/emails.php#<?= e($key) ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 14) ?> All emails</a>
    <a href="/admin/email-edit.php?key=<?= e($key) ?><?= $venue ? '&venue=' . $venue : '' ?>" class="btn-outline btn-sm"><?= admin_icon('edit', 14) ?> Edit wording</a>
  </span>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:14px">
  <div class="card__body ep-bar">
    <div class="ep-meta">
      <div><span class="text-muted">Subject</span> <strong><?= e($m['subject'] ?? '—') ?></strong></div>
      <div class="text-muted" style="font-size:12.5px">Sent when: <?= e($t['trigger']) ?></div>
      <div class="text-muted" style="font-size:12.5px">Replies go to <?= e($m['reply_to'] ?? '—') ?> · Sample data, not a real booking.</div>
    </div>
    <div class="ep-controls">
      <?php if (in_array($key, EMAIL_VENUE_AWARE, true) && $venues): ?>
      <form method="GET" action="/admin/email-preview.php" class="filter-field" style="margin:0">
        <input type="hidden" name="key" value="<?= e($key) ?>">
        <span>Property</span>
        <select name="venue" class="filter-select" onchange="this.form.submit()" aria-label="Preview for property">
          <option value="0">Sample property</option>
          <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $venue === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <div class="ep-width" role="radiogroup" aria-label="Preview width">
        <label class="optchip"><input type="radio" name="ep-w" value="desktop" checked> Desktop</label>
        <label class="optchip"><input type="radio" name="ep-w" value="mobile"> Mobile</label>
      </div>
      <form method="POST" action="/admin/email-preview.php" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="test"><input type="hidden" name="key" value="<?= e($key) ?>">
        <?php if ($venue): ?><input type="hidden" name="venue" value="<?= $venue ?>"><?php endif; ?>
        <button type="submit" class="btn-primary btn-sm" data-confirm="Send this email with sample data to <?= e((string)($me['email'] ?? 'you')) ?>?"><?= admin_icon('send', 14) ?> Send test to me</button>
      </form>
    </div>
  </div>
</div>

<div class="ep-stage">
  <?php if ($m): ?>
  <iframe id="epFrame" class="ep-frame" sandbox="" referrerpolicy="no-referrer" title="Email preview" srcdoc="<?= e((string)$m['html']) ?>"></iframe>
  <?php else: ?>
  <div class="card"><div class="card__body" style="padding:18px">This email couldn’t be previewed.</div></div>
  <?php endif; ?>
</div>

<style>
.ep-bar{padding:14px 18px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center}
.ep-meta{display:flex;flex-direction:column;gap:4px;min-width:0;flex:1 1 320px}
.ep-controls{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ep-width{display:flex;gap:6px}
.ep-stage{display:flex;justify-content:center}
.ep-frame{width:100%;max-width:100%;height:820px;border:1px solid var(--border);border-radius:12px;background:#f0f4f5;transition:width .2s}
.ep-frame.is-mobile{width:390px}
</style>
<script>
document.querySelectorAll('input[name="ep-w"]').forEach(function (r) {
  r.addEventListener('change', function () {
    var f = document.getElementById('epFrame');
    if (f) f.classList.toggle('is-mobile', r.value === 'mobile' && r.checked);
  });
});
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
