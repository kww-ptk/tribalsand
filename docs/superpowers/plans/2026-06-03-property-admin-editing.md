# Property Admin Editing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the admin fully manage Properties (the `venues` table) like Rooms — a create/edit/delete page with a Property→Rooms→Calendar drill-down — and rename the UI from "Venue" to "Property", without ever changing a public URL.

**Architecture:** UI-only relabel (no DB/table renames). New `admin/venue-edit.php` mirrors `admin/room-edit.php`. Slugs are read-only on existing records to protect ranking URLs. "Calendar" reuses the existing `admin/gantt.php`, extended with an optional `?room=` filter. Existing FK `rooms.venue_id … ON DELETE SET NULL` makes property deletion safe.

**Tech Stack:** PHP 8 + PostgreSQL (PDO), the existing admin layout (`_layout.php`/`_layout_end.php`), CSRF via `csrf_field()`/`verify_csrf()`, `audit_log()`.

Spec: `docs/superpowers/specs/2026-06-03-property-admin-editing-design.md`.

**Conventions:** No test framework — verify with `php -l`, an authenticated `curl` session, `psql`, and `grep`. Dev server runs on `http://localhost:8080` (Preview "PHP Built-in Server"). Postgres v18 client: `/Applications/Postgres.app/Contents/Versions/18/bin/psql` (user/db `patrikgiuliana`/`tribalsand`). End every commit message with a trailing blank line then `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

**Reusable authenticated-request helper** (used in verification steps):
```bash
BASE=http://localhost:8080
authcurl_setup() { JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" \
  --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null; }
csrf_of() { curl -s -b "$JAR" "$1" | grep -oE 'name="csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/'; }
```

---

## File structure

- **Modify** `admin/_layout.php` — nav labels: "Venues"→"Properties", "For Sale"→"For Sale Listings".
- **Modify** `admin/venues.php` — header "Properties"; add Edit + "New Property" actions.
- **Create** `admin/venue-edit.php` — create/edit/delete a property + "Rooms in this Property" section.
- **Modify** `admin/room-edit.php` — slug read-only on edit; preselect venue for new rooms via `?venue=`; fix "View on site" link to `/<slug>`; add "Manage availability" link.
- **Modify** `admin/rooms.php` — add "Property" column + filter; fix "View" link to `/<slug>`.
- **Modify** `admin/gantt.php` — optional `?room=<id>` filter scoping the calendar to one property's room.

---

## Task 1: Rename nav labels

**Files:** Modify `admin/_layout.php`

- [ ] **Step 1: Relabel the two nav links** (labels only; keep the `$activeMenu` keys `venues` and `properties` so highlighting still works)

In `admin/_layout.php`, the Venues link currently reads (the text node after its SVG):
```
        Venues
```
Change that text to:
```
        Properties
```
And the For-Sale link currently reads:
```
        For Sale
```
Change that text to:
```
        For Sale Listings
```
Leave the `href`s, the `$activeMenu` comparisons (`==='venues'` and `==='properties'`), and the SVGs unchanged.

- [ ] **Step 2: Verify**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l admin/_layout.php
grep -nE ">\s*(Properties|For Sale Listings)\s*<" admin/_layout.php
grep -c ">Venues<\|>For Sale<" admin/_layout.php   # expect 0
```
Expected: lint clean; both new labels present; old labels gone.

- [ ] **Step 3: Commit**

```bash
git add admin/_layout.php
git commit -m "feat(admin): rename nav Venues→Properties, For Sale→For Sale Listings"
```

---

## Task 2: Properties list — relabel + actions

**Files:** Modify `admin/venues.php`

- [ ] **Step 1: Relabel the page + add a "New Property" button.** Replace the page-header block:

```php
<div class="page-header">
  <h1>Venues</h1>
  <span style="color:var(--muted);font-size:13px"><?= count($venues) ?> venue<?= count($venues) !== 1 ? 's' : '' ?></span>
</div>
```
with:
```php
<div class="page-header">
  <h1>Properties</h1>
  <a href="/admin/venue-edit.php" class="btn-primary btn-sm">+ New Property</a>
</div>
```

- [ ] **Step 2: Update the page title.** Change:
```php
$pageTitle  = 'Venues';
```
to:
```php
$pageTitle  = 'Properties';
```
(Leave `$activeMenu = 'venues';` — it matches the nav highlight key.)

- [ ] **Step 3: Add an Actions column with Edit + View links.** Change the table header row from:
```php
            <th>Name</th>
            <th>Slug</th>
            <th>Location</th>
            <th>Rooms</th>
            <th>Published</th>
```
to:
```php
            <th>Name</th>
            <th>Slug</th>
            <th>Location</th>
            <th>Rooms</th>
            <th>Published</th>
            <th></th>
```
And change the body row's published cell + add an actions cell. Replace:
```php
            <td><?= $v['is_published'] ? '<span class="badge badge--green">Yes</span>' : '<span class="badge badge--grey">No</span>' ?></td>
          </tr>
```
with:
```php
            <td><?= $v['is_published'] ? '<span class="badge badge--green">Yes</span>' : '<span class="badge badge--grey">No</span>' ?></td>
            <td style="white-space:nowrap">
              <a href="/admin/venue-edit.php?id=<?= (int)$v['id'] ?>" class="btn-sm btn-outline">Edit</a>
              <a href="/<?= e($v['slug']) ?>" class="btn-sm btn-outline" target="_blank">View</a>
            </td>
          </tr>
```
Also update the empty-state text "No venues found." → "No properties found."

- [ ] **Step 4: Verify**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l admin/venues.php
. /dev/stdin <<'SH'
BASE=http://localhost:8080
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
curl -s -b "$JAR" "$BASE/admin/venues.php" | grep -oE ">Properties<|New Property|venue-edit.php\?id=[0-9]+" | sort -u
SH
```
Expected: lint clean; output shows `>Properties<`, `New Property`, and `venue-edit.php?id=N` edit links.

- [ ] **Step 5: Commit**

```bash
git add admin/venues.php
git commit -m "feat(admin): relabel venues list as Properties with edit/new actions"
```

---

## Task 3: Create the Property edit page

**Files:** Create `admin/venue-edit.php`

- [ ] **Step 1: Write `admin/venue-edit.php`** with exactly this content:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$venue = $id ? db_query('SELECT * FROM venues WHERE id = :id', [':id' => $id])->fetch() : null;
$isNew = !$venue;

$success = '';
$error   = '';

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
  <a href="/admin/venues.php" class="btn-sm btn-outline">← Back to Properties</a>
</div>

<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Details</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit.php<?= $id ? '?id=' . $id : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_details">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Name</label>
        <input type="text" name="name" value="<?= e($venue['name'] ?? '') ?>" required style="width:100%;max-width:480px;padding:8px 10px">
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
        <input type="number" name="sort_order" value="<?= e($venue['sort_order'] ?? 0) ?>" style="width:120px;padding:8px 10px">
      </div>

      <button type="submit" class="btn-primary btn-sm">Save Property</button>
    </form>
  </div>
</div>

<?php if (!$isNew): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Publish</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit.php?id=<?= $id ?>">
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
  <div class="card__head">
    <span class="card__title">Rooms in this Property</span>
    <a href="/admin/room-edit.php?venue=<?= $id ?>" class="btn-sm btn-primary">+ Add room to this property</a>
  </div>
  <div class="card__body" style="padding:0">
    <?php if (!$rooms): ?>
    <p style="padding:24px;text-align:center;color:var(--muted)">No rooms in this property yet.</p>
    <?php else: ?>
    <table class="admin-table">
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
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="POST" action="/admin/venue-edit.php?id=<?= $id ?>" onsubmit="return confirm('Delete this property? Its rooms are kept but detached.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_venue">
      <button type="submit" class="btn-sm" style="background:#dc2626;color:#fff;border:none">Delete property</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l admin/venue-edit.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Functional test — edit an existing property (slug must NOT change)**

```bash
BASE=http://localhost:8080
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
VID=$($PSQL -c "SELECT id FROM venues WHERE slug='zuri'")
OLDSLUG=$($PSQL -c "SELECT slug FROM venues WHERE id=$VID")
TOK=$(curl -s -b "$JAR" "$BASE/admin/venue-edit.php?id=$VID" | grep -oE 'name="csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')
curl -s -b "$JAR" -X POST "$BASE/admin/venue-edit.php?id=$VID" \
  --data-urlencode "csrf_token=$TOK" --data-urlencode "action=save_details" \
  --data-urlencode "name=Zuri (edited)" --data-urlencode "location=Watamu, Kenya" \
  --data-urlencode "sort_order=3" --data-urlencode "slug=hacked-slug" -o /dev/null
echo "name now: $($PSQL -c "SELECT name FROM venues WHERE id=$VID")"
echo "slug now: $($PSQL -c "SELECT slug FROM venues WHERE id=$VID")  (was: $OLDSLUG)"
```
Expected: `name now: Zuri (edited)` AND `slug now: zuri` (unchanged — the posted `slug=hacked-slug` was ignored on edit). Reset the name afterward if you like:
```bash
$PSQL -c "UPDATE venues SET name='Zuri' WHERE id=$VID"
```

- [ ] **Step 4: Functional test — the "Rooms in this Property" section renders for My Amani**

```bash
MID=$($PSQL -c "SELECT id FROM venues WHERE slug='my-amani'")
curl -s -b "$JAR" "$BASE/admin/venue-edit.php?id=$MID" | grep -oE "Rooms in this Property|room-edit.php\?id=[0-9]+|gantt.php\?room=[0-9]+|room-edit.php\?venue=$MID" | sort -u | head
```
Expected: shows "Rooms in this Property", multiple `room-edit.php?id=N`, `gantt.php?room=N`, and the `room-edit.php?venue=$MID` add link.

- [ ] **Step 5: Commit**

```bash
git add admin/venue-edit.php
git commit -m "feat(admin): add Property edit page (create/edit/delete + rooms drill-down, read-only slug on edit)"
```

---

## Task 4: Room edit — read-only slug, venue preselect, fixed links, calendar link

**Files:** Modify `admin/room-edit.php`

- [ ] **Step 1: Make the slug read-only on edit in the save handler.** In the `save_details` block, replace:
```php
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        if (!$slug) $error = 'Slug is required.';
```
with:
```php
        // Slug is editable only when creating a new room; on edit it is locked to protect the live URL.
        $slug = $isNew
            ? preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_POST['slug'] ?? '')))
            : ($room['slug'] ?? '');
        if ($isNew && !$slug) $error = 'Slug is required.';
