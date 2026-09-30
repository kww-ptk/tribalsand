# Architecture: how Tribal Sand is built, and how to make it cleaner

Audience: any developer joining the project. For *running* it see `README.md`; for
the rules of each feature see `CLAUDE.md`.

Reviewed 30 Sep 2026 against `master` (1,084 commits, 127 migrations, 76 test suites).

---

## 1. The shape of the system

```
                          ┌────────────────────── one Docker image (php:8.2-apache) ─────────────────────┐
 Guest browser ──HTTPS──▶ │  Apache + .htaccess (clean URLs)                                              │
 Staff browser            │    ├─ /*.php            public pages ─┐                                       │
 POS tablet               │    ├─ /admin/*.php      back office   ├─ require includes/*.php  ──PDO──▶ PostgreSQL 18
 Clock kiosk              │    ├─ /agent  /pos      portals       │   (all business logic)          (RDS) + pgvector
 Zuri's server  ────────▶ │    ├─ /api/*.php        JSON + forms ─┘                                       │
 AWS SNS / GHL webhooks ─▶│    └─ /sync/v1/*        Zuri sync API                                         │
                          │  docker/scheduler.sh (background loop) → bin/*.php + loopback calls to /api   │
                          └───────────────────────────────────────────────────────────────────────────────┘
                                  │ SES (email) · S3/CloudFront (files) · Anthropic/OpenAI · GHL · OTA iCal · Zuri
```

- **One repo, one deployable, one process type.** There is no separate front end.
  Each PHP page is controller + view in one file: it reads the database through
  helpers in `includes/` and prints HTML. Vanilla JS in `js/` enhances pages;
  interactive widgets call JSON endpoints in `api/`.
- **All real logic lives in `includes/`**, one file per domain (`rates.php`,
  `pos.php`, `inventory.php`, `acct.php`, `mail.php`, `reservations.php` …).
  Pages and endpoints are thin(ish) callers. This is the project's best
  structural decision; keep it.
- **Background work** runs in the same container (`docker/scheduler.sh`), mostly
  by calling the app's own endpoints over loopback, so there's no duplicate logic.
- **Deploy:** push to `master` → GitHub Actions builds the image → ECR → ECS
  rolling deploy → CloudFront invalidation. Migrations are run by hand from
  Admin → Migrations.

### Tech stack (and why it is fine)

PHP 8.2 without a framework, PostgreSQL, vanilla JS/CSS, no build tools. For a
small team maintaining a hospitality business system this is a reasonable choice:
nothing to upgrade except PHP itself, no dependency supply chain, and any page can
be read top to bottom. The costs show up as the codebase grows (§3). The
recommendation is **not** a rewrite or a framework migration, but the incremental
clean-up in §4.

---

## 2. What is good (keep doing this)

| Practice | Where |
|---|---|
| **One path per rule**: one pricing resolver (`rates_nightly_map()` / `room_stay_quotes()`), one email send path (`mail_send()`), one sale writer (`pos_complete_sale()`), one stock writer (`inv_move()`) | `includes/` |
| **Pre-migration safety**: code checks `*_supported()` before touching new tables, so a deploy never 500s while a migration waits | everywhere |
| Prepared statements only (`db_query()`), CSRF on forms, Turnstile fail-closed on public forms, `client_ip()` behind the load balancer | `includes/db.php`, `auth.php`, `turnstile.php` |
| Money never summed across currencies; Nairobi time everywhere | `CLAUDE.md` |
| Tests exist for most domains, and DB tests roll back | `tests/` |
| The **why** is written down | `CLAUDE.md` |

---

## 3. Review findings (ranked)

### High

