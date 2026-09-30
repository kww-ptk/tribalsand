# Listing Alternatives Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a property page has no space for the guest's dates + party, list the other Tribal Sand properties that do (spec: `docs/superpowers/specs/2026-09-30-listing-alternatives-design.md`).

**Architecture:** A pure PHP helper file ranks `ts_search_availability()` results (the `/search` function — no second availability or pricing path); a public JSON endpoint wraps it; one shared JS renderer draws the list and is called from the two sidebar widgets' "not available" branches.

**Tech Stack:** PHP 8.2 vanilla, PostgreSQL via `db_query()`, vanilla JS, no build step. Tests are plain PHP scripts (`php tests/<name>.php`, PASS/FAIL lines, exit code).

Deviation from the spec, deliberately: helpers live in a new `includes/listing-alternatives.php` (not `includes/db.php`, already ~2,000 lines), and the ranking derives the home town from the excluded venue's own result row, so its signature is `ts_alternative_properties(array $results, string $excludeSlug, int $limit = 3)`.

---

## File map

| File | Responsibility |
|---|---|
| `includes/listing-alternatives.php` (new) | Pure: venue type labels, town key, date-window validation, ranking |
| `api/alternative-properties.php` (new) | Public GET → JSON; validates, calls `ts_search_availability()`, ranks |
| `js/alternatives.js` (new) | `window.tsShowAlternatives(box, q)` / `tsClearAlternatives(box)`; styles injected once |
| `includes/head.php` | Load `js/alternatives.js` with the booking assets |
| `includes/property-availability-widget.php` | Slot + call in the "nothing fits" branch (Maya Kobe, Zuri) |
| `includes/booking-widget.php` + `js/booking-widget.js` | Opt-in slot (`$bk_alternatives`) + call on `available:false` |
| `my-amani.php`, `enkare-bofa.php`, `sandbox.php` | Set `$bk_alternatives = true` |
| `search.php` | Use the shared type/town helpers |
| `tests/listing_alternatives_logic.php` (new) | Pure tests + DB round-trip |

---

### Task 1: Pure helpers (TDD)

**Files:** Create `includes/listing-alternatives.php`, `tests/listing_alternatives_logic.php`

- [ ] **Step 1: Write the failing test** — `tests/listing_alternatives_logic.php`:

```php
<?php
declare(strict_types=1);
// Listing page alternatives — ranking, labels, window validation.
// Run: php tests/listing_alternatives_logic.php
require_once __DIR__ . '/../includes/listing-alternatives.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

check('type label hotel', ts_venue_type_label('zuri') === 'Boutique Hotel');
check('type label villa', ts_venue_type_label('my-amani') === 'Private Villa');
check('type label unknown', ts_venue_type_label('nowhere') === '');
check('town key', ts_venue_town('Watamu · Kenya') === 'watamu' && ts_venue_town('Kilifi, Kenya') === 'kilifi');
check('town key empty', ts_venue_town('') === '');

$today = '2026-09-30';
check('window ok',           ts_alternatives_window('2026-10-15', '2026-10-18', $today) === ['2026-10-15', '2026-10-18', null]);
check('window repairs',      ts_alternatives_window('2026-10-5', '2026-10-8', $today)[0] === '2026-10-05');
check('window bad date',     ts_alternatives_window('nope', '2026-10-18', $today)[2] !== null);
check('window order',        ts_alternatives_window('2026-10-18', '2026-10-15', $today)[2] !== null);
check('window past',         ts_alternatives_window('2026-09-29', '2026-10-02', $today)[2] !== null);
check('window today ok',     ts_alternatives_window('2026-09-30', '2026-10-02', $today)[2] === null);
check('window 60 nights ok', ts_alternatives_window('2026-10-01', '2026-11-30', $today)[2] === null);
check('window 61 refused',   ts_alternatives_window('2026-10-01', '2026-12-01', $today)[2] !== null);

$r = fn(string $slug, string $loc, int $count, ?float $from, string $cur = 'KES') => [
    'venue' => ['slug' => $slug, 'name' => ucfirst($slug), 'location' => $loc],
    'hero' => "/img/$slug.jpg", 'count' => $count, 'from' => $from, 'currency' => $cur,
];
$results = [
    $r('maya-kobe', 'Watamu', 0, null),          // the page we're on (full)
    $r('my-amani',  'Kilifi', 1, 50000),
    $r('zuri',      'Watamu', 3, 124050),
    $r('sandbox',   'Watamu', 0, null),          // full elsewhere
    $r('enkare-bofa', 'Kilifi', 1, 40000),
    $r('maya_ilai', 'Watamu', 2, 200000),
];
$alt = ts_alternative_properties($results, 'maya-kobe', 3);
$slugs = array_column($alt['options'], 'slug');
check('excludes current venue', !in_array('maya-kobe', $slugs, true));
check('excludes full venues',   !in_array('sandbox', $slugs, true));
check('same town first, then cheapest', $slugs === ['zuri', 'maya_ilai', 'enkare-bofa']);
check('more counts the rest',   $alt['more'] === 1);
check('option shape', ($alt['options'][0]['name'] ?? '') === 'Zuri' && $alt['options'][0]['type'] === 'Boutique Hotel'
    && $alt['options'][0]['location'] === 'Watamu' && $alt['options'][0]['from'] === 124050.0 && $alt['options'][0]['currency'] === 'KES'
    && $alt['options'][0]['hero'] === '/img/zuri.jpg');
$alt2 = ts_alternative_properties($results, 'unknown-slug', 10);
check('unknown current venue: cheapest first', array_column($alt2['options'], 'slug') === ['enkare-bofa', 'my-amani', 'zuri', 'maya_ilai'] && $alt2['more'] === 0);
$alt3 = ts_alternative_properties([$r('a', 'X', 1, null), $r('b', 'X', 1, 10)], 'none', 3);
check('unpriced sorts last', array_column($alt3['options'], 'slug') === ['b', 'a'] && $alt3['options'][1]['from'] === null);
check('nothing free → empty', ts_alternative_properties([$r('a', 'X', 0, null)], 'none')['options'] === []);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it — expect a fatal "failed opening required" (file missing).** `php tests/listing_alternatives_logic.php`

- [ ] **Step 3: Implement** `includes/listing-alternatives.php`:

```php
<?php
declare(strict_types=1);
/**
 * Listing page alternatives: when a property has no space for the guest's
 * dates + party, the sidebar lists the other properties that do.
 *
 * Pure helpers only — ranking takes the rows ts_search_availability() already
 * returned (the /search function), so the list can never disagree with /search
 * and there is no second availability or pricing path. Used by
 * api/alternative-properties.php and search.php. Test:
 * php tests/listing_alternatives_logic.php
 */
require_once __DIR__ . '/rates.php';   // rates_window_ymd()

/** Property type per venue slug — mirrors the homepage "Our Properties" filters. */
const TS_VENUE_TYPES = [
    'maya-kobe' => 'hotel', 'zuri' => 'hotel', 'maya_ilai' => 'hotel',
    'my-amani'  => 'villa', 'enkare-bofa' => 'villa', 'sandbox' => 'villa',
];
/** Wording matches the /search filter chips ("Private Villas" ↔ "Private Villa"). */
const TS_VENUE_TYPE_LABELS = ['hotel' => 'Boutique Hotel', 'villa' => 'Private Villa'];

/** Longest stay the alternatives lookup will check (it checks every property). */
const TS_ALT_MAX_NIGHTS = 60;

function ts_venue_type_label(string $slug): string {
    return TS_VENUE_TYPE_LABELS[TS_VENUE_TYPES[$slug] ?? ''] ?? '';
}

/** Town key of a venue location ("Watamu · Kenya" → "watamu") — the /search rule. */
function ts_venue_town(string $location): string {
    return strtolower(trim(preg_split('/[·,]/u', $location)[0] ?? ''));
}

/**
 * Validate a stay window: [check_in, check_out, error|null]. Dates are repaired
 * by rates_window_ymd() (zero-padded — every comparison here is a string
 * comparison); check-in not before $today (Nairobi, passed in); at most
 * TS_ALT_MAX_NIGHTS nights.
 */
