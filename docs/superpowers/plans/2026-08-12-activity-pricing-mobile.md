# Activity Pricing & Two-Up Mobile Cards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make activity prices actually settable — the field owners were typing into was dead — make it visible in admin which activities are unpriced or unreachable, and put browse cards two-up on a phone.

**Architecture:** One new pure helper (`is_priced()`) defines "does this have a usable price" so the admin badges cannot drift. `admin/tour-edit.php` loses its dead `price` input and gains a read-only hint showing any stored legacy text. `admin/tours.php` gains Price and "Offered at" columns via a correlated subquery matching the existing hero-image one. `.pa-grid` goes two-up below 720px, with an open activity card spanning both columns.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, vanilla JS, vanilla CSS. Tests are plain PHP scripts with a `check()` helper. **No migration, no schema change.**

**Spec:** `docs/superpowers/specs/2026-08-12-activity-pricing-mobile-design.md`

---

## Before you start

Read the spec. The key finding: `admin/tour-edit.php` renders **two** price fields. The first
(`name="price"`, labelled *"Price (activities; shown on card)"*) writes `tours.price`, a varchar
**read by nothing in the repo**. The second (`name="price_amount"`) is what the portal card, the
booking API and the bill all use. Owners type into the first one and nothing appears.

**Conventions** (`CLAUDE.md`):

- Escape output with `e()`. `db_query()` with bound parameters only.
- No build step — edit CSS and JS directly.

**Baseline:**

```bash
php tests/activities_logic.php && php tests/services_logic.php
```

Both must end `ALL PASS`. `php tests/team_logic.php` has **two** known failures that also fail on
`master` — ignore them entirely, do not try to fix them.

**Committing:** the tree has unrelated pre-existing changes (`.claude/launch.json`, two untracked
`Archive*.zip`). **NEVER `git add -A` or `git add .`.**

**Do not drop the `tours.price` column.** It still holds data on production and this plan's whole
point is not to destroy it. Stop writing to it; leave it be.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `includes/services.php` | Pricing helpers. Gains `is_priced()` | Modify |
| `tests/services_logic.php` | `is_priced()` assertions | Modify |
| `admin/tour-edit.php` | Remove the dead input + its write; relabel; legacy hint | Modify |
| `admin/tours.php` | Price and "Offered at" columns | Modify |
| `admin/services.php` | Unpriced badge + count | Modify |
| `css/portal-app.css` | Two-up grid, card tightening, open-card span | Modify |
| `includes/app/activities.php` | `is-open` class on the expanded card | Modify |

---

### Task 1: `is_priced()`

**Files:** `includes/services.php`, `tests/services_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/services_logic.php`, insert this immediately before the final
`echo $failures ? … ` line:

```php
// ── is_priced: one definition of "has a usable price" ───────────────────────
check('is_priced null',        is_priced(null) === false);
check('is_priced empty string', is_priced('') === false);
check('is_priced zero int',    is_priced(0) === false);
check('is_priced zero string', is_priced('0.00') === false);
check('is_priced negative',    is_priced(-5) === false);
check('is_priced positive',    is_priced(12.5) === true);
check('is_priced numeric string', is_priced('2500.00') === true);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/services_logic.php`
Expected: fatal — `Call to undefined function is_priced()`. Confirm you see it.

- [ ] **Step 3: Add the helper**

In `includes/services.php`, insert this immediately after `format_price()`:

```php
/**
 * Is this a usable price? The portal shows a price only when it is greater than
 * zero, so admin's "no price" badge must use exactly the same rule or the two
 * disagree about what counts as priced. NULL, '', 0 and negatives are all "no". Pure.
 */
function is_priced($amount): bool {
    if ($amount === null || $amount === '') return false;
    return (float)$amount > 0;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/services_logic.php`
