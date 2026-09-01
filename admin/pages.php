<?php
declare(strict_types=1);
/** Page content — the list of pages that expose editable slots. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/page-content.php';
require_login();
require_owner();

$registry = page_content_registry();
$pageTitle  = 'Page Content';
$activeMenu = 'pages';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Page Content</h1></div>

<?php if (!page_content_supported()): ?>
<div class="card" style="border-left:4px solid var(--red,#dc2626);margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;font-size:14px">
    Editing is unavailable until the <code>add_page_content.sql</code> migration is applied.
    The pages below still render their built-in copy, so nothing is broken in the meantime.
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title">Editable pages</span>
    <span class="text-muted" style="font-size:12px">Anything not listed here is still coded in the template</span>
  </div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Page</th><th>Editable fields</th><th>Customised</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($registry as $key => $def):
        $slots  = page_slots($key);
        $custom = count(page_content_values($key)); ?>
        <tr>
          <td><strong><?= e($def['label']) ?></strong><div class="text-muted" style="font-size:12px"><?= e($def['url'] ?? '') ?></div></td>
          <td class="text-muted"><?= count($slots) ?></td>
          <td class="text-muted"><?= $custom ?: '<span class="text-muted">— all default</span>' ?></td>
          <td style="text-align:right">
            <a href="/admin/page-edit.php?page=<?= e($key) ?>" class="btn-primary btn-sm">Edit</a>
            <?php if (!empty($def['url'])): ?><a href="<?= e($def['url']) ?>" target="_blank" class="btn-outline btn-sm">View</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
