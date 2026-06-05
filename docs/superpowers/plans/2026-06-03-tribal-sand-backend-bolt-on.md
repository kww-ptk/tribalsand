# Tribal Sand Backend Bolt-On — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the existing static Tribal Sand PHP site a real PostgreSQL-backed backend (booking engine + admin panel + lead database) ported from the Claris/7island app, without changing any public URL or page design.

**Architecture:** Bolt-on/hybrid. The ~90 existing Tribal Sand pages stay as-is. We drop the Claris backend (`includes/` modules, `api/`, `admin/`, `db/`, `Dockerfile`) in underneath, add a `venues` layer so the 7 properties map onto the room/unit model, light up a request-to-book widget on property pages, and route all leads into Postgres + the admin inbox while keeping the existing GoHighLevel pipeline.

**Tech Stack:** PHP 8.2 (Apache in Docker locally PHP 8.5 built-in server), PostgreSQL via PDO, Resend email, vanilla JS/CSS. Hosting target: Render (Docker + managed Postgres + cron). Source spec: `docs/superpowers/specs/2026-06-03-tribal-sand-backend-bolt-on-design.md`.

---

## Refinement vs spec §4 (leads integration)

The spec said "repoint forms to the new `/api/submit-*` endpoints." While reading the code I found a **lower-churn, lower-risk** path that meets the same goal (leads in Postgres + admin inbox + GHL kept):

- `contact.php` and all property-page enquiry forms already POST a GHL-shaped JSON to **`/ghl-submit`**. We **enrich `ghl-submit.php`** to also insert a `submissions` row (and move its hardcoded creds to env) — **zero front-end changes** for those forms.
- `trip-builder.php` posts client-side to a GHL **webhook** and has a built-in `alsoPostToBackend` switch (currently `false`). We flip it on and point it at a new tiny `/api/trip-builder.php` that stores the lead. GHL behavior unchanged.
- `for-agents.php` posts to `process-agent-form.php`, which `require`s `vendor/autoload.php` (PHPMailer) — **but there is no `vendor/` directory**, so it is currently broken. We repoint that form to the Resend-backed **`/api/submit-agency.php`** and retire the PHPMailer handler.
- The new booking widget posts natively to **`/api/submit-enquiry.php`** (Claris shape) to create holds; that endpoint also mirrors to GHL via the shared `includes/ghl.php`.

All paths insert into the same `submissions` table with a consistent `type`, so the admin inbox is coherent.

## Security actions (must happen)

Two live secrets are committed in the repo and must be **rotated by the site owner** (we cannot rotate them, only stop using them):
- GHL API key `pit-9d84bc02-...` in `ghl-submit.php`.
- Gmail app password `rarosgycpirkjmoa` in `process-agent-form.php`.
This plan moves all secrets to `.env`/Render env vars and removes them from code; the owner must regenerate both credentials after cutover.

## File map

**Create:**
- `.env`, `.env.example`, `.gitignore`
- `includes/ghl.php` — GHL push helper (env creds), shared by `ghl-submit.php` + `api/submit-enquiry.php`
- `api/trip-builder.php` — stores trip-builder leads
- `db/migrations/add_venues.sql` — `venues` table + `rooms.venue_id`
- `db/seed_tribalsand.sql` — venues, rooms, units, settings
- `includes/booking-widget.php` — availability form partial (adapted from Claris `form-availability.php`)
- `js/booking-widget.js` — the public booking calendar/JS (from Claris `room.js` → `initAvailForm`)
- `css/booking.css` — booking widget styles (extracted `bk-*` rules, restyled to TS palette)

**Copy in from Claris (verbatim, then config/brand edits):**
- `includes/`: `db.php`, `auth.php`, `booking.php`, `tracking.php`, `storage.php`, `turnstile.php`, `mail.php`, `form-enquiry.php`
- `api/`: `check-availability.php`, `submit-enquiry.php`, `submit-contact.php`, `submit-agency.php`, `ical.php`, `sync-ical.php`
- `admin/` (whole dir), `bin/migrate.php`, `bin/create-admin.php`, `bin/reset-admin-password.php`, `bin/ical-expire-holds.php`
- `db/schema.sql`, `db/migrations/*.sql`
- `Dockerfile`

**Modify (Tribal Sand):**
- `ghl-submit.php` (env creds + DB insert via `includes/ghl.php`)
- `for-agents.php` (repoint form → `/api/submit-agency.php`)
- `trip-builder.php` (enable backend post)
- `includes/head.php` (conditional booking CSS/JS + hCaptcha when `$page_booking` set)
- property/room pages: `zuri.php`, `maya-kobe.php`, `my-amani.php`, `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php`, `tribal-dunes.php`, and the `my-amani-*` room-type pages (add widget + repoint `BOOK NOW`)
- `booking.php` (add `?ref=` guest-lookup branch)
- `.htaccess` (extensionless URL rewrite)
- rebrand: `includes/mail.php`, `includes/booking.php`, `includes/db.php`, `admin/_layout.php`

**Retire:** `process-agent-form.php` (after for-agents repoint).

**Conventions for "tests":** This is a vanilla PHP site with no test framework. Each task verifies with concrete commands: `php -l` (lint), `curl` against the running dev server, `psql` row checks, `grep`, and preview screenshots. The dev server runs via the already-configured `php -S localhost:8080 router.php` (Preview tool: "PHP Built-in Server"). Local Postgres = Postgres.app on `localhost:5432`.

---

## Phase 0 — Repo, env, local database

### Task 1: Git baseline + ignore rules

**Files:**
- Create: `.gitignore`

- [ ] **Step 1: Create `.gitignore`**

```gitignore
# Secrets & local config
.env

# Logs & runtime
/logs/
*.log

# OS / editor
.DS_Store

# Large local-only archives (not part of the app)
/Archive.zip

# Uploaded room images (R2 or runtime; keep dir, ignore contents)
/assets/img/rooms/*
!/assets/img/rooms/.gitkeep
```

- [ ] **Step 2: Initialise git and commit the current site as the baseline**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
git init
git add -A
git commit -m "chore: baseline — existing Tribal Sand site before backend bolt-on"
```

- [ ] **Step 3: Verify no secrets/large files are tracked**

Run:
```bash
git ls-files | grep -E "\.env$|Archive.zip" || echo "OK: no secrets/archive tracked"
```
Expected: `OK: no secrets/archive tracked`

- [ ] **Step 4: Create a working branch**

```bash
git checkout -b feat/backend-bolt-on
```

### Task 2: Local PostgreSQL database

**Files:** none (infra)

- [ ] **Step 1: Confirm Postgres is reachable** (Postgres.app on :5432; client may need PATH)

Run:
```bash
export PATH="/opt/homebrew/opt/postgresql@16/bin:$PATH"
psql -h localhost -p 5432 -U "$USER" -l | head
```
Expected: a list of databases (no connection error).

- [ ] **Step 2: Create the `tribalsand` database**

```bash
createdb -h localhost -p 5432 -U "$USER" tribalsand && echo "created tribalsand"
```
Expected: `created tribalsand` (or "already exists" — fine).

### Task 3: Environment config

**Files:**
- Create: `.env`
- Create: `.env.example`

- [ ] **Step 1: Generate two secrets**

```bash
echo "BOOKING_TOKEN_SECRET=$(openssl rand -hex 32)"
echo "ICAL_SYNC_SECRET=$(openssl rand -hex 20)"
```
Copy the two printed lines into `.env` below.

- [ ] **Step 2: Create `.env`** (local dev values; paste the two generated secrets)

```dotenv
# ── Database (local: Postgres.app) ──
DB_HOST=localhost
DB_PORT=5432
DB_NAME=tribalsand
DB_USER=patrikgiuliana
DB_PASS=

# ── Site ──
APP_URL=http://localhost:8080
SITE_URL=http://localhost:8080

# ── Mail (blank locally → falls back to mail(); set RESEND_API_KEY in prod) ──
MAIL_FROM=noreply@tribalsand.com
MAIL_DRIVER=mail
RESEND_API_KEY=

# ── Booking tokens (paste generated values) ──
BOOKING_TOKEN_SECRET=PASTE_FROM_STEP_1
ICAL_SYNC_SECRET=PASTE_FROM_STEP_1

# ── hCaptcha (blank locally → captcha bypassed) ──
HCAPTCHA_SITE_KEY=
HCAPTCHA_SECRET_KEY=

