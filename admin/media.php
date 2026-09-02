<?php
declare(strict_types=1);
/**
 * Media library — browse every image on the site and upload new ones.
 *
 * Doubles as the upload endpoint for the picker modal: POST with ?ajax=1
 * returns JSON {ok,key,url} instead of re-rendering the page.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/media.php';
require_login();
require_owner();                       // site-wide content, same tier as Site Menu

$ajax    = isset($_GET['ajax']);
$error   = '';
$success = '';

/** Resize, store and index one uploaded image. Returns [key, error]. */
function media_handle_upload(array $file, ?int $adminId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['', 'No file received.'];
    if (($file['size'] ?? 0) > 5 * 1024 * 1024)                   return ['', 'File too large (max 5MB).'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return ['', 'Invalid file type.'];

    $src = match ($mime) {
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        default      => imagecreatefromjpeg($file['tmp_name']),
    };
    if (!$src) return ['', 'Could not process image.'];

    $w = imagesx($src); $h = imagesy($src);
    if ($w > 2000) {                                    // same cap as the venue galleries
        $nh  = (int) round($h * 2000 / $w);
        $dst = imagecreatetruecolor(2000, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, 2000, $nh, $w, $h);
        imagedestroy($src); $src = $dst; $w = 2000; $h = $nh;
    }
    $filename = seo_filename((string)($file['name'] ?? ''), 'media');
    $tmp_out  = sys_get_temp_dir() . '/' . $filename;
    imagejpeg($src, $tmp_out, 88);
    imagedestroy($src);
    $bytes  = (int) @filesize($tmp_out);
    $stored = storage_put($tmp_out, $filename);
    @unlink($tmp_out);
    if ($stored === false) return ['', 'Storage error — check the R2/S3 credentials.'];

    media_record($stored, (string)($file['name'] ?? ''), $adminId, $bytes, $w, $h);
    return [$stored, ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$key, $err] = media_handle_upload($_FILES['image'] ?? [], $_SESSION['admin_id'] ?? null);
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode($key !== ''
            ? ['ok' => true, 'key' => $key, 'url' => media_url($key)]
            : ['ok' => false, 'error' => $err]);
        exit;
    }
    if ($key !== '') { audit_log('media.upload', 'media', 0, $key); $success = 'Image uploaded.'; }
    else             { $error = $err; }
}

$search = trim((string)($_GET['q'] ?? ''));
$items  = media_library_items($search);

$pageTitle  = 'Media Library';
$activeMenu = 'media';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Media Library</h1>
</div>

<?php if ($error): ?><div class="card" style="border-left:4px solid var(--red,#dc2626);margin-bottom:16px"><div class="card__body" style="padding:14px 18px;font-size:14px"><?= e($error) ?></div></div><?php endif; ?>
<?php if ($success): ?><div class="card" style="border-left:4px solid var(--green,#16a34a);margin-bottom:16px"><div class="card__body" style="padding:14px 18px;font-size:14px"><?= e($success) ?></div></div><?php endif; ?>

<?php if (!media_supported()): ?>
<div class="card"><div class="card__body" style="padding:20px;font-size:14px">
  Uploads are unavailable until the <code>add_media.sql</code> migration is applied.
  Images already attached to a venue, room or tour gallery are still listed below.
</div></div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title">Upload</span>
    <span class="text-muted" style="font-size:12px">JPG, PNG or WebP · max 5MB · resized to 2000px wide</span>
  </div>
  <div class="card__body" style="padding:20px">
    <form method="POST" enctype="multipart/form-data" action="/admin/media.php">
      <?= csrf_field() ?>
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
      <button type="submit" class="btn-primary btn-sm" style="margin-left:10px">Upload</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">All images</span>
    <span class="text-muted" style="font-size:12px"><?= count($items) ?> image<?= count($items) === 1 ? '' : 's' ?> · library plus every venue, room and tour gallery</span>
  </div>
  <div class="card__body" style="padding:20px">
    <form method="GET" action="/admin/media.php" style="margin-bottom:16px">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search filename…" class="inp" style="max-width:280px;padding:8px 10px">
      <button type="submit" class="btn-outline btn-sm">Search</button>
      <?php if ($search !== ''): ?><a href="/admin/media.php" class="btn-outline btn-sm">Clear</a><?php endif; ?>
    </form>
    <?php if (!$items): ?>
      <p class="text-muted" style="margin:0;font-size:13px">No images<?= $search !== '' ? ' match that search' : ' yet' ?>.</p>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px">
      <?php foreach ($items as $it): ?>
      <figure style="margin:0;border:1px solid var(--border,#e5e7eb);border-radius:7px;overflow:hidden;background:var(--bg,#f9fafb)">
        <img src="<?= e($it['url']) ?>" alt="" loading="lazy" decoding="async" style="width:100%;aspect-ratio:4/3;object-fit:cover;display:block">
        <figcaption style="padding:6px 8px;font:10.5px/1.35 ui-monospace,Menlo,monospace;color:var(--muted,#6b7280);word-break:break-all">
          <span style="font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--sand,#B8965A)"><?= e($it['source']) ?></span><br>
          <?= e($it['key']) ?>
        </figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
