<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$TYPES    = ['villa', 'apartment', 'house', 'land', 'commercial'];
$STATUSES = ['for_sale', 'under_offer', 'sold'];
$TYPE_LABELS = [
    'villa' => 'Villa', 'apartment' => 'Apartment', 'house' => 'House',
    'land' => 'Land', 'commercial' => 'Commercial',
];
$STATUS_LABELS = [
    'for_sale' => 'For Sale', 'under_offer' => 'Under Offer', 'sold' => 'Sold',
];

$id       = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$property = $id ? db_query('SELECT * FROM properties WHERE id = :id', [':id' => $id])->fetch() : null;
$images   = $id ? db_query('SELECT * FROM property_images WHERE property_id = :id ORDER BY sort_order ASC, id ASC', [':id' => $id])->fetchAll() : [];
$isNew    = !$property;

$success = '';
$error   = '';

// ── POST: save details / seo / publish / delete ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'save_details') {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        if (!$slug) $error = 'Slug is required.';

        if (!$error) {
            $features = array_values(array_filter(array_map('trim', explode("\n", $_POST['features'] ?? ''))));
            $type   = in_array($_POST['property_type'] ?? '', $TYPES, true) ? $_POST['property_type'] : 'villa';
            $status = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : 'for_sale';
            $data = [
                ':title'           => trim($_POST['title']           ?? ''),
                ':slug'            => $slug,
                ':location'        => trim($_POST['location']        ?? ''),
                ':property_type'   => $type,
                ':status'          => $status,
                ':price_amount'    => (float)($_POST['price_amount'] ?? 0),
                ':price_currency'  => trim($_POST['price_currency']  ?? 'USD'),
                ':price_qualifier' => trim($_POST['price_qualifier'] ?? ''),
                ':bedrooms'        => (int)($_POST['bedrooms']  ?? 0) ?: null,
                ':bathrooms'       => (int)($_POST['bathrooms'] ?? 0) ?: null,
                ':plot_sqm'        => (int)($_POST['plot_sqm']  ?? 0) ?: null,
                ':built_sqm'       => (int)($_POST['built_sqm'] ?? 0) ?: null,
                ':ref_code'        => trim($_POST['ref_code']    ?? ''),
                ':short_desc'      => trim($_POST['short_desc']  ?? ''),
                ':long_desc'       => trim($_POST['long_desc']   ?? ''),
                ':features_json'   => json_encode($features),
            ];

            if ($isNew) {
                db_query(
                    "INSERT INTO properties (title,slug,location,property_type,status,price_amount,price_currency,price_qualifier,bedrooms,bathrooms,plot_sqm,built_sqm,ref_code,short_desc,long_desc,features_json)
                     VALUES (:title,:slug,:location,:property_type,:status,:price_amount,:price_currency,:price_qualifier,:bedrooms,:bathrooms,:plot_sqm,:built_sqm,:ref_code,:short_desc,:long_desc,:features_json)",
                    $data
                );
                $id = (int)db()->lastInsertId();
                header("Location: /admin/property-edit.php?id={$id}&saved=1");
                exit;
            } else {
                $data[':id'] = $id;
                db_query(
                    "UPDATE properties SET title=:title,slug=:slug,location=:location,property_type=:property_type,
                     status=:status,price_amount=:price_amount,price_currency=:price_currency,price_qualifier=:price_qualifier,
                     bedrooms=:bedrooms,bathrooms=:bathrooms,plot_sqm=:plot_sqm,built_sqm=:built_sqm,ref_code=:ref_code,
                     short_desc=:short_desc,long_desc=:long_desc,features_json=:features_json,updated_at=NOW()
                     WHERE id=:id",
                    $data
                );
                $success = 'Details saved.';
            }
        }
    }

    if ($action === 'save_seo') {
        db_query(
            'UPDATE properties SET seo_title=:title, seo_description=:desc, updated_at=NOW() WHERE id=:id',
            [':title' => trim($_POST['seo_title'] ?? ''), ':desc' => trim($_POST['seo_description'] ?? ''), ':id' => $id]
        );
        $success = 'SEO saved.';
    }

    if ($action === 'save_publish') {
        db_query(
            'UPDATE properties SET is_published=:pub, updated_at=NOW() WHERE id=:id',
            [':pub' => isset($_POST['is_published']) ? 'TRUE' : 'FALSE', ':id' => $id]
        );
        $success = 'Publish status updated.';
    }

    if ($action === 'delete_property') {
        // Remove image files first, then the row (FK cascade clears property_images)
        require_once __DIR__ . '/../includes/storage.php';
        $imgs = db_query('SELECT filename FROM property_images WHERE property_id = :id', [':id' => $id])->fetchAll();
        foreach ($imgs as $im) { if (!empty($im['filename'])) storage_delete($im['filename']); }
        db_query('DELETE FROM properties WHERE id = :id', [':id' => $id]);
        header('Location: /admin/properties.php');
        exit;
    }

    // Reload property after update
    if ($id) {
        $property = db_query('SELECT * FROM properties WHERE id = :id', [':id' => $id])->fetch();
        $images   = db_query('SELECT * FROM property_images WHERE property_id = :id ORDER BY sort_order ASC, id ASC', [':id' => $id])->fetchAll();
    }
}