Expected: all seven new lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/services.php tests/services_logic.php
git commit -m "feat(admin): is_priced() — one rule for what counts as priced"
```

---

### Task 2: Remove the dead price field

**Files:** `admin/tour-edit.php`

This is the defect. Owners type a price into a field that writes a column nothing reads.

- [ ] **Step 1: Stop writing the dead column**

In `admin/tour-edit.php`, remove this line from the `$data` array (around line 45):

```php
                ':price'     => trim($_POST['price']    ?? ''),
```

Then remove `price` from the INSERT column list and its `:price` placeholder. Replace:

```php
                    "INSERT INTO tours (name,slug,category,tag_label,duration,price,short_desc,long_desc,highlights_json,
                                        price_amount,price_per_person,max_pax,whats_included)
                     VALUES (:name,:slug,:category,:tag_label,:duration,:price,:short_desc,:long_desc,:highlights,
                             :price_amount,:per_person,:max_pax,:whats_included)",
```

with:

```php
                    "INSERT INTO tours (name,slug,category,tag_label,duration,short_desc,long_desc,highlights_json,
                                        price_amount,price_per_person,max_pax,whats_included)
                     VALUES (:name,:slug,:category,:tag_label,:duration,:short_desc,:long_desc,:highlights,
                             :price_amount,:per_person,:max_pax,:whats_included)",
```

And in the UPDATE, replace:

```php
                     duration=:duration,price=:price,short_desc=:short_desc,long_desc=:long_desc,highlights_json=:highlights,
```

with:

```php
                     duration=:duration,short_desc=:short_desc,long_desc=:long_desc,highlights_json=:highlights,
```

**Do not add a migration and do not drop the column** — existing rows keep their text so Step 3
can show it back to the owner.

- [ ] **Step 2: Remove the dead input**

Replace this whole field block:

```php
        <div class="field">
          <label>Price <span class="text-muted">(activities; shown on card)</span></label>
          <input type="text" name="price" value="<?= e($tour['price'] ?? '') ?>" placeholder="e.g. From $60 / person">
        </div>
```

with nothing — delete it entirely. Its label claimed "shown on card", which was never true.

- [ ] **Step 3: Relabel the real field and show any legacy value**

Replace:

```php
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Booking price <span style="color:var(--muted);font-weight:400">(numeric — used for guest requests &amp; the bill)</span></label>
        <input type="number" name="price_amount" step="0.01" min="0" value="<?= e($tour['price_amount'] ?? '') ?>" placeholder="Enter price" style="width:160px;padding:8px 10px">
```

with:

```php
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Price <span style="color:var(--muted);font-weight:400">(shown on the guest card, and charged on the bill)</span></label>
        <input type="number" name="price_amount" step="0.01" min="0" value="<?= e($tour['price_amount'] ?? '') ?>" placeholder="Enter price" style="width:160px;padding:8px 10px">
        <?php
          // Until this tour is priced numerically, show whatever was typed into the
          // old free-text Price field. That column is written by nothing now and read
          // by nothing ever — this is the only way the owner sees it again. It is NOT
          // auto-converted: "From $60 / person" would mean guessing a currency and
          // guessing per-person, then silently charging a guest that number.
          $__legacyPrice = trim((string)($tour['price'] ?? ''));
        ?>
        <?php if ($__legacyPrice !== '' && !is_priced($tour['price_amount'] ?? null)): ?>
        <p class="text-muted" style="margin:6px 0 0;font-size:12.5px">
          Previously entered as text: <strong><?= e($__legacyPrice) ?></strong> — retype it above as a number to use it.
        </p>
        <?php endif; ?>
