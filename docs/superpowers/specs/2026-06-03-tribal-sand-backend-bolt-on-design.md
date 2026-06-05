# Tribal Sand — Backend Bolt-On Design

> Date: 2026-06-03
> Status: Approved (design). Next step: implementation plan (writing-plans).

## Goal

Give the existing **Tribal Sand** website (currently static PHP brochure pages + GoHighLevel lead handoff + external `book.tribalsand.com`) a real application backend by **bolting on the Claris / 7island backend** (PHP 8.2 + PostgreSQL). The backend must power three things:

1. **Real booking engine** — on-site **request-to-book** (no online card payments): live availability, 24h soft holds, admin confirm/decline, iCal/Airbnb-Booking.com sync. This **replaces** `book.tribalsand.com`.
2. **Admin panel / CMS** — manage venues, rooms, units, prices, images, availability, holds, rates, iCal feeds, and the lead inbox.
3. **Lead database** — store every enquiry/contact/agency submission in Postgres with an admin inbox, **while still mirroring leads to GoHighLevel** (existing sales pipeline untouched).

## Hard constraint (non-negotiable)

**Every existing Tribal Sand URL must be preserved byte-for-byte.** The live site is ranking on Google; URL or page-content changes risk those rankings. No slug changes, no page moves, no content rewrites on existing pages.

## Decisions locked in

| Decision | Choice |
|---|---|
| Integration approach | **A — Bolt-on / hybrid.** Keep all ~90 existing pages, URLs, and design. Add the backend underneath; light up only dynamic surfaces. |
| Booking model | **Replace** `book.tribalsand.com` with on-site **request-to-book**. No card payments online. |
| Leads / CRM | **Mirror to both** — Postgres admin inbox **and** GoHighLevel. |
| Hosting | **Render** (Docker `php:8.2-apache`) + **Render managed PostgreSQL** + **Render Cron** for hold expiry. |
| Data model | 7 venues (My Amani is multi-room; others mostly whole-villa). Confirmed correct. |
| Repo / deploy | **Work in the current folder first** (build + test locally); decide on GitHub repo + Render wiring at deploy time. |

## Source material

- Current site: `/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand`
- Backend source: `/Users/patrikgiuliana/Desktop/CLAUDE CODE/ClarisAfricanExperience` (cloned from `7island`)
- Backend reference docs: `ClarisAfricanExperience/tech-stack.md`, `ClarisAfricanExperience/HANDOFF.md`

---

## 1. Architecture — the layering

Tribal Sand stays exactly what it is on the surface. The Claris backend slides in underneath; only dynamic surfaces change.

```
Existing presentation (UNCHANGED)            New backend (PORTED FROM CLARIS)
──────────────────────────────              ────────────────────────────────
index.php, maya-kobe.php, my-amani-*.php,    includes/db.php (PDO → PostgreSQL)
zuri.php, enkare-bofa.php, sandbox.php,      includes/auth.php, booking.php, mail.php,
maya_ilai.php, tribal-dunes.php,             tracking.php, storage.php, turnstile.php
blog/activity/guide pages,                   includes/ghl.php  (NEW — ported GHL push)
includes/head.php, header.php,
footer.php, schema.php                       api/submit-enquiry.php, submit-contact.php,
        │                                    submit-agency.php, check-availability.php,
        │  forms + booking widget            ical.php, sync-ical.php
        ├───────────────────────────────►   admin/  (full panel)
        │                                    db/ schema + migrations + NEW venues migration
        └───────────────────────────────►   Dockerfile, .htaccess (extensionless rewrite),
                                             bin/ical-expire-holds.php (cron)
```

**What actually changes in the existing front-end (the only changes):**
- Contact / trip-builder / for-agents forms POST to the new `/api/` endpoints instead of `ghl-submit.php` / `process-agent-form.php`.
- Property and room-type pages gain an availability calendar + a "request to book" form.
- "BOOK NOW" CTAs repoint from `https://book.tribalsand.com/...` to the on-site request-to-book flow.

