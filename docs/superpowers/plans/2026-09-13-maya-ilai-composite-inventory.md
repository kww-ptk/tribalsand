# Maya Ilai Composite Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Maya Ilai sell eight products that slice the same eight villas, without ever selling the same bed twice.

**Architecture:** Units stay physical — 8 villas + 8 studios. `availability_blocks` gains a nullable `components TEXT[]` column recording which parts of a villa a booking consumes; `NULL` keeps its present meaning of "the whole unit", so every other property is unaffected. The eight villa units are owned by the `maya-ilai-villa` room, and the other component products resolve against those same units. `holds.unit_id` never changes.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, plain `php tests/*.php` scripts. No build system.

**Spec:** `docs/superpowers/specs/2026-09-13-maya-ilai-composite-inventory-design.md`

---

## File structure

**Create:**
- `includes/maya-ilai-inventory.php` — the component map and all pure logic: product patterns, component resolution, ring-fencing, villa ordering, Postgres array codec. No I/O, so it is testable without a database.
- `db/migrations/add_maya_ilai_components.sql` — the `components` column and the `maya_ilai_reserved_villas` setting. Additive and safe to run any time.
- `db/migrations/maya_ilai_rooms_2026.sql` — rebuilds the Maya Ilai room catalogue to 8 products and 16 units. Destructive; gated on a production hold check (Task 9).
- `tests/maya_ilai_inventory.php` — pure logic always; DB assertions inside one rolled-back transaction.

**Modify:**
- `includes/db.php` — `find_available_unit()`, `room_conflict_unit_ids()`, `create_hold_with_block()`, `get_room_blocked_dates()` each gain a Maya Ilai branch. All other venues keep the existing code path untouched.
- `admin/venue-edit.php` — the `maya_ilai_reserved_villas` field.
- `admin/gantt.php` — show component detail on a villa bar.
- `CLAUDE.md` — a Key Conventions entry.

### The one structural decision

`units.room_id` is NOT NULL, so the eight villa units must belong to exactly one room. **They belong to `maya-ilai-villa`.** The other six component products own no units and resolve against the villa room's units. This is why `find_available_unit()` and `get_room_blocked_dates()` both need a branch: their existing queries start from `units WHERE room_id = <the product>`, which would return nothing for a component product.

---

## Task 1: Migration — components column and setting

**Files:**
- Create: `db/migrations/add_maya_ilai_components.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Maya Ilai composite inventory: which components of a villa a block consumes.
-- NULL means "the whole unit", which is what every existing row means, so this
-- is a no-op for Zuri, Maya Kobe and every other property.
-- Values are a subset of {double_a, double_b, bunk, living}. Studio blocks stay NULL.
ALTER TABLE availability_blocks ADD COLUMN IF NOT EXISTS components TEXT[] NULL;

-- Villas held back for whole-villa sales only. The last N villas by sort order.
INSERT INTO settings (setting_key, setting_value)
VALUES ('maya_ilai_reserved_villas', '2')
ON CONFLICT (setting_key) DO NOTHING;
```

- [ ] **Step 2: Apply it locally and verify**

Run:
```bash
psql -d tribalsand -f db/migrations/add_maya_ilai_components.sql
psql -d tribalsand -c "\d availability_blocks" | grep components
```
Expected: a line showing `components | text[]`.

- [ ] **Step 3: Verify existing rows are untouched**

Run:
```bash
psql -d tribalsand -c "SELECT count(*) FROM availability_blocks WHERE components IS NOT NULL;"
```
Expected: `0`.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_maya_ilai_components.sql
git commit -m "feat(maya-ilai): add availability_blocks.components and the reserved-villa setting"
```

---

## Task 2: Postgres array codec

The component set round-trips as a Postgres array literal. Component names are plain identifiers (`[a-z_]+`), so no quoting or escaping is needed.

**Files:**
- Create: `includes/maya-ilai-inventory.php`
- Create: `tests/maya_ilai_inventory.php`

- [ ] **Step 1: Write the failing test**

Create `tests/maya_ilai_inventory.php`:

```php
<?php
declare(strict_types=1);
// Maya Ilai composite inventory — component resolution, ring-fencing, allocation.
// Run: php tests/maya_ilai_inventory.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-inventory.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Postgres array codec ────────────────────────────────────────────────────
check('encode: empty set',
    mi_pg_array_encode([]) === '{}');
check('encode: one component',
    mi_pg_array_encode(['bunk']) === '{bunk}');
check('encode: several keep order',
    mi_pg_array_encode(['double_a', 'living']) === '{double_a,living}');
check('decode: NULL is an empty list',
    mi_pg_array_decode(null) === []);
check('decode: empty literal',
    mi_pg_array_decode('{}') === []);
check('decode: one component',
    mi_pg_array_decode('{bunk}') === ['bunk']);
check('decode: several',
    mi_pg_array_decode('{double_a,living}') === ['double_a', 'living']);
check('decode: tolerates quotes and spaces',
    mi_pg_array_decode('{"double_a", "living"}') === ['double_a', 'living']);
check('codec: round-trips',
    mi_pg_array_decode(mi_pg_array_encode(['double_b', 'bunk', 'living']))
        === ['double_b', 'bunk', 'living']);

echo "\n" . ($failures ? "{$failures} FAILED\n" : "All passed\n");
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/maya_ilai_inventory.php`
Expected: a fatal error — `Failed opening required '.../includes/maya-ilai-inventory.php'`.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/maya-ilai-inventory.php`:

```php
<?php
declare(strict_types=1);
/**
 * Maya Ilai composite inventory — pure logic, no I/O.
 *
 * Maya Ilai sells eight products that slice the same eight villas. Each villa has
 * four components; a product consumes a subset of ONE villa's components. Studios
 * are ordinary independent units and never appear here.
 *
 * Everything in this file is a pure function so it can be tested without a
 * database. The DB-touching allocator lives in includes/db.php.
 *
 * See docs/superpowers/specs/2026-09-13-maya-ilai-composite-inventory-design.md
 */

/** The room that owns the eight villa units. Component products borrow them. */
const MAYA_ILAI_VILLA_ROOM_SLUG = 'maya-ilai-villa';

/** The two interchangeable double bedrooms in a villa, lower letter allocated first. */
const MAYA_ILAI_DOUBLES = ['double_a', 'double_b'];

/** Encode a component list as a Postgres array literal. Names are [a-z_]+ only. */
function mi_pg_array_encode(array $items): string {
    return '{' . implode(',', $items) . '}';
}

/**
 * Decode a Postgres array literal into a component list.
 *
 * NOTE: a NULL column means "the whole unit is taken", which is NOT the same as
 * an empty set. This returns [] for both, so callers MUST test the raw value for
 * null before calling this.
 */
function mi_pg_array_decode(?string $raw): array {
    if ($raw === null) return [];
    $raw = trim($raw);
    if ($raw === '' || $raw === '{}') return [];
    $inner = trim($raw, '{}');
    if ($inner === '') return [];
    $parts = array_map(static fn(string $s): string => trim($s, " \t\"'"), explode(',', $inner));
    return array_values(array_filter($parts, static fn(string $s): bool => $s !== ''));
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/maya_ilai_inventory.php`
Expected: 9 × `PASS`, then `All passed`.

