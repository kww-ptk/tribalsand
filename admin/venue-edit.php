<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/upsells.php';   // checkin_deposit_supported()
require_once __DIR__ . '/../includes/rates.php';
require_login();
require_owner();

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$venue = $id ? db_query('SELECT * FROM venues WHERE id = :id', [':id' => $id])->fetch() : null;
$isNew = !$venue;

require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/admin-media-picker.php';   // "choose from library" for the gallery
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
        $filename = seo_filename((string)($_FILES['gallery_upload']['name'][$i] ?? ''), 'venue');
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
    } elseif ($act === 'delete_images') {
        // Bulk delete — an array of image IDs from the gallery selection bar.
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['image_ids'] ?? []))));
        $deleted = 0;
        foreach ($ids as $iid) {
            $row = db_query('SELECT filename FROM venue_images WHERE id=:id AND venue_id=:v', [':id'=>$iid, ':v'=>$id])->fetch();
            if ($row) { storage_delete($row['filename']); db_query('DELETE FROM venue_images WHERE id=:id', [':id'=>$iid]); $deleted++; }
        }
        if ($deleted) { audit_log('venue.gallery_delete','venue',$id,"-{$deleted}"); $success = "{$deleted} photo(s) deleted."; }
    } elseif ($act === 'reorder') {
        foreach (json_decode($_POST['order'] ?? '[]', true) as $o => $iid) {
            db_query('UPDATE venue_images SET sort_order=:o WHERE id=:id AND venue_id=:v', [':o'=>$o, ':id'=>(int)$iid, ':v'=>$id]);
        }
        header('Content-Type: application/json'); exit(json_encode(['ok'=>true]));
    } elseif ($act === 'add_library') {
        // Attach one or more existing library images (picker posts storage keys).
        $keys = (array)($_POST['image_keys'] ?? []);
        if (isset($_POST['image_key'])) $keys[] = (string)$_POST['image_key'];
        $added = 0;
        foreach ($keys as $k) {
            if (media_attach_to_gallery('venue_images', 'venue_id', $id, (string)$k, $venue['name'] ?? '')) $added++;
        }
        if ($added) { audit_log('venue.gallery_library','venue',$id,"+{$added}"); $success = "{$added} image(s) added from the library."; }
        else        { $error = 'No new image added — already in this gallery, or invalid.'; }
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

        // Security deposit (per-property) — only when the column exists.
        $depSupported = checkin_deposit_supported();
        $depRaw = trim((string)($_POST['deposit_amount'] ?? ''));
        $depAmt = $depRaw === '' ? null : (float)preg_replace('/[^0-9.]/', '', $depRaw);
        if ($depAmt !== null && $depAmt <= 0) $depAmt = null;
        $depCur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['deposit_currency'] ?? 'USD')));
        if ($depCur === '') $depCur = 'USD';

        // Booking-flow add-ons master switch for this property.
        $upsOn = upsells_supported() ? isset($_POST['upsell_enabled']) : null;

        if (!$error && $isNew) {
            // Slug is editable ONLY when creating a new property (no live page exists yet).
            $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['slug'] ?? '')));
            if (!$slug) {
                $error = 'Slug is required for a new property.';
            } elseif (db_query('SELECT id FROM venues WHERE slug = :s', [':s' => $slug])->fetch()) {
                $error = 'That slug is already in use.';
            }
            if (!$error) {
                if ($depSupported) {
                    db_query(
                        'INSERT INTO venues (slug, name, location, sort_order, is_published, deposit_amount, deposit_currency)
                         VALUES (:slug,:name,:loc,:sort,TRUE,:damt,:dcur)',
                        [':slug' => $slug, ':name' => $name, ':loc' => $location, ':sort' => $sort, ':damt' => $depAmt, ':dcur' => $depCur]
                    );
                } else {
                    db_query(
                        'INSERT INTO venues (slug, name, location, sort_order, is_published) VALUES (:slug,:name,:loc,:sort,TRUE)',
                        [':slug' => $slug, ':name' => $name, ':loc' => $location, ':sort' => $sort]
                    );
                }
                $id = (int)db()->lastInsertId();
                if ($upsOn !== null) {
                    db_query('UPDATE venues SET upsell_enabled = :u WHERE id = :id',
                             [':u' => $upsOn ? 'TRUE' : 'FALSE', ':id' => $id]);
                }
                audit_log('venue.create', 'venue', $id, $name);
                header("Location: /admin/venue-edit.php?id={$id}&saved=1");
                exit;
            }
        } elseif (!$error) {
            // Existing property: slug is READ-ONLY (protects the ranking URL) — never updated here.
            if ($depSupported) {
                db_query(
                    'UPDATE venues SET name=:name, location=:loc, sort_order=:sort,
                            deposit_amount=:damt, deposit_currency=:dcur, updated_at=NOW() WHERE id=:id',
                    [':name' => $name, ':loc' => $location, ':sort' => $sort, ':damt' => $depAmt, ':dcur' => $depCur, ':id' => $id]
                );
            } else {
                db_query(
                    'UPDATE venues SET name=:name, location=:loc, sort_order=:sort, updated_at=NOW() WHERE id=:id',
                    [':name' => $name, ':loc' => $location, ':sort' => $sort, ':id' => $id]
                );
            }
            if ($upsOn !== null) {
                db_query('UPDATE venues SET upsell_enabled = :u WHERE id = :id',
                         [':u' => $upsOn ? 'TRUE' : 'FALSE', ':id' => $id]);
            }
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

// ── POST: rate overrides ────────────────────────────────────────────────────
// Owner-only page, so no venue scoping is needed on the write — but the room
// must belong to THIS property, or a posted foreign room_id would price
// somebody else's room from this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rates_save' && $id) {
    verify_csrf();
    $rt_room = (int)($_POST['room_id'] ?? 0);
    $rt_own  = $rt_room
        ? (int) db_query('SELECT COUNT(*) FROM rooms WHERE id = :r AND venue_id = :v',
              [':r' => $rt_room, ':v' => $id])->fetchColumn()
        : 0;
    if (!$rt_own) {
        $error = 'That room is not in this property.';
    } else {
        $rt_n = rates_apply_ranges(
            $rt_room,
            rates_ranges_from_post($_POST),
            (float)($_POST['price'] ?? 0),
            trim((string)($_POST['rate_label'] ?? ''))
        );
        if ($rt_n) {
            audit_log('rates.save', 'room', $rt_room, "{$rt_n} range(s)");
            header("Location: /admin/venue-edit.php?id={$id}&rate_room={$rt_room}&saved=1");
            exit;
        }
        $error = 'Nothing saved — check the dates and that the price is above zero.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_delete' && $id) {
    verify_csrf();
    $rt_room = (int)($_POST['room_id'] ?? 0);
    if (rates_delete((int)($_POST['rate_id'] ?? 0), null)) {
        audit_log('rates.delete', 'venue', $id, '');
    }
    header("Location: /admin/venue-edit.php?id={$id}&rate_room={$rt_room}&saved=1");
    exit;
}

$rooms = $id
    ? db_query('SELECT id, name, slug, price_amount, price_currency, is_published FROM rooms WHERE venue_id = :id ORDER BY sort_order', [':id' => $id])->fetchAll()
    : [];

// Rates tab: which room's calendar to draw, and which month.
$rateRoomId = isset($_GET['rate_room']) ? (int)$_GET['rate_room'] : 0;
$rateRoom   = null;
foreach ($rooms as $__r) {
    if ($rateRoomId && (int)$__r['id'] === $rateRoomId) { $rateRoom = $__r; break; }
}
if (!$rateRoom && $rooms) $rateRoom = $rooms[0];        // default to the first room
$rateMonth  = isset($_GET['rate_month']) && strtotime($_GET['rate_month'] . '-01')
    ? substr((string)$_GET['rate_month'], 0, 7)
    : date('Y-m');
$venueRates = $id ? rates_for_venue($id) : [];

$pageTitle  = $isNew ? 'New Property' : 'Edit Property';
$activeMenu = 'venues';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1><?= $isNew ? 'New Property' : 'Edit Property: ' . e($venue['name']) ?></h1>
  <div class="actions">
    <a href="/admin/venues.php" class="btn-sm btn-outline" data-tip="Back to all properties"><?= admin_icon('arrow-left', 15) ?> Properties</a>
    <?php if (!$isNew): ?>
    <a href="/<?= e($venue['slug']) ?>" class="btn-sm btn-outline" target="_blank" data-tip="Open the live public page">View on site</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert--success is-flash"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<!-- Tabs -->
<div class="tabs">
  <button class="tab-btn is-active" data-tab="details">Details</button>
  <?php if (!$isNew): ?>
  <button class="tab-btn" data-tab="content">Content</button>
  <button class="tab-btn" data-tab="stay">Stay</button>
  <button class="tab-btn" data-tab="rooms">Rooms<?php if ($rooms): ?> <span class="tab-btn__count"><?= count($rooms) ?></span><?php endif; ?></button>
  <button class="tab-btn" data-tab="rates">Rates<?php if ($venueRates): ?> <span class="tab-btn__count"><?= count($venueRates) ?></span><?php endif; ?></button>
  <button class="tab-btn" data-tab="gallery">Gallery<?php if ($images): ?> <span class="tab-btn__count"><?= count($images) ?></span><?php endif; ?></button>
  <button class="tab-btn" data-tab="publish">Publish</button>
  <?php endif; ?>
</div>

<!-- ── TAB: Details ── -->
<div class="tab-panel is-active" id="tab-details">
  <div class="card">
    <div class="card__head"><span class="card__title">Details</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" action="/admin/venue-edit<?= $id ? '?id=' . $id : '' ?>" data-shell-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_details">

        <div class="form-row">
          <div class="field">
            <label>Name</label>
            <input type="text" name="name" value="<?= e($venue['name'] ?? '') ?>" required placeholder="Enter property name">
          </div>
          <div class="field">
            <label>Location</label>
            <input type="text" name="location" value="<?= e($venue['location'] ?? '') ?>" placeholder="e.g. Watamu">
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label>Slug <span class="text-muted">(URL)</span></label>
            <?php if ($isNew): ?>
            <input type="text" name="slug" value="" required placeholder="e.g. zuri">
            <span class="field-hint">Must match the page file — e.g. <code>zuri</code> serves <code>/zuri</code>.</span>
            <?php else: ?>
            <input type="text" value="<?= e($venue['slug']) ?>" readonly disabled style="background:var(--bg);color:var(--muted)">
            <span class="field-hint">Locked — this is the live URL <code>/<?= e($venue['slug']) ?></code> and cannot be changed.</span>
            <?php endif; ?>
          </div>
          <div class="field">
            <label>Sort order</label>
            <input type="number" name="sort_order" value="<?= e($venue['sort_order'] ?? 0) ?>" placeholder="0">
            <span class="field-hint">Lower numbers show first in listings.</span>
          </div>
        </div>

        <?php if (checkin_deposit_supported()): $__dcur = strtoupper(trim((string)($venue['deposit_currency'] ?? 'USD'))) ?: 'USD'; ?>
        <div class="form-row">
          <div class="field">
            <label>Security deposit amount <span class="text-muted">(per property)</span></label>
            <input type="number" name="deposit_amount" min="0" step="1" value="<?= $venue && $venue['deposit_amount'] !== null && $venue['deposit_amount'] !== '' ? e(number_format((float)$venue['deposit_amount'], 0, '.', '')) : '' ?>" placeholder="e.g. 500">
            <span class="field-hint">Shown to guests at check-in. Leave blank if there is no fixed amount. Charged at the property, never online.</span>
          </div>
          <div class="field">
            <label>Deposit currency</label>
            <select name="deposit_currency" class="inp">
              <?php foreach (['USD','EUR','GBP','KES'] as $__c): ?>
              <option value="<?= $__c ?>"<?= $__dcur === $__c ? ' selected' : '' ?>><?= $__c ?></option>
              <?php endforeach; ?>
              <?php if (!in_array($__dcur, ['USD','EUR','GBP','KES'], true)): ?>
              <option value="<?= e($__dcur) ?>" selected><?= e($__dcur) ?></option>
              <?php endif; ?>
            </select>
          </div>
        </div>
        <?php endif; ?>

        <?php if (upsells_supported()): ?>
        <div class="field">
          <label class="ckwrap" style="display:inline-flex;align-items:center;gap:8px">
            <input type="checkbox" name="upsell_enabled" value="1"<?= !empty($venue['upsell_enabled']) ? ' checked' : '' ?>><span class="ck"></span>
            <span>Offer add-ons in the booking flow</span>
          </label>
          <span class="field-hint">Lets guests add activities to this property&rsquo;s enquiry and pre-arrival check-in. Which activities appear &mdash; and on which of the two &mdash; is set per activity under <a href="/admin/tours.php">Tours</a>.</span>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn-primary btn-sm" data-tip="Save name, location & sort order">Save Property</button>
      </form>
    </div>
  </div>
</div>

<?php if (!$isNew): ?>
<!-- ── TAB: Content ── -->
<div class="tab-panel" id="tab-content">
  <div class="card">
    <div class="card__head"><span class="card__title">Page Content</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_content">

        <div class="field">
          <label>Tagline <span class="text-muted">(the small line above the title)</span></label>
          <input type="text" name="tagline" value="<?= e($venue['tagline'] ?? '') ?>" placeholder="e.g. Ultra-Luxury Private Beachfront Villa · Entire Property Only">
        </div>

        <div class="field">
          <label>About — heading</label>
          <input type="text" name="about_heading" value="<?= e($venue['about_heading'] ?? '') ?>" placeholder="e.g. Best Beachfront Villa in *Vipingo, Kenya*">
          <span class="field-hint">Wrap a word in <code>*asterisks*</code> to italicise it (shows in the accent colour).</span>
        </div>

        <div class="field">
          <label>About — body</label>
          <textarea name="about_body" rows="9" placeholder="Write the property description. Leave a blank line between paragraphs."><?= e($venue['about_body'] ?? '') ?></textarea>
          <span class="field-hint">Separate paragraphs with a blank line. Leave everything here empty to keep the page's built-in text.</span>
        </div>

        <button type="submit" class="btn-primary btn-sm" data-tip="Save the tagline & About text">Save Content</button>
      </form>
    </div>
  </div>
</div>

<!-- ── TAB: Stay ── -->
<div class="tab-panel" id="tab-stay">
  <div class="card">
    <div class="card__head"><span class="card__title">Stay info &amp; location</span></div>
    <div class="card__body" style="padding:20px">
      <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Shown to guests in the app for this property. Leave a field blank to hide it.</p>
      <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_stay">

        <div class="field">
          <label>Address <span class="text-muted">(shown in the booking box)</span></label>
          <input type="text" name="address" value="<?= e($venue['address'] ?? '') ?>" placeholder="e.g. Zuri Beach House, Vipingo Ridge, Kilifi County">
        </div>

        <div class="field">
          <label>Google Maps link <span class="text-muted">(optional)</span></label>
          <input type="url" name="maps_url" value="<?= e($venue['maps_url'] ?? '') ?>" placeholder="Paste a Google Maps share link (overrides the address search)">
          <span class="field-hint">If blank, the map pin searches Google Maps for the address above.</span>
        </div>

        <?php
          $__stay = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
          foreach ($__stay as $__k => $__label): ?>
        <div class="field">
          <label><?= e($__label) ?></label>
          <textarea name="<?= e($__k) ?>" rows="3" placeholder="Enter <?= e(strtolower($__label)) ?>"><?= e($venue[$__k] ?? '') ?></textarea>
        </div>
        <?php endforeach; ?>

        <button type="submit" class="btn-primary btn-sm" data-tip="Save the guest stay details">Save Stay Info</button>
      </form>
    </div>
  </div>
</div>

<!-- ── TAB: Rooms ── -->
<div class="tab-panel" id="tab-rooms">
  <div class="card">
    <div class="card__head">
      <span class="card__title">Rooms in this Property</span>
      <a href="/admin/room-edit.php?venue=<?= $id ?>" class="btn-sm btn-primary" data-tip="Create a new room in this property">+ Add room</a>
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
</div>

<!-- ── TAB: Rates ── -->
<div class="tab-panel" id="tab-rates">
  <?php if (!$rooms): ?>
  <div class="card"><div class="card__body">
    <p style="padding:24px;text-align:center;color:var(--muted)">Add a room to this property before setting rates.</p>
  </div></div>
  <?php else: ?>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title">Set a rate</span>
      <span class="text-muted" style="font-size:12px">One price and label across as many date ranges as you need</span>
    </div>
    <div class="card__body" style="padding:20px">
      <?php
        $rf_room_id = null;                     // property page → show the room selector
        $rf_rooms   = $rooms;
        $rf_action  = '/admin/venue-edit.php?id=' . (int)$id;
        include __DIR__ . '/../includes/rate-form.php';
      ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title">Calendar — <?= e($rateRoom['name']) ?></span>
      <form method="GET" style="display:flex;gap:8px;align-items:center;margin:0">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="rate_month" value="<?= e($rateMonth) ?>">
        <select name="rate_room" class="eselect" onchange="this.form.submit()">
          <?php foreach ($rooms as $r): ?>
          <option value="<?= (int)$r['id'] ?>"<?= (int)$r['id'] === (int)$rateRoom['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="card__body" style="padding:20px">
      <?php
        $rc_room_id       = (int)$rateRoom['id'];
        $rc_default_price = (float)$rateRoom['price_amount'];
        $rc_currency      = (string)$rateRoom['price_currency'];
        $rc_month         = $rateMonth;
        $rc_base_url      = '/admin/venue-edit.php?id=' . (int)$id . '&rate_room=' . (int)$rateRoom['id'];
        include __DIR__ . '/../includes/rate-calendar.php';
      ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">All overrides in this property</span></div>
    <div class="card__body" style="padding:0">
      <?php if (!$venueRates): ?>
      <p style="padding:24px;text-align:center;color:var(--muted)">No overrides yet. Default room prices apply.</p>
      <?php else: ?>
      <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Room</th><th>First night</th><th>Last night</th><th>Price/night</th><th>Label</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($venueRates as $rt): ?>
          <tr>
            <td><strong><?= e($rt['room_name']) ?></strong></td>
            <td><?= e(date('j M Y', strtotime((string)$rt['date_from']))) ?></td>
            <td><?= e(date('j M Y', strtotime((string)$rt['date_to'] . ' -1 day'))) ?></td>
            <td><?= e(number_format((float)$rt['price_amount'], 0)) ?></td>
            <td><?= e((string)($rt['label'] ?? '')) ?></td>
            <td style="text-align:right">
              <form method="POST" style="display:inline" onsubmit="return confirm('Remove this rate?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="rate_delete">
                <input type="hidden" name="rate_id" value="<?= (int)$rt['id'] ?>">
                <input type="hidden" name="room_id" value="<?= (int)$rt['room_id'] ?>">
                <button type="submit" class="btn-icon" title="Remove this rate"><?= admin_icon('trash', 15) ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<!-- ── TAB: Gallery ── -->
<div class="tab-panel" id="tab-gallery">
  <div class="card">
    <div class="card__head"><span class="card__title">Upload Photos</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="dropzone" id="dropzone">
          <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.4" style="color:var(--muted)"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <p>Drop images here or <label for="gallery_upload" style="color:var(--brand);cursor:pointer;text-decoration:underline">browse</label></p>
          <p>JPEG, PNG, WebP · max 5 MB each · the first becomes the main image</p>
          <input type="file" id="gallery_upload" name="gallery_upload[]" multiple accept="image/jpeg,image/png,image/webp" style="display:none">
        </div>
        <div style="padding:12px 0 0">
          <button type="submit" class="btn-primary btn-sm" data-tip="Upload the selected photos">Upload selected</button>
        </div>
      </form>
      <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)">
        <p class="text-muted" style="margin:0 0 10px;font-size:13px">…or reuse a photo already on the site:</p>
        <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" class="gallery-libform">
          <?= csrf_field() ?>
          <input type="hidden" name="gallery_action" value="add_library">
          <input type="hidden" id="venueLibKey" name="image_key" value="">
          <button type="button" class="btn-outline btn-sm" data-mp-open="venueLibKey"><?= admin_icon('image', 15) ?> Choose from library</button>
        </form>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Gallery <span class="text-muted">(drag to reorder)</span></span></div>
    <div class="card__body">
      <?php if (!$images): ?>
      <p style="padding:24px;text-align:center;color:var(--muted)">No photos yet — upload some above.</p>
      <?php else: ?>
      <div class="gallery-bulkbar" data-gallery-bulk data-grid="venueGallery"
           data-endpoint="/admin/venue-edit.php?id=<?= $id ?>" data-idfield="image_id"
           data-csrf="<?= e(csrf_token()) ?>">
        <label class="ckwrap"><input type="checkbox" data-bulk-all><span class="ck"></span> Select all</label>
        <span class="gallery-bulkbar__count" data-bulk-count>0 selected</span>
        <span class="gallery-bulkbar__spacer"></span>
        <button type="button" class="btn-outline btn-sm" data-bulk-setmain hidden data-tip="Make the selected photo the main image">Set as main</button>
        <button type="button" class="btn-outline btn-sm" data-bulk-clear>Clear</button>
        <button type="button" class="btn-danger btn-sm" data-bulk-delete disabled data-tip="Delete every selected photo">Delete selected (0)</button>
      </div>
      <div class="gallery-grid" id="venueGallery">
        <?php foreach ($images as $im): ?>
        <div class="gallery-item<?= $im['is_hero'] ? ' is-hero' : '' ?>" data-img-id="<?= (int)$im['id'] ?>" draggable="true">
          <label class="gallery-select" data-tip="Select photo"><input type="checkbox" data-bulk-item value="<?= (int)$im['id'] ?>"><span class="ck"></span></label>
          <?php if ($im['is_hero']): ?><span class="gallery-hero-badge">Main</span><?php endif; ?>
          <img src="<?= e(storage_url($im['filename'])) ?>" alt="<?= e($im['alt_text']) ?>">
          <div class="gallery-item__actions">
            <?php if (!$im['is_hero']): ?>
            <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" style="display:contents"><?= csrf_field() ?><input type="hidden" name="gallery_action" value="set_hero"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>"><button type="submit" data-tip="Make this the main image">★ Main</button></form>
            <?php endif; ?>
            <form method="POST" action="/admin/venue-edit?id=<?= $id ?>" style="display:contents"><?= csrf_field() ?><input type="hidden" name="gallery_action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>"><button type="submit" data-confirm="Delete this photo? This cannot be undone." data-tip="Delete photo" aria-label="Delete photo"><?= admin_icon('x', 14) ?></button></form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── TAB: Publish ── -->
<div class="tab-panel" id="tab-publish">
  <div class="card">
    <div class="card__head"><span class="card__title">Publish Status</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_publish">
        <label class="togglerow" style="margin-bottom:16px">
          <span class="toggle">
            <input type="checkbox" name="is_published" value="1" <?= $venue['is_published'] ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </span>
          <span><?= $venue['is_published'] ? '<strong>Published</strong> — visible on the public site' : '<strong>Hidden</strong> — not visible to guests' ?></span>
        </label>
        <div><button type="submit" class="btn-sm btn-outline" data-tip="Save the publish status">Update status</button></div>
      </form>
    </div>
  </div>

  <div class="card" style="border:1.5px solid var(--red)">
    <div class="card__head"><span class="card__title" style="color:var(--red)">Danger Zone</span></div>
    <div class="card__body" style="padding:20px">
      <p style="font-size:13px;color:var(--muted);margin:0 0 16px">Deleting a property removes it permanently. Its rooms are kept but detached from this property.</p>
      <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_venue">
        <button type="submit" class="btn-danger btn-sm" data-confirm="Delete this property? Its rooms are kept but detached." data-tip="Permanently delete this property">Delete property</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ── Tabs (remember the active tab across saves) ──
(function () {
  var KEY = 'ts_venue_tab';
  var btns = document.querySelectorAll('.tab-btn');
  if (!btns.length) return;
  function activate(tab) {
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === tab); });
    document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.toggle('is-active', p.id === 'tab-' + tab); });
  }
  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      activate(b.dataset.tab);
      try { sessionStorage.setItem(KEY, b.dataset.tab); } catch (e) {}
    });
  });
  var saved; try { saved = sessionStorage.getItem(KEY); } catch (e) {}
  if (saved && document.getElementById('tab-' + saved)) activate(saved);
})();

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

