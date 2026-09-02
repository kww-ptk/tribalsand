<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/offers.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();
require_owner();

/** Upload an offer image (mirrors admin/nav-menu.php); returns storage key or throws. */
function offer_upload_image(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
    if ($file['size'] > 6 * 1024 * 1024) throw new RuntimeException('Image too large (max 6MB).');
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('Use a JPG, PNG or WebP image.');
    $src = match ($mime) {
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        default      => imagecreatefromjpeg($file['tmp_name']),
    };
    if (!$src) throw new RuntimeException('Could not read that image.');
    $w = imagesx($src); $h = imagesy($src);
    if ($w > 1000) { $nh = (int) round($h * 1000 / $w); $dst = imagecreatetruecolor(1000, $nh); imagecopyresampled($dst, $src, 0, 0, 0, 0, 1000, $nh, $w, $h); imagedestroy($src); $src = $dst; }
    $filename = seo_filename((string)($file['name'] ?? ''), 'offer');
    $tmp_out  = sys_get_temp_dir() . '/' . $filename;
    imagejpeg($src, $tmp_out, 86); imagedestroy($src);
    $stored = storage_put($tmp_out, $filename, 'image/jpeg', 'offers'); @unlink($tmp_out);
    if ($stored === false) throw new RuntimeException('Could not save the image (storage error).');
    return (string) $stored;
}

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$offer = $id ? fetch_offer($id) : null;
$isNew = !$offer;

$success = isset($_GET['saved']) ? 'Offer saved.' : '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && offers_supported()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_offer' && $offer) {
        if (!empty($offer['image_key'])) storage_delete($offer['image_key']);
        db_query('DELETE FROM offers WHERE id = :id', [':id' => $id]);
        audit_log('offer.delete', 'offer', $id, $offer['title']);
        header('Location: /admin/offers.php');
        exit;
    }

    if ($action === 'remove_image' && $offer) {
        if (!empty($offer['image_key'])) storage_delete($offer['image_key']);
        db_query("UPDATE offers SET image_key = NULL, updated_at = now() WHERE id = :id", [':id' => $id]);
        header("Location: /admin/offer-edit.php?id={$id}&saved=1");
        exit;
    }

    if ($action === 'save_offer') {
        $title = trim($_POST['title'] ?? '');
        $cat   = $_POST['category'] ?? 'special';
        if ($title === '')                          $error = 'Title is required.';
        if (!$error && !array_key_exists($cat, offer_categories())) $error = 'Pick a valid category.';

        // Image (optional). The media picker posts a storage key in image_key
        // (an existing library image, or one it just uploaded via admin/media.php).
        $imageKey = $offer['image_key'] ?? null;
        if (isset($_POST['image_key'])) {
            $picked   = trim((string)$_POST['image_key']);
            $imageKey = $picked !== '' ? $picked : null;   // '' = reset to no image
        }
        // Legacy direct file upload still supported (takes precedence if a file was sent).
        if (!$error) {
            try {
                $up = offer_upload_image($_FILES['image'] ?? []);
                if ($up !== '') {
                    if (!empty($offer['image_key'])) storage_delete($offer['image_key']);
                    $imageKey = $up;
                }
            } catch (Throwable $e) { $error = $e->getMessage(); }
        }

        if (!$error) {
            $data = [
                ':title'      => $title,
                ':subtitle'   => trim($_POST['subtitle'] ?? '') ?: null,
                ':category'   => $cat,
                ':badge'      => trim($_POST['badge'] ?? '') ?: null,
                ':body'       => trim($_POST['body'] ?? '') ?: null,
                ':image_key'  => $imageKey,
                ':cta_label'  => trim($_POST['cta_label'] ?? '') ?: null,
                ':cta_url'    => trim($_POST['cta_url'] ?? '') ?: null,
                ':valid_from' => ($_POST['valid_from'] ?? '') !== '' ? $_POST['valid_from'] : null,
                ':valid_to'   => ($_POST['valid_to'] ?? '')   !== '' ? $_POST['valid_to']   : null,
                ':sort_order' => (int)($_POST['sort_order'] ?? 0),
                ':is_pub'     => isset($_POST['is_published']) ? 'TRUE' : 'FALSE',
            ];
            if ($isNew) {
                db_query(
                    "INSERT INTO offers (title,subtitle,category,badge,body,image_key,cta_label,cta_url,valid_from,valid_to,sort_order,is_published)
                     VALUES (:title,:subtitle,:category,:badge,:body,:image_key,:cta_label,:cta_url,:valid_from,:valid_to,:sort_order,:is_pub)",
                    $data
                );
                $id = (int)db()->lastInsertId();
                audit_log('offer.create', 'offer', $id, $title);
            } else {
                $data[':id'] = $id;
                db_query(
                    "UPDATE offers SET title=:title,subtitle=:subtitle,category=:category,badge=:badge,body=:body,
                       image_key=:image_key,cta_label=:cta_label,cta_url=:cta_url,valid_from=:valid_from,valid_to=:valid_to,
                       sort_order=:sort_order,is_published=:is_pub,updated_at=now() WHERE id=:id",
                    $data
                );
                audit_log('offer.save', 'offer', $id, $title);
            }
            header("Location: /admin/offer-edit.php?id={$id}&saved=1");
            exit;
        }
    }

    // Re-read on a validation error so the form re-renders posted state where possible.
    if ($id) $offer = fetch_offer($id);
}

