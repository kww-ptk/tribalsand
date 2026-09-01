<?php
declare(strict_types=1);
/**
 * Edit one page's content. Every field is rendered from page_content_registry(),
 * so adding a page (or a slot) needs no changes to this file.
 *
 * Leaving a field empty deletes the override and the page falls back to the
 * copy it ships with — that is why every field shows its default as placeholder.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/page-content.php';
require_once __DIR__ . '/../includes/admin-media-picker.php';
require_login();
require_owner();

$page = preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['page'] ?? ''));
$def  = page_content_registry()[$page] ?? null;
if (!$def) {
    http_response_code(404);
    $pageTitle = '404'; $activeMenu = 'pages';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Unknown page. <a href="/admin/pages.php">Back to Page Content</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

$flash = $_SESSION['pc_flash'] ?? null;
unset($_SESSION['pc_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!page_content_supported()) {
        $_SESSION['pc_flash'] = ['type'=>'error','msg'=>'Run the add_page_content.sql migration first.'];
    } else {
        $n = 0;
        foreach (page_slots($page) as $slot => $sdef) {
            if (!array_key_exists($slot, $_POST)) continue;      // only posted fields
            $val = is_scalar($_POST[$slot]) ? trim((string)$_POST[$slot]) : '';
            // An unchanged field equal to the default stores nothing, so the page
            // keeps tracking the shipped copy if it is ever revised in code.
            if ($val === trim((string)($sdef['default'] ?? ''))) $val = '';
            page_content_save($page, $slot, $val, $_SESSION['admin_id'] ?? null);
            $n++;
        }
        audit_log('page_content.save', 'page', 0, $page . " ({$n} fields)");
        $_SESSION['pc_flash'] = ['type'=>'success','msg'=>'Saved. Changes are live now.'];
    }
    header('Location: /admin/page-edit.php?page=' . urlencode($page));
    exit;
}

$values = page_content_values($page);
$pageTitle  = $def['label'] . ' — Page Content';
$activeMenu = 'pages';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($def['label']) ?></h1>
  <div class="actions">
    <a href="/admin/pages.php" class="btn-outline btn-sm">Back</a>
    <?php if (!empty($def['url'])): ?><a href="<?= e($def['url']) ?>" target="_blank" class="btn-outline btn-sm">View page</a><?php endif; ?>
  </div>
</div>

<?php if ($flash): ?>
<div class="card" style="border-left:4px solid <?= $flash['type']==='error' ? 'var(--red,#dc2626)' : 'var(--green,#16a34a)' ?>;margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;font-size:14px"><?= e($flash['msg']) ?></div>
</div>
<?php endif; ?>

<form method="POST" action="/admin/page-edit.php?page=<?= e($page) ?>">
  <?= csrf_field() ?>
  <?php foreach ($def['groups'] as $group => $slots): ?>
  <div class="card">
    <div class="card__head"><span class="card__title"><?= e($group) ?></span></div>
    <div class="card__body" style="padding:20px;display:flex;flex-direction:column;gap:18px">
      <?php foreach ($slots as $slot => $s):
        $cur  = $values[$slot] ?? '';
        $dflt = (string)($s['default'] ?? ''); ?>
        <?php if ($s['type'] === 'image'): ?>
          <?php media_picker_field($slot, $cur, $s['label'], $s['hint'] ?? '', $dflt); ?>
        <?php else: ?>
        <div>
          <label class="detail-item__label" for="f_<?= e($slot) ?>"><?= e($s['label']) ?></label>
          <?php if ($s['type'] === 'textarea'): ?>
            <textarea id="f_<?= e($slot) ?>" name="<?= e($slot) ?>" rows="3" class="inp" style="width:100%" placeholder="<?= e($dflt) ?>"><?= e($cur) ?></textarea>
          <?php else: ?>
            <input id="f_<?= e($slot) ?>" type="text" name="<?= e($slot) ?>" value="<?= e($cur) ?>" class="inp" style="width:100%" placeholder="<?= e($dflt) ?>">
          <?php endif; ?>
          <div class="field-hint">
            <?php if (!empty($s['hint'])): ?><?= $s['hint'] ?><br><?php endif; ?>
            <?php if ($s['type'] === 'html'): ?><strong>Accepts HTML</strong> — <?php endif; ?>
            Leave empty to use the built-in text.
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <div style="margin:18px 0 40px"><button type="submit" class="btn-primary">Save changes</button></div>
</form>

<?php media_picker_modal(); ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
