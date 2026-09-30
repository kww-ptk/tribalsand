# Documentation map

Everything written about the project lives under `docs/`. Nothing here is served
by the website (`.htaccess` returns 404 for `/docs`).

| Start here | |
|---|---|
| [`../README.md`](../README.md) | Run the app locally, everyday commands, deploying |
| [`../CLAUDE.md`](../CLAUDE.md) | The developer handbook: the rules of every feature and why |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | How the system fits together, review findings, clean-up plan |

## Runbooks: how to operate a live integration

| File | Covers |
|---|---|
| [`inbound-mail-setup.md`](inbound-mail-setup.md) | Guest email replies → SES → SNS → the enquiry thread |
| [`email-events-setup.md`](email-events-setup.md) | SES delivery / bounce / complaint events → the email log |
| [`ghl-whatsapp-webhook.md`](ghl-whatsapp-webhook.md) | GoHighLevel inbound WhatsApp webhook |
| [`restaurant-sync.md`](restaurant-sync.md) | Two-way sync with Zuri's restaurant system (flags, stages) |
| [`restaurant-api.md`](restaurant-api.md) | Menu feed + reservation API for the restaurant site |
| [`redirects-map.csv`](redirects-map.csv) | Old URL → new URL map behind the `.htaccess` redirects |
| [`pos/`](pos/) | Point-of-sale plan and the till UI prototype |
| [`ai/`](ai/) | AI assistant notes |

## Specs and implementation plans: one per feature

Written before each feature was built. `CLAUDE.md` links to the relevant one.

- [`superpowers/specs/`](superpowers/specs/): the design of each feature (what and why)
- [`superpowers/plans/`](superpowers/plans/): the step-by-step build plan for each

File names start with the date (`2026-09-29-…`), so they sort oldest → newest.

## `plans/`: older project-level plans and audits

These used to sit in the repository root. They are a **historical record**: much
of the work in them has already been done. Check `CLAUDE.md` and the code for the current
state before acting on anything here.

| File | What it is | Last touched |
|---|---|---|
| [`plans/AWS-GOLIVE-PLAN.md`](plans/AWS-GOLIVE-PLAN.md) | Move to the full AWS stack (ECS, RDS, S3, SES). Supersedes the hosting part of GO-LIVE-PLAN | Aug 2026 |
| [`plans/GO-LIVE-PLAN.md`](plans/GO-LIVE-PLAN.md) | Original end-to-end go-live plan | Aug 2026 |
| [`plans/ACTIVITIES-BOOKING-FIXES-PLAN.md`](plans/ACTIVITIES-BOOKING-FIXES-PLAN.md) | Activities page + booking fixes | Sep 2026 |
| [`plans/ADMIN-UX-TASKS-PLAN.md`](plans/ADMIN-UX-TASKS-PLAN.md) | Admin UX task list | Sep 2026 |
| [`plans/MULTICURRENCY-PLAN.md`](plans/MULTICURRENCY-PLAN.md) | Display-currency system (referenced from `includes/db.php`) | Sep 2026 |
| [`plans/UI-FIXES-PLAN.md`](plans/UI-FIXES-PLAN.md) | Guest-flow UI fixes | Sep 2026 |
| [`plans/PORTAL-PROPERTY-FINANCIALS-PLAN.md`](plans/PORTAL-PROPERTY-FINANCIALS-PLAN.md) | Portal nav, property-edit fix, financial reports | Sep 2026 |
| [`plans/PLAN-fixes-and-promo.md`](plans/PLAN-fixes-and-promo.md) | Fixes + the offers/promo section | Aug 2026 |
| [`plans/SITE-MENU-REDESIGN-AND-TODO.md`](plans/SITE-MENU-REDESIGN-AND-TODO.md) | Site menu (mega menu) redesign | Aug 2026 |
| [`plans/lawyer-and-ux-fixes-plan.md`](plans/lawyer-and-ux-fixes-plan.md) | Legal-integrity + UX fixes | Aug 2026 |
| [`plans/redesign-plan.md`](plans/redesign-plan.md) | Admin + guest-portal redesign | Aug 2026 |
| [`plans/admin-ui-modernization-plan.md`](plans/admin-ui-modernization-plan.md) | Admin UI modernization | Aug 2026 |
| [`plans/admin-ui-audit.md`](plans/admin-ui-audit.md) | Admin UI cleanup spec | Aug 2026 |
| [`plans/TribalSand-Admin-Editors-Plan.docx`](plans/TribalSand-Admin-Editors-Plan.docx) | Admin editors plan (Word) | Aug 2026 |
| [`plans/ADMIN_AUDIT.md`](plans/ADMIN_AUDIT.md) | Admin panel audit | Jul 2026 |
| [`plans/SEO-AUDIT-2026.md`](plans/SEO-AUDIT-2026.md) | SEO audit report | Jun 2026 |

## `reference/`: background material

| File | What it is |
|---|---|
| [`reference/BRIEFING.md`](reference/BRIEFING.md) | The original project brief (April 2026) |
| [`reference/CONTEXT.md`](reference/CONTEXT.md) | Brand knowledge base: tone, properties, audience |
| `reference/GUEST ACKNOWLEDGMENT FORM.pdf` | Guest acknowledgment form (PDF) |

## Where a new document goes

- Designing a feature → `superpowers/specs/YYYY-MM-DD-name-design.md`
- Planning its build → `superpowers/plans/YYYY-MM-DD-name.md`
- Operating something live → a runbook in `docs/`
- **Never the repository root.** The root is for the website's pages and the
  four project files (`README.md`, `CLAUDE.md`, `Dockerfile`, `docker-compose.yml`).
