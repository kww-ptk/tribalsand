# Guest Board + Desktop Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin-authored, per-property "guest board" (updates/excursions/promotions) to the portal Home, and widen the portal on desktop so cards flow into multiple columns.

**Architecture:** One new table `guest_board_posts` (nullable `venue_id` for per-property vs global). Guest side is read-only: a `fetch_guest_board()` helper + a Home section, gated by the guest's venue (added to the hold lookup). Admin side is a new CRUD page reusing the existing `storage_put` image pipeline. Desktop layout is pure CSS: widen `.pa-wrap`/`.pa-nav` at ≥720px and add a `.pa-grid` utility applied to card lists.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via PDO (`db_query()`), vanilla CSS/JS. No build system.

---

## File Structure

- Create: `db/migrations/add_guest_board.sql` — the new table.
- Modify: `db/schema.sql` — append the table for fresh installs.
- Modify: `includes/booking.php` — add `venue_id` to hold lookups; add `fetch_guest_board()`.
- Modify: `css/portal-app.css` — desktop widen + `.pa-grid` + `.pa-tag--*`.
- Modify: `includes/app/activities.php`, `includes/app/home.php` — wrap card lists in `.pa-grid`; add board section to home.
- Create: `admin/guest-board.php` — admin CRUD.
- Modify: `admin/_layout.php` — nav link.
- Modify: `tests/portal_logic.php` — `fetch_guest_board()` assertions.

---

## Task 1: DB migration + schema

**Files:**
- Create: `db/migrations/add_guest_board.sql`
- Modify: `db/schema.sql` (append at end)

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_guest_board.sql`:

```sql
-- Migration: guest board posts (admin-authored updates / excursions / promotions)
-- Run via /admin/migrate.php (or: php bin/migrate.php db/migrations/add_guest_board.sql)
-- Idempotent — safe to re-run.

