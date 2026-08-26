# DB-Driven "Photo Gallery" Section Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the bottom "Photo Gallery" section on the six Tribal Sand property pages render from `venue_images`, so the existing Admin → Venues → *property* → Gallery tab drives it.

**Architecture:** Extract the "slug → gallery images" logic out of `includes/property-gallery.php` into a memoized `pg_gallery()` resolver. The existing top hero gallery and a new bottom-section include both call it, so they share one DB query and one ordered image list — which means the bottom grid's tile indices address the correct image in the hero's existing `pgOpenLb` lightbox. Each page's legacy second lightbox is then dead code and gets deleted.

**Tech Stack:** PHP 8.2+ (vanilla, no framework), PostgreSQL via PDO (`db_query()`), plain CSS/JS. No build step. Spec: `docs/superpowers/specs/2026-08-26-db-driven-photo-gallery-section-design.md`.

---

## Context you need before starting

**The two galleries.** Each property page currently renders *two* independent galleries:

1. **Top hero gallery** — `includes/property-gallery.php`, included around line 320 of each page. Already DB-driven from `venue_images`. Defines a lightbox opened by `pgOpenLb(i)`.
2. **Bottom "Photo Gallery" section** — hand-coded `<div class="photo-grid">` with hard-coded `<img>` tags, opened by a *second*, page-local lightbox called `openLb(i)`.

This plan replaces #2 with a DB-driven include and deletes the page-local lightbox that only #2 used.

**Verified precondition:** `openLb()` has no call sites outside the bottom photo-grid tiles on any of the six pages. Deleting it is safe.

**Watch out — two different variable names.** The page-local lightbox array is called `IMGS` on My Amani, Maya Kobe, Zuri and Maya Ilai, but `lbImages` on Enkare Bofa and Sandbox. Do not blind-search for one name.

**The memoization trap.** On a page render, the hero include runs *first* and calls the resolver **with** a static fallback; the bottom include runs *second* and calls it **without** one. If the whole result were memoized, a venue with no DB images would populate the cache from the hero's fallback, and the bottom section would then render those fallback images instead of hiding. So the resolver must **memoize only the DB-derived result** and apply `$fallback` per call. Task 1 has a test that locks this down.

**Running the site locally:**

```bash
touch .router-fast
php -S localhost:8765 router.php
```

`.router-fast` (gitignored) makes the dev router serve a 1×1 placeholder for missing images instead of redirecting to the live site — without it, page loads stall on dozens of external image requests. The router strips `.php`, so the URL is `http://localhost:8765/my-amani`.

**Database:** `.env` points at the production Neon DB. Every code path in this plan is read-only, and the one test that writes does so inside a transaction that is rolled back. Do not add write paths.

---

## File Structure

| File | Status | Responsibility |
|---|---|---|
| `includes/property-gallery-data.php` | **Create** | `pg_gallery()` — the single, memoized slug → images resolver. No output. |
| `tests/property_gallery.php` | **Create** | Tests for `pg_gallery()`. |
| `includes/property-gallery.php` | Modify | Top hero gallery. Resolution logic replaced by a `pg_gallery()` call; rendered output unchanged. |
| `includes/property-photo-grid.php` | **Create** | Bottom "Photo Gallery" section markup. No DB logic of its own. |
| `my-amani.php` | Modify | Swap hard-coded section for the include; delete legacy lightbox. |
| `maya-kobe.php` | Modify | Same. |
| `zuri.php` | Modify | Same. |
| `enkare-bofa.php` | Modify | Same (note: `lbImages`, not `IMGS`). |
| `sandbox.php` | Modify | Same (note: `lbImages`, not `IMGS`). |
| `maya_ilai.php` | Modify | Same; keeps its "View full gallery →" caption link. |

---

### Task 1: The shared resolver

**Files:**
- Create: `includes/property-gallery-data.php`
- Test: `tests/property_gallery.php`

- [ ] **Step 1: Write the failing test**

Create `tests/property_gallery.php`:

```php
<?php
declare(strict_types=1);
// Shared venue-gallery resolver — DB mapping, fallback rules, memoization.
// Run: php tests/property_gallery.php
// The DB assertions run inside ONE transaction that is ROLLED BACK at the end,
// so no venue or image rows are ever left behind.
require_once __DIR__ . '/../includes/property-gallery-data.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Unknown slug: fallback rules ────────────────────────────────────────────
$g = pg_gallery('__pg_missing_a__');
check('unknown slug, no fallback → empty',   $g['images'] === []);
check('unknown slug, no fallback → no badge', $g['badge'] === '');

$g = pg_gallery('__pg_missing_b__', ['images/x.jpg'], 'Badge Text');
check('string fallback → one image',         count($g['images']) === 1);
check('string fallback → url passthrough',   $g['images'][0]['url'] === 'images/x.jpg');
check('string fallback → alt from badge',    $g['images'][0]['alt'] === 'Badge Text');
check('fallback badge used',                 $g['badge'] === 'Badge Text');

$g = pg_gallery('__pg_missing_c__', [['src' => 'images/y.jpg', 'alt' => 'Why']], 'B');
check('array fallback → url',                $g['images'][0]['url'] === 'images/y.jpg');
check('array fallback → alt',                $g['images'][0]['alt'] === 'Why');

$g = pg_gallery('__pg_missing_d__', ['', ['src' => 'images/z.jpg', 'alt' => 'Zed']], 'B');
check('fallback drops empty urls',           count($g['images']) === 1 && $g['images'][0]['url'] === 'images/z.jpg');

// ── DB-backed venue (rolled back) ───────────────────────────────────────────
db()->beginTransaction();
try {
    db_query("INSERT INTO venues (slug, name, location, sort_order) VALUES ('__pg_test__', 'Test Venue', 'Testland', 999)");
    $vid = (int) db_query("SELECT id FROM venues WHERE slug = '__pg_test__'")->fetch()['id'];
    db_query(
        "INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order) VALUES
           (:v, '/images/a.jpg', 'Alpha', TRUE,  0),
           (:v, '/images/b.jpg', '',      FALSE, 1)",
        [':v' => $vid]
    );

    $g = pg_gallery('__pg_test__');
    check('DB → two images',                 count($g['images']) === 2);
    check('DB → hero first',                 $g['images'][0]['url'] === '/images/a.jpg');
    check('DB → alt_text used',              $g['images'][0]['alt'] === 'Alpha');
    check('storage_url passes /images/ through', $g['images'][1]['url'] === '/images/b.jpg');
    check('empty alt_text → venue name',     $g['images'][1]['alt'] === 'Test Venue');
    check('badge = name · location',         $g['badge'] === 'Test Venue · Testland');

    check('memoized: identical on 2nd call', pg_gallery('__pg_test__') === $g);

    // A venue WITH DB rows must ignore any fallback passed to it.
    db_query("INSERT INTO venues (slug, name, location, sort_order) VALUES ('__pg_test2__', 'Test Two', '', 999)");
    $vid2 = (int) db_query("SELECT id FROM venues WHERE slug = '__pg_test2__'")->fetch()['id'];
    db_query("INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order) VALUES (:v, '/images/real.jpg', 'Real', TRUE, 0)", [':v' => $vid2]);
    $g2 = pg_gallery('__pg_test2__', ['images/ignored.jpg'], 'Ignored');
    check('DB rows beat fallback',           count($g2['images']) === 1 && $g2['images'][0]['url'] === '/images/real.jpg');
    check('badge from venue, not fallback',  $g2['badge'] === 'Test Two');

    // THE MEMOIZATION TRAP: hero calls WITH fallback, grid calls WITHOUT.
    // The no-fallback call must still return empty for an image-less venue.
    db_query("INSERT INTO venues (slug, name, location, sort_order) VALUES ('__pg_empty__', 'Empty Venue', '', 999)");
    $withFb = pg_gallery('__pg_empty__', ['images/hero-fallback.jpg'], 'Empty Venue');
    check('image-less venue + fallback → fallback shown', count($withFb['images']) === 1);
    $noFb = pg_gallery('__pg_empty__');
    check('image-less venue, no fallback → still empty',  $noFb['images'] === []);
} finally {
    db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/property_gallery.php
```

Expected: a PHP fatal error — `Failed opening required '.../includes/property-gallery-data.php'`. The file does not exist yet.

- [ ] **Step 3: Write the implementation**

Create `includes/property-gallery-data.php`:

```php
<?php
/**
 * Shared resolver for a venue's gallery images.
 *
 * Both includes/property-gallery.php (top hero gallery) and
 * includes/property-photo-grid.php (bottom "Photo Gallery" section) call this,
 * so they render the same ordered list — which is what lets the bottom grid's
 * tile indices address the right image in the hero's pgOpenLb lightbox.
 *
 * Only the DB-derived result is memoized. $fallback is applied per call, because
 * the hero passes one and the bottom grid deliberately does not.
 */
require_once __DIR__ . '/db.php';

/**
 * @param string $slug     venues.slug
 * @param array  $fallback Static images used ONLY when the DB returns nothing.
 *                         Accepts 'path/to.jpg' or ['src' => …, 'alt' => …].
 * @param string $badge    Badge text, used only alongside $fallback.
 * @return array{badge: string, images: array<int, array{url: string, alt: string}>}
 */
function pg_gallery(string $slug, array $fallback = [], string $badge = ''): array {
    static $cache = [];

    if (!isset($cache[$slug])) {
        $venue  = false;
        $rows   = [];
        try {
            $venue = $slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $slug])->fetch() : false;
            $rows  = $venue ? fetch_venue_images((int) $venue['id']) : [];
        } catch (Throwable $e) {
            $venue = false;
            $rows  = [];
        }

        $images = [];
        foreach ($rows as $r) {
            $images[] = [
                'url' => storage_url($r['filename']),
                'alt' => ($r['alt_text'] ?: ($venue['name'] ?? '')),
            ];
        }

        $cache[$slug] = [
            'badge'  => $rows ? trim(($venue['name'] ?? '') . (!empty($venue['location']) ? ' · ' . $venue['location'] : '')) : '',
            'images' => $images,
        ];
    }

    if ($cache[$slug]['images']) return $cache[$slug];
    if (!$fallback)              return $cache[$slug];

    // DB gave us nothing — fall back to the page's static list for this call only.
    $images = [];
    foreach ($fallback as $fb) {
        if (is_string($fb)) {
            $images[] = ['url' => $fb, 'alt' => ($badge ?: 'Property photo')];
        } elseif (is_array($fb)) {
            $images[] = [
                'url' => $fb['src'] ?? ($fb['url'] ?? ''),
                'alt' => $fb['alt'] ?? ($badge ?: 'Property photo'),
            ];
        }
    }
    $images = array_values(array_filter($images, fn($g) => $g['url'] !== ''));

    return ['badge' => $badge, 'images' => $images];
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/property_gallery.php
```

Expected: every line `PASS`, final line `All passed`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add includes/property-gallery-data.php tests/property_gallery.php
git commit -m "feat(gallery): shared memoized venue-gallery resolver"
```

---

### Task 2: Point the hero gallery at the resolver

**Files:**
- Modify: `includes/property-gallery.php:1-36`

This is a **pure extraction** — the rendered HTML must not change by a single byte.

- [ ] **Step 1: Capture the baseline HTML**

```bash
touch .router-fast
php -S localhost:8765 router.php > /dev/null 2>&1 &
sleep 2
mkdir -p /tmp/pg-baseline
for p in my-amani maya-kobe zuri enkare-bofa sandbox maya_ilai; do
  curl -s "http://localhost:8765/$p" > "/tmp/pg-baseline/$p.html"
done
wc -c /tmp/pg-baseline/*.html
```

Expected: six files, each tens of KB, none empty. If any is empty or tiny, stop — the server is not serving correctly and the comparison in Step 4 would be worthless.

- [ ] **Step 2: Replace the resolution block**

In `includes/property-gallery.php`, replace everything from the opening `<?php` down to and including the line `if (!$__gallery) { return; }` with:

```php
<?php
/**
 * DB-driven property hero gallery + lightbox.
 * Usage: $pg_venue_slug = 'zuri'; include __DIR__ . '/includes/property-gallery.php';
 * Renders nothing if the venue has no images (page can keep a fallback gallery).
 */
require_once __DIR__ . '/property-gallery-data.php';

$pg_venue_slug = $pg_venue_slug ?? '';

$__pg      = pg_gallery(
    $pg_venue_slug,
    (!empty($pg_fallback) && is_array($pg_fallback)) ? $pg_fallback : [],
    $pg_fallback_badge ?? ''
);
$__badge   = $__pg['badge'];
$__gallery = $__pg['images'];

if (!$__gallery) { return; }
```

Leave everything from `$__urls = array_map(...)` onward exactly as it is. The variables `$__badge` and `$__gallery` keep their names precisely so the rest of the file needs no edits.

- [ ] **Step 3: Re-render the six pages**

```bash
mkdir -p /tmp/pg-after
for p in my-amani maya-kobe zuri enkare-bofa sandbox maya_ilai; do
  curl -s "http://localhost:8765/$p" > "/tmp/pg-after/$p.html"
