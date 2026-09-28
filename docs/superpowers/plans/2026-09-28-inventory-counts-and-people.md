# Inventory — Part B (count screen, discrepancy review, assigned assets, due counts) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the Inventory & Assets feature: a phone/tablet stock-count screen, a manager's discrepancy review, an "Assets" tab on employee profiles, and "counts due" reminders on My Work and the Front Desk.

**Architecture:** Two small helper files on top of the Plan 1 core and the Plan 2A views — `includes/inventory-count-views.php` (who may count/resolve, what is due, count sheets, the review queue, the shared "due" card) and `includes/inventory-people.php` (what a team member holds and what can be assigned). Counting uses the existing `inv_count_start()` / `inv_count_submit()` / `inv_count_resolve_line()`; assigning/returning uses the existing `inv_apply_item_action()`. No migration.

**Tech Stack:** PHP 8.2 (vanilla), PostgreSQL via PDO, plain-PHP tests.

**Spec:** `docs/superpowers/specs/2026-09-27-inventory-assets-design.md` §3 rule 7, §4 (count screen, discrepancy queue, employee card, My Work surfacing).

---

## Conventions

- Worktree `/Users/patrikgiuliana/.config/superpowers/worktrees/Tribal Sand/inventory-counts`, branch `feat/inventory-counts` (from `master`, which has Plans 1 + 2A). **Never push**; never touch `/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand`.
- `db_query()`; pdo_pgsql: no reused named placeholder, no unused bound param.
- Core (includes/inventory.php): `inv_count_start($locationId, $userId)` (reuses a same-day open count, cancels a stale one), `inv_count_submit($countId, [item_id => number], $userId)` (never moves stock; matches auto-accept), `inv_count_resolve_line($lineId, $resolution, $userId, $note)` (missing/broken/stolen/found/recount/accepted; refused while the live balance differs from `expected`), `inv_count_status()`, `inv_count_due_ymd()`.
- Views (includes/inventory-views.php): `inv_location_visible()`, `inv_location_editable()`, `inv_location_label()`, `inv_visible_sql($alias, $venueIds, &$p, $tag)`, `inv_apply_item_action($in, $item, $venueIds, $userId)`, `inv_thumb_html()`, `inv_shared_css()`, `inv_money()`, `INV_LOSS_LABELS`.
- Roles: `owner | manager | reception | staff`. `admin_venue_ids()` → `null` for the owner. `current_admin()` returns the row (with `id`, `role`).
- Admin UI: no native chrome (`.eselect`, `.optchip`, `.dp-btn`); PRG + CSRF; escape with `e()`. Flash key per page as noted.
- Tests: `php tests/<name>.php` from the worktree root; DB work in one rolled-back transaction.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; stage only the named files.

## Who may do what (load-bearing)

- **Count a place** (`inv_can_count`): the owner; the place's responsible person (`count_assignee_id`); for SHARED places (Main stock, venue-less outlets) also managers; otherwise anyone whose venues include the place's venue (manager, reception, staff at that property). A team member's own location is never counted here.
- **Resolve a gap** (`inv_can_resolve`): the owner, or a manager of that property (`inv_location_editable`). Shared places (Main stock) are owner-resolved — matching the Plan 1 write scope.
- **Assign / return / report lost** on a profile: through `inv_apply_item_action()`, so all scope rules of Plan 2A apply.

## File map

| File | Status | Responsibility |
|---|---|---|
| `includes/inventory-count-views.php` | Create | Count permissions, places to count + due, count sheet, review queue, shared "due" card |
| `includes/inventory-people.php` | Create | A person's assets; stock/units that can be assigned |
| `tests/inventory_counts_logic.php` | Create | Pure + DB tests for both files |
| `admin/inventory-count.php` | Create | The count screen (list → place → count sheet → summary) |
| `admin/inventory-counts.php` | Create | Manager review queue + counts due |
| `admin/employee.php` | Modify | "Assets" tab: what they hold, assign, return / report lost |
| `admin/mywork.php`, `admin/frontdesk.php` | Modify | "Stock counts due" card (+ review count on the Front Desk) |
| `admin/_layout.php` | Modify | "Counts" in the Inventory group; "Stock count" for staff/reception |
| `CLAUDE.md` | Modify | Document the rules above |

---

### Task 1: Count helpers + people helpers (+ tests)

**Files:**
- Create: `includes/inventory-count-views.php`, `includes/inventory-people.php`, `tests/inventory_counts_logic.php`

- [ ] **Step 1: Write the failing test file**

