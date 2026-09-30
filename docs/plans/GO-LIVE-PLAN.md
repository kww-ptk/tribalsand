# Tribal Sand — Go-Live Plan (End-to-End)

_Authoritative cutover plan. Last updated 2026-08-13. Supersedes the 2026-07-08 Tawi-comparison draft._

**Goal in one line:** point `tribalsand.com` at THIS new site, keep every URL working, replace eZee, break nothing.

Legend: ✅ done · 🔴 launch blocker · 🟠 important · 🟡 polish (not blocking)

---

## The picture in plain words

Moving your shop to a nicer building while keeping the same street address and sign.
- **Address** = `tribalsand.com` — customers keep typing the same thing.
- **Old building** = current brochure site on Namecheap (no database of its own).
- **New building** = this repo, running on Render (its own booking system on a Neon/Postgres database).

"Going live" = telling the address to point at the new building. Customers don't notice a move — they just walk into a nicer shop.

### Where data lives (old vs new)
| | Old live site (Namecheap) | New site (this repo, Render) |
|---|---|---|
| Database | none of its own | PostgreSQL on **Neon** |
| Bookings | **eZee/IPMS247** (`book.tribalsand.com`) | its own booking/hold system |
| Leads | **GoHighLevel / LeadConnector** | GoHighLevel integration |
| Email | Reservations@Tribalsand.com | unchanged |
| Photos | on the Namecheap disk | **must go to Cloudflare R2** |

Because the old site has **no database**, there is **no customer data to migrate.**

---

## Phase 0 — Decisions (locked)
- ✅ **Same domain, same URLs** — no change visible to customers or Google.
- ✅ **eZee: replaced.** The new site's own booking system takes over. Keep the eZee account + `book.` subdomain alive ONLY until already-booked future stays have checked out, then cancel.
- ✅ **Captcha: Cloudflare Turnstile** (not hCaptcha). Wired in code, `render.yaml`, and the cookie notice as of 2026-08-13.

---

## Phase 1 — Critical code / config blockers

- 🔴 **A1 — Lock down secret files.** `.htaccess` currently does NOT block `.env`, `.git/`, `*.sql`, `*.md`, `router.php`, `/db/`. On the live server these could be downloaded — **the database password would leak.** Add:
  ```apache
  Options -Indexes
  ErrorDocument 404 /404.php
  <FilesMatch "(^\.|\.env|\.sql$|\.md$|\.log$|^composer\.(json|lock)$|^package(-lock)?\.json$|^router\.php$)">
    Require all denied
  </FilesMatch>
  RedirectMatch 404 (?i)/(\.git|logs|docs|deploy|db)(/|$)
  ```
- 🔴 **A3 — Turn on production email.** In Render: set `RESEND_API_KEY`; verify `noreply@tribalsand.com` in Resend. Until then no booking confirmations / password resets send.
- 🔴 **Turnstile keys** — enter the real `TURNSTILE_SITE_KEY` + `TURNSTILE_SECRET_KEY` in Render (by hand; they are `sync:false`).
- ✅ **A2 — POST to `/admin/` & `/api/` forms** — already fixed (current `.htaccess` serves POST directly, never redirects).
- ✅ **Image self-loop landmine** — fixed 2026-08-13 (host-guarded the "borrow images from tribalsand.com" fallback so it can't loop once we ARE tribalsand.com).

---

## Phase 2 — Content & data readiness
- 🔴 **Images → Cloudflare R2.** Deploy the `/images/<venue>` photo library to R2 (set `R2_ACCOUNT_ID` + `R2_ACCESS_KEY` + `R2_SECRET_KEY` + `R2_PUBLIC_URL`). #1 "site looks broken" risk — photos currently only exist by borrowing from the old site.
- 🔴 **Room pricing** — confirm every room has a price set in the Neon DB. Can't sell unpriced rooms.
- 🟠 **Check-in file storage** — set `R2_CHECKIN_BUCKET` (private) so guest passport/waiver scans survive deploys.
- 🟠 **`DATABASE_URL`** in Render points at the real Neon production DB.

---

## Phase 3 — URL parity (keep every page working)
- ✅ Clean-URL scheme replicated; `/foo.php` → `/foo` redirects match the live site 1:1.
- ✅ 3 missing pages ported: `retreats`, `zuri-menu`, `maya-kobe-breakfast`.
- 🟠 **Google Search Console → Pages export** — diff against the repo to catch any old indexed URL nothing links to (the last ~5%). Any gap → port the page or add a 301 in `.htaccess`.

---

## Phase 4 — DNS cutover (the "go live" moment)
1. Day before: lower DNS TTL to 300s (fast switch + fast rollback).
2. In Render: add custom domains `tribalsand.com` + `www.tribalsand.com`.
3. In Namecheap: point ONLY the web records (apex + `www`) at Render.
   **Leave MX/email records AND the `book.` subdomain untouched.**
4. Wait for Render to verify + auto-issue the HTTPS certificate.
5. Keep the old Namecheap site switched on as a rollback safety net.

Note: Namecheap DNS can't ALIAS the bare apex — either redirect apex→www, or move DNS to Cloudflare (CNAME flattening).

---

## Phase 5 — Verify on the live domain
- Homepage + the 3 ported pages + a few blog URLs load over HTTPS (no cert warning).
- Make a **test booking hold**, submit the **enquiry form** (Turnstile passes), **admin login**, images load, **email arrives**.
- `book.tribalsand.com` still reaches eZee; email to Reservations@ still works.

---

## Phase 6 — After cutover
- Monitor 24–48h. Rollback = revert the DNS web records (fast, thanks to low TTL).
- Raise TTL back up; re-submit sitemap to Google Search Console; watch Coverage for new 404s.
- **Wind down eZee** once the new site is proven AND all pre-existing eZee bookings have checked out.
- Not blockers (do later): admin roles + Users UI, admin CSS polish, offers/newsletter features.

---

## The 5 true launch-blockers (everything else is done or non-blocking)
1. 🔴 A1 — lock down secret files (`.htaccess`)
2. 🔴 A3 — set `RESEND_API_KEY` + verify mail domain in Render
3. 🔴 Turnstile keys in Render
4. 🔴 Images → R2
5. 🔴 Room pricing populated in the DB

---

## Deferred / post-launch backlog (NOT blockers — do after go-live)

_Preserved from the 2026-07-08 Tawi-comparison review. None of these block launch, but they're real improvements worth tracking._

### 🟠 Admin roles + Users management UI (B1)
Currently `admin_users` has no roles UI, so new admins are created via raw SQL. Port a `role` column + an `admin/users.php` (add / delete / change-role, with guardrails: can't delete the last super admin, can't demote yourself) + a `require_super_admin()` helper. ~1–2 h.

### 🟡 Admin polish (Part C)
- Undefined CSS renders admin bits unstyled: `.btn-secondary`, `.admin-table` (should be `.data-table`), `var(--surface)`. ~10 min.
- Room drag-reorder AJAX has no CSRF check (`admin/rooms.php`).
- Forgot-password has no rate limit / captcha (`admin/forgot-password.php`).
- Missing Post-Redirect-Get on settings/conflicts/holds → browser refresh re-submits.
- Confirm `admin/migrate.php` is auth-gated or removed in production.

### 🔵 Optional feature systems (only if the business wants them)
- Offers/promotions — `admin/offers.php`, `offer-edit.php`, public `offers.php`, `includes/offer-modal.php`
- Newsletter signup — `api/submit-newsletter.php`
- Guest submission-status page — `includes/submission-status.php`