- [ ] **Step 5: Commit**

```bash
git add includes/maya-ilai-inventory.php tests/maya_ilai_inventory.php
git commit -m "feat(maya-ilai): component-set Postgres array codec"
```

---

## Task 3: Product map and component resolution

This is the heart of the feature. `mi_resolve()` answers: given a product's pattern and the components already taken in one villa, which concrete components would this booking occupy — or is it impossible?

**Files:**
- Modify: `includes/maya-ilai-inventory.php`
- Modify: `tests/maya_ilai_inventory.php`

- [ ] **Step 1: Write the failing test**

Insert into `tests/maya_ilai_inventory.php`, immediately before the `echo "\n" . ($failures …` line:

```php
// ── Product map ─────────────────────────────────────────────────────────────
check('map: seven composite products',
    count(mi_product_map()) === 7);
check('map: the studio is NOT composite — it is its own unit',
    !isset(mi_product_map()['maya-ilai-studio']));
check('map: villa takes all four components',
    mi_product_map()['maya-ilai-villa'] === ['double', 'double', 'bunk', 'living']);
check('is_composite: a component product',
    mi_is_composite_room(['slug' => 'maya-ilai-double']) === true);
check('is_composite: the studio is not',
    mi_is_composite_room(['slug' => 'maya-ilai-studio']) === false);
check('is_composite: another property is not',
    mi_is_composite_room(['slug' => 'zuri-jua']) === false);

// ── Component resolution ────────────────────────────────────────────────────
check('resolve: double into an empty villa takes double_a',
    mi_resolve(['double'], []) === ['double_a']);
check('resolve: double when double_a is taken falls to double_b',
    mi_resolve(['double'], ['double_a']) === ['double_b']);
check('resolve: no doubles left',
    mi_resolve(['double'], ['double_a', 'double_b']) === null);
check('resolve: bunk is free regardless of doubles',
    mi_resolve(['bunk'], ['double_a', 'double_b']) === ['bunk']);
check('resolve: bunk already taken',
    mi_resolve(['bunk'], ['bunk']) === null);
check('resolve: villa into an empty villa',
    mi_resolve(['double', 'double', 'bunk', 'living'], [])
        === ['double_a', 'double_b', 'bunk', 'living']);
check('resolve: villa blocked by a single taken double',
    mi_resolve(['double', 'double', 'bunk', 'living'], ['double_b']) === null);
check('resolve: two-bed suite needs both doubles',
    mi_resolve(['double', 'double', 'living'], ['double_a']) === null);

// The case from the design review: a One-Bedroom Suite is sold in this villa,
// taking double_a + living. double_b and bunk must stay sellable.
$suiteSold = ['double_a', 'living'];
check('One-Bed Suite sold: a Double Room still sells',
    mi_resolve(['double'], $suiteSold) === ['double_b']);
check('One-Bed Suite sold: the Bunk Room still sells',
    mi_resolve(['bunk'], $suiteSold) === ['bunk']);
check('One-Bed Suite sold: a Family Room still sells',
    mi_resolve(['double', 'bunk'], $suiteSold) === ['double_b', 'bunk']);
check('One-Bed Suite sold: a SECOND One-Bed Suite does not',
    mi_resolve(['double', 'living'], $suiteSold) === null);
check('One-Bed Suite sold: the Family Suite does not',
    mi_resolve(['double', 'bunk', 'living'], $suiteSold) === null);
check('One-Bed Suite sold: the full villa does not',
    mi_resolve(['double', 'double', 'bunk', 'living'], $suiteSold) === null);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/maya_ilai_inventory.php`
Expected: a fatal error — `Call to undefined function mi_product_map()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/maya-ilai-inventory.php`:

```php
/**
 * Product slug => the components it consumes from ONE villa.
 *
 * 'double' is a placeholder for "either free double bedroom" and is resolved to a
 * concrete double_a/double_b by mi_resolve(). The studio is deliberately absent:
 * it is an ordinary independent unit and uses the existing availability path.
 *
 * Prices (rooms.price_amount) for reference — High season, USD:
 *   bunk-room 150 · double 350 · family-room 500 · one-bed-suite 750
 *   family-suite 900 · two-bed-suite 1100 · villa 1170
 */
function mi_product_map(): array {
    return [
        'maya-ilai-bunk-room'     => ['bunk'],
        'maya-ilai-double'        => ['double'],
        'maya-ilai-family-room'   => ['double', 'bunk'],
        'maya-ilai-one-bed-suite' => ['double', 'living'],
        'maya-ilai-family-suite'  => ['double', 'bunk', 'living'],
        'maya-ilai-two-bed-suite' => ['double', 'double', 'living'],
        'maya-ilai-villa'         => ['double', 'double', 'bunk', 'living'],
    ];
}

/** True when this room is one of the seven products that slice a villa. */
function mi_is_composite_room(array $room): bool {
    return isset(mi_product_map()[$room['slug'] ?? '']);
}

/**
 * Resolve a product's pattern against the components already taken in one villa.
 *
 * Returns the concrete component list this booking would occupy, or NULL when the
 * villa cannot accommodate it. Doubles are allocated lower letter first so
 * allocation is deterministic and reproducible when staff are debugging.
 */
function mi_resolve(array $pattern, array $taken): ?array {
    $freeDoubles = array_values(array_diff(MAYA_ILAI_DOUBLES, $taken));
    $out = [];
    foreach ($pattern as $need) {
        if ($need === 'double') {
            if (!$freeDoubles) return null;
            $out[] = array_shift($freeDoubles);
            continue;
        }
        if (in_array($need, $taken, true)) return null;
        if (in_array($need, $out, true))   return null; // a pattern asking twice for one room
        $out[] = $need;
    }
    return $out;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/maya_ilai_inventory.php`
Expected: all `PASS`, including the six "One-Bed Suite sold" assertions.

- [ ] **Step 5: Commit**

```bash
git add includes/maya-ilai-inventory.php tests/maya_ilai_inventory.php
git commit -m "feat(maya-ilai): product component map and resolution"
```

---

## Task 4: Ring-fencing and villa ordering

Pack tight so whole villas stay intact; skip reserved villas for component products; prefer reserved villas when selling the whole villa.

