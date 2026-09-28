# Inventory Pages — Part A (list, item, locations, location stock) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give owners and managers the Inventory admin pages — one central list, an item page with every everyday stock action, a locations page (areas + count schedules), and a per-location stock page (par levels + restock) — all on top of the Plan 1 inventory core.

**Architecture:** A new `includes/inventory-views.php` holds the read models, scope rules and settings writers (never quantities). Pages are thin: they validate the request, call one helper, and render with the existing admin design system (`card`, `data-table`, `.eselect`, `.optchip`, `.dp-btn`, `dt_*`). Every quantity change still goes through `inv_move()` via the Plan 1 actions; `inv_apply_item_action()` is the one dispatcher the item page uses, so it is unit-tested.

**Tech Stack:** PHP 8.2 (vanilla), PostgreSQL via PDO (`db_query()`), plain-PHP test scripts.

**Spec:** `docs/superpowers/specs/2026-09-27-inventory-assets-design.md` §4. **Part B** (count screen, discrepancy queue, employee Assigned-assets tab, My Work due counts) follows this plan.

---

## Conventions an engineer new to this repo must know

- Work in the worktree `/Users/patrikgiuliana/.config/superpowers/worktrees/Tribal Sand/inventory-pages` on branch `feat/inventory-pages` (created from `master`, which already contains Plan 1). **Never push**; never touch `/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand` (another session works there).
- SQL goes through `db_query($sql, $params)`. **pdo_pgsql forbids reusing a named placeholder in one statement** and **errors on a bound param the statement doesn't use** — build separate param arrays per statement.
- Inventory core (Plan 1, `includes/inventory.php`): `inv_move()` is the only quantity writer; `inv_transfer()`, `inv_report_loss()`, `inv_replace()`, `inv_asset_create()`, `inv_set_par()`, `inv_restock_to_par()` wrap it. Refusals are `InvRefusal` (`PosRefusal extends InvRefusal`). `inv_move_in_scope($from, $to, $venueIds)` is the write-scope rule. `inv_supported()` guards everything.
- Roles: `require_manager()` = owner or manager. `admin_venue_ids()` → `null` for the owner (all), else the account's venue ids. Scoping is re-checked on every POST — posted ids are requests.
- Admin UI rules: no native chrome — selects use `class="eselect"` (forms) or `class="filter-select"` (filter bars), dates use the `.dp-btn` datepicker + a hidden input, toggles use `.optchip`, file inputs use `.filefield` with `data-file-input` / `data-file-name`. Flash pattern: `$_SESSION['inv_flash'] = ['type' => 'success|error|info', 'msg' => …]` then PRG redirect. CSS variables: `--brand --text --muted --border --bg --white --red --green --radius --shadow`.
- Tests: `php tests/<name>.php` — a `check()` helper, a DB block in ONE rolled-back transaction, SKIP when no DB. Run with the worktree root as cwd.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Stage only the files a task names.

## File map

| File | Status | Responsibility |
|---|---|---|
| `includes/inventory-views.php` | Create | Pure scope/label helpers; read models (central list, gone ledger, item/location views); settings writers (item details, areas, schedules); `inv_apply_item_action()` |
| `tests/inventory_views_logic.php` | Create | Pure + rolled-back DB tests for the above |
| `admin/_layout.php` | Modify | "Inventory" sidebar group (owner + manager) |
| `admin/inventory.php` | Create | Central list + filters; Sold / Lost ledger view |
| `admin/inventory-item.php` | Create | Item create/edit, where it is, units, history, actions |
| `admin/inventory-locations.php` | Create | Locations list, add area, count schedule + responsible person |
| `admin/inventory-location.php` | Create | One location's stock, par levels, restock to par |
| `CLAUDE.md` | Modify | Inventory pages bullet + file map rows |

---

### Task 1: Pure helpers in `inventory-views.php`

**Files:**
- Create: `includes/inventory-views.php`
- Create: `tests/inventory_views_logic.php`

- [ ] **Step 1: Write the failing test file**

Create `tests/inventory_views_logic.php`:

```php
<?php
declare(strict_types=1);
// Inventory pages — read models, scope and settings helpers. Run: php tests/inventory_views_logic.php
// Pure rules always run. The DB block runs in ONE rolled-back transaction and
// SKIPs when no DB is reachable or add_inventory.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-views.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Visibility (mirrors inv_move_in_scope) ──────────────────────────────────
$store = ['kind' => 'store', 'venue_id' => null]; $amani = ['kind' => 'property', 'venue_id' => 1];
$zuri = ['kind' => 'property', 'venue_id' => 3];  $office = ['kind' => 'person', 'venue_id' => null];
check('visible: owner sees everything', inv_location_visible($zuri, null) && inv_location_visible($office, null));
check('visible: manager sees their own property', inv_location_visible($amani, [1]));
check('visible: manager sees shared Main stock', inv_location_visible($store, [1]));
check('visible: manager does not see another property', !inv_location_visible($zuri, [1]));
check('visible: a venue-less team member is owner-only', !inv_location_visible($office, [1]));
check('visible: venue id read back as a string still matches', inv_location_visible(['kind' => 'area', 'venue_id' => '1'], [1]));
check('editable: owner edits anything', inv_location_editable($store, null));
check('editable: a manager cannot edit shared Main stock', !inv_location_editable($store, [1]));
check('editable: a manager edits their own property', inv_location_editable($amani, [1]) && !inv_location_editable($zuri, [1]));

// ── Labels, sorting, money, dates ───────────────────────────────────────────
check('label: area under its property', inv_location_label(['kind' => 'area', 'name' => 'Kitchen', 'parent_name' => 'My Amani']) === 'My Amani › Kitchen');
check('label: outlet and person are marked', str_contains(inv_location_label(['kind' => 'outlet', 'name' => 'Shop']), 'outlet')
    && str_contains(inv_location_label(['kind' => 'person', 'name' => 'Jane']), 'team'));
$sorted = inv_sort_locations([
    ['kind' => 'person', 'name' => 'Jane', 'sort_order' => 0],
    ['kind' => 'area', 'name' => 'Kitchen', 'parent_name' => 'Zuri', 'sort_order' => 1],
    ['kind' => 'outlet', 'name' => 'Shop', 'sort_order' => 0],
    ['kind' => 'property', 'name' => 'Zuri', 'sort_order' => 0],
    ['kind' => 'property', 'name' => 'My Amani', 'sort_order' => 0],
    ['kind' => 'store', 'name' => 'Main stock', 'sort_order' => 0],
]);
check('sort: Main stock, properties A→Z with their areas, outlets, people',
    array_column($sorted, 'name') === ['Main stock', 'My Amani', 'Zuri', 'Kitchen', 'Shop', 'Jane']);
check('breakdown: joins name + qty, caps the list',
    inv_breakdown_label([['name' => 'Main', 'qty' => 30], ['name' => 'A', 'qty' => 20], ['name' => 'B', 'qty' => 5]], 2) === 'Main 30 · A 20 · +1 more');
check('money: whole amounts without decimals', inv_money(1700.0, 'KES') === 'KES 1,700');
check('money: cents shown when present, code upper-cased', inv_money(12.5, 'usd') === 'USD 12.50');
check('money: unknown value is a dash', inv_money(null, 'KES') === '—');
check('date: a valid Y-m-d passes', inv_ymd_or('2026-09-01', 'x') === '2026-09-01');
check('date: garbage falls back', inv_ymd_or('9/1/2026', '2026-01-01') === '2026-01-01' && inv_ymd_or(null, 'f') === 'f');
check('target: a location', inv_parse_target('loc:12') === ['loc', 12]);
check('target: a team member', inv_parse_target('staff:5') === ['staff', 5]);
check('target: anything else is refused', inv_parse_target('12') === null && inv_parse_target('loc:x') === null);

// ── Item form ───────────────────────────────────────────────────────────────
[$v, $e] = inv_item_from_post(['name' => 'Dinner plate', 'item_type' => 'operational', 'replacement_value' => '850', 'currency' => 'kes', 'category' => 'Kitchen', 'is_active' => '1']);
check('item form: a valid plate', !$e && $v['name'] === 'Dinner plate' && $v['replacement_value'] === 850.0 && $v['currency'] === 'KES' && $v['tracking'] === 'qty' && $v['is_active'] === true);
[$v, $e] = inv_item_from_post(['name' => '', 'item_type' => 'gadget', 'replacement_value' => '-1', 'low_stock_at' => 'two']);
check('item form: name, type, value and alert all flagged', isset($e['name'], $e['item_type'], $e['replacement_value'], $e['low_stock_at']));
[$v, $e] = inv_item_from_post(['name' => 'Laptop', 'item_type' => 'employee', 'tracking' => 'serial', 'currency' => 'KES']);
check('item form: serial tracking + blank value = unknown', !$e && $v['tracking'] === 'serial' && $v['replacement_value'] === null && $v['is_active'] === false);

// ── DB-backed ───────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!inv_supported()) {
    echo "\nSKIP  DB block (add_inventory.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx   = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins   = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $count = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();
    $refused = function (callable $fn): string { try { $fn(); } catch (InvRefusal $e) { return $e->getMessage(); } return ''; };

    $vA = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ View A')", [':s' => "zz-view-a-{$sfx}"]);
    $vB = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ View B')", [':s' => "zz-view-b-{$sfx}"]);
    $store = inv_store_location_id();
    $locA  = inv_property_location_id($vA);
    $locB  = inv_property_location_id($vB);
    $owner = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'owner', 'ZZ Owner', TRUE)", [':e' => "zz-view-o-{$sfx}@example.com"]);
    $mgr   = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'manager', 'ZZ Manager', TRUE)", [':e' => "zz-view-m-{$sfx}@example.com"]);
    db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:u, :v)', [':u' => $mgr, ':v' => $vA]);
    $jane  = $ins("INSERT INTO hr_staff (full_name, venue_id) VALUES ('ZZ Jane', :v)", [':v' => $vA]);
    $plates = inv_create_item(['name' => 'ZZ View plate', 'item_type' => 'operational', 'category' => 'ZZ Kitchen', 'replacement_value' => 850]);
    $laptop = inv_create_item(['name' => 'ZZ View laptop', 'item_type' => 'employee', 'tracking' => 'serial', 'replacement_value' => 95000]);

    // ── DB checks (tasks 2–3 insert their blocks above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/inventory_views_logic.php`
Expected: fatal — `Failed opening required '.../includes/inventory-views.php'`.

- [ ] **Step 3: Create `includes/inventory-views.php` with the pure helpers**

