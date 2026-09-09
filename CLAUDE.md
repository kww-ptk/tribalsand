# Tribal Sand — Development Notes

## Tech Stack
- PHP 8.2 — no framework, vanilla PHP only
- PostgreSQL via PDO — prepared statements only (`db_query()` helper in `includes/db.php`)
- Apache on AWS ECS (Docker) — auto-deploy from GitHub via GitHub Actions (build → ECR → ECS)
- Vanilla JS and CSS — no build system, no npm dependencies
- Local dev: `D:\php84\php.exe -S localhost:8765` (AWS RDS PostgreSQL via `.env`)

## Key Conventions

### IP detection — always use `client_ip()`
The app runs behind a load balancer. `$_SERVER['REMOTE_ADDR']` is always the proxy IP.
**Never use `$_SERVER['REMOTE_ADDR']` directly** — always call `client_ip()` (defined in `includes/db.php`).
Affects: rate limiting, audit logs, tracking.

### Timezone — the whole app runs in Africa/Nairobi
`includes/db.php` sets `date_default_timezone_set('Africa/Nairobi')` at module load **and** issues `SET TIME ZONE 'Africa/Nairobi'` on every PDO connect. So PHP `date()`/`strtotime()`/`DateTime('now')` and Postgres `NOW()`/`CURRENT_DATE`/`::date`/`timestamptz` rendering all agree on Kenya local time. **Never assume UTC** and never hardcode a UTC offset — "today" (e.g. `frontdesk_today_ymd()`, the gate visitor day-filter in `includes/team.php`) is Nairobi-local and must match the DB. Note: legacy naive `TIMESTAMP` rows written before this change hold UTC wall-clock and read ~3h off until superseded; `TIMESTAMPTZ` columns are unaffected.

### Cloudflare Turnstile — fail-closed in production
`verify_captcha()` is in `includes/turnstile.php`. If `TURNSTILE_SITE_KEY` is set but secret is missing, it returns `false` (fail-closed). Both absent = dev mode bypass. Never revert to the old `return true` bypass. Widget class is `.cf-turnstile`, token field is `cf-turnstile-response`, script is `challenges.cloudflare.com/turnstile/v0/api.js`.

### Asset cache busting
All CSS/JS `<link>`/`<script>` tags in `includes/head.php` use `?v=<?= filemtime(...) ?>` to force cache invalidation on deploy.

### Booking form label
`includes/form-enquiry.php` — the submit button says **"Request to Book"** (not "Book Now"). The form creates a 24h hold enquiry, not an instant booking — wrong labels cause chargebacks.

### iCal sync secret
`api/sync-ical.php` — secret is passed via `Authorization: Bearer` header. Legacy `?secret=` query param still works for external crons but the admin Gantt "Sync Now" button uses the header. Never revert to query-param-only (it logs the secret in plaintext).

