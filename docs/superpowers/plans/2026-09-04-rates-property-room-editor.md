# Rate Overrides — Property/Room Editors + Rates Calendar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move nightly rate overrides off the site-wide Gantt page onto the property and room pages, let one submission carry several date ranges, and add a read-only month calendar showing what each night actually costs.

**Architecture:** A new `includes/rates.php` owns all rate logic. Its `rates_nightly_map()` becomes the single resolver for a night's price, and `room_stay_quote()` is refactored to sum it, so the calendar and the guest quote cannot drift. Writes go through `rates_apply_ranges()`, which merges the submitted ranges then trims, splits or deletes any existing row overlapping them, leaving every night owned by exactly one row. Two partials (`rate-form.php`, `rate-calendar.php`) are reused by a Rates tab on the property page, a Rates tab on the room page, and a new read-only `admin/rates.php`.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO with `db_query()`, vanilla JS/CSS. Shared `js/datepicker.js` (already loaded on every admin page by `admin/_layout.php`) supplies the range picker. Tests are plain PHP scripts run with the `php` CLI.

**Spec:** `docs/superpowers/specs/2026-09-04-rates-property-room-editor-design.md`

---

## File Structure

| File | Responsibility |
|---|---|
| `includes/rates.php` | **Create.** All rate logic: range merging, nightly resolution, trim/split writes, listing, deletion. |
| `includes/db.php` | **Modify** `room_stay_quote()` (~line 809) to sum `rates_nightly_map()`. |
| `includes/rate-form.php` | **Create.** Repeatable date-range entry form partial. |
| `includes/rate-calendar.php` | **Create.** Read-only month grid partial. |
| `admin/venue-edit.php` | **Modify.** New Rates tab + `rates_save` / `rate_delete` POST handlers. |
| `admin/room-edit.php` | **Modify.** New Rates tab + the same two handlers, room fixed. |
| `admin/rates.php` | **Create.** Site-wide read-only rates page. |
| `admin/_layout.php` | **Modify.** "Rates" link in the Bookings sidebar group. |
| `admin/gantt.php` | **Modify.** Delete the Price Overrides form, table, handlers and its two rate datepickers. |
| `tests/rates_logic.php` | **Create.** Pure-logic + DB assertions in a rolled-back transaction. |
| `CLAUDE.md` | **Modify.** New conventions section. |

**Run tests with:** `php tests/rates_logic.php` — expected final line `ALL PASS`, exit 0.

---

## Task 1: Range merging (pure logic)

**Files:**
- Create: `includes/rates.php`
- Create: `tests/rates_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/rates_logic.php`:

```php
<?php
declare(strict_types=1);
// Nightly rate overrides — range merging, resolution, trim/split writes, scope.
// Run: php tests/rates_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end, so no
// real rate rows are ever left behind.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic (no DB) ──────────────────────────────────────────────────────
check('merge: drops from >= to',
    rates_merge_ranges([['2099-01-05', '2099-01-05']]) === []);
check('merge: drops blank rows',
    rates_merge_ranges([['', '2099-01-05'], ['2099-01-01', '']]) === []);
check('merge: sorts by start',
    rates_merge_ranges([['2099-02-01', '2099-02-05'], ['2099-01-01', '2099-01-05']])
        === [['2099-01-01', '2099-01-05'], ['2099-02-01', '2099-02-05']]);
check('merge: joins overlapping',
    rates_merge_ranges([['2099-01-01', '2099-01-10'], ['2099-01-05', '2099-01-20']])
        === [['2099-01-01', '2099-01-20']]);
check('merge: joins abutting',
    rates_merge_ranges([['2099-01-01', '2099-01-10'], ['2099-01-10', '2099-01-20']])
        === [['2099-01-01', '2099-01-20']]);
check('merge: keeps a contained range inside its parent',
    rates_merge_ranges([['2099-01-01', '2099-01-30'], ['2099-01-05', '2099-01-10']])
        === [['2099-01-01', '2099-01-30']]);
check('merge: leaves disjoint ranges alone',
    rates_merge_ranges([['2099-01-01', '2099-01-05'], ['2099-03-01', '2099-03-05']])
        === [['2099-01-01', '2099-01-05'], ['2099-03-01', '2099-03-05']]);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/rates_logic.php`
Expected: a PHP fatal — `Failed opening required '.../includes/rates.php'`.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/rates.php`:

```php
<?php
/**
 * Nightly rate overrides (`rates`), stored per ROOM (not per unit).
 *
 * Data model (no migration — the table predates this file):
 *   rates(id, room_id, date_from, date_to, price_amount, label, created_at)
 *
 * date_to is EXCLUSIVE — it is the checkout morning, not the last night. The
 * forms label it "To (last night)" and add a day before storing; never change
 * that without repricing every stored override.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Normalise a list of [from, toExcl] ranges: drop invalid ones, sort by start,
 * and merge any that overlap or abut. Pure — no DB.
 *
 * Ranges submitted together share one price and one label, so merging is
 * lossless. It also stops two ranges in the same submission from trimming each
 * other in rates_apply_ranges().
 *
 * Dates are 'Y-m-d', so string comparison is chronological comparison.
 */
