# Portal v3 — Concierge-first + Messages — Design Spec

**Date:** 2026-07-23
**Status:** Approved

## Goal
Reshape the guest portal into a focused property concierge web app:
1. Land on **Concierge** (the app home); remove the standalone Home view/tab.
2. Merge the duplicate **Booking + Stay** into one **Stay** tab.
3. Add **Messages** — per-request conversation threads (plus a general thread) between guest and staff, on both the guest portal and admin.
4. Remove **Cloudflare Turnstile** from the portal guest flows only (keep it on public enquiry forms; do not alter `verify_captcha()`).

Reuses the existing `booking_addons` pipeline; adds one table (`booking_messages`).

---

## 1. Navigation & routing (`booking.php`, `includes/app/nav.php`)
- `$view` whitelist becomes `['concierge','activities','messages','stay']`. Default (no/unknown `view`, **including the legacy `home`**) → `concierge`.
- Bottom nav tabs (in order): **Concierge · Activities · Messages · Stay**. Icons: keep concierge (bell), activities (compass), add messages (speech bubble), stay (calendar). Remove the Home tab and the Booking(manage) tab.
- The `home` and `manage` view branches are removed from `booking.php`'s view switch. `includes/app/home.php` is deleted (its content is redistributed, below). `includes/booking-manage-actions.php` is split: the change-request form moves into Stay; the "Your requests" list is removed (superseded by Messages).
- **Status header** (`includes/app/status-header.php`) renders only on `concierge` and `stay` (gate the include in `booking.php`), not on activities/messages.
- Per-view topbar titles (`$__titles`): concierge→"Concierge", activities→"Activities", messages→"Messages", stay→"Your stay".
- The nav gets an **unread badge** on the Messages tab: a small count bubble when the guest has unread admin messages (see §4).

## 2. Concierge (home) — `includes/app/concierge.php`
- Prepend the greeting **"Karibu, &lt;first name&gt;"** and the **guest board** (from the old home) above the service tiles. (Move `fetch_guest_board()` usage + the board render here; `$hold['venue_id']` is available.)
- Remove the old "← Back to home" link (this IS home) and the on-page "Recent requests" list (now in Messages).
- Keep the service tiles (laundry, housekeeping, amenities, maintenance, restaurant, transfer, other) with icons + the structured/free-text forms + scheduling field. **Remove the Turnstile widget** from every concierge form (see §5).

## 3. Stay (merged) — `includes/app/stay.php`
- Keeps its stay-info cards (wifi/checkout/house rules/area guide) and the empty-state.
- Appends the **Request a change** form (moved verbatim from `booking-manage-actions.php`): date/guests/notes → `POST /api/booking-change.php`, `data-bm`, `.bm-status`. **Remove its Turnstile widget.**
- The booking status details are already shown by the status-header at the top of the Stay view — no separate booking card needed.
- Delete `includes/booking-manage-actions.php` once its change form is relocated (nothing else references it after the manage view is removed — verify with grep).

## 4. Messages (new)

### Data model — `db/migrations/add_messages.sql` (+ append to `db/schema.sql`)
```sql
CREATE TABLE IF NOT EXISTS booking_messages (
    id            SERIAL PRIMARY KEY,
    hold_id       INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    addon_id      INT REFERENCES booking_addons(id) ON DELETE CASCADE,  -- NULL = general thread
    sender        TEXT NOT NULL CHECK (sender IN ('guest','admin')),
    body          TEXT NOT NULL,
    read_by_guest BOOLEAN NOT NULL DEFAULT FALSE,
    read_by_admin BOOLEAN NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_bmsg_thread ON booking_messages (hold_id, addon_id, created_at);
```
A **thread** is the set of messages sharing `(hold_id, addon_id)`. `addon_id IS NULL` is the single general thread per hold. No threads table.

### Helpers (`includes/booking.php`)
- `fetch_message_threads(int $holdId): array` — returns one row per thread: the addon (id/kind/details/status, NULL for general), latest message body + time, and `unread_guest` count (admin messages with `read_by_guest=FALSE`). Built by querying `booking_addons` for the hold (left-joined to their latest message) UNION the general thread; order by latest activity desc. Implementation may be two queries merged in PHP for clarity.
- `fetch_thread_messages(int $holdId, ?int $addonId): array` — all messages in a thread, oldest→newest.
- `mark_thread_read_by_guest(int $holdId, ?int $addonId): void` — set `read_by_guest=TRUE` on that thread's admin messages.
- `count_unread_guest(int $holdId): int` — total admin messages unread by guest (for the nav badge).
- Admin-side: `fetch_admin_threads(): array` (all threads across holds, unread-by-admin first, with guest name/venue), `count_unread_admin(): int`, `mark_thread_read_by_admin(int $holdId, ?int $addonId): void`.