```

- [ ] **Step 4: `is_priced()` is already available here — add nothing**

`admin/tour-edit.php:5` requires `includes/booking.php`, which itself requires
`includes/services.php` at its top. So `is_priced()` and `format_price()` are already in scope.
**Do not add a require** — verify with `grep -n require_once admin/tour-edit.php` and confirm in
your report that you left the block untouched.

- [ ] **Step 5: Verify**

Run: `php -l admin/tour-edit.php` → expect no syntax errors.

Then prove the round trip — that a saved price lands in the column the portal reads, and that the
dead column is no longer written:

```bash
php -r '
$_SERVER["REQUEST_METHOD"]="GET";
require_once "includes/db.php";
$t = db_query("SELECT id,name,price,price_amount FROM tours ORDER BY id LIMIT 1")->fetch();
printf("before: %s | legacy=%s numeric=%s\n", $t["name"], var_export($t["price"],true), var_export($t["price_amount"],true));
db_query("UPDATE tours SET price = :p WHERE id = :i", [":p"=>"From \$60 / person", ":i"=>$t["id"]]);
echo "seeded a legacy value for the hint test — tour id ".$t["id"]."\n";'
```

Then open `/admin/tour-edit.php?id=<that id>` **in your head** by rendering it:

```bash
php -r '
$_SERVER["REQUEST_METHOD"]="GET";
$id = (int)trim(shell_exec("php -r \"require \\\"includes/db.php\\\"; echo db_query(\\\"SELECT id FROM tours ORDER BY id LIMIT 1\\\")->fetchColumn();\""));
$_GET=["id"=>(string)$id];
require_once "includes/auth.php"; require_once "includes/db.php"; require_once "includes/icons.php";
session_init();
$r=db_query("SELECT id FROM admin_users WHERE role=:r LIMIT 1",[":r"=>"owner"])->fetch();
$_SESSION["admin_id"]=(int)$r["id"]; $_SESSION["admin_role"]="owner";
ob_start(); include "admin/tour-edit.php"; $h=ob_get_clean();
echo "dead input present:  ".(strpos($h,"name=\"price\"")!==false ? "*** STILL THERE ***" : "removed")."\n";
echo "legacy hint shown:   ".(strpos($h,"Previously entered as text")!==false ? "yes" : "NO")."\n";
echo "legacy value shown:  ".(strpos($h,"From \$60 / person")!==false ? "yes" : "NO")."\n";
echo "numeric field label: ".(strpos($h,"shown on the guest card")!==false ? "updated" : "NOT updated")."\n";'
```

Expected: `removed`, `yes`, `yes`, `updated`.

**Careful with the first check** — `name="price_amount"` also contains the substring `name="price`.
Use the exact string `name="price"` including the closing quote, as above, and say in your report
which you used.

Finally, clean up the seeded legacy value:

```bash
php -r 'require "includes/db.php"; db_query("UPDATE tours SET price = NULL WHERE price = :p", [":p"=>"From \$60 / person"]); echo "cleaned\n";'
```

- [ ] **Step 6: Commit**

```bash
git add admin/tour-edit.php
git commit -m "fix(admin): remove the dead Price field that swallowed activity prices"
```

---

### Task 3: The Tours list shows Price and where it's offered

**Files:** `admin/tours.php`

- [ ] **Step 1: Fetch the venue names**

In `admin/tours.php`, replace:

```php
    "SELECT t.*,
        (SELECT filename FROM tour_images WHERE tour_id = t.id AND is_hero = TRUE LIMIT 1) AS hero_img
     FROM tours t $where
```

with:

```php
    "SELECT t.*,
        (SELECT filename FROM tour_images WHERE tour_id = t.id AND is_hero = TRUE LIMIT 1) AS hero_img,
        -- NULL here means the tour has no tour_venues rows, which fetch_portal_activities()
        -- treats as \"offered everywhere\" — so a null check is the display rule.
        (SELECT string_agg(v.name, ', ' ORDER BY v.name)
           FROM tour_venues tv JOIN venues v ON v.id = tv.venue_id
          WHERE tv.tour_id = t.id) AS venue_names
     FROM tours t $where
```

- [ ] **Step 2: Add the columns**

Replace the header row:

```php
            <th>Name</th>
            <th>Category</th>
            <th>Duration</th>
            <th>Published</th>
```

with:

```php
            <th>Name</th>
            <th>Category</th>
            <th>Price</th>
            <th>Offered at</th>
            <th>Published</th>
```

Duration is dropped from the list to make room — it is still editable on the tour itself, and
price and reach are what determine whether a guest can book.

