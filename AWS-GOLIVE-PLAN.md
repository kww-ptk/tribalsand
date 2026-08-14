# Tribal Sand — AWS Go-Live Plan (Full AWS Stack)

_Created 2026-08-14. This plan re-hosts the app entirely on AWS, replacing Render + Neon + Cloudflare R2 + Resend. It **supersedes the hosting/data sections** of `GO-LIVE-PLAN.md`; the URL-parity, content, and DNS-safety guidance in that file still applies and is referenced below rather than repeated._

**Goal in one line:** run the exact same app (PHP 8.2 / Apache Docker container) on AWS end-to-end, point `tribalsand.com` at it, break nothing.

Legend: ✅ done · 🔴 blocker · 🟠 important · 🟡 polish

---

## Decisions (locked 2026-08-14)

| Concern | Choice |
|---|---|
| Compute | **App Runner** (closest to Render: push image → managed auto-deploy, HTTPS, autoscale) |
| Database | **RDS for PostgreSQL**, `db.t4g.micro`, single-AZ to start |
| Object storage | **S3** (public images bucket + private check-in bucket) |
| Email | **Amazon SES** (via the app's existing `smtp` mail driver) |
| Bot protection | **Keep Cloudflare Turnstile** for now (provider-neutral, already fail-closed). AWS WAF CAPTCHA is an optional later swap. |
| Region | Pick one and stay in it — recommend **eu-west-1 (Ireland)** or **eu-central-1** for latency to Kenya; SES production access is per-region. |

---

## Target architecture

```
                        Route 53 (DNS: tribalsand.com)
                                   │
                             CloudFront (CDN + ACM TLS)
                          ┌────────┴─────────┐
                   /images/* etc.        everything else
                          │                   │
                    S3 (public images)   App Runner  ──►  RDS PostgreSQL (db.t4g.micro)
                                          (Apache/PHP     │
                                           Docker)        └►  S3 (private check-in scans, presigned)
                                             │
                                        SES (email)   SSM Parameter Store (secrets/env)
                                             │
   EventBridge Scheduler ──► Lambda ──► App Runner /api/sync-ical.php  (iCal cron, Bearer secret)
                                             │
                                        CloudWatch (logs + alarms)
        GitHub push ──► GitHub Actions ──► ECR (image) ──► App Runner deploy
```

---

## Service inventory (what each thing is for)

| # | AWS service | Replaces | Purpose |
|---|---|---|---|
| 1 | **App Runner** | Render | Runs the Docker container; managed HTTPS + autoscaling |
| 2 | **ECR** | Render build | Stores the built Docker image |
| 3 | **RDS PostgreSQL** | Neon | The database |
| 4 | **S3** (2 buckets) | Cloudflare R2 | Public images + private passport/waiver scans |
| 5 | **SES** | Resend | Transactional email (confirmations, password resets) |
| 6 | **CloudFront** + **ACM** | Cloudflare CDN/TLS | CDN, caching, free TLS certs |
| 7 | **Route 53** | Namecheap DNS | Authoritative DNS for `tribalsand.com` |
| 8 | **SSM Parameter Store** | Render env vars | Secrets/config injected into App Runner |
| 9 | **EventBridge Scheduler + Lambda** | external cron | Fires `api/sync-ical.php` on a schedule |
| 10 | **CloudWatch** | Render logs | Logs, metrics, alarms |
| 11 | **GitHub Actions** | Render auto-deploy | Build → push to ECR → trigger App Runner deploy |
| 12 | **IAM** | — | Roles/policies binding it all together (least privilege) |

---

## Phase 0 — Prerequisites
- 🔴 AWS account with billing + MFA on root; create an admin IAM user/role (don't use root day-to-day).
- 🔴 Pick the region and use it for **every** resource (SES, RDS, S3, App Runner must agree).
- 🟠 Decide who owns the domain transfer: keep registrar at Namecheap but move DNS to **Route 53** (or Cloudflare) — Namecheap DNS can't ALIAS the bare apex to App Runner/CloudFront.

## Phase 1 — Code changes (small; mostly config)

The app is already close to AWS-native. Required changes:

- 🔴 **S3 storage driver** — `includes/storage.php` already implements S3 SigV4 signing for R2. Add an S3 code path (or generalize the R2 functions):
  - Host: `s3.<region>.amazonaws.com` (or `<bucket>.s3.<region>.amazonaws.com`), `region = <your region>` (not `auto`).
  - New env: `S3_REGION`, `S3_BUCKET` (public), `S3_CHECKIN_BUCKET` (private), `S3_PUBLIC_URL` (CloudFront domain), plus IAM access key/secret **or** — preferred — no keys at all, using the App Runner instance role via SigV4 with a session token.
  - Keep `storage_put()` / `storage_put_private()` / `storage_signed_get_url()` signatures unchanged so callers (`admin/venues.php`, `admin/checkin-file.php`, check-in flow) don't change.
- 🔴 **SES email** — `includes/mail.php` already has an `smtp` driver. Set `MAIL_DRIVER=smtp` and SES SMTP creds; **do not set `RESEND_API_KEY`** (its presence short-circuits to Resend). Verify `send_smtp()` handles the SES endpoint/STARTTLS on port 587. (Alternative: add an SES API/SDK driver — not required.)
- 🟠 **`DATABASE_URL`** — point at RDS. Code uses PDO + `db_query()` already; no query changes. Keep `SET TIME ZONE 'Africa/Nairobi'` behavior (already on every connect).
- 🟠 **`.htaccess` lockdown (A1 from `GO-LIVE-PLAN.md`)** — still required: deny `.env`, `.git/`, `*.sql`, `*.md`, `router.php`, `/db/`. Hosting-agnostic; carry it over.
- 🟡 **Turnstile keys** unchanged (`TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`).

## Phase 2 — Provision infrastructure (order matters)

1. 🔴 **ECR** — create repo `tribalsand`. Build the existing Dockerfile, push `:latest`.
2. 🔴 **RDS PostgreSQL** `db.t4g.micro` — private subnet, security group allows only App Runner's VPC connector. Enable automated backups (7-day). Note the connection string for `DATABASE_URL`.
3. 🔴 **S3** — two buckets: `tribalsand-images` (public-read via CloudFront OAC, **Block Public Access on**, served through CloudFront) and `tribalsand-checkin` (fully private; app presigns reads).
4. 🔴 **SES** — verify `tribalsand.com` domain (DKIM + SPF + DMARC records in Route 53), verify `noreply@tribalsand.com` / `reservations@`, then **request production access** (exits the sandbox; can take ~24h — do this early). Generate SMTP credentials.
5. 🔴 **SSM Parameter Store** — store as SecureString: `DATABASE_URL`, `S3_*`, SES SMTP user/pass, `TURNSTILE_*`, `ICAL_SYNC_SECRET`, `APP_URL`, `MAIL_FROM`, `MAIL_DRIVER=smtp`.
6. 🔴 **App Runner** — service from the ECR image; attach a **VPC connector** to reach RDS; instance role granting S3 (both buckets) + SSM read + SES send; inject env from SSM; health check `/`. Note the default `*.awsapprunner.com` URL.
7. 🟠 **CloudFront + ACM** — request an ACM cert for `tribalsand.com` + `www` (in **us-east-1** for CloudFront). Distribution: default origin = App Runner URL; a `/images/*` (and other static) behavior → S3 origin with OAC. Attach the cert + alternate domain names.
8. 🟠 **EventBridge Scheduler + Lambda** — schedule (e.g. hourly) → small Lambda that `POST`s `https://tribalsand.com/api/sync-ical.php` with `Authorization: Bearer <ICAL_SYNC_SECRET>`. (Keeps the header-based secret; never the query param.)
9. 🟡 **CloudWatch** — log groups for App Runner + Lambda; alarms on 5xx rate, RDS CPU/free-storage, App Runner unhealthy.

## Phase 3 — Data migration

- 🔴 **Postgres: Neon → RDS.** `pg_dump` from Neon → `pg_restore`/`psql` into RDS. Run the pending migrations after restore (per `CLAUDE.md`: `add_team_roles` → `add_addon_assignee` → `add_tasks` → `add_visitors`, plus the new `db/migrations/add_submission_availability_type.sql`). Verify row counts + a spot-check booking.
- 🔴 **Files: R2 → S3.** Sync the public image tree and any existing private check-in scans (`rclone` R2→S3, or download+upload). Confirm keys/paths match what the DB stores (public URLs will change from the R2 domain to the CloudFront domain — see note below).
- 🟠 **Stored public URLs.** The DB holds full public URLs for images (`storage_put` returns `{PUBLIC_URL}/{key}`). After cutover new uploads use the CloudFront URL, but **old rows still point at the R2 URL.** Either (a) keep the R2 public URL alive during transition, or (b) run a one-time SQL update rewriting the R2 host → CloudFront host. Plan for (b).

## Phase 4 — CI/CD

- 🟠 **GitHub Actions**: on push to `master` → build image → push to ECR → `aws apprunner start-deployment` (or rely on App Runner auto-deploy from ECR). Store AWS creds via GitHub OIDC role (no long-lived keys).

## Phase 5 — Cutover (the "go live" moment)

_Follows the same DNS-safety discipline as `GO-LIVE-PLAN.md` Phase 4._
1. Day before: lower current DNS TTL to 300s.
2. Stand everything up on the App Runner/CloudFront URL and fully test there first (Phase 6).
3. Move DNS to **Route 53** hosted zone; add apex + `www` → CloudFront (alias record). **Leave MX/email + the `book.` subdomain (eZee) untouched.**
4. Wait for ACM cert validation + CloudFront propagation.
5. Keep the old Render/Namecheap site reachable as a rollback net.

## Phase 6 — Verify on the live domain
- Homepage + ported pages + blog URLs load over HTTPS (no cert warning).
- Test **booking hold**, **enquiry form** (Turnstile passes), **admin login**, images load from CloudFront, a **passport scan** uploads to the private bucket and re-opens via `checkin-file.php`, **email arrives via SES**, **iCal cron** fires and imports.
- `book.tribalsand.com` still reaches eZee; mail to `reservations@` still flows.

## Phase 7 — After cutover
- Monitor 24–48h via CloudWatch. Rollback = revert the DNS records (fast, low TTL).
- Decommission Render, Neon, R2, Resend **only after** the AWS stack is proven and data is confirmed migrated.
- Consider RDS Multi-AZ + S3 lifecycle rules + a WAF web ACL as hardening later.

---

## Rough monthly cost (small traffic, one region)

| Service | Estimate |
|---|---|
| App Runner (1 vCPU / 2 GB, low min instances) | ~$25–45 |
| RDS `db.t4g.micro` + 20 GB storage | ~$15–18 |
| S3 (both buckets, low volume) | ~$1–3 |
| CloudFront | ~$1–5 (often near free-tier) |
| SES | ~$0.10 / 1,000 emails → negligible |
| Route 53 | ~$0.50 zone + queries ≈ $1 |
| ECR / EventBridge / Lambda / SSM (standard) | ~$0–2 |
| **Total** | **~$45–75 / month** |

(vs. Render + Neon + R2 + Resend today — likely comparable or slightly higher, in exchange for a single-vendor stack.)

---

## Blockers checklist (must be true before cutover)
1. 🔴 App Runner service healthy on the ECR image, reachable over HTTPS
2. 🔴 RDS reachable from App Runner; data migrated + migrations run
3. 🔴 S3 buckets live; storage driver writes/reads public + private objects
4. 🔴 SES out of sandbox; test email delivered
5. 🔴 Secrets in SSM wired into App Runner (no secrets in the image)
6. 🔴 `.htaccess` secret-file lockdown carried over
7. 🟠 Stored image URLs rewritten R2 → CloudFront
8. 🟠 iCal cron firing on schedule

---

## Open items for you to confirm
- **Region** (recommend eu-west-1 or eu-central-1 for Kenya latency + SES availability).
- **Turnstile vs AWS WAF** — kept Turnstile by default; say the word to plan the WAF swap.
- **Registrar** — keep the domain at Namecheap but delegate DNS to Route 53? (Cleanest for apex → CloudFront.)