# ── GoHighLevel (blank locally → GHL push is skipped; fill ROTATED keys in prod) ──
GHL_API_KEY=
GHL_LOCATION_ID=cBTrngnK5Q4lTkFUwhlo
GHL_PIPELINE_ID=NqZQxRL7xuWRoVGB5VJO
GHL_STAGE_ID=b1e48209-56c3-416a-87f8-9f9e0e533623
GHL_WEBHOOK_URL=https://services.leadconnectorhq.com/hooks/cBTrngnK5Q4lTkFUwhlo/webhook-trigger/ad7f1a2d-9c2a-4f9a-9049-c30b144643e5

# ── Cloudflare R2 image storage (optional; falls back to local disk) ──
# R2_ACCOUNT_ID=
# R2_BUCKET=
# R2_ACCESS_KEY=
# R2_SECRET_KEY=
# R2_PUBLIC_URL=
```

- [ ] **Step 3: Create `.env.example`** — copy `.env` with all secret values blanked

```bash
sed -E 's/^(BOOKING_TOKEN_SECRET|ICAL_SYNC_SECRET|RESEND_API_KEY|GHL_API_KEY|HCAPTCHA_SITE_KEY|HCAPTCHA_SECRET_KEY)=.*/\1=/' .env > .env.example
```

- [ ] **Step 4: Verify `.env` is ignored**

```bash
git check-ignore .env && echo "OK: .env ignored"
```
Expected: `.env` then `OK: .env ignored`.

- [ ] **Step 5: Commit**

```bash
git add .gitignore .env.example
git commit -m "chore: add env scaffolding and gitignore"
```

---

## Phase 1 — Port the backend

### Task 4: Copy backend modules (no front-end collisions)

**Files:** Create (copied): `includes/db.php`, `includes/auth.php`, `includes/booking.php`, `includes/tracking.php`, `includes/storage.php`, `includes/turnstile.php`, `includes/mail.php`, `includes/form-enquiry.php`

> Tribal Sand already has `includes/header.php`, `footer.php`, `head.php`, `schema.php`. Do NOT copy Claris's `header.php`/`footer.php`/`search-bar.php`/`form-availability.php` here — only the backend modules listed.

- [ ] **Step 1: Copy the backend includes**

```bash
SRC="/Users/patrikgiuliana/Desktop/CLAUDE CODE/ClarisAfricanExperience"
DST="/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
for f in db.php auth.php booking.php tracking.php storage.php turnstile.php mail.php form-enquiry.php; do
  cp "$SRC/includes/$f" "$DST/includes/$f"
done
ls "$DST/includes/"
```
Expected: the new files plus the existing `head.php header.php footer.php schema.php`.

- [ ] **Step 2: Lint all copied includes**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
for f in includes/db.php includes/auth.php includes/booking.php includes/tracking.php includes/storage.php includes/turnstile.php includes/mail.php includes/form-enquiry.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: Commit**

```bash
git add includes/db.php includes/auth.php includes/booking.php includes/tracking.php includes/storage.php includes/turnstile.php includes/mail.php includes/form-enquiry.php
git commit -m "feat: port Claris backend includes (db, auth, booking, mail, tracking, storage, captcha)"
```

### Task 5: Copy API, admin, bin, db, Dockerfile

**Files:** Create (copied): `api/*`, `admin/*`, `bin/migrate.php`, `bin/create-admin.php`, `bin/reset-admin-password.php`, `bin/ical-expire-holds.php`, `db/schema.sql`, `db/migrations/*.sql`, `Dockerfile`

- [ ] **Step 1: Copy directories and files**

```bash
SRC="/Users/patrikgiuliana/Desktop/CLAUDE CODE/ClarisAfricanExperience"
DST="/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
cp -R "$SRC/api" "$DST/api"
cp -R "$SRC/admin" "$DST/admin"
mkdir -p "$DST/bin" "$DST/db/migrations"
for f in migrate.php create-admin.php reset-admin-password.php ical-expire-holds.php; do cp "$SRC/bin/$f" "$DST/bin/$f"; done
cp "$SRC/db/schema.sql" "$DST/db/schema.sql"
cp "$SRC"/db/migrations/*.sql "$DST/db/migrations/"
cp "$SRC/Dockerfile" "$DST/Dockerfile"
ls "$DST/api" "$DST/admin" "$DST/bin" "$DST/db/migrations"
```
Expected: the Claris API endpoints, admin pages, bin scripts, and 8 migration files present.

- [ ] **Step 2: Lint the API + bin entrypoints**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
for f in api/*.php bin/*.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: Commit**

```bash
git add api admin bin db Dockerfile
git commit -m "feat: port Claris api/, admin/, bin/, db/ schema+migrations, Dockerfile"
```

### Task 6: Connect to the database

**Files:** none (verification)

- [ ] **Step 1: Verify the app's `db()` connects using `.env`**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -r 'require "includes/db.php"; $v = db()->query("select version()")->fetchColumn(); echo "DB OK: ", substr($v,0,25), "\n";'
```
Expected: `DB OK: PostgreSQL 18.x ...` (no PDO exception).

### Task 7: Create schema + run migrations

**Files:** none (runs copied SQL)

- [ ] **Step 1: Load the base schema**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
export PATH="/opt/homebrew/opt/postgresql@16/bin:$PATH"
psql -h localhost -p 5432 -U "$USER" -d tribalsand -f db/schema.sql
```
Expected: `CREATE TABLE` lines, no errors.

- [ ] **Step 2: Run all migrations**

```bash
php bin/migrate.php
```
Expected: `→ add_availability.sql ... OK`, …, ending `All migrations completed successfully.`

- [ ] **Step 3: Verify the core tables exist**

```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c "\dt" | grep -E "rooms|units|holds|availability_blocks|submissions|settings|admin_users"
```
Expected: all of those table names listed.

---

## Phase 2 — Tribal Sand data model

### Task 8: Venues migration

**Files:**
- Create: `db/migrations/add_venues.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Tribal Sand: venue layer above rooms (My Amani, Maya Kobe, Zuri, Enkare Bofa,
-- Sandbox, Maya Ilai, Tribal Dunes). Sorts after add_tours.sql, before enrich_tours.sql.
CREATE TABLE IF NOT EXISTS venues (
    id           SERIAL PRIMARY KEY,
    slug         VARCHAR(100) NOT NULL UNIQUE,
    name         VARCHAR(255) NOT NULL,
    location     VARCHAR(255),
    sort_order   INT          NOT NULL DEFAULT 0,
    is_published BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at   TIMESTAMP    NOT NULL DEFAULT NOW()
);

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS venue_id INT REFERENCES venues(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_rooms_venue_id ON rooms(venue_id);
```

- [ ] **Step 2: Run it**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php bin/migrate.php db/migrations/add_venues.sql
```
Expected: `→ add_venues.sql ... OK`.

- [ ] **Step 3: Verify**

```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c "\d venues" && \
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c "\d rooms" | grep venue_id
```
Expected: `venues` table description + a `venue_id` line on `rooms`.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_venues.sql
git commit -m "feat: add venues table + rooms.venue_id migration"
```

### Task 9: Seed venues, rooms, units, settings

**Files:**
- Create: `db/seed_tribalsand.sql`

> Slugs MUST match the existing page filenames exactly (note `maya_ilai` uses an underscore). Prices are placeholders (0) — real prices are set later in admin. `form_mode='availability'` makes each room request-to-book.

- [ ] **Step 1: Write the seed**

```sql
-- Tribal Sand seed: venues + bookable rooms + one unit each.
-- Idempotent via ON CONFLICT (slug).

-- Venues -------------------------------------------------------------
INSERT INTO venues (slug, name, location, sort_order) VALUES
  ('my-amani',     'My Amani',     'Vipingo',  1),
  ('maya-kobe',    'Maya Kobe',    'Kilifi',   2),
  ('zuri',         'Zuri',         'Watamu',   3),
  ('enkare-bofa',  'Enkare Bofa',  'Kilifi',   4),
  ('sandbox',      'Sandbox',      'Vipingo',  5),
  ('maya_ilai',    'Maya Ilai',    'Kilifi',   6),
  ('tribal-dunes', 'Tribal Dunes', 'Community',7)
ON CONFLICT (slug) DO NOTHING;

-- Rooms (slug = booking-widget target; price 0 = set in admin) --------
-- Whole-villa venues: one room per venue (slug matches venue page).
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT v.slug, v.name || ' — Whole Villa', v.id, 0, 'USD', 'availability', 0
FROM venues v
WHERE v.slug IN ('zuri','enkare-bofa','sandbox','maya_ilai','tribal-dunes')
ON CONFLICT (slug) DO NOTHING;

-- Maya Kobe: main house + cottages.
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT r.slug, r.name, v.id, 0, 'USD', 'availability', r.so
FROM (VALUES
  ('maya-kobe-main-house', 'Maya Kobe — Main House', 1),
  ('maya-kobe-cottages',   'Maya Kobe — Cottages',   2)
) AS r(slug, name, so)
JOIN venues v ON v.slug = 'maya-kobe'
ON CONFLICT (slug) DO NOTHING;

-- My Amani room types (the my-amani-*.php pages).
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT r.slug, r.name, v.id, 0, 'USD', 'availability', r.so
FROM (VALUES
  ('my-amani-premium-sea-view-single',   'My Amani — Premium Sea View (Single)',   1),
  ('my-amani-premium-sea-view-twin',     'My Amani — Premium Sea View (Twin)',     2),
  ('my-amani-superior-sea-view-single',  'My Amani — Superior Sea View (Single)',  3),
  ('my-amani-superior-sea-view-twin',    'My Amani — Superior Sea View (Twin)',    4),
  ('my-amani-superior-garden-view-single','My Amani — Superior Garden View (Single)',5),
  ('my-amani-superior-garden-view-twin', 'My Amani — Superior Garden View (Twin)', 6),
  ('my-amani-twin-sea-view-single',      'My Amani — Twin Sea View (Single)',      7),
  ('my-amani-twin-sea-view-twin',        'My Amani — Twin Sea View (Twin)',        8),
  ('my-amani-twin-garden-view-single',   'My Amani — Twin Garden View (Single)',   9),
  ('my-amani-twin-garden-view-twin',     'My Amani — Twin Garden View (Twin)',     10),
  ('my-amani-full-rental',               'My Amani — Full Villa Rental',           11)
) AS r(slug, name, so)
JOIN venues v ON v.slug = 'my-amani'
ON CONFLICT (slug) DO NOTHING;

-- One bookable unit per room (rooms were created after add_availability's seed) --
INSERT INTO units (room_id, name, sort_order)
SELECT id, 'Unit A', 0 FROM rooms r
WHERE NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id);

-- Settings -----------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_email', 'reservations@tribalsand.com'),
  ('form_mode', 'enquiry'),
  ('checkin_instructions', '')
ON CONFLICT (setting_key) DO NOTHING;
```

- [ ] **Step 2: Run the seed**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
export PATH="/opt/homebrew/opt/postgresql@16/bin:$PATH"
psql -h localhost -p 5432 -U "$USER" -d tribalsand -f db/seed_tribalsand.sql
```
Expected: `INSERT 0 N` lines, no errors.

- [ ] **Step 3: Verify counts (7 venues, 18 rooms, 18 units)**

```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c \
"SELECT (SELECT count(*) FROM venues) venues, (SELECT count(*) FROM rooms) rooms, (SELECT count(*) FROM units) units;"
```
Expected: `venues=7, rooms=18, units=18`.

- [ ] **Step 4: Commit**

```bash
git add db/seed_tribalsand.sql
git commit -m "feat: seed Tribal Sand venues, rooms, units, settings"
```

### Task 10: Create the admin user

**Files:** none (runs copied script)

- [ ] **Step 1: Inspect the script's usage**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php bin/create-admin.php 2>&1 | head -5
```
Expected: a usage/prompt line (it shows how to pass email + password).

- [ ] **Step 2: Create the admin** (use the email klickenya@gmail.com; follow the printed usage — typically `php bin/create-admin.php <email> <password>`)

```bash
php bin/create-admin.php klickenya@gmail.com 'TribalAdmin2026!'
```
Expected: a success line. (If the script is interactive, follow its prompts.)

- [ ] **Step 3: Verify the row**

```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c "SELECT id, email FROM admin_users;"
```
Expected: one row with the admin email.

---

## Phase 3 — Leads into Postgres

### Task 11: Shared GHL helper

**Files:**
- Create: `includes/ghl.php`

- [ ] **Step 1: Write `includes/ghl.php`** (creds from env; no-ops if `GHL_API_KEY` empty)

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const GHL_BASE    = 'https://services.leadconnectorhq.com';
const GHL_VERSION = '2021-07-28';

/** Low-level GHL API call. Returns ['ok'=>bool,'status'=>int,'data'=>array]. */
function ghl_request(string $method, string $path, array $body = []): array {
    $key = parse_env()['GHL_API_KEY'] ?? '';
    if (!$key) return ['ok' => false, 'status' => 0, 'data' => [], 'skipped' => true];

    $ch = curl_init(GHL_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Version: ' . GHL_VERSION,
        ],
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    if ($method === 'PUT')  { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    @curl_close($ch);
    if ($err) return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $err];
    return ['ok' => $code < 300, 'status' => $code, 'data' => json_decode($resp, true) ?? []];
}

