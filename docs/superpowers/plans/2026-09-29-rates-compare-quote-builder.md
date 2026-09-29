# Rates comparison + Quote Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign `admin/rates.php` into an all-properties Rate card / Timeline / Calendar with a KES | USD switch, and add a Quote Builder (all properties + activities + transfers + custom lines) under Bookings and as a pop-up on enquiries.

**Architecture:** Pure helpers (`includes/rates-compare.php`, the maths half of `includes/quote-builder.php`) are unit-tested without a DB. Every price comes from the booking engine's own resolvers: `rates_nightly_maps()` for nights and a new batch `room_stay_quotes()` (which `room_stay_quote()` now calls) for stay totals. Amounts are rendered in their own currency with `data-amt`/`data-cur`; one small inline script (`admin-money.js`) converts them with the site's `fx_rates()`. The builder is one partial + one script, priced by a JSON endpoint, used on its own page and inside a modal on `admin/submission-view.php`.

**Tech Stack:** PHP 8.2 (vanilla), PostgreSQL via `db_query()`, vanilla JS/CSS (no build). Tests are plain PHP scripts with a `check()` helper.

**Spec:** `docs/superpowers/specs/2026-09-28-rates-compare-design.md`

**Worktree:** `/private/tmp/claude-501/-Users-patrikgiuliana-Desktop-CLAUDE-CODE-Tribal-Sand/44e1c3c3-72dc-4faa-aef9-0e88f0d8ac56/scratchpad/rates-compare` (branch `feat/rates-compare`). All paths below are relative to it. Run PHP with `php` from the worktree root (it has its own `.env`, pointing at the local Postgres).

## Load-bearing facts (read before coding)

- **Admin shell navigation re-runs INLINE `<script>`s only**, never `<script src>` inside page content (`admin/assets/admin-nav.js:36-41`). So page JS is kept in its own `.js` file for editing but EMITTED INLINE with `readfile()` by the partial that needs it, behind a `$GLOBALS` once-guard; the JS itself guards against double-binding (`window.tsMoney`, `root.dataset.qbReady`, `window.__qbGlobal`).
- Every `<select>` inside `.admin-content` is auto-enhanced by `admin/assets/admin-select.js` (optgroups supported). Selects added later: call `window.enhanceSelects(el)`. Resetting a select in code: set `selectedIndex = 0` then dispatch `change` (the enhancer re-syncs its label on `change`).
- The shared datepicker (`js/datepicker.js`, loaded by `admin/_layout.php`) fills range hidden inputs but does NOT fire `change` for ranges — the builder detects date changes by comparing values after each document click.
- `rates.date_to` is EXCLUSIVE; season date runs shown to staff are LAST NIGHTS.
- `pdo_pgsql` booleans may arrive as `true`/`false` or `'t'`/`'f'` — use `qb_bool()`.
- Tour numeric prices (`tours.price_amount`) are **USD**; transfer prices (`service_options`) are in `setting('site_currency','USD')`.
- `require_bookings()` (owner or reception) is the Bookings audience; `admin/submission-view.php` already uses it.
- Money is never summed across currencies without converting: the builder converts every line to the chosen currency with `rc_convert()` and says so in the quote.

## File structure

| File | Status | Responsibility |
|---|---|---|
| `includes/db.php` | modify | add `room_stay_quotes()`; `room_stay_quote()` delegates |
| `includes/rates-compare.php` | create | pure: season order/class, rate-card row, season runs, season mix, currency convert, money text/HTML |
| `admin/assets/admin-money.js` | create | the KES/USD switch (client) |
| `includes/money-switch.php` | create | switch markup + FX config + inline script (once) |
| `admin/rates.php` | rewrite | toolbar + Rate card + Timeline + Calendar |
| `includes/quote-builder.php` | create | pure maths + quote text; catalogue loader; `qb_price_selection()` |
| `api/quote-builder.php` | create | JSON pricing endpoint |
| `includes/quote-builder-view.php` | create | builder markup + CSS (once) + inline script (once) |
| `admin/assets/admin-quote-builder.js` | create | builder behaviour |
| `admin/quote-builder.php` | create | the page |
| `admin/_layout.php` | modify | Bookings → Quote builder link |
| `admin/submission-view.php` | modify | Build quote button + modal + insert-into-reply |
| `tests/rates_compare_logic.php` | create | tests for rates-compare + `room_stay_quotes()` |
| `tests/quote_builder_logic.php` | create | tests for quote-builder |
| `CLAUDE.md` | modify | document the two features |

---

### Task 1: `room_stay_quotes()` — the batch quote

**Files:**
- Modify: `includes/db.php` (the `room_stay_quote()` function, ~line 1591)
- Create: `tests/rates_compare_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/rates_compare_logic.php`:

```php
<?php
declare(strict_types=1);
// Rates comparison + batch quote. Run: php tests/rates_compare_logic.php
// Pure helpers always run; the DB block runs in ONE rolled-back transaction and
// SKIPs when no database is reachable.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/rates-compare.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── DB: room_stay_quotes() is the ONE summation ─────────────────────────────
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { echo "SKIP  DB block (no database)\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        $rooms = db_query('SELECT id, price_amount FROM rooms ORDER BY id LIMIT 2')->fetchAll();
        if (count($rooms) < 2) {
            echo "SKIP  batch quote (need 2 rooms)\n";
        } else {
            [$a, $b] = $rooms;
            db_query("INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
                      VALUES (:r, '2099-03-02', '2099-03-04', 777, 'Peak season')", [':r' => (int)$a['id']]);
            $defaults = [(int)$a['id'] => 100.0, (int)$b['id'] => 50.0];
            $batch = room_stay_quotes($defaults, '2099-03-01', '2099-03-05');
            check('batch: room A == single quote',
                $batch[(int)$a['id']] === room_stay_quote((int)$a['id'], 100.0, '2099-03-01', '2099-03-05'));
            check('batch: room B == single quote',
                $batch[(int)$b['id']] === room_stay_quote((int)$b['id'], 50.0, '2099-03-01', '2099-03-05'));
            check('batch: A = 2 base + 2 override nights', $batch[(int)$a['id']] === ['nights' => 4, 'total' => 1754.0]);
            check('batch: bad window is not a quote',
                room_stay_quotes($defaults, '2099-03-05', '2099-03-01')[(int)$a['id']] === ['nights' => 0, 'total' => 0.0]);
            $withMap = room_stay_quotes($defaults, '2099-03-01', '2099-03-05', true);
            check('batch: withNightly returns the nightly map',
                count($withMap[(int)$a['id']]['nightly']) === 4
                && $withMap[(int)$a['id']]['nightly']['2099-03-02']['label'] === 'Peak season');
            check('single quote keeps its exact shape', array_keys(room_stay_quote((int)$a['id'], 100.0, '2099-03-01', '2099-03-05')) === ['nights', 'total']);
        }
    } finally { $pdo->rollBack(); }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

Also create an empty placeholder `includes/rates-compare.php` so the require succeeds (Task 2 fills it):

```php
<?php
declare(strict_types=1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/rates_compare_logic.php`
Expected: fatal `Call to undefined function room_stay_quotes()`.

- [ ] **Step 3: Implement**

In `includes/db.php` replace the whole `room_stay_quote()` function (its docblock stays; keep it) with:

```php
function room_stay_quote(int $room_id, float $default_price, string $check_in, string $check_out): array {
    return room_stay_quotes([$room_id => $default_price], $check_in, $check_out)[$room_id];
}

/**
 * room_stay_quote() for MANY rooms in ONE query: [room_id => default price] in,
 * [room_id => ['nights' => int, 'total' => float]] out. This IS the summation of
 * the nightly map — room_stay_quote() is a one-room call into it — so a batch
 * quote (the Quote Builder) and a single quote (the booking widget) can never
 * disagree about a stay.
 *
 * $withNightly adds 'nightly' => the resolved per-night map (for season mixes),
 * so a caller that needs both does not run the resolver twice.
 *
 * A window it cannot parse is "not a quote": nights = 0 for every room.
 */
function room_stay_quotes(array $defaults, string $check_in, string $check_out, bool $withNightly = false): array {
    // Required here, not at file scope: rates.php requires this file, so a
    // file-scope require would be a load-order cycle.
    require_once __DIR__ . '/rates.php';

    $out = [];
    foreach ($defaults as $rid => $_) {
        $out[(int)$rid] = ['nights' => 0, 'total' => 0.0] + ($withNightly ? ['nightly' => []] : []);
    }
    // A window we cannot parse is not a $0 stay, and it is not a 47,000-night
    // stay either — strtotime() returns false for garbage, which silently
    // becomes epoch. Every caller must reject nights === 0 before showing a price.
    $ci = rates_window_ymd($check_in);
    $co = rates_window_ymd($check_out);
    if (!$defaults || $ci === null || $co === null || $ci >= $co) return $out;

    $nights = max(1, (int)((strtotime($co) - strtotime($ci)) / 86400));
    foreach (rates_nightly_maps($defaults, $ci, $co) as $rid => $map) {
        // A valid window always yields a full map (defaults included), so this
        // can never sum to zero.
        $total = 0.0;
        foreach ($map as $night) $total += $night['price'];
        $out[(int)$rid] = ['nights' => $nights, 'total' => round($total, 2)]
                        + ($withNightly ? ['nightly' => $map] : []);
    }
    return $out;
}
```

- [ ] **Step 4: Run tests**

Run: `php tests/rates_compare_logic.php && php tests/rates_logic.php | tail -1 && php tests/capacity_search_logic.php | tail -1`
Expected: `ALL PASS` for each (capacity_search may print its own summary; any FAIL is a regression).

- [ ] **Step 5: Commit**

```bash
git add includes/db.php includes/rates-compare.php tests/rates_compare_logic.php
git commit -m "feat(rates): room_stay_quotes() batch — room_stay_quote() delegates to it"
```

---

### Task 2: Pure comparison helpers (`includes/rates-compare.php`)

**Files:**
- Modify: `includes/rates-compare.php`
- Modify: `tests/rates_compare_logic.php`

- [ ] **Step 1: Add failing tests**

In `tests/rates_compare_logic.php`, insert BEFORE the `// ── DB:` block:

```php
// ── Pure helpers ────────────────────────────────────────────────────────────
function n(float $p, ?string $label): array {
    return ['price' => $p, 'label' => $label, 'rate_id' => $label === null ? null : 1, 'is_override' => $label !== null];
}
check('class: standard/mid/peak/other/base',
    rc_season_class('Standard season') === 'std' && rc_season_class('Mid season') === 'mid'
    && rc_season_class('Peak season') === 'peak' && rc_season_class('Christmas') === 'other'
    && rc_season_class(null) === 'base');
check('labels sort Standard, Mid, Peak, then others A-Z',
    rc_sort_labels(['Peak season', 'Zeta', 'Mid season', 'Alpha', 'Standard season'])
        === ['Standard season', 'Mid season', 'Peak season', 'Alpha', 'Zeta']);

$map = ['2027-12-18' => n(97812, 'Mid season'), '2027-12-19' => n(97812, 'Mid season'),
        '2027-12-20' => n(119500, 'Peak season'), '2027-12-21' => n(143400, 'Peak season'),
        '2027-12-22' => n(80000, null), '2027-12-23' => n(5000, '')];
$row = rc_rate_card_row($map, 80000.0);
check('card row: one price per season', $row['seasons']['Mid season'] === ['min' => 97812.0, 'max' => 97812.0]);
check('card row: two peak prices become a range', $row['seasons']['Peak season'] === ['min' => 119500.0, 'max' => 143400.0]);
check('card row: base nights counted, base price kept', $row['base_nights'] === 1 && $row['base'] === 80000.0);
check('card row: unlabelled override is "Other rate"', isset($row['seasons']['Other rate']));

$runs = rc_season_runs([
    1 => ['2027-03-26' => n(1, 'Peak season'), '2027-03-27' => n(1, 'Peak season'), '2027-03-28' => n(1, 'Mid season')],
    2 => ['2027-03-27' => n(1, 'Peak season'), '2027-03-29' => n(1, 'Peak season'), '2027-03-30' => n(1, null)],
]);
check('runs: union across rooms, split on gaps',
    $runs['Peak season'] === [['2027-03-26', '2027-03-27'], ['2027-03-29', '2027-03-29']]);
check('runs: base nights are not a season', !isset($runs['']) && count($runs) === 2);
check('runs: keys in season order', array_keys($runs) === ['Mid season', 'Peak season']);
check('run label: same month', rc_run_label(['2027-12-20', '2027-12-31']) === '20 – 31 Dec');
check('run label: across months', rc_run_label(['2027-03-26', '2027-04-04']) === '26 Mar – 4 Apr');
check('run label: one night', rc_run_label(['2027-12-25', '2027-12-25']) === '25 Dec');

check('mix: counts per season in order',
    rc_season_mix(['a' => n(1, 'Peak season'), 'b' => n(1, 'Mid season'), 'c' => n(1, 'Mid season'), 'd' => n(1, null)])
        === '2 Mid + 1 Peak + 1 Base');
check('mix: empty map', rc_season_mix([]) === '');

$fx = ['USD' => 1.0, 'KES' => 129.0];
check('convert: same currency is exact', rc_convert(48360.0, 'KES', 'KES', $fx) === 48360.0);
check('convert: KES → USD', abs(rc_convert(129000.0, 'KES', 'USD', $fx) - 1000.0) < 0.0001);
check('convert: missing rate is null', rc_convert(10.0, 'KES', 'EUR', $fx) === null);

check('text: KES full', rc_money_text(48360.4, 'KES') === 'KES 48,360');
check('text: USD full', rc_money_text(374.6, 'USD') === '$375');
check('short: KES thousands', rc_money_text(48360, 'KES', true) === '48.4k');
check('short: KES round thousands', rc_money_text(100000, 'KES', true) === '100k');
check('short: KES millions', rc_money_text(1250000, 'KES', true) === '1.25m');
check('short: KES small', rc_money_text(950, 'KES', true) === '950');
check('short: USD', rc_money_text(375.2, 'USD', true) === '$375');
check('html: own currency, exact',
    rc_money_html(48360.0, 'KES', 'KES', $fx) === '<span class="mny" data-amt="48360" data-cur="KES">KES 48,360</span>');
check('html: converted is marked ≈',
    rc_money_html(129000.0, 'KES', 'USD', $fx) === '<span class="mny is-approx" data-amt="129000" data-cur="KES">≈ $1,000</span>');
check('html: short cells carry data-fmt and no ≈ prefix',
    rc_money_html(129000.0, 'KES', 'USD', $fx, true) === '<span class="mny is-approx" data-amt="129000" data-cur="KES" data-fmt="short">$1,000</span>');
```

- [ ] **Step 2: Run to verify failure**

Run: `php tests/rates_compare_logic.php`
Expected: fatal `Call to undefined function rc_season_class()`.

- [ ] **Step 3: Implement `includes/rates-compare.php`**

```php
<?php
declare(strict_types=1);
/**
 * Rates comparison helpers — PURE (no I/O). Shared by admin/rates.php (Rate card,
 * Timeline) and the Quote Builder (season mixes, currency conversion).
 *
 * Every input is a resolved nightly map from rates_nightly_maps() /
 * room_stay_quotes(..., true) — never raw `rates` rows — so nothing here can
 * disagree with what a guest is charged.
 *
 * A "season" is the rate row's free-text label. The three the owner uses
 * (Standard / Mid / Peak season) sort first and carry colours; any other label
 * sorts after them alphabetically; an override with no label is "Other rate";
 * a night with no override is the room's Base price.
 *
 * Money: amounts stay in their OWN currency; rc_money_html() renders them for the
 * chosen currency and carries the original in data-* so admin-money.js can
 * re-render instantly on the KES | USD switch. rc_money_text() and the JS
 * formatter must stay byte-identical (tests/rates_compare_logic.php pins PHP).
 */

/** Colour key for a season label: std | mid | peak | other | base. */
function rc_season_class(?string $label): string {
    if ($label === null || $label === '') return 'base';
    $l = strtolower($label);
    if (str_contains($l, 'peak'))     return 'peak';
    if (str_contains($l, 'mid'))      return 'mid';
    if (str_contains($l, 'standard')) return 'std';
    return 'other';
}

/** Sort rank for a label: Standard 0, Mid 1, Peak 2, anything else 3. */
function rc_season_rank(string $label): int {
    return ['std' => 0, 'mid' => 1, 'peak' => 2][rc_season_class($label)] ?? 3;
}

/** Labels in display order: Standard, Mid, Peak, then the rest A–Z. */
function rc_sort_labels(array $labels): array {
    $labels = array_values(array_unique(array_map('strval', $labels)));
    usort($labels, fn($a, $b) => (rc_season_rank($a) <=> rc_season_rank($b)) ?: strcmp($a, $b));
    return $labels;
}

/** The key a night files under: its label, "Other rate" for an unlabelled override, null for base. */
function rc_night_key(array $night): ?string {
    if (empty($night['is_override'])) return null;
    $l = trim((string)($night['label'] ?? ''));
    return $l !== '' ? $l : 'Other rate';
}

/**
 * One Rate-card row from a room's nightly map.
 * @return array{seasons: array<string, array{min: float, max: float}>, base: float, base_nights: int}
 */
function rc_rate_card_row(array $nightly, float $base): array {
    $seasons = [];
    $baseNights = 0;
    foreach ($nightly as $night) {
        $key = rc_night_key($night);
        if ($key === null) { $baseNights++; continue; }
        $p = (float)$night['price'];
        if (!isset($seasons[$key])) { $seasons[$key] = ['min' => $p, 'max' => $p]; continue; }
        $seasons[$key]['min'] = min($seasons[$key]['min'], $p);
        $seasons[$key]['max'] = max($seasons[$key]['max'], $p);
    }
    return ['seasons' => $seasons, 'base' => $base, 'base_nights' => $baseNights];
}

/**
 * Season date runs across several rooms' nightly maps (the union of their
 * nights), merged into contiguous [firstNight, lastNight] runs. Keyed by label
 * in display order. Base nights are not a season and are left out.
 */
function rc_season_runs(array $maps): array {
    $days = [];
    foreach ($maps as $map) {
        foreach ($map as $ymd => $night) {
            $key = rc_night_key($night);
            if ($key !== null) $days[$key][$ymd] = true;
        }
    }
    $out = [];
    foreach (rc_sort_labels(array_keys($days)) as $key) {
        $dates = array_keys($days[$key]);
        sort($dates);
        $runs = [];
        $start = $prev = null;
        foreach ($dates as $ymd) {
            if ($prev !== null && $ymd === (new DateTime($prev))->modify('+1 day')->format('Y-m-d')) { $prev = $ymd; continue; }
            if ($start !== null) $runs[] = [$start, $prev];
            $start = $prev = $ymd;
        }
        if ($start !== null) $runs[] = [$start, $prev];
        $out[$key] = $runs;
    }
    return $out;
}

/** "26 Mar – 4 Apr", "20 – 31 Dec", or "25 Dec" for a [first, last] night run. */
function rc_run_label(array $run): string {
    [$a, $b] = $run;
    $ta = strtotime($a); $tb = strtotime($b);
    if ($a === $b) return date('j M', $ta);
    if (date('Y-m', $ta) === date('Y-m', $tb)) return date('j', $ta) . ' – ' . date('j M', $tb);
    return date('j M', $ta) . ' – ' . date('j M', $tb);
}

/** "2 Mid + 2 Peak" for a stay's nightly map ("season" dropped; base nights = "Base"). */
function rc_season_mix(array $nightly): string {
    $count = [];
    foreach ($nightly as $night) {
        $key = rc_night_key($night) ?? '';
        $count[$key] = ($count[$key] ?? 0) + 1;
    }
    $keys = array_keys($count);
    usort($keys, function ($a, $b) {
        if ($a === '' || $b === '') return ($a === '') <=> ($b === '');   // base last
        return (rc_season_rank($a) <=> rc_season_rank($b)) ?: strcmp($a, $b);
    });
    $parts = [];
    foreach ($keys as $k) {
        $name = $k === '' ? 'Base' : trim(preg_replace('/\s*season$/i', '', $k));
        $parts[] = $count[$k] . ' ' . $name;
    }
    return implode(' + ', $parts);
}

/** Convert with a USD-based rate table; null when a rate is missing (never a guessed price). */
function rc_convert(float $amt, string $from, string $to, array $rates): ?float {
    $from = strtoupper($from); $to = strtoupper($to);
    if ($from === $to) return $amt;
    $rf = (float)($rates[$from] ?? 0); $rt = (float)($rates[$to] ?? 0);
    if ($rf <= 0 || $rt <= 0) return null;
    return $amt / $rf * $rt;
}

/** Trim trailing zeros from a decimal string: "100.0" → "100", "1.25" stays. */
function rc_trimz(string $s): string {
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/**
 * Display text for an amount already in $cur. Full: "KES 48,360" / "$375"
 * (whole units). Short (timeline cells): "48.4k" / "1.25m" for KES, "$375" /
 * "$120.5k" for other currencies. Mirrored exactly by admin-money.js.
 */
function rc_money_text(float $amt, string $cur, bool $short = false): string {
    $cur = strtoupper($cur);
    $sym = TS_CURRENCIES[$cur]['symbol'] ?? ($cur . ' ');
    if ($short) {
        $a = abs($amt);
        if ($cur === 'KES') {
            if ($a >= 1000000) return rc_trimz(number_format($amt / 1000000, 2, '.', '')) . 'm';
            if ($a >= 1000)    return rc_trimz(number_format($amt / 1000, 1, '.', '')) . 'k';
            return number_format($amt, 0, '.', '');
        }
        if ($a >= 100000) return $sym . rc_trimz(number_format($amt / 1000, 1, '.', '')) . 'k';
    }
    return $sym . number_format($amt, 0);
}

/**
 * An amount for the page: rendered in $to (converted with $rates, marked ≈ and
 * .is-approx) and carrying its original amount + currency so the KES | USD switch
 * re-renders from source. A missing rate leaves it in its own currency.
 */
function rc_money_html(float $amt, string $from, string $to, array $rates, bool $short = false): string {
    $from  = strtoupper($from !== '' ? $from : 'USD');
    $v     = rc_convert($amt, $from, $to, $rates);
    $shown = $v === null ? $from : strtoupper($to);
    $approx = $shown !== $from;
    $txt = rc_money_text($v ?? $amt, $shown, $short);
    return '<span class="mny' . ($approx ? ' is-approx' : '') . '" data-amt="' . e(rc_trimz((string)$amt))
         . '" data-cur="' . e($from) . '"' . ($short ? ' data-fmt="short"' : '') . '>'
         . e(($approx && !$short ? '≈ ' : '') . $txt) . '</span>';
}
```

Note `data-amt` uses `rc_trimz((string)$amt)` so `48360.0` renders as `48360` (the test pins it).

- [ ] **Step 4: Run tests**

Run: `php tests/rates_compare_logic.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/rates-compare.php tests/rates_compare_logic.php
git commit -m "feat(rates): pure comparison helpers — seasons, runs, mix, currency text"
```

---

### Task 3: The KES | USD switch (`admin-money.js` + `includes/money-switch.php`)

**Files:**
- Create: `admin/assets/admin-money.js`
- Create: `includes/money-switch.php`

- [ ] **Step 1: Create `admin/assets/admin-money.js`**

```js
/* KES | USD switch for admin pages (Rates, Quote builder).
 *
 * Every amount on the page is a <span class="mny" data-amt data-cur [data-fmt=short]>
 * holding its ORIGINAL amount + currency (rc_money_html() in PHP). This script
 * re-renders them all in the chosen currency using window.TS_FX (the site's
 * fx_rates(), USD-based) — instant, no reload. Text must stay identical to
 * rc_money_text() in includes/rates-compare.php.
 *
 * Emitted INLINE by includes/money-switch.php (admin shell navigation re-runs
 * inline scripts only), so it guards against running twice.
 */
(function () {
  'use strict';
  if (window.tsMoney) { window.tsMoney.apply(document); return; }
  var KEY = 'ts_admin_cur', ALLOWED = ['KES', 'USD'];

  function cfg() { return window.TS_FX || { rates: { USD: 1 }, symbols: { USD: '$' } }; }
  function sym(c) { var s = cfg().symbols || {}; return s[c] || (c + ' '); }
  function trimz(s) { return s.indexOf('.') >= 0 ? s.replace(/0+$/, '').replace(/\.$/, '') : s; }
  function group(v) { return Math.round(v).toLocaleString('en-US'); }
  function text(v, c, short) {
    if (short) {
      var a = Math.abs(v);
      if (c === 'KES') {
        if (a >= 1000000) return trimz((v / 1000000).toFixed(2)) + 'm';
        if (a >= 1000) return trimz((v / 1000).toFixed(1)) + 'k';
        return String(Math.round(v));
      }
      if (a >= 100000) return sym(c) + trimz((v / 1000).toFixed(1)) + 'k';
    }
    return sym(c) + group(v);
  }
  function convert(a, from, to) {
    if (from === to) return a;
    var r = cfg().rates || {};
    if (!(r[from] > 0) || !(r[to] > 0)) return null;
    return a / r[from] * r[to];
  }
  function initial() {
    var p = null;
    try { p = new URLSearchParams(location.search).get('cur'); } catch (e) {}
    if (ALLOWED.indexOf(p) >= 0) return p;
    try { var s = localStorage.getItem(KEY); if (ALLOWED.indexOf(s) >= 0) return s; } catch (e) {}
    return 'KES';
  }
  var cur = initial();

  function paint(el) {
    var a = parseFloat(el.getAttribute('data-amt')), from = el.getAttribute('data-cur');
    if (isNaN(a) || !from) return;
    var short = el.getAttribute('data-fmt') === 'short';
    var v = convert(a, from, cur), shown = v === null ? from : cur;
    if (v === null) v = a;
    var approx = shown !== from;
    el.textContent = (approx && !short ? '≈ ' : '') + text(v, shown, short);
    el.classList.toggle('is-approx', approx);
  }
  function apply(root) {
    root = root || document;
    Array.prototype.forEach.call(root.querySelectorAll('.mny[data-amt]'), paint);
    Array.prototype.forEach.call(document.querySelectorAll('[data-money-cur]'), function (b) {
      var on = b.getAttribute('data-money-cur') === cur;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    Array.prototype.forEach.call(document.querySelectorAll('a[data-keep-cur]'), function (a) {
      try {
        var u = new URL(a.getAttribute('href'), location.href);
        u.searchParams.set('cur', cur);
        a.setAttribute('href', u.pathname + u.search + u.hash);
      } catch (e) {}
    });
  }
  function set(c) {
    if (ALLOWED.indexOf(c) < 0 || c === cur) return;
    cur = c;
    try { localStorage.setItem(KEY, c); } catch (e) {}
    try {
      var u = new URL(location.href);
      u.searchParams.set('cur', c);
      history.replaceState(history.state, '', u.pathname + u.search + u.hash);
    } catch (e) {}
    apply(document);
    document.dispatchEvent(new CustomEvent('ts:currency', { detail: { cur: c } }));
  }

  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-money-cur]') : null;
    if (!b) return;
    e.preventDefault();
    set(b.getAttribute('data-money-cur'));
  });

  window.tsMoney = { apply: apply, set: set, current: function () { return cur; }, text: text, convert: convert };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { apply(document); });
  else apply(document);
})();
```

- [ ] **Step 2: Create `includes/money-switch.php`**

```php
<?php
/**
 * KES | USD switch (markup) + the client config and script (once per page).
 *
 * Config before include:  $ms_cur  'KES' | 'USD'  — the currency the server
 * rendered the page in (amounts come from rc_money_html()).
 *
 * The script is emitted INLINE via readfile(): admin shell navigation re-runs
 * inline scripts but never an external <script src> inside page content.
 * Depends on includes/db.php (fx_rates(), e(), TS_CURRENCIES).
 */
$__fx   = fx_rates();
$__kes  = (float)($__fx['rates']['KES'] ?? 0);
$__when = !empty($__fx['fetched_at']) ? date('j M', strtotime((string)$__fx['fetched_at'])) : null;
$ms_cur = in_array($ms_cur ?? '', ['KES', 'USD'], true) ? $ms_cur : 'KES';
?>
<span class="mny-wrap">
  <span class="mny-switch" role="group" aria-label="Currency">
    <button type="button" data-money-cur="KES" class="<?= $ms_cur === 'KES' ? 'is-on' : '' ?>" aria-pressed="<?= $ms_cur === 'KES' ? 'true' : 'false' ?>">KES</button>
    <button type="button" data-money-cur="USD" class="<?= $ms_cur === 'USD' ? 'is-on' : '' ?>" aria-pressed="<?= $ms_cur === 'USD' ? 'true' : 'false' ?>">USD</button>
  </span>
  <span class="mny-note"><?= $__kes > 0 ? '1 USD = ' . e(rc_trimz(number_format($__kes, 2, '.', ''))) . ' KES' : 'No exchange rate' ?><?= $__when ? ' · updated ' . e($__when) : ' · rate not synced' ?></span>
</span>
<?php if (empty($GLOBALS['__ms_assets_done'])): $GLOBALS['__ms_assets_done'] = true; ?>
<style>
.mny-wrap{display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap}
.mny-switch{display:inline-flex;border:1.5px solid var(--border);border-radius:999px;overflow:hidden;background:var(--white)}
.mny-switch button{border:0;background:transparent;padding:6px 14px;font:inherit;font-size:12.5px;font-weight:600;color:var(--muted);cursor:pointer}
.mny-switch button.is-on{background:var(--brand);color:#fff}
.mny-note{font-size:11.5px;color:var(--muted)}
.mny.is-approx{font-style:italic}
</style>
<script>window.TS_FX = <?= json_encode(['rates' => $__fx['rates'], 'symbols' => array_map(fn($c) => $c['symbol'], TS_CURRENCIES)], JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script><?php readfile(__DIR__ . '/../admin/assets/admin-money.js'); ?></script>
<?php endif; ?>
```

`includes/money-switch.php` uses `rc_trimz()`, so pages must `require_once includes/rates-compare.php` before including it.

- [ ] **Step 3: Lint**

Run: `php -l includes/money-switch.php && node -e "require('fs').readFileSync('admin/assets/admin-money.js','utf8'); new Function(require('fs').readFileSync('admin/assets/admin-money.js','utf8'))" && echo OK`
Expected: `No syntax errors detected` and `OK`. (If `node` is missing, skip the JS parse — the browser check in Task 5 covers it.)

- [ ] **Step 4: Commit**

```bash
git add admin/assets/admin-money.js includes/money-switch.php
git commit -m "feat(admin): KES | USD switch — inline, shell-safe, site FX rate"
```

---

### Task 4: Rates page rewrite (`admin/rates.php`)

**Files:**
- Rewrite: `admin/rates.php`

- [ ] **Step 1: Replace `admin/rates.php` entirely**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/rates-compare.php';

require_login();

// Read-only, so reception may look but not touch — editing lives on the
// owner-only property and room pages. Scoped so a reception account only sees
// rates for its own properties.
//
// Views (each a real URL — bookmarkable, works with JS off):
//   card      every room of every chosen property × its seasons for a year
//   timeline  rooms × days for a month, coloured by season
//   calendar  one property's 3-month calendars (the original page)
// Every figure comes from rates_nightly_maps() — the resolver quotes sum — so
// nothing here can disagree with what a guest is charged. Amounts render in the
// room's own currency and admin-money.js converts them (KES | USD).
$scope = admin_venue_ids();                 // null = owner (every venue)
$venues = $scope === null
    ? db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll()
    : ($scope
        ? db_query('SELECT id, name FROM venues WHERE id IN (' . implode(',', array_map('intval', $scope)) . ')
                    ORDER BY sort_order ASC, name ASC')->fetchAll()
        : []);
$venueIds  = array_map(fn($v) => (int)$v['id'], $venues);
$venueName = array_column($venues, 'name', 'id');

$view = in_array($_GET['view'] ?? '', ['card', 'timeline', 'calendar'], true) ? (string)$_GET['view'] : 'card';
$cur  = in_array($_GET['cur'] ?? '', ['KES', 'USD'], true) ? (string)$_GET['cur'] : 'KES';
$fx   = fx_rates()['rates'];

// ?venues=1,4 narrows the comparison. Ids outside the account's own list are
// dropped, never honoured.
$picked = array_values(array_intersect(
    array_map('intval', array_filter(explode(',', (string)($_GET['venues'] ?? '')), 'strlen')),
    $venueIds
));
$allPicked = !$picked || count($picked) === count($venueIds);
if (!$picked) $picked = $venueIds;

$year = (int)($_GET['year'] ?? 0);
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month) || rates_window_ymd($month . '-01') === null) $month = date('Y-m');

