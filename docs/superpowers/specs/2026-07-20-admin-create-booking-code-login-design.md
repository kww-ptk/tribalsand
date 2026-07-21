# Admin: Create Booking + Code-Only Login — Design

**Date:** 2026-07-20
**Status:** Approved design, pending implementation plan

## Summary

Two related changes that let staff onboard a guest into the portal/app directly:

1. **Admin creates a booking** from scratch (no enquiry required) — a "+ New Booking" action on
   the Holds screen that creates a **confirmed** hold, generates the guest's access code, and shows
   the code + one-tap portal link to hand over.
2. **Login becomes code-only** — the guest gets in via the existing one-tap magic link, or by typing
   just their **code** (no email). New codes are lengthened to 8 characters to compensate.

Together: admin makes a booking → gets a code + link → gives it to the guest → guest logs into the
app with it. The booking *is* the guest's "account" (no passwords, per the guest-app direction).

## Goals

- Staff can create a booking (phone/walk-in/existing guest) without a public enquiry.
- Every booking yields a code + one-tap link the guest logs in with.
- Guest login is as simple as "here's your code / here's your link."

## Non-Goals (v1)

- No phone or guest-count stored on the booking (`holds` has no such columns).
- No availability check on admin create — force-create (staff manage overlaps), consistent with the convert flow.
- No auto-email (email/Resend not connected) — staff copy the code/link and share manually.
- No persistent "stay logged in" session yet — that's Phase 0 of the guest app; this feature just gets them *in* for the visit.
- No editing an existing booking's guest/dates from the new form.

## Decisions (2026-07-20)

- Admin-created bookings start **confirmed** (dates booked, no 24h expiry).
- Entry point: a **"+ New Booking" button on `admin/holds.php`** → a dedicated create page.
- Login is **code-only**: one-tap magic link (primary, already built) + manual code entry (no email). New codes are **8 chars**.

## Part A — Admin creates the booking

### Helper change: `create_hold_with_block()` (`includes/db.php`)
Extend the existing function (used by the public booking widget and the convert flow) — backward-compatible:
```php
function create_hold_with_block(
    int $unit_id, ?int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email,
    string $status = 'pending'          // NEW, defaults preserve current behavior
): int
```
- `submission_id` widened to `?int` so an admin booking can pass `null` (schema already allows null).
- When `$status === 'confirmed'`: insert the hold with `status='confirmed'`, `confirmed_at=NOW()`, and the availability block as `block_type='booked'`. Otherwise unchanged (pending, `block_type='hold'`, 24h expiry).
- Existing callers (`api/submit-enquiry.php`, `admin/submission-view.php` convert) pass neither new value → identical behavior.

### New page: `admin/hold-new.php`
Admin-auth + CSRF, mirroring `admin/submission-view.php`'s convert handler.
- **GET:** renders a form — Room/Unit `<select>` (via `fetch_room_unit_options()`), Check-in, Check-out, Guest name, Guest email. (Name + email only — what a hold stores and what login needs.)
- **POST (`action=create`):** `verify_csrf()`; harden inputs (`is_scalar` `$str()`); validate: active unit exists, real calendar dates (`checkdate`), `check_in < check_out`, non-empty name, valid email. On failure → flash error, redirect back to the form. On success → `create_hold_with_block($unit_id, null, $ci, $co, $name, $email, 'confirmed')`, `audit_log('hold.create_manual', 'hold', $hold_id, …)`, flash **"Booking #N created for {name} — code {access_code}."**, redirect to `/admin/holds.php`.

### Button: `admin/holds.php`
A "+ New Booking" link to `admin/hold-new.php`, near the page header/KPIs. The new booking then
appears in the holds list with its **code + Copy portal link** (already built).

## Part B — Code-only login

### New resolver: `resolve_booking_by_code_only()` (`includes/booking.php`)
Replace the current `resolve_booking_by_code(string $code, string $email)` (its only caller is
`booking.php`) with:
```php
function resolve_booking_by_code_only(string $code): array|false {
    $code = strtoupper(trim($code));
    if ($code === '') return false;
    // same SELECT (hold + unit/room/venue names) WHERE h.access_code = :code
}
```

### `booking.php` lookup
- The POST `do=lookup` handler calls `resolve_booking_by_code_only($_POST['code'])` (drop the email arg).
- The lookup **form** drops the email field — code input only.
- Error copy: "We couldn't find a booking with that code." (drop "and email").
- Keep the existing bot check (`verify_captcha`) and the session rate-limit (≥8/10min) as-is.
- The one-tap magic link (`?ref=…`, from `make_manage_url()`) is unchanged — it remains the primary, strongest login path.

### Code length
`generate_access_code(int $len = 6)` → default **8**. New holds get 8-char codes (~31^8 ≈ 8.5×10¹¹ combinations); the `access_code` column is `VARCHAR(12)`, so it fits. Existing 6-char codes remain valid.

### Security notes
- Code-only means the code is the sole secret for manual entry (the user accepted this: "anyone with the code gets in"). 8-char random codes make guessing infeasible; the real exposure is a *shared/forwarded* code, which is acceptable for this use.
- This is a **site-wide** login change affecting all existing bookings (their 6-char codes still work, slightly weaker than 8). Flagged and accepted.
- The magic link remains an unforgeable HMAC — the recommended thing to share.

## Files

| File | Change |
|------|--------|
| `includes/db.php` | `create_hold_with_block()` nullable `submission_id` + `$status`; `generate_access_code()` default 6→8 |
| `includes/booking.php` | Replace `resolve_booking_by_code()` with `resolve_booking_by_code_only()` |
| `booking.php` | Code-only lookup (drop email field + arg + copy) |
| `admin/hold-new.php` | New — create-booking form + `action=create` handler |
| `admin/holds.php` | "+ New Booking" button |

No DB migration.

## Testing

- `create_hold_with_block(..., 'confirmed')` → hold `status=confirmed`, `confirmed_at` set, `submission_id` NULL, block `block_type='booked'`, 8-char `access_code`. Existing pending path (no status arg) unchanged.
- `generate_access_code()` returns 8 chars, unambiguous alphabet.
- `resolve_booking_by_code_only(code)` resolves a real booking; wrong/empty code → false.
- `booking.php`: code-only manual entry logs in; magic link still logs in; wrong code → generic error; rate-limit + captcha still enforced.
- `admin/hold-new.php`: auth-gated (302 unauthenticated); valid submit creates a confirmed booking + redirects with the code in the flash; invalid input (bad date, email, inactive unit) rejected with no booking created.
- Regression: public booking widget and convert-from-enquiry still create pending holds correctly.