/**
 * Push a normalized lead to GHL: upsert contact, create opportunity, post a note.
 * $lead keys: firstName,lastName,email,phone,country,property,arrival,departure,
 *             adults,children,nights,message,source,tags(array),note
 * Returns ['ok'=>bool,'contactId'=>?string]. Never throws; logs nothing fatal.
 */
function ghl_push(array $lead): array {
    $env = parse_env();
    $key = $env['GHL_API_KEY'] ?? '';
    if (!$key) return ['ok' => false, 'skipped' => true];

    $loc      = $env['GHL_LOCATION_ID'] ?? '';
    $pipeline = $env['GHL_PIPELINE_ID'] ?? '';
    $stage    = $env['GHL_STAGE_ID'] ?? '';

    $cf = [];
    $map = [
        'property'        => $lead['property']  ?? '',
        'arrivaldate'     => $lead['arrival']   ?? '',
        'departuredate'   => $lead['departure'] ?? '',
        'adults'          => (string)($lead['adults']   ?? ''),
        'children'        => (string)($lead['children'] ?? ''),
        'nights'          => (string)($lead['nights']   ?? ''),
        'enquiry_message' => $lead['message']   ?? '',
    ];
    foreach ($map as $k => $v) { if ($v !== '' && $v !== null) $cf[] = ['key' => $k, 'field_value' => (string)$v]; }

    $contactBody = [
        'locationId' => $loc,
        'firstName'  => $lead['firstName'] ?? '',
        'lastName'   => $lead['lastName']  ?? '',
        'email'      => $lead['email']     ?? '',
        'phone'      => $lead['phone']     ?? '',
        'source'     => 'tribalsand.com',
        'tags'       => $lead['tags']      ?? ['website-enquiry'],
    ];
    if ($cf) $contactBody['customFields'] = $cf;

    $res = ghl_request('POST', '/contacts/', $contactBody);
    $contactId = $res['data']['contact']['id'] ?? $res['data']['meta']['contactId'] ?? null;
    if (!$contactId) return ['ok' => false];

    if ($pipeline && $stage) {
        ghl_request('POST', '/opportunities/', [
            'locationId'      => $loc,
            'pipelineId'      => $pipeline,
            'pipelineStageId' => $stage,
            'contactId'       => $contactId,
            'name'            => trim(($lead['firstName'] ?? '') . ' ' . ($lead['lastName'] ?? '')) . ' · ' . ($lead['property'] ?? ''),
            'status'          => 'open',
            'source'          => $lead['source'] ?? 'Website Enquiry',
        ]);
    }
    if (!empty($lead['note'])) {
        ghl_request('POST', '/conversations/messages', [
            'locationId' => $loc, 'contactId' => $contactId, 'type' => 'Note', 'message' => $lead['note'],
        ]);
    }
    return ['ok' => true, 'contactId' => $contactId];
}
```

- [ ] **Step 2: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l includes/ghl.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add includes/ghl.php
git commit -m "feat: add includes/ghl.php (env-based GHL push, skips when key absent)"
```

### Task 12: Enrich `ghl-submit.php` (env creds + Postgres insert)

**Files:**
- Modify: `ghl-submit.php`

- [ ] **Step 1: Replace the hardcoded config block** (lines 15–22) with env-based config + backend require