function ts_alternatives_window(string $ci, string $co, string $today): array {
    $ci = rates_window_ymd($ci) ?? '';
    $co = rates_window_ymd($co) ?? '';
    if ($ci === '' || $co === '') return [$ci, $co, 'Dates must be valid and formatted YYYY-MM-DD'];
    if ($ci >= $co)               return [$ci, $co, 'Check-out must be after check-in'];
    if ($ci < $today)             return [$ci, $co, 'Check-in is in the past'];
    $nights = (int) round((strtotime($co . ' 12:00') - strtotime($ci . ' 12:00')) / 86400);
    if ($nights > TS_ALT_MAX_NIGHTS) return [$ci, $co, 'Stays longer than ' . TS_ALT_MAX_NIGHTS . ' nights: please contact us'];
    return [$ci, $co, null];
}

/**
 * Rank ts_search_availability() rows into the alternatives list: drop the
 * current venue and every venue with nothing for the party (count 0); same
 * town as the current venue first, then the cheapest "from" (unpriced last),
 * then name. Returns ['options' => first $limit, 'more' => how many were cut].
 */
function ts_alternative_properties(array $results, string $excludeSlug, int $limit = 3): array {
    $homeTown = '';
    foreach ($results as $r) {
        if (($r['venue']['slug'] ?? '') === $excludeSlug) { $homeTown = ts_venue_town((string)($r['venue']['location'] ?? '')); break; }
    }
    $opts = [];
    foreach ($results as $r) {
        $v = $r['venue'] ?? [];
        $slug = (string)($v['slug'] ?? '');
        if ($slug === '' || $slug === $excludeSlug || (int)($r['count'] ?? 0) <= 0) continue;
        $from = isset($r['from']) && (float)$r['from'] > 0 ? (float)$r['from'] : null;
        $opts[] = [
            'slug'     => $slug,
            'name'     => (string)($v['name'] ?? $slug),
            'location' => (string)($v['location'] ?? ''),
            'type'     => ts_venue_type_label($slug),
            'hero'     => $r['hero'] ?? null,
            'from'     => $from,
            'currency' => (string)($r['currency'] ?? 'USD'),
            '_home'    => $homeTown !== '' && ts_venue_town((string)($v['location'] ?? '')) === $homeTown,
        ];
    }
    usort($opts, function (array $a, array $b): int {
        if ($a['_home'] !== $b['_home']) return $a['_home'] ? -1 : 1;
        if (($a['from'] === null) !== ($b['from'] === null)) return $a['from'] === null ? 1 : -1;
        return [$a['from'], $a['name']] <=> [$b['from'], $b['name']];
    });
    $opts = array_map(function (array $o) { unset($o['_home']); return $o; }, $opts);
    return ['options' => array_slice($opts, 0, max(0, $limit)), 'more' => max(0, count($opts) - max(0, $limit))];
}
```

Note: comparing `from` across currencies is only an ordering hint (Maya Ilai is USD, most others KES); the displayed figures are never summed or converted server-side.

- [ ] **Step 4: Run — expect ALL PASS.** `php tests/listing_alternatives_logic.php`
- [ ] **Step 5: Commit** `git add includes/listing-alternatives.php tests/listing_alternatives_logic.php && git commit -m "feat(listing): rank other properties with space (pure helpers)"`

### Task 2: search.php uses the shared helpers

**Files:** Modify `search.php:44-51`

- [ ] **Step 1:** add `require_once __DIR__ . '/includes/listing-alternatives.php';` after the db.php require, and replace lines 43–51 with:

```php
/* Property type per venue + card label — shared with the listing page's
   "other places" list (includes/listing-alternatives.php). */
