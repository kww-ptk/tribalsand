# Convert Enquiry → Hold (Admin) — Design

**Date:** 2026-07-20
**Status:** Approved design, pending implementation plan

## Summary

Give admins a "Convert to hold" action on the submission detail page
(`admin/submission-view.php`) that turns an enquiry (a `submissions` row) into a real
availability hold (a `holds` row) — generating the guest access code, blocking the dates,
and linking the hold back to the originating submission. This closes the gap where an
enquiry lead currently has no path to become a managed booking.

## Goals

- One place in admin to create a hold from an existing enquiry, prefilled from its data.
- Works for any submission — including enquiry-mode leads with no room/dates — by letting
  staff pick the room/unit and dates (the "fallback").
- The created hold links to the submission; the submission is preserved and shows the link.
- After conversion, staff can immediately grab the booking code + portal link to send the
  guest manually (email is not connected yet).

## Non-Goals (v1)

- No availability enforcement — staff **force-create**; overlaps are theirs to manage (per decision 2026-07-20).
- No emailing the guest on conversion (email/Resend not connected). A "send portal link" button is a later add-on.
- No deletion or status-mutation of the submission beyond showing the linked hold.
- No bulk convert, no quote/payment fields, no availability calendar UI.
- No DB migration — uses existing tables and the existing `holds.submission_id` link.

## Decisions (2026-07-20)

- **Availability:** always force-create (ignore `find_available_unit`; block the chosen dates regardless).
- **Enquiry fate:** keep the submission, linked to the hold (preserve lead + tracking); show a "Converted → Hold #N" banner.

## Data Model

No schema change. Uses:
- `holds` (created via `create_hold_with_block()`, which already sets `access_code` + `expires_at` and inserts the `availability_blocks` row).
- `holds.submission_id` — existing FK linking a hold to its submission (`ON DELETE SET NULL`).
- `units` — a hold must attach to a unit; the form lists all `Room — Unit` pairs.

## Component: `admin/submission-view.php`

### On load
- Fetch any existing hold for this submission:
  `SELECT id, status, access_code, guest_name, check_in, check_out FROM holds WHERE submission_id = :id ORDER BY id DESC LIMIT 1`.
- If a hold exists → render the **Converted banner** (see below), no form.
- Else → render the **Convert form**.

### Converted banner (when a linked hold exists)
Shows: "Converted → Hold #N" with the hold status badge, the **booking code** (monospace), and a
**Copy portal link** button (reusing `make_manage_url()` + the `.copy-link` JS pattern added in
`admin/holds.php`), plus a link to `/admin/holds.php`. This gives staff the guest access details
immediately after converting.

### Convert form (when no linked hold)
A new card "Convert to hold", CSRF-protected, prefilled from the submission:
- **Room / Unit** — one `<select name="unit_id">` listing every active `Room — Unit` pair
  (value = `units.id`, label = `"<room name> — <unit name>"`). Prefilled to the first active unit
  of the submission's room if that room has units; otherwise no preselection.
- **Check-in / Check-out** — `<input type="date">`, prefilled from `submissions.check_in/out`.
- **Guest name / email** — prefilled from the submission, editable (these are the only guest fields `holds` stores).
- **Guest reference (read-only)** — phone / adults / children shown from the enquiry for staff context, NOT form fields (the `holds` table has no columns for them, so collecting them would imply persistence that doesn't exist).
- **[Create hold]** submit button.

> Simplification: because `holds` stores only name/email (not phone/adults/children), v1 collects
> name/email/dates/unit. Phone/adults/children are shown read-only from the enquiry for staff
> reference but are not form fields — avoids implying data that isn't stored.

### POST handler (`action=convert`)
1. `verify_csrf()`.
2. Guard: re-query for an existing hold on this submission; if found, flash "already converted" and redirect (prevents double-submit duplicates).
3. Validate: `unit_id` is a positive int that exists in `units`; `check_in` and `check_out` are `YYYY-MM-DD`; `check_in < check_out`; `guest_name` non-empty; `guest_email` valid. On any failure → flash error, redirect back to the submission view (no partial hold).
4. **Force-create:** `create_hold_with_block((int)$unit_id, (int)$submission_id, $check_in, $check_out, $guest_name, $guest_email)`. (No availability check — this is the force-create path; it inserts the hold with a generated `access_code` and blocks the dates.)
5. `audit_log('hold.create_from_submission', 'hold', $hold_id, "from submission #{$id} — {$guest_name} {$check_in}→{$check_out}")`.
6. Flash success ("Hold #N created from this enquiry."), redirect back to `/admin/submission-view.php?id=<id>` — which now renders the Converted banner.

## Helpers (`includes/db.php`)

- `fetch_hold_by_submission(int $submission_id): array|false` — the SELECT above.
- `fetch_room_unit_options(): array` — rows of `{unit_id, room_name, unit_name}` for the dropdown,
  ordered by room sort_order then unit sort_order, active units only. (Or inline the query in the
  view; a helper keeps the view lean and is testable.)

## Security & Conventions

- Admin-only (`require_login()` already at top of the file) + `verify_csrf()` on the POST (matches the existing delete action).
- All bound params (PDO), all output escaped with `e()`.
- `client_ip()` not needed here (admin action, audited).

## Testing

- Convert a submission that has a room+dates → hold created, `access_code` set, `availability_blocks` row created, `holds.submission_id` = submission id; submission view now shows the Converted banner with code + link.
- Convert a submission with no room/dates (enquiry-mode lead) → staff pick unit+dates → hold created.
- Double-submit / reload of the POST → no second hold (guard triggers, flash "already converted").
- Invalid input (missing unit, `check_in >= check_out`, bad date, empty name/email) → flash error, no hold created.
- Force-create on already-blocked dates → succeeds (overlap allowed by design).
- `fetch_hold_by_submission()` returns false when none, the row when present.

## Files

| File | Change |
|------|--------|
| `admin/submission-view.php` | Add Convert card + Converted banner + `action=convert` POST handler |
| `includes/db.php` | Add `fetch_hold_by_submission()` + `fetch_room_unit_options()` |
