# Guest Board + Desktop Polish — Design Spec

**Date:** 2026-07-22
**Status:** Approved

## Goal
Two improvements to the guest portal (`booking.php` + `includes/app/*`):
1. **Desktop polish** — the portal currently renders as a 640px mobile column centered in empty space on wide screens. Widen it and flow cards into multiple columns on desktop, without touching the mobile experience.
2. **Guest board** — an admin-authored channel of daily updates, excursions, and promotions shown on the portal Home, scoped per property.

Reuses the existing backend and design system entirely. One new table, one new admin page. No new guest-facing write endpoints.

---

## Part 1 — Desktop layout (Option B: widen the centered column)

Chosen over a sidebar app-shell for lowest risk — mobile stays identical, no structural rewrite.

- **Widen shell on desktop only.** Add `@media (min-width:720px)` rules in `css/portal-app.css` that take `.pa-wrap` and `.pa-nav` `max-width` from `640px` to `880px`. Below 720px, nothing changes.
- **New `.pa-grid` utility** in `css/portal-app.css`:
  - Mobile: `display:grid; grid-template-columns:1fr; gap:12px`.
  - `@media (min-width:720px)`: `grid-template-columns:repeat(auto-fill,minmax(240px,1fr))`.
  - Use `minmax(0,1fr)` semantics to avoid overflow.
- **Apply `.pa-grid`** to the card lists that currently stack vertically:
  - Activities list (`includes/app/activities.php`) — the activity `.pa-card`s.
  - Home "Experiences" featured cards (`includes/app/home.php`).
  - The new Guest board (see Part 2).
- The status card, notices, concierge tiles, stay cards, and forms keep their current single-column flow (they read better full-width). Only the browse-style card lists become grids.

### Constraints / non-goals
- No change to the bottom tab bar behavior (it stays fixed; just widens to match the wrap on desktop).
- No change to any mobile breakpoint rendering.
- The category-filter JS in activities keeps working — it toggles `display` on `.pa-card[data-cat]`, which still works inside a grid (hidden items leave the grid flow).

---

## Part 2 — Guest board

### Data model
New table `guest_board_posts`:

| Column | Type | Notes |
|--------|------|-------|
| `id` | serial PK | |
| `venue_id` | int NULL, FK → `venues(id)` ON DELETE CASCADE | `NULL` = shown to all guests regardless of property |
| `category` | text NOT NULL, CHECK in (`update`,`excursion`,`promotion`) | color-coded tag |
| `title` | text NOT NULL | |
| `body` | text NOT NULL DEFAULT '' | short description |
| `image_filename` | text NULL | stored via `storage_put`; rendered via `storage_url` |
| `is_published` | boolean NOT NULL DEFAULT TRUE | admin toggle |
| `sort_order` | int NOT NULL DEFAULT 0 | higher = pinned toward top |
| `created_at` | timestamptz NOT NULL DEFAULT now() | |
| `updated_at` | timestamptz NOT NULL DEFAULT now() | |

Ships as `db/migrations/add_guest_board.sql` using `CREATE TABLE IF NOT EXISTS` (safe to re-run). Run on production via the existing `/admin/migrate.php` runner after deploy. Also append the `CREATE TABLE` to `db/schema.sql` for fresh installs.

Index: `CREATE INDEX IF NOT EXISTS idx_gbp_visible ON guest_board_posts (is_published, venue_id, sort_order DESC, created_at DESC);`

### Guest side (read-only, display only)
- **Expose the guest's venue.** Add `r.venue_id AS venue_id` to the SELECT in `fetch_hold_for_guest()` and `resolve_booking_by_code_only()` in `includes/booking.php` (the ref path calls `fetch_hold_for_guest`, so both resolve paths get it).
- **Helper** `fetch_guest_board(?int $venueId): array` in `includes/booking.php`:
  ```sql
  SELECT id, category, title, body, image_filename
  FROM guest_board_posts
  WHERE is_published = TRUE
    AND (venue_id IS NULL OR venue_id = :venue)
  ORDER BY sort_order DESC, created_at DESC
  LIMIT 6
  ```
  When `$venueId` is null, `venue_id = :venue` is never true, so only the global (`venue_id IS NULL`) posts show — correct. Bind `:venue` as `null` safely (PDO handles `venue_id = NULL` → no match, which is the intended behavior).