1. **Internal folders are reachable from the web.** Apache's document root is the
   repository root. `.htaccess` hides `db/`, `docs/`, `logs/`, `.git` and
   `.md`/`.sql` files, but **not `tests/`, `bin/`, `includes/`, `docker/`**. Only 1 of
   77 test files and 4 of 21 `bin/` scripts refuse a web request. So on
   production, `https://…/tests/<name>.php` would run a test suite (which writes
   inside transactions) and `/bin/<script>.php` could start a job (e.g. the AI
   reindex, which spends API credit). Nothing in the site links to these folders
   (verified: they are only `require`d from disk).
   **Fix (small):** add to `.htaccess`
   `RedirectMatch 404 (?i)^/(tests|bin|includes|docker|reference)(/|$)`,
   and long-term the `public/` web root in §4.
2. **Every push to `master` deploys to production with no tests run.** The
   workflow builds and ships; it never runs `tests/`. There is no staging
   environment. **Fix:** a `test` job in `deploy.yml` (PostgreSQL service →
   `php bin/dev-setup.php` → `php bin/test.php`) that `build` depends on, plus
   branch protection requiring a PR.
3. **Migrations have no order and no ledger.** File names carry no number, and
   production records nothing about what ran; the owner runs files by hand.
   Several migrations are not commutative (`add_staff_role`, `add_team_roles`,
   `add_reception_role` each rewrite the same constraint), so any fresh build in
   name order silently produced the wrong role list. *Fixed for local builds:*
   `db/migrations/ORDER.txt` (derived from git history) + `bin/dev-setup.php`'s
   local ledger + a test that keeps the list complete. Related trap: `db/schema.sql`
   ends with copies of early migrations, so running it again after the migrations
   narrows `booking_addons_kind_check` back (loses `event`); dev-setup runs it once,
   first, as production did. *Still open for
   production:* a `schema_migrations` table written by `/admin/migrate.php`, so
   anyone can see what production has run.

### Medium

4. **Nine test suites fail on a clean database.** They are stale, not setup
   problems: they assert behaviour that later commits changed (the owner now
   lands on Front Desk, the HR scope SQL, a unique index changed by multiple
   stores; `rate_editor_logic`'s "undo tie" case now trips the guard added in
   `a745c0a`), or they assume production rows. A red suite that everyone ignores
   hides a real regression. Fix these, then enforce green in CI.
5. **`includes/` is flat and large**: 131 files, 43.6k lines. `db.php` alone is
   2,079 lines and 72 functions (connection, env, escaping, availability, search,
   quotes, currency). Every page loads it, and there is no autoloader, so each
   file hand-maintains its own `require_once` list.
6. **The repository root is crowded**: 107 PHP files (real pages, ~26 journal
   articles, ~28 one-line 301 stubs for old URLs), so it is hard to see what the
   site's pages actually are. *Partly fixed:* the 12 planning documents, the brief
   and a PDF moved to `docs/plans/` and `docs/reference/` (index: `docs/README.md`),
   and `_tmp_holds.txt` was deleted. That file was a debug dump of booking rows
   with guest names and emails that the web server was serving. `.txt` is
   not in the `.htaccess` deny list.
7. **Seed data drifted from production**: seed rooms price in USD while
   production is KES, so the 2027–28 KES rate migrations can't run locally, and
   the demo prices don't match what staff see.
8. **Large page files mix everything**: `admin/gantt.php` 1,524 lines,
   `submission-view.php` 966, `room-edit.php` 953. Each holds POST handling,
   queries, HTML and inline JS. They're hard to test and easy to break.

### Low

9. The production image previously included `.git` (now excluded by
   `.dockerignore`). `reference/` (old HTML prototypes) ships in the image.
10. Two parallel docs: `CLAUDE.md` is excellent but is also the only
    architecture record. New developers need the short version first (this file
    + `README.md`).

---

## 4. Proposed structure

Goal: **same stack, same deploy, no framework.** Only move things so that (a) the
web can reach only what it should, (b) a developer can find code by domain,
(c) nothing breaks in between. Every step is independently shippable.

### Target layout