done
```

- [ ] **Step 4: Verify the output is byte-identical**

```bash
diff -r /tmp/pg-baseline /tmp/pg-after && echo "IDENTICAL"
```

Expected: `IDENTICAL`, no diff output. If anything differs, the extraction changed behaviour — fix it before moving on. Do not proceed with a non-empty diff.

- [ ] **Step 5: Commit**

```bash
git add includes/property-gallery.php
git commit -m "refactor(gallery): hero gallery reads through pg_gallery()"
```

---

### Task 3: The bottom-section include

**Files:**
- Create: `includes/property-photo-grid.php`

- [ ] **Step 1: Write the include**

Create `includes/property-photo-grid.php`:

```php
<?php
/**
 * DB-driven bottom "Photo Gallery" section for a property page.
 *
 * Include AFTER includes/property-gallery.php — that include defines the
 * pgOpenLb() lightbox these tiles open, and both read the same ordered list,
 * so tile index i addresses image i.
 *
 * Usage:
 *   $pgrid_heading       = 'Explore <em>My Amani</em>';
 *   $pgrid_caption_extra = '';                       // optional
 *   include __DIR__ . '/includes/property-photo-grid.php';
 *
 * Renders nothing when the venue has no images in the DB. There is deliberately
 * no static fallback: the section disappears rather than showing stale photos.
 */
require_once __DIR__ . '/property-gallery-data.php';

$pg_venue_slug       = $pg_venue_slug ?? '';
$pgrid_heading       = $pgrid_heading ?? '';
$pgrid_caption_extra = $pgrid_caption_extra ?? '';

$__pgrid = pg_gallery($pg_venue_slug);
if (!$__pgrid['images']) { return; }

// Default heading: the venue name alone, from "Name · Location".
if ($pgrid_heading === '') {
    $pgrid_heading = e(trim(explode('·', $__pgrid['badge'])[0]));
}
?>
    <!-- Photo Gallery -->
    <div class="sec">
      <div class="sec-label">Photo Gallery</div>
      <h2 class="sec-h"><?= $pgrid_heading ?></h2>
      <div class="sec-rule"></div>
      <div class="photo-grid">
<?php foreach ($__pgrid['images'] as $i => $im): ?>
        <img src="<?= e($im['url']) ?>" alt="<?= e($im['alt']) ?>" onclick="pgOpenLb(<?= $i ?>)" loading="lazy">
<?php endforeach; ?>
      </div>
      <div class="photo-grid-cap">Tap any photo to enlarge<?= $pgrid_caption_extra ?></div>
    </div>

    <div class="divider"></div>
```

`$pgrid_heading` and `$pgrid_caption_extra` are echoed as raw HTML on purpose — they carry `<em>` and `<a>` markup. They are developer-authored page config, never user or DB input. Image URLs and alt text **are** DB input and go through `e()`.

The trailing `<div class="divider"></div>` lives inside the include so that when the section hides, the page does not end up with two stacked dividers.

- [ ] **Step 2: Verify it parses**

```bash
php -l includes/property-photo-grid.php
```

Expected: `No syntax errors detected in includes/property-photo-grid.php`

- [ ] **Step 3: Commit**

```bash
git add includes/property-photo-grid.php
git commit -m "feat(gallery): DB-driven bottom Photo Gallery section include"
```

---

### Task 4: Wire My Amani

**Files:**
- Modify: `my-amani.php` — section at `434-448`, lightbox markup at `686`, lightbox JS at `704-737`, lightbox CSS at `273-280`

- [ ] **Step 1: Replace the hard-coded section**

Delete lines 434–448 — from `    <!-- Photo Gallery -->` through the `    <div class="divider"></div>` that follows the section's closing `</div>` (the line `450` comment `<!-- Nearby Experiences -->` must survive) — and put this in their place:

```php
<?php
$pgrid_heading = 'Explore <em>My Amani</em>';
include __DIR__ . '/includes/property-photo-grid.php';
?>
```

The caption loses "· More photos in the gallery": the grid now *is* every photo, so the line no longer says anything true. `$pg_venue_slug` is already set at line 313 for the hero gallery and is reused as-is.

- [ ] **Step 2: Delete the legacy lightbox markup**

Delete the whole element starting at `<div class="lb" id="lb" role="dialog" aria-modal="true" aria-label="Photo lightbox">` and ending at its matching `</div>` (it contains `.lb-close`, `#lbImg`, `.lb-nav` with two `.lb-btn`s, and `#lbCount`).

- [ ] **Step 3: Delete the legacy lightbox JS**

Delete from the comment line `// ── Lightbox ──` through the closing `});` of the `keydown` listener — that is `var IMGS = [...]`, `var lbIdx = 0;`, `openLb()`, `closeLb()`, `lbNext()`, `lbPrev()`, the `getElementById('lb')` click listener and the `keydown` listener. Stop before `// ── Scroll reveal ──`, which must survive.

- [ ] **Step 4: Delete the legacy lightbox CSS**

