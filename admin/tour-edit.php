<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();
require_owner();

/** Replace an activity's property assignments with the posted set (ints, existing venues only). */
function sync_tour_venues(int $tourId, array $venueIds): void {
    db_query("DELETE FROM tour_venues WHERE tour_id = :t", [':t' => $tourId]);
    $valid = db_query("SELECT id FROM venues")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_unique(array_map('intval', $venueIds)) as $vid) {
        if (in_array($vid, array_map('intval', $valid), true)) {
            db_query("INSERT INTO tour_venues (tour_id, venue_id) VALUES (:t,:v) ON CONFLICT DO NOTHING", [':t' => $tourId, ':v' => $vid]);
        }
    }
}

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tour = $id ? db_query('SELECT * FROM tours WHERE id = :id', [':id' => $id])->fetch() : null;
$images = $id ? fetch_tour_images($id) : [];
$isNew  = !$tour;

$success = '';
$error   = '';

// ── POST: save details ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'save_details') {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        if (!$slug) $error = 'Slug is required.';

        if (!$error) {
            $highlights = array_values(array_filter(array_map('trim', explode("\n", $_POST['highlights'] ?? ''))));
            $data = [
                ':name'      => trim($_POST['name']     ?? ''),
                ':slug'      => $slug,
                ':category'  => trim($_POST['category'] ?? 'classic'),
                ':tag_label' => trim($_POST['tag_label']?? ''),
                ':duration'  => trim($_POST['duration'] ?? ''),
                ':short_desc'=> trim($_POST['short_desc']?? ''),
                ':long_desc' => trim($_POST['long_desc'] ?? ''),
                ':highlights'=> json_encode($highlights),
                ':price_amount'  => ($_POST['price_amount'] ?? '') === '' ? null : (float)$_POST['price_amount'],
                ':per_person'    => isset($_POST['price_per_person']) ? 'TRUE' : 'FALSE',
                ':max_pax'       => ($_POST['max_pax'] ?? '') === '' ? null : (int)$_POST['max_pax'],
                ':whats_included'=> trim($_POST['whats_included'] ?? ''),
            ];

            if ($isNew) {
                db_query(
                    "INSERT INTO tours (name,slug,category,tag_label,duration,short_desc,long_desc,highlights_json,
                                        price_amount,price_per_person,max_pax,whats_included)
                     VALUES (:name,:slug,:category,:tag_label,:duration,:short_desc,:long_desc,:highlights,
                             :price_amount,:per_person,:max_pax,:whats_included)",
                    $data
                );
                $id   = (int)db()->lastInsertId();
                sync_tour_venues($id, (array)($_POST['venue_ids'] ?? []));   // helper defined in Step 2
                audit_log('tour.save', 'tour', $id, $data[':name']);
                $tour = db_query('SELECT * FROM tours WHERE id = :id', [':id' => $id])->fetch();
                $isNew = false;
                header("Location: /admin/tour-edit.php?id={$id}&saved=1");
                exit;
            } else {
                $data[':id'] = $id;
                db_query(
                    "UPDATE tours SET name=:name,slug=:slug,category=:category,tag_label=:tag_label,
                     duration=:duration,short_desc=:short_desc,long_desc=:long_desc,highlights_json=:highlights,
                     price_amount=:price_amount,price_per_person=:per_person,max_pax=:max_pax,whats_included=:whats_included,
                     updated_at=NOW() WHERE id=:id",
                    $data
                );
                sync_tour_venues($id, (array)($_POST['venue_ids'] ?? []));
                audit_log('tour.save', 'tour', $id, $data[':name']);
                $success = 'Details saved.';
            }
        }
    }

    if ($action === 'save_seo') {
        db_query(
            'UPDATE tours SET seo_title=:title, seo_description=:desc, updated_at=NOW() WHERE id=:id',
            [':title' => trim($_POST['seo_title'] ?? ''), ':desc' => trim($_POST['seo_description'] ?? ''), ':id' => $id]
        );
        $success = 'SEO saved.';
    }

    if ($action === 'save_publish') {
        db_query(
            'UPDATE tours SET is_published=:pub, updated_at=NOW() WHERE id=:id',
            [':pub' => isset($_POST['is_published']) ? 'TRUE' : 'FALSE', ':id' => $id]
        );
        $success = 'Publish status updated.';
    }

    if ($action === 'delete_tour') {
        db_query('DELETE FROM tours WHERE id = :id', [':id' => $id]);
        header('Location: /admin/tours.php');
        exit;
    }

    if ($id) {
        $tour   = db_query('SELECT * FROM tours WHERE id = :id', [':id' => $id])->fetch();
        $images = fetch_tour_images($id);
    }
}

