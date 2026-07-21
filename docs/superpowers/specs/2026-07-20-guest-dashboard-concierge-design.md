# Guest Dashboard + Concierge (v1) — Design

**Date:** 2026-07-20
**Status:** Approved design, pending implementation plan

## Summary

Reshape the guest portal (`booking.php`) from a single scrolling "manage booking" page into a
small **app**: a home dashboard (booking status + featured tiles), a **Concierge** request hub, and
a **Stay info** page — with the existing manage features (tours/transfers, change requests, cancel)
kept but moved under a "Manage booking" view. This is Phase 0 (shell) + Phase 1 (concierge) of the
guest-app direction ([[guest-app-direction]]).

The big reuse win: the existing `booking_addons` table + `api/booking-addon.php` endpoint + admin
Confirm/Decline flow already **are** a request system. Concierge just adds new request *types* and a
friendlier UI on top of them.

## Goals

- The guest opens a link/code and lands on an app-style **home** (status + Concierge + Stay info).
- The guest can make **concierge requests** (housekeeping, amenities, maintenance, transfer, activity, restaurant, free-text) that staff see and action — reusing existing plumbing.
- The guest can read **stay info** (Wi-Fi, check-out, house rules, area guide), edited by admin.
- Nothing existing is lost — add-ons, change requests, and cancel move under "Manage booking".

## Non-Goals (v1)

- No persistent "stay logged in" session yet — entry stays code/magic-link (separate phase).
- No online payment — requests are charge-to-room / staff-confirmed.
- No full restaurant menu — "Restaurant" is a free-text request; a real menu is a later phase.
- No per-property Stay info — one shared set for all properties in v1.
- No live chat/messaging.
- Not a native app — a mobile-first, installable PWA served by the same PHP stack.

## Decisions (2026-07-20)

- v1 = **Dashboard home + Concierge + Stay info** (persistent login deferred).
- Home tiles: **Concierge** (featured) + **Stay info**; existing manage features demoted to a "Manage booking" view.
- Concierge categories: **housekeeping, amenities, maintenance, restaurant, + free-text "other"** (new free-text kinds), plus **Transfer** and **Activity** tiles that link into the existing structured pickers (transfer options / tour catalog) rather than being new kinds.
- Stay info: **one shared, admin-editable** content set (v1).

## Architecture

### App shell — `booking.php` becomes view-routed
After resolving the booking (existing magic-link `?ref=` or code lookup — unchanged), the page reads
`$view = $_GET['view'] ?? 'home'` and renders the app shell (brand `.bk-*` styling, mobile-first,
`noindex`) with one of:

| view | Content | Backed by |
|------|---------|-----------|
| `home` (default) | Status header (property, dates, code, badge, countdown) + Concierge tile + Stay info tile + "Manage booking" link | existing hold data |
| `concierge` | Category grid + per-category request form + "recent requests" list | `booking_addons` (below) |
| `stay` | Stay info content (read-only) | `settings` (below) |
| `manage` | The existing manage-actions (add tours/transfers, request change, Your requests) + cancel | existing `includes/booking-manage-actions.php` + cancel |

Each view is a small include to keep `booking.php` lean:
`includes/app/home.php`, `includes/app/concierge.php`, `includes/app/stay.php` (manage reuses
`includes/booking-manage-actions.php`). Navigation is plain links (`booking.php?ref=…&view=concierge`),
server-rendered, no framework. PWA installability via the existing `manifest.json` (+ a minimal
service worker for the install/offline shell — optional, can be a fast-follow).

### Concierge — reuses `booking_addons`
- **Migration** (`db/migrations/add_concierge.sql`, idempotent): expand the two CHECK constraints on
  `booking_addons` (drop + re-add):
  - `kind` → add `housekeeping, amenities, maintenance, restaurant` (keep `tour, transfer, itinerary, other`). No `activity` kind — the Activity tile reuses `tour`/the catalog.
  - `status` → add `completed` (keep `requested, confirmed, declined, cancelled`), so staff can tick off a service request.
- **Endpoint** (`api/booking-addon.php`, extend): accept the new kinds. `tour` (catalog) and
  `transfer` (options) keep their structured handling; the new kinds + `itinerary`/`other` require
  **free-text details**. Same ref-gate + Turnstile + rate-limit + insert + admin-notify already there.
