<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_owner();

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$venue = $id ? db_query('SELECT * FROM venues WHERE id = :id', [':id' => $id])->fetch() : null;
$isNew = !$venue;

require_once __DIR__ . '/../includes/storage.php';
$images = $id ? fetch_venue_images($id) : [];

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gallery_upload']) && $id) {
    verify_csrf();
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
    $uploaded = 0; $errs = [];
    foreach ($_FILES['gallery_upload']['tmp_name'] as $i => $tmp) {
        if ($_FILES['gallery_upload']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['gallery_upload']['size'][$i] > 5 * 1024 * 1024) { $errs[] = 'File too large (max 5MB).'; continue; }
        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowed_mime)) { $errs[] = 'Invalid file type.'; continue; }
        $src = match($mime) { 'image/png' => imagecreatefrompng($tmp), 'image/webp' => imagecreatefromwebp($tmp), default => imagecreatefromjpeg($tmp) };
        if (!$src) { $errs[] = 'Could not process image.'; continue; }
        $w = imagesx($src); $h = imagesy($src);
        if ($w > 2000) { $nh = (int)round($h * 2000 / $w); $dst = imagecreatetruecolor(2000, $nh); imagecopyresampled($dst, $src, 0,0,0,0, 2000,$nh,$w,$h); imagedestroy($src); $src = $dst; }
        $filename = bin2hex(random_bytes(10)) . '.jpg';
        $tmp_out = sys_get_temp_dir() . '/' . $filename;
        imagejpeg($src, $tmp_out, 88); imagedestroy($src);
        $stored = storage_put($tmp_out, $filename); @unlink($tmp_out);
        if ($stored === false) { $errs[] = 'Storage error.'; continue; }
        $is_hero = empty($images) && $uploaded === 0;
        $max_order = db_query('SELECT COALESCE(MAX(sort_order),0) AS m FROM venue_images WHERE venue_id=:id', [':id'=>$id])->fetch()['m'];
        db_query('INSERT INTO venue_images (venue_id,filename,alt_text,is_hero,sort_order) VALUES (:vid,:f,:alt,:hero,:o)',
            [':vid'=>$id, ':f'=>$stored, ':alt'=>$venue['name']??'', ':hero'=>$is_hero?'TRUE':'FALSE', ':o'=>$max_order+1]);
        $uploaded++;
    }
    if ($uploaded) { audit_log('venue.gallery_upload','venue',$id,"+{$uploaded}"); $success = "{$uploaded} image(s) uploaded."; }
    if ($errs) $error = implode(' ', array_unique($errs));
    $images = fetch_venue_images($id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_action']) && $id) {
    verify_csrf();
    $act = $_POST['gallery_action']; $img_id = (int)($_POST['image_id'] ?? 0);
    if ($act === 'set_hero') {
        db_query('UPDATE venue_images SET is_hero = FALSE WHERE venue_id = :v', [':v'=>$id]);
        db_query('UPDATE venue_images SET is_hero = TRUE  WHERE id = :id AND venue_id = :v', [':id'=>$img_id, ':v'=>$id]);
    } elseif ($act === 'delete_image') {
        $row = db_query('SELECT filename FROM venue_images WHERE id=:id AND venue_id=:v', [':id'=>$img_id, ':v'=>$id])->fetch();
        if ($row) { storage_delete($row['filename']); db_query('DELETE FROM venue_images WHERE id=:id', [':id'=>$img_id]); }
    } elseif ($act === 'reorder') {
        foreach (json_decode($_POST['order'] ?? '[]', true) as $o => $iid) {
            db_query('UPDATE venue_images SET sort_order=:o WHERE id=:id AND venue_id=:v', [':o'=>$o, ':id'=>(int)$iid, ':v'=>$id]);
        }
        header('Content-Type: application/json'); exit(json_encode(['ok'=>true]));
    }
    $images = fetch_venue_images($id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'save_details') {
        $name     = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $sort     = (int)($_POST['sort_order'] ?? 0);
        if (!$name) $error = 'Name is required.';

        if (!$error && $isNew) {
            // Slug is editable ONLY when creating a new property (no live page exists yet).
            $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['slug'] ?? '')));
            if (!$slug) {
                $error = 'Slug is required for a new property.';
            } elseif (db_query('SELECT id FROM venues WHERE slug = :s', [':s' => $slug])->fetch()) {
                $error = 'That slug is already in use.';
            }
            if (!$error) {
                db_query(
                    'INSERT INTO venues (slug, name, location, sort_order, is_published) VALUES (:slug,:name,:loc,:sort,TRUE)',
                    [':slug' => $slug, ':name' => $name, ':loc' => $location, ':sort' => $sort]
                );
                $id = (int)db()->lastInsertId();
                audit_log('venue.create', 'venue', $id, $name);
                header("Location: /admin/venue-edit.php?id={$id}&saved=1");
                exit;
            }
        } elseif (!$error) {
            // Existing property: slug is READ-ONLY (protects the ranking URL) — never updated here.
            db_query(
                'UPDATE venues SET name=:name, location=:loc, sort_order=:sort, updated_at=NOW() WHERE id=:id',
                [':name' => $name, ':loc' => $location, ':sort' => $sort, ':id' => $id]
            );
            audit_log('venue.update', 'venue', $id, $name);
            header("Location: /admin/venue-edit.php?id={$id}&saved=1");
            exit;
        }
    }

    if ($action === 'save_content' && !$isNew) {
        db_query(
            'UPDATE venues SET tagline=:t, about_heading=:h, about_body=:b, updated_at=NOW() WHERE id=:id',
            [
                ':t'  => trim($_POST['tagline'] ?? ''),
                ':h'  => trim($_POST['about_heading'] ?? ''),
                ':b'  => trim($_POST['about_body'] ?? ''),
                ':id' => $id,
            ]
        );
        audit_log('venue.content', 'venue', $id);
        header("Location: /admin/venue-edit.php?id={$id}&saved=1");
        exit;
    }

    if ($action === 'save_stay' && !$isNew) {
        db_query(
            'UPDATE venues SET address=:addr, maps_url=:maps, stay_wifi=:wifi,
                    stay_checkout=:co, stay_house_rules=:hr, stay_area_guide=:ag, updated_at=NOW()
             WHERE id=:id',
            [
                ':addr' => trim($_POST['address'] ?? ''),
                ':maps' => trim($_POST['maps_url'] ?? ''),
                ':wifi' => trim($_POST['stay_wifi'] ?? ''),
                ':co'   => trim($_POST['stay_checkout'] ?? ''),
                ':hr'   => trim($_POST['stay_house_rules'] ?? ''),
                ':ag'   => trim($_POST['stay_area_guide'] ?? ''),
                ':id'   => $id,
            ]
        );
        audit_log('venue.stay', 'venue', $id);
        header("Location: /admin/venue-edit.php?id={$id}&saved=1");
        exit;
    }

    if ($action === 'save_publish' && !$isNew) {
        db_query('UPDATE venues SET is_published=:pub, updated_at=NOW() WHERE id=:id',
            [':pub' => isset($_POST['is_published']) ? 'TRUE' : 'FALSE', ':id' => $id]);
        audit_log('venue.publish', 'venue', $id);
        header("Location: /admin/venue-edit.php?id={$id}&saved=1");
        exit;
    }

    if ($action === 'delete_venue' && !$isNew) {
        db_query('DELETE FROM venues WHERE id = :id', [':id' => $id]); // rooms.venue_id → NULL via FK ON DELETE SET NULL
        audit_log('venue.delete', 'venue', $id);
        header('Location: /admin/venues.php');
        exit;
    }
}