Replace:
```php
/* ── Config ── */
define('GHL_API_KEY',     'pit-9d84bc02-740c-4e22-b9e8-6fe4d60f5377');
define('GHL_LOCATION_ID', 'cBTrngnK5Q4lTkFUwhlo');
define('GHL_PIPELINE_ID', 'NqZQxRL7xuWRoVGB5VJO');
define('GHL_STAGE_ID',    'b1e48209-56c3-416a-87f8-9f9e0e533623'); // "New Enquiry"
define('GHL_BASE',        'https://services.leadconnectorhq.com');
define('GHL_VERSION',     '2021-07-28');
define('NOTIFY_EMAIL',    'reservations@tribalsand.com');
```
With:
```php
/* ── Config (from env; logic ports to includes/ghl.php) ── */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ghl.php';
$__env = parse_env();
define('GHL_API_KEY',     $__env['GHL_API_KEY']     ?? '');
define('GHL_LOCATION_ID', $__env['GHL_LOCATION_ID'] ?? '');
define('GHL_PIPELINE_ID', $__env['GHL_PIPELINE_ID'] ?? '');
define('GHL_STAGE_ID',    $__env['GHL_STAGE_ID']    ?? '');
define('GHL_BASE',        'https://services.leadconnectorhq.com');
define('GHL_VERSION',     '2021-07-28');
define('NOTIFY_EMAIL',    setting('notify_email', 'reservations@tribalsand.com'));
```

