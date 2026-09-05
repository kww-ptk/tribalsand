# Plan — Portal nav, Property-edit fix, Financial reporting

_Date: 2026-09-05 · Branch: master · Author: Claude (Opus 4.8)_

Three requests, investigated against the live code and the DB the app currently reads
(Neon, via local `.env`). Each section states **what I confirmed**, **the fix**, **files
touched**, and **how it will be tested**. Nothing below is implemented yet — this is the
plan for review.

> **Scope note:** Items 1 is a small, safe CSS change. Item 2 is mostly an *operational*
> fix (prod RDS) plus optional hardening. Item 3 is a new subsystem and is the bulk of the
> work — it has real product decisions that need your call (see **Open decisions**).

---

## Implementation status — 2026-09-05

Decisions taken: **D1 DB-driven · D2 unified `bookings` table · D3 no amount column (staff type) · D4 snapshot at confirm.**

- **Item 1 — DONE & shipped** (commit `abab1f4`): mobile bottom tab bar.
- **Item 2 — code + content backfill DONE**; prod migration is the only open ops step:
  - `venue_content_supported()` / `venue_stay_supported()` added to `includes/db.php`; `admin/venue-edit.php` `save_content`/`save_stay` now degrade with a clear message + in-tab banner instead of 500-ing on a DB missing the columns.
  - **D1 was already built:** the About heading/body already render DB-first via `includes/venue-about.php` (with per-page fallbacks); `tagline` too. So no page rewrites were needed.
  - **Content backfill DONE:** `db/seeds/seed_venue_content.php` seeds all 6 properties' tagline/heading/body from the page fallbacks (blanks-only; `--force` to overwrite). Ran on local Neon and verified: `/zuri.php` renders the DB heading with correct `<em>` emphasis, DB body, no literal asterisks. Admin Content boxes are now pre-filled and edits render on the page.
  - **Still open (ops):** run `add_venue_content.sql` + `add_venue_stay_info.sql` on **prod RDS** via `/admin/migrate.php`, then run `seed_venue_content.php` against prod. (Stay fields — wifi/checkout/house-rules/area-guide — are per-property operational copy the owner fills; the migration already seeds globals→venues, so no marketing backfill needed there.)
- **Item 3 — code COMPLETE & tested locally** (migration applied to local Neon; **apply to prod RDS separately**):
  - `db/migrations/add_bookings_finance.sql` — unified `bookings` ledger.
  - `includes/bookings.php` — confirm-snapshot + import writers, pure report aggregators, occupancy (all pre-migration-safe).
  - Snapshot-at-confirm wired into all three hold-confirm sites; cancel wired into their decline paths.
  - Import amount capture: sheet auto-detect + `import_parse_amount()` + staff-typed amounts in the preview.
  - `admin/reports.php` + sidebar "Reports" group (owner+manager, venue-scoped, per-currency KPIs, CSV export).
  - Tests: `tests/reports_logic.php` (all pass incl. DB round-trip) and extended `tests/booking_import_logic.php` (all pass).

