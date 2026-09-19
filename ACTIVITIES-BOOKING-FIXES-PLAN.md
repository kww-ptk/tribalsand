# Tribal Sand — Activities & Booking Fixes: Implementation Plan

> Self-contained spec. A fresh session can execute this without prior context.
> Written 2026-09-04 after investigating the live code + the Neon dev DB.
> **Do the work in the priority order below (P0 → P3), not the order the requests arrived in.**

---

## 0. Ground rules (READ FIRST)

- **Stack:** PHP 8.2, vanilla JS/CSS. No framework, no build system, no npm, no React. PostgreSQL via `db_query()` (prepared statements only).
- **No native UI (hard constraint):** never use native `<select>`, `<input type="date">`, `<input type="time">`, or unicode arrow/tick glyphs as UI. Reuse the styled components already in the codebase (`.dp-btn` datepicker, `.eselect`, steppers) + inline Lucide SVG icons.
- **Local dev server:** `D:\php84\php.exe -S localhost:8765 -t D:\TribalIsland D:\TribalIsland\router.php`. Launch config exists at `.claude/launch.json` (name `tribal-sand`).
- **Local DB ≠ production.** Local `.env` currently points at a **Neon** dev DB. **Production is AWS RDS.** Migrations/seeds run locally do **NOT** reach production — they must be applied to prod RDS separately (see P0). Prod RDS is private (VPC-only); apply via `/admin/migrate.php` on the live site (owner login) or a CloudShell one-off ECS run-task.
- **Do NOT submit real enquiries/forms against a DB that emails** — the enquiry/lead endpoints send staff + guest email. Test rendering + client validation; for end-to-end insert tests, use a throwaway address and delete the row, or assert inside a rolled-back transaction (see `tests/`).
- **Migrations are idempotent** (`IF NOT EXISTS` / `DROP+ADD CONSTRAINT` / `ON CONFLICT DO NOTHING`). Safe to re-run.
- Run the relevant `php tests/*.php` after each change. Add tests for new logic.

---

## Root-cause summary (why the order matters)

Investigation found that **two of the three bugs are almost certainly the same underlying problem: production RDS is missing recently-shipped migrations.** Evidence:

| Symptom | Finding |
|---|---|
| "Something went wrong" on homepage **Check Availability** | Posts to `api/search-lead.php`, which inserts `submissions.type = 'availability'`. That INSERT is **not wrapped in try/catch** — any DB error fatals to a 500 with no JSON body, and the JS falls back to exactly *"Something went wrong. Please try again."* The most likely DB error is the `submissions_type_check` constraint **not allowing `'availability'`** (migration `add_submission_availability_type.sql` not applied on prod). |
| **Internal notes not saved** | Notes write through `add_submission_note()` (`includes/submission-notes.php`). It is pre-migration-safe and **swallows any DB exception** (`catch (Throwable) { return 0; }`) with **no `error_log`**, so a failed insert looks like a silent vanish. If the `submission_notes` table (or its `kind`/`author_name` columns) is missing on prod, notes don't persist. |
| **Duplicate enquiries** | No duplicate-insert path exists in the code, the admin list query cannot fan-out, and the Neon DB has **zero** exact-duplicate rows. The screenshot rows (Sep 4, `alysaemilio@gmail.com`, "Homepage") do **not exist in Neon at all** → they are prod-only. Needs reproduction on prod; treat as unconfirmed and add a defensive idempotency guard. |

**On the Neon dev DB (already migrated in this session):** `submissions_type_check` allows `enquiry, contact, agency, availability`; `submission_notes` has `id, submission_id, admin_id, body, created_at, kind, author_name`. So locally everything works — which is exactly why these bugs are invisible in dev and live only on prod.

---

## P0 — Audit & apply production RDS migrations (do this first)

**Why first:** likely fixes the availability error *and* internal notes with **zero code changes**, and is a prerequisite for verifying everything else.