```php
<?php
declare(strict_types=1);
// Inventory counts + people helpers. Run: php tests/inventory_counts_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_once __DIR__ . '/../includes/inventory-people.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Who may count / resolve ─────────────────────────────────────────────────
$amani = ['kind' => 'property', 'venue_id' => 1, 'count_assignee_id' => null];
$store = ['kind' => 'store', 'venue_id' => null, 'count_assignee_id' => null];
$jane  = ['kind' => 'person', 'venue_id' => 1, 'count_assignee_id' => null];
check('count: owner counts anything', inv_can_count($store, 9, 'owner', null));
check('count: staff at the property count it', inv_can_count($amani, 5, 'staff', [1]));
check('count: staff elsewhere do not', !inv_can_count($amani, 5, 'staff', [3]));
check('count: staff do not count shared Main stock…', !inv_can_count($store, 5, 'staff', [1]));
check('count: …unless they are responsible for it', inv_can_count(['count_assignee_id' => 5] + $store, 5, 'staff', [1]));
check('count: managers count shared Main stock', inv_can_count($store, 7, 'manager', [1]));
check('count: a team member’s own location is never a count', !inv_can_count($jane, 9, 'owner', null));
check('resolve: owner resolves anywhere', inv_can_resolve($store, 'owner', null));
check('resolve: a manager resolves their own property', inv_can_resolve($amani, 'manager', [1]));
check('resolve: a manager does not resolve shared Main stock', !inv_can_resolve($store, 'manager', [1]));
check('resolve: staff never resolve', !inv_can_resolve($amani, 'staff', [1]));

// ── Sorting and gaps ────────────────────────────────────────────────────────
$sorted = inv_count_sort([
    ['name' => 'B', 'count_status' => 'ok'], ['name' => 'A', 'count_status' => 'manual'],
    ['name' => 'C', 'count_status' => 'overdue'], ['name' => 'D', 'count_status' => 'due'],
]);
check('sort: overdue, due, up to date, manual', array_column($sorted, 'name') === ['C', 'D', 'B', 'A']);
check('gap: short line valued at replacement', inv_count_line_gap(['expected' => 12, 'counted' => 11, 'replacement_value' => '400']) === ['gap' => -1, 'value' => 400.0]);
check('gap: extra line, no value known', inv_count_line_gap(['expected' => 5, 'counted' => 7, 'replacement_value' => null]) === ['gap' => 2, 'value' => null]);
check('due card: lists due places with a Count button', str_contains($card = inv_counts_due_card([['id' => 4, 'label' => 'My Amani › Pantry', 'count_status' => 'overdue', 'open_count_id' => null]], 0), 'Pantry') && str_contains($card, 'Count now'));
check('due card: skips places that are not due, and is empty when nothing is', inv_counts_due_card([['id' => 4, 'label' => 'X', 'count_status' => 'ok', 'open_count_id' => null]], 0) === '');
check('due card: shows the review count for managers', str_contains(inv_counts_due_card([], 3), '3 to review'));

// ── DB-backed ───────────────────────────────────────────────────────────────
try { db()->query('SELECT 1'); } catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}
if (!inv_supported()) { echo "\nSKIP  DB block (add_inventory.sql not applied)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    $sfx = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $today = frontdesk_today_ymd();

    $vA = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Count A')", [':s' => "zz-cnt-a-{$sfx}"]);
    $vB = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Count B')", [':s' => "zz-cnt-b-{$sfx}"]);
    $store = inv_store_location_id();
    $locA  = inv_property_location_id($vA);
    $locB  = inv_property_location_id($vB);
    $area  = inv_create_area($locA, 'ZZ Pantry');
    $mkUser = fn(string $role, string $tag) => $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, :r, :n, TRUE)",
        [':e' => "zz-cnt-{$tag}-{$sfx}@example.com", ':r' => $role, ':n' => "ZZ {$tag}"]);
    $owner = $mkUser('owner', 'owner'); $mgrA = $mkUser('manager', 'mgra'); $mgrB = $mkUser('manager', 'mgrb'); $maid = $mkUser('staff', 'maid');
    foreach ([[$mgrA, $vA], [$mgrB, $vB], [$maid, $vA]] as [$u, $v]) db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:u, :v)', [':u' => $u, ':v' => $v]);
    $glass = inv_create_item(['name' => 'ZZ Count glass', 'item_type' => 'operational', 'replacement_value' => 400]);
    inv_move(['item_id' => $glass, 'qty' => 20, 'to' => $store, 'reason' => 'receive']);
    inv_transfer($glass, 12, $store, $area, $owner);

    // Places to count.
    $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);
    $maidPlaces = $ids(inv_countable_locations($maid, 'staff', [$vA], $today));
    check('places: staff get their property and its area', in_array($locA, $maidPlaces, true) && in_array($area, $maidPlaces, true));
    check('places: staff do not get Main stock or another property', !in_array($store, $maidPlaces, true) && !in_array($locB, $maidPlaces, true));
    inv_update_location($store, ['count_every_days' => '', 'count_assignee_id' => $maid]);
    check('places: …Main stock appears once they are responsible for it', in_array($store, $ids(inv_countable_locations($maid, 'staff', [$vA], $today)), true));
    inv_update_location($area, ['count_every_days' => '7', 'count_assignee_id' => 0]);
    $due = inv_counts_due($maid, 'staff', [$vA], $today);
    check('due: a weekly place never counted is due', in_array($area, $ids($due), true) && !in_array($locA, $ids($due), true));

    // Count → review queue.
    $cid = inv_count_start($area, $maid);
    $sheet = inv_count_sheet($cid);
    check('sheet: lines carry item details and expected', $sheet && count($sheet['lines']) === 1 && (int)$sheet['lines'][0]['expected'] === 12 && $sheet['lines'][0]['name'] === 'ZZ Count glass');
    inv_count_submit($cid, [$glass => 11], $maid);
    $qA = array_map(fn($c) => (int)$c['id'], inv_count_queue([$vA]));
    check('queue: the manager of the property sees the gap', in_array($cid, $qA, true));
    check('queue: another property’s manager does not', !in_array($cid, array_map(fn($c) => (int)$c['id'], inv_count_queue([$vB])), true));
    check('queue: size helper agrees', inv_count_queue_size([$vA]) >= 1);
    $line = (int) db_query('SELECT id FROM inv_count_lines WHERE count_id = :c', [':c' => $cid])->fetchColumn();
    $ll = inv_count_line_location($line);
    check('line location: resolves to the counted place', $ll && (int)$ll['id'] === $area && inv_can_resolve($ll, 'manager', [$vA]));
    check('due: after counting, the place is no longer due', !in_array($area, $ids(inv_counts_due($maid, 'staff', [$vA], $today)), true));

    // People.
    $jane = $ins("INSERT INTO hr_staff (full_name, venue_id) VALUES ('ZZ Count Jane', :v)", [':v' => $vA]);
    check('person: nothing held yet, no location created', inv_person_location_find($jane) === null && inv_person_assets($jane)['rows'] === []);
    $stock = inv_assignable_stock([$vA]);
    check('assignable: stock at the manager’s places and shared Main stock', (bool) array_filter($stock, fn($s) => (int)$s['item_id'] === $glass && (int)$s['location_id'] === $store));
    inv_apply_item_action(['action' => 'transfer', 'qty' => '2', 'from_id' => (string)$store, 'to' => 'staff:' . $jane], inv_fetch_item($glass), [$vA], $mgrA);
    $held = inv_person_assets($jane);
    check('person: holds 2 glasses, with value', count($held['rows']) === 1 && (int)$held['rows'][0]['qty'] === 2 && (float)$held['rows'][0]['value'] === 800.0 && $held['location'] !== null);
    $phone = inv_create_item(['name' => 'ZZ Count phone', 'item_type' => 'employee', 'tracking' => 'serial', 'replacement_value' => 30000]);
    $unit = inv_asset_create($phone, $store, ['serial' => "ZZ-CP-{$sfx}"], $owner);
    check('assignable: a serial unit in Main stock', (bool) array_filter(inv_assignable_units(null), fn($u) => (int)$u['id'] === $unit));
    inv_apply_item_action(['action' => 'transfer', 'asset_id' => (string)$unit, 'to' => 'staff:' . $jane], inv_fetch_item($phone), null, $owner);
    $held = inv_person_assets($jane);
    $ph = array_values(array_filter($held['rows'], fn($r) => (int)$r['item_id'] === $phone))[0] ?? null;
    check('person: the phone is listed with its serial', $ph && count($ph['units']) === 1 && $ph['units'][0]['serial'] === "ZZ-CP-{$sfx}");
    // ── DB checks (later tasks insert above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_counts_logic.php`
Expected: fatal — `Failed opening required '.../includes/inventory-count-views.php'`.

- [ ] **Step 3: Create `includes/inventory-count-views.php`**

