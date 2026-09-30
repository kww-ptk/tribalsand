# Tribal Sand — Admin UX Tasks Plan

**Prepared:** 2026-09-02 · Hand-off doc — execute in a fresh chat, one phase at a time.
**Tasks:** (0) Email situation, (1) iOS mobile form zoom, (2) Reusable image picker, (3) SEO image filenames, (4) Enquiry status + reply-from-dashboard.

> **Golden rule for the executor:** the reusable **media picker** (`includes/admin-media-picker.php` + `includes/media.php` + `admin/media.php`) already exists and works — do **not** rebuild it. The **enquiry reply thread** is a direct port from `D:\7IslandWatamu` (working reference). Everything below is additive + pre-migration-safe.

---

## 0. EMAIL — current state, why it took longer, and how to make it better

### Where it stands (this is NOT a code problem)
The entire email pipeline is **built and deployed**. Per the go-live work (task-def **rev 15**):

- SES domain identity `tribalsand.com` verified in **eu-west-1**, **Easy DKIM = SUCCESS**, `VerifiedForSending = True`.
- App configured: `MAIL_DRIVER=smtp`, `SMTP_HOST=email-smtp.eu-west-1.amazonaws.com`, port 587 TLS, `MAIL_FROM=noreply@tribalsand.com`, **no** `RESEND_API_KEY` (SES-only decision).
- `includes/mail.php` `send_smtp()` is a real, working SES SMTP client. SMTP AUTH was verified OK via `smtplib`.

**The one thing gating live email = AWS SES *production access* approval.** The request was submitted **2026-09-01**; AWS review takes **~24 h**. Until it's granted, SES is in **sandbox** and will *only* deliver to **verified** addresses. That is the whole reason nothing seems to send to real guests yet — not a bug, an AWS review queue.

### ✅ FIRST STEP before any coding — confirm whether it's already live
Since ~24 h has passed, it may already be approved. Check, in this order:

1. **AWS Console → SES (eu-west-1) → Account dashboard.** If it says *"Your account is out of the sandbox / Production access: Enabled"* → **email is fully live**, guests receive mail automatically. Done.
2. If still sandbox: **AWS Console → SES → Verified identities → Create identity → Email address**, verify your own inbox, then from the site trigger a **password reset** (`/admin/forgot-password.php`) or submit the contact form using that verified address. If it arrives → pipeline is proven; you're just waiting on the sandbox lift.
3. Check the SES **Account-level suppression list** and CloudWatch sending metrics if a specific message doesn't arrive.

> Do this check first and tell me the sandbox status — it decides whether task 4's "reply-from-dashboard" email actually leaves the building on day one or waits for approval. **The dashboard reply feature is still worth building now**; it degrades gracefully (saves the thread entry, reports "email not sent" if SES can't yet deliver).

### How to make deliverability better (avoid spam) — which address to use
DKIM alone gets mail *out*; these get it into the **inbox**:

| Lever | Status now | Action |
|---|---|---|
| **SPF** (apex) | apex SPF = `include:spf.protection.outlook.com` + secureserver — **does NOT authorize SES** | Add a **SES custom MAIL FROM subdomain** (e.g. `bounce.tribalsand.com`) so the return-path SPF aligns. (DKIM already aligns the `From:`, but MAIL FROM alignment hardens DMARC.) |
| **DKIM** | ✅ SUCCESS on `tribalsand.com` | none |
| **DMARC** (apex) | ❌ only `_dmarc.mail.` exists (Mailgun/GHL). **No apex `_dmarc`.** | Add `_dmarc.tribalsand.com` TXT: `v=DMARC1; p=none; rua=mailto:dmarc@tribalsand.com` to start (monitor), tighten to `p=quarantine` later. |
| **From address** | `noreply@tribalsand.com` | Fine with DKIM. **Better:** send as `reservations@tribalsand.com` (a real, monitored M365 mailbox) or keep `noreply@` **From** but set **`Reply-To: reservations@tribalsand.com`** so replies reach a human. Avoid brand-new lookalike subdomains. |
| **Reputation warm-up** | new SES identity | Low volume first days; keep bounce/complaint rates low (SES auto-pauses on high complaints). |

