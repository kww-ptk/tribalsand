# Portal v4 — Simplify (guest Home merge + admin booking workspace) — Design Spec

**Date:** 2026-08-03
**Status:** Approved

## Goal
Simplify both sides of the portal:
1. **Guest:** collapse Concierge + Stay into a single **Home** tab (3 tabs total: Home · Activities · Messages). The trip/itinerary lives on Home. Mobile-first, one clean scroll.
2. **Admin:** a single **booking workspace** (`admin/booking.php?hold=<id>`) with sub-tabs (Requests · Messages · Plan · Details) so staff manage a guest end-to-end without hopping between Holds, Concierge Desk, Messages, and Itinerary pages.

No new tables, no migration. Pure reorganization + one new admin page reusing existing helpers/endpoints.

---

## Part 1 — Guest Home

### Routing / nav (`booking.php`, `includes/app/nav.php`)
- `$view` whitelist → `['home','activities','messages']`; default `home`. Legacy `concierge`/`stay`/`manage` fall through to `home`.
- Bottom nav → **3 tabs**: Home (house icon) · Activities · Messages. Keep the Messages unread badge.
- Status header (`includes/app/status-header.php`) renders only on `home` (gate the include in `booking.php`).
- Per-view titles: home→"Your stay", activities→"Activities", messages→"Messages".

### Compose Home from partials
Refactor the current `concierge.php` + `stay.php` markup into focused partials (no behavior change to the pieces), then compose them in a new `includes/app/home.php`:
- `includes/app/_greeting_board.php` — "Karibu, <first name>" + the guest board (from concierge.php). Expects `$hold`, `$ref`.
- `includes/app/_trip.php` — "My trip": the day-by-day itinerary timeline + guest delete (×) + "＋ Add to plan" form (moved from stay.php). Expects `$hold`, `$ref`, `$status`.
- `includes/app/_services.php` — "Need something?": concierge service tiles + free-text/laundry/transfer forms + toggle script (from concierge.php). Expects `$hold`, `$ref`, `$status`.
- `includes/app/_stay_essentials.php` — "Your stay": Wi-Fi / check-out / house rules / area guide, wrapped in a collapsible `<details>` so it's tidy. Reads `setting()`.
- `includes/app/home.php` — includes, in order: `_greeting_board`, `_trip`, `_services`, `_stay_essentials`.

Delete `includes/app/concierge.php` and `includes/app/stay.php` once their content lives in the partials + home.php; remove their view branches from `booking.php`. Grep to confirm no remaining references.

### Home order (top → bottom)
1. Booking status card (status-header, rendered by booking.php on `home`).
2. Greeting + guest board.
3. **My trip** (itinerary + add-to-plan).
4. **Need something?** (concierge service tiles + forms).
5. **Your stay** essentials (collapsible `<details>`).

Reuses all existing `.pa-*` styles; add a small `.pa-details`/summary style if needed for the collapsible. All output `e()`-escaped; guest write flows unchanged (ref-gated, no Turnstile).

---

## Part 2 — Admin booking workspace

### `admin/booking.php?hold=<id>&tab=<requests|messages|plan|details>` (new)
- `require_login()`. Loads the hold (guest, room, venue, dates, code, status); flash+redirect to `holds.php` if not found.
- **Sub-tab nav** (chip links) with live counts: Requests (open addon+change count), Messages (unread-by-admin count), Plan, Details. Default tab `requests`.
- Renders each tab inline using existing helpers:
  - **Requests:** `fetch_booking_addons($hold)` + `fetch_booking_change_requests($hold)`; each row with inline Accept/Done/Decline (addons) / Mark-handled/Decline (changes). Actions POST to the existing `admin/booking-request-action.php` with a `return` that comes back to the workspace (see below).
  - **Messages:** `fetch_message_threads` → thread list; a thread view (`&thread=<addon_id|general>`) shows `fetch_thread_messages` + a reply form. Reply POST handled in `admin/booking.php` (insert `booking_messages` sender=admin, `mark_thread_read_by_admin` on view), PRG back to the same tab/thread.
  - **Plan:** the itinerary editor — `fetch_itinerary($hold)` merged view + add/delete form (same logic as `admin/itinerary.php`). Add/delete POST handled in `admin/booking.php`, PRG back to `?hold&tab=plan`.
  - **Details:** guest name/email, dates, nights, booking code, **Copy portal link** (reuse the holds.php approach: `make_guest_ref` → portal URL), status badge, and Confirm / Cancel actions (POST to the existing `admin/hold-action.php` or replicate the holds.php confirm/cancel handler — reuse whatever `holds.php` posts to).
- All POSTs `verify_csrf()`; all output `e()`-escaped; `audit_log` on writes.

### `admin/booking-request-action.php` — extend the return whitelist
It currently maps `return=concierge-desk` → `/admin/concierge-desk.php`, else `/admin/holds.php`. Add: `return=workspace` (with a `hold_id` POST field already present on those forms) → `/admin/booking.php?hold=<hold_id>&tab=requests`. Preserve `$_SESSION['hold_flash']`. No change to transition logic.

### Link the global lists into the workspace
- `admin/holds.php`: the per-hold **Plan** link (added earlier) becomes/【joins】a **Manage** link → `/admin/booking.php?hold=<id>` (keep the quick Cancel on the list).
- `admin/concierge-desk.php`: each row's actions gain (or the existing Message/Plan links point to) a **Manage** link → `/admin/booking.php?hold=<hold_id>&tab=requests`.
- `admin/messages.php`: each thread row → the workspace Messages tab (`/admin/booking.php?hold=<hold_id>&tab=messages`) — optional; can keep messages.php standalone and just add a Manage link.
- Keep the standalone Concierge Desk / Messages / Itinerary pages (global triage views); the workspace is the per-booking hub. `admin/itinerary.php` can remain or redirect to the workspace Plan tab — keep it (the workspace reuses its logic; no need to delete).

---

## Security
- Guest side unchanged in behavior: ref-gated writes, `e()`-escaping, no Turnstile on portal.
- Admin workspace: `require_login()` + `verify_csrf()` on every POST; hold loaded/validated; message/itinerary inserts scoped to the loaded `$hold['id']`; itinerary day validated within the stay range and category against the whitelist (same as `admin/itinerary.php`); request actions delegated to the already-guarded `booking-request-action.php`; the new `return=workspace` redirect is built from an integer `hold_id` (no open redirect). Output `e()`-escaped. SQL parameterized.

## Testing
- Existing tests must stay green (`portal_logic`, `manage_logic`, `convert_logic`); `php -l` clean on all changed/new files. No new DB helpers needed, so few new unit tests — add a small assertion that `fetch_itinerary`/message/addon helpers still return expected shapes (already covered).
- Browser E2E (mobile 375px + desktop):
  - Guest: `booking.php?ref=…` lands on **Home** with status → greeting/board → My trip (add/remove works) → service tiles (a request submits) → collapsible Your-stay. Nav = 3 tabs. `view=concierge`/`stay`/`manage` all fall back to Home. Activities + Messages unchanged.
  - Admin (logged-in portion may need the human, but verify structure/no-fatal): open `admin/booking.php?hold=<id>` → Requests tab lists the booking's requests with actions; Messages tab shows threads + reply; Plan tab adds/deletes an item; Details shows code + copy link + confirm/cancel. A request action returns to the workspace. Manage links from Holds/Desk open it.
- Regression: existing admin pages (concierge-desk, messages, itinerary, holds) still work; the `return=workspace` addition doesn't break `return=concierge-desk`/default.

## Deploy
No migration. Pure code — deploys on merge. Rollback: Render previous deploy.
