<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/nav-data.php';
require_once __DIR__ . '/../includes/storage.php';   // storage_put() for nav thumbnails
require_once __DIR__ . '/../includes/admin-media-picker.php';   // "pick from library" for thumbnails
require_login();
require_owner();   // site-wide navigation = owner only

/** Flash helpers (PRG). */
function nav_flash(string $type, string $msg): void { $_SESSION['nav_flash'] = ['type' => $type, 'msg' => $msg]; }
function nav_redirect(): void { header('Location: /admin/nav-menu.php'); exit; }

/** Emit a JSON body and stop (used by the drag-reorder endpoints). */
function nav_json(array $payload): void { header('Content-Type: application/json'); exit(json_encode($payload)); }

/** Decode a posted `order` payload into a clean list of ints. */
function nav_order_ids(): array {
    return array_values(array_filter(array_map('intval', (array) (json_decode($_POST['order'] ?? '[]', true) ?: []))));
}

/** Upload a nav thumbnail (mirrors admin/venue-edit.php); returns storage key or ''. */
function nav_upload_thumb(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
    if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Image too large (max 5MB).');
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('Use a JPG, PNG or WebP image.');
    $src = match ($mime) { 'image/png' => imagecreatefrompng($file['tmp_name']), 'image/webp' => imagecreatefromwebp($file['tmp_name']), default => imagecreatefromjpeg($file['tmp_name']) };
    if (!$src) throw new RuntimeException('Could not read that image.');
    $w = imagesx($src); $h = imagesy($src);
    if ($w > 600) { $nh = (int) round($h * 600 / $w); $dst = imagecreatetruecolor(600, $nh); imagecopyresampled($dst, $src, 0, 0, 0, 0, 600, $nh, $w, $h); imagedestroy($src); $src = $dst; }
    $filename = seo_filename((string)($file['name'] ?? ''), 'nav');
    $tmp_out  = sys_get_temp_dir() . '/' . $filename;
    imagejpeg($src, $tmp_out, 86); imagedestroy($src);
    $stored = storage_put($tmp_out, $filename); @unlink($tmp_out);
    if ($stored === false) throw new RuntimeException('Could not save the image (storage error).');
    return (string) $stored;
}

// Ownership / editability helpers.
$groupItemId = fn(int $gid) => (int) (db_query("SELECT nav_item_id FROM nav_groups WHERE id = :g", [':g' => $gid])->fetchColumn() ?: 0);
$linkGroupId = fn(int $lid) => (int) (db_query("SELECT nav_group_id FROM nav_links WHERE id = :l", [':l' => $lid])->fetchColumn() ?: 0);
$linkItemId  = fn(int $lid) => (int) (db_query("SELECT g.nav_item_id FROM nav_links l JOIN nav_groups g ON g.id = l.nav_group_id WHERE l.id = :l", [':l' => $lid])->fetchColumn() ?: 0);
$itemIsAuto  = fn(int $iid) => (bool) db_query("SELECT auto_source FROM nav_items WHERE id = :i", [':i' => $iid])->fetchColumn();

