# Request lifecycle & notifications

**Date:** 2026-08-04
**Status:** Approved design — ready for planning
**Sub-project:** D of five. (A, B shipped; C, E separate.)

## Problem

A guest request (concierge service or activity) is created with a status, but the lifecycle is invisible and silent:
- When staff **Accept / Decline / Mark-done** a request (`admin/booking-request-action.php`), the status updates + audit-logs, but the guest gets **no message and no notification**.
- The portal **conversation view shows no request details** — opening a request thread shows only the message bubbles, not what the request was (label, pax, date, price, current status).
- Message threads **don't reorder on activity**, so an approved/updated request doesn't move up the guest's Messages list.

## Goal

Close the loop: a status change auto-posts a message the guest is notified of (Messages-tab badge), the request thread bumps to the top and shows its current status, and opening a request thread shows the request's full info.

Non-goals: email notifications (in-app only, per brainstorming); a note field on the quick actions (fixed templates); change-request (`booking_change_requests`) threads (those have no message thread — unchanged); any DB migration.

## Decisions (from brainstorming)

- **In-app only** notification (the auto-message → the existing `count_unread_guest` badge on the Messages tab). No email.
- **Fixed-template** auto-message per status (staff can still type a custom follow-up manually).
- **General** thread stays pinned at the top; **request threads sort by most-recent message**.

## Design

### 1. Auto-message on status change — `admin/booking-request-action.php`

After a successful `booking_addons` status update (the `type === 'addon'` path, statuses `confirmed | declined | cancelled | completed`), post an **admin** message into that request's thread using a fixed template, then continue to the existing flash/redirect. `$cur['hold_id']` and `$id` (the addon id) identify the thread. Wrapped in try/catch so a messaging failure never breaks the status action.

Templates (`request_status_message($status)`):
| status | message |
|--------|---------|
| confirmed | "Confirmed ✓ — we'll take care of it." |
| completed | "Marked as done ✓" |
| declined  | "Sorry, we can't fulfil this request." |
| cancelled | "This request was cancelled." |
| (other)   | "" (no message posted) |

The message is `sender='admin'`, `read_by_admin=TRUE`, `read_by_guest=FALSE` → it counts toward `count_unread_guest()` (the nav Messages badge) and, via the reorder below, bumps the thread to the top. Change-request (`type === 'change'`) actions are unchanged (no thread).

### 2. Request header in the conversation — `includes/app/messages.php`

In the conversation view, when viewing a **request** thread (`$__addonId !== null`), fetch the addon and render a header card above the messages showing:
- the request **label** (`addon_label($addon)`),
- a **status pill** (`addon_status_label($addon['status'])`, class `pa-pill pa-pill--<status>`),
- a **meta line**: **pax** (`· N pax`, when `kind='tour'` and `pax` set), **date/time** (`scheduled_for`, formatted), and **price** (`format_price(price_amount)`, when set).

The General thread (`$__addonId === null`) shows no header (nothing to describe). If the addon can't be fetched, render no header (messages still show).

### 3. Threads bump on activity — `fetch_message_threads()` (`includes/booking.php`)

Today the array is `[general, …addons by created_at DESC]`. Change: keep **general first**, then sort the **request threads by most-recent activity DESC** — `COALESCE(last message time, request created_at)`. The addons query gains `ba.created_at` (for the fallback); the existing per-thread `last_at` is the primary key. A request with a fresh admin/guest message therefore rises to just under General.

## Helpers — `includes/booking.php`

- `post_admin_message(int $holdId, ?int $addonId, string $body): void` — insert a `booking_messages` row `sender='admin', read_by_guest=FALSE, read_by_admin=TRUE` (admin counterpart of `seed_request_message`).
- `request_status_message(string $status): string` — the template table above; `''` for unknown.
- `fetch_addon_for_thread(int $holdId, int $addonId): array|false` — `SELECT ba.*, t.name AS tour_name FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id WHERE ba.id = :a AND ba.hold_id = :h` (ownership-scoped; else false). For the conversation header.
- `fetch_message_threads()` — add `ba.created_at` to the addon SELECT and the general-pinned, activity-sorted ordering.

## Error handling / edge cases

- **Messaging failure on status change**: caught + logged; the status update and redirect still succeed (the auto-message is best-effort).
- **Double action guard**: unchanged — `booking-request-action.php` already rejects a transition from a non-allowed current status, so no duplicate auto-messages for an already-actioned request.
- **Staff scoping**: unchanged — the handler already blocks staff acting outside their venues before any update/message.
- **Header for a non-request (general) thread**: no header rendered.
- **Missing addon** (deleted between list and open): `fetch_addon_for_thread` → false → no header; conversation still renders.
- **Escaping**: header fields via `e()`; `price_amount`/`pax` cast; `scheduled_for` via `date()`.

## Testing — `tests/portal_logic.php` (extend; `check()` style, self-cleaning)

- `request_status_message`: 'confirmed'/'completed'/'declined'/'cancelled' return non-empty distinct strings; 'weird' returns ''.
- `post_admin_message`: on a seeded hold+addon, inserts a row with `sender='admin'`, `read_by_guest=FALSE`; `count_unread_guest($hold)` increases by 1; clean up.
- Thread ordering: seed two addons on a hold (A older, B newer by created_at); `post_admin_message` on A; `fetch_message_threads($hold)` returns General at index 0, and A before B among the request threads. Clean up.
- `fetch_addon_for_thread`: returns the row (incl. `status`, `pax`, `price_amount`, `tour_name`) for a valid (hold, addon); false for a mismatched hold. Clean up.

## Rollout

No migration. Ship: staff actions immediately message + notify the guest; request threads show status + info and bump on activity.