**Prod cutover for item 3:** owner runs `/admin/migrate.php` → `add_bookings_finance.sql` on prod RDS, then `php bin/backfill-bookings.php` once (snapshots every already-confirmed hold into the ledger so reports aren't empty on day one). New confirms/imports populate automatically thereafter.

---

## 1 · Guest portal — move the nav to the bottom on mobile

### What I confirmed
Loaded the real portal (`booking.php?ref=…&view=home`) at a 375 px mobile viewport — the
home/calendar/request/activities/messages/bill icon row sits **at the top**, inside the
dark header, exactly as in your screenshot.

Cause: the nav markup (`includes/app/nav.php`, still commented "Bottom tab bar") is rendered
inside `.pa-topbar`. `css/portal-app.css` has **two** `.pa-nav` definitions:
- line 80 — the original `position:fixed; bottom:0` bottom bar (now dead), and
- line 386 — a later redesign that made it `position:static` inside the top bar, and on
  phones (`@media max-width:640px`, lines 396–403) wraps it to a full-width row **under the
  title, still at the top**.

The DOM is fine; this is purely which CSS wins on mobile.

### Fix (CSS only — `css/portal-app.css`)
Inside the existing `@media (max-width:640px)` block, turn `.pa-nav` back into a fixed
**bottom** tab bar and leave desktop untouched:
- `.pa-nav` → `position:fixed; left:0; right:0; bottom:0; z-index:30; background:#fff;
  border-top:1px solid var(--pa-line); max-width:640px; margin:0 auto;
  padding-bottom:env(safe-area-inset-bottom);` (re-uses the original bottom-bar styling).
- `.pa-nav__item` → back to column layout (icon over label), muted colour, teal active
  colour; **show `.pa-nav__label` again** (small 11 px labels, like a native tab bar).
- Reserve space so the fixed bar never covers content: add
  `padding-bottom: calc(64px + env(safe-area-inset-bottom))` to `.pa-app` (currently `0`)
  on mobile, and drop the bottom padding on `.pa-help-footer` accordingly.
- Keep the desktop (`min-width:641px`) top-bar nav exactly as-is.

No PHP/DOM change needed. The unread **messages badge** and the conditional **Bill** tab
(6 tabs when reservation-sharing is on) both keep working — 6 labelled tabs at 375 px is
tight but fits at 11 px; if it looks cramped I'll drop to icon-only for the 6-tab case only.

### Test
- Preview at 375 px: bar is at the **bottom**, labels visible, active tab highlighted,
  content scrolls clear of the bar, unread badge renders.
- Preview at ≥720 px (desktop): nav unchanged in the top bar.
- Check the `share_reservation` (6-tab) and 5-tab cases both fit.

**Effort:** ~1 hour. **Risk:** low (isolated to one media query).

---

## 2 · Admin can't edit a property — all boxes empty after the AWS move

### What I confirmed
- `admin/venue-edit.php` loads the row with `SELECT * FROM venues` and fills each field with
  `$venue['<col>'] ?? ''`, then saves with `UPDATE venues SET tagline=…, about_body=…,
  address=…` etc.
- On **Neon** (the DB local dev reads) the content columns *exist* — `tagline`,
  `about_heading`, `about_body`, `address`, `maps_url`, `stay_wifi`, `stay_checkout`,
  `stay_house_rules`, `stay_area_guide`, `deposit_amount`, `deposit_currency`,
  `upsell_enabled` — **but they are almost entirely empty** (only Zuri has an `address`).
  The live property pages hard-code their marketing copy, so nobody has ever filled these
  DB fields.

### Most likely root cause on prod (needs one RDS check to confirm)
The Neon→RDS move brought the base `venues` table but **not the migrations that add the
content columns**. On RDS then:
- `SELECT *` returns a row **without** those columns → every box shows blank (`?? ''`), and
- Saving runs `UPDATE venues SET tagline=…` against a **non-existent column → SQL error →
  "can't edit."**

This matches the recurring "not yet applied to prod RDS" note across recent features.
I **cannot reach the private RDS from this machine**, so this is a hypothesis until checked.

### Step A — Confirm on RDS (you or me-with-access run this)
Via the documented CloudShell one-off ECS `run-task` path (see memory
`ical-sync-and-prod-rds-access.md`), run against RDS:
```sql
SELECT column_name FROM information_schema.columns WHERE table_name='venues' ORDER BY 1;
```
Compare to the 19 columns Neon has (listed above). Any missing → confirms the diagnosis.
While there, list all tables and spot-check the other recent feature tables (menus,
reservations, nav_*, sustainability_metrics, checkin_* etc.) — the same gap may hit
other admin pages.

### Step B — Fix
1. **Apply the missing migrations to RDS**, in dependency order. I'll produce the exact
   ordered list from `db/migrations/` (the ones adding venue content, deposit, upsell, and
   any other feature tables found missing) and a single runbook to apply them against RDS.
2. **Backfill the content** (separate sub-task, needed regardless of columns): the copy you
   expect to see lives hard-coded in the per-property pages (`zuri.php`, `my_amani.php`,
   `enkare.php`, …). To make the admin form show/edit real content, we extract that copy
   into the `venues` rows once (a seed script), after which the form is the source of truth.
   *(Open decision D1 — do you want the public pages to then render from the DB, or keep
   hard-coded and treat admin as forward-only? I recommend DB-driven to match the rest of
   the site.)*
3. **Hardening (optional):** make `venue-edit.php` degrade gracefully if a column is absent
   (guard the content `UPDATE` behind a `columns_exist` check, same pre-migration-safe
   pattern used elsewhere) so a future missing migration blanks a field instead of 500-ing
   the save.

### Test
- After RDS migration: reload Edit Property on prod → boxes populated (once backfilled),
  save succeeds.
- Local regression: `admin/venue-edit.php` still saves against Neon.

**Effort:** verify + migrate ~1–2 h (mostly waiting on RDS access); content backfill +
optional DB-driven pages ~0.5–1 day. **Risk:** medium — depends on RDS access and how many
migrations are behind.

---

## 3 · Import the booking (not just a block) + Financial reports

### What I confirmed
- The importer (`includes/booking-import.php`) reads guest / arrival / departure / room /
  unit / agent / booking-date from the sheet and writes **only `availability_blocks`
  (`block_type='blocked'`)** to stop double-booking. It creates **no booking record, no
  guest record, and captures no money** — there is no amount/rate column parsed at all.
- `holds` (website bookings) has **no money columns** (`booking_total`, `amount_paid`, etc.
  — none). There is **no payments / invoice / revenue table** anywhere in the schema.
- So today there is nothing to report on financially, for either website or imported
  bookings. This is a new subsystem, in three parts.

### Part 3a — Capture revenue (the foundation)
Add a place to store money per booking. **Recommended model (D2):** one lightweight
**`bookings`** table that unifies both sources, rather than bolting money onto `holds` and
`availability_blocks` separately:

```
bookings(
  id, venue_id, unit_id, source ENUM('website','ota','agent','direct'),
  guest_name, guest_email, agent, check_in, check_out, nights,
  gross_amount NUMERIC, currency, amount_paid NUMERIC, status,
  hold_id NULL (link to holds for website), block_id NULL (link to availability_blocks),
  external_ref, created_at, imported_at
)
```
- **Website bookings:** on confirm, derive `gross_amount` from the shared nightly rate map
  (`includes/rates.php`, the new resolver) × nights, and write/refresh a `bookings` row.
- **Imported bookings:** the importer creates a `bookings` row **and** the
  `availability_blocks` calendar block (linked), instead of a block alone.

### Part 3b — Import the amount
Extend `import_extract_rows()` to detect an **amount/rate/total** column (tolerant header
match, same style as the existing detectors). If the sheet has no amount column, the import
**preview lets staff type the amount per row** before committing. Committing writes the
`bookings` row + block. *(Open decision D3 — does your channel-manager export include a
price/total column? If you can send one sample sheet I'll wire the exact header.)*

### Part 3c — Reports
New `admin/reports.php`, gated `require_manager()` and **scoped by `admin_venue_ids()`**
(owner = all properties; manager = their venues), with:
- **General view** (all properties) and a **per-property** filter.
- KPIs: **revenue**, bookings, **nights sold**, **occupancy %**, **ADR** (avg daily rate),
  **RevPAR**, broken down **by property**, **by month**, and **by source** (website / OTA /
  agent). Date-range filter + the existing `dt_*` admin toolkit (search / filter / pager /
  AJAX).
- **CSV export** for accounting; simple bar/line chart on the summary (optional, no new deps).
- Sidebar entry in a new "Reports" group (owner + manager).

### Migrations & tests
- `db/migrations/add_bookings_finance.sql` (the `bookings` table + indexes; pre-migration-safe
  `bookings_supported()` guard so nothing breaks before it's applied).
- `tests/reports_logic.php` (revenue/occupancy/ADR math, source split, venue scoping) and
  extend `tests/booking_import_logic.php` for amount parsing + booking creation.
- All reads pre-migration-safe; **apply to Neon locally AND to prod RDS separately** (same
  discipline as item 2).

### Test
- Import a sample sheet → `bookings` rows + blocks created, amounts captured, dedupe still
  idempotent.
- Confirm a website booking → its `bookings` row appears with derived revenue.
- Reports: numbers reconcile against seeded data; manager sees only their venues; CSV opens
  clean; date filters correct (Nairobi-local, per house convention).

**Effort:** 3a ~1 day · 3b ~0.5 day · 3c ~1.5–2 days. **Risk:** medium — new data model;
revenue-source rule (D2/D3) must be right or reports mislead.

---

## Open decisions (need your call before I build item 3)

| # | Decision | My recommendation |
|---|----------|-------------------|
| **D1** | Property pages: make them render content from the DB, or keep hard-coded and use admin as forward-only? | **DB-driven** (consistent with menus/nav/galleries already being DB-driven). |
| **D2** | Financial model: one unified `bookings` table, or add money columns to `holds` + track imports separately? | **Unified `bookings` table** — one clean source for reports across website + OTA + agent. |
| **D3** | Does your channel-manager export include a price/total column? | Send me **one sample export** so I wire the exact header; otherwise staff type amounts in the import preview. |
| **D4** | Where does website revenue come from — the live rate map (auto), or a stored total at confirm time? | **Snapshot at confirm** (defensible historical figure that doesn't shift when rates change), same principle as the sustainability re-baselining. |

## Suggested sequencing
1. **Item 1** (CSS, ~1 h) — ship first, low risk.
2. **Item 2 Step A** (RDS schema check) — unblocks the real diagnosis; cheap.
3. **Item 2 Step B** (migrate + backfill) once RDS access is in hand.
4. **Item 3** after D1–D4 are answered — 3a → 3b → 3c, each with tests.

---

_All DB changes must be applied to prod RDS separately from local Neon (see item 2). No
migration in this plan touches prod automatically._
