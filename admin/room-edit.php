<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_owner();

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$room = $id ? db_query('SELECT * FROM rooms WHERE id = :id', [':id' => $id])->fetch() : null;
$images = $id ? fetch_room_images($id) : [];
$units  = $id ? fetch_units_by_room($id) : [];
$isNew  = !$room;
$preselect_venue = ($isNew && isset($_GET['venue'])) ? (int)$_GET['venue'] : (int)($room['venue_id'] ?? 0);

$success = '';
$error   = '';

// ── POST: save details / seo / publish ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'save_details') {
        // Slug editable only when creating a new room; on edit it is locked to protect the live URL.
        $slug = $isNew
            ? preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_POST['slug'] ?? '')))
            : ($room['slug'] ?? '');
        if ($isNew && !$slug) $error = 'Slug is required.';

        if (!$error) {
            $features = array_values(array_filter(array_map('trim', explode("\n", $_POST['features'] ?? ''))));
            // FAQs: parallel faq_q[] / faq_a[] arrays — keep only rows with a question
            $faq_qs = $_POST['faq_q'] ?? [];
            $faq_as = $_POST['faq_a'] ?? [];
            $faqs = [];
            foreach ((array)$faq_qs as $k => $fq) {
                $fq = trim((string)$fq);
                $fa = trim((string)($faq_as[$k] ?? ''));
                if ($fq !== '') $faqs[] = ['q' => $fq, 'a' => $fa];
            }
            // form_mode: '' (inherit), 'enquiry', or 'availability'
            $form_mode_in = $_POST['form_mode'] ?? '';
            $form_mode    = in_array($form_mode_in, ['enquiry', 'availability'], true) ? $form_mode_in : null;
            $data = [
                ':name'          => trim($_POST['name']          ?? ''),
                ':slug'          => $slug,
                ':price_amount'  => (float)($_POST['price_amount'] ?? 0),
                ':price_currency'=> trim($_POST['price_currency'] ?? 'USD'),
                ':price_unit'    => trim($_POST['price_unit']     ?? 'per night'),
                ':size_sqm'      => (int)($_POST['size_sqm']     ?? 0) ?: null,
                ':capacity'      => (int)($_POST['capacity']      ?? 0) ?: null,
                ':bed_count'     => (int)($_POST['bed_count']     ?? 0) ?: null,
                ':short_desc'    => trim($_POST['short_desc']     ?? ''),
                ':long_desc'     => trim($_POST['long_desc']      ?? ''),
                ':features_json' => json_encode($features),
                ':faqs_json'     => json_encode($faqs),
                ':form_mode'       => $form_mode,
                ':venue_id'        => ($_POST['venue_id'] !== '' ? (int)$_POST['venue_id'] : null),
                ':is_entire_place' => isset($_POST['is_entire_place']) ? 'TRUE' : 'FALSE',
                ':tag_label'       => trim($_POST['tag_label'] ?? ''),
            ];

            if ($isNew) {
                db_query(
                    "INSERT INTO rooms (name,slug,price_amount,price_currency,price_unit,size_sqm,capacity,bed_count,short_desc,long_desc,features_json,faqs_json,form_mode,venue_id,is_entire_place,tag_label)
                     VALUES (:name,:slug,:price_amount,:price_currency,:price_unit,:size_sqm,:capacity,:bed_count,:short_desc,:long_desc,:features_json,:faqs_json,:form_mode,:venue_id,:is_entire_place,:tag_label)",
                    $data
                );
                $id   = (int)db()->lastInsertId();
                $room = db_query('SELECT * FROM rooms WHERE id = :id', [':id' => $id])->fetch();
                $isNew = false;
                $success = 'Room created.';
                header("Location: /admin/room-edit.php?id={$id}&saved=1");
                exit;
            } else {
                $data[':id'] = $id;
                db_query(
                    "UPDATE rooms SET name=:name,slug=:slug,price_amount=:price_amount,price_currency=:price_currency,
                     price_unit=:price_unit,size_sqm=:size_sqm,capacity=:capacity,bed_count=:bed_count,
                     short_desc=:short_desc,long_desc=:long_desc,features_json=:features_json,faqs_json=:faqs_json,
                     form_mode=:form_mode,venue_id=:venue_id,is_entire_place=:is_entire_place,tag_label=:tag_label,updated_at=NOW()
                     WHERE id=:id",
                    $data
                );
                $success = 'Details saved.';
            }
        }
    }

    if ($action === 'save_seo') {
        db_query(
            'UPDATE rooms SET seo_title=:title, seo_description=:desc, updated_at=NOW() WHERE id=:id',
            [':title' => trim($_POST['seo_title'] ?? ''), ':desc' => trim($_POST['seo_description'] ?? ''), ':id' => $id]
        );
        $success = 'SEO saved.';
    }

    if ($action === 'save_publish') {
        db_query(
            'UPDATE rooms SET is_published=:pub, updated_at=NOW() WHERE id=:id',
            [':pub' => isset($_POST['is_published']) ? 'TRUE' : 'FALSE', ':id' => $id]
        );
        $success = 'Publish status updated.';
    }

    if ($action === 'add_unit') {
        $unit_name = trim($_POST['unit_name'] ?? '');
        if (!$unit_name) {
            $error = 'Unit name is required.';
        } else {
            $max_order = db_query('SELECT COALESCE(MAX(sort_order),0) AS m FROM units WHERE room_id=:id', [':id' => $id])->fetch()['m'];
            db_query('INSERT INTO units (room_id, name, sort_order) VALUES (:rid, :name, :ord)',
                [':rid' => $id, ':name' => $unit_name, ':ord' => $max_order + 1]);
            $success = 'Unit added.';
        }
    }

    if ($action === 'delete_unit') {
        $unit_id = (int)($_POST['unit_id'] ?? 0);
        $blocked = db_query(
            'SELECT COUNT(*) FROM availability_blocks WHERE unit_id = :uid',
            [':uid' => $unit_id]
        )->fetchColumn();
        if ($blocked > 0) {
            $error = 'Cannot delete a unit that has existing availability blocks or holds.';
        } else {
            db_query('DELETE FROM units WHERE id = :id AND room_id = :rid', [':id' => $unit_id, ':rid' => $id]);
            $success = 'Unit deleted.';
        }
    }

    if ($action === 'delete_room') {
        db_query('DELETE FROM rooms WHERE id = :id', [':id' => $id]);
        header('Location: /admin/rooms.php');
        exit;
    }

    // Reload room after update
    if ($id) {
        $room   = db_query('SELECT * FROM rooms WHERE id = :id', [':id' => $id])->fetch();
        $images = fetch_room_images($id);
        $units  = fetch_units_by_room($id);
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

        // Re-encode via GD
        $src = match($mime) {
            'image/png'  => imagecreatefrompng($tmp),
            'image/webp' => imagecreatefromwebp($tmp),
            default      => imagecreatefromjpeg($tmp),
        };
        if (!$src) { $errs[] = 'Could not process image.'; continue; }

        // Resize to max 2000px wide
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

        // First image for this room becomes hero
        $is_hero = empty($images) && $uploaded === 0;
        $max_order = db_query('SELECT COALESCE(MAX(sort_order),0) AS m FROM room_images WHERE room_id=:id', [':id'=>$id])->fetch()['m'];
        db_query(
            'INSERT INTO room_images (room_id,filename,alt_text,is_hero,sort_order) VALUES (:room_id,:filename,:alt,:hero,:order)',
            [':room_id'=>$id, ':filename'=>$stored, ':alt'=>$room['name']??'', ':hero'=>$is_hero?'TRUE':'FALSE', ':order'=>$max_order+1]
        );
        $uploaded++;
    }

    $images = fetch_room_images($id);
    if ($uploaded) $success = "{$uploaded} image(s) uploaded.";
    if ($errs)     $error   = implode(' ', $errs);
}