$LAYOUTS = ['simple' => 'Simple list', 'wide2' => '2 columns', 'wide3' => '3 columns'];
$TAGS    = ['' => 'No tag', 'open' => '· Now Open', 'soon' => '— Soon'];
$ROLES   = ['row' => 'Link', 'footer_link' => 'Footer link', 'cta_button' => 'Button (CTA)'];

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && nav_supported()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        // ---- Reorder (AJAX, drag-and-drop) — respond JSON, no redirect ----
        // Each scope rewrites sort_order from the posted id array. Scoped WHERE
        // clauses stop a payload from touching rows outside the drag list, and
        // the auto (Restaurants) item stays uneditable except for its own slot.
        if ($action === 'item_reorder') {
            foreach (nav_order_ids() as $o => $rid) db_query("UPDATE nav_items SET sort_order = :o WHERE id = :id", [':o' => $o, ':id' => $rid]);
            audit_log('nav.item_reorder', 'nav_item', 0, '');
            nav_json(['ok' => true]);
        } elseif ($action === 'group_reorder') {
            $iid = (int) ($_POST['item_id'] ?? 0);
            if ($iid && !$itemIsAuto($iid)) {
                foreach (nav_order_ids() as $o => $gid) db_query("UPDATE nav_groups SET sort_order = :o WHERE id = :id AND nav_item_id = :i", [':o' => $o, ':id' => $gid, ':i' => $iid]);
            }
            nav_json(['ok' => true]);
        } elseif ($action === 'link_reorder') {
            $gid = (int) ($_POST['group_id'] ?? 0);
            if ($gid && !$itemIsAuto($groupItemId($gid))) {
                foreach (nav_order_ids() as $o => $lid) db_query("UPDATE nav_links SET sort_order = :o WHERE id = :id AND nav_group_id = :g", [':o' => $o, ':id' => $lid, ':g' => $gid]);
            }
            nav_json(['ok' => true]);
        }

        // ---- Items ----
        elseif ($action === 'item_add') {
            $label  = trim($_POST['label'] ?? '');
            $layout = array_key_exists($_POST['layout'] ?? '', $LAYOUTS) ? $_POST['layout'] : 'simple';
            if ($label === '') { nav_flash('error', 'A menu name is required.'); }
            else {
                db_query("INSERT INTO nav_items (label, layout, sort_order) VALUES (:l, :ly, (SELECT COALESCE(MAX(sort_order),0)+1 FROM nav_items))",
                    [':l' => $label, ':ly' => $layout]);
                audit_log('nav.item_add', 'nav_item', 0, $label);
                nav_flash('success', 'Menu added.');
            }
        } elseif ($action === 'item_save') {
            $iid = (int) ($_POST['item_id'] ?? 0);
            if ($iid && !$itemIsAuto($iid)) {
                $label  = trim($_POST['label'] ?? '');
                $layout = array_key_exists($_POST['layout'] ?? '', $LAYOUTS) ? $_POST['layout'] : 'simple';
                db_query("UPDATE nav_items SET label = :l, layout = :ly, is_published = :p WHERE id = :i",
                    [':l' => $label ?: 'Menu', ':ly' => $layout, ':p' => isset($_POST['is_published']) ? 'TRUE' : 'FALSE', ':i' => $iid]);
                nav_flash('success', 'Menu updated.');
            }
        } elseif ($action === 'item_delete') {
            $iid = (int) ($_POST['item_id'] ?? 0);
            if ($iid && !$itemIsAuto($iid)) {
                db_query("DELETE FROM nav_items WHERE id = :i", [':i' => $iid]);   // cascade clears groups + links
                audit_log('nav.item_delete', 'nav_item', $iid, '');
                nav_flash('success', 'Menu deleted.');
            }
        }

        // ---- Groups ----
        elseif ($action === 'group_add') {
            $iid = (int) ($_POST['item_id'] ?? 0);
            if ($iid && !$itemIsAuto($iid)) {
                db_query("INSERT INTO nav_groups (nav_item_id, label, sort_order) VALUES (:i, :l, (SELECT COALESCE(MAX(sort_order),0)+1 FROM nav_groups WHERE nav_item_id = :i))",
                    [':i' => $iid, ':l' => trim($_POST['label'] ?? '') ?: null]);
                nav_flash('success', 'Column added.');
            }
        } elseif ($action === 'group_save') {
            $gid = (int) ($_POST['group_id'] ?? 0);
            if ($gid && !$itemIsAuto($groupItemId($gid))) {
                db_query("UPDATE nav_groups SET label = :l WHERE id = :g", [':l' => trim($_POST['label'] ?? '') ?: null, ':g' => $gid]);
                nav_flash('success', 'Column saved.');
            }
        } elseif ($action === 'group_delete') {
            $gid = (int) ($_POST['group_id'] ?? 0);
            if ($gid && !$itemIsAuto($groupItemId($gid))) {
                db_query("DELETE FROM nav_groups WHERE id = :g", [':g' => $gid]);
                nav_flash('success', 'Column deleted.');
            }
        }

        // ---- Links ----
        elseif ($action === 'link_add') {
            $gid = (int) ($_POST['group_id'] ?? 0);
            if ($gid && !$itemIsAuto($groupItemId($gid))) {
                $label = trim($_POST['label'] ?? '');
                if ($label === '') { nav_flash('error', 'A link label is required.'); }
                else {
                    db_query("INSERT INTO nav_links (nav_group_id, label, href, sort_order) VALUES (:g, :l, :h, (SELECT COALESCE(MAX(sort_order),0)+1 FROM nav_links WHERE nav_group_id = :g))",
                        [':g' => $gid, ':l' => $label, ':h' => trim($_POST['href'] ?? '') ?: '#']);
                    nav_flash('success', 'Link added.');
                }
            }
        } elseif ($action === 'link_save') {
            $lid = (int) ($_POST['link_id'] ?? 0);
            if ($lid && !$itemIsAuto($linkItemId($lid))) {
                $tag  = array_key_exists($_POST['tag'] ?? '', $TAGS) ? $_POST['tag'] : '';
                $role = array_key_exists($_POST['role'] ?? '', $ROLES) ? $_POST['role'] : 'row';
                db_query("UPDATE nav_links SET label=:l, href=:h, sublabel=:s, tag=:t, role=:r, cta_note=:n, target_blank=:b, is_published=:p WHERE id=:i",
                    [
                        ':l' => trim($_POST['label'] ?? '') ?: 'Link',
                        ':h' => trim($_POST['href'] ?? '') ?: '#',
                        ':s' => trim($_POST['sublabel'] ?? '') ?: null,
                        ':t' => $tag,
                        ':r' => $role,
                        ':n' => trim($_POST['cta_note'] ?? '') ?: null,
                        ':b' => isset($_POST['target_blank']) ? 'TRUE' : 'FALSE',
                        ':p' => isset($_POST['is_published']) ? 'TRUE' : 'FALSE',
                        ':i' => $lid,
                    ]);
                nav_flash('success', 'Link saved.');
            }
        } elseif ($action === 'link_delete') {
            $lid = (int) ($_POST['link_id'] ?? 0);
            if ($lid && !$itemIsAuto($linkItemId($lid))) {
                db_query("DELETE FROM nav_links WHERE id = :i", [':i' => $lid]);
                nav_flash('success', 'Link deleted.');
            }
        } elseif ($action === 'link_upload_image') {
            $lid = (int) ($_POST['link_id'] ?? 0);
            if ($lid && !$itemIsAuto($linkItemId($lid))) {
                $key = nav_upload_thumb($_FILES['thumb'] ?? []);
                if ($key !== '') { db_query("UPDATE nav_links SET image_key = :k WHERE id = :i", [':k' => $key, ':i' => $lid]); nav_flash('success', 'Thumbnail updated.'); }
                else nav_flash('error', 'No image was uploaded.');
            }
        } elseif ($action === 'link_remove_image') {
            $lid = (int) ($_POST['link_id'] ?? 0);
            if ($lid && !$itemIsAuto($linkItemId($lid))) {
                db_query("UPDATE nav_links SET image_key = NULL WHERE id = :i", [':i' => $lid]);
                nav_flash('success', 'Thumbnail removed.');
            }
        } elseif ($action === 'link_set_image') {
            // Thumbnail chosen from the media library (picker posts a storage key).
            $lid = (int) ($_POST['link_id'] ?? 0);
            if ($lid && !$itemIsAuto($linkItemId($lid))) {
                $key = trim((string) ($_POST['image_key'] ?? ''));
                db_query("UPDATE nav_links SET image_key = :k WHERE id = :i",
                         [':k' => $key !== '' ? $key : null, ':i' => $lid]);
                nav_flash('success', $key !== '' ? 'Thumbnail updated.' : 'Thumbnail removed.');
            }
        }
    } catch (Throwable $e) {
        nav_flash('error', $e->getMessage());
    }
    nav_redirect();
}