$venue_type       = TS_VENUE_TYPES;
$venue_type_label = TS_VENUE_TYPE_LABELS;
$loc_slug = fn($loc) => ts_venue_town((string)$loc);
```

- [ ] **Step 2:** `php -l search.php` → no errors; `curl -s localhost:8765/search?checkin=…` renders (checked in Task 6).
- [ ] **Step 3: Commit** `refactor(search): venue type + town helpers shared`

### Task 3: Endpoint

**Files:** Create `api/alternative-properties.php`; extend the test.

- [ ] **Step 1: Add the DB round-trip to the test** (before the final echo), rolled back:

```php
// ── DB: the endpoint's pipeline over real rows (read-only) ──
require_once __DIR__ . '/../includes/db.php';
try { db(); $hasDb = true; } catch (Throwable $e) { $hasDb = false; }
if ($hasDb) {
    $ci = '2098-04-10'; $co = '2098-04-13';
    $res = ts_search_availability($ci, $co, 2);
    $alt = ts_alternative_properties($res, 'zuri', 50);
    $slugs = array_column($alt['options'], 'slug');
    check('db: current venue never listed', !in_array('zuri', $slugs, true));
    $free = array_values(array_filter($res, fn($r) => $r['count'] > 0 && $r['venue']['slug'] !== 'zuri'));
    check('db: every free venue listed', count($slugs) === count($free));
} else {
    echo "SKIP  db round-trip (no database)\n";
}
```

- [ ] **Step 2: Create** `api/alternative-properties.php`:

```php
<?php
declare(strict_types=1);
/**
 * Other properties with space — the listing page's fallback when its own
 * property has nothing for the dates + party. Public, read-only, JSON.
 *   GET ?venue=<current slug>&check_in&check_out&adults&children
 *   → { ok, check_in, check_out, nights, guests, adults, children,
 *       options:[{slug,name,location,type,hero,from,currency,url}], more, search_url }
 * Uses ts_search_availability() — the /search function — so the list never
 * disagrees with /search. Guards follow api/property-availability.php, plus a
 * past-date and 60-night limit because this checks every property.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/listing-alternatives.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

[$ci, $co, $err] = ts_alternatives_window((string)($_GET['check_in'] ?? ''), (string)($_GET['check_out'] ?? ''), date('Y-m-d'));
if ($err !== null) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => $err])); }

$slug     = substr(trim((string)($_GET['venue'] ?? '')), 0, 80);
$adults   = max(0, min(30, (int)($_GET['adults']   ?? 0)));
$children = max(0, min(20, (int)($_GET['children'] ?? 0)));
$guests   = max(1, $adults + $children);
$nights   = (int) round((strtotime($co . ' 12:00') - strtotime($ci . ' 12:00')) / 86400);

try {
    $alt = ts_alternative_properties(ts_search_availability($ci, $co, $guests), $slug, 3);
} catch (Throwable $e) {
    error_log('[alternative-properties] ' . $e->getMessage());
    http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'Could not check other properties right now.']));
}

$q = http_build_query(['checkin' => $ci, 'checkout' => $co, 'adults' => max(1, $adults), 'children' => $children]);
foreach ($alt['options'] as &$o) { $o['url'] = '/' . rawurlencode($o['slug']) . '?' . $q; }
unset($o);

exit(json_encode([
    'ok' => true, 'check_in' => $ci, 'check_out' => $co, 'nights' => $nights,
    'guests' => $guests, 'adults' => $adults, 'children' => $children,
    'options' => $alt['options'], 'more' => $alt['more'], 'search_url' => '/search?' . $q,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
```

- [ ] **Step 3:** `php tests/listing_alternatives_logic.php` → ALL PASS; `curl -s "localhost:8765/api/alternative-properties?venue=zuri&check_in=2098-04-10&check_out=2098-04-13&adults=2"` → `ok:true` with options; `…check_in=bad` → 422.
- [ ] **Step 4: Commit** `feat(api): alternative-properties endpoint`

### Task 4: Shared renderer

**Files:** Create `js/alternatives.js`; modify `includes/head.php` (both booking asset blocks).

- [ ] **Step 1: Create** `js/alternatives.js`:

```js
/* Listing page: "Other places with space" — shown when this property has
 * nothing for the guest's dates + party. One renderer for both sidebars
 * (property-availability-widget + booking-widget). Data comes from
 * /api/alternative-properties (the /search availability function).
 *   tsShowAlternatives(box, {venue, checkin, checkout, adults, children})
 *   tsClearAlternatives(box)
 */