// On error, prefer the just-posted values so the user doesn't lose their edits.
$val = function (string $k, $default = '') use ($offer) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') return $_POST[$k] ?? $default;
    return $offer[$k] ?? $default;
};
// Published state for the checkbox: posted state on error re-render; stored state on
// edit; default TRUE for a brand-new offer.
$pubChecked = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? isset($_POST['is_published'])
    : ($offer ? (bool)$offer['is_published'] : true);

$pageTitle  = $isNew ? 'New Offer' : 'Edit Offer';
$activeMenu = 'offers';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1><?= $isNew ? 'New Offer' : 'Edit Offer' ?></h1>
  <div style="display:flex;gap:10px">
    <a href="/admin/offers.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Offers</a>
  </div>
</div>

<?php if ($success): ?><div class="alert alert--success is-flash"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>
<?php if (!offers_supported()): ?><div class="alert alert--error">Run <code>db/migrations/add_offers.sql</code> first.</div><?php endif; ?>

<form method="POST" action="/admin/offer-edit.php<?= $id ? "?id={$id}" : '' ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_offer">

  <div class="card">
    <div class="card__head"><span class="card__title">Offer details</span></div>
    <div class="card__body" style="padding:20px">
      <div class="form-row">
        <div class="field">
          <label>Title</label>
          <input type="text" name="title" value="<?= e($val('title')) ?>" required placeholder="e.g. Stay 4, pay 3">
        </div>
        <div class="field">
          <label>Subtitle <span class="text-muted">(card sub-line)</span></label>
          <input type="text" name="subtitle" value="<?= e($val('subtitle')) ?>" placeholder="e.g. Any villa, low season">
        </div>
      </div>

      <div class="form-row" style="margin-top:14px">
        <div class="field">
          <label>Category</label>
          <select name="category">
            <?php foreach (offer_categories() as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= (string)$val('category', 'special') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Badge <span class="text-muted">(corner pill)</span></label>
          <input type="text" name="badge" value="<?= e($val('badge')) ?>" maxlength="60" placeholder="e.g. -20% or From $120/night">
        </div>
      </div>

      <div class="field" style="margin-top:14px">
        <label>Body <span class="text-muted">(longer description, optional)</span></label>
        <textarea name="body" rows="3" placeholder="Full details of the offer."><?= e($val('body')) ?></textarea>
      </div>

      <div class="form-row" style="margin-top:14px">
        <div class="field">
          <label>CTA label <span class="text-muted">(optional)</span></label>
          <input type="text" name="cta_label" value="<?= e($val('cta_label')) ?>" placeholder="e.g. View details">
        </div>
        <div class="field">
          <label>CTA URL <span class="text-muted">(optional)</span></label>
          <input type="text" name="cta_url" value="<?= e($val('cta_url')) ?>" placeholder="https://…">
          <span class="field-hint">The homepage card always opens the enquiry modal; this link is stored for a future offers page.</span>
        </div>
      </div>

      <div class="form-row" style="margin-top:14px">
        <div class="field">
          <label>Valid from <span class="text-muted">(optional)</span></label>
          <input type="date" name="valid_from" value="<?= e($val('valid_from')) ?>">
        </div>
        <div class="field">
          <label>Valid to <span class="text-muted">(hidden after this date)</span></label>
          <input type="date" name="valid_to" value="<?= e($val('valid_to')) ?>">
        </div>
      </div>

      <div class="form-row" style="margin-top:14px">
        <div class="field">
          <label>Sort order <span class="text-muted">(lower = first)</span></label>
          <input type="number" name="sort_order" value="<?= e((string)$val('sort_order', '0')) ?>" style="max-width:120px">
        </div>
        <div class="field" style="justify-content:flex-end">
          <label class="toggle-row">
            <input type="checkbox" name="is_published" value="1" <?= $pubChecked ? 'checked' : '' ?>>
            <span>Published (visible on the site)</span>
          </label>
        </div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <div class="card__head"><span class="card__title">Image</span></div>
    <div class="card__body" style="padding:20px">
      <?php
        require_once __DIR__ . '/../includes/admin-media-picker.php';
        media_picker_field('image_key', (string)$val('image_key'), 'Offer image',
                           'Choose an existing image or upload a new one (JPG/PNG/WebP). 16:10 looks best.');
      ?>
      <span class="field-hint" style="margin-top:8px;display:block">Saved when you press <strong>Save</strong> below.</span>
    </div>
  </div>

  <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn-primary"><?= $isNew ? 'Create offer' : 'Save' ?></button>
    <a href="/#offers" class="btn-outline btn-sm" target="_blank" rel="noopener">View homepage strip</a>
  </div>
</form>

<?php if (!$isNew): ?>
<div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap">
  <?php if (!empty($offer['image_key'])): ?>
  <form method="POST" action="/admin/offer-edit.php?id=<?= (int)$id ?>" onsubmit="return confirm('Remove this offer’s image?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove_image">
    <button type="submit" class="btn-outline btn-sm"><?= admin_icon('trash', 14) ?> Remove image</button>
  </form>
  <?php endif; ?>
  <form method="POST" action="/admin/offer-edit.php?id=<?= (int)$id ?>" onsubmit="return confirm('Delete this offer? This cannot be undone.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_offer">
    <button type="submit" class="btn-outline btn-sm" style="color:#b23b3b;border-color:rgba(178,59,59,.4)"><?= admin_icon('trash', 14) ?> Delete offer</button>
  </form>
</div>
<?php endif; ?>

<?php media_picker_modal(); ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
