<?php
declare(strict_types=1);
/** Page content — the list of pages that expose editable slots. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/page-content.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_owner();

$registry = page_content_registry();
$pageTitle  = 'Page Content';
$activeMenu = 'pages';
$pageDocIcon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/></svg>';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1 style="display:inline-flex;align-items:center;gap:10px"><?= $pageDocIcon ?> Page Content</h1></div>

<?php if (!page_content_supported()): ?>
<div class="alert alert--error" style="margin-bottom:16px">
  Editing is unavailable until the <code>add_page_content.sql</code> migration is applied.
  The pages below still render their built-in copy, so nothing is broken in the meantime.
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
          <td><span class="badge badge--grey"><?= count($slots) ?> field<?= count($slots) === 1 ? '' : 's' ?></span></td>
          <td><?= $custom ? '<span class="badge badge--green">' . (int)$custom . ' edited</span>' : '<span class="badge badge--grey">All default</span>' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="/admin/page-edit.php?page=<?= e($key) ?>" class="btn-primary btn-sm"><?= admin_icon('edit', 14) ?> Edit</a>
            <?php if (!empty($def['url'])): ?><a href="<?= e($def['url']) ?>" target="_blank" rel="noopener" class="btn-outline btn-sm"><?= admin_icon('external-link', 14) ?> View</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
