# Pre-Check-in — Design Spec

**Date:** 2026-08-06
**Status:** Approved for planning
**Author:** brainstormed with Claude

## Summary

Add a **Pre-Check-in** flow to the guest portal. A booking can be marked as
*requires check-in*. When it is, opening the portal presents a **hard gate**: a
mobile-first, multi-step check-in wizard. The concierge app (activities,
calendar, requests) stays locked until the required steps are complete. A
"Message the team" / contact escape hatch always remains reachable so a guest is
never stranded.

The goal is to automate data collection that today creates front-desk friction:
passport/ID, flight and transfer logistics, dietary needs, special requests, and
a signed indemnity/insurance waiver — all captured before arrival.

Admin controls which steps appear and which are mandatory, and can require
check-in on any booking (manual or enquiry-sourced).

## Decisions (locked during brainstorming)

1. **Gate strength — Hard gate.** Until check-in is complete, the portal shows
   only the check-in flow; concierge features unlock afterward. A
   "Message the team" link + the email/phone help footer stay reachable.
2. **Admin config — Fixed catalog + toggles.** A known set of steps, each with
   an *enabled* and a *required* switch. Not a dynamic form builder.
3. **Waiver signing — Typed-name e-consent.** Read terms → tick "I agree" → type
   full name. We store name + timestamp + IP + waiver version as the signature.
4. **File privacy — Private, admin-only.** Passport/waiver files stored under
   unguessable keys; served only through a logged-in admin proxy via signed GET,
   never a public URL. **Owner and managers can view the scan; other staff see
   only "on file ✓".**
5. **Toggle scope — Any booking, editable anytime.** A global
   "require check-in by default" setting plus a per-booking on/off editable from
   the booking detail page.
6. **Whose passport — Lead guest in the UI, per-guest table from day one.**
   Collect only the lead guest now, but model identity as one row per guest so
   "all adult guests" can be added later with no schema rework.
7. **Edit after submit — Editable until arrival day.** Guest can update their
   details up to the check-in date; locked afterward.
8. **Completion notice — In-admin, not email.** Email is not configured yet, so
   completion surfaces in the admin UI (Frontdesk arrivals board + Holds list +
   the Check-in tab). A guarded email sender is included but best-effort — it
   no-ops cleanly when mail is unconfigured and can be switched on later with no
   code change.
9. **Passport scan visibility — Owner + managers.** Managers who manage the
   booking's venue can view the scan and full passport number; non-manager staff
   see only "on file ✓".

## The step catalog

Six fixed steps. Each has admin **enabled** (show/hide) and **required**
(must complete to finish) switches. Field-level `required` follows the step.

| Key | Step | Collects |
|-----|------|----------|
| `arrival`  | Arrival & flight   | Airport of arrival, flight number, arrival date/time |
| `transfer` | Airport transfer   | "Need us to arrange it?" (yes/no) + details (pickup point, pax, luggage) |
| `passport` | Passport & identity| Name as on passport, passport number, nationality, expiry, **passport scan upload** (lead guest) |
| `dietary`  | Dietary requirements| Allergies / dietary notes (free text) |
| `requests` | Special requests   | Birthday surprises, wine in room, etc. (free text) |
| `waiver`   | Waiver & indemnity | Read terms → "I agree" checkbox → typed full name |

Default config: all steps enabled; `passport` and `waiver` required; others
optional. Admin can change any of this.

## Data model

One idempotent migration `db/migrations/add_checkin.sql`, runnable via
`/admin/migrate.php`. All new reads are wrapped in the project's pre-migration
`try/catch` guard pattern and gated behind a `checkin_supported()` capability
helper so nothing fatals before the migration runs.

### `holds` (add columns)
```sql
ALTER TABLE holds ADD COLUMN IF NOT EXISTS require_checkin      BOOLEAN     NOT NULL DEFAULT FALSE;
ALTER TABLE holds ADD COLUMN IF NOT EXISTS checkin_completed_at TIMESTAMPTZ;
```
State is derived, not stored: *not required* (`require_checkin = false`),
*pending* (`true` and `checkin_completed_at IS NULL`), *complete* (timestamp set).