### Steps
1. Log in to `/admin/migrate.php` on production (owner account). It lists every file in `db/migrations/` with a "Run" button and executes `db()->exec($sql)` against the **live** DB.
2. Run (idempotent, safe to re-run) — at minimum these, in order:
   - `add_submission_availability_type.sql` (fixes the availability CHECK)
   - `add_submission_notes.sql` (base notes table, if absent)
   - `add_submission_notes_kind.sql` (adds `kind` + `author_name`)
   - `add_submission_status.sql` (lead pipeline status)
   - `add_reception_role.sql`, `add_sustainability_metrics.sql`, `add_upsells.sql` (shipped features that expect these)
3. **Verify on prod** after running: submit a test availability search (throwaway email) → confirm no error and one row in Submissions; open a submission → add an internal note → refresh → confirm it persists.

### Acceptance
- Homepage "Check Availability" completes without error and redirects to `/search`.
- Internal notes persist across refresh on prod.

> If step 2 can't be done via the admin UI, apply the same SQL through a CloudShell one-off ECS run-task (the container has `DATABASE_URL`). Confirm with:
> `SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname='submissions_type_check';`
> and `SELECT column_name FROM information_schema.columns WHERE table_name='submission_notes';`

---

## P1 — Harden the availability-search endpoint (`api/search-lead.php`)

Even after P0, this endpoint must never fatal into an opaque 500. Currently the INSERT + `lastInsertId()` sit outside any try/catch.

### Changes — `api/search-lead.php`
- Wrap the `db_query(INSERT …)` + `lastInsertId()` in `try { … } catch (Throwable $e) { error_log('[search-lead] insert failed: '.$e->getMessage()); http_response_code(500); exit(json_encode(['ok'=>false,'error'=>'Something went wrong saving your search. Please try again or contact us directly.'])); }` (mirror the pattern already used in `api/submit-enquiry.php:229`).
- Keep the notify call in its existing best-effort try/catch.

### Acceptance
- With a deliberately broken constraint, the endpoint returns valid JSON `{ok:false,error:…}` (not an HTML 500) and the reason is in the server log.

---

## P1 — Internal notes: surface failures + confirm round-trip

**File:** `includes/submission-notes.php`

### Changes
- In `add_submission_note()`, replace the silent `catch (Throwable $e) { return 0; }` with one that logs: `error_log('[submission-notes] add failed: '.$e->getMessage()); return 0;`. Do the same in `fetch_submission_notes()` and `submission_note_counts()`. (Diagnostics only — behaviour unchanged when it works.)
- No logic change needed otherwise; the handler in `admin/submission-view.php` (`action=add_note`, lines 61–96) already shows a specific flash when the table is missing and when the insert fails.

### Verify (after P0)
- `php tests/submission_status_reply.php` (exercises note insert/fetch incl. `kind`) → all pass.
- Manual: add a note and a reply on a submission, refresh, both persist with author + timestamp.

> Note: the requester called this "the booking message thread." The actual feature is the **conversation thread on a submission** (`admin/submission-view.php` → notes + replies). If they instead mean the guest↔staff chat on a *hold* (`admin/messages.php`), re-confirm — but all evidence points to the submission thread.

---

## P2 — Duplicate enquiries (reproduce, then guard)

No duplicate-insert path was found in code; the admin list can't fan-out; Neon has no dupes. The screenshot's three "Homepage" rows have **different names and check-in dates**, so they may be three distinct test submissions rather than duplicates. Treat as **unconfirmed** and do two things:

### 1. Reproduce / diagnose (on prod data)
- In Submissions, check whether genuinely identical rows exist (same name+email+dates+type within seconds). Query:
  ```sql
  SELECT guest_name,guest_email,type,check_in,date_trunc('second',created_at) s,COUNT(*)
  FROM submissions GROUP BY 1,2,3,4,5 HAVING COUNT(*)>1 ORDER BY s DESC;
  ```
- If none: it's perception (distinct submissions) — report back, no code change.
- If dupes exist: capture their `source_page`/`payload_json` to identify which form double-fired.

