# Editable Property Gallery — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each property page's hero gallery DB-driven and editable from the admin (upload/reorder/set-main/delete), pre-loaded with each property's current photos.

**Architecture:** New `venue_images` table + `fetch_venue_images()`; a self-contained `includes/property-gallery.php` renders the hero grid (reusing the existing `.gallery*` classes) + its own lightbox from the DB; the Property edit page gains a gallery uploader ported from the room editor; the 6 property pages swap their hard-coded `.gallery` for the include; a seed pre-loads existing photos. Reuses `storage_put`/`storage_url`; no URL changes.

**Tech Stack:** PHP 8 + PostgreSQL (PDO), GD image re-encode, the existing `includes/storage.php`, vanilla JS lightbox.

Spec: `docs/superpowers/specs/2026-06-04-editable-property-gallery-design.md`.

**Conventions:** No test framework — verify with `php -l`, `psql`, throwaway `php -S` + `curl`, and admin upload via an authenticated session. Postgres v18 client `/Applications/Postgres.app/Contents/Versions/18/bin/psql` (user/db `patrikgiuliana`/`tribalsand`). Branch `main`. End every commit with a trailing blank line + `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## Task 1: `venue_images` table + helper

**Files:** Create `db/migrations/add_venue_images.sql`; Modify `includes/db.php`

- [ ] **Step 1: Migration**
```sql
CREATE TABLE IF NOT EXISTS venue_images (
    id         SERIAL PRIMARY KEY,
    venue_id   INT          NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    filename   VARCHAR(255) NOT NULL,
    alt_text   VARCHAR(255),
    is_hero    BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_venue_images_venue_id ON venue_images(venue_id);
```
Run: `php bin/migrate.php db/migrations/add_venue_images.sql` → expect `OK`.

- [ ] **Step 2: Add `fetch_venue_images()` to `includes/db.php`.** After the existing `fetch_room_images()` function, add:
```php
function fetch_venue_images(int $venue_id): array {
    return db_query(
        'SELECT * FROM venue_images WHERE venue_id = :id ORDER BY is_hero DESC, sort_order ASC',
        [':id' => $venue_id]
    )->fetchAll();
}
```

- [ ] **Step 3: Verify + commit**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l includes/db.php
/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA -c "SELECT count(*) FROM venue_images;"   # 0
php -r 'require "includes/db.php"; var_dump(fetch_venue_images(1));'   # array(0)
git add db/migrations/add_venue_images.sql includes/db.php
git commit -m "feat: add venue_images table + fetch_venue_images()"
```
Expected: lint clean; table exists (count 0); helper returns `array(0)`.

---

## Task 2: The gallery partial

**Files:** Create `includes/property-gallery.php`

- [ ] **Step 1: Write `includes/property-gallery.php`** (self-contained: reuses the page's `.gallery*` classes for the grid, ships its own `.pg-lb` lightbox + inline CSS; renders nothing if the venue has no images):
```php
<?php
/**
 * DB-driven property hero gallery + lightbox.
 * Usage: $pg_venue_slug = 'zuri'; include __DIR__ . '/includes/property-gallery.php';
 * Renders nothing if the venue has no images (page can keep a fallback gallery).
 */
require_once __DIR__ . '/db.php';
$pg_venue_slug = $pg_venue_slug ?? '';
$__pgv = $pg_venue_slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $pg_venue_slug])->fetch() : false;
if (!$__pgv) { return; }
$__imgs = fetch_venue_images((int)$__pgv['id']);
if (!$__imgs) { return; }
$__badge  = trim($__pgv['name'] . (!empty($__pgv['location']) ? ' · ' . $__pgv['location'] : ''));
$__urls   = array_map(fn($im) => storage_url($im['filename']), $__imgs);
$__thumbs = array_slice($__imgs, 1, 2);
$__more   = max(0, count($__imgs) - 3);
?>
<div class="gallery" style="margin-top:0;">
  <div class="gallery-main" onclick="pgOpenLb(0)">
    <img src="<?= e(storage_url($__imgs[0]['filename'])) ?>" alt="<?= e($__imgs[0]['alt_text'] ?: $__pgv['name']) ?>" loading="eager">
    <?php if ($__badge): ?><div class="gallery-badge"><?= e($__badge) ?></div><?php endif; ?>
  </div>
  <?php foreach ($__thumbs as $ti => $t): $idx = $ti + 1; $isLast = ($idx === 2 && $__more > 0); ?>
  <div class="gallery-thumb<?= $isLast ? ' last' : '' ?>" onclick="pgOpenLb(<?= $idx ?>)">
    <img src="<?= e(storage_url($t['filename'])) ?>" alt="<?= e($t['alt_text'] ?: $__pgv['name']) ?>">
  </div>
  <?php endforeach; ?>
</div>

<div class="pg-lb" id="pgLb" hidden>
  <button class="pg-lb__close" type="button" data-pg-close aria-label="Close">&times;</button>
  <button class="pg-lb__nav pg-lb__prev" type="button" data-pg-prev aria-label="Previous">&#8249;</button>
  <figure class="pg-lb__stage"><img id="pgLbImg" alt=""></figure>
  <button class="pg-lb__nav pg-lb__next" type="button" data-pg-next aria-label="Next">&#8250;</button>
  <span class="pg-lb__count" id="pgLbCount"></span>
</div>
<style>
.pg-lb{position:fixed;inset:0;z-index:9999;background:rgba(20,20,18,.92);display:flex;align-items:center;justify-content:center}
.pg-lb[hidden]{display:none}
.pg-lb__stage{margin:0;max-width:90vw;max-height:86vh}
.pg-lb__stage img{max-width:90vw;max-height:86vh;object-fit:contain;display:block}
.pg-lb__close{position:absolute;top:18px;right:24px;background:none;border:none;color:#fff;font-size:2.4rem;line-height:1;cursor:pointer}
.pg-lb__nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.12);border:none;color:#fff;font-size:2rem;width:52px;height:52px;border-radius:50%;cursor:pointer}
.pg-lb__prev{left:24px}.pg-lb__next{right:24px}
.pg-lb__count{position:absolute;bottom:22px;left:50%;transform:translateX(-50%);color:#fff;font-size:.85rem;letter-spacing:.1em}
</style>
<script>
(function () {
  var imgs = <?= json_encode($__urls, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
  var lb = document.getElementById('pgLb'), img = document.getElementById('pgLbImg'), cnt = document.getElementById('pgLbCount'), i = 0;
  window.pgOpenLb = function (n) { i = (n + imgs.length) % imgs.length; render(); lb.hidden = false; document.body.style.overflow = 'hidden'; };
  function render() { img.src = imgs[i]; cnt.textContent = (i + 1) + ' / ' + imgs.length; }
  function close() { lb.hidden = true; document.body.style.overflow = ''; }
  function nav(d) { i = (i + d + imgs.length) % imgs.length; render(); }
  lb.querySelector('[data-pg-close]').addEventListener('click', close);
  lb.querySelector('[data-pg-prev]').addEventListener('click', function (e) { e.stopPropagation(); nav(-1); });
  lb.querySelector('[data-pg-next]').addEventListener('click', function (e) { e.stopPropagation(); nav(1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
  document.addEventListener('keydown', function (e) { if (lb.hidden) return; if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') nav(-1); else if (e.key === 'ArrowRight') nav(1); });
})();
</script>
```

- [ ] **Step 2: Lint + commit**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l includes/property-gallery.php
git add includes/property-gallery.php
git commit -m "feat: DB-driven property gallery partial (grid + self-contained lightbox)"
```
Expected: `No syntax errors detected`.

---

## Task 3: Seed existing photos into `venue_images`

**Files:** Create `db/seed_venue_images.sql`

- [ ] **Step 1: Extract each page's current gallery-grid image srcs.** For each of the 6 pages, grab the 3 `.gallery` block `<img src>`s (main + 2 thumbs):
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
for f in zuri my-amani maya-kobe enkare-bofa sandbox maya_ilai; do
  echo "--- $f ---"
  awk '/<div class="gallery"/{g=1} g{print} /<\/div>/{if(g)c++; if(c>=4)g=0}' "$f.php" | grep -oE 'src="[^"]+"' | sed 's/src="//;s/"//' | head -3
done
```
Record the (up to 3) distinct image paths per page. If a page repeats the same hero 3× (e.g. Zuri = `images/hero-zuri.jpg`×3), just seed that one image (no point in 3 identical). Prefer the distinct set if the grid uses different images.

- [ ] **Step 2: Write `db/seed_venue_images.sql`** using the Step-1 paths, as full `https://tribalsand.com/<path>` URLs (idempotent: delete the venue's images, then insert). Template (replace the example paths with the real per-page ones from Step 1; keep ≥1 per venue, the first `is_hero=TRUE`):
```sql
-- Pre-load each property's current gallery photos so pages look unchanged, then editable in admin.
-- Idempotent: clear each venue's images then insert. Full URLs render via the dev image proxy + in prod.

-- helper pattern repeated per venue:
DELETE FROM venue_images WHERE venue_id = (SELECT id FROM venues WHERE slug='zuri');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Zuri', x.hero, x.so
FROM venues v CROSS JOIN (VALUES
  ('https://tribalsand.com/images/hero-zuri.jpg', TRUE, 0)
  -- add more Zuri image URLs here as (url, FALSE, 1), (url, FALSE, 2), … if the grid/lightbox had distinct ones
) AS x(url,hero,so) WHERE v.slug='zuri';

DELETE FROM venue_images WHERE venue_id = (SELECT id FROM venues WHERE slug='my-amani');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'My Amani', x.hero, x.so
FROM venues v CROSS JOIN (VALUES
  ('https://tribalsand.com/images/my-amani/Aerial/myamani-11.webp', TRUE, 0),
  ('https://tribalsand.com/images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best18.jpg', FALSE, 1),
  ('https://tribalsand.com/images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best20.jpg', FALSE, 2)
) AS x(url,hero,so) WHERE v.slug='my-amani';

DELETE FROM venue_images WHERE venue_id = (SELECT id FROM venues WHERE slug='maya-kobe');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Maya Kobe', x.hero, x.so
FROM venues v CROSS JOIN (VALUES
  ('https://tribalsand.com/images/hero-maya-kobe.jpg', TRUE, 0),
  ('https://tribalsand.com/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg', FALSE, 1),
  ('https://tribalsand.com/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg', FALSE, 2)
) AS x(url,hero,so) WHERE v.slug='maya-kobe';

DELETE FROM venue_images WHERE venue_id = (SELECT id FROM venues WHERE slug='enkare-bofa');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, 'https://tribalsand.com/images/hero-enkare-bofa.jpg', 'Enkare Bofa', TRUE, 0 FROM venues v WHERE v.slug='enkare-bofa';

DELETE FROM venue_images WHERE venue_id = (SELECT id FROM venues WHERE slug='sandbox');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, 'https://tribalsand.com/images/hero-sandbox.jpg', 'Sandbox', TRUE, 0 FROM venues v WHERE v.slug='sandbox';

DELETE FROM venue_images WHERE venue_id = (SELECT id FROM venues WHERE slug='maya_ilai');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, 'https://tribalsand.com/images/Maya-Kobe-1-hero.webp', 'Maya Ilai', TRUE, 0 FROM venues v WHERE v.slug='maya_ilai';
```
(Use the comma-join form `FROM venues v, (VALUES …) AS x(...) WHERE v.slug=...` if `CROSS JOIN (VALUES …)` errors, as in the prior seed. Add the real distinct image URLs you found in Step 1 for each page — at minimum the hero, ideally 3.)

- [ ] **Step 3: Run + verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand"
$PSQL -f db/seed_venue_images.sql
$PSQL -tA -c "SELECT v.slug, count(vi.id), count(*) FILTER (WHERE vi.is_hero) FROM venues v LEFT JOIN venue_images vi ON vi.venue_id=v.id GROUP BY v.slug ORDER BY v.slug;"
```
Expected: each of the 6 venues has ≥1 image and exactly 1 hero (tribal-dunes 0 — fine).

- [ ] **Step 4: Commit**
```bash
git add db/seed_venue_images.sql
git commit -m "feat: pre-seed property galleries with existing photos"
```

---

## Task 4: Admin gallery uploader on the Property edit page

**Files:** Modify `admin/venue-edit.php`

> Port the room editor's image uploader. Read `admin/room-edit.php` lines ~145–236 (POST handlers: upload, `set_hero`, `delete_image`, reorder) and ~470–520 (the Gallery card markup) and ~720–740 (the dropzone JS). Copy them into `admin/venue-edit.php`, retargeting: `room_images`→`venue_images`, `room_id`→`venue_id`, `fetch_room_images`→`fetch_venue_images`, `$room`→`$venue`, and use the existing `$id` (venue id). venue-edit only renders the gallery when editing an existing venue (`!$isNew`).

- [ ] **Step 1: Load images + handle upload/gallery actions.** Near the top of `admin/venue-edit.php` after `$venue = …`, add:
```php
require_once __DIR__ . '/../includes/storage.php';
$images = $id ? fetch_venue_images($id) : [];
```
Inside the existing `if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))` block is for `save_details`/`publish`/`delete`. Add TWO new top-level POST handlers BEFORE that block (mirroring room-edit), guarded by `!$isNew`:

(a) Upload handler:
```php
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
```
(b) Gallery actions (set hero / delete / reorder):
```php
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
```

- [ ] **Step 2: Add the Gallery card markup** (only when `!$isNew`), after the "Rooms in this Property" card. Use:
```php
<?php if (!$isNew): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Gallery photos</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit.php?id=<?= $id ?>" enctype="multipart/form-data">
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
          <form method="POST" action="/admin/venue-edit.php?id=<?= $id ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="gallery_action" value="set_hero"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>"><button type="submit" class="btn-sm btn-outline" style="font-size:11px">Set main</button></form>
          <?php endif; ?>
          <form method="POST" action="/admin/venue-edit.php?id=<?= $id ?>" style="display:inline" onsubmit="return confirm('Delete this photo?')"><?= csrf_field() ?><input type="hidden" name="gallery_action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>"><button type="submit" class="btn-sm" style="font-size:11px;color:#c0392b;border:1px solid #c0392b;background:#fff;border-radius:4px">Delete</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$images): ?><p style="color:var(--muted);padding:12px 0">No photos yet — upload some above.</p><?php endif; ?>
  </div>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Add drag-reorder JS** before `_layout_end.php` include:
```php
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
```

- [ ] **Step 4: Verify (lint + an authenticated upload round-trip)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l admin/venue-edit.php
php -S localhost:8062 router.php >/tmp/pg4.log 2>&1 & SRV=$!; sleep 1
BASE=http://localhost:8062; PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
VID=$($PSQL -c "SELECT id FROM venues WHERE slug='sandbox'")
TOK=$(curl -s -b "$JAR" "$BASE/admin/venue-edit.php?id=$VID" | grep -oE 'name="csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')
# make a tiny test jpg and upload it
php -r '$im=imagecreatetruecolor(200,150);imagefilledrectangle($im,0,0,200,150,imagecolorallocate($im,30,92,107));imagejpeg($im,"/tmp/t.jpg",80);'
BEFORE=$($PSQL -c "SELECT count(*) FROM venue_images WHERE venue_id=$VID")
curl -s -b "$JAR" -X POST "$BASE/admin/venue-edit.php?id=$VID" -F "csrf_token=$TOK" -F "gallery_upload[]=@/tmp/t.jpg;type=image/jpeg" -o /dev/null
AFTER=$($PSQL -c "SELECT count(*) FROM venue_images WHERE venue_id=$VID")
echo "sandbox venue_images before=$BEFORE after=$AFTER (expect +1)"
# the Gallery card renders on the edit page:
curl -s -b "$JAR" "$BASE/admin/venue-edit.php?id=$VID" | grep -c "Gallery photos"
kill $SRV
# reset: remove the uploaded test image row (keep the seeded one)
$PSQL -c "DELETE FROM venue_images WHERE venue_id=$VID AND alt_text='Sandbox' AND filename NOT LIKE 'http%';" >/dev/null
```
Expected: lint clean; `after = before + 1` (upload created a `venue_images` row + stored the file); "Gallery photos" card count `1`.

- [ ] **Step 5: Commit**
```bash
git add admin/venue-edit.php
git commit -m "feat(admin): property gallery uploader (upload/set-main/delete/reorder)"
```

---

## Task 5: Wire the 6 property pages to the DB gallery

**Files:** Modify `zuri.php`, `my-amani.php`, `maya-kobe.php`, `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php`

> Each page has a hard-coded `<div class="gallery" style="margin-top:0;"> … </div>` (main + 2 thumbs) near the top, right after the header include. Replace JUST that `.gallery` block with the partial. Leave the page's existing inline lightbox markup/`openLb` script in place (now unused/dead, harmless — the partial ships its own `.pg-lb` lightbox with distinct ids).

For EACH page (slug per file): replace the `<div class="gallery" style="margin-top:0;"> … </div>` block with:
```php
<?php $pg_venue_slug = '<slug>'; include __DIR__ . '/includes/property-gallery.php'; ?>
```
(Read each page's gallery block to match its exact open/close — it's main `.gallery-main` + two `.gallery-thumb` divs inside one `.gallery` div.)

- [ ] **Step 1: Edit all 6 pages** as above (slugs: zuri, my-amani, maya-kobe, enkare-bofa, sandbox, maya_ilai).

- [ ] **Step 2: Verify (lint + DB gallery renders, page 200, badge present)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
for f in zuri my-amani maya-kobe enkare-bofa sandbox maya_ilai; do php -l "$f.php" >/dev/null || echo "LINT FAIL $f"; done
php -S localhost:8061 router.php >/tmp/pg5.log 2>&1 & SRV=$!; sleep 1
for p in zuri my-amani maya-kobe enkare-bofa sandbox maya_ilai; do
  out=$(curl -s "http://localhost:8061/$p")
  echo "$p: status=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8061/$p) gallery=$(echo "$out"|grep -c 'class=\"gallery\"') pgLightbox=$(echo "$out"|grep -c 'id=\"pgLb\"') pgOpenLb=$(echo "$out"|grep -c 'pgOpenLb(0)')"
done
kill $SRV
```
Expected each: `status=200 gallery=1 pgLightbox=1 pgOpenLb=1` (the DB gallery + its lightbox render).