Everything else (markup, copy, CSS, includes, URLs) is untouched.

### Backend modules reused verbatim from Claris (do not edit their logic)
`includes/db.php`, `auth.php`, `booking.php`, `tracking.php`, `storage.php`, `turnstile.php`, `mail.php`; all of `api/`; all of `admin/`; `db/` schema + migrations; `Dockerfile`; `bin/ical-expire-holds.php`. Only **config (env), copy strings, branding, and the new `venues` layer** are adjusted.

---

## 2. Data model — multi-property

Claris's schema is single-hotel (`rooms` + `units`). Tribal Sand has 7 venues, so we add **one layer** via a new migration, without altering existing Claris tables' meaning.

- **`venues`** *(NEW table)* — the 7 properties: My Amani, Maya Kobe, Zuri, Enkare Bofa, Sandbox, Maya Ilai, Tribal Dunes. Columns: `id, slug, name, sort_order, is_published`. `slug` matches the existing page (e.g. `maya-kobe` ↔ `/maya-kobe`).
- **`rooms`** *(existing, + new `venue_id` FK)* — room types. My Amani has ~9 (`my-amani-premium-sea-view-twin`, `my-amani-superior-garden-view-single`, …); Maya Kobe has main house + cottages; whole-villa venues have a single room. `slug` matches the existing room-type page.
- **`units`** *(existing)* — individually bookable instances per room type (usually 1). Units hold availability and own the `feed_token` for iCal.
- **`holds`, `availability_blocks`, `rates`, `ical_feeds`** *(existing)* — booking engine, unchanged.
- **`submissions`** *(existing)* — every lead (`type` ∈ enquiry / contact / agency).
- **`settings`, `admin_users`, `login_attempts`, `admin_audit_log`** *(existing)* — unchanged.

**Unused for now (YAGNI):** Claris's `tours` and for-sale `properties` tables ship with the backend but get **no UI and no data**. Tribal Sand's activities/excursions pages stay static. (Note: if activities ever become bookable/CMS-managed, the `tours` table is a natural fit — explicitly out of scope here.)

**Seeding:** populate `venues` / `rooms` / `units` with slugs matching the live pages, plus prices and unit counts. **Page content stays hand-coded in PHP** — the DB only stores what's needed for availability, pricing, holds, and leads. Booking widgets resolve by slug via the existing `fetch_room_by_slug()` / venue lookups.

---

## 3. URL & SEO preservation

This is the highest-risk area; it is handled explicitly.

- **Every existing URL stays identical.** No slug changes, no moves, no rewrites of existing page bodies or meta.
- The live host currently serves **extensionless URLs** (`/maya-kobe`), but the repo `.htaccess` does **not** strip `.php` — the host does. On Render we own Apache, so we **add an extensionless rewrite to `.htaccess`**: if the request has no extension and `<path>.php` exists, internally rewrite to it. This replicates current behavior exactly.
- Keep existing 301s: `http`→`https`, `www`→non-`www`, legacy `/tribalsand-blog-tribal-dunes.html`→`/tribal-dunes.php`, and `/interactive-site-map/`→`/interactive-site-map.php`. Keep the `/includes/` deny rule.
- Canonicals (`$page_url` in `includes/head.php`) stay extensionless and unchanged.
- `sitemap.xml`, `sitemap-tribalsand.xml`, `robots.txt`, `llm.txt` unchanged.
- **Staged cutover:** deploy to a temporary Render URL → QA that every URL returns HTTP 200 with byte-identical content + meta + canonical → only then repoint DNS, keeping the old host as a rollback for a grace period.
- The dev `router.php` (local PHP built-in server + image proxy) stays **dev-only**; production routing is Apache `.htaccess`.

---

## 4. Lead & booking flows

