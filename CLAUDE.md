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

### hCaptcha — fail-closed in production
`verify_captcha()` is in `includes/turnstile.php`. If `HCAPTCHA_SITE_KEY` is set but secret is missing, it returns `false` (fail-closed). Both absent = dev mode bypass. Never revert to the old `return true` bypass.

### Asset cache busting
All CSS/JS `<link>`/`<script>` tags in `includes/head.php` use `?v=<?= filemtime(...) ?>` to force cache invalidation on deploy.

### Booking form label
`includes/form-enquiry.php` — the submit button says **"Request to Book"** (not "Book Now"). The form creates a 24h hold enquiry, not an instant booking — wrong labels cause chargebacks.

### iCal sync secret
`api/sync-ical.php` — secret is passed via `Authorization: Bearer` header. Legacy `?secret=` query param still works for external crons but the admin Gantt "Sync Now" button uses the header. Never revert to query-param-only (it logs the secret in plaintext).

## File Map

| File | Purpose |
|------|---------|
| `includes/db.php` | DB connection, `client_ip()`, `e()`, `site_url()`, `setting()`, availability helpers |
| `includes/head.php` | SEO meta, OG, structured data, conditional CSS/JS loading |
| `includes/header.php` | Site nav |
| `includes/footer.php` | Footer, WhatsApp float button, cookie consent banner |
| `includes/turnstile.php` | hCaptcha verification (`verify_captcha()`) |
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
- hCaptcha fail-closed: `HCAPTCHA_SITE_KEY` set but secret missing now returns `false` instead of `true`
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
APP_URL=              # https://tribalsand.com (no trailing slash)
HCAPTCHA_SITE_KEY=    # hCaptcha public key
HCAPTCHA_SECRET_KEY=  # hCaptcha secret key
ICAL_SYNC_SECRET=     # Random secret for iCal sync endpoint
RESEND_API_KEY=       # Resend.com API key for emails
MAIL_FROM=            # noreply@yourdomain.com (must be Resend-verified domain)
```