// ── POST: image upload ───────────────────────────────────────────
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
        if ($stored === false) { $errs[] = 'Storage error.'; continue; }

        $is_hero   = empty($images) && $uploaded === 0;
        $max_order = db_query('SELECT COALESCE(MAX(sort_order),0) AS m FROM tour_images WHERE tour_id=:id', [':id'=>$id])->fetch()['m'];
        db_query(
            'INSERT INTO tour_images (tour_id,filename,alt_text,is_hero,sort_order) VALUES (:tid,:filename,:alt,:hero,:order)',
            [':tid'=>$id, ':filename'=>$stored, ':alt'=>$tour['name']??'', ':hero'=>$is_hero?'TRUE':'FALSE', ':order'=>$max_order+1]
        );
        $uploaded++;
    }

    $images = fetch_tour_images($id);
    if ($uploaded) $success = "{$uploaded} image(s) uploaded.";
    if ($errs)     $error   = implode(' ', $errs);
}

// ── POST: gallery actions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_action'])) {
    verify_csrf();
    $img_id = (int)($_POST['img_id'] ?? 0);
    $act    = $_POST['gallery_action'];

    if ($act === 'set_hero') {
        db_query('UPDATE tour_images SET is_hero = FALSE WHERE tour_id = :tid', [':tid' => $id]);
        db_query('UPDATE tour_images SET is_hero = TRUE  WHERE id = :id',       [':id'  => $img_id]);
        $success = 'Hero image updated.';
    }
    if ($act === 'delete') {
        $img = db_query('SELECT filename FROM tour_images WHERE id=:id AND tour_id=:tid', [':id'=>$img_id,':tid'=>$id])->fetch();
        if ($img) {
            require_once __DIR__ . '/../includes/storage.php';
            storage_delete($img['filename']);
            db_query('DELETE FROM tour_images WHERE id=:id', [':id'=>$img_id]);
        }
        $success = 'Image deleted.';
    }
    if ($act === 'update_alt') {
        db_query('UPDATE tour_images SET alt_text=:alt WHERE id=:id AND tour_id=:tid',
            [':alt'=>trim($_POST['alt_text']??''), ':id'=>$img_id, ':tid'=>$id]);
        $success = 'Alt text updated.';
    }
    $images = fetch_tour_images($id);
}

if (isset($_GET['saved'])) $success = 'Tour created successfully.';