### Leads (mirror to both)
A new `includes/ghl.php` holds the GoHighLevel push logic currently inline in `ghl-submit.php` (contact upsert, opportunity creation, conversation note, timeline note), with credentials moved to env. Each `/api/submit-*` endpoint runs: validate → rate-limit (5 / 10 min per IP) → hCaptcha → insert into `submissions` (Postgres, shows in admin inbox) → email notification → **also call `ghl.php`**. Nothing in the existing sales pipeline breaks.

Form → endpoint mapping:
- `contact.php` → `api/submit-contact.php` (`type=contact`).
- `trip-builder.php` (multi-step, cross-property) → `api/submit-enquiry.php` as a rich enquiry (**no** specific room hold — it's a trip plan).
- `for-agents.php` / `process-agent-form.php` → `api/submit-agency.php` (`type=agency`).

### Booking (request-to-book — replaces external platform)
On a property / room-type page the guest:
1. Picks dates → live availability via `api/check-availability.php` (returns blocked dates, price/night, total).
2. Submits the request → `api/submit-enquiry.php` with room slug + dates → creates a `submission` + a **24h soft hold** + an `availability_blocks` row for those dates.
3. **Admin confirms or declines** in `/admin/holds.php` → guest receives a signed one-click confirm/decline email (via `mail.php`, using `BOOKING_TOKEN_SECRET`).
4. `bin/ical-expire-holds.php` (Render Cron, every 5 min) releases stale holds and frees their blocks.
5. **iCal sync** (`api/ical.php` export per unit + `api/sync-ical.php` import of OTA feeds) keeps Airbnb / Booking.com from double-booking.

All "BOOK NOW" CTAs that currently link to `book.tribalsand.com` are repointed to this on-site flow.

---

## 5. Admin panel

Reuse the Claris admin near-verbatim — restyled lightly, relabeled for Tribal Sand:

- **Dashboard** — lead KPIs (today / week / total), recent submissions.
- **Submissions inbox** — all leads (enquiry / contact / agency), filterable; single-submission view with tracking + hold status.
- **Venues / Rooms / Units** — CRUD: names, slugs, prices, images, SEO overrides, publish toggle; assign rooms to venues; units per room.
- **Holds** — confirm / decline, Gantt timeline.
- **Availability (Gantt)**, **Rates** (date-range price overrides), **iCal feeds + conflict resolution**.
- **Settings** (notify email, form mode, check-in instructions, etc.), **Audit log**.

Auth is the existing session + CSRF + login-rate-limit. Admin user created via `php bin/reset-admin-password.php` / the Claris admin-creation script.

---

## 6. Hosting, infra & security

- **Render web service** — Docker `php:8.2-apache` with `pdo_pgsql` + `gd` + `mod_rewrite`, `AllowOverride All`. Auto-deploy from GitHub (repo decided at deploy time).
- **Render managed PostgreSQL** — connected via `DATABASE_URL`.
- **Render Cron** — `*/5 * * * *` → `php bin/ical-expire-holds.php`.
- **`.env` (no secrets in code):** `DATABASE_URL`; Resend mail (`MAIL_FROM`, `MAIL_DRIVER`, `RESEND_API_KEY`); `BOOKING_TOKEN_SECRET`; `ICAL_SYNC_SECRET`; **GHL `GHL_API_KEY` / `GHL_LOCATION_ID` / `GHL_PIPELINE_ID` / `GHL_STAGE_ID` (moved out of `ghl-submit.php`)**; hCaptcha keys; `APP_URL` / `SITE_URL = https://tribalsand.com`; optional R2 keys.
- **Security improvement (in scope):** the current `ghl-submit.php` hardcodes the GHL API key, and `process-agent-form.php` hardcodes Gmail SMTP creds — both move to env vars during the port.
- **Images:** keep the existing `/images` directory in the repo, served directly by Apache. Cloudflare R2 optional later. The `router.php` image-proxy remains dev-only.
- **Local dev:** continue using the PHP built-in server (`php -S localhost:8080 router.php`) with a local Postgres (Postgres.app, as in Claris) for backend testing.

---

## 7. Out of scope (deliberate / YAGNI)

- **No** online card payments (instant-paid booking) — explicitly excluded.
- **No** rebuild of static, blog, activity, or guide pages — they stay hand-coded.
- **No** tours module and **no** for-sale-properties module (tables ship unused).
- French homepage (`home-french.php`) + GTranslate widget left as-is.
- No CMS-ification of marketing content (that would be a future Approach-C migration).

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| URL/ranking regression on cutover | Staged Render deploy + 200-check of every URL with identical content/meta before DNS repoint; keep old host as rollback. |
| Extensionless URLs not replicated in Docker Apache | Add + test the `.htaccess` rewrite rule on the temporary Render URL before cutover. |
| Double-booking during external→on-site transition | iCal two-way sync with Airbnb/Booking.com; cron hold-expiry; manual confirm step. |
| Data-model mismatch (whole-villa vs multi-room) | `venues` → `rooms` → `units` covers both; whole-villa venues use a single room + single unit. |
| Losing GHL pipeline | Mirror-to-both; GHL push retained via `includes/ghl.php`. |
| Secrets currently in repo | Move GHL + SMTP creds to env during the port; rotate the exposed GHL key. |

## Success criteria

1. Every existing Tribal Sand URL returns HTTP 200 with unchanged content, meta, and canonical on Render.
2. Contact / trip-builder / agent forms persist to Postgres, email a notification, and still appear in GoHighLevel.
3. A guest can request a booking with live availability; a 24h hold + date block is created; admin can confirm/decline; the guest is emailed.
4. iCal export/import keeps availability in sync with at least one OTA.
5. Admin can manage venues/rooms/units/prices/availability/holds and view the lead inbox.
6. No secrets remain hardcoded in the repository.

---

## Appendix A — Code-grounded findings (verified by reading the Claris source)

These specifics were confirmed by reading the actual backend code and will anchor the implementation plan.

### A1. The public booking widget is self-contained and easy to graft
- `includes/form-availability.php` renders the widget. It only needs `$room` with `slug`, `name`, `price_amount`, `price_currency`, and outputs a `#availCalendar` element carrying `data-slug` / `data-price` / `data-currency`, plus an optional hCaptcha box when `captcha_site_key()` is set.
- `room.js` → `initAvailForm()` is **vanilla JS that builds its own month-grid calendar** — it does **not** require Flatpickr on the public side. It calls `GET /api/check-availability.php?room=<slug>[&check_in&check_out]` for blocked dates + live pricing, and `POST /api/submit-enquiry.php` (JSON) to create the hold.
- Grafting onto a Tribal Sand property/room page = include `form-availability.php` (after setting `$room` from the DB by slug) + port the `bk-*` CSS rules from `styles.css` + include the `initAvailForm` JS block. No other coupling.

### A2. Request-to-book is gated by `rooms.form_mode`
- `api/submit-enquiry.php`: per-room `form_mode` overrides the global `settings.form_mode`. When `'availability'` and a room slug + dates are supplied, it runs `find_available_unit()` → `create_hold_with_block()` (24h hold + `availability_blocks` row) → `send_hold_notification()`. Otherwise it stores a plain enquiry and emails via `send_notification()`.
- So enabling on-site request-to-book per Tribal Sand property = set that room's `form_mode = 'availability'` (added by `add_room_form_mode.sql`).
- The honeypot field is `website`; the captcha field is `h-captcha-response`; rate limit is 5 submissions / 10 min per IP. The trip-builder cross-property lead maps to a `type='enquiry'` submission with **no** `room_id` (rich detail in `message`/`payload_json`).

### A3. New migration & how migrations run
- Add `db/migrations/add_venues.sql`: create `venues` (id, slug, name, sort_order, is_published) + `ALTER TABLE rooms ADD COLUMN venue_id INT REFERENCES venues(id)`. It only depends on `rooms` (from `schema.sql`, which runs first), and its filename sorts after `add_tours.sql` / before `enrich_tours.sql` — safe with the runner.
- Migrations run via `php bin/migrate.php` (executes every `db/migrations/*.sql` in alphabetical order; idempotent via `IF NOT EXISTS`). Bootstrap order: `schema.sql` → `bin/migrate.php` → seed.
- `add_availability.sql` **auto-seeds one `units` row per existing room**, so whole-villa venues (Zuri, Enkare Bofa, Sandbox, Maya Ilai, Tribal Dunes) become bookable with zero extra setup.

### A4. Exact rebranding surface (copy/config only — logic untouched)
- `includes/mail.php`: hardcoded "Claris African Experience", default addresses (`clarisafricanexperience@gmail.com`, `noreply@clarisafricanexperience.com`), and brand color `#47121d` in the HTML emails → Tribal Sand copy/colors.
- `includes/booking.php`: guest-ref prefix `SI-` (Seven Islands) in `make_guest_ref()` / `verify_guest_ref()` regex → e.g. `TS-`.
- `includes/db.php`: `site_url()` default `https://sevenislandswatamu.com` (overridden by `APP_URL` env, but update the literal).
- `storage.php`: default R2 bucket literal `7island-images` (only matters if R2 is enabled).

### A5. Mail driver reality
- `includes/mail.php` uses **Resend** when `RESEND_API_KEY` is set (primary). The `smtp` driver is a **stub** that logs "not implemented" and falls back to PHP `mail()`. → Tribal Sand should use **Resend** for all mail, including the agency/agent notifications that currently go through PHPMailer + Gmail SMTP in `process-agent-form.php`.

### A6. GHL mirror integration point
- A new `includes/ghl.php` holds the ported push logic from the current `ghl-submit.php` (contact upsert → opportunity in the "New Enquiry" pipeline/stage → conversation note → timeline note), with credentials read from env (`GHL_API_KEY`, `GHL_LOCATION_ID`, `GHL_PIPELINE_ID`, `GHL_STAGE_ID`).
- Mirror is wired by adding a single `ghl_push($submission)` call into each of `api/submit-enquiry.php`, `api/submit-contact.php`, `api/submit-agency.php` after the DB insert (additive, non-breaking). Failures are logged, never block the response.

### A7. Full env var set (`.env` local / Render env vars in prod)
`DATABASE_URL` (Render) **or** `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` (local); `MAIL_FROM`, `MAIL_DRIVER`, `RESEND_API_KEY` (+ unused `SMTP_*`); `APP_URL`, `SITE_URL` (= `https://tribalsand.com`); `HCAPTCHA_SITE_KEY`, `HCAPTCHA_SECRET_KEY`; `BOOKING_TOKEN_SECRET` (`openssl rand -hex 32`); `ICAL_SYNC_SECRET` (`openssl rand -hex 20`); **new** `GHL_API_KEY`, `GHL_LOCATION_ID`, `GHL_PIPELINE_ID`, `GHL_STAGE_ID`; optional `R2_ACCOUNT_ID/R2_BUCKET/R2_ACCESS_KEY/R2_SECRET_KEY/R2_PUBLIC_URL`.

### A8. Deploy mechanics (verified)
- `Dockerfile`: `php:8.2-apache` + `pdo_pgsql` + `gd`, `a2enmod rewrite`, `AllowOverride All`, `COPY . /var/www/html/` (existing Tribal Sand pages + backend coexist at web root), writable `logs/` and `assets/img/rooms/`. The extensionless-URL `.htaccess` rewrite will work because `AllowOverride All` + `mod_rewrite` are on.
- Admin user created via `php bin/create-admin.php` (or `bin/reset-admin-password.php`).
- Local dev keeps `php -S localhost:8080 router.php` against a local Postgres; production routing is Apache + `.htaccess`.