**Leave `admin/tours.php:36` alone.** `search_where()` includes `COALESCE(t.duration,'')`, so
duration remains searchable even though the column is gone from the table. Removing it from the
search too would quietly break an existing behaviour.

Then replace the matching body cells:

```php
            <td><span class="badge badge--blue"><?= e(ucfirst($tour['category'])) ?></span></td>
            <td class="text-muted"><?= e($tour['duration'] ?: '—') ?></td>
```

with:

```php
            <td><span class="badge badge--blue"><?= e(ucfirst($tour['category'])) ?></span></td>
            <td>
              <?php if (is_priced($tour['price_amount'] ?? null)): ?>
                <?= e(format_price((float)$tour['price_amount'])) ?><?php if (!empty($tour['price_per_person']) && $tour['price_per_person'] !== 'f'): ?> <span class="text-muted" style="font-size:12px">/pp</span><?php endif; ?>
              <?php else: ?>
                <span class="badge badge--orange">no price</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= $tour['venue_names'] !== null ? e($tour['venue_names']) : 'All properties' ?></td>
```

- [ ] **Step 3: Add the require — this file genuinely needs it**

Unlike `tour-edit.php`, `admin/tours.php` requires only auth, db, icons and the two pagination
helpers; nothing in that chain pulls in `includes/services.php`, so `is_priced()` and
`format_price()` would both be undefined. Replace:

```php
require_once __DIR__ . '/../includes/admin-pagination.php';
```

with:

```php
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/services.php';        // is_priced(), format_price()
```

- [ ] **Step 4: Verify**

Run: `php -l admin/tours.php` → no syntax errors.

Then render the list and read the new columns:

```bash
php -r '
$_SERVER["REQUEST_METHOD"]="GET"; $_GET=[];
require_once "includes/auth.php"; require_once "includes/db.php"; require_once "includes/icons.php";
require_once "includes/pagination.php"; require_once "includes/admin-pagination.php";
session_init();
$r=db_query("SELECT id FROM admin_users WHERE role=:r LIMIT 1",[":r"=>"owner"])->fetch();
$_SESSION["admin_id"]=(int)$r["id"]; $_SESSION["admin_role"]="owner";
ob_start(); include "admin/tours.php"; $h=ob_get_clean();
printf("row markup balanced: %d <tr> / %d </tr>\n", substr_count($h,"<tr"), substr_count($h,"</tr>"));
preg_match_all("#<tr[^>]*>(.*?)</tr>#s",$h,$m);
foreach (array_slice($m[1],0,6) as $row) {
  preg_match_all("#<t[dh][^>]*>(.*?)</t[dh]>#s",$row,$c);
  $cells=array_map(fn($x)=>trim(preg_replace("/\s+/"," ",strip_tags($x))),$c[1]);
  $cells=array_slice($cells,0,6);
  echo "  | ".implode(" | ",$cells)." |\n";
}'
```

Expected: balanced `<tr>` counts, a header row reading `… Name | Category | Price | Offered at | Published …`,
and data rows showing a `no price` badge and `All properties` for the current seed data. Report the
actual output.

- [ ] **Step 5: Commit**

```bash
git add admin/tours.php
git commit -m "feat(admin): show price and reach on the tours list"
```

---

### Task 4: Unpriced badge on the Service pricing page

**Files:** `admin/services.php`

- [ ] **Step 1: Count the unpriced options**

In `admin/services.php`, replace:

```php
<?php foreach ($SERVICES as $svc => $svcLabel):
    $rows   = fetch_service_options($svc, false);
    $active = array_sum(array_map(fn($r) => $r['is_active'] ? 1 : 0, $rows)); ?>
```

with:

```php
<?php foreach ($SERVICES as $svc => $svcLabel):
    $rows     = fetch_service_options($svc, false);
    $active   = array_sum(array_map(fn($r) => $r['is_active'] ? 1 : 0, $rows));
    $unpriced = array_sum(array_map(fn($r) => is_priced($r['price_amount']) ? 0 : 1, $rows)); ?>
```

