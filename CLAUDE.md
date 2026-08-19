# Tribal Sand — Development Notes

## Tech Stack
- PHP 8.2 — no framework, vanilla PHP only
- PostgreSQL via PDO — prepared statements only (`db_query()` helper in `includes/db.php`)
- Apache on Render (Docker) — auto-deploy from GitHub
- Vanilla JS and CSS — no build system, no npm dependencies
- Local dev: `D:\php84\php.exe -S localhost:8765` (Neon cloud DB via `.env`)

## Key Conventions

### IP detection — always use `client_ip()`
Render runs behind a load balancer. `$_SERVER['REMOTE_ADDR']` is always the proxy IP.
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
