# Tribal Sand — Development Notes

## Tech Stack
- PHP 8.2 — no framework, vanilla PHP only
- PostgreSQL via PDO — prepared statements only (`db_query()` helper in `includes/db.php`)
- Apache on AWS ECS (Docker) — auto-deploy from GitHub via GitHub Actions (build → ECR → ECS)
- Vanilla JS and CSS — no build system, no npm dependencies
- Local dev: `D:\php84\php.exe -S localhost:8765` (Neon cloud DB via `.env`)

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
There is **no external cron service** (the Render cron in `render.yaml` is gone). Scheduled jobs run **inside the app container**: `docker/entrypoint.sh` starts `docker/scheduler.sh` in the background, then execs `apache2-foreground`. The scheduler is a plain bash loop (not crond — crond doesn't inherit container env; a loop launched from the entrypoint does, so the PHP scripts and loopback calls all see `DATABASE_URL`/`*_SYNC_SECRET`). It calls the app's **existing** endpoints over loopback (`127.0.0.1`, `?secret=` param) rather than duplicating logic — never fork the sync logic into the scheduler. Jobs: hold expiry every 5 min (`bin/ical-expire-holds.php`; also runs inline via `expire_stale_holds()`), OTA iCal import hourly (`api/sync-ical.php`, needs `ICAL_SYNC_SECRET` in the ECS env), FX rates daily (`api/fx-sync.php`, needs `FX_SYNC_SECRET`). All three are idempotent, so multiple ECS tasks running the loop is harmless. `*.sh` are pinned to LF (`.gitattributes` + a `sed` CR-strip in the Dockerfile) so Windows checkouts don't break the shebang.

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
Guest↔staff chat updates live via **short polling** (no websockets — Apache/Render has no long-running socket process). Both sides poll a JSON endpoint every 5s (`after=<last id>`) and pause when the tab is hidden. Guest: `GET/POST api/booking-message.php` (ref-authed). Admin: `GET/POST admin/messages-poll.php` (session-authed, `staff_can_hold`-scoped, `require_frontdesk`-gated; JSON POST carries `csrf_token` in the body since `verify_csrf()` reads `$_POST`). Shared helpers in `includes/booking.php`: `fetch_thread_messages_since()`, `message_payload()`, `message_time_label()` — keep initial render and appended bubbles identical. Admin `admin/messages.php` keeps its PRG form as a no-JS fallback; `admin/assets/admin-chat.js` and `js/booking-manage.js` (chat block) enhance it.

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

## File Map

| File | Purpose |
|------|---------|
| `includes/db.php` | DB connection, `client_ip()`, `e()`, `site_url()`, `setting()`, availability helpers |
| `includes/head.php` | SEO meta, OG, structured data, conditional CSS/JS loading |
| `includes/header.php` | Site nav |
| `includes/footer.php` | Footer, WhatsApp float button, cookie consent banner |
| `includes/turnstile.php` | Cloudflare Turnstile verification (`verify_captcha()`) |
| `includes/mail.php` | Email dispatch via Resend API |
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
- ~~Restaurant reservations (Phase 3)~~ — **DONE & shipped** (request model; commit `13a13fc` on master). Migration applied to Neon, 32/32 tests pass, full smoke test passed.

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
```
