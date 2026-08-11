<?php
/**
 * Admin: guest board posts (updates / excursions / promotions).
 * List + create/edit/delete/publish-toggle. Image upload reuses the tour-edit pipeline.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_owner();

$pageTitle  = 'Guest board';
$activeMenu = 'guest_board';

$CATS   = ['update' => 'Update', 'excursion' => 'Excursion', 'promotion' => 'Promotion', 'event' => 'Event'];
$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll();
$flash  = '';
$errs   = [];

/** Handle an uploaded image → stored filename, or null. Mirrors tour-edit. */
function gb_handle_image(array &$errs): ?string {
    if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) { $errs[] = 'Upload failed.'; return null; }
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) { $errs[] = 'Image too large (max 5MB).'; return null; }
    require_once __DIR__ . '/../includes/storage.php';
    $tmp  = $_FILES['image']['tmp_name'];
    $mime = mime_content_type($tmp);
    $allowed = ['image/jpeg','image/png','image/webp'];
    if (!in_array($mime, $allowed, true)) { $errs[] = 'Invalid image type (JPEG/PNG/WebP only).'; return null; }
    $src = match($mime) {
        'image/png'  => imagecreatefrompng($tmp),
        'image/webp' => imagecreatefromwebp($tmp),
        default      => imagecreatefromjpeg($tmp),
    };
    if (!$src) { $errs[] = 'Could not process image.'; return null; }
    $w = imagesx($src); $h = imagesy($src);
    if ($w > 2000) {
        $nh = (int)round($h * 2000 / $w);
        $dst = imagecreatetruecolor(2000, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, 2000, $nh, $w, $h);
        imagedestroy($src); $src = $dst;
    }
    $filename = bin2hex(random_bytes(10)) . '.jpg';
    $tmp_out  = sys_get_temp_dir() . '/' . $filename;
    imagejpeg($src, $tmp_out, 88);
    imagedestroy($src);
    $stored = storage_put($tmp_out, $filename);
    @unlink($tmp_out);
    if ($stored === false) { $errs[] = 'Storage error.'; return null; }
    return $stored;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $category = $_POST['category'] ?? '';
        $title    = trim((string)($_POST['title'] ?? ''));
        $body     = trim((string)($_POST['body'] ?? ''));
        $venueRaw = $_POST['venue_id'] ?? '';
        $venueId  = ($venueRaw === '' ) ? null : (int)$venueRaw;
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $pub      = !empty($_POST['is_published']);
        $evRaw    = trim((string)($_POST['event_date'] ?? ''));
        $eventDate = $evRaw !== '' && strtotime($evRaw) !== false ? date('Y-m-d H:i:s', strtotime($evRaw)) : null;
        $priceRaw = $_POST['price_amount'] ?? '';
        $priceAmt = ($priceRaw === '' ) ? null : (float)$priceRaw;
        // Date/price are events-only. Previously a non-event silently nulled both,
        // so an owner who filled them in without noticing the Category dropdown
        // (which defaults to "Update" on a new post) lost the data and got a
        // "Post created" flash. Refuse instead of discarding.
        if ($category !== 'event' && ($evRaw !== '' || $priceRaw !== '')) {
            $errs[] = 'Pick the Event category to set a date or price.';
        }
        if ($category !== 'event') { $eventDate = null; $priceAmt = null; }

        if (!isset($CATS[$category])) $errs[] = 'Pick a category.';
        if ($title === '') $errs[] = 'Title is required.';
        if ($venueId !== null && !in_array($venueId, array_column($venues, 'id'), true)) $errs[] = 'Invalid property.';
        $existing = null;
        if ($id > 0) {
            $existing = db_query('SELECT image_filename FROM guest_board_posts WHERE id = :id', [':id'=>$id])->fetch();
            if (!$existing) $errs[] = 'That post no longer exists.';
        }

        // Only touch storage once the rest of the input is valid — avoids orphaned uploads.
        $newImage = $errs ? null : gb_handle_image($errs);

        if (!$errs) {
            if ($id > 0) {
                $img = $existing['image_filename'] ?? null;
                if (!empty($_POST['remove_image']) && $img) {
                    require_once __DIR__ . '/../includes/storage.php';
                    storage_delete($img); $img = null;
                }
                if ($newImage !== null) {
                    if ($img) { require_once __DIR__ . '/../includes/storage.php'; storage_delete($img); }
                    $img = $newImage;
                }
                db_query(
                    'UPDATE guest_board_posts SET venue_id=:v, category=:c, title=:t, body=:b,
                            image_filename=:img, is_published=:p, sort_order=:s,
                            event_date=:ed, price_amount=:pa, updated_at=now() WHERE id=:id',
                    [':v'=>$venueId, ':c'=>$category, ':t'=>$title, ':b'=>$body, ':img'=>$img,
                     ':p'=>$pub?'TRUE':'FALSE', ':s'=>$sort, ':ed'=>$eventDate, ':pa'=>$priceAmt, ':id'=>$id]
                );
                audit_log('guest_board_update', 'guest_board_post', $id, $title);
                $_SESSION['gb_flash'] = 'Post updated.';
            } else {
                db_query(
                    'INSERT INTO guest_board_posts (venue_id, category, title, body, image_filename, is_published, sort_order, event_date, price_amount)
                     VALUES (:v,:c,:t,:b,:img,:p,:s,:ed,:pa)',
                    [':v'=>$venueId, ':c'=>$category, ':t'=>$title, ':b'=>$body, ':img'=>$newImage,
                     ':p'=>$pub?'TRUE':'FALSE', ':s'=>$sort, ':ed'=>$eventDate, ':pa'=>$priceAmt]
                );
                $nid = (int)db()->lastInsertId();
                audit_log('guest_board_create', 'guest_board_post', $nid, $title);
                $_SESSION['gb_flash'] = 'Post created.';
            }
            header('Location: /admin/guest-board.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = db_query('SELECT image_filename FROM guest_board_posts WHERE id = :id', [':id'=>$id])->fetch();
        if ($row) {
            if (!empty($row['image_filename'])) { require_once __DIR__ . '/../includes/storage.php'; storage_delete($row['image_filename']); }
            db_query('DELETE FROM guest_board_posts WHERE id = :id', [':id'=>$id]);
            audit_log('guest_board_delete', 'guest_board_post', $id, '');
            $_SESSION['gb_flash'] = 'Post deleted.';
        }
        header('Location: /admin/guest-board.php');
        exit;
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db_query('UPDATE guest_board_posts SET is_published = NOT is_published, updated_at=now() WHERE id = :id', [':id'=>$id]);
        audit_log('guest_board_toggle', 'guest_board_post', $id, '');
        $_SESSION['gb_flash'] = 'Visibility updated.';
        header('Location: /admin/guest-board.php');
        exit;
    }
}

