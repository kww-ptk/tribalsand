# Portal Home redesign + per-property stay info + requests-as-conversations

**Date:** 2026-08-04
**Status:** Approved design — ready for planning
**Area:** Guest portal (`booking.php`, `includes/app/*`), admin (`admin/venue-edit.php`), request/message plumbing (`api/booking-addon.php`), DB (`venues`).

## Problem

The guest portal Home tab currently:
- Renders the **marketing site nav** (`includes/header.php`) on top of the app — visually wrong for an app.
- Shows a topbar with a generic section title ("Your stay") and a separate "Karibu, {name}" greeting lower down (duplication).
- Pulls Wi-Fi / check-out / house rules / area guide from **global site settings** (`setting('stay_*')`) — but these differ per property (Watamu vs Kilifi vs Vipingo).
- Has no property **address / map** in the booking box.
- Surfaces "My trip" and "Need something?" as always-expanded blocks; the user wants tighter, calmer, collapsible sections.
- Treats concierge requests as fire-and-forget form submissions: a request row is created but its message thread starts **empty**, so guest and staff have no single conversation to manage it.

## Goals

1. Remove the marketing nav from the portal; lead with the guest greeting.
2. Put the **property address in the booking box**, tappable to open Google Maps.
3. Make Wi-Fi / check-out / house rules / area guide (and address/map) **per property**, editable on the Property edit page.
4. Reorganize Home into calm, collapsible sections, resized larger/easier.
5. Rename "My trip" → **"My Calendar"** and make it a dropdown.
6. Add **Activities** and **Messages** shortcut tiles to "Need something?"; replace "Towels & amenities" with **"Make a request"**.
7. **Every concierge request auto-starts a conversation**: on submit, seed the request's message thread with the guest's request and drop the guest into that thread. Staff manage each request as a conversation.

Non-goals: redesigning Activities or Messages tabs; changing the admin Concierge Desk; changing booking/cancel logic; touching marketing pages.

## Decisions (from brainstorming)

- **Address/Maps storage:** per-venue `address` (display text) **+ optional** `maps_url`. Pin opens `maps_url` if set, else a Google Maps search for `address`. If both empty, no address row renders.
- **Admin editing:** a new "Stay info & location" card on `admin/venue-edit.php`. The **global Stay Info page is retired** (`admin/stay-info.php` deleted, nav item removed). A blank field simply hides in the app.
- **Guest board placement:** stays at the **bottom of Home**, after the concierge tiles.
- **Greeting scope:** Home's topbar title becomes "Karibu, {first name}". Activities/Messages tabs keep their own titles. The small "Tribal Sand" eyebrow stays.

## New Home layout (top → bottom)

1. **Topbar** — eyebrow "Tribal Sand" + title. Home: `Karibu, {first}`. Activities: `Activities`. Messages: `Messages`.
2. **Booking box** (`status-header.php`) — room name + status badge, check-in/out, booking code, plus a new **address row** (pin → Google Maps).
3. **Your stay** — collapsible `<details>`, per-property Wi-Fi / check-out / house rules / area guide.
4. **My Calendar** — collapsible `<details>`, the day-by-day itinerary (was "My trip").
5. **Need something?** — icon tile grid: Laundry · Housekeeping · **Make a request** · Maintenance · Restaurant · Transfer, then **Activities** and **Messages** shortcut tiles (visually distinct/dark; Messages shows unread count).
6. **Guest board** — property updates/promos (greeting removed from this partial).
7. Cancel card (unchanged, home only).

## Data model

New migration `db/migrations/add_venue_stay_info.sql` (idempotent):

```sql
ALTER TABLE venues
  ADD COLUMN IF NOT EXISTS address          TEXT,
  ADD COLUMN IF NOT EXISTS maps_url         TEXT,
  ADD COLUMN IF NOT EXISTS stay_wifi        TEXT,
  ADD COLUMN IF NOT EXISTS stay_checkout    TEXT,
  ADD COLUMN IF NOT EXISTS stay_house_rules TEXT,
  ADD COLUMN IF NOT EXISTS stay_area_guide  TEXT;

-- One-time seed: carry existing GLOBAL stay values into every venue as an
-- editable starting point, so the app isn't blank on deploy. Only fills NULLs.
UPDATE venues SET
  stay_wifi        = COALESCE(stay_wifi,        (SELECT setting_value FROM settings WHERE setting_key='stay_wifi')),
  stay_checkout    = COALESCE(stay_checkout,    (SELECT setting_value FROM settings WHERE setting_key='stay_checkout')),
  stay_house_rules = COALESCE(stay_house_rules, (SELECT setting_value FROM settings WHERE setting_key='stay_house_rules')),
  stay_area_guide  = COALESCE(stay_area_guide,  (SELECT setting_value FROM settings WHERE setting_key='stay_area_guide'));
```

No new tables for messaging — `booking_messages` (with `addon_id`) already models per-request threads.

## Backend / helpers

**`includes/booking.php`**
- Add `fetch_venue_stay(?int $venueId): array` returning `['address','maps_url','stay_wifi','stay_checkout','stay_house_rules','stay_area_guide']` (empty array/blanks when `$venueId` is null or row missing). Guarded so it degrades to blanks pre-migration.
- Add `venue_maps_link(array $stay): string` — returns `maps_url` if non-empty, else `https://www.google.com/maps/search/?api=1&query=<urlencoded address>`, else `''`.