```
(The existing INSERT/UPDATE both bind `:slug` from `$data[':slug'] = $slug`; on edit this is now the unchanged existing slug, so the column count/params are untouched.)

- [ ] **Step 2: Make the slug input read-only on edit.** Replace the slug input line:
```php
          <input type="text" name="slug" value="<?= e($room['slug'] ?? '') ?>" required placeholder="e.g. junior-suite">
```
with:
```php
          <?php if ($isNew): ?>
          <input type="text" name="slug" value="" required placeholder="e.g. junior-suite">
          <?php else: ?>
          <input type="text" value="<?= e($room['slug']) ?>" readonly disabled style="background:var(--surface);color:var(--muted)">
          <small style="display:block;color:var(--muted);margin-top:4px">Locked — live URL <code>/<?= e($room['slug']) ?></code>.</small>
          <?php endif; ?>
```

- [ ] **Step 3: Preselect the venue when adding a room from a property.** Near the top, after `$isNew = !$room;` (line 11), add:
```php
$preselect_venue = ($isNew && isset($_GET['venue'])) ? (int)$_GET['venue'] : (int)($room['venue_id'] ?? 0);
```
Then in the venue `<select name="venue_id">` (around line 291), change each option's selected test to use `$preselect_venue`. Read the select block; replace its `selected` comparison so it reads, for each option:
```php
<?= ((int)$v['id'] === $preselect_venue) ? 'selected' : '' ?>
```
(Adjust the option loop variable name to whatever the file uses — read lines ~291–300 first. The intent: the currently-selected option is `$preselect_venue`.)

- [ ] **Step 4: Fix the "View on site" link** (line ~249). Replace:
```php
    <a href="/room.php?slug=<?= e($room['slug']) ?>" class="btn-outline btn-sm" target="_blank">View on site</a>