// ── POST: gallery actions (set hero, delete, update alt) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_action'])) {
    verify_csrf();
    $img_id = (int)($_POST['img_id'] ?? 0);
    $act    = $_POST['gallery_action'];

    if ($act === 'set_hero') {
        db_query('UPDATE room_images SET is_hero = FALSE WHERE room_id = :rid', [':rid' => $id]);
        db_query('UPDATE room_images SET is_hero = TRUE  WHERE id = :id',      [':id'  => $img_id]);
        $success = 'Hero image updated.';
    }
    if ($act === 'delete') {
        $img = db_query('SELECT filename FROM room_images WHERE id=:id AND room_id=:rid', [':id'=>$img_id,':rid'=>$id])->fetch();
        if ($img) {
            require_once __DIR__ . '/../includes/storage.php';
            storage_delete($img['filename']);
            db_query('DELETE FROM room_images WHERE id=:id', [':id'=>$img_id]);
        }
        $success = 'Image deleted.';
    }
    if ($act === 'delete_images') {
        // Bulk delete — an array of image IDs from the gallery selection bar.
        require_once __DIR__ . '/../includes/storage.php';
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['image_ids'] ?? []))));
        $deleted = 0;
        foreach ($ids as $iid) {
            $img = db_query('SELECT filename FROM room_images WHERE id=:id AND room_id=:rid', [':id'=>$iid, ':rid'=>$id])->fetch();
            if ($img) { storage_delete($img['filename']); db_query('DELETE FROM room_images WHERE id=:id', [':id'=>$iid]); $deleted++; }
        }
        $success = $deleted ? "{$deleted} image(s) deleted." : 'Nothing deleted.';
    }
    if ($act === 'update_alt') {
        db_query('UPDATE room_images SET alt_text=:alt WHERE id=:id AND room_id=:rid',
            [':alt'=>trim($_POST['alt_text']??''), ':id'=>$img_id, ':rid'=>$id]);
        $success = 'Alt text updated.';
    }
    if ($act === 'reorder') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        foreach ($ids as $order => $iid) {
            db_query('UPDATE room_images SET sort_order=:o WHERE id=:id AND room_id=:rid',
                [':o'=>$order+1, ':id'=>(int)$iid, ':rid'=>$id]);
        }
        header('Content-Type: application/json');
        exit(json_encode(['ok'=>true]));
    }
    $images = fetch_room_images($id);
}

