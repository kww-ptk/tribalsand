# Guest Portal v2 — Travel-App Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the guest portal into a mobile-first travel app — a persistent bottom tab bar across Home/Activities/Concierge/Stay/Booking, one design system (`css/portal-app.css`), and a new Activities page of image cards — reusing all existing backend.

**Architecture:** `booking.php` becomes a thin shell: compact top bar + active `?view=` include + bottom nav (`includes/app/nav.php`), styled by `css/portal-app.css`. A new `includes/app/activities.php` renders tour cards (requests reuse `api/booking-addon.php` `kind=tour`). Existing views are re-marked to the `.pa-*` classes; tour/transfer/itinerary add-on forms are re-homed (tours→Activities, transfer→Concierge, itinerary→Concierge "other"). No DB migration, no endpoint change.

**Tech Stack:** Vanilla PHP 8.2, Postgres via PDO, brand CSS (teal/cream/gold, Cormorant/Jost), Tabler-free inline SVG or unicode icons, existing `js/booking-manage.js` for `data-bm` fetch submits. No framework/build. No PHP test framework — pure helpers via `php`-CLI, views via curl greps + browser E2E.

**Key facts (verified):**
- `booking.php` loads `includes/head.php` then `includes/header.php`, then an inline `<style>` block; after resolving the hold it sets `$view` (whitelist), renders `.bk-hero` + `.bk-page > .container` with a `status-header` include + a `$view` switch (home/concierge/stay/manage) + the countdown script + footer.
- `includes/booking.php`: `fetch_published_tours()` returns published tours; `fetch_booking_addons(int)`; `make_guest_ref(int)`; `TRANSFER_OPTIONS`. `includes/db.php`: `storage_url(string): string` (filename→URL: http/`/`→as-is else `/assets/img/`+name), `setting()`, `e()`.
- Tours: `category` (classic/custom/excursion), `tag_label`, `duration`, `short_desc`, `highlights_json`, hero via `(SELECT filename FROM tour_images WHERE tour_id=t.id AND is_hero=TRUE LIMIT 1)`. Most tours have NO image yet → gradient fallback is the common case.
- `booking-manage-actions.php` forms: Request a change (`api/booking-change.php`), Add a tour (`kind=tour`), Add a transfer (`kind=transfer`, `name=transfer` select from `TRANSFER_OPTIONS`), Itinerary (`kind=itinerary`), + a "Your requests" list.
- `js/booking-manage.js` binds `form[data-bm]` → JSON POST to the form `action` → `.bm-status` + reload on ok. `captcha_site_key()` from turnstile.php (dev-bypass locally).
- Local: `php -S localhost:8765 router.php`; NO DATABASE_URL — `php -r`, never psql; Turnstile unset → captcha dev-bypass.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `css/portal-app.css` | New — the `.pa-*` design system (single visual source of truth) |
| `includes/app/nav.php` | New — bottom tab bar (5 sections, active state) |
| `includes/app/activities.php` | New — Activities browse + request cards |
| `includes/booking.php` | Add `fetch_portal_activities()`, `fetch_tour_categories()` |
| `booking.php` | Shell: load CSS, `view=activities`, top bar, include nav, drop the hero |
| `includes/app/status-header.php` | Restyle → compact `.pa-status` |
| `includes/app/home.php` | Restyle → greeting + status + featured activities + concierge shortcut |
| `includes/app/concierge.php` | Restyle; add relocated Transfer form; drop Activity tile |
| `includes/app/stay.php` | Restyle → `.pa-*` |
| `includes/booking-manage-actions.php` | Reorg: remove tour + itinerary forms, keep change + "Your requests", restyle |

---

## Task 1: Design system + data helpers