- [ ] **Step 3: Commit**
```bash
git add zuri.php my-amani.php maya-kobe.php enkare-bofa.php sandbox.php maya_ilai.php
git commit -m "feat: property pages use the DB-driven editable gallery"
```

---

## Task 6: Verification sweep

**Files:** none

- [ ] **Step 1: All property galleries DB-driven + tribal-dunes unaffected**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -S localhost:8060 router.php >/tmp/pg6.log 2>&1 & SRV=$!; sleep 1
for p in zuri my-amani maya-kobe enkare-bofa sandbox maya_ilai tribal-dunes; do
  out=$(curl -s "http://localhost:8060/$p")
  echo "$p: pgGallery=$(echo "$out"|grep -c 'id=\"pgLb\"') status=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8060/$p)"
done
kill $SRV
```
Expected: the 6 properties `pgGallery=1 status=200`; `tribal-dunes pgGallery=0 status=200`.

- [ ] **Step 2: Booking still works (no regression from the gallery swap)** — spot-check one villa + one by-room page render their Rooms & Availability / bar:
```bash
php -S localhost:8060 router.php >/tmp/pg6b.log 2>&1 & SRV=$!; sleep 1
curl -s "http://localhost:8060/my-amani" | grep -c 'id="rrBar"'   # 1 (villa bar intact)
curl -s "http://localhost:8060/zuri" | grep -c 'suite-card rr-card'   # 6 (room cards intact)
kill $SRV
```
Expected: `1` and `6` (the gallery change didn't disturb the booking section).

---

## Self-review

**Spec coverage:**
- `venue_images` table + helper → Task 1 ✓
- DB-driven gallery partial (grid reusing `.gallery*` + own lightbox) → Task 2 ✓
- Pre-load existing photos → Task 3 ✓
- Admin uploader on Property edit (upload/set-main/delete/reorder via storage_put) → Task 4 ✓
- Swap 6 property pages to the partial; Tribal Dunes untouched → Task 5 (+ Task 6 confirms tribal-dunes) ✓
- Reuse storage; no URL changes; booking intact → Task 4 (storage_put) + Task 6 Step 2 ✓

**Placeholder scan:** the seed (Task 3) image URLs are filled from a concrete grep in Step 1 (the my-amani/maya-kobe examples are real paths found during planning; hero paths for the others are literal). No TBDs.

**Type/name consistency:** `venue_images`(venue_id, filename, alt_text, is_hero, sort_order) used identically in migration, helper, seed, admin handlers, and partial; `fetch_venue_images()` defined Task 1, used in Tasks 2 & 4; partial uses `pgOpenLb`/`#pgLb`/`.pg-lb*` (distinct from the pages' old `openLb`/`.gallery-lightbox`, so no collision); admin actions `gallery_upload[]`, `gallery_action` (`set_hero`/`delete_image`/`reorder`) consistent between handlers (Task 4 Step 1) and markup/JS (Steps 2–3); `$pg_venue_slug` set on pages (Task 5), consumed by the partial (Task 2).

**Known follow-up (out of scope):** richer pre-load (more than the hero per property) is easy to extend in the seed/admin; page text/sections remain hand-coded; uploaded files share the `assets/img/rooms/` storage dir (harmless).