```php
<?php
declare(strict_types=1);
/**
 * Inventory & Assets — read models and admin helpers behind the Inventory pages
 * (central list, item page, locations, one location's stock). Spec:
 * docs/superpowers/specs/2026-09-27-inventory-assets-design.md §4.
 * Test: php tests/inventory_views_logic.php
 *
 * Load-bearing rules:
 *   • QUANTITIES are never written here — every stock change goes through
 *     inv_move() and the actions in inventory.php (inv_apply_item_action() only
 *     dispatches to them). This file writes settings only: item details, areas,
 *     count schedules.
 *   • Visibility mirrors inv_move_in_scope(): the owner sees everything; a manager
 *     sees their properties' locations plus SHARED ones (Main stock, venue-less
 *     outlets) — never a venue-less team member (owner business).
 *   • Every id a page receives (location, team member, item, unit) is re-checked
 *     here — a posted id is a request, never a fact.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hr.php';          // hr_staff_in_venue_scope(), hr_staff_supported()
require_once __DIR__ . '/inventory.php';

const INV_STATUS_FILTERS = ['' => 'Everything', 'in_stock' => 'In stock', 'assigned' => 'Assigned to people', 'sold' => 'Sold', 'written_off' => 'Lost / written off'];
const INV_KIND_ORDER     = ['store' => 0, 'property' => 1, 'area' => 1, 'outlet' => 3, 'person' => 4];
const INV_REASON_LABELS  = [
    'receive' => ['Received', 'badge--green'], 'opening' => ['Opening', 'badge--green'], 'found' => ['Found', 'badge--green'],
    'void' => ['Void', 'badge--orange'], 'sale' => ['Sold', 'badge--blue'], 'transfer' => ['Moved', 'badge--teal'],
    'assign' => ['Assigned', 'badge--teal'], 'return' => ['Returned', 'badge--teal'], 'replaced' => ['Replacement', 'badge--teal'],
    'broken' => ['Broken', 'badge--red'], 'missing' => ['Missing', 'badge--red'], 'stolen' => ['Stolen', 'badge--red'],
    'written_off' => ['Written off', 'badge--red'],
];
const INV_LOSS_LABELS      = ['broken' => 'Broken', 'missing' => 'Missing', 'stolen' => 'Stolen', 'written_off' => 'Written off'];
const INV_CONDITION_LABELS = ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'];
const INV_COUNT_EVERY      = ['' => 'Manual only', '1' => 'Every day', '7' => 'Every week', '14' => 'Every 2 weeks', '30' => 'Every month'];

// ── Pure helpers ────────────────────────────────────────────────────────────

/** May an account with $venueIds (null = owner) SEE this location row? — PURE. */
function inv_location_visible(array $loc, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    $v = $loc['venue_id'] ?? null;
    if ($v === null || $v === '') return ($loc['kind'] ?? '') !== 'person';   // shared Main stock / outlets; a venue-less person is owner-only
    return in_array((int)$v, array_map('intval', $venueIds), true);
}

/** May it change this location's SETTINGS (name, schedule, areas)? Owner, or a manager for their own property — PURE. */
function inv_location_editable(array $loc, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    $v = $loc['venue_id'] ?? null;
    return $v !== null && $v !== '' && in_array((int)$v, array_map('intval', $venueIds), true);
}

/** Human label for pickers and tables — PURE. */
function inv_location_label(array $l): string {
    return match ((string)($l['kind'] ?? '')) {
        'area'   => trim((string)($l['parent_name'] ?? '') . ' › ' . (string)$l['name'], ' ›'),
        'outlet' => (string)$l['name'] . ' (shop / outlet)',
        'person' => (string)$l['name'] . ' (team)',
        default  => (string)$l['name'],
    };
}

/** Sort key: Main stock, then each property followed by its areas (A→Z), then outlets, then people — PURE. */
function inv_location_group_key(array $l): array {
    $kind   = (string)($l['kind'] ?? '');
    $isArea = $kind === 'area';
    $group  = $isArea ? mb_strtolower((string)($l['parent_name'] ?? '')) : mb_strtolower((string)($l['name'] ?? ''));
    return [INV_KIND_ORDER[$kind] ?? 9, $group, $isArea ? 1 : 0, (int)($l['sort_order'] ?? 0), mb_strtolower((string)($l['name'] ?? ''))];
}

function inv_sort_locations(array $rows): array {
    usort($rows, fn(array $a, array $b): int => inv_location_group_key($a) <=> inv_location_group_key($b));
    return $rows;
}

/** "Main 30 · My Amani 20 · +2 more" — PURE. */
function inv_breakdown_label(array $rows, int $max = 4): string {
    $parts = [];
    foreach ($rows as $r) $parts[] = (string)$r['name'] . ' ' . (int)$r['qty'];
    if (count($parts) > $max) {
        $more  = count($parts) - $max;
        $parts = array_slice($parts, 0, $max);
        $parts[] = "+{$more} more";
    }
    return implode(' · ', $parts);
}

/** "KES 1,700" / "USD 12.50" / "—" — PURE. */
function inv_money(?float $amount, string $currency): string {
    if ($amount === null) return '—';
    $dec = abs($amount - round($amount)) < 0.005 ? 0 : 2;
    return strtoupper($currency) . ' ' . number_format($amount, $dec);
}

/** A Y-m-d date, or $fallback — PURE. */
function inv_ymd_or(mixed $v, string $fallback): string {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $fallback;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : $fallback;
}

/** A move destination from a picker: "loc:12" or "staff:5" → ['loc', 12] / ['staff', 5]; else null — PURE. */
function inv_parse_target(string $v): ?array {
    return preg_match('/^(loc|staff):(\d+)$/', $v, $m) ? [$m[1], (int)$m[2]] : null;
}

/** The item details form → [values, errors] — PURE. */
function inv_item_from_post(array $in): array {
    $e    = [];
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) $e['name'] = 'Give the item a name (up to 160 characters).';
    $type = (string)($in['item_type'] ?? '');
    if (!isset(INV_TYPES[$type])) $e['item_type'] = 'Pick a type.';
    $val = trim((string)($in['replacement_value'] ?? ''));
    if ($val !== '' && (!is_numeric($val) || (float)$val < 0 || (float)$val > 9999999999.99)) $e['replacement_value'] = 'Enter a value of zero or more.';
    $cur = strtoupper(trim((string)($in['currency'] ?? INV_DEFAULT_CURRENCY)));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $e['currency'] = 'Pick a currency.';
    $low = trim((string)($in['low_stock_at'] ?? ''));
    if ($low !== '' && (!ctype_digit($low) || (int)$low > INV_MAX_QTY)) $e['low_stock_at'] = 'The low-stock alert must be a whole number.';
    $category = trim((string)($in['category'] ?? ''));
    if (mb_strlen($category) > 60) $e['category'] = 'Category is up to 60 characters.';
    $icon = trim((string)($in['icon'] ?? ''));
    if (mb_strlen($icon) > 8) $e['icon'] = 'Use a single emoji.';
    return [[
        'name'              => $name,
        'item_type'         => $type,
        'category'          => $category,
        'sku'               => mb_substr(trim((string)($in['sku'] ?? '')), 0, 60),
        'icon'              => $icon,
        'tracking'          => ($in['tracking'] ?? 'qty') === 'serial' ? 'serial' : 'qty',
        'unit_label'        => mb_substr(trim((string)($in['unit_label'] ?? '')), 0, 20),
        'replacement_value' => $val === '' || isset($e['replacement_value']) ? null : round((float)$val, 2),
        'currency'          => $cur,
        'low_stock_at'      => $low === '' || isset($e['low_stock_at']) ? null : (int)$low,
        'is_active'         => !empty($in['is_active']),
    ], $e];
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_views_logic.php | tail -3`
Expected: every pure check PASS, then `ALL PASS` (the DB block runs with no checks yet).

- [ ] **Step 5: Commit**

```bash
git add includes/inventory-views.php tests/inventory_views_logic.php
git commit -m "feat(inventory): view helpers — visibility, labels, sorting, money, item form

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Read models — locations, central list, gone ledger, item and location views

**Files:**
- Modify: `includes/inventory-views.php` (append)
- Modify: `tests/inventory_views_logic.php` (insert above the marker)

- [ ] **Step 1: Write the failing DB checks**

Insert above `    // ── DB checks (tasks 2–3 insert their blocks above this line) ──`:

```php
    // ── Read models ──
    $kitchen = inv_create_area($locA, 'ZZ Kitchen');
    inv_move(['item_id' => $plates, 'qty' => 30, 'to' => $store, 'reason' => 'receive']);
    inv_transfer($plates, 10, $store, $kitchen, $owner);
    inv_transfer($plates, 5, $store, $locB, $owner);
    $pick = fn(array $rows, int $id) => array_values(array_filter($rows, fn($r) => (int)$r['id'] === $id))[0] ?? null;

    $own = inv_central_list(['q' => 'ZZ View'], null, 50, 0);
    $row = $pick($own['rows'], $plates);
    check('list: owner sees every unit (15 Main + 10 kitchen + 5 B)', $row && (int)$row['qty'] === 30 && count($row['breakdown']) === 3);
    check('list: the value is qty × replacement value', $row && (float)$row['value'] === 25500.0);
    $mine = inv_central_list(['q' => 'ZZ View'], [$vA], 50, 0);
    $row  = $pick($mine['rows'], $plates);
    check('list: a manager sees only their property + shared Main stock', $row && (int)$row['qty'] === 25
        && !in_array($locB, array_map(fn($b) => (int)$b['location_id'], $row['breakdown']), true));
    $row = $pick(inv_central_list(['q' => 'ZZ View', 'location' => $locA], null, 50, 0)['rows'], $plates);
    check('list: a property filter includes its areas', $row && (int)$row['qty'] === 10);
    check('list: an empty filter still lists items with no stock', $pick($own['rows'], $laptop) !== null);
    check('list: a location filter hides items that are not there',
        $pick(inv_central_list(['q' => 'ZZ View', 'location' => $locA], null, 50, 0)['rows'], $laptop) === null);
    inv_report_loss($plates, 2, $locB, 'broken', $owner, 'dropped');
    $gone = inv_gone_moves(['q' => 'ZZ View', 'status' => 'written_off'], null, 50, 0);
    check('gone: losses listed with their value', $gone['total'] === 1 && ($gone['totals']['KES'] ?? 0) === 1700.0);
    check('gone: a manager does not see another property’s losses', inv_gone_moves(['q' => 'ZZ View', 'status' => 'written_off'], [$vA], 50, 0)['total'] === 0);
    check('item view: where it is, scoped', count(inv_item_locations($plates, null)) === 3 && count(inv_item_locations($plates, [$vA])) === 2);
    $hist = inv_item_history($plates, [$vA]);
    check('item view: history hides moves entirely outside the manager’s places',
        !array_filter($hist, fn($m) => $m['reason'] === 'broken') && count(inv_item_history($plates, null)) > count($hist));
    inv_set_par($plates, $kitchen, 12);
    $stock = inv_location_stock($kitchen);
    check('location view: qty, par and what is short', count($stock) === 1 && (int)$stock[0]['qty'] === 10 && (int)$stock[0]['need'] === 2);
    check('location view: child areas of a property', array_column(inv_child_areas($locA), 'id') === [$kitchen]);
    $vis = inv_locations_visible([$vA]);
    $visIds = array_map(fn($l) => (int)$l['id'], $vis);
    check('locations: a manager sees Main stock, their property and its area — not B',
        in_array($store, $visIds, true) && in_array($locA, $visIds, true) && in_array($kitchen, $visIds, true) && !in_array($locB, $visIds, true));
    check('venues: a manager’s venues only', array_keys(inv_visible_venues([$vA])) === [$vA]);
    check('staff: a manager can pick their own team', isset(inv_assignable_staff([$vA])[$jane]) && !isset(inv_assignable_staff([$vB])[$jane]));

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_views_logic.php`
Expected: `FAIL  DB block threw: Call to undefined function inv_create_area()`.

- [ ] **Step 3: Append the read models to `includes/inventory-views.php`**