```
with:
```php
    <a href="/<?= e($room['slug']) ?>" class="btn-outline btn-sm" target="_blank">View on site</a>
    <a href="/admin/gantt.php?room=<?= (int)$id ?>" class="btn-outline btn-sm">Manage availability</a>
```
(The second line adds the per-room calendar link. If `$id` is 0 for a brand-new unsaved room, that's fine — the link just points at the unfiltered calendar; it's only shown on the edit screen which has an id.)

- [ ] **Step 5: Verify**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l admin/room-edit.php
BASE=http://localhost:8080
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
RID=$($PSQL -c "SELECT id FROM rooms WHERE slug='zuri'")
OLD=$($PSQL -c "SELECT slug FROM rooms WHERE id=$RID")
# slug must be read-only on edit: render shows readonly + no editable slug input named "slug"
curl -s -b "$JAR" "$BASE/admin/room-edit.php?id=$RID" | grep -oE 'readonly|gantt.php\?room='"$RID"'|/zuri"' | sort -u
# attempt to change slug via POST → must stay 'zuri'
TOK=$(curl -s -b "$JAR" "$BASE/admin/room-edit.php?id=$RID" | grep -oE 'name="csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')
curl -s -b "$JAR" -X POST "$BASE/admin/room-edit.php?id=$RID" --data-urlencode "csrf_token=$TOK" --data-urlencode "action=save_details" --data-urlencode "name=Zuri Whole Villa" --data-urlencode "slug=evil" --data-urlencode "price_amount=0" --data-urlencode "venue_id=$($PSQL -c "SELECT venue_id FROM rooms WHERE id=$RID")" -o /dev/null
echo "room slug after edit: $($PSQL -c "SELECT slug FROM rooms WHERE id=$RID")  (was $OLD)"
# venue preselect for a new room from a property:
MID=$($PSQL -c "SELECT id FROM venues WHERE slug='my-amani'")
curl -s -b "$JAR" "$BASE/admin/room-edit.php?venue=$MID" | grep -oE "option value=\"$MID\" selected|selected" | head -1
```
Expected: render shows `readonly` + `gantt.php?room=$RID`; the room slug stays `zuri` after the malicious POST; the new-room page preselects the My Amani option.