// ── Dropzone (drag-drop + browse, filename readout) ──
(function () {
  var dz = document.getElementById('dropzone');
  var fi = document.getElementById('gallery_upload');
  if (!dz || !fi) return;
  var hint = dz.querySelector('p');
  var hintText = hint ? hint.innerHTML : '';
  dz.addEventListener('dragover',  function (e) { e.preventDefault(); dz.classList.add('is-over'); });
  dz.addEventListener('dragleave', function () { dz.classList.remove('is-over'); });
  dz.addEventListener('drop', function (e) {
    e.preventDefault(); dz.classList.remove('is-over');
    fi.files = e.dataTransfer.files;
    if (hint) hint.textContent = e.dataTransfer.files.length + ' file(s) selected';
  });
  fi.addEventListener('change', function () {
    if (hint) hint.textContent = fi.files.length ? fi.files.length + ' file(s) selected' : '';
    if (hint && !fi.files.length) hint.innerHTML = hintText;
  });
})();

// Library pick → submit the add-from-library form (PRG reload shows the photo).
(function () {
  document.querySelectorAll('.gallery-libform input[name="image_key"]').forEach(function (inp) {
    inp.addEventListener('change', function () { if (inp.value && inp.form) inp.form.submit(); });
  });
})();
</script>

<?php media_picker_modal(); ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