```php

// ── Scope in SQL ────────────────────────────────────────────────────────────

/**
 * SQL condition: is location alias $a visible for $venueIds? Appends its params
 * to $p under the $tag prefix (use a different tag for each alias in one query).
 */
function inv_visible_sql(string $a, ?array $venueIds, array &$p, string $tag = 'vis'): string {
    if ($venueIds === null) return 'TRUE';
    $ph = [];
    foreach (array_values($venueIds) as $i => $v) { $ph[] = ":{$tag}{$i}"; $p[":{$tag}{$i}"] = (int)$v; }
    $own = $ph ? "{$a}.venue_id IN (" . implode(',', $ph) . ')' : 'FALSE';
    return "({$own} OR ({$a}.venue_id IS NULL AND {$a}.kind <> 'person'))";
}

/** Published venues the account may filter by: [id => name]. */
function inv_visible_venues(?array $venueIds): array {
    $out = [];
    foreach (db_query('SELECT id, name FROM venues WHERE is_published = TRUE ORDER BY sort_order, name')->fetchAll() as $r) {
        if ($venueIds === null || in_array((int)$r['id'], array_map('intval', $venueIds), true)) $out[(int)$r['id']] = (string)$r['name'];
    }
    return $out;
}

/** Main stock + one location per published property exist (idempotent; new venues get theirs here). */
function inv_ensure_default_locations(): void {
    if (!inv_supported()) return;
    inv_store_location_id();
    $missing = db_query("SELECT v.id FROM venues v WHERE v.is_published = TRUE
                          AND NOT EXISTS (SELECT 1 FROM inv_locations l WHERE l.kind = 'property' AND l.venue_id = v.id)")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($missing as $vid) inv_property_location_id((int)$vid);
}

/** Every location the account may see, sorted for pickers and the locations page. */
function inv_locations_visible(?array $venueIds, bool $activeOnly = true): array {
    if (!inv_supported()) return [];
    $p = [];
    $w = inv_visible_sql('l', $venueIds, $p) . ($activeOnly ? ' AND l.is_active = TRUE' : '');
    $rows = db_query("SELECT l.*, pl.name AS parent_name, a.name AS assignee_name,
                             (SELECT COUNT(*) FROM inv_balances b WHERE b.location_id = l.id AND b.qty <> 0) AS item_count
                        FROM inv_locations l
                        LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                        LEFT JOIN admin_users a    ON a.id = l.count_assignee_id
                       WHERE {$w}", $p)->fetchAll();
    return inv_sort_locations($rows);
}

// ── The central list ────────────────────────────────────────────────────────

/**
 * The central list. $f: q, type, venue (int), location (int — a property includes
 * its areas), person (hr_staff id), status ('' | in_stock | assigned).
 * Returns ['total' => int, 'rows' => [item + qty + value + breakdown[]]]; qty and
 * breakdown count only VISIBLE locations matching the filters.
 */
function inv_central_list(array $f, ?array $venueIds, int $limit, int $offset): array {
    if (!inv_supported()) return ['total' => 0, 'rows' => []];
    $pl = [];                                                    // params of the vb CTE
    $lw = [inv_visible_sql('l', $venueIds, $pl), 'b.qty <> 0'];
    if (!empty($f['venue']))    { $lw[] = 'l.venue_id = :fv'; $pl[':fv'] = (int)$f['venue']; }
    if (!empty($f['location'])) { $lw[] = '(l.id = :fl OR l.parent_id = :fl2)'; $pl[':fl'] = (int)$f['location']; $pl[':fl2'] = (int)$f['location']; }
    if (!empty($f['person']))   { $lw[] = 'l.hr_staff_id = :fp'; $pl[':fp'] = (int)$f['person']; }
    $status = (string)($f['status'] ?? '');
    if ($status === 'in_stock') $lw[] = "l.kind <> 'person'";
    if ($status === 'assigned') $lw[] = "l.kind = 'person'";
    $cte = 'WITH vb AS (SELECT b.item_id, b.location_id, b.qty, l.name, l.kind
                          FROM inv_balances b JOIN inv_locations l ON l.id = b.location_id
                         WHERE ' . implode(' AND ', $lw) . ')';

    $pi = [];                                                    // params of the item filter
    $iw = ['i.is_active = TRUE'];
    if (!empty($f['type']) && isset(INV_TYPES[$f['type']])) { $iw[] = 'i.item_type = :ft'; $pi[':ft'] = (string)$f['type']; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $iw[] = "(i.name ILIKE :q1 OR COALESCE(i.sku, '') ILIKE :q2 OR COALESCE(i.category, '') ILIKE :q3)";
        $pi[':q1'] = $like; $pi[':q2'] = $like; $pi[':q3'] = $like;
    }
    if (!empty($f['venue']) || !empty($f['location']) || !empty($f['person']) || $status !== '') {
        $iw[] = 'EXISTS (SELECT 1 FROM vb WHERE vb.item_id = i.id)';
    }
    $where = implode(' AND ', $iw);
    $total = (int) db_query("{$cte} SELECT COUNT(*) FROM inv_items i WHERE {$where}", $pl + $pi)->fetchColumn();
    $rows  = db_query("{$cte} SELECT i.*, COALESCE((SELECT SUM(vb.qty) FROM vb WHERE vb.item_id = i.id), 0) AS qty
                          FROM inv_items i WHERE {$where}
                         ORDER BY i.category NULLS LAST, i.name, i.id
                         LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $pl + $pi)->fetchAll();
    if (!$rows) return ['total' => $total, 'rows' => []];

    $pb = $pl; $ph = [];
    foreach ($rows as $k => $r) { $ph[] = ":it{$k}"; $pb[":it{$k}"] = (int)$r['id']; }
    $bd = [];
    foreach (db_query("{$cte} SELECT item_id, location_id, name, kind, qty FROM vb WHERE item_id IN (" . implode(',', $ph) . ')', $pb)->fetchAll() as $b) {
        $bd[(int)$b['item_id']][] = $b;
    }
    foreach ($rows as &$r) {
        $list = $bd[(int)$r['id']] ?? [];
        usort($list, fn(array $a, array $b): int =>
            [INV_KIND_ORDER[$a['kind']] ?? 9, -(int)$a['qty'], (string)$a['name']] <=> [INV_KIND_ORDER[$b['kind']] ?? 9, -(int)$b['qty'], (string)$b['name']]);
        $r['breakdown'] = $list;
        $r['value']     = $r['replacement_value'] !== null ? round((float)$r['replacement_value'] * (int)$r['qty'], 2) : null;
    }
    unset($r);
    return ['total' => $total, 'rows' => $rows];
}

/**
 * The "Sold" / "Lost / written off" view: stock that LEFT through a sale or a loss
 * in a date window. $f: status ('sold' | 'written_off'), from, to (Y-m-d, inclusive;
 * default the last 30 days), q, type, venue, location, person — applied to the
 * location it left from. Returns total, rows, totals (per currency), from, to.
 */
function inv_gone_moves(array $f, ?array $venueIds, int $limit, int $offset): array {
    $from = inv_ymd_or($f['from'] ?? null, date('Y-m-d', strtotime('-30 days')));
    $to   = inv_ymd_or($f['to'] ?? null, date('Y-m-d'));
    if (!inv_supported()) return ['total' => 0, 'rows' => [], 'totals' => [], 'from' => $from, 'to' => $to];
    $reasons = ($f['status'] ?? '') === 'sold' ? ['sale'] : INV_LOSS_REASONS;
    $p = [];
    $w = [inv_visible_sql('l', $venueIds, $p), 'm.to_location_id IS NULL'];
    $rp = [];
    foreach ($reasons as $k => $r) { $rp[] = ":r{$k}"; $p[":r{$k}"] = $r; }
    $w[] = 'm.reason IN (' . implode(',', $rp) . ')';
    $w[] = 'm.created_at >= CAST(:dfrom AS date)';        $p[':dfrom'] = $from;
    $w[] = 'm.created_at < CAST(:dto AS date) + 1';       $p[':dto']   = $to;
    if (!empty($f['venue']))    { $w[] = 'l.venue_id = :fv'; $p[':fv'] = (int)$f['venue']; }
    if (!empty($f['location'])) { $w[] = '(l.id = :fl OR l.parent_id = :fl2)'; $p[':fl'] = (int)$f['location']; $p[':fl2'] = (int)$f['location']; }
    if (!empty($f['person']))   { $w[] = 'l.hr_staff_id = :fp'; $p[':fp'] = (int)$f['person']; }
    if (!empty($f['type']) && isset(INV_TYPES[$f['type']])) { $w[] = 'i.item_type = :ft'; $p[':ft'] = (string)$f['type']; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') { $w[] = 'i.name ILIKE :q1'; $p[':q1'] = '%' . addcslashes($q, '%_\\') . '%'; }
    $base = 'FROM inv_moves m
               JOIN inv_items i     ON i.id = m.item_id
               JOIN inv_locations l ON l.id = m.from_location_id
               LEFT JOIN admin_users a ON a.id = m.admin_user_id
               LEFT JOIN pos_sales s   ON s.id = m.pos_sale_id
              WHERE ' . implode(' AND ', $w);
    $total  = (int) db_query("SELECT COUNT(*) {$base}", $p)->fetchColumn();
    $rows   = db_query("SELECT m.*, i.name AS item_name, i.image_key, i.icon, l.name AS location_name, a.name AS user_name, s.reference AS sale_reference
                        {$base} ORDER BY m.created_at DESC, m.id DESC
                        LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $p)->fetchAll();
    $totals = inv_sum_by_currency(db_query("SELECT m.value, m.currency {$base}", $p)->fetchAll());
    return ['total' => $total, 'rows' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to];
}

// ── One item ────────────────────────────────────────────────────────────────

/** Where an item is (visible locations with stock or a par level), sorted. */
function inv_item_locations(int $itemId, ?array $venueIds): array {
    if (!inv_supported()) return [];
    $p = [':i' => $itemId];
    $w = inv_visible_sql('l', $venueIds, $p);
    return inv_sort_locations(db_query(
        "SELECT l.id, l.name, l.kind, l.venue_id, l.parent_id, l.sort_order, l.is_active, pl.name AS parent_name, b.qty, b.par_qty
           FROM inv_balances b
           JOIN inv_locations l       ON l.id = b.location_id
           LEFT JOIN inv_locations pl ON pl.id = l.parent_id
          WHERE b.item_id = :i AND (b.qty <> 0 OR b.par_qty IS NOT NULL) AND {$w}", $p)->fetchAll());
}

/** Serial units of an item: active ones at visible locations; gone ones (sold / written off) for the owner only. */
function inv_item_units(int $itemId, ?array $venueIds): array {
    if (!inv_supported()) return [];
    $rows = db_query("SELECT a.*, l.name AS location_name, l.kind, l.venue_id, pl.name AS parent_name
                        FROM inv_assets a
                        LEFT JOIN inv_locations l  ON l.id = a.location_id
                        LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                       WHERE a.item_id = :i
                       ORDER BY (a.status = 'active') DESC, a.serial NULLS LAST, a.id", [':i' => $itemId])->fetchAll();
    return array_values(array_filter($rows, fn(array $u): bool => $u['status'] === 'active'
        ? inv_location_visible(['venue_id' => $u['venue_id'], 'kind' => $u['kind']], $venueIds)
        : $venueIds === null));
}

/** An item's movement history, newest first — only moves touching a location the account may see. */
function inv_item_history(int $itemId, ?array $venueIds, int $limit = 100): array {
    if (!inv_supported()) return [];
    $p  = [':i' => $itemId];
    $vf = inv_visible_sql('lf', $venueIds, $p, 'vf');
    $vt = inv_visible_sql('lt', $venueIds, $p, 'vt');
    return db_query("SELECT m.*, a.name AS user_name, s.reference AS sale_reference, lf.name AS from_name, lt.name AS to_name, u.serial
                       FROM inv_moves m
                       LEFT JOIN inv_locations lf ON lf.id = m.from_location_id
                       LEFT JOIN inv_locations lt ON lt.id = m.to_location_id
                       LEFT JOIN admin_users a    ON a.id = m.admin_user_id
                       LEFT JOIN pos_sales s      ON s.id = m.pos_sale_id
                       LEFT JOIN inv_assets u     ON u.id = m.asset_id
                      WHERE m.item_id = :i AND ((lf.id IS NOT NULL AND {$vf}) OR (lt.id IS NOT NULL AND {$vt}))
                      ORDER BY m.created_at DESC, m.id DESC LIMIT " . max(1, min(500, $limit)), $p)->fetchAll();
}

/** POS listings selling this item (they own its name, SKU, photo, currency and alert). */
function inv_item_pos_listings(int $itemId): array {
    if (!inv_supported()) return [];
    return db_query('SELECT p.id, p.name, p.outlet_id, o.name AS outlet_name FROM pos_items p JOIN pos_outlets o ON o.id = p.outlet_id
                      WHERE p.inv_item_id = :i ORDER BY o.name', [':i' => $itemId])->fetchAll();
}

function inv_item_has_moves(int $itemId): bool {
    return inv_supported() && (bool) db_query('SELECT 1 FROM inv_moves WHERE item_id = :i LIMIT 1', [':i' => $itemId])->fetchColumn();
}

/** Existing categories, for the item form's suggestions. */
function inv_categories(): array {
    if (!inv_supported()) return [];
    return db_query("SELECT DISTINCT category FROM inv_items WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
}

// ── One location ────────────────────────────────────────────────────────────

/** A location's stock: every item with stock or a par level there, with need (short of par) and value. */
function inv_location_stock(int $locationId): array {
    if (!inv_supported()) return [];
    $rows = db_query("SELECT i.id AS item_id, i.name, i.category, i.image_key, i.icon, i.tracking, i.replacement_value, i.currency,
                             i.unit_label, b.qty, b.par_qty
                        FROM inv_balances b JOIN inv_items i ON i.id = b.item_id
                       WHERE b.location_id = :l AND (b.qty <> 0 OR b.par_qty IS NOT NULL)
                       ORDER BY i.category NULLS LAST, i.name", [':l' => $locationId])->fetchAll();
    foreach ($rows as &$r) {
        $r['need']  = $r['par_qty'] === null ? 0 : max(0, (int)$r['par_qty'] - max(0, (int)$r['qty']));
        $r['value'] = $r['replacement_value'] !== null ? round((float)$r['replacement_value'] * (int)$r['qty'], 2) : null;
    }
    unset($r);
    return $rows;
}

/** A property's areas (open first), with how many units each holds. */
function inv_child_areas(int $propertyLocationId): array {
    if (!inv_supported()) return [];
    return db_query("SELECT l.*, (SELECT COALESCE(SUM(b.qty), 0) FROM inv_balances b WHERE b.location_id = l.id) AS units
                       FROM inv_locations l WHERE l.parent_id = :p
                      ORDER BY l.is_active DESC, l.sort_order, l.name", [':p' => $propertyLocationId])->fetchAll();
}

/** Active team members the account may assign items to: [hr_staff id => name]. */
function inv_assignable_staff(?array $venueIds): array {
    if (!hr_staff_supported()) return [];
    $out = [];
    foreach (db_query("SELECT id, full_name, venue_id FROM hr_staff WHERE status = 'active' ORDER BY full_name")->fetchAll() as $r) {
        if (hr_staff_in_venue_scope((int)$r['id'], $r['venue_id'] !== null ? (int)$r['venue_id'] : null, $venueIds)) $out[(int)$r['id']] = (string)$r['full_name'];
    }
    return $out;
}

/** Accounts that may be made responsible for counting a location of $venueId (null = any account): [id => label]. */
function inv_assignable_users(?int $venueId): array {
    $sql = "SELECT id, COALESCE(NULLIF(name, ''), email) AS label FROM admin_users WHERE is_active = TRUE";
    $p   = [];
    if ($venueId !== null) {
        $sql .= " AND (role = 'owner' OR id IN (SELECT admin_user_id FROM admin_user_venues WHERE venue_id = :v))";
        $p[':v'] = $venueId;
    }
    $out = [];
    foreach (db_query($sql . ' ORDER BY 2', $p)->fetchAll() as $r) $out[(int)$r['id']] = (string)$r['label'];
    return $out;
}

// ── Settings writers (never quantities) ─────────────────────────────────────

/** Add an area (Kitchen, Villa 3…) under a property. It inherits the property's owning venue. */
function inv_create_area(int $parentId, string $name): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $parent = inv_fetch_location($parentId);
    if (!$parent || $parent['kind'] !== 'property') throw new InvRefusal('Areas go under a property.');
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) throw new InvRefusal('Give the area a name (up to 120 characters).');
    if (db_query('SELECT 1 FROM inv_locations WHERE parent_id = :p AND is_active = TRUE AND lower(name) = lower(:n)', [':p' => $parentId, ':n' => $name])->fetchColumn()) {
        throw new InvRefusal("{$parent['name']} already has an area called {$name}.");
    }
    $sort = (int) db_query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM inv_locations WHERE parent_id = :p', [':p' => $parentId])->fetchColumn();
    db_query("INSERT INTO inv_locations (kind, name, parent_id, venue_id, sort_order) VALUES ('area', :n, :p, :v, :s)",
        [':n' => $name, ':p' => $parentId, ':v' => $parent['venue_id'], ':s' => $sort]);
    return (int) db()->lastInsertId();
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_views_logic.php | tail -3`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory-views.php tests/inventory_views_logic.php
git commit -m "feat(inventory): read models — scoped central list, sold/lost ledger, item and location views

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Settings writers + the item action dispatcher

**Files:**
- Modify: `includes/inventory-views.php` (append)
- Modify: `tests/inventory_views_logic.php` (insert above the marker)

- [ ] **Step 1: Write the failing DB checks**

Insert above the marker (state: plates — Main 15, kitchen 10 (par 12), B 3; laptop has no units):

