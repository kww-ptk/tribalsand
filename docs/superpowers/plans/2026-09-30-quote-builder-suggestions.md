# Quote Builder Suggestions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** "Suggest options" in the quote builder: from dates + party, list per-property options (singles / combo / whole property) that reception loads into the builder with one click (spec: `docs/superpowers/specs/2026-09-30-quote-builder-suggestions-design.md`).

**Architecture:** pure helpers in `includes/quote-suggest.php` shape `ts_property_configurations()` output into builder rows; `api/quote-builder.php` gains a read-only `suggest` action; the builder script renders the panel and loads an option into the existing rooms table, then re-prices through the unchanged `price` action.

**Tech Stack:** PHP 8.2, PostgreSQL, vanilla JS; tests are plain PHP scripts.

## Contracts

```php
// [{units, capacity}] rows in order → guests per row; fills each row up to units×capacity.
qb_suggest_split_guests(array $rows, int $party): array            // e.g. [[1,4],[1,2]], 5 → [4,1]

// One property's configurations → up to $limit options:
//   ['kind' => 'single'|'combo'|'entire', 'label' => string, 'sleeps' => int,
//    'total' => ?float (null = unpriced), 'currency' => string,
//    'rooms' => [['id' => int, 'qty' => int, 'guests' => int], …]]
// Order: 2 cheapest singles (unpriced last), best combo, whole property. An option
// whose room slug is not in $slugToId (out of scope / unpublished) is dropped.
qb_suggest_venue_options(array $cfg, array $slugToId, int $party, int $limit = 3): array

// Groups ['venue_id','name','location','options'] → ordered: preferred venue, same
// town as it, then cheapest option ($rank(amount, currency) → comparable), empty last.
qb_suggest_order(array $groups, ?int $preferVenueId, ?callable $rank = null): array

// I/O: runs ts_property_configurations() per catalogue property.
qb_suggestions(string $ci, string $co, int $party, ?array $scope, ?int $preferVenueId): array
```

## Tasks

- [ ] **1. Pure helpers (TDD).** Write `tests/quote_suggest_logic.php` covering the contracts above; run → fails (missing file); implement `includes/quote-suggest.php`; run → passes; commit.
- [ ] **2. `qb_suggestions()` + DB round-trip test.** Every suggested id is in `qb_catalog($scope)`; a single's total equals `qb_price_selection()`'s line for that room/dates. Commit.
- [ ] **3. API action `suggest`** in `api/quote-builder.php` (validation: dates via `rates_window_ymd`, ci < co, ≤ 60 nights, party ≥ 1 → else 422). `curl`-free check via the browser in Task 5. Commit.
- [ ] **4. UI.** View: "Suggest options" button in the Rooms card head, `<div class="qb-sugg" data-qb-sugg hidden>` above the table, `data-prefer-venue` on `.qb`, styles. Script: POST `{action:'suggest'}`, render groups (money via `.mny` + `tsMoney.apply`), **Use** = zero all rows, set qty/guests, tick the property chip if unticked, `schedule()`. Commit.
- [ ] **5. Verify in the browser** (dev server): builder page → dates + party → Suggest → Use → table + total match the suggestion; enquiry pop-up → Use → Save to enquiry still creates Option N. Run `quote_builder_logic`, `quote_docs_logic`, `capacity_search_logic`. Update CLAUDE.md. Commit.
