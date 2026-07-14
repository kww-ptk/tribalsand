# Tribal Sand — Go-Live Plan & Tawi Sync

_Generated 2026-07-08. Compares Tribal Sand (`D:\TribalIsland`) against the newer
sibling build **Tawi Watamu** (`github.com/kww-ptk/tawi-watamu`, private) and lists what
must happen before Tribal Sand goes live._

Legend: 🔴 blocker (fix before launch) · 🟠 important · 🟡 polish · 🔵 optional feature

---

## 0. Current status — the site works

Local boot smoke test (via `router.php`) all green:

| Route | Result |
|-------|--------|
| `/` (home) | HTTP 200 · 95 KB |
| `/admin/login.php` | HTTP 200 |
| `/booking.php` | HTTP 200 |
| `/zuri.php` | HTTP 200 |

- PHP lint clean on all modified room pages.
- DB connected (Neon): 8 rooms, 7 venues, 4 holds, 5 submissions, 5 settings.
- Admin login verified working: `julesalysa@gmail.com` (admin #3, created via SQL).

**Nothing is broken locally.** The blockers below are production/`.htaccess`-level issues
that a local PHP server does not exercise, plus deploy config.

---

## PART A — 🔴 GO-LIVE BLOCKERS (do these first)

These all live in **`.htaccess`**. Tawi's `.htaccess` fixes every one of them; Tribal's is an
older, thinner version. **Porting Tawi's `.htaccess` hardening is the single highest-value
action.** Compare: [Tribal `.htaccess`](.htaccess) vs Tawi's (below).

### 🔴 A1. Secrets & source are likely web-exposed
Tribal's `.htaccess` has **no rule blocking sensitive files**. Tawi blocks them explicitly.
Files confirmed present in web root with no deny rule:

- `/.env` — contains `DATABASE_URL`, `HCAPTCHA_SECRET_KEY`, `BOOKING_TOKEN_SECRET`.
  If Apache serves it (dotfiles are not blocked by default beyond `.ht*`), the **entire
  database credential leaks**.
- `/.git/` — full source + commit history downloadable.
- `/db/`, `*.sql`, `*.md` (this plan, `BRIEFING.md`, `CONTEXT.md`), `/router.php`.

**Fix:** add to `.htaccess` (from Tawi):
```apache
Options -Indexes
ErrorDocument 404 /404.php

<FilesMatch "(^\.|\.env|\.sql$|\.md$|\.log$|^composer\.(json|lock)$|^package(-lock)?\.json$|^router\.php$)">
  Require all denied
</FilesMatch>
RedirectMatch 404 (?i)/(\.git|logs|docs|deploy|db)(/|$)
```

### 🔴 A2. POST to any `.php` URL under `/admin/` or `/api/` is silently broken
Tribal's `.htaccess` 301-redirects **every** `.php` URL to its clean form — with **no
exclusion for `/admin/` or `/api/`**:
```apache
RewriteCond %{THE_REQUEST} ^[A-Z]+\s/(.+)\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L]
```
A 301 on a POST makes the browser **re-issue as GET and drop the body**. Any form that
posts to a real `.php` URL breaks in production. Confirmed offenders in Tribal:

- [`api/submit-agency.php`](for-agents.php) — form posts `fetch('/api/submit-agency.php')` → **agency enquiries lost**.
- `admin/forgot-password.php` and `admin/reset-password.php` — forms `action="/admin/forgot-password.php"` / `reset-password.php` → **password reset broken**.
- (Note: most other admin forms post to *clean* URLs, e.g. `/admin/settings`, which happen
  to work via the internal rewrite — but the mix is fragile.)

**Fix (Tawi's approach):** exclude `/admin/` and `/api/` from the strip-`.php` redirect:
```apache
RewriteCond %{THE_REQUEST} \s/([^?\s]+)\.php[\s?] [NC]
RewriteCond %{REQUEST_URI} !^/admin/ [NC]
RewriteCond %{REQUEST_URI} !^/api/ [NC]
RewriteRule ^ /%1 [R=301,L,QSA]
```
Then standardise all form/fetch targets so `/admin/` + `/api/` use **real `.php` URLs**
(what Tawi did in its "Fix admin Save 404" commit).

### 🔴 A3. Production mail is not configured
`RESEND_API_KEY` is `sync:false` in [`render.yaml`](render.yaml) — it must be set manually
in the Render dashboard. Until then **no emails send** (booking holds, hold-cancelled,
password reset). `MAIL_FROM` = `noreply@tribalsand.com` must be a **Resend-verified domain**.

**Fix:** in Render → set `RESEND_API_KEY`; verify the sending domain in Resend.

---

## PART B — 🟠 Tawi capabilities Tribal is missing

### 🟠 B1. Multi-admin with roles + Users management UI
Tawi has `super_admin`/`staff` roles, `require_super_admin()`, and a full
[`admin/users.php`](admin/users.php) (add / delete / change-role, with guardrails: can't
delete the last super admin, can't demote yourself). **Tribal has none** — `admin_users`
columns are only `id, email, password_hash, created_at, last_login_at`, and there is no
users screen (which is why the new login had to be created via raw SQL).

**Port:** add `role` column migration → copy `users.php` + `require_super_admin()` helper
into `includes/auth.php`. ~1–2 h.

### 🔵 B2. Optional feature systems (only if wanted)
- **Offers/promotions** — `admin/offers.php`, `offer-edit.php`, public `offers.php`, `includes/offer-modal.php`
- **Newsletter signup** — `api/submit-newsletter.php`
- **Guest submission-status page** — `includes/submission-status.php`

### ⛔ B3. DO NOT port
- **Captcha provider** — Tawi uses **Cloudflare Turnstile**; Tribal deliberately uses
  **hCaptcha**. Copying Tawi's `includes/turnstile.php` would swap providers and break
  Tribal's `HCAPTCHA_*` env config. Leave as-is.
- "properties" vs "venues" naming — divergence, not an upgrade.

---

## PART C — 🟡 Admin polish (from ADMIN_AUDIT.md, still open)

- 🔴 Undefined CSS: `.btn-secondary`, `.admin-table` (should be `.data-table`), `var(--surface)` — render unstyled. ~10 min.
- 🟠 Room drag-reorder AJAX has no CSRF check ([rooms.php:18](admin/rooms.php)).
- 🟠 Forgot-password has no rate limit / captcha ([forgot-password.php:11](admin/forgot-password.php)).
- 🟠 Missing Post-Redirect-Get on settings/conflicts/holds → refresh re-submits.
- 🔵 Confirm `admin/migrate.php` is auth-gated / removed in production.

---

## PART D — Deploy / env checklist

- [ ] **A1** `.htaccess` — add file-block + `-Indexes` + `ErrorDocument`.
- [ ] **A2** `.htaccess` — exclude `/admin/` + `/api/` from strip-`.php`; standardise form targets.
- [ ] **A3** Render — set `RESEND_API_KEY`; verify `MAIL_FROM` domain in Resend.
- [ ] Confirm `DATABASE_URL`, `HCAPTCHA_SITE_KEY`, `HCAPTCHA_SECRET_KEY` set in Render (not just local `.env`).
- [ ] `ICAL_SYNC_SECRET` — auto-generated by `render.yaml` (`generateValue`), OK.
- [ ] TripAdvisor listing + Google Search Console sitemap (from CLAUDE.md "Still Pending").
- [ ] Commit the 4 local room-page fixes (Book-button scroll) + this plan.

---

## Suggested order

1. **A1 + A2** (`.htaccess`) — one file, closes the security holes and the broken-POST bug.
2. **A3** — set Resend key so mail works.
3. **B1** — roles + Users UI (stop managing admins via SQL).
4. **Part C** admin polish (CSS quick wins first).
5. **B2** optional features, if the business wants them.