- [ ] **Step 2: Persist the lead to Postgres** — insert immediately before the final `/* ── Respond ── */` block (after Step 5's `mail(...)` call on line 284)

Insert:
```php
/* ── Persist to Postgres (admin inbox) ── */
try {
    $nameParts = $guest['firstName'] ?? '';
    db_query(
        "INSERT INTO submissions
            (type, guest_name, guest_email, guest_phone, message,
             check_in, check_out, guests_adults, guests_children, payload_json,
             source_page, referrer, ip_address, user_agent)
         VALUES
            ('enquiry', :name, :email, :phone, :message,
             :ci, :co, :adults, :children, :payload,
             :src, :ref, :ip, :ua)",
        [
            ':name'     => trim(($guest['firstName'] ?? '') . ' ' . ($guest['lastName'] ?? '')),
            ':email'    => $guest['email'] ?? '',
            ':phone'    => $guest['phone'] ?? '',
            ':message'  => $userMsg ?: ($data['note'] ?? ''),
            ':ci'       => $toDate($arrivalRaw) ?: null,
            ':co'       => $toDate($departureRaw) ?: null,
            ':adults'   => (int)($toNum((string)$adultsRaw) ?: 1),
            ':children' => (int)($toNum((string)$childrenRaw) ?: 0),
            ':payload'  => json_encode(['property' => $property, 'rooms' => $rooms, 'ghl_contact' => $contactId, 'ref' => $ref, 'source' => $source]),
            ':src'      => $_SERVER['HTTP_REFERER'] ?? '',
            ':ref'      => $_SERVER['HTTP_REFERER'] ?? '',
            ':ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]
    );
} catch (Throwable $e) {
    error_log('[ghl-submit] submissions insert failed: ' . $e->getMessage());
}
```

- [ ] **Step 3: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l ghl-submit.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Start the dev server (if not running) and submit a test enquiry**

```bash
# Server: use the Preview tool "PHP Built-in Server" (php -S localhost:8080 router.php)
curl -s -X POST http://localhost:8080/ghl-submit \
  -H 'Content-Type: application/json' \
  -d '{"guest":{"firstName":"Test","lastName":"Lead","email":"test@example.com","phone":"+254700000000"},"customData":{"property":"Zuri","enquiry_message":"Plan testing"},"opportunity":{"source":"Website - Contact Page"},"note":"hello"}'
```
Expected: JSON `{"ok":true,...}` (GHL fields may be empty locally since `GHL_API_KEY` is blank — that's fine; the DB insert still runs).

- [ ] **Step 5: Verify the row landed in Postgres**

```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c \
"SELECT id, type, guest_name, guest_email FROM submissions ORDER BY id DESC LIMIT 1;"
```
Expected: a row `enquiry | Test Lead | test@example.com`.

- [ ] **Step 6: Commit**

```bash
git add ghl-submit.php
git commit -m "feat: ghl-submit reads creds from env and persists enquiries to Postgres"
```

### Task 13: Trip-builder → Postgres

**Files:**
- Create: `api/trip-builder.php`
- Modify: `trip-builder.php` (the `TS_GHL` config block, lines 505–509)

- [ ] **Step 1: Write `api/trip-builder.php`** (stores the trip-builder payload; GHL still handled client-side by the webhook)

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
if (!empty($data['website'])) { exit(json_encode(['ok' => true])); } // honeypot

$guest = $data['guest'] ?? [];
$trip  = $data['trip']  ?? [];
$name  = trim(($guest['firstName'] ?? '') . ' ' . ($guest['lastName'] ?? ''));
$email = trim($guest['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'valid email required'])); }

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$window = date('Y-m-d H:i:s', time() - 600);
$count = (int)db_query("SELECT COUNT(*) c FROM submissions WHERE ip_address=:ip AND created_at>:w", [':ip'=>$ip, ':w'=>$window])->fetch()['c'];
if ($count >= 5) { http_response_code(429); exit(json_encode(['ok' => false, 'error' => 'rate limited'])); }

try {
    db_query(
        "INSERT INTO submissions
            (type, guest_name, guest_email, guest_phone, message,
             check_in, check_out, guests_adults, guests_children, payload_json,
             source_page, referrer, ip_address, user_agent)
         VALUES
            ('enquiry', :name, :email, :phone, :msg,
             :ci, :co, :adults, :children, :payload,
             :src, :ref, :ip, :ua)",
        [
            ':name'     => $name,
            ':email'    => $email,
            ':phone'    => trim($guest['phone'] ?? ''),
            ':msg'      => 'Trip Builder request — see payload for full itinerary.',
            ':ci'       => ($trip['arrival']   ?? '') ?: null,
            ':co'       => ($trip['departure'] ?? '') ?: null,
            ':adults'   => (int)($trip['adults']   ?? 1),
            ':children' => (int)($trip['children'] ?? 0),
            ':payload'  => json_encode($data, JSON_UNESCAPED_SLASHES),
            ':src'      => $_SERVER['HTTP_REFERER'] ?? '',
            ':ref'      => $_SERVER['HTTP_REFERER'] ?? '',
            ':ip'       => $ip,
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]
    );
} catch (Throwable $e) {
    error_log('[trip-builder] insert failed: ' . $e->getMessage());
    http_response_code(500); exit(json_encode(['ok' => false]));
}
echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
```

- [ ] **Step 2: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l api/trip-builder.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Enable the backend post in `trip-builder.php`** (lines 507–508)

Replace:
```js
  alsoPostToBackend: false,
  backendUrl: 'https://www.tribalsand.com/tribal-sand/api/submit.php',
```
With:
```js
  alsoPostToBackend: true,
  backendUrl: '/api/trip-builder.php',
```

- [ ] **Step 4: Verify a backend post lands** (the page posts to the backend after the webhook; simulate the backend call directly)

```bash
curl -s -X POST http://localhost:8080/api/trip-builder.php \
  -H 'Content-Type: application/json' \
  -d '{"guest":{"firstName":"Trip","lastName":"Tester","email":"trip@example.com"},"trip":{"arrival":"2026-07-01","departure":"2026-07-05","adults":2,"children":1,"prop":"Zuri"}}'
```
Expected: `{"ok":true,"id":N}`. Confirm:
```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c "SELECT type, guest_name, check_in, check_out FROM submissions ORDER BY id DESC LIMIT 1;"
```
Expected: `enquiry | Trip Tester | 2026-07-01 | 2026-07-05`.

- [ ] **Step 5: Commit**

```bash
git add api/trip-builder.php trip-builder.php
git commit -m "feat: store trip-builder leads in Postgres (backend post enabled)"
```

### Task 14: Agent form → Resend-backed endpoint

**Files:**
- Modify: `for-agents.php` (the submit handler around lines 360–380)
- Delete: `process-agent-form.php`

- [ ] **Step 1: Inspect the agent form fields** (to map them to the endpoint)

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
sed -n '286,380p' for-agents.php
```
Note the input `name=` / `id=` attributes (at minimum name + email; possibly company/agency).

- [ ] **Step 2: Replace the `fetch('process-agent-form.php', …)` submit** with a JSON POST to `/api/submit-agency.php`

Replace the existing fetch block (around line 367) with:
```js
  var fd = new FormData(form);
  var nm = (fd.get('name') || fd.get('fullname') || '').toString().trim();
  var payload = {
    name:    nm,
    email:   (fd.get('email') || '').toString().trim(),
    phone:   (fd.get('phone') || '').toString().trim(),
    agency:  (fd.get('company') || fd.get('agency') || nm).toString().trim(),
    country: (fd.get('country') || '').toString().trim(),
    message: (fd.get('message') || 'Travel agent registration enquiry').toString().trim(),
    website: (fd.get('website') || '').toString()
  };
  fetch('/api/submit-agency.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
```
(Leave the existing `.then(...)`/`.catch(...)` success+error handlers — `submit-agency.php` also returns `{ ok: true }`. If the existing code checks `r.success`, change that check to `r.ok`.)

- [ ] **Step 3: Delete the dead PHPMailer handler**

```bash
git rm process-agent-form.php
```

- [ ] **Step 4: Verify the endpoint stores an agency lead**

```bash
curl -s -X POST http://localhost:8080/api/submit-agency.php \
  -H 'Content-Type: application/json' \
  -d '{"name":"Agent A","email":"agent@example.com","agency":"Acme Travel","message":"partner request"}'
```
Expected: `{"ok":true,"id":N}`. Confirm:
```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c "SELECT type, guest_name FROM submissions WHERE type='agency' ORDER BY id DESC LIMIT 1;"
```
Expected: `agency | Agent A`.

- [ ] **Step 5: Commit**

```bash
git add for-agents.php
git commit -m "feat: route agent form to Resend-backed /api/submit-agency; remove dead PHPMailer handler"
```

### Task 15: Mirror booking-widget holds to GHL

**Files:**
- Modify: `api/submit-enquiry.php` (add a GHL mirror after the hold/enquiry branches)

- [ ] **Step 1: Require the GHL helper** — change the top requires (lines 3–4)

Replace:
```php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
```
With:
```php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/ghl.php';
```

- [ ] **Step 2: Mirror to GHL** — insert just before the final `echo json_encode([...'mode' => 'hold'...]);` and the `else` enquiry echo (i.e. right after `$id = (int)db()->lastInsertId();`, line 122)

Insert:
```php
// Mirror to GHL (skips automatically if GHL_API_KEY is unset)
$nameParts = explode(' ', $name, 2);
ghl_push([
    'firstName' => $nameParts[0] ?? $name,
    'lastName'  => $nameParts[1] ?? '',
    'email'     => $email,
    'phone'     => trim($data['phone'] ?? ''),
    'property'  => $room ? $room['name'] : '',
    'arrival'   => $checkin,
    'departure' => $checkout,
    'adults'    => (int)($data['adults'] ?? 1),
    'children'  => (int)($data['children'] ?? 0),
    'message'   => trim($data['message'] ?? ''),
    'source'    => 'Website Booking Request',
    'tags'      => ['website-enquiry', 'booking-request'],
    'note'      => "Booking request via tribalsand.com\nRoom: " . ($room['name'] ?? '-') . "\nDates: {$checkin} → {$checkout}",
]);
```

- [ ] **Step 3: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l api/submit-enquiry.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add api/submit-enquiry.php
git commit -m "feat: mirror booking-widget holds to GHL via shared helper"
```

---

## Phase 4 — Booking engine on property pages

### Task 16: Booking widget JS

**Files:**
- Create: `js/booking-widget.js`

- [ ] **Step 1: Create the directory and copy the widget logic**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
mkdir -p js
```

- [ ] **Step 2: Write `js/booking-widget.js`** — this is the `initAvailForm` IIFE from Claris `room.js`, made standalone (it self-guards on `#availCalendar`, so it's inert on pages without the widget)

Copy the entire body of the `initAvailForm` function from `ClarisAfricanExperience/room.js` (lines 182–503) into this wrapper:
```js
document.addEventListener("DOMContentLoaded", () => {
  (function initAvailForm() {
    // ⬇️ paste the FULL body of initAvailForm from ClarisAfricanExperience/room.js
    //    (lines 183–502, i.e. everything between the function's braces).
    //    It already calls /api/check-availability.php and /api/submit-enquiry.php
    //    with absolute paths, and exits early if #availCalendar is absent.
  })();
});
```

Exact extraction command to produce the inner body:
```bash
sed -n '184,502p' "/Users/patrikgiuliana/Desktop/CLAUDE CODE/ClarisAfricanExperience/room.js" > /tmp/availform-body.js
wc -l /tmp/availform-body.js   # ~319 lines
# Then paste /tmp/availform-body.js inside the initAvailForm() wrapper above.
```

- [ ] **Step 3: Lint (node syntax check if available, else skip)**

```bash
node --check js/booking-widget.js 2>/dev/null && echo "JS OK" || echo "node not available — will verify in browser"
```

- [ ] **Step 4: Commit**

```bash
git add js/booking-widget.js
git commit -m "feat: add standalone booking-widget JS (from Claris initAvailForm)"
```

### Task 17: Booking widget CSS

**Files:**
- Create: `css/booking.css`

- [ ] **Step 1: Extract the booking widget styles from Claris**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
# Pull every rule block whose selector touches the booking widget classes.
awk 'BEGIN{RS="}"; ORS="}\n"} /\.bk-|#availCalendar|\.h-captcha|\.gallery-lightbox/ {print}' \
  "/Users/patrikgiuliana/Desktop/CLAUDE CODE/ClarisAfricanExperience/styles.css" > css/booking.css
wc -l css/booking.css
```
Expected: a non-empty file (~150–300 lines).

- [ ] **Step 2: Restyle to the Tribal Sand palette** — prepend brand variables so the widget matches the site (teal/sand tones used across Tribal Sand)

Add at the very top of `css/booking.css`:
```css
/* Tribal Sand booking widget — brand overrides */
.bk-avail{--bk-accent:#1E5C6B;--bk-accent-dark:#16424d;--bk-ink:#2b2b2b;font-family:'Jost',sans-serif}
.bk-submit{background:var(--bk-accent)}
.bk-submit:hover{background:var(--bk-accent-dark)}
.bk-cell--start,.bk-cell--end{background:var(--bk-accent)!important;color:#fff}
.bk-cell--in-range,.bk-cell--hover-range{background:rgba(30,92,107,.15)}
.bk-pop__cta,.bk-success__icon{color:var(--bk-accent)}
```

- [ ] **Step 3: Verify it parses (no stray braces)**

```bash
php -r '$c=file_get_contents("css/booking.css"); echo substr_count($c,"{")===substr_count($c,"}") ? "BRACES OK\n" : "MISMATCH\n";'
```
Expected: `BRACES OK`.

- [ ] **Step 4: Commit**

```bash
git add css/booking.css
git commit -m "feat: add booking widget CSS extracted from Claris, restyled to Tribal Sand"
```

### Task 18: Booking widget partial

**Files:**
- Create: `includes/booking-widget.php`

- [ ] **Step 1: Write `includes/booking-widget.php`** — adapted from Claris `form-availability.php`; takes a `$booking_slug` (string) and renders the widget by loading the room from the DB

```php
<?php
/**
 * Tribal Sand booking widget.
 * Usage on a property/room page (before including this file):
 *   $booking_slug = 'zuri';            // must match a rooms.slug
 *   include __DIR__ . '/includes/booking-widget.php';
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/turnstile.php';

$booking_slug = $booking_slug ?? '';
$__room = $booking_slug ? fetch_room_by_slug($booking_slug) : false;
if (!$__room) { return; } // no matching room → render nothing (page unaffected)

$room_slug  = $__room['slug'];
$room_name  = $__room['name'];
$room_price = (float)($__room['price_amount'] ?? 0);
$room_curr  = $__room['price_currency'] ?? 'USD';
?>
<div class="bk-avail" id="availCalendar"
     data-slug="<?= e($room_slug) ?>"
     data-price="<?= e($room_price) ?>"
     data-currency="<?= e($room_curr) ?>">
  <form id="availForm" class="bk-form" novalidate data-room-slug="<?= e($room_slug) ?>">
    <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
    <input type="hidden" name="checkin"  id="availCheckin">
    <input type="hidden" name="checkout" id="availCheckout">
    <input type="hidden" name="adults"   id="availAdults"   value="2">
    <input type="hidden" name="children" id="availChildren" value="0">

    <button type="button" class="bk-pill" id="bkDatesBtn" aria-haspopup="dialog" aria-expanded="false">
      <span class="bk-pill__label">Dates</span>
      <span class="bk-pill__value" id="bkDatesValue">Add dates</span>
    </button>
    <div class="bk-pop" id="bkDatesPop" role="dialog" aria-label="Select dates" hidden>
      <div class="bk-cal">
        <div class="bk-cal__head">
          <button type="button" class="bk-cal__nav" id="bkPrevMonth" aria-label="Previous month">&#8249;</button>
          <span class="bk-cal__title" id="bkMonthLabel"></span>
          <button type="button" class="bk-cal__nav" id="bkNextMonth" aria-label="Next month">&#8250;</button>
        </div>
        <div class="bk-cal__dow"><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>
        <div class="bk-cal__grid" id="bkCalGrid"></div>
      </div>
      <div class="bk-pop__footer">
        <span class="bk-pop__hint" id="bkDatesHint">Select your check-in date</span>
        <button type="button" class="bk-pop__cta" id="bkDatesDone">Done</button>
      </div>
    </div>

    <button type="button" class="bk-pill" id="bkGuestsBtn" aria-haspopup="dialog" aria-expanded="false">
      <span class="bk-pill__label">Guests</span>
      <span class="bk-pill__value" id="bkGuestsValue">2 adults</span>
    </button>
    <div class="bk-pop bk-pop--narrow" id="bkGuestsPop" role="dialog" aria-label="Select guests" hidden>
      <div class="bk-stepper-row">
        <div class="bk-stepper-row__label"><strong>Adults</strong><small>Age 18+</small></div>
        <div class="bk-stepper">
          <button type="button" data-bk="adult" data-dir="-1" aria-label="Decrease adults">&minus;</button>
          <span data-bk-count="adult">2</span>
          <button type="button" data-bk="adult" data-dir="1" aria-label="Increase adults">+</button>
        </div>
      </div>
      <div class="bk-stepper-row">
        <div class="bk-stepper-row__label"><strong>Children</strong><small>Age 0–17</small></div>
        <div class="bk-stepper">
          <button type="button" data-bk="child" data-dir="-1" aria-label="Decrease children">&minus;</button>
          <span data-bk-count="child">0</span>
          <button type="button" data-bk="child" data-dir="1" aria-label="Increase children">+</button>
        </div>
      </div>
      <div class="bk-pop__footer bk-pop__footer--end"><button type="button" class="bk-pop__cta" id="bkGuestsDone">Done</button></div>
    </div>

    <div class="bk-total" id="bkTotal" hidden>
      <div class="bk-total__row"><span class="bk-total__label" id="bkTotalLabel">— nights</span><span class="bk-total__price" id="bkTotalPrice">—</span></div>
      <div class="bk-total__hint">Final price confirmed by email</div>
    </div>

    <div class="bk-fields">
      <label class="bk-field"><span>Your name</span><input type="text" name="name" placeholder="Full name" required></label>
      <label class="bk-field"><span>Email</span><input type="email" name="email" placeholder="you@example.com" required></label>
      <label class="bk-field"><span>Phone <small>(optional)</small></span><input type="tel" name="phone" placeholder="+254 …"></label>
      <label class="bk-field"><span>Message <small>(optional)</small></span><textarea name="message" rows="2" placeholder="Special requests, arrival time, etc."></textarea></label>
    </div>

    <div class="bk-feedback" id="availFeedback" hidden></div>
    <?php if (captcha_site_key()): ?><div class="h-captcha" data-sitekey="<?= e(captcha_site_key()) ?>"></div><?php endif; ?>
    <button type="submit" class="bk-submit"><span class="bk-submit__label">Check availability</span></button>
    <p class="bk-hold-note">Dates are held for 24 hours pending confirmation</p>
  </form>
</div>
```

- [ ] **Step 2: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l includes/booking-widget.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add includes/booking-widget.php
git commit -m "feat: add booking widget partial (loads room by slug, renders nothing if absent)"
```

### Task 19: Conditional asset loading in head.php

**Files:**
- Modify: `includes/head.php`

- [ ] **Step 1: Add booking assets** — insert before the closing `<?php if (!empty($page_preload)): ?>` block (after the `css/main.css` line, line 75)

Insert:
```php
<?php if (!empty($page_booking)): ?>
<!-- ── BOOKING WIDGET ── -->
<link rel="stylesheet" href="css/booking.css">
<script src="js/booking-widget.js" defer></script>
<?php if (!empty(getenv('HCAPTCHA_SITE_KEY')) || !empty($_ENV['HCAPTCHA_SITE_KEY'])): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 2: Document the variable** — add `$page_booking` to the header docblock comment (after the `$page_preload` line)

```php
 *   $page_booking — set truthy to load the booking widget CSS/JS, optional
```

- [ ] **Step 3: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l includes/head.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/head.php
git commit -m "feat: conditionally load booking widget assets when \$page_booking is set"
```

### Task 20: Wire the widget into the first property page (Zuri) — pilot

**Files:**
- Modify: `zuri.php`

> Zuri is a whole-villa venue (room slug `zuri`). This task proves the end-to-end flow on one page before rolling out.

- [ ] **Step 1: Enable booking assets** — find where `zuri.php` sets its page vars (before `include 'includes/head.php'`) and add:

```php
$page_booking = true;
$booking_slug = 'zuri';
```

- [ ] **Step 2: Insert the widget at the existing "BOOK NOW" location** — locate the `book.tribalsand.com` CTA (around line 741) and replace the anchor with an anchor that scrolls to the widget, then place the widget markup. Replace:

```php
<a href="https://book.tribalsand.com/booking/chain-tribalsand-en" ...>BOOK NOW</a>
```
With:
```php
<a href="#book" class="...keep existing classes...">BOOK NOW</a>
```
And at the booking section of the page (where the CTA pointed), insert:
```php
<div id="book" class="ts-booking-section">
  <h2>Check availability &amp; request your dates</h2>
  <?php include __DIR__ . '/includes/booking-widget.php'; ?>
</div>
```

- [ ] **Step 3: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l zuri.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verify the calendar API responds for this slug**

```bash
curl -s "http://localhost:8080/api/check-availability.php?room=zuri" | head -c 200
```
Expected: JSON with `fully_blocked`, `price`, `currency` keys.

- [ ] **Step 5: Visual check** — open the dev server, load `/zuri`, screenshot, and confirm the widget renders and the calendar populates (Preview tool screenshot). Click dates → "✓ Available" hint appears.

- [ ] **Step 6: End-to-end hold test**

```bash
curl -s -X POST http://localhost:8080/api/submit-enquiry.php \
  -H 'Content-Type: application/json' \
  -d '{"room_slug":"zuri","checkin":"2026-08-01","checkout":"2026-08-04","name":"Hold Test","email":"hold@example.com","adults":2,"children":0}'
```
Expected: `{"ok":true,"id":N,"mode":"hold"}`. Confirm hold + block:
```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c \
"SELECT h.status, h.check_in, h.check_out FROM holds h ORDER BY h.id DESC LIMIT 1;"
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c \
"SELECT block_type, date_from, date_to FROM availability_blocks ORDER BY id DESC LIMIT 1;"
```
Expected: a `pending` hold for those dates + a `hold` block.

- [ ] **Step 7: Verify the dates now show blocked**

```bash
curl -s "http://localhost:8080/api/check-availability.php?room=zuri&check_in=2026-08-01&check_out=2026-08-04" | head -c 200
```
Expected: `"available":false` (the unit is now held).

- [ ] **Step 8: Commit**

```bash
git add zuri.php
git commit -m "feat: enable request-to-book widget on Zuri (pilot)"
```

### Task 21: Roll out the widget to remaining property pages

**Files:**
- Modify: `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php`, `tribal-dunes.php`, `maya-kobe.php`, `my-amani.php`, and the `my-amani-*` room-type pages

> Apply the exact same 2-step edit from Task 20 (set `$page_booking=true; $booking_slug='<slug>';` + replace the `book.tribalsand.com` CTA with `#book` and insert the widget section). Slug per page below.

- [ ] **Step 1: Whole-villa venue pages** — `$booking_slug` = the venue slug:
  - `enkare-bofa.php` → `enkare-bofa`
  - `sandbox.php` → `sandbox`
  - `maya_ilai.php` → `maya_ilai`
  - `tribal-dunes.php` → `tribal-dunes`

- [ ] **Step 2: Maya Kobe** — `maya-kobe.php` is a venue overview with a main house + cottages. Use `$booking_slug='maya-kobe-main-house'` for the page's primary CTA, and add a second widget section for cottages with `include` after temporarily setting `$booking_slug='maya-kobe-cottages'` (re-include the partial; it reloads the room by slug each time).

- [ ] **Step 3: My Amani** — `my-amani.php` is the venue overview: replace its `book.tribalsand.com` CTA with links to the individual room-type pages (which carry the widget). Then on each `my-amani-*.php` room-type page, set `$page_booking=true` and `$booking_slug` to the matching slug from the seed (e.g. `my-amani-premium-sea-view-twin.php` → `my-amani-premium-sea-view-twin`).

- [ ] **Step 4: Repoint remaining `book.tribalsand.com` CTAs in nav/footer/index** — these are global "BOOK NOW" buttons:

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
grep -rn "book.tribalsand.com" --include="*.php" includes/ index.php
```
Point `includes/header.php` and `includes/footer.php` "BOOK NOW" buttons to `/booking` (the existing booking page, which Task 23 turns into a property chooser) and the two `index.php` CTAs likewise. Do NOT alter `home-french.php`/reference files.

- [ ] **Step 5: Lint every modified page**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
for f in enkare-bofa.php sandbox.php maya_ilai.php tribal-dunes.php maya-kobe.php my-amani.php my-amani-*.php includes/header.php includes/footer.php index.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 6: Confirm no public page still links to the external booking platform**

```bash
grep -rn "book.tribalsand.com" --include="*.php" . | grep -v "reference/\|home-french.php" || echo "OK: no external booking links remain"
```
Expected: `OK: no external booking links remain`.

- [ ] **Step 7: Visual spot-check** 3 pages (`/enkare-bofa`, `/maya-kobe`, `/my-amani-premium-sea-view-twin`) via screenshots — widget present, calendar loads.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: roll out request-to-book widget across all property pages; repoint BOOK NOW CTAs"
```

### Task 22: Guest booking lookup on `/booking`

**Files:**
- Modify: `booking.php`

> The confirmation email links to `/booking.php?ref=TS-…`. Add a `?ref=` branch at the very top of `booking.php` that renders the lookup, leaving the default page untouched when no ref is present.

- [ ] **Step 1: Add the lookup branch** at the very top of `booking.php` (before any output)

```php
<?php
// ── Guest booking lookup (when ?ref= is present) ──
if (!empty($_GET['ref'])) {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/booking.php';
    $holdId = verify_guest_ref(trim($_GET['ref']));
    $hold = $holdId ? db_query(
        "SELECT h.*, r.name AS room_name FROM holds h
         JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = u.room_id
         WHERE h.id = :id", [':id' => $holdId]
    )->fetch() : false;
    $page_title = 'Your Booking · Tribal Sand';
    $page_desc  = 'View your Tribal Sand booking.';
    $page_url   = 'https://tribalsand.com/booking';
    include __DIR__ . '/includes/head.php';
    echo '<body>'; include __DIR__ . '/includes/header.php';
    echo '<main style="max-width:680px;margin:120px auto;padding:0 1.5rem;font-family:Jost,sans-serif">';
    if ($hold) {
        echo '<h1 style="font-family:\'Cormorant Garamond\',serif">Your booking</h1>';
        echo '<p>Reference: <strong>' . e($_GET['ref']) . '</strong></p>';
        echo '<p>Room: ' . e($hold['room_name']) . '</p>';
        echo '<p>Status: <strong>' . e(ucfirst($hold['status'])) . '</strong></p>';
        echo '<p>Check-in: ' . e($hold['check_in']) . ' · Check-out: ' . e($hold['check_out']) . '</p>';
    } else {
        echo '<h1 style="font-family:\'Cormorant Garamond\',serif">Booking not found</h1>';
        echo '<p>We couldn\'t find a booking for that reference. Please contact <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a>.</p>';
    }
    echo '</main>'; include __DIR__ . '/includes/footer.php'; echo '</body></html>';
    exit;
}
?>
```
(The existing `booking.php` content follows unchanged below this block.)

- [ ] **Step 2: Lint**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l booking.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify default page still works + lookup branch responds**

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8080/booking"        # default page
curl -s "http://localhost:8080/booking?ref=TS-1-deadbeef" | grep -o "Booking not found" # invalid ref path
```
Expected: `200`, then `Booking not found`.

- [ ] **Step 4: Commit**

```bash
git add booking.php
git commit -m "feat: add guest booking lookup branch to /booking (?ref=)"
```

---

## Phase 5 — Rebranding & admin

### Task 23: Rebrand email + booking-ref + site URL

**Files:**
- Modify: `includes/mail.php`, `includes/booking.php`, `includes/db.php`, `includes/storage.php`

- [ ] **Step 1: Rebrand `includes/mail.php`** — replace Claris identity with Tribal Sand

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
sed -i '' \
  -e 's/clarisafricanexperience@gmail.com/reservations@tribalsand.com/g' \
  -e 's#noreply@clarisafricanexperience.com#noreply@tribalsand.com#g' \
  -e 's/Claris African Experience, Watamu &mdash; Kenya/Tribal Sand — Kenya/g' \
  -e 's/Claris African Experience/Tribal Sand/g' \
  -e 's/clarisafricanexperience\.com/tribalsand.com/g' \
  -e 's/#47121d/#1E5C6B/g' \
  -e 's/#e0b3bd/#bcdfe6/g' \
  -e 's/#f7f1f2/#eef6f7/g' \
  includes/mail.php
php -l includes/mail.php
```
Expected: `No syntax errors detected`. Then confirm no Claris strings remain:
```bash
grep -i "claris\|#47121d" includes/mail.php || echo "OK: mail.php rebranded"
```
Expected: `OK: mail.php rebranded`.

- [ ] **Step 2: Rebrand the guest-ref prefix `SI-` → `TS-`** in `includes/booking.php`

```bash
sed -i '' -e 's/"SI-{\$holdId}-{\$hash}"/"TS-{\$holdId}-{\$hash}"/' \
          -e 's#/\^SI-\(\\d+\)-#/^TS-(\\d+)-#' includes/booking.php
grep -n "TS-" includes/booking.php
```
Expected: both the `make_guest_ref` string and the `verify_guest_ref` regex now use `TS-`. (If the `sed` regex doesn't match due to escaping, edit the two lines manually: line 37 `return "TS-{$holdId}-{$hash}";` and line 46 `preg_match('/^TS-(\d+)-([0-9a-f]{8})$/', $ref, $m)`.)

- [ ] **Step 3: Update `site_url()` default** in `includes/db.php` (line 99)

```bash
sed -i '' "s#https://sevenislandswatamu.com#https://tribalsand.com#" includes/db.php
```

- [ ] **Step 4: Update the R2 bucket default** in `includes/storage.php` (only used if R2 enabled)

```bash
sed -i '' "s/'7island-images'/'tribalsand-images'/g" includes/storage.php
```

- [ ] **Step 5: Lint all four**

```bash
for f in includes/mail.php includes/booking.php includes/db.php includes/storage.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 6: Commit**

```bash
git add includes/mail.php includes/booking.php includes/db.php includes/storage.php
git commit -m "chore: rebrand backend email/ref/site-url/bucket to Tribal Sand"
```

### Task 24: Rebrand the admin shell

**Files:**
- Modify: `admin/_layout.php` (and any admin page with literal "Seven Islands"/"Claris" branding)

- [ ] **Step 1: Find branding strings in admin**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
grep -rni "seven islands\|claris\|sevenislands" admin/ | head
```

- [ ] **Step 2: Replace them** with Tribal Sand

```bash
grep -rl -i "seven islands\|claris\|sevenislands" admin/ | while read -r f; do
  sed -i '' -e 's/Seven Islands Resort/Tribal Sand/g' -e 's/Seven Islands/Tribal Sand/g' \
            -e 's/Claris African Experience/Tribal Sand/g' -e 's/Claris/Tribal Sand/g' "$f"
done
grep -rni "seven islands\|claris" admin/ || echo "OK: admin rebranded"
```
Expected: `OK: admin rebranded`.

- [ ] **Step 3: Lint admin entrypoints**

```bash
for f in admin/login.php admin/dashboard.php admin/_layout.php; do php -l "$f"; done
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Log in and smoke-test the admin** — open `/admin/login.php`, log in with the Task 10 credentials, screenshot the dashboard, open Submissions (should show the test leads from Phase 3) and Holds (should show the Zuri hold from Task 20).

- [ ] **Step 5: Commit**

```bash
git add admin
git commit -m "chore: rebrand admin panel to Tribal Sand"
```

### Task 25: Venue support in admin (attach rooms to venues)

**Files:**
- Modify: `admin/room-edit.php` (add a venue dropdown)
- Create: `admin/venues.php` (simple list/manage)

- [ ] **Step 1: Add a venue selector to `admin/room-edit.php`** — load venues and render a `<select name="venue_id">`; include it in the room save (INSERT/UPDATE). Find the form's save handler and add `venue_id` to the column list + params. Concretely, near the top where the room is loaded, add:

```php
$venues = db_query("SELECT id, name FROM venues ORDER BY sort_order")->fetchAll();
```
In the form, add (near the name field):
```php
<label>Venue
  <select name="venue_id">
    <option value="">— none —</option>
    <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>" <?= ((int)($room['venue_id'] ?? 0) === (int)$v['id']) ? 'selected' : '' ?>><?= e($v['name']) ?></option>
    <?php endforeach; ?>
  </select>
</label>
```
In the POST save, add `venue_id` to the update (cast `:venue_id => ($_POST['venue_id'] ?: null)`).

- [ ] **Step 2: Create `admin/venues.php`** — a minimal list using the existing admin layout

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/db.php';
$venues = db_query("SELECT v.*, (SELECT count(*) FROM rooms r WHERE r.venue_id = v.id) AS room_count FROM venues v ORDER BY sort_order")->fetchAll();
$pageTitle = 'Venues';
include __DIR__ . '/_layout.php';
?>
<h1>Venues</h1>
<table class="tbl">
  <tr><th>Name</th><th>Slug</th><th>Location</th><th>Rooms</th><th>Published</th></tr>
  <?php foreach ($venues as $v): ?>
    <tr>
      <td><?= e($v['name']) ?></td>
      <td><code><?= e($v['slug']) ?></code></td>
      <td><?= e($v['location']) ?></td>
      <td><?= (int)$v['room_count'] ?></td>
      <td><?= $v['is_published'] ? 'Yes' : 'No' ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 3: Add a "Venues" link to the admin nav** in `admin/_layout.php` (next to the Rooms link)

```php
<a href="/admin/venues.php">Venues</a>
```

- [ ] **Step 4: Lint**

```bash
for f in admin/room-edit.php admin/venues.php; do php -l "$f"; done
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verify** — open `/admin/venues.php` (7 venues, room counts), then open a room in `room-edit.php`, set its venue, save, and confirm:

```bash
psql -h localhost -p 5432 -U "$USER" -d tribalsand -c "SELECT slug, venue_id FROM rooms WHERE venue_id IS NOT NULL LIMIT 5;"
```
Expected: rows with a non-null `venue_id`.

- [ ] **Step 6: Commit**

```bash
git add admin/room-edit.php admin/venues.php admin/_layout.php
git commit -m "feat: admin venue list + attach rooms to venues"
```

---

## Phase 6 — Production routing & deploy

### Task 26: Extensionless URL rewrite (production parity)

**Files:**
- Modify: `.htaccess`

> The live host strips `.php` today; the repo `.htaccess` does not. On Render (Apache we control) we must replicate it so `/maya-kobe` serves `maya-kobe.php`. This rule is harmless locally (the dev server uses `router.php`).

- [ ] **Step 1: Add the extensionless rewrite** inside the existing `<IfModule mod_rewrite.c>` block (after the `RewriteEngine On` line, before the HTTPS redirect so internal rewrites resolve), insert:

```apache
  # Serve extensionless URLs from their .php file (parity with current live host)
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME}\.php -f
  RewriteRule ^(.+?)/?$ $1.php [L]
```

- [ ] **Step 2: Verify the file is still valid Apache config syntax** (mod_rewrite present locally is not required; this is a static check)

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
grep -c "RewriteRule \^(.+?)/?\$ \$1.php" .htaccess
```
Expected: `1`.

- [ ] **Step 3: Commit**

```bash
git add .htaccess
git commit -m "feat: add extensionless URL rewrite for production Apache parity"
```

### Task 27: Render deploy configuration

**Files:**
- Create: `render.yaml`

- [ ] **Step 1: Write `render.yaml`** — web service (Docker) + managed Postgres + cron

```yaml
databases:
  - name: tribalsand-db
    databaseName: tribalsand
    plan: basic-256mb

services:
  - type: web
    name: tribalsand
    runtime: docker
    plan: starter
    dockerfilePath: ./Dockerfile
    envVars:
      - key: DATABASE_URL
        fromDatabase: { name: tribalsand-db, property: connectionString }
      - key: APP_URL
        value: https://tribalsand.com
      - key: SITE_URL
        value: https://tribalsand.com
      - key: MAIL_FROM
        value: noreply@tribalsand.com
      - key: MAIL_DRIVER
        value: mail
      - key: RESEND_API_KEY
        sync: false
      - key: BOOKING_TOKEN_SECRET
        generateValue: true
      - key: ICAL_SYNC_SECRET
        generateValue: true
      - key: HCAPTCHA_SITE_KEY
        sync: false
      - key: HCAPTCHA_SECRET_KEY
        sync: false
      - key: GHL_API_KEY
        sync: false
      - key: GHL_LOCATION_ID
        sync: false
      - key: GHL_PIPELINE_ID
        sync: false
      - key: GHL_STAGE_ID
        sync: false

  - type: cron
    name: tribalsand-expire-holds
    runtime: docker
    dockerfilePath: ./Dockerfile
    schedule: "*/5 * * * *"
    dockerCommand: php bin/ical-expire-holds.php
    envVars:
      - key: DATABASE_URL
        fromDatabase: { name: tribalsand-db, property: connectionString }
```

- [ ] **Step 2: Verify YAML parses**

```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
python3 -c "import yaml,sys; yaml.safe_load(open('render.yaml')); print('YAML OK')"
```
Expected: `YAML OK`.

- [ ] **Step 3: Commit**

```bash
git add render.yaml
git commit -m "chore: add Render blueprint (web + postgres + expire-holds cron)"
```

### Task 28: Staged cutover checklist (manual, runs on staging Render URL)

**Files:** none (operational checklist — do NOT repoint DNS until all pass)

- [ ] **Step 1: Push to GitHub and create the Render services** from `render.yaml` (Render Blueprint). Set the `sync:false` secrets in the Render dashboard: **rotated** `GHL_API_KEY`, real `RESEND_API_KEY`, `HCAPTCHA_*`, and the GHL ids.

- [ ] **Step 2: Initialise the production DB** via the Render web service Shell:

```bash
psql $DATABASE_URL -f db/schema.sql
php bin/migrate.php
psql $DATABASE_URL -f db/seed_tribalsand.sql
php bin/create-admin.php <owner-email> <strong-password>
```

- [ ] **Step 3: URL parity check** — on the temporary `*.onrender.com` URL, confirm a sample of ranking URLs all return 200 with the expected canonical:

```bash
BASE="https://tribalsand.onrender.com"
for u in / /maya-kobe /my-amani /my-amani-premium-sea-view-twin /zuri /enkare-bofa /sandbox /maya_ilai /tribal-dunes /activities /contact /trip-builder /blog /kenya-coast-guide; do
  printf "%s -> %s\n" "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$u")"
done
```
Expected: every line ends in `200`. Investigate any non-200 before proceeding.

- [ ] **Step 4: Functional checks on staging** — submit the contact form, the trip-builder, the agent form, and a booking request; confirm each appears in `/admin` and (with prod GHL key) in GoHighLevel; confirm the booking-request guest receives the hold email and admin can confirm it.

- [ ] **Step 5: iCal sync check** — add an Airbnb/Booking.com iCal feed to a unit in admin, run a sync, and confirm imported dates show as blocked.

- [ ] **Step 6: Cut over DNS** — point `tribalsand.com` (+ `www`) at Render, keep the old host live as rollback for 72h. Re-run Step 3 against `https://tribalsand.com`.

- [ ] **Step 7: Post-cutover** — submit the sitemap in Search Console (unchanged URLs), monitor 404s and Search Console coverage for 1–2 weeks. Rotate the previously-exposed GHL + Gmail credentials if not already done.

---

## Self-review

**Spec coverage:**
- Booking engine (request-to-book, holds, iCal) → Tasks 5, 7, 16–22, 27/28 ✓
- Admin/CMS → Tasks 5, 10, 24, 25 ✓
- Lead database + GHL mirror → Tasks 11–15 ✓
- 7-venue data model → Tasks 8, 9 ✓
- URL/SEO preservation → Tasks 26, 28 (extensionless rewrite + staged 200-check cutover) ✓
- Render/Docker/Postgres/cron hosting → Tasks 5, 27, 28 ✓
- Rebranding (mail/ref/site-url/admin) → Tasks 23, 24 ✓
- Security (secrets to env, retire dead handler, rotate keys) → Tasks 3, 12, 14, 28 ✓
- Replace book.tribalsand.com → Tasks 20, 21 ✓

**Known follow-ups (out of MVP scope, per spec §7):** first-touch UTM tracking on all pages (would require including `tracking.php` site-wide); full venue CRUD (create/delete) beyond the list + room attachment; tours/for-sale-property modules (tables ship unused); CMS-ification of static content.

**Type/name consistency:** `$booking_slug` (pages → `includes/booking-widget.php`), `ghl_push()`/`ghl_request()` (defined Task 11, used Tasks 12, 15), `$page_booking` (Task 19 → property pages), guest-ref prefix `TS-` (Task 23, consumed by `/booking` in Task 22) — all consistent.