**Recommendation (one line):** keep `MAIL_FROM=noreply@tribalsand.com` + DKIM (done), **add the apex DMARC record** and a **SES custom MAIL FROM subdomain**, and set **`Reply-To: reservations@tribalsand.com`** on guest-facing mail. Those three changes are the difference between "lands in spam" and "lands in inbox." (These are DNS + SES-console changes, not app code — a separate small task from the four below.)

---

## 1. iOS mobile form zoom — inputs < 16px auto-zoom on focus

### Root cause
iOS Safari zooms the viewport whenever a focused `<input>/<select>/<textarea>` has a **font-size below 16px**. The viewport meta is correct (`width=device-width,initial-scale=1.0` — do **NOT** add `maximum-scale=1`/`user-scalable=no`; that's an accessibility anti-pattern and Apple ignores it anyway on modern iOS). Fix by making the **text 16px on mobile**, exactly as the user asked.

### Where it's below 16px (verified)
- **Admin** (`admin/assets/admin.css`): `.inp`/`.field input` (13.5px), `.filters input`/`.filters select` (13px), `.cell-select` (12.5px), plus inline-styled admin inputs with no font-size that inherit `body` **14px** — e.g. the `admin/submission-view.php` "Convert to Hold" name/email fields (`style="padding:9px…"`), status/room selects.
- **Guest** (`css/main.css`): `.savail-field input` = **15px** (availability search). `.contact-input/.contact-select/.contact-textarea` are already `1rem`=16px ✅. Booking enquiry `.booking-field` inputs inherit body 1rem ✅.

### Approach (low-risk, preserves desktop density)
Add ONE mobile-only media query to each stylesheet so desktop admin stays compact but phones get 16px:

**`admin/assets/admin.css`** (append):
```css
/* iOS Safari auto-zooms any focused field under 16px. Bump to 16px on mobile
   only, so the desktop admin keeps its compact 13–14px density. */
@media (max-width: 768px) {
  .inp, .field input, .field select, .field textarea,
  .filters input, .filters select, .cell-select,
  input:not([type=checkbox]):not([type=radio]):not([type=range]),
  select, textarea { font-size: 16px; }
}
```
**`css/main.css`** (append, or fold into existing mobile block):
```css
@media (max-width: 768px) {
  input:not([type=checkbox]):not([type=radio]):not([type=range]),
  select, textarea { font-size: 16px; }
}
.savail-field input { font-size: 16px; } /* was 15px — safe on desktop too */
```
> The bare-element selector inside the media query also catches inline-styled inputs (they don't set `font-size` inline), so the Convert-to-Hold fields get fixed with no per-field edits.

### Test (do this — user asked to verify before shipping)
- Browser pane → `resize_window` preset **mobile** (375px) → open `/contact`, the room booking widget, `/admin/submission-view.php?id=<any>` → focus each field → confirm **no zoom**. Use `javascript_tool` to read `getComputedStyle(el).fontSize` = `16px` on mobile.
- Desktop (preset **desktop**): confirm admin tables/filters still look compact (unchanged).

**Files:** `admin/assets/admin.css`, `css/main.css`. **Risk:** cosmetic only. No migration.

---

## 2. Reusable image picker — "pick existing OR upload new" everywhere

### Good news: the reusable component already exists
The Page Content editor's picker is already a **drop-in, page-agnostic widget**:
- `includes/media.php` — `media_library_items()` UNIONs the `media` table with **every existing gallery** (`venue_images`, `room_images`, `tour_images`, `property_images`), excluding private `checkin/` scans. `media_url()`, `media_record()`.
- `includes/admin-media-picker.php` — `media_picker_field($name,$value,$label,$hint,$default)` renders one slot + hidden input; `media_picker_modal()` renders the shared modal+JS once. Uploads POST to `admin/media.php?ajax=1` → JSON `{ok,key,url}`, never leaves the page.
- `admin/media.php` — doubles as the AJAX upload endpoint + the standalone Media Library page.

**So this task is NOT "build a picker" — it's "retrofit the existing admin image inputs to use it."**

### Where to retrofit (each currently a bespoke `<input type=file>` → GD resize → `storage_put`)
| File | Field(s) today | Change |
|---|---|---|
| `admin/venue-edit.php` | Gallery upload (`filename` → `venue_images`) | Add `media_picker_field` for "pick existing"; keep bulk-upload for galleries |
| `admin/room-edit.php` | Room gallery | same |
| `admin/property-edit.php` | Property images | same |
| `admin/tour-edit.php` | Tour images | same |
| `admin/nav-menu.php` | Link thumbnail (`image_key`) | swap the file input for `media_picker_field('image_key', …)` |
| `admin/offer-edit.php` | Offer image | swap for `media_picker_field` |
| `admin/menu-edit.php` | (if any item image) | swap if present |

### Approach
1. For **single-image** fields (nav thumbnail, offer image, page-content slots): replace the bespoke `<input type=file>` block with:
   ```php
   require_once __DIR__ . '/../includes/admin-media-picker.php';
   media_picker_field('image_key', $currentKey, 'Thumbnail');
   // once near </form> / end of page:
   media_picker_modal();
   ```
   The host form already submits the hidden input's storage key — server-side, just save `$_POST['image_key']` as the key (no upload handling needed; the modal already uploaded via `admin/media.php`).
2. For **gallery** screens (venue/room/property/tour = many images): keep the existing multi-upload, but **add** a "Choose from library" affordance using the same modal, appending picked keys as new gallery rows. (Lower priority — do single-image fields first; they're the clean wins.)
3. **Backfill nothing** — `media_library_items()` already surfaces every gallery image live.

### Gotchas
- The modal reads the CSRF token from `input[name=csrf_token]` on the page — ensure each host form has `csrf_field()` (all admin forms do).
- `require_owner()` gates `admin/media.php` uploads. Manager-scoped pages (menus/reservations) currently can't upload through it — if a **manager** needs the picker, relax `admin/media.php` to `require_manager()` for the `ajax` upload branch (decide per screen; keep owner-only for the standalone library page).
- `media_supported()` is false until `add_media.sql` is applied → picker still **lists** gallery images, just can't record new library uploads. Make sure `add_media.sql` is applied to **prod RDS** (see checklist).

### Test
Open each retrofitted screen → "Choose image" shows the grid → pick one → hidden input gets the key → save → reload → correct image renders. Upload-new → appears as a `Library` tile → selectable.

**Files:** the admin edit pages above. **Migration:** `add_media.sql` must be on prod (task shares this with the picker being useful).

---

## 3. SEO image filenames — `TribalSand_website_official_…`

### Current state
Every upload path stores a **random** key: `bin2hex(random_bytes(10)) . '.jpg'` (in `admin/media.php`, `venue-edit`, `room-edit`, `property-edit`, `tour-edit`, `nav-menu`, `guest-board`; offers use `offer-<rand>.jpg`). `storage_put($local, $filename, …)` takes the filename verbatim. `media_record()` already stores the human `original_name` in the DB, but the **served URL** is the random key — bad for SEO/alt discovery.

### Approach — one shared helper, used by every upload path
Add to `includes/storage.php` (or `includes/media.php`):
```php
/**
 * SEO-friendly, collision-safe storage filename.
 * e.g. seo_filename('Maya Kobe Pool.JPG', 'villa') → "TribalSand_website_official_villa-maya-kobe-pool-9f3a2c.jpg"
 */
function seo_filename(string $originalName = '', string $context = '', string $ext = 'jpg'): string {
    $base = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    $slugCtx = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($context)), '-');
    $parts = array_filter(['TribalSand_website_official', $slugCtx, $base]);
    $stub  = implode('_', array_slice($parts, 0, 1)) . (count($parts) > 1 ? '_' . implode('-', array_slice($parts, 1)) : '');
    $stub  = substr($stub, 0, 90);                 // keep keys sane
    return $stub . '-' . bin2hex(random_bytes(4)) . '.' . $ext;   // 8-hex suffix = uniqueness
}
```
Then replace each `$filename = bin2hex(random_bytes(10)) . '.jpg';` with:
```php
$filename = seo_filename($file['name'] ?? '', 'venue');   // context per page: venue/room/property/tour/nav/offer/page
```
Pass a sensible `$context` per screen (venue slug, room name, "nav", "offer", etc. — whatever's on hand).

### Important constraints / gotchas
- **Keep the random suffix.** Two guests can upload `pool.jpg`; the 8-hex suffix prevents overwrites in S3.
- **Don't touch `checkin/` keys** — passport/deposit scans (`api/checkin-upload.php`, `admin/booking.php`) are private and their opaque names are a *feature*. Leave those `bin2hex` as-is.
- **Existing images keep their random keys** — this only affects *new* uploads. No rename/migration of stored objects (that would break every `venue_images.filename` in the DB and every live URL). SEO benefit accrues going forward. If a full historical rename is ever wanted, that's a separate, risky data+S3 migration — out of scope here.
- The rewrite helpers (`storage_url()`) don't care about the name, so nothing else changes.

### Test
Upload via Media Library and via a venue gallery → confirm the stored key is `TribalSand_website_official_…-<hex>.jpg` and the image serves 200 through CloudFront. Confirm `media_library_items()` still lists it.

**Files:** `includes/storage.php` (helper) + each admin upload page. **Risk:** low (new uploads only). No migration.

---

## 4. Enquiry status + reply-from-dashboard (port from 7IslandWatamu)

This is the "Lead Status & Conversation" card from the screenshot. **Working reference: `D:\7IslandWatamu`.** Tribal Sand already has the *internal-notes* half (`includes/submission-notes.php`, `admin/submission-view.php` notes card) but is **missing status** and **reply-to-guest email**. We upgrade the existing pieces rather than duplicating.

### What Tribal Sand has vs. needs
| Piece | TribalSand now | 7island (source) | Action |
|---|---|---|---|
| `submissions.status` column + pipeline | ❌ none | ✅ `add_submission_status.sql` + `includes/submission-status.php` | **Port both** |
| Status badge in list + filter | ❌ | ✅ | Add to `admin/submissions.php` |
| Internal notes thread | ✅ (`submission_notes`, body only) | ✅ (has **`kind`** note/reply) | **Add `kind` column** |
| Reply-to-guest (logs + optionally emails) | ❌ (only a `mailto:` link) | ✅ `send_admin_reply()` | Port |
| Subject ref tag for reply matching | ❌ | ✅ `make_submission_ref()`/`parse_submission_ref()` | Port (prefix `TSR-`/`TS-`) |
| Inbound IMAP pull (guest replies auto-threaded) | ❌ | ✅ | **Defer to fast-follow** (needs a mailbox + cron; M365 IMAP creds required) |

### Phase 4a — Status pipeline (ship alone, safe)
1. New migration `db/migrations/add_submission_status.sql` — copy 7island's verbatim (adds `status VARCHAR(20) DEFAULT 'received'` + CHECK + index).
2. New `includes/submission-status.php` — copy 7island's (`submission_statuses()`, `_label()`, `_badge()`, `_valid()`, `_default()`). Labels: Received / Answered / Option Sent / Waiting / To Follow Up / Booked / Not Interested / Dates Not Available.
3. `admin/submission-view.php`: add the **status `<select>` + "Update status"** form (POST `action=set_status`, `submission_status_valid()` guard). Put a status **badge** in the page `<h1>`.
4. `admin/submissions.php`: add a **status filter** dropdown (mirror the `type` filter) + a status **badge column**; add `status` to the WHERE builder + CSV.
   > Wrap every read in a `submission_status_supported()` guard (check the column exists via `information_schema`) so pre-migration deploys don't 500 — match the `submission_notes_supported()` pattern already in the repo.

### Phase 4b — Conversation thread with reply (the screenshot)
5. Migration `db/migrations/add_submission_notes_kind.sql` (Tribal Sand's table already exists, so **ALTER**, don't recreate):
   ```sql
   ALTER TABLE submission_notes
     ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NOT NULL DEFAULT 'note';
   ALTER TABLE submission_notes DROP CONSTRAINT IF EXISTS submission_notes_kind_check;
   ALTER TABLE submission_notes ADD CONSTRAINT submission_notes_kind_check
     CHECK (kind IN ('note','reply','guest_reply'));
   ALTER TABLE submission_notes
     ADD COLUMN IF NOT EXISTS author_name VARCHAR(255) NOT NULL DEFAULT '';
   ```
6. `includes/submission-notes.php`: extend `add_submission_note()` to accept `$kind` and `$authorName`; `fetch_submission_notes()` already returns rows — include `kind`.
7. `includes/booking.php`: add `make_submission_ref()` + `parse_submission_ref()` (port; use prefix **`TSR-`**, HMAC off `BOOKING_TOKEN_SECRET` which is already set on prod).
8. `includes/mail.php`: add `send_admin_reply(array $sub, string $message): array`. **Adapt the 7island version to Tribal Sand's mailer** — it must go through the existing dispatch (`send_smtp()`/SES), NOT 7island's Resend path. Branding: teal `#1E5C6B`/`#102F3A`, from `MAIL_FROM`, **`Reply-To: reservations@tribalsand.com`**, subject `Re: Your enquiry — Tribal Sand [TSR-<id>-<hash>]`. Returns `['ok'=>bool,'error'=>string]` so the UI can report "saved but not emailed."
9. `admin/submission-view.php`: replace the current "Internal Notes" card with the 7island **"Lead Status & Conversation"** card (thread render with per-`kind` colour/badge; the add-entry form with **radio Internal note / Reply sent to guest** + **"Also email this reply to <guest email>"** checkbox + the small JS that enables the checkbox only for replies). The `add_note` POST: if `kind=reply` && `send_email` → call `send_admin_reply()`, then log the row with `kind='reply'`; flash the email result. Fix all routes to Tribal Sand's `.php` URLs.
10. Remove/keep the top-right "Reply via Email" `mailto:` button (optional — the in-dashboard reply supersedes it).

> **SCOPE DECISION (2026-09-02):** build **4a + 4b** only. **4c (inbound IMAP) is OUT of scope** for this pass — do not build it now.

### Phase 4c — Inbound IMAP (DEFERRED — do NOT build this pass)
Guest replies auto-threaded via IMAP poll (`includes/imap.php`, `includes/inbound.php`, `api/poll-inbound.php`, `admin/inbound.php`, `guest_reply` kind). **Blocked on:** IMAP creds for a monitored mailbox (M365 `reservations@` — needs IMAP enabled + app password) and a poll trigger (the in-container `docker/scheduler.sh` can call `api/poll-inbound.php?secret=` on an interval — reuse that, don't add external cron). Ship 4a+4b first; treat this as a separate task once mailbox creds exist.

### Test
- Migrations applied → open a submission → change status → badge updates in view + list; filter by status.
- Add an **internal note** → appears grey. Add a **reply** with the email box **off** → logged green, no mail. Add a **reply** with email **on** → guest receives it (if SES out of sandbox — else UI shows "saved, email not sent"), subject carries `[TSR-…]`.
- `php tests/…` — mirror `tests/submission_payload.php` style if adding logic tests.

**Files:** `db/migrations/add_submission_status.sql`, `db/migrations/add_submission_notes_kind.sql`, `includes/submission-status.php`, `includes/submission-notes.php`, `includes/booking.php`, `includes/mail.php`, `admin/submission-view.php`, `admin/submissions.php`.

---

## Suggested execution order
1. **Task 1 (iOS zoom)** — pure CSS, zero risk, instant mobile win. Ship first.
2. **Task 3 (SEO filenames)** — small shared helper + find/replace. New uploads only.
3. **Task 2 (image picker retrofit)** — single-image fields first (nav, offers, page-content), galleries later.
4. **Task 4 (status + reply)** — 4a then 4b; 4c deferred.

## Migrations checklist (LOCAL `.env` is local Postgres — NOT prod; apply to prod RDS separately)
Apply in order, each `IF NOT EXISTS`/re-runnable:
- [ ] `add_media.sql` — **already in repo**; confirm it's on **prod RDS** (task 2/3 need it to record library uploads).
- [ ] `add_page_content.sql`, `add_event_submission_type.sql` — shipped with the last pull; confirm on prod.
- [ ] `add_submission_status.sql` — new (task 4a).
- [ ] `add_submission_notes_kind.sql` — new (task 4b).
> Prod RDS is private — apply via CloudShell one-off ECS run-task or the temporary public-access toggle (recipe in the AWS infra notes). ECS reads **RDS**, not the local/Neon DB.

## Env / DNS (task 0 — separate from the four code tasks)
- [ ] Confirm SES **production access** granted (else guest email only reaches verified addresses).
- [ ] Add apex **`_dmarc.tribalsand.com`** TXT (`p=none` → later `p=quarantine`).
- [ ] Add SES **custom MAIL FROM** subdomain for SPF alignment.
- [ ] Set **`Reply-To: reservations@tribalsand.com`** on guest-facing mail (code: one header in the mailer).
