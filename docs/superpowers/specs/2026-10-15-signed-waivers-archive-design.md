# Signed waivers archive — design (15 Oct 2026)

## Why
The accounting team must keep, for legal compliance, every signed waiver together with
the scan of the passport / Kenyan ID it was signed with — findable by date and property,
and stored month by month outside the system.

## Decisions (owner)
- **Who:** the owner only for now (`require_owner()`). An "Accounts" role can come later.
- **What:** guest check-in waivers **and** visitor waivers (`/register`).
- **Monthly download:** one ZIP per month.
- **The record shows all the document details** (no masking) and the scan.

## 1. The signed record

### Full document details
`record.php` (guest) and `includes/visitor-record.php` (visitor) stop masking the number.
The Signatory block shows, from the frozen snapshots only: document type, **full number**,
nationality, expiry (guest: `waiver_passport_expiry_snapshot`; visitor:
`waiver_id_expiry_snapshot`; "—" when empty, e.g. an old Kenyan ID).

### The scan on the record
A "Document scan" section under the signature:
- **Owner view and the ZIP:** the image is shown (a PDF scan shows "PDF scan — see the
  file beside this record" in the ZIP, and an "Open scan" link on screen).
- **Guest links** (`?ref=` lead, `?g=` co-guest, `?vp=` visitor): full details, **no
  image** — a lead must never see another adult's passport photo. Managers / reception
  keep today's access to the record (`can_view_guest_docs()`) and see the image too,
  since they can already open the scan from the booking.

### The scan is frozen at signing (fixes a real gap)
Today a new photo taken after signing **deletes the old scan**, so the signed record
would point at a different picture. Migration `add_waiver_scan_snapshot.sql`:
- `checkin_guests.waiver_scan_snapshot TEXT`, `registered_visitors.waiver_photo_snapshot TEXT`.
- Set to the current file key at signing (`api/checkin-save.php`, visitor sign path).
- **A frozen scan is never deleted by a re-upload:** `api/checkin-upload.php` skips
  `storage_delete_private()` when the old key equals `waiver_scan_snapshot` (same for the
  visitor photo replace).
- When a signature is voided (`checkin_void_signature_if_identity_changed()` / visitor
  identity change) the snapshot is cleared; if it differs from the current file, that
  file is deleted after commit (it belongs to no record any more).
- **Visitor removal** (`visitor_remove()`) still deletes the photo (privacy) and clears the
  snapshot; the record then says "ID photo removed on <date>".
- Records signed before the migration fall back to the current file key (as the identity
  fallback already does), labelled "scan on file now".
- Guard `waiver_scan_snapshot_supported()` (information_schema lookup).

## 2. Page — Team → Signed waivers
`admin/waivers.php`, `require_owner()`, in a nav tab (owner-only, added to
`ACCESS_OWNER_ONLY`). Logic `includes/waiver-archive.php`.
- Filters: **month** (default this month), **property** (all / one), **Guests | Visitors |
  Both**, name search. Month = `waiver_signed_at` in Nairobi time.
- Rows, newest first: date signed · name · Guest/Visitor · property · booking (TS-…) or
  place · document type + number · record reference · **View** (the record) · **Scan**
  (the original file).
- **Property of a record:** guest = the booking's property (`venue_id` of the hold's
  room). Visitor = the property of their first visit on/after signing
  (`visitor_visits` → `visitor_places.venue_id`), else "No visit yet".
- Visitors re-sign when the terms change, which overwrites their row: the page shows the
  current signature; the earlier one lives in the month ZIP taken at the time (one more
  reason for the monthly download) and in the audit chain.

## 3. Download month (ZIP)
`admin/waivers.php?download=1&month=YYYY-MM&venue=&kind=` — same filters, owner only.
- `waivers-YYYY-MM[-<property>].zip`:
  - `<Property>/` for guests, `Visitors/<Property>/` for visitors.
  - Per person: `YYYY-MM-DD <Name> <reference>.html` — the full record, self-contained
    (scan embedded as a data URI when it is an image) — and the original scan
    `… .jpg|png|pdf`, same base name.
  - `index.csv`: date signed, name, guest/visitor, property, booking/place, document type,
    number, nationality, expiry, reference, waiver version, scan file name.
- Built with `ZipArchive` into a temp file and streamed; the record HTML comes from ONE
  renderer shared with `record.php` / `visitor-record.php` (no second template).
- Dockerfile: `apt-get install libzip-dev` + `docker-php-ext-install zip`.
  Without the extension the button is hidden and the page says so.
- Each download writes `audit_log('waivers.download', …)` with month, filters, count.

## 4. Testing
`tests/waiver_archive_logic.php`:
- record shows full number + expiry; guest view has no image, owner view has it;
- signing freezes the scan; a retake after signing keeps the frozen file;
  voiding clears it; visitor removal clears it;
- month / property / kind filters (Nairobi month edges);
- ZIP: folders, file names, index.csv rows, embedded image (when ZipArchive exists);
- non-owner refused (page and download).

## Out of scope
Accounts role, automatic monthly e-mailing of the ZIP, server-side PDF generation.
