# Editable Property Gallery — Design

> Date: 2026-06-04
> Status: Approved (design).

## Goal

Let the owner manage each property page's **hero photo gallery** from the admin — upload, reorder, set the main image, and delete — by making the gallery DB-driven. Pre-load each property's current photos so pages look identical until edited. Reuses the existing room-image uploader pattern. Gallery only (page text/sections stay hand-coded). No URL changes.

## Decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Scope | **Gallery photos only** (the hero gallery grid + its lightbox). Page text untouched. |
| Pre-load | **Yes** — seed each property's current photos so the page is visually unchanged, then editable. |
| Lightbox | Unify each page's inline lightbox into **one shared, DB-driven lightbox** inside the gallery partial. |
| Pages | Zuri, My Amani, Maya Kobe, Enkare Bofa, Sandbox, Maya Ilai (the 6 with a `.gallery`). Tribal Dunes (no gallery) untouched. |
| Upload | Reuse the existing room-editor uploader (`storage_put`, multipart `gallery_upload[]`, set-hero, delete). |

## Data model

New migration `db/migrations/add_venue_images.sql` (mirrors `room_images`):
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
- `is_hero` marks the **main** (large) image; the rest order by `sort_order` for the thumbnails + lightbox.
- A DB helper `fetch_venue_images(int $venue_id): array` in `includes/db.php` (mirrors `fetch_room_images`), ordered `is_hero DESC, sort_order ASC`.

## Components

### 1. `includes/property-gallery.php` (NEW) — DB-driven hero gallery + lightbox
Input: `$pg_venue_slug`. Loads the venue + its `venue_images`. If none → `return;` (page keeps its hand-coded gallery — safety net). Else renders:
- The `.gallery` grid matching the current look: a `.gallery-main` (the `is_hero`/first image, with the existing `.gallery-badge` = "{Venue} · {Location}") + up to two `.gallery-thumb`s; if there are more images, the last thumb gets the existing "+N more" `::after` treatment (`.gallery-thumb.last`).
- A **self-contained lightbox** (its own markup + scoped JS) cycling through ALL `venue_images` (prev/next/esc/click-out), so it no longer depends on each page's inline `openLb`. Images sourced via `storage_url(filename)`.

### 2. `admin/venue-edit.php` (MODIFY) — Gallery section
Add a **Gallery** card reusing the room-editor uploader almost verbatim (it already exists in `admin/room-edit.php`): a `multipart/form-data` form with `gallery_upload[]` (multiple, jpeg/png/webp, 5 MB cap) → `storage_put()` → insert `venue_images` rows (first upload when empty becomes `is_hero`); a thumbnail list with **Set as main** (toggle `is_hero`), **Delete** (remove row + `storage_delete`), and drag-reorder (update `sort_order`) — same handlers/markup/JS as room images, retargeted to `venue_images`/`venue_id`.

### 3. `db/seed_venue_images.sql` (NEW) — pre-load existing photos
For each of the 6 properties, insert its **current gallery + lightbox image paths** (extracted from the page) as `venue_images` rows, stored as full `https://tribalsand.com/<path>` URLs (so `storage_url()` passes them through; they render via the dev image proxy and in prod). The first/main image gets `is_hero = TRUE`. Idempotent: delete a venue's images then re-insert.

### 4. Page wiring — replace the hard-coded gallery (6 pages)
On each of `zuri.php`, `my-amani.php`, `maya-kobe.php`, `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php`: replace the hard-coded `<div class="gallery">…</div>` block (and the now-unused inline lightbox markup/`openLb` script it fed) with:
```php
<?php $pg_venue_slug = '<slug>'; include __DIR__ . '/includes/property-gallery.php'; ?>
```
Keep everything else (header, sections, the Rooms & Availability + bar, footer) intact.

## Storage

Uploads use the existing `storage_put($tmp, $filename)` — Cloudflare R2 when configured, else local `/assets/img/rooms/`. (Acceptable shared folder; filenames are unique. A dedicated `venues/` subdir is a nice-to-have, not required.) `storage_url()` resolves stored keys; full-URL seeds pass through unchanged.

## Out of scope (YAGNI)

- Editing page text/sections (still hand-coded). Tribal Dunes (no gallery). Room-card photos (already editable via Room editor). No URL changes; booking engine untouched. Per-image captions beyond `alt_text`.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Unifying the lightbox changes behavior per page | The partial ships one consistent lightbox; visually it shows the same photos. Verify each page opens/cycles images after the swap. |
| Pre-load image paths differ per page (some lightboxes have >3 images) | The seed task extracts each page's actual gallery + lightbox `<img src>`s (grep) before writing the seed — no guessing. |
| Replacing the gallery breaks page layout | Match the existing `.gallery`/`.gallery-main`/`.gallery-thumb`/`.gallery-badge` classes exactly; per-page render check after each swap. |
| Local uploads need a writable dir | `storage.php` already `mkdir`s `assets/img/rooms/`; Dockerfile makes it writable. |
| Deleting a venue cascades its images | FK `ON DELETE CASCADE` on `venue_images.venue_id` — expected. |

## Success criteria

1. Each of the 6 property pages renders its hero gallery from `venue_images`, **looking the same as before** (pre-loaded photos), with a working lightbox.
2. In **Admin → Properties → Edit**, a Gallery section lets you upload, reorder, set the main image, and delete photos; changes appear on the live page.
3. Uploading a new photo stores it via `storage_put` and shows it in the gallery.
4. Tribal Dunes (no gallery) is unchanged; no URLs change; booking flows still work.