**`api/booking-addon.php`** (requests → conversations)
- After the `INSERT INTO booking_addons`, capture `addon_id = (int)db()->lastInsertId()`.
- Insert the opening message:
  ```sql
  INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
  VALUES (:h, :aid, 'guest', :body, TRUE, FALSE)
  ```
  `:body` = the composed `$details` (already human-readable, e.g. "Wash & fold — preferred 3pm"). Wrapped in its own try/catch so a messaging failure never fails the request.
- Response gains a redirect: `{ok:true, redirect: "/booking.php?ref=<ref>&view=messages&thread=<addon_id>"}`. `ref` is re-signed via `make_guest_ref($hold['id'])` (never trust the posted ref for the URL).
- `send_addon_request_notification` stays as-is.

**`js/booking-manage.js`**
- On `data.ok`: if `data.redirect` present, `window.location = data.redirect` (after the brief success flash); else keep current `location.reload()`.

## Front-end changes

**`booking.php`**
- Remove `include includes/header.php`.
- Set `$portal_chrome = true` before including `footer.php`.
- Compute `$first` (guest first name); topbar title = `Karibu, {first}` on home, section name otherwise.

**`includes/footer.php`**
- Gate the visible marketing `<footer class="ts-footer">`, WhatsApp link, and cookie banner behind `if (!($portal_chrome ?? false))`. **Always** emit the `window.showSuccessModal` script (portal depends on it).

**`includes/app/status-header.php`**
- Fetch stay/location via `fetch_venue_stay($hold['venue_id'])`.
- Render an address row under the booking details when a maps link exists: pin icon + `address` text + "Open in Google Maps →", `<a target="_blank" rel="noopener">` to `venue_maps_link()`. All output `e()`-escaped; the href is a fixed Google base + `urlencode`d address, or the stored `maps_url`.

**`includes/app/_stay_essentials.php`**
- Source values from `fetch_venue_stay($hold['venue_id'])` instead of `setting('stay_*')`. Same collapsible UI; blank fields hide; if all blank, the whole section hides.

**`includes/app/_trip.php`**
- Heading "My trip" → **"My Calendar"**; wrap the itinerary + "Add to plan" in a `<details class="pa-details">` (collapsed by default), matching "Your stay".

**`includes/app/_services.php`**
- Tiles: drop `amenities`; relabel/add **"Make a request"** mapping to existing free-form `kind = 'other'`.
- Append two **anchor** tiles (not forms): **Activities** → `?ref=…&view=activities`; **Messages** → `?ref=…&view=messages` with the unread badge from `count_unread_guest()`. Distinct dark styling.
- Copy tweak: "Tap what you need — it opens a chat with our team."

**`includes/app/home.php`**
- New include order: `status-header` (already included by booking.php for home) → `_stay_essentials` → `_trip` → `_services` → `_greeting_board`.

**`includes/app/_greeting_board.php` (now guest board only)**
- Keep the filename. Remove the "Karibu" greeting line (now in the topbar); keep the board grid exactly as-is.

**`admin/venue-edit.php`**
- New "Stay info & location" card + `action = 'save_stay'` handler updating the six new columns (`address`, `maps_url`, `stay_wifi`, `stay_checkout`, `stay_house_rules`, `stay_area_guide`). Standard `verify_csrf()` + `audit_log('venue.stay', 'venue', $id)` + PRG. Owner-only (page already `require_owner()`).

**`admin/stay-info.php` + `admin/_layout.php`**
- Delete `admin/stay-info.php`; remove its nav item (`stay_info`) from `_layout.php`.

## CSS

Extend `css/portal-app.css` for the resized layout: larger topbar/greeting, roomier cards, bigger tap targets, the address row (`.pa-maps`, `.pa-maps__pin`), distinct nav tiles (`.cx-tile--nav`) and unread badge on the Messages tile. Reuse existing `.pa-details`, `.cx-grid`, `.cx-tile` tokens; no new framework.

## Error handling & edge cases

- **No venue on hold** (`venue_id` null): address row and per-property stay fields hide; nothing errors.
- **Pre-migration** (columns absent): `fetch_venue_stay` catches and returns blanks; sections hide.
- **Messaging insert fails**: request still succeeds; guest sees success, lands on the (possibly empty) thread; logged via `error_log`.
- **Maps link safety**: href is either the owner's stored `maps_url` or a Google base URL with a `urlencode`d address — never raw user text interpolated into markup; rendered with `e()` and `rel="noopener"`.
- **Escaping**: all new venue-sourced strings output through `e()`; `white-space:pre-wrap` retained for stay text.

## Testing

- `tests/portal_logic.php`: add coverage for `fetch_venue_stay` (venue with values, null venue, missing columns) and `venue_maps_link` (maps_url set; address-only; both empty).
- Manual (in-app browser via `php -S localhost:8765 router.php`): seed a hold on a venue with stay/address set; verify topbar greeting, address→Maps link, both dropdowns, "Make a request", and that submitting a request lands in a Messages thread seeded with the request. Then a venue with blank fields → sections hide. Clean up seeded rows after.

## Rollout

1. Merge; user runs `add_venue_stay_info.sql` via `/admin/migrate.php` on Neon.
2. Owner fills per-property stay info + address/map on each Property edit page (global values were seeded as a starting point).
