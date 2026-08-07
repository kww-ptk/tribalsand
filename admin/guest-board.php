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
        // Date/price are events-only — keep them off other categories.
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
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)"><?= $pg['q'] !== '' ? 'No posts match your search.' : 'Nothing to show yet.' ?></td></tr>
        <?php else: foreach ($posts as $p): ?>
        <tr>
          <td><strong><?= e($p['title']) ?></strong></td>
          <td style="text-transform:capitalize"><?= e($p['category']) ?></td>
          <td><?= e($p['venue_name'] ?? 'All properties') ?></td>
          <td><?= $p['is_published'] ? 'Yes' : 'No' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="/admin/guest-board.php?edit=<?= (int)$p['id'] ?>" class="btn-outline btn-sm">Edit</a>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-outline btn-sm"><?= $p['is_published']?'Hide':'Show' ?></button></form>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-danger btn-sm" data-confirm="Delete this post?">Delete</button></form>
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

<div class="page-header">
  <h1>Guest board</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
</div>

<?php if ($flash): ?><div class="alert alert--success"><?= e($flash) ?></div><?php endif; ?>
<?php foreach ($errs as $er): ?><div class="alert alert--error"><?= e($er) ?></div><?php endforeach; ?>

<div class="card" style="margin-bottom:24px">
  <div class="card__head"><span class="card__title"><?= $edit ? 'Edit post' : 'New post' ?></span></div>
  <div class="card__body">
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <label style="display:block;margin-bottom:12px">Property
        <select name="venue_id" style="display:block;width:100%;max-width:360px;margin-top:4px">
          <option value="">All properties</option>
          <?php foreach ($venues as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= (isset($edit['venue_id']) && (int)$edit['venue_id']===(int)$v['id'])?'selected':'' ?>><?= e($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label style="display:block;margin-bottom:12px">Category
        <select name="category" style="display:block;width:100%;max-width:360px;margin-top:4px">
          <?php foreach ($CATS as $ck=>$cl): ?>
          <option value="<?= e($ck) ?>" <?= (($edit['category'] ?? '')===$ck)?'selected':'' ?>><?= e($cl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label style="display:block;margin-bottom:12px">Title
        <input type="text" name="title" required value="<?= e($edit['title'] ?? '') ?>" placeholder="Enter title" style="display:block;width:100%;max-width:520px;margin-top:4px">
      </label>

      <label style="display:block;margin-bottom:12px">Body
        <textarea name="body" rows="3" placeholder="Enter description" style="display:block;width:100%;max-width:520px;margin-top:4px"><?= e($edit['body'] ?? '') ?></textarea>
      </label>

      <label style="display:block;margin-bottom:12px">Event date &amp; time <span style="color:var(--muted);font-weight:400">(events only)</span>
        <input type="datetime-local" name="event_date" value="<?= e(!empty($edit['event_date']) ? date('Y-m-d\TH:i', strtotime((string)$edit['event_date'])) : '') ?>" style="display:block;margin-top:4px">
      </label>
      <label style="display:block;margin-bottom:12px">Price <span style="color:var(--muted);font-weight:400">(events only — blank = free)</span>
        <input type="number" name="price_amount" step="0.01" min="0" value="<?= e(isset($edit['price_amount']) && $edit['price_amount'] !== null ? rtrim(rtrim(number_format((float)$edit['price_amount'],2,'.',''),'0'),'.') : '') ?>" placeholder="Enter price" style="display:block;width:160px;margin-top:4px">
      </label>

      <label style="display:block;margin-bottom:12px">Sort order (higher = pinned toward top)
        <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>" placeholder="Enter sort order" style="display:block;width:120px;margin-top:4px">
      </label>

      <label style="display:block;margin-bottom:12px">Image (optional, JPEG/PNG/WebP)
        <input type="file" name="image" accept="image/*" style="display:block;margin-top:4px">
      </label>
      <?php if (!empty($edit['image_filename'])): ?>
      <div style="margin-bottom:12px">
        <img src="<?= e(storage_url($edit['image_filename'])) ?>" alt="" style="height:70px;border-radius:6px;vertical-align:middle">
        <label style="margin-left:10px"><input type="checkbox" name="remove_image" value="1"> Remove image</label>
      </div>
      <?php endif; ?>

      <label style="display:block;margin-bottom:16px"><input type="checkbox" name="is_published" value="1" <?= (!$edit || !empty($edit['is_published']))?'checked':'' ?>> Published</label>

      <button type="submit" class="btn-primary"><?= $edit ? 'Save changes' : 'Create post' ?></button>
      <?php if ($edit): ?><a href="/admin/guest-board.php" class="btn-outline btn-sm" style="margin-left:8px">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="dt" data-dt>
  <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search title, category or property…']); ?>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