### 2. Defensive fix (do regardless — cheap insurance)
Add a short-window idempotency guard shared by `api/search-lead.php` and `api/submit-enquiry.php`: before inserting, skip (and return the existing id) if an identical lead exists in the last ~30s:
```sql
SELECT id FROM submissions
WHERE type=:type AND guest_email=:email AND COALESCE(check_in::text,'')=:ci
  AND COALESCE(check_out::text,'')=:co AND created_at > now() - interval '30 seconds'
ORDER BY id DESC LIMIT 1;
```
This neutralises any double-submit (double-tap on mobile, retry, StrictMode-style re-fire) without affecting legitimate repeat enquiries.

- Also confirm the front-end disables the submit button on first click for **all** lead forms (the homepage `savailForm` and room `availForm` already do; verify `enquiry-multistep.php` too — it does at `[data-enq-send]`).

### Cleanup of existing dupes (only if confirmed)
- **Do not bulk delete.** Delete only confirmed exact-duplicate copies, keeping the earliest `id` per group. Provide the owner the SELECT above first for sign-off; delete by explicit id list.

---

## P2 — Activities page: Guest Favourite, Location filter, modal enquiry

**Files:** `activities.php`, `admin/tour-edit.php`, new migration, `includes/` (modal partial), plus a small JS block.
Current state confirmed: `activities.php` groups published `tours` by **category** with category filter chips; each card's "Enquire →" links to the **separate page** `enquire.php?tour=<slug>` (which uses `includes/enquiry-multistep.php`, a form that always shows check-in/check-out).

### 2a. Data model — new migration `db/migrations/add_tour_favourite_location.sql`
```sql
ALTER TABLE tours ADD COLUMN IF NOT EXISTS is_guest_favourite BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE tours ADD COLUMN IF NOT EXISTS location VARCHAR(20) NOT NULL DEFAULT 'all';
ALTER TABLE tours DROP CONSTRAINT IF EXISTS tours_location_check;
ALTER TABLE tours ADD CONSTRAINT tours_location_check
  CHECK (location IN ('all','watamu','kilifi','vipingo'));
CREATE INDEX IF NOT EXISTS idx_tours_guest_fav ON tours(is_guest_favourite) WHERE is_guest_favourite;
```
> `location` is an explicit field (not derived from `tour_venues`) because the requirement lists a fixed option set incl. "All Locations", and it matches the homepage property filter values (`watamu|kilifi|vipingo`). Keep `tour_venues` as-is (it drives upsell placement, not this filter).

### 2b. Admin editor — `admin/tour-edit.php`
- In the details form (near Category / Tag label, ~lines 270–295) add:
  - **Guest Favourite** checkbox → `name="is_guest_favourite"`.
  - **Location** styled select (`.eselect`, NOT native) → `name="location"` with options All Locations / Watamu / Kilifi / Vipingo.
- Add both to the `INSERT` (line 66) and `UPDATE` (line 83) column lists + `$params` (bind favourite as `'TRUE'/'FALSE'` string like `is_published` at line 108; bind `location` as the posted value validated against the 4 allowed).

### 2c. Public page — `activities.php`
- **Guest Favourite category + label:**
  - Add a "Guest Favourites" filter chip at the front of `.act-filters` (`data-filter="favourite"`).
  - Build a virtual group of all `is_guest_favourite` tours rendered first (cross-category), OR tag each favourite card with a badge (e.g. a `.act-card__fav` ribbon "★ Guest Favourite"). Recommended: **both** — a badge on the card *and* the favourites filter shows only favourited cards.
  - The existing chip JS filters by `data-cat` on `.act-group`. Extend it: cards need a `data-fav` and `data-loc` attribute so filtering can be per-card, not just per-group (see 2d).
- **Location filter:**
  - Add a **second chip row** (or a styled select) for location: All / Watamu / Kilifi / Vipingo (`data-locfilter`).
  - Give each `<article class="act-card">` `data-loc="<?= e($a['location']) ?>"` and `data-fav="<?= $a['is_guest_favourite'] ? '1':'0' ?>"`.
  - **Refactor the filter JS** to apply **category AND location AND favourite** together at the card level (show a card only if it matches all active filters), then hide any `.act-group` whose visible-card count is 0. This replaces the current group-only toggle.
- **SELECT change:** add `is_guest_favourite, location` to the query at line 31 (they come through `t.*` already — no change needed; just use them).