Delete the eight rules `.lb{…}`, `.lb.show{…}`, `.lb-close{…}`, `.lb-img{…}`, `.lb-nav{…}`, `.lb-btn{…}`, `.lb-btn:hover{…}`, `.lb-count{…}`. Leave every `.pg-lb*` rule alone — those belong to the shared lightbox and are defined in the include, not here.

**Also in this step — add the missing `object-fit`.** This page's `.photo-grid img` rule sets `aspect-ratio:4/3` with no `object-fit`, so it inherits the default `object-fit: fill`. That is invisible today only because the hand-coded tiles are hand-picked landscape crops. Once the grid renders every `venue_images` row, the first portrait photo a manager uploads will render horizontally squashed. Add `object-fit:cover;` to that rule, matching what `enkare-bofa.php` and `sandbox.php` already do:

```css
.photo-grid img{width:100%;aspect-ratio:4/3;object-fit:cover;cursor:pointer;transition:opacity .25s,transform .4s;}
```

- [ ] **Step 5: Verify**

```bash
php -l my-amani.php
grep -c "openLb(\|IMGS\|class=\"lb\"\|\.lb-" my-amani.php
curl -s http://localhost:8765/my-amani | grep -c "pgOpenLb"
```

Expected: no syntax errors; the `grep -c` prints `0`; the `pgOpenLb` count is greater than the number of hero tiles (hero + every bottom-grid tile).

- [ ] **Step 6: Commit**

```bash
git add my-amani.php
git commit -m "feat(my-amani): DB-driven Photo Gallery section, drop legacy lightbox"
```

---

### Task 5: Wire Maya Kobe

**Files:**
- Modify: `maya-kobe.php` — section at `427-443`, lightbox markup at `696`, lightbox JS at `714-737`, lightbox CSS at `279-286`

- [ ] **Step 1: Replace the hard-coded section**

Delete lines 427–443 — from `    <!-- Photo Gallery -->` through the `    <div class="divider"></div>` that follows the section's closing `</div>` (the `<!-- Tribal Dunes Context -->` comment must survive) — and put this in their place:

```php
<?php
$pgrid_heading = 'Explore <em>Maya Kobe</em>';
include __DIR__ . '/includes/property-photo-grid.php';
?>
```

The caption loses "· 5 photos total". That count is hard-coded and becomes wrong the moment someone uploads a sixth photo — exactly the drift this change removes.

- [ ] **Step 2: Delete the legacy lightbox markup**

Delete the whole element starting at `<div class="lb" id="lb">` and ending at its matching `</div>` (it contains `.lb-close`, `#lbImg`, `.lb-nav` with two `.lb-btn`s, and `#lbCount`).

- [ ] **Step 3: Delete the legacy lightbox JS**

Delete from the comment line `// Lightbox images` through the closing `});` of the `keydown` listener — `var IMGS = [...]`, `var lbIdx = 0;`, `openLb()`, `closeLb()`, `lbNext()`, `lbPrev()`, the click listener and the `keydown` listener. Stop before `// Scroll reveal`, which must survive.

- [ ] **Step 4: Delete the legacy lightbox CSS**

Delete the eight rules `.lb{…}`, `.lb.show{…}`, `.lb-close{…}`, `.lb-img{…}`, `.lb-nav{…}`, `.lb-btn{…}`, `.lb-btn:hover{…}`, `.lb-count{…}`. Leave every `.pg-lb*` rule alone.

**Also in this step — add the missing `object-fit`.** This page's `.photo-grid img` rule sets `aspect-ratio:4/3` with no `object-fit`, so it inherits the default `object-fit: fill`. That is invisible today only because the hand-coded tiles are hand-picked landscape crops. Once the grid renders every `venue_images` row, the first portrait photo a manager uploads will render horizontally squashed. Add `object-fit:cover;` to that rule, matching what `enkare-bofa.php` and `sandbox.php` already do:

```css
.photo-grid img{width:100%;aspect-ratio:4/3;object-fit:cover;cursor:pointer;transition:opacity .25s,transform .4s;}
```

- [ ] **Step 5: Verify**

```bash
php -l maya-kobe.php
grep -c "openLb(\|IMGS\|class=\"lb\"\|\.lb-" maya-kobe.php
curl -s http://localhost:8765/maya-kobe | grep -c "pgOpenLb"
```

Expected: no syntax errors; `grep -c` prints `0`; `pgOpenLb` count exceeds the hero tile count.

- [ ] **Step 6: Commit**

```bash
git add maya-kobe.php
git commit -m "feat(maya-kobe): DB-driven Photo Gallery section, drop legacy lightbox"
```