$highlights_text = implode("\n", json_decode($tour['highlights_json'] ?? '[]', true) ?: []);
$pageTitle  = $isNew ? 'Add Tour' : 'Edit: ' . ($tour['name'] ?? '');
$activeMenu = 'tours';

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1><?= $isNew ? 'Add Tour' : e($tour['name']) ?></h1>
  <div class="actions">
    <a href="/admin/tours.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Tours</a>
    <?php if (!$isNew): ?>
    <a href="/tour.php?slug=<?= e($tour['slug']) ?>" class="btn-outline btn-sm" target="_blank">View on site</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

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
<form method="POST" action="/admin/tour-edit<?= $id ? "?id={$id}" : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_details">

  <div class="card">
    <div class="card__head"><span class="card__title">Basic Info</span></div>
    <div class="card__body" style="padding:20px">
      <div class="form-row">
        <div class="field">
          <label>Tour Name</label>
          <input type="text" name="name" value="<?= e($tour['name'] ?? '') ?>" required placeholder="e.g. Tsavo East Safari">
        </div>
        <div class="field">
          <label>Slug <span class="text-muted">(URL)</span></label>
          <input type="text" name="slug" value="<?= e($tour['slug'] ?? '') ?>" required placeholder="e.g. tsavo-east">
          <span class="field-hint">Lowercase letters, numbers and hyphens only.</span>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Category</label>
          <?php $__cat = $tour['category'] ?? ''; ?>
          <select name="category">
            <optgroup label="Safaris">
              <option value="classic"   <?= $__cat === 'classic'   ? 'selected' : '' ?>>Classic Safari</option>
              <option value="custom"    <?= $__cat === 'custom'    ? 'selected' : '' ?>>Custom Journey</option>
              <option value="excursion" <?= $__cat === 'excursion' ? 'selected' : '' ?>>Day Excursion</option>
            </optgroup>
            <optgroup label="Activities">
              <option value="marine"      <?= $__cat === 'marine'      ? 'selected' : '' ?>>Marine &amp; Ocean</option>
              <option value="watersports" <?= $__cat === 'watersports' ? 'selected' : '' ?>>Watersports &amp; Kite</option>
              <option value="nature"      <?= $__cat === 'nature'      ? 'selected' : '' ?>>Nature &amp; Culture</option>
              <option value="wellness"    <?= $__cat === 'wellness'    ? 'selected' : '' ?>>Wellness</option>
              <option value="golf"        <?= $__cat === 'golf'        ? 'selected' : '' ?>>Golf</option>
            </optgroup>
          </select>
        </div>
        <div class="field">
          <label>Tag label <span class="text-muted">(displayed on card)</span></label>
          <input type="text" name="tag_label" value="<?= e($tour['tag_label'] ?? '') ?>" placeholder="e.g. Classic Safari">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Duration</label>
          <input type="text" name="duration" value="<?= e($tour['duration'] ?? '') ?>" placeholder="e.g. 3 days / 2 nights">
        </div>
      </div>

      <div style="margin:14px 0">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Price <span style="color:var(--muted);font-weight:400">(shown on the guest card, and charged on the bill)</span></label>
        <input type="number" name="price_amount" step="0.01" min="0" value="<?= e($tour['price_amount'] ?? '') ?>" placeholder="Enter price" style="width:160px;padding:8px 10px">
        <?php
          // Until this tour is priced numerically, show whatever was typed into the
          // old free-text Price field. That column is written by nothing now and read
          // by nothing ever — this is the only way the owner sees it again. It is NOT
          // auto-converted: "From $60 / person" would mean guessing a currency and
          // guessing per-person, then silently charging a guest that number.
          $__legacyPrice = trim((string)($tour['price'] ?? ''));
        ?>
        <?php if ($__legacyPrice !== '' && !is_priced($tour['price_amount'] ?? null)): ?>
        <p class="text-muted" style="margin:6px 0 0;font-size:12.5px">
          Previously entered as text: <strong><?= e($__legacyPrice) ?></strong> — retype it above as a number to use it.
        </p>
        <?php endif; ?>
        <label style="margin-left:14px;font-size:13px"><input type="checkbox" name="price_per_person" value="1" <?= (($tour['price_per_person'] ?? true) && ($tour['price_per_person'] ?? 't') !== 'f') ? 'checked' : '' ?>> Price is per person</label>
        <label style="margin-left:14px;font-size:13px">Max pax <input type="number" name="max_pax" min="1" value="<?= e($tour['max_pax'] ?? '') ?>" placeholder="Enter max guests" style="width:80px;padding:8px 10px"></label>
      </div>

      <div style="margin:14px 0">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">What’s included</label>
        <textarea name="whats_included" rows="3" style="width:100%;max-width:640px;padding:8px 10px" placeholder="e.g. Return transfers, guide, entrance fees, bottled water"><?= e($tour['whats_included'] ?? '') ?></textarea>
      </div>

      <div style="margin:14px 0">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:6px">Available at <span style="color:var(--muted);font-weight:400">(none ticked = shown at every property)</span></label>
        <?php
          $__assigned = $id ? activity_venue_ids((int)$id) : [];
          $__venues   = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
          foreach ($__venues as $__v):
        ?>
        <label style="display:inline-flex;align-items:center;gap:6px;margin:0 14px 8px 0;font-size:13px">
          <input type="checkbox" name="venue_ids[]" value="<?= (int)$__v['id'] ?>" <?= in_array((int)$__v['id'], $__assigned, true) ? 'checked' : '' ?>>
          <?= e($__v['name']) ?>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="field" style="margin-top:16px">
        <label>Short description <span class="text-muted">(shown on listing cards)</span></label>
        <textarea name="short_desc" rows="3" placeholder="One or two sentences about this tour."><?= e($tour['short_desc'] ?? '') ?></textarea>
      </div>
      <div class="field" style="margin-top:16px">
        <label>Full description <span class="text-muted">(shown on the tour detail page)</span></label>
        <textarea name="long_desc" rows="6" placeholder="Full itinerary, what's included, etc."><?= e($tour['long_desc'] ?? '') ?></textarea>
      </div>
      <div class="field" style="margin-top:16px">
        <label>Highlights <span class="text-muted">(one per line)</span></label>
        <textarea name="highlights" rows="5" placeholder="Game drive at sunrise&#10;Visit the Tsavo river crossing&#10;Overnight at camp"><?= e($highlights_text) ?></textarea>
      </div>
    </div>
  </div>

  <div style="margin-top:16px">
    <button type="submit" class="btn-primary"><?= $isNew ? 'Create Tour' : 'Save Details' ?></button>
  </div>