CREATE TABLE IF NOT EXISTS guest_board_posts (
    id             SERIAL PRIMARY KEY,
    venue_id       INT REFERENCES venues(id) ON DELETE CASCADE,   -- NULL = all properties
    category       TEXT NOT NULL CHECK (category IN ('update','excursion','promotion')),
    title          TEXT NOT NULL,
    body           TEXT NOT NULL DEFAULT '',
    image_filename TEXT,
    is_published   BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order     INT NOT NULL DEFAULT 0,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_gbp_visible
    ON guest_board_posts (is_published, venue_id, sort_order DESC, created_at DESC);
```

- [ ] **Step 2: Apply it to the local dev DB**

Run: `D:\php84\php.exe -r "require 'includes/db.php'; db()->exec(file_get_contents('db/migrations/add_guest_board.sql')); echo 'ok';"`
(macOS dev: `php -r "..."`.)
Expected: `ok`

- [ ] **Step 3: Verify the table exists**

Run: `php -r "require 'includes/db.php'; var_dump(db_query('SELECT COUNT(*) FROM guest_board_posts')->fetchColumn());"`
Expected: `int(0)` (no error).

- [ ] **Step 4: Append the table to `db/schema.sql`**

Add the same `CREATE TABLE IF NOT EXISTS guest_board_posts (...)` block (and the index) to the end of `db/schema.sql` so fresh installs get it. Use the exact DDL from Step 1.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/add_guest_board.sql db/schema.sql
git commit -m "feat(board): add guest_board_posts table + migration"
```

---

## Task 2: Guest-side helpers + tests

**Files:**
- Modify: `includes/booking.php` (`fetch_hold_for_guest`, `resolve_booking_by_code_only`, new `fetch_guest_board`)
- Modify: `tests/portal_logic.php`

- [ ] **Step 1: Expose the guest's venue in the hold lookups**

In `includes/booking.php`, in `fetch_hold_for_guest()`, change the SELECT list to also fetch the venue id. The current query selects `v.name AS venue_name`; add `r.venue_id AS venue_id`:

```php
    return db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug,
                r.venue_id AS venue_id, v.name AS venue_name
         FROM holds h
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         WHERE h.id = :id",
        [':id' => $holdId]
    )->fetch();
```

Do the identical addition (`r.venue_id AS venue_id`) to the SELECT in `resolve_booking_by_code_only()` (same join shape, `WHERE h.access_code = :code`).

- [ ] **Step 2: Add the `fetch_guest_board()` helper**

In `includes/booking.php`, next to `fetch_portal_activities()`, add:

```php
/**
 * Published guest-board posts visible to a guest at the given venue.
 * $venueId null (or a venue with no targeted posts) → only global posts (venue_id IS NULL).
 */
function fetch_guest_board(?int $venueId): array {
    return db_query(
        "SELECT id, category, title, body, image_filename
         FROM guest_board_posts
         WHERE is_published = TRUE
           AND (venue_id IS NULL OR venue_id = :venue)
         ORDER BY sort_order DESC, created_at DESC
         LIMIT 6",
        [':venue' => $venueId]
    )->fetchAll();
}
```

- [ ] **Step 3: Add tests**

In `tests/portal_logic.php`, before the final summary line, add DB-backed assertions. These seed two posts (one global, one venue-scoped), assert visibility, then clean up:

```php
// ── guest board ──────────────────────────────────────────────
$vid = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
db_query("INSERT INTO guest_board_posts (venue_id, category, title, body) VALUES (NULL, 'update', 'ZZ Global Test', '')");
$gGlobal = (int)db()->lastInsertId();
$gScoped = 0;
if ($vid) {
    db_query("INSERT INTO guest_board_posts (venue_id, category, title, body) VALUES (:v, 'promotion', 'ZZ Scoped Test', '')", [':v'=>$vid]);
    $gScoped = (int)db()->lastInsertId();
}

$boardNull = fetch_guest_board(null);
check('board(null) is a list', is_array($boardNull));
check('board(null) includes the global post',
      in_array('ZZ Global Test', array_column($boardNull, 'title'), true));
check('board(null) excludes venue-scoped posts',
      !in_array('ZZ Scoped Test', array_column($boardNull, 'title'), true));

if ($vid) {
    $boardVenue = fetch_guest_board($vid);
    check('board(venue) includes global + scoped',
          in_array('ZZ Global Test', array_column($boardVenue, 'title'), true) &&
          in_array('ZZ Scoped Test', array_column($boardVenue, 'title'), true));
    $otherVid = (int)(db()->query("SELECT id FROM venues WHERE id <> {$vid} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($otherVid) {
        $boardOther = fetch_guest_board($otherVid);
        check('board(other venue) excludes the scoped post',
              !in_array('ZZ Scoped Test', array_column($boardOther, 'title'), true));
    }
}

check('board rows expose category/title/body/image_filename',
      $boardNull === [] || (array_key_exists('category',$boardNull[0]) && array_key_exists('title',$boardNull[0])
                            && array_key_exists('body',$boardNull[0]) && array_key_exists('image_filename',$boardNull[0])));

db_query("DELETE FROM guest_board_posts WHERE id IN (:a, :b)", [':a'=>$gGlobal, ':b'=>$gScoped ?: -1]);
```

- [ ] **Step 4: Run the tests**

Run: `php tests/portal_logic.php`
Expected: `ALL PASS` (existing assertions + the new board ones).

- [ ] **Step 5: Commit**

```bash
git add includes/booking.php tests/portal_logic.php
git commit -m "feat(board): fetch_guest_board helper + venue_id on hold lookup + tests"
```

---

## Task 3: Desktop CSS — widen shell, grid utility, tag colors

**Files:**
- Modify: `css/portal-app.css`
- Modify: `includes/app/activities.php`

- [ ] **Step 1: Add desktop-widen, `.pa-grid`, and `.pa-tag` rules to `css/portal-app.css`**

Append at the end of the file:

```css
/* ── Guest-board category tags ── */
.pa-tag{display:inline-block;font-size:11px;font-weight:500;padding:3px 10px;border-radius:999px;text-transform:capitalize;}
.pa-tag--update{background:#E6F1FB;color:#0C447C;}
.pa-tag--excursion{background:#E1F5EE;color:#0F6E56;}
.pa-tag--promotion{background:#FAEEDA;color:#854F0B;}

/* ── Responsive card grid (browse-style lists) ── */
.pa-grid{display:grid;grid-template-columns:1fr;gap:12px;}

/* ── Desktop: widen the shell + flow cards into columns ── */
@media (min-width:720px){
  .pa-wrap{max-width:880px;}
  .pa-nav{max-width:880px;}
  .pa-grid{grid-template-columns:repeat(auto-fill,minmax(240px,1fr));}
}
```

- [ ] **Step 2: Wrap the Activities card list in `.pa-grid`**

In `includes/app/activities.php`, the activity `.pa-card`s are emitted directly by the `foreach`. Wrap that loop's output in a grid container. Change the block that begins `<?php if (!$__acts): ?>` so the cards live inside `<div class="pa-grid">…</div>`:

```php
<?php if (!$__acts): ?>
  <p class="pa-sub">Experiences will appear here soon.</p>
<?php else: ?>
<div class="pa-grid">
<?php foreach ($__acts as $a):
    $img = trim((string)($a['hero'] ?? ''));
    $mediaClass = 'pa-media pa-media--' . preg_replace('/[^a-z]/','',strtolower((string)$a['category']));
    $style = $img !== '' ? 'background-image:url(\'' . e(storage_url($img)) . '\')' : '';
?>
  <div class="pa-card" data-cat="<?= e($a['category']) ?>">
    <div class="<?= e($mediaClass) ?>" style="<?= $style ?>">
      <?php if (!empty($a['tag_label'])): ?><span class="pa-media__tag"><?= e($a['tag_label']) ?></span><?php endif; ?>
    </div>
    <div class="pa-card__body">
      <p class="pa-card__title"><?= e($a['name']) ?></p>
      <div class="pa-card__meta">
        <?php if (!empty($a['duration'])): ?><span><?= e($a['duration']) ?></span><?php endif; ?>
        <?php if (!empty($a['short_desc'])): ?><span style="flex-basis:100%;margin-top:4px;color:var(--pa-muted)"><?= e($a['short_desc']) ?></span><?php endif; ?>
      </div>
      <?php if ($__active): ?>
      <form data-bm action="/api/booking-addon.php" style="margin-top:12px">
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <input type="hidden" name="kind" value="tour">
        <input type="hidden" name="tour_slug" value="<?= e($a['slug']) ?>">
        <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 8px"></div>
        <button type="submit" class="pa-btn pa-btn--primary">Request this activity</button>
        <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
```

(Only structural change: the `else:` now opens `<div class="pa-grid">`, and it closes `</div>` before `endif`. The card markup itself is unchanged.)

- [ ] **Step 3: Verify the JS category filter still works inside the grid**

The filter script sets `cd.style.display='none'` on hidden cards; grid items with `display:none` leave the flow. No JS change needed — just confirm by reading `includes/app/activities.php` that the `<script>` still selects `.pa-card[data-cat]` (it does).

- [ ] **Step 4: Lint**

Run: `php -l includes/app/activities.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add css/portal-app.css includes/app/activities.php
git commit -m "feat(portal): widen shell on desktop, add pa-grid + pa-tag; grid the activities list"
```

---

## Task 4: Guest board on Home

**Files:**
- Modify: `includes/app/home.php`

- [ ] **Step 1: Add the board section above the Concierge tile**

In `includes/app/home.php`, after the `Karibu` greeting line and before the `<?php if ($__active): ?>` Concierge tile, insert the board. First extend the top PHP block to fetch the board (guarded like the experiences fetch), then render it:

At the top, alongside `$__feat`, add the board fetch:

```php
<?php
  $__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
  try { $__board = fetch_guest_board($__venue); } catch (Throwable $e) { $__board = []; }
  $__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion'];
?>
```

Then, immediately after the `Karibu` `<div>…</div>` greeting, add:

```php
<?php if ($__board): ?>
<div class="pa-grid" style="margin:0 0 16px">
  <?php foreach ($__board as $p): $bimg = trim((string)($p['image_filename'] ?? '')); ?>
  <div class="pa-card">
    <?php if ($bimg !== ''): ?>
    <div class="pa-media" style="background-image:url('<?= e(storage_url($bimg)) ?>')"></div>
    <?php endif; ?>
    <div class="pa-card__body">
      <span class="pa-tag <?= e($__tagClass[$p['category']] ?? '') ?>"><?= e($p['category']) ?></span>
      <p class="pa-card__title" style="margin-top:8px"><?= e($p['title']) ?></p>
      <?php if (($p['body'] ?? '') !== ''): ?><p class="pa-card__meta" style="display:block;margin-top:4px;line-height:1.5"><?= e($p['body']) ?></p><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Lint**

Run: `php -l includes/app/home.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual smoke via CLI render check**

Run: `php -r "require 'includes/db.php'; require 'includes/booking.php'; echo function_exists('fetch_guest_board') ? 'helper ok' : 'MISSING';"`
Expected: `helper ok`.

- [ ] **Step 4: Commit**

```bash
git add includes/app/home.php
git commit -m "feat(board): render guest board at top of portal Home"
```

---

## Task 5: Admin editor

**Files:**
- Create: `admin/guest-board.php`
- Modify: `admin/_layout.php` (nav link)

- [ ] **Step 1: Add the admin nav link**

In `admin/_layout.php`, after the `stay-info.php` sidebar link (the `$activeMenu==='stay_info'` line), add:

```php
      <a href="/admin/guest-board.php" class="sidebar__link <?= ($activeMenu??'')==='guest_board' ? 'is-active':'' ?>">Guest board</a>
```

(Match the surrounding link markup — copy the exact `<a class="sidebar__link ...">` shape used by the neighbors, including any icon span if present.)

- [ ] **Step 2: Create `admin/guest-board.php`**

Full file:

```php
<?php
/**
 * Admin: guest board posts (updates / excursions / promotions).
 * List + create/edit/delete/publish-toggle. Image upload reuses the tour-edit pipeline.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pageTitle  = 'Guest board';
$activeMenu = 'guest_board';

$CATS   = ['update' => 'Update', 'excursion' => 'Excursion', 'promotion' => 'Promotion'];
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

        if (!isset($GLOBALS['CATS'][$category])) $errs[] = 'Pick a category.';
        if ($title === '') $errs[] = 'Title is required.';
        if ($venueId !== null && !in_array($venueId, array_column($venues, 'id'), true)) $errs[] = 'Invalid property.';

        $newImage = gb_handle_image($errs);

        if (!$errs) {
            if ($id > 0) {
                $existing = db_query('SELECT image_filename FROM guest_board_posts WHERE id = :id', [':id'=>$id])->fetch();
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
                            image_filename=:img, is_published=:p, sort_order=:s, updated_at=now() WHERE id=:id',
                    [':v'=>$venueId, ':c'=>$category, ':t'=>$title, ':b'=>$body, ':img'=>$img,
                     ':p'=>$pub?'TRUE':'FALSE', ':s'=>$sort, ':id'=>$id]
                );
                audit_log('guest_board_update', 'guest_board_post', $id, $title);
                $flash = 'Post updated.';
            } else {
                db_query(
                    'INSERT INTO guest_board_posts (venue_id, category, title, body, image_filename, is_published, sort_order)
                     VALUES (:v,:c,:t,:b,:img,:p,:s)',
                    [':v'=>$venueId, ':c'=>$category, ':t'=>$title, ':b'=>$body, ':img'=>$newImage,
                     ':p'=>$pub?'TRUE':'FALSE', ':s'=>$sort]
                );
                $nid = (int)db()->lastInsertId();
                audit_log('guest_board_create', 'guest_board_post', $nid, $title);
                $flash = 'Post created.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = db_query('SELECT image_filename FROM guest_board_posts WHERE id = :id', [':id'=>$id])->fetch();
        if ($row) {
            if (!empty($row['image_filename'])) { require_once __DIR__ . '/../includes/storage.php'; storage_delete($row['image_filename']); }
            db_query('DELETE FROM guest_board_posts WHERE id = :id', [':id'=>$id]);
            audit_log('guest_board_delete', 'guest_board_post', $id, '');
            $flash = 'Post deleted.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db_query('UPDATE guest_board_posts SET is_published = NOT is_published, updated_at=now() WHERE id = :id', [':id'=>$id]);
        audit_log('guest_board_toggle', 'guest_board_post', $id, '');
        $flash = 'Visibility updated.';
    }
}

$posts = db_query(
    'SELECT g.*, v.name AS venue_name FROM guest_board_posts g
     LEFT JOIN venues v ON v.id = g.venue_id
     ORDER BY g.sort_order DESC, g.created_at DESC'
)->fetchAll();

// Post being edited (from ?edit=ID), if any.
$edit = null;
if (isset($_GET['edit'])) {
    $edit = db_query('SELECT * FROM guest_board_posts WHERE id = :id', [':id'=>(int)$_GET['edit']])->fetch() ?: null;
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Guest board</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
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
        <input type="text" name="title" required value="<?= e($edit['title'] ?? '') ?>" style="display:block;width:100%;max-width:520px;margin-top:4px">
      </label>

      <label style="display:block;margin-bottom:12px">Body
        <textarea name="body" rows="3" style="display:block;width:100%;max-width:520px;margin-top:4px"><?= e($edit['body'] ?? '') ?></textarea>
      </label>

      <label style="display:block;margin-bottom:12px">Sort order (higher = pinned toward top)
        <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>" style="display:block;width:120px;margin-top:4px">
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

<div class="card">
  <div class="card__head"><span class="card__title">All posts</span></div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Title</th><th>Category</th><th>Property</th><th>Published</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$posts): ?>
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">No posts yet.</td></tr>
        <?php else: foreach ($posts as $p): ?>
        <tr>
          <td><strong><?= e($p['title']) ?></strong></td>
          <td style="text-transform:capitalize"><?= e($p['category']) ?></td>
          <td><?= e($p['venue_name'] ?? 'All properties') ?></td>
          <td><?= $p['is_published'] ? 'Yes' : 'No' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="/admin/guest-board.php?edit=<?= (int)$p['id'] ?>" class="btn-outline btn-sm">Edit</a>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-outline btn-sm"><?= $p['is_published']?'Hide':'Show' ?></button></form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this post?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-outline btn-sm" style="color:#dc2626">Delete</button></form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/guest-board.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verify page loads (auth redirect is expected without a session)**

Run: `php -r "\$_SERVER['REQUEST_METHOD']='GET'; require 'admin/guest-board.php';" 2>&1 | head -3`
Expected: no PHP fatal (a redirect/login notice is fine — it means `require_login()` ran).

- [ ] **Step 5: Commit**

```bash
git add admin/guest-board.php admin/_layout.php
git commit -m "feat(board): admin guest-board CRUD + image upload + nav link"
```

---

## Task 6: E2E + regression + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Run all tests**

Run: `php tests/portal_logic.php && php tests/manage_logic.php && php tests/convert_logic.php`
Expected: three `ALL PASS`.

- [ ] **Step 2: Lint all touched files**

Run: `for f in includes/booking.php includes/app/home.php includes/app/activities.php admin/guest-board.php admin/_layout.php; do php -l "$f"; done`
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: Browser E2E**

Start the dev server (`php -S localhost:8765 router.php`). Seed a confirmed hold at a venue and two board posts (one global, one for that venue) directly via SQL or the admin page. Then, using the browser preview:
  - Visit `booking.php?ref=<REF>&view=home` at **mobile** width → the board renders at the top, above Concierge, single column; tags color-coded.
  - Resize to **desktop** → shell is wider (~880px) and the board + Experiences cards flow into 2–3 columns.
  - Visit `&view=activities` on desktop → activity cards are in a grid; the category filter still hides/shows correctly.
  - Confirm a guest whose hold has a **different** venue does NOT see the venue-scoped post (change the hold's unit/room or seed a second hold).

- [ ] **Step 4: Clean up test data**

Delete any seeded holds, availability blocks, and `guest_board_posts` rows created during E2E. Confirm counts are back to baseline.

- [ ] **Step 5: Final commit (if any cleanup scripts/notes) + done**

No code changes expected here; if all green, the branch is ready for final review.

---

## Self-Review Notes
- Spec coverage: migration (T1), helper + venue exposure + tests (T2), desktop CSS + grid (T3), Home render (T4), admin CRUD (T5), verification (T6) — all spec sections covered.
- Type consistency: `fetch_guest_board(?int)` used with `$__venue` (nullable int) in home.php; `venue_id` alias added to both resolve paths; tag class map keys (`update/excursion/promotion`) match the DB CHECK and CSS classes.
- No placeholders: every step has concrete code or a concrete command. The one intentional correction note in T3 Step 1 tells the implementer the exact final CSS line to use.