**Files:** Create `css/portal-app.css`; modify `includes/booking.php`, `booking.php`; test `tests/portal_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/portal_logic.php`:
```php
<?php
declare(strict_types=1);
// DB-backed checks for portal v2 helpers. Run: php tests/portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$acts = fetch_portal_activities();
check('activities is a list', is_array($acts));
check('activity rows have slug/name/category/hero keys',
      $acts === [] || (array_key_exists('slug',$acts[0]) && array_key_exists('name',$acts[0])
                       && array_key_exists('category',$acts[0]) && array_key_exists('hero',$acts[0])));

$cats = fetch_tour_categories();
check('categories is a list', is_array($cats));
check('categories have key + label',
      $cats === [] || (isset($cats[0]['key'], $cats[0]['label'])));

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it, confirm it fails**

Run: `php tests/portal_logic.php` → fatal `Call to undefined function fetch_portal_activities()`.

- [ ] **Step 3: Add the two helpers to `includes/booking.php`**

Append:
```php
/** Published tours with their hero image + fields, for the portal Activities page. */
function fetch_portal_activities(): array {
    return db_query(
        "SELECT t.id, t.slug, t.name, t.category, t.tag_label, t.duration, t.short_desc, t.highlights_json,
                (SELECT filename FROM tour_images ti WHERE ti.tour_id = t.id AND ti.is_hero = TRUE LIMIT 1) AS hero
         FROM tours t
         WHERE t.is_published = TRUE
         ORDER BY t.sort_order ASC, t.name ASC"
    )->fetchAll();
}

/** Distinct published tour categories → {key,label} for the Activities filter. */
function fetch_tour_categories(): array {
    $labels = ['classic' => 'Classic safari', 'custom' => 'Custom journey', 'excursion' => 'Excursion'];
    $rows = db_query(
        "SELECT DISTINCT category FROM tours WHERE is_published = TRUE AND category <> '' ORDER BY category ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $c) { $out[] = ['key' => $c, 'label' => $labels[$c] ?? ucfirst($c)]; }
    return $out;
}
```

- [ ] **Step 4: Run the test, confirm PASS**

Run: `php tests/portal_logic.php` → `ALL PASS`.

- [ ] **Step 5: Create `css/portal-app.css`**

```css
/* Portal v2 — travel-app design system (.pa-*) */
:root{
  --pa-teal:#1E5C6B; --pa-teal-d:#102F3A; --pa-gold:#D4B07A; --pa-cream:#F5F1EB;
  --pa-ink:#1a1a1a; --pa-muted:#6b7280; --pa-line:#e5e0d6; --pa-card:#fff;
}
.pa-app{background:var(--pa-cream);min-height:100vh;padding-bottom:76px;}
.pa-wrap{max-width:640px;margin:0 auto;padding:0 16px;}
.pa-topbar{background:var(--pa-teal-d);color:#fff;padding:16px 16px 14px;position:sticky;top:0;z-index:20;}
.pa-topbar__eyebrow{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--pa-gold);}
.pa-topbar__title{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:24px;margin:2px 0 0;line-height:1.1;}
.pa-topbar__row{display:flex;align-items:center;justify-content:space-between;gap:12px;}
.pa-section{padding:16px 0 8px;}
.pa-h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:22px;margin:0 0 4px;color:var(--pa-ink);}
.pa-sub{font-size:13px;color:var(--pa-muted);margin:0 0 14px;}
.pa-back{display:inline-block;font-size:13px;color:var(--pa-teal);text-decoration:none;margin:0 0 14px;}

.pa-card{background:var(--pa-card);border:1px solid var(--pa-line);border-radius:14px;overflow:hidden;margin-bottom:12px;}
.pa-card__body{padding:14px 16px;}
.pa-card__title{font-size:15px;font-weight:500;color:var(--pa-ink);margin:0;}
.pa-card__meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:5px 0 0;font-size:12px;color:var(--pa-muted);}