if (isset($_GET['saved'])) $success = 'Saved.';

$rooms = $id
    ? db_query('SELECT id, name, slug, price_amount, price_currency, is_published FROM rooms WHERE venue_id = :id ORDER BY sort_order', [':id' => $id])->fetchAll()
    : [];

$pageTitle  = $isNew ? 'New Property' : 'Edit Property';
$activeMenu = 'venues';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1><?= $isNew ? 'New Property' : 'Edit Property: ' . e($venue['name']) ?></h1>
  <a href="/admin/venues.php" class="btn-sm btn-outline"><?= admin_icon('arrow-left', 15) ?> Back to Properties</a>
</div>

<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Details</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit<?= $id ? '?id=' . $id : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_details">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Name</label>
        <input type="text" name="name" value="<?= e($venue['name'] ?? '') ?>" required placeholder="Enter property name" style="width:100%;max-width:480px;padding:8px 10px">
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Location</label>
        <input type="text" name="location" value="<?= e($venue['location'] ?? '') ?>" placeholder="e.g. Watamu" style="width:100%;max-width:480px;padding:8px 10px">
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Slug (URL)</label>
        <?php if ($isNew): ?>
          <input type="text" name="slug" value="" required placeholder="e.g. zuri" style="width:100%;max-width:480px;padding:8px 10px">
          <p style="font-size:12px;color:var(--muted);margin:4px 0 0">Must match the page file — e.g. <code>zuri</code> serves <code>/zuri</code>.</p>
        <?php else: ?>
          <input type="text" value="<?= e($venue['slug']) ?>" readonly disabled style="width:100%;max-width:480px;padding:8px 10px;background:var(--surface);color:var(--muted)">
          <p style="font-size:12px;color:var(--muted);margin:4px 0 0">Locked — this is the live URL <code>/<?= e($venue['slug']) ?></code> and cannot be changed.</p>
        <?php endif; ?>
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Sort order</label>
        <input type="number" name="sort_order" value="<?= e($venue['sort_order'] ?? 0) ?>" placeholder="Enter sort order" style="width:120px;padding:8px 10px">
      </div>

      <button type="submit" class="btn-primary btn-sm">Save Property</button>
    </form>
  </div>