```php
<?php
declare(strict_types=1);
/**
 * Inventory counts — who may count and resolve, what is due, count sheets and the
 * manager's review queue. Counting and resolving themselves are the Plan 1 core
 * (inv_count_start / inv_count_submit / inv_count_resolve_line): counting never
 * moves stock; only a manager's resolution does.
 * Test: php tests/inventory_counts_logic.php
 */

require_once __DIR__ . '/inventory-views.php';
require_once __DIR__ . '/frontdesk.php';     // frontdesk_today_ymd() — Nairobi "today"

const INV_COUNT_STATUS_ORDER  = ['overdue' => 0, 'due' => 1, 'ok' => 2, 'manual' => 3];
const INV_COUNT_STATUS_LABELS = ['manual' => ['Counted by hand', 'badge--grey'], 'ok' => ['Up to date', 'badge--green'],
                                 'due' => ['Due today', 'badge--orange'], 'overdue' => ['Overdue', 'badge--red']];
const INV_RESOLUTION_LABELS   = ['missing' => 'Missing', 'broken' => 'Broken', 'stolen' => 'Stolen', 'found' => 'Found',
                                 'recount' => 'Recount', 'accepted' => 'Matched'];

// ── Pure rules ──────────────────────────────────────────────────────────────

/**
 * May this account COUNT a place? — PURE. Owner: anywhere. The place's responsible
 * person: yes. Shared places (Main stock, venue-less outlets): managers too.
 * Otherwise anyone whose venues include the place's venue. Never a person's location.
 */
function inv_can_count(array $loc, int $adminId, string $role, ?array $venueIds): bool {
    if (($loc['kind'] ?? '') === 'person') return false;
    if ($venueIds === null || $role === 'owner') return true;
    if ($adminId > 0 && (int)($loc['count_assignee_id'] ?? 0) === $adminId) return true;
    $v = $loc['venue_id'] ?? null;
    if ($v === null || $v === '') return $role === 'manager';
    return in_array((int)$v, array_map('intval', $venueIds), true);
}

/** May this account RESOLVE gaps at a place? The owner, or a manager of that property — PURE. */
function inv_can_resolve(array $loc, string $role, ?array $venueIds): bool {
    if ($role === 'owner' || $venueIds === null) return true;
    return $role === 'manager' && inv_location_editable($loc, $venueIds);
}

/** Most urgent first: overdue, due, up to date, manual; then by label — PURE. */
function inv_count_sort(array $rows): array {
    usort($rows, fn(array $a, array $b): int =>
        [INV_COUNT_STATUS_ORDER[$a['count_status']] ?? 9, mb_strtolower((string)$a['name'])]
        <=> [INV_COUNT_STATUS_ORDER[$b['count_status']] ?? 9, mb_strtolower((string)$b['name'])]);
    return $rows;
}

/** A count line's gap (counted − expected) and the value of that gap — PURE. */
function inv_count_line_gap(array $line): array {
    $gap = (int)$line['counted'] - (int)$line['expected'];
    $rv  = $line['replacement_value'] ?? null;
    return ['gap' => $gap, 'value' => ($rv === null || $rv === '') ? null : round(abs($gap) * (float)$rv, 2)];
}

// ── Reads ───────────────────────────────────────────────────────────────────

/** Places this account can count, each with count_status + due_ymd + open_count_id, most urgent first. */
function inv_countable_locations(int $adminId, string $role, ?array $venueIds, string $todayYmd): array {
    if (!inv_supported()) return [];
    $p = [':me' => $adminId];
    $w = '(' . inv_visible_sql('l', $venueIds, $p) . ' OR l.count_assignee_id = :me)';
    $rows = db_query("SELECT l.*, pl.name AS parent_name,
                             (SELECT COUNT(*) FROM inv_balances b WHERE b.location_id = l.id AND (b.qty <> 0 OR b.par_qty IS NOT NULL)) AS line_count,
                             (SELECT c.id FROM inv_counts c WHERE c.location_id = l.id AND c.status = 'open' ORDER BY c.id DESC LIMIT 1) AS open_count_id
                        FROM inv_locations l
                        LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                       WHERE l.is_active = TRUE AND l.kind <> 'person' AND {$w}", $p)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if (!inv_can_count($r, $adminId, $role, $venueIds)) continue;
        $every = $r['count_every_days'] !== null ? (int)$r['count_every_days'] : null;
        $r['count_status'] = inv_count_status($r['last_counted_at'], $every, $todayYmd);
        $r['due_ymd']      = inv_count_due_ymd($r['last_counted_at'], $every);
        $r['label']        = inv_location_label($r);
        $out[] = $r;
    }
    return inv_count_sort($out);
}

/** The places this account should count today (due or overdue). */
function inv_counts_due(int $adminId, string $role, ?array $venueIds, string $todayYmd): array {
    return array_values(array_filter(inv_countable_locations($adminId, $role, $venueIds, $todayYmd),
        fn(array $r): bool => in_array($r['count_status'], ['due', 'overdue'], true)));
}

/** A count with its place and lines (item details). null when it does not exist. */
function inv_count_sheet(int $countId): ?array {
    if (!inv_supported() || $countId <= 0) return null;
    $c = db_query("SELECT c.*, l.name AS location_name, l.kind, l.venue_id, l.count_assignee_id, l.parent_id,
                          pl.name AS parent_name, a.name AS counted_by_name
                     FROM inv_counts c
                     JOIN inv_locations l       ON l.id = c.location_id
                     LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                     LEFT JOIN admin_users a    ON a.id = c.counted_by
                    WHERE c.id = :c", [':c' => $countId])->fetch();
    if (!$c) return null;
    $c['lines'] = db_query("SELECT cl.*, i.name, i.image_key, i.icon, i.unit_label, i.tracking, i.replacement_value, i.currency, i.category
                              FROM inv_count_lines cl JOIN inv_items i ON i.id = cl.item_id
                             WHERE cl.count_id = :c ORDER BY i.category NULLS LAST, i.name", [':c' => $countId])->fetchAll();
    return $c;
}

/** Submitted counts with open gaps at places the account can see, oldest first. */
function inv_count_queue(?array $venueIds): array {
    if (!inv_supported()) return [];
    $p = [];
    $w = inv_visible_sql('l', $venueIds, $p);
    return db_query("SELECT c.id, c.location_id, c.submitted_at, c.counted_by, l.name AS location_name, l.kind, l.venue_id,
                            pl.name AS parent_name, a.name AS counted_by_name,
                            (SELECT COUNT(*) FROM inv_count_lines cl WHERE cl.count_id = c.id AND cl.resolution IS NULL) AS open_lines
                       FROM inv_counts c
                       JOIN inv_locations l       ON l.id = c.location_id
                       LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                       LEFT JOIN admin_users a    ON a.id = c.counted_by
                      WHERE c.status = 'submitted' AND {$w}
                      ORDER BY c.submitted_at, c.id", $p)->fetchAll();
}

function inv_count_queue_size(?array $venueIds): int {
    if (!inv_supported()) return 0;
    $p = [];
    $w = inv_visible_sql('l', $venueIds, $p);
    return (int) db_query("SELECT COUNT(*) FROM inv_counts c JOIN inv_locations l ON l.id = c.location_id
                            WHERE c.status = 'submitted' AND {$w}", $p)->fetchColumn();
}

/** The place a count line belongs to (for the resolve permission check). */
function inv_count_line_location(int $lineId): ?array {
    if (!inv_supported()) return null;
    $r = db_query('SELECT l.* FROM inv_count_lines cl JOIN inv_counts c ON c.id = cl.count_id
                     JOIN inv_locations l ON l.id = c.location_id WHERE cl.id = :id', [':id' => $lineId])->fetch();
    return $r ?: null;
}

// ── Shared "counts due" card (My Work, Front Desk) ──────────────────────────

/**
 * A card listing places due/overdue for counting (from inv_countable_locations();
 * rows that are not due are skipped) and, for managers, how many counts wait for
 * review. Returns '' when there is nothing to show.
 */
function inv_counts_due_card(array $places, int $reviewCount): string {
    $due = array_values(array_filter($places, fn(array $r): bool => in_array($r['count_status'], ['due', 'overdue'], true)));
    if (!$due && $reviewCount <= 0) return '';
    ob_start(); ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Stock counts</span>
    <?php if ($reviewCount > 0): ?><a href="/admin/inventory-counts.php" class="btn-outline btn-sm"><?= (int)$reviewCount ?> to review</a><?php endif; ?></div>
  <?php if ($due): ?>
  <div class="card__body" style="padding:0">
    <?php foreach ($due as $r): [$sl, $sc] = INV_COUNT_STATUS_LABELS[$r['count_status']]; ?>
    <div style="display:flex;align-items:center;gap:10px;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--border)">
      <span><strong><?= e($r['label']) ?></strong> <span class="badge <?= e($sc) ?>"><?= e($sl) ?></span></span>
      <a href="/admin/inventory-count.php?location=<?= (int)$r['id'] ?>" class="btn-primary btn-sm"><?= $r['open_count_id'] ? 'Continue' : 'Count now' ?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}
```

- [ ] **Step 4: Create `includes/inventory-people.php`**