function rates_merge_ranges(array $ranges): array {
    $clean = [];
    foreach ($ranges as $r) {
        $from = trim((string)($r[0] ?? ''));
        $to   = trim((string)($r[1] ?? ''));
        if ($from === '' || $to === '' || $from >= $to) continue;
        $clean[] = [$from, $to];
    }
    if (!$clean) return [];

    usort($clean, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

    $out = [array_shift($clean)];
    foreach ($clean as [$from, $to]) {
        $i = count($out) - 1;
        if ($from <= $out[$i][1]) {                       // overlaps or abuts
            if ($to > $out[$i][1]) $out[$i][1] = $to;
        } else {
            $out[] = [$from, $to];
        }
    }
    return $out;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/rates_logic.php`
Expected: 7 `PASS` lines, then `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/rates.php tests/rates_logic.php
git commit -m "feat(rates): merge submitted date ranges

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 2: Nightly price resolution

**Files:**
- Modify: `includes/rates.php` (append)
- Modify: `tests/rates_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/rates_logic.php`, replace the final two lines (the `echo`/`exit` pair) with the DB block below. Every later task appends inside this same `try`, before the `} finally {`.

```php
// ── DB assertions (rolled back) ─────────────────────────────────────────────
$roomId = (int) db_query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
if (!$roomId) { echo "\nSKIP  no rooms seeded\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);

    db_query(
        "INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
         VALUES (:r, '2099-06-10', '2099-06-15', 500, 'Peak')",
        [':r' => $roomId]
    );

    $map = rates_nightly_map($roomId, 100.0, '2099-06-08', '2099-06-18');
    check('map: one entry per night',        count($map) === 10);
    check('map: night before is default',    $map['2099-06-09']['price'] === 100.0);
    check('map: first override night',       $map['2099-06-10']['price'] === 500.0);
    check('map: last override night',        $map['2099-06-14']['price'] === 500.0);
    check('map: date_to is exclusive',       $map['2099-06-15']['price'] === 100.0);
    check('map: override carries label',     $map['2099-06-10']['label'] === 'Peak');
    check('map: override flagged',           $map['2099-06-10']['is_override'] === true);
    check('map: default not flagged',        $map['2099-06-09']['is_override'] === false);
    check('map: default has no label',       $map['2099-06-09']['label'] === null);
    check('map: default has no rate_id',     $map['2099-06-09']['rate_id'] === null);
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/rates_logic.php`
Expected: fatal — `Call to undefined function rates_nightly_map()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/rates.php`:

```php
/**
 * ymd => ['price','label','rate_id','is_override'] for every night in
 * [$fromYmd, $toExclYmd).
 *
 * THE single source of truth for what a night costs. room_stay_quote() sums
 * this, so a rates calendar can never show a price a guest is not quoted.
 *
 * Resolution: the first overlapping row by created_at DESC claims a night;
 * anything unclaimed falls back to the room's own price. Production may still
 * hold overlapping rows written by the old Gantt form, and they must keep
 * resolving exactly as they did. (`id DESC` is only a tiebreak for rows sharing
 * a created_at — previously those resolved arbitrarily.)
 */
function rates_nightly_map(int $roomId, float $default, string $fromYmd, string $toExclYmd): array {
    $out = [];
    if ($fromYmd >= $toExclYmd) return $out;

    $rows = db_query(
        "SELECT id, date_from, date_to, price_amount, label
           FROM rates
          WHERE room_id = :rid AND date_from < :to AND date_to > :from
          ORDER BY created_at DESC, id DESC",
        [':rid' => $roomId, ':from' => $fromYmd, ':to' => $toExclYmd]
    )->fetchAll();

    $claimed = [];
    foreach ($rows as $r) {
        $d = new DateTime(max((string)$r['date_from'], $fromYmd));
        $e = new DateTime(min((string)$r['date_to'],   $toExclYmd));
        while ($d < $e) {
            $k = $d->format('Y-m-d');
            if (!isset($claimed[$k])) {
                $lbl = (string)($r['label'] ?? '');
                $claimed[$k] = [
                    'price'       => (float)$r['price_amount'],
                    'label'       => $lbl !== '' ? $lbl : null,
                    'rate_id'     => (int)$r['id'],
                    'is_override' => true,
                ];
            }
            $d->modify('+1 day');
        }
    }

    $d = new DateTime($fromYmd);
    $e = new DateTime($toExclYmd);
    while ($d < $e) {
        $k = $d->format('Y-m-d');
        $out[$k] = $claimed[$k] ?? [
            'price'       => $default,
            'label'       => null,
            'rate_id'     => null,
            'is_override' => false,
        ];
        $d->modify('+1 day');
    }
    return $out;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/rates_logic.php`
Expected: 17 `PASS` lines, then `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/rates.php tests/rates_logic.php
git commit -m "feat(rates): single nightly price resolver

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 3: Trim/split writes

**Files:**
- Modify: `includes/rates.php` (append)
- Modify: `tests/rates_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/rates_logic.php`, insert before the `} finally {` line:

```php
    // ── rates_apply_ranges: trim / split ───────────────────────────────────
    // Helper: the room's rows as [from, to, price] triples, ordered.
    $rows = function () use ($roomId): array {
        return array_map(
            fn($r) => [(string)$r['date_from'], (string)$r['date_to'], (float)$r['price_amount']],
            db_query('SELECT date_from, date_to, price_amount FROM rates
                       WHERE room_id = :r ORDER BY date_from ASC', [':r' => $roomId])->fetchAll()
        );
    };

    // Case A — new range fully covers the existing one → existing deleted.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-10','2099-06-15',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-07-01']], 300.0, 'Wide');
    check('trim A: covered row is deleted',
        $rows() === [['2099-06-01', '2099-07-01', 300.0]]);

    // Case B — existing spans the new range → split into two.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-01','2099-07-01',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-15']], 300.0, 'Inner');
    check('trim B: spanning row splits in two, new row between',
        $rows() === [
            ['2099-06-01', '2099-06-10', 500.0],
            ['2099-06-10', '2099-06-15', 300.0],
            ['2099-06-15', '2099-07-01', 500.0],
        ]);

    // Case C — new range overlaps the existing row's tail → existing pulled back.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-01','2099-06-20',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-25']], 300.0, null);
    check('trim C: existing date_to pulled back',
        $rows() === [['2099-06-01', '2099-06-10', 500.0], ['2099-06-10', '2099-06-25', 300.0]]);

    // Case D — new range overlaps the existing row's head → existing pushed forward.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    db_query("INSERT INTO rates (room_id,date_from,date_to,price_amount)
              VALUES (:r,'2099-06-10','2099-06-25',500)", [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-15']], 300.0, null);
    check('trim D: existing date_from pushed forward',
        $rows() === [['2099-06-01', '2099-06-15', 300.0], ['2099-06-15', '2099-06-25', 500.0]]);

    // Two ranges in one submission that overlap each other merge into one row.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    $n = rates_apply_ranges($roomId, [['2099-06-01', '2099-06-10'], ['2099-06-05', '2099-06-20']], 300.0, null);
    check('apply: overlapping submitted ranges merge',
        $n === 1 && $rows() === [['2099-06-01', '2099-06-20', 300.0]]);

    // Disjoint ranges in one submission stay separate but share price + label.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    $n = rates_apply_ranges($roomId, [['2099-06-01', '2099-06-05'], ['2099-08-01', '2099-08-05']], 275.0, 'Shoulder');
    check('apply: disjoint ranges make two rows', $n === 2 && count($rows()) === 2);
    check('apply: both rows share the label',
        (int) db_query("SELECT COUNT(*) FROM rates WHERE room_id = :r AND label = 'Shoulder'",
            [':r' => $roomId])->fetchColumn() === 2);

    // Guards.
    check('apply: zero price is refused',  rates_apply_ranges($roomId, [['2099-09-01', '2099-09-05']], 0.0, null) === 0);
    check('apply: no ranges is a no-op',   rates_apply_ranges($roomId, [], 300.0, null) === 0);

    // No write ever leaves an overlap behind.
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-07-01']], 500.0, null);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-15']], 300.0, null);
    rates_apply_ranges($roomId, [['2099-06-12', '2099-06-20']], 200.0, null);
    check('apply: never leaves overlapping rows',
        (int) db_query(
            "SELECT COUNT(*) FROM rates a JOIN rates b
                ON a.room_id = b.room_id AND a.id <> b.id
               AND a.date_from < b.date_to AND a.date_to > b.date_from
             WHERE a.room_id = :r", [':r' => $roomId])->fetchColumn() === 0);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/rates_logic.php`
Expected: fatal — `Call to undefined function rates_apply_ranges()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/rates.php`:

```php
/**
 * Free every night in [$from, $toExcl) on this room, trimming, splitting or
 * deleting whatever already overlaps.
 *
 * The "spans" case must be tested BEFORE the two one-sided cases: a row that
 * covers the new range on both sides satisfies both of their conditions, and
 * checking them first would swallow it instead of splitting around it.
 *
 * The right-hand half of a split keeps the original created_at so resolution
 * order stays stable for any legacy overlapping rows around it.
 */
function rates_clear_span(int $roomId, string $from, string $toExcl): void {
    $rows = db_query(
        "SELECT id, date_from, date_to, price_amount, label, created_at
           FROM rates
          WHERE room_id = :rid AND date_from < :to AND date_to > :from",
        [':rid' => $roomId, ':from' => $from, ':to' => $toExcl]
    )->fetchAll();

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $ef = (string)$r['date_from'];
        $et = (string)$r['date_to'];

        if ($ef >= $from && $et <= $toExcl) {              // fully inside → gone
            db_query('DELETE FROM rates WHERE id = :id', [':id' => $id]);
        } elseif ($ef < $from && $et > $toExcl) {          // spans → split (test FIRST)
            db_query('UPDATE rates SET date_to = :nt WHERE id = :id', [':nt' => $from, ':id' => $id]);
            db_query(
                "INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
                 VALUES (:rid, :df, :dt, :price, :label, :ca)",
                [':rid' => $roomId, ':df' => $toExcl, ':dt' => $et,
                 ':price' => $r['price_amount'], ':label' => $r['label'],
                 ':ca' => (string)$r['created_at']]
            );
        } elseif ($ef < $from) {                           // overlaps the tail
            db_query('UPDATE rates SET date_to = :nt WHERE id = :id', [':nt' => $from, ':id' => $id]);
        } else {                                           // overlaps the head
            db_query('UPDATE rates SET date_from = :nf WHERE id = :id', [':nf' => $toExcl, ':id' => $id]);
        }
    }
}

/**
 * Write one price + label across N date ranges. Returns the rows inserted.
 *
 * Ranges are merged first, then each one clears the nights it claims before its
 * own row is inserted, so every night ends up owned by exactly one row.
 *
 * Runs in a transaction — but only opens one if the caller has not already
 * (tests wrap everything in a transaction they roll back, and PDO/pgsql cannot
 * nest).
 */
function rates_apply_ranges(int $roomId, array $ranges, float $price, ?string $label): int {
    $merged = rates_merge_ranges($ranges);
    if ($roomId <= 0 || !$merged || $price <= 0) return 0;

    $label = ($label !== null && trim($label) !== '') ? trim($label) : null;

    $pdo   = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        $inserted = 0;
        foreach ($merged as [$from, $toExcl]) {
            rates_clear_span($roomId, $from, $toExcl);
            db_query(
                "INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
                 VALUES (:rid, :df, :dt, :price, :label)",
                [':rid' => $roomId, ':df' => $from, ':dt' => $toExcl,
                 ':price' => $price, ':label' => $label]
            );
            $inserted++;
        }
        if ($ownTx) $pdo->commit();
        return $inserted;
    } catch (\Throwable $e) {
        if ($ownTx) $pdo->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/rates_logic.php`
Expected: 27 `PASS` lines, then `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/rates.php tests/rates_logic.php
git commit -m "feat(rates): trim/split overlapping rows on write

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 4: Listing, deletion, and POST parsing

**Files:**
- Modify: `includes/rates.php` (append)
- Modify: `tests/rates_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/rates_logic.php`, insert before the `} finally {` line:

```php
    // ── listing / delete / POST parsing ────────────────────────────────────
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-01', '2099-06-05']], 400.0, 'Listed');
    $listed = rates_for_room($roomId);
    check('for_room: returns the row',        count($listed) === 1);
    check('for_room: carries the label',      ($listed[0]['label'] ?? '') === 'Listed');

    $venueId = (int) db_query('SELECT venue_id FROM rooms WHERE id = :r', [':r' => $roomId])->fetchColumn();
    if ($venueId) {
        $vrows = rates_for_venue($venueId);
        check('for_venue: includes the row',  count($vrows) >= 1);
        check('for_venue: joins room name',   isset($vrows[0]['room_name']));
    }

    $rateId = (int)$listed[0]['id'];
    check('delete: refused outside scope',    rates_delete($rateId, [-1]) === false);
    check('delete: still present after refusal',
        (int) db_query('SELECT COUNT(*) FROM rates WHERE id = :i', [':i' => $rateId])->fetchColumn() === 1);
    check('delete: allowed for owner (null scope)', rates_delete($rateId, null) === true);
    check('delete: row is gone',
        (int) db_query('SELECT COUNT(*) FROM rates WHERE id = :i', [':i' => $rateId])->fetchColumn() === 0);
    check('delete: unknown id → false',       rates_delete(0, null) === false);

    // The form posts the LAST NIGHT; storage is exclusive, so parsing adds a day.
    check('post parse: last night → exclusive',
        rates_ranges_from_post(['range_from' => ['2099-06-10'], 'range_to' => ['2099-06-14']])
            === [['2099-06-10', '2099-06-15']]);
    check('post parse: skips incomplete rows',
        rates_ranges_from_post(['range_from' => ['2099-06-10', ''], 'range_to' => ['2099-06-14', '2099-07-01']])
            === [['2099-06-10', '2099-06-15']]);
    check('post parse: missing keys → empty',  rates_ranges_from_post([]) === []);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/rates_logic.php`
Expected: fatal — `Call to undefined function rates_for_room()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/rates.php`:

```php
/** Every override on one room, earliest first. */
function rates_for_room(int $roomId): array {
    return db_query(
        'SELECT * FROM rates WHERE room_id = :rid ORDER BY date_from ASC, id ASC',
        [':rid' => $roomId]
    )->fetchAll();
}

/** Every override across one property's rooms, grouped by room then date. */
function rates_for_venue(int $venueId): array {
    return db_query(
        'SELECT r.*, rm.name AS room_name
           FROM rates r JOIN rooms rm ON rm.id = r.room_id
          WHERE rm.venue_id = :vid
          ORDER BY rm.sort_order ASC, rm.id ASC, r.date_from ASC',
        [':vid' => $venueId]
    )->fetchAll();
}

/**
 * Delete one override. $venueScope of null means unscoped (owner); otherwise the
 * row's room must belong to one of those venues, so a scoped account cannot
 * delete another property's rate by posting a foreign id.
 */
function rates_delete(int $rateId, ?array $venueScope): bool {
    if ($rateId <= 0) return false;
    $vid = db_query(
        'SELECT rm.venue_id FROM rates r JOIN rooms rm ON rm.id = r.room_id WHERE r.id = :id',
        [':id' => $rateId]
    )->fetchColumn();
    if ($vid === false) return false;
    if ($venueScope !== null && !in_array((int)$vid, array_map('intval', $venueScope), true)) return false;
    db_query('DELETE FROM rates WHERE id = :id', [':id' => $rateId]);
    return true;
}

/**
 * Parse the rate form's parallel range_from[] / range_to[] arrays into
 * [from, toExcl] pairs.
 *
 * The form's "To" field is the LAST NIGHT (inclusive) — the wording the old
 * Price Overrides form used — so a day is added here for exclusive storage.
 */
function rates_ranges_from_post(array $post): array {
    $from = $post['range_from'] ?? [];
    $to   = $post['range_to']   ?? [];
    if (!is_array($from) || !is_array($to)) return [];

    $out = [];
    foreach ($from as $i => $f) {
        $f = trim((string)$f);
        $t = trim((string)($to[$i] ?? ''));
        if ($f === '' || $t === '') continue;
        $out[] = [$f, date('Y-m-d', strtotime($t . ' +1 day'))];
    }
    return $out;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/rates_logic.php`
Expected: 39 `PASS` lines, then `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/rates.php tests/rates_logic.php
git commit -m "feat(rates): listing, scoped delete, POST range parsing

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 5: Point `room_stay_quote()` at the shared resolver

This is the one change that can move guest-facing numbers. The signature, return shape and rounding stay identical.

**Files:**
- Modify: `includes/db.php:809-825`
- Modify: `tests/rates_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/rates_logic.php`, insert before the `} finally {` line:

```php
    // ── the calendar and the guest quote must agree ────────────────────────
    db_query('DELETE FROM rates WHERE room_id = :r', [':r' => $roomId]);
    rates_apply_ranges($roomId, [['2099-06-10', '2099-06-15']], 500.0, 'Peak');

    $sumMap = function (string $ci, string $co) use ($roomId): float {
        $t = 0.0;
        foreach (rates_nightly_map($roomId, 100.0, $ci, $co) as $n) $t += $n['price'];
        return round($t, 2);
    };

    check('quote: matches the map across an override',
        room_stay_quote($roomId, 100.0, '2099-06-08', '2099-06-18')['total'] === $sumMap('2099-06-08', '2099-06-18'));
    check('quote: matches the map with no override at all',
        room_stay_quote($roomId, 100.0, '2099-01-08', '2099-01-12')['total'] === $sumMap('2099-01-08', '2099-01-12'));
    check('quote: 4 default nights = 400',
        room_stay_quote($roomId, 100.0, '2099-01-08', '2099-01-12')['total'] === 400.0);
    check('quote: 5 override nights = 2500',
        room_stay_quote($roomId, 100.0, '2099-06-10', '2099-06-15')['total'] === 2500.0);
    check('quote: nights count unchanged',
        room_stay_quote($roomId, 100.0, '2099-06-10', '2099-06-15')['nights'] === 5);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/rates_logic.php`
Expected: all five PASS already — the old and new implementations agree, which is the point. To prove the test has teeth, temporarily change `$default_price` to `$default_price + 1` in `room_stay_quote()`, re-run, confirm the quote checks FAIL, then revert.

- [ ] **Step 3: Write the implementation**

In `includes/db.php`, replace the body of `room_stay_quote()` (everything between the opening `{` and the closing `}`) with:

```php
    $nights = max(1, (int)((strtotime($check_out) - strtotime($check_in)) / 86400));

    // One resolver for both the guest quote and the admin rates calendar, so a
    // price shown in Admin can never differ from the price a guest is charged.
    require_once __DIR__ . '/rates.php';

    $total = 0.0;
    foreach (rates_nightly_map($room_id, $default_price, $check_in, $check_out) as $night) {
        $total += $night['price'];
    }
    return ['nights' => $nights, 'total' => round($total, 2)];
```

Update its docblock to say the resolution now lives in `rates_nightly_map()`.

`require_once` sits inside the function, not at the top of `db.php`: `rates.php` requires `db.php`, and requiring it back at file scope would be a load-order cycle.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/rates_logic.php`
Expected: 44 `PASS` lines, then `ALL PASS`, exit 0.

Then check nothing else regressed:

Run: `php -l includes/db.php && php -l includes/rates.php`
Expected: `No syntax errors detected` twice.

- [ ] **Step 5: Commit**

```bash
git add includes/db.php tests/rates_logic.php
git commit -m "refactor(rates): room_stay_quote sums the shared nightly map

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 6: The multi-range form partial

**Files:**
- Create: `includes/rate-form.php`

No test — this is a view partial. It is exercised by hand in Task 11.

- [ ] **Step 1: Create the partial**

Create `includes/rate-form.php`:

```php
<?php
/**
 * Rate entry form — one room, N date ranges, one price, one label.
 *
 * Config before include:
 *   $rf_room_id  int|null  fixed room (room page), or null to render a selector
 *   $rf_rooms    array     rooms for the selector: rows with id + name
 *   $rf_action   string    where to POST (default: the current URL)
 *
 * Posts action=rates_save with room_id, price, rate_label and parallel
 * range_from[] / range_to[] arrays. "To" is the LAST NIGHT — the handler calls
 * rates_ranges_from_post(), which converts to exclusive storage.
 *
 * The date pickers are the shared js/datepicker.js already loaded by
 * admin/_layout.php: each row is a ci/co pair sharing a unique data-dp-pair.
 * Rows are cloned from a <template> (never from a live node — a live node
 * carries data-dp-bound and the clone would never bind).
 */
$rf_room_id = $rf_room_id ?? null;
$rf_rooms   = $rf_rooms   ?? [];
$rf_action  = $rf_action  ?? '';
?>
<style>
.rate-form__ranges { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
.rate-range { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.rate-range .field { margin:0; }
.rate-range .dp-btn { min-width:150px; }
.rate-form__row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:4px; }
.rate-form__hint { font-size:12px; color:var(--muted); margin-top:10px; }
</style>

<form method="POST" action="<?= e($rf_action) ?>" class="rate-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="rates_save">

  <?php if ($rf_room_id !== null): ?>
    <input type="hidden" name="room_id" value="<?= (int)$rf_room_id ?>">
  <?php else: ?>
    <div class="field" style="max-width:280px">
      <label>Room</label>
      <select name="room_id" class="eselect" required>
        <?php foreach ($rf_rooms as $r): ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <div class="rate-form__ranges" id="rateRanges"></div>

  <button type="button" class="btn-sm btn-outline" id="rateAddRange">
    <?= admin_icon('plus', 14) ?> Add another date range
  </button>

  <div class="rate-form__row">
    <div class="field" style="margin:0">
      <label>Price / night</label>
      <input class="inp" type="number" name="price" step="0.01" min="1" placeholder="450" required style="width:110px">
    </div>
    <div class="field" style="margin:0">
      <label>Label</label>
      <input class="inp" type="text" name="rate_label" placeholder="Peak Season" style="width:170px">
    </div>
    <button type="submit" class="btn-primary btn-sm">Save rate</button>
  </div>

  <p class="rate-form__hint">
    The price and label apply to every date range above. Nights already covered by
    another rate are re-priced — each night ends up on exactly one rate.
  </p>
</form>

<template id="rateRangeTpl">
  <div class="rate-range">
    <div class="field">
      <label>From (first night)</label>
      <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="__PAIR__"
              data-dp-target="rf_from___N__" data-dp-placeholder="Pick a date">Pick a date</button>
      <input type="hidden" id="rf_from___N__" name="range_from[]">
    </div>
    <div class="field">
      <label>To (last night)</label>
      <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="__PAIR__"
              data-dp-target="rf_to___N__" data-dp-placeholder="Pick a date">Pick a date</button>
      <input type="hidden" id="rf_to___N__" name="range_to[]">
    </div>
    <button type="button" class="btn-icon rate-range__rm" title="Remove this range" aria-label="Remove this range">
      <?= admin_icon('trash', 15) ?>
    </button>
  </div>
</template>

<script>
(function () {
  var wrap = document.getElementById('rateRanges');
  var tpl  = document.getElementById('rateRangeTpl');
  var add  = document.getElementById('rateAddRange');
  if (!wrap || !tpl || !add) return;
  var n = 0;

  function addRow() {
    n++;
    var html = tpl.innerHTML.replace(/__N__/g, String(n)).replace(/__PAIR__/g, 'rfp' + n);
    var host = document.createElement('div');
    host.innerHTML = html;
    wrap.appendChild(host.firstElementChild);
    if (window.initDatepickers) window.initDatepickers();
    syncRemove();
  }

  // The last remaining row cannot be removed — the form always posts one range.
  function syncRemove() {
    var rows = wrap.querySelectorAll('.rate-range');
    rows.forEach(function (r) {
      var btn = r.querySelector('.rate-range__rm');
      if (btn) btn.style.visibility = rows.length > 1 ? 'visible' : 'hidden';
    });
  }

  add.addEventListener('click', addRow);
  wrap.addEventListener('click', function (e) {
    var btn = e.target.closest('.rate-range__rm');
    if (!btn) return;
    btn.closest('.rate-range').remove();
    syncRemove();
  });

  addRow();
})();
</script>
```

- [ ] **Step 2: Verify it parses**

Run: `php -l includes/rate-form.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add includes/rate-form.php
git commit -m "feat(rates): multi-range rate entry form partial

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 7: The rates calendar partial

**Files:**
- Create: `includes/rate-calendar.php`

- [ ] **Step 1: Create the partial**

Create `includes/rate-calendar.php`:

```php
<?php
/**
 * Read-only month grid of nightly prices for ONE room.
 *
 * Config before include:
 *   $rc_room_id       int
 *   $rc_default_price float   the room's own price_amount
 *   $rc_month         string  'Y-m' (default: this month)
 *   $rc_currency      string  default 'USD'
 *   $rc_base_url      string  URL the prev/next links rebuild, WITHOUT rate_month
 *
 * Prices come from rates_nightly_map(), the same resolver room_stay_quote()
 * sums — so this grid always shows what a guest would actually be charged.
 * Month navigation is a plain link (rate_month=YYYY-MM), so it works with JS off.
 */
require_once __DIR__ . '/rates.php';

$rc_month         = $rc_month         ?? date('Y-m');
$rc_currency      = $rc_currency      ?? 'USD';
$rc_default_price = (float)($rc_default_price ?? 0);
$rc_base_url      = $rc_base_url      ?? '';

$__rcFirst = $rc_month . '-01';
if (!strtotime($__rcFirst)) { $rc_month = date('Y-m'); $__rcFirst = $rc_month . '-01'; }

$__rcStart = date('Y-m-01', strtotime($__rcFirst));
$__rcEndEx = date('Y-m-01', strtotime($__rcFirst . ' +1 month'));
$__rcPrev  = date('Y-m',    strtotime($__rcFirst . ' -1 month'));
$__rcNext  = date('Y-m',    strtotime($__rcFirst . ' +1 month'));
$__rcMap   = rates_nightly_map((int)$rc_room_id, $rc_default_price, $__rcStart, $__rcEndEx);
$__rcLead  = (int)date('N', strtotime($__rcStart)) - 1;   // Monday = 0
$__rcSep   = str_contains($rc_base_url, '?') ? '&' : '?';

/** Money for a grid cell: no decimals when the figure is whole. */
$__rcMoney = function (float $v): string {
    return number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2);
};
?>
<style>
.rcal__head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
.rcal__title { font-size:13px; font-weight:700; }
.rcal__grid { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
.rcal__dow { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
  color:var(--muted); text-align:center; padding:4px 0; }
.rcal__cell { min-height:52px; border:1px solid var(--border); border-radius:4px; padding:4px 6px; background:#fff; }
.rcal__cell--blank { border:none; background:transparent; }
.rcal__cell--rate { background:#fefce8; border-color:#fde68a; }
.rcal__cell--today { box-shadow:inset 0 0 0 2px #0369a1; }
.rcal__day { font-size:10px; color:var(--muted); }
.rcal__price { font-size:12px; font-weight:700; color:#102F3A; }
.rcal__cell--rate .rcal__price { color:#92400e; }
.rcal__lbl { font-size:9px; color:#92400e; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rcal__legend { font-size:11px; color:var(--muted); margin-top:10px; }
</style>

<div class="rcal">
  <div class="rcal__head">
    <span class="rcal__title"><?= e(date('F Y', strtotime($__rcStart))) ?></span>
    <span style="display:flex;gap:6px">
      <a class="btn-sm btn-outline" href="<?= e($rc_base_url . $__rcSep . 'rate_month=' . $__rcPrev) ?>">
        <?= admin_icon('chevron-left', 14) ?> Prev
      </a>
      <a class="btn-sm btn-outline" href="<?= e($rc_base_url . $__rcSep . 'rate_month=' . $__rcNext) ?>">
        Next <?= admin_icon('chevron-right', 14) ?>
      </a>
    </span>
  </div>

  <div class="rcal__grid">
    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $__d): ?>
      <div class="rcal__dow"><?= $__d ?></div>
    <?php endforeach; ?>

    <?php for ($i = 0; $i < $__rcLead; $i++): ?>
      <div class="rcal__cell rcal__cell--blank"></div>
    <?php endfor; ?>

    <?php foreach ($__rcMap as $__ymd => $__n):
      $__cls = 'rcal__cell'
             . ($__n['is_override'] ? ' rcal__cell--rate' : '')
             . ($__ymd === date('Y-m-d') ? ' rcal__cell--today' : '');
      $__title = $__n['is_override']
        ? ($__n['label'] !== null ? $__n['label'] . ' — override' : 'Rate override')
        : 'Default room price';
    ?>
      <div class="<?= $__cls ?>" title="<?= e(date('D j M', strtotime($__ymd)) . ' · ' . $__title) ?>">
        <div class="rcal__day"><?= (int)date('j', strtotime($__ymd)) ?></div>
        <div class="rcal__price"><?= e($__rcMoney((float)$__n['price'])) ?></div>
        <?php if ($__n['label'] !== null): ?>
          <div class="rcal__lbl"><?= e($__n['label']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="rcal__legend">
    Prices in <?= e($rc_currency) ?> per night. Yellow nights are overrides; the rest
    use the room's default price of <?= e($rc_currency) ?> <?= e($__rcMoney($rc_default_price)) ?>.
  </p>
</div>
```

- [ ] **Step 2: Verify it parses**

Run: `php -l includes/rate-calendar.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add includes/rate-calendar.php
git commit -m "feat(rates): read-only month rate calendar partial

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 8: Rates tab on the property page

**Files:**
- Modify: `admin/venue-edit.php`

- [ ] **Step 1: Add the POST handlers**

In `admin/venue-edit.php`, immediately after the `require_once` block at the top (after the `includes/upsells.php` line), add:

```php
require_once __DIR__ . '/../includes/rates.php';
```

Then, immediately before the line `$rooms = $id` (~line 215), add:

```php
// ── POST: rate overrides ────────────────────────────────────────────────────
// Owner-only page, so no venue scoping is needed on the write — but the room
// must belong to THIS property, or a posted foreign room_id would price
// somebody else's room from this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rates_save' && $id) {
    verify_csrf();
    $rt_room = (int)($_POST['room_id'] ?? 0);
    $rt_own  = $rt_room
        ? (int) db_query('SELECT COUNT(*) FROM rooms WHERE id = :r AND venue_id = :v',
              [':r' => $rt_room, ':v' => $id])->fetchColumn()
        : 0;
    if (!$rt_own) {
        $error = 'That room is not in this property.';
    } else {
        $rt_n = rates_apply_ranges(
            $rt_room,
            rates_ranges_from_post($_POST),
            (float)($_POST['price'] ?? 0),
            trim((string)($_POST['rate_label'] ?? ''))
        );
        if ($rt_n) {
            audit_log('rates.save', 'room', $rt_room, "{$rt_n} range(s)");
            header("Location: /admin/venue-edit.php?id={$id}&rate_room={$rt_room}&saved=1");
            exit;
        }
        $error = 'Nothing saved — check the dates and that the price is above zero.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_delete' && $id) {
    verify_csrf();
    $rt_room = (int)($_POST['room_id'] ?? 0);
    if (rates_delete((int)($_POST['rate_id'] ?? 0), null)) {
        audit_log('rates.delete', 'venue', $id, '');
    }
    header("Location: /admin/venue-edit.php?id={$id}&rate_room={$rt_room}&saved=1");
    exit;
}
```

- [ ] **Step 2: Add the view data**

Directly after the `$rooms = $id ? … : [];` assignment (~line 217), add:

```php
// Rates tab: which room's calendar to draw, and which month.
$rateRoomId = isset($_GET['rate_room']) ? (int)$_GET['rate_room'] : 0;
$rateRoom   = null;
foreach ($rooms as $__r) {
    if ($rateRoomId && (int)$__r['id'] === $rateRoomId) { $rateRoom = $__r; break; }
}
if (!$rateRoom && $rooms) $rateRoom = $rooms[0];        // default to the first room
$rateMonth  = isset($_GET['rate_month']) && strtotime($_GET['rate_month'] . '-01')
    ? substr((string)$_GET['rate_month'], 0, 7)
    : date('Y-m');
$venueRates = $id ? rates_for_venue($id) : [];
```

- [ ] **Step 3: Add the tab button**

In the tab bar (~line 243), insert between the Rooms and Gallery buttons:

```php
  <button class="tab-btn" data-tab="rates">Rates<?php if ($venueRates): ?> <span class="tab-btn__count"><?= count($venueRates) ?></span><?php endif; ?></button>
```

- [ ] **Step 4: Add the tab panel**

Immediately after the closing `</div>` of `<div class="tab-panel" id="tab-rooms">` (the line before `<!-- ── TAB: Gallery ── -->`), insert:

```php
<!-- ── TAB: Rates ── -->
<div class="tab-panel" id="tab-rates">
  <?php if (!$rooms): ?>
  <div class="card"><div class="card__body">
    <p style="padding:24px;text-align:center;color:var(--muted)">Add a room to this property before setting rates.</p>
  </div></div>
  <?php else: ?>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title">Set a rate</span>
      <span class="text-muted" style="font-size:12px">One price and label across as many date ranges as you need</span>
    </div>
    <div class="card__body" style="padding:20px">
      <?php
        $rf_room_id = null;                     // property page → show the room selector
        $rf_rooms   = $rooms;
        $rf_action  = '/admin/venue-edit.php?id=' . (int)$id;
        include __DIR__ . '/../includes/rate-form.php';
      ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title">Calendar — <?= e($rateRoom['name']) ?></span>
      <form method="GET" style="display:flex;gap:8px;align-items:center;margin:0">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="rate_month" value="<?= e($rateMonth) ?>">
        <select name="rate_room" class="eselect" onchange="this.form.submit()">
          <?php foreach ($rooms as $r): ?>
          <option value="<?= (int)$r['id'] ?>"<?= (int)$r['id'] === (int)$rateRoom['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="card__body" style="padding:20px">
      <?php
        $rc_room_id       = (int)$rateRoom['id'];
        $rc_default_price = (float)$rateRoom['price_amount'];
        $rc_currency      = (string)$rateRoom['price_currency'];
        $rc_month         = $rateMonth;
        $rc_base_url      = '/admin/venue-edit.php?id=' . (int)$id . '&rate_room=' . (int)$rateRoom['id'];
        include __DIR__ . '/../includes/rate-calendar.php';
      ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">All overrides in this property</span></div>
    <div class="card__body" style="padding:0">
      <?php if (!$venueRates): ?>
      <p style="padding:24px;text-align:center;color:var(--muted)">No overrides yet. Default room prices apply.</p>
      <?php else: ?>
      <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Room</th><th>First night</th><th>Last night</th><th>Price/night</th><th>Label</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($venueRates as $rt): ?>
          <tr>
            <td><strong><?= e($rt['room_name']) ?></strong></td>
            <td><?= e(date('j M Y', strtotime((string)$rt['date_from']))) ?></td>
            <td><?= e(date('j M Y', strtotime((string)$rt['date_to'] . ' -1 day'))) ?></td>
            <td><?= e(number_format((float)$rt['price_amount'], 0)) ?></td>
            <td><?= e((string)($rt['label'] ?? '')) ?></td>
            <td style="text-align:right">
              <form method="POST" style="display:inline" onsubmit="return confirm('Remove this rate?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="rate_delete">
                <input type="hidden" name="rate_id" value="<?= (int)$rt['id'] ?>">
                <input type="hidden" name="room_id" value="<?= (int)$rt['room_id'] ?>">
                <button type="submit" class="btn-icon" title="Remove this rate"><?= admin_icon('trash', 15) ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>
```

The table shows the **last night** (`date_to -1 day`) so the list reads the same way the form is filled in.

- [ ] **Step 5: Verify and commit**

Run: `php -l admin/venue-edit.php`
Expected: `No syntax errors detected`.

```bash
git add admin/venue-edit.php
git commit -m "feat(rates): Rates tab on the property page

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 9: Rates tab on the room page

**Files:**
- Modify: `admin/room-edit.php`

- [ ] **Step 1: Add the handlers and view data**

In `admin/room-edit.php`, after the `require_once __DIR__ . '/../includes/admin-media-picker.php';` line (~line 7), add:

```php
require_once __DIR__ . '/../includes/rates.php';
```

Then, immediately before the `$success = '';` line (~line 16), add:

```php
// ── POST: rate overrides ────────────────────────────────────────────────────
// Owner-only page. room_id is forced to THIS room, so a posted foreign id
// cannot price another room from here.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rates_save' && $id) {
    verify_csrf();
    $rt_n = rates_apply_ranges(
        $id,
        rates_ranges_from_post($_POST),
        (float)($_POST['price'] ?? 0),
        trim((string)($_POST['rate_label'] ?? ''))
    );
    if ($rt_n) {
        audit_log('rates.save', 'room', $id, "{$rt_n} range(s)");
        header("Location: /admin/room-edit.php?id={$id}&saved=1");
        exit;
    }
    $error = 'Nothing saved — check the dates and that the price is above zero.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_delete' && $id) {
    verify_csrf();
    if (rates_delete((int)($_POST['rate_id'] ?? 0), null)) {
        audit_log('rates.delete', 'room', $id, '');
    }
    header("Location: /admin/room-edit.php?id={$id}&saved=1");
    exit;
}
```

`$error` is declared below this point in the file, so move the two lines `$success = '';` and `$error   = '';` to sit **above** this new block.

Then, directly before `$activeMenu = 'rooms';` (~line 272), add:

```php
$roomRates = $id ? rates_for_room($id) : [];
$rateMonth = isset($_GET['rate_month']) && strtotime($_GET['rate_month'] . '-01')
    ? substr((string)$_GET['rate_month'], 0, 7)
    : date('Y-m');
```

- [ ] **Step 2: Add the tab button**

In the tab bar (~line 295), insert between the Units and SEO buttons:

```php
  <button class="tab-btn" data-tab="rates">Rates<?php if ($roomRates): ?> <span class="tab-btn__count"><?= count($roomRates) ?></span><?php endif; ?></button>
```

- [ ] **Step 3: Add the tab panel**

Immediately after the closing `</div>` of the Units tab panel, insert:

```php
<!-- ── TAB: Rates ── -->
<div class="tab-panel" id="tab-rates">
  <div class="card" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title">Set a rate</span>
      <span class="text-muted" style="font-size:12px">One price and label across as many date ranges as you need</span>
    </div>
    <div class="card__body" style="padding:20px">
      <?php
        $rf_room_id = (int)$id;                 // room page → no selector
        $rf_rooms   = [];
        $rf_action  = '/admin/room-edit.php?id=' . (int)$id;
        include __DIR__ . '/../includes/rate-form.php';
      ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card__head"><span class="card__title">Calendar</span></div>
    <div class="card__body" style="padding:20px">
      <?php
        $rc_room_id       = (int)$id;
        $rc_default_price = (float)($room['price_amount'] ?? 0);
        $rc_currency      = (string)($room['price_currency'] ?? 'USD');
        $rc_month         = $rateMonth;
        $rc_base_url      = '/admin/room-edit.php?id=' . (int)$id;
        include __DIR__ . '/../includes/rate-calendar.php';
      ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Overrides on this room</span></div>
    <div class="card__body" style="padding:0">
      <?php if (!$roomRates): ?>
      <p style="padding:24px;text-align:center;color:var(--muted)">No overrides yet. The default room price applies.</p>
      <?php else: ?>
      <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>First night</th><th>Last night</th><th>Price/night</th><th>Label</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($roomRates as $rt): ?>
          <tr>
            <td><?= e(date('j M Y', strtotime((string)$rt['date_from']))) ?></td>
            <td><?= e(date('j M Y', strtotime((string)$rt['date_to'] . ' -1 day'))) ?></td>
            <td><?= e(number_format((float)$rt['price_amount'], 0)) ?></td>
            <td><?= e((string)($rt['label'] ?? '')) ?></td>
            <td style="text-align:right">
              <form method="POST" style="display:inline" onsubmit="return confirm('Remove this rate?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="rate_delete">
                <input type="hidden" name="rate_id" value="<?= (int)$rt['id'] ?>">
                <button type="submit" class="btn-icon" title="Remove this rate"><?= admin_icon('trash', 15) ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
```

- [ ] **Step 4: Verify and commit**

Run: `php -l admin/room-edit.php`
Expected: `No syntax errors detected`.

```bash
git add admin/room-edit.php
git commit -m "feat(rates): Rates tab on the room page

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 10: Site-wide read-only Rates page

**Files:**
- Create: `admin/rates.php`
- Modify: `admin/_layout.php`

- [ ] **Step 1: Create the page**

Create `admin/rates.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_login();

// Read-only, so reception may look but not touch — editing lives on the
// owner-only property and room pages. Scoped so a reception account only sees
// rates for its own properties.
$scope = admin_venue_ids();                 // null = owner (every venue)

$venues = $scope === null
    ? db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll()
    : ($scope
        ? db_query('SELECT id, name FROM venues WHERE id IN (' . implode(',', array_map('intval', $scope)) . ')
                    ORDER BY sort_order ASC, name ASC')->fetchAll()
        : []);

$venueId = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
if ($venueId && !in_array($venueId, array_map(fn($v) => (int)$v['id'], $venues), true)) $venueId = 0;
if (!$venueId && $venues) $venueId = (int)$venues[0]['id'];

$rooms = $venueId
    ? db_query('SELECT id, name, price_amount, price_currency FROM rooms
                 WHERE venue_id = :v ORDER BY sort_order ASC, id ASC', [':v' => $venueId])->fetchAll()
    : [];

$rateMonth = isset($_GET['rate_month']) && strtotime($_GET['rate_month'] . '-01')
    ? substr((string)$_GET['rate_month'], 0, 7)
    : date('Y-m');

$pageTitle  = 'Rates';
$activeMenu = 'rates';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Rates</h1>
  <form method="GET" style="display:flex;gap:8px;align-items:center;margin:0">
    <input type="hidden" name="rate_month" value="<?= e($rateMonth) ?>">
    <select name="venue" class="eselect" onchange="this.form.submit()">
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $venueId ? ' selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$venues): ?>
<div class="alert alert--info">No properties are assigned to your account.</div>
<?php elseif (!$rooms): ?>
<div class="alert alert--info">This property has no rooms yet.</div>
<?php else: ?>

<?php foreach ($rooms as $r): ?>
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
      $rc_base_url      = '/admin/rates.php?venue=' . $venueId;
      include __DIR__ . '/../includes/rate-calendar.php';
    ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Add the sidebar link**

In `admin/_layout.php`, inside the Bookings group, immediately after the Calendar `<a>` block (the one ending `Calendar\n        </a>`), insert:

```php
        <a href="/admin/rates.php"         class="sidebar__link <?= ($activeMenu??'')==='rates'         ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          Rates
        </a>
```

- [ ] **Step 3: Verify and commit**

Run: `php -l admin/rates.php && php -l admin/_layout.php`
Expected: `No syntax errors detected` twice.

```bash
git add admin/rates.php admin/_layout.php
git commit -m "feat(rates): site-wide read-only rates page

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 11: Retire the Gantt Price Overrides form

**Files:**
- Modify: `admin/gantt.php`

- [ ] **Step 1: Remove the POST handlers**

In `admin/gantt.php`, delete the whole `if ($action === 'set_rate') { … }` block and the whole `if ($action === 'delete_rate') { … }` block (~lines 77–108).

- [ ] **Step 2: Remove the form and table**

Delete the entire `<!-- ── Rate overrides ── -->` card — from that comment through the `</div>` that closes it, ending on the line before `<!-- ── iCal feeds ── -->` (~lines 574–637). Replace it with:

```php
<!-- ── Rate overrides moved ── -->
<div class="card" style="margin-bottom:24px">
  <div class="card__body" style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <span class="text-muted" style="font-size:13px">
      Nightly rates are edited on each property and room, and shown as a calendar on the Rates page.
    </span>
    <a href="/admin/rates.php" class="btn-sm btn-outline">Open Rates <?= admin_icon('chevron-right', 14) ?></a>
  </div>
</div>
```

- [ ] **Step 3: Remove the orphaned datepickers**

Delete these two lines near the end of the `<script>` block (~lines 1185–1186):

```js
const dpRateFrom = makePicker('dpRateFromPop', 'dpRateFromVal','dpRateFromDisplay');
const dpRateTo   = makePicker('dpRateToPop',   'dpRateToVal',  'dpRateToDisplay');
```

Their DOM nodes are gone with the form, so leaving them would throw on every page load.

**Keep** the `$rates` query and the `$rate_dates` / `$rate_dates_any` loops — they drive the yellow day-tinting on the availability grid, which stays.

- [ ] **Step 4: Verify**

Run: `php -l admin/gantt.php`
Expected: `No syntax errors detected`.

Run: `grep -n "set_rate\|delete_rate\|dpRateFrom\|dpRateTo\|Price Overrides" admin/gantt.php`
Expected: no output.

Run: `grep -c "rate_dates" admin/gantt.php`
Expected: `6` — the tinting lookups survive.

- [ ] **Step 5: Commit**

```bash
git add admin/gantt.php
git commit -m "refactor(rates): retire the Gantt price-override form

Rate writing now lives on the owner-only property and room pages, which
also moves it behind require_owner() — gantt.php is require_login() only,
so reception could previously set prices there.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 12: Manual verification

**Files:** none — this is a browser pass against the local dev server.

- [ ] **Step 1: Start the preview**

Use the `tribalsand` launch config (`php -S localhost:8766 router.php`). Sign in to `/admin` as the owner.

- [ ] **Step 2: Property tab**

Open `/admin/venue-edit.php?id=<a venue with rooms>` → **Rates**. Confirm:
- "Add another date range" appends a row whose two pickers open and pair correctly.
- The remove button is hidden on the first row and appears once a second row exists.
- Saving two disjoint ranges with one price and label creates two rows in the table.
- The calendar's yellow nights match the ranges just saved.
- Switching the room selector redraws the calendar for the other room.
- Prev/Next month keeps you on the Rates tab.

- [ ] **Step 3: Overlap behaviour**

Save `1–10 June` at 500, then `3–5 June` at 300. The table should show three rows (1–2 at 500, 3–5 at 300, 6–10 at 500) and the calendar should show 300 only on 3–5.

- [ ] **Step 4: Room tab and Rates page**

Open `/admin/room-edit.php?id=<that room>` → **Rates**: the same rates appear with no room selector. Then `/admin/rates.php`: the property's rooms each render a calendar, and the property filter switches properties.

- [ ] **Step 5: Gantt is clean**

Open `/admin/gantt.php`. The Price Overrides card is replaced by the link, the browser console is error-free, and days carrying a rate are still tinted yellow.

- [ ] **Step 6: Run the suite once more**

Run: `php tests/rates_logic.php`
Expected: `ALL PASS`, exit 0.

---

## Task 13: Document the conventions

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the section**

In `CLAUDE.md`, after the "### Sustainability metrics" section, add:

```markdown
### Nightly rates — per room, edited on the property and the room
Rate overrides live in `rates` (`room_id, date_from, date_to, price_amount, label`) — no
migration, the table predates the editors. Helpers in **`includes/rates.php`**.
- **`date_to` is EXCLUSIVE** — the checkout morning, not the last night. Both forms label
  it "To (last night)" and `rates_ranges_from_post()` adds the day. Never reinterpret the
  column; it would silently reprice every stored override.
- **One resolver.** `rates_nightly_map()` decides what a night costs, and
  `room_stay_quote()` (in `includes/db.php`) sums it. That split is load-bearing: the admin
  rates calendar and the guest quote read the same function, so they cannot drift. Don't
  reintroduce a second nightly loop.
- **Writes leave no overlaps.** `rates_apply_ranges()` merges the submitted ranges, then
  `rates_clear_span()` trims, splits or deletes whatever already covers those nights. The
  **spans** case must be tested before the two one-sided cases — a row covering the new
  range on both sides satisfies both, and checking them first swallows it instead of
  splitting it. Reads still resolve `created_at DESC` so legacy overlapping rows written by
  the old Gantt form behave exactly as before.
- **Editing is owner-only** (`admin/venue-edit.php` and `admin/room-edit.php` Rates tabs,
  both `require_owner()`). `admin/rates.php` is read-only, `require_login()` + scoped by
  `admin_venue_ids()`, so reception can quote from it. The Gantt's Price Overrides form is
  **gone** — don't reinstate it; it was `require_login()` only and let reception set prices.
- Partials: `includes/rate-form.php` (N ranges, one price, one label — rows cloned from a
  `<template>` so `data-dp-bound` is never copied, then `window.initDatepickers()`) and
  `includes/rate-calendar.php` (read-only month grid). Both use the shared
  `js/datepicker.js` already loaded by `admin/_layout.php`.
- Test: `php tests/rates_logic.php` (DB assertions in a rolled-back transaction).
```

- [ ] **Step 2: Add the file-map rows**

In the File Map table, after the `includes/sustainability.php` row, add:

```markdown
| `includes/rates.php` | Nightly rate helpers — merge, resolve, trim/split writes, scoped delete |
| `includes/rate-form.php` · `includes/rate-calendar.php` | Multi-range rate entry + read-only month grid partials |
| `admin/rates.php` | Site-wide read-only rates calendar (scoped, reception-visible) |
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(rates): record the rate-override conventions

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Self-Review Notes

**Spec coverage:** goals 1–5 map to Tasks 8/9 (property + room editing), 6 (multi-range), 7 + 10 (calendar), 5 (calendar/quote agreement), 3 (one row per night). Non-goals hold: no drag-select, no per-unit rates, no currency work, no change to quoted numbers. The spec's `rate_month` / `rate_room` param naming is used in Tasks 8, 9 and 10; the permission tightening is carried in Task 11's commit message and Task 13's docs.

**Naming consistency:** `rates_merge_ranges`, `rates_nightly_map`, `rates_clear_span`, `rates_apply_ranges`, `rates_for_room`, `rates_for_venue`, `rates_delete`, `rates_ranges_from_post` are defined in Tasks 1–4 and used under those exact names in Tasks 5, 8, 9 and 10. Partial config variables (`$rf_*`, `$rc_*`) match between the partials and all three call sites.

**Known ordering trap:** Task 9 requires moving `$success` / `$error` above the new POST block in `room-edit.php`, since the handlers assign `$error`. Called out in the step.