// Calendar view: one property, validated against the account's list.
$venueId = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
if ($venueId && !in_array($venueId, $venueIds, true)) $venueId = 0;
if (!$venueId && $venueIds) $venueId = $venueIds[0];

$qs = [
    'view'   => $view,
    'venues' => $allPicked ? null : implode(',', $picked),
    'year'   => $view === 'card' ? $year : null,
    'month'  => $view === 'timeline' ? $month : null,
    'venue'  => $view === 'calendar' ? $venueId : null,
    'cur'    => $cur,
];
$url = function (array $over) use ($qs): string {
    $q = array_filter(array_merge($qs, $over), fn($v) => $v !== null && $v !== '');
    return '/admin/rates.php' . ($q ? '?' . http_build_query($q) : '');
};
$calUrl = fn(int $vid): string => $url(['view' => 'calendar', 'venue' => $vid, 'year' => null, 'month' => null, 'venues' => null]);

// Rooms of the chosen properties, in property then room order.
$rooms = ($view !== 'calendar' && $picked)
    ? db_query('SELECT r.id, r.venue_id, r.name, r.price_amount, r.price_currency, r.is_published
                  FROM rooms r JOIN venues v ON v.id = r.venue_id
                 WHERE r.venue_id IN (' . implode(',', $picked) . ')
                 ORDER BY v.sort_order ASC, v.name ASC, r.sort_order ASC, r.id ASC')->fetchAll()
    : [];
$byVenue = [];
foreach ($rooms as $r) $byVenue[(int)$r['venue_id']][] = $r;
$defaults = [];
foreach ($rooms as $r) $defaults[(int)$r['id']] = (float)$r['price_amount'];

if ($view === 'card') {
    $maps = $defaults ? rates_nightly_maps($defaults, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)) : [];
    $cardRows = [];
    $labelSet = [];
    foreach ($rooms as $r) {
        $row = rc_rate_card_row($maps[(int)$r['id']] ?? [], (float)$r['price_amount']);
        $cardRows[(int)$r['id']] = $row;
        foreach (array_keys($row['seasons']) as $l) $labelSet[$l] = true;
    }
    $labels = rc_sort_labels(array_keys($labelSet));
} elseif ($view === 'timeline') {
    $tFrom = $month . '-01';
    $tTo   = (new DateTime($tFrom))->modify('+1 month')->format('Y-m-d');
    $maps  = $defaults ? rates_nightly_maps($defaults, $tFrom, $tTo) : [];
    $days  = [];
    for ($d = new DateTime($tFrom); $d->format('Y-m-d') < $tTo; $d->modify('+1 day')) $days[] = $d->format('Y-m-d');
    $prevMonth = (new DateTime($tFrom))->modify('-1 month')->format('Y-m');
    $nextMonth = (new DateTime($tFrom))->modify('+1 month')->format('Y-m');
}