### Periodic jobs — in-container scheduler (AWS)
There is **no external cron service**. Scheduled jobs run **inside the app container**: `docker/entrypoint.sh` starts `docker/scheduler.sh` in the background, then execs `apache2-foreground`. The scheduler is a plain bash loop (not crond — crond doesn't inherit container env; a loop launched from the entrypoint does, so the PHP scripts and loopback calls all see `DATABASE_URL`/`*_SYNC_SECRET`). It calls the app's **existing** endpoints over loopback (`127.0.0.1`, `?secret=` param) rather than duplicating logic — never fork the sync logic into the scheduler. Jobs: hold expiry every 5 min (`bin/ical-expire-holds.php`; also runs inline via `expire_stale_holds()`), OTA iCal import hourly (`api/sync-ical.php`, needs `ICAL_SYNC_SECRET` in the ECS env), FX rates daily (`api/fx-sync.php`, needs `FX_SYNC_SECRET`). All three are idempotent, so multiple ECS tasks running the loop is harmless. `*.sh` are pinned to LF (`.gitattributes` + a `sed` CR-strip in the Dockerfile) so Windows checkouts don't break the shebang.

### Team roles & job types
`admin_users.role` is `owner | manager | staff` (extended from the old owner/staff binary via `db/migrations/add_team_roles.sql`). Gate with the role helpers in `includes/auth.php`, never a raw `admin_role()` string compare:
- `is_owner()` — full access. `require_owner()` (pricing/settings/staff/bookings config) bounces **everyone but the owner**, so it now blocks managers too.
- `is_manager()` — per-property ops tier. `require_manager()` = owner **or** manager (guards assignment, `admin/tasks.php`, `admin/gate.php` management).
- `is_staff()` — access-code staff only (managers are **not** staff). `admin_job()` returns their specialty; a **NULL `job_type` means `frontdesk`** (backward-compatible — existing staff keep landing on Front Desk). `job_is_ops()` = housekeeping/maintenance/gardening/driver.
- `require_frontdesk()` — guest-messaging tier: owner, manager, or front-desk staff. **Bounces ops staff and gate-security** (they get a focused interface with no messaging). Guards `admin/messages.php` + `admin/messages-poll.php`; the Messages nav link (`$__navMessages`) uses the same audience.
- **Scoping now covers managers:** `admin_venue_ids()` returns the venue set for managers *and* staff (owner = `null` = all); `staff_can_hold()` scopes both. When scoping a page, test `!is_owner()`, not `is_staff()`.
- `admin_home_url()` routes each account to its home (owner→dashboard, manager→frontdesk, security→gate, ops→mywork, else frontdesk); `admin/_layout.php` nav is role/job-aware.
- Request auto-routing + assignment + tasks + visitors helpers live in `includes/team.php` (loaded via `includes/booking.php`); every read is pre-migration-safe (`*_supported()` guards). Migrations, in order: `add_team_roles` → `add_addon_assignee` → `add_tasks` → `add_visitors`. Test: `php tests/team_logic.php`.

### Live messaging — polling, not websockets
Guest↔staff chat updates live via **short polling** (no websockets — Apache/ECS has no long-running socket process). Both sides poll a JSON endpoint every 5s (`after=<last id>`) and pause when the tab is hidden. Guest: `GET/POST api/booking-message.php` (ref-authed). Admin: `GET/POST admin/messages-poll.php` (session-authed, `staff_can_hold`-scoped, `require_frontdesk`-gated; JSON POST carries `csrf_token` in the body since `verify_csrf()` reads `$_POST`). Shared helpers in `includes/booking.php`: `fetch_thread_messages_since()`, `message_payload()`, `message_time_label()` — keep initial render and appended bubbles identical. Admin `admin/messages.php` keeps its PRG form as a no-JS fallback; `admin/assets/admin-chat.js` and `js/booking-manage.js` (chat block) enhance it.

### Inbound guest replies — SES → SNS webhook, threaded by subject tag
A guest's reply to one of our enquiry replies lands back in the submission thread automatically, instead of a human reading `reservations@` and pasting it in. Path: SES receives on `INBOUND_MAIL_ADDRESS` (`reply@mail.tribalsand.com`) → SES receipt rule **publishes to SNS** → SNS HTTPS-POSTs to **`api/inbound-mail.php`**. Full AWS/DNS runbook: `docs/inbound-mail-setup.md`. Helpers in **`includes/inbound-mail.php`**; every DB read is pre-migration-safe.
- **The whole thing hinges on Reply-To.** `send_admin_reply()` (`includes/mail.php`) sets `Reply-To` to `INBOUND_MAIL_ADDRESS` **when that env var is set**, else the legacy `notify_email` (reservations mailbox). Unset = feature is inert (replies go to the mailbox, staff paste manually — the old behaviour, deliberately). So the env var is the on-switch; there is no separate feature flag.
- **Matching is the subject `[TSR-<id>]` tag, HMAC-verified.** Outbound replies already carry `make_submission_ref()` in the subject. Inbound uses **`verify_submission_ref()`** (added in `includes/booking.php`), which **re-derives the HMAC** — NOT the looser `parse_submission_ref()`, which trusts any well-formed tag. This is load-bearing: the endpoint is public, so a forged `TSR-<id>-000000` in a subject must not inject a "guest_reply" into an arbitrary thread. Use `verify_*` for anything inbound.
- **The endpoint authenticates SNS itself** — `sns_verify_signature()` (canonical string per message type, cert fetched only from an `sns.<region>.amazonaws.com` `.pem` over https, SHA1/SHA256 per `SignatureVersion`). Optionally pinned to one topic via `INBOUND_MAIL_SNS_TOPIC_ARN`. It **auto-confirms** the `SubscriptionConfirmation` (so you just create the HTTPS subscription and it goes Confirmed). It returns **200 even when it can't match** a reply (logged as unmatched) so SNS stops retrying; only genuine auth failures 403.
- Threading writes a **`guest_reply`** note (the kind already existed — `add_submission_notes_kind.sql` anticipated it) and nudges status to `to_follow_up` unless the lead is already `booked`/`not_interested`/`dates_unavailable`. De-dupe + observability via **`inbound_mail_log`** (migration `add_inbound_mail_log.sql`), keyed on SES `messageId`; pre-migration it still threads, just can't de-dupe.
- Body extraction is a **minimal in-house MIME parser** (`inbound_extract_text()`): prefers `text/plain`, decodes base64/quoted-printable + charset, strips the quoted history (Gmail/Outlook/`>` blocks). **Boundaries are case-sensitive** — parse them from the original-case Content-Type, never the lowercased copy. If the SNS action truncates a large body (~150 KB cap), the endpoint stores a "check the inbox" placeholder rather than dropping the reply; the S3-action variant is a documented follow-up. Test: `php tests/inbound_mail_logic.php` (pure logic always; de-dupe round-trip in a rolled-back transaction when a DB is reachable).

### Signed-consent record — clean URL + evidence trail
The electronic registration/waiver evidence document is served from **`/record.php`** (guest-facing, web root) — **never** from `/admin/`. Legal requirement: the generated PDF's browser print-footer shows the page URL, and a backend route must not appear on a document that serves as legal evidence. `admin/consent-print.php` is now a **301 redirect** to `/record.php` (keeps old links alive) — don't revert it to a renderer. Tri-auth unchanged (lead `ref` / co-guest `g` / admin session, `can_view_guest_docs`). Each signed record carries a controlled reference ID (`waiver_reference`, format `TSR-<hold>-<guest>-<rand>`, minted at signing or lazily on first view via `checkin_ensure_reference()`) shown on the document instead of raw internal IDs. Signing writes to an append-only, server-side audit trail (`checkin_signing_audit`) via `checkin_log_signing_step()` — one row per material step (`signed`, `record_viewed`, `signature_voided`) capturing reference, exact waiver version, personal link issued, timestamp, IP, device, method. All reads are pre-migration-safe (`checkin_reference_supported()` / `checkin_signing_audit_supported()`). Migration: `add_checkin_reference.sql` (after `add_checkin_signature`). Terms text itself lives in the DB (`checkin_waiver_text` setting), edited in Admin → Check-in Settings; editing only affects future signings — existing records keep their `waiver_terms_snapshot`.

**Record immutability (migration: `add_checkin_record_integrity.sql`, after `add_checkin_reference`).** The signed document must never change after signing. Two mechanisms enforce this — never weaken either:
- **Identity snapshot.** At signing, `api/checkin-save.php` freezes the guest's identity into `waiver_{name,passport,nationality,passport_expiry}_snapshot`. `record.php` renders these snapshots (guarded by `checkin_identity_snapshot_supported()`), falling back to the live `checkin_guests` columns only for legacy pre-migration rows. **Never render live identity columns on `record.php`** — that was the original mutation bug (editing a passport # silently rewrote the "signed" evidence).
- **Void-on-edit.** `checkin_void_signature_if_identity_changed($holdId,$guestId,$new,$actor)` (in `includes/checkin.php`) is called by **both identity-edit paths** (`api/checkin-save.php`'s passport UPDATE and `admin/booking.php`'s `guest_fill`) **before** they write. (The `api/checkin-guest.php` add-adult/add-child path only *inserts* new rows — never an already-signed one — so it needs no guard.) If the guest is already signed and a **material identity field** (`checkin_identity_material_fields()`: name/number/nationality/expiry) actually changed, it clears the signature (keeping `waiver_reference` + audit rows), clears `holds.checkin_completed_at` to reopen the booking, and logs `signature_voided`. It is **compare-before-void** — the wizard re-posts unchanged passport fields every step, so callers must pass only posted fields, and unchanged values never void. The admin `_ws_checkin.php` edit form confirms before saving when the guest has signed. Re-signing re-snapshots the corrected identity.

**Tamper-evident audit chain.** `checkin_signing_audit` also carries `prev_hash`/`row_hash` (same migration): each new row stores `sha256(prev_hash || payload)` over `checkin_audit_canonical()` (fixed field order — never reorder, it invalidates every stored hash). `checkin_audit_verify(?holdId)` walks the chain and returns `['ok','checked','bad_id']`; any later edit/delete of a row is detectable. Rows written before the migration have NULL hashes and sit outside the chain. Tests: `php tests/checkin_consent.php` (runs inside a rolled-back transaction).

### Security-deposit step — per-property amount + credit-card image
A `deposit` step in the check-in wizard (migration: `add_checkin_deposit.sql`, after `add_checkin_record_integrity`). It is **booking-level** (the lead handles it once — co-guest `?g=` links never show it) and, like every step, appears in the fixed `checkin_step_catalog()` — enabled by default, **not required** by default. All reads are pre-migration-safe (`checkin_deposit_supported()` guards the `booking_checkin.deposit_card_file_key` column).
- **Amount is per property**, stored on `venues.deposit_amount` + `venues.deposit_currency` (default `USD`), edited in **Admin → Properties → Edit → Details** (owner-only). `checkin_venue_deposit($hold)` resolves it via the hold's `venue_id`; `checkin_format_deposit()` renders USD as `$1,234`, any other code as `CODE 1,234`. A NULL/0 amount shows the step with **"Confirmed at arrival"** instead of a figure — the step never hard-depends on an amount being set.
- **The deposit is charged AT THE PROPERTY, never online.** The guest-facing copy (`checkin_deposit_note()`, editable in Admin → Pre-Check-in) must always say so. The card image is a convenience for the front desk, not a payment instrument — do not add any online-charge flow.
- **Card image storage mirrors the passport scan**: private only. Uploaded via `api/checkin-upload.php` (the `deposit_card` file field → booking-level key `checkin/<hold>/deposit/…`, lead-only, images only — no PDF), stored with `storage_put_private()`, and served **only** through `admin/checkin-file.php?hold=<id>&kind=deposit`. Never a public URL. The wizard's delegated uploader (`js/checkin-wizard.js`) routes a `.ci-upload[data-kind="deposit"]` input to the `deposit_card` field; every other `.ci-upload` stays a passport scan.
- **Completion:** `checkin_step_complete('deposit', $data, …)` is true once the card image is on file (`checkin_deposit_card_on_file()`). When the admin marks the step required, both the client (`validateStep`, gated on `data-deposit-required`) and the server (`checkin_missing_steps` → the submit gate in `api/checkin-save.php`) block finishing until the image is uploaded. Admin `_ws_checkin.php` shows the amount + a "View card" link. Test: `php tests/checkin_logic.php`.

### Property photo galleries — DB-driven, admin-editable
Both galleries on a property page come from **`venue_images`**, edited in Admin → Venues → *property* → **Gallery** (upload / set-main / reorder / delete, `admin/venue-edit.php`). Nothing about a property page's photos is hand-coded any more.
- **One resolver, one query.** `pg_gallery($slug, $fallback = [], $badge = '')` in `includes/property-gallery-data.php` returns `['badge','images'=>[['url','alt'],…]]`. It memoizes **only the DB-derived result** — `$fallback` is applied per call. That split is load-bearing: the hero calls it *with* a static fallback and the bottom grid calls it *without* one, and caching the fallback would make the grid render stale photos instead of hiding. Don't "simplify" it into a single cached return.
- **Two partials, one lightbox.** `includes/property-gallery.php` (hero) and `includes/property-photo-grid.php` (bottom section) consume the same ordered list, so grid tile `i` addresses image `i` in the shared `pgOpenLb` lightbox. Never slice, re-sort or filter one and not the other. The per-page `openLb`/`#lb` lightboxes were deleted — don't reintroduce one.
- **The bottom section renders all images and hides when there are none** (no static fallback, deliberately). The trailing `<div class="divider">` lives *inside* the partial so a hidden section doesn't leave two stacked dividers.
- `$pgrid_heading` / `$pgrid_caption_extra` are echoed as **raw HTML** (they carry `<em>` and `<a>`); they are page-authored config and must never receive user or DB input. Image `url`/`alt` are DB values and go through `e()`.
- **Full gallery page — one shared, DB-driven page.** `gallery.php?venue=<slug>` renders *all* of a venue's `venue_images` (same source as the listing hero + grid), no category filters. The six legacy per-property pages (`maya-kobe-gallery.php`, `zuri-gallery.php`, `my-amani-gallery.php`, `enkarebofa-gallery.php`, `maya-ilai-gallery.php`, `sandbox-gallery.php`) are now **301 redirects** to it — don't reintroduce hardcoded galleries. Unknown/unpublished `?venue=` → redirect home (never a blank page). The property page is `<slug>.php` (back-link + Gallery nav point there). `events-gallery.php` is NOT a venue and stays standalone.
- **Listing grid caps at 15 + "See more".** `property-photo-grid.php` shows the first 15 images and, when the venue has more, a "See all N photos →" button linking to `gallery.php?venue=<slug>`. The cap is a tail-truncation only (`array_slice(…,0,15)`) so tile `i` still addresses image `i` in the shared `pgOpenLb` lightbox, which still holds the FULL list — never re-sort/filter, only truncate.
- Test: `php tests/property_gallery.php`. Seed: `db/seed_venue_images.sql`.

### Site navigation — DB-driven, admin-editable mega menu
The top nav + mobile drawer render from the DB (migration: `add_nav_menu.sql`), edited in **Admin → Site Menu** (`admin/nav-menu.php`, **`require_owner()`** — site-wide config). Model: `nav_items` (top-level button; `layout` simple|wide2|wide3; `auto_source`; `sort_order`; `is_published`) → `nav_groups` (a column/section, optional `label`) → `nav_links` (`label`,`href`,`sublabel`,`image_key` thumbnail, `tag` ''|open|soon, `role` row|footer_link|cta_button, `cta_note`, `target_blank`, `is_published`). Helpers in **`includes/nav-data.php`**; every read is pre-migration-safe (`nav_supported()`).
- **Render + fallback.** `includes/header.php` computes `$__navTree = nav_supported() ? fetch_nav_tree() : []`. When non-empty it renders via `nav_desktop_html()` / `nav_drawer_html()`; when empty it renders the **original hardcoded nav kept inline as the `else` fallback** — so a missing migration/seed never blanks the menu. Keep both branches working. The right-side actions (Plan Your Trip / Book Now / **currency** / **language**) and the logo are always hardcoded, never in the tree.
- **Restaurants stays auto-driven.** It is one `nav_items` row with `auto_source='restaurants'`; header.php captures the existing published-menus dropdown + drawer markup once (via `ob_start`) into `$__restoDesktop`/`$__restoDrawer` and the renderers splice it in at that item. The builder shows it **locked** (reorder only, no editing). Don't fold the live menu list into the tree.
- **Thumbnails** upload via the same path as venue galleries (`storage_put()` + GD resize, served through `storage_url()`); `nav_img_url()` resolves keys: `http…`/leading-slash pass through, `images/…` → `asset_url()` (legacy seeded paths), else `storage_url()`. Same R2/S3 dependency as venue photos on prod.
- **Simple** dropdowns stack groups vertically with a `.ts-drop-div` between them (this is how About's "Destinations" label + dividers are modeled — one group per section); **wide2/wide3** lay groups out as `.ts-drop-col` columns. `footer_link`/`cta_button` rows are pinned into a `.ts-drop-col-footer`. The drawer renders one section per top item (a deliberate, cleaner IA than the old bespoke drawer — single source of truth).
- **Builder UI = house design system only** (no native chrome): `.eselect` selects, `.inp` fields, `.optchip` toggles, `.filefield` uploads, `.btn-icon` save/delete. Each menu is a collapsible `<details class="nv-item">` card. **Reorder is grip-handle drag-and-drop** (same pattern as `admin/services.php`), not ▲▼ buttons — three independent sortable scopes (menus, columns within a menu, links within a column) each POST an AJAX `*_reorder` action (`item_reorder`/`group_reorder`/`link_reorder`) carrying `order=JSON.stringify(ids)` (+ scoped `item_id`/`group_id`) and get back `{ok:true}`; the server rewrites `sort_order` with a scoped `WHERE` so a payload can't touch rows outside its list. The auto (Restaurants) item stays draggable-only + locked. When editing this page keep it native-chrome-free.
- **Seed:** `db/seeds/seed_nav_menu.php` (idempotent; wipes + rebuilds the current nav 1:1). Owner-only admin, sidebar "Site Menu" in the Catalog group. Local `.env` = local Postgres, not prod — apply migration + seed to production separately. Test: `php tests/nav_menu_logic.php`.

### Restaurant menus — DB-driven, per property, manager-editable
Digital restaurant menus live in the DB (migration: `add_menus.sql`), not in static pages. Model: `menus` (per property, keyed by URL `slug`, optional `venue_id`) → `menu_categories` (`section` food|drinks, `is_visible`) → `menu_items` (`price` NUMERIC KES, 7 badge booleans, `is_available` = the "Hidden" toggle, `sort_order`). Helpers in **`includes/menu.php`**; every read is pre-migration-safe (`menus_supported()`).
- **Public:** `menu.php?m=<slug>` — a standalone, `noindex`, mobile-first page (reuses the original zuri-menu design). Legacy `zuri-menu.php` + `maya-kobe-breakfast.php` are now **conditional 301 redirects** to it (redirect only when the DB menu exists — never a blank page). The site nav "Restaurants" mega-menu (`includes/header.php`, `fetch_published_menus()`) lists published menus alongside the static Tribal Table / Somewhere Café venue links; the two columns are **Kilifi** and **Watamu**, driven by the `$__navMenuMeta` slug map at the top of `header.php` (town / thumbnail / display name / `open`|`soon` status tag) and rendered by `ts_nav_menu_row()`. A published menu with no entry in that map still appears — it falls through to a "More Menus" group so nothing silently drops out of the nav.
- **Admin:** `admin/menus.php` (list) + `admin/menu-edit.php` (single-page editor, all PRG). Gated by **`require_manager()`** (owner **or** house manager) and **scoped by `admin_venue_ids()`** — a manager only sees/edits menus whose `venue_id` is in their set. Every mutation re-checks ownership (`menu_editable()` on the menu, `catOwned`/`itemCat` on category/item rows) so a scoped manager can't touch another property's menu by posting a foreign id. Sidebar "Restaurant" group is owner+manager only. Reorder uses ↑/↓ glyph buttons (the `admin_icon()` set has **no** arrow-up/down paths — don't call them).
- **Seed:** `db/seeds/seed_menus.php` (idempotent CLI: wipes each menu by slug, re-inserts) seeds Zuri (food+drinks) + Maya Kobe (breakfast). **Note:** the local `.env` is a **local Postgres.app** instance (`DB_HOST`/`DB_NAME`), *not* production — production is RDS PostgreSQL since the AWS move (see `AWS-GOLIVE-PLAN.md`). Migrations and seeds run locally do **not** reach live data; apply them to production separately.

### Restaurant reservations — request model, per property, manager-scoped
Table reservations live in the DB (migration: `add_reservations.sql`, after `add_menus.sql`). **Request model:** guest submits → `pending` → staff **confirm**/**cancel**. **v1 has no table-capacity / double-booking logic** — staff eyeball availability. Enabled for **all published venues**. Helpers in **`includes/reservations.php`**; every read is pre-migration-safe (`reservations_supported()`). Model: `reservations` (venue-scoped, optional `menu_id` soft-link to the venue's first published menu, `reference` `TSR-<venue>-<rand>` minted on insert, `status` pending|confirmed|cancelled).
- **Public:** `reserve.php?venue=<slug>` — a `noindex`, mobile-first branded page (header/footer + `$page_booking` for the styled datepicker). Property = styled select of published venues; date = **styled datepicker** (`.dp-btn`, never a native date input); time = 30-min slots 12:00–22:00 (`reservation_slots()`); party = stepper. Posts to **`api/submit-reservation.php`** — Turnstile fail-closed + IP rate-limit (5/10min) + CSRF, **PRG**: success → `reserve.php?ok=<reference>` (success modal), validation errors flash to the session and re-render per-field. No-JS still submits. `send_reservation_received()` sends the guest ack ("pending confirmation") **and** the staff alert.
- **Admin:** `admin/reservations.php` — gated by **`require_manager()`** (owner + house manager), **scoped by `admin_venue_ids()`**. Today/Upcoming/Pending KPI cards + the `dt_*` toolkit (search + status/property/date filters + pager, AJAX-swappable body). Confirm/Cancel are PRG + CSRF with a **per-row ownership re-check** (`reservation_editable()`) so a scoped manager can't act on another venue's row by posting a foreign id. Confirming calls `send_reservation_confirmed()` (guest email; no-op when the guest left no email). Nav entries: site "Restaurant" dropdown + `menu.php` CTA (only when the menu has a `venue_id`) → `reserve.php`; admin "Reservations" in the Restaurant sidebar group (owner+manager). Tests: `php tests/reservations_logic.php` (pure logic + DB assertions in a rolled-back transaction). Mail (`send_reservation_received` / `send_reservation_confirmed`) uses the branded `_email_shell()` template.

### Sustainability metrics — live, owner-editable, time-accruing
The figures on `sustainability.php` and the home page's "Live Data" cards come from **`sustainability_metrics`** (migration: `add_sustainability_metrics.sql`), edited in **Admin → Sustainability** (`admin/sustainability.php`, **`require_owner()`** — site-wide config). Helpers in **`includes/sustainability.php`**; every read is pre-migration-safe (`sustainability_supported()`).
- **The stored `value` is a reading, not the displayed number.** Each row holds the last known-true reading (`value` taken at `baseline_at`) plus an optional `growth_per_day`; the page derives `current = min(value + growth_per_day × days_since(baseline_at), max_value)` at render. **Nothing increments `value` on a schedule** — don't add a cron for it. That split is load-bearing: the stored figure stays a number the owner can defend, and `sus_metric_save()` **re-bases `baseline_at` whenever the value actually changes**, so re-entering a corrected meter reading never compounds on an already-accrued one. Editing only the label/note/rate leaves an in-flight accrual alone.
- Elapsed days are **fractional and Nairobi-local** (figures creep through the day rather than jumping at midnight) and clamped at ≥ 0, so a future `baseline_at` can never render below the stored reading.
- **`solar_mwh` and `co2_tonnes` seed with `growth_per_day = 0` deliberately.** These are public environmental claims and 27.59 MWh reads as a 2024 annual total — auto-incrementing it changes what the site asserts. The rate is an admin field the owner opts into per metric. `beach_kg_total` accrues at 30/7 kg/day because that restates the weekly rate the page already publishes. `desal_pct` is capped at 100 via `max_value`.
- **Pre-migration the helpers return `sus_fallback_metrics()`** — the exact figures the templates shipped with — so a missing migration renders the current page, never zeros. Keep that list in step with the migration's seed block.
- Front-end: the stats strip, the solar card and the beach ring count up on scroll (IntersectionObserver). The count-up **never paints over the server-rendered value on its first frame** and skips animating when `document.hidden` — a paused rAF in a background tab would otherwise leave a 0 on screen. `prefers-reduced-motion` gets the final value immediately.
- Hero photo + section headings are editable through the `sustainability` entry in `page_content_registry()` (Admin → Content → Pages). Test: `php tests/sustainability_logic.php`.

### Channel-manager import — per property, mapping is per property too
`admin/import-bookings.php` turns an Ezee export (CSV/TSV/XLSX) into calendar blocks.
Engine in **`includes/booking-import.php`**. **No migration** — it stores its mapping in
the `ezee_room_maps` setting.
- **One export covers one property.** The venue is chosen up front and validated against
  `admin_venue_ids()`, so a posted `venue_id` outside the account's own list is ignored
  rather than honoured. Owner + manager; a manager sees only their properties (and no
  picker at all when they have one).
- **Room maps are per property**, stored as `{"<venue_id>": {ezee_name: slug}}`. A saved
  map is authoritative for that venue — it fully replaces the seed, so deleting a row
  really unmaps that name. An unmapped name **blocks** its row; it is never guessed.
- **Legacy fallback:** before multi-property support there was one flat `zuri_room_map`
  setting. A venue with no saved map that IS Zuri reads that old setting, then falls back
  to `ZURI_ROOM_MAP`. Every other property starts empty. Don't delete that fallback until
  production has re-saved Zuri's map through the new UI.
- **A mapped room in another property is blocked, not imported** (`import_resolve_row()`
  compares `rooms.venue_id` to the chosen venue) — importing it would drop a booking into
  the wrong property's calendar.
- **"No website room for slug X" has two causes** and the message distinguishes them: the
  room does not exist, or it exists with **no active unit** (`import_room_unit()` joins
  `units ON is_active = TRUE`). The second is the one that wastes debugging time.
- Test: `php tests/booking_import_logic.php`. Note one pre-existing failure on a DB with
  no `zuri-buyout` room — the seed map references a slug that install may not have.

### Nightly rates — per room, edited on the property and the room
Rate overrides live in `rates` (`room_id, date_from, date_to, price_amount, label`) — **no
migration**, the table predates the editors. Helpers in **`includes/rates.php`**.
- **`date_to` is EXCLUSIVE** — the checkout morning, not the last night. Both forms label it
  "To (last night)" and `rates_ranges_from_post()` adds the day; the override tables render
  `date_to - 1 day` so a rate reads back the way it was typed. Never reinterpret the column —
  it would silently reprice every stored override.
- **One resolver, and it is the only one.** `rates_nightly_map()` decides what a night costs.
  There were **three** copies of that loop before this work (`room_stay_quote()` in
  `includes/db.php`, plus two in `api/check-availability.php` — the guest booking widget's own
  endpoint); all three now call it. Don't reintroduce a second nightly loop: two summations
  over one map is how two guests get quoted two prices for the same night. `admin/gantt.php`
  keeps its own *day-tinting* loop, which is a "does any rate touch this day" highlight and
  never resolves a price.
- **Two date validators, deliberately different.** `rates_ymd()` is strict and guards
  **writes** — a malformed date there is a bug worth surfacing. `rates_window_ymd()` repairs a
  recoverable **read** window (`2099-9-1` → `2099-09-01`). The split is load-bearing: every
  comparison in the file is a STRING comparison, chronological only for zero-padded dates, and
  `'2099-9-01'` sorts **above** `'2099-09-15'`. An un-normalised window made `max()`/`min()`
  clamp to the wrong day and quoted a guest override nights the rate never covered.
- **`room_stay_quote()` returns `nights => 0` for a window it cannot parse** — that is "not a
  quote", and callers must check it before displaying a price. Neither alternative is safe:
  summing an empty map quotes a **free stay**, and `strtotime()` returns `false` for garbage
  (which becomes epoch), so an unguarded night count runs to ~47,000. `api/check-availability.php`
  is public, so it validates and 422s before quoting at all.
- **Writes leave no overlaps.** `rates_apply_ranges()` merges the submitted ranges, then
  `rates_clear_span()` trims, splits or deletes whatever already covers those nights. The
  **spans** case must be tested before the two one-sided cases — a row covering the new range on
  both sides satisfies both, so checking those first swallows it instead of splitting around it.
  It opens a transaction only when the caller has not (`db()->inTransaction()`); the tests wrap
  everything in one they roll back, and PDO/pgsql cannot nest. Reads still resolve
  `created_at DESC, id DESC` so legacy overlapping rows keep behaving as they did.
- **`rates_apply_ranges()` does NO scoping** — the caller must verify the room belongs to the
  acting account. `rates_delete()` does take a venue scope. That asymmetry is intentional but
  easy to trip over.
- **Editing is owner-only** (`admin/venue-edit.php` and `admin/room-edit.php` Rates tabs, both
  already `require_owner()`). `admin/rates.php` is read-only, `require_login()` + scoped by
  `admin_venue_ids()`, with `?venue=` validated against the account's own list — reception can
  quote from it. The Gantt's Price Overrides form is **gone**; don't reinstate it. It was
  `require_login()` only, so it let reception set prices, against the rule that pricing is owner
  business.
- Partials: `includes/rate-form.php` (N ranges, one price, one label) and
  `includes/rate-calendar.php` (read-only, **3 compact months by default** via `$rc_months`;
  an unset room price renders "—", never "0", and compact cells abbreviate thousands as `61.5k`
  with the full figure in the tooltip). The calendar resolves the WHOLE span in one
  `rates_nightly_map()` call and slices per month in the view — `admin/rates.php` renders one
  per room, so a per-month call would make an 8-room property fire 24 queries. Its CSS is
  emitted once per page (`$GLOBALS['__rc_css_done']`) for the same reason.
  **Prev/Next swap only the calendar**, via `admin/rate-calendar-frag.php` — the admin
  shell only intercepts `a.sidebar__link` / `a[data-shell-link]`, so these links used to do
  a full page load. They are still real URLs and the handler falls back to following them
  when the fetch fails (expired session, offline), so the calendar works with JS off. The
  fragment endpoint re-reads price and currency from the room and re-checks the room
  against `admin_venue_ids()` — the room id comes from the client and is never trusted. Both use the shared `js/datepicker.js` loaded by `admin/_layout.php`. **Range rows are
  cloned from a `<template>`, never from a live row** — `datepicker.js` marks bound buttons
  `data-dp-bound` and skips them, so a clone of a live row would look right and never open.
- Test: `php tests/rates_logic.php` (71 assertions, DB work in a rolled-back transaction).

### Financial reports — unified bookings ledger
Revenue reporting reads from one **`bookings`** table (migration: `add_bookings_finance.sql`, after `add_availability`) that unifies every source — website, OTA, agent, direct. Helpers in **`includes/bookings.php`**; every read is pre-migration-safe (`bookings_supported()` via `to_regclass`).
- **Two writers feed the ledger, both idempotent.**
  - *Website* — `bookings_sync_hold($holdId)` **snapshots gross at confirm time** (`room_stay_quote()` = rate map × nights, frozen) so a later rate edit never rewrites a historical figure. Called from **all three** hold-confirm sites (`admin/hold-action.php`, `admin/holds.php`, `admin/booking.php`); `bookings_mark_hold_cancelled()` runs on the matching decline/cancel paths. Upsert keyed on `hold_id` (unique partial index).
  - *Import* — `bookings_import_upsert($blockId, $ctx)` writes a row **alongside** the availability block the Ezee importer creates, carrying the amount staff entered in the preview. Upsert keyed on `block_id`. Source is `agent` when the row has a travel agent, else `ota`.
- **Money is never summed across currencies.** Rooms price in USD or KES, so aggregates are keyed by currency and the reports page renders one line per currency. `bookings_summarize()` (pure) returns per-currency totals + `by_property`/`by_month`/`by_source`; `bookings_occupancy()` is currency-independent (individual-room nights only — whole-property stays excluded from both sides). Revenue is attributed by **arrival date**.
- **Import amount capture:** `import_extract_rows()` detects an amount/total column (tolerant, prefers an explicit total over a per-night rate); `import_parse_amount()` tolerates symbols/thousands separators. When the sheet has no amount column the **preview lets staff type each amount** (`admin/import-bookings.php`, `name="amount[<i>]"` inside the commit form; 0 = unpriced import).
- **`admin/reports.php`** — `require_manager()`, scoped by `admin_venue_ids()` (`?venue=` validated against the account's own list). Range/property/source filters (styled `.eselect`, no native inputs), per-currency KPI blocks (revenue/ADR/RevPAR/nights), by-property/source/month tables, and a `?export=csv` streaming the same rows. Sidebar "Reports" group is owner + manager (`$__navReports`).
- **Historical backfill:** `bin/backfill-bookings.php` (idempotent; `--dry-run` counts only) snapshots every already-confirmed hold into the ledger, so reports aren't empty for bookings confirmed before this shipped. Run once per DB after the migration.
- Tests: `php tests/reports_logic.php` (pure aggregators + a ledger round-trip in a rolled-back transaction) and `tests/booking_import_logic.php` (amount parsing + ledger capture). **Apply `add_bookings_finance.sql` to prod RDS separately** (via `/admin/migrate.php`) — local Neon and prod are distinct.

### Property page copy — DB-driven, admin-editable
The eyebrow tagline + About heading/body on each property page render **DB-first** from `venues` (`tagline`/`about_heading`/`about_body`, migration `add_venue_content.sql`), edited in Admin → Properties → *property* → **Content**. `includes/venue-about.php` renders `about_heading`/`about_body` when set, else the page's built-in `$va_*_fallback` (so a blank DB never blanks the page). `ts_venue_text()` reads one field with a fallback; `ts_venue_content()` and the `venue_content_supported()` / `venue_stay_supported()` guards are pre-migration-safe. **`about_heading` stores emphasis as `*asterisks*`, not `<em>`** — the pipeline runs `ts_emphasis(e($value))`; storing raw `<em>` would render escaped. Seed the starting copy from the page fallbacks with `db/seeds/seed_venue_content.php` (fills blanks only; `--force` overwrites) — once a field is set the admin form is the source of truth. `save_content`/`save_stay` in `admin/venue-edit.php` are guarded by the `*_supported()` checks so a DB missing the columns shows a banner instead of 500-ing.

### Video feature sections — one partial, click-to-load
`includes/video-feature.php` renders the "watch the film" section used on `index.php` (after the property cards) and `sustainability.php`. Config via `$vf_video_id` / `$vf_heading` / `$vf_sub` / `$vf_caption` / `$vf_title` / `$vf_poster_alt` / `$vf_class` set before the include.
- **Click-to-load facade.** Nothing loads from YouTube on page view except the poster thumbnail; the iframe is injected on click, pointed at `youtube-nocookie.com` with **`cc_load_policy=0`** (captions off), `iv_load_policy=3`, `rel=0`, `modestbranding=1`, `playsinline=1`. Don't replace it with a static iframe — that reinstates third-party cookies and the page weight on every visit.
- CSS/JS emit **once per page** even when the partial is included twice (`$GLOBALS['__vf_assets_done']`), and the play handler is delegated, so multiple instances work.
- `$vf_heading` is echoed **raw** (it carries `<em>`) — page-authored config, never user or DB input. Everything else goes through `e()`.
- `vfeat--rule-top` / `vfeat--rule-bottom` add the hairline needed when a neighbouring section is also white.

### AI availability & price assistant — tool-calling, read-only, provider-swappable
An internal, staff-facing assistant that answers "what's free for N pax from X to Y and the price?" by **calling the app's own live helpers**, not by reading a snapshot. **Tool calling, not RAG** — RAG can't do date-overlap math or return a live price; the model only extracts dates/pax and phrases the answer, every number comes from PHP. Plan: `docs/superpowers/plans/2026-09-08-ai-availability-assistant.md`. Phase 1 only (admin tool layer); RAG for prose = Phase 2, guest widget = Phase 3.
- **One pricing path, always.** The tools call `room_stay_quote()` / `ts_search_availability()` — the SAME canonical resolvers the booking widget and `admin/rates.php` use. **Never add a second nightly loop** for the AI (two summations over one rate map = two prices for one night). `nights===0` from the resolver is "not a quote" and surfaces as a structured error, never a $0 stay. This is the acceptance bar: an AI quote must equal the widget's quote for identical dates (asserted in `tests/assistant_tools.php`).
- **Read-only end to end.** Every tool is a lookup — `list_properties`, `check_availability`, `quote_stay` in `includes/assistant-tools.php`. There is **no write tool**; the AI can quote but never books/holds (booking stays in the existing hold flow). Don't add a mutating tool to this layer.
- **Provider behind ONE adapter.** `includes/ai.php` is the only file that knows the vendor (raw cURL, matching `ghl.php`/`mail.php` — no composer/SDK in this project). Both **Claude** (Anthropic Messages API) and **OpenAI** (Chat Completions, function calling) backends are wired up — `ai_claude_loop()` / `ai_openai_loop()`, same contract, different wire format; `gemini` is the remaining "not implemented" branch. `chat_with_tools()` runs the bounded (`AI_MAX_ITERATIONS`) tool-use loop; `ai_assistant_supported()` gates every surface so a deploy with no key **hides** the feature instead of 500-ing. Pick vendor with `AI_PROVIDER` (`claude` default | `openai`); the key resolves from `AI_API_KEY`, else the vendor-native `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` — so you can keep both keys in env and just flip `AI_PROVIDER`. Default model: `claude-opus-5` (claude) / `gpt-4o-mini` (openai), override with `AI_MODEL`. Both `ai_claude_request()`/`ai_openai_request()` are `function_exists`-guarded so tests stub them (see `tests/assistant_loop.php`). **Local Windows dev note:** PHP cURL needs a CA bundle for the HTTPS call — run the dev server with `-d curl.cainfo=<path> -d openssl.cafile=<path>` (Git Bash ships one at `C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt`); prod Linux has a system store, so no flag needed.
- **Scoped like the rest of admin.** `api/assistant.php` is session-authed, CSRF-checked (token in the JSON body — `verify_csrf()` reads `$_POST` which a JSON fetch doesn't populate), and passes `admin_venue_ids()` into every tool (`null` = owner/all). A scoped manager's assistant sees only their properties. Audience = `require_frontdesk()` (owner/manager/reception/front-desk staff; ops & security excluded).
- **Nairobi-local dates.** The system prompt hands the model today's Nairobi date; the tools validate with `rates_window_ymd()` (same read-window validator as `api/check-availability.php`) and ask a clarifying question on ambiguous/invalid dates rather than guess.
- UI: `admin/assistant.php` + `admin/assets/admin-assistant.js` (a no-native-chrome chat panel; renders a structured availability/quote card alongside the prose). Nav link in Operations, gated by `ai_assistant_supported()`. Test: `php tests/assistant_tools.php` (pure logic + adapter fail-soft always; DB-backed wrapper asserts run when a DB is reachable, else SKIP — the model call is never exercised).

### AI assistant — descriptive layer (RAG, Phase 2)
The SAME assistant also answers **prose** questions ("what's the villa like?", "amenities?", "activities near Watamu?", "check-out time?") via retrieval-augmented generation, added as a **fourth tool** alongside the three factual ones. The split is load-bearing: **exact facts (availability, price) stay tool-calls; only descriptions are RAG.** The embeddings table holds prose and **never a price** — the two paths cannot cross. Migration: `add_content_embeddings.sql` (after `add_bookings_finance`). Helpers in **`includes/assistant-rag.php`**; every read is pre-migration-safe (`rag_supported()`).
- **pgvector, in the same Postgres.** `content_embeddings` (one row per chunk: `source`/`source_id`/`venue_id`/`title`/`chunk_index`/`chunk_text`/`content_hash`/`embedding vector(1536)`) with an HNSW cosine index. It is a **derived cache** — safe to TRUNCATE and rebuild from the live DB. Confirmed available on Neon (dev) and RDS; `rag_supported()` = table exists **and** an embeddings key is set, so a deploy missing either just omits the descriptive layer (NFR4).
- **Embeddings are OpenAI, independent of the chat provider.** `ai_embed()` in `includes/ai.php` calls OpenAI `text-embedding-3-small` (**1536 dims — the DB column is fixed to this; changing model ⇒ change the migration**). It does **not** follow `ai_provider()`: Anthropic has no first-party embeddings API, and the OpenAI key is funded — so you can run chat on Claude and still embed on OpenAI. Key resolves `AI_EMBED_KEY` → `OPENAI_API_KEY` → `AI_API_KEY` (when provider=openai). `ai_embed_request()` is `function_exists`-guarded for test stubbing, like the chat requests.
- **Reindex is a CLI, idempotent.** `bin/reindex-content.php` gathers editable prose (venue about/stay copy, room descriptions + features + FAQs, tours, sustainability), chunks it (`rag_chunk`, ~1200 chars, paragraph-aware), embeds **only chunks whose `content_hash` changed**, and prunes removed docs/chunks. Re-run after editing copy (`--dry-run` to preview). On-save/scheduled reindex is a **documented follow-up**, not wired yet — copy edits don't reach the assistant until a reindex runs. Local Windows dev needs the same CA-bundle `-d curl.cainfo=…` flag as the chat call.
- **Retrieval is read-only + venue-scoped.** `rag_search()` embeds the query and orders by cosine distance (`<=>`), dropping matches below `RAG_MIN_SCORE` (0.20) so the model can honestly say "I don't have that" (FR5). Scope mirrors the tool layer: global rows (`venue_id IS NULL`, e.g. tours) are always visible; a scoped account additionally sees only its own venues. The tool is `search_property_info`, wrapped by `assistant_tool_search_info()`.
- **Wiring stays opt-in so Phase 1 is untouched.** `assistant_tool_definitions($withRag)` / `assistant_system_prompt($scope, $withRag)` take a flag; `api/assistant.php` passes `rag_supported()`. With no args they're still the 3-tool Phase-1 shape (that test asserts `count===3`). Test: `php tests/assistant_rag.php` (chunking/cleaning/wiring always; embed mocked; DB round-trip — upsert + search + scope + relevance floor — in a rolled-back transaction).

### AI assistant — public guest concierge (Phase 3)
The SAME read-only tool+RAG engine, exposed to website visitors as a branded chat at **`/concierge.php`** → **`api/concierge.php`**. It quotes and describes; it **never books** — the answer card hands off to the property page's "Request to Book" (the existing 24h-hold flow). Helpers in **`includes/concierge.php`**; migration `add_concierge_log.sql` (after `add_content_embeddings`); pre-migration-safe (`concierge_supported()` / `concierge_log_supported()`).
- **Same engine, guest persona.** The endpoint runs `chat_with_tools()` with `assistant_system_prompt(null, rag_supported(), 'guest')` and the same tools at **null scope = all PUBLISHED venues**. The `'guest'` audience only swaps the persona + booking hand-off + an on-topic guard; all the hard rules (one price path, Nairobi dates, RAG-for-prose, never-invent) are shared with the staff prompt — don't fork them.
- **Public-form guardrails (NFR6), enforced in `api/concierge.php`.** (1) **CSRF** — token in the JSON body, and the session token must be **non-empty** before comparing (`hash_equals('','')` is true, so a cookie-less bot would otherwise pass — the check requires a real page load that minted the token). (2) **Turnstile fail-closed** via `verify_captcha()`, required on the **first** message per session then trusted for `CONCIERGE_TURNSTILE_TTL` (good chat UX; the widget hides after the first success). (3) **IP rate limit** — `concierge_rate_limited()` counts `concierge_log` rows per `client_ip`/window (fails OPEN on a read error). (4) **Honeypot** `website` field → accept-and-ignore without spending a model call.
- **`concierge_log` does double duty:** rate-limit fuel **and** observability (one row per answered turn: question, tools used, ok) — NFR7. `concierge_log_turn()` is best-effort; a logging failure never breaks the reply.
- **Discoverability is a deploy step, not code.** `concierge.php` is not yet linked from the site nav (avoids touching the DB-driven mega-menu / hardcoded fallback). Add a link via Admin → Site Menu (or a CTA) after deploy. Deliberately **NOT a floating global bubble** — bottom-right is taken by the LeadConnector widget (see the WhatsApp note), so the concierge is a dedicated page. Test: `php tests/concierge_logic.php` (guards, guest-vs-staff prompt, Turnstile session stamp, rate-limit + logging round-trip rolled back).

### Capacity-aware search & multi-room combinations
Guest **capacity is the source of truth** for search and the AI. **No migration** — capacity already lives on `rooms.capacity` (max occupancy of ONE unit of that room type; a room with N active `units` provides N × capacity beds; entire-place rooms carry the whole-property capacity). **A NULL/0 capacity is "unknown"** — such a room is never assumed to fit a party and is skipped by the combination search (matches the long-standing `$cap > 0` guard).
- **The brain is `ts_property_configurations($venue, $ci, $co, $guests, ?$rooms)`** in `includes/db.php` (beside `ts_search_availability`). Returns `singles` (individual rooms whose one unit fits: `capacity >= guests`, free), `entire` (whole-property option when free), `combos` (ranked multi-room suggestions), and `max_capacity` (largest party the window can host). **`combos` are computed ONLY when no single room fits** the party — but they ARE offered even when the whole place is free (a combo can be cheaper than the buyout), so both show.
- **`count_available_units($room_id, $ci, $co, ?$room)`** is the count sibling of `find_available_unit()` — same whole-villa/by-room mutual exclusion (a conflicting sibling booking → 0), needed for multi-unit room types (Maya Ilai villa/studio, 8 units each). Pass the room row in to skip a re-fetch.
- **Ranking (`ts_rank_combos`, pure):** (1) fewest total units, (2) least wasted capacity (`Σcap − guests`), (3) lowest total price. Bounded DFS (≤4 distinct rooms; per-room units capped at `ceil(need/cap)`), returns the top 3. **ONE pricing path:** each room's figure is `room_stay_quote()` × units_used — never a second nightly loop. A candidate that would **mix currencies is dropped** (money is never summed across currencies).
- **`ts_search_availability()` is additive:** its existing `rooms`/`from`/`count` output is unchanged for small parties; it now also attaches `configurations`, bumps `count` to ≥1 when a combo-only match exists (so a 7-guest group no longer reads "No availability"), and folds the cheapest combo into `from`. `search.php` renders a **"For N guests we suggest"** combo card (rooms + per-room/total price + "Request these rooms" → property page; true atomic multi-room hold is Phase 3/v2).
- **The AI uses the same resolver.** `assistant_tool_check_availability()` surfaces `suggested_combinations` + `max_capacity` per property; the `check_availability` tool description and a **shared** system-prompt rule tell the model to offer only the combinations the tool returns and never invent one or a price. Staff (`admin/assistant.php`) and guest (`api/concierge.php`) both inherit it.
- **Phase 0 data:** `db/backfill_room_capacity.sql` (idempotent; Sandbox → 8; audit query for other unset published rooms — apply to prod RDS separately). `admin/room-edit.php` shows a **non-blocking** warning when a published room has no capacity (copy only — capacity stays optional). Tests: `php tests/capacity_search_logic.php` (pure ranking + DB configurations; combo total == Σ per-room quotes) and the extended `tests/assistant_tools.php`.

## File Map

| File | Purpose |
|------|---------|
| `includes/db.php` | DB connection, `client_ip()`, `e()`, `site_url()`, `setting()`, availability helpers |
| `includes/head.php` | SEO meta, OG, structured data, conditional CSS/JS loading |
| `includes/header.php` | Site nav |
| `includes/footer.php` | Footer, WhatsApp float button, cookie consent banner |
| `includes/turnstile.php` | Cloudflare Turnstile verification (`verify_captcha()`) |
| `includes/mail.php` | Email dispatch via Resend API; `send_admin_reply()` Reply-To = inbound address when set |
| `includes/inbound-mail.php` | Inbound guest-reply helpers — SNS signature verify, MIME→text extraction, de-dupe log (pre-migration-safe) |
| `api/inbound-mail.php` | SES→SNS inbound webhook — auth, `[TSR-<id>]` HMAC match, threads a `guest_reply` |
| `includes/auth.php` | Admin login, session, rate limiting |
| `includes/tracking.php` | First-touch UTM capture in session |
| `includes/form-enquiry.php` | Shared booking enquiry form widget |
| `api/submit-enquiry.php` | Room booking hold submission |
| `api/submit-contact.php` | General contact/tour enquiry |
| `api/submit-agency.php` | Trade/agent enquiry |
| `api/sync-ical.php` | Pull OTA iCal feeds, import availability blocks |
| `admin/gantt.php` | Gantt calendar + iCal sync |
| `includes/property-gallery-data.php` | `pg_gallery()` — memoized venue-slug → gallery-image resolver, shared by both gallery partials |
| `includes/property-gallery.php` | Top hero gallery partial + the shared `pgOpenLb` lightbox |
| `includes/property-photo-grid.php` | Bottom "Photo Gallery" section partial — DB-driven, caps at 15 + "See more" → gallery.php |
| `gallery.php` | Shared full property gallery (`?venue=<slug>`) — DB-driven; legacy `*-gallery.php` 301 here |
| `includes/nav-data.php` | Mega-menu read model + desktop/drawer renderers (pre-migration-safe) |
| `admin/nav-menu.php` | Site Menu builder (owner-only) — items/columns/links CRUD + reorder + thumbnails |
| `includes/menu.php` | Restaurant menu helpers (DB-driven, per property, pre-migration-safe) |
| `menu.php` | Public digital menu page (`?m=<slug>`) |
| `admin/menus.php` · `admin/menu-edit.php` | Menu manager (list + editor, manager-scoped) |
| `includes/reservations.php` | Reservation helpers (request model, pre-migration-safe) |
| `reserve.php` · `api/submit-reservation.php` | Public "Reserve a Table" form + PRG submit handler |
| `admin/reservations.php` | Reservation manager (dashboard + confirm/cancel, manager-scoped) |
| `includes/sustainability.php` | Live metric helpers — accrual, formatting, re-baselining (pre-migration-safe) |
| `admin/sustainability.php` | Live metrics editor (owner-only) — reading, rate, cap, small print |
| `includes/rates.php` | Nightly rate helpers — merge, resolve, trim/split writes, scoped delete |
| `includes/rate-form.php` · `includes/rate-calendar.php` | Multi-range rate entry + read-only month grid partials |
| `admin/rates.php` | Site-wide read-only rates calendar (scoped, reception-visible) |
| `includes/bookings.php` | Unified bookings ledger — confirm/import writers, pure report aggregators, occupancy (pre-migration-safe) |
| `admin/reports.php` | Financial reports (revenue/ADR/RevPAR/occupancy, per currency, CSV export; manager-scoped) |
| `includes/video-feature.php` | Shared click-to-load video section (home + sustainability) |
| `includes/ai.php` | AI provider adapter — the ONE file that knows the vendor; `chat_with_tools()` bounded tool-use loop, `ai_assistant_supported()` |
| `includes/assistant-tools.php` | Read-only assistant tool layer — `check_availability`/`quote_stay`/`list_properties` wrappers, schemas, system prompt (one pricing path, scoped) |
| `api/assistant.php` | Assistant endpoint (JSON) — session-authed, CSRF, `admin_venue_ids()`-scoped; runs the loop, returns `{answer, tool_result}` |
| `admin/assistant.php` · `admin/assets/admin-assistant.js` | Admin availability-assistant chat panel (read-only; renders a structured card) |
| `includes/assistant-rag.php` | RAG descriptive layer (Phase 2) — `rag_supported()`, chunking, `rag_reindex()`, `rag_search()` (pgvector, pre-migration-safe) |
| `bin/reindex-content.php` | CLI: rebuild `content_embeddings` from live prose (idempotent, `--dry-run`) |
| `includes/concierge.php` | Guest concierge helpers (Phase 3) — support guards, IP rate limit, turn logging, Turnstile session stamp |
| `concierge.php` · `api/concierge.php` · `js/concierge.js` | Public guest concierge page + endpoint + chat UI (read-only, quote-only, Turnstile + rate-limit + CSRF) |
| `css/main.css` | Global stylesheet (brand tokens, layout, components) |
| `js/booking-widget.js` | Booking date picker widget |
| `manifest.json` | PWA web app manifest |

## Improvements Applied (from Claris African Experience guide — June 2026)

### Security (Critical)
- `client_ip()` helper added to `includes/db.php`; all `$_SERVER['REMOTE_ADDR']` replaced across api/ and includes/
- Turnstile fail-closed: `TURNSTILE_SITE_KEY` set but secret missing now returns `false` instead of `true`
- iCal sync secret moved from URL query param to `Authorization: Bearer` header

### Performance & SEO
- Google Fonts loaded non-blocking (preload pattern) in `includes/head.php`
- Asset cache busting (`?v=filemtime()`) on all CSS/JS in `includes/head.php`
- `manifest.json` created — site installable as home-screen app on Android
- Apple touch icon `<link>` added to head

### Conversion & UX
- Cookie consent GDPR banner added to `includes/footer.php`
- Hero ghost button mobile contrast fix in `css/main.css` (readable over light hero photos on mobile)
- WhatsApp floating button intentionally NOT added — conflicts with LeadConnector widget bottom-right
- Form success modal (`window.showSuccessModal()`) added via `includes/footer.php` — used by booking widget and contact form
- 24h countdown timer in booking success modal (via `js/booking-widget.js` + `showSuccessModal(…, true)`)
- Auto-scroll to booking widget on successful hold (`wrap.scrollIntoView`)
- Guest reviews section: `includes/room-reviews.php` — added to the 3 main booking-widget pages
- Cross-sell tours section: `includes/cross-sell-tours.php` — queries `tours` DB table, gracefully hidden when DB unavailable; added to 3 main booking-widget pages
- Admin password reset flow: `admin/forgot-password.php` + `admin/reset-password.php`; token stored in `settings` table (`pwd_reset_<md5(email)>`, 1h expiry); email via Resend; link added to `admin/login.php`

## Still Pending
- TripAdvisor listing — claim at tripadvisor.com/GetListedNew (2–5 day approval). Badge already in trust bar on `index.php` and placeholder `sameAs` comments in `includes/schema.php` — just swap in the real URL once approved.
- Google Search Console — submit sitemap after SEO changes deploy
- Per-room reviews DB table (future — currently hardcoded testimonials in room-reviews.php)
- ~~Restaurant reservations (Phase 3)~~ — **DONE & shipped** (request model; commit `13a13fc` on master). Migration applied, 32/32 tests pass, full smoke test passed.

## Environment Variables Required
```
DATABASE_URL=         # PostgreSQL connection string
APP_URL=              # https://tribalsand.com (no trailing slash) — page/canonical URLs, site_url()
ASSET_URL=            # OPTIONAL asset origin for asset_url() (images/PDFs). Defaults to https://tribalsand.com. Set to the CDN/S3 origin at cutover.
TURNSTILE_SITE_KEY=   # Cloudflare Turnstile public key
TURNSTILE_SECRET_KEY= # Cloudflare Turnstile secret key
ICAL_SYNC_SECRET=     # Random secret for iCal sync endpoint
FX_SYNC_SECRET=       # Random secret for the display-currency rate sync endpoint (api/fx-sync.php)
RESEND_API_KEY=       # Resend.com API key for emails
MAIL_FROM=            # noreply@yourdomain.com (must be Resend-verified domain)
INBOUND_MAIL_ADDRESS= # OPTIONAL. The SES inbound address (reply@mail.tribalsand.com). Set = guest replies thread automatically (api/inbound-mail.php); unset = replies go to the reservations mailbox for manual paste. This env var IS the feature's on-switch.
INBOUND_MAIL_SNS_TOPIC_ARN= # OPTIONAL. Pin the inbound webhook to one SNS topic ARN (defence-in-depth on top of the SNS signature check).
AI_API_KEY=           # AI assistant key. Optional if the vendor-native key below is set. Unset (and no vendor key) = feature hidden.
ANTHROPIC_API_KEY=    # Vendor-native key used when AI_PROVIDER=claude and AI_API_KEY is unset
OPENAI_API_KEY=       # Vendor-native key used when AI_PROVIDER=openai and AI_API_KEY is unset
AI_PROVIDER=          # OPTIONAL: claude (default) | openai (both wired up) | gemini (not implemented)
AI_MODEL=             # OPTIONAL: model id override (default claude-opus-5 for claude, gpt-4o-mini for openai)
AI_EMBED_KEY=         # OPTIONAL RAG (Phase 2) embeddings key; falls back to OPENAI_API_KEY, then AI_API_KEY (if provider=openai)
AI_EMBED_MODEL=       # OPTIONAL: embedding model override (default text-embedding-3-small — 1536 dims, MUST match the DB column)
```