```
tribalsand/
├── public/                     ← the ONLY folder Apache serves (DocumentRoot)
│   ├── index.php  zuri.php  search.php  reserve.php …   public pages
│   ├── journal/                 the ~26 articles  (+ 301s from the old URLs in .htaccess)
│   ├── admin/   agent/   pos/   api/   sync/v1/
│   ├── assets/  css/  js/  fonts/  images/
│   └── .htaccess  router.php (dev only)
├── src/                         ← today's includes/, grouped by domain
│   ├── core/          db.php (connection only), env.php, http.php, auth.php, csrf.php
│   ├── booking/       holds, hold-groups, availability, search, capacity, bookings ledger
│   ├── rates/         rates, rate-editor, rates-compare, quote-builder, quote-docs
│   ├── guests/        checkin, consent record, portal, messages, inbound-mail
│   ├── mail/          mail-log (mail_send), email-templates, mail builders
│   ├── pos/  inventory/  accounting/  hr/  restaurant/  ai/  sync/  channels/ (iCal, GHL, OTA)
│   └── views/         partials: head, header, footer, widgets, admin layout
├── bin/                         CLI jobs + dev-setup + test runner
├── db/
│   ├── schema.sql
│   ├── migrations/    NNNN_name.sql (numbered) + a schema_migrations ledger
│   └── seeds/
├── tests/             mirroring src/ (tests/rates/…, tests/pos/…)
├── docker/            Dockerfile, entrypoint, scheduler, dev/
├── docs/              ARCHITECTURE.md, runbooks/, specs/, plans/ (the 13 root .md files move here)
├── README.md  CLAUDE.md  docker-compose.yml  .env.example
```

### Phased plan

| Phase | Change | Risk | Effort |
|---|---|---|---|
| **0 (done on this branch)** | Docker local stack, `bin/dev-setup.php`, `ORDER.txt`, `bin/test.php`, README, `.dockerignore` | none (local only) | done |
| **1: safety** | `.htaccess` deny for `tests/ bin/ includes/ docker/ reference/`; CI `test` job before deploy; fix the 9 stale tests; branch protection on `master` | low | ½–1 day |
| **2: tidy root** | *Docs done* (plans → `docs/plans/`, temp file deleted). Remaining: move the 28 redirect stubs into `.htaccess` rules; remove the unused root `deep-sea-fishing.jpg`; articles → `journal/` | low | ½ day |
| **3: migration ledger** | `schema_migrations` table; `/admin/migrate.php` records runs and shows "not yet run on production"; new migrations get a numeric prefix (`0128_…`) | low | 1 day |
| **4: group `includes/` by domain** | Move files into `src/<domain>/` **with a thin forwarding file left at each old path** (`<?php require_once __DIR__.'/../src/rates/rates.php';`), then update callers domain by domain and delete the forwarders. Split `db.php` into core + domain files the same way | medium, mechanical, test-covered | 2–4 days |
| **5: `public/` web root** | Move served files under `public/`; Dockerfile `DocumentRoot /var/www/html/public`; update `__DIR__ . '/../includes'` paths. After this, nothing outside `public/` can ever be served | medium, one careful PR | 1–2 days |
| **6: thin the giant pages** | Extract each large admin page's POST handling into `src/<domain>/…` functions (testable), leaving the page as view + dispatch. Do it when a page is next touched, not as a big bang | low per page | ongoing |

What **not** to do: adopt a framework, add Composer/npm, or split into separate
front-end/back-end repos. None of the problems above is caused by the stack, and
each would cost months and risk the live booking engine.

---

## 5. Conventions for new code (short version of CLAUDE.md)

- Logic in `includes/` (later `src/`), pages only call it. One path per rule; never
  a second pricing loop, send path or stock writer.
- New table/column → migration (safe to re-run) + append to `ORDER.txt` + a
  `*_supported()` guard so the code works before production runs it.
- Money: integer cents or NUMERIC, one currency per sum. Time: Nairobi, via the DB.
- Every POST: CSRF. Every public form: Turnstile + rate limit. Every scoped admin
  action: re-check the row's venue against `admin_venue_ids()` server-side.
- Styled components only in the admin (no native selects, date inputs or `confirm()`).
- A test per domain in `tests/`, DB work inside a rolled-back transaction.