$flash = $_SESSION['nav_flash'] ?? null;
unset($_SESSION['nav_flash']);
$tree  = nav_supported() ? fetch_nav_tree(false) : [];   // include unpublished for admin

$pageTitle  = 'Site Menu';
$activeMenu = 'nav_menu';
include __DIR__ . '/_layout.php';

$csrf     = csrf_field();
$lockIcon = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
$gripSpan = '<span class="nv-grip" data-grip data-tip="Drag to reorder" aria-hidden="true">' . admin_icon('grip', 18) . '</span>';

/** One editable link row, built entirely from house primitives. */
function nv_render_link(array $l, string $csrf, array $TAGS, array $ROLES, string $gripSpan): void {
    $lid = (int) $l['id'];
    $img = nav_img_url($l['image_key'] ?? '');
    ?>
    <div class="nv-link" data-id="<?= $lid ?>">
      <?= $gripSpan ?>

      <div class="nv-link__side">
        <?php if ($img): ?>
          <img class="nv-thumb" src="<?= e($img) ?>" alt="">
        <?php else: ?>
          <div class="nv-thumb nv-thumb--empty" aria-hidden="true"><?= admin_icon('image', 18) ?></div>
        <?php endif; ?>
        <form method="post" action="/admin/nav-menu.php" enctype="multipart/form-data" class="nv-upload">
          <?= $csrf ?><input type="hidden" name="action" value="link_upload_image"><input type="hidden" name="link_id" value="<?= $lid ?>">
          <label class="filefield">
            <span class="btn-outline btn-sm"><?= admin_icon('image', 13) ?> <?= $img ? 'Replace' : 'Add image' ?></span>
            <input type="file" name="thumb" accept="image/jpeg,image/png,image/webp" data-nv-thumb>
          </label>
        </form>
        <form method="post" action="/admin/nav-menu.php" class="nv-mpform">
          <?= $csrf ?><input type="hidden" name="action" value="link_set_image"><input type="hidden" name="link_id" value="<?= $lid ?>">
          <input type="hidden" id="navmp<?= $lid ?>" name="image_key" value="<?= e($l['image_key'] ?? '') ?>">
          <button type="button" class="btn-outline btn-sm" data-mp-open="navmp<?= $lid ?>"><?= admin_icon('image', 13) ?> Library</button>
        </form>
        <?php if ($img): ?>
          <button class="btn-icon btn-icon--danger" type="submit" form="rmimg<?= $lid ?>" data-confirm="Remove this thumbnail?" data-tip="Remove image" aria-label="Remove image"><?= admin_icon('trash', 14) ?></button>
          <form id="rmimg<?= $lid ?>" method="post" action="/admin/nav-menu.php"><?= $csrf ?><input type="hidden" name="action" value="link_remove_image"><input type="hidden" name="link_id" value="<?= $lid ?>"></form>
        <?php endif; ?>
      </div>

      <form method="post" action="/admin/nav-menu.php" class="nv-link__main">
        <?= $csrf ?><input type="hidden" name="action" value="link_save"><input type="hidden" name="link_id" value="<?= $lid ?>">
        <div class="nv-link__grid">
          <label class="nv-fld"><span>Label</span><input type="text" class="inp inp--sm" name="label" value="<?= e($l['label']) ?>"></label>
          <label class="nv-fld"><span>Link (URL)</span><input type="text" class="inp inp--sm" name="href" value="<?= e($l['href']) ?>"></label>
          <label class="nv-fld"><span>Sub-label (optional)</span><input type="text" class="inp inp--sm" name="sublabel" value="<?= e($l['sublabel'] ?? '') ?>"></label>
          <label class="nv-fld"><span>Button note (CTA only)</span><input type="text" class="inp inp--sm" name="cta_note" value="<?= e($l['cta_note'] ?? '') ?>"></label>
          <label class="nv-fld"><span>Tag</span><select name="tag"><?php foreach ($TAGS as $tv => $tl): ?><option value="<?= e($tv) ?>"<?= ($l['tag'] ?? '') === $tv ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select></label>
          <label class="nv-fld"><span>Type</span><select name="role"><?php foreach ($ROLES as $rv => $rl): ?><option value="<?= e($rv) ?>"<?= ($l['role'] ?? 'row') === $rv ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="nv-link__foot">
          <label class="optchip"><input type="checkbox" name="is_published"<?= !empty($l['is_published']) ? ' checked' : '' ?>> Visible</label>
          <label class="optchip"><input type="checkbox" name="target_blank"<?= !empty($l['target_blank']) ? ' checked' : '' ?>> New tab</label>
          <span class="nv-link__actions">
            <button class="btn-icon btn-icon--primary" type="submit" data-tip="Save link" aria-label="Save link"><?= admin_icon('check') ?></button>
            <button class="btn-icon btn-icon--danger" type="submit" form="dellink<?= $lid ?>" data-confirm="Delete this link?" data-tip="Delete link" aria-label="Delete link"><?= admin_icon('trash') ?></button>
          </span>
        </div>
      </form>
      <form id="dellink<?= $lid ?>" method="post" action="/admin/nav-menu.php"><?= $csrf ?><input type="hidden" name="action" value="link_delete"><input type="hidden" name="link_id" value="<?= $lid ?>"></form>
    </div>
    <?php
}
?>

