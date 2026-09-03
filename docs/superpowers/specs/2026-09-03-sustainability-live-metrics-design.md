# Sustainability page — hero image, interactive sections, live metrics

**Date:** 2026-09-03
**Status:** approved

## Problem

`sustainability.php` shows four environmental figures (27.59 MWh solar, 21.88 T CO2
avoided, ~30 kg beach waste weekly, 100% desalinated water) as hardcoded literals.
The same four are hardcoded a second time on the home page, in cards that already
claim "Live Data". Updating a number means editing two templates and shipping a deploy.

The page also opens on a flat teal hero with no photograph, and its Solar and Ocean
Conservation sections are a paragraph beside a static Font Awesome icon.

## Goals

1. Hero carries a photograph, swappable by the owner.
2. Solar section becomes a two-column layout with interactive live data.
3. Beach cleanup section shows live cumulative progress.
4. All figures come from the database, are owner-editable, and can accrue over time.
5. Home page reads the same metrics — one edit updates both pages.

## Non-goals

- No live meter integration. The owner types readings; the app accrues between them.
- No public API for the figures.
- No changes to the FAQ, CTA or the other three content sections.

## Data model

Migration `db/migrations/add_sustainability_metrics.sql`, applied through the existing
Admin -> Migrations runner (`admin/migrate.php`).

```
sustainability_metrics
  id              SERIAL PK
  metric_key      TEXT UNIQUE     stable id used by templates
  label           TEXT            display label
  value           NUMERIC(14,2)   last known-true reading
  baseline_at     TIMESTAMPTZ     when that reading was taken
  growth_per_day  NUMERIC(12,4)   accrual rate; 0 = static
  max_value       NUMERIC(14,2)   optional cap (NULL = uncapped)
  unit            TEXT            MWh, T, kg, %
  decimals        SMALLINT        display precision
  note            TEXT            small print under the figure
  sort_order      INT
  is_published    BOOLEAN
  updated_at      TIMESTAMPTZ
  updated_by      INT
```

Seeded keys: `solar_mwh`, `co2_tonnes`, `beach_kg_total`, `beach_kg_weekly`, `desal_pct`.

### Accrual

    current = min(value + growth_per_day * days_since(baseline_at), max_value)

Days are fractional and Nairobi-local (the app's timezone), so a figure creeps through
the day instead of jumping at midnight. It never decreases: elapsed days are clamped
at >= 0 so a future `baseline_at` cannot read below the stored value.

Saving a *changed* `value` in admin resets `baseline_at` to now. This is what keeps
corrections honest — re-entering a real meter reading re-bases the accrual instead of
compounding on top of an already-accrued number.

### Rate defaults

`solar_mwh` and `co2_tonnes` seed with `growth_per_day = 0`. These are public
environmental claims and 27.59 MWh currently reads as a 2024 annual total; silently
converting it into an auto-incrementing counter would change what the site asserts.
The admin field carries a suggested rate in its hint, and the owner opts in per metric.

`beach_kg_total` seeds with 30/7 = 4.2857 kg/day, which is exactly the weekly rate the
page already publishes, so accrual there restates an existing claim rather than a new one.

`beach_kg_weekly` (the rate itself) and `desal_pct` are static: `growth_per_day = 0`,
and `desal_pct` is capped at 100.

## Components

### `includes/sustainability.php`

| function | contract |
|---|---|
| `sustainability_supported()` | `to_regclass` probe, cached per request |
| `sus_metrics()` | published rows keyed by `metric_key`, ordered |
| `sus_metric(string $key)` | one row, or the fallback default |
| `sus_metric_current(array $m): float` | the accrual formula above |
| `sus_metric_display(array $m): string` | `number_format` to `decimals` |
| `sus_metric_save(...)` | admin write; re-baselines on value change |

Pre-migration-safe: with no table, `sus_metrics()` returns a hardcoded default set
equal to the figures the page ships with today, so an unapplied migration renders the
current page rather than blanks or zeros. This mirrors `page_content_registry()`.

### `admin/sustainability.php`

Owner-only (`require_owner()` — site-wide config, same tier as Page Content and Site
Menu). PRG + CSRF, house design system, no native chrome. One card per metric:
value, unit, decimals, growth/day, cap, note, published, sort. Each card shows
"renders today as X" so the effect of a rate is visible before saving.

Sidebar: "Sustainability" in the Catalog group, next to Page Content.

### `includes/page-content.php`

New `sustainability` registry entry so the hero is editable in Admin -> Content ->
Pages with the existing media picker: `hero_image`, `hero_eyebrow`, `hero_title`
(html — carries `<em>`), `hero_sub`, `og_image`. Defaults are the copy the page
ships with, so behaviour is unchanged until someone edits it.

### Templates

- **Hero** — full-bleed `hero_image` under a teal gradient, min-height ~62vh, matching
  the About / Weddings / Retreats heroes.
- **Stats strip** — renders `sus_metrics()`, counts up on scroll via
  IntersectionObserver, honours `prefers-reduced-motion` (renders the final value
  immediately), shows a live dot and a relative "updated" stamp.
- **Solar section** — two columns. Copy left. Right is a live card with three tabs
  (Energy / CO2 avoided / Equivalent) switching the figure and its comparison line,
  over an animated bar. Tabs are real `<button role="tab">`s, keyboard reachable.
- **Beach section** — the icon box becomes a live card: cumulative kg counting up, the
  weekly rate beneath, and a progress ring toward the next tonne. Three sub-cards stay.
- **Home page** — the four `sustain-data-card`s read the same metrics.

## Testing

`tests/sustainability_logic.php` — pure logic plus DB assertions inside a rolled-back
transaction, following `tests/reservations_logic.php`:

- accrual at 0, 1, 30 fractional days
- `max_value` clamp; negative elapsed clamped to 0
- `growth_per_day = 0` returns the stored value unchanged
- re-baselining on value change; no re-baseline when only the label changes
- formatting honours `decimals` and `unit`
- pre-migration fallback returns the shipped figures

Browser verification of `sustainability.php` and `index.php` at desktop and 375px.

## Deployment

The local `.env` points at a database separate from production. Migration and seed do
not reach live data — run `add_sustainability_metrics.sql` against production through
`/admin/migrate.php` after the deploy. Until then both pages render the fallback
figures, which are the current ones.
