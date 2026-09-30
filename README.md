# Tribal Sand

The website, booking engine and back office for Tribal Sand's properties on the
Kenya coast (Zuri, Maya Kobe, Maya Ilai, My Amani, Enkare Bofa, Sandbox,
Tribal Dunes). One codebase serves:

- the **public site**: property pages, search and availability, booking requests,
  restaurant menus and table reservations, the guest AI concierge;
- the **guest portal**: manage a booking, pre-check-in, signed consent record;
- the **admin**: front desk, calendar (Gantt), rates and quotes, enquiries,
  emails, team/HR, inventory, point of sale, accounting and reports;
- the **trade portal** (`/agent`), the **POS till** (`/pos`) and the staff
  **clock kiosk** (`/clock.php`).

Live at https://tribalsand.com (AWS: ECS + RDS PostgreSQL + S3/CloudFront).

---

## Contents

1. [Tech stack](#tech-stack)
2. [How the code is organised](#how-the-code-is-organised)
3. [Run it locally, Option A: Docker (recommended)](#option-a--docker-recommended)
4. [Run it locally, Option B: without Docker](#option-b--without-docker)
5. [Working in VS Code](#working-in-vs-code)
6. [Everyday tasks](#everyday-tasks)
7. [How local differs from production](#how-local-differs-from-production)
8. [Working with real (production) data](#working-with-real-production-data)
9. [Database changes (migrations)](#database-changes-migrations)
10. [Tests](#tests)
11. [Branches and deploying](#branches-and-deploying)
12. [Troubleshooting](#troubleshooting)

---

## Tech stack

| Layer | What we use |
|---|---|
| Language | **PHP 8.2**, vanilla: no framework, no Composer packages |
| Web server | **Apache** + `mod_rewrite` (`.htaccess` gives clean URLs: `/zuri`, not `/zuri.php`) |
| Database | **PostgreSQL 18** via PDO (prepared statements through `db_query()`), **pgvector** for the AI search |
| Front end | Server-rendered HTML from the PHP pages + **vanilla JavaScript** (`js/`) + plain **CSS** (`css/`). No build step, no npm |
| Background jobs | A bash loop inside the app container (`docker/scheduler.sh`): hold expiry, iCal import, FX rates, AI reindex, Zuri sync |
| Email | Amazon SES over SMTP in production · **Mailpit** locally |
| Files | Amazon S3 (+ CloudFront) in production · local disk locally |
| Integrations | Cloudflare Turnstile (anti-spam), GoHighLevel (CRM/WhatsApp), Anthropic/OpenAI (AI assistant), OTA iCal feeds, Zuri restaurant sync |
| Hosting | Docker image → AWS ECR → **ECS**, deployed by GitHub Actions on every push to `master` |

**There is no separate front end and back end.** Each page is a PHP file that
queries the database and prints HTML in the same request, and JavaScript
enhances it (date pickers, live chat, the POS till). The few interactive parts
talk to small JSON endpoints in `api/`. So "running the app" means running
**one** web server with PHP, plus a PostgreSQL database. There is nothing else to start.

---

## How the code is organised

```
/                    public pages (index.php, zuri.php, search.php, reserve.php …)
                     plus ~26 journal articles and ~28 old URLs that 301-redirect
admin/               the back office (one PHP file per screen); admin/assets/ = its JS
api/                 JSON / form endpoints the browser calls (bookings, availability,
                     AI, webhooks). Public ones are rate-limited + Turnstile-protected
agent/  pos/         trade portal · point-of-sale till
includes/            ALL shared logic: db.php (connection + core helpers), one file per
                     domain (rates.php, pos.php, inventory.php, acct.php, mail.php …),
                     and page partials (header.php, footer.php, booking-widget.php …)
js/  css/  fonts/    static assets (no build step; cache-busted by file time)
bin/                 command-line scripts (jobs the scheduler runs, admin tools, dev-setup)
db/schema.sql        the original tables
db/migrations/       every later change (run in the order of db/migrations/ORDER.txt)
db/seeds/  db/*.sql  demo / starting data
tests/               one standalone PHP script per area: `php tests/<name>.php`
docker/              production container: entrypoint + in-container scheduler
docker/dev/          LOCAL ONLY: container start script, database init
docs/                runbooks, specs and plans; start at docs/README.md
CLAUDE.md            THE developer handbook: every convention and "why" (read it)
docs/ARCHITECTURE.md how the pieces fit, plus the proposed clean-up plan
```

A request to `/zuri`: Apache's `.htaccess` maps it to `zuri.php`. The page
`require`s what it needs from `includes/`, reads the database, and includes
`includes/head.php` / `header.php` / `footer.php` around its own HTML.

---

## Option A: Docker (recommended)

This runs **the same image production runs** (PHP 8.2, Apache and the job
scheduler), with PostgreSQL 18 and a mail catcher next to it. Nobody needs PHP
or PostgreSQL installed.

### 1. Install once

- **Git**
- **Docker Desktop**, https://www.docker.com/products/docker-desktop/
  - Windows: it will ask to enable **WSL 2**; accept and restart.
- **VS Code** (optional, recommended)

### 2. Get the code

```bash
git clone https://github.com/kww-ptk/tribalsand.git
cd tribalsand
```


### 3. Start it

```bash
docker compose up
```

The first start downloads images and builds the database (about 2–3 minutes).
When the log shows `Ready`, open:

| What | URL |
|---|---|
| Website | http://localhost:8080 |
| Admin | http://localhost:8080/admin/login.php |
| Every email the app sends (Mailpit) | http://localhost:8025 |
| Database (for a DB client) | `localhost:54320`, user / password / database: `tribalsand` |

**Test logins**, one per role. The password is `DEV_ADMIN_PASSWORD` in
`.env.example`, which Docker uses by default:

| Email | Role | Sees |
|---|---|---|
| `owner@tribalsand.test` | owner | everything |
| `manager@tribalsand.test` | manager | the first property only |
| `reception@tribalsand.test` | reception | the first property only |
| `frontdesk@tribalsand.test` | front-desk staff | the first property only |

You get demo data: 7 properties, 36 rooms, activities, menus, reviews, POS
outlets, the staff roster and a sample inventory order. There are no guests or
bookings until you make some. The site works end to end: request a booking on
a property page, then find it in the admin and its emails in Mailpit.

**Edits show up on refresh.** Your folder is mounted into the container, so
there's nothing to rebuild when you change PHP, JS or CSS.

Stop with `Ctrl+C` (or `docker compose down`).

---

## Option B: without Docker

Use this if you can't run Docker. It is further from production (a different
web server, and no background jobs).

1. Install **PHP 8.2+** with the extensions `pdo_pgsql`, `gd`, `curl`, `mbstring`,
   `openssl`, `fileinfo`, `sodium`.
   - Windows: download the "Non Thread Safe" zip from https://windows.php.net,
     and in `php.ini` enable those extensions (`extension=pdo_pgsql`, …).
2. Install **PostgreSQL 18** (https://www.postgresql.org/download/). Optionally
   add **pgvector**; without it only the AI description search is off.
3. Create the database:
   ```bash
   psql -U postgres -c "CREATE USER tribalsand PASSWORD 'tribalsand' CREATEDB" -c "CREATE DATABASE tribalsand OWNER tribalsand"
   ```
4. Configure and build:
   ```bash
   cp .env.example .env         # defaults point at the database above
   php bin/dev-setup.php         # builds tables + demo data + test logins
   ```
5. Run:
   ```bash
   php -S localhost:8765 -t . router.php
   ```
   Open http://localhost:8765. `router.php` imitates `.htaccess` for PHP's
   built-in server.

Emails are written to `logs/mail.log` (`MAIL_DRIVER=log`) instead of being sent.

**Windows notes:** outbound HTTPS calls (AI, GoHighLevel) need a CA bundle:
add `-d curl.cainfo="C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt"` to the
`php` commands. If `sodium` is not enabled in `php.ini`, add `-d extension=sodium`.

---

## Working in VS Code

1. **File → Open Folder…** and choose the repository folder. Accept the
   recommended extensions: PHP Intelephense, Docker, PostgreSQL.
2. **Terminal → Run Task…** gives one-click tasks (defined in `.vscode/tasks.json`):
   - `Docker: start the app` (also `Ctrl+Shift+B`)
   - `Docker: stop the app` · `Docker: reset the database`
   - `Docker: run all tests` · `Docker: apply new migrations` · `Docker: open a database shell`
   - `Native: start PHP server` · `Native: run all tests`
3. Edit any file and refresh the browser. There is no separate front-end server to run.

---

## Everyday tasks

| I want to… | Docker | Native |
|---|---|---|
| Start / stop | `docker compose up` / `docker compose down` | `php -S localhost:8765 -t . router.php` |
| Update after `git pull` | restart the app (`docker compose restart app`) | `php bin/dev-setup.php` |
| See which migrations ran | `docker compose exec app php bin/dev-setup.php --status` | `php bin/dev-setup.php --status` |
| Start over with a clean database | `docker compose down -v` then `up` | `php bin/dev-setup.php --reset` |
| Run tests | `docker compose exec app php bin/test.php` | `php bin/test.php` |
| Run one test file | `docker compose exec app php tests/rates_logic.php` | `php tests/rates_logic.php` |
| Open SQL | `docker compose exec db psql -U tribalsand` | `psql -U tribalsand` |
| Read the app's logs | `docker compose logs -f app` and `logs/` | the terminal + `logs/` |
| Use the AI assistant | put `ANTHROPIC_API_KEY=…` in `.env`, restart | same |
| Use other ports | `APP_PORT=8090 docker compose up` | change the `-S` port |

`bin/dev-setup.php` is safe to run at any time: it only applies what is new. It
**refuses to run against anything but a local database**.

---

## How local differs from production

| | Production | Local |
|---|---|---|
| Code, PHP version, Apache, scheduler | ECS image | **same image** (Docker) |
| Database | RDS PostgreSQL 18, live data | PostgreSQL 18, **demo data** (or a scrubbed copy of production) |
| Room prices | KES | the seed data is in USD, so the 2027–28 KES rate files skip themselves |
| Emails | sent through Amazon SES | **caught by Mailpit**, never delivered |
| Anti-spam (Turnstile) | on | off (no keys = dev bypass) |
| Uploaded photos / private files | S3 | the container's disk |
| Property photos | CloudFront | loaded from the production CDN (read-only) |
| AI assistant / concierge | on | hidden until you add an API key |
| GoHighLevel, SES events, inbound email, iCal feeds, Zuri sync | on | off. They need real keys or a public URL; test them on production with care |

Nothing you do locally can reach production: different database, no
production keys, and email is caught.

---

## Working with real (production) data

Use this when a bug only shows with real bookings or prices. The copy is
**scrubbed automatically** when it loads: guest emails, phones, names, passport
numbers, IPs, signatures and secrets are replaced, and every login's password
becomes `DEV_ADMIN_PASSWORD`.

**Taking the snapshot** (someone with AWS access; production is private and is
only reached from inside AWS):

1. AWS console → **CloudShell** (region eu-west-1) → *Actions → Create VPC
   environment*: the database's VPC, a private subnet, and the database's
   security group.
2. Install the PostgreSQL 18 client (production runs 18):
   ```bash
   sudo dnf swap -y postgresql16 postgresql18
   ```
3. Dump (the database password is held by the owner):
   ```bash
   pg_dump -Fc --no-owner --no-privileges "postgresql://postgres@<rds-endpoint>:5432/tribalsand?sslmode=require" -f tribalsand-$(date +%F).dump
   ```
4. *Actions → Download file* → `tribalsand-YYYY-MM-DD.dump`.

**Loading it** (Docker):

```bash
# put the file in db/dev/snapshots/ (git-ignored), then:
docker compose down -v
docker compose up
```

The raw dump contains real guest data. Share it only directly with the
developer who needs it (never in Git, chat or a shared drive), and delete it
when you're done. `pg_dump` only reads; it never changes production.

---

## Database changes (migrations)

1. Add `db/migrations/<what_it_does>.sql`. Write it so it is safe to run twice
   (`IF NOT EXISTS`, guards), because production applies migrations by hand.
2. **Append its file name as the last line of `db/migrations/ORDER.txt`.**
   Order matters: several migrations rewrite the same constraint, and
   `tests/migration_order_logic.php` fails if a file is missing from the list.
3. Run `php bin/dev-setup.php` (or restart the Docker app) to apply it locally.
4. After the code deploys, the owner runs the file on production from
   **Admin → Migrations** (`/admin/migrate.php`). Production has no automatic
   migrations, and code must keep working before the migration runs (see the
   `*_supported()` guards in `CLAUDE.md`).

---

## Tests

Each file in `tests/` is a standalone script: pure logic always runs, and
database checks run inside a transaction that is rolled back.

```bash
php bin/test.php            # everything, one line per suite
php bin/test.php rates      # only suites with "rates" in the name
php bin/test.php -v rates   # show the output of failures
```

Run them against your **local** database only.

---

## Branches and deploying

There are two long-lived branches:

| Branch | What it is | Who pushes to it |
|---|---|---|
| `master` | **The live site.** Every push deploys to tribalsand.com within minutes (GitHub Actions → build image → ECR → ECS → CloudFront cache cleared) | Nobody directly: it only changes by merging `dev` |
| `dev` | The shared development branch: everyone's work, tested on localhost, not yet live | All developers |

**Day to day:**

```bash
git checkout dev
git pull origin dev          # get everyone's latest work
# …edit, test on localhost…
git add -A
git commit -m "what changed"
git pull origin dev          # pick up anything pushed meanwhile
git push origin dev
```

For a bigger change, branch off `dev` (`git checkout -b feat/short-name`), then
merge it back into `dev` when it works and delete the branch.

**Going live:** when `dev` is tested and ready, open a pull request
**`dev` → `master`** on GitHub and merge it. That merge is the deploy. There is no
staging server, so `dev` on localhost is the test environment; run the tests first.

- **Never push or commit straight to `master`.**
- New migrations must be run on production from Admin → Migrations after the deploy.
- Pushing to `dev` deploys nothing.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `port is already allocated` | Something else uses 8080/54320/8025. Run `APP_PORT=8090 DB_PORT=54321 MAIL_UI_PORT=8026 docker compose up` |
| Very slow pages on Windows | Clone the repo **inside WSL** (`\\wsl$\Ubuntu\home\…`) instead of `C:\`; Docker file sharing from Windows drives is slow |
| Database looks wrong / half-built | `docker compose down -v && docker compose up` (fresh build) |
| "REFUSING: DATABASE_URL points at …" | dev-setup only works on a local database. Fix `.env` |
| Emails "not sent" | Locally they're caught on purpose: open http://localhost:8025 |
| A migration shows as failed in `--status` | The 4 production-data files (2027–28 KES rates, one account deactivation) are expected to skip on demo data |
| AI menu item missing | Add `ANTHROPIC_API_KEY` (or `OPENAI_API_KEY`) to `.env` and restart |
| Shell script errors like `$'\r': command not found` | `git config core.autocrlf input`, then re-clone. `.sh` files must stay LF |

Read **`CLAUDE.md`** before changing an area; it records the rules and the
reasons behind them (one pricing path, Nairobi time, pre-migration guards, …).