if (!empty($_SESSION['gb_flash'])) { $flash = $_SESSION['gb_flash']; unset($_SESSION['gb_flash']); }

// Search + pagination.
$pg = paginate_params(25);
$gbParams = [];
$gbWhere  = '';
$sw = search_where(['g.title', "COALESCE(g.body,'')", "COALESCE(v.name,'')", 'g.category'], $pg['q'], $gbParams);
if ($sw !== '') $gbWhere = "WHERE $sw";

$gbFrom = "FROM guest_board_posts g LEFT JOIN venues v ON v.id = g.venue_id $gbWhere";
$total  = (int) db_query("SELECT COUNT(*) $gbFrom", $gbParams)->fetchColumn();
$meta   = paginate_meta($total, $pg['page'], $pg['per']);

$posts = db_query(
    "SELECT g.*, v.name AS venue_name $gbFrom
     ORDER BY g.sort_order DESC, g.created_at DESC
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $gbParams
)->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    $edit = db_query('SELECT * FROM guest_board_posts WHERE id = :id', [':id'=>(int)$_GET['edit']])->fetch() ?: null;
}

// ── Swappable body (posts list + pager) — reused for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__head"><span class="card__title">All posts</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Title</th><th>Category</th><th>Property</th><th>Published</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$posts): ?>
        <tr><td colspan="5" style="padding:0"><?php dt_empty($pg['q'] !== '' ? 'No posts match your search.' : 'No posts yet.'); ?></td></tr>
        <?php else: foreach ($posts as $p): ?>
        <tr>
          <td><strong><?= e($p['title']) ?></strong></td>
          <td style="text-transform:capitalize"><?= e($p['category']) ?></td>
          <td><?= e($p['venue_name'] ?? 'All properties') ?></td>
          <td><?= $p['is_published'] ? 'Yes' : 'No' ?></td>
          <td>
            <div class="dt-actions">
              <a href="/admin/guest-board.php?edit=<?= (int)$p['id'] ?>" class="btn-icon btn-icon--outline" data-tip="Edit post" aria-label="Edit post"><?= admin_icon('edit') ?></a>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-icon btn-icon--outline" data-tip="<?= $p['is_published']?'Hide from guests':'Show to guests' ?>" aria-label="<?= $p['is_published']?'Hide from guests':'Show to guests' ?>"><?= admin_icon($p['is_published']?'ban':'eye') ?></button></form>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-icon btn-icon--danger" data-confirm="Delete this post?" data-tip="Delete post" aria-label="Delete post"><?= admin_icon('trash') ?></button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