<div class="page-header">
  <h1>Site Menu</h1>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?> is-flash"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if (!nav_supported()): ?>
<div class="card"><div class="card__body card__body--pad">
  <p><strong>The menu tables aren't set up yet.</strong> Run the migration <code>db/migrations/add_nav_menu.sql</code> and the seed <code>db/seeds/seed_nav_menu.php</code>, then reload. Until then the site shows the built-in menu.</p>
</div></div>
<?php else: ?>

<p class="nv-intro text-muted">
  This is the site's top navigation. <strong>Drag the grip</strong> to reorder menus, columns and links; expand a menu to edit its labels, links and thumbnails. Changes are live immediately.
</p>

<style>
.nv-intro{margin:-.25rem 0 1.25rem;max-width:66ch}
.nv-list{display:flex;flex-direction:column;gap:14px}
.nv-item{background:var(--white);border:1.5px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden;transition:border-color .15s,box-shadow .15s,opacity .15s}
.nv-item[open]{border-color:#d8cfc4}
.nv-item.is-dragging{opacity:.4;box-shadow:0 8px 24px rgba(16,47,58,.16);border-color:var(--brand)}
.nv-item.is-auto{border-style:dashed;background:linear-gradient(180deg,rgba(30,92,107,.045),transparent)}
.nv-head{display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;list-style:none}
.nv-head::-webkit-details-marker{display:none}
.nv-head__name{font-weight:600;font-size:15px;color:var(--text)}
.nv-head__meta{font-size:12px;color:var(--muted)}
.nv-head__spacer{margin-left:auto}
.nv-head__chev{color:var(--muted);transition:transform .18s;display:inline-flex}
.nv-item[open] .nv-head__chev{transform:rotate(180deg)}
.nv-grip{display:inline-flex;color:var(--muted);cursor:grab;padding:2px 4px;flex:0 0 auto;user-select:none}
.nv-grip:active{cursor:grabbing}
.nv-lock{display:inline-flex;color:var(--muted);flex:0 0 auto}
.nv-body{padding:2px 16px 18px}
.nv-note{font-size:13px;color:var(--muted);line-height:1.5;margin:8px 2px 0;max-width:60ch}
.nv-editbar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;padding:12px 0 16px;margin-bottom:4px;border-bottom:1px dashed var(--border)}
.nv-cols{display:flex;flex-direction:column;gap:12px;margin-top:14px}
.nv-col{border:1px solid var(--border);border-radius:10px;padding:12px 14px;background:var(--bg);transition:border-color .15s,box-shadow .15s,opacity .15s}
.nv-col.is-dragging{opacity:.4;box-shadow:0 8px 24px rgba(16,47,58,.16);border-color:var(--brand)}
.nv-col__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.nv-col__save{display:inline-flex;align-items:center;gap:8px;flex:1 1 200px;min-width:180px}
.nv-col__save .inp{flex:1 1 auto}
.nv-links{display:flex;flex-direction:column;gap:10px;margin-top:12px}
.nv-link{display:flex;align-items:flex-start;gap:12px;background:var(--white);border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;transition:border-color .15s,box-shadow .15s,opacity .15s}
.nv-link.is-dragging{opacity:.4;box-shadow:0 8px 24px rgba(16,47,58,.16);border-color:var(--brand)}
.nv-link__main{flex:1;min-width:220px}
.nv-link__grid{display:grid;grid-template-columns:repeat(2,minmax(150px,1fr));gap:10px}
.nv-fld{display:flex;flex-direction:column;gap:4px}
.nv-fld>span{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700}
.nv-fld .inp{width:100%}
.nv-link__side{display:flex;flex-direction:column;align-items:center;gap:10px;flex:0 0 auto;width:90px}
.nv-upload{margin:2px 0 0}
.nv-upload .btn-outline{cursor:pointer;white-space:nowrap;padding:7px 12px;gap:6px}
.nv-mpform{margin:0}
.nv-mpform .btn-outline{cursor:pointer;white-space:nowrap;padding:7px 12px;gap:6px}
.nv-thumb{width:72px;height:52px;object-fit:cover;border:1px solid var(--border);border-radius:6px;background:var(--bg)}
.nv-thumb--empty{display:flex;align-items:center;justify-content:center;color:var(--muted)}
.nv-link__foot{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px}
.nv-link__actions{display:inline-flex;gap:8px;margin-left:auto}
.nv-addrow{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)}
.nv-addrow--links{margin-top:12px}
@media (max-width:640px){
  .nv-link{flex-wrap:wrap}
  .nv-link__grid{grid-template-columns:1fr}
  .nv-link__side{flex-direction:row;width:auto;flex-wrap:wrap}
}
</style>