- **Render on Home** (`includes/app/home.php`), a new section **above** the Concierge hero tile:
  - Section only renders if `fetch_guest_board()` returns rows (wrapped in try/catch → `[]` on DB error, like the existing experiences fetch).
  - Each post is a `.pa-card`: optional image band (`.pa-media` with `background-image` when `image_filename` set; no gradient fallback — if no image, show a compact text-only card with no media band), a category tag pill, title (`.pa-card__title`), and body (muted).
  - Cards laid out with `.pa-grid`.
  - New tag classes in `css/portal-app.css`: `.pa-tag`, `.pa-tag--update` (blue), `.pa-tag--excursion` (teal), `.pa-tag--promotion` (amber). Colors match the approved mockup.
- All output escaped with `e()`; image via `e(storage_url($image_filename))`. No user input, no writes — no Turnstile/CSRF/rate-limit on the guest side.

### Admin side
New page `admin/guest-board.php` (mirrors `admin/tour-edit.php` / `admin/stay-info.php` conventions):
- `require_login()`; all POST actions `verify_csrf()`.
- **List** all posts (published + drafts) with category, title, target property (venue name or "All properties"), published state, and a thumbnail if present.
- **Create / edit form:**
  - Property `<select>`: "All properties" (empty → NULL) + one option per row from `SELECT id, name FROM venues ORDER BY sort_order ASC`.
  - Category `<select>`: Update / Excursion / Promotion.
  - Title `<input>` (required), Body `<textarea>`.
  - Sort order `<input type=number>` (default 0).
  - Published checkbox.
  - Image upload `<input type=file>`: reuse the tour-edit pipeline — validate type, GD-decode, downscale to max 2000px wide, `imagejpeg(...,88)`, `storage_put()`. Store returned filename in `image_filename`. Optional; leaving it empty on edit keeps the existing image. A "remove image" checkbox deletes via `storage_delete()` and nulls the column.
- **Delete** action: `storage_delete()` the image if present, then delete the row.
- Every create/edit/delete writes `audit_log('guest_board_...', 'guest_board_post', $id, ...)`.
- Add a nav link "Guest board" to the admin layout (`admin/_layout.php`) alongside the other sections; set `$activeMenu` appropriately.

### Defaults (confirmed)
- Home shows up to **6** posts.
- Ordering: `sort_order DESC, created_at DESC` (pin via higher sort_order; otherwise newest first).

---

## Security review
- Guest side is read-only display — no new attack surface; all output `e()`-escaped, images via `storage_url()`.
- `category` constrained by DB CHECK; the guest renderer maps only the three known categories to tag classes (unknown → neutral), so a bad value can't inject a class.
- Admin page: `require_login()` + `verify_csrf()` on all mutations; image upload reuses the hardened tour-edit path (type check + re-encode strips non-image payloads). `venue_id` validated against the venues list before insert.
- No `$_SERVER['REMOTE_ADDR']`; no secrets in URLs.

## Testing
- `tests/portal_logic.php` gains assertions for `fetch_guest_board()` shape and the venue-filter behavior (global vs property-scoped vs null-venue guest).
- Browser E2E: seed posts (one global, one property-scoped), confirm a guest at that property sees both, a guest at another property sees only the global one; confirm desktop grid layout and widened shell; confirm admin CRUD + image upload + publish toggle.
- Regression: existing portal views unchanged on mobile; `php -l` clean; existing tests still pass.

## Deploy
- No code deploy risk beyond the new table. After merge auto-deploys, run `add_guest_board.sql` via `/admin/migrate.php` (one click) before authoring posts. Rollback: Render previous deploy; the unused table is harmless if left.