- [ ] **Step 2: Show it in the card header**

Replace:

```php
    <span class="svc-count"><?= (int)$active ?> active · <?= count($rows) ?> total</span>
```

with:

```php
    <span class="svc-count"><?= (int)$active ?> active · <?= count($rows) ?> total<?php if ($unpriced > 0): ?> · <span class="badge badge--orange"><?= (int)$unpriced ?> unpriced</span><?php endif; ?></span>
```

- [ ] **Step 3: Badge the individual rows**

Replace:

```php
        <span class="inp-money svc-money">
          <span class="inp-money__cur"><?= e($currency) ?></span>
          <input type="number" name="price_amount" class="inp inp--num no-spin svc-price" value="<?= e($fmt_price($r['price_amount'])) ?>" min="0" step="0.01" placeholder="0">
        </span>
```

with:

```php
        <span class="inp-money svc-money">
          <span class="inp-money__cur"><?= e($currency) ?></span>
          <input type="number" name="price_amount" class="inp inp--num no-spin svc-price" value="<?= e($fmt_price($r['price_amount'])) ?>" min="0" step="0.01" placeholder="0">
        </span>
        <?php if (!is_priced($r['price_amount'])): ?><span class="badge badge--orange" data-tip="A price of 0 shows the label only — guests see no price">no price</span><?php endif; ?>
```

- [ ] **Step 4: Verify**

Run: `php -l admin/services.php` → no syntax errors.

`admin/services.php` already requires `includes/services.php` (it calls `fetch_service_options()`),
so `is_priced()` is available — confirm that by reading the top of the file and say so.

Then render it:

```bash
php -r '
$_SERVER["REQUEST_METHOD"]="GET"; $_GET=[];
require_once "includes/auth.php"; require_once "includes/db.php"; require_once "includes/icons.php";
session_init();
$r=db_query("SELECT id FROM admin_users WHERE role=:r LIMIT 1",[":r"=>"owner"])->fetch();
$_SESSION["admin_id"]=(int)$r["id"]; $_SESSION["admin_role"]="owner";
ob_start(); include "admin/services.php"; $h=ob_get_clean();
printf("no-price badges: %d\n", substr_count($h,">no price<"));
preg_match_all("#<span class=\"svc-count\">(.*?)</span>\s*</div>#s",$h,$m);
foreach ($m[1] as $c) echo "  header: ".trim(preg_replace("/\s+/"," ",strip_tags($c)))."\n";'
```

Expected: 8 `no price` badges (every seeded option is 0.00), and each service header ending
`· 4 unpriced`. Report the actual output.

- [ ] **Step 5: Commit**

```bash
git add admin/services.php
git commit -m "feat(admin): flag unpriced service options"
```

---

### Task 5: Two-up browse cards on mobile

**Files:** `css/portal-app.css`, `includes/app/activities.php`

- [ ] **Step 1: Two columns, and tighten the card below 720px**

In `css/portal-app.css`, replace:

```css
.pa-grid{display:grid;grid-template-columns:1fr;gap:12px;}
.pa-grid .pa-card{margin-bottom:0;}
```

with:

```css
/* Two-up on a phone: these are browse lists (Activities, What's on), so a guest
   is scanning options rather than reading one. The 720px rule below widens it
   further on desktop. */
.pa-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
.pa-grid .pa-card{margin-bottom:0;}
/* A card whose request form is open spans the row — the form (guests, date,
   notes, total) cannot work in a ~165px column. Same trick as .cx-tile. */
.pa-grid .pa-card.is-open{grid-column:1 / -1;}
@media (max-width:719px){
  .pa-grid .pa-media{height:92px;}
  .pa-grid .pa-card__body{padding:11px 12px;}
  .pa-grid .pa-card__title{font-size:14px;}
  .pa-grid .pa-card__meta{font-size:11.5px;}
  .pa-grid .pa-card .pa-btn{padding:10px 8px;font-size:13px;}
  /* An open card is full width again, so its controls get their room back. */
  .pa-grid .pa-card.is-open .pa-media{height:120px;}
  .pa-grid .pa-card.is-open .pa-btn{padding:11px 14px;font-size:14px;}
}
```