<div class="nv-list" id="nv-items">
  <?php foreach ($tree as $item): $iid = (int) $item['id']; $auto = (string) ($item['auto_source'] ?? ''); ?>
  <details class="nv-item<?= $auto ? ' is-auto' : '' ?>" data-id="<?= $iid ?>">
    <summary class="nv-head">
      <?= $gripSpan ?>
      <?php if ($auto): ?><span class="nv-lock" aria-hidden="true"><?= $lockIcon ?></span><?php endif; ?>
      <span class="nv-head__name"><?= e($item['label']) ?></span>
      <?php if ($auto): ?>
        <span class="badge badge--blue">Automatic</span>
      <?php else: ?>
        <?php if (empty($item['is_published'])): ?><span class="badge badge--grey">Hidden</span><?php endif; ?>
        <span class="nv-head__meta"><?= count($item['groups']) ?> column<?= count($item['groups']) === 1 ? '' : 's' ?></span>
      <?php endif; ?>
      <span class="nv-head__spacer"></span>
      <span class="nv-head__chev"><?= admin_icon('chevron-down', 18) ?></span>
    </summary>

    <div class="nv-body">
      <?php if ($auto): ?>
        <p class="nv-note">This menu is filled automatically from your published restaurant menus. You can drag it to a new position in the bar, but its contents are managed for you.</p>
      <?php else: ?>

        <form method="post" action="/admin/nav-menu.php" class="nv-editbar">
          <?= $csrf ?><input type="hidden" name="action" value="item_save"><input type="hidden" name="item_id" value="<?= $iid ?>">
          <label class="nv-fld" style="flex:1 1 200px;min-width:180px"><span>Menu name</span><input type="text" class="inp inp--sm" name="label" value="<?= e($item['label']) ?>"></label>
          <label class="nv-fld"><span>Layout</span><select name="layout"><?php foreach ($LAYOUTS as $lv => $ll): ?><option value="<?= e($lv) ?>"<?= ($item['layout'] ?? 'simple') === $lv ? ' selected' : '' ?>><?= e($ll) ?></option><?php endforeach; ?></select></label>
          <label class="optchip"><input type="checkbox" name="is_published"<?= !empty($item['is_published']) ? ' checked' : '' ?>> Visible</label>
          <button class="btn-icon btn-icon--primary" type="submit" data-tip="Save menu" aria-label="Save menu"><?= admin_icon('check') ?></button>
          <button class="btn-icon btn-icon--danger" type="submit" form="delitem<?= $iid ?>" data-confirm="Delete the whole &ldquo;<?= e($item['label']) ?>&rdquo; menu and all its links?" data-tip="Delete menu" aria-label="Delete menu"><?= admin_icon('trash') ?></button>
        </form>
        <form id="delitem<?= $iid ?>" method="post" action="/admin/nav-menu.php"><?= $csrf ?><input type="hidden" name="action" value="item_delete"><input type="hidden" name="item_id" value="<?= $iid ?>"></form>

        <div class="nv-cols" data-item="<?= $iid ?>">
          <?php foreach ($item['groups'] as $g): $gid = (int) $g['id']; ?>
          <div class="nv-col" data-id="<?= $gid ?>">
            <div class="nv-col__head">
              <?= $gripSpan ?>
              <form method="post" action="/admin/nav-menu.php" class="nv-col__save">
                <?= $csrf ?><input type="hidden" name="action" value="group_save"><input type="hidden" name="group_id" value="<?= $gid ?>">
                <input type="text" class="inp inp--sm" name="label" value="<?= e($g['label'] ?? '') ?>" placeholder="Column heading (optional)">
                <button class="btn-icon btn-icon--primary" type="submit" data-tip="Save heading" aria-label="Save heading"><?= admin_icon('check') ?></button>
              </form>
              <button class="btn-icon btn-icon--danger" type="submit" form="delgrp<?= $gid ?>" data-confirm="Delete this column and all its links?" data-tip="Delete column" aria-label="Delete column"><?= admin_icon('trash') ?></button>
              <form id="delgrp<?= $gid ?>" method="post" action="/admin/nav-menu.php"><?= $csrf ?><input type="hidden" name="action" value="group_delete"><input type="hidden" name="group_id" value="<?= $gid ?>"></form>
            </div>

            <div class="nv-links" data-group="<?= $gid ?>">
              <?php foreach ($g['links'] as $l) nv_render_link($l, $csrf, $TAGS, $ROLES, $gripSpan); ?>
            </div>

            <form method="post" action="/admin/nav-menu.php" class="nv-addrow nv-addrow--links">
              <?= $csrf ?><input type="hidden" name="action" value="link_add"><input type="hidden" name="group_id" value="<?= $gid ?>">
              <label class="nv-fld" style="flex:1 1 160px"><span>New link label</span><input type="text" class="inp inp--sm" name="label" placeholder="e.g. Zuri"></label>
              <label class="nv-fld" style="flex:1 1 160px"><span>URL</span><input type="text" class="inp inp--sm" name="href" placeholder="e.g. zuri.php"></label>
              <button class="btn-primary btn-sm" type="submit"><?= admin_icon('plus', 14) ?> Add link</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>

        <form method="post" action="/admin/nav-menu.php" class="nv-addrow">
          <?= $csrf ?><input type="hidden" name="action" value="group_add"><input type="hidden" name="item_id" value="<?= $iid ?>">
          <label class="nv-fld" style="flex:1 1 200px"><span>New column heading (optional)</span><input type="text" class="inp inp--sm" name="label" placeholder="e.g. Destinations"></label>
          <button class="btn-outline btn-sm" type="submit"><?= admin_icon('plus', 14) ?> Add column</button>
        </form>

      <?php endif; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Add a menu</span></div>
  <div class="card__body card__body--pad">
    <form method="post" action="/admin/nav-menu.php" class="nv-addrow" style="margin:0;padding:0;border:0">
      <?= $csrf ?><input type="hidden" name="action" value="item_add">
      <label class="nv-fld" style="flex:1 1 220px"><span>Menu name</span><input type="text" class="inp inp--sm" name="label" placeholder="e.g. Offers"></label>
      <label class="nv-fld"><span>Layout</span><select name="layout"><?php foreach ($LAYOUTS as $lv => $ll): ?><option value="<?= e($lv) ?>"><?= e($ll) ?></option><?php endforeach; ?></select></label>
      <button class="btn-primary btn-sm" type="submit"><?= admin_icon('plus', 15) ?> Add menu</button>
    </form>
  </div>