// ── POST: gallery upload ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gallery_upload'])) {
    require_once __DIR__ . '/../includes/storage.php';
    verify_csrf();
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
    $uploaded     = 0;
    $errs         = [];

    foreach ($_FILES['gallery_upload']['tmp_name'] as $i => $tmp) {
        if ($_FILES['gallery_upload']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['gallery_upload']['size'][$i] > 5 * 1024 * 1024) { $errs[] = 'File too large (max 5MB).'; continue; }

        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowed_mime)) { $errs[] = 'Invalid file type.'; continue; }

        $src = match($mime) {
            'image/png'  => imagecreatefrompng($tmp),
            'image/webp' => imagecreatefromwebp($tmp),
            default      => imagecreatefromjpeg($tmp),
        };
        if (!$src) { $errs[] = 'Could not process image.'; continue; }

        $w = imagesx($src); $h = imagesy($src);
        if ($w > 2000) {
            $nh = (int)round($h * 2000 / $w);
            $dst = imagecreatetruecolor(2000, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, 2000, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        $filename = bin2hex(random_bytes(10)) . '.jpg';
        $tmp_out  = sys_get_temp_dir() . '/' . $filename;
        imagejpeg($src, $tmp_out, 88);
        imagedestroy($src);

        $stored = storage_put($tmp_out, $filename);
        @unlink($tmp_out);
        if ($stored === false) { $errs[] = 'Storage error — could not save image.'; continue; }

        $is_hero = empty($images) && $uploaded === 0;
        $max_order = db_query('SELECT COALESCE(MAX(sort_order),0) AS m FROM property_images WHERE property_id=:id', [':id'=>$id])->fetch()['m'];
        db_query(
            'INSERT INTO property_images (property_id,filename,alt_text,is_hero,sort_order) VALUES (:property_id,:filename,:alt,:hero,:order)',
            [':property_id'=>$id, ':filename'=>$stored, ':alt'=>$property['title']??'', ':hero'=>$is_hero?'TRUE':'FALSE', ':order'=>$max_order+1]
        );
        $uploaded++;
    }

    $images = db_query('SELECT * FROM property_images WHERE property_id = :id ORDER BY sort_order ASC, id ASC', [':id' => $id])->fetchAll();
    if ($uploaded) $success = "{$uploaded} image(s) uploaded.";
    if ($errs)     $error   = implode(' ', $errs);
}