- **Guest UI** (`includes/app/concierge.php`): a category grid. Housekeeping / amenities /
  maintenance / restaurant / free-text "something else" reveal a short free-text form that posts to
  `api/booking-addon.php`. **Transfer** and **Activity** tiles instead link to the existing pickers
  (Transfer → the transfer-options form; Activity → the tour catalog in the "Add to your trip" /
  Manage view) — no duplicate flow. Plus a "recent requests" list (reuses `fetch_booking_addons()`),
  each with a status pill.
- **Admin** (`admin/holds.php` / `admin/booking-request-action.php`, extend): the existing per-hold
  request list + Confirm/Decline already render `booking_addons`; add a **"Mark done"** action
  (sets `status='completed'`) so service requests can be completed. Labels stay generic.

### Stay info — admin-managed content (settings-based)
- **Storage:** the existing `settings` key-value table. Keys: `stay_wifi`, `stay_checkout`,
  `stay_house_rules`, `stay_area_guide` (plain text / simple lines). No migration.
- **Admin editor** (`admin/stay-info.php`, new): a small form (textareas for each field) writing
  those settings via the existing `setting()`/settings-write pattern; admin-auth + CSRF; linked from
  the admin nav.
- **Guest view** (`includes/app/stay.php`): renders those settings read-only, gracefully hiding
  empty sections.

## Data flow

1. Guest opens `booking.php?ref=…` → resolves hold → `home` view.
2. Taps Concierge → `?view=concierge` → picks a category → posts to `api/booking-addon.php` → a
   `booking_addons` row (`kind`, `details`, `status='requested'`) + admin notification (existing).
3. Staff see it on the Holds screen → Confirm / Decline / **Mark done** → status updates.
4. Guest's "recent requests" (concierge) and "Your requests" (manage) show the live status.
5. Stay info: admin edits `admin/stay-info.php` → guest reads it at `?view=stay`.

## Security & conventions

- Guest write paths keep the existing ref-gate + Turnstile + rate-limit (unchanged endpoint).
- Admin editor + the new "Mark done" action: `require_login()` + `verify_csrf()`, `e()` escaping, bound params.
- `booking.php` stays `noindex`. All new views inherit the token/lookup gate — no new access surface.

## Files

| File | Change |
|------|--------|
| `db/migrations/add_concierge.sql` | New — expand `booking_addons` kind + status CHECKs (idempotent); append to `db/run-migrations.sql` |
| `booking.php` | View routing (`?view=`), render the app shell + selected view include |
| `includes/app/home.php` | New — home dashboard (status header + tiles + manage link) |
| `includes/app/concierge.php` | New — concierge category grid + forms + recent requests |
| `includes/app/stay.php` | New — stay info read view |
| `includes/booking-manage-actions.php` | Reused under `?view=manage` (minor: heading/context) |
| `api/booking-addon.php` | Accept new concierge kinds (free-text details) |
| `admin/booking-request-action.php` | Add `status='completed'` ("Mark done") action for add-ons |
| `admin/holds.php` | Add the "Mark done" button to the per-hold request list |
| `admin/stay-info.php` | New — admin Stay-info editor |
| `includes/db.php` (or booking.php helper) | Small helper to fetch stay-info settings as a group (optional) |

## Deploy note (important)

Production (Render) uses a **separate Neon database** from local dev, and migrations are **not**
applied automatically. The `add_concierge.sql` migration MUST be run against the production DB
(Neon SQL console or `php bin/migrate.php` on Render) at deploy time, or all concierge writes will
fail the CHECK constraint. (This is the same gap that broke booking creation earlier.)

## Testing

- Migration: `booking_addons` accepts the new `kind` values and `status='completed'`; existing values still valid; idempotent re-run.
- Endpoint: a housekeeping/amenities/maintenance/restaurant request with free-text details creates a `booking_addons` row (ref-gated, Turnstile, rate-limited); missing details → 422; `tour`/`transfer` still use their structured validation (Activity/Transfer tiles route here).
- App routing: `?view=home|concierge|stay|manage` each render; invalid view → home; all `noindex`; still gated by ref/code.
- Concierge UI: category → form → submit → appears in recent requests with `requested`; magic link + code login both reach it.
- Admin: "Mark done" sets `status='completed'`, transition-guarded; existing Confirm/Decline unaffected.
- Stay info: admin edit persists to settings; guest `?view=stay` renders it; empty sections hidden.
- Regression: existing manage features (add tours/transfers, change request, cancel) still work under `?view=manage`.
