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
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — needed in the AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';       // paginate_params/meta
require_once __DIR__ . '/../includes/admin-pagination.php'; // dt_toolbar / dt_pager / dt_empty
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

// ── List state: search + server-side pagination (reusable dt_* toolkit) ──
$pg    = paginate_params(25);                 // page / per / q / offset / ajax
$all   = media_library_items($pg['q'], 2000); // full filtered set (library + every gallery)
$total = count($all);
$meta  = paginate_meta($total, $pg['page'], $pg['per']);
$items = array_slice($all, $meta['offset'], $meta['per']);

// ── Body fragment (grid + pager) — shared by the full render and the AJAX swap ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:20px">
    <?php if (!$items): ?>
      <?php dt_empty($pg['q'] !== '' ? 'No images match that search.' : 'No images yet — upload one above.', 'image'); ?>
    <?php else: ?>
    <div class="ml-grid">
      <?php foreach ($items as $it): ?>
      <figure class="ml-card">
        <div class="ml-thumb">
          <img src="<?= e($it['url']) ?>" alt="" loading="lazy" decoding="async"
               onerror="this.closest('.ml-thumb').classList.add('is-broken')">
          <span class="ml-thumb__fb" aria-hidden="true"><?= admin_icon('image', 24) ?><small>Unavailable</small></span>
        </div>
        <figcaption class="ml-cap">
          <span class="ml-badge"><?= e($it['source']) ?></span>
          <span class="ml-key" title="<?= e($it['key']) ?>"><?= e($it['key']) ?></span>
        </figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php dt_pager($meta); ?>
<?php
$dtBody = ob_get_clean();
// AJAX (search / paging / per-page): return ONLY the body fragment.
if ($pg['ajax'] && $_SERVER['REQUEST_METHOD'] === 'GET') { echo $dtBody; exit; }

$pageTitle  = 'Media Library';
$activeMenu = 'media';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1 style="display:inline-flex;align-items:center;gap:10px"><?= admin_icon('image', 22) ?> Media Library</h1>
</div>

<?php if ($error): ?><div class="alert alert--error is-flash" style="margin-bottom:16px"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert--success is-flash" style="margin-bottom:16px"><?= e($success) ?></div><?php endif; ?>

<?php if (!media_supported()): ?>
<div class="alert alert--error" style="margin-bottom:16px">
  Uploads are unavailable until the <code>add_media.sql</code> migration is applied.
  Images already attached to a venue, room or tour gallery are still listed below.
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title" style="display:inline-flex;align-items:center;gap:8px"><?= admin_icon('image', 16) ?> Upload</span>
    <span class="text-muted" style="font-size:12px">JPG, PNG or WebP · max 5MB · resized to 2000px wide</span>
  </div>
  <div class="card__body" style="padding:20px">
    <form method="POST" enctype="multipart/form-data" action="/admin/media.php" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <?= csrf_field() ?>
      <label class="filefield">
        <span class="btn-outline btn-sm"><?= admin_icon('image', 14) ?> Choose image</span>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required data-media-file>
        <span class="filefield__name" data-media-filename>No file chosen</span>
      </label>
      <button type="submit" class="btn-primary btn-sm" data-media-submit disabled><?= admin_icon('plus', 15) ?> Upload</button>
    </form>
  </div>
</div>

<div class="dt" data-dt>
  <div class="card" style="margin-bottom:16px">
    <div class="card__head" style="gap:12px;flex-wrap:wrap">
      <span class="card__title" style="display:inline-flex;align-items:center;gap:8px"><?= admin_icon('inbox', 16) ?> All images</span>
      <span class="text-muted" style="font-size:12px">Library plus every venue, room and tour gallery</span>
      <span style="flex:1 1 auto"></span>
      <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search filename…']); ?>
    </div>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<style>
.ml-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}
.ml-card{margin:0;border:1px solid var(--border,#e7ded7);border-radius:10px;overflow:hidden;background:var(--card,#fff);display:flex;flex-direction:column;transition:box-shadow .15s,border-color .15s}
.ml-card:hover{border-color:var(--sand,#B8965A);box-shadow:0 4px 14px rgba(16,47,58,.08)}
.ml-thumb{position:relative;aspect-ratio:4/3;background:var(--bg,#f4efe9)}
.ml-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.ml-thumb__fb{display:none;position:absolute;inset:0;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:var(--muted,#8a7f74)}
.ml-thumb__fb small{font-size:10px;letter-spacing:.06em;text-transform:uppercase}
.ml-thumb.is-broken img{display:none}
.ml-thumb.is-broken .ml-thumb__fb{display:flex}
.ml-cap{padding:8px 10px;display:flex;flex-direction:column;gap:4px;min-width:0}
.ml-badge{align-self:flex-start;font-size:9.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--sand,#B8965A);background:var(--bg,#f4efe9);border-radius:5px;padding:2px 6px}
.ml-key{font:11px/1.35 ui-monospace,Menlo,monospace;color:var(--muted,#8a7f74);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>

<script>
// Styled file field: reflect the chosen filename + enable the submit button.
(function () {
  var inp  = document.querySelector('[data-media-file]');
  var name = document.querySelector('[data-media-filename]');
  var btn  = document.querySelector('[data-media-submit]');
  if (!inp) return;
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    if (name) name.textContent = f ? f.name : 'No file chosen';
    if (btn)  btn.disabled = !f;
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