// ── POST: gallery actions (set hero, delete, update alt, reorder) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_action'])) {
    verify_csrf();
    $img_id = (int)($_POST['img_id'] ?? 0);
    $act    = $_POST['gallery_action'];

    if ($act === 'set_hero') {
        db_query('UPDATE property_images SET is_hero = FALSE WHERE property_id = :pid', [':pid' => $id]);
        db_query('UPDATE property_images SET is_hero = TRUE  WHERE id = :id',          [':id'  => $img_id]);
        $success = 'Hero image updated.';
    }
    if ($act === 'delete') {
        $img = db_query('SELECT filename FROM property_images WHERE id=:id AND property_id=:pid', [':id'=>$img_id,':pid'=>$id])->fetch();
        if ($img) {
            require_once __DIR__ . '/../includes/storage.php';
            storage_delete($img['filename']);
            db_query('DELETE FROM property_images WHERE id=:id', [':id'=>$img_id]);
        }
        $success = 'Image deleted.';
    }
    if ($act === 'update_alt') {
        db_query('UPDATE property_images SET alt_text=:alt WHERE id=:id AND property_id=:pid',
            [':alt'=>trim($_POST['alt_text']??''), ':id'=>$img_id, ':pid'=>$id]);
        $success = 'Alt text updated.';
    }
    if ($act === 'reorder') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        foreach ($ids as $order => $iid) {
            db_query('UPDATE property_images SET sort_order=:o WHERE id=:id AND property_id=:pid',
                [':o'=>$order+1, ':id'=>(int)$iid, ':pid'=>$id]);
        }
        header('Content-Type: application/json');
        exit(json_encode(['ok'=>true]));
    }
    $images = db_query('SELECT * FROM property_images WHERE property_id = :id ORDER BY sort_order ASC, id ASC', [':id' => $id])->fetchAll();
}

if (isset($_GET['saved'])) {
    $success  = 'Property created successfully.';
    $property = db_query('SELECT * FROM properties WHERE id = :id', [':id' => $id])->fetch();
    $images   = db_query('SELECT * FROM property_images WHERE property_id = :id ORDER BY sort_order ASC, id ASC', [':id' => $id])->fetchAll();
    $isNew    = !$property;
}

$features_text = implode("\n", json_decode($property['features_json'] ?? '[]', true) ?: []);
$pageTitle  = $isNew ? 'Add Property' : 'Edit: ' . ($property['title'] ?? '');
$activeMenu = 'properties';

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1><?= $isNew ? 'Add Property' : e($property['title']) ?></h1>
  <div class="actions">
    <a href="/admin/properties.php" class="btn-outline btn-sm">← For Sale</a>
    <?php if (!$isNew): ?>
    <a href="/property.php?slug=<?= e($property['slug']) ?>" class="btn-outline btn-sm" target="_blank">View on site</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

<!-- Tabs -->
<div class="tabs">
  <button class="tab-btn is-active" data-tab="details">Details</button>
  <?php if (!$isNew): ?>
  <button class="tab-btn" data-tab="gallery">Gallery</button>
  <button class="tab-btn" data-tab="seo">SEO</button>
  <button class="tab-btn" data-tab="publish">Publish</button>
  <?php endif; ?>
</div>