### `booking_checkin` (booking-level, one row per hold)
```sql
CREATE TABLE IF NOT EXISTS booking_checkin (
    hold_id           INT PRIMARY KEY REFERENCES holds(id) ON DELETE CASCADE,
    -- arrival & flight
    arrival_airport   TEXT,
    flight_number     TEXT,
    arrival_at        TIMESTAMPTZ,
    -- transfer
    needs_transfer    BOOLEAN,
    transfer_details  TEXT,
    -- dietary & requests
    dietary           TEXT,
    special_requests  TEXT,
    -- waiver e-signature
    waiver_signed_name TEXT,
    waiver_signed_at   TIMESTAMPTZ,
    waiver_signed_ip   TEXT,
    waiver_version     TEXT,          -- hash/label of the terms the guest agreed to
    -- progress
    steps_saved        JSONB NOT NULL DEFAULT '{}'::jsonb,  -- {stepKey: savedAtIso} for resume
    submitted_at       TIMESTAMPTZ,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

### `checkin_guests` (per-guest identity — one row per guest)
```sql
CREATE TABLE IF NOT EXISTS checkin_guests (
    id               SERIAL PRIMARY KEY,
    hold_id          INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    is_lead          BOOLEAN NOT NULL DEFAULT TRUE,
    passport_name    TEXT,
    passport_number  TEXT,
    nationality      TEXT,
    passport_expiry  DATE,
    passport_file_key TEXT,           -- PRIVATE storage key, not a public URL
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_checkin_guests_hold ON checkin_guests (hold_id);
```
v1 UI writes exactly one row (`is_lead = true`). "All adult guests" later = allow
N rows; no schema change.

### Config (`settings` key/value store)
| Key | Value |
|-----|-------|
| `checkin_required_default` | `'0'` / `'1'` — default state of the toggle on new bookings |
| `checkin_steps`   | JSON: `{"arrival":{"enabled":true,"required":false}, ...}` |
| `checkin_waiver_text` | The indemnity / insurance waiver terms (editable) |
| `checkin_welcome` | Optional intro copy shown on the check-in landing screen |

Helpers (new `includes/checkin.php`):
- `checkin_supported(): bool` — do the columns/tables exist (capability guard).
- `checkin_config(): array` — merged step catalog (hard-coded defaults + settings overrides).
- `checkin_required(array $hold): bool` and `checkin_is_complete(array $hold): bool`.
- `fetch_checkin(int $holdId): ?array` and `fetch_checkin_guests(int $holdId): array`.
- `waiver_version(string $text): string` — stable hash label for the current terms.
- `can_view_guest_docs(int $holdId): bool` — `is_owner() || (is_manager() && staff_can_hold($holdId))`.
- `checkin_badge(array $hold): array` — `{label, class}` for the Frontdesk/Holds badge (or null when not required).

## Admin side

### `admin/hold-new.php` (manual booking)
Add a "Require check-in from this guest" checkbox, pre-ticked from
`checkin_required_default`. Pass through to hold creation
(`create_hold_with_block` gains an optional `require_checkin` argument, or a
follow-up `UPDATE` — implementation detail for the plan).

### `admin/booking.php` (booking workspace — new "Check-in" tab)
- Adds `checkin` to the tab set (`requests · messages · plan · bill · checkin · details`).
- **Require-checkin toggle** (owner) — flip on/off anytime; audit-logged.
- **Submitted data**, read-only: arrival/flight, transfer, dietary, special
  requests, waiver status ("Signed by <name> on <date>").
- **Sensitive-PII guard:** owner + assigned staff see status + logistics; the
  **passport scan and full passport number are visible to owner and managers**
  (non-manager staff see "Passport on file ✓"). Gated by a
  `can_view_guest_docs(int $holdId): bool` helper =
  `is_owner() || (is_manager() && staff_can_hold($holdId))`. Passport views are
  audit-logged.
- Files render via the private proxy (below), never inline public URLs.

### Check-in settings (owner-only)
A new owner-only config surface linked from `admin/settings.php` (new page
`admin/checkin-settings.php` to avoid nav bloat): per-step enabled/required
toggles, the global default, and an editor for the waiver terms + welcome copy.
Changes are audit-logged.

### `admin/checkin-file.php` (private file proxy)
`require_login()` → `can_view_guest_docs($holdId)` (owner or venue-managing
manager) → look up the `passport_file_key` for that hold → stream the object from
R2 via a **signed GET** (reuse the SigV4 signing already in
`includes/storage.php`). Sets correct content-type and `Content-Disposition`. No
public link is ever exposed. Each successful view writes
`audit_log('checkin.file_view', ...)`.

### Completion surfacing (Frontdesk + Holds)
Because email is not the notification channel, a completed check-in is visible
where staff already work:
- **`admin/frontdesk.php`** (arrivals board) — each arriving card gets a check-in
  badge next to the existing request/unread badges: green **"Checked in ✓"** when
  complete, amber **"Check-in pending"** when required-but-incomplete, nothing
  when not required. Reuses the `.fd-badge` component.
- **`admin/holds.php`** (bookings list) — a check-in column/badge on each row and
  an optional "check-in pending" filter, so the team can chase incomplete ones.
- All guarded by `checkin_supported()` so both pages render pre-migration.

## Guest side

### Gate (in `booking.php`)
When `checkin_required($hold)` and not `checkin_is_complete($hold)`:
- Render the check-in flow as the portal's main screen.
- Hide the concierge views (home essentials, activities, calendar, services).
- Keep reachable: the help footer (email/phone) and a "Message the team" link
  (the Messages view stays accessible as the escape hatch).
- Bottom nav collapses to the check-in context until complete.

After completion: concierge unlocks; Home shows a small "Checked in ✓" card with
a link to review/edit until arrival day.

### The wizard
New view `includes/app/checkin.php` (rendered by `booking.php?view=checkin`),
reusing the `.pa-app` shell and `css/portal-app.css`. Mobile-first, one step per
screen, progress indicator, Back/Next, then **Review → Submit**.

- **Resumable:** each Next persists that step (`api/checkin-save.php`) and stamps
  `steps_saved` so a returning guest lands on their first unfinished step.
- **Passport upload:** `api/checkin-upload.php` streams the file to R2 under an
  unguessable key and stores the key on the lead `checkin_guests` row; the client
  shows an "uploaded ✓" state (no re-fetchable public link).
- **Waiver step:** shows `checkin_waiver_text`; requires the checkbox + typed
  name; on submit records name + `now()` + `client_ip()` + `waiver_version`.
- **Submit** (`api/checkin-save.php?do=submit`): server re-validates that every
  *required, enabled* step is complete; on success sets
  `holds.checkin_completed_at = now()`, which surfaces on the Frontdesk/Holds
  boards (best-effort email if later configured), and unlocks the portal.

### Guest auth & CSRF
The portal is magic-link (ref-token) based. Check-in POSTs carry the `ref` and a
per-session CSRF token (added to `$_SESSION` in `booking.php`); handlers verify
both `verify_guest_ref($ref)` and the CSRF token before writing.

## Files & storage changes

`includes/storage.php` today hardcodes `Content-Type: image/jpeg` and a `rooms/`
target. Generalize:
- `storage_put($local, $key, $contentType, $folder)` — accept `image/jpeg`,
  `image/png`, `application/pdf`; target folder `checkin/`.
- Add `storage_get_signed_url($key)` **or** `storage_stream($key)` (signed GET)
  for the admin proxy. Keep the bucket private for these keys (store the key,
  not `R2_PUBLIC_URL`).
- Upload validation: extension + MIME allowlist (jpg/png/pdf), size cap (~8 MB),
  and rate-limiting on the upload endpoint.

## Notifications & audit

- **Primary channel is the admin UI** (email is not configured). On completion,
  `holds.checkin_completed_at` is set and immediately visible on the **Frontdesk
  arrivals board** and **Holds list** (see "Completion surfacing" above) and on
  the booking's **Check-in tab**.
- **Email is optional / best-effort** — a guarded `send_checkin_completed($hold,
  $data)` wrapper (over `send_resend()` in `includes/mail.php`) that returns
  early when `RESEND_API_KEY`/`MAIL_FROM` are unset, so the feature never depends
  on mail. When mail is later configured it starts sending with no code change.
- **Audit log:** `checkin.submit`, `checkin.require_toggle`, `checkin.config_change`,
  `checkin.file_view`.

## Conventions followed

- Pre-migration `try/catch` guards + `checkin_supported()` capability helper, so
  every page renders before the migration is applied.
- Idempotent migration (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`),
  run via `/admin/migrate.php`.
- CSRF on all POSTs; `client_ip()` (never `$_SERVER['REMOTE_ADDR']`); `e()` escaping.
- Owner vs. venue-scoped staff authorization via `is_owner()` / `staff_can_hold()`.
- Asset cache-busting `?v=filemtime()` for any new CSS/JS.

## Out of scope (v1)

- All-adult-guest passport capture in the UI (table already supports it).
- Draw-to-sign / uploaded-PDF signature (using typed e-consent).
- Payment/deposit collection at check-in.
- OCR / passport auto-parsing.
- Editing check-in after the arrival day (locked by design).

## Open risks / notes

- **Passport PII is sensitive.** Private-proxy access + audit logging +
  owner/manager-only visibility mitigate exposure; retention/deletion policy
  (e.g. purge scans N days after checkout) is worth a follow-up but not in v1.
- **R2 private access** assumes the bucket can serve signed GETs; the existing
  SigV4 code already signs PUT/DELETE, so GET signing is a small addition. If R2
  is not configured, the local-filesystem fallback stores under a non-web-served
  path and the proxy reads from disk.
