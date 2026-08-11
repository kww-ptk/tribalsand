# Check-in — Guest Management & Fill-then-Sign (Sub-project B)

- **Date:** 2026-08-10
- **Status:** Approved design, ready for implementation plan
- **Sub-project:** B of the check-in improvements epic (A — signature & legal consent — shipped as PR #51; D multi-unit **dropped**; C shared portal is later)
- **Base branch:** `feature/checkin-guest-management`, branched from `feature/checkin-signature-consent` (B builds on A's signature/integrity code and the co-guest signing UI). Rebase/retarget onto `master` once A (PR #51) merges.

## Problem

Guests often hand their passport details to reception ahead of arrival, and the lead usually knows their party — but today only a guest can enter their own passport, and building the party is entirely guest-driven. Reception should be able to fill a guest's details (and upload their scan) so the guest only has to sign; staff should be able to add/remove adults and children from admin. And when a guest's details are already filled, opening their personal link should present a "just sign" state, not what looks like a blank form to fill again.

## Goal & scope

**In scope**
- Admin (owner / venue-manager) fills any guest's passport fields **and uploads the scan** from the check-in tab.
- Admin adds/removes adults and children from the check-in tab; adding an adult past the party size auto-bumps `guest_count`.
- The co-guest link shows a **review-and-sign** state when the passport is already complete, and "you're all set" once signed (the latter already works post-A).

**Out of scope**
- The signature is **never** set by admin — A's integrity rule stands (the guest draws it in their own session).
- Shared post-check-in portal / per-name requests & bill (sub-project C).
- Multi-unit bookings (D — dropped).

## Decisions

1. **Gate:** `can_view_guest_docs($holdId)` for every new admin action (owner, or the booking's venue manager) — same gate as passport scans. Front-desk staff unaffected.
2. **Location:** the new admin actions live in `admin/booking.php`'s existing check-in-tab POST dispatcher (session-authed, `verify_csrf()`, PRG redirect back to the tab) — consistent with the existing `checkin_toggle` / `guest_count_set` actions, no new JS.
3. **Integrity:** admin may fill/upload everything **except** `waiver_signature` / `waiver_signed_*`.
4. **Auto-bump:** adding an adult past `guest_count` raises `guest_count` (admin is defining the real party).
5. **Reuse:** add/remove mirrors the logic already in `api/checkin-guest.php`; scan upload mirrors `api/checkin-upload.php` (`storage_put_private`, `finfo` MIME sniff, 8 MB cap); completion via `checkin_recompute_completion()`. No new DB columns.

## The work

### 1. Admin fills a guest's passport (fields + scan)
`admin/_ws_checkin.php`: each adult row gets an **Edit** toggle revealing an inline PRG form — `passport_name` / `passport_number` / `nationality` / `passport_expiry` + a file input. Two `admin/booking.php` actions:
- `guest_fill` — `UPDATE checkin_guests SET passport_name, passport_number, nationality, passport_expiry WHERE id=:g AND hold_id=:h`. Never touches `waiver_*`.
- `guest_upload` — `finfo` MIME sniff (jpg/png/pdf), 8 MB cap, `storage_put_private('checkin/<hold>/<guest>/<rand>.<ext>')`, `UPDATE passport_file_key`, delete the previous file. Mirrors `api/checkin-upload.php`.

Both `can_view_guest_docs`-gated, `audit_log`'d, then `checkin_recompute_completion($holdId)`.

### 2. Admin adds/removes adults & kids
`admin/_ws_checkin.php`: "+ Add adult", "+ Add child" (per adult), and "Remove" (per non-lead guest). `admin/booking.php` actions:
- `guest_add_adult` — insert an adult (`is_lead=false, is_child=false`); if the adult count would exceed `guest_count`, raise `guest_count` to match. (Mirrors `api/checkin-guest.php` `add_adult`, minus the party-full 409.)
- `guest_add_child` — insert a child (`is_child=true`, `parent_guest_id` = a chosen adult on the hold, `passport_name` + optional `date_of_birth`).
- `guest_remove` — delete a non-lead guest (and their children); delete their stored scan file(s). (Mirrors `remove`.)

All gated, audited, then recompute. Admin-added adults surface their `?g=` link + the "Sign on this device" control that A already renders per unsigned adult, so admin can hand off signing.

### 3. Co-guest review-and-sign
`includes/checkin.php` gains a pure helper `checkin_coguest_view_state(?array $me, array $config): string` returning:
- `'done'` — the guest is fully signed (passport where required + waiver+signature),
- `'review_sign'` — passport complete but not yet signed,
- `'full'` — passport incomplete.

`includes/app/checkin-guest.php` uses it: on `'review_sign'`, render a compact summary of their passport (name · nationality · masked number · "scan on file") + an **"Edit my details"** toggle that reveals the existing editable fields, with the waiver + signature pad as the primary CTA. On `'full'`, the current full form. On `'done'`, the existing "You're all set" card. This is the direct fix for "when I reload the link it shows the form again."

## Files touched
- `admin/booking.php` — new check-in-tab actions (`guest_fill`, `guest_upload`, `guest_add_adult`, `guest_add_child`, `guest_remove`), `can_view_guest_docs`-gated.
- `admin/_ws_checkin.php` — per-guest Edit/upload UI + roster buttons.
- `includes/app/checkin-guest.php` — review-and-sign state.
- `includes/checkin.php` — `checkin_coguest_view_state()` (pure).
- `tests/checkin_logic.php` — assertions for the state helper.

## Testing
`checkin_coguest_view_state()` unit-tested (done / review_sign / full from passport + waiver flags). Admin actions + the co-guest state verified via browser E2E on a throwaway `ZZ` hold (A's pattern — insert directly, never the New Booking form; delete after, cascades `checkin_guests`). `php tests/*_logic.php` stays green.

## Conventions
`can_view_guest_docs` / `is_owner` / `is_manager` gating; `verify_csrf()`; `client_ip()`; `storage_put_private`; `checkin_recompute_completion`; reads stay pre-migration-guarded. No booking-model change (multi-unit dropped).