if ($pg['ajax']) { echo $dtBody; exit; }

include __DIR__ . '/_layout.php';
?>

<?php $__openForm = $edit || !empty($errs); ?>
<div class="page-header">
  <h1>Guest board</h1>
  <div class="actions">
    <button type="button" class="btn-primary btn-sm" id="newPostBtn" aria-controls="postCard" aria-expanded="<?= $__openForm ? 'true' : 'false' ?>"><?= admin_icon('plus', 15) ?> New post</button>
    <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert--success"><?= e($flash) ?></div><?php endif; ?>
<?php foreach ($errs as $er): ?><div class="alert alert--error"><?= e($er) ?></div><?php endforeach; ?>

<div class="card" style="margin-bottom:24px" id="postCard" <?= $__openForm ? '' : 'hidden' ?>>
  <div class="card__head"><span class="card__title"><?= $edit ? 'Edit post' : 'New post' ?></span></div>
  <div class="card__body card__body--pad">
    <?php
      // On a validation error the form re-opens. Repopulate it from what was posted
      // so the owner isn't made to retype the whole post — rejecting their input is
      // only an improvement on silently discarding it if we hand it back.
      $src = $errs ? $_POST : ($edit ?? []);
      $fv  = fn(string $k, $d = '') => $src[$k] ?? $d;

      // Accepts both the posted 'Y-m-d\TH:i' and the stored 'Y-m-d H:i:s'.
      $__evSrc  = trim((string)$fv('event_date'));
      $__evTs   = $__evSrc !== '' ? strtotime($__evSrc) : false;
      $__evDate = $__evTs ? date('Y-m-d', $__evTs) : '';
      $__evTime = $__evTs ? date('H:i',   $__evTs) : '';

      $__price = '';
      if ($errs) {
          $__price = (string)$fv('price_amount');
      } elseif (isset($edit['price_amount']) && $edit['price_amount'] !== null) {
          $__price = rtrim(rtrim(number_format((float)$edit['price_amount'], 2, '.', ''), '0'), '.');
      }
      $__isEvent = $fv('category') === 'event';
      $__pub     = $errs ? !empty($_POST['is_published']) : (!$edit || !empty($edit['is_published']));
    ?>
    <form method="POST" enctype="multipart/form-data" id="gbForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$fv('id', 0) ?>">

      <div class="form-row" style="max-width:520px">
        <div class="field">
          <label>Property</label>
          <select name="venue_id" class="filter-select eselect--block" aria-label="Property">
            <option value="">All properties</option>
            <?php foreach ($venues as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= ((string)$fv('venue_id') !== '' && (int)$fv('venue_id')===(int)$v['id'])?'selected':'' ?>><?= e($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Category</label>
          <select name="category" class="filter-select eselect--block" aria-label="Category">
            <?php foreach ($CATS as $ck=>$cl): ?>
            <option value="<?= e($ck) ?>" <?= ($fv('category')===$ck)?'selected':'' ?>><?= e($cl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field" style="max-width:520px">
        <label for="gbTitle">Title</label>
        <input id="gbTitle" type="text" name="title" class="inp" required value="<?= e((string)$fv('title')) ?>" placeholder="Enter title" style="width:100%">
      </div>

      <div class="field" style="max-width:520px">
        <label for="gbBody">Body</label>
        <textarea id="gbBody" name="body" rows="3" class="inp" placeholder="Enter description" style="width:100%"><?= e((string)$fv('body')) ?></textarea>
      </div>

      <div class="field gb-eventonly"<?= $__isEvent ? '' : ' hidden' ?>>
        <label>Event date &amp; time</label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <button type="button" class="dp-btn" data-dp-target="gbEvDate" data-dp-placeholder="Select date"><?= $__evDate !== '' ? e(date('D j M Y', strtotime($__evDate))) : 'Select date' ?></button>
          <input type="hidden" id="gbEvDate" value="<?= e($__evDate) ?>">
          <select id="gbEvTime" class="filter-select" aria-label="Event time">
            <option value="">Time —</option>
            <?php for ($__h=0; $__h<24; $__h++): foreach (['00','30'] as $__m): $__t=sprintf('%02d:%s',$__h,$__m); ?>
            <option value="<?= $__t ?>" <?= $__evTime===$__t?'selected':'' ?>><?= $__t ?></option>
            <?php endforeach; endfor; ?>
          </select>
          <?php if ($__evDate !== ''): ?><button type="button" class="btn-outline btn-sm" id="gbEvClear">Clear</button><?php endif; ?>
        </div>
        <input type="hidden" name="event_date" id="gbEvOut" value="">
      </div>

      <div class="form-row" style="max-width:520px">
        <div class="field gb-eventonly"<?= $__isEvent ? '' : ' hidden' ?>>
          <label for="gbPrice">Price <span class="text-muted" style="font-weight:400">(blank = free)</span></label>
          <input id="gbPrice" type="number" name="price_amount" class="inp inp--num no-spin" step="0.01" min="0" value="<?= e($__price) ?>" placeholder="0" style="width:100%">
        </div>
        <div class="field">
          <label for="gbSort">Sort order <span class="text-muted" style="font-weight:400">(higher = pinned toward top)</span></label>
          <input id="gbSort" type="number" name="sort_order" class="inp inp--num no-spin" value="<?= (int)$fv('sort_order', 0) ?>" placeholder="0" style="width:100%">
        </div>
      </div>

      <div class="field">
        <label>Image <span class="text-muted" style="font-weight:400">(optional, JPEG/PNG/WebP)</span></label>
        <div class="filefield">
          <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('image', 15) ?> Choose image<input type="file" name="image" accept="image/*" data-file-input></label>
          <span class="filefield__name" data-file-name>No file chosen</span>
        </div>
      </div>
      <?php if (!empty($edit['image_filename'])): ?>
      <div class="field" style="display:flex;align-items:center;gap:14px">
        <img src="<?= e(storage_url($edit['image_filename'])) ?>" alt="" style="height:70px;border-radius:8px">
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="remove_image" value="1"><span class="toggle-slider"></span></span><span>Remove image</span></label>
      </div>
      <?php endif; ?>

      <div class="field">
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_published" value="1" <?= $__pub?'checked':'' ?>><span class="toggle-slider"></span></span><span>Published</span></label>
      </div>

      <button type="submit" class="btn-primary"><?= $edit ? 'Save changes' : 'Create post' ?></button>
      <?php if ($edit): ?><a href="/admin/guest-board.php" class="btn-outline btn-sm" style="margin-left:8px">Cancel</a>
      <?php else: ?><button type="button" class="btn-outline btn-sm" data-close-create style="margin-left:8px">Cancel</button><?php endif; ?>
    </form>

    <script>
    (function () {
      // Toggle the inline "New post" form from the header button.
      var btn = document.getElementById('newPostBtn'), card = document.getElementById('postCard');
      if (btn && card) {
        function setOpen(open) {
          if (open) { card.removeAttribute('hidden'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); var f = card.querySelector('input,select,textarea'); if (f) f.focus(); }
          else card.setAttribute('hidden', '');
          btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        btn.addEventListener('click', function () { setOpen(card.hasAttribute('hidden')); });
        card.querySelectorAll('[data-close-create]').forEach(function (c) { c.addEventListener('click', function () { setOpen(false); }); });
      }

      var form = document.getElementById('gbForm');
      if (!form) return;
      // The date and price fields belong to the Event category — show them only
      // then, so the values can never be typed into a post that would drop them.
      var gbCat = form.querySelector('select[name="category"]');
      function gbSyncEventFields() {
        var on = gbCat && gbCat.value === 'event';
        form.querySelectorAll('.gb-eventonly').forEach(function (el) { el.hidden = !on; });
      }
      if (gbCat) gbCat.addEventListener('change', gbSyncEventFields);
      gbSyncEventFields();
      // Compose the hidden event_date from the styled date picker + time select.
      var d = document.getElementById('gbEvDate'), t = document.getElementById('gbEvTime'), out = document.getElementById('gbEvOut');
      function compose() { out.value = (d.value ? d.value + (t.value ? 'T' + t.value : 'T00:00') : ''); }
      if (d) d.addEventListener('change', compose);
      if (t) t.addEventListener('change', compose);
      form.addEventListener('submit', compose);
      compose();
      var clr = document.getElementById('gbEvClear');
      if (clr) clr.addEventListener('click', function () {
        d.value = ''; if (t) t.value = ''; compose();
        var btn = form.querySelector('.dp-btn[data-dp-target="gbEvDate"]');
        if (btn) btn.textContent = 'Select date';
      });
      // Styled file input: show the chosen filename.
      var fi = form.querySelector('[data-file-input]'), fn = form.querySelector('[data-file-name]');
      if (fi && fn) fi.addEventListener('change', function () { fn.textContent = fi.files && fi.files.length ? fi.files[0].name : 'No file chosen'; });
    })();
    </script>
  </div>
</div>

<div class="dt" data-dt>
  <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search title, category or property…']); ?>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