### 2d. On-page modal enquiry (replace the separate page for activities)
- Replace each card's `href="/enquire.php?tour=<slug>"` link with a **button** `data-act-enquire data-tour-slug="<slug>" data-tour-name="<name>"`.
- Add one shared modal at the bottom of `activities.php` (or a new `includes/activity-enquiry-modal.php`) containing an **activity-specific** enquiry form:
  - Fields: **Name, Email, Phone (optional), Preferred date (single `.dp-btn` datepicker, optional), Number of guests (stepper), Message.**
  - **NO check-in/check-out.** (This is the key difference from `enquiry-multistep.php`, which is dates-first.)
  - Hidden `tour_slug` set from the clicked button; honeypot `website`; Turnstile widget if `captcha_site_key()`.
  - POST JSON to **`/api/submit-enquiry.php`** with `{tour_slug, name, email, phone, message, adults, children}` and **no** `checkin/checkout`. `api/submit-enquiry.php` already handles a tour (`tour_slug` → `fetch_tour_by_slug`) and, with no room + no dates, falls into the plain `enquiry` branch (no hold) — so **no server change is required**, but verify: `$form_mode` resolves to `'enquiry'` when `$room` is false, and the date-required block only triggers in availability mode. ✅ (see `api/submit-enquiry.php:84–108`).
  - On success show an inline thank-you (reuse `window.showSuccessModal()` if present, or a simple in-modal confirmation).
- Keep `enquire.php?tour=` working (no-JS fallback / direct links), but the primary path on `activities.php` is the modal.

### Tests / verify
- `php tests/*` for tours if present; add a small logic test for the location/favourite filter helper if you extract one.
- Preview `activities.php`: favourites chip + location chips filter correctly and combine; card badges show; "Enquire" opens the modal; modal has no check-in/out; submit creates a `type=enquiry` row with `tour_id` set and no dates.

---

## P3 — Zuri bookings importer (Ezee channel-manager sheet)

**Goal:** upload the channel manager's spreadsheet and populate confirmed bookings so they **block the calendar** (prevent double-booking) and appear on the Gantt — mirroring how OTA iCal import writes availability blocks.

### Source format (confirmed from `Ezee - Zuri Bookings.xlsx`, 38 rows)
Single sheet, header row 1. Columns:
`Hotel Name | Booking Date | Guest Name | Arrival | Dept | Room | (unit/sub-name) | Rate Type | Total | Paid | Total Charges | Travelagent`
- Hotel Name = `"Zuri | Watamu"` (all rows).
- Dates are **DD/MM/YYYY** (e.g. `05/09/2026`). Arrival = check-in, Dept = check-out.
- Column 7 (between Room and Rate Type) occasionally carries a unit name (only value seen: `"Anga Suite"`).
- Money columns are KES numerics. Travelagent is `-` or an agent/OTA string (e.g. `Booking.com-14050038`, `Paola Safaris`).
- **No reservation-ID column** → derive a stable dedupe key by hashing `guest_name|arrival|dept|room`.

### Room-name mapping (Ezee → website `rooms.slug`) — mostly exact
| Ezee "Room" | Website room | slug | Notes |
|---|---|---|---|
| Standard Garden View Suite | Standard Garden View Suite | `zuri-maji` | exact |
| Tropical Pool View Double Suite | Tropical Pool View Double Suite | `zuri-mwezi` | exact |
| Tropical Garden View Suite | Tropical Garden View Suite | `zuri-ua` | exact |
| Master Double Suite | Master Double Suite | `zuri-anga` | exact |
| Family Garden View Suite | Family Garden View Suite | `zuri-jua` | exact (col 7 "Anga Suite" is a unit label) |
| Entire Retreat Buyout | Zuri — Full Property Buyout | `zuri-buyout` | **name differs — needs explicit map** |
| Tropical Pool View **Twin** Suite | *(no exact match)* | `zuri-mwezi`? | **DECISION NEEDED** — likely same room as Double, twin config. Owner to confirm. |

Store this mapping in a small editable structure (a PHP array constant to start; optionally a `room_import_aliases` table later). Any unmapped Ezee room name must **block the import of that row and be surfaced** in the preview (never guess).

