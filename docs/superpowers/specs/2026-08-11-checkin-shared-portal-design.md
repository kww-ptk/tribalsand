# Check-in — Shared Booking Portal (Sub-project C)

- **Date:** 2026-08-11
- **Status:** Approved design, ready for implementation plan
- **Sub-project:** C of the check-in improvements epic (A shipped PR #51; B shipped PR #52; D multi-unit **dropped**). C is the last one.
- **Base branch:** `feature/checkin-shared-portal`, branched from `feature/checkin-guest-management` (B). Chain: `master` ← A (#51) ← B (#52) ← C. Rebase/retarget as the earlier PRs merge.
- **Staged build:** one spec, **two stacked PRs** — **C-1** (foundation + requests attribution) then **C-2** (bill + messaging).

## Problem

A booking has one portal, reachable only by the lead's `?ref=` magic link. Co-guests (the `checkin_guests` roster from A/B, each with a `?g=` token) can reach only the check-in screen. For a shared trip (e.g. two partners on one booking) both people should be able to use the portal — make service requests and see the bill — with everything attributed by name. Today no request, bill line, or message records *which* guest it came from, and there is no guest-facing bill.

## Goal & scope

**In scope**
- A per-booking **"share reservation"** admin toggle (`holds.share_reservation`, default off).
- When on, co-guest `?g=` links open the **full participant portal** (home, activities, messages, bill), not just check-in.
- **Requests attributed by name** — a co-guest's service request records who made it; shown by name to guests and admin.
- **Whole shared bill, itemized by name** — a new guest-facing bill view (shown when shared), plus name labels in the admin bill.
- **Shared messaging thread labeled by name** — each message records its sender guest; bubbles show the sender's name on both sides.
- Identity reuses the `checkin_guests` roster + `?g=` token (no new identity system). The lead is their `is_lead` row.

**Out of scope**
- Booking management by co-guests: **cancel / change dates stay lead-only** (`?ref=` only), hidden *and* server-gated. `booking_change_requests` therefore gains no attribution.
- Co-guests seeing other guests' passport PII (A's Policy A stands).
- Per-guest private message threads, bill splitting/payment, and any guest-visible bill on **non-shared** bookings (today's admin-only bill is unchanged there).
- Multi-unit (D — dropped).

## Decisions

1. **Toggle:** `holds.share_reservation BOOLEAN NOT NULL DEFAULT FALSE`. Admin toggle in the check-in tab beside "Require check-in", gated by `can_view_guest_docs($holdId)`. Off = today's behavior exactly.
2. **Acting-guest resolver:** a shared `shared_portal_actor()` in `includes/booking.php` returns `[holdId, guestId, isLead]` from the request — `?ref=` → the (lazily-seeded) `is_lead` row; `?g=` → that guest, **only if `share_reservation` is on**; else 403. Endpoints use it to stamp attribution.
3. **Attribution columns** (nullable FKs → `checkin_guests(id) ON DELETE SET NULL`; existing rows = NULL = "the lead/booking"): `booking_addons.requested_by` (C-1), `bill_items.guest_id` (C-2), `booking_messages.sender_guest_id` (C-2). `holds.share_reservation` (C-1).
4. **Endpoints accept `?g=`** (gated by `share_reservation`): `api/booking-addon.php` (C-1) and `api/booking-message.php` (C-2). `api/booking-change.php` stays `?ref=`-only (co-guests can't request date changes).
5. **Guest bill view** appears in the portal only when `share_reservation` is on (for the lead and co-guests). Read-only.
6. **Migration-safe:** a `share_reservation_supported()` guard (probes the column); all new reads null-safe; feature not live, no production data to migrate.

## Identity & attribution model

- Co-guest = `checkin_guests` row; `?g=` token binds `[holdId, guestId]` (`verify_guest_pass_token`). Lead = the `is_lead` row; add `checkin_ensure_lead_guest_id($holdId): int` (seed via the existing `ON CONFLICT (hold_id) WHERE is_lead` upsert, return id) so the lead can be attributed too.
- `shared_portal_actor()` centralizes the ref-or-g resolution + the `share_reservation` gate for the `?g=` path.
- Name display: a small `guest_display_name(?array $g): string` (first name, else "Guest") used everywhere attribution is shown. Reads join `checkin_guests` on the attribution FK.

## The work — C-1 (foundation + requests)

**Migration** `db/migrations/add_shared_portal.sql`: `holds.share_reservation`, `booking_addons.requested_by`.

**Toggle:** `admin/_ws_checkin.php` — a "Share reservation" form (owner/venue-manager) beside "Require check-in"; a `share_toggle` action in `admin/booking.php`'s POST dispatcher (mirror `checkin_toggle`), `can_view_guest_docs`-gated.

**Co-guest portal access:** in `booking.php`, the `?g=` branch stops `exit`-ing when `share_reservation` is on: set `$hold`, `$holdId`, `$actorGuestId`, mark the request as a co-guest session, and fall through to the `view=` router. Nav (`includes/app/nav.php`) renders the participant tabs. Booking-management controls hidden for co-guests; the change/cancel endpoints reject `?g=` server-side.

**Requests attribution:** `api/booking-addon.php` uses `shared_portal_actor()` (accepts `?g=` when shared) and `insert_booking_addon()` stamps `requested_by`. `fetch_booking_addons()` joins `checkin_guests` to return the requester's name. Admin `admin/_ws_requests.php` and the guest request views show **"Requested by <name>"**.

**Files (C-1):** `db/migrations/add_shared_portal.sql`; `includes/booking.php` (resolver, ensure-lead helper, `guest_display_name`, `share_reservation_supported`, addon insert + fetch); `booking.php` (shared `?g=` gate + router threading); `includes/app/nav.php`; `api/booking-addon.php`; `api/booking-change.php` (reject `?g=`); `admin/booking.php` (`share_toggle`); `admin/_ws_checkin.php` (toggle UI); `admin/_ws_requests.php` (name); `tests/checkin_logic.php` (pure helpers).

## The work — C-2 (bill + messaging)

**Migration** `db/migrations/add_shared_portal_bill_msg.sql`: `bill_items.guest_id`, `booking_messages.sender_guest_id`.

**Guest bill view:** add `bill` to the portal `view=` whitelist (rendered only when `share_reservation` on); new `includes/app/bill.php` — read-only list of priced requests (`booking_addons` where `status IN ('confirmed','completed')`) + `bill_items`, each labeled with the guest's name (join on `requested_by` / `guest_id`), plus the total (`bill_total`). `includes/app/nav.php` shows a Bill tab when shared. Admin `admin/_ws_bill.php` gains name labels; `bill_add` (in `admin/booking.php`) accepts an optional `guest_id`.

**Messaging by name:** `api/booking-message.php` uses `shared_portal_actor()` (accepts `?g=` when shared) and stamps `sender_guest_id`. Message fetch helpers (`fetch_thread_messages*`) return `sender_guest_id`; `includes/app/messages.php` and admin (`admin/_ws_messages.php` / `admin/messages-poll.php` / `message_payload()`) label guest bubbles with `guest_display_name` instead of a generic "Guest"/"You".

**Files (C-2):** `db/migrations/add_shared_portal_bill_msg.sql`; `includes/booking.php` (bill fetch joins, message insert + fetch + payload); `booking.php` (bill view route); `includes/app/nav.php` (Bill tab); `includes/app/bill.php` (new); `includes/app/messages.php`; `api/booking-message.php`; `admin/messages-poll.php`; `admin/_ws_bill.php`; `admin/booking.php` (`bill_add` guest_id).

## Guarding & backward-compat

- All co-guest portal access + `?g=` endpoint paths gated by `share_reservation` (off = current behavior; co-guests do check-in only).
- Attribution columns nullable; NULL renders as the lead / "Guest". `booking_change_requests` untouched.
- Cancel / change-dates remain lead-only, server-enforced (not just hidden).
- Reads guarded by `share_reservation_supported()`; no booking-model change.

## Testing

Pure helpers unit-tested in `tests/checkin_logic.php`: `guest_display_name()`, and the `shared_portal_actor()` gating decision (ref → lead; g + shared → guest; g + not-shared → denied) factored into a pure helper where possible. E2E on a throwaway `ZZ` hold: turn share on, open a co-guest `?g=` link → full portal; the co-guest posts a request → admin sees "Requested by <name>"; (C-2) the bill view lists items by name and a co-guest message shows their name. `php tests/*_logic.php` stays green.

## Conventions

`can_view_guest_docs` / `is_owner` / `is_manager` gating; `verify_csrf()`; `client_ip()`; `verify_guest_ref` / `verify_guest_pass_token` for portal auth; pre-migration guards; no booking-model change.