```php
<?php
declare(strict_types=1);
/**
 * Inventory — what a team member holds, and what can be assigned to them. Their
 * items sit at their own `kind='person'` location (home venue = owning venue).
 * Assigning / returning / reporting lost always goes through inv_apply_item_action(),
 * so the Plan 2A scope rules apply. Test: php tests/inventory_counts_logic.php
 */

require_once __DIR__ . '/inventory-views.php';

/** Read-only: a team member's location id, or null when they have never held anything (never creates one). */
function inv_person_location_find(int $hrStaffId): ?int {
    if (!inv_supported()) return null;
    $id = db_query('SELECT id FROM inv_locations WHERE hr_staff_id = :s', [':s' => $hrStaffId])->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * What a team member holds: ['location' => row|null, 'rows' => [item_id, name, image_key, icon,
 * tracking, unit_label, currency, replacement_value, qty, value, since, units[]]].
 */
function inv_person_assets(int $hrStaffId): array {
    $locId = inv_person_location_find($hrStaffId);
    if ($locId === null) return ['location' => null, 'rows' => []];
    $rows = db_query("SELECT i.id AS item_id, i.name, i.image_key, i.icon, i.tracking, i.unit_label, i.currency, i.replacement_value, b.qty,
                             (SELECT MAX(m.created_at) FROM inv_moves m WHERE m.item_id = i.id AND m.to_location_id = b.location_id) AS since
                        FROM inv_balances b JOIN inv_items i ON i.id = b.item_id
                       WHERE b.location_id = :l AND b.qty <> 0
                       ORDER BY i.name", [':l' => $locId])->fetchAll();
    $units = [];
    foreach (db_query("SELECT id, item_id, serial, tag, condition FROM inv_assets WHERE location_id = :l AND status = 'active' ORDER BY serial NULLS LAST, id",
                      [':l' => $locId])->fetchAll() as $u) {
        $units[(int)$u['item_id']][] = $u;
    }
    foreach ($rows as &$r) {
        $r['value'] = $r['replacement_value'] !== null ? round((float)$r['replacement_value'] * (int)$r['qty'], 2) : null;
        $r['units'] = $units[(int)$r['item_id']] ?? [];
    }
    unset($r);
    return ['location' => inv_fetch_location($locId), 'rows' => $rows];
}

/** Counted stock the account can hand out: [item_id, item_name, location_id, location_label, qty], at visible non-person places. */
function inv_assignable_stock(?array $venueIds): array {
    if (!inv_supported()) return [];
    $p = [];
    $w = inv_visible_sql('l', $venueIds, $p);
    $rows = db_query("SELECT i.id AS item_id, i.name AS item_name, l.id AS location_id, l.name, l.kind, pl.name AS parent_name, b.qty
                        FROM inv_balances b
                        JOIN inv_items i           ON i.id = b.item_id
                        JOIN inv_locations l       ON l.id = b.location_id
                        LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                       WHERE b.qty > 0 AND i.is_active = TRUE AND i.tracking = 'qty'
                         AND l.is_active = TRUE AND l.kind <> 'person' AND {$w}
                       ORDER BY i.name, l.name", $p)->fetchAll();
    return array_map(fn(array $r): array => $r + ['location_label' => inv_location_label($r)], $rows);
}

/** Serial units the account can hand out: [id, item_id, item_name, serial, location_id, location_label], at visible non-person places. */
function inv_assignable_units(?array $venueIds): array {
    if (!inv_supported()) return [];
    $p = [];
    $w = inv_visible_sql('l', $venueIds, $p);
    $rows = db_query("SELECT a.id, a.item_id, i.name AS item_name, a.serial, l.id AS location_id, l.name, l.kind, pl.name AS parent_name
                        FROM inv_assets a
                        JOIN inv_items i           ON i.id = a.item_id
                        JOIN inv_locations l       ON l.id = a.location_id
                        LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                       WHERE a.status = 'active' AND i.is_active = TRUE AND l.is_active = TRUE AND l.kind <> 'person' AND {$w}
                       ORDER BY i.name, a.serial NULLS LAST, a.id", $p)->fetchAll();
    return array_map(fn(array $r): array => $r + ['location_label' => inv_location_label($r)], $rows);
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `php tests/inventory_counts_logic.php | tail -3 && php tests/inventory_views_logic.php | tail -1`
Expected: `ALL PASS` both.

- [ ] **Step 6: Commit**

```bash
git add includes/inventory-count-views.php includes/inventory-people.php tests/inventory_counts_logic.php
git commit -m "feat(inventory): count permissions, due places, count sheets, review queue; a person's assets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The count screen

**Files:**
- Create: `admin/inventory-count.php`

- [ ] **Step 1: Create the page**