### Architecture
1. **Admin page** `admin/import-bookings.php` — `require_manager()` (owner + manager), scoped to Zuri for a scoped manager. Upload `.xlsx`/`.csv`.
2. **Parse** with a minimal reader. PHP has no built-in XLSX reader and the project has **no Composer deps** — prefer asking the owner to **export the sheet as CSV** and parse with `fgetcsv()` (zero new dependencies). If XLSX upload is required, add a tiny standalone XLSX-reader (single-file, vendored) — get sign-off before adding any dependency.
3. **Dry-run preview (mandatory before writing):** table of each row → resolved room, unit, arrival→dept, dupe-status (already imported?), and **conflict-status** (overlaps an existing hold/booked block). Rows with unmapped rooms or hard conflicts are flagged and excluded until resolved.
4. **Commit:** for each accepted row, mirror `api/sync-ical.php`'s writer:
   - Resolve room → pick a unit (`fetch_units_by_room`); for **Entire Retreat Buyout** use the buyout room/unit so existing **entire-place mutual-exclusion** blocks all suites for those dates.
   - Insert `availability_blocks (unit_id, date_from, date_to, block_type='blocked', notes)` with `notes` = `"Ezee import · <guest> · <agent>"`. **Idempotent**: skip if an identical `(unit_id, date_from, date_to, block_type='blocked')` already exists (same guard the iCal import uses).
   - Detect conflicts with existing `hold`/`booked` blocks and record to `channel_conflicts` (reuse the iCal path) rather than silently overwriting.
   - Optionally also store the guest/agent/totals in a lightweight `imported_bookings` audit table (or in the block `notes` payload) for the Gantt tooltip. Keep money as reference only — do not build billing off this.
5. **Report:** N imported, N skipped (already present), N conflicts, N unmapped — with the row detail.

### Decisions to confirm with owner
- **"Tropical Pool View Twin Suite"** → which website room? (recommend `zuri-mwezi`).
- Import as **`block_type='blocked'`** (shows as unavailable, like OTA) vs creating full **holds/bookings**. Recommended: `blocked` (simplest, achieves double-booking protection + Gantt visibility). Confirm.
- Whether cancelled/no-show rows can appear in future exports (this sheet has none) and how to handle re-imports that should *remove* a block.

### Tests
- Unit-test the parser + date parsing (DD/MM/YYYY → `Y-m-d`) + room mapping + dedupe key, using a small fixture, inside a rolled-back transaction (pattern: `tests/reservations_logic.php`).

---

## Suggested execution order (recap)

1. **P0** — apply prod migrations, verify availability + notes on prod. *(May close the availability error and the notes bug outright.)*
2. **P1** — harden `search-lead.php`; add error logging to `submission-notes.php`; confirm notes round-trip.
3. **P2** — reproduce duplicates; add idempotency guard; clean confirmed dupes only.
4. **P2** — activities: migration + admin fields + favourite/location filters + on-page modal enquiry.
5. **P3** — Zuri Ezee importer (dry-run first, blocks-based, idempotent).

## Files touched (quick index)
- `db/migrations/add_tour_favourite_location.sql` *(new)*
- `db/migrations/add_import_audit.sql` *(optional, P3)*
- `api/search-lead.php`, `api/submit-enquiry.php`
- `includes/submission-notes.php`
- `admin/tour-edit.php`, `activities.php`, `includes/activity-enquiry-modal.php` *(new)*
- `admin/import-bookings.php` *(new)*
- `admin/submission-view.php` *(no change expected — verify only)*
- Tests under `tests/`

## Open questions for the owner
1. Confirm production RDS is missing the listed migrations (P0 verification will show this definitively).
2. "Internal notes in the booking message thread" = the **submission conversation thread**, correct? (vs. the guest chat on a hold.)
3. Zuri importer: CSV upload acceptable (no new deps) or must it accept `.xlsx`?
4. Map "Tropical Pool View Twin Suite" → `zuri-mwezi`?
5. Import bookings as calendar **blocks** (recommended) or full holds?
