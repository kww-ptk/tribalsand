# Guest Booking Management ("Manage My Booking") — Design

**Date:** 2026-07-19
**Status:** Approved design, pending implementation plan

## Summary

Give guests who make an availability booking (a `holds` row) a self-service page to
**see their booking status** and **manage their trip** — request date/detail changes and
add extras (tours, airport transfers, itinerary notes). Access is via a passwordless
magic link. All guest actions are **request-based**: nothing auto-mutates the booking and
there is no online payment. Admin confirms/declines requests through the existing admin UI.

This is consistent with the site's core rule: bookings are 24h *holds* ("Request to Book"),
never instant bookings — avoiding chargeback exposure.

## Goals

- A guest with a booking can view live status, dates, and (for pending holds) a countdown to expiry.
- A guest can request a change to dates / guest count (goes to admin, not applied automatically).
- A guest can add tours, transfers, and itinerary requests to their booking (request-based).
- Admin sees all guest requests against a booking and can confirm/decline them.

## Non-Goals (v1)

- No online payment / checkout — all add-ons are request-a-quote.
- No guest accounts or password login — access is by magic link or code + email only.
- No self-service mutation of the hold (dates/guests) — requests go to admin.
- No manage page for plain enquiry or tour-only leads — **availability holds only**.
- No admin CRUD for a transfers catalog — transfers are a fixed option list.
- No "Cancel my booking" self-service button — cancellation is requested via the change form.
- No guest confirmation email when an admin confirms an add-on (may add later).

## Access & Security

Two ways in, both landing on the same manage page:

### 1. Magic link (primary) — stateless HMAC token
Reuse the existing `BOOKING_TOKEN_SECRET` HMAC pattern already used for the one-click email
confirm/decline buttons (see `includes/mail.php`).

- Token = `HMAC_SHA256(hold_id . guest_email, BOOKING_TOKEN_SECRET)`, base64url-encoded.
- URL shape: `https://tribalsand.com/booking?ref=<hold_id>&t=<token>`.
- Nothing to expire-manage, nothing to leak.
- Invalid/tampered/missing token → generic "booking not found" (no enumeration).
- If `BOOKING_TOKEN_SECRET` is unset, the link is not emitted and endpoints reject
  (fail-closed, matching the Turnstile convention).

### 2. Typed code (fallback) — random stored code + email
For guests who lost the email link. Each hold gets a short random **access code**
(e.g. `K7QM2P`) stored on the `holds` row. A lookup page (`booking.php` with no valid token)
shows a form: **access code + email**.

- On submit: look up the hold by `access_code` AND matching `guest_email` (case-insensitive).
- On match, render the same manage page (internally mint the HMAC token so the rest of the
  flow — add-on/change POSTs — is identical to the magic-link path).
- No match → generic "we couldn't find a booking with that code and email."
- Turnstile-gated and rate-limited by `client_ip()` to prevent brute-forcing the code.
- Code is shown in the guest acknowledgement email and on the manage page.
- Code format: 6 chars, uppercase, unambiguous alphabet (no `0/O`, `1/I`), generated at hold creation.

All state-changing POSTs (add-on, change request) are additionally protected by:
- Token gate (must match the booking).
- Cloudflare Turnstile (`verify_captcha()`), same as other API endpoints.
- Rate limiting by `client_ip()` and email (mirror `api/submit-enquiry.php`: max 5 / 10 min).

## Data Model

### New column: `holds.access_code`
```sql
ALTER TABLE holds ADD COLUMN IF NOT EXISTS access_code VARCHAR(12);
CREATE UNIQUE INDEX IF NOT EXISTS idx_holds_access_code ON holds(access_code)
    WHERE access_code IS NOT NULL;
-- Backfill existing holds with a generated code (one-time UPDATE in the migration).
```
The code is generated in PHP at hold creation (in `create_hold_with_block()` /
`api/submit-enquiry.php`) from a 6-char unambiguous uppercase alphabet, retried on the rare
unique-index collision. Existing rows are backfilled by the migration.

### New table: `booking_addons`
```sql
CREATE TABLE IF NOT EXISTS booking_addons (
    id         SERIAL PRIMARY KEY,
    hold_id    INT          NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    kind       VARCHAR(20)  NOT NULL CHECK (kind IN ('tour','transfer','itinerary','other')),
    tour_id    INT          REFERENCES tours(id) ON DELETE SET NULL, -- set when kind='tour'
    details    TEXT         NOT NULL DEFAULT '',  -- transfer route, itinerary notes, etc.
    status     VARCHAR(20)  NOT NULL DEFAULT 'requested'
                            CHECK (status IN ('requested','confirmed','declined','cancelled')),
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_booking_addons_hold ON booking_addons(hold_id);
```