- [ ] **Step 6: Commit**

```bash
git add admin/room-edit.php
git commit -m "feat(admin): lock room slug on edit, preselect property for new rooms, add calendar link, fix view URL"
```

---

## Task 5: Rooms list — Property column + filter + fixed View link

**Files:** Modify `admin/rooms.php`

- [ ] **Step 1: Join venue + support a property filter.** Replace the `$rooms` query block:
```php
$rooms = db_query(
    "SELECT r.*,
        (SELECT filename FROM room_images WHERE room_id = r.id AND is_hero = TRUE LIMIT 1) AS hero_img
     FROM rooms r ORDER BY r.sort_order ASC"
)->fetchAll();
```
with:
```php
$venues       = db_query("SELECT id, name FROM venues ORDER BY sort_order")->fetchAll();
$filterVenue  = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
$rooms = db_query(
    "SELECT r.*, v.name AS venue_name,
        (SELECT filename FROM room_images WHERE room_id = r.id AND is_hero = TRUE LIMIT 1) AS hero_img
     FROM rooms r
     LEFT JOIN venues v ON v.id = r.venue_id" .
     ($filterVenue ? " WHERE r.venue_id = :vid" : "") .
     " ORDER BY r.sort_order ASC",
    $filterVenue ? [':vid' => $filterVenue] : []
)->fetchAll();
```

- [ ] **Step 2: Add a filter dropdown** in the page header. Replace:
```php
<div class="page-header">
  <h1>Rooms</h1>
  <a href="/admin/room-edit.php" class="btn-primary btn-sm">+ Add Room</a>
</div>
```
with:
```php
<div class="page-header">
  <h1>Rooms</h1>
  <form method="GET" style="margin-left:auto;display:flex;gap:8px;align-items:center">
    <select name="venue" onchange="this.form.submit()" style="padding:6px 8px">
      <option value="0">All properties</option>
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>" <?= $filterVenue === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <a href="/admin/room-edit.php" class="btn-primary btn-sm">+ Add Room</a>
  </form>
</div>
```

- [ ] **Step 3: Add the Property column.** In the `<thead>`, change:
```php
          <th>Name</th>
          <th>Slug</th>
```
to:
```php
          <th>Name</th>
          <th>Property</th>
          <th>Slug</th>
```
And in the body row, after the Name cell `<td><strong><?= e($room['name']) ?></strong></td>`, add:
```php
          <td class="text-muted"><?= e($room['venue_name'] ?? '—') ?></td>
```

- [ ] **Step 4: Fix the View link.** Replace:
```php
            <a href="/room.php?slug=<?= e($room['slug']) ?>" class="btn-sm btn-outline" target="_blank">View</a>
```
with:
```php
            <a href="/<?= e($room['slug']) ?>" class="btn-sm btn-outline" target="_blank">View</a>
```