```php
    // ── Settings + actions ──
    check('area: a duplicate name under one property is refused', str_contains($refused(fn() => inv_create_area($locA, 'zz kitchen')), 'already has'));
    check('area: only under a property', $refused(fn() => inv_create_area($store, 'Shelf')) !== '');
    inv_update_location($kitchen, ['count_every_days' => '7', 'count_assignee_id' => $mgr, 'name' => 'ZZ Main kitchen', 'is_active' => true]);
    $k = inv_fetch_location($kitchen);
    check('location: schedule, responsible person and name saved', (int)$k['count_every_days'] === 7 && (int)$k['count_assignee_id'] === $mgr && $k['name'] === 'ZZ Main kitchen');
    check('location: a silly schedule is refused', $refused(fn() => inv_update_location($kitchen, ['count_every_days' => '400'])) !== '');
    check('location: an area holding stock cannot be closed', str_contains($refused(fn() => inv_update_location($kitchen, ['is_active' => false])), 'Move its stock'));
    inv_update_location($locA, ['name' => 'Renamed', 'count_every_days' => '']);
    check('location: a property keeps its venue name', inv_fetch_location($locA)['name'] !== 'Renamed');

    [$v] = inv_item_from_post(['name' => 'ZZ View plate', 'item_type' => 'operational', 'tracking' => 'serial', 'currency' => 'KES', 'is_active' => '1']);
    check('item: tracking cannot change once it has history', str_contains($refused(fn() => inv_update_item($plates, $v)), 'Tracking'));
    [$v] = inv_item_from_post(['name' => 'ZZ View plate (large)', 'item_type' => 'operational', 'replacement_value' => '900', 'currency' => 'KES', 'is_active' => '1']);
    inv_update_item($plates, $v);
    check('item: details saved', inv_fetch_item($plates)['name'] === 'ZZ View plate (large)' && (float)inv_fetch_item($plates)['replacement_value'] === 900.0);

    $item = inv_fetch_item($plates);
    $msg = inv_apply_item_action(['action' => 'receive', 'qty' => '4', 'to_id' => (string)$kitchen, 'note' => 'delivery'], $item, [$vA], $mgr);
    check('action: a manager receives into their own area', str_contains($msg, 'Received 4') && inv_balance($plates, $kitchen) === 14);
    check('action: a manager cannot receive into another property',
        str_contains($refused(fn() => inv_apply_item_action(['action' => 'receive', 'qty' => '1', 'to_id' => (string)$locB], $item, [$vA], $mgr)), 'location you manage'));
    $msg = inv_apply_item_action(['action' => 'transfer', 'qty' => '2', 'from_id' => (string)$kitchen, 'to' => 'staff:' . $jane], $item, [$vA], $mgr);
    $janeLoc = inv_person_location_id($jane);
    check('action: assigning to a team member', str_contains($msg, 'ZZ Jane') && inv_balance($plates, $janeLoc) === 2
        && db_query("SELECT reason FROM inv_moves WHERE item_id = :i ORDER BY id DESC LIMIT 1", [':i' => $plates])->fetchColumn() === 'assign');
    check('action: restock from shared Main stock into their own area is allowed',
        str_contains(inv_apply_item_action(['action' => 'transfer', 'qty' => '1', 'from_id' => (string)$store, 'to' => 'loc:' . $kitchen], $item, [$vA], $mgr), 'Moved'));
    check('action: moving shared stock to another property is refused',
        $refused(fn() => inv_apply_item_action(['action' => 'transfer', 'qty' => '1', 'from_id' => (string)$store, 'to' => 'loc:' . $locB], $item, [$vA], $mgr)) !== '');
    $msg = inv_apply_item_action(['action' => 'loss', 'qty' => '1', 'from_id' => (string)$kitchen, 'reason' => 'broken', 'note' => 'dropped'], $item, [$vA], $mgr);
    check('action: a loss reports its value', str_contains($msg, 'KES 900') && inv_balance($plates, $kitchen) === 12);
    $mainBefore = inv_balance($plates, $store);
    $msg = inv_apply_item_action(['action' => 'replace', 'qty' => '1', 'at_id' => (string)$kitchen, 'reason' => 'broken'], $item, null, $owner);
    check('action: replace = loss here + refill from Main stock', inv_balance($plates, $kitchen) === 12 && inv_balance($plates, $store) === $mainBefore - 1);
    check('action: an unknown action is refused', $refused(fn() => inv_apply_item_action(['action' => 'teleport'], $item, null, $owner)) !== '');

    $lap = inv_fetch_item($laptop);
    check('action: counted receive is refused for a serial item',
        $refused(fn() => inv_apply_item_action(['action' => 'receive', 'qty' => '1', 'to_id' => (string)$store], $lap, null, $owner)) !== '');
    inv_apply_item_action(['action' => 'add_unit', 'to_id' => (string)$store, 'serial' => "ZZ-V-{$sfx}", 'condition' => 'new', 'purchase_value' => '90000'], $lap, null, $owner);
    $unit = (int) db_query('SELECT id FROM inv_assets WHERE item_id = :i', [':i' => $laptop])->fetchColumn();
    inv_apply_item_action(['action' => 'transfer', 'asset_id' => (string)$unit, 'to' => 'staff:' . $jane], $lap, null, $owner);
    check('action: a serial unit is assigned by unit', (int) db_query('SELECT location_id FROM inv_assets WHERE id = :a', [':a' => $unit])->fetchColumn() === $janeLoc);
    $row = array_values(array_filter(inv_central_list(['q' => 'ZZ View', 'status' => 'assigned'], null, 50, 0)['rows'], fn($r) => (int)$r['id'] === $laptop))[0] ?? null;
    check('list: "assigned" shows what people hold', $row !== null && (int)$row['qty'] === 1);
    check('list: the person filter', count(inv_central_list(['q' => 'ZZ View', 'person' => $jane], null, 50, 0)['rows']) === 2);

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_views_logic.php`
Expected: `FAIL  DB block threw: Call to undefined function inv_update_location()`.

- [ ] **Step 3: Append to `includes/inventory-views.php`**

```php

/**
 * Save a location's settings. $v: count_every_days ('' = manual, 1–365),
 * count_assignee_id (0 = nobody), name (areas and Main stock only — properties,
 * outlets and people take their name from their own record), is_active (areas
 * only; refused while it holds stock). The CALLER checks inv_location_editable().
 */
function inv_update_location(int $id, array $v): void {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $loc = inv_fetch_location($id);
    if (!$loc) throw new InvRefusal('That location no longer exists.');
    if ($loc['kind'] === 'person') throw new InvRefusal('A team member’s items are managed from their profile.');
    $every = trim((string)($v['count_every_days'] ?? ''));
    if ($every !== '' && (!ctype_digit($every) || (int)$every < 1 || (int)$every > 365)) throw new InvRefusal('Count every 1–365 days, or choose manual only.');
    $assignee = (int)($v['count_assignee_id'] ?? 0);
    if ($assignee > 0 && !db_query('SELECT 1 FROM admin_users WHERE id = :u AND is_active = TRUE', [':u' => $assignee])->fetchColumn()) {
        throw new InvRefusal('Pick an active team account.');
    }
    $set = ['count_every_days = :e', 'count_assignee_id = :a'];
    $p   = [':e' => $every === '' ? null : (int)$every, ':a' => $assignee > 0 ? $assignee : null, ':id' => $id];
    if (in_array($loc['kind'], ['area', 'store'], true) && array_key_exists('name', $v)) {
        $name = trim((string)$v['name']);
        if ($name === '' || mb_strlen($name) > 120) throw new InvRefusal('Give it a name (up to 120 characters).');
        $set[] = 'name = :n'; $p[':n'] = $name;
    }
    if ($loc['kind'] === 'area' && array_key_exists('is_active', $v)) {
        $active = (bool)$v['is_active'];
        if (!$active && inv_bool($loc['is_active'])
            && db_query('SELECT 1 FROM inv_balances WHERE location_id = :l AND qty <> 0 LIMIT 1', [':l' => $id])->fetchColumn()) {
            throw new InvRefusal('Move its stock out before closing this area.');
        }
        $set[] = 'is_active = :act'; $p[':act'] = $active ? 'TRUE' : 'FALSE';
    }
    db_query('UPDATE inv_locations SET ' . implode(', ', $set) . ' WHERE id = :id', $p);
}

/**
 * Save an item's details ($v from inv_item_from_post(), plus optional image_key —
 * null removes the photo). Tracking can't change once the item has stock history.
 * A POS-linked item keeps the name, SKU, photo, currency, alert and tracking its
 * POS listing owns ($posLinked = true skips them).
 */
function inv_update_item(int $id, array $v, bool $posLinked = false): void {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $item = inv_fetch_item($id);
    if (!$item) throw new InvRefusal('That item no longer exists.');
    if (!$posLinked && $v['tracking'] !== $item['tracking'] && inv_item_has_moves($id)) {
        throw new InvRefusal('Tracking can’t change once the item has stock history.');
    }
    $set = ['item_type = :t', 'category = :c', 'icon = :ic', 'unit_label = :u', 'replacement_value = :rv', 'is_active = :a', 'updated_at = now()'];
    $p   = [':t' => $v['item_type'], ':c' => $v['category'] !== '' ? $v['category'] : null, ':ic' => $v['icon'] !== '' ? $v['icon'] : null,
            ':u' => $v['unit_label'] !== '' ? $v['unit_label'] : 'pcs', ':rv' => $v['replacement_value'],
            ':a' => $v['is_active'] ? 'TRUE' : 'FALSE', ':id' => $id];
    if (!$posLinked) {
        array_push($set, 'name = :n', 'sku = :s', 'tracking = :tr', 'currency = :cur', 'low_stock_at = :low');
        $p += [':n' => $v['name'], ':s' => $v['sku'] !== '' ? $v['sku'] : null, ':tr' => $v['tracking'], ':cur' => $v['currency'], ':low' => $v['low_stock_at']];
        if (array_key_exists('image_key', $v)) { $set[] = 'image_key = :img'; $p[':img'] = $v['image_key']; }
    }
    db_query('UPDATE inv_items SET ' . implode(', ', $set) . ' WHERE id = :id', $p);
}

/**
 * The item page's everyday actions, all through the inventory core. $in is the
 * posted form: action = receive | add_unit | transfer | loss | replace, plus
 * qty / asset_id, to_id / from_id / at_id / to ("loc:ID" | "staff:ID"), reason,
 * unit_cost, note and the unit fields. Every location and team member is
 * re-checked against the account's scope; inv_move() re-checks stock under lock.
 * Returns the success message; throws InvRefusal.
 */
function inv_apply_item_action(array $in, array $item, ?array $venueIds, int $userId): string {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $itemId = (int)$item['id'];
    $name   = (string)$item['name'];
    $serial = $item['tracking'] === 'serial';
    $qty    = $serial ? 1 : (ctype_digit((string)($in['qty'] ?? '')) ? (int)$in['qty'] : 0);
    $asset  = $serial ? ((int)($in['asset_id'] ?? 0) ?: null) : null;
    $note   = mb_substr(trim((string)($in['note'] ?? '')), 0, 500);
    $loc = function (string $key) use ($in, $venueIds): ?array {
        $id = (int)($in[$key] ?? 0);
        if ($id <= 0) return null;
        $l = inv_fetch_location($id);
        if (!$l || !inv_location_visible($l, $venueIds)) throw new InvRefusal('Pick a location you manage.');
        return $l;
    };
    $scope = function (?array $from, ?array $to) use ($venueIds): void {
        if (!inv_move_in_scope($from, $to, $venueIds)) throw new InvRefusal('That involves a location outside your properties.');
    };
    $units = fn(int $n): string => $serial ? $name : "{$n} × {$name}";
    // A serial unit moves from wherever it is — the server looks that up, it is never taken from the form.
    $unitFrom = function () use ($asset, $itemId, $venueIds): ?array {
        if (!$asset) return null;
        $locId = db_query("SELECT location_id FROM inv_assets WHERE id = :a AND item_id = :i AND status = 'active'",
            [':a' => $asset, ':i' => $itemId])->fetchColumn();
        if (!$locId) throw new InvRefusal('That unit is not in stock.');
        $l = inv_fetch_location((int)$locId);
        if (!$l || !inv_location_visible($l, $venueIds)) throw new InvRefusal('Pick a unit you manage.');
        return $l;
    };

    switch ((string)($in['action'] ?? '')) {
        case 'receive':
            if ($serial) throw new InvRefusal("{$name} is tracked by serial number — add each unit instead.");
            $to = $loc('to_id');
            if (!$to) throw new InvRefusal('Pick where it arrived.');
            $scope(null, $to);
            $cost = trim((string)($in['unit_cost'] ?? ''));
            if ($cost !== '' && (!is_numeric($cost) || (float)$cost < 0)) throw new InvRefusal('Unit cost must be zero or more.');
            inv_move(['item_id' => $itemId, 'qty' => $qty, 'to' => (int)$to['id'], 'reason' => 'receive',
                      'user_id' => $userId, 'note' => $note, 'unit_value' => $cost === '' ? null : $cost]);
            return 'Received ' . $units($qty) . " at {$to['name']}.";

        case 'add_unit':
            if (!$serial) throw new InvRefusal("{$name} is counted, not tracked by serial number — receive a quantity instead.");
            $to = $loc('to_id');
            if (!$to) throw new InvRefusal('Pick where the unit is.');
            $scope(null, $to);
            $sn = trim((string)($in['serial'] ?? ''));
            inv_asset_create($itemId, (int)$to['id'], [
                'serial' => $sn, 'tag' => $in['tag'] ?? '', 'condition' => $in['condition'] ?? 'good',
                'purchase_date' => $in['purchase_date'] ?? '', 'purchase_value' => $in['purchase_value'] ?? null, 'notes' => $note,
            ], $userId);
            return "Added {$name}" . ($sn !== '' ? " ({$sn})" : '') . " at {$to['name']}.";

        case 'transfer':
            $from   = $serial ? $unitFrom() : $loc('from_id');
            $target = inv_parse_target((string)($in['to'] ?? ''));
            if (!$from || !$target) throw new InvRefusal('Pick where it comes from and where it goes.');
            if ($target[0] === 'staff') {
                $staff = inv_assignable_staff($venueIds);
                if (!isset($staff[$target[1]])) throw new InvRefusal('Pick a team member you manage.');
                $to = inv_fetch_location(inv_person_location_id($target[1]));
            } else {
                $to = inv_fetch_location($target[1]);
                if (!$to || !inv_location_visible($to, $venueIds)) throw new InvRefusal('Pick a location you manage.');
                if (!inv_bool($to['is_active'])) throw new InvRefusal("{$to['name']} is closed.");
            }
            $scope($from, $to);
            inv_transfer($itemId, $qty, (int)$from['id'], (int)$to['id'], $userId, $note, $asset);
            return 'Moved ' . $units($qty) . " from {$from['name']} to {$to['name']}.";

        case 'loss':
            $from   = $serial ? $unitFrom() : $loc('from_id');
            $reason = (string)($in['reason'] ?? '');
            if (!$from) throw new InvRefusal('Pick where it was lost.');
            $scope($from, null);
            inv_report_loss($itemId, $qty, (int)$from['id'], $reason, $userId, $note, $asset);
            $value = db_query('SELECT value, currency FROM inv_moves WHERE item_id = :i ORDER BY id DESC LIMIT 1', [':i' => $itemId])->fetch();
            $label = mb_strtolower(INV_LOSS_LABELS[$reason] ?? $reason);
            return 'Recorded ' . $units($qty) . " {$label} at {$from['name']}"
                . ($value && $value['value'] !== null ? ' (' . inv_money((float)$value['value'], (string)$value['currency']) . ')' : '') . '.';

        case 'replace':
            $at     = $loc('at_id');
            $reason = (string)($in['reason'] ?? '');
            if (!$at) throw new InvRefusal('Pick where it is being replaced.');
            $source = inv_fetch_location(inv_store_location_id());
            $scope($at, null);
            $scope($source, $at);
            inv_replace($itemId, $qty, (int)$at['id'], $reason, $userId, $note);
            return 'Replaced ' . $units($qty) . " at {$at['name']} from {$source['name']}.";
    }
    throw new InvRefusal('Unknown action.');
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_views_logic.php | tail -3 && php tests/inventory_logic.php | tail -1 && php tests/pos_logic.php | tail -1`
Expected: `ALL PASS` three times.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory-views.php tests/inventory_views_logic.php
git commit -m "feat(inventory): settings writers (areas, schedules, item details) and the item action dispatcher

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Shared page chrome + the Inventory sidebar group + the central list page