### Guest UI — `includes/app/messages.php`
- Routing within `?view=messages`: no `thread` param → **thread list**; `&thread=<addon_id>` or `&thread=general` → **conversation**.
- **Thread list:** a "Message the team" entry (opens general thread) + one row per request thread showing service label (`addon_label`) + status pill (`addon_status_label`) + latest snippet + unread dot. Empty state if a hold has no requests yet ("Start a request from Concierge, or message the team.").
- **Conversation:** on load, call `mark_thread_read_by_guest`. Render message bubbles (guest right/teal, admin left/card) with timestamps. A reply form: `data-bm action="/api/booking-message.php"`, hidden `ref` + `addon_id` (empty for general), `body` textarea, send button, `.bm-status`. **No Turnstile.** A back link to the thread list.

### Guest endpoint — `api/booking-message.php` (new)
- POST JSON `{ref, addon_id?, body}`. Resolve hold by ref (`resolve_booking_by_ref`); 403 if invalid. Require `body` non-empty (trim, max ~2000 chars). If `addon_id` given, verify it belongs to this hold (else 422). **No Turnstile.** Rate-limit: max 20 messages / hold / 10 min (same pattern as booking-addon).
- INSERT `sender='guest', read_by_guest=TRUE, read_by_admin=FALSE`. Return `{ok:true}`. In-app only — no email (per decision).

### Admin UI — `admin/messages.php` (new) + nav link
- `require_login()`. Lists all threads across guests via `fetch_admin_threads()` (unread-by-admin first), each showing guest name + venue + service/general + latest snippet + unread badge.
- Thread view (`?hold=<id>&thread=<addon_id|general>`): on load `mark_thread_read_by_admin`; render the conversation; a reply `<form method=POST>` with `csrf_field()` → inserts `sender='admin', read_by_admin=TRUE, read_by_guest=FALSE`; redirects back to the thread (PRG). All output `e()`-escaped.
- Nav link "Messages" in `admin/_layout.php` (`$activeMenu='messages'`) with an unread-count badge from `count_unread_admin()`.
- **Concierge Desk** (`admin/concierge-desk.php`): add a "Message" link per row → `admin/messages.php?hold=<hold_id>&thread=<addon_id>`.

## 5. Remove Turnstile from portal guest flows
- Delete the `<div class="cf-turnstile" ...>` widget from: `includes/app/concierge.php` (all forms), `includes/app/activities.php`, the relocated change form (in `stay.php`), and never add it to `messages.php`.
- In `api/booking-addon.php` and `api/booking-change.php`, remove the `verify_captcha(...)` block (and its 403). Keep the ref check, status check, and rate-limit. `api/booking-message.php` never calls it.
- Do **not** modify `includes/turnstile.php` / `verify_captcha()` (still used by public forms) and do **not** touch `api/submit-enquiry.php`, `api/submit-contact.php`, `api/submit-agency.php`, or `includes/form-enquiry.php`. `captcha_site_key()` stays (used publicly).

## Security
- Guest message endpoint is magic-link (HMAC ref) gated + rate-limited; `addon_id` ownership verified; body length-capped; output `e()`-escaped (XSS-safe in both guest and admin thread views). Removing Turnstile from portal flows is acceptable because every portal write already requires the signed ref (unguessable) and is rate-limited; the change is scoped strictly to portal endpoints.
- Admin message send is `require_login()` + `verify_csrf()`; `hold`/`addon_id`/thread params validated; PRG redirect to a fixed local path (no open redirect).
- No SQL string interpolation; all `db_query()` prepared. No `$_SERVER['REMOTE_ADDR']` (use `client_ip()` in the endpoint rate-limit).

## Testing
- `tests/portal_logic.php`: assert the message helpers — send a guest message + an admin reply on a seeded hold, then `fetch_message_threads` shows the thread with correct `unread_guest`, `fetch_thread_messages` returns both in order, `mark_thread_read_by_guest` zeroes the unread count, and `count_unread_admin` reflects the guest message. Clean up.
- Browser E2E: land on `booking.php?ref=…` → **Concierge** renders (greeting + board + tiles), nav shows 4 tabs with no Home/Booking; submit a laundry request (no Turnstile present); open **Messages** → the request thread exists → send a message → appears; simulate an admin reply (insert) → guest thread shows it + the nav badge; open **Stay** → status + stay info + change form together; confirm `view=home` and `view=manage` both fall back to Concierge.
- Regression: existing tests pass; `php -l` clean; public enquiry forms still contain the Turnstile widget (grep) and their endpoints still call `verify_captcha`.

## Deploy
- Run `db/migrations/add_messages.sql` via `/admin/migrate.php` after deploy. The guest Messages tab and endpoint tolerate the table's absence only if guarded — so ship the tile/tab but the migration must be run promptly (Messages queries assume the table). Turnstile removal takes effect immediately on deploy (no DB dependency). Rollback: Render previous deploy; the unused table is harmless.
