# DB-Driven "Photo Gallery" Section — Design

> Date: 2026-08-26
> Status: Approved (design).
> Follows on from `2026-06-04-editable-property-gallery-design.md`.

## Goal

Make the **bottom "Photo Gallery" section** on the six property pages read from `venue_images`,
so the existing Admin → Venues → *property* → **Gallery** tab drives it. Today that section is
hand-coded markup fed by a second, page-local lightbox.

## Background — what already exists

The June 2026 work made the **top hero gallery** DB-driven via `includes/property-gallery.php`
(`$pg_venue_slug` → `venues` → `fetch_venue_images()` → `.gallery` + the `pgOpenLb` lightbox).
That spec intended to also delete each page's inline `openLb` lightbox, but could not: the bottom
`.photo-grid` section was still calling it. So each property page currently carries **two**
galleries and **two** lightboxes:

| | Source | Lightbox |
|---|---|---|
| Top hero `.gallery` | `venue_images` (admin-editable) | `pgOpenLb` (in the include) |
| Bottom `.photo-grid` | hard-coded `<img>` tags | `openLb` + page-local `IMGS` array |

The hard-coded bottom grids have drifted into placeholder filler:

| Page | Bottom grid today |
|---|---|
| Zuri | same hero image repeated 3× |
| Enkare Bofa | same hero image repeated 3× |
| Sandbox | same hero image repeated 3× |
| Maya Kobe | 6 tiles, 2 duplicated |
| My Amani | 4 real photos |
| Maya Ilai | 6 real photos + "View full gallery →" link |

`admin/venue-edit.php` already has a working Gallery tab (upload, set-main, reorder, delete)
writing `venue_images`, and `db/seed_venue_images.sql` has pre-loaded each venue's photos.
**No admin or DB work is needed** — this is public-page wiring only.

Note: the `properties` / `property_images` tables are a *separate* real-estate "For Sale" module
(`admin/properties.php`) with no public consumer. It is **not** involved here.

## Decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Which images in the bottom grid | **All** of the venue's images, in `venue_images` order. Accepts that the hero's first 3 appear twice on the page. |
| No DB images / DB unreachable | **Hide the section entirely.** The hero gallery still renders from its per-page `$pg_fallback`, so the page never looks broken. |
| Lightbox | Unify on the include's `pgOpenLb`. Delete each page's legacy `openLb` lightbox. |
| Approach | Shared memoized resolver + a second include (over a `$pg_mode` flag on one include, or inlining the query six times). |
| Pages | The six with `$pg_venue_slug`: My Amani, Maya Kobe, Zuri, Enkare Bofa, Sandbox, Maya Ilai. |
| Tribal Dunes | **Out of scope** — see below. |

## Components

### 1. `includes/property-gallery-data.php` (NEW) — shared resolver

```php
pg_gallery(string $slug, array $fallback = [], string $badge = ''): array
// → ['badge' => string, 'images' => [['url' => string, 'alt' => string], …]]
```

- Looks up the venue by slug and calls `fetch_venue_images()`, wrapped in try/catch so a DB
  outage yields an empty list rather than a fatal.
- Maps `filename` through `storage_url()`; `alt` falls back to the venue name when `alt_text`
  is empty.
- Applies `$fallback` (the existing `$pg_fallback` shape: strings or `['src'=>…,'alt'=>…]`)
  **only** when the DB returns nothing.
- Builds `badge` as `"{name} · {location}"` from the venue row, else `$badge`.
- **Memoized** in a static keyed by slug, so the hero and the grid together cost one query per
  page request, not two.
- `require_once`s `includes/db.php`, as `property-gallery.php` already does, so either include is
  safe to pull in on its own.

### 2. `includes/property-gallery.php` (MODIFY) — hero gallery

Its inline resolution logic is replaced by a `pg_gallery()` call. This is a pure extraction:
rendered output must stay byte-identical, including the `pg-single` / `pg-double` classes,
the badge, the "View all N photos" button, and the `pgOpenLb` script block.

### 3. `includes/property-photo-grid.php` (NEW) — the bottom section

- Calls `pg_gallery($pg_venue_slug)` with **no fallback**. Empty list → `return;` having emitted
  nothing (the "hide the section" decision).
- Otherwise renders the existing markup shape:
  `.sec` → `.sec-label` "Photo Gallery" → `.sec-h` heading → `.sec-rule` → `.photo-grid` →
  `.photo-grid-cap`, followed by the trailing `<div class="divider"></div>`.
- Every venue image becomes a tile: `onclick="pgOpenLb(i)"` where `i` is its index in the same
  list the hero uses, plus `loading="lazy"`.
- Emitting the trailing divider inside the include prevents two stacked dividers when the
  section hides.

Page-configurable inputs:

| Variable | Purpose | Default |
|---|---|---|
| `$pgrid_heading` | HTML for `.sec-h` | venue name, plain |
| `$pgrid_caption_extra` | optional HTML appended after `Tap any photo to enlarge` | `''` |

Both are echoed as **raw HTML** — they carry `<em>` and `<a>` markup. They are developer-authored
page config, never user or DB input. Both default via `?? ''` so a page that sets neither still
renders.

The caption is always `Tap any photo to enlarge` plus `$pgrid_caption_extra`. Per page:

| Page | `$pgrid_caption_extra` | Note |
|---|---|---|
| My Amani | `''` | drops "More photos in the gallery" — the grid now *is* every photo |
| Maya Kobe | `''` | **drops "· 5 photos total"** — a hardcoded count goes stale as soon as the admin uploads a sixth photo |
| Zuri | `· Zuri · Watamu, Kenya` | kept |
| Enkare Bofa | `''` | unchanged |
| Sandbox | `''` | unchanged |
| Maya Ilai | `· <a href="maya-ilai-gallery.php" style="color:var(--teal);">View full gallery →</a>` | kept verbatim |

No caption states a photo count. If a live count is wanted later, the include already knows it and
can render it — but hardcoding one is exactly the drift this change removes.

### 4. Page wiring — the six property pages

Replace the hand-coded `<div class="sec">…Photo Gallery…</div>` block (and the `<div class="divider">`
that follows it) with:

```php
<?php
$pgrid_heading = 'Explore <em>My Amani</em>';
include __DIR__ . '/includes/property-photo-grid.php';
?>
```

`$pg_venue_slug` is already set earlier on each page for the hero gallery and is reused as-is.

Then delete the now-dead legacy lightbox from each page:
- the `var IMGS = [...]` array
- `openLb()`, `closeLb()`, `lbNext()`, `lbPrev()`, the `#lb` click and keydown listeners
- the `<div class="lb" id="lb">` markup
- the `.lb`, `.lb-close`, `.lb-img`, `.lb-nav`, `.lb-btn`, `.lb-count` CSS rules

Verified precondition: `openLb()` is called **only** from the bottom photo-grid tiles on all six
pages, so nothing else regresses.

`.photo-grid` CSS stays in each page untouched — the include emits markup only, so per-page
differences (`object-fit`, column counts, caption font size) are preserved.

## Data flow

```
Admin → Venues → <property> → Gallery tab
        ↓ (upload / set main / reorder / delete)
    venue_images
        ↓ fetch_venue_images()
    pg_gallery($slug)   ← memoized, one query per request
        ├→ includes/property-gallery.php     (top hero gallery + pgOpenLb lightbox)
        └→ includes/property-photo-grid.php  (bottom Photo Gallery section)
```

Because both includes consume the same ordered list, tile index `i` in the bottom grid always
addresses the correct image in the shared lightbox.

## Error handling

| Condition | Behaviour |
|---|---|
| DB unreachable | `pg_gallery()` catches, returns `[]`. Hero falls back to `$pg_fallback`; bottom section hides. Page renders. |
| Venue slug not in `venues` | Same as above. |
| Venue exists, zero images | Same as above. |
| `pgOpenLb` undefined | Cannot occur: the grid only renders when the DB returned images, and in that case the hero rendered from the same data and defined `pgOpenLb`. |
| Image file missing at the URL | Browser shows a broken tile — same as today. Out of scope. |

## Testing

**`tests/property_gallery.php`** (NEW), matching the existing `tests/*_logic.php` style
(`check(label, cond)`, `php tests/property_gallery.php`):
- a fixture venue's images come back in `is_hero DESC, sort_order ASC` order, each entry having `url` and `alt`
- URLs are resolved through `storage_url()` (root-relative `/images/…` passes through unchanged)
- empty `alt_text` falls back to the venue name; badge is `"{name} · {location}"`
- unknown slug → empty `images`, and the supplied fallback is used when one is given
- unknown slug with **no** fallback → empty `images` (the hide-the-section path)
- fallback is ignored when the DB has rows
- fallback accepts both the string and `['src'=>…,'alt'=>…]` shapes, and drops entries with no URL
- calling twice for the same slug returns identical data (memoization)
- **the memoization trap:** for a venue with zero images, a call *with* a fallback (the hero) must
  not poison the cache for the later call *without* one (the grid) — the second call still returns empty

Tests insert their own fixture venues rather than depending on whatever rows production happens to
hold, so the DB assertions run inside ONE transaction that is ROLLED BACK at the end — matching
`tests/reservations_logic.php` and `tests/checkin_consent.php`. No rows survive the run.

**Render smoke test:** serve locally with PHP 8.5.4 and load all six pages, confirming for each:
top gallery unchanged, bottom section present with the full venue set, tiles open the lightbox at
the right image, and no console errors from the removed `openLb`.

## Out of scope (YAGNI)

- **`tribal-dunes.php`** — its `td-photo-grid` is a cross-venue montage (Maya Kobe + Maya Ilai
  photos), has no lightbox and no `$pg_venue_slug`. It is not one venue's gallery, so it does not
  map onto `venue_images`.
- The `properties` / `property_images` "For Sale" module.
- Any change to `admin/venue-edit.php`, the `venue_images` schema, or the seed.
- Per-image captions beyond `alt_text`; a cap or "+N more" affordance on the grid; the standalone
  `*-gallery.php` pages.
- Restyling `.photo-grid`.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Refactoring `property-gallery.php` changes the hero's rendered output | Pure extraction; diff the rendered HTML for all six pages before/after. |
| Deleting the legacy lightbox breaks another feature | Verified `openLb()` has no call sites outside the bottom photo-grid on all six pages. |
| Bottom grid grows long on image-rich venues | Accepted — "all images" is the chosen rule. A cap can be added later if it reads poorly. |
| Hero's first 3 photos repeat further down the page | Accepted deliberately in the decisions table. |
| Live DB is the production Neon instance | All new *application* code paths are read-only. The tests write fixture rows only inside a transaction that is rolled back. |
