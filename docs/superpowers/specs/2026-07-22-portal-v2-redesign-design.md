# Guest Portal v2 — Travel-App Redesign — Design

**Date:** 2026-07-22
**Status:** Approved design, pending implementation plan

## Summary

Restyle the guest portal (`booking.php` + its `includes/app/*` views) into a cohesive, mobile-first
**travel app**: a persistent bottom tab bar across five sections (Home · Activities · Concierge ·
Stay · Booking), one shared design system, and a new **Activities** page that replaces the plain
"Add a tour" dropdown with rich, browsable image cards. This is a **visual/IA redesign** — the
backend (requests → `booking_addons`, statuses, admin actions, access/login) is unchanged. No DB
migration.

## Goals

- The guest always sees every section (persistent bottom navigation) — "clear vision of all sections".
- Activities are browsable travel-app cards (photo, category, duration, tag) with a one-tap request.
- The whole portal reads as one polished app, not scattered forms.
- Nothing existing is lost — every current capability is re-homed and restyled.

## Non-Goals (v1 of the redesign)

- No online payment — activity/service requests stay charge-to-room / staff-confirmed.
- No new activity data model — reuses the existing `tours` + `tour_images` tables.
- No persistent "stay logged in" session (separate phase); access stays magic-link / code.
- The public `activities.php` marketing page is left as-is.
- No new images required — cards use uploaded hero photos where present, brand-gradient fallback otherwise (most tours currently have no image).

## Decisions (2026-07-22)