</form>
</div>

<?php if (!$isNew): ?>

<!-- ── TAB: Gallery ── -->
<div class="tab-panel" id="tab-gallery">
  <div class="card">
    <div class="card__head"><span class="card__title">Images</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" enctype="multipart/form-data" action="/admin/tour-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label>Upload images <span class="text-muted">(JPEG, PNG or WebP, max 5 MB each)</span></label>
          <input type="file" name="gallery_upload[]" multiple accept="image/jpeg,image/png,image/webp">
        </div>
        <button type="submit" class="btn-primary" style="margin-top:12px">Upload</button>
      </form>

      <?php if ($images): ?>
      <div class="gallery-grid" style="margin-top:24px">
        <?php foreach ($images as $img): ?>
        <div class="gallery-item">
          <img src="<?= e(storage_url($img['filename'])) ?>" alt="<?= e($img['alt_text'] ?? '') ?>">
          <?php if ($img['is_hero']): ?><span class="gallery-item__badge">Hero</span><?php endif; ?>
          <div class="gallery-item__actions">
            <?php if (!$img['is_hero']): ?>
            <form method="POST" action="/admin/tour-edit?id=<?= $id ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="gallery_action" value="set_hero">
              <input type="hidden" name="img_id" value="<?= e($img['id']) ?>">
              <button type="submit" class="btn-sm btn-outline">Set hero</button>
            </form>
            <?php endif; ?>
            <form method="POST" action="/admin/tour-edit?id=<?= $id ?>" style="display:inline"
                  onsubmit="return confirm('Delete this image?')">
              <?= csrf_field() ?>
              <input type="hidden" name="gallery_action" value="delete">
              <input type="hidden" name="img_id" value="<?= e($img['id']) ?>">
              <button type="submit" class="btn-sm btn-danger">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── TAB: SEO ── -->
<div class="tab-panel" id="tab-seo">
  <form method="POST" action="/admin/tour-edit?id=<?= $id ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_seo">
    <div class="card">
      <div class="card__head"><span class="card__title">SEO &amp; Meta</span></div>
      <div class="card__body" style="padding:20px">
        <div class="field">
          <label>SEO title <span class="text-muted">(max 60 chars)</span></label>
          <input type="text" name="seo_title" maxlength="60" value="<?= e($tour['seo_title'] ?? '') ?>" placeholder="Enter SEO title">
        </div>
        <div class="field" style="margin-top:16px">
          <label>Meta description <span class="text-muted">(max 160 chars)</span></label>
          <textarea name="seo_description" rows="3" maxlength="160" placeholder="Enter meta description"><?= e($tour['seo_description'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
    <div style="margin-top:16px">
      <button type="submit" class="btn-primary">Save SEO</button>
    </div>
  </form>
</div>

<!-- ── TAB: Publish ── -->
<div class="tab-panel" id="tab-publish">
  <div class="card">
    <div class="card__head"><span class="card__title">Visibility</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" action="/admin/tour-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_publish">
        <label class="toggle-row">
          <input type="checkbox" name="is_published" value="1" <?= ($tour['is_published'] ?? false) ? 'checked' : '' ?>>
          <span>Published (visible on the site)</span>
        </label>
        <button type="submit" class="btn-primary" style="margin-top:16px">Update</button>
      </form>

      <hr style="margin:24px 0;border-color:var(--border)">

      <form method="POST" action="/admin/tour-edit?id=<?= $id ?>" onsubmit="return confirm('Delete this tour and all its images? This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_tour">
        <button type="submit" class="btn-danger">Delete tour permanently</button>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn, .tab-panel').forEach(el => el.classList.remove('is-active'));
    btn.classList.add('is-active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('is-active');
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
