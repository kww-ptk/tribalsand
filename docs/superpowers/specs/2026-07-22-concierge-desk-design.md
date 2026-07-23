# Concierge Full-App + Management (Concierge Desk) — Design Spec

**Date:** 2026-07-22
**Status:** Approved

## Goal
Turn the guest concierge into a real service app and give staff a single place to manage every request:
1. **Laundry** — a new concierge service with a structured form.
2. **Icons** on every concierge service tile (app-style grid).
3. **Scheduling** — optional preferred date/time on any concierge request.
4. **Status tracking** — friendly, colour-coded states (Requested → In progress → Done / Declined / Cancelled) everywhere requests are listed.
5. **Concierge Desk** — one admin screen showing all requests across all guests, with inline actions and filters.

Reuses the existing `booking_addons` pipeline and the existing admin action endpoint. One migration.

---

## Data model — `db/migrations/add_concierge_desk.sql` (+ append to `db/schema.sql`)
- Extend the `booking_addons.kind` CHECK to add `'laundry'`:
  ```sql
  ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
  ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
      CHECK (kind IN ('tour','transfer','itinerary','other',
                      'housekeeping','amenities','maintenance','restaurant','laundry'));
  ```
- Add an optional preferred-time column (no timezone → stores the wall-clock time the guest picked, avoids tz drift):
  ```sql
  ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS scheduled_for TIMESTAMP;
  ```
- Idempotent (`IF NOT EXISTS`, `DROP CONSTRAINT IF EXISTS`). Run on prod via `/admin/migrate.php`.
- Statuses are unchanged: `requested`, `confirmed`, `declined`, `cancelled`, `completed`.

---

## Guest side

### Laundry (structured form)
- New constant in `includes/booking.php`:
  ```php
  const LAUNDRY_OPTIONS = [
      'wash_fold' => 'Wash & fold',
      'iron'      => 'Ironing',
      'dry_clean' => 'Dry-clean',
      'wash_iron' => 'Wash & iron',
  ];
  ```
- Concierge tile + a dedicated form (mirrors the Transfer form): a `<select name="service">` of LAUNDRY_OPTIONS + notes textarea + the optional preferred-time field.
- `api/booking-addon.php`: whitelist `'laundry'`; build `details` as `"<label> — <notes>"` (same shape as transfer), rejecting an unknown service value.

### Icons on tiles
- In `includes/app/concierge.php`, map each concierge kind to an inline outline SVG icon (laundry, housekeeping, amenities/towels, maintenance, restaurant, transfer, other). Tiles show icon above label. Pure presentation, no data change.

### Scheduling (optional)
- Every concierge form (the free-text kinds, transfer, and laundry) gets an optional `<input type="datetime-local" name="scheduled_for">` labelled "Preferred time (optional)".
- `api/booking-addon.php`: accept `scheduled_for`; if non-empty, validate it parses as a date (`strtotime`) and store the normalized `Y-m-d H:i:s`; if empty or invalid, store NULL (never error the whole request on a bad time — just drop it).
- Shown on the guest request lists and the admin Desk.

### Status tracking (friendly labels)
- New helper in `includes/booking.php`:
  ```php
  function addon_status_label(string $status): string {
      return [
          'requested' => 'Requested',
          'confirmed' => 'In progress',
          'completed' => 'Done',
          'declined'  => 'Declined',
          'cancelled' => 'Cancelled',
      ][$status] ?? ucfirst($status);
  }
  ```
- The existing `.pa-pill--<status>` colours already match the intent (requested=amber, confirmed=blue, completed=green, declined/cancelled=red) — keep the class keyed on the raw status, but render `addon_status_label($status)` as the text.
- Apply in both request lists: `includes/app/concierge.php` (recent requests) and `includes/booking-manage-actions.php` ("Your requests"). Where a `scheduled_for` exists, append it (e.g. "· Fri 24 Apr, 09:00").

---

## Admin side — Concierge Desk

### `admin/concierge-desk.php` (new)
- `require_login()`. Read-only list; the mutating actions post to the existing `admin/booking-request-action.php`.
- Query: all `booking_addons` joined to `holds` → `units`/`rooms`/`venues` for guest name, room, venue; plus `t.name` for tours. Order `created_at DESC`.
- **Filters** (GET params, re-rendered as chip links):
  - `status`: default `open` (= `requested` + `confirmed`); plus `all`, `requested`, `confirmed`, `completed`, `declined`, `cancelled`.
  - `kind`: `all` (default) or a specific service.
- **Row**: guest name + venue/room, service (label + details via `addon_label()`), preferred time (`scheduled_for`), submitted-at, status pill (via `addon_status_label`), and inline action forms:
  - **Accept** → status `confirmed` (shown when `requested`).
  - **Mark done** → status `completed` (shown when `requested` or `confirmed`).
  - **Decline** → status `declined` (shown when `requested`).
  - Each posts to `booking-request-action.php` with `type=addon`, `id`, `status`, `csrf_field()`, and `return=concierge-desk`.
- Nav link "Concierge desk" in `admin/_layout.php` (`$activeMenu='concierge_desk'`), placed near Holds.
- Empty state when no rows match the filter.

### `admin/booking-request-action.php` (modify — add whitelisted return)
- Read `$return = $_POST['return'] ?? ''`. Map to a whitelist: `'concierge-desk' => '/admin/concierge-desk.php'`, anything else → `/admin/holds.php` (current default).
- Replace the three `header('Location: /admin/holds.php')` redirects (success + the two not-found/already-actioned early exits) with the resolved target, preserving the existing `$_SESSION['hold_flash']` messages so they surface on whichever page. (The desk reads `hold_flash` the same way holds.php does.)
- No change to the transition/validation logic.

---

## Security
- Guest side stays read-only display + the existing ref-gated, Turnstile-protected, rate-limited `api/booking-addon.php` (no CSRF field by design). New `scheduled_for` is validated/normalized server-side; the laundry `service` is whitelisted against LAUNDRY_OPTIONS.
- Admin Desk: `require_login()`; all mutations go through `booking-request-action.php`, which already enforces `verify_csrf()` + status-transition guards + `audit_log`. The new `return` param is whitelisted (no open redirect).
- All output `e()`-escaped; SQL parameterized. `scheduled_for` rendered via `e(date(...))`.

## Testing
- `tests/portal_logic.php`: assert `addon_status_label()` maps all five states (+ fallback), and that `LAUNDRY_OPTIONS` is non-empty.
- A small endpoint check that `api/booking-addon.php` accepts `kind=laundry` with a valid service and stores `scheduled_for` (seed a hold, POST-simulate at the function level or via curl against the dev server, then assert the row).
- Browser E2E: submit a laundry request with a preferred time as a guest → appears in "Your requests" with "Requested" + the time; open the Concierge Desk as admin → the request shows under the open filter; Accept → guest sees "In progress"; Mark done → guest sees "Done"; filter chips work.
- Regression: existing concierge kinds, transfer, tours still submit; existing tests pass; `php -l` clean.

## Deploy
- Requires the migration. After merge/deploy, run `add_concierge_desk.sql` via `/admin/migrate.php`. Guest concierge forms that POST `laundry`/`scheduled_for` will 422/ignore gracefully until the column+CHECK exist, but the tile should only ship together with the migration being run — call it out. Rollback: Render previous deploy; the added column/relaxed CHECK are harmless if left.