</div>

<?php if (!$isNew): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Publish</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_publish">
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="is_published" value="1" <?= $venue['is_published'] ? 'checked' : '' ?>>
        Published (visible)
      </label>
      <button type="submit" class="btn-sm btn-outline" style="margin-top:10px">Update</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Page Content</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_content">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Tagline <span style="color:var(--muted);font-weight:400">(the small line above the title)</span></label>
        <input type="text" name="tagline" value="<?= e($venue['tagline'] ?? '') ?>" placeholder="e.g. Ultra-Luxury Private Beachfront Villa · Entire Property Only" style="width:100%;max-width:640px;padding:8px 10px">
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">About — heading</label>
        <input type="text" name="about_heading" value="<?= e($venue['about_heading'] ?? '') ?>" placeholder="e.g. Best Beachfront Villa in *Vipingo, Kenya*" style="width:100%;max-width:640px;padding:8px 10px">
        <p style="font-size:12px;color:var(--muted);margin:4px 0 0">Wrap a word in <code>*asterisks*</code> to italicise it (shows in the accent colour).</p>
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">About — body</label>
        <textarea name="about_body" rows="9" placeholder="Write the property description. Leave a blank line between paragraphs." style="width:100%;max-width:640px;padding:8px 10px;font-family:inherit;line-height:1.6"><?= e($venue['about_body'] ?? '') ?></textarea>
        <p style="font-size:12px;color:var(--muted);margin:4px 0 0">Separate paragraphs with a blank line. Leave everything here empty to keep the page's built-in text.</p>
      </div>

      <button type="submit" class="btn-primary btn-sm">Save Content</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Stay info &amp; location</span></div>
  <div class="card__body">
    <p style="margin:0 0 14px;font-size:13px;color:var(--muted)">Shown to guests in the app for this property. Leave a field blank to hide it.</p>
    <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_stay">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Address <span style="color:var(--muted);font-weight:400">(shown in the booking box)</span></label>
        <input type="text" name="address" value="<?= e($venue['address'] ?? '') ?>" placeholder="e.g. Zuri Beach House, Vipingo Ridge, Kilifi County" style="width:100%;max-width:640px;padding:8px 10px">
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Google Maps link <span style="color:var(--muted);font-weight:400">(optional)</span></label>
        <input type="url" name="maps_url" value="<?= e($venue['maps_url'] ?? '') ?>" placeholder="Paste a Google Maps share link (overrides the address search)" style="width:100%;max-width:640px;padding:8px 10px">
        <p style="font-size:12px;color:var(--muted);margin:4px 0 0">If blank, the map pin searches Google Maps for the address above.</p>
      </div>

      <?php
        $__stay = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
        foreach ($__stay as $__k => $__label): ?>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px"><?= e($__label) ?></label>
        <textarea name="<?= e($__k) ?>" rows="3" placeholder="Enter <?= e(strtolower($__label)) ?>" style="width:100%;max-width:640px;padding:8px 10px;font-family:inherit;line-height:1.6"><?= e($venue[$__k] ?? '') ?></textarea>
      </div>
      <?php endforeach; ?>

      <button type="submit" class="btn-primary btn-sm">Save Stay Info</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">Rooms in this Property</span>
    <a href="/admin/room-edit.php?venue=<?= $id ?>" class="btn-sm btn-primary">+ Add room to this property</a>
  </div>
  <div class="card__body" style="padding:0">
    <?php if (!$rooms): ?>
    <p style="padding:24px;text-align:center;color:var(--muted)">No rooms in this property yet.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Room</th><th>Price</th><th>Published</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rooms as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong> <code style="font-size:11px;color:var(--muted)"><?= e($r['slug']) ?></code></td>
          <td><?= e($r['price_currency']) ?> <?= e(number_format((float)$r['price_amount'], 0)) ?></td>
          <td><?= $r['is_published'] ? '<span class="badge badge--green">Live</span>' : '<span class="badge badge--grey">Hidden</span>' ?></td>
          <td style="white-space:nowrap">
            <a href="/admin/room-edit.php?id=<?= (int)$r['id'] ?>" class="btn-sm btn-outline">Edit</a>
            <a href="/admin/gantt.php?room=<?= (int)$r['id'] ?>" class="btn-sm btn-outline">Availability</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$isNew): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Gallery photos</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="file" id="gallery_upload" name="gallery_upload[]" multiple accept="image/jpeg,image/png,image/webp" style="display:none" onchange="this.form.submit()">
      <p><label for="gallery_upload" style="color:var(--brand);cursor:pointer;text-decoration:underline">Upload images</label> (JPEG/PNG/WebP, max 5 MB each). The first becomes the main image.</p>
    </form>
    <div class="venue-gallery" id="venueGallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-top:12px">
      <?php foreach ($images as $im): ?>
      <div class="gallery-item<?= $im['is_hero'] ? ' is-hero' : '' ?>" data-img-id="<?= (int)$im['id'] ?>" draggable="true" style="position:relative;border:1px solid var(--border,#e7ded7);border-radius:8px;overflow:hidden">
        <?php if ($im['is_hero']): ?><span style="position:absolute;top:6px;left:6px;background:var(--brand,#1E5C6B);color:#fff;font-size:10px;padding:2px 7px;border-radius:8px;z-index:2">MAIN</span><?php endif; ?>
        <img src="<?= e(storage_url($im['filename'])) ?>" alt="<?= e($im['alt_text']) ?>" style="width:100%;height:110px;object-fit:cover;display:block">
        <div style="display:flex;gap:4px;padding:6px;justify-content:center">
          <?php if (!$im['is_hero']): ?>
          <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="gallery_action" value="set_hero"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>"><button type="submit" class="btn-sm btn-outline" style="font-size:11px">Set main</button></form>
          <?php endif; ?>
          <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" style="display:inline" onsubmit="return confirm('Delete this photo?')"><?= csrf_field() ?><input type="hidden" name="gallery_action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>"><button type="submit" class="btn-sm" style="font-size:11px;color:#c0392b;border:1px solid #c0392b;background:#fff;border-radius:4px">Delete</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$images): ?><p style="color:var(--muted);padding:12px 0">No photos yet — upload some above.</p><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" onsubmit="return confirm('Delete this property? Its rooms are kept but detached.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_venue">
      <button type="submit" class="btn-sm" style="background:#dc2626;color:#fff;border:none">Delete property</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  var g=document.getElementById('venueGallery'); if(!g) return; var dragged=null;
  g.querySelectorAll('.gallery-item').forEach(function(it){
    it.addEventListener('dragstart',function(){dragged=it;it.style.opacity='.4';});
    it.addEventListener('dragend',function(){dragged=null;it.style.opacity='';save();});
    it.addEventListener('dragover',function(e){e.preventDefault();});
    it.addEventListener('dragenter',function(e){e.preventDefault(); if(dragged&&dragged!==it){var k=[].slice.call(g.children);var di=k.indexOf(dragged),ri=k.indexOf(it);g.insertBefore(dragged, di<ri?it.nextSibling:it);}});
  });
  function save(){var ids=[].slice.call(g.querySelectorAll('.gallery-item')).map(function(x){return x.dataset.imgId;});var fd=new FormData();fd.append('gallery_action','reorder');fd.append('order',JSON.stringify(ids));fd.append('csrf_token',<?= json_encode(csrf_token()) ?>);fetch('/admin/venue-edit.php?id=<?= $id ?>',{method:'POST',body:fd});}
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