**Files:**
- Modify: `includes/inventory-views.php` (append)
- Modify: `admin/_layout.php`
- Create: `admin/inventory.php`

- [ ] **Step 1: Append the shared view helpers to `includes/inventory-views.php`**

```php

// ── Shared page chrome ──────────────────────────────────────────────────────

/** Photo, else the item's emoji, else its initial — a square thumbnail. */
function inv_thumb_html(array $item, int $size = 36): string {
    $s = max(16, $size);
    if (!empty($item['image_key'])) {
        return '<img class="inv-thumb" src="' . e(storage_url((string)$item['image_key'])) . '" alt="" width="' . $s . '" height="' . $s . '" loading="lazy">';
    }
    $glyph = trim((string)($item['icon'] ?? ''));
    if ($glyph === '') $glyph = mb_strtoupper(mb_substr((string)($item['name'] ?? '?'), 0, 1));
    return '<span class="inv-thumb inv-thumb--glyph" style="width:' . $s . 'px;height:' . $s . 'px;font-size:' . (int)round($s * 0.5) . 'px">' . e($glyph) . '</span>';
}

/** CSS shared by every inventory page — echo once per page. */
function inv_shared_css(): string {
    return '<style>
.inv-thumb{width:36px;height:36px;border-radius:8px;object-fit:cover;flex:0 0 auto;background:var(--bg)}
.inv-thumb--glyph{display:inline-flex;align-items:center;justify-content:center;color:var(--muted);font-weight:600;border:1px solid var(--border)}
.inv-name{display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none}
.inv-name:hover strong,.inv-name:hover span{text-decoration:underline}
.inv-sub{display:block;font-size:12px;color:var(--muted);font-weight:400}
.inv-num{text-align:right;white-space:nowrap}
.inv-nowrap{white-space:nowrap}
.inv-note{font-size:12px;margin-top:2px}
.inv-where{font-size:13px;color:var(--text)}
.inv-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,1fr);gap:18px;align-items:start}
@media (max-width:980px){.inv-grid{grid-template-columns:minmax(0,1fr)}}
.inv-stack{display:grid;gap:18px;min-width:0}
.inv-form .field{margin-bottom:12px}
.inv-form .inp,.inv-form .eselect--block{width:100%}
.inv-row2{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
@media (max-width:560px){.inv-row2{grid-template-columns:1fr}}
.inv-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
[data-inv-panel][hidden]{display:none}
.inv-err{color:var(--red);font-size:12px;margin-top:4px}
.inv-kpis{display:flex;flex-wrap:wrap;gap:10px 24px;padding:14px 18px;border-bottom:1px solid var(--border)}
.inv-kpi span{display:block;font-size:12px;color:var(--muted)}
.inv-kpi strong{font-size:18px}
.table-wrap .data-table{min-width:0}
</style>';
}
```

- [ ] **Step 2: Add the sidebar group in `admin/_layout.php`**

a) In the require block at the top, after `require_once __DIR__ . '/../includes/pos-support.php';       // pos_supported() — gates the Point of Sale nav group` add:

```php
require_once __DIR__ . '/../includes/inventory-support.php'; // inv_supported() — gates the Inventory nav group
```

b) After the line `$__navPosTill   = $admin && pos_is_seller($admin);                    // "Open till" + own PIN — anyone who can sell` add:

```php
$__navInventory = ($__isOwner || $__isManager) && inv_supported();   // Inventory & Assets (managers scoped to their properties)
```

c) Directly after the POS group's closing lines

```php
      <?php $__navgroup('pos', 'Point of Sale', ob_get_clean()); ?>
      <?php endif; ?>
```

insert:

```php

      <?php if ($__navInventory): ?>
      <?php ob_start(); ?>
        <a href="/admin/inventory.php" class="sidebar__link <?= in_array($activeMenu ?? '', ['inventory', 'inventory_item'], true) ? 'is-active' : '' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
          Inventory
        </a>
        <a href="/admin/inventory-locations.php" class="sidebar__link <?= in_array($activeMenu ?? '', ['inventory_locations', 'inventory_location'], true) ? 'is-active' : '' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          Locations
        </a>
      <?php $__navgroup('inventory', 'Inventory', ob_get_clean()); ?>
      <?php endif; ?>
```

- [ ] **Step 3: Create `admin/inventory.php`**

```php
<?php
/**
 * Admin: Inventory — one list of everything the company owns or sells, across
 * Main stock, properties (and their areas), POS outlets and team members.
 * Owner + manager; a manager sees their properties plus shared locations (Main
 * stock, shared outlets). Read-only: quantities change on the item page, via
 * inv_move(). "Sold" and "Lost / written off" switch to the movement ledger.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/inventory-views.php';
require_login();
require_manager();

$vids      = admin_venue_ids();
$supported = inv_supported();
$pg        = paginate_params();
$f = [
    'q'        => $pg['q'],
    'type'     => (string)($_GET['type'] ?? ''),
    'venue'    => (int)($_GET['venue'] ?? 0),
    'location' => (int)($_GET['location'] ?? 0),
    'person'   => (int)($_GET['person'] ?? 0),
    'status'   => (string)($_GET['status'] ?? ''),
    'from'     => (string)($_GET['from'] ?? ''),
    'to'       => (string)($_GET['to'] ?? ''),
];
if (!isset(INV_STATUS_FILTERS[$f['status']])) $f['status'] = '';
if (!isset(INV_TYPES[$f['type']])) $f['type'] = '';

// Filters are requests: anything outside the account's scope is dropped, never honoured.
$locations = $supported ? inv_locations_visible($vids, false) : [];
$byId = []; $people = [];
foreach ($locations as $l) {
    $byId[(int)$l['id']] = $l;
    if ($l['kind'] === 'person' && $l['hr_staff_id'] !== null) $people[(int)$l['hr_staff_id']] = (string)$l['name'];
}
if ($f['location'] && !isset($byId[$f['location']])) $f['location'] = 0;
if ($f['person'] && !isset($people[$f['person']])) $f['person'] = 0;
$venues = $supported ? inv_visible_venues($vids) : [];
if ($f['venue'] && !isset($venues[$f['venue']])) $f['venue'] = 0;

$gone = in_array($f['status'], ['sold', 'written_off'], true);
$run  = fn(int $offset): array => $gone ? inv_gone_moves($f, $vids, $pg['per'], $offset) : inv_central_list($f, $vids, $pg['per'], $offset);
$res  = $supported ? $run((int)$pg['offset']) : ['total' => 0, 'rows' => []];
$meta = paginate_meta((int)$res['total'], $pg['page'], $pg['per']);
if ($supported && $meta['offset'] !== (int)$pg['offset']) $res = $run($meta['offset']);   // page past the end → last page
$filtered = $f['q'] !== '' || $f['type'] || $f['venue'] || $f['location'] || $f['person'] || $f['status'];

ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
  <?php if (!$res['rows']): ?>
    <?php dt_empty($gone ? 'Nothing recorded for these filters in this period.' : ($filtered ? 'No items match these filters.' : 'No items yet — add the first one.')); ?>
  <?php elseif ($gone): ?>
    <?php if (!empty($res['totals'])): ?>
    <div class="inv-kpis">
      <div class="inv-kpi"><span><?= e(INV_STATUS_FILTERS[$f['status']]) ?> · <?= e(date('j M', strtotime($res['from']))) ?> – <?= e(date('j M Y', strtotime($res['to']))) ?></span>
        <?php foreach ($res['totals'] as $cur => $amt): ?><strong><?= e(inv_money((float)$amt, (string)$cur)) ?></strong> <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>When</th><th>Item</th><th>From</th><th>What</th><th class="inv-num">Qty</th><th class="inv-num">Value</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($res['rows'] as $m): [$lbl, $cls] = INV_REASON_LABELS[$m['reason']] ?? [$m['reason'], 'badge--grey']; ?>
        <tr>
          <td class="text-muted inv-nowrap"><?= e(date('j M Y, H:i', strtotime((string)$m['created_at']))) ?></td>
          <td><a href="/admin/inventory-item.php?id=<?= (int)$m['item_id'] ?>" class="inv-name"><?= inv_thumb_html(['image_key' => $m['image_key'], 'icon' => $m['icon'], 'name' => $m['item_name']], 28) ?><span><?= e($m['item_name']) ?></span></a></td>
          <td><?= e($m['location_name']) ?></td>
          <td><span class="badge <?= e($cls) ?>"><?= e($lbl) ?></span><?php if (!empty($m['sale_reference'])): ?> <span class="text-muted"><?= e($m['sale_reference']) ?></span><?php endif; ?>
            <?php if (!empty($m['note'])): ?><div class="text-muted inv-note"><?= e($m['note']) ?></div><?php endif; ?></td>
          <td class="inv-num"><?= (int)$m['qty'] ?></td>
          <td class="inv-num"><?= e(inv_money($m['value'] !== null ? (float)$m['value'] : null, (string)$m['currency'])) ?></td>
          <td class="text-muted"><?= e($m['user_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th>Where</th><th class="inv-num">Qty</th><th class="inv-num">Each</th><th class="inv-num">Total value</th></tr></thead>
      <tbody>
      <?php foreach ($res['rows'] as $r): $q = (int)$r['qty']; $low = $r['low_stock_at'] !== null && $q <= (int)$r['low_stock_at']; ?>
        <tr>
          <td><a href="/admin/inventory-item.php?id=<?= (int)$r['id'] ?>" class="inv-name"><?= inv_thumb_html($r, 36) ?>
            <span><strong><?= e($r['name']) ?></strong>
              <span class="inv-sub"><?= e(INV_TYPES[$r['item_type']] ?? (string)$r['item_type']) ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?><?= $r['tracking'] === 'serial' ? ' · by serial' : '' ?></span></span></a></td>
          <td class="inv-where"><?= $r['breakdown'] ? e(inv_breakdown_label($r['breakdown'])) : '<span class="text-muted">None in stock</span>' ?></td>
          <td class="inv-num"><strong><?= $q ?></strong><?php if ($low): ?> <span class="badge badge--orange">Low</span><?php endif; ?></td>
          <td class="inv-num text-muted"><?= e(inv_money($r['replacement_value'] !== null ? (float)$r['replacement_value'] : null, (string)$r['currency'])) ?></td>
          <td class="inv-num"><?= e(inv_money($r['value'], (string)$r['currency'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }

$pageTitle  = 'Inventory';
$activeMenu = 'inventory';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Inventory</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/inventory-locations.php" class="btn-outline btn-sm">Locations</a>
    <?php if ($supported): ?><a href="/admin/inventory-item.php?new=1" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add item</a><?php endif; ?>
  </div>
</div>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>
<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/inventory.php" class="filters">
      <input type="hidden" name="q" value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field"><span>Type</span>
        <select name="type" class="filter-select" aria-label="Filter by type" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach (INV_TYPES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <?php if (count($venues) > 1): ?>
      <div class="filter-field"><span>Property</span>
        <select name="venue" class="filter-select" aria-label="Filter by property" onchange="this.form.submit()">
          <option value="0">All properties</option>
          <?php foreach ($venues as $vid => $vname): ?><option value="<?= (int)$vid ?>" <?= $f['venue'] === $vid ? 'selected' : '' ?>><?= e($vname) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="filter-field"><span>Location</span>
        <select name="location" class="filter-select" aria-label="Filter by location" onchange="this.form.submit()">
          <option value="0">All locations</option>
          <?php foreach ($locations as $l): if ($l['kind'] === 'person') continue; ?>
          <option value="<?= (int)$l['id'] ?>" <?= $f['location'] === (int)$l['id'] ? 'selected' : '' ?>><?= e(inv_location_label($l)) ?><?= inv_bool($l['is_active']) ? '' : ' (closed)' ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php if ($people): ?>
      <div class="filter-field"><span>Team member</span>
        <select name="person" class="filter-select" aria-label="Filter by team member" onchange="this.form.submit()">
          <option value="0">Anyone</option>
          <?php foreach ($people as $sid => $pname): ?><option value="<?= (int)$sid ?>" <?= $f['person'] === $sid ? 'selected' : '' ?>><?= e($pname) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="filter-field"><span>Status</span>
        <select name="status" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
          <?php foreach (INV_STATUS_FILTERS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <?php if ($gone): $fromVal = inv_ymd_or($f['from'], date('Y-m-d', strtotime('-30 days'))); $toVal = inv_ymd_or($f['to'], date('Y-m-d')); ?>
      <div class="filter-field"><span>From</span>
        <button type="button" class="dp-btn" data-dp-target="invFrom" data-dp-past data-dp-placeholder="From" style="width:140px"><?= e(date('j M Y', strtotime($fromVal))) ?></button>
        <input type="hidden" id="invFrom" name="from" value="<?= e($fromVal) ?>"></div>
      <div class="filter-field"><span>To</span>
        <button type="button" class="dp-btn" data-dp-target="invTo" data-dp-past data-dp-placeholder="To" style="width:140px"><?= e(date('j M Y', strtotime($toVal))) ?></button>
        <input type="hidden" id="invTo" name="to" value="<?= e($toVal) ?>"></div>
      <button type="submit" class="btn-outline btn-sm" style="align-self:flex-end">Apply</button>
      <?php endif; ?>
      <?php if ($f['type'] || $f['venue'] || $f['location'] || $f['person'] || $f['status']): ?>
      <a href="/admin/inventory.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, SKU or category…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>
<?php endif; ?>
<?= inv_shared_css() ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 4: Lint + tests**

Run: `php -l admin/inventory.php && php -l admin/_layout.php && php -l includes/inventory-views.php && php tests/inventory_views_logic.php | tail -1`
Expected: no syntax errors ×3, `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory-views.php admin/_layout.php admin/inventory.php
git commit -m "feat(inventory): Inventory sidebar group and the central list page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The item page

**Files:**
- Create: `admin/inventory-item.php`

- [ ] **Step 1: Create the page**