<!-- ── TAB: Details ── -->
<div class="tab-panel is-active" id="tab-details">
<form method="POST" action="/admin/property-edit<?= $id ? "?id={$id}" : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_details">

  <div class="card">
    <div class="card__head"><span class="card__title">Basic Info</span></div>
    <div class="card__body" style="padding:20px">
      <div class="form-row">
        <div class="field">
          <label>Title</label>
          <input type="text" name="title" value="<?= e($property['title'] ?? '') ?>" required placeholder="e.g. Turtle Bay Garden Villa">
        </div>
        <div class="field">
          <label>Slug <span class="text-muted">(URL)</span></label>
          <input type="text" name="slug" value="<?= e($property['slug'] ?? '') ?>" required placeholder="e.g. turtle-bay-garden-villa">
          <span class="field-hint">Lowercase letters, numbers and hyphens only.</span>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Location</label>
          <input type="text" name="location" value="<?= e($property['location'] ?? '') ?>" placeholder="e.g. Watamu, Turtle Bay Road">
        </div>
        <div class="field">
          <label>Reference code</label>
          <input type="text" name="ref_code" value="<?= e($property['ref_code'] ?? '') ?>" placeholder="e.g. CAE-1001">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Property type</label>
          <select name="property_type">
            <?php foreach ($TYPE_LABELS as $val => $lbl): ?>
            <option value="<?= e($val) ?>" <?= ($property['property_type'] ?? 'villa') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <?php foreach ($STATUS_LABELS as $val => $lbl): ?>
            <option value="<?= e($val) ?>" <?= ($property['status'] ?? 'for_sale') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Price</span></div>
    <div class="card__body" style="padding:20px">
      <div class="form-row">
        <div class="field">
          <label>Amount <span class="text-muted">(0 = "Price on request")</span></label>
          <input type="number" name="price_amount" value="<?= e($property['price_amount'] ?? '') ?>" step="0.01" min="0" placeholder="485000">
        </div>
        <div class="field">
          <label>Currency</label>
          <input type="text" name="price_currency" value="<?= e($property['price_currency'] ?? 'USD') ?>" maxlength="10" placeholder="USD">
        </div>
      </div>
      <div class="field">
        <label>Price qualifier <span class="text-muted">(optional)</span></label>
        <input type="text" name="price_qualifier" value="<?= e($property['price_qualifier'] ?? '') ?>" placeholder="e.g. Guide price, Negotiable">
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Specifications</span></div>
    <div class="card__body" style="padding:20px">
      <div class="form-row">
        <div class="field">
          <label>Bedrooms</label>
          <input type="number" name="bedrooms" value="<?= e($property['bedrooms'] ?? '') ?>" min="0" placeholder="4">
        </div>
        <div class="field">
          <label>Bathrooms</label>
          <input type="number" name="bathrooms" value="<?= e($property['bathrooms'] ?? '') ?>" min="0" placeholder="3">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Built size (m²)</label>
          <input type="number" name="built_sqm" value="<?= e($property['built_sqm'] ?? '') ?>" min="0" placeholder="320">
        </div>
        <div class="field">
          <label>Plot size (m²)</label>
          <input type="number" name="plot_sqm" value="<?= e($property['plot_sqm'] ?? '') ?>" min="0" placeholder="800">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Descriptions</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label>Short description <span class="text-muted">(shown on cards + meta description)</span></label>
        <textarea name="short_desc" rows="2" placeholder="One-line teaser..."><?= e($property['short_desc'] ?? '') ?></textarea>
      </div>
      <div class="field">
        <label>Full description <span class="text-muted">(shown on property page, separate paragraphs with blank line)</span></label>
        <textarea name="long_desc" rows="8" placeholder="Full property description..."><?= e($property['long_desc'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Features &amp; amenities</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label>One feature per line</label>
        <textarea name="features" rows="10" placeholder="Private pool&#10;Beach access&#10;Mature garden&#10;..."><?= e($features_text) ?></textarea>
      </div>
    </div>
  </div>

  <button type="submit" class="btn-primary"><?= $isNew ? 'Create Property' : 'Save Details' ?></button>
</form>
</div>

<?php if (!$isNew): ?>

<!-- ── TAB: Gallery ── -->
<div class="tab-panel" id="tab-gallery">
  <div class="card">
    <div class="card__head"><span class="card__title">Upload Images</span></div>
    <div class="card__body">
      <form method="POST" action="/admin/property-edit?id=<?= $id ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="dropzone" id="dropzone">
          <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.4" style="color:var(--muted)"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <p>Drop images here or <label for="gallery_upload" style="color:var(--brand);cursor:pointer;text-decoration:underline">browse</label></p>
          <p>JPEG, PNG, WebP · max 5 MB each · re-encoded to JPEG</p>
          <input type="file" id="gallery_upload" name="gallery_upload[]" multiple accept="image/jpeg,image/png,image/webp" style="display:none">
        </div>
        <div style="padding:0 16px 16px">
          <button type="submit" class="btn-primary btn-sm">Upload selected</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="card__title">Images <span class="text-muted">(drag to reorder)</span></span>
    </div>
    <div class="card__body">
      <?php if (empty($images)): ?>
      <p style="padding:24px;color:var(--muted);text-align:center">No images yet. Upload some above.</p>
      <?php else: ?>
      <div class="gallery-grid" id="galleryGrid">
        <?php foreach ($images as $img): ?>
        <div class="gallery-item <?= $img['is_hero'] ? 'is-hero' : '' ?>" data-img-id="<?= e($img['id']) ?>" draggable="true">
          <?php if ($img['is_hero']): ?>
          <span class="gallery-hero-badge">Hero</span>
          <?php endif; ?>
          <img src="<?= e(storage_url($img['filename'])) ?>" alt="<?= e($img['alt_text']) ?>">
          <div class="gallery-item__actions">
            <form method="POST" action="/admin/property-edit?id=<?= $id ?>" style="display:contents">
              <?= csrf_field() ?>
              <input type="hidden" name="gallery_action" value="set_hero">
              <input type="hidden" name="img_id" value="<?= e($img['id']) ?>">
              <button type="submit">★ Hero</button>
            </form>
            <form method="POST" action="/admin/property-edit?id=<?= $id ?>" style="display:contents">
              <?= csrf_field() ?>
              <input type="hidden" name="gallery_action" value="delete">
              <input type="hidden" name="img_id" value="<?= e($img['id']) ?>">
              <button type="submit" onclick="return confirm('Delete this image?')">✕</button>
            </form>
          </div>
          <form method="POST" action="/admin/property-edit?id=<?= $id ?>" style="padding:4px 6px;background:#f9fafb;border-top:1px solid var(--border)">
            <?= csrf_field() ?>
            <input type="hidden" name="gallery_action" value="update_alt">
            <input type="hidden" name="img_id" value="<?= e($img['id']) ?>">
            <input type="text" name="alt_text" value="<?= e($img['alt_text']) ?>" placeholder="Alt text" style="font-size:11px;padding:3px 6px;width:calc(100% - 52px)">
            <button type="submit" style="font-size:11px;padding:3px 6px;border:1px solid var(--border);border-radius:3px;background:var(--white);cursor:pointer">Save</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── TAB: SEO ── -->
<div class="tab-panel" id="tab-seo">
<form method="POST" action="/admin/property-edit?id=<?= $id ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_seo">

  <div class="card">
    <div class="card__head"><span class="card__title">SEO Fields</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label>SEO Title <span class="text-muted">(recommended: 50-60 chars)</span></label>
        <input type="text" name="seo_title" id="seoTitle" maxlength="70"
               value="<?= e($property['seo_title'] ?? '') ?>"
               placeholder="<?= e($property['title']) ?> — For Sale">
        <span class="field-hint" id="seoTitleCount">0 / 60</span>
      </div>
      <div class="field">
        <label>Meta Description <span class="text-muted">(recommended: 150-160 chars)</span></label>
        <textarea name="seo_description" id="seoDesc" maxlength="320" rows="3"
                  placeholder="Brief description for search engines..."><?= e($property['seo_description'] ?? '') ?></textarea>
        <span class="field-hint" id="seoDescCount">0 / 160</span>
      </div>

      <div style="margin-top:20px">
        <div class="form-section__title">Google Preview</div>
        <div style="border:1px solid var(--border);border-radius:6px;padding:16px;background:#fff;font-family:Arial,sans-serif">
          <div id="gTitle" style="font-size:18px;color:#1a0dab;margin-bottom:2px"><?= e($property['seo_title'] ?: $property['title']) ?></div>
          <div style="font-size:13px;color:#006621;margin-bottom:4px">/property.php?slug=<?= e($property['slug']) ?></div>
          <div id="gDesc" style="font-size:13px;color:#545454;line-height:1.5"><?= e($property['seo_description'] ?? '') ?></div>
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn-primary">Save SEO</button>
</form>
</div>

<!-- ── TAB: Publish ── -->
<div class="tab-panel" id="tab-publish">
  <div class="card">
    <div class="card__head"><span class="card__title">Publish Status</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" action="/admin/property-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_publish">
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;margin-bottom:20px">
          <label class="toggle">
            <input type="checkbox" name="is_published" value="1" <?= ($property['is_published'] ?? false) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </label>
          <span style="font-size:14px">
            <?= ($property['is_published'] ?? false) ? '<strong>Live</strong> — visible on the public site' : '<strong>Hidden</strong> — not visible to buyers' ?>
          </span>
        </label>
        <button type="submit" class="btn-primary btn-sm">Update Status</button>
      </form>
    </div>
  </div>

  <div class="card" style="border:1.5px solid var(--red)">
    <div class="card__head"><span class="card__title" style="color:var(--red)">Danger Zone</span></div>
    <div class="card__body" style="padding:20px">
      <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Deleting a property permanently removes it and all its images. This cannot be undone.</p>
      <form method="POST" action="/admin/property-edit?id=<?= $id ?>"
            onsubmit="return confirm('Permanently delete this property and all its images? This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_property">
        <button type="submit" class="btn-danger btn-sm">Delete Property</button>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
// ── Tabs ──
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('is-active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('is-active'));
    btn.classList.add('is-active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('is-active');
  });
});