</div>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>;

  // Reorder feedback via the shared admin toast (defined in _layout_end.php),
  // resolved at call time so it's ready by the time a drag completes.
  function toast(msg, type) { if (typeof window.tsToast === 'function') window.tsToast(msg, type); }

  // Rows are draggable only while a grip is held; a single shared mouseup disarms
  // whichever row was armed (covers a plain grip-click that never became a drag,
  // with no per-drag listener churn). dragend also disarms after a real drag.
  var armed = null;
  document.addEventListener('mouseup', function () { if (armed) { armed.draggable = false; armed = null; } });

  // Handle-driven, nested-safe drag reorder. dragstart/enter/over stopPropagation
  // keeps a nested drag (a link) from bubbling to its column/menu sortable.
  function wireSortable(list, opts) {
    if (!list) return;
    var dragged = null, startOrder = '';
    function rows() {
      return Array.prototype.filter.call(list.children, function (n) {
        return n.nodeType === 1 && n.matches(opts.rowSel);
      });
    }
    function orderKey() { return rows().map(function (x) { return x.getAttribute('data-id'); }).join(','); }
    rows().forEach(function (row) {
      var grip = row.querySelector('[data-grip]');
      if (grip) {
        grip.addEventListener('mousedown', function (e) { e.stopPropagation(); row.draggable = true; armed = row; });
        grip.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); });
      }
      row.addEventListener('dragstart', function (e) { e.stopPropagation(); dragged = row; startOrder = orderKey(); row.classList.add('is-dragging'); });
      row.addEventListener('dragend', function (e) {
        e.stopPropagation(); row.classList.remove('is-dragging'); row.draggable = false;
        // Only persist (and toast) when the order actually changed.
        if (dragged) { dragged = null; if (orderKey() !== startOrder) save(); }
      });
      row.addEventListener('dragover', function (e) { e.preventDefault(); e.stopPropagation(); });
      row.addEventListener('dragenter', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (!dragged || dragged === row || row.parentNode !== list) return;
        var k = rows(), di = k.indexOf(dragged), ri = k.indexOf(row);
        if (di < 0 || ri < 0) return;
        list.insertBefore(dragged, di < ri ? row.nextSibling : row);
      });
    });
    function save() {
      var ids = rows().map(function (x) { return x.getAttribute('data-id'); });
      var fd = new FormData();
      fd.append('action', opts.action);
      if (opts.extraName) fd.append(opts.extraName, list.getAttribute(opts.extraAttr));
      fd.append('order', JSON.stringify(ids));
      fd.append('csrf_token', CSRF);
      fetch('/admin/nav-menu.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { ok: false }; })
        .then(function (d) { toast(d && d.ok ? opts.label + ' reordered' : 'Could not save the new order', d && d.ok ? 'ok' : 'err'); })
        .catch(function () { toast('Could not save the new order', 'err'); });
    }
  }

  wireSortable(document.getElementById('nv-items'), { action: 'item_reorder', rowSel: '.nv-item', label: 'Menus' });
  document.querySelectorAll('.nv-cols').forEach(function (l) {
    wireSortable(l, { action: 'group_reorder', rowSel: '.nv-col', extraName: 'item_id', extraAttr: 'data-item', label: 'Columns' });
  });
  document.querySelectorAll('.nv-links').forEach(function (l) {
    wireSortable(l, { action: 'link_reorder', rowSel: '.nv-link', extraName: 'group_id', extraAttr: 'data-group', label: 'Links' });
  });

  // Thumbnail: submit the upload form as soon as a file is chosen (PRG reload
  // re-renders the row with the stored image).
  document.querySelectorAll('[data-nv-thumb]').forEach(function (inp) {
    inp.addEventListener('change', function () { if (inp.files && inp.files.length) inp.form.submit(); });
  });

  // Library pick: the media picker writes the chosen key into the row's hidden
  // input and fires 'change' — submit that row's form (PRG re-renders the thumb).
  document.querySelectorAll('.nv-mpform input[name="image_key"]').forEach(function (inp) {
    inp.addEventListener('change', function () { if (inp.form) inp.form.submit(); });
  });
})();
</script>

<?php media_picker_modal(); ?>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