```php
<?php
/**
 * Stock count — the phone/tablet check. The person responsible for a place, or
 * anyone who works at its property, counts it: one card per item with the Expected
 * number and a big number box; a card turns red when the count differs. Submitting
 * saves the numbers — stock does NOT change; a manager resolves gaps on
 * Inventory → Counts. No params: the places you can count. ?location=ID: start
 * (or continue) that place's count. ?count=ID: the count itself.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_login();

$self      = '/admin/inventory-count.php';
$me        = current_admin();
$meId      = (int)$me['id'];
$role      = (string)($me['role'] ?? 'staff');
$vids      = admin_venue_ids();
$supported = inv_supported();
$today     = frontdesk_today_ymd();
$flash     = $_SESSION['invc_flash'] ?? null; unset($_SESSION['invc_flash']);

function invc_go(string $url): never { header('Location: ' . $url); exit; }
$countable = fn(array $sheetOrLoc): bool => inv_can_count(['kind' => $sheetOrLoc['kind'], 'venue_id' => $sheetOrLoc['venue_id'],
                                                           'count_assignee_id' => $sheetOrLoc['count_assignee_id']], $meId, $role, $vids);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = $self;
    try {
        if ($act === 'start') {
            $loc = inv_fetch_location((int)($_POST['location_id'] ?? 0));
            if (!$loc || !$countable($loc)) throw new InvRefusal('You can’t count that place.');
            $back = $self . '?location=' . (int)$loc['id'];
            invc_go($self . '?count=' . inv_count_start((int)$loc['id'], $meId));
        }
        if ($act === 'submit') {
            $sheet = inv_count_sheet((int)($_POST['count_id'] ?? 0));
            if (!$sheet || !$countable($sheet)) throw new InvRefusal('You can’t count that place.');
            $back    = $self . '?count=' . (int)$sheet['id'];
            $counted = is_array($_POST['counted'] ?? null) ? array_map(fn($v) => trim((string)$v), $_POST['counted']) : [];
            $res     = inv_count_submit((int)$sheet['id'], $counted, $meId);
            audit_log('inv.count_submit', 'inv_location', (int)$sheet['location_id'], "count #{$sheet['id']}: {$res['gaps']} gap(s)");
            $_SESSION['invc_flash'] = ['type' => 'success', 'msg' => $res['gaps']
                ? "Thanks — {$res['gaps']} item" . ($res['gaps'] === 1 ? '' : 's') . ' didn’t match. A manager will check.'
                : 'Thanks — everything matched.'];
            invc_go($back);
        }
    } catch (InvRefusal $e) {
        $_SESSION['invc_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    invc_go($back);
}

$sheet = $supported && isset($_GET['count']) ? inv_count_sheet((int)$_GET['count']) : null;
if ($sheet && !$countable($sheet)) $sheet = null;
$loc = (!$sheet && $supported && isset($_GET['location'])) ? inv_fetch_location((int)$_GET['location']) : false;
if ($loc && (!$countable($loc) || !inv_bool($loc['is_active']))) $loc = false;
$places = (!$sheet && !$loc && $supported) ? inv_countable_locations($meId, $role, $vids, $today) : [];
$notFound = (isset($_GET['count']) && !$sheet) || (isset($_GET['location']) && !$loc);

$pageTitle  = 'Stock count';
$activeMenu = 'inventory_count';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $sheet ? e(inv_location_label(['kind' => $sheet['kind'], 'name' => $sheet['location_name'], 'parent_name' => $sheet['parent_name']])) : ($loc ? e(inv_location_label($loc + ['parent_name' => $loc['parent_id'] ? (inv_fetch_location((int)$loc['parent_id'])['name'] ?? '') : ''])) : 'Stock count') ?></h1>
  <?php if ($sheet || $loc): ?><a href="<?= $self ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> All places</a><?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Inventory isn’t set up yet.</div>
<?php elseif ($notFound): ?>
  <?php dt_empty('That count isn’t available to you.'); ?>

<?php elseif ($sheet && $sheet['status'] === 'open'): $lines = $sheet['lines']; ?>
  <?php if (!$lines): ?>
    <?php dt_empty('Nothing is expected here yet — ask a manager to set what this place should have.'); ?>
  <?php else: ?>
  <p class="text-muted" style="margin:-4px 0 14px;font-size:13px">Count what is actually there. Stock doesn’t change until a manager checks any difference.</p>
  <form method="POST" action="<?= $self ?>" id="invcForm" data-count="<?= (int)$sheet['id'] ?>" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="submit"><input type="hidden" name="count_id" value="<?= (int)$sheet['id'] ?>">
    <div class="invc-grid">
      <?php foreach ($lines as $l): $exp = (int)$l['expected']; ?>
      <div class="invc-card" data-expected="<?= $exp ?>">
        <div class="invc-card__top"><?= inv_thumb_html($l, 48) ?>
          <div><strong><?= e($l['name']) ?></strong>
            <span class="inv-sub">Expected <b><?= $exp ?></b> <?= e((string)$l['unit_label']) ?></span></div></div>
        <div class="invc-step">
          <button type="button" class="invc-btn" data-step="-1" aria-label="One less">−</button>
          <input name="counted[<?= (int)$l['item_id'] ?>]" type="number" inputmode="numeric" min="0" step="1" class="inp inp--num no-spin invc-inp" aria-label="Counted <?= e($l['name']) ?>">
          <button type="button" class="invc-btn" data-step="1" aria-label="One more">+</button>
          <button type="button" class="invc-same" data-same>= <?= $exp ?></button>
        </div>
        <div class="invc-diff" aria-live="polite"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="invc-bar">
      <span id="invcProgress" class="text-muted">0 of <?= count($lines) ?> counted</span>
      <button type="submit" class="btn-primary"><?= admin_icon('check', 16) ?> Submit count</button>
    </div>
  </form>
  <?php endif; ?>

<?php elseif ($sheet): ?>
  <div class="card">
    <div class="card__head"><span class="card__title"><?= $sheet['status'] === 'resolved' ? 'Count checked' : ($sheet['status'] === 'cancelled' ? 'Count closed' : 'Waiting for a manager') ?></span>
      <span class="text-muted" style="font-size:12.5px"><?= $sheet['submitted_at'] ? 'Submitted ' . e(date('j M, H:i', strtotime((string)$sheet['submitted_at']))) : '' ?><?= $sheet['counted_by_name'] ? ' by ' . e($sheet['counted_by_name']) : '' ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="inv-num">Expected</th><th class="inv-num">Counted</th><th>Result</th></tr></thead>
      <tbody>
      <?php foreach ($sheet['lines'] as $l): $g = $l['counted'] === null ? null : inv_count_line_gap($l)['gap']; ?>
        <tr>
          <td><span class="inv-name"><?= inv_thumb_html($l, 28) ?><span><?= e($l['name']) ?></span></span></td>
          <td class="inv-num"><?= (int)$l['expected'] ?></td>
          <td class="inv-num"><strong><?= $l['counted'] === null ? '—' : (int)$l['counted'] ?></strong></td>
          <td><?php if ($l['resolution']): ?><span class="badge <?= $l['resolution'] === 'accepted' ? 'badge--green' : 'badge--grey' ?>"><?= e(INV_RESOLUTION_LABELS[$l['resolution']] ?? $l['resolution']) ?></span>
              <?php elseif ($g !== null && $g !== 0): ?><span class="badge badge--orange"><?= $g > 0 ? '+' . $g : $g ?> — to check</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

<?php elseif ($loc): $stat = inv_count_status($loc['last_counted_at'], $loc['count_every_days'] !== null ? (int)$loc['count_every_days'] : null, $today); [$sl, $sc] = INV_COUNT_STATUS_LABELS[$stat]; ?>
  <div class="card"><div class="card__body" style="padding:18px">
    <p style="margin:0 0 6px"><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
      <span class="text-muted" style="font-size:13px"><?= $loc['last_counted_at'] ? 'Last counted ' . e(date('j M', strtotime((string)$loc['last_counted_at']))) : 'Never counted' ?></span></p>
    <p class="text-muted" style="font-size:13px;margin:0 0 14px">You’ll see every item this place should have, with the number the system expects. Count each one and submit.</p>
    <form method="POST" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
      <button type="submit" class="btn-primary"><?= admin_icon('check', 16) ?> Start counting</button></form>
  </div></div>

<?php else: ?>
  <?php if (!$places): ?>
    <?php dt_empty('There’s nothing for you to count.'); ?>
  <?php else: ?>
  <div class="card"><div class="card__body" style="padding:0">
    <?php foreach ($places as $r): [$sl, $sc] = INV_COUNT_STATUS_LABELS[$r['count_status']]; ?>
    <a href="<?= $self ?>?location=<?= (int)$r['id'] ?>" class="invc-place">
      <span><strong><?= e($r['label']) ?></strong>
        <span class="inv-sub"><?= (int)$r['line_count'] ?> item<?= (int)$r['line_count'] === 1 ? '' : 's' ?><?= $r['last_counted_at'] ? ' · last ' . e(date('j M', strtotime((string)$r['last_counted_at']))) : '' ?></span></span>
      <span class="badge <?= e($sc) ?>"><?= $r['open_count_id'] ? 'In progress' : e($sl) ?></span>
    </a>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.invc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;padding-bottom:84px}
.invc-card{background:var(--white);border:2px solid var(--border);border-radius:var(--radius);padding:14px;display:grid;gap:12px;min-width:0}
.invc-card.is-off{border-color:var(--red)}
.invc-card.is-ok{border-color:var(--green)}
.invc-card__top{display:flex;gap:12px;align-items:center;min-width:0}
.invc-step{display:grid;grid-template-columns:48px minmax(0,1fr) 48px auto;gap:8px;align-items:center}
.invc-btn{height:48px;border-radius:10px;border:1px solid var(--border);background:var(--bg);font-size:24px;line-height:1;cursor:pointer}
.invc-inp{height:48px;font-size:22px;text-align:center;width:100%}
.invc-same{height:48px;padding:0 12px;border-radius:10px;border:1px solid var(--border);background:var(--white);cursor:pointer;font-weight:600;white-space:nowrap}
.invc-diff{font-size:13px;font-weight:600;color:var(--red);min-height:1em}
.invc-card.is-ok .invc-diff{color:var(--green)}
.invc-bar{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;background:var(--white);border-top:1px solid var(--border);box-shadow:var(--shadow)}
@media (min-width:900px){.invc-bar{left:var(--sidebar-w,0)}}
.invc-place{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border-top:1px solid var(--border);color:inherit;text-decoration:none}
.invc-place:first-child{border-top:0}
.invc-warn{color:var(--red);font-weight:600}
.invc-place:hover{background:var(--bg)}
</style>
<script>
(function () {
  var form = document.getElementById('invcForm'); if (!form) return;
  var key = 'invc-draft-' + form.getAttribute('data-count');
  var cards = Array.prototype.slice.call(form.querySelectorAll('.invc-card'));
  var draft = {};
  try { draft = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) { draft = {}; }
  function save() {
    var d = {};
    cards.forEach(function (c) { var i = c.querySelector('.invc-inp'); if (i.value !== '') d[i.name] = i.value; });
    try { localStorage.setItem(key, JSON.stringify(d)); } catch (e) {}
  }
  function paint(c) {
    var i = c.querySelector('.invc-inp'), exp = parseInt(c.getAttribute('data-expected'), 10), out = c.querySelector('.invc-diff');
    c.classList.remove('is-off', 'is-ok'); out.textContent = '';
    if (i.value === '') return;
    var n = parseInt(i.value, 10);
    if (isNaN(n)) return;
    if (n === exp) { c.classList.add('is-ok'); out.textContent = '✓ Matches'; }
    else { c.classList.add('is-off'); out.textContent = n < exp ? (exp - n) + ' short' : (n - exp) + ' extra'; }
  }
  function progress() {
    var done = cards.filter(function (c) { return c.querySelector('.invc-inp').value !== ''; }).length;
    document.getElementById('invcProgress').textContent = done + ' of ' + cards.length + ' counted';
  }
  cards.forEach(function (c) {
    var i = c.querySelector('.invc-inp');
    if (draft[i.name] !== undefined) i.value = draft[i.name];
    i.addEventListener('input', function () { paint(c); progress(); save(); });
    c.querySelectorAll('[data-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        var n = parseInt(i.value === '' ? c.getAttribute('data-expected') : i.value, 10) || 0;
        i.value = Math.max(0, n + parseInt(b.getAttribute('data-step'), 10));
        paint(c); progress(); save();
      });
    });
    c.querySelector('[data-same]').addEventListener('click', function () { i.value = c.getAttribute('data-expected'); paint(c); progress(); save(); });
    paint(c);
  });
  progress();
  form.addEventListener('submit', function (ev) {
    var missing = cards.filter(function (c) { return c.querySelector('.invc-inp').value === ''; });
    if (missing.length) {
      ev.preventDefault();
      missing[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
      missing[0].querySelector('.invc-inp').focus();
      var pr = document.getElementById('invcProgress');
      pr.textContent = missing.length + ' still to count — enter 0 if there are none';
      pr.classList.add('invc-warn');
      return;
    }
    try { localStorage.removeItem(key); } catch (e) {}
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Lint + commit**

Run: `php -l admin/inventory-count.php` → no syntax errors.

```bash
git add admin/inventory-count.php
git commit -m "feat(inventory): the stock count screen — phone-first cards, live mismatch, draft kept on the device

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The review queue