---

### Task 6: Wire Zuri

**Files:**
- Modify: `zuri.php` — section at `410-423`, lightbox markup at `601`, lightbox JS at `619-640`, lightbox CSS at `255-262`

- [ ] **Step 1: Replace the hard-coded section**

Delete lines 410–423 — from `    <!-- Photo Grid -->` (note: this page's comment says "Photo Grid", not "Photo Gallery") through the `    <div class="divider"></div>` that follows the section's closing `</div>`, leaving `<!-- Nearby Experiences -->` intact — and put this in their place:

```php
<?php
$pgrid_heading       = 'Explore <em>Zuri</em>';
$pgrid_caption_extra = ' · Zuri · Watamu, Kenya';
include __DIR__ . '/includes/property-photo-grid.php';
?>
```

This page's grid currently repeats the same hero image three times; after this it shows Zuri's real seeded gallery.

- [ ] **Step 2: Delete the legacy lightbox markup**

Delete the whole element starting at `<div class="lb" id="lb">` and ending at its matching `</div>` (it contains `.lb-close`, `#lbImg`, `.lb-nav` with two `.lb-btn`s, and `#lbCount`).

- [ ] **Step 3: Delete the legacy lightbox JS**

Delete from the comment line `// ── Lightbox ──` through the closing `});` of the `keydown` listener — `var IMGS = [...]`, `var lbIdx = 0;`, `openLb()`, `closeLb()`, `lbNext()`, `lbPrev()`, the click listener and the `keydown` listener.

- [ ] **Step 4: Delete the legacy lightbox CSS**

Delete the eight rules `.lb{…}`, `.lb.show{…}`, `.lb-close{…}`, `.lb-img{…}`, `.lb-nav{…}`, `.lb-btn{…}`, `.lb-btn:hover{…}`, `.lb-count{…}`. Leave every `.pg-lb*` rule alone.

**Also in this step — add the missing `object-fit`.** This page's `.photo-grid img` rule sets `aspect-ratio:4/3` with no `object-fit`, so it inherits the default `object-fit: fill`. That is invisible today only because the hand-coded tiles are hand-picked landscape crops. Once the grid renders every `venue_images` row, the first portrait photo a manager uploads will render horizontally squashed. Add `object-fit:cover;` to that rule, matching what `enkare-bofa.php` and `sandbox.php` already do:

```css
.photo-grid img{width:100%;aspect-ratio:4/3;object-fit:cover;cursor:pointer;transition:opacity .25s,transform .4s;}
```

- [ ] **Step 5: Verify**

```bash
php -l zuri.php
grep -c "openLb(\|IMGS\|class=\"lb\"\|\.lb-" zuri.php
curl -s http://localhost:8765/zuri | grep -c "pgOpenLb"
```

Expected: no syntax errors; `grep -c` prints `0`; `pgOpenLb` count exceeds the hero tile count.

- [ ] **Step 6: Commit**

```bash
git add zuri.php
git commit -m "feat(zuri): DB-driven Photo Gallery section, drop legacy lightbox"
```

---

### Task 7: Wire Enkare Bofa

**Files:**
- Modify: `enkare-bofa.php` — section at `362-375`, lightbox markup at `526`, lightbox JS at `557-582`, lightbox CSS at `230-237`

**Note:** this page's lightbox array is `lbImages`, **not** `IMGS`.

- [ ] **Step 1: Replace the hard-coded section**

Delete lines 362–375 — from `    <!-- Photo gallery -->` through the `    <div class="divider"></div>` that follows the section's closing `</div>`, leaving `<!-- Experiences -->` intact — and put this in their place:

```php
<?php
$pgrid_heading = 'Explore <em>Enkare Bofa</em>';
include __DIR__ . '/includes/property-photo-grid.php';
?>
```

This page's grid currently repeats the same hero image three times.

- [ ] **Step 2: Delete the legacy lightbox markup**

Delete the whole element starting at `<div class="lb" id="lb">` and ending at its matching `</div>` (it contains `.lb-close`, `#lbImg`, `.lb-nav` with two `.lb-btn`s, and `#lbCount`). It sits directly above the `$sbb_name = 'Enkare Bofa';` sticky-book-bar block, which must survive.

- [ ] **Step 3: Delete the legacy lightbox JS**

Delete from the comment line `/* ── Lightbox ── */` through the closing `});` of the `keydown` listener — `var lbImages = [...]`, `var lbIdx = 0;`, `openLb()`, `closeLb()`, `lbNext()`, `lbPrev()`, the click listener and the `keydown` listener. The FAQ accordion block above it must survive.

- [ ] **Step 4: Delete the legacy lightbox CSS**

Delete the eight rules `.lb{…}`, `.lb.show{…}`, `.lb-close{…}`, `.lb-img{…}`, `.lb-nav{…}`, `.lb-btn{…}`, `.lb-btn:hover{…}`, `.lb-count{…}`. Leave every `.pg-lb*` rule alone.

- [ ] **Step 5: Verify**

```bash
php -l enkare-bofa.php
grep -c "openLb(\|lbImages\|class=\"lb\"\|\.lb-" enkare-bofa.php
curl -s http://localhost:8765/enkare-bofa | grep -c "pgOpenLb"
```

Expected: no syntax errors; `grep -c` prints `0`; `pgOpenLb` count exceeds the hero tile count.

- [ ] **Step 6: Commit**

```bash
git add enkare-bofa.php
git commit -m "feat(enkare-bofa): DB-driven Photo Gallery section, drop legacy lightbox"
```

---

### Task 8: Wire Sandbox

**Files:**
- Modify: `sandbox.php` — section at `372-385`, lightbox markup at `553`, lightbox JS at `584-609`, lightbox CSS at `239-246`

**Note:** this page's lightbox array is `lbImages`, **not** `IMGS`.

- [ ] **Step 1: Replace the hard-coded section**

Delete lines 372–385 — from `    <!-- Photo gallery -->` through the `    <div class="divider"></div>` that follows the section's closing `</div>`, leaving `<!-- Compare callout -->` intact — and put this in their place:

```php
<?php
$pgrid_heading = 'Explore <em>Sandbox</em>';
include __DIR__ . '/includes/property-photo-grid.php';
?>
```

This page's grid currently repeats the same hero image three times.

- [ ] **Step 2: Delete the legacy lightbox markup**

Delete the whole element starting at `<div class="lb" id="lb">` and ending at its matching `</div>` (it contains `.lb-close`, `#lbImg`, `.lb-nav` with two `.lb-btn`s, and `#lbCount`).

- [ ] **Step 3: Delete the legacy lightbox JS**

Delete from the comment line `/* ── Lightbox ── */` through the closing `});` of the `keydown` listener — `var lbImages = [...]`, `var lbIdx = 0;`, `openLb()`, `closeLb()`, `lbNext()`, `lbPrev()`, the click listener and the `keydown` listener. Stop before `// ── Policy accordion ──`, which must survive.

- [ ] **Step 4: Delete the legacy lightbox CSS**

Delete the eight rules `.lb{…}`, `.lb.show{…}`, `.lb-close{…}`, `.lb-img{…}`, `.lb-nav{…}`, `.lb-btn{…}`, `.lb-btn:hover{…}`, `.lb-count{…}`. Leave every `.pg-lb*` rule alone.

- [ ] **Step 5: Verify**

```bash
php -l sandbox.php
grep -c "openLb(\|lbImages\|class=\"lb\"\|\.lb-" sandbox.php
curl -s http://localhost:8765/sandbox | grep -c "pgOpenLb"
```

Expected: no syntax errors; `grep -c` prints `0`; `pgOpenLb` count exceeds the hero tile count.

- [ ] **Step 6: Commit**

```bash
git add sandbox.php
git commit -m "feat(sandbox): DB-driven Photo Gallery section, drop legacy lightbox"
```

---

### Task 9: Wire Maya Ilai

**Files:**
- Modify: `maya_ilai.php` — section at `684-700`, lightbox markup at `895`, lightbox JS at `913-940`, lightbox CSS at `348-355`

- [ ] **Step 1: Replace the hard-coded section**

Delete lines 684–700 — from `    <!-- Photo Gallery -->` through the `    <div class="divider"></div>` that follows the section's closing `</div>`, leaving `<!-- Tribal Dunes Context -->` intact — and put this in their place:

```php
<?php
$pgrid_heading       = 'Explore <em>Maya Ilai</em>';
$pgrid_caption_extra = ' · <a href="maya-ilai-gallery.php" style="color:var(--teal);">View full gallery →</a>';
include __DIR__ . '/includes/property-photo-grid.php';
?>
```

The "View full gallery →" link is kept verbatim — it points at the standalone `maya-ilai-gallery.php` page, which is out of scope here.

- [ ] **Step 2: Delete the legacy lightbox markup**

Delete the whole element starting at `<div class="lb" id="lb">` and ending at its matching `</div>` (it contains `.lb-close`, `#lbImg`, `.lb-nav` with two `.lb-btn`s, and `#lbCount`).

- [ ] **Step 3: Delete the legacy lightbox JS**

Delete from the comment line `// Lightbox images` through the closing `});` of the `keydown` listener — `var IMGS = [...]`, `var lbIdx = 0;`, `openLb()`, `closeLb()`, `lbNext()`, `lbPrev()`, the click listener and the `keydown` listener. Stop before `// Scroll reveal`, which must survive.

- [ ] **Step 4: Delete the legacy lightbox CSS**

Delete the eight rules `.lb{…}`, `.lb.show{…}`, `.lb-close{…}`, `.lb-img{…}`, `.lb-nav{…}`, `.lb-btn{…}`, `.lb-btn:hover{…}`, `.lb-count{…}`. Leave every `.pg-lb*` rule alone.

**Also in this step — add the missing `object-fit`.** This page's `.photo-grid img` rule sets `aspect-ratio:4/3` with no `object-fit`, so it inherits the default `object-fit: fill`. That is invisible today only because the hand-coded tiles are hand-picked landscape crops. Once the grid renders every `venue_images` row, the first portrait photo a manager uploads will render horizontally squashed. Add `object-fit:cover;` to that rule, matching what `enkare-bofa.php` and `sandbox.php` already do:

```css
.photo-grid img{width:100%;aspect-ratio:4/3;object-fit:cover;cursor:pointer;transition:opacity .25s,transform .4s;}
```

- [ ] **Step 5: Verify**

```bash
php -l maya_ilai.php
grep -c "openLb(\|IMGS\|class=\"lb\"\|\.lb-" maya_ilai.php
curl -s http://localhost:8765/maya_ilai | grep -c "pgOpenLb"
```

Expected: no syntax errors; `grep -c` prints `0`; `pgOpenLb` count exceeds the hero tile count.

- [ ] **Step 6: Commit**

```bash
git add maya_ilai.php
git commit -m "feat(maya-ilai): DB-driven Photo Gallery section, drop legacy lightbox"
```

---

### Task 10: Whole-feature verification

**Files:** none modified (verification only)

- [ ] **Step 1: Re-run the resolver tests**

```bash
php tests/property_gallery.php
```

Expected: `All passed`, exit 0.

- [ ] **Step 2: Confirm the legacy lightbox is gone site-wide**

```bash
grep -rn "openLb(" --include="*.php" . | grep -v pgOpenLb
```

Expected: **no output**. Any hit means a page still calls the deleted function.

- [ ] **Step 3: Confirm every page renders with a DB-driven grid**

```bash
for p in my-amani maya-kobe zuri enkare-bofa sandbox maya_ilai; do
  n=$(curl -s "http://localhost:8765/$p" | grep -c 'onclick="pgOpenLb')
  s=$(curl -s "http://localhost:8765/$p" | grep -c 'sec-label">Photo Gallery')
  echo "$p: pgOpenLb tiles=$n  photo-gallery-section=$s"
done
```

Expected: every page reports `photo-gallery-section=1` and a `pgOpenLb` tile count of at least 4 (hero main + 2 thumbs + at least one grid tile).

- [ ] **Step 4: Confirm one DB query, not two**

Confirm the memoization holds by checking that `pg_gallery` is the only place either include queries `venues`:

```bash
grep -n "db_query" includes/property-gallery.php includes/property-photo-grid.php
```

Expected: **no output** — both includes delegate all DB access to `pg_gallery()`.

- [ ] **Step 5: Check the browser**

Load each of the six pages in a browser. For each, confirm:
- the top hero gallery looks exactly as it did before;
- the bottom "Photo Gallery" section shows the venue's full set of admin-managed photos (Zuri, Enkare Bofa and Sandbox should no longer show the same photo three times);
- clicking a bottom-grid tile opens the shared lightbox **on that photo**, and prev/next/Esc work;
- the browser console is free of `openLb is not defined` errors.

- [ ] **Step 6: Confirm the admin round-trip**

In the admin, go to Venues → Zuri → Gallery, reorder two photos, then reload `http://localhost:8765/zuri`. The bottom grid order must change to match. Undo the reorder afterwards if this is the production DB.

- [ ] **Step 7: Stop the dev server and clean up**

```bash
kill %1
rm -f .router-fast
```

- [ ] **Step 8: Update the CLAUDE.md file map**

Add the two new includes to the File Map table in `CLAUDE.md`:

```markdown
| `includes/property-gallery-data.php` | Shared memoized venue-gallery resolver (`pg_gallery()`) |
| `includes/property-photo-grid.php` | Bottom "Photo Gallery" section — DB-driven, hides when empty |
```

- [ ] **Step 9: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: note the property-gallery includes in the file map"
```