(function () {
  if (window.tsShowAlternatives) return;

  var CSS =
    '.ts-alt{margin-top:14px;display:flex;flex-direction:column;gap:10px}' +
    '.ts-alt[hidden]{display:none}' +
    '.ts-alt__h{font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:#b8965a;font-weight:700}' +
    '.ts-alt__card{display:flex;gap:12px;align-items:center;border:1px solid #e7ded7;border-radius:10px;padding:10px;background:#fff;text-decoration:none;color:inherit;transition:border-color .15s}' +
    '.ts-alt__card:hover,.ts-alt__card:focus-visible{border-color:#1E5C6B}' +
    '.ts-alt__img{flex:0 0 76px;width:76px;height:64px;border-radius:8px;object-fit:cover;background:#f4efe9}' +
    '.ts-alt__body{flex:1 1 auto;min-width:0}' +
    '.ts-alt__name{font-weight:600;color:#102F3A;font-size:.95rem}' +
    '.ts-alt__meta{font-size:.74rem;color:#8a8173;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.ts-alt__price{font-size:.8rem;color:#102F3A;margin-top:2px}' +
    '.ts-alt__price small{color:#8a8173}' +
    '.ts-alt__go{font-size:.78rem;font-weight:600;color:#1E5C6B;white-space:nowrap}' +
    '.ts-alt__all{font-size:.8rem;color:#1E5C6B;font-weight:600;text-decoration:none}';

  function css() {
    if (document.getElementById('tsAltCss')) return;
    var s = document.createElement('style'); s.id = 'tsAltCss'; s.textContent = CSS;
    document.head.appendChild(s);
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function price(a, cur) {
    if (typeof window.tsPriceSpan === 'function') return window.tsPriceSpan(a, cur);
    return esc((cur || '') + ' ' + Math.round(Number(a) || 0).toLocaleString('en-US'));
  }
  function range(ci, co) {
    var o = { day: 'numeric', month: 'short' };
    var a = new Date(ci + 'T12:00:00'), b = new Date(co + 'T12:00:00');
    return a.toLocaleDateString('en-GB', o) + ' – ' + b.toLocaleDateString('en-GB', o);
  }

  window.tsClearAlternatives = function (box) {
    if (!box) return;
    box.__tsAltSeq = (box.__tsAltSeq || 0) + 1;   // drop any answer still in flight
    box.innerHTML = ''; box.hidden = true;
  };

  window.tsShowAlternatives = function (box, q) {
    window.tsClearAlternatives(box);
    if (!box || !q || !q.checkin || !q.checkout) return;
    var my = box.__tsAltSeq;
    var url = '/api/alternative-properties?venue=' + encodeURIComponent(q.venue || '') +
      '&check_in=' + encodeURIComponent(q.checkin) + '&check_out=' + encodeURIComponent(q.checkout) +
      '&adults=' + (parseInt(q.adults, 10) || 0) + '&children=' + (parseInt(q.children, 10) || 0);
    fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (box.__tsAltSeq !== my || !d || d.ok !== true || !d.options || !d.options.length) return;
        css();
        var stay = JSON.stringify({ checkin: d.check_in, checkout: d.check_out, adults: Math.max(1, d.adults), children: d.children });
        var nights = d.nights + ' night' + (d.nights === 1 ? '' : 's');
        var html = '<div class="ts-alt__h">Other places with space for ' + esc(range(d.check_in, d.check_out)) +
          ' · ' + d.guests + ' guest' + (d.guests === 1 ? '' : 's') + '</div>';
        d.options.forEach(function (o) {
          html += '<a class="ts-alt__card" href="' + esc(o.url) + '" data-ts-alt="' + esc(stay) + '">' +
            (o.hero ? '<img class="ts-alt__img" src="' + esc(o.hero) + '" alt="" loading="lazy">' : '<span class="ts-alt__img"></span>') +
            '<span class="ts-alt__body"><span class="ts-alt__name">' + esc(o.name) + '</span>' +
            '<span class="ts-alt__meta" style="display:block">' + esc([o.location, o.type].filter(Boolean).join(' · ')) + '</span>' +
            (o.from ? '<span class="ts-alt__price" style="display:block"><small>from</small> ' + price(o.from, o.currency) + ' <small>· ' + nights + '</small></span>' : '') +
            '</span><span class="ts-alt__go">See rooms →</span></a>';
        });
        html += '<a class="ts-alt__all" href="' + esc(d.search_url) + '">' +
          (d.more > 0 ? 'See ' + d.more + ' more on the search page →' : 'See all on the search page →') + '</a>';
        box.innerHTML = html;
        box.hidden = false;
      })
      .catch(function () { /* the sorry message already stands on its own */ });
  };

  // Whole-property pages prefill from sessionStorage, not the URL — carry the stay.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[data-ts-alt]') : null;
    if (!a) return;
    try { sessionStorage.setItem('ts_search', a.getAttribute('data-ts-alt')); } catch (err) {}
  });
})();
```

- [ ] **Step 2: Load it** in `includes/head.php` right after each `js/booking-widget.js` script tag (both `$page_booking` and `$page_rooms_rates` blocks):

```php
<script src="js/alternatives.js?v=<?= filemtime(__DIR__ . '/../js/alternatives.js') ?>" defer></script>
```

- [ ] **Step 3: Commit** `feat(listing): shared "other places" renderer`

### Task 5: Hook the two widgets

**Files:** `includes/property-availability-widget.php`, `includes/booking-widget.php`, `js/booking-widget.js`, `my-amani.php`, `enkare-bofa.php`, `sandbox.php`

- [ ] **Step 1 — multi-room widget.** In the `!(data.singles.length || data.combos.length || data.entire.length)` branch, after appending the `.pa-none` paragraph:

```js
        var altBox = document.createElement('div');
        altBox.className = 'ts-alt'; altBox.hidden = true;
        results.appendChild(altBox);
        if (typeof window.tsShowAlternatives === 'function') {
          window.tsShowAlternatives(altBox, { venue: venue, checkin: ci, checkout: co, adults: data.adults, children: data.children });
        }