- [ ] **Step 2: Mark the open card**

In `includes/app/activities.php`, replace:

```js
  document.querySelectorAll('.pa-card .act-toggle').forEach(function(btn){
    var form = btn.parentNode.querySelector('.act-form');
    btn.addEventListener('click', function(){
      var open = form.style.display !== 'none';
      form.style.display = open ? 'none' : 'block';
      if(!open){ fmtTotal(form); form.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    });
  });
```

with:

```js
  document.querySelectorAll('.pa-card .act-toggle').forEach(function(btn){
    var form = btn.parentNode.querySelector('.act-form');
    var card = btn.closest('.pa-card');
    btn.addEventListener('click', function(){
      var open = form.style.display !== 'none';
      form.style.display = open ? 'none' : 'block';
      // The CSS keys on is-open to span the card across both mobile columns.
      if (card) card.classList.toggle('is-open', !open);
      if(!open){ fmtTotal(form); form.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    });
  });
```

- [ ] **Step 3: Verify**

Run: `php -l includes/app/activities.php` → no syntax errors.

**Do NOT attempt browser verification** — I will do the 375px walkthrough myself in Task 6.

**Reason about and report on these. If any is real, report it as a concern — do NOT silently change the code:**

- **i.** The category chips filter cards with `cd.style.display = 'none'`. In a two-column grid, does hiding a card leave a gap, or do the remaining cards reflow? Say which, and whether `display:none` is the right mechanism inside a grid.
- **ii.** `.pa-card` is used outside `.pa-grid` too — the check-in cards, the bill, the party roster. Confirm every rule you added is scoped under `.pa-grid` so nothing else is affected. List anywhere `.pa-card` appears without a `.pa-grid` ancestor.
- **iii.** The What's on board (`_greeting_board.php`) uses `.pa-grid` but its cards have no `act-toggle`, so they never get `is-open`. Confirm its Join form (a single button) is usable at ~165px, and that its `.pa-media` and price line survive the tightening.
- **iv.** `.pa-btn` has `width:100%`. At 10px 8px padding and 13px font in a 165px column, does the label "Request" still fit on one line? What about "Download my signed waiver" — is that button inside `.pa-grid` anywhere?

- [ ] **Step 4: Commit**

```bash
git add css/portal-app.css includes/app/activities.php
git commit -m "feat(portal): two-up browse cards on mobile"
```

---

### Task 6: Full verification

**Files:** none — this task only runs and observes.

- [ ] **Step 1: Every suite**

```bash
for f in tests/*_logic.php; do printf "%-30s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done
```

Expected: all `ALL PASS` except `team_logic.php`'s two known pre-existing failures.

- [ ] **Step 2: Lint**

```bash
for f in includes/services.php admin/tour-edit.php admin/tours.php admin/services.php includes/app/activities.php; do php -l "$f"; done
```

Expected: `No syntax errors detected` five times.

- [ ] **Step 3: Confirm the dead column is no longer written**

```bash
grep -rn "':price'\|,price," admin/tour-edit.php || echo "tours.price is no longer written"
```

Expected: the fallback message. If anything matches, the write survived somewhere.

- [ ] **Step 4: Report**

Summarise what passed and what did not, plus `git log --oneline` for the tasks. Do **not** claim
success for anything you did not run.

---

## Notes for the implementer

- **Never auto-convert `tours.price` into `price_amount`.** It is free text like "From $60 / person";
  guessing a number there means silently charging a guest something nobody typed.
- **Do not drop the `tours.price` column** and do not write a migration. Stopping the write is the
  whole change; the data stays for the owner to read off.
- `admin/services.php` already loads `includes/services.php`; `admin/tours.php` and
  `admin/tour-edit.php` may not — check rather than assume, and report what you found.
- The `.pa-card` tightening must be scoped under `.pa-grid`. Other screens reuse that class.