// ── SEO char counters + preview ──
const seoTitle = document.getElementById('seoTitle');
const seoDesc  = document.getElementById('seoDesc');
const gTitle   = document.getElementById('gTitle');
const gDesc    = document.getElementById('gDesc');

function updateSeo() {
  if (seoTitle) {
    const l = seoTitle.value.length;
    document.getElementById('seoTitleCount').textContent = l + ' / 60';
    if (gTitle) gTitle.textContent = seoTitle.value || seoTitle.placeholder;
  }
  if (seoDesc) {
    const l = seoDesc.value.length;
    document.getElementById('seoDescCount').textContent = l + ' / 160';
    if (gDesc) gDesc.textContent = seoDesc.value;
  }
}
if (seoTitle) { seoTitle.addEventListener('input', updateSeo); updateSeo(); }
if (seoDesc)  { seoDesc.addEventListener('input',  updateSeo); }

// ── Dropzone ──
const dz = document.getElementById('dropzone');
const fi = document.getElementById('gallery_upload');
if (dz && fi) {
  dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('is-over'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('is-over'));
  dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.classList.remove('is-over');
    fi.files = e.dataTransfer.files;
    dz.querySelector('p').textContent = e.dataTransfer.files.length + ' file(s) selected';
  });
  fi.addEventListener('change', () => {
    if (fi.files.length) dz.querySelector('p').textContent = fi.files.length + ' file(s) selected';
  });
}

// ── Gallery drag-to-reorder ──
const grid = document.getElementById('galleryGrid');
if (grid) {
  let dragged = null;
  grid.querySelectorAll('.gallery-item').forEach(item => {
    item.addEventListener('dragstart', () => { dragged = item; item.style.opacity = '.4'; });
    item.addEventListener('dragend',   () => { dragged = null; item.style.opacity = ''; saveGalleryOrder(); });
    item.addEventListener('dragover',  e => e.preventDefault());
    item.addEventListener('dragenter', e => {
      e.preventDefault();
      if (dragged && dragged !== item) {
        const items = [...grid.querySelectorAll('.gallery-item')];
        const di = items.indexOf(dragged), ri = items.indexOf(item);
        grid.insertBefore(dragged, di < ri ? item.nextSibling : item);
      }
    });
  });

  function saveGalleryOrder() {
    const ids = [...grid.querySelectorAll('.gallery-item')].map(i => i.dataset.imgId);
    const fd = new FormData();
    fd.append('gallery_action', 'reorder');
    fd.append('img_id', '0');
    fd.append('ids', JSON.stringify(ids));
    fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
    fetch('/admin/property-edit.php?id=<?= $id ?>', { method: 'POST', body: fd });
  }
}
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