```
(`results` is cleared on every render, so a new check drops the old list.)

- [ ] **Step 2 — booking widget slot (opt-in).** In `includes/booking-widget.php` add to the docblock `$bk_alternatives = true; // whole-property page: list other properties when these dates are taken`, compute after `$room_curr`:

```php
$bk_alternatives = !empty($bk_alternatives);
$__bk_venue_slug = '';
if ($bk_alternatives && !empty($__room['venue_id'])) {
    try { $__bk_venue_slug = (string) db_query('SELECT slug FROM venues WHERE id = :id', [':id' => (int)$__room['venue_id']])->fetchColumn(); }
    catch (Throwable $e) { $__bk_venue_slug = ''; }
}
```
and right after the `#bkTotal` block:

```php
    <?php if ($bk_alternatives): ?>
    <div class="ts-alt" id="bkAlternatives" data-venue="<?= e($__bk_venue_slug) ?>" hidden></div>
    <?php endif; ?>
```

- [ ] **Step 3 — booking widget JS.** In `js/booking-widget.js` `checkAvailability()`: after `setHint("Checking availability…", "loading");` add `const altBox = document.getElementById("bkAlternatives"); if (altBox && window.tsClearAlternatives) window.tsClearAlternatives(altBox);`, and in the `else` (not available) branch after the setHint:

```js
          if (altBox && window.tsShowAlternatives) {
            window.tsShowAlternatives(altBox, {
              venue: altBox.dataset.venue, checkin: ci, checkout: co,
              adults: parseInt(adultsH.value, 10) || 1, children: parseInt(childrenH.value, 10) || 0,
            });
          }
```

- [ ] **Step 4 — opt in the three whole-property pages:** prefix the include line in `my-amani.php`, `enkare-bofa.php`, `sandbox.php` with `$bk_alternatives = true;`.
- [ ] **Step 5:** `php -l` each file. Commit `feat(listing): show other properties when this one is full`.

### Task 6: Verify in the browser

- [ ] Start the dev server (`.claude/launch.json`), fill Zuri's rooms for a far-future window inside the local DB (blocks with note `alt-test`), open `/zuri?checkin=…&checkout=…&adults=2`: the sorry line + "Other places with space" list appears; the Zuri card is absent; click a card → the target page opens with dates/guests prefilled and its check auto-runs.
- [ ] Whole-property page: block My Amani's unit, then check that date range (the calendar greys it; call the check directly to exercise the branch) — list appears.
- [ ] 375 px: no sideways scroll. Currency switch changes the "from" prices.
- [ ] Delete the `alt-test` blocks. Run `php tests/listing_alternatives_logic.php`, `php tests/capacity_search_logic.php`, `php tests/assistant_tools.php`.
- [ ] Update CLAUDE.md (a short *Listing alternatives* section + file map rows) and commit.