```php
<?php
/**
 * Admin: one inventory item — details, where it is, its serial units, its
 * history, and the everyday actions: receive (or add a unit), move / assign /
 * return, report a loss, replace. Owner + manager. Every action goes through
 * inv_apply_item_action(), which re-checks scope and uses the inventory core.
 * ?new=1 creates an item. A POS-linked item's name, SKU, photo, currency, alert
 * and tracking are owned by its POS listing and edited there.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/pos.php';               // pos_upload_item_image() (shared photo pipeline)
require_login();
require_manager();

$self      = '/admin/inventory-item.php';
$vids      = admin_venue_ids();
$me        = current_admin();
$supported = inv_supported();
$id        = (int)($_GET['id'] ?? $_POST['item_id'] ?? 0);
$isNew     = !$id && isset($_GET['new']);
$item      = $id ? inv_fetch_item($id) : false;

function inv_item_go(string $url): never { header('Location: ' . $url); exit; }

$flash  = $_SESSION['inv_flash'] ?? null;  unset($_SESSION['inv_flash']);
$errors = $_SESSION['inv_errors'] ?? [];   unset($_SESSION['inv_errors']);
$old    = $_SESSION['inv_old'] ?? null;    unset($_SESSION['inv_old']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        $listings  = $item ? inv_item_pos_listings((int)$item['id']) : [];
        $posLinked = (bool)$listings;
        // A POS-linked item's POS-owned fields are not on the form — keep the stored ones.
        $in = $posLinked ? array_merge($_POST, ['name' => $item['name'], 'sku' => (string)$item['sku'], 'currency' => $item['currency'],
                                                'low_stock_at' => (string)$item['low_stock_at'], 'tracking' => $item['tracking']]) : $_POST;
        [$v, $e] = inv_item_from_post($in);
        $img = null;
        if (!$e && !$posLinked) {
            try { $up = pos_upload_item_image($_FILES['image'] ?? []); if ($up !== '') $img = $up; }
            catch (PosRefusal $ex) { $e['image'] = $ex->getMessage(); }
        }
        if ($e) {
            $_SESSION['inv_errors'] = $e; $_SESSION['inv_old'] = $_POST;
            inv_item_go($item ? "{$self}?id={$id}#details" : "{$self}?new=1");
        }
        try {
            if ($item) {
                if ($img !== null) $v['image_key'] = $img;
                elseif (!empty($_POST['remove_image']) && !$posLinked) $v['image_key'] = null;
                inv_update_item($id, $v, $posLinked, is_owner());   // managers can't change an existing item's value, currency or switch it off
                audit_log('inv.item_save', 'inv_item', $id, $v['name']);
                $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "{$item['name']} saved."];
                inv_item_go("{$self}?id={$id}");
            }
            $newId = inv_create_item($v + ['image_key' => $img]);
            audit_log('inv.item_add', 'inv_item', $newId, $v['name']);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "{$v['name']} added. Receive its stock below."];
            inv_item_go("{$self}?id={$newId}#actions");
        } catch (InvRefusal $ex) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $ex->getMessage()]; $_SESSION['inv_old'] = $_POST;
            inv_item_go($item ? "{$self}?id={$id}#details" : "{$self}?new=1");
        }
    }
    if (!$item) { $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'That item no longer exists.']; inv_item_go('/admin/inventory.php'); }
    try {
        $msg = inv_apply_item_action($_POST, $item, $vids, (int)$me['id']);
        audit_log('inv.' . preg_replace('/[^a-z_]/', '', $act), 'inv_item', $id, $msg);
        $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $msg];
    } catch (InvRefusal $ex) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $ex->getMessage()];
        $_SESSION['inv_old'] = $_POST;
    }
    inv_item_go("{$self}?id={$id}#actions");
}

if (!$isNew && !$item) {
    http_response_code(404);
    $pageTitle = 'Inventory'; $activeMenu = 'inventory';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Item not found. <a href="/admin/inventory.php">Back to Inventory</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

$locs      = $supported ? array_values(array_filter(inv_locations_visible($vids), fn($l) => $l['kind'] !== 'person')) : [];
$where     = $item ? inv_item_locations($id, $vids) : [];
$serial    = $item && $item['tracking'] === 'serial';
$units     = $serial ? inv_item_units($id, $vids) : [];
$active    = array_values(array_filter($units, fn($u) => $u['status'] === 'active'));
$history   = $item ? inv_item_history($id, $vids, 100) : [];
$staff     = $supported ? inv_assignable_staff($vids) : [];
$listings  = $item ? inv_item_pos_listings($id) : [];
$posLinked = (bool)$listings;
$hasMoves  = $item ? inv_item_has_moves($id) : false;
$ownerOnly = $item && !is_owner();   // an existing item's value, currency and on/off switch are owner business
$holding   = array_values(array_filter($where, fn($w) => (int)$w['qty'] > 0));
$totalQty  = array_sum(array_map(fn($w) => (int)$w['qty'], $where));
$form      = $old ?? ($item ?: ['item_type' => 'operational', 'tracking' => 'qty', 'currency' => INV_DEFAULT_CURRENCY, 'is_active' => true, 'unit_label' => 'pcs']);
$val       = fn(string $k): string => (string)($form[$k] ?? '');
$err       = fn(string $k): string => isset($errors[$k]) ? '<div class="inv-err">' . e($errors[$k]) . '</div>' : '';
$cur       = $item ? (string)$item['currency'] : INV_DEFAULT_CURRENCY;
$oldAct    = (string)($old['action'] ?? '');
$panel     = in_array($oldAct, ['receive', 'add_unit', 'transfer', 'loss', 'replace'], true) ? $oldAct : ($serial ? 'add_unit' : 'receive');
$locOpts   = function (int $selected = 0) use ($locs): string {
    $h = '';
    foreach ($locs as $l) $h .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $selected ? ' selected' : '') . '>' . e(inv_location_label($l)) . '</option>';
    return $h;
};
$holdOpts  = function () use ($holding): string {
    $h = '';
    foreach ($holding as $w) $h .= '<option value="' . (int)$w['id'] . '">' . e(inv_location_label($w)) . ' — ' . (int)$w['qty'] . '</option>';
    return $h;
};
$unitOpts  = function () use ($active): string {
    $h = '';
    foreach ($active as $u) $h .= '<option value="' . (int)$u['id'] . '">'
        . e(($u['serial'] ?: 'Unit #' . $u['id']) . ' — ' . inv_location_label(['kind' => $u['kind'], 'name' => $u['location_name'], 'parent_name' => $u['parent_name']])) . '</option>';
    return $h;
};

$pageTitle  = $item ? (string)$item['name'] : 'New item';
$activeMenu = 'inventory_item';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $item ? e($item['name']) : 'New item' ?></h1>
  <a href="/admin/inventory.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inventory</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>

<div class="inv-grid">
  <div class="inv-stack">
    <?php if ($item): ?>
    <div class="card">
      <div class="inv-kpis">
        <div class="inv-name"><?= inv_thumb_html($item, 44) ?><span><strong><?= e(INV_TYPES[$item['item_type']] ?? $item['item_type']) ?></strong>
          <span class="inv-sub"><?= $item['category'] ? e($item['category']) . ' · ' : '' ?><?= $serial ? 'Tracked by serial number' : 'Counted' ?><?= $item['sku'] ? ' · SKU ' . e($item['sku']) : '' ?></span></span></div>
        <div class="inv-kpi"><span>Total you can see</span><strong><?= (int)$totalQty ?> <?= e((string)$item['unit_label']) ?></strong></div>
        <div class="inv-kpi"><span>Each</span><strong><?= e(inv_money($item['replacement_value'] !== null ? (float)$item['replacement_value'] : null, $cur)) ?></strong></div>
        <div class="inv-kpi"><span>Total value</span><strong><?= e(inv_money($item['replacement_value'] !== null ? round((float)$item['replacement_value'] * $totalQty, 2) : null, $cur)) ?></strong></div>
      </div>
      <?php if ($listings): ?>
      <div style="padding:10px 18px;font-size:13px" class="text-muted">Sold at the till: <?php foreach ($listings as $i => $pl): ?><?= $i ? ', ' : '' ?><a href="/admin/pos-items.php?outlet=<?= (int)$pl['outlet_id'] ?>&edit=<?= (int)$pl['id'] ?>#form"><?= e($pl['outlet_name']) ?></a><?php endforeach; ?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head"><span class="card__title">Where it is</span></div>
      <?php if (!$where): ?>
        <?php dt_empty('None anywhere you can see yet. Receive stock to get started.'); ?>
      <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Location</th><th class="inv-num">Qty</th><th class="inv-num">Should have</th></tr></thead>
        <tbody>
        <?php foreach ($where as $w): $q = (int)$w['qty']; ?>
          <tr>
            <td><?php if ($w['kind'] !== 'person'): ?><a href="/admin/inventory-location.php?id=<?= (int)$w['id'] ?>"><?= e(inv_location_label($w)) ?></a><?php else: ?><?= e(inv_location_label($w)) ?><?php endif; ?></td>
            <td class="inv-num"><strong class="<?= $q < 0 ? 'text-danger' : '' ?>"><?= $q ?></strong></td>
            <td class="inv-num text-muted"><?= $w['par_qty'] === null ? '—' : (int)$w['par_qty'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <?php if ($serial): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Units</span><span class="text-muted" style="font-size:12.5px"><?= count($active) ?> in use</span></div>
      <?php if (!$units): ?>
        <?php dt_empty('No units yet. Add each one with its serial number.'); ?>
      <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Serial / tag</th><th>Where</th><th>Condition</th><th class="inv-num">Bought for</th></tr></thead>
        <tbody>
        <?php foreach ($units as $u): ?>
          <tr>
            <td><strong><?= e($u['serial'] ?: 'Unit #' . $u['id']) ?></strong><?= $u['tag'] ? ' <span class="text-muted">· ' . e($u['tag']) . '</span>' : '' ?></td>
            <td><?php if ($u['status'] === 'active'): ?><?= e(inv_location_label(['kind' => $u['kind'], 'name' => $u['location_name'], 'parent_name' => $u['parent_name']])) ?>
                <?php else: ?><span class="badge <?= $u['status'] === 'sold' ? 'badge--blue' : 'badge--red' ?>"><?= $u['status'] === 'sold' ? 'Sold' : 'Written off' ?></span><?php endif; ?></td>
            <td><?= e(INV_CONDITION_LABELS[$u['condition']] ?? $u['condition']) ?></td>
            <td class="inv-num text-muted"><?= e(inv_money($u['purchase_value'] !== null ? (float)$u['purchase_value'] : null, $cur)) ?><?= $u['purchase_date'] ? '<div class="inv-sub">' . e(date('j M Y', strtotime((string)$u['purchase_date']))) . '</div>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card" id="history">
      <div class="card__head"><span class="card__title">History</span></div>
      <?php if (!$history): ?>
        <?php dt_empty('No movements yet.'); ?>
      <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>When</th><th>What</th><th class="inv-num">Qty</th><th>From → to</th><th class="inv-num">Value</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($history as $m): [$lbl, $cls] = INV_REASON_LABELS[$m['reason']] ?? [$m['reason'], 'badge--grey']; ?>
          <tr>
            <td class="text-muted inv-nowrap"><?= e(date('j M Y, H:i', strtotime((string)$m['created_at']))) ?></td>
            <td><span class="badge <?= e($cls) ?>"><?= e($lbl) ?></span><?= $m['serial'] ? ' <span class="text-muted">' . e($m['serial']) . '</span>' : '' ?>
              <?php if (!empty($m['note']) || !empty($m['sale_reference'])): ?><div class="text-muted inv-note"><?= e((string)($m['note'] ?: $m['sale_reference'])) ?></div><?php endif; ?></td>
            <td class="inv-num"><?= (int)$m['qty'] ?></td>
            <td><?= e($m['from_name'] ?? '—') ?> → <?= e($m['to_name'] ?? ($m['reason'] === 'sale' ? 'sold' : '—')) ?></td>
            <td class="inv-num text-muted"><?= e(inv_money($m['value'] !== null ? (float)$m['value'] : null, (string)$m['currency'])) ?></td>
            <td class="text-muted"><?= e($m['user_name'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="inv-stack">
    <?php if ($item): ?>
    <div class="card" id="actions">
      <div class="card__head"><span class="card__title">Move stock</span></div>
      <div class="card__body" style="padding:16px 18px">
        <div class="inv-chips" role="tablist">
          <?php $tabs = $serial ? ['add_unit' => 'Add a unit', 'transfer' => 'Move / assign', 'loss' => 'Report a loss']
                                : ['receive' => 'Receive', 'transfer' => 'Move / assign', 'loss' => 'Report a loss', 'replace' => 'Replace'];
          foreach ($tabs as $k => $lbl): ?>
          <label class="optchip"><input type="radio" name="inv_panel" value="<?= e($k) ?>" <?= $panel === $k ? 'checked' : '' ?>><?= e($lbl) ?></label>
          <?php endforeach; ?>
        </div>

        <?php if (!$serial): ?>
        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="receive" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="receive">
          <div class="inv-row2">
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
            <div class="field"><label>Unit cost <span class="text-muted">(optional)</span></label><input name="unit_cost" type="number" class="inp inp--num no-spin" min="0" step="0.01" placeholder="<?= e($cur) ?>"></div>
          </div>
          <div class="field"><label>Arrived at</label><select name="to_id" class="eselect eselect--block" required><?= $locOpts(0) ?></select></div>
          <div class="field"><label>Note <span class="text-muted">(supplier, invoice #)</span></label><input name="note" class="inp" maxlength="500"></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Receive</button>
        </form>
        <?php else: ?>
        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="add_unit" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="add_unit">
          <div class="inv-row2">
            <div class="field"><label>Serial number</label><input name="serial" class="inp" maxlength="80"></div>
            <div class="field"><label>Tag <span class="text-muted">(optional)</span></label><input name="tag" class="inp" maxlength="40"></div>
          </div>
          <div class="inv-row2">
            <div class="field"><label>Condition</label><select name="condition" class="eselect eselect--block"><?php foreach (INV_CONDITION_LABELS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $k === 'good' ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Bought for <span class="text-muted">(optional)</span></label><input name="purchase_value" type="number" class="inp inp--num no-spin" min="0" step="0.01" placeholder="<?= e($cur) ?>"></div>
          </div>
          <div class="field"><label>Bought on <span class="text-muted">(optional)</span></label>
            <button type="button" class="dp-btn" data-dp-target="invBought" data-dp-past data-dp-placeholder="Pick a date" style="width:100%">Pick a date</button>
            <input type="hidden" id="invBought" name="purchase_date" value=""></div>
          <div class="field"><label>Where it is</label><select name="to_id" class="eselect eselect--block" required><?= $locOpts(0) ?></select></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500"></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add unit</button>
        </form>
        <?php endif; ?>

        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="transfer" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="transfer">
          <?php if ($serial): ?>
          <div class="field"><label>Which unit</label><select name="asset_id" class="eselect eselect--block" required><?= $unitOpts() ?></select></div>
          <?php else: ?>
          <div class="inv-row2">
            <div class="field"><label>From</label><select name="from_id" class="eselect eselect--block" required><?= $holdOpts() ?></select></div>
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
          </div>
          <?php endif; ?>
          <div class="field"><label>To</label>
            <select name="to" class="eselect eselect--block" required>
              <optgroup label="Places"><?php foreach ($locs as $l): ?><option value="loc:<?= (int)$l['id'] ?>"><?= e(inv_location_label($l)) ?></option><?php endforeach; ?></optgroup>
              <?php if ($staff): ?><optgroup label="Team members"><?php foreach ($staff as $sid => $sname): ?><option value="staff:<?= (int)$sid ?>"><?= e($sname) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            </select></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500"></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('arrow-right', 15) ?> Move</button>
        </form>

        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="loss" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="loss">
          <?php if ($serial): ?>
          <div class="field"><label>Which unit</label><select name="asset_id" class="eselect eselect--block" required><?= $unitOpts() ?></select></div>
          <?php else: ?>
          <div class="inv-row2">
            <div class="field"><label>Where</label><select name="from_id" class="eselect eselect--block" required><?= $holdOpts() ?></select></div>
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
          </div>
          <?php endif; ?>
          <div class="field"><label>What happened</label>
            <div class="inv-chips"><?php foreach (INV_LOSS_LABELS as $k => $lbl): ?><label class="optchip"><input type="radio" name="reason" value="<?= e($k) ?>" <?= $k === 'broken' ? 'checked' : '' ?>><?= e($lbl) ?></label><?php endforeach; ?></div></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500" placeholder="What happened, who reported it"></div>
          <button type="submit" class="btn-primary btn-sm" onclick="return confirm('Record this loss? It is written to the history with its value.')"><?= admin_icon('check', 15) ?> Record loss</button>
        </form>

        <?php if (!$serial): ?>
        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="replace" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="replace">
          <p class="text-muted" style="font-size:13px;margin:0 0 12px">Records the loss and brings the same number in from Main stock, in one step.</p>
          <div class="inv-row2">
            <div class="field"><label>Where</label><select name="at_id" class="eselect eselect--block" required><?= $holdOpts() ?></select></div>
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
          </div>
          <div class="field"><label>What happened</label>
            <div class="inv-chips"><?php foreach (INV_LOSS_LABELS as $k => $lbl): ?><label class="optchip"><input type="radio" name="reason" value="<?= e($k) ?>" <?= $k === 'broken' ? 'checked' : '' ?>><?= e($lbl) ?></label><?php endforeach; ?></div></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500"></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('rotate', 15) ?> Replace</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card" id="details">
      <div class="card__head"><span class="card__title"><?= $item ? 'Details' : 'Add an item' ?></span></div>
      <div class="card__body" style="padding:16px 18px">
        <form method="POST" action="<?= $self ?><?= $item ? '?id=' . $id : '' ?>" enctype="multipart/form-data" class="inv-form" novalidate>
          <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($item): ?><input type="hidden" name="item_id" value="<?= $id ?>"><?php endif; ?>
          <?php if ($posLinked): ?><p class="text-muted" style="font-size:13px;margin:0 0 12px">Name, SKU, photo, currency and the low-stock alert come from the POS catalogue.</p><?php endif; ?>
          <?php if (!$posLinked): ?>
          <div class="field"><label>Name</label><input name="name" class="inp" maxlength="160" value="<?= e($val('name')) ?>" required><?= $err('name') ?></div>
          <?php endif; ?>
          <div class="inv-row2">
            <div class="field"><label>Type</label><select name="item_type" class="eselect eselect--block">
              <?php foreach (INV_TYPES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $val('item_type') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select><?= $err('item_type') ?></div>
            <div class="field"><label>Category</label><input name="category" class="inp" maxlength="60" list="invCats" value="<?= e($val('category')) ?>" placeholder="Kitchen, Linen, Electronics…"><?= $err('category') ?>
              <datalist id="invCats"><?php foreach (inv_categories() as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
          </div>
          <div class="inv-row2">
            <div class="field"><label>Replacement value <span class="text-muted">(each)</span></label><input name="replacement_value" type="number" class="inp inp--num no-spin" min="0" step="0.01" value="<?= e($val('replacement_value')) ?>" <?= $ownerOnly ? 'readonly title="Only the owner changes an item’s value"' : '' ?>><?= $err('replacement_value') ?></div>
            <?php if (!$posLinked): ?>
            <div class="field"><label>Currency</label><select name="currency" class="eselect eselect--block" <?= $ownerOnly ? 'disabled' : '' ?>>
              <?php foreach (array_keys(TS_CURRENCIES) as $c): ?><option value="<?= e($c) ?>" <?= strtoupper($val('currency') ?: INV_DEFAULT_CURRENCY) === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
          </div>
          <div class="inv-row2">
            <div class="field"><label>Icon <span class="text-muted">(one emoji)</span></label><input name="icon" class="inp" maxlength="8" value="<?= e($val('icon')) ?>" placeholder="🍽️"><?= $err('icon') ?></div>
            <div class="field"><label>Unit</label><input name="unit_label" class="inp" maxlength="20" value="<?= e($val('unit_label')) ?>" placeholder="pcs"></div>
          </div>
          <?php if (!$posLinked): ?>
          <div class="field"><label>Tracking</label>
            <div class="inv-chips">
              <label class="optchip"><input type="radio" name="tracking" value="qty" <?= $val('tracking') !== 'serial' ? 'checked' : '' ?> <?= $hasMoves ? 'disabled' : '' ?>>Counted (plates, towels)</label>
              <label class="optchip"><input type="radio" name="tracking" value="serial" <?= $val('tracking') === 'serial' ? 'checked' : '' ?> <?= $hasMoves ? 'disabled' : '' ?>>By serial number (laptops, phones)</label>
            </div>
            <?php if ($hasMoves): ?><input type="hidden" name="tracking" value="<?= e((string)$item['tracking']) ?>"><div class="text-muted" style="font-size:12px">Fixed once the item has stock history.</div><?php endif; ?></div>
          <div class="inv-row2">
            <div class="field"><label>SKU <span class="text-muted">(optional)</span></label><input name="sku" class="inp" maxlength="60" value="<?= e($val('sku')) ?>"></div>
            <div class="field"><label>Low-stock alert <span class="text-muted">(optional)</span></label><input name="low_stock_at" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= e($val('low_stock_at')) ?>"><?= $err('low_stock_at') ?></div>
          </div>
          <div class="field"><label>Photo</label>
            <div class="filefield">
              <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('image', 15) ?> Choose photo<input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-file-input hidden></label>
              <span class="filefield__name" data-file-name><?= $item && $item['image_key'] ? 'Current photo kept' : 'No photo' ?></span>
            </div>
            <?php if ($item && $item['image_key']): ?><label class="optchip" style="margin-top:8px"><input type="checkbox" name="remove_image" value="1">Remove photo</label><?php endif; ?>
            <?= $err('image') ?></div>
          <?php endif; ?>
          <?php if ($ownerOnly): ?>
          <?php if (inv_bool($form['is_active'] ?? true)): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
          <?php if (!$posLinked): ?><input type="hidden" name="currency" value="<?= e($val('currency') ?: INV_DEFAULT_CURRENCY) ?>"><?php endif; ?>
          <p class="text-muted" style="font-size:12px;margin:0 0 12px">Only the owner can change this item’s value or currency, or switch it off.</p>
          <?php else: ?>
          <div class="field"><label class="optchip"><input type="checkbox" name="is_active" value="1" <?= inv_bool($form['is_active'] ?? true) ? 'checked' : '' ?>>In use (shows in lists)</label></div>
          <?php endif; ?>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $item ? 'Save' : 'Add item' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<script>
(function () {
  // One action panel at a time.
  var chips = document.querySelectorAll('input[name=inv_panel]');
  function show() {
    var on = document.querySelector('input[name=inv_panel]:checked');
    document.querySelectorAll('[data-inv-panel]').forEach(function (f) { f.hidden = !on || f.getAttribute('data-inv-panel') !== on.value; });
  }
  chips.forEach(function (c) { c.addEventListener('change', show); });
  show();
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Lint**

Run: `php -l admin/inventory-item.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add admin/inventory-item.php
git commit -m "feat(inventory): item page — details, where it is, units, history and every stock action

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The locations page and one location's stock

