<?php
/**
 * Edit an email's wording (owner-only).
 *
 * Subject / heading / intro / closing note, with {{placeholders}} from a fixed
 * list per email (unknown ones are refused on save), a live side-by-side
 * preview through the real sender, "Reset to default" and a version history
 * with restore. Plain text only — **bold** is the one formatting mark. The
 * booking details, buttons and links inside the email stay system-rendered.
 *
 * ?venue=<id> edits one property's wording (only for emails that know their
 * property); blank fields fall through to the all-properties wording.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/icons.php';
require_owner();

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$key = (string)($src['key'] ?? '');
$t   = email_template($key);
if (!$t) { header('Location: /admin/emails.php'); exit; }

$venueAware = in_array($key, EMAIL_VENUE_AWARE, true);
$venues = [];
try { $venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll(); } catch (Throwable $e) {}
$venue = $venueAware ? ((int)($src['venue'] ?? 0) ?: null) : null;
if ($venue && !in_array($venue, array_map(fn($v) => (int)$v['id'], $venues), true)) $venue = null;
$venueName = '';
foreach ($venues as $v) if ((int)$v['id'] === $venue) $venueName = (string)$v['name'];

$self = '/admin/email-edit.php?key=' . rawurlencode($key) . ($venue ? '&venue=' . $venue : '');
$supported = email_templates_supported();
$errors = [];
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $uid = (int)($_SESSION['admin_id'] ?? 0) ?: null;
    try {
        if (!$supported) throw new RuntimeException('Run the add_email_templates migration first (Admin → Migrations).');
        if ($act === 'save') {
            $posted = [];
            foreach (array_keys($t['fields']) as $f) $posted[$f] = (string)($_POST[$f] ?? '');
            $errors = email_validate_fields($key, $posted);
            if (!$errors) {
                email_template_save($key, $venue, $posted, $uid);
                audit_log('email.wording', 'email_template', 0, $key . ($venue ? " (venue {$venue})" : ''));
                $_SESSION['emails_flash'] = ['type' => 'success', 'msg' => 'Saved. The next “' . $t['name'] . '” email uses this wording.'];
                header('Location: ' . $self); exit;
            }
        } elseif ($act === 'reset') {
            email_template_reset($key, $venue, $uid);
            audit_log('email.wording_reset', 'email_template', 0, $key . ($venue ? " (venue {$venue})" : ''));
            $_SESSION['emails_flash'] = ['type' => 'success', 'msg' => $venue ? 'This property now uses the all-properties wording.' : 'Back to the built-in wording.'];
            header('Location: ' . $self); exit;
        } elseif ($act === 'restore') {
            $ver = db_query('SELECT * FROM email_template_versions WHERE id = :id AND template_key = :k AND COALESCE(venue_id, 0) = :v',
                            [':id' => (int)($_POST['version'] ?? 0), ':k' => $key, ':v' => (int)$venue])->fetch();
            if (!$ver) throw new InvalidArgumentException('That version isn’t available.');
            if ($ver['action'] === 'reset') {
                email_template_reset($key, $venue, $uid);
            } else {
                $fields = [];
                foreach (array_keys($t['fields']) as $f) $fields[$f] = (string)($ver[$f] ?? '');
                email_template_save($key, $venue, $fields, $uid, 'restore');
            }
            audit_log('email.wording_restore', 'email_template', 0, $key . ' v' . (int)$ver['id']);
            $_SESSION['emails_flash'] = ['type' => 'success', 'msg' => 'Restored the version from ' . date('j M Y, H:i', strtotime((string)$ver['saved_at'])) . '.'];
            header('Location: ' . $self); exit;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        $_SESSION['emails_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
        if ($act !== 'save' || !$posted) { header('Location: ' . $self); exit; }
    }
}

$flash = $_SESSION['emails_flash'] ?? null; unset($_SESSION['emails_flash']);
$row   = $supported ? email_template_override_row($key, $venue) : null;
// What the form shows: posted (after an error) → saved at this scope → what the email currently says.
$value = function (string $f) use ($posted, $row, $key, $venue): string {
    if ($posted !== null) return (string)($posted[$f] ?? '');
    if ($row && trim((string)($row[$f] ?? '')) !== '') return (string)$row[$f];
    return email_field_raw($key, $f, $venue);
};
$versions = $supported ? email_template_versions($key, $venue) : [];
$ph = email_allowed_placeholders($key);
$labels = [
    'subject'     => ['Subject', 'The email’s subject line.'],
    'heading'     => ['Heading', 'The big title at the top of the email.'],
    'intro'       => ['Opening paragraph', 'The first paragraph, under “Dear …”. **Bold** works.'],
    'footer_note' => ['Closing note', 'The small note near the end (e.g. how to reach us).'],
];
$fixedNote = match ($key) {
    'admin_reply' => 'The [TSR-…] tag is always added after the subject so the guest’s answer threads back.',
    default       => 'Booking details, buttons and links are filled in by the system and can’t be edited.',
};

$pageTitle  = 'Edit · ' . $t['name'];
$activeMenu = 'emails';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($t['name']) ?></h1>
  <span style="display:inline-flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/emails.php#<?= e($key) ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 14) ?> All emails</a>
    <a href="/admin/email-preview.php?key=<?= e($key) ?><?= $venue ? '&venue=' . $venue : '' ?>" class="btn-outline btn-sm"><?= admin_icon('eye', 14) ?> Preview &amp; test</a>
  </span>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$supported): ?><div class="alert alert--error">Editing needs the <code>add_email_templates.sql</code> migration (Admin → Migrations). You can already preview the built-in wording on the right.</div><?php endif; ?>

<div class="ee-wrap">
  <div class="ee-col">
    <div class="card">
      <div class="card__body" style="padding:18px">
        <p class="text-muted" style="font-size:13px;margin:0 0 14px">Sent when: <?= e($t['trigger']) ?></p>

        <?php if ($venueAware && $venues): ?>
        <form method="GET" action="/admin/email-edit.php" class="ee-scope">
          <input type="hidden" name="key" value="<?= e($key) ?>">
          <span class="text-muted" style="font-size:12.5px">Wording for</span>
          <select name="venue" class="filter-select" onchange="this.form.submit()" aria-label="Which property">
            <option value="0">All properties</option>
            <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $venue === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?> only</option><?php endforeach; ?>
          </select>
        </form>
        <?php if ($venue): ?><p class="text-muted" style="font-size:12px;margin:6px 0 0">Only <?= e($venueName) ?>’s emails use this. A field left the same as the all-properties wording keeps following it.</p><?php endif; ?>
        <?php endif; ?>

        <form method="POST" action="/admin/email-edit.php" id="eeForm" class="ee-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="key" value="<?= e($key) ?>">
          <?php if ($venue): ?><input type="hidden" name="venue" value="<?= $venue ?>"><?php endif; ?>

          <?php foreach (EMAIL_TEMPLATE_FIELDS as $f): if (!array_key_exists($f, $t['fields'])) continue; [$lbl, $help] = $labels[$f]; ?>
          <div class="ee-field">
            <label for="ee-<?= $f ?>"><?= e($lbl) ?></label>
            <?php if ($f === 'intro' || $f === 'footer_note'): ?>
            <textarea id="ee-<?= $f ?>" name="<?= $f ?>" class="inp inp--area" rows="<?= $f === 'intro' ? 5 : 3 ?>" data-ee-field><?= e($value($f)) ?></textarea>
            <?php else: ?>
            <input id="ee-<?= $f ?>" name="<?= $f ?>" type="text" class="inp" value="<?= e($value($f)) ?>" data-ee-field>
            <?php endif; ?>
            <div class="ee-help"><?= e($help) ?> <span class="ee-default">Built-in: “<?= e((string)$t['fields'][$f]) ?>”</span></div>
            <div class="field-error" data-ee-error="<?= $f ?>"><?= e($errors[$f] ?? '') ?></div>
          </div>
          <?php endforeach; ?>

          <div class="ee-ph">
            <div class="ee-ph__title">Insert a placeholder <span class="text-muted">(click — it goes where your cursor is)</span></div>
            <div class="ee-ph__chips">
              <?php foreach ($ph as $name => $desc): ?>
              <button type="button" class="optchip" data-ph="<?= e($name) ?>" data-tip="<?= e($desc) ?>">{{<?= e($name) ?>}}</button>
              <?php endforeach; ?>
            </div>
          </div>
          <p class="text-muted" style="font-size:12px;margin:0"><?= e($fixedNote) ?></p>

          <div class="ee-actions">
            <button type="submit" class="btn-primary btn-sm"<?= $supported ? '' : ' disabled' ?>><?= admin_icon('check', 14) ?> Save wording</button>
          </div>
        </form>
        <?php if ($row): ?>
        <form method="POST" action="/admin/email-edit.php" style="margin-top:10px">
          <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="key" value="<?= e($key) ?>">
          <?php if ($venue): ?><input type="hidden" name="venue" value="<?= $venue ?>"><?php endif; ?>
          <button type="submit" class="btn-outline btn-sm" data-confirm="<?= $venue ? 'Remove this property’s wording? It will use the all-properties wording again.' : 'Go back to the built-in wording? Your version stays in the history below.' ?>"><?= admin_icon('rotate', 14) ?> <?= $venue ? 'Use the all-properties wording' : 'Reset to default' ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($versions): ?>
    <div class="card" style="margin-top:14px">
      <div class="card__head"><span class="card__title">History</span></div>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>When</th><th>By</th><th>What</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($versions as $i => $v): ?>
          <tr>
            <td style="font-size:12.5px;white-space:nowrap"><?= e(date('j M Y, H:i', strtotime((string)$v['saved_at']))) ?></td>
            <td style="font-size:12.5px"><?= e($v['saved_by_name'] ?: '—') ?></td>
            <td style="font-size:12.5px"><?= e(['save' => 'Saved', 'reset' => 'Reset to default', 'restore' => 'Restored'][$v['action']] ?? $v['action']) ?>
              <?php if ($v['action'] !== 'reset' && ($v['subject'] ?? '') !== ''): ?><div class="text-muted" style="font-size:11.5px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string)$v['subject']) ?></div><?php endif; ?></td>
            <td style="text-align:right"><?php if ($i > 0): ?>
              <form method="POST" action="/admin/email-edit.php" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="version" value="<?= (int)$v['id'] ?>">
                <?php if ($venue): ?><input type="hidden" name="venue" value="<?= $venue ?>"><?php endif; ?>
                <button type="submit" class="btn-icon btn-icon--outline" data-tip="Restore this version" aria-label="Restore this version" data-confirm="Restore this version of the wording?"><?= admin_icon('rotate') ?></button>
              </form>
            <?php else: ?><span class="text-muted" style="font-size:12px">current</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="ee-col">
    <div class="card ee-preview">
      <div class="card__head"><span class="card__title">Live preview</span><span class="text-muted" style="font-size:12px">sample data</span></div>
      <div class="ee-subject"><span class="text-muted">Subject</span> <strong id="eeSubject"></strong></div>
      <iframe id="eeFrame" class="ee-frame" sandbox="" referrerpolicy="no-referrer" title="Live preview"></iframe>
    </div>
  </div>
</div>

<style>
.ee-wrap{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start}
@media(max-width:1100px){.ee-wrap{grid-template-columns:minmax(0,1fr)}}
.ee-scope{display:flex;gap:10px;align-items:center;margin:0}
.ee-form{display:flex;flex-direction:column;gap:16px;margin-top:16px}
.ee-field label{display:block;font-weight:600;font-size:13px;margin-bottom:6px}
.ee-field .inp{width:100%}
.ee-help{font-size:12px;color:var(--muted);margin-top:5px;line-height:1.45}
.ee-default{display:block;margin-top:2px;overflow-wrap:anywhere}
.field-error{color:var(--red,#b42318);font-size:12px;margin-top:4px}
.field-error:empty{display:none}
.ee-ph__title{font-size:12.5px;font-weight:600;margin-bottom:8px}
.ee-ph__chips{display:flex;flex-wrap:wrap;gap:6px}
.ee-ph__chips .optchip{font-family:ui-monospace,monospace;font-size:12px;cursor:pointer}
.ee-actions{display:flex;gap:10px}
.ee-preview{position:sticky;top:12px}
.ee-subject{padding:10px 18px;border-bottom:1px solid var(--border);font-size:13px;overflow-wrap:anywhere}
.ee-frame{display:block;width:100%;height:720px;border:0;background:#f0f4f5;border-radius:0 0 12px 12px}
</style>
<script>
(function () {
  var form = document.getElementById('eeForm'), frame = document.getElementById('eeFrame'), subj = document.getElementById('eeSubject');
  if (!form) return;
  var last = form.querySelector('[data-ee-field]'), timer = null, seq = 0;
  form.querySelectorAll('[data-ee-field]').forEach(function (el) {
    ['focus', 'click', 'keyup', 'select'].forEach(function (ev) { el.addEventListener(ev, function () { last = el; }); });
    el.addEventListener('input', function () { last = el; schedule(); });
  });
  document.querySelectorAll('[data-ph]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!last) return;
      var tok = '{{' + b.getAttribute('data-ph') + '}}';
      var s = last.selectionStart != null ? last.selectionStart : last.value.length;
      var e = last.selectionEnd != null ? last.selectionEnd : s;
      last.value = last.value.slice(0, s) + tok + last.value.slice(e);
      last.focus(); last.setSelectionRange(s + tok.length, s + tok.length);
      schedule();
    });
  });
  function schedule() { clearTimeout(timer); timer = setTimeout(render, 450); }
  function render() {
    var fd = new FormData(form), my = ++seq;
    fd.set('action', 'render');
    fetch('/admin/email-preview.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (my !== seq || !j) return;
        subj.textContent = j.subject || '—';
        frame.srcdoc = j.html || '';
        form.querySelectorAll('[data-ee-error]').forEach(function (el) { el.textContent = (j.errors || {})[el.getAttribute('data-ee-error')] || ''; });
      })
      .catch(function () {});
  }
  render();
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
