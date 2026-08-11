# Check-in — Signature & Legal Consent

- **Date:** 2026-08-10
- **Status:** Approved design, ready for implementation plan
- **Sub-project:** 1 of 4 in the check-in improvements epic (see also: guest management & fill-then-sign, shared booking portal, multi-unit bookings — not covered here)
- **Depends on:** the merged Pre-Check-in (PR #49) and multi-guest (PR #50) work

## Problem

The waiver step today records only a typed name + timestamp + IP + a 12-char waiver-version hash on each `checkin_guests` row. For the waiver to be legally defensible the owner wants:

1. A **hand-drawn signature** (finger/stylus on screen), not just a typed name.
2. A **downloadable signed-consent record** per adult guest, carrying the signature plus timestamp, IP, device, and the exact terms agreed — so it can stand as legal evidence.

There is also a latent gap: only the waiver-version *hash* is stored, not the terms text. If the owner later edits the waiver wording, the exact terms a guest agreed to can no longer be reproduced.

## Goal & scope

**In scope**
- Capture a drawn signature per adult guest, alongside the existing typed name + "I agree" tick.
- Freeze an immutable legal snapshot at signing time (terms text, signature, name, timestamp, IP, user-agent, method).
- Generate a per-guest downloadable consent document (print-styled HTML → browser Save-as-PDF).
- Enforce signing integrity: staff/lead may pre-fill any field *except* the signature; the signature must be drawn in the signer's own session.

**Out of scope (later sub-projects)**
- Admin filling arbitrary guest details / adding kids & adults from admin (sub-project 2).
- Shared booking portal, per-name requests & bill (sub-project 3).
- Multi-unit bookings / per-unit guest assignment (sub-project 4).
- A true server-generated PDF file or emailed consent copy (deferred; see Decisions).

## Decisions (with rationale)

1. **Delivery = print-styled HTML page, saved to PDF via the browser.** Matches the existing `admin/bill-print.php` pattern, adds zero dependencies (the stack is vanilla PHP, no framework, no npm, no build), and produces a legally-equivalent document. A true one-click/emailable PDF would require vendoring a PHP PDF library; deferred until there's a concrete need.
2. **Signature stored in the DB as a PNG data-URL (TEXT column), not object storage.** It is tiny (~10–30 KB), it is legal evidence that must never disappear, and the private passport bucket (`R2_CHECKIN_BUCKET`) is not configured on production yet — a DB column is the durable, dependency-free choice and keeps the consent record self-contained.
3. **Freeze a terms snapshot at signing.** Store the exact waiver text on the guest row at sign time so each consent document reproduces the terms *that guest* agreed to, independent of later edits.
4. **Signing integrity.** The drawn signature is the one field staff cannot produce on a guest's behalf. Staff/lead pre-fill passport fields; the signature is drawn in the signer's own session — on the guest's own phone (their personal `?g=` link) or on a reception tablet handed to a present guest (which is just their `?g=` link opened on that device). The evidence block records which.

## Data model

New migration `db/migrations/add_checkin_signature.sql`, idempotent (`ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS ...`). New columns on `checkin_guests`:

| Column | Type | Meaning |
|---|---|---|
| `waiver_signature` | `TEXT` | Drawn signature as a `data:image/png;base64,...` URL |
| `waiver_terms_snapshot` | `TEXT` | Exact waiver text shown at signing (frozen) |
| `waiver_signed_user_agent` | `TEXT` | Browser/device UA string at signing |
| `waiver_signed_method` | `TEXT` | `own_link` / `reception` — how the signing surface was reached |

Reused as-is: `waiver_signed_name`, `waiver_signed_at`, `waiver_signed_ip`, `waiver_version`.

**Migration safety:** a `checkin_signature_supported()` guard (probes `waiver_signature`) so pre-migration deploys still render; all reads of the new columns are guard- or null-safe. The feature is not live, so there is no production signed data to migrate; signature-less test rows simply read as "needs signing".

## Signing flow

- **Client:** new `js/signature-pad.js` — a small vanilla `<canvas>` pad using pointer + touch events, retina-scaled, with a Clear control. On form submit it writes `canvas.toDataURL('image/png')` into a hidden `waiver_signature` field. Wired into the lead's own card (`includes/app/checkin.php`) and the co-guest page (`includes/app/checkin-guest.php`); `js/checkin-wizard.js` includes the field in its saves.
- **Server:** `api/checkin-save.php` — when `waiver_agree` + `waiver_signed_name` + `waiver_signature` are present, validate the signature is a PNG data-URL under a size cap (~200 KB), then stamp in one update: signature, name, `now()`, `client_ip()`, user-agent, method, waiver version, and the **terms snapshot** (current `checkin_waiver_text`). Method is `reception` when the signing surface was reached via the admin "Sign on this device" affordance (which passes a marker through to the page), otherwise `own_link` (the signer used their own hold `ref` or personal `g` link).
- **Completion:** `checkin_guest_waiver_signed()` is extended to require a non-empty `waiver_signature` in addition to name + timestamp. Because `checkin_recompute_completion()` already keys off this helper, the stricter definition flows through to booking completion automatically — no change to the recompute logic itself.
- **Integrity enforcement:** waiver-signing is *removed* from the "Fill in for them" inline card in the lead's party roster (`includes/app/checkin.php`). That card keeps passport fields only; signing is steered to the guest's own link (matching the existing "for a personal signature, use Send them a link" hint).

## Consent document

New `admin/consent-print.php?hold=<id>&guest=<id>`:
- **Auth:** `require_login()` + `can_view_guest_docs($holdId)` (owner, or the venue's manager — same gate as passport scans). `audit_log('checkin.consent_view', 'hold', $holdId, 'guest '.$guestId)`.
- **Render:** print-styled HTML matching the approved mockup — letterhead, booking/stay, signatory (name, nationality, passport reference), the frozen terms + version hash, the signature (inline `<img>` from the data-URL) + typed name + tick, and the evidence block (timestamp, IP, device, method). `@media print` CSS for clean Save-as-PDF. Renders entirely from the frozen snapshot.
- **Access points:**
  - Admin Check-in tab (`admin/_ws_checkin.php`): a **"Download consent →"** link per signed adult (next to the existing scan link), plus a **"Sign on this device"** link per unsigned adult that opens that guest's signing surface for the reception-tablet flow.
  - Guest self-service: a **"Download my signed waiver"** link on the check-in done screen, authed by the guest's existing `ref` / `g` token (their own record only).

## Testing

Extend `tests/checkin_logic.php` (keep the pure-function-first structure; `php tests/checkin_logic.php` stays green, whole suite `php tests/*_logic.php`):
- `checkin_guest_waiver_signed()` → false when name + timestamp present but signature absent; true with a signature.
- Pure helper for signature data-URL validation (accepts `data:image/png;base64,…` within cap; rejects other types / oversize / garbage).
- Terms-snapshot capture and method-derivation helpers, where extractable as pure functions.

## Files touched

- `db/migrations/add_checkin_signature.sql` (new)
- `includes/checkin.php` — `waiver_signed` definition, `checkin_signature_supported()`, snapshot + validation + method helpers
- `api/checkin-save.php` — capture signature, snapshot, UA, method
- `includes/app/checkin.php` — lead card signature pad; remove waiver from the additional-adult inline card
- `includes/app/checkin-guest.php` — co-guest signature pad
- `js/signature-pad.js` (new)
- `js/checkin-wizard.js` — wire the pad into saves
- `admin/consent-print.php` (new)
- `admin/_ws_checkin.php` — "Download consent" + "Sign on this device" links
- `css/portal-app.css` — signature-pad styles (and print CSS as needed)
- `tests/checkin_logic.php` — assertions

## Conventions

Pre-migration guards via `checkin_supported()` / new `checkin_signature_supported()`; CSRF via `verify_csrf()`; IP via `client_ip()`; idempotent migration; admin gating via `is_owner()` / `is_manager()` / `staff_can_hold()` / `can_view_guest_docs()`.

## Go-live dependency (tracked separately)

Production is still stuck on the PR #49-era build (a Render dashboard issue, not the code) and the live DB is unmigrated. This feature — like v1 — cannot be verified in production until that pipeline is unblocked and `add_checkin.sql`, `add_multiguest_checkin.sql`, and this `add_checkin_signature.sql` are run on the live DB via `/admin/migrate.php`. Design and implementation do not depend on it.