**Files:**
- Create: `admin/inventory-locations.php`
- Create: `admin/inventory-location.php`

- [ ] **Step 1: Create `admin/inventory-locations.php`**

```php
<?php
/**
 * Admin: Inventory locations — Main stock, each property and its areas (Kitchen,
 * Villa 3…), and the POS outlets. Add areas; set how often each place is counted
 * and who is responsible. Owner + manager: a manager sees their properties plus
 * shared places, and changes settings only for their own properties
 * (inv_location_editable()). Team members' items live on their profile.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/frontdesk.php';          // frontdesk_today_ymd() — Nairobi "today"
require_login();
require_manager();

$self      = '/admin/inventory-locations.php';
$vids      = admin_venue_ids();
$supported = inv_supported();
if ($supported) inv_ensure_default_locations();

$flash = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'add_area') {
            $parent = inv_fetch_location((int)($_POST['parent_id'] ?? 0));
            if (!$parent || !inv_location_editable($parent, $vids)) throw new InvRefusal('Pick one of your properties.');
            $newId = inv_create_area((int)$parent['id'], (string)($_POST['name'] ?? ''));
            audit_log('inv.area_add', 'inv_location', $newId, (string)($_POST['name'] ?? ''));
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => 'Area added to ' . $parent['name'] . '.'];
        } elseif ($act === 'save_location') {
            $loc = inv_fetch_location((int)($_POST['location_id'] ?? 0));
            if (!$loc || !inv_location_editable($loc, $vids)) throw new InvRefusal('You can only change your own properties’ locations.');
            $v = ['count_every_days' => (string)($_POST['count_every_days'] ?? ''), 'count_assignee_id' => (int)($_POST['count_assignee_id'] ?? 0)];
            if (in_array($loc['kind'], ['area', 'store'], true) && isset($_POST['name'])) $v['name'] = (string)$_POST['name'];
            if ($loc['kind'] === 'area') $v['is_active'] = !empty($_POST['is_active']);
            inv_update_location((int)$loc['id'], $v);
            audit_log('inv.location_save', 'inv_location', (int)$loc['id'], (string)$loc['name']);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $loc['name'] . ' saved.'];
        }
    } catch (InvRefusal $e) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$rows      = $supported ? array_values(array_filter(inv_locations_visible($vids, false), fn($l) => $l['kind'] !== 'person')) : [];
$props     = array_values(array_filter($rows, fn($l) => $l['kind'] === 'property' && inv_location_editable($l, $vids)));
$today     = frontdesk_today_ymd();
$STATUS    = ['manual' => ['Manual', 'badge--grey'], 'ok' => ['Up to date', 'badge--green'], 'due' => ['Due today', 'badge--orange'], 'overdue' => ['Overdue', 'badge--red']];
$usersFor  = [];   // venue id => [user id => label], cached per venue

$pageTitle  = 'Inventory locations';
$activeMenu = 'inventory_locations';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Locations</h1>
  <a href="/admin/inventory.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inventory</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>

<div class="inv-grid">
  <div class="card">
    <div class="card__head"><span class="card__title">Places</span><span class="text-muted" style="font-size:12.5px">Open one for its stock and par levels</span></div>
    <?php if (!$rows): ?>
      <?php dt_empty('No locations yet.'); ?>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Location</th><th class="inv-num">Items</th><th>Counted</th><th>Responsible</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l):
        $st = inv_count_status($l['last_counted_at'], $l['count_every_days'] !== null ? (int)$l['count_every_days'] : null, $today);
        [$sl, $sc] = $STATUS[$st];
        $editable = inv_location_editable($l, $vids);
        $vKey = $l['venue_id'] !== null ? (int)$l['venue_id'] : 0;
        if ($editable && !isset($usersFor[$vKey])) $usersFor[$vKey] = inv_assignable_users($vKey ?: null);
        $closed = !inv_bool($l['is_active']); ?>
        <tr class="<?= $closed ? 'text-muted' : '' ?>">
          <td style="<?= $l['kind'] === 'area' ? 'padding-left:28px' : '' ?>">
            <a href="/admin/inventory-location.php?id=<?= (int)$l['id'] ?>"><strong><?= e($l['kind'] === 'area' ? (string)$l['name'] : inv_location_label($l)) ?></strong></a>
            <span class="inv-sub"><?= e(INV_LOCATION_KINDS[$l['kind']] ?? $l['kind']) ?><?= $closed ? ' · closed' : '' ?></span></td>
          <td class="inv-num"><?= (int)$l['item_count'] ?></td>
          <td><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
            <span class="inv-sub"><?= $l['count_every_days'] ? e(INV_COUNT_EVERY[(string)$l['count_every_days']] ?? 'Every ' . (int)$l['count_every_days'] . ' days') : '' ?><?= $l['last_counted_at'] ? ' · last ' . e(date('j M', strtotime((string)$l['last_counted_at']))) : '' ?></span></td>
          <td><?= e($l['assignee_name'] ?? '—') ?></td>
          <td style="text-align:right">
            <?php if ($editable): ?>
            <details class="inv-set"><summary class="btn-icon" data-tip="Settings" aria-label="Settings for <?= e((string)$l['name']) ?>"><?= admin_icon('settings', 15) ?></summary>
              <form method="POST" action="<?= $self ?>" class="inv-form inv-set__form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_location"><input type="hidden" name="location_id" value="<?= (int)$l['id'] ?>">
                <?php if (in_array($l['kind'], ['area', 'store'], true)): ?>
                <div class="field"><label>Name</label><input name="name" class="inp" maxlength="120" value="<?= e((string)$l['name']) ?>"></div>
                <?php endif; ?>
                <div class="field"><label>Count</label><select name="count_every_days" class="eselect eselect--block">
                  <?php $cur = $l['count_every_days'] === null ? '' : (string)(int)$l['count_every_days'];
                  $opts = INV_COUNT_EVERY; if ($cur !== '' && !isset($opts[$cur])) $opts[$cur] = 'Every ' . $cur . ' days';
                  foreach ($opts as $k => $lbl): ?><option value="<?= e((string)$k) ?>" <?= (string)$k === $cur ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Responsible</label><select name="count_assignee_id" class="eselect eselect--block">
                  <option value="0">Nobody in particular</option>
                  <?php foreach ($usersFor[$vKey] as $uid => $ulbl): ?><option value="<?= (int)$uid ?>" <?= (int)$l['count_assignee_id'] === $uid ? 'selected' : '' ?>><?= e($ulbl) ?></option><?php endforeach; ?></select></div>
                <?php if ($l['kind'] === 'area'): ?>
                <div class="field"><label class="optchip"><input type="checkbox" name="is_active" value="1" <?= $closed ? '' : 'checked' ?>>Open</label></div>
                <?php endif; ?>
                <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save</button>
              </form>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="inv-stack">
    <?php if ($props): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Add an area</span></div>
      <div class="card__body" style="padding:16px 18px">
        <form method="POST" action="<?= $self ?>" class="inv-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_area">
          <div class="field"><label>Property</label><select name="parent_id" class="eselect eselect--block">
            <?php foreach ($props as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= e((string)$pr['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Area name</label><input name="name" class="inp" maxlength="120" placeholder="Kitchen, Villa 3, Pool house…" required></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add area</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <div class="card"><div class="card__body text-muted" style="padding:16px 18px;font-size:13px">
      Counts are done by hand; the schedule only decides when a place shows as due. Items assigned to a team member are on their profile.
    </div></div>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.inv-set{display:inline-block;position:relative;text-align:left}
.inv-set summary{list-style:none;cursor:pointer}
.inv-set summary::-webkit-details-marker{display:none}
.inv-set__form{position:absolute;right:0;top:calc(100% + 6px);z-index:20;width:280px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px}
@media (max-width:560px){.inv-set__form{position:fixed;left:16px;right:16px;top:auto;bottom:16px;width:auto}}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Create `admin/inventory-location.php`**

```php
<?php
/**
 * Admin: one location's stock — every item there with its quantity, the par level
 * it should always have, and what is short. Set par levels (and add an item to
 * the list by giving it a par), then "Restock to par" pulls the shortfall from
 * Main stock in one transaction (inv_restock_to_par()). Owner + manager; the
 * location must be visible, and changes need inv_location_editable() plus the
 * move scope. Quantities change only through the inventory core.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/frontdesk.php';          // frontdesk_today_ymd()
require_login();
require_manager();

$vids      = admin_venue_ids();
$me        = current_admin();
$supported = inv_supported();
$id        = (int)($_GET['id'] ?? $_POST['location_id'] ?? 0);
$loc       = $supported && $id ? inv_fetch_location($id) : false;
$self      = '/admin/inventory-location.php?id=' . $id;

if (!$loc || !inv_location_visible($loc, $vids)) {
    http_response_code(404);
    $pageTitle = 'Inventory location'; $activeMenu = 'inventory_location';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Location not found. <a href="/admin/inventory-locations.php">Back to Locations</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}
$editable = inv_location_editable($loc, $vids);
$flash = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if (!$editable) throw new InvRefusal('You can only change your own properties’ stock settings.');
        if ($act === 'set_par') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $raw    = trim((string)($_POST['par'] ?? ''));
            if ($raw !== '' && !ctype_digit($raw)) throw new InvRefusal('Par level must be a whole number (blank to clear it).');
            $it = inv_fetch_item($itemId);
            if (!$it) throw new InvRefusal('Pick an item.');
            inv_set_par($itemId, (int)$loc['id'], $raw === '' ? null : (int)$raw);
            audit_log('inv.par', 'inv_location', (int)$loc['id'], "{$it['name']}: " . ($raw === '' ? 'cleared' : $raw));
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $raw === '' ? "Par level for {$it['name']} cleared." : "{$loc['name']} should always have {$raw} × {$it['name']}."];
        } elseif ($act === 'restock') {
            $store = inv_fetch_location(inv_store_location_id());
            if (!inv_move_in_scope($store, $loc, $vids)) throw new InvRefusal('That restock is outside your properties.');
            $r = inv_restock_to_par((int)$loc['id'], (int)$me['id']);
            $moved = array_sum($r['moved']);
            $short = count($r['short']);
            audit_log('inv.restock', 'inv_location', (int)$loc['id'], "moved {$moved}, short {$short}");
            $_SESSION['inv_flash'] = ['type' => $short ? 'info' : 'success', 'msg' =>
                ($moved ? "Moved {$moved} unit" . ($moved === 1 ? '' : 's') . " from Main stock." : 'Nothing could be moved.')
                . ($short ? " {$short} item" . ($short === 1 ? ' is' : 's are') . ' still short — Main stock has run out.' : '')];
        }
    } catch (InvRefusal $e) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$parentName = $loc['parent_id'] ? (string)(inv_fetch_location((int)$loc['parent_id'])['name'] ?? '') : '';
$stock      = inv_location_stock((int)$loc['id']);
$areas      = $loc['kind'] === 'property' ? inv_child_areas((int)$loc['id']) : [];
$needs      = array_sum(array_map(fn($r) => (int)$r['need'], $stock));
$values     = inv_sum_by_currency($stock);
$status     = inv_count_status($loc['last_counted_at'], $loc['count_every_days'] !== null ? (int)$loc['count_every_days'] : null, frontdesk_today_ymd());
$listed     = array_map(fn($r) => (int)$r['item_id'], $stock);
$addable    = $editable ? array_values(array_filter(
    db_query("SELECT id, name FROM inv_items WHERE is_active = TRUE AND tracking = 'qty' ORDER BY name")->fetchAll(),
    fn($i) => !in_array((int)$i['id'], $listed, true))) : [];
$STATUS     = ['manual' => ['Counted by hand', 'badge--grey'], 'ok' => ['Up to date', 'badge--green'], 'due' => ['Count due today', 'badge--orange'], 'overdue' => ['Count overdue', 'badge--red']];

$pageTitle  = (string)$loc['name'];
$activeMenu = 'inventory_location';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($parentName !== '' ? "{$parentName} › {$loc['name']}" : (string)$loc['name']) ?></h1>
  <a href="/admin/inventory-locations.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Locations</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:18px">
  <div class="inv-kpis">
    <div class="inv-kpi"><span><?= e(INV_LOCATION_KINDS[$loc['kind']] ?? $loc['kind']) ?></span><strong><?= count($stock) ?> item<?= count($stock) === 1 ? '' : 's' ?></strong></div>
    <div class="inv-kpi"><span>Value here</span><strong><?php if (!$values): ?>—<?php else: foreach ($values as $c => $amt): ?><?= e(inv_money((float)$amt, (string)$c)) ?> <?php endforeach; endif; ?></strong></div>
    <div class="inv-kpi"><span>Short of par</span><strong><?= (int)$needs ?></strong></div>
    <div class="inv-kpi"><span><?= $loc['last_counted_at'] ? 'Last counted ' . e(date('j M', strtotime((string)$loc['last_counted_at']))) : 'Never counted' ?></span><span class="badge <?= e($STATUS[$status][1]) ?>"><?= e($STATUS[$status][0]) ?></span></div>
    <?php if ($editable && $needs > 0 && $loc['kind'] !== 'store'): ?>
    <form method="POST" action="<?= e($self) ?>" style="margin-left:auto;align-self:center">
      <?= csrf_field() ?><input type="hidden" name="action" value="restock"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
      <button type="submit" class="btn-primary btn-sm" onclick="return confirm('Move what is short from Main stock to here?')"><?= admin_icon('arrow-right', 15) ?> Restock to par from Main stock</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($areas): ?>
  <div style="padding:10px 18px;font-size:13px">Areas:
    <?php foreach ($areas as $i => $a): ?><?= $i ? ' · ' : '' ?><a href="/admin/inventory-location.php?id=<?= (int)$a['id'] ?>"><?= e((string)$a['name']) ?></a> <span class="text-muted">(<?= (int)$a['units'] ?>)</span><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Stock here</span><span class="text-muted" style="font-size:12.5px">Par = what this place should always have</span></div>
  <?php if (!$stock): ?>
    <?php dt_empty('Nothing here yet. Give an item a par level below, then restock from Main stock — or receive stock on the item page.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Item</th><th class="inv-num">On hand</th><th class="inv-num">Par</th><th class="inv-num">Short</th><th class="inv-num">Value</th></tr></thead>
    <tbody>
    <?php foreach ($stock as $r): ?>
      <tr>
        <td><a href="/admin/inventory-item.php?id=<?= (int)$r['item_id'] ?>" class="inv-name"><?= inv_thumb_html($r, 32) ?><span><strong><?= e($r['name']) ?></strong><?= $r['category'] ? '<span class="inv-sub">' . e($r['category']) . '</span>' : '' ?></span></a></td>
        <td class="inv-num"><strong><?= (int)$r['qty'] ?></strong></td>
        <td class="inv-num">
          <?php if ($editable && $r['tracking'] === 'qty'): ?>
          <form method="POST" action="<?= e($self) ?>" class="inv-par">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_par"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>"><input type="hidden" name="item_id" value="<?= (int)$r['item_id'] ?>">
            <input name="par" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= $r['par_qty'] === null ? '' : (int)$r['par_qty'] ?>" aria-label="Par level for <?= e($r['name']) ?>">
            <button type="submit" class="btn-icon" data-tip="Save par" aria-label="Save par"><?= admin_icon('check', 14) ?></button>
          </form>
          <?php else: ?><?= $r['par_qty'] === null ? '—' : (int)$r['par_qty'] ?><?php endif; ?>
        </td>
        <td class="inv-num"><?= (int)$r['need'] > 0 ? '<span class="badge badge--orange">' . (int)$r['need'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td class="inv-num text-muted"><?= e(inv_money($r['value'], (string)$r['currency'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($editable && $addable): ?>
<div class="card" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Add an item to this list</span></div>
  <div class="card__body" style="padding:16px 18px">
    <form method="POST" action="<?= e($self) ?>" class="inv-form inv-add">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_par"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
      <div class="field"><label>Item</label><select name="item_id" class="eselect eselect--block"><?php foreach ($addable as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Should always have</label><input name="par" type="number" class="inp inp--num no-spin" min="0" step="1" required></div>
      <button type="submit" class="btn-primary btn-sm" style="align-self:end;margin-bottom:12px"><?= admin_icon('plus', 15) ?> Add</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.inv-par{display:inline-flex;gap:6px;align-items:center;justify-content:flex-end}
.inv-par .inp{width:72px}
.inv-add{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto;gap:0 12px}
@media (max-width:560px){.inv-add{grid-template-columns:1fr}}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 3: Lint**

Run: `php -l admin/inventory-locations.php && php -l admin/inventory-location.php`
Expected: `No syntax errors detected` ×2.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory-locations.php admin/inventory-location.php
git commit -m "feat(inventory): locations page (areas, count schedule, responsible) and a location's stock (par, restock)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Docs + full check

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Document the pages**

In `CLAUDE.md`, at the end of the `### Inventory & Assets …` section (after its last bullet, before the next `###`), add:

```markdown
- **Admin pages (owner + manager, sidebar "Inventory"):** `admin/inventory.php` (central list — scoped qty + "where" breakdown + value; Status "Sold" / "Lost / written off" switches to the movement ledger for a date window), `admin/inventory-item.php` (details, where it is, serial units, history, and the actions — receive / add unit / move-assign-return / report loss / replace — all via **`inv_apply_item_action()`**), `admin/inventory-locations.php` (areas, count schedule, responsible person), `admin/inventory-location.php` (a place's stock, par levels, **Restock to par** from Main stock). Read models, scope and settings writers live in **`includes/inventory-views.php`** (never quantities). Visibility (`inv_location_visible()`) mirrors `inv_move_in_scope()`: managers see their properties + shared Main stock / outlets, never a venue-less person; settings need `inv_location_editable()` (own property only — Main stock is owner-set). A POS-linked item's name, SKU, photo, currency, alert and tracking stay owned by its POS listing (`inv_update_item(..., $posLinked)`). Test: `php tests/inventory_views_logic.php`.
```

And in the File Map table, after the `db/migrations/add_inventory.sql · tests/inventory_logic.php` row, add:

```markdown
| `includes/inventory-views.php` · `tests/inventory_views_logic.php` | Inventory page read models, scope + settings writers, `inv_apply_item_action()` · tests |
| `admin/inventory.php` · `admin/inventory-item.php` · `admin/inventory-locations.php` · `admin/inventory-location.php` | Inventory admin: central list · item (details + actions) · locations (areas, count schedule) · one place's stock (par, restock) |
```

- [ ] **Step 2: Run everything**

Run:
```bash
for f in includes/inventory-views.php admin/_layout.php admin/inventory.php admin/inventory-item.php admin/inventory-locations.php admin/inventory-location.php; do php -l $f; done
php tests/inventory_views_logic.php | tail -1 && php tests/inventory_logic.php | tail -1 && php tests/pos_logic.php | tail -1
```
Expected: no syntax errors ×6; `ALL PASS` ×3.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(inventory): the Inventory admin pages

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: Browser check (coordinator)**

Start a dev server rooted at the worktree, sign in as the owner, and walk: Inventory → Add item "TEST plate" (Property asset, KES 850) → Receive 30 at Main stock → Move 10 to a property → Report 1 broken there (value shown) → Locations → add area "Kitchen" under that property, set "Every week" + a responsible person → open the property → set par 12 for TEST plate → Restock to par → the list's "Where" column shows Main 20 − … and the property; Status "Lost / written off" lists the breakage with KES 850. Check at 375 px wide for horizontal overflow. No console or server errors.