**Files:**
- Create: `admin/inventory-counts.php`

- [ ] **Step 1: Create the page**

```php
<?php
/**
 * Inventory → Counts — the manager's review. Every submitted count with a
 * difference, line by line: expected, counted, the gap and its value. Resolving
 * a short line as Missing / Broken / Stolen records the loss (with value); an
 * extra line as Found adds it back; Recount closes the line with no change. Only
 * the owner or a manager of that property resolves (inv_can_resolve()); the core
 * refuses while stock has moved since the count. Also lists the places due.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_login();
require_manager();

$self      = '/admin/inventory-counts.php';
$me        = current_admin();
$meId      = (int)$me['id'];
$role      = (string)($me['role'] ?? 'manager');
$vids      = admin_venue_ids();
$supported = inv_supported();
$flash     = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    try {
        $lineId = (int)($_POST['line_id'] ?? 0);
        $res    = (string)($_POST['resolution'] ?? '');
        $loc    = inv_count_line_location($lineId);
        if (!$loc || !inv_location_visible($loc, $vids) || !inv_can_resolve($loc, $role, $vids)) {
            throw new InvRefusal('Only the owner or a manager of this property can resolve it.');
        }
        $moveId = inv_count_resolve_line($lineId, $res, $meId, trim((string)($_POST['note'] ?? '')));
        $msg = 'Marked for recount — nothing changed.';
        if ($moveId !== null) {
            $m = db_query('SELECT m.qty, m.reason, m.value, m.currency, i.name FROM inv_moves m JOIN inv_items i ON i.id = m.item_id WHERE m.id = :m', [':m' => $moveId])->fetch();
            $msg = "Recorded {$m['qty']} × {$m['name']} " . mb_strtolower(INV_RESOLUTION_LABELS[$m['reason']] ?? $m['reason']) . " at {$loc['name']}"
                 . ($m['value'] !== null ? ' (' . inv_money((float)$m['value'], (string)$m['currency']) . ')' : '') . '.';
        }
        audit_log('inv.count_resolve', 'inv_location', (int)$loc['id'], "line {$lineId}: {$res}");
        $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $msg];
    } catch (InvRefusal $e) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$queue  = $supported ? inv_count_queue($vids) : [];
$sheets = array_map(fn(array $q): ?array => inv_count_sheet((int)$q['id']), $queue);
$places = $supported ? inv_counts_due($meId, $role, $vids, frontdesk_today_ymd()) : [];

$pageTitle  = 'Stock counts';
$activeMenu = 'inventory_counts';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Stock counts</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/inventory-count.php" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Count a place</a>
    <a href="/admin/inventory-locations.php" class="btn-outline btn-sm">Schedules</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>

<div class="inv-grid">
  <div class="inv-stack">
    <?php if (!$sheets): ?>
      <div class="card"><?php dt_empty('No differences waiting — every submitted count matched or has been checked.', 'check'); ?></div>
    <?php endif; ?>
    <?php foreach ($sheets as $s): if (!$s) continue;
      $canResolve = inv_can_resolve(['kind' => $s['kind'], 'venue_id' => $s['venue_id']], $role, $vids);
      $label = inv_location_label(['kind' => $s['kind'], 'name' => $s['location_name'], 'parent_name' => $s['parent_name']]); ?>
    <div class="card">
      <div class="card__head"><span class="card__title"><?= e($label) ?></span>
        <span class="text-muted" style="font-size:12.5px">Counted by <?= e($s['counted_by_name'] ?? '—') ?> · <?= e(date('j M, H:i', strtotime((string)$s['submitted_at']))) ?></span></div>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Item</th><th class="inv-num">Expected</th><th class="inv-num">Counted</th><th class="inv-num">Gap</th><th>What happened</th></tr></thead>
        <tbody>
        <?php foreach ($s['lines'] as $l): if ($l['resolution'] !== null) continue; $g = inv_count_line_gap($l); ?>
          <tr>
            <td><a href="/admin/inventory-item.php?id=<?= (int)$l['item_id'] ?>" class="inv-name"><?= inv_thumb_html($l, 28) ?><span><?= e($l['name']) ?></span></a></td>
            <td class="inv-num"><?= (int)$l['expected'] ?></td>
            <td class="inv-num"><strong><?= (int)$l['counted'] ?></strong></td>
            <td class="inv-num"><span class="badge <?= $g['gap'] < 0 ? 'badge--red' : 'badge--green' ?>"><?= $g['gap'] > 0 ? '+' . $g['gap'] : $g['gap'] ?></span>
              <div class="inv-sub"><?= e(inv_money($g['value'], (string)$l['currency'])) ?></div></td>
            <td>
              <?php if (!$canResolve): ?><span class="text-muted" style="font-size:12.5px">The owner checks this place</span>
              <?php else: ?>
              <form method="POST" action="<?= $self ?>" class="invq-form">
                <?= csrf_field() ?><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
                <input name="note" class="inp" maxlength="500" placeholder="Note (optional)">
                <div class="invq-btns">
                  <?php if ($g['gap'] < 0): foreach (['missing', 'broken', 'stolen'] as $r): ?>
                  <button type="submit" name="resolution" value="<?= $r ?>" class="btn-outline btn-sm"
                    onclick="return confirm(<?= e(json_encode('Record ' . abs($g['gap']) . ' × ' . $l['name'] . ' as ' . mb_strtolower(INV_RESOLUTION_LABELS[$r]) . ($g['value'] !== null ? ' (' . inv_money($g['value'], (string)$l['currency']) . ')' : '') . '?')) ?>)"><?= e(INV_RESOLUTION_LABELS[$r]) ?></button>
                  <?php endforeach; else: ?>
                  <button type="submit" name="resolution" value="found" class="btn-outline btn-sm">Found</button>
                  <?php endif; ?>
                  <button type="submit" name="resolution" value="recount" class="btn-outline btn-sm">Recount</button>
                </div>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="inv-stack">
    <div class="card">
      <div class="card__head"><span class="card__title">Due to count</span></div>
      <?php if (!$places): ?>
        <?php dt_empty('Nothing due. Set schedules on the Locations page.'); ?>
      <?php else: ?>
      <div class="card__body" style="padding:0">
        <?php foreach ($places as $r): [$sl, $sc] = INV_COUNT_STATUS_LABELS[$r['count_status']]; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;border-top:1px solid var(--border)">
          <span><strong><?= e($r['label']) ?></strong> <span class="badge <?= e($sc) ?>"><?= e($sl) ?></span></span>
          <a href="/admin/inventory-count.php?location=<?= (int)$r['id'] ?>" class="btn-outline btn-sm">Count</a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.invq-form{display:grid;gap:6px;min-width:220px}
.invq-form .inp{width:100%}
.invq-btns{display:flex;flex-wrap:wrap;gap:6px}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Lint + commit**

Run: `php -l admin/inventory-counts.php` → no syntax errors.

```bash
git add admin/inventory-counts.php
git commit -m "feat(inventory): Counts — manager review of count differences, and places due

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Navigation + "counts due" on My Work and the Front Desk

**Files:**
- Modify: `admin/_layout.php`, `admin/mywork.php`, `admin/frontdesk.php`

- [ ] **Step 1: Sidebar**

In `admin/_layout.php`:

a) After `$__navInventory = ($__isOwner || $__isManager) && inv_supported();   // Inventory & Assets (managers scoped to their properties)` add:

```php
$__navCount     = !$__navInventory && inv_supported() && ($__isReception || is_staff());   // the stock-count screen for staff and reception
```

b) In the Inventory group, after the Locations link (the `</a>` that closes the `inventory-locations.php` link) add:

```php
        <a href="/admin/inventory-counts.php" class="sidebar__link <?= in_array($activeMenu ?? '', ['inventory_counts', 'inventory_count'], true) ? 'is-active' : '' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          Counts
        </a>
```

c) In the Operations group, directly after the My work link block (the `<?php endif; ?>` that closes `<?php if ($__navMyWork): ?>`), add:

```php
        <?php if ($__navCount): ?>
        <a href="/admin/inventory-count.php" class="sidebar__link <?= ($activeMenu ?? '') === 'inventory_count' ? 'is-active' : '' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
          Stock count
        </a>
        <?php endif; ?>
```

- [ ] **Step 2: My Work**

In `admin/mywork.php`, add to the require block (after `task-calendar.php`):

```php
require_once __DIR__ . '/../includes/inventory-count-views.php';   // "Stock counts due" card
```

After the line `$worklist = staff_day_worklist(admin_venue_ids(), (string)$myJob, $today);` add:

```php
// Places this person should count today (responsible for it, or works at its property).
$countsCard = inv_supported() ? inv_counts_due_card(inv_countable_locations($meId, admin_role(), admin_venue_ids(), $today), 0) : '';
```

After the flash line (`<?php if ($flash): ?><div class="alert …` … `<?php endif; ?>` right below the page header) add:

```php
<?= $countsCard ?>
```

- [ ] **Step 3: Front Desk**

In `admin/frontdesk.php`, add to the require block (after `includes/checkin.php`):

```php
require_once __DIR__ . '/../includes/inventory-count-views.php';   // "Stock counts" card
```

After `$isOwner = is_owner();` add:

```php
$__me        = current_admin();
$countsCard  = inv_supported() ? inv_counts_due_card(
    inv_countable_locations((int)$__me['id'], admin_role(), admin_venue_ids(), frontdesk_today_ymd()),
    ($isOwner || is_manager()) ? inv_count_queue_size(admin_venue_ids(), true) : 0) : '';   // only what this account can resolve
```

Insert `<?= $countsCard ?>` on its own line directly before the line `<?php if ($when === 'week'): ?>` that comes right after the `fd-bar` block (the one followed by `  <?php if (!$weekRows): ?>`).

- [ ] **Step 4: Lint + tests + commit**

Run: `for f in admin/_layout.php admin/mywork.php admin/frontdesk.php; do php -l $f; done; php tests/inventory_counts_logic.php | tail -1`
Expected: no syntax errors ×3, `ALL PASS`.

```bash
git add admin/_layout.php admin/mywork.php admin/frontdesk.php
git commit -m "feat(inventory): Counts in the sidebar; counts due on My Work and the Front Desk

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The "Assets" tab on employee profiles

**Files:**
- Modify: `admin/employee.php`

- [ ] **Step 1: Requires + action handler**

Add to the require block (after `includes/hr-documents.php`):

```php
require_once __DIR__ . '/../includes/inventory-people.php';   // Assets tab (assigned items)
```

Directly before the line `// ── Documents: upload one or more / delete (owner/manager, scoped above) ──` insert:

```php
// ── Assets: assign / return / report lost (owner/manager, scoped above; every move re-checked) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['asset_assign', 'asset_return'], true)) {
    verify_csrf();
    try {
        if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
        $note = (string)($_POST['note'] ?? '');
        if ($_POST['action'] === 'asset_assign') {
            $pick = (string)($_POST['pick'] ?? '');
            if (preg_match('/^qty:(\d+):(\d+)$/', $pick, $m)) {
                $item = inv_fetch_item((int)$m[1]);
                $in   = ['action' => 'transfer', 'qty' => (string)($_POST['qty'] ?? ''), 'from_id' => $m[2], 'to' => 'staff:' . $id, 'note' => $note];
            } elseif (preg_match('/^unit:(\d+)$/', $pick, $m)) {
                $itemId = db_query('SELECT item_id FROM inv_assets WHERE id = :a', [':a' => (int)$m[1]])->fetchColumn();
                $item   = $itemId ? inv_fetch_item((int)$itemId) : false;
                $in     = ['action' => 'transfer', 'asset_id' => $m[1], 'to' => 'staff:' . $id, 'note' => $note];
            } else {
                throw new InvRefusal('Pick what to hand over.');
            }
        } else {
            $item  = inv_fetch_item((int)($_POST['item_id'] ?? 0));
            $ploc  = inv_person_location_find($id);
            if (!$ploc) throw new InvRefusal('They don’t hold anything.');
            $do    = (string)($_POST['do'] ?? '');
            $base  = ['qty' => (string)($_POST['qty'] ?? '1'), 'asset_id' => (string)($_POST['asset_id'] ?? ''), 'from_id' => (string)$ploc, 'note' => $note];
            if (preg_match('/^loc:\d+$/', $do))                        $in = $base + ['action' => 'transfer', 'to' => $do];
            elseif (preg_match('/^loss:(broken|missing|stolen)$/', $do, $m)) $in = $base + ['action' => 'loss', 'reason' => $m[1]];
            else throw new InvRefusal('Pick where it goes back to, or what happened.');
        }
        if (!$item) throw new InvRefusal('That item no longer exists.');
        $msg = inv_apply_item_action($in, $item, $vids, (int)(current_admin()['id'] ?? 0));
        audit_log('inv.' . $_POST['action'], 'hr_staff', $id, $msg);
        $_SESSION['emp_flash'] = ['type' => 'success', 'msg' => $msg];
    } catch (InvRefusal $e) {
        $_SESSION['emp_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: /admin/employee.php?id=' . $id . '#assets'); exit;
}
```

- [ ] **Step 2: Data for the tab**

After the line `$docs       = $hasDocs ? fetch_hr_staff_documents($id) : [];` add:

```php
// Assets tab: what they hold, and what this account may hand them.
$invOn       = inv_supported();
$assetsOwned = $invOn && (is_owner() || ($p['venue_id'] !== null && in_array((int)$p['venue_id'], array_map('intval', $vids ?? []), true)));
$assets      = $invOn ? inv_person_assets($id) : ['location' => null, 'rows' => []];
$assetStock  = $assetsOwned ? inv_assignable_stock($vids) : [];
$assetUnits  = $assetsOwned ? inv_assignable_units($vids) : [];
$returnTo    = $assetsOwned ? array_values(array_filter(inv_locations_visible($vids), fn($l) => $l['kind'] !== 'person')) : [];
$assetValue  = inv_sum_by_currency($assets['rows']);
```

- [ ] **Step 3: Tab button + panel**

Replace

```php
  <button type="button" class="tab-btn" data-tab="documents">Documents</button>
</nav>
```

with

```php
  <button type="button" class="tab-btn" data-tab="documents">Documents</button>
  <?php if ($invOn): ?><button type="button" class="tab-btn" data-tab="assets">Assets<?= $assets['rows'] ? ' (' . count($assets['rows']) . ')' : '' ?></button><?php endif; ?>
</nav>
```

Directly before the line `<div class="emp-stack">` insert:

```php
<?php if ($invOn): ?>
<div class="tab-panel" id="tab-assets">
  <div class="card">
    <div class="card__head" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card__title">Assigned assets</span>
      <?php if ($assetValue): ?><span class="text-muted" style="font-size:12px"><?php foreach ($assetValue as $c => $amt): ?><?= e(inv_money((float)$amt, (string)$c)) ?> <?php endforeach; ?></span><?php endif; ?>
    </div>
    <?php if (!$assets['rows']): ?>
      <div class="card__body" style="padding:18px"><p class="text-muted" style="margin:0;font-size:13px">Nothing assigned — phones, laptops, keys and tools handed to <?= e($p['full_name']) ?> show here.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="inv-num">Qty</th><th>Last given</th><th class="inv-num">Value</th><?php if ($assetsOwned): ?><th>Return / report</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($assets['rows'] as $r): ?>
        <tr>
          <td><a href="/admin/inventory-item.php?id=<?= (int)$r['item_id'] ?>" class="inv-name"><?= inv_thumb_html($r, 32) ?><span><strong><?= e($r['name']) ?></strong>
            <?php if ($r['units']): ?><span class="inv-sub"><?= e(implode(', ', array_map(fn($u) => $u['serial'] ?: 'Unit #' . $u['id'], $r['units']))) ?></span><?php endif; ?></span></a></td>
          <td class="inv-num"><?= (int)$r['qty'] ?></td>
          <td class="text-muted"><?= $r['since'] ? e(date('j M Y', strtotime((string)$r['since']))) : '—' ?></td>
          <td class="inv-num"><?= e(inv_money($r['value'], (string)$r['currency'])) ?></td>
          <?php if ($assetsOwned): ?>
          <td>
            <form method="POST" action="/admin/employee.php?id=<?= $id ?>" class="emp-asset-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="asset_return"><input type="hidden" name="hr_id" value="<?= $id ?>"><input type="hidden" name="item_id" value="<?= (int)$r['item_id'] ?>">
              <?php if ($r['tracking'] === 'serial'): ?>
              <select name="asset_id" class="eselect" aria-label="Which unit"><?php foreach ($r['units'] as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['serial'] ?: 'Unit #' . $u['id']) ?></option><?php endforeach; ?></select>
              <?php else: ?>
              <input name="qty" type="number" class="inp inp--num no-spin" min="1" max="<?= (int)$r['qty'] ?>" step="1" value="<?= (int)$r['qty'] ?>" aria-label="How many" style="width:70px">
              <?php endif; ?>
              <select name="do" class="eselect" aria-label="What happens">
                <optgroup label="Back to"><?php foreach ($returnTo as $l): ?><option value="loc:<?= (int)$l['id'] ?>"><?= e(inv_location_label($l)) ?></option><?php endforeach; ?></optgroup>
                <optgroup label="Report"><?php foreach (['broken' => 'Broken', 'missing' => 'Missing', 'stolen' => 'Stolen'] as $k => $lbl): ?><option value="loss:<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?></optgroup>
              </select>
              <button type="submit" class="btn-outline btn-sm">Save</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>

    <?php if ($assetsOwned && ($assetStock || $assetUnits)): ?>
    <div class="card__body" style="padding:16px 18px;border-top:1px solid var(--border)">
      <form method="POST" action="/admin/employee.php?id=<?= $id ?>" class="emp-asset-assign">
        <?= csrf_field() ?><input type="hidden" name="action" value="asset_assign"><input type="hidden" name="hr_id" value="<?= $id ?>">
        <div class="field"><label>Hand over</label>
          <select name="pick" class="eselect eselect--block" required>
            <?php if ($assetUnits): ?><optgroup label="By serial number"><?php foreach ($assetUnits as $u): ?><option value="unit:<?= (int)$u['id'] ?>"><?= e($u['item_name'] . ' · ' . ($u['serial'] ?: 'Unit #' . $u['id']) . ' — ' . $u['location_label']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            <?php if ($assetStock): ?><optgroup label="Counted items"><?php foreach ($assetStock as $s): ?><option value="qty:<?= (int)$s['item_id'] ?>:<?= (int)$s['location_id'] ?>"><?= e($s['item_name'] . ' — ' . $s['location_label'] . ' (' . (int)$s['qty'] . ')') ?></option><?php endforeach; ?></optgroup><?php endif; ?>
          </select></div>
        <div class="emp-asset-row">
          <div class="field"><label>How many <span class="text-muted">(counted items)</span></label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" value="1"></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500" placeholder="e.g. work phone"></div>
        </div>
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Assign</button>
      </form>
    </div>
    <?php elseif (!$assetsOwned): ?>
    <div class="card__body" style="padding:12px 18px;border-top:1px solid var(--border)"><p class="text-muted" style="margin:0;font-size:12.5px">Items are handed over and returned by the owner or a manager of <?= e($homeVenue) ?>.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

```

- [ ] **Step 4: Open the tab after a redirect + styles**

Replace

```php
  if (window.location.hash === '#documents') {
    activate('documents');
  }
```

with

```php
  if (window.location.hash === '#documents' || window.location.hash === '#assets') {
    activate(window.location.hash.slice(1));
  }
```

Before the closing `</style>` of the page's style block (the one containing `.emp-docs__uprow .filefield__name{…}`), add:

```css
.emp-asset-form{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.emp-asset-assign .eselect--block,.emp-asset-assign .inp{width:100%}
.emp-asset-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:0 12px}
@media (max-width:560px){.emp-asset-row{grid-template-columns:1fr}}
```

And add `<?= inv_shared_css() ?>` directly before `<?php include __DIR__ . '/_layout_end.php'; ?>` at the end of the file (the thumbnails/labels use it).

- [ ] **Step 5: Lint + tests + commit**

Run: `php -l admin/employee.php && php tests/inventory_counts_logic.php | tail -1`
Expected: no syntax errors, `ALL PASS`.

```bash
git add admin/employee.php
git commit -m "feat(inventory): Assets tab on employee profiles — what they hold, hand over, return or report lost

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Docs + full check

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Document**

At the end of the `### Inventory & Assets …` section add:

```markdown
- **Counts + people (Part B):** `admin/inventory-count.php` is the phone/tablet count screen (list of places → start/continue → one card per item with Expected and a big number box, red on a mismatch; the typed numbers are kept on the device until submitted). **Submitting never moves stock** — gaps go to **Inventory → Counts** (`admin/inventory-counts.php`) where the owner or a manager of that property resolves each line (Missing / Broken / Stolen record the loss with value; Found adds it back; Recount changes nothing). Rules in `includes/inventory-count-views.php`: `inv_can_count()` (owner; the place's responsible person; managers for shared Main stock/outlets; else anyone whose venues include the place's venue; never a person's location) and `inv_can_resolve()` (owner, or a manager via `inv_location_editable()` — Main stock is owner-resolved, matching the write scope). Due places (`inv_counts_due()`) show on **My Work** and the **Front Desk** (`inv_counts_due_card()`, plus "N to review" for managers). Staff and reception get a "Stock count" sidebar link. Employee profiles have an **Assets** tab (`includes/inventory-people.php`): what they hold (value, serials, since), hand over (counted stock or a serial unit) and return / report lost — all through `inv_apply_item_action()`, so managers act only for staff whose home venue is theirs. Tests: `php tests/inventory_counts_logic.php`.
```

In the File Map, after the `admin/inventory.php · …` row add:

```markdown
| `includes/inventory-count-views.php` · `includes/inventory-people.php` · `tests/inventory_counts_logic.php` | Count permissions, due places, count sheets, review queue, "due" card · a person's assets + what can be assigned · tests |
| `admin/inventory-count.php` · `admin/inventory-counts.php` | The phone count screen · manager review of count differences + places due |
```

- [ ] **Step 2: Run everything**

```bash
for f in includes/inventory-count-views.php includes/inventory-people.php admin/inventory-count.php admin/inventory-counts.php admin/_layout.php admin/mywork.php admin/frontdesk.php admin/employee.php; do php -l $f; done
for t in inventory_counts_logic inventory_views_logic inventory_logic pos_logic; do php tests/$t.php | tail -1; done
```
Expected: no syntax errors ×8; `ALL PASS` ×4.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(inventory): counts, review queue, due reminders and the Assets tab

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: Browser check (coordinator)**

On the local DB as the owner: set a place's count to weekly → Front Desk shows it under "Stock counts" → Count now → Start counting → enter one number that differs (card turns red) and the rest matching → Submit ("1 item didn't match") → Inventory → Counts shows the gap with its value → resolve as Broken (value in the flash; stock drops) → the count shows "Count checked". On an employee profile: Assets tab → hand over 1 item → it appears with value → return it to Main stock. Check the count screen at 375 px (big buttons, no horizontal scroll, sticky submit bar).