if (isset($_GET['saved'])) $success = 'Room created successfully.';

$features_text = implode("\n", json_decode($room['features_json'] ?? '[]', true) ?: []);
$faqs_data     = json_decode($room['faqs_json'] ?? '[]', true) ?: [];
$venues        = db_query("SELECT id, name FROM venues ORDER BY sort_order")->fetchAll();
$pageTitle  = $isNew ? 'Add Room' : 'Edit: ' . ($room['name'] ?? '');
$activeMenu = 'rooms';

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1><?= $isNew ? 'Add Room' : e($room['name']) ?></h1>
  <div class="actions">
    <a href="/admin/rooms.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Rooms</a>
    <?php if (!$isNew): ?>
    <a href="/<?= e($room['slug']) ?>" class="btn-outline btn-sm" target="_blank">View on site</a>
    <a href="/admin/gantt.php?room=<?= (int)$id ?>" class="btn-outline btn-sm">Manage availability</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert--success is-flash"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<!-- Tabs -->
<div class="tabs">
  <button class="tab-btn is-active" data-tab="details">Details</button>
  <?php if (!$isNew): ?>
  <button class="tab-btn" data-tab="gallery">Gallery</button>
  <button class="tab-btn" data-tab="units">Units</button>
  <button class="tab-btn" data-tab="seo">SEO</button>
  <button class="tab-btn" data-tab="publish">Publish</button>
  <?php endif; ?>
</div>