.pa-media{height:120px;position:relative;background:var(--pa-teal);background-size:cover;background-position:center;}
.pa-media--classic{background:linear-gradient(135deg,#4a6b3a,#1d2b16);}
.pa-media--custom{background:linear-gradient(135deg,#8a6d3b,#3a2c14);}
.pa-media--excursion{background:linear-gradient(135deg,#1E5C6B,#0d2b33);}
.pa-media__tag{position:absolute;top:10px;left:10px;font-size:11px;font-weight:500;background:rgba(255,255,255,.92);color:var(--pa-teal-d);padding:3px 10px;border-radius:999px;}

.pa-chips{display:flex;gap:8px;overflow-x:auto;padding:12px 0;-webkit-overflow-scrolling:touch;}
.pa-chip{white-space:nowrap;font-size:13px;padding:6px 14px;border-radius:999px;border:1px solid var(--pa-line);background:#fff;color:var(--pa-muted);cursor:pointer;}
.pa-chip.is-active{background:var(--pa-teal-d);color:#fff;border-color:var(--pa-teal-d);font-weight:500;}

.pa-btn{display:inline-block;width:100%;text-align:center;background:#fff;border:1px solid var(--pa-line);color:var(--pa-ink);border-radius:8px;padding:11px 14px;font:inherit;font-size:14px;font-weight:500;cursor:pointer;text-decoration:none;}
.pa-btn--primary{background:var(--pa-teal-d);color:#fff;border-color:var(--pa-teal-d);}
.pa-btn--danger{background:#dc2626;color:#fff;border-color:#dc2626;}

.pa-tile{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--pa-line);border-radius:14px;padding:16px 18px;text-decoration:none;color:var(--pa-ink);margin-bottom:12px;}
.pa-tile--hero{background:var(--pa-teal-d);color:#fff;border-color:var(--pa-teal-d);}
.pa-tile__t{font-weight:600;font-size:16px;display:block;}
.pa-tile__s{font-size:13px;color:var(--pa-muted);display:block;}
.pa-tile--hero .pa-tile__s{color:rgba(255,255,255,.85);}

.pa-field{display:block;font-size:13px;margin:0 0 12px;color:var(--pa-ink);}
.pa-field input,.pa-field select,.pa-field textarea{display:block;width:100%;margin-top:4px;padding:10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;}

.pa-pill{font-size:12px;padding:2px 9px;border-radius:999px;text-transform:capitalize;white-space:nowrap;}
.pa-pill--requested{background:#fff7e6;color:#8a5a00;}
.pa-pill--confirmed{background:#e6eefb;color:#1a4a9c;}
.pa-pill--completed{background:#e6f6ec;color:#146c37;}
.pa-pill--declined,.pa-pill--cancelled{background:#fbe6e6;color:#a12;}

.pa-nav{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;background:#fff;border-top:1px solid var(--pa-line);max-width:640px;margin:0 auto;}
.pa-nav__item{flex:1;text-align:center;padding:8px 0 9px;color:var(--pa-muted);text-decoration:none;font-size:10px;}
.pa-nav__item.is-active{color:var(--pa-teal-d);font-weight:500;}
.pa-nav__ico{display:block;font-size:20px;line-height:1;margin:0 auto 2px;}
.pa-status{background:#fff;border:1px solid var(--pa-line);border-radius:14px;padding:16px;margin-bottom:8px;}
.pa-status__row{display:flex;justify-content:space-between;gap:10px;font-size:14px;padding:6px 0;}
.pa-status__row dt{color:var(--pa-muted);} .pa-status__row dd{margin:0;font-weight:500;}
.pa-badge{font-size:12px;font-weight:500;padding:3px 12px;border-radius:999px;}
```

- [ ] **Step 6: Load the CSS in `booking.php`**

Immediately after `include __DIR__ . '/includes/header.php';` (before the `<style>` block), add:
```php
<link rel="stylesheet" href="/css/portal-app.css?v=<?= @filemtime(__DIR__ . '/css/portal-app.css') ?: time() ?>">
```

- [ ] **Step 7: Verify + commit**

`php tests/portal_logic.php` → ALL PASS. `php -l booking.php includes/booking.php` clean. Sample the data:
```bash
php -r 'require "includes/booking.php"; $a=fetch_portal_activities(); echo "activities: ".count($a).", first: ".($a[0]["name"]??"-").", hero: ".($a[0]["hero"]??"none")."\n"; $c=fetch_tour_categories(); echo "categories: ".implode(", ", array_map(fn($x)=>$x["label"],$c))."\n";'
```
Expected: a non-zero activity count + category labels (Classic safari / Custom journey / Excursion).
```bash
git add css/portal-app.css includes/booking.php booking.php tests/portal_logic.php
git commit -m "feat(portal): design system + activities data helpers"
```

---

## Task 2: App shell + bottom nav

**Files:** Create `includes/app/nav.php`; modify `booking.php`

- [ ] **Step 1: Create `includes/app/nav.php`**

```php
<?php /** Bottom tab bar. Expects $ref, $view. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__tabs = [
  'home'       => ['Home',      '&#9432;'],
  'activities' => ['Activities', '&#9788;'],
  'concierge'  => ['Concierge', '&#128276;'],
  'stay'       => ['Stay',      '&#8505;'],
  'manage'     => ['Booking',   '&#128197;'],
];
?>
<nav class="pa-nav">
  <?php foreach ($__tabs as $__k => $__t): ?>
  <a class="pa-nav__item <?= $view === $__k ? 'is-active' : '' ?>" href="<?= e($__u) ?>&view=<?= e($__k) ?>">
    <span class="pa-nav__ico" aria-hidden="true"><?= $__t[1] ?></span><?= e($__t[0]) ?>
  </a>
  <?php endforeach; ?>
</nav>
```
> Icons here are unicode glyphs to avoid an icon-font dependency; if the project already ships an icon set, use it instead (check `includes/head.php`). Keep the labels/keys exactly.

- [ ] **Step 2: Wire the shell into `booking.php`**

Read `booking.php`'s render body. Make these changes:
1. Add `'activities'` to the `$view` whitelist line: `['home','concierge','stay','manage','activities']`.
2. Remove the `.bk-hero` block (the `<div class="bk-hero">…</div>`). Replace it, and the `<div class="bk-page"><div class="container">` wrapper, with an app frame + compact top bar. The top bar title changes per view. Just before the existing `<?php if ($error): ?>` branch, open the frame:
```php
<div class="pa-app">
  <?php
    $__titles = ['home'=>'Your stay','activities'=>'Activities','concierge'=>'Concierge','stay'=>'Stay info','manage'=>'Booking'];
    $__t = $hold ? ($__titles[$view] ?? 'Your stay') : 'Your booking';
  ?>
  <div class="pa-topbar"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title"><?= e($__t) ?></div></div>
  <div class="pa-wrap" style="padding-top:16px">
```
3. Keep the existing `if ($error) … elseif ($hold) …` content inside `.pa-wrap`. At the very end of that content (after the `<?php endif; ?>` that closes the `$hold` branch, before the countdown script), close the wrap and render the nav only when a booking is loaded:
```php
  </div><!-- /pa-wrap -->
  <?php if ($hold): ?><?php include __DIR__ . '/includes/app/nav.php'; ?><?php endif; ?>
</div><!-- /pa-app -->
```
4. Leave the old inline `<style>` block in place for now (later restyle tasks remove reliance on `.bk-*`; harmless to keep). Leave the countdown script and footer include as-is.

> This gets the top bar + bottom nav on every view immediately. Views still render their current markup inside — later tasks restyle them. Verify the page still resolves the hold and each `?view=` still renders (activities is blank until Task 3's `is_file` guard — add one: in booking.php's view switch, the `activities` branch should be `<?php elseif ($view === 'activities'): ?><?php if (is_file(__DIR__.'/includes/app/activities.php')) include __DIR__.'/includes/app/activities.php'; ?>`).

- [ ] **Step 3: Verify + commit**

`php -l booking.php includes/app/nav.php` clean. Start the server; with a real hold ref `$REF`:
```bash
curl -s "localhost:8765/booking.php?ref=$REF" | grep -c 'class="pa-nav"'          # >=1 (bottom nav present)
curl -s "localhost:8765/booking.php?ref=$REF&view=activities" -o /dev/null -w "%{http_code}\n"  # 200
curl -s "localhost:8765/booking.php?ref=$REF&view=concierge" | grep -c 'pa-nav__item'  # 5 (five tabs)
curl -s "localhost:8765/booking.php?ref=$REF" | grep -c '<meta name="robots"'      # >=1 (noindex intact)
```
Stop the server.
```bash
git add booking.php includes/app/nav.php
git commit -m "feat(portal): app shell top bar + persistent bottom nav"
```

---

## Task 3: Activities view

**Files:** Create `includes/app/activities.php`

- [ ] **Step 1: Create `includes/app/activities.php`**

```php
<?php /** Activities view. Expects $hold, $ref, $status. */ ?>
<?php
$__acts = fetch_portal_activities();
$__cats = fetch_tour_categories();
$__active = in_array($status ?? '', ['pending','confirmed'], true);
?>
<h2 class="pa-h2">Experiences</h2>
<p class="pa-sub">Browse and request activities — our team confirms availability and pricing.</p>

<?php if ($__cats): ?>
<div class="pa-chips" id="paCatChips">
  <button type="button" class="pa-chip is-active" data-cat="all">All</button>
  <?php foreach ($__cats as $c): ?>
  <button type="button" class="pa-chip" data-cat="<?= e($c['key']) ?>"><?= e($c['label']) ?></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$__acts): ?>
  <p class="pa-sub">Experiences will appear here soon.</p>
<?php else: foreach ($__acts as $a):
    $img = trim((string)($a['hero'] ?? ''));
    $mediaClass = 'pa-media pa-media--' . preg_replace('/[^a-z]/','',strtolower((string)$a['category']));
    $style = $img !== '' ? 'background-image:url(\'' . e(storage_url($img)) . '\')' : '';
?>
  <div class="pa-card" data-cat="<?= e($a['category']) ?>">
    <div class="<?= e($mediaClass) ?>" style="<?= $style ?>">
      <?php if (!empty($a['tag_label'])): ?><span class="pa-media__tag"><?= e($a['tag_label']) ?></span><?php endif; ?>
    </div>
    <div class="pa-card__body">
      <p class="pa-card__title"><?= e($a['name']) ?></p>
      <div class="pa-card__meta">
        <?php if (!empty($a['duration'])): ?><span><?= e($a['duration']) ?></span><?php endif; ?>
        <?php if (!empty($a['short_desc'])): ?><span style="flex-basis:100%;margin-top:4px;color:#555"><?= e($a['short_desc']) ?></span><?php endif; ?>
      </div>
      <?php if ($__active): ?>
      <form data-bm action="/api/booking-addon.php" style="margin-top:12px">
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <input type="hidden" name="kind" value="tour">
        <input type="hidden" name="tour_slug" value="<?= e($a['slug']) ?>">
        <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 8px"></div>
        <button type="submit" class="pa-btn pa-btn--primary">Request this activity</button>
        <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

<script>
(function(){
  var chips=document.querySelectorAll('#paCatChips .pa-chip');
  var cards=document.querySelectorAll('.pa-card[data-cat]');
  chips.forEach(function(ch){ch.addEventListener('click',function(){
    chips.forEach(function(x){x.classList.remove('is-active')}); ch.classList.add('is-active');
    var cat=ch.getAttribute('data-cat');
    cards.forEach(function(cd){ cd.style.display=(cat==='all'||cd.getAttribute('data-cat')===cat)?'':'none'; });
  });});
})();
</script>
```
> `js/booking-manage.js` (loaded by booking.php) handles the `data-bm` forms; each activity's request posts `kind=tour` + `tour_slug` to the existing endpoint. When the booking isn't actionable (expired/cancelled) the request form is hidden but cards still browse.

- [ ] **Step 2: Verify + commit**

`php -l includes/app/activities.php` clean. Start server; with `$REF` (pending/confirmed):
```bash
curl -s "localhost:8765/booking.php?ref=$REF&view=activities" | grep -c "Request this activity"   # >=1 if tours exist
curl -s "localhost:8765/booking.php?ref=$REF&view=activities" | grep -c 'data-cat'                 # >=1 (cards + chips)
curl -s "localhost:8765/booking.php?ref=$REF&view=activities" | grep -c 'name="tour_slug"'         # >=1
```
Optional real submit (endpoint already handles kind=tour): POST a tour_slug to /api/booking-addon.php with $REF → `{"ok":true}`, then delete the row. Stop server.
```bash
git add includes/app/activities.php
git commit -m "feat(portal): activities view (image cards + category filter + request)"
```

---

## Task 4: Restyle status header + home

**Files:** Modify `includes/app/status-header.php`, `includes/app/home.php`

- [ ] **Step 1: Restyle `includes/app/status-header.php`**

Replace the `.bk-card` summary markup with a compact `.pa-status` card (keep the same data: room, dates, code, status badge, and the pending countdown element `#bkCountdown` so the existing countdown script keeps working). Keep the `if (!isset($hold)||!$hold) return;` guard. Target markup:
```php
<?php if (!isset($hold) || !$hold) return; ?>
<?php
$__bg = ['pending'=>'#fef3c7','confirmed'=>'#dcfce7','cancelled'=>'#fee2e2','expired'=>'#f3f4f6'][$status] ?? '#f3f4f6';
$__fg = ['pending'=>'#92400e','confirmed'=>'#166534','cancelled'=>'#991b1b','expired'=>'#6b7280'][$status] ?? '#6b7280';
$__nights = (int)((strtotime($hold['check_out']) - strtotime($hold['check_in'])) / 86400);
?>
<div class="pa-status">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
    <div style="font-family:'Cormorant Garamond',serif;font-size:20px"><?= e($hold['room_name']) ?></div>
    <span class="pa-badge" style="background:<?= $__bg ?>;color:<?= $__fg ?>"><?= e(ucfirst($status)) ?></span>
  </div>
  <dl style="margin:0">
    <div class="pa-status__row"><dt>Check-in</dt><dd><?= e(date('D, j M Y', strtotime($hold['check_in']))) ?></dd></div>
    <div class="pa-status__row"><dt>Check-out</dt><dd><?= e(date('D, j M Y', strtotime($hold['check_out']))) ?> · <?= e((string)$__nights) ?> nights</dd></div>
    <?php if (!empty($hold['access_code'])): ?><div class="pa-status__row"><dt>Booking code</dt><dd style="font-family:monospace;letter-spacing:1px"><?= e($hold['access_code']) ?></dd></div><?php endif; ?>
    <?php if ($status === 'pending' && !empty($hold['expires_at'])): ?><div class="pa-status__row"><dt>Hold expires</dt><dd id="bkCountdown" style="color:#b45309"></dd></div><?php endif; ?>
  </dl>
</div>
```
> Confirm booking.php's countdown script still targets `#bkCountdown` (it does). If the old script wrote initial text server-side, it's fine — the JS updates it on load.

- [ ] **Step 2: Restyle `includes/app/home.php`**

Home leads with a greeting, then the (already-included) status header context, then featured activities + a concierge shortcut. Replace the tiles with:
```php
<?php /** Home. Expects $hold, $ref, $status. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref); $__active = in_array($status ?? '', ['pending','confirmed'], true);
try { $__feat = array_slice(fetch_portal_activities(), 0, 3); } catch (Throwable $e) { $__feat = []; } ?>
<div style="font-family:'Cormorant Garamond',serif;font-size:24px;margin:4px 0 12px">Karibu, <?= e(explode(' ', trim((string)$hold['guest_name']))[0] ?? 'guest') ?></div>

<?php if ($__active): ?>
<a class="pa-tile pa-tile--hero" href="<?= e($__u) ?>&view=concierge">
  <span aria-hidden="true" style="font-size:24px">&#128276;</span>
  <span><span class="pa-tile__t">Concierge</span><span class="pa-tile__s">Towels, housekeeping, anything you need</span></span>
  <span style="margin-left:auto">&rarr;</span>
</a>
<?php endif; ?>

<?php if ($__feat): ?>
<div style="display:flex;justify-content:space-between;align-items:baseline;margin:16px 0 8px">
  <div class="pa-h2" style="font-size:18px">Experiences</div>
  <a href="<?= e($__u) ?>&view=activities" style="font-size:13px;color:var(--pa-teal,#1E5C6B);text-decoration:none">See all &rarr;</a>
</div>
<?php foreach ($__feat as $a): $mediaClass='pa-media pa-media--'.preg_replace('/[^a-z]/','',strtolower((string)$a['category'])); $img=trim((string)($a['hero']??'')); ?>
<a class="pa-card" style="display:block;text-decoration:none;color:inherit" href="<?= e($__u) ?>&view=activities">
  <div class="<?= e($mediaClass) ?>" style="height:96px;<?= $img!==''?'background-image:url(\''.e(storage_url($img)).'\')':'' ?>"></div>
  <div class="pa-card__body"><p class="pa-card__title"><?= e($a['name']) ?></p><?php if(!empty($a['duration'])): ?><div class="pa-card__meta"><span><?= e($a['duration']) ?></span></div><?php endif; ?></div>
</a>
<?php endforeach; endif; ?>
```
> Keep the `status-header` include (already rendered by booking.php above the view switch) — home doesn't re-render it.

- [ ] **Step 3: Verify + commit**

`php -l includes/app/status-header.php includes/app/home.php` clean. Server; `$REF`:
```bash
curl -s "localhost:8765/booking.php?ref=$REF" | grep -c "pa-status"      # >=1
curl -s "localhost:8765/booking.php?ref=$REF" | grep -c "Karibu"         # >=1
curl -s "localhost:8765/booking.php?ref=$REF" | grep -c "Experiences"    # >=1 if tours exist
```
Stop server.
```bash
git add includes/app/status-header.php includes/app/home.php
git commit -m "feat(portal): restyle status header + home to travel-app look"
```

---

## Task 5: Restyle concierge (+ transfer) and stay

**Files:** Modify `includes/app/concierge.php`, `includes/app/stay.php`

- [ ] **Step 1: Restyle `includes/app/concierge.php` + add the Transfer form; drop the Activity tile**

Read the current file. Keep the JS toggle + the `$__kinds` free-text forms + the "Recent requests" list, but: (a) re-class to `.pa-*` (chips/cards/buttons/pills → `.pa-chip`/`.pa-card`/`.pa-btn--primary`/`.pa-pill--<status>`); (b) REMOVE the "Activity" link tile (Activities is now a nav section); (c) keep the "Transfer" as a real form (relocated from manage), a `data-bm` form posting `kind=transfer` with the `TRANSFER_OPTIONS` select. Insert this Transfer form alongside the free-text forms:
```php
<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-transfer">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="transfer">
  <label class="pa-field">Transfer
    <select name="transfer" required>
      <option value="">— select —</option>
      <?php foreach (TRANSFER_OPTIONS as $__tk => $__tl): ?><option value="<?= e($__tk) ?>"><?= e($__tl) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="pa-field">Details (flight no., time, pickup…)<textarea name="details" rows="2"></textarea></label>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="pa-btn pa-btn--primary">Request transfer</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>
```
Add a "Transfer" tile to the category grid (`data-cx="transfer"`) so the toggle reveals it (transfer is a real form now, not a link). Keep the `$__kinds` service categories (housekeeping/amenities/maintenance/restaurant/other). Keep the "← Back to home" link as `.pa-back`.

- [ ] **Step 2: Restyle `includes/app/stay.php`**

Re-class the cards to `.pa-card` + `.pa-back`; keep the read-once `$__vals` logic and empty-state. Target the section header `.pa-h2` "Stay info", back link `.pa-back`, each card `.pa-card` with `.pa-card__body` (label + pre-wrap value).

- [ ] **Step 3: Verify + commit**

`php -l includes/app/concierge.php includes/app/stay.php` clean. Server; `$REF`:
```bash
curl -s "localhost:8765/booking.php?ref=$REF&view=concierge" | grep -c 'name="transfer"'   # >=1 (transfer relocated here)
curl -s "localhost:8765/booking.php?ref=$REF&view=concierge" | grep -ci "activity"          # 0 (activity tile removed)
curl -s "localhost:8765/booking.php?ref=$REF&view=stay" | grep -c "pa-card"                 # depends on seeded stay info
```
Stop server.
```bash
git add includes/app/concierge.php includes/app/stay.php
git commit -m "feat(portal): restyle concierge (+ transfer) and stay"
```

---

## Task 6: Restyle Booking (manage) view + reorg forms

**Files:** Modify `includes/booking-manage-actions.php`; adjust the `manage` branch cancel card in `booking.php`

- [ ] **Step 1: Reorg + restyle `includes/booking-manage-actions.php`**

Read the file. Remove the "Add a tour" form (Activities owns tours) and the "Itinerary request" form (Concierge "Something else" covers ad-hoc); remove the "Add a transfer" form (relocated to Concierge in Task 5). KEEP: the "Request a change" form (`api/booking-change.php`) and the "Your requests" list. Re-class both to `.pa-*` (`.pa-card`, `.pa-field`, `.pa-btn--primary`, `.pa-pill--<status>`). The "Your requests" list should show ALL of the hold's add-ons + change requests with status pills (it already does). Wrap the section under a `.pa-h2` "Booking" context if not already framed by the top bar.

- [ ] **Step 2: Restyle the cancel card in `booking.php`'s `manage` branch**

In booking.php's `manage` branch, re-class the existing cancel card to `.pa-card` + `.pa-btn--danger` (keep the SAME form fields — hidden `ref` + `action=cancel` + onclick confirm; NO csrf_field, it's a guest page). Do not change how cancel POSTs.

- [ ] **Step 3: Verify + commit**

`php -l includes/booking-manage-actions.php booking.php` clean. Server; `$REF` (pending/confirmed):
```bash
curl -s "localhost:8765/booking.php?ref=$REF&view=manage" | grep -c "Send change request"   # >=1 (change kept)
curl -s "localhost:8765/booking.php?ref=$REF&view=manage" | grep -c "Add a tour"             # 0 (tour form removed)
curl -s "localhost:8765/booking.php?ref=$REF&view=manage" | grep -ci "cancel"                # >=1 (cancel kept)
curl -s "localhost:8765/booking.php?ref=$REF&view=manage" | grep -c "Your requests"          # >=1
```
Stop server.
```bash
git add includes/booking-manage-actions.php booking.php
git commit -m "feat(portal): restyle Booking view; re-home tour/transfer/itinerary forms"
```

---

## Task 7: E2E + regression + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Lint + tests**
```bash
php tests/portal_logic.php | tail -1     # ALL PASS
php tests/manage_logic.php | tail -1     # ALL PASS
php tests/convert_logic.php | tail -1    # ALL PASS
for f in booking.php css/portal-app.css includes/app/nav.php includes/app/activities.php includes/app/status-header.php includes/app/home.php includes/app/concierge.php includes/app/stay.php includes/booking-manage-actions.php includes/booking.php; do case "$f" in *.css) echo "-- $f";; *) php -l "$f" >/dev/null && echo "OK $f";; esac; done
```

- [ ] **Step 2: Browser E2E** (dev server; a real pending/confirmed hold ref):
  1. Home → greeting + status card + featured activities + bottom nav (5 tabs, Home active).
  2. Tap each bottom tab → Activities/Concierge/Stay/Booking each render with the active tab highlighted.
  3. **Activities** → category chips filter the cards (JS, no reload); tap "Request this activity" → "Request sent" → reload → appears in Booking → "Your requests" as `requested` (confirm a `booking_addons` kind=tour row via `php -r`).
  4. **Concierge** → a service category and the Transfer form both submit (create rows).
  5. **Booking** → change request + cancel still work; "Your requests" lists everything with status pills.
  6. Code-only login still lands on Home; `noindex` present; fixed bottom nav doesn't cover content.
  7. resize_window mobile (375) + desktop — nav + cards legible, no horizontal overflow.

- [ ] **Step 3: Regression** — admin Confirm/Decline/Mark-done still act on the requests; public booking widget + convert-enquiry unaffected.

- [ ] **Step 4: Clean up** — delete any test holds/add-ons created; clear any seeded stay-info.
```bash
php -r '$h=(int)($argv[1]??0); if(!$h){echo "pass id\n";exit;} require "includes/db.php"; db_query("DELETE FROM booking_addons WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM booking_change_requests WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM holds WHERE id=:h",[":h"=>$h]); echo "removed $h\n";' <HID>
```

---

## Self-Review Notes (author checklist)

- **Spec coverage:** design system (T1 css), data helpers (T1), bottom nav + shell (T2), Activities page (T3), restyle status/home (T4), concierge+transfer+stay (T5), Booking reorg (T6), E2E (T7). ✔ Re-homing (tours→Activities T3, transfer→Concierge T5, itinerary→dropped/T6, change+cancel+requests→Booking T6) covered.
- **Reuse:** activity + transfer requests reuse `api/booking-addon.php` (kind=tour / kind=transfer) + `js/booking-manage.js` (`data-bm`/`.bm-status`) — no endpoint change; no migration.
- **Consistency:** `.pa-*` classes defined once in T1 and applied in T2–T6; `fetch_portal_activities()`/`fetch_tour_categories()` used in T1/T3/T4; `storage_url()`, `TRANSFER_OPTIONS`, `captcha_site_key()`, `#bkCountdown` referenced consistently.
- **Guest-page safety:** no `csrf_field()` anywhere in guest includes; `e()` on all output incl. image URLs via `storage_url()`; `noindex` retained; cancel form unchanged.
- **Risk note (T2/T6):** booking.php markup surgery — leave the countdown script + footer intact; the old inline `.bk-*` `<style>` may remain (unused) or be trimmed once all views use `.pa-*`. Verify each `?view=` renders after T2.
- **No placeholders; complete CSS/markup shown; incremental commits keep each view shippable.**