### New table: `booking_change_requests`
```sql
CREATE TABLE IF NOT EXISTS booking_change_requests (
    id                  SERIAL PRIMARY KEY,
    hold_id             INT          NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    requested_check_in  DATE,
    requested_check_out DATE,
    requested_guests    INT,
    note                TEXT         NOT NULL DEFAULT '',
    status              VARCHAR(20)  NOT NULL DEFAULT 'requested'
                                     CHECK (status IN ('requested','handled','declined')),
    created_at          TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_booking_changes_hold ON booking_change_requests(hold_id);
```

Both migrations follow the existing idempotent `CREATE TABLE IF NOT EXISTS` convention and go in
`db/migrations/` (plus appended to `db/run-migrations.sql` for the Neon console).

### Add-on catalog sources
- **Tours** — live query of published `tours`, grouped by `category` (Classic / Custom / Excursion).
  Tours have no price → reinforces request-a-quote.
- **Transfers** — fixed option set in code: Airport → Property, Property → Airport,
  Inter-property, Custom — plus free-text detail.
- **Itinerary / other** — free-text request.

## Guest Page — `booking.php`

Server-rendered from the hold using existing `includes/head.php`, `header.php`, `footer.php`, `css/main.css`.

**Lookup state:** if `booking.php` is opened without a valid token (no link, or tampered), it
renders the **code + email lookup form** (Turnstile-gated, rate-limited) instead of the booking.
A successful lookup renders the manage page below; a failure re-shows the form with a generic error.

1. **Status banner** — colour-coded by `holds.status`:
   - `pending` → "Reservation held — awaiting confirmation" + live 24h countdown to `expires_at`
     (reuse countdown logic from `js/booking-widget.js`).
   - `confirmed` → "Booking confirmed."
   - `expired` → "This hold expired" + CTA to re-enquire.
   - `cancelled` → "This booking was cancelled."
2. **Booking summary** — room/venue name, check-in/out, nights, guest name.
3. **Request a change** — form (new check-in, check-out, guest count, note) → creates a
   `booking_change_requests` row, emails admin, shows confirmation. Never mutates the hold.
4. **Add to your trip** — tour cards + transfer options + itinerary free-text. Each "Add"
   creates a `booking_addons` row (`status='requested'`) and emails admin. Existing add-ons
   are listed with their current status.

Expired/cancelled bookings show status + summary but hide the change/add-on forms.

## API Endpoints (in `api/`, same conventions as `submit-enquiry.php`)

- `api/booking-addon.php` — POST: token check → Turnstile → rate-limit → insert `booking_addons` → admin email.
- `api/booking-change.php` — POST: token check → Turnstile → rate-limit → insert `booking_change_requests` → admin email.

Booking viewing is handled inline in `booking.php` (token validated there); no separate view endpoint.

## Admin

Extend the existing hold detail view (`admin/holds.php`, and/or `admin/submission-view.php`) to show,
per booking:
- **Add-on requests** — list with Confirm / Decline actions (updates `booking_addons.status`).
- **Change requests** — list with Mark handled / Decline (updates `booking_change_requests.status`).

Admin edits actual hold dates through the existing hold tools — this feature never bypasses that logic.
A small route (e.g. extend `admin/hold-action.php`) handles the status-update POSTs, protected by the
existing admin auth/session.

## Emails (via `includes/mail.php` / Resend)

- **Guest acknowledgement** (existing hold email) — add a **"Manage your booking"** magic-link
  button **and** show the booking **access code** (so a guest who loses the link can still look it up).
- **New admin notification: add-on request** — links to admin hold view.
- **New admin notification: change request** — links to admin hold view.

## Testing

- Token: valid token loads booking; tampered/missing token → lookup form; wrong email in token → rejected.
- Code lookup: correct code + email loads booking; wrong code, wrong email, or mismatched pair → generic
  error; lookup is Turnstile-gated and rate-limited (brute-force protection).
- Access code is generated on hold creation, is unique, and uses the unambiguous alphabet.
- Fail-closed: with `BOOKING_TOKEN_SECRET` unset, no link emitted and endpoints reject.
- Add-on POST creates a row, is rate-limited, Turnstile-gated, and only against the matching hold.
- Change request POST creates a row and does **not** modify the hold.
- Status banner renders correctly for each of pending / confirmed / expired / cancelled.
- Admin confirm/decline updates the correct row's status.
- Expired/cancelled bookings hide the action forms.

## Files (anticipated)

| File | Change |
|------|--------|
| `booking.php` | New — guest manage page; token-gated render, else code+email lookup form |
| `api/submit-enquiry.php` | Generate `access_code` at hold creation |
| `api/booking-addon.php` | New — add-on request endpoint |
| `api/booking-change.php` | New — change request endpoint |
| `db/migrations/add_booking_management.sql` | New — `holds.access_code` column + backfill + the two tables |
| `db/run-migrations.sql` | Append the two tables |
| `includes/mail.php` | Add magic-link button to guest email; add 2 admin notification emails; add token helper |
| `admin/holds.php` (and/or `admin/submission-view.php`) | Show + action guest requests |
| `admin/hold-action.php` | Handle add-on / change-request status updates |