> **SHIPPED WITH REVISIONS — the code below is the first draft, not what is on the
> branch.** Two rounds of review changed it. The differences that matter to later
> tasks:
>
> - **`mi_order_villas()` takes no `$totalVillas`.** Its real signature is
>   `mi_order_villas(array $villas, bool $isVillaProduct, int $reserved)`; the total
>   is derived from `count($villas)`. A caller-supplied total could disagree with
>   the list it described, and when it did it failed **open** — with one villa
>   deactivated, a ring-fenced villa became sellable to component products.
>   `$villas` must therefore be the COMPLETE villa set, never a filtered subset.
> - **`mi_villa_is_reserved()` takes a 1-based `$rank`, not a raw `sort_order`.**
>   `units.sort_order` is `NOT NULL DEFAULT 0`, so an admin-added unit lands at 0
>   and the "last N by sort order" arithmetic silently shrank the ring-fence.
>   `mi_order_villas()` sorts by `sort_order` (tiebreaking on `unit_id`) and derives
>   rank from position.
> - **`mi_resolve()` fails closed.** An empty pattern returns `null`, and the
>   component vocabulary is closed to `double`/`bunk`/`living`.
> - **`mi_block_taken_components(?string): array` was added** — it owns the
>   NULL-means-whole-unit rule so no caller can get it wrong. Use it, never
>   `mi_pg_array_decode()`, when reading a stored block.
>
> Tasks 5 and 8 below already reflect the revised signatures.

**Files:**
- Modify: `includes/maya-ilai-inventory.php`
- Modify: `tests/maya_ilai_inventory.php`

- [ ] **Step 1: Write the failing test**

Insert into `tests/maya_ilai_inventory.php`, before the closing `echo`:

```php
// ── Ring-fencing ────────────────────────────────────────────────────────────
// Reserved villas are the LAST N by sort order, so changing N never reshuffles
// which villas were already reserved.
check('reserved: villa 8 of 8 with N=2',
    mi_villa_is_reserved(8, 8, 2) === true);
check('reserved: villa 7 of 8 with N=2',
    mi_villa_is_reserved(7, 8, 2) === true);
check('reserved: villa 6 of 8 with N=2',
    mi_villa_is_reserved(6, 8, 2) === false);
check('reserved: nothing reserved with N=0',
    mi_villa_is_reserved(8, 8, 0) === false);

// ── Villa ordering ──────────────────────────────────────────────────────────
$villas = [
    ['unit_id' => 101, 'sort_order' => 1, 'taken' => []],
    ['unit_id' => 102, 'sort_order' => 2, 'taken' => ['double_a']],
    ['unit_id' => 103, 'sort_order' => 3, 'taken' => ['double_a', 'bunk']],
    ['unit_id' => 104, 'sort_order' => 4, 'taken' => []],
    ['unit_id' => 105, 'sort_order' => 5, 'taken' => []],
    ['unit_id' => 106, 'sort_order' => 6, 'taken' => []],
    ['unit_id' => 107, 'sort_order' => 7, 'taken' => []],
    ['unit_id' => 108, 'sort_order' => 8, 'taken' => []],
];

$ordered = mi_order_villas($villas, false, 8, 2);
check('order: component products skip the 2 reserved villas',
    count($ordered) === 6);
check('order: reserved villas are absent',
    !in_array(107, array_column($ordered, 'unit_id'), true)
    && !in_array(108, array_column($ordered, 'unit_id'), true));
check('order: pack tight — most-occupied villa first',
    array_column($ordered, 'unit_id') === [103, 102, 101, 104, 105, 106]);

$orderedVilla = mi_order_villas($villas, true, 8, 2);
check('order: the villa product sees all 8',
    count($orderedVilla) === 8);
check('order: the villa product takes a reserved villa first',
    array_column($orderedVilla, 'unit_id')[0] === 107
    && array_column($orderedVilla, 'unit_id')[1] === 108);

$noFence = mi_order_villas($villas, false, 8, 0);
check('order: with N=0 every villa is offered',
    count($noFence) === 8);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/maya_ilai_inventory.php`
Expected: a fatal error — `Call to undefined function mi_villa_is_reserved()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/maya-ilai-inventory.php`:

```php
/**
 * Reserved villas are the LAST $reserved by sort order. Taking them from the end
 * means raising or lowering N leaves the already-reserved villas unchanged, so
 * staff keep a stable mental model of which villas are held back.
 */
function mi_villa_is_reserved(int $sortOrder, int $totalVillas, int $reserved): bool {
    if ($reserved <= 0) return false;
    return $sortOrder > ($totalVillas - $reserved);
}

/**
 * Order candidate villas for allocation.
 *
 * $villas: [['unit_id'=>int, 'sort_order'=>int, 'taken'=>string[]], …]
 *
 * Component products never see a reserved villa. The whole-villa product sees
 * every villa and prefers the reserved ones, so that consuming a villa leaves the
 * open villas available for component sales.
 *
 * Within those rules: most-occupied first (pack tight, keeping whole villas
 * intact), ties broken by villa number ascending for deterministic allocation.
 */
function mi_order_villas(array $villas, bool $isVillaProduct, int $totalVillas, int $reserved): array {
    $out = [];
    foreach ($villas as $v) {
        $isRes = mi_villa_is_reserved((int)$v['sort_order'], $totalVillas, $reserved);
        if ($isRes && !$isVillaProduct) continue;
        $v['_reserved'] = $isRes;
        $out[] = $v;
    }
    usort($out, static function (array $a, array $b) use ($isVillaProduct): int {
        if ($isVillaProduct && $a['_reserved'] !== $b['_reserved']) {
            return $a['_reserved'] ? -1 : 1;
        }
        $ca = count($a['taken']);
        $cb = count($b['taken']);
        if ($ca !== $cb) return $cb <=> $ca;
        return (int)$a['sort_order'] <=> (int)$b['sort_order'];
    });
    return $out;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/maya_ilai_inventory.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/maya-ilai-inventory.php tests/maya_ilai_inventory.php
git commit -m "feat(maya-ilai): ring-fenced villas and pack-tight ordering"
```

---

## Task 5: Villa state reader and the allocation branch

`find_available_unit()` gains a Maya Ilai branch. Note the branch must run **before** the existing `room_conflict_unit_ids()` logic, which does not apply to Maya Ilai.

**Files:**
- Modify: `includes/db.php:536-573` (`find_available_unit()`)
- Modify: `tests/maya_ilai_inventory.php`

- [ ] **Step 1: Write the failing test**

Insert into `tests/maya_ilai_inventory.php`, before the closing `echo`:

```php
// ── DB-backed allocation (rolled back) ──────────────────────────────────────
$villaRoom = db_query(
    'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
)->fetch();
$doubleRoom = db_query(
    'SELECT id FROM rooms WHERE slug = :s', [':s' => 'maya-ilai-double']
)->fetch();

if (!$villaRoom || !$doubleRoom) {
    echo "\nSKIP  Maya Ilai rooms not seeded — run db/migrations/maya_ilai_rooms_2026.sql\n";
    echo ($failures ? "{$failures} FAILED\n" : "All passed\n");
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $CI = '2099-05-01';
    $CO = '2099-05-05';

    // A clean window: nothing booked, so the villa product allocates.
    $u = find_available_unit((int)$villaRoom['id'], $CI, $CO);
    check('alloc: the villa product finds a unit in a clean window', $u !== false);
    check('alloc: it resolves all four components',
        ($u['_mi_components'] ?? []) === ['double_a', 'double_b', 'bunk', 'living']);

    // With N=2 the villa product must take a RESERVED villa first (villa 7).
    $seventh = db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 7',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    check('alloc: the villa product takes reserved villa 7 first',
        (int)$u['id'] === (int)$seventh);

    // Sell a One-Bedroom Suite in villa 1 and re-check what remains there.
    $first = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 1',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
         VALUES (:u, :df, :dt, 'booked', :c)",
        [':u' => $first, ':df' => $CI, ':dt' => $CO,
         ':c' => mi_pg_array_encode(['double_a', 'living'])]
    );

    $d = find_available_unit((int)$doubleRoom['id'], $CI, $CO);
    check('alloc: a Double Room packs into the partly-sold villa 1',
        $d !== false && (int)$d['id'] === $first);
    check('alloc: and it takes double_b',
        ($d['_mi_components'] ?? []) === ['double_b']);

    // A whole-unit block (components NULL) still means the entire villa.
    $second = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 2',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
         VALUES (:u, :df, :dt, 'booked', NULL)",
        [':u' => $second, ':df' => $CI, ':dt' => $CO]
    );
    $taken = mi_villa_states((int)$villaRoom['id'], $CI, $CO);
    check('alloc: a NULL-components block takes the whole villa',
        ($taken[$second]['taken'] ?? []) === MAYA_ILAI_ALL_COMPONENTS);

    // Dates outside the window are unaffected.
    $far = find_available_unit((int)$doubleRoom['id'], '2099-09-01', '2099-09-03');
    check('alloc: an unrelated window is unaffected', $far !== false);

    // ── The same-villa constraint ───────────────────────────────────────────
    // A Family Room needs a double AND a bunk in the SAME villa. Arrange a window
    // where a free double exists in one villa and free bunks exist in others, but
    // no single villa has both — the product must be unavailable.
    $SC = '2099-11-01';
    $SO = '2099-11-03';
    $villaUnits = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r ORDER BY sort_order',
        [':r' => $villaRoom['id']]
    )->fetchAll();
    foreach ($villaUnits as $v) {
        // Villa 1 keeps a free double but loses its bunk; every other villa keeps
        // a free bunk but loses both doubles.
        $take = ((int)$v['sort_order'] === 1)
            ? ['double_a', 'bunk', 'living']
            : ['double_a', 'double_b', 'living'];
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, :df, :dt, 'booked', :c)",
            [':u' => $v['id'], ':df' => $SC, ':dt' => $SO, ':c' => mi_pg_array_encode($take)]
        );
    }
    $famRoom = db_query("SELECT id FROM rooms WHERE slug = 'maya-ilai-family-room'")->fetch();
    check('same-villa: a Family Room cannot borrow a bunk from another villa',
        find_available_unit((int)$famRoom['id'], $SC, $SO) === false);
    check('same-villa: a plain Double Room still sells from villa 1',
        find_available_unit((int)$doubleRoom['id'], $SC, $SO) !== false);
    $bunkOnly = db_query("SELECT id FROM rooms WHERE slug = 'maya-ilai-bunk-room'")->fetch();
    check('same-villa: a plain Bunk Room still sells from another villa',
        find_available_unit((int)$bunkOnly['id'], $SC, $SO) !== false);
} finally {
    db()->rollBack();
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/maya_ilai_inventory.php`
Expected: either the `SKIP` line (if Task 9 has not run yet — that is fine, run this task's test again after Task 9), or a fatal error `Call to undefined function mi_villa_states()`.

- [ ] **Step 3: Add the components constant**

Append to `includes/maya-ilai-inventory.php`:

```php
/** Every component of a villa, in canonical order. A NULL block takes all of them. */
const MAYA_ILAI_ALL_COMPONENTS = ['double_a', 'double_b', 'bunk', 'living'];
```

- [ ] **Step 4: Write the villa state reader**

Add to `includes/db.php`, immediately above `function find_available_unit(`:

```php
/**
 * The occupancy of every Maya Ilai villa over a date range.
 *
 * Returns [unit_id => ['unit_id'=>int, 'sort_order'=>int, 'taken'=>string[]], …].
 * A block with components IS NULL means the whole villa is taken, so it
 * contributes every component — that is how pre-existing and staff-entered blocks
 * keep working unchanged.
 */
function mi_villa_states(int $villaRoomId, string $check_in, string $check_out): array {
    $units = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order',
        [':r' => $villaRoomId]
    )->fetchAll();

    $states = [];
    foreach ($units as $u) {
        $states[(int)$u['id']] = [
            'unit_id'    => (int)$u['id'],
            'sort_order' => (int)$u['sort_order'],
            'taken'      => [],
        ];
    }
    if (!$states) return [];

    $blocks = db_query(
        "SELECT ab.unit_id, ab.components::text AS components
           FROM availability_blocks ab
           JOIN units u ON u.id = ab.unit_id
          WHERE u.room_id = :r AND u.is_active = TRUE
            AND ab.date_from < :co AND ab.date_to > :ci",
        [':r' => $villaRoomId, ':ci' => $check_in, ':co' => $check_out]
    )->fetchAll();

    foreach ($blocks as $b) {
        $uid = (int)$b['unit_id'];
        if (!isset($states[$uid])) continue;
        // mi_block_taken_components() owns the NULL-means-whole-unit rule. Do NOT
        // call mi_pg_array_decode() directly here — it returns [] for NULL, which
        // reads as "nothing is taken" and would oversell the villa.
        $comp = mi_block_taken_components($b['components']);
        $states[$uid]['taken'] = array_values(array_unique(
            array_merge($states[$uid]['taken'], $comp)
        ));
    }
    return $states;
}

/**
 * Allocate a villa for a Maya Ilai composite product.
 *
 * Returns the chosen unit row with the resolved component list under
 * '_mi_components', which create_hold_with_block() writes onto the block.
 */
function mi_find_villa_unit(array $room, string $check_in, string $check_out): array|false {
    $pattern = mi_product_map()[$room['slug']] ?? null;
    if ($pattern === null) return false;

    $villaRoomId = (int) db_query(
        'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
    )->fetchColumn();
    if (!$villaRoomId) return false;

    $states = mi_villa_states($villaRoomId, $check_in, $check_out);
    if (!$states) return false;

    // mi_order_villas() derives the villa total from the list it is given, so
    // $states MUST be the complete villa set — never a pre-filtered subset.
    $reserved = max(0, (int) setting('maya_ilai_reserved_villas', '2'));
    $ordered  = mi_order_villas(
        array_values($states),
        $room['slug'] === MAYA_ILAI_VILLA_ROOM_SLUG,
        $reserved
    );

    foreach ($ordered as $villa) {
        $resolved = mi_resolve($pattern, $villa['taken']);
        if ($resolved === null) continue;
        $unit = db_query('SELECT * FROM units WHERE id = :id', [':id' => $villa['unit_id']])->fetch();
        if (!$unit) continue;
        $unit['_mi_components'] = $resolved;
        return $unit;
    }
    return false;
}
```

- [ ] **Step 5: Add the branch to `find_available_unit()`**

In `includes/db.php`, inside `find_available_unit()`, immediately after the
`if (!$room) return false;` line, insert:

```php
    // Maya Ilai sells several products over the same villas; allocation is by
    // component, not by whole unit. This must run BEFORE the is_entire_place
    // conflict logic below, which does not apply to this property.
    if (mi_is_composite_room($room)) {
        return mi_find_villa_unit($room, $check_in, $check_out);
    }
```

The `SELECT` above it must also fetch the slug. Change:

```php
    $room = db_query(
        'SELECT id, venue_id, is_entire_place FROM rooms WHERE id = :id',
        [':id' => $room_id]
    )->fetch();
```

to:

```php
    $room = db_query(
        'SELECT id, slug, venue_id, is_entire_place FROM rooms WHERE id = :id',
        [':id' => $room_id]
    )->fetch();
```

- [ ] **Step 6: Require the inventory file**

Near the other `require_once` lines at the top of `includes/db.php`, add:

```php
require_once __DIR__ . '/maya-ilai-inventory.php';
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php tests/maya_ilai_inventory.php`
Expected: all `PASS`, or the `SKIP` line if Task 9 has not run. **If it SKIPs, return to this step after Task 9 and confirm it passes.**

- [ ] **Step 8: Commit**

```bash
git add includes/db.php includes/maya-ilai-inventory.php tests/maya_ilai_inventory.php
git commit -m "feat(maya-ilai): component-aware unit allocation"
```

---

## Task 6: Keep the venue-wide buyout rule off Maya Ilai

`room_conflict_unit_ids()` implements the Zuri/Maya Kobe rule: an `is_entire_place` room blocks every other unit in the venue. Maya Ilai's exclusion is per-villa, so this rule must not fire there — otherwise one villa booking would block the whole property.

**Files:**
- Modify: `includes/db.php:518-534` (`room_conflict_unit_ids()`)
- Modify: `tests/maya_ilai_inventory.php`

- [ ] **Step 1: Write the failing test**

Insert into the `try { … }` block in `tests/maya_ilai_inventory.php`, before the closing `} finally {`:

```php
    // Maya Ilai must NOT use the venue-wide is_entire_place exclusion.
    $villaRoomRow = db_query(
        'SELECT id, slug, venue_id, is_entire_place FROM rooms WHERE slug = :s',
        [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
    )->fetch();
    check('conflict: Maya Ilai has no venue-wide conflict units',
        room_conflict_unit_ids($villaRoomRow) === []);

    // Zuri keeps the old behaviour.
    $zuriEntire = db_query(
        "SELECT id, slug, venue_id, is_entire_place FROM rooms
          WHERE is_entire_place = TRUE AND venue_id = (SELECT id FROM venues WHERE slug = 'zuri')"
    )->fetch();
    if ($zuriEntire) {
        check('conflict: Zuri still blocks its sibling units',
            count(room_conflict_unit_ids($zuriEntire)) > 0);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/maya_ilai_inventory.php`
Expected: `FAIL  conflict: Maya Ilai has no venue-wide conflict units` — the villa room currently returns sibling unit IDs.

- [ ] **Step 3: Write the minimal implementation**

In `includes/db.php`, at the top of `room_conflict_unit_ids()`, immediately after
`$venue_id = $room['venue_id'] ?? null;` and its `if (!$venue_id) return [];`, insert:

```php
    // Maya Ilai's exclusion is per-villa and handled by mi_find_villa_unit();
    // the venue-wide buyout rule would block the whole property off one booking.
    if (mi_is_composite_room($room)) return [];
```

For this to work the callers must pass the slug. `find_available_unit()` already
does after Task 5. Also update the `SELECT` in `get_room_blocked_dates()`:

```php
    $room = db_query(
        'SELECT id, venue_id, is_entire_place FROM rooms WHERE id = :id',
        [':id' => $room_id]
    )->fetch();
```

to:

```php
    $room = db_query(
        'SELECT id, slug, venue_id, is_entire_place FROM rooms WHERE id = :id',
        [':id' => $room_id]
    )->fetch();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/maya_ilai_inventory.php`
Expected: all `PASS`, including the Zuri no-regression check.

- [ ] **Step 5: Commit**

```bash
git add includes/db.php tests/maya_ilai_inventory.php
git commit -m "fix(maya-ilai): exempt composite products from the venue-wide buyout rule"
```

---

## Task 7: Write the components onto the block

The allocation is useless unless the booking records it. `create_hold_with_block()` gains an optional component list.

**Files:**
- Modify: `includes/db.php:582-632` (`create_hold_with_block()`)
- Modify: `api/submit-enquiry.php:172`
- Modify: `admin/hold-new.php:47`
- Modify: `admin/submission-view.php:141`
- Modify: `tests/maya_ilai_inventory.php`

- [ ] **Step 1: Write the failing test**

Insert into the `try { … }` block in `tests/maya_ilai_inventory.php`, before `} finally {`:

```php
    // A hold on a composite product must record its components on the block.
    $third = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 3',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    $hid = create_hold_with_block(
        $third, null, '2099-07-01', '2099-07-04',
        'Component Test', 'test@example.com', 'pending', 24,
        ['double_a', 'living']
    );
    $written = db_query(
        'SELECT components::text AS c FROM availability_blocks WHERE hold_id = :h',
        [':h' => $hid]
    )->fetchColumn();
    check('hold: components are written onto the block',
        mi_pg_array_decode($written) === ['double_a', 'living']);

    // Omitting them keeps the old meaning: the whole unit.
    $fourth = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 4',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    $hid2 = create_hold_with_block(
        $fourth, null, '2099-07-01', '2099-07-04',
        'Whole Unit Test', 'test@example.com', 'pending', 24
    );
    $written2 = db_query(
        'SELECT components FROM availability_blocks WHERE hold_id = :h',
        [':h' => $hid2]
    )->fetchColumn();
    check('hold: omitting components leaves NULL — the whole unit',
        $written2 === null);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/maya_ilai_inventory.php`
Expected: `FAIL  hold: components are written onto the block` — the extra argument is ignored and the column stays NULL.

- [ ] **Step 3: Add the parameter**

In `includes/db.php`, change the signature of `create_hold_with_block()` from:

```php
function create_hold_with_block(
    int $unit_id, ?int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email,
    string $status = 'pending',
    ?int $expiresInHours = 24
): int {
```

to:

```php
function create_hold_with_block(
    int $unit_id, ?int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email,
    string $status = 'pending',
    ?int $expiresInHours = 24,
    ?array $components = null
): int {
```

Then change the block insert at the end of the function from:

```php
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, hold_id)
         VALUES (:unit, :df, :dt, :bt, :hold)",
        [':unit' => $unit_id, ':df' => $check_in, ':dt' => $check_out,
         ':bt' => $confirmed ? 'booked' : 'hold', ':hold' => $hold_id]
    );
```

to:

```php
    // NULL components means "the whole unit", which is what every non-Maya-Ilai
    // booking means and what every pre-existing row already says.
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, hold_id, components)
         VALUES (:unit, :df, :dt, :bt, :hold, :comp)",
        [':unit' => $unit_id, ':df' => $check_in, ':dt' => $check_out,
         ':bt' => $confirmed ? 'booked' : 'hold', ':hold' => $hold_id,
         ':comp' => $components === null ? null : mi_pg_array_encode($components)]
    );
```

- [ ] **Step 4: Pass the components from the guest booking path**

Only `api/submit-enquiry.php` has the allocated unit row in scope — it is the
return value of `find_available_unit()`, so it carries `_mi_components`.

`api/submit-enquiry.php:172` — change:

```php
        $hold_id = create_hold_with_block($unit['id'], $id, $checkin, $checkout, $name, $email);
```

to:

```php
        $hold_id = create_hold_with_block($unit['id'], $id, $checkin, $checkout, $name, $email,
            'pending', 24, $unit['_mi_components'] ?? null);
```

- [ ] **Step 5: Leave the two admin paths alone — deliberately**

`admin/hold-new.php:47` and `admin/submission-view.php:141` have only `$unit_id`;
staff pick a unit from a dropdown, so there is no allocation and no component set.
Both keep passing nothing, which leaves `components` NULL — **the whole villa**.

This is the safe default (a NULL set blocks everything, so nothing can be
oversold), but it is a real limitation: **staff cannot record a single-bedroom
Maya Ilai booking from the admin.** Add this comment above each call so the next
reader knows it is a decision, not an oversight:

```php
            // Maya Ilai: staff-entered bookings take the WHOLE villa (components
            // NULL). Safe — nothing can be oversold — but a per-bedroom admin
            // booking needs a component picker on this form first.
```

Do not attempt the picker in this task; it is listed under "Known follow-ups".

- [ ] **Step 6: Run the test to verify it passes**

Run: `php tests/maya_ilai_inventory.php`
Expected: all `PASS`.

- [ ] **Step 7: Verify no other caller broke**

Run: `grep -rn "create_hold_with_block" api/ admin/ includes/ bin/`
Expected: exactly four sites — the definition in `includes/db.php`, the three
callers above — plus the comment reference in `includes/bookings.php`. Every call
still passes at most the original eight arguments, so the new ninth parameter is
backwards compatible.

- [ ] **Step 8: Commit**

```bash
git add includes/db.php api/submit-enquiry.php admin/hold-new.php admin/submission-view.php tests/maya_ilai_inventory.php
git commit -m "feat(maya-ilai): record consumed components when a hold is created"
```

---

## Task 8: Guest calendar blocked dates

`get_room_blocked_dates()` starts with `SELECT COUNT(*) FROM units WHERE room_id = <product>` and returns `[]` when that is zero. Six of the seven composite products own no units, so without a branch the public calendar would show every date as free.

**Files:**
- Modify: `includes/db.php:637-…` (`get_room_blocked_dates()`)
- Modify: `tests/maya_ilai_inventory.php`

- [ ] **Step 1: Write the failing test**

Insert into the `try { … }` block in `tests/maya_ilai_inventory.php`, before `} finally {`:

```php
    // Fill every non-reserved villa's doubles over one night, so a Double Room
    // cannot be sold on that date and the calendar must say so.
    $BD = '2099-08-10';
    $BE = '2099-08-11';
    $allVillas = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r ORDER BY sort_order',
        [':r' => $villaRoom['id']]
    )->fetchAll();
    foreach ($allVillas as $v) {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, :df, :dt, 'booked', :c)",
            [':u' => $v['id'], ':df' => $BD, ':dt' => $BE,
             ':c' => mi_pg_array_encode(['double_a', 'double_b'])]
        );
    }
    $blocked = get_room_blocked_dates((int)$doubleRoom['id'], '2099-08-01', '2099-08-20');
    check('calendar: a Double Room is blocked when every villa has both doubles sold',
        in_array($BD, $blocked, true));
    check('calendar: neighbouring dates stay open',
        !in_array('2099-08-12', $blocked, true));

    // The Bunk Room is untouched by doubles being sold.
    $bunkRoom = db_query("SELECT id FROM rooms WHERE slug = 'maya-ilai-bunk-room'")->fetch();
    if ($bunkRoom) {
        $bunkBlocked = get_room_blocked_dates((int)$bunkRoom['id'], '2099-08-01', '2099-08-20');
        check('calendar: the Bunk Room is unaffected by sold doubles',
            !in_array($BD, $bunkBlocked, true));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/maya_ilai_inventory.php`
Expected: `FAIL  calendar: a Double Room is blocked when every villa has both doubles sold` — the function returns `[]` for a unitless product.

- [ ] **Step 3: Write the minimal implementation**

In `includes/db.php`, at the very top of `get_room_blocked_dates()` — before the
`$unit_count` query — insert:

```php
    // Maya Ilai composite products own no units of their own; a date is blocked
    // when no villa can satisfy the product's component pattern that night.
    $miRoom = db_query('SELECT id, slug FROM rooms WHERE id = :id', [':id' => $room_id])->fetch();
    if ($miRoom && mi_is_composite_room($miRoom)) {
        return mi_blocked_dates($miRoom, $from, $to);
    }
```

Then add this function immediately above `get_room_blocked_dates()`:

```php
/**
 * Dates on which no villa can satisfy a Maya Ilai product's component pattern.
 *
 * Resolved one night at a time: availability is a per-night question, and a stay
 * is sellable only when every night of it is.
 */
function mi_blocked_dates(array $room, string $from, string $to): array {
    $pattern = mi_product_map()[$room['slug']] ?? null;
    if ($pattern === null) return [];

    $villaRoomId = (int) db_query(
        'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
    )->fetchColumn();
    if (!$villaRoomId) return [];

    $reserved = max(0, (int) setting('maya_ilai_reserved_villas', '2'));
    $isVilla  = $room['slug'] === MAYA_ILAI_VILLA_ROOM_SLUG;

    $blocked = [];
    $d   = new DateTime($from);
    $end = new DateTime($to);
    while ($d < $end) {
        $night = $d->format('Y-m-d');
        $next  = (clone $d)->modify('+1 day')->format('Y-m-d');

        $states  = mi_villa_states($villaRoomId, $night, $next);
        $ordered = mi_order_villas(array_values($states), $isVilla, $reserved);

        $fits = false;
        foreach ($ordered as $villa) {
            if (mi_resolve($pattern, $villa['taken']) !== null) { $fits = true; break; }
        }
        if (!$fits) $blocked[] = $night;

        $d->modify('+1 day');
    }
    return $blocked;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/maya_ilai_inventory.php`
Expected: all `PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/db.php tests/maya_ilai_inventory.php
git commit -m "feat(maya-ilai): component-aware blocked dates for the guest calendar"
```

---

## Task 9: Rebuild the room catalogue

Eight products replace the current three. **This migration is destructive**, so it checks for live holds first and refuses to run if any exist.

**Files:**
- Create: `db/migrations/maya_ilai_rooms_2026.sql`

- [ ] **Step 1: Check production for live holds — DO NOT SKIP**

The local database is a separate Postgres and is **not** evidence about production.
Before this runs anywhere near live data, run against the production database:

```sql
SELECT rm.slug, h.id, h.status, h.check_in, h.check_out
  FROM holds h
  JOIN units u  ON u.id = h.unit_id
  JOIN rooms rm ON rm.id = u.room_id
  JOIN venues v ON v.id = rm.venue_id
 WHERE v.slug = 'maya_ilai' AND h.status IN ('pending', 'confirmed');
```

If this returns any rows, **stop** and report them. Those bookings must be
re-pointed at the new units by hand before the old rooms are deleted.

- [ ] **Step 2: Write the migration**

Create `db/migrations/maya_ilai_rooms_2026.sql`:

```sql
-- Maya Ilai — rebuild the room catalogue as eight products over shared villas.
--
-- Physical inventory: 8 villas x (2 double + 1 bunk + 1 living) + 8 studios.
-- The eight villa UNITS belong to maya-ilai-villa; the six other composite
-- products own no units and resolve against those same villas via
-- includes/maya-ilai-inventory.php. The studio is an ordinary independent room.
--
-- Prices are rooms.price_amount = the HIGH-season figure from the internal rate
-- tool. Dated seasonal ranges are a separate follow-on migration.
--
-- capacity = MAXIMUM guests, because that is what the guest-count filter in
-- ts_search_availability() compares against.
--
-- DESTRUCTIVE: deletes the previous Maya Ilai rooms. Check for live holds first
-- (see the plan, Task 9 Step 1).

BEGIN;

-- Ordering gate. Without availability_blocks.components every composite product
-- would fall back to whole-unit blocking and silently oversell, so this fails
-- loudly rather than degrading. Run add_maya_ilai_components.sql first.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'availability_blocks' AND column_name = 'components'
  ) THEN
    RAISE EXCEPTION
      'availability_blocks.components is missing - run db/migrations/add_maya_ilai_components.sql first';
  END IF;
END $$;

DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug = 'maya_ilai');

INSERT INTO rooms (slug, name, venue_id, capacity, bed_count, short_desc,
                   price_amount, price_currency, form_mode, is_entire_place,
                   is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.beds, x.descr, x.price, 'USD',
       'availability', FALSE, TRUE, x.so
FROM venues v, (VALUES
  ('maya-ilai-bunk-room',     'Private Bunk Room',            6,  3,
   'A private room with three bunk beds and six individual beds. Includes 3 guests.', 150, 1),
  ('maya-ilai-double',        'Double Room',                  2,  1,
   'A double bedroom overlooking the pool.', 350, 2),
  ('maya-ilai-studio',        'Studio with Kitchenette',      2,  1,
   'A separate studio within the complex, with a small kitchenette.', 390, 3),
  ('maya-ilai-family-room',   'Two-Bedroom Family Room',      8,  2,
   'A double bedroom and a private bunk room. Includes 5 guests.', 500, 4),
  ('maya-ilai-one-bed-suite', 'One-Bedroom Suite with Kitchen', 2, 1,
   'A double bedroom with a separate living area and kitchen.', 750, 5),
  ('maya-ilai-family-suite',  'Two-Bedroom Family Suite with Kitchen', 8, 2,
   'A double bedroom, bunk room, living area and kitchen. Includes 5 guests.', 900, 6),
  ('maya-ilai-two-bed-suite', 'Two-Bedroom Suite with Kitchen', 4, 2,
   'Two double bedrooms, a living area and kitchen.', 1100, 7),
  ('maya-ilai-villa',         'Three-Bedroom Villa',          10, 3,
   'An entire villa: two double bedrooms, a bunk room, living area and kitchen. Includes 7 guests.', 1170, 8)
) AS x(slug, name, cap, beds, descr, price, so)
WHERE v.slug = 'maya_ilai';

-- The eight physical villas. Every composite product allocates against these.
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Villa ' || g, g
  FROM rooms r, generate_series(1, 8) g
 WHERE r.slug = 'maya-ilai-villa';

-- The eight studios are ordinary independent units.
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Studio ' || g, g
  FROM rooms r, generate_series(1, 8) g
 WHERE r.slug = 'maya-ilai-studio';

COMMIT;
```

- [ ] **Step 3: Apply locally and verify the room set**

Run:
```bash
psql -d tribalsand -f db/migrations/maya_ilai_rooms_2026.sql
psql -d tribalsand -c "SELECT r.slug, r.capacity, r.price_amount, count(u.id) AS units FROM rooms r JOIN venues v ON v.id=r.venue_id LEFT JOIN units u ON u.room_id=r.id WHERE v.slug='maya_ilai' GROUP BY r.slug, r.capacity, r.price_amount, r.sort_order ORDER BY r.sort_order;"
```
Expected: 8 rows. `maya-ilai-villa` and `maya-ilai-studio` show `units = 8`; the other six show `units = 0`.

- [ ] **Step 4: Run the full test suite**

Run: `php tests/maya_ilai_inventory.php`
Expected: every assertion `PASS` — the DB-backed tasks no longer `SKIP`.

- [ ] **Step 5: Re-run the earlier tasks' DB assertions**

If Task 5's test printed `SKIP` when you first ran it, confirm it passes now.

Run: `php tests/maya_ilai_inventory.php | grep -c PASS`
Expected: a count matching the number of `check()` calls in the file, with no `SKIP` line.

- [ ] **Step 6: Commit**

```bash
git add db/migrations/maya_ilai_rooms_2026.sql
git commit -m "feat(maya-ilai): rebuild the room catalogue as eight products over shared villas"
```

---

## Task 10: Admin setting for the reserved villa count

**Files:**
- Modify: `admin/venue-edit.php`

- [ ] **Step 1: Add the field to the Details form**

The deposit block at `admin/venue-edit.php:351-360` is the pattern: a `.form-row`
wrapping a `.field` with a `<label>`, an input, and a `.field-hint`. Add this
**after** the closing `</div>` of the deposit `.form-row`:

```php
        <?php if (($venue['slug'] ?? '') === 'maya_ilai'): ?>
        <div class="form-row">
          <div class="field">
            <label>Villas reserved for whole-villa sales <span class="text-muted">(of 8)</span></label>
            <input type="number" name="maya_ilai_reserved_villas" min="0" max="8" step="1"
                   value="<?= e(setting('maya_ilai_reserved_villas', '2')) ?>">
            <span class="field-hint">Held back from room-by-room bookings so a group can always book a whole villa. These are the last N villas by number, so changing this never reshuffles which villas were already reserved.</span>
          </div>
        </div>
        <?php endif; ?>
```

- [ ] **Step 2: Save it in the `save_details` handler**

`admin/venue-edit.php:92` opens `if ($action === 'save_details') {`. Add this
inside that branch, after the deposit currency lines (around line 104):

```php
    if (($venue['slug'] ?? '') === 'maya_ilai' && isset($_POST['maya_ilai_reserved_villas'])) {
        $n = max(0, min(8, (int)$_POST['maya_ilai_reserved_villas']));
        db_query(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('maya_ilai_reserved_villas', :v)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
            [':v' => (string)$n]
        );
    }
```

Clamping to 0–8 server-side matters: the field is a number input, and a posted
value above 8 would ring-fence every villa and stop all component sales.

- [ ] **Step 4: Verify in the browser**

Start the dev server and open Admin → Properties → Maya Ilai → Edit → Details.
Set the field to 3, save, then confirm:

```bash
psql -d tribalsand -c "SELECT setting_value FROM settings WHERE setting_key='maya_ilai_reserved_villas';"
```
Expected: `3`. Set it back to `2` afterwards.

- [ ] **Step 5: Commit**

```bash
git add admin/venue-edit.php
git commit -m "feat(maya-ilai): reserved-villa count is editable in Admin → Properties"
```

---

## Task 11: Show components on the Gantt

Staff need to see *which part* of a villa a bar represents, or the calendar reads as though a villa is fully booked when only one bedroom is sold.

**Files:**
- Modify: `admin/gantt.php`

- [ ] **Step 1: Confirm no query change is needed**

`admin/gantt.php:194-200` already selects `ab.*`, which includes the new column.
PDO's pgsql driver returns a `text[]` as its raw literal (`{double_a,living}`), and
`mi_pg_array_decode()` parses exactly that. A NULL column stays PHP `null`.

Run: `sed -n '194,200p' admin/gantt.php`
Expected: a `SELECT ab.*, u.room_id` query. If it ever becomes an explicit column
list, add `ab.components` to it.

- [ ] **Step 2: Build the component label**

`admin/gantt.php:487` currently reads:

```php
        $label     = $b['notes'] ?: $b['block_type'];
```

Replace it with:

```php
        // Maya Ilai: a block may consume only part of a villa. NULL means the
        // whole unit, which is what every block at every other property means.
        $miLabel = '';
        if (($b['components'] ?? null) !== null) {
            $names = ['double_a' => 'Double A', 'double_b' => 'Double B',
                      'bunk' => 'Bunk', 'living' => 'Living'];
            $parts = array_map(
                static fn(string $c): string => $names[$c] ?? $c,
                mi_pg_array_decode($b['components'])
            );
            if ($parts) $miLabel = ' · ' . implode(' + ', $parts);
        }
        $label     = ($b['notes'] ?: $b['block_type']) . $miLabel;
```

- [ ] **Step 3: Add it to the hover card**

`admin/gantt.php:497` builds the card payload starting `'type' => ucfirst(...)`.
Add one entry immediately after the `'unit'` line:

```php
            'rooms'  => $miLabel === '' ? null : ltrim($miLabel, ' ·'),
```

The card renders only fields that carry a value (see the comment at `gantt.php:490`),
so a `null` here is omitted and every other property's card is unchanged.

- [ ] **Step 4: Verify in the browser**

Create a component hold locally, then open Admin → Gantt for Maya Ilai and confirm
the villa bar reads e.g. `… · Double A + Living`, while a Zuri bar is unchanged.

- [ ] **Step 5: Commit**

```bash
git add admin/gantt.php
git commit -m "feat(maya-ilai): show consumed components on Gantt bars"
```

---

## Task 12: Document the convention

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a Key Conventions entry**

Add after the "Nightly rates" section:

```markdown
### Maya Ilai — composite inventory (components on the block)
Maya Ilai sells **eight products over eight shared villas** (7 composite + the studio). Physical inventory is
8 villas x (2 double + 1 bunk + 1 living) + 8 studios. The eight villa **units**
belong to `maya-ilai-villa`; the other six composite products own **no units** and
allocate against those same villas. Migrations, in order:
`add_maya_ilai_components` -> `maya_ilai_rooms_2026`.
- **`availability_blocks.components` is NULL = the whole unit.** That is what every
  row at every other property means, so the column is a no-op outside Maya Ilai.
  **Never** backfill it with `{}` — an empty set and NULL would then be
  indistinguishable, and NULL is what makes a staff-entered block still take a
  whole villa. `mi_pg_array_decode()` returns `[]` for both; callers MUST test the
  raw value for `null` first.
- **One component map, in PHP.** `mi_product_map()` in `includes/maya-ilai-inventory.php`
  is a fixed physical fact about the building, kept in code so it is testable
  without a DB. Everything in that file is pure; the DB-touching allocator
  (`mi_villa_states()` / `mi_find_villa_unit()` / `mi_blocked_dates()`) lives in
  `includes/db.php`.
- **`room_conflict_unit_ids()` must return `[]` for Maya Ilai.** Its venue-wide
  `is_entire_place` rule is Zuri/Maya Kobe's; applying it here would block the
  whole property off one villa booking.
- **Four call sites branch, nothing else does:** `find_available_unit()`,
  `room_conflict_unit_ids()`, `create_hold_with_block()` (writes the set) and
  `get_room_blocked_dates()`. `holds.unit_id` stays singular, so the Gantt, the
  iCal importer, `bookings.block_id`, check-in and the guest portal are untouched.
- **Allocation is deterministic:** pack tight (most-occupied villa first), ties by
  villa number ascending, doubles allocated `double_a` before `double_b`. The last
  N villas (`maya_ilai_reserved_villas`, default 2, Admin -> Properties) never take
  component bookings, and the whole-villa product prefers them first.
- Test: `php tests/maya_ilai_inventory.php`.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(maya-ilai): document the composite inventory convention"
```

---

## Verification

- [ ] **Full suite passes**

```bash
php tests/maya_ilai_inventory.php
```

- [ ] **No regressions in the neighbouring suites**

```bash
php tests/rates_logic.php
php tests/booking_import_logic.php
php tests/team_logic.php
```

Expected: the same results as before this work. `booking_import_logic.php` has one
known pre-existing failure on a database with no `zuri-buyout` room — see
`CLAUDE.md`. Anything else is a regression.

- [ ] **The guest booking widget quotes correctly**

Open a Maya Ilai product on the live site locally, request dates, and confirm the
quoted total equals `price_amount x nights`. The acceptance bar from the rates work
applies here too: one pricing path, no second nightly loop.

## Known follow-ups

Surfaced while writing this plan. None blocks the work; all are worth recording.

1. **Staff cannot book a single bedroom from the admin.** `admin/hold-new.php` and
   `admin/submission-view.php` take a unit from a dropdown, not from an allocation,
   so a staff-entered Maya Ilai hold takes the whole villa (Task 7 Step 5). Safe,
   but it means a phone booking for one Double Room idles a villa until someone
   adds a component picker to those forms.
2. **The iCal importer and `admin/gantt.php`'s manual block form write NULL
   components**, i.e. whole villas. Correct and safe, and the right default for an
   OTA feed that knows nothing about components — noted so it is not mistaken for
   a bug.
3. **Availability-band pricing is now possible.** The bands in the rate tool price
   by units remaining, which this engine is the first thing to know accurately.
   That makes them a plausible next project rather than a permanent exclusion.

## Not in this plan

Per the spec, these have no home in the `rates` schema and stay in the offline
quote tool: the $45 extra-guest supplement, the $20pp eco fee, the -15%
single-occupancy discount, the availability bands, and the group tiers.

Seasonal rate rows are the follow-on project. The agreed mapping is High = Maya
Kobe's Peak windows only (32 nights), Standard = Mid + Standard (333 nights).