- Bottom tab bar with five sections: **Home, Activities, Concierge, Stay, Booking** (Booking = today's `manage` view).
- Full cohesive redesign of all five sections in one pass.
- Activities own **tour** requests; the old "Add a tour" dropdown is removed in favour of the Activities cards.

## Information architecture (re-homing existing features)

| Section (view) | Contains | Backed by |
|---|---|---|
| **Home** (`home`) | Greeting + booking status summary + 2–3 featured activities + quick Concierge shortcut | existing hold data + activities helper |
| **Activities** (`activities`, NEW) | Category filter + activity cards + request + detail expand | `tours` + `tour_images`, requests via existing add-on endpoint (`kind=tour`) |
| **Concierge** (`concierge`) | Service requests (housekeeping, amenities, maintenance, restaurant, other) **+ Transfer** (structured options, relocated here) | `booking_addons` via existing endpoint |
| **Stay** (`stay`) | Wi-Fi, check-out, house rules, area guide | `settings` |
| **Booking** (`manage`) | Booking details (dates, code, room) + Request a change + Cancel + "Your requests" (all requests, with status) | existing hold + `booking_addons` / `booking_change_requests` |

Re-homing summary: tour add-ons → Activities; transfer add-on form → Concierge; itinerary folds into
Concierge "something else" (free text); change-request + cancel + the requests list → Booking. All
reuse the same endpoints; nothing is deleted, only relocated and restyled.

## Architecture

### App shell — `booking.php`
After resolving the hold (unchanged), `booking.php` loads `css/portal-app.css`, renders a **compact
per-section top bar** (small brand mark + section title, replacing the big "Your Booking" hero), then
the active view include, then `includes/app/nav.php` (the bottom tab bar). `$view` whitelist gains
`activities`. `noindex` and the code/magic-link access are unchanged. The bottom nav is fixed to the
viewport bottom on mobile (with body padding so content isn't obscured).

### Design system — `css/portal-app.css` (new)
Brand tokens (teal `#102F3A` / `#1E5C6B`, cream `#F5F1EB`, gold `#D4B07A`, Cormorant serif headings,
Jost sans) and reusable classes: `.pa-app` (frame), `.pa-topbar`, `.pa-nav` + `.pa-nav__item`
(bottom tabs, active state), `.pa-card`, `.pa-chip` (filter pills), `.pa-btn` / `.pa-btn--primary`,
`.pa-pill--<status>` (request status). Loaded via `includes/head.php`'s cache-busting `<link>`
pattern (or an inline `<link>` in booking.php with `?v=filemtime`). All existing views are re-marked
to these classes; the old inline `.bk-*` styles are removed from booking.php as their markup is
replaced.

### Bottom nav — `includes/app/nav.php` (new)
Five `<a>` tabs linking to `booking.php?ref=<ref>&view=<section>`, each with a Tabler-style/SVG icon
+ label, highlighting the active `$view` (`manage` highlights "Booking"). Expects `$ref`, `$view` in
scope.

### Activities — `includes/app/activities.php` (new)
- Data: `fetch_portal_activities()` — published `tours` with their hero image
  (`(SELECT filename FROM tour_images WHERE tour_id=t.id AND is_hero=TRUE LIMIT 1) AS hero`), plus
  `category`, `tag_label`, `duration`, `short_desc`, `highlights_json`, ordered by `sort_order`.
  Hero → `storage_url($hero)`; when empty, a brand gradient chosen by `category`.
- `fetch_tour_categories()` — distinct published categories → friendly labels (classic → "Classic
  safari", custom → "Custom journey", excursion → "Excursion").
- UI: a horizontally-scrolling category chip row (All + each category; client-side filter via a
  `data-cat` attribute + small JS, no reload) and a single-column list of `.pa-card` activity cards
  (image/gradient band with category + tag chips, name, duration, a "Details" expand showing
  `short_desc` + highlights, and a "Request this activity" button). The request is a `data-bm` form
  posting `kind=tour` + `tour_slug` to `/api/booking-addon.php` (handled by the existing
  `js/booking-manage.js`), so it slots into the existing add-on pipeline + admin actions.

### Restyle of existing views
`status-header.php`, `home.php`, `concierge.php`, `stay.php`, and the Booking (manage) markup are
re-clad in `.pa-*`. Concierge gains the relocated Transfer form; its Activity tile is removed (now a
nav section). Home leads with a greeting + status + featured activities + a Concierge shortcut. Logic
and endpoints are untouched — this is markup/CSS only.

## Data flow

Unchanged from today: a request (activity or service) posts to `/api/booking-addon.php` → a
`booking_addons` row → admin sees/acts on the Holds screen → guest sees status in "Your requests"
(Booking) and inline (Activities/Concierge). Stay info reads `settings`. Access via magic link/code.

## Security & conventions

- Guest write paths unchanged (ref-gate + Turnstile + rate-limit in the endpoint).
- All dynamic output escaped with `e()`; image src via `storage_url()` (no raw path interpolation).
- Guest page has no `csrf_field()` (unchanged). `noindex` retained.

## Files

| File | Change |
|------|--------|
| `css/portal-app.css` | New — the `.pa-*` design system |
| `includes/app/nav.php` | New — bottom tab bar |
| `includes/app/activities.php` | New — Activities browse + request |
| `includes/booking.php` | Add `fetch_portal_activities()` + `fetch_tour_categories()` |
| `booking.php` | App shell: load CSS, compact top bar, `view=activities`, include nav; remove old hero/`.bk-*` inline styles as markup is replaced |
| `includes/app/status-header.php` | Restyle to `.pa-*` (compact status summary) |
| `includes/app/home.php` | Restyle → greeting + status + featured activities + concierge shortcut |
| `includes/app/concierge.php` | Restyle; relocate the Transfer form in; drop the Activity tile |
| `includes/app/stay.php` | Restyle to `.pa-*` |
| `includes/booking-manage-actions.php` | Reorg: tours removed (→ Activities); keep change form; relocate transfer (→ concierge); restyle; used by the Booking view |

No DB migration.

## Testing

- Visual/behavioural (browser E2E): each of the five sections renders with the bottom nav; active tab highlights; Activities shows cards (gradient fallback when no image, real photo when present) with category filter working; "Request this activity" creates a `booking_addons` (kind=tour) row and shows in Your requests; Concierge service + transfer requests still work; Stay renders admin content; Booking shows details + change + cancel + requests list; magic-link and code login both land on Home; `noindex` present.
- Regression: the add-on/change/cancel endpoints and admin Confirm/Decline/Mark-done are unchanged and still work; public booking widget + convert-enquiry unaffected.
- Responsive: fixed bottom nav doesn't obscure content (body padding); horizontal chip scroll works; cards legible at 360–414px widths.