$pageTitle  = 'Rates';
$activeMenu = 'rates';
include __DIR__ . '/_layout.php';
?>
<style>
.rc-bar{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center;margin:0 0 14px}
.rc-seg{display:inline-flex;border:1.5px solid var(--border);border-radius:10px;overflow:hidden;background:var(--white)}
.rc-seg a{padding:7px 14px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none}
.rc-seg a.is-on{background:var(--brand);color:#fff}
.rc-chips{display:flex;flex-wrap:wrap;gap:6px}
.rc-chip{padding:5px 12px;font-size:12.5px;text-decoration:none}
.rc-chip.is-on{background:var(--brand);border-color:var(--brand);color:#fff}
.rc-period{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:13.5px}
.rc-spacer{flex:1}
.rc-wrap{overflow-x:auto;background:var(--white);border:1px solid var(--border);border-radius:12px}
.rc-table{width:100%;border-collapse:collapse;font-size:13px}
.rc-table th,.rc-table td{padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap}
.rc-table th:first-child,.rc-table td:first-child{text-align:left}
.rc-table thead th{font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:#faf8f5}
.rc-group td{background:#f5f1ea;font-weight:700;text-align:left!important}
.rc-dates{font-weight:400;color:var(--muted);font-size:12px;margin-left:10px}
.rc-room a{color:inherit;text-decoration:none}.rc-room a:hover{text-decoration:underline}
.rc-hidden{font-size:11px;color:var(--muted);margin-left:6px}
.rc-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:none;letter-spacing:0}
.rc-std{background:#eaf3de;color:#27500a}.rc-mid{background:#faeeda;color:#633806}.rc-peak{background:#fcebeb;color:#791f1f}
.rc-other{background:#e6eef5;color:#1f4460}.rc-base{background:transparent;color:inherit}
.rc-tl td,.rc-tl th{padding:6px 5px;font-size:11.5px;text-align:center}
.rc-tl th.is-we,.rc-tl td.is-we{box-shadow:inset 0 0 0 999px rgba(0,0,0,.03)}
.rc-tl td:first-child,.rc-tl th:first-child{position:sticky;left:0;background:var(--white);z-index:1;text-align:left;min-width:170px}
.rc-tl .rc-group td:first-child{background:#f5f1ea}
.rc-legend{display:flex;gap:10px;flex-wrap:wrap;margin:10px 2px 0;font-size:12px;color:var(--muted)}
.rc-foot{margin:8px 2px 0;font-size:12px;color:var(--muted)}
@media (max-width:640px){.rc-spacer{display:none}.rc-bar{gap:8px}}
</style>

<div class="page-header">
  <h1>Rates</h1>
  <a class="btn-outline btn-sm" href="/admin/quote-builder.php" data-keep-cur>Build a quote <?= admin_icon('chevron-right', 14) ?></a>
</div>

<?php if (!$venues): ?>
<div class="alert alert--info">No properties are assigned to your account.</div>
<?php else: ?>

<div class="rc-bar">
  <nav class="rc-seg" aria-label="Rates view">
    <a href="<?= e($url(['view' => 'card', 'month' => null, 'venue' => null, 'year' => $year])) ?>" data-keep-cur class="<?= $view === 'card' ? 'is-on' : '' ?>">Rate card</a>
    <a href="<?= e($url(['view' => 'timeline', 'year' => null, 'venue' => null, 'month' => $month])) ?>" data-keep-cur class="<?= $view === 'timeline' ? 'is-on' : '' ?>">Timeline</a>
    <a href="<?= e($calUrl($venueId)) ?>" data-keep-cur class="<?= $view === 'calendar' ? 'is-on' : '' ?>">Calendar</a>
  </nav>

  <?php if ($view === 'card'): ?>
  <span class="rc-period">
    <a class="btn-icon" href="<?= e($url(['year' => $year - 1])) ?>" data-keep-cur aria-label="Previous year">‹</a>
    <?= $year ?>
    <a class="btn-icon" href="<?= e($url(['year' => $year + 1])) ?>" data-keep-cur aria-label="Next year">›</a>
  </span>
  <?php elseif ($view === 'timeline'): ?>
  <span class="rc-period">
    <a class="btn-icon" href="<?= e($url(['month' => $prevMonth])) ?>" data-keep-cur aria-label="Previous month">‹</a>
    <?= e(date('F Y', strtotime($month . '-01'))) ?>
    <a class="btn-icon" href="<?= e($url(['month' => $nextMonth])) ?>" data-keep-cur aria-label="Next month">›</a>
  </span>
  <?php endif; ?>

  <span class="rc-spacer"></span>
  <?php $ms_cur = $cur; include __DIR__ . '/../includes/money-switch.php'; ?>
</div>

<?php if ($view !== 'calendar' && count($venues) > 1): ?>
<div class="rc-chips" style="margin:-4px 0 14px">
  <?php foreach ($venues as $v):
        $vid = (int)$v['id'];
        $on  = in_array($vid, $picked, true);
        $set = $on ? array_values(array_diff($picked, [$vid])) : array_merge($picked, [$vid]);
        if (!$set) $set = [$vid];                       // never an empty selection
        $href = $url(['venues' => count($set) === count($venueIds) ? null : implode(',', $set)]); ?>
  <a class="optchip rc-chip<?= $on ? ' is-on' : '' ?>" href="<?= e($href) ?>" data-keep-cur><?= e($v['name']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($view === 'card'): ?>
  <?php if (!$rooms): ?>
  <div class="alert alert--info">These properties have no rooms yet.</div>
  <?php else: ?>
  <div class="rc-wrap">
    <table class="rc-table">
      <thead><tr>
        <th>Room</th>
        <?php foreach ($labels as $l): ?><th><span class="rc-pill rc-<?= e(rc_season_class($l)) ?>"><?= e($l) ?></span></th><?php endforeach; ?>
        <th>Base</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byVenue as $vid => $vRooms):
            $vMaps = [];
            foreach ($vRooms as $r) $vMaps[] = $maps[(int)$r['id']] ?? [];
            $runs = rc_season_runs($vMaps); ?>
        <tr class="rc-group"><td colspan="<?= count($labels) + 2 ?>">
          <?= e($venueName[$vid] ?? '') ?>
          <?php foreach ($runs as $l => $rs): ?>
          <span class="rc-dates"><span class="rc-pill rc-<?= e(rc_season_class($l)) ?>"><?= e(preg_replace('/\s*season$/i', '', $l)) ?></span>
            <?= e(implode(' · ', array_map('rc_run_label', $rs))) ?></span>
          <?php endforeach; ?>
        </td></tr>
        <?php foreach ($vRooms as $r):
              $row = $cardRows[(int)$r['id']];
              $c   = (string)($r['price_currency'] ?: 'USD'); ?>
        <tr>
          <td class="rc-room"><a href="<?= e($calUrl((int)$vid)) ?>" data-keep-cur><?= e($r['name']) ?></a><?php if (empty($r['is_published']) || $r['is_published'] === 'f'): ?><span class="rc-hidden">hidden</span><?php endif; ?></td>
          <?php foreach ($labels as $l): $s = $row['seasons'][$l] ?? null; ?>
          <td><?php if (!$s): ?>—<?php elseif ($s['min'] === $s['max']): ?><?= rc_money_html($s['min'], $c, $cur, $fx) ?><?php else: ?><?= rc_money_html($s['min'], $c, $cur, $fx) ?> – <?= rc_money_html($s['max'], $c, $cur, $fx) ?><?php endif; ?></td>
          <?php endforeach; ?>
          <td><?= (float)$r['price_amount'] > 0 ? rc_money_html((float)$r['price_amount'], $c, $cur, $fx) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="rc-foot">Seasons are the rate labels set on each room. <strong>Base</strong> is the room's own price, used on any night without a seasonal rate. Dates are last nights.</p>
  <?php endif; ?>

<?php elseif ($view === 'timeline'): ?>
  <?php if (!$rooms): ?>
  <div class="alert alert--info">These properties have no rooms yet.</div>
  <?php else: ?>
  <div class="rc-wrap">
    <table class="rc-table rc-tl">
      <thead><tr>
        <th>Room</th>
        <?php foreach ($days as $d): $we = in_array((int)date('N', strtotime($d)), [6, 7], true); ?>
        <th class="<?= $we ? 'is-we' : '' ?>"><?= e(date('D', strtotime($d))[0] . ' ' . date('j', strtotime($d))) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($byVenue as $vid => $vRooms): ?>
        <tr class="rc-group"><td><?= e($venueName[$vid] ?? '') ?></td><td colspan="<?= count($days) ?>"></td></tr>
        <?php foreach ($vRooms as $r): $c = (string)($r['price_currency'] ?: 'USD'); $m = $maps[(int)$r['id']] ?? []; ?>
        <tr>
          <td class="rc-room"><a href="<?= e($calUrl((int)$vid)) ?>" data-keep-cur><?= e($r['name']) ?></a></td>
          <?php foreach ($days as $d):
                $night = $m[$d] ?? null;
                $key   = $night ? rc_night_key($night) : null;
                $cls   = rc_season_class($key);
                $we    = in_array((int)date('N', strtotime($d)), [6, 7], true);
                $tip   = $night ? date('D j M', strtotime($d)) . ' · ' . ($key ?? 'Base') . ' · ' . rc_money_text((float)$night['price'], $c) : ''; ?>
          <td class="rc-<?= e($cls) ?><?= $we ? ' is-we' : '' ?>" title="<?= e($tip) ?>"><?= $night && (float)$night['price'] > 0 ? rc_money_html((float)$night['price'], $c, $cur, $fx, true) : '—' ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="rc-legend">
    <span><span class="rc-pill rc-std">Standard</span></span><span><span class="rc-pill rc-mid">Mid</span></span>
    <span><span class="rc-pill rc-peak">Peak</span></span><span><span class="rc-pill rc-other">Other rate</span></span>
    <span>Uncoloured = base price · hover a cell for the full price</span>
  </div>
  <?php endif; ?>

<?php else: /* calendar */ ?>
  <div style="display:flex;gap:8px;align-items:center;margin:0 0 14px">
    <form method="GET" style="margin:0">
      <input type="hidden" name="view" value="calendar">
      <input type="hidden" name="cur" value="<?= e($cur) ?>">
      <select name="venue" class="eselect" onchange="this.form.submit()">
        <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $venueId ? ' selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php
    $calRooms = $venueId
        ? db_query('SELECT id, name, price_amount, price_currency FROM rooms
                     WHERE venue_id = :v ORDER BY sort_order ASC, id ASC', [':v' => $venueId])->fetchAll()
        : [];
    $rateMonth = isset($_GET['rate_month']) && strtotime($_GET['rate_month'] . '-01')
        ? substr((string)$_GET['rate_month'], 0, 7)
        : date('Y-m');
  ?>
  <?php if (!$calRooms): ?>
  <div class="alert alert--info">This property has no rooms yet.</div>
  <?php else: foreach ($calRooms as $r): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title"><?= e($r['name']) ?></span>
      <?php if (is_owner()): ?>
      <a class="btn-sm btn-outline" href="/admin/room-edit.php?id=<?= (int)$r['id'] ?>">Edit rates <?= admin_icon('chevron-right', 14) ?></a>
      <?php endif; ?>
    </div>
    <div class="card__body" style="padding:20px">
      <?php
        $rc_room_id       = (int)$r['id'];
        $rc_default_price = (float)$r['price_amount'];
        $rc_currency      = (string)$r['price_currency'];
        $rc_month         = $rateMonth;
        $rc_base_url      = '/admin/rates.php?view=calendar&venue=' . $venueId;
        include __DIR__ . '/../includes/rate-calendar.php';
      ?>
    </div>
  </div>
  <?php endforeach; endif; ?>
<?php endif; ?>

<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Check `rate-calendar-frag.php` still builds working links**

Run: `grep -n "rc_base_url\|rate_month" admin/rate-calendar-frag.php includes/rate-calendar.php | head -20`
Confirm the partial appends `&rate_month=` to `$rc_base_url` (it contains `?`). If it instead appends `?rate_month=`, change the partial to use `(str_contains($rc_base_url, '?') ? '&' : '?')`. Also confirm `admin/rate-calendar-frag.php` rebuilds its own base URL from `venue`; if it hardcodes `/admin/rates.php?venue=`, change that literal to `/admin/rates.php?view=calendar&venue=`.

- [ ] **Step 3: Lint + load in the browser**

Run: `php -l admin/rates.php`
Expected: no syntax errors. Browser check happens in Task 11 (needs a login).

- [ ] **Step 4: Commit**

```bash
git add admin/rates.php admin/rate-calendar-frag.php includes/rate-calendar.php
git commit -m "feat(rates): Rate card + Timeline across every property, KES | USD, calendar kept"
```

---

### Task 5: Quote Builder maths (pure) — `includes/quote-builder.php`

**Files:**
- Create: `includes/quote-builder.php`
- Create: `tests/quote_builder_logic.php`

- [ ] **Step 1: Write failing tests**

Create `tests/quote_builder_logic.php`:

```php
<?php
declare(strict_types=1);
// Quote Builder — maths, notices, quote text; DB-backed pricing in a rolled-back
// transaction. Run: php tests/quote_builder_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-builder.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
$fx = ['USD' => 1.0, 'KES' => 129.0];

// ── Extras by basis ──────────────────────────────────────────────────────────
check('extra: per stay = unit × qty',    qb_extra_amount(50.0, 2, 'stay', 4) === 100.0);
check('extra: per night = unit × nights × qty', qb_extra_amount(10.0, 2, 'night', 4) === 80.0);
check('extra: per person = unit × qty',  qb_extra_amount(40.0, 3, 'person', 4) === 120.0);
check('extra: unknown basis = per stay', qb_extra_amount(5.0, 1, 'bogus', 4) === 5.0);

// ── Totals ───────────────────────────────────────────────────────────────────
$t = qb_totals([['amt' => 100000.0, 'cur' => 'KES']], [['amt' => 50.0, 'cur' => 'USD']], 10.0, 'KES', $fx);
check('totals: discount on accommodation only', $t['discount'] === 10000.0);
check('totals: extras converted to the quote currency', $t['extras'] === 6450.0);
check('totals: total = acc − discount + extras', $t['total'] === 96450.0);
check('totals: flags a conversion', $t['converted'] === true);
$t2 = qb_totals([['amt' => 1000.0, 'cur' => 'KES']], [], 0.0, 'KES', $fx);
check('totals: single currency is exact', $t2['converted'] === false && $t2['total'] === 1000.0);
check('totals: discount clamps to 100%', qb_totals([['amt' => 10.0, 'cur' => 'KES']], [], 250.0, 'KES', $fx)['discount'] === 10.0);
$t3 = qb_totals([['amt' => 10.0, 'cur' => 'EUR']], [], 0.0, 'KES', $fx);
check('totals: a missing rate is reported, never summed as 0', $t3['missing'] === ['EUR'] && $t3['total'] === 0.0);

// ── Notices ──────────────────────────────────────────────────────────────────
$picked = [
    ['name' => 'Zuri — Maji Suite', 'qty' => 1, 'guests' => 3, 'capacity' => 2, 'free' => 1, 'free_exact' => true],
    ['name' => 'Maya Kobe — Haze Suite', 'qty' => 2, 'guests' => 2, 'capacity' => 4, 'free' => 1, 'free_exact' => true],
];
$ns = array_column(qb_notices($picked, 6, true, []), 'text');
check('notice: over a room\'s capacity', in_array('Zuri — Maji Suite: 3 guests, sleeps 2.', $ns, true));
check('notice: fewer free than asked', in_array('Maya Kobe — Haze Suite: only 1 free for these dates.', $ns, true));
check('notice: rooms sleep less than the party', in_array('The rooms chosen sleep 6; the party is 6.', $ns, true) === false);
check('notice: allocation differs from party', in_array('Guests allocated (5) differ from the party (6).', $ns, true));
$ns2 = array_column(qb_notices([], 2, false, []), 'text');
check('notice: bad dates', in_array('Choose check-in and check-out dates.', $ns2, true));
$ns3 = array_column(qb_notices([['name' => 'X', 'qty' => 1, 'guests' => 2, 'capacity' => 2, 'free' => 0, 'free_exact' => false]], 2, true, ['Sunset dhow']), 'text');
check('notice: composite product not free', in_array('X: not free for these dates.', $ns3, true));
check('notice: unpriced extra', in_array('Sunset dhow: add a price.', $ns3, true));

// ── Quote text ───────────────────────────────────────────────────────────────
$txt = qb_quote_text([
    'name' => 'Sofia Martin', 'check_in' => '2027-03-24', 'check_out' => '2027-03-28', 'nights' => 4,
    'adults' => 2, 'children' => 1, 'currency' => 'KES', 'today' => '2026-09-29',
    'rooms' => [['name' => 'Zuri — Maji Suite', 'qty' => 1, 'mix' => '2 Mid + 2 Peak', 'amt' => 229680.0]],
    'extras' => [['label' => 'Airport → Property', 'qty' => 1, 'amt' => 6450.0]],
    'discount_pct' => 10.0, 'discount_note' => 'Returning guest', 'discount' => 22968.0,
    'total' => 213162.0, 'fx_note' => 'Converted at 1 USD = 129 KES on 29 Sep 2026.',
]);
check('text: header', str_starts_with($txt, "Tribal Sand — quote for Sofia Martin\n24 Mar 2027 → 28 Mar 2027 · 4 nights · 2 adults, 1 child"));
check('text: room line', str_contains($txt, '• Zuri — Maji Suite × 1 (2 Mid + 2 Peak): KES 229,680'));
check('text: discount line', str_contains($txt, 'Discount 10% (Returning guest): −KES 22,968'));
check('text: extra line', str_contains($txt, '• Airport → Property × 1: KES 6,450'));
check('text: total + fx + validity', str_contains($txt, "Total: KES 213,162\nConverted at 1 USD = 129 KES on 29 Sep 2026.\nPrices valid on 29 Sep 2026; subject to availability until booked."));
check('fx note', qb_fx_note($fx, 'KES', '2026-09-29') === 'Converted at 1 USD = 129 KES on 29 Sep 2026.');

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run to verify failure**

Run: `php tests/quote_builder_logic.php`
Expected: fatal — `includes/quote-builder.php` missing.

- [ ] **Step 3: Implement the pure half of `includes/quote-builder.php`**

```php
<?php
declare(strict_types=1);
/**
 * Quote Builder — prices a staff quote across every property, plus activities,
 * transfers and custom lines. Used by admin/quote-builder.php and the enquiry
 * pop-up (admin/submission-view.php) through api/quote-builder.php.
 *
 * READ-ONLY: nothing is saved, no hold is placed, nothing is sent. The output is
 * text / a printable page that staff send through the normal reply flow.
 *
 * ONE pricing path: room figures come from room_stay_quotes() (the same
 * summation room_stay_quote() — the booking widget's quote — runs), availability
 * from count_available_units() / find_available_unit(). Activities are priced
 * from tours.price_amount (USD), transfers from service_options (site currency).
 * A client-sent price is used only for custom lines and explicitly edited
 * catalogue prices — staff input by definition.
 *
 * Money: each line keeps its own currency; qb_totals() converts every line to
 * the quote currency with rc_convert() and the quote says when it did.
 * Discount % applies to accommodation only (extras are never discounted).
 *
 * Depends on includes/db.php; requires rates.php, rates-compare.php, services.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/rates-compare.php';
require_once __DIR__ . '/services.php';

const QB_CURRENCIES = ['KES', 'USD'];
const QB_BASES      = ['stay', 'night', 'person'];

/** pdo_pgsql booleans arrive as bool or 't'/'f'. */
function qb_bool($v): bool {
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

/** An extra's line amount for its basis (unknown basis = per stay). */
function qb_extra_amount(float $unit, int $qty, string $basis, int $nights): float {
    $qty = max(0, $qty);
    $amt = $basis === 'night' ? $unit * max(0, $nights) * $qty : $unit * $qty;
    return round($amt, 2);
}

/**
 * Sum room lines and extra lines into the quote currency. Lines are
 * ['amt' => float, 'cur' => 'KES'|'USD'|…] (null entries are skipped).
 * A line whose currency has no rate is NOT summed (never counted as 0 silently):
 * its currency is listed in 'missing' so the caller can say so.
 */
function qb_totals(array $roomLines, array $extraLines, float $discountPct, string $cur, array $rates): array {
    $converted = false;
    $missing   = [];
    $sum = function (array $lines) use ($cur, $rates, &$converted, &$missing): float {
        $t = 0.0;
        foreach ($lines as $l) {
            if ($l === null) continue;
            $from = strtoupper((string)$l['cur']);
            if ($from !== $cur) $converted = true;
            $v = rc_convert((float)$l['amt'], $from, $cur, $rates);
            if ($v === null) { $missing[$from] = true; continue; }
            $t += $v;
        }
        return round($t, 2);
    };
    $acc  = $sum($roomLines);
    $ext  = $sum($extraLines);
    $pct  = max(0.0, min(100.0, $discountPct));
    $disc = round($acc * $pct / 100, 2);
    return [
        'accommodation' => $acc, 'discount' => $disc, 'discount_pct' => $pct, 'extras' => $ext,
        'total' => round($acc - $disc + $ext, 2), 'converted' => $converted, 'missing' => array_keys($missing),
    ];
}

/**
 * Warnings/infos for the summary panel. $picked rows: name, qty, guests,
 * capacity (already × qty), free (int|null), free_exact (false for Maya Ilai
 * composite products, where free means "at least one"). $unpricedExtras = labels.
 * @return list<array{type: string, text: string}>
 */
function qb_notices(array $picked, int $party, bool $okDates, array $unpricedExtras): array {
    $out = [];
    if (!$okDates) $out[] = ['type' => 'warn', 'text' => 'Choose check-in and check-out dates.'];
    $guests = 0; $cap = 0;
    foreach ($picked as $p) {
        $guests += (int)$p['guests'];
        $cap    += (int)$p['capacity'];
        if ((int)$p['capacity'] > 0 && (int)$p['guests'] > (int)$p['capacity']) {
            $out[] = ['type' => 'warn', 'text' => "{$p['name']}: {$p['guests']} guests, sleeps {$p['capacity']}."];
        }
        if ($okDates && $p['free'] !== null) {
            if (empty($p['free_exact'])) {
                if ((int)$p['free'] === 0) $out[] = ['type' => 'warn', 'text' => "{$p['name']}: not free for these dates."];
            } elseif ((int)$p['qty'] > (int)$p['free']) {
                $out[] = ['type' => 'warn', 'text' => (int)$p['free'] === 0
                    ? "{$p['name']}: not free for these dates."
                    : "{$p['name']}: only {$p['free']} free for these dates."];
            }
        }
    }
    if ($picked && $party > 0 && $cap > 0 && $cap < $party) {
        $out[] = ['type' => 'warn', 'text' => "The rooms chosen sleep {$cap}; the party is {$party}."];
    }
    if ($picked && $party > 0 && $guests !== $party) {
        $out[] = ['type' => 'info', 'text' => "Guests allocated ({$guests}) differ from the party ({$party})."];
    }
    foreach ($unpricedExtras as $label) $out[] = ['type' => 'warn', 'text' => "{$label}: add a price."];
    return $out;
}

/** "Converted at 1 USD = 129 KES on 29 Sep 2026." */
function qb_fx_note(array $rates, string $cur, string $today): string {
    $kes = (float)($rates['KES'] ?? 0);
    return 'Converted at 1 USD = ' . rc_trimz(number_format($kes, 2, '.', '')) . ' KES on '
         . date('j M Y', strtotime($today)) . '.';
}

/** Money for the quote text, in the quote currency ("KES 48,360", "$375"). */
function qb_fmt(float $amt, string $cur): string {
    return rc_money_text($amt, $cur);
}

/**
 * The copy-and-paste quote. $q: name, check_in, check_out, nights, adults,
 * children, currency, today, rooms[name,qty,mix,amt], extras[label,qty,amt],
 * discount_pct, discount_note, discount, total, fx_note (string|null).
 * Amounts are already in $q['currency'].
 */
function qb_quote_text(array $q): string {
    $c = $q['currency'];
    $party = (int)$q['adults'] . ' adult' . ((int)$q['adults'] === 1 ? '' : 's');
    if ((int)$q['children'] > 0) $party .= ', ' . (int)$q['children'] . ' child' . ((int)$q['children'] === 1 ? '' : 'ren');
    $lines = [];
    $lines[] = 'Tribal Sand — quote' . (trim((string)$q['name']) !== '' ? ' for ' . trim((string)$q['name']) : '');
    if ((int)$q['nights'] > 0) {
        $lines[] = date('j M Y', strtotime($q['check_in'])) . ' → ' . date('j M Y', strtotime($q['check_out']))
                 . ' · ' . (int)$q['nights'] . ' night' . ((int)$q['nights'] === 1 ? '' : 's') . ' · ' . $party;
    }
    if ($q['rooms']) {
        $lines[] = '';
        $lines[] = 'Accommodation';
        foreach ($q['rooms'] as $r) {
            $lines[] = '• ' . $r['name'] . ' × ' . (int)$r['qty'] . ($r['mix'] !== '' ? ' (' . $r['mix'] . ')' : '')
                     . ': ' . qb_fmt((float)$r['amt'], $c);
        }
        if ((float)$q['discount'] > 0) {
            $lines[] = 'Discount ' . rc_trimz(number_format((float)$q['discount_pct'], 2, '.', '')) . '%'
                     . (trim((string)$q['discount_note']) !== '' ? ' (' . trim((string)$q['discount_note']) . ')' : '')
                     . ': −' . qb_fmt((float)$q['discount'], $c);
        }
    }
    if ($q['extras']) {
        $lines[] = '';
        $lines[] = 'Extras';
        foreach ($q['extras'] as $x) {
            $lines[] = '• ' . $x['label'] . ' × ' . (int)$x['qty'] . ': ' . qb_fmt((float)$x['amt'], $c);
        }
    }
    $lines[] = '';
    $lines[] = 'Total: ' . qb_fmt((float)$q['total'], $c);
    if (!empty($q['fx_note'])) $lines[] = $q['fx_note'];
    $lines[] = 'Prices valid on ' . date('j M Y', strtotime($q['today'])) . '; subject to availability until booked.';
    return implode("\n", $lines);
}
```

- [ ] **Step 4: Run tests**

Run: `php tests/quote_builder_logic.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/quote-builder.php tests/quote_builder_logic.php
git commit -m "feat(quote-builder): pure maths — extras by basis, totals, notices, quote text"
```

---

### Task 6: Catalogue + server pricing (`qb_catalog()`, `qb_price_selection()`)

**Files:**
- Modify: `includes/quote-builder.php` (append)
- Modify: `tests/quote_builder_logic.php` (DB block)

- [ ] **Step 1: Add failing DB tests**

In `tests/quote_builder_logic.php`, insert before the final `echo`:

```php
// ── DB: catalogue + pricing (rolled back) ─────────────────────────────────────
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { echo "SKIP  DB block (no database)\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        $cat = qb_catalog(null);
        check('catalogue: rooms are published only', !array_filter($cat['rooms'], fn($r) => !qb_bool($r['is_published'] ?? true)));
        check('catalogue: every room has max_qty ≥ 1', !array_filter($cat['rooms'], fn($r) => (int)$r['max_qty'] < 1));
        check('catalogue: empty scope sees nothing', qb_catalog([])['rooms'] === []);

        $room = $cat['rooms'][0] ?? null;
        if (!$room) { echo "SKIP  pricing (no published rooms)\n"; }
        else {
            $rid = (int)$room['id'];
            $sel = ['check_in' => '2099-05-10', 'check_out' => '2099-05-13', 'adults' => 2, 'children' => 0,
                    'cur' => strtoupper((string)$room['price_currency']) === 'USD' ? 'USD' : 'KES',
                    'rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2]], 'extras' => [], 'want_free' => true];
            $q = qb_price_selection($sel, null);
            $single = room_stay_quote($rid, (float)$room['price_amount'], '2099-05-10', '2099-05-13');
            $line = null;
            foreach ($q['rooms'] as $r) if ($r['id'] === $rid) $line = $r;
            check('pricing: room line == the booking widget quote', $line && abs($line['line']['amt'] - $single['total']) < 0.001);
            check('pricing: nights', $q['nights'] === 3);
            check('pricing: every catalogue room is priced (avg)', count($q['rooms']) === count($cat['rooms']));
            check('pricing: free computed when asked', array_key_exists('free', $line));

            $foreign = qb_price_selection(['rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2]]] + $sel, []);
            check('pricing: out-of-scope room is dropped', $foreign['rooms'] === [] && $foreign['summary']['accommodation'] === 0.0);

            $bad = qb_price_selection(['check_in' => '2099-05-13', 'check_out' => '2099-05-10'] + $sel, null);
            check('pricing: bad dates → no room lines, a notice', $bad['nights'] === 0
                && in_array('Choose check-in and check-out dates.', array_column($bad['notices'], 'text'), true));

            $cust = qb_price_selection(['extras' => [['key' => 'x1', 'kind' => 'custom', 'label' => 'Private chef',
                'qty' => 2, 'price' => 100, 'price_cur' => 'USD', 'basis' => 'night']]] + $sel, null);
            $x = $cust['extras'][0];
            check('pricing: custom per-night extra', $x['line']['amt'] === 600.0 && $x['line']['cur'] === 'USD');
            check('pricing: copy text present', str_contains($cust['text'], 'Private chef × 2'));
        }
    } finally { $pdo->rollBack(); }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php tests/quote_builder_logic.php`
Expected: fatal `Call to undefined function qb_catalog()`.

- [ ] **Step 3: Append to `includes/quote-builder.php`**

```php
/**
 * What the builder can quote for an account: published rooms of published
 * venues in scope (null = all), published activities (tours) and active
 * transfers. Memoized per scope for the request.
 *
 * max_qty = the room's active units (a Maya Ilai composite product counts the
 * villa units it allocates from — room_inventory_room_id()); a whole-property
 * room is 0/1; a room with no units (enquiry-only) can still be quoted once.
 */
function qb_catalog(?array $scope): array {
    static $memo = [];
    $key = $scope === null ? 'all' : implode(',', array_map('intval', $scope));
    if (isset($memo[$key])) return $memo[$key];
    $empty = ['rooms' => [], 'tours' => [], 'transfers' => [], 'site_currency' => 'USD'];
    if ($scope !== null && !$scope) return $memo[$key] = $empty;

    $where = 'r.is_published = TRUE AND v.is_published = TRUE';
    if ($scope !== null) $where .= ' AND v.id IN (' . implode(',', array_map('intval', $scope)) . ')';
    $rooms = db_query(
        "SELECT r.id, r.slug, r.name, r.venue_id, r.capacity, r.price_amount, r.price_currency,
                r.is_entire_place, r.is_published, v.name AS venue_name,
                (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id AND u.is_active = TRUE) AS unit_count
           FROM rooms r JOIN venues v ON v.id = r.venue_id
          WHERE $where
          ORDER BY v.sort_order ASC, v.name ASC, r.sort_order ASC, r.id ASC"
    )->fetchAll();
    foreach ($rooms as &$r) {
        $r['composite'] = mi_is_composite_room($r);
        $inv   = room_inventory_room_id($r);
        $units = $inv === (int)$r['id']
            ? (int)$r['unit_count']
            : (int)db_query('SELECT COUNT(*) FROM units WHERE room_id = :r AND is_active = TRUE', [':r' => $inv])->fetchColumn();
        $r['units']   = $units;
        $r['max_qty'] = qb_bool($r['is_entire_place']) ? 1 : max(1, $units);
        $r['price_currency'] = strtoupper((string)($r['price_currency'] ?: 'USD'));
    }
    unset($r);

    $tours = [];
    try {
        $tours = db_query(
            "SELECT id, name, category, price_amount, price_per_person
               FROM tours WHERE is_published = TRUE ORDER BY category ASC, sort_order ASC, name ASC"
        )->fetchAll();
    } catch (Throwable $e) { $tours = []; }
    $transfers = array_map(fn($o) => ['id' => (int)$o['id'], 'label' => (string)$o['label'], 'price_amount' => $o['price_amount']],
                           fetch_service_options('transfer'));

    return $memo[$key] = [
        'rooms' => $rooms, 'tours' => $tours, 'transfers' => $transfers,
        'site_currency' => strtoupper(setting('site_currency', 'USD')),
    ];
}

/** Units free for the stay: a count for ordinary rooms, 1/0 for a composite product; null when the room has no units. */
function qb_free_units(array $room, string $ci, string $co): ?int {
    if (!empty($room['composite'])) return find_available_unit((int)$room['id'], $ci, $co) ? 1 : 0;
    if ((int)$room['units'] === 0) return null;
    return count_available_units((int)$room['id'], $ci, $co, $room);
}

/**
 * Price a builder selection. $sel (from the client, untrusted):
 *   name, check_in, check_out, adults, children, discount_pct, discount_note,
 *   cur ('KES'|'USD'), want_free (bool: dates changed → count free for every room),
 *   rooms: [{id, qty, guests}], extras: [{key, kind: tour|transfer|custom, id,
 *   label, qty, price, edited, price_cur, basis}]
 * Room ids are filtered through the account's catalogue ($scope); catalogue
 * extras must exist; prices are looked up server-side except custom lines and
 * edited catalogue prices.
 */
function qb_price_selection(array $sel, ?array $scope): array {
    $cat   = qb_catalog($scope);
    $rates = fx_rates()['rates'];
    $cur   = in_array($sel['cur'] ?? '', QB_CURRENCIES, true) ? (string)$sel['cur'] : 'KES';
    $ci    = rates_window_ymd((string)($sel['check_in'] ?? ''));
    $co    = rates_window_ymd((string)($sel['check_out'] ?? ''));
    $okDates = $ci !== null && $co !== null && $ci < $co;
    $nights  = $okDates ? max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400)) : 0;
    $adults   = max(0, min(500, (int)($sel['adults'] ?? 0)));
    $children = max(0, min(500, (int)($sel['children'] ?? 0)));
    $party    = $adults + $children;
    $wantFree = !empty($sel['want_free']);

    $want = [];
    foreach ((array)($sel['rooms'] ?? []) as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $want[$id] = ['qty' => max(0, (int)($r['qty'] ?? 0)), 'guests' => max(0, (int)($r['guests'] ?? 0))];
    }

    $defaults = [];
    foreach ($cat['rooms'] as $room) $defaults[(int)$room['id']] = (float)$room['price_amount'];
    $quotes = ($okDates && $defaults) ? room_stay_quotes($defaults, $ci, $co, true) : [];

    $roomsOut = []; $picked = []; $roomLines = []; $textRooms = [];
    foreach ($cat['rooms'] as $room) {
        $id   = (int)$room['id'];
        $c    = $room['price_currency'];
        $w    = $want[$id] ?? ['qty' => 0, 'guests' => 0];
        $qty  = min($w['qty'], (int)$room['max_qty']);
        $q    = $quotes[$id] ?? null;
        $unit = ($q && $q['nights'] > 0) ? (float)$q['total'] : null;
        $row  = [
            'id' => $id, 'qty' => $qty, 'guests' => $w['guests'],
            'capacity' => (int)$room['capacity'] * max(1, $qty),
            'avg'  => $unit !== null ? ['amt' => round($unit / $nights, 2), 'cur' => $c] : null,
            'line' => ($unit !== null && $qty > 0) ? ['amt' => round($unit * $qty, 2), 'cur' => $c] : null,
            'mix'  => $q ? rc_season_mix($q['nightly']) : '',
        ];
        if ($okDates && ($wantFree || $qty > 0)) {
            $row['free'] = qb_free_units($room, $ci, $co);
            $row['free_exact'] = empty($room['composite']);
        }
        $roomsOut[] = $row;
        if ($qty > 0) {
            $name = $room['venue_name'] . ' — ' . $room['name'];
            $picked[] = ['name' => $name, 'qty' => $qty, 'guests' => $w['guests'],
                         'capacity' => (int)$room['capacity'] * $qty,
                         'free' => $row['free'] ?? null, 'free_exact' => $row['free_exact'] ?? true];
            $roomLines[] = $row['line'];
            if ($row['line']) $textRooms[] = ['name' => $name, 'qty' => $qty, 'mix' => $row['mix'], 'line' => $row['line']];
        }
    }

    $tourById = [];
    foreach ($cat['tours'] as $t) $tourById[(int)$t['id']] = $t;
    $transferById = [];
    foreach ($cat['transfers'] as $t) $transferById[(int)$t['id']] = $t;

    $extrasOut = []; $extraLines = []; $unpriced = []; $textExtras = [];
    foreach (array_slice((array)($sel['extras'] ?? []), 0, 50) as $x) {
        $key  = substr(preg_replace('/[^a-z0-9_-]/i', '', (string)($x['key'] ?? '')), 0, 20);
        $kind = (string)($x['kind'] ?? '');
        $qtyX = max(0, min(1000, (int)($x['qty'] ?? 0)));
        $sent = isset($x['price']) && is_numeric($x['price']) && (float)$x['price'] >= 0 ? (float)$x['price'] : null;
        $label = null; $unitX = null; $curX = null; $basis = 'stay';
        if ($kind === 'tour' && isset($tourById[(int)($x['id'] ?? 0)])) {
            $t = $tourById[(int)$x['id']];
            $label = (string)$t['name'];
            $basis = qb_bool($t['price_per_person']) ? 'person' : 'stay';
            $curX  = 'USD';
            $unitX = !empty($x['edited']) && $sent !== null ? $sent
                   : (is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : $sent);
        } elseif ($kind === 'transfer' && isset($transferById[(int)($x['id'] ?? 0)])) {
            $t = $transferById[(int)$x['id']];
            $label = (string)$t['label'];
            $curX  = $cat['site_currency'];
            $unitX = !empty($x['edited']) && $sent !== null ? $sent
                   : (is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : $sent);
        } elseif ($kind === 'custom') {
            $label = trim(mb_substr((string)($x['label'] ?? ''), 0, 120));
            if ($label === '') $label = 'Custom item';
            $curX  = in_array($x['price_cur'] ?? '', QB_CURRENCIES, true) ? (string)$x['price_cur'] : $cur;
            $basis = in_array($x['basis'] ?? '', QB_BASES, true) ? (string)$x['basis'] : 'stay';
            $unitX = $sent;
        } else {
            continue;                                  // unknown or out-of-catalogue extra: ignored
        }
        $line = $unitX !== null ? ['amt' => qb_extra_amount($unitX, $qtyX, $basis, $nights), 'cur' => $curX] : null;
        if ($unitX === null) $unpriced[] = $label;
        $extrasOut[] = ['key' => $key, 'label' => $label, 'qty' => $qtyX, 'basis' => $basis,
                        'unit' => $unitX !== null ? ['amt' => $unitX, 'cur' => $curX] : null, 'line' => $line];
        $extraLines[] = $line;
        if ($line) $textExtras[] = ['label' => $label, 'qty' => $qtyX, 'line' => $line];
    }

    $pct = (float)($sel['discount_pct'] ?? 0);
    $t   = qb_totals($roomLines, $extraLines, $pct, $cur, $rates);
    $capTotal = array_sum(array_column($picked, 'capacity'));
    $notices  = qb_notices($picked, $party, $okDates, $unpriced);
    foreach ($t['missing'] as $m) $notices[] = ['type' => 'warn', 'text' => "No exchange rate for {$m}; those lines are left out of the total."];
    $today  = date('Y-m-d');
    $fxNote = $t['converted'] ? qb_fx_note($rates, $cur, $today) : null;
    $conv   = fn(array $l): float => round(rc_convert((float)$l['amt'], $l['cur'], $cur, $rates) ?? 0.0, 2);

    $breakdown = [];
    foreach ($textRooms as $r) $breakdown[] = ['kind' => 'room', 'label' => $r['name'] . ' × ' . $r['qty'], 'amt' => $conv($r['line'])];
    if ($t['discount'] > 0) {
        $note = trim(mb_substr((string)($sel['discount_note'] ?? ''), 0, 80));
        $breakdown[] = ['kind' => 'discount', 'label' => 'Discount ' . rc_trimz(number_format($t['discount_pct'], 2, '.', '')) . '%' . ($note !== '' ? " ({$note})" : ''), 'amt' => $t['discount']];
    }
    foreach ($textExtras as $x) $breakdown[] = ['kind' => 'extra', 'label' => $x['label'] . ' × ' . $x['qty'], 'amt' => $conv($x['line'])];
    $breakdown[] = ['kind' => 'total', 'label' => 'Total', 'amt' => $t['total']];

    $text = qb_quote_text([
        'name' => (string)($sel['name'] ?? ''), 'check_in' => $ci ?? '', 'check_out' => $co ?? '', 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'currency' => $cur, 'today' => $today,
        'rooms'  => array_map(fn($r) => ['name' => $r['name'], 'qty' => $r['qty'], 'mix' => $r['mix'], 'amt' => $conv($r['line'])], $textRooms),
        'extras' => array_map(fn($x) => ['label' => $x['label'], 'qty' => $x['qty'], 'amt' => $conv($x['line'])], $textExtras),
        'discount_pct' => $t['discount_pct'], 'discount_note' => (string)($sel['discount_note'] ?? ''),
        'discount' => $t['discount'], 'total' => $t['total'], 'fx_note' => $fxNote,
    ]);

    return [
        'currency' => $cur, 'check_in' => $ci, 'check_out' => $co, 'nights' => $nights,
        'rooms' => $roomsOut, 'extras' => $extrasOut,
        'summary' => [
            'accommodation' => $t['accommodation'], 'discount' => $t['discount'], 'discount_pct' => $t['discount_pct'],
            'extras' => $t['extras'], 'total' => $t['total'],
            'guests' => $party, 'capacity' => $capTotal,
            'per_guest' => $party > 0 && $t['total'] > 0 ? round($t['total'] / $party, 2) : null,
            'nightly'   => $nights > 0 && $t['accommodation'] > 0 ? round($t['accommodation'] / $nights, 2) : null,
        ],
        'lines' => $breakdown, 'notices' => $notices, 'fx_note' => $fxNote, 'text' => $text,
    ];
}
```

- [ ] **Step 4: Run tests**

Run: `php tests/quote_builder_logic.php && php tests/rates_compare_logic.php | tail -1`
Expected: `ALL PASS` twice.

- [ ] **Step 5: Commit**

```bash
git add includes/quote-builder.php tests/quote_builder_logic.php
git commit -m "feat(quote-builder): catalogue + server pricing (one pricing path, scoped)"
```

---

### Task 7: Pricing endpoint (`api/quote-builder.php`)

**Files:**
- Create: `api/quote-builder.php`

- [ ] **Step 1: Create the endpoint**

```php
<?php
declare(strict_types=1);
/**
 * Quote Builder pricing (JSON). POST {csrf_token, sel} → {ok, quote}.
 *
 * READ-ONLY — prices a selection with the booking engine's own resolvers and
 * returns lines, totals, notices and the quote text. Nothing is saved.
 * Guards: signed-in admin session; Bookings audience (owner or reception, the
 * same rule as require_bookings()); CSRF token in the JSON body (verify_csrf()
 * reads $_POST, which a JSON fetch does not fill) and the session token must be
 * non-empty (hash_equals('', '') is true); rooms scoped by admin_venue_ids().
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-builder.php';

header('Content-Type: application/json');

if (!current_admin()) { http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'Your session expired. Sign in again.'])); }
if (!is_owner() && !is_reception()) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Only the owner and reception can build quotes.'])); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed'])); }

$data  = json_decode((string)file_get_contents('php://input'), true) ?? [];
$token = (string)($data['csrf_token'] ?? '');
$sess  = (string)($_SESSION['csrf_token'] ?? '');
if ($sess === '' || !hash_equals($sess, $token)) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Your session token expired. Reload the page.']));
}

try {
    $sel = is_array($data['sel'] ?? null) ? $data['sel'] : [];
    echo json_encode(['ok' => true, 'quote' => qb_price_selection($sel, admin_venue_ids())]);
} catch (Throwable $e) {
    error_log('[quote-builder] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not price this quote. Try again.']);
}
```

- [ ] **Step 2: Lint**

Run: `php -l api/quote-builder.php`
Expected: no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add api/quote-builder.php
git commit -m "feat(quote-builder): JSON pricing endpoint (session, Bookings audience, CSRF, scoped)"
```

---

### Task 8: Builder view + script

**Files:**
- Create: `includes/quote-builder-view.php`
- Create: `admin/assets/admin-quote-builder.js`

- [ ] **Step 1: Create `includes/quote-builder-view.php`**

```php
<?php
/**
 * The Quote Builder UI — one partial for the page (admin/quote-builder.php) and
 * the enquiry pop-up (admin/submission-view.php). Layout mirrors the Maya Ilai
 * rate tool's Quote Builder tab: Stay details · Rooms · Extras | Summary.
 *
 * Config before include:
 *   $qb_context  'page' | 'modal'   (modal adds "Insert into reply")
 *   $qb_prefill  ['name','check_in','check_out','adults','children','room_id']  (all optional)
 *   $qb_cur      'KES' | 'USD'       initial currency (default KES)
 *
 * Needs includes/quote-builder.php + includes/rates-compare.php loaded. The CSS
 * and the script are emitted once per page, INLINE (shell navigation re-runs
 * inline scripts only). Pricing is always the server's (api/quote-builder.php).
 */
$qb_context = ($qb_context ?? 'page') === 'modal' ? 'modal' : 'page';
$qb_prefill = is_array($qb_prefill ?? null) ? $qb_prefill : [];
$qb_cur     = in_array($qb_cur ?? '', ['KES', 'USD'], true) ? $qb_cur : 'KES';
$__qbCat    = qb_catalog(admin_venue_ids());
$__qbFx     = fx_rates()['rates'];
$__pre      = fn(string $k) => (string)($qb_prefill[$k] ?? '');
$__preRoom  = (int)($qb_prefill['room_id'] ?? 0);
$__preCi    = rates_window_ymd($__pre('check_in')) ?? '';
$__preCo    = rates_window_ymd($__pre('check_out')) ?? '';
$__preAd    = max(0, (int)($qb_prefill['adults'] ?? 2));
$__preCh    = max(0, (int)($qb_prefill['children'] ?? 0));
$__byVenue  = [];
foreach ($__qbCat['rooms'] as $r) $__byVenue[(int)$r['venue_id']][] = $r;
$__clientCat = [
    'tours' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'],
        'price' => is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : null,
        'per_person' => qb_bool($t['price_per_person'])], $__qbCat['tours']),
    'transfers' => array_map(fn($t) => ['id' => $t['id'], 'name' => $t['label'],
        'price' => is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : null], $__qbCat['transfers']),
    'tour_cur' => 'USD', 'transfer_cur' => $__qbCat['site_currency'],
];
$__uid = 'qb' . substr(md5((string)mt_rand()), 0, 6);
?>
<div class="qb" data-qb data-context="<?= e($qb_context) ?>"
     data-endpoint="/api/quote-builder.php" data-csrf="<?= e(csrf_token()) ?>">
  <script type="application/json" data-qb-catalog><?= json_encode($__clientCat, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

  <div class="qb-head">
    <?php if ($qb_context === 'page'): ?>
    <p class="qb-sub">Rooms at live website prices, plus activities, transfers and your own lines. Nothing is saved or booked.</p>
    <?php endif; ?>
    <span class="qb-spacer"></span>
    <?php $ms_cur = $qb_cur; include __DIR__ . '/money-switch.php'; ?>
  </div>

  <div class="qb-grid">
    <div class="qb-stack">
      <section class="qb-card">
        <h3>Stay details</h3>
        <div class="qb-form">
          <label class="qb-field qb-field--wide"><span>Guest or group name</span>
            <input class="inp" data-qb-name value="<?= e($__pre('name')) ?>" placeholder="Sofia Martin"></label>
          <div class="qb-field"><span>Check-in</span>
            <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="<?= e($__uid) ?>" data-dp-target="<?= e($__uid) ?>_ci" data-dp-placeholder="Pick a date"><?= $__preCi ? e(date('j M Y', strtotime($__preCi))) : 'Pick a date' ?></button>
            <input type="hidden" id="<?= e($__uid) ?>_ci" data-qb-ci value="<?= e($__preCi) ?>"></div>
          <div class="qb-field"><span>Check-out</span>
            <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="<?= e($__uid) ?>" data-dp-target="<?= e($__uid) ?>_co" data-dp-placeholder="Pick a date"><?= $__preCo ? e(date('j M Y', strtotime($__preCo))) : 'Pick a date' ?></button>
            <input type="hidden" id="<?= e($__uid) ?>_co" data-qb-co value="<?= e($__preCo) ?>"></div>
          <label class="qb-field"><span>Adults</span><input class="inp inp--num" type="number" min="0" data-qb-adults value="<?= $__preAd ?>"></label>
          <label class="qb-field"><span>Children</span><input class="inp inp--num" type="number" min="0" data-qb-children value="<?= $__preCh ?>"></label>
          <label class="qb-field"><span>Discount %</span><input class="inp inp--num" type="number" min="0" max="100" step="0.5" data-qb-disc placeholder="0"></label>
          <label class="qb-field qb-field--wide"><span>Discount note</span><input class="inp" data-qb-discnote placeholder="Returning guest"></label>
        </div>
      </section>

      <section class="qb-card">
        <div class="qb-card__head"><h3>Rooms</h3>
          <?php if (count($__byVenue) > 1): ?>
          <div class="qb-chips" role="group" aria-label="Show properties">
            <?php foreach ($__byVenue as $vid => $vr): ?>
            <label class="optchip qb-chip"><input type="checkbox" data-qb-venue="<?= (int)$vid ?>" checked> <?= e($vr[0]['venue_name']) ?></label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!$__byVenue): ?>
        <p class="qb-empty">No rooms are available to quote for your account.</p>
        <?php else: ?>
        <div class="qb-tablewrap">
          <table class="qb-table">
            <thead><tr><th>Room</th><th>Qty</th><th>Guests</th><th>Sleeps</th><th>Free</th><th class="qb-money">Avg / night</th><th class="qb-money">Stay total</th></tr></thead>
            <?php foreach ($__byVenue as $vid => $vr): ?>
            <tbody data-qb-group="<?= (int)$vid ?>">
              <tr class="qb-group"><td colspan="7"><?= e($vr[0]['venue_name']) ?></td></tr>
              <?php foreach ($vr as $r): $pre = (int)$r['id'] === $__preRoom; ?>
              <tr data-room="<?= (int)$r['id'] ?>" class="<?= $pre ? 'is-picked' : '' ?>">
                <td><span class="qb-rname"><?= e($r['name']) ?></span><span class="qb-mix" data-qb-mix></span></td>
                <td><input class="inp inp--sm inp--num qb-num" type="number" min="0" max="<?= (int)$r['max_qty'] ?>" value="<?= $pre ? 1 : 0 ?>" data-qb-qty aria-label="Quantity"></td>
                <td><input class="inp inp--sm inp--num qb-num" type="number" min="0" value="<?= $pre ? max(1, $__preAd + $__preCh) : 0 ?>" data-qb-guests aria-label="Guests"></td>
                <td data-qb-cap><?= (int)$r['capacity'] ?: '—' ?></td>
                <td data-qb-free>—</td>
                <td class="qb-money" data-qb-avg>—</td>
                <td class="qb-money" data-qb-line>—</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <section class="qb-card">
        <div class="qb-card__head"><h3>Extras</h3>
          <select class="qb-pick" data-qb-pick aria-label="Add an extra">
            <option value="">+ Add an extra…</option>
            <?php if ($__clientCat['tours']): ?>
            <optgroup label="Activities">
              <?php foreach ($__clientCat['tours'] as $t): ?><option value="tour:<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ($__clientCat['transfers']): ?>
            <optgroup label="Transfers">
              <?php foreach ($__clientCat['transfers'] as $t): ?><option value="transfer:<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <optgroup label="Other"><option value="custom">Custom line</option></optgroup>
          </select>
        </div>
        <div class="qb-tablewrap">
          <table class="qb-table qb-extras">
            <thead><tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Basis</th><th class="qb-money">Total</th><th></th></tr></thead>
            <tbody data-qb-extras><tr class="qb-noextras"><td colspan="6">No extras yet.</td></tr></tbody>
          </table>
        </div>
      </section>
    </div>

    <aside class="qb-summary">
      <div class="qb-totalbox">
        <div class="qb-totalbox__label">Quote total</div>
        <div class="qb-grand" data-qb-total>—</div>
        <div class="qb-per" data-qb-perguest>Add rooms or extras</div>
      </div>
      <div class="qb-metrics">
        <div><span>Nights</span><strong data-qb-m-nights>0</strong></div>
        <div><span>Guests</span><strong data-qb-m-guests>0</strong></div>
        <div><span>Sleeps</span><strong data-qb-m-cap>0</strong></div>
        <div><span>Rooms / night</span><strong data-qb-m-nightly>—</strong></div>
      </div>
      <div class="qb-breakdown" data-qb-breakdown></div>
      <div class="qb-notices" data-qb-notices></div>
      <div class="qb-status" data-qb-status aria-live="polite"></div>
      <div class="qb-actions">
        <button type="button" class="btn-outline btn-sm" data-qb-copy>Copy quote</button>
        <button type="button" class="btn-outline btn-sm" data-qb-print>Print / PDF</button>
        <?php if ($qb_context === 'modal'): ?>
        <button type="button" class="btn-primary btn-sm qb-insert" data-qb-insert>Insert into reply</button>
        <?php endif; ?>
      </div>
      <p class="qb-foot">Maya Ilai group and availability deals: <a href="/admin/maya-ilai-rates.php">Maya Ilai rate tool</a>.</p>
    </aside>
  </div>

  <div class="qb-print" data-qb-printout aria-hidden="true"></div>
</div>

<?php if (empty($GLOBALS['__qb_assets_done'])): $GLOBALS['__qb_assets_done'] = true; ?>
<style>
.qb{--qb-navy:#182247;--qb-navy2:#26335f;--qb-line:var(--border);color:var(--text)}
.qb-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px}
.qb-sub{margin:0;color:var(--muted);font-size:13px}.qb-spacer{flex:1}
.qb-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
.qb-stack{display:grid;gap:16px;min-width:0}
.qb-card{background:var(--white);border:1px solid var(--qb-line);border-radius:14px;padding:16px 18px;min-width:0}
.qb-card h3{margin:0 0 12px;font-size:15px}
.qb-card__head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.qb-card__head h3{margin:0}
.qb-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.qb-field{display:grid;gap:5px;font-size:12px;color:var(--muted);font-weight:600}
.qb-field--wide{grid-column:span 2}
.qb-field .inp,.qb-field .dp-btn{width:100%;box-sizing:border-box}
.qb-chips{display:flex;flex-wrap:wrap;gap:6px}.qb-chip{padding:4px 11px;font-size:12px}
.qb-tablewrap{overflow-x:auto}
.qb-table{width:100%;border-collapse:collapse;font-size:13px}
.qb-table th,.qb-table td{padding:7px 8px;border-bottom:1px solid var(--qb-line);text-align:left;white-space:nowrap}
.qb-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.qb-table .qb-money{text-align:right}
.qb-group td{background:#f5f1ea;font-weight:700;font-size:12.5px}
.qb-table tr.is-picked td{background:#f3faf8}
.qb-rname{display:block}.qb-mix{display:block;font-size:11px;color:var(--muted)}
.qb-num{width:64px}
[data-qb-free].is-none{color:#b91c1c;font-weight:700}
.qb-extras .inp{width:100%;min-width:90px;box-sizing:border-box}
.qb-noextras td{color:var(--muted);font-size:12.5px}
.qb-summary{position:sticky;top:18px;background:var(--white);border:1px solid var(--qb-line);border-radius:14px;overflow:hidden}
.qb-totalbox{background:linear-gradient(145deg,var(--qb-navy),var(--qb-navy2));color:#fff;padding:18px 20px}
.qb-totalbox__label{color:#cdd5ea;font-size:12px}
.qb-grand{font-size:1.9rem;font-weight:800;letter-spacing:-.03em;margin:4px 0}
.qb-per{color:#cdd5ea;font-size:13px}
.qb-metrics{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--qb-line)}
.qb-metrics div{background:var(--white);padding:11px 14px}
.qb-metrics span{display:block;color:var(--muted);font-size:11px}
.qb-metrics strong{font-size:15px}
.qb-breakdown{padding:12px 16px}
.qb-line{display:flex;justify-content:space-between;gap:12px;padding:5px 0;font-size:13px}
.qb-line span:first-child{color:var(--muted)}
.qb-line--total{border-top:1px solid var(--qb-line);margin-top:6px;padding-top:10px;font-weight:800}
.qb-line--total span:first-child{color:var(--text)}
.qb-fx{font-size:11.5px;color:var(--muted);margin:8px 0 0}
.qb-notices{padding:0 16px;display:grid;gap:6px}
.qb-notice{padding:8px 11px;border-radius:9px;font-size:12.5px}
.qb-notice--warn{background:#fff0ed;color:#a3362a}.qb-notice--info{background:#e6f4f2;color:#096c66}
.qb-status{padding:6px 16px 0;font-size:12px;color:var(--muted);min-height:18px}
.qb-actions{display:flex;flex-wrap:wrap;gap:8px;padding:10px 16px 14px}
.qb-foot{padding:0 16px 14px;margin:0;font-size:11.5px;color:var(--muted)}
.qb-empty{color:var(--muted);font-size:13px;margin:0}
.qb-print{display:none}
@media (max-width:1100px){.qb-grid{grid-template-columns:minmax(0,1fr)}.qb-summary{position:static}}
@media (max-width:640px){.qb-form{grid-template-columns:repeat(2,minmax(0,1fr))}.qb-field--wide{grid-column:span 2}}
@media print{
  body *{visibility:hidden!important}
  .qb-print,.qb-print *{visibility:visible!important}
  .qb-print{display:block!important;position:absolute;left:0;top:0;width:100%;padding:24px;font-size:13px;color:#000}
  .qb-print img{height:42px;filter:invert(1)}
  .qb-print h1{font-size:20px;margin:14px 0 4px}
  .qb-print table{width:100%;border-collapse:collapse;margin:12px 0}
  .qb-print td{padding:6px 0;border-bottom:1px solid #ddd}
  .qb-print td:last-child{text-align:right}
  .qb-print .qb-p-total td{font-weight:800;border-bottom:0;font-size:15px}
  .qb-print p{margin:4px 0;color:#444}
}
</style>
<script><?php readfile(__DIR__ . '/../admin/assets/admin-quote-builder.js'); ?></script>
<?php endif; ?>
```

- [ ] **Step 2: Create `admin/assets/admin-quote-builder.js`**

```js
/* Quote builder — the all-properties quote tool (admin/quote-builder.php and the
 * enquiry pop-up in admin/submission-view.php).
 *
 * Server-priced: every change posts the selection to /api/quote-builder.php,
 * which re-prices it with the booking engine's own resolvers and returns lines,
 * totals, notices and the quote text. This script only collects input and
 * paints the answer. Amounts are <span class="mny"> painted by admin-money.js.
 *
 * Emitted INLINE by includes/quote-builder-view.php (admin shell navigation
 * re-runs inline scripts only), so it guards against double-binding.
 */
(function () {
  'use strict';

  function q(root, sel) { return root.querySelector(sel); }
  function qa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function int(v) { var n = parseInt(v, 10); return isNaN(n) || n < 0 ? 0 : n; }
  function num(v) { if (v === '' || v == null) return null; var n = parseFloat(v); return isNaN(n) || n < 0 ? null : n; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function money(o, short) {
    return o ? '<span class="mny" data-amt="' + o.amt + '" data-cur="' + esc(o.cur) + '"' + (short ? ' data-fmt="short"' : '') + '></span>' : '—';
  }
  function curNow() { return window.tsMoney ? window.tsMoney.current() : 'KES'; }

  function init(root) {
    if (root.dataset.qbReady) return;
    root.dataset.qbReady = '1';
    var cat = {};
    try { cat = JSON.parse(q(root, 'script[data-qb-catalog]').textContent || '{}'); } catch (e) { cat = {}; }
    var seq = 0, timer = null, last = null, lastDates = null, freeSeen = {}, xseq = 0;
    root.__qbDates = function () { return q(root, '[data-qb-ci]').value + '|' + q(root, '[data-qb-co]').value; };
    root.__qbSeenDates = root.__qbDates();

    function selection() {
      var ci = q(root, '[data-qb-ci]').value, co = q(root, '[data-qb-co]').value;
      var rooms = qa(root, 'tr[data-room]').map(function (tr) {
        return { id: +tr.getAttribute('data-room'), qty: int(q(tr, '[data-qb-qty]').value), guests: int(q(tr, '[data-qb-guests]').value) };
      }).filter(function (r) { return r.qty > 0; });
      var extras = qa(root, 'tr[data-extra]').map(function (tr) {
        var p = q(tr, '[data-qb-price]');
        var lbl = q(tr, '[data-qb-label]'), pc = q(tr, '[data-qb-pcur]'), bs = q(tr, '[data-qb-basis]');
        return {
          key: tr.getAttribute('data-extra'), kind: tr.getAttribute('data-kind'), id: +(tr.getAttribute('data-id') || 0),
          label: lbl ? lbl.value : '', qty: int(q(tr, '[data-qb-xqty]').value), price: num(p.value),
          edited: p.value !== (p.getAttribute('data-default') || ''),
          price_cur: pc ? pc.value : '', basis: bs ? bs.value : ''
        };
      });
      return {
        name: q(root, '[data-qb-name]').value, check_in: ci, check_out: co,
        adults: int(q(root, '[data-qb-adults]').value), children: int(q(root, '[data-qb-children]').value),
        discount_pct: num(q(root, '[data-qb-disc]').value) || 0, discount_note: q(root, '[data-qb-discnote]').value,
        cur: curNow(), rooms: rooms, extras: extras, want_free: (ci + '|' + co) !== lastDates
      };
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(price, 300); }
    root.__qbSchedule = schedule;

    function price() {
      var s = selection(), my = ++seq, status = q(root, '[data-qb-status]');
      status.textContent = 'Pricing…';
      fetch(root.getAttribute('data-endpoint'), {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: root.getAttribute('data-csrf'), sel: s })
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (d) {
          if (my !== seq) return;
          if (!d || !d.ok) { status.textContent = (d && d.error) || 'Could not price this quote.'; return; }
          status.textContent = '';
          if (s.want_free) { lastDates = s.check_in + '|' + s.check_out; freeSeen = {}; }
          last = d.quote;
          paint(d.quote);
        })
        .catch(function () { if (my === seq) status.textContent = 'Could not reach the server. Your last quote is kept.'; });
    }

    function paint(qt) {
      (qt.rooms || []).forEach(function (r) {
        var tr = q(root, 'tr[data-room="' + r.id + '"]');
        if (!tr) return;
        if ('free' in r) freeSeen[r.id] = { free: r.free, exact: r.free_exact };
        var f = freeSeen[r.id], fc = q(tr, '[data-qb-free]');
        fc.textContent = !f || f.free === null ? '—' : (f.exact === false ? (f.free ? 'Yes' : 'No') : String(f.free));
        fc.classList.toggle('is-none', !!f && f.free === 0);
        q(tr, '[data-qb-cap]').textContent = r.capacity || '—';
        q(tr, '[data-qb-avg]').innerHTML = money(r.avg);
        q(tr, '[data-qb-line]').innerHTML = r.line ? money(r.line) : '—';
        q(tr, '[data-qb-mix]').textContent = r.qty > 0 ? r.mix : '';
        tr.classList.toggle('is-picked', r.qty > 0);
      });
      (qt.extras || []).forEach(function (x) {
        var tr = q(root, 'tr[data-extra="' + x.key + '"]');
        if (tr) q(tr, '[data-qb-xline]').innerHTML = x.line ? money(x.line) : '—';
      });
      var s = qt.summary, c = qt.currency;
      function m(a) { return money({ amt: a, cur: c }); }
      var any = (qt.lines || []).length > 1;
      q(root, '[data-qb-total]').innerHTML = any ? m(s.total) : '—';
      q(root, '[data-qb-perguest]').innerHTML = !any ? 'Add rooms or extras'
        : (s.per_guest !== null ? m(s.per_guest) + ' per guest' : 'Add guests for a per-guest figure');
      q(root, '[data-qb-m-nights]').textContent = qt.nights || 0;
      q(root, '[data-qb-m-guests]').textContent = s.guests;
      q(root, '[data-qb-m-cap]').textContent = s.capacity;
      q(root, '[data-qb-m-nightly]').innerHTML = s.nightly !== null ? m(s.nightly) : '—';
      q(root, '[data-qb-breakdown]').innerHTML = any ? (qt.lines || []).map(function (l) {
        return '<div class="qb-line' + (l.kind === 'total' ? ' qb-line--total' : '') + '"><span>' + esc(l.label) + '</span><span>'
          + (l.kind === 'discount' ? '−' : '') + m(l.amt) + '</span></div>';
      }).join('') + (qt.fx_note ? '<p class="qb-fx">' + esc(qt.fx_note) + '</p>' : '') : '';
      q(root, '[data-qb-notices]').innerHTML = (qt.notices || []).map(function (n) {
        return '<div class="qb-notice qb-notice--' + esc(n.type) + '">' + esc(n.text) + '</div>';
      }).join('');
      if (window.tsMoney) window.tsMoney.apply(root);
    }

    // ── Extras ──────────────────────────────────────────────────────────────
    var tbody = q(root, '[data-qb-extras]');
    function selectHtml(attr, opts, val) {
      return '<select ' + attr + '>' + opts.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (o[0] === val ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      }).join('') + '</select>';
    }
    function addExtra(value) {
      var parts = value.split(':'), kind = parts[0], id = +(parts[1] || 0), item = null, key = 'x' + (++xseq);
      if (kind === 'tour') item = (cat.tours || []).filter(function (t) { return t.id === id; })[0];
      if (kind === 'transfer') item = (cat.transfers || []).filter(function (t) { return t.id === id; })[0];
      if (kind !== 'custom' && !item) return;
      var none = q(tbody, '.qb-noextras'); if (none) none.remove();
      var party = int(q(root, '[data-qb-adults]').value) + int(q(root, '[data-qb-children]').value);
      var tr = document.createElement('tr');
      tr.setAttribute('data-extra', key);
      tr.setAttribute('data-kind', kind);
      if (id) tr.setAttribute('data-id', String(id));
      var def = item && item.price !== null ? String(item.price) : '';
      var curLbl = kind === 'tour' ? cat.tour_cur : cat.transfer_cur;
      var basis = kind === 'tour' ? (item.per_person ? 'per person' : 'per trip') : (kind === 'transfer' ? 'per transfer' : '');
      tr.innerHTML =
        '<td>' + (kind === 'custom' ? '<input class="inp inp--sm" data-qb-label placeholder="Private chef dinner">' : esc(item.name)) + '</td>'
        + '<td><input class="inp inp--sm inp--num qb-num" type="number" min="0" data-qb-xqty value="'
        + (kind === 'tour' && item.per_person ? Math.max(1, party) : 1) + '"></td>'
        + '<td><input class="inp inp--sm inp--num" type="number" min="0" step="0.01" data-qb-price data-default="' + esc(def)
        + '" value="' + esc(def) + '" placeholder="Price">'
        + (kind === 'custom' ? ' ' + selectHtml('data-qb-pcur', [['KES', 'KES'], ['USD', 'USD']], curNow())
          : ' <span class="qb-mix" style="display:inline">' + esc(curLbl) + '</span>') + '</td>'
        + '<td>' + (kind === 'custom'
          ? selectHtml('data-qb-basis', [['stay', 'per stay'], ['night', 'per night'], ['person', 'per person']], 'stay')
          : esc(basis)) + '</td>'
        + '<td class="qb-money" data-qb-xline>—</td>'
        + '<td><button type="button" class="btn-icon" data-qb-xrm aria-label="Remove">×</button></td>';
      tbody.appendChild(tr);
      if (window.enhanceSelects) window.enhanceSelects(tr);
      var focus = q(tr, '[data-qb-label]') || q(tr, '[data-qb-price]');
      if (focus && (kind === 'custom' || def === '')) focus.focus();
      schedule();
    }
    var pick = q(root, '[data-qb-pick]');
    pick.addEventListener('change', function () {
      var v = pick.value;
      if (!v) return;
      addExtra(v);
      pick.selectedIndex = 0;
      pick.dispatchEvent(new Event('change'));          // re-sync the enhanced dropdown label
    });
    tbody.addEventListener('click', function (e) {
      var b = e.target.closest('[data-qb-xrm]');
      if (!b) return;
      b.closest('tr').remove();
      if (!q(tbody, 'tr[data-extra]')) tbody.innerHTML = '<tr class="qb-noextras"><td colspan="6">No extras yet.</td></tr>';
      schedule();
    });

    // ── Property chips hide/show room groups (never changes the selection) ──
    qa(root, '[data-qb-venue]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var g = q(root, 'tbody[data-qb-group="' + cb.getAttribute('data-qb-venue') + '"]');
        if (g) g.hidden = !cb.checked;
      });
    });

    // ── Any input change re-prices ──────────────────────────────────────────
    root.addEventListener('input', function (e) { if (!e.target.closest('[data-qb-pick]')) schedule(); });
    root.addEventListener('change', function (e) { if (!e.target.closest('[data-qb-pick]') && !e.target.closest('[data-qb-venue]')) schedule(); });

    // ── Output ──────────────────────────────────────────────────────────────
    q(root, '[data-qb-copy]').addEventListener('click', function () {
      var b = this;
      if (!last) return;
      var done = function () { var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1400); };
      if (navigator.clipboard) navigator.clipboard.writeText(last.text).then(done, function () {});
    });
    q(root, '[data-qb-print]').addEventListener('click', function () {
      if (!last) return;
      var out = q(root, '[data-qb-printout]'), c = last.currency;
      var t = window.tsMoney ? window.tsMoney.text : function (v) { return String(Math.round(v)); };
      var rows = (last.lines || []).map(function (l) {
        return '<tr class="' + (l.kind === 'total' ? 'qb-p-total' : '') + '"><td>' + esc(l.label) + '</td><td>'
          + (l.kind === 'discount' ? '−' : '') + esc(t(l.amt, c, false)) + '</td></tr>';
      }).join('');
      var lines = last.text.split('\n');
      out.innerHTML = '<img src="/images/whitelogo11.png" alt="Tribal Sand">'
        + '<h1>' + esc(lines[0]) + '</h1>' + (lines[1] ? '<p>' + esc(lines[1]) + '</p>' : '')
        + '<table>' + rows + '</table>'
        + (last.fx_note ? '<p>' + esc(last.fx_note) + '</p>' : '')
        + '<p>' + esc(lines[lines.length - 1]) + '</p>';
      window.print();
    });
    var ins = q(root, '[data-qb-insert]');
    if (ins) ins.addEventListener('click', function () {
      if (!last) return;
      document.dispatchEvent(new CustomEvent('qb:insert', { detail: { text: last.text } }));
    });

    schedule();
  }

  // ── Once per window: dates (the datepicker fires no change for ranges) and
  //    the currency switch re-price every builder on the page. ──────────────
  if (!window.__qbGlobal) {
    window.__qbGlobal = true;
    document.addEventListener('click', function () {
      setTimeout(function () {
        qa(document, '.qb[data-qb-ready]').forEach(function (root) {
          if (!root.__qbDates) return;
          var d = root.__qbDates();
          if (d !== root.__qbSeenDates) { root.__qbSeenDates = d; root.__qbSchedule(); }
        });
      }, 0);
    }, true);
    document.addEventListener('ts:currency', function () {
      qa(document, '.qb[data-qb-ready]').forEach(function (root) { if (root.__qbSchedule) root.__qbSchedule(); });
    });
  }

  qa(document, '.qb[data-qb]').forEach(init);
})();
```

Note `.qb[data-qb-ready]` is the attribute form of `dataset.qbReady`.

- [ ] **Step 3: Lint**

Run: `php -l includes/quote-builder-view.php && (command -v node >/dev/null && node -e "new Function(require('fs').readFileSync('admin/assets/admin-quote-builder.js','utf8'))" && echo JS-OK || echo "node missing — checked in browser")`
Expected: no PHP syntax errors; `JS-OK` (or the node-missing note).

- [ ] **Step 4: Commit**

```bash
git add includes/quote-builder-view.php admin/assets/admin-quote-builder.js
git commit -m "feat(quote-builder): builder UI — stay, rooms, extras, summary, copy/print/insert"
```

---

### Task 9: Quote Builder page + Bookings link

**Files:**
- Create: `admin/quote-builder.php`
- Modify: `admin/_layout.php` (Bookings group, after the Rates link)

- [ ] **Step 1: Create `admin/quote-builder.php`**

```php
<?php
declare(strict_types=1);
/**
 * Admin: Quote builder — quote any mix of rooms across properties, activities,
 * transfers and custom lines, in KES or USD. READ-ONLY: nothing is saved, held
 * or sent (see includes/quote-builder.php). Same audience as the Bookings menu.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-builder.php';

require_bookings();

$pageTitle  = 'Quote builder';
$activeMenu = 'quote_builder';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Quote builder</h1>
  <a class="btn-outline btn-sm" href="/admin/rates.php" data-keep-cur>Rates <?= admin_icon('chevron-right', 14) ?></a>
</div>
<?php
$qb_context = 'page';
$qb_prefill = [];
$qb_cur     = in_array($_GET['cur'] ?? '', ['KES', 'USD'], true) ? (string)$_GET['cur'] : 'KES';
include __DIR__ . '/../includes/quote-builder-view.php';
?>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Add the sidebar link**

In `admin/_layout.php`, directly after the `<a href="/admin/rates.php" …>…Rates</a>` block inside the Bookings group, insert:

```php
        <a href="/admin/quote-builder.php" class="sidebar__link <?= ($activeMenu??'')==='quote_builder' ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>
          Quote builder
        </a>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/quote-builder.php && php -l admin/_layout.php`
Expected: no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add admin/quote-builder.php admin/_layout.php
git commit -m "feat(quote-builder): page under Bookings → Quote builder"
```

---

### Task 10: Enquiry pop-up (`admin/submission-view.php`)

**Files:**
- Modify: `admin/submission-view.php`

- [ ] **Step 1: Make the button row always render, add "Build quote"**

Replace this block (inside `#stForm`):

```php
        <?php if (ai_assistant_supported()): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
          <button type="button" class="btn-outline btn-sm" id="aiDraftBtn"
                  data-endpoint="/api/assistant-draft.php" data-sid="<?= $id ?>" data-csrf="<?= e(csrf_token()) ?>">
            <?= admin_icon('sparkles', 15) ?: '✨' ?> Draft options with AI
          </button>
          <span id="aiDraftMsg" class="text-muted" style="font-size:12.5px"></span>
        </div>
        <?php endif; ?>
```

with:

```php
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
          <button type="button" class="btn-outline btn-sm" id="qbOpenBtn">Build quote</button>
          <?php if (ai_assistant_supported()): ?>
          <button type="button" class="btn-outline btn-sm" id="aiDraftBtn"
                  data-endpoint="/api/assistant-draft.php" data-sid="<?= $id ?>" data-csrf="<?= e(csrf_token()) ?>">
            <?= admin_icon('sparkles', 15) ?: '✨' ?> Draft options with AI
          </button>
          <span id="aiDraftMsg" class="text-muted" style="font-size:12.5px"></span>
          <?php endif; ?>
        </div>
```

- [ ] **Step 2: Add the modal after the closing `</form>` of `#stForm`**

Find the `</form>` that closes `<form method="POST" … id="stForm">` and insert directly after it:

```php
      <?php
        require_once __DIR__ . '/../includes/quote-builder.php';
        $qb_context = 'modal';
        $qb_prefill = [
            'name'      => (string)($sub['guest_name'] ?? ''),
            'check_in'  => (string)($sub['check_in'] ?? ''),
            'check_out' => (string)($sub['check_out'] ?? ''),
            'adults'    => (int)($sub['guests_adults'] ?? 0) ?: 2,
            'children'  => (int)($sub['guests_children'] ?? 0),
            'room_id'   => (int)($sub['room_id'] ?? 0),
        ];
        $qb_cur = 'KES';
      ?>
      <div class="qb-modal" id="qbModal" hidden>
        <div class="qb-modal__back" data-qb-close></div>
        <div class="qb-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="qbModalTitle">
          <div class="qb-modal__head">
            <h2 id="qbModalTitle">Build a quote</h2>
            <button type="button" class="btn-icon" data-qb-close aria-label="Close">×</button>
          </div>
          <?php include __DIR__ . '/../includes/quote-builder-view.php'; ?>
        </div>
      </div>
      <style>
        .qb-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto}
        .qb-modal[hidden]{display:none}
        .qb-modal__back{position:fixed;inset:0;background:rgba(16,47,58,.45)}
        .qb-modal__dialog{position:relative;background:#faf8f5;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);width:100%;max-width:1180px;padding:18px 20px 22px}
        .qb-modal__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
        .qb-modal__head h2{margin:0;font-size:18px}
        @media print{.qb-modal__back{display:none}}
      </style>
      <script>
        (function () {
          var modal = document.getElementById('qbModal'), open = document.getElementById('qbOpenBtn');
          if (!modal || !open) return;
          function close() { modal.hidden = true; document.body.style.overflow = ''; }
          open.addEventListener('click', function () {
            modal.hidden = false; document.body.style.overflow = 'hidden';
            var root = modal.querySelector('.qb[data-qb-ready]');
            if (root && root.__qbSchedule) root.__qbSchedule();
          });
          modal.addEventListener('click', function (e) { if (e.target.closest('[data-qb-close]')) close(); });
          document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
          window.__qbCloseModal = close;
          // Insert into reply — bound ONCE per window (shell navigation re-runs
          // this inline script). Looks elements up at event time.
          if (window.__qbInsertBound) return;
          window.__qbInsertBound = true;
          document.addEventListener('qb:insert', function (e) {
            var box = document.getElementById('replyBody');
            if (!box) return;
            var text = e.detail.text, n = +(box.getAttribute('data-qb-count') || 0);
            if (n === 0) {
              box.value = box.value.trim() ? box.value.replace(/\s+$/, '') + '\n\n' + text : text;
              box.setAttribute('data-qb-first', text);
            } else {
              var first = box.getAttribute('data-qb-first');
              if (n === 1 && first && box.value.indexOf(first) >= 0) box.value = box.value.replace(first, 'Option 1\n' + first);
              box.value = box.value.replace(/\s+$/, '') + '\n\nOption ' + (n + 1) + '\n' + text;
            }
            box.setAttribute('data-qb-count', String(n + 1));
            var reply = document.getElementById('kindReply');
            if (reply) { reply.checked = true; reply.dispatchEvent(new Event('change')); }
            if (window.__qbCloseModal) window.__qbCloseModal();
            box.focus();
          });
        })();
      </script>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/submission-view.php`
Expected: no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add admin/submission-view.php
git commit -m "feat(quote-builder): Build quote pop-up on enquiries, insert as Option 1, 2…"
```

---

### Task 11: Browser verification

**Files:** none (fix-ups only if something is broken; commit each fix separately).

- [ ] **Step 1: Start the dev server from the worktree**

Add a `.claude/launch.json` configuration in the MAIN checkout (not committed) named `rates-compare` that runs `php -S localhost:8767 -t <worktree> <worktree>/router.php` with port 8767, then `preview_start {name: "rates-compare"}`. Log in with a local test owner account (from the project's own seed/fixture files or created locally via the test flow — never a real credential).

- [ ] **Step 2: Rates page**

Visit `/admin/rates.php` (card), `?view=timeline`, `?view=calendar`. Check: every property grouped, season pills, Enkare peak range shows `119,500 – 143,400` only if its 2027/2028 overrides exist locally (otherwise just check layout), KES↔USD flips every figure and marks `≈`, chips narrow properties, Prev/Next work, calendar Prev/Next swap still work. `read_console_messages` → no errors.

- [ ] **Step 3: Quote builder page**

Visit `/admin/quote-builder.php`. Pick dates, set qty on two rooms from different properties, add an activity, a transfer and a custom line, set 10% discount. Check: totals change, notices appear for over-capacity, free column fills, currency switch re-prices, Copy puts text on clipboard (read via `javascript_tool: navigator.clipboard` may be blocked — instead read `last.text` through the Pricing response in `read_network_requests`), Print opens the print dialog (verify the `.qb-print` content via `javascript_tool` instead of printing).

- [ ] **Step 4: Enquiry pop-up**

Open any `/admin/submission-view.php?id=<id>` with dates. Click Build quote → modal pre-filled; add a room; Insert into reply → text in `#replyBody`, "Reply sent to guest" selected; insert a second quote → "Option 1" / "Option 2" labels.

- [ ] **Step 5: Responsive**

`resize_window` 375×812 on each page: `javascript_tool` → `document.documentElement.scrollWidth <= window.innerWidth` must be `true`. Reset with preset `desktop`.

- [ ] **Step 6: Screenshots** for the user: rates card, timeline, builder, modal.

---

### Task 12: Docs + final test run

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a section to `CLAUDE.md`** after "### Nightly rates — per room, edited on the property and the room":

```markdown
### Rates comparison + Quote builder — every property, KES | USD
`admin/rates.php` is now three views (real URLs): **Rate card** (`?view=card`, every room × its season labels for a year, plus Base), **Timeline** (`?view=timeline`, rooms × days coloured by season) and **Calendar** (`?view=calendar&venue=`, the original per-property calendars). **Bookings → Quote builder** (`admin/quote-builder.php`, `require_bookings()`) and the **Build quote** pop-up on `admin/submission-view.php` share ONE component: `includes/quote-builder-view.php` + `admin/assets/admin-quote-builder.js`, priced by `api/quote-builder.php` (session + Bookings audience + CSRF-in-body + `admin_venue_ids()` scope). Spec: `docs/superpowers/specs/2026-09-28-rates-compare-design.md`. Tests: `php tests/rates_compare_logic.php`, `php tests/quote_builder_logic.php`.
- **One pricing path.** `room_stay_quotes()` (batch, `includes/db.php`) is THE summation; `room_stay_quote()` is a one-room call into it. The Rate card / Timeline read `rates_nightly_maps()` — never raw `rates` rows. Don't add a second nightly loop.
- **Quotes are read-only**: nothing saved, no hold, no email. Activities price from `tours.price_amount` (USD), transfers from `service_options` (site currency); a client price is honoured only for custom lines and edited catalogue prices. Discount % hits accommodation only.
- **Currency switch** (`includes/money-switch.php` + `admin/assets/admin-money.js`): amounts render in their own currency with `data-amt`/`data-cur` via `rc_money_html()`; the script converts with `fx_rates()`. `rc_money_text()` and the JS `text()` must stay identical. Converted figures are marked ≈; totals are converted per line with `rc_convert()` and the quote states the rate.
- **Shell-safe scripts:** both scripts are emitted INLINE with `readfile()` (admin shell navigation re-runs inline scripts only) behind `$GLOBALS` once-guards, and guard themselves (`window.tsMoney`, `data-qb-ready`, `window.__qbGlobal`, `window.__qbInsertBound`).
- The shared range datepicker fires no `change` for ranges — the builder watches its hidden inputs after each click. Maya Ilai is quoted at its live rates; group/availability deals stay in `admin/maya-ilai-rates.php`.
```

Also add three rows to the File Map table:

```markdown
| `includes/rates-compare.php` · `includes/money-switch.php` · `admin/assets/admin-money.js` | Rates comparison helpers (pure) + the KES/USD switch |
| `includes/quote-builder.php` · `api/quote-builder.php` | Quote builder pricing (pure maths + catalogue + `qb_price_selection()`) and its JSON endpoint |
| `admin/quote-builder.php` · `includes/quote-builder-view.php` · `admin/assets/admin-quote-builder.js` | Quote builder page, shared UI partial (also the enquiry pop-up) and script |
```

- [ ] **Step 2: Run every related test**

Run:
```bash
php tests/rates_compare_logic.php | tail -1
php tests/quote_builder_logic.php | tail -1
php tests/rates_logic.php | tail -1
php tests/capacity_search_logic.php | tail -1
php tests/assistant_tools.php | tail -1
php tests/agent_portal_logic.php | tail -1
```
Expected: each ends with its pass summary (`ALL PASS` or equivalent). `assistant_tools.php` / `agent_portal_logic.php` call `room_stay_quote()` — any FAIL there is a regression from Task 1.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: rates comparison + quote builder conventions"
```