<!-- ── TAB: Details ── -->
<div class="tab-panel is-active" id="tab-details">
<form method="POST" action="/admin/room-edit<?= $id ? "?id={$id}" : '' ?>" data-shell-form>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_details">

  <div class="card">
    <div class="card__head"><span class="card__title">Basic Info</span></div>
    <div class="card__body" style="padding:20px">
      <div class="form-row">
        <div class="field">
          <label>Room Name</label>
          <input type="text" name="name" value="<?= e($room['name'] ?? '') ?>" required placeholder="e.g. Junior Suite">
        </div>
        <div class="field">
          <label>Slug <span class="text-muted">(URL)</span></label>
          <?php if ($isNew): ?>
          <input type="text" name="slug" value="" required placeholder="e.g. junior-suite">
          <span class="field-hint">Lowercase letters, numbers and hyphens only.</span>
          <?php else: ?>
          <input type="text" value="<?= e($room['slug']) ?>" readonly disabled style="background:var(--surface);color:var(--muted)">
          <small style="display:block;color:var(--muted);margin-top:4px">Locked — live URL <code>/<?= e($room['slug']) ?></code>.</small>
          <?php endif; ?>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Venue</label>
          <select name="venue_id">
            <option value="">— none —</option>
            <?php foreach ($venues as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= ($preselect_venue === (int)$v['id']) ? 'selected' : '' ?>><?= e($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label class="togglerow" style="margin:10px 0">
          <span class="toggle">
            <input type="checkbox" name="is_entire_place" value="1" <?= !empty($room['is_entire_place']) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </span>
          <span>Entire Villa <span class="text-muted">(whole-property option — only shown as available when no individual rooms are booked for the dates)</span></span>
        </label>
      </div>
      <label style="display:block;margin:10px 0">Badge label (optional)
        <input type="text" name="tag_label" value="<?= e($room['tag_label'] ?? '') ?>" placeholder="e.g. Oceanfront" style="width:100%;max-width:320px;padding:8px 10px">
      </label>
      <div class="form-row">
        <div class="field">
          <label>Price</label>
          <input type="number" name="price_amount" value="<?= e($room['price_amount'] ?? '') ?>" step="0.01" min="0" placeholder="450">
        </div>
        <div class="field">
          <label>Currency</label>
          <?php
            $cur = $room['price_currency'] ?? 'USD';
            $cur_opts = ['USD' => 'USD — US Dollar', 'KES' => 'KES — Kenyan Shilling'];
            if ($cur !== '' && !isset($cur_opts[$cur])) $cur_opts[$cur] = $cur; // preserve any legacy value
          ?>
          <select name="price_currency">
            <?php foreach ($cur_opts as $code => $lbl): ?>
            <option value="<?= e($code) ?>" <?= $cur === $code ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Price unit</label>
          <input type="text" name="price_unit" value="<?= e($room['price_unit'] ?? 'per night') ?>" placeholder="per night">
        </div>
        <div class="field">
          <label>Size (m²)</label>
          <input type="number" name="size_sqm" value="<?= e($room['size_sqm'] ?? '') ?>" min="0" placeholder="55">
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Max capacity</label>
          <input type="number" name="capacity" value="<?= e($room['capacity'] ?? '') ?>" min="1" placeholder="6">
        </div>
        <div class="field">
          <label>Bed count</label>
          <input type="number" name="bed_count" value="<?= e($room['bed_count'] ?? '') ?>" min="1" placeholder="2">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Descriptions</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label>Short description <span class="text-muted">(shown on room cards)</span></label>
        <textarea name="short_desc" rows="2" placeholder="One-line teaser..."><?= e($room['short_desc'] ?? '') ?></textarea>
      </div>
      <div class="field">
        <label>Full description <span class="text-muted">(shown on room page, separate paragraphs with blank line)</span></label>
        <textarea name="long_desc" rows="8" placeholder="Full room description..."><?= e($room['long_desc'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Amenities &amp; Features</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label>One feature per line</label>
        <textarea name="features" rows="10" placeholder="WiFi&#10;Air conditioning&#10;Minibar&#10;..."><?= e($features_text) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="card__title">FAQs</span>
      <span class="text-muted" style="font-size:12px">Shown as an accordion on the villa page</span>
    </div>
    <div class="card__body" style="padding:20px">
      <div id="faq-rows" class="faq-editor">
        <?php foreach ($faqs_data as $faq): ?>
        <div class="faq-row">
          <div class="faq-row__fields">
            <div class="field">
              <label>Question</label>
              <input type="text" name="faq_q[]" value="<?= e($faq['q'] ?? '') ?>" placeholder="Where is the villa located?">
            </div>
            <div class="field">
              <label>Answer</label>
              <textarea name="faq_a[]" rows="3" placeholder="Answer shown when the question is expanded..."><?= e($faq['a'] ?? '') ?></textarea>
            </div>
          </div>
          <button type="button" class="btn-sm faq-row__remove" aria-label="Remove FAQ">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-outline btn-sm" id="faq-add" style="margin-top:6px">+ Add FAQ</button>

      <template id="faq-row-tpl">
        <div class="faq-row">
          <div class="faq-row__fields">
            <div class="field">
              <label>Question</label>
              <input type="text" name="faq_q[]" value="" placeholder="Where is the villa located?">
            </div>
            <div class="field">
              <label>Answer</label>
              <textarea name="faq_a[]" rows="3" placeholder="Answer shown when the question is expanded..."></textarea>
            </div>
          </div>
          <button type="button" class="btn-sm faq-row__remove" aria-label="Remove FAQ">Remove</button>
        </div>
      </template>
    </div>
  </div>

  <?php
    $room_form_mode    = $room['form_mode'] ?? null;
    $global_form_mode  = setting('form_mode', 'enquiry');
    $global_label      = $global_form_mode === 'availability' ? 'Live availability' : 'Enquiry form';
  ?>
  <div class="card">
    <div class="card__head">
      <span class="card__title">Booking form</span>
      <span class="text-muted" style="font-size:12px">Which form guests see on this room's page</span>
    </div>
    <div class="card__body" style="padding:20px">
      <div class="optset" id="formModeChips">
        <label class="optchip"><input type="radio" name="form_mode" value="" <?= $room_form_mode === null ? 'checked' : '' ?>> Use global setting</label>
        <label class="optchip"><input type="radio" name="form_mode" value="enquiry" <?= $room_form_mode === 'enquiry' ? 'checked' : '' ?>> Enquiry form</label>
        <label class="optchip"><input type="radio" name="form_mode" value="availability" <?= $room_form_mode === 'availability' ? 'checked' : '' ?>> Live availability</label>
      </div>
      <div style="margin-top:12px">
        <div class="field-hint" data-mode-help="" <?= $room_form_mode === null ? '' : 'hidden' ?>>
          Inherits the site-wide default from <a href="/admin/settings.php">Settings</a> — currently
          <span class="badge <?= $global_form_mode==='availability'?'badge--orange':'badge--green' ?>"><?= e($global_label) ?></span>.
        </div>
        <div class="field-hint" data-mode-help="enquiry" <?= $room_form_mode === 'enquiry' ? '' : 'hidden' ?>>
          Guest fills out a contact form. You receive a submission and reply manually.
        </div>
        <div class="field-hint" data-mode-help="availability" <?= $room_form_mode === 'availability' ? '' : 'hidden' ?>>
          Guest sees a calendar with real availability and can request a 24h hold.
          Requires at least one active <a href="#" onclick="document.querySelector('[data-tab=units]').click();return false">unit</a>.
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn-primary"><?= $isNew ? 'Create Room' : 'Save Details' ?></button>
</form>
</div>

<?php if (!$isNew): ?>

<!-- ── TAB: Gallery ── -->
<div class="tab-panel" id="tab-gallery">
  <div class="card">
    <div class="card__head"><span class="card__title">Upload Images</span></div>
    <div class="card__body">
      <form method="POST" action="/admin/room-edit?id=<?= $id ?>" enctype="multipart/form-data">
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
      <div class="gallery-bulkbar" data-gallery-bulk data-grid="galleryGrid"
           data-endpoint="/admin/room-edit.php?id=<?= $id ?>" data-idfield="img_id"
           data-csrf="<?= e(csrf_token()) ?>">
        <label class="ckwrap"><input type="checkbox" data-bulk-all><span class="ck"></span> Select all</label>
        <span class="gallery-bulkbar__count" data-bulk-count>0 selected</span>
        <span class="gallery-bulkbar__spacer"></span>
        <button type="button" class="btn-outline btn-sm" data-bulk-setmain hidden>Set as hero</button>
        <button type="button" class="btn-outline btn-sm" data-bulk-clear>Clear</button>
        <button type="button" class="btn-danger btn-sm" data-bulk-delete disabled>Delete selected (0)</button>
      </div>
      <div class="gallery-grid" id="galleryGrid">
        <?php foreach ($images as $img): ?>
        <div class="gallery-item <?= $img['is_hero'] ? 'is-hero' : '' ?>" data-img-id="<?= e($img['id']) ?>" draggable="true">
          <label class="gallery-select" title="Select image"><input type="checkbox" data-bulk-item value="<?= e($img['id']) ?>"><span class="ck"></span></label>
          <?php if ($img['is_hero']): ?>
          <span class="gallery-hero-badge">Hero</span>
          <?php endif; ?>
          <img src="<?= e(storage_url($img['filename'])) ?>" alt="<?= e($img['alt_text']) ?>">
          <div class="gallery-item__actions">
            <form method="POST" action="/admin/room-edit?id=<?= $id ?>" style="display:contents">
              <?= csrf_field() ?>
              <input type="hidden" name="gallery_action" value="set_hero">
              <input type="hidden" name="img_id" value="<?= e($img['id']) ?>">
              <button type="submit">★ Hero</button>
            </form>
            <form method="POST" action="/admin/room-edit?id=<?= $id ?>" style="display:contents">
              <?= csrf_field() ?>
              <input type="hidden" name="gallery_action" value="delete">
              <input type="hidden" name="img_id" value="<?= e($img['id']) ?>">
              <button type="submit" data-confirm="Delete this image? This cannot be undone." aria-label="Delete image" title="Delete image"><?= admin_icon('x', 14) ?></button>
            </form>
          </div>
          <form method="POST" action="/admin/room-edit?id=<?= $id ?>" style="padding:4px 6px;background:#f9fafb;border-top:1px solid var(--border)">
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

<!-- ── TAB: Units ── -->
<div class="tab-panel" id="tab-units">
  <div class="card">
    <div class="card__head">
      <span class="card__title">Bookable Units</span>
      <span class="text-muted" style="font-size:12px">Each unit is a physical room that can be independently booked</span>
    </div>
    <div class="card__body">
      <?php if (empty($units)): ?>
      <p style="padding:24px;text-align:center;color:var(--muted)">No units yet. Add at least one to enable availability booking for this room.</p>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Name</th><th>Status</th><th>iCal Feed URL</th><th></th></tr></thead>
        <tbody>
        <?php
        $env_re = parse_env();
        $site_url_re = rtrim($env_re['SITE_URL'] ?? '', '/');
        foreach ($units as $unit):
        ?>
        <tr>
          <td><?= e($unit['name']) ?></td>
          <td><?= $unit['is_active'] ? '<span class="badge badge--green">Active</span>' : '<span class="badge badge--grey">Inactive</span>' ?></td>
          <td style="font-size:11px;max-width:260px">
            <?php if (!empty($unit['feed_token'])): ?>
            <input type="text" readonly onclick="this.select()"
                   value="<?= e($site_url_re . '/api/ical.php?unit=' . $unit['id'] . '&token=' . $unit['feed_token']) ?>"
                   style="width:100%;font-family:monospace;font-size:10px;background:#f9fafb;padding:3px 6px;border:1px solid var(--border);border-radius:3px"
                   title="iCal feed URL — share with OTAs">
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <form method="POST" action="/admin/room-edit?id=<?= $id ?>" style="display:inline"
                  onsubmit="return confirm('Delete this unit? Cannot be undone if it has existing bookings.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action"  value="delete_unit">
              <input type="hidden" name="unit_id" value="<?= e($unit['id']) ?>">
              <button type="submit" class="btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <div style="padding:16px;border-top:1px solid var(--border)">
        <form method="POST" action="/admin/room-edit?id=<?= $id ?>" style="display:flex;gap:8px;align-items:flex-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_unit">
          <div class="field" style="margin:0;flex:1">
            <label>New unit name</label>
            <input type="text" name="unit_name" placeholder="e.g. Unit B, Garden View, Room 101" required>
          </div>
          <button type="submit" class="btn-primary btn-sm" style="align-self:flex-end">Add Unit</button>
        </form>
      </div>
    </div>
  </div>

  <div class="alert alert--info" style="font-size:13px">
    <strong>How units work:</strong> A room type (e.g. "Standard Room") can have multiple physical units (e.g. Unit A, Unit B).
    When availability mode is active, the system automatically assigns an available unit when a guest requests a hold.
    View and manage holds at <a href="/admin/holds.php">Holds &amp; Bookings</a>.
  </div>
</div>

<!-- ── TAB: SEO ── -->
<div class="tab-panel" id="tab-seo">
<form method="POST" action="/admin/room-edit?id=<?= $id ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_seo">

  <div class="card">
    <div class="card__head"><span class="card__title">SEO Fields</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label>SEO Title <span class="text-muted">(recommended: 50-60 chars)</span></label>
        <input type="text" name="seo_title" id="seoTitle" maxlength="70"
               value="<?= e($room['seo_title'] ?? '') ?>"
               placeholder="<?= e($room['name']) ?> — Tribal Sand, Kenya">
        <span class="field-hint" id="seoTitleCount">0 / 60</span>
      </div>
      <div class="field">
        <label>Meta Description <span class="text-muted">(recommended: 150-160 chars)</span></label>
        <textarea name="seo_description" id="seoDesc" maxlength="320" rows="3"
                  placeholder="Brief description for search engines..."><?= e($room['seo_description'] ?? '') ?></textarea>
        <span class="field-hint" id="seoDescCount">0 / 160</span>
      </div>

      <!-- Google preview -->
      <div style="margin-top:20px">
        <div class="form-section__title">Google Preview</div>
        <div style="border:1px solid var(--border);border-radius:6px;padding:16px;background:#fff;font-family:Arial,sans-serif">
          <div id="gTitle" style="font-size:18px;color:#1a0dab;margin-bottom:2px"><?= e($room['seo_title'] ?: $room['name']) ?></div>
          <div style="font-size:13px;color:#006621;margin-bottom:4px">tribalsand.com/<?= e($room['slug']) ?></div>
          <div id="gDesc" style="font-size:13px;color:#545454;line-height:1.5"><?= e($room['seo_description'] ?? '') ?></div>
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
      <form method="POST" action="/admin/room-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_publish">
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;margin-bottom:20px">
          <label class="toggle">
            <input type="checkbox" name="is_published" value="1" <?= ($room['is_published'] ?? false) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </label>
          <span style="font-size:14px">
            <?= ($room['is_published'] ?? false) ? '<strong>Live</strong> — visible on the public site' : '<strong>Hidden</strong> — not visible to guests' ?>
          </span>
        </label>
        <button type="submit" class="btn-primary btn-sm">Update Status</button>
      </form>
    </div>
  </div>

  <div class="card" style="border:1.5px solid var(--red)">
    <div class="card__head"><span class="card__title" style="color:var(--red)">Danger Zone</span></div>
    <div class="card__body" style="padding:20px">
      <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Deleting a room permanently removes it and all its images. This cannot be undone.</p>
      <form method="POST" action="/admin/room-edit?id=<?= $id ?>"
            onsubmit="return confirm('Permanently delete this room and all its images? This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_room">
        <button type="submit" class="btn-danger btn-sm">Delete Room</button>
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

// ── Booking-form-mode chips: show the matching description ──
(function () {
  var chips = document.getElementById('formModeChips');
  if (!chips) return;
  var helps = document.querySelectorAll('[data-mode-help]');
  chips.addEventListener('change', function (e) {
    if (!e.target || e.target.name !== 'form_mode') return;
    var v = e.target.value;
    helps.forEach(function (h) { h.hidden = (h.getAttribute('data-mode-help') !== v); });
  });
})();

// ── FAQ editor (repeatable Q/A rows) ──
(function () {
  const rows = document.getElementById('faq-rows');
  const tpl  = document.getElementById('faq-row-tpl');
  const add  = document.getElementById('faq-add');
  if (!rows || !tpl || !add) return;
  add.addEventListener('click', () => {
    const node = tpl.content.cloneNode(true);
    rows.appendChild(node);
    rows.lastElementChild.querySelector('input').focus();
  });
  rows.addEventListener('click', e => {
    const btn = e.target.closest('.faq-row__remove');
    if (btn) btn.closest('.faq-row').remove();
  });
})();

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
    fetch('/admin/room-edit.php?id=<?= $id ?>', { method: 'POST', body: fd });
  }
}
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