- [ ] **Step 5: Verify**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l admin/rooms.php
BASE=http://localhost:8080
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
MID=$($PSQL -c "SELECT id FROM venues WHERE slug='my-amani'")
echo "--- unfiltered shows Property column + a venue name ---"
curl -s -b "$JAR" "$BASE/admin/rooms.php" | grep -oE ">Property<|My Amani" | sort -u | head
echo "--- filtered to My Amani: row count (expect 11) ---"
curl -s -b "$JAR" "$BASE/admin/rooms.php?venue=$MID" | grep -c 'class="draggable-row"'
```
Expected: shows `>Property<` and `My Amani`; filtered list has 11 rows (My Amani's room types).

- [ ] **Step 6: Commit**

```bash
git add admin/rooms.php
git commit -m "feat(admin): rooms list shows Property column + filter; fix View URL"
```

---

## Task 6: Per-property calendar filter in the Gantt

**Files:** Modify `admin/gantt.php`

> Goal: `gantt.php?room=<id>` scopes the calendar to that room's unit(s). Read the units-loading query first (around lines 138–160) — it selects active units joined to rooms with `WHERE u.is_active = TRUE`.

- [ ] **Step 1: Read the current units query** to get its exact text:
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
sed -n '130,170p' admin/gantt.php
```

- [ ] **Step 2: Add the optional room filter.** Just before that units query, add:
```php
$filterRoom = isset($_GET['room']) ? (int)$_GET['room'] : 0;
$filterRoomName = $filterRoom
    ? (db_query('SELECT name FROM rooms WHERE id = :id', [':id' => $filterRoom])->fetchColumn() ?: '')
    : '';
```
Then modify the units query's `WHERE u.is_active = TRUE` clause to append the filter when set. Concretely, change that query so its WHERE becomes:
```php
     WHERE u.is_active = TRUE" . ($filterRoom ? " AND u.room_id = " . $filterRoom : "") . "
```
(`$filterRoom` is an int cast, so direct interpolation is injection-safe. Match the surrounding string-concatenation style of the existing query — read it first and splice the conditional into the same string.)

- [ ] **Step 3: Show the active filter + a reset link.** Find where the page renders its main heading/title (search for the first `<h1` after `_layout.php` include) and immediately after it add:
```php
<?php if ($filterRoom): ?>
<div class="alert" style="display:flex;justify-content:space-between;align-items:center">
  <span>Showing availability for: <strong><?= e($filterRoomName) ?></strong></span>
  <a href="/admin/gantt.php" class="btn-sm btn-outline">Show all rooms</a>
</div>
<?php endif; ?>
```

- [ ] **Step 4: Verify**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l admin/gantt.php
BASE=http://localhost:8080
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
RID=$($PSQL -c "SELECT id FROM rooms WHERE slug='zuri'")
echo "--- filtered gantt shows the room name + reset link ---"
curl -s -b "$JAR" "$BASE/admin/gantt.php?room=$RID" | grep -oE "Showing availability for|Show all rooms" | sort -u
echo "--- unfiltered gantt still 200 ---"
curl -s -b "$JAR" -o /dev/null -w "gantt all -> %{http_code}\n" "$BASE/admin/gantt.php"
```
Expected: filtered view shows "Showing availability for" + "Show all rooms"; unfiltered returns 200.

- [ ] **Step 5: Commit**

```bash
git add admin/gantt.php
git commit -m "feat(admin): optional ?room= filter to scope the availability calendar to one property's room"
```

---

## Self-review

**Spec coverage:**
- Nav relabel (Properties / For Sale Listings) → Task 1 ✓
- Property list with edit/new actions → Task 2 ✓
- Full property create/edit/delete page → Task 3 ✓
- "Rooms in this Property" drill-down + add-room + availability links → Task 3 ✓
- Read-only slug on existing property + room (URL protection) → Tasks 3 & 4 ✓
- Add room preselecting its property → Task 4 ✓
- Per-room "calendar" link (Gantt filter) → Tasks 3, 4, 6 ✓
- Rooms list Property column + filter → Task 5 ✓
- No DB renames; for-sale relabel only → Tasks 1–2 (UI), no schema changes ✓
- URLs unchanged (slugs locked, no page edits, view links fixed to `/<slug>`) → Tasks 2, 4, 5 ✓

**Placeholder scan:** none — every step has concrete code/edits and verification commands.

**Type/name consistency:** `venues` table columns (`id, slug, name, location, sort_order, is_published, updated_at`) used consistently; `$activeMenu='venues'` for accommodations vs `'properties'` for for-sale (distinct, preserved); `rooms.venue_id` FK relied on for safe delete; action names (`save_details`, `save_publish`, `delete_venue`) consistent within `venue-edit.php`; `?room=` filter param consistent across `venue-edit.php`, `room-edit.php`, and `gantt.php`.

**Known minor follow-up (out of scope):** the for-sale `properties.php`/`property-edit.php` internal page headings may still say "Properties" — only the nav label is changed here per the spec; their internal copy can be relabelled later if desired.
