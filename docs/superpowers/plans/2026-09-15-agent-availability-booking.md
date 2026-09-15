# Travel-Agent Availability + Request-to-Book Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a signed-in travel agent check live availability at their net rate and send a "Request to book" that creates the same 24h hold the public site creates, tagged as a trade booking end to end (admin, emails, ledger).

**Architecture:** Server-rendered pages under `/agent` (standalone chrome, no admin assets) call the SAME resolvers the public site uses — `ts_property_configurations()` / `room_stay_quote()` for prices, `find_available_unit()` + `create_hold_with_block()` / `mi_allocate_and_hold()` for the hold. A pure helper applies the agent's discount; a single transactional writer (`agent_submit_request()`) records the request as a submission (payload names the agent) plus a hold carrying `holds.agent_id` and the frozen net price in `holds.quoted_amount` (which the ledger already reads). One small migration; every read/write of the new column is guarded.

**Tech Stack:** PHP 8.2 (vanilla), PostgreSQL via PDO (`db_query()`), plain HTML forms + the shared `js/datepicker.js`, tests are plain PHP scripts (`php tests/<file>.php`, DB work inside one rolled-back transaction).

**Spec:** `docs/superpowers/specs/2026-09-15-agent-availability-booking-design.md`

---

## File structure

| File | Responsibility |
|---|---|
| `db/migrations/add_holds_agent.sql` (new) | `holds.agent_id` + indexes (after `add_travel_agents.sql`). |
| `includes/db.php` (modify) | `holds_agent_supported()` beside the other `holds_*_supported()` probes. |
| `includes/agent.php` (modify) | All trade-portal logic: stay validation, pricing of configurations, quote, form-mode rule, the transactional writer, the request list, status labels, trade lines, request emails. |
| `includes/bookings.php` (modify) | `bookings_sync_hold()` writes `source='agent'` + agency for an agent hold. |
| `includes/mail.php` (modify) | Optional trade rows in the staff hold notification; optional `price` row in the acknowledgement. |
| `agent/_layout.php` (modify) | Nav, datepicker assets, option/status CSS. |
| `agent/availability.php` (new) | Search form + priced options per venue. |
| `agent/request.php` (new) | Summary + traveller form (GET), write + emails + PRG (POST). |
| `agent/requests.php` (new) | "Your requests" list. |
| `agent/login.php`, `agent/rates.php` (modify) | Copy + landing page. |
| `admin/holds.php`, `admin/booking.php`, `admin/agents.php`, `admin/submissions.php` (modify) | Trade badge / header line / copy / source label. |
| `tests/agent_portal_logic.php` (modify) | Pure + DB round-trip coverage. |
| `CLAUDE.md` (modify) | "Travel-agent portal" section. |

Local prerequisites: the local Postgres is missing `add_travel_agents.sql` and `add_holds_quoted_amount.sql`. Task 1 applies them with `php bin/migrate.php <file>` (local DB only — production is migrated separately via `/admin/migrate.php`).

---

### Task 1: Migration + `holds_agent_supported()`

**Files:**
- Create: `db/migrations/add_holds_agent.sql`
- Modify: `includes/db.php` (after `holds_quoted_amount_supported()`, ~line 905–933)
- Test: `tests/agent_portal_logic.php` (a probe assertion)

- [ ] **Step 1: Write the migration**

```sql
-- Migration: link a hold to the travel agent who requested it.
-- Run via /admin/migrate.php AFTER add_travel_agents.sql (the FK target). Apply
-- add_holds_quoted_amount.sql as well — that is where an agent hold freezes its
-- NET price, which bookings_sync_hold() snapshots into the ledger. Idempotent.
--
-- holds.agent_id says a hold was requested through the trade portal, and by whom.
-- NULL = a guest or staff hold — exactly what every existing row already means.
-- The submission payload (payload_json.agent_id, agency, quoted_total …) is the
-- canonical record of the request and needs no column; this link is what the
-- admin badge, the revenue ledger (source = 'agent') and the trade emails read.
ALTER TABLE holds ADD COLUMN IF NOT EXISTS agent_id INTEGER NULL
    REFERENCES travel_agents(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_holds_agent_id
    ON holds (agent_id) WHERE agent_id IS NOT NULL;

-- "Your requests" in the portal reads submissions by the agent id in the payload.
CREATE INDEX IF NOT EXISTS idx_submissions_agent_id
    ON submissions ((payload_json->>'agent_id'));
```

- [ ] **Step 2: Add the probe to `includes/db.php`** right after `holds_quoted_amount_supported()`:

```php
/**
 * True once add_holds_agent.sql has added holds.agent_id (memoised). An
 * information_schema probe, never a failing SELECT: it is first reached inside
 * the trade-portal booking transaction, and in Postgres a failed statement aborts
 * the whole transaction.
 */
function holds_agent_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try {
        $c = (bool) db_query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'holds' AND column_name = 'agent_id'
              LIMIT 1"
        )->fetchColumn();
    } catch (Throwable $e) {
        $c = false;
    }
    return $c;
}
```

- [ ] **Step 3: Apply the three migrations to the LOCAL database**

```bash
php bin/migrate.php db/migrations/add_travel_agents.sql
php bin/migrate.php db/migrations/add_holds_quoted_amount.sql
php bin/migrate.php db/migrations/add_holds_agent.sql
```
Expected: three `OK` lines.

- [ ] **Step 4: Assert the probe in the test** — add near the top of the DB block of `tests/agent_portal_logic.php`:

```php
check('db: holds.agent_id probe agrees with information_schema',
    holds_agent_supported() === (bool) db_query("SELECT 1 FROM information_schema.columns WHERE table_name='holds' AND column_name='agent_id'")->fetchColumn());
```
Run: `php tests/agent_portal_logic.php` → `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/add_holds_agent.sql includes/db.php tests/agent_portal_logic.php
git commit -m "feat(agents): holds.agent_id migration + guarded probe"
```

---

### Task 2: Pure helpers in `includes/agent.php`

**Files:**
- Modify: `includes/agent.php` (append; add `require_once __DIR__ . '/rates.php';` and `require_once __DIR__ . '/services.php';` after the existing requires)
- Test: `tests/agent_portal_logic.php`

- [ ] **Step 1: Write the failing tests** (append to the pure section):

```php
// ── Stay validation (pure) ─────────────────────────────────────────────────────
check('stay: a normal future window is accepted and normalised',
    agent_valid_stay('2098-6-10', '2098-06-12', '2026-09-15') === ['2098-06-10', '2098-06-12', 2]);
check('stay: a check-in before today is refused',  agent_valid_stay('2026-09-14', '2026-09-16', '2026-09-15') === null);
check('stay: today is allowed as a check-in',      agent_valid_stay('2026-09-15', '2026-09-16', '2026-09-15') !== null);
check('stay: reversed / equal dates are refused',  agent_valid_stay('2098-06-12', '2098-06-10', '2026-09-15') === null && agent_valid_stay('2098-06-10', '2098-06-10', '2026-09-15') === null);
check('stay: garbage is refused',                  agent_valid_stay('soon', '2098-06-12', '2026-09-15') === null);
check('stay: 30 nights ok, 31 refused',
    agent_valid_stay('2098-06-01', '2098-07-01', '2026-09-15') !== null && agent_valid_stay('2098-06-01', '2098-07-02', '2026-09-15') === null);

// ── Pricing a configurations result (pure) ────────────────────────────────────
$cfg = [
    'singles' => [['slug' => 'a', 'name' => 'A', 'total' => 1000.0, 'currency' => 'USD']],
    'entire'  => [['slug' => 'whole', 'name' => 'Whole', 'total' => 5000.0, 'currency' => 'USD']],
    'combos'  => [['rooms' => [['slug' => 'a', 'units_used' => 2, 'total' => 2000.0, 'currency' => 'USD']], 'total' => 2000.0, 'currency' => 'USD', 'capacity' => 4]],
    'max_capacity' => 8,
];
$priced = agent_price_configurations($cfg, ['discount_pct' => 10, 'venue_discounts' => [7 => 25]], 3);
check('price: net_total on a single = published × 0.9',   eq($priced['singles'][0]['net_total'], 900.0));
check('price: net_total on the whole property',          eq($priced['entire'][0]['net_total'], 4500.0));
check('price: net_total on a combo and on its rooms',    eq($priced['combos'][0]['net_total'], 1800.0) && eq($priced['combos'][0]['rooms'][0]['net_total'], 1800.0));
check('price: published totals are untouched',           eq($priced['singles'][0]['total'], 1000.0) && eq($priced['combos'][0]['total'], 2000.0));
check('price: discount_pct is reported',                 eq($priced['discount_pct'], 10.0));
$pricedOv = agent_price_configurations($cfg, ['discount_pct' => 10, 'venue_discounts' => [7 => 25]], 7);
check('price: a per-venue override drives the net',      eq($pricedOv['singles'][0]['net_total'], 750.0));
$priced0 = agent_price_configurations($cfg, ['discount_pct' => 0], 3);
check('price: 0% leaves net = published',                eq($priced0['singles'][0]['net_total'], 1000.0));

// ── Combo link parameter round-trip (pure) ────────────────────────────────────
check('rooms param: builds from combo rooms',
    agent_rooms_param([['slug' => 'double', 'units_used' => 2], ['slug' => 'bunk', 'units_used' => 1]]) === 'double:2,bunk:1');
check('rooms param: parses back, clamps and drops junk',
    agent_parse_rooms_param('double:2,bunk,bad slug!,x:99') === [['slug' => 'double', 'units' => 2], ['slug' => 'bunk', 'units' => 1], ['slug' => 'x', 'units' => 8]]);

// ── Trade lines (pure) ────────────────────────────────────────────────────────
$tl = agent_trade_lines(['name' => 'Jane Agent', 'agency' => 'Safari Co', 'email' => 'jane@x.com'],
    ['nights' => 2, 'published' => 1000.0, 'net' => 850.0, 'currency' => 'USD', 'discount_pct' => 15]);
check('trade lines: agent line names agency, agent and email', $tl['agent'] === 'Safari Co — Jane Agent <jane@x.com>');
check('trade lines: rate line shows net, nights and the discount', $tl['rate'] === 'USD 850 net · 2 nights · 15% off published USD 1,000');
$tl0 = agent_trade_lines(['name' => 'Solo', 'agency' => '', 'email' => 's@x.com'],
    ['nights' => 1, 'published' => 200.0, 'net' => 200.0, 'currency' => 'KES', 'discount_pct' => 0]);
check('trade lines: no discount reads as published rate', $tl0['agent'] === 'Solo <s@x.com>' && $tl0['rate'] === 'KES 200 · 1 night · published rate (no trade discount)');

// ── Request status (pure) ─────────────────────────────────────────────────────
$now = strtotime('2026-09-15 10:00:00');
check('status: no hold = enquiry sent', agent_request_status(['hold_status' => null], $now)['label'] === 'Enquiry sent');
$st = agent_request_status(['hold_status' => 'pending', 'expires_at' => '2026-09-15 21:30:00'], $now);
check('status: pending shows the countdown', $st['label'] === 'On hold' && $st['note'] === 'Expires in 11h 30m');
check('status: pending with no expiry awaits confirmation', agent_request_status(['hold_status' => 'pending', 'expires_at' => null], $now)['note'] === 'Awaiting confirmation');
check('status: confirmed / expired / cancelled labels',
    agent_request_status(['hold_status' => 'confirmed'], $now)['label'] === 'Confirmed'
    && agent_request_status(['hold_status' => 'expired'], $now)['label'] === 'Expired'
    && agent_request_status(['hold_status' => 'cancelled'], $now)['class'] === 'cancelled');
```

- [ ] **Step 2: Run to verify they fail**: `php tests/agent_portal_logic.php` → fatal "Call to undefined function agent_valid_stay()".

- [ ] **Step 3: Implement** — append to `includes/agent.php`:

```php
/** Longest stay the portal will request — the same cap as room_max_stay_nights(). */
const AGENT_MAX_STAY_NIGHTS = 30;

/**
 * Validate a requested stay the way the public endpoints do (rates_window_ymd()
 * on both dates, check-out after check-in) plus the two rules a BOOKING needs:
 * it cannot start before today (Nairobi-local — includes/db.php sets the zone)
 * and it is capped at AGENT_MAX_STAY_NIGHTS. Returns [check_in, check_out, nights]
 * normalised to zero-padded Y-m-d, or null. Pure ($today only for tests).
 */
function agent_valid_stay(string $checkIn, string $checkOut, ?string $today = null): ?array {
    $ci = rates_window_ymd($checkIn);
    $co = rates_window_ymd($checkOut);
    if ($ci === null || $co === null || $ci >= $co) return null;
    if ($ci < ($today ?? date('Y-m-d'))) return null;
    $nights = (int) round((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights < 1 || $nights > AGENT_MAX_STAY_NIGHTS) return null;
    return [$ci, $co, $nights];
}

/**
 * Apply an agent's discount to a ts_property_configurations() result: the same
 * shape back, with `discount_pct` at the top and `net_total` beside every
 * published `total` (singles, entire, each combo and each combo room). Published
 * figures are never altered — the portal shows both. Pure: the totals in are
 * already the ONE pricing path's output; this only applies the percentage.
 */
function agent_price_configurations(array $cfg, array $agent, int $venueId): array {
    $net = fn($total): float => agent_net_price((float)$total, $agent, $venueId);
    foreach (['singles', 'entire'] as $section) {
        $items = is_array($cfg[$section] ?? null) ? $cfg[$section] : [];
        foreach ($items as $i => $item) $items[$i]['net_total'] = $net($item['total'] ?? 0);
        $cfg[$section] = $items;
    }
    $combos = is_array($cfg['combos'] ?? null) ? $cfg['combos'] : [];
    foreach ($combos as $i => $combo) {
        $combos[$i]['net_total'] = $net($combo['total'] ?? 0);
        $rooms = is_array($combo['rooms'] ?? null) ? $combo['rooms'] : [];
        foreach ($rooms as $j => $r) $rooms[$j]['net_total'] = $net($r['total'] ?? 0);
        $combos[$i]['rooms'] = $rooms;
    }
    $cfg['combos']       = $combos;
    $cfg['discount_pct'] = agent_discount_pct($agent, $venueId);
    return $cfg;
}

/** Combo rooms → "slug:units,slug:units" for a "Request these rooms" link. Pure. */
function agent_rooms_param(array $rooms): string {
    $parts = [];
    foreach ($rooms as $r) {
        $slug = (string)($r['slug'] ?? '');
        if ($slug === '') continue;
        $parts[] = $slug . ':' . max(1, (int)($r['units_used'] ?? $r['units'] ?? 1));
    }
    return implode(',', $parts);
}

/**
 * "slug:2,other:1" → [['slug' => 'slug', 'units' => 2], …]. Units clamp to 1..8,
 * malformed entries are dropped, slugs keep the room-slug alphabet only. Pure.
 */
function agent_parse_rooms_param(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $part) {
        $part = trim($part);
        if ($part === '' || !preg_match('/^([a-z0-9][a-z0-9_-]*)(?::(\d{1,2}))?$/i', $part, $m)) continue;
        $out[] = ['slug' => strtolower($m[1]), 'units' => max(1, min(8, (int)($m[2] ?? 1)))];
    }
    return $out;
}

/** 12.50 → "12.5", 10.00 → "10". Pure. */
function agent_pct_label(float $pct): string {
    return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
}

/**
 * The two human-readable lines every trade request carries into the staff email,
 * the stored message and admin: who booked it, and at what rate. Pure.
 * $quote: nights, published, net, currency, discount_pct (agent_stay_quote() shape).
 */
function agent_trade_lines(array $agent, array $quote): array {
    $name   = trim((string)($agent['name'] ?? ''));
    $agency = trim((string)($agent['agency'] ?? ''));
    $email  = trim((string)($agent['email'] ?? ''));
    $who    = $agency !== '' ? $agency . ' — ' . $name : $name;
    if ($email !== '') $who .= ' <' . $email . '>';

    $cur       = (string)($quote['currency'] ?? 'USD');
    $nights    = (int)($quote['nights'] ?? 0);
    $pct       = (float)($quote['discount_pct'] ?? 0);
    $nightsTxt = $nights . ' night' . ($nights === 1 ? '' : 's');
    $rate = $pct > 0
        ? format_price((float)($quote['net'] ?? 0), $cur) . ' net · ' . $nightsTxt . ' · '
          . agent_pct_label($pct) . '% off published ' . format_price((float)($quote['published'] ?? 0), $cur)
        : format_price((float)($quote['published'] ?? 0), $cur) . ' · ' . $nightsTxt . ' · published rate (no trade discount)';
    return ['agent' => $who, 'rate' => $rate];
}

/**
 * Portal-facing state of one agent_requests() row: the hold's status (with a
 * countdown while pending) or "Enquiry sent" when the request created no hold
 * (enquiry-mode room, or a room combination). Pure — pass $now for tests.
 */
function agent_request_status(array $row, ?int $now = null): array {
    $now = $now ?? time();
    $st  = (string)($row['hold_status'] ?? '');
    if ($st === '') return ['label' => 'Enquiry sent', 'class' => 'sent', 'note' => 'We’ll reply by email'];
    if ($st === 'pending') {
        $exp = !empty($row['expires_at']) ? strtotime((string)$row['expires_at']) : false;
        if ($exp === false) return ['label' => 'On hold', 'class' => 'pending', 'note' => 'Awaiting confirmation'];
        $left = $exp - $now;
        $note = $left > 0
            ? 'Expires in ' . intdiv($left, 3600) . 'h ' . str_pad((string)intdiv($left % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm'
            : 'Expiring…';
        return ['label' => 'On hold', 'class' => 'pending', 'note' => $note];
    }
    return match ($st) {
        'confirmed' => ['label' => 'Confirmed', 'class' => 'confirmed', 'note' => ''],
        'expired'   => ['label' => 'Expired',   'class' => 'expired',   'note' => 'Not confirmed in time — search again'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'cancelled', 'note' => ''],
        default     => ['label' => ucfirst($st), 'class' => 'expired', 'note' => ''],
    };
}
```

- [ ] **Step 4: Run**: `php tests/agent_portal_logic.php` → `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/agent.php tests/agent_portal_logic.php
git commit -m "feat(agents): pure trade-portal helpers — stay validation, net pricing of configurations, trade lines, request status"
```

---

### Task 3: `agent_stay_quote()` + `agent_room_form_mode()` (DB-backed)

**Files:**
- Modify: `includes/agent.php` (append)
- Test: `tests/agent_portal_logic.php` (DB block, inside the rolled-back transaction, after the existing agent-row checks)

- [ ] **Step 1: Write the failing tests** — inside the `if ($hasDb)` / `agents_supported()` branch, after `check('db: net price at the overridden venue …')`:

```php
            // ── Quote parity + form-mode rule ───────────────────────────────
            $agentRow = db_query("SELECT * FROM travel_agents WHERE email = 'zz-agent@example.com'")->fetch();
            $qRoom = db_query(
                "SELECT r.* FROM rooms r JOIN venues v ON v.id = r.venue_id
                  WHERE r.is_published = TRUE AND v.is_published = TRUE AND r.price_amount > 0
                  ORDER BY r.id LIMIT 1")->fetch();
            if (!$qRoom) {
                echo "SKIP  no priced published room for the quote checks\n";
            } else {
                $canon = room_stay_quote((int)$qRoom['id'], (float)$qRoom['price_amount'], '2098-06-10', '2098-06-12');
                $aq    = agent_stay_quote($qRoom, $agentRow, '2098-06-10', '2098-06-12');
                check('quote: published = room_stay_quote() (the ONE path)', eq($aq['published'], (float)$canon['total']) && $aq['nights'] === 2);
                check('quote: net = published × (1 − 12.5%)',              eq($aq['net'], round((float)$canon['total'] * 0.875, 2)));
                check('quote: currency is the room’s',                      $aq['currency'] === ($qRoom['price_currency'] ?: 'USD'));
                $bad = agent_stay_quote($qRoom, $agentRow, '2098-06-12', '2098-06-10');
                check('quote: a reversed window is NOT a quote (nights 0, net 0)', $bad['nights'] === 0 && $bad['net'] === 0.0);

                db_query("UPDATE rooms SET form_mode = 'enquiry' WHERE id = :id", [':id' => $qRoom['id']]);
                check('form mode: the room’s own enquiry mode wins', agent_room_form_mode(array_merge($qRoom, ['form_mode' => 'enquiry'])) === 'enquiry');
                $hasUnits = count(fetch_units_by_room(room_inventory_room_id($qRoom))) > 0;
                check('form mode: availability only when the inventory room has units',
                    agent_room_form_mode(array_merge($qRoom, ['form_mode' => 'availability'])) === ($hasUnits ? 'availability' : 'enquiry'));
            }
```
Also add `require_once __DIR__ . '/../includes/bookings.php';` at the top of the test (needed in Task 5).

- [ ] **Step 2: Run to verify failure**: `php tests/agent_portal_logic.php` → fatal "undefined function agent_stay_quote()".

- [ ] **Step 3: Implement** — append to `includes/agent.php`:

```php
/**
 * An agent's quote for one room over a stay: the published total from
 * room_stay_quote() — the ONE pricing path, override-aware — and the net total
 * with the agent's discount for that room's property. nights === 0 means "not a
 * quote" (unparseable / reversed window): callers must reject it before showing
 * or storing a price — neither a $0 stay nor an epoch night-count is safe.
 * $room needs id, venue_id, price_amount, price_currency (fetch_room_by_slug()).
 */
function agent_stay_quote(array $room, array $agent, string $checkIn, string $checkOut): array {
    $venueId   = (int)($room['venue_id'] ?? 0);
    $q         = room_stay_quote((int)$room['id'], (float)($room['price_amount'] ?? 0), $checkIn, $checkOut);
    $published = round((float)$q['total'], 2);
    return [
        'nights'       => (int)$q['nights'],
        'published'    => $published,
        'net'          => (int)$q['nights'] > 0 ? agent_net_price($published, $agent, $venueId) : 0.0,
        'currency'     => (string)(($room['price_currency'] ?? '') ?: 'USD'),
        'discount_pct' => agent_discount_pct($agent, $venueId),
    ];
}

/**
 * Whether a request for this room becomes a 24h HOLD or a plain ENQUIRY — the
 * exact rule api/submit-enquiry.php applies to the public widget: the room's own
 * form_mode, else the global `form_mode` setting, and never 'availability' when
 * the room's inventory room has no active unit to hold. The inventory question
 * goes through room_inventory_room_id(), so a Maya Ilai composite product asks
 * about the villa's units instead of silently downgrading to an enquiry.
 */
function agent_room_form_mode(array $room): string {
    $mode = !empty($room['form_mode']) ? (string)$room['form_mode'] : setting('form_mode', 'enquiry');
    if ($mode === 'availability') {
        try {
            if (count(fetch_units_by_room(room_inventory_room_id($room))) === 0) $mode = 'enquiry';
        } catch (Throwable $e) {
            $mode = 'enquiry';
        }
    }
    return $mode === 'availability' ? 'availability' : 'enquiry';
}
```

- [ ] **Step 4: Run**: `php tests/agent_portal_logic.php` → `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/agent.php tests/agent_portal_logic.php
git commit -m "feat(agents): agent stay quote through the one pricing path + the public form-mode rule"
```

---

### Task 4: The writer `agent_submit_request()` + `agent_requests()`

**Files:**
- Modify: `includes/agent.php` (append)
- Test: `tests/agent_portal_logic.php`

- [ ] **Step 1: Write the failing tests** — continue inside the same DB `else` branch, after the form-mode checks:

```php
            // ── Request writer round-trip (all rolled back) ──────────────────
            // A published, priced, individual, non-composite room with exactly ONE
            // active unit at a published property — so a second identical request
            // must be refused with a 409.
            $room = db_query(
                "SELECT r.* FROM rooms r JOIN venues v ON v.id = r.venue_id
                  WHERE r.is_published = TRUE AND v.is_published = TRUE AND r.is_entire_place = FALSE
                    AND r.price_amount > 0 AND r.slug NOT LIKE 'maya-ilai-%'
                    AND (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id AND u.is_active) = 1
                  ORDER BY r.id LIMIT 1")->fetch();
            if (!$room) {
                echo "SKIP  no single-unit published room to book against\n";
            } else {
                db_query("UPDATE rooms SET form_mode = 'availability' WHERE id = :id", [':id' => $room['id']]);
                $venueSlug = (string) db_query('SELECT slug FROM venues WHERE id = :id', [':id' => $room['venue_id']])->fetchColumn();
                $ci = '2098-06-10'; $co = '2098-06-12';
                $req = ['kind' => 'room', 'room_slug' => $room['slug'], 'check_in' => $ci, 'check_out' => $co,
                        'adults' => 2, 'children' => 1, 'guest_name' => 'ZZ Traveller',
                        'guest_email' => 'zz-trav@example.com', 'guest_phone' => '+254700000000', 'notes' => 'Late arrival'];
                $res = agent_submit_request($agentRow, $req, ['source_page' => 'test', 'ip' => '127.0.0.1']);
                check('request: hold mode succeeds', $res['ok'] === true && $res['mode'] === 'hold' && (int)$res['hold_id'] > 0);

                $hold = db_query('SELECT * FROM holds WHERE id = :id', [':id' => $res['hold_id']])->fetch();
                check('request: hold names the traveller, is emailed to the agent',
                    $hold['guest_name'] === 'ZZ Traveller' && $hold['guest_email'] === 'zz-agent@example.com');
                check('request: hold is pending with a 24h expiry', $hold['status'] === 'pending' && !empty($hold['expires_at']));
                check('request: availability block written',
                    (bool) db_query('SELECT 1 FROM availability_blocks WHERE hold_id = :h', [':h' => $res['hold_id']])->fetchColumn());

                $canon     = room_stay_quote((int)$room['id'], (float)$room['price_amount'], $ci, $co);
                $expectNet = agent_net_price((float)$canon['total'], $agentRow, (int)$room['venue_id']);
                check('request: net = published (ONE path) × (1 − discount)',
                    eq((float)$res['quote']['net'], $expectNet) && eq((float)$res['quote']['published'], (float)$canon['total']));
                if (holds_agent_supported()) check('request: holds.agent_id links the agent', (int)$hold['agent_id'] === (int)$agentRow['id']);
                else echo "SKIP  holds.agent_id absent — run add_holds_agent.sql\n";
                if (holds_quoted_amount_supported()) check('request: net frozen on the hold',
                    eq((float)$hold['quoted_amount'], $expectNet) && $hold['quoted_currency'] === ($room['price_currency'] ?: 'USD'));
                else echo "SKIP  holds.quoted_amount absent — run add_holds_quoted_amount.sql\n";

                $sub = db_query('SELECT * FROM submissions WHERE id = :id', [':id' => $res['submission_id']])->fetch();
                $pl  = json_decode((string)$sub['payload_json'], true);
                check('request: submission payload names the agent, source and net',
                    (int)$pl['agent_id'] === (int)$agentRow['id'] && $pl['source'] === 'trade-portal'
                    && eq((float)$pl['quoted_total'], $expectNet) && $pl['traveller_email'] === 'zz-trav@example.com');
                check('request: submission contact is the agent, guest is the traveller, message carries the trade lines',
                    $sub['guest_email'] === 'zz-agent@example.com' && $sub['guest_name'] === 'ZZ Traveller'
                    && (int)$sub['room_id'] === (int)$room['id'] && (int)$sub['guests_children'] === 1
                    && str_contains((string)$sub['message'], 'ZZ Agency') && str_contains((string)$sub['message'], 'Late arrival'));

                // "Your requests"
                $list = agent_requests($agentRow);
                check('requests: the request lists with its hold status',
                    count($list) === 1 && (int)$list[0]['hold_id'] === (int)$res['hold_id'] && $list[0]['hold_status'] === 'pending');
                check('requests: reads as "On hold"', agent_request_status($list[0])['label'] === 'On hold');

                // The race: the one unit is now held → refused, nothing written.
                $holdsBefore = (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn();
                $subsBefore  = (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn();
                $again = agent_submit_request($agentRow, $req, ['ip' => '127.0.0.1']);
                check('request: a second request for the one unit is refused (409)', $again['ok'] === false && $again['code'] === 409);
                check('request: … and writes nothing',
                    (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn() === $holdsBefore
                    && (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn() === $subsBefore);

                // Validation: traveller name required; past dates refused.
                check('request: traveller name is required', agent_submit_request($agentRow, ['guest_name' => ''] + $req)['code'] === 422);
                check('request: a past check-in is refused', agent_submit_request($agentRow, ['check_in' => '2020-01-01', 'check_out' => '2020-01-03'] + $req)['code'] === 422);

                // A combination is an ENQUIRY: submission only, no hold, rooms in the payload.
                $combo = agent_submit_request($agentRow, [
                    'kind' => 'combo', 'venue_slug' => $venueSlug, 'rooms' => [['slug' => $room['slug'], 'units' => 1]],
                    'check_in' => $ci, 'check_out' => $co, 'adults' => 2, 'children' => 0, 'guest_name' => 'ZZ Group',
                ], ['ip' => '127.0.0.1']);
                check('request: a combination is recorded as an enquiry with no hold',
                    $combo['ok'] === true && $combo['mode'] === 'enquiry' && $combo['hold_id'] === null);
                $csub = db_query('SELECT * FROM submissions WHERE id = :id', [':id' => $combo['submission_id']])->fetch();
                $cpl  = json_decode((string)$csub['payload_json'], true);
                check('request: combo submission has no room_id and lists the rooms',
                    $csub['room_id'] === null && str_contains((string)($cpl['rooms'] ?? ''), (string)$room['name']));
                $list2 = agent_requests($agentRow);
                check('requests: both list, newest first, the combo reads "Enquiry sent"',
                    count($list2) === 2 && agent_request_status($list2[0])['label'] === 'Enquiry sent');
                check('request: a room from another property is refused in a combo',
                    agent_submit_request($agentRow, ['kind' => 'combo', 'venue_slug' => $venueSlug,
                        'rooms' => [['slug' => 'no-such-room-zz', 'units' => 1]], 'check_in' => $ci, 'check_out' => $co,
                        'adults' => 2, 'children' => 0, 'guest_name' => 'X'])['code'] === 422);
            }
```

- [ ] **Step 2: Run to verify failure**: `php tests/agent_portal_logic.php` → fatal "undefined function agent_submit_request()".

- [ ] **Step 3: Implement** — append to `includes/agent.php`:

```php
/** Raised inside the request transaction when the dates are taken while we write. */
class AgentSoldOutException extends \RuntimeException {}

/**
 * Turn an agent's "Request to book" into the SAME records the public widget
 * creates: one submission (type 'enquiry'; payload.agent_* carries the trade
 * facts) and — when the room is in availability mode — one 24h hold written by
 * the same allocators the guest path uses (mi_allocate_and_hold() for a Maya Ilai
 * composite room, else find_available_unit() + create_hold_with_block()). The
 * hold carries holds.agent_id and freezes the NET price in holds.quoted_amount,
 * which bookings_sync_hold() snapshots at confirm time. A room combination, or a
 * room in enquiry mode, creates the submission only.
 *
 * $req: kind 'room' (room_slug) | 'combo' (venue_slug + rooms [['slug','units'],…]),
 *       check_in, check_out, adults, children, guest_name (the traveller, required),
 *       guest_email, guest_phone, notes.
 *
 * Contact of record = the AGENT. guest_email on the submission and the hold is
 * the agent's login email, so every automatic email (acknowledgement,
 * confirmation, cancellation, expiry, the manage link) and admin's reply reach
 * the trade partner; guest_name is the traveller. The traveller's own contact
 * details go into the payload and the message for reception.
 *
 * Runs in ONE transaction (joins the caller's when one is open, so a test can
 * roll everything back). Never sends email — the caller does, after commit.
 * Returns ['ok'=>true, submission_id, hold_id|null, mode 'hold'|'enquiry', quote,
 * trade, lines, venue, rooms_label, message, hold (joined row)|null, check_in,
 * check_out, adults, children, traveller, notes]
 * or ['ok'=>false, error, code 403|409|422|500].
 */
function agent_submit_request(array $agent, array $req, array $tracking = []): array {
    $err = fn(string $m, int $c = 422): array => ['ok' => false, 'error' => $m, 'code' => $c];
    $agentId    = (int)($agent['id'] ?? 0);
    $agentEmail = strtolower(trim((string)($agent['email'] ?? '')));
    if ($agentId <= 0 || !filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) return $err('Please sign in again.', 403);

    $stay = agent_valid_stay((string)($req['check_in'] ?? ''), (string)($req['check_out'] ?? ''));
    if ($stay === null) {
        return $err('Please choose a valid check-in and a later check-out — not in the past and up to ' . AGENT_MAX_STAY_NIGHTS . ' nights.');
    }
    [$ci, $co, $nights] = $stay;

    $adults    = max(1, min(30, (int)($req['adults'] ?? 1)));
    $children  = max(0, min(20, (int)($req['children'] ?? 0)));
    $traveller = mb_substr(trim((string)($req['guest_name'] ?? '')), 0, 255);
    if ($traveller === '') return $err('The travelling guest’s name is required.');
    $tEmail = trim((string)($req['guest_email'] ?? ''));
    if ($tEmail !== '' && !filter_var($tEmail, FILTER_VALIDATE_EMAIL)) return $err('The traveller’s email address doesn’t look right.');
    $tPhone = mb_substr(trim((string)($req['guest_phone'] ?? '')), 0, 50);
    $notes  = mb_substr(trim((string)($req['notes'] ?? '')), 0, 2000);

    // ── Resolve the product(s) and price them server-side — the ONE path ──
    $kind  = (($req['kind'] ?? 'room') === 'combo') ? 'combo' : 'room';
    $lines = [];   // [['room' => row, 'units' => int, 'quote' => agent_stay_quote()], …]
    if ($kind === 'room') {
        $room = fetch_room_by_slug(trim((string)($req['room_slug'] ?? '')));
        if (!$room || empty($room['is_published'])) return $err('That room isn’t available to book.');
        $venue = db_query('SELECT id, slug, name FROM venues WHERE id = :id AND is_published = TRUE',
            [':id' => $room['venue_id']])->fetch();
        if (!$venue) return $err('That property isn’t available to book.');
        $lines[] = ['room' => $room, 'units' => 1, 'quote' => agent_stay_quote($room, $agent, $ci, $co)];
    } else {
        $venue = db_query('SELECT id, slug, name FROM venues WHERE slug = :s AND is_published = TRUE',
            [':s' => trim((string)($req['venue_slug'] ?? ''))])->fetch();
        if (!$venue) return $err('That property isn’t available to book.');
        foreach ((is_array($req['rooms'] ?? null) ? $req['rooms'] : []) as $pick) {
            $room = fetch_room_by_slug(trim((string)($pick['slug'] ?? '')));
            if (!$room || empty($room['is_published']) || (int)$room['venue_id'] !== (int)$venue['id']) {
                return $err('One of those rooms isn’t available at this property.');
            }
            $lines[] = ['room' => $room, 'units' => max(1, min(8, (int)($pick['units'] ?? 1))),
                        'quote' => agent_stay_quote($room, $agent, $ci, $co)];
        }
        if (!$lines) return $err('Choose at least one room.');
    }
    $currency  = (string)$lines[0]['quote']['currency'];
    $published = 0.0;
    $net       = 0.0;
    foreach ($lines as $l) {
        if ((int)$l['quote']['nights'] === 0) return $err('We couldn’t price those dates. Please try again.');
        // Money is never summed across currencies.
        if ($l['quote']['currency'] !== $currency) return $err('Those rooms are priced in different currencies and can’t be requested together.');
        $published += $l['quote']['published'] * $l['units'];
        $net       += $l['quote']['net']       * $l['units'];
    }
    $venueId = (int)$venue['id'];
    $quote   = ['nights' => $nights, 'published' => round($published, 2), 'net' => round($net, 2),
                'currency' => $currency, 'discount_pct' => agent_discount_pct($agent, $venueId)];
    $trade   = agent_trade_lines($agent, $quote);

    $holdMode   = $kind === 'room' && agent_room_form_mode($lines[0]['room']) === 'availability';
    $roomsLabel = implode(', ', array_map(
        fn($l) => $l['room']['name'] . ($l['units'] > 1 ? ' ×' . $l['units'] : ''), $lines));

    // What staff read first — in the notification email and the inbox.
    $msg = ['Trade booking request via the agent portal' . ($holdMode ? '' : ' (enquiry — no hold placed)'),
            'Agent: ' . $trade['agent'],
            'Traveller: ' . $traveller . ($tEmail !== '' ? ' · ' . $tEmail : '') . ($tPhone !== '' ? ' · ' . $tPhone : ''),
            'Rate: ' . $trade['rate']];
    if ($kind === 'combo') $msg[] = 'Rooms: ' . $roomsLabel;
    if ($notes !== '') { $msg[] = ''; $msg[] = 'Agent note: ' . $notes; }
    $message = implode("\n", $msg);

    $payload = array_filter([
        'source'          => 'trade-portal',
        'agent_id'        => $agentId,
        'agent_name'      => (string)($agent['name'] ?? ''),
        'agency'          => (string)($agent['agency'] ?? ''),
        'agent_email'     => $agentEmail,
        'venue'           => (string)$venue['name'],
        'traveller_email' => $tEmail,
        'traveller_phone' => $tPhone,
        'discount_pct'    => $quote['discount_pct'],
        'published_total' => $quote['published'],
        'quoted_total'    => $quote['net'],
        'quoted_currency' => $currency,
        'quoted_label'    => $nights . ' night' . ($nights === 1 ? '' : 's') . ' · trade net rate'
            . ($quote['discount_pct'] > 0
                ? ' (' . agent_pct_label($quote['discount_pct']) . '% off published ' . format_price($quote['published'], $currency) . ')'
                : ''),
        'rooms'           => $kind === 'combo' ? $roomsLabel : '',
    ], fn($v) => $v !== '' && $v !== null);

    $pdo   = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    $holdId = null;
    try {
        $room = $lines[0]['room'];
        $unit = false;
        if ($holdMode) {
            // Fast pre-check; mi_allocate_and_hold() re-allocates under its own lock.
            $unit = find_available_unit((int)$room['id'], $ci, $co);
            if (!$unit) throw new AgentSoldOutException();
        }

        db_query(
            "INSERT INTO submissions
                (type, room_id, guest_name, guest_email, guest_phone, message,
                 check_in, check_out, guests_adults, guests_children, payload_json,
                 source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 user_agent, ip_address)
             VALUES
                ('enquiry', :room_id, :name, :email, :phone, :message,
                 :ci, :co, :adults, :children, :payload,
                 :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                 :ua, :ip)",
            [
                ':room_id'     => $kind === 'room' ? (int)$room['id'] : null,
                ':name'        => $traveller,
                ':email'       => $agentEmail,
                ':phone'       => $tPhone,
                ':message'     => $message,
                ':ci'          => $ci,
                ':co'          => $co,
                ':adults'      => $adults,
                ':children'    => $children,
                ':payload'     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':source_page' => (string)($tracking['source_page'] ?? ''),
                ':referrer'    => (string)($tracking['referrer'] ?? ''),
                ':utm_source'  => (string)($tracking['utm_source'] ?? 'trade-portal'),
                ':utm_medium'  => (string)($tracking['utm_medium'] ?? ''),
                ':utm_campaign'=> (string)($tracking['utm_campaign'] ?? ''),
                ':utm_term'    => (string)($tracking['utm_term'] ?? ''),
                ':utm_content' => (string)($tracking['utm_content'] ?? ''),
                ':ua'          => (string)($tracking['user_agent'] ?? ''),
                ':ip'          => (string)($tracking['ip'] ?? client_ip()),
            ]
        );
        $subId = (int)$pdo->lastInsertId();

        if ($holdMode) {
            if (mi_is_composite_room($room)) {
                $holdId = mi_allocate_and_hold($room, $subId, $ci, $co, $traveller, $agentEmail, 'pending', 24);
            } else {
                $holdId = create_hold_with_block((int)$unit['id'], $subId, $ci, $co, $traveller, $agentEmail,
                    'pending', 24, $unit['_mi_components'] ?? null, (int)$room['id']);
            }
            if ($holdId === false) throw new AgentSoldOutException();
            $holdId = (int)$holdId;
            if (holds_agent_supported()) {
                db_query('UPDATE holds SET agent_id = :a WHERE id = :id', [':a' => $agentId, ':id' => $holdId]);
            }
            if (holds_quoted_amount_supported()) {
                db_query('UPDATE holds SET quoted_amount = :q, quoted_currency = :c WHERE id = :id',
                    [':q' => $quote['net'], ':c' => $currency, ':id' => $holdId]);
            }
        }
        if ($ownTx) $pdo->commit();
    } catch (AgentSoldOutException $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        return $err('Those dates were taken while you were completing the request. Please search again.', 409);
    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[agent-request] failed: ' . $e->getMessage());
        return $err('Something went wrong saving the request. Please try again or contact reservations.', 500);
    }

    $hold = $holdId ? db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name
           FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
          WHERE h.id = :id", [':id' => $holdId]
    )->fetch() : null;

    return [
        'ok' => true, 'submission_id' => $subId, 'hold_id' => $holdId, 'mode' => $holdMode ? 'hold' : 'enquiry',
        'quote' => $quote, 'trade' => $trade, 'lines' => $lines, 'venue' => $venue, 'rooms_label' => $roomsLabel,
        'message' => $message, 'hold' => $hold ?: null, 'check_in' => $ci, 'check_out' => $co,
        'adults' => $adults, 'children' => $children, 'traveller' => $traveller, 'notes' => $notes,
    ];
}

/**
 * The agent's requests, newest first. Every request writes a submission whose
 * payload names the agent, so that is the source of truth — with or without the
 * holds.agent_id column. The latest hold on each submission supplies the status.
 * Each row carries a decoded `payload`.
 */
function agent_requests(array $agent, int $limit = 100): array {
    $aid = (int)($agent['id'] ?? 0);
    if ($aid <= 0) return [];
    $limit = max(1, min(500, $limit));
    $rows = db_query(
        "SELECT s.id, s.created_at, s.check_in, s.check_out, s.guest_name, s.room_id, s.payload_json,
                s.guests_adults, s.guests_children,
                r.name AS room_name, v.name AS venue_name,
                h.id AS hold_id, h.status AS hold_status, h.expires_at, h.access_code
           FROM submissions s
           LEFT JOIN rooms  r ON r.id = s.room_id
           LEFT JOIN venues v ON v.id = r.venue_id
           LEFT JOIN LATERAL (
                SELECT id, status, expires_at, access_code FROM holds
                 WHERE submission_id = s.id ORDER BY id DESC LIMIT 1
           ) h ON TRUE
          WHERE s.payload_json->>'agent_id' = :aid
          ORDER BY s.created_at DESC, s.id DESC
          LIMIT {$limit}",
        [':aid' => (string)$aid]
    )->fetchAll();
    foreach ($rows as $i => $r) {
        $pl = json_decode((string)($r['payload_json'] ?? '{}'), true);
        $rows[$i]['payload'] = is_array($pl) ? $pl : [];
    }
    return $rows;
}
```

- [ ] **Step 4: Run**: `php tests/agent_portal_logic.php` → `ALL PASS` (a couple of SKIP lines are fine only if a column is genuinely absent — after Task 1 none should print).

- [ ] **Step 5: Commit**

```bash
git add includes/agent.php tests/agent_portal_logic.php
git commit -m "feat(agents): transactional request writer — same hold path as guests, agent link + frozen net; request list"
```

---

### Task 5: Ledger — an agent hold books as `source = 'agent'`

**Files:**
- Modify: `includes/bookings.php` (`bookings_sync_hold()`, ~lines 91–175)
- Test: `tests/agent_portal_logic.php`

- [ ] **Step 1: Write the failing test** — inside the writer block of Task 4, right after the `check('requests: reads as "On hold"' …)` line and BEFORE the race check (the hold must still be pending for the race; confirming it here keeps the unit blocked, so the race check still 409s):

```php
                // Ledger: confirming snapshots source = 'agent' at the NET figure.
                if (bookings_supported() && holds_agent_supported() && holds_quoted_amount_supported()) {
                    db_query("UPDATE holds SET status = 'confirmed', confirmed_at = NOW() WHERE id = :id", [':id' => $res['hold_id']]);
                    bookings_sync_hold((int)$res['hold_id']);
                    $bk = db_query('SELECT * FROM bookings WHERE hold_id = :h', [':h' => $res['hold_id']])->fetch();
                    check('ledger: an agent hold books as source=agent, named by agency',
                        is_array($bk) && $bk['source'] === 'agent' && $bk['agent'] === 'ZZ Agency');
                    check('ledger: gross is the frozen net figure', is_array($bk) && eq((float)$bk['gross_amount'], $expectNet));
                } else {
                    echo "SKIP  ledger source check needs bookings + holds.agent_id + holds.quoted_amount\n";
                }
```

- [ ] **Step 2: Run to verify failure**: `php tests/agent_portal_logic.php` → `FAIL  ledger: an agent hold books as source=agent…` (source is `website`).

- [ ] **Step 3: Implement** — in `bookings_sync_hold()`:

Replace the `$qaCols` / `$h = db_query(` block with:

```php
    $qaCols = holds_quoted_amount_supported() ? 'h.quoted_amount, h.quoted_currency,' : '';
    // A hold requested through the trade portal is an AGENT booking. Read the link
    // (and the agency, for the report label) only where the column exists.
    $agOn   = holds_agent_supported();
    $agCols = $agOn ? 'h.agent_id, ta.name AS agent_name, ta.agency AS agent_agency,' : '';
    $agJoin = $agOn ? 'LEFT JOIN travel_agents ta ON ta.id = h.agent_id' : '';
    $h = db_query(
        "SELECT h.id, h.check_in, h.check_out, h.guest_name, h.guest_email, h.status,
                {$qaCols} {$agCols}
                u.id AS unit_id, r.id AS room_id, r.venue_id,
                r.price_amount, r.price_currency
         FROM holds h
         JOIN units u ON u.id = h.unit_id
         JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
         {$agJoin}
         WHERE h.id = :id",
        [':id' => $holdId]
    )->fetch();
    if (!$h) return;
```

Replace the `else { db_query("INSERT INTO bookings …` branch with:

```php
    } else {
        // Trade-portal holds are 'agent' bookings — the source the reports split on,
        // with the agency named beside them. Their gross is the frozen quoted_amount
        // above: the net figure the agent actually pays, never the rack rate.
        $isAgent  = !empty($h['agent_id']);
        $agentTag = $isAgent
            ? (trim((string)($h['agent_agency'] ?? '')) ?: trim((string)($h['agent_name'] ?? '')))
            : '';
        db_query(
            "INSERT INTO bookings (venue_id, room_id, unit_id, source, guest_name, guest_email, agent,
                    check_in, check_out, nights, gross_amount, currency, status, hold_id)
             VALUES (:v,:r,:u,:src,:gn,:ge,:ag,:ci,:co,:n,:g,:cur,:st,:h)",
            [':v'=>$h['venue_id'], ':r'=>$h['room_id'], ':u'=>$h['unit_id'], ':src'=>$isAgent ? 'agent' : 'website',
             ':gn'=>$h['guest_name'], ':ge'=>$h['guest_email'], ':ag'=>$agentTag,
             ':ci'=>$h['check_in'], ':co'=>$h['check_out'], ':n'=>$q['nights'],
             ':g'=>$gross, ':cur'=>$currency, ':st'=>$status, ':h'=>$holdId]
        );
    }
```
Also update the file's header comment bullet for the website writer: "(an agent-portal hold writes `source = 'agent'` + the agency, gross = its frozen net)".

- [ ] **Step 4: Run**: `php tests/agent_portal_logic.php` and `php tests/reports_logic.php` → both `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/bookings.php tests/agent_portal_logic.php
git commit -m "feat(ledger): trade-portal holds snapshot as source=agent at their frozen net"
```

---

### Task 6: Emails — trade rows on the hold notification, price row on the acknowledgement

**Files:**
- Modify: `includes/mail.php` (`send_hold_notification()` ~682–743, `_hold_notification_html()` ~745–793, `send_guest_acknowledgement()` ~299–301)
- Test: `tests/agent_portal_logic.php` (pure section; add `require_once __DIR__ . '/../includes/mail.php';` at the top)

- [ ] **Step 1: Write the failing test** (pure section):

```php
// ── Trade rows in the staff hold email (pure HTML builder) ───────────────────
$mailBase = ['guest_name' => 'ZZ Traveller', 'guest_email' => 'a@x.com', 'room_name' => 'Suite', 'unit_name' => 'Unit A',
             'check_in' => '2098-06-10', 'check_out' => '2098-06-12', 'expires' => '24 hours',
             'confirm_url' => '#', 'decline_url' => '#', 'holds_url' => '#', 'has_tokens' => false];
$plain = _hold_notification_html($mailBase);
$trade = _hold_notification_html($mailBase + ['trade_agent' => 'Safari Co — Jane <j@x.com>', 'trade_rate' => 'USD 850 net · 2 nights']);
check('mail: a guest hold email has no trade rows', !str_contains($plain, 'Booked by'));
check('mail: a trade hold email names the agent and the net rate',
    str_contains($trade, 'Booked by') && str_contains($trade, 'Safari Co') && str_contains($trade, 'USD 850 net'));
check('mail: trade values are escaped', str_contains($trade, '&lt;j@x.com&gt;'));
```

- [ ] **Step 2: Run to verify failure**: `FAIL  mail: a trade hold email names the agent…`.

- [ ] **Step 3: Implement**

In `send_hold_notification()`:
- after `$expires = …;` add
```php
    // Trade-portal requests pass the two lines from agent_trade_lines(); absent = a guest hold, unchanged.
    $tradeAgent = trim((string)($hold['trade_agent'] ?? ''));
    $tradeRate  = trim((string)($hold['trade_rate']  ?? ''));
```
- subject: `$subject = ($tradeAgent !== '' ? '[Trade Hold Request] ' : '[Hold Request] ') . "{$hold['room_name']} — {$hold['guest_name']} — {$hold['check_in']} to {$hold['check_out']}";`
- text: after the `"Expires:   {$expires}",` line, before `'',`: nothing; instead after the array is built add
```php
    if ($tradeAgent !== '') {
        array_splice($text_lines, 9, 0, ["Booked by: {$tradeAgent}", "Trade rate: {$tradeRate}"]);
    }
```
  (index 9 = the blank line after Expires, so the rows land inside the detail block).
- HTML call: add `'trade_agent' => $tradeAgent, 'trade_rate' => $tradeRate,` to the `_hold_notification_html([...])` array.

In `_hold_notification_html()`:
- header sub-line: replace the `<p style="margin:6px 0 0;color:#bcdfe6;font-size:14px">24-hour soft hold &mdash; please confirm or decline</p>` with
```php
            . '<p style="margin:6px 0 0;color:#bcdfe6;font-size:14px">' . (!empty($d['trade_agent']) ? 'Trade booking &middot; ' : '') . '24-hour soft hold &mdash; please confirm or decline</p>'
```
- after the Expires `<tr>` add:
```php
              . (!empty($d['trade_agent'])
                  ? '<tr><td style="padding:8px 0;color:#777;font-size:13px">Booked by</td>'
                      . '<td style="padding:8px 0;font-weight:600">' . $esc((string)$d['trade_agent']) . '</td></tr>'
                  . '<tr><td style="padding:8px 0;color:#777;font-size:13px">Trade rate</td>'
                      . '<td style="padding:8px 0;font-weight:700;color:#0f6f68">' . $esc((string)($d['trade_rate'] ?? '')) . '</td></tr>'
                  : '')
```

In `send_guest_acknowledgement()`, extend the label map: `'agency_name' => 'Agency', 'subject' => 'Subject', 'price' => 'Price'` — only the trade portal passes `price` (a preformatted string), so guest acknowledgements are unchanged.

- [ ] **Step 4: Run**: `php -l includes/mail.php` → no errors; `php tests/agent_portal_logic.php` → `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/mail.php tests/agent_portal_logic.php
git commit -m "feat(mail): trade rows on the hold notification, optional price row on the acknowledgement"
```

---

### Task 7: Portal chrome + `agent/availability.php`

**Files:**
- Modify: `agent/_layout.php`
- Create: `agent/availability.php`
- Manual check: `php -l` + a browser look (Task 12 does the full smoke)

- [ ] **Step 1: Layout — assets, nav, CSS.** In `agent/_layout.php`:

After the `<title>` line add:
```php
<link rel="stylesheet" href="/css/datepicker.css?v=<?= @filemtime(__DIR__ . '/../css/datepicker.css') ?: '1' ?>">
<script defer src="/js/datepicker.js?v=<?= @filemtime(__DIR__ . '/../js/datepicker.js') ?: '1' ?>"></script>
```
Append inside the `<style>` block (before `</style>`):
```css
  .ap-nav{display:flex;gap:4px;flex-wrap:wrap}
  .ap-nav a{padding:6px 10px;border-radius:8px;color:var(--mut);text-decoration:none;font-size:.9rem;font-weight:600}
  .ap-nav a:hover{background:var(--sand)}
  .ap-nav a.is-active{background:var(--teal);color:#fff}
  .ap-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end}
  .ap-form .field{margin:0}
  .field select,.field textarea{border:1px solid var(--line);border-radius:9px;padding:10px 12px;font:inherit;font-size:1rem;background:#fff;width:100%}
  .field .dp-btn{border-radius:9px;padding:10px 12px;font-size:1rem;border-color:var(--line);color:var(--mut)}
  .field .dp-btn--active{color:var(--ink)}
  .btn--auto{width:auto}
  .ap-btn{display:inline-block;text-decoration:none;border-radius:9px;padding:8px 12px;background:var(--teal);color:#fff;font-weight:700;font-size:.88rem;margin-top:6px}
  .ap-btn:hover{background:var(--teal-d)}
  .ap-btn--ghost{background:#fff;color:var(--teal-d);border:1px solid var(--teal)}
  .ap-btn--ghost:hover{background:var(--sand)}
  .ap-sec{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:#b8965a;font-weight:700;margin:14px 0 6px}
  .ap-opts{display:grid;gap:10px}
  .ap-opt{display:flex;justify-content:space-between;gap:14px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:12px 14px;flex-wrap:wrap}
  .ap-opt--entire{border-color:#b8965a;background:#fcf9f3}
  .ap-opt__name{font-weight:700}
  .ap-opt__meta{color:var(--mut);font-size:.82rem;margin-top:2px}
  .ap-opt__price{text-align:right}
  .ap-opt__price b{display:block;color:var(--teal-d);font-size:1.15rem}
  .ap-chip{display:inline-block;background:var(--sand);border:1px solid var(--line);border-radius:999px;padding:3px 9px;font-size:.78rem;margin:4px 4px 0 0}
  .ap-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px 18px;margin:0 0 16px}
  .ap-summary small{display:block;color:var(--mut);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px}
  .ap-summary span{font-weight:600}
  .ap-note{color:var(--mut);font-size:.85rem}
  .ap-status{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:700}
  .ap-status--pending{background:#fff4e5;color:#9a5b00}
  .ap-status--confirmed{background:#eef7ee;color:#2f6b36}
  .ap-status--expired,.ap-status--cancelled{background:#f1ece5;color:#6b6050}
  .ap-status--sent{background:#eef5f7;color:#0f6f68}
  .alert-success{background:#eef7ee;border:1px solid #cfe6cf;color:#2f6b36}
  table th.ap-left,table td.ap-left{text-align:left}
```
Replace the header's `<?php if ($__agent): ?> … <?php endif; ?>` block with:
```php
  <?php if ($__agent): ?>
  <nav class="ap-nav" aria-label="Trade portal">
    <a href="/agent/availability.php" class="<?= ($agentActive ?? '') === 'availability' ? 'is-active' : '' ?>">Availability</a>
    <a href="/agent/rates.php" class="<?= ($agentActive ?? '') === 'rates' ? 'is-active' : '' ?>">Rates</a>
    <a href="/agent/requests.php" class="<?= ($agentActive ?? '') === 'requests' ? 'is-active' : '' ?>">Your requests</a>
  </nav>
  <div class="ap-user"><?= e($__agent['name']) ?><?= trim((string)$__agent['agency']) !== '' ? ' · ' . e($__agent['agency']) : '' ?> · <a href="/agent/logout.php">Sign out</a></div>
  <?php endif; ?>
```

- [ ] **Step 2: Create `agent/availability.php`**

```php
<?php
declare(strict_types=1);
/**
 * Trade portal — live availability. The agent picks dates, party and (optionally)
 * one property; every published venue in scope is resolved with
 * ts_property_configurations() — the SAME capacity-aware brain and the ONE
 * pricing path the property pages and /search use — and
 * agent_price_configurations() adds the net figure beside each published one.
 * Every option links into request.php. Server-rendered GET; the only JS is the
 * shared datepicker.
 */
require_once __DIR__ . '/../includes/agent.php';

agent_require_login();
$agent = agent_current();

$venuesAll = db_query('SELECT * FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();

$ciRaw    = trim((string)($_GET['check_in']  ?? ''));
$coRaw    = trim((string)($_GET['check_out'] ?? ''));
$adults   = max(1, min(30, (int)($_GET['adults']   ?? 2)));
$children = max(0, min(20, (int)($_GET['children'] ?? 0)));
$venueSel = trim((string)($_GET['venue'] ?? ''));
$guests   = $adults + $children;

$searched = ($ciRaw !== '' || $coRaw !== '');
$error    = '';
$stay     = $searched ? agent_valid_stay($ciRaw, $coRaw) : null;
$results  = [];   // [['venue' => row, 'cfg' => priced configurations], …]

if ($searched && $stay === null) {
    $error = 'Please choose a check-in date from today and a later check-out (up to ' . AGENT_MAX_STAY_NIGHTS . ' nights).';
} elseif ($stay !== null) {
    [$ci, $co, $nights] = $stay;
    foreach ($venuesAll as $v) {
        if ($venueSel !== '' && $v['slug'] !== $venueSel) continue;
        try {
            $cfg = ts_property_configurations($v, $ci, $co, $guests, null, true);
        } catch (Throwable $e) {
            error_log('[agent-availability] ' . $e->getMessage());
            $error = 'We could not check live availability right now. Please try again in a moment.';
            $results = [];
            break;
        }
        $results[] = ['venue' => $v, 'cfg' => agent_price_configurations($cfg, $agent, (int)$v['id'])];
    }
}

$fmtDate = fn(string $d): string => date('D j M Y', strtotime($d));
$reqUrl  = fn(array $params): string => '/agent/request.php?' . http_build_query($params + [
    'check_in' => $stay[0] ?? '', 'check_out' => $stay[1] ?? '', 'adults' => $adults, 'children' => $children,
]);
/** Published (struck through when discounted) + net, for one option. */
$priceCell = function (array $o, float $pct): string {
    $cur = (string)($o['currency'] ?? 'USD');
    $pub = format_price((float)$o['total'], $cur);
    $net = format_price((float)($o['net_total'] ?? $o['total']), $cur);
    return ($pct > 0 ? '<span class="ap-was">' . e($pub) . '</span> ' : '') . '<b>' . e($net) . '</b>';
};
$nightsTxt = $stay ? $stay[2] . ' night' . ($stay[2] === 1 ? '' : 's') : '';

$agentPageTitle = 'Check availability';
$agentActive    = 'availability';
include __DIR__ . '/_layout.php';
?>
<h1>Check availability</h1>
<p class="ap-sub">Live availability across every property, priced at your trade rate. Choose dates and party size, then request the option you want — dates are held for 24 hours while reservations confirm.</p>

<div class="ap-card">
  <form method="GET" action="/agent/availability.php" class="ap-form">
    <div class="field">
      <label for="apCi">Check-in</label>
      <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="apStay" data-dp-target="apCi" data-dp-placeholder="Add date">Add date</button>
      <input type="hidden" id="apCi" name="check_in" value="<?= e($stay[0] ?? $ciRaw) ?>">
    </div>
    <div class="field">
      <label for="apCo">Check-out</label>
      <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="apStay" data-dp-target="apCo" data-dp-placeholder="Add date">Add date</button>
      <input type="hidden" id="apCo" name="check_out" value="<?= e($stay[1] ?? $coRaw) ?>">
    </div>
    <div class="field"><label for="apAd">Adults</label><input type="number" id="apAd" name="adults" min="1" max="30" value="<?= (int)$adults ?>"></div>
    <div class="field"><label for="apCh">Children</label><input type="number" id="apCh" name="children" min="0" max="20" value="<?= (int)$children ?>"></div>
    <div class="field">
      <label for="apVenue">Property</label>
      <select id="apVenue" name="venue">
        <option value="">All properties</option>
        <?php foreach ($venuesAll as $v): ?>
        <option value="<?= e($v['slug']) ?>" <?= $venueSel === $v['slug'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><button type="submit" class="btn">Check availability</button></div>
  </form>
  <?php if ($error): ?><div class="alert alert-error" style="margin:14px 0 0"><?= e($error) ?></div><?php endif; ?>
</div>

<?php if ($stay !== null && !$error): [$ci, $co, $nights] = $stay; ?>
<p class="ap-sub"><strong><?= e($fmtDate($ci)) ?> → <?= e($fmtDate($co)) ?></strong> · <?= e($nightsTxt) ?> ·
  <?= $guests ?> guest<?= $guests === 1 ? '' : 's' ?> (<?= $adults ?> adult<?= $adults === 1 ? '' : 's' ?><?= $children ? ', ' . $children . ' child' . ($children === 1 ? '' : 'ren') : '' ?>)</p>

<?php foreach ($results as $res): $v = $res['venue']; $cfg = $res['cfg']; $pct = (float)$cfg['discount_pct'];
      $has = $cfg['singles'] || $cfg['combos'] || $cfg['entire']; ?>
<div class="ap-card">
  <h2><?= e($v['name']) ?></h2>
  <p class="ap-cardsub"><?php if ($pct > 0): ?>Your rate: <span class="ap-badge"><?= e(agent_pct_label($pct)) ?>% off published</span><?php else: ?>Published rates (no trade discount set for this property).<?php endif; ?></p>

  <?php if (!$has): ?>
    <p class="ap-note">Nothing fits <?= $guests ?> guest<?= $guests === 1 ? '' : 's' ?> for these dates<?= !empty($cfg['max_capacity']) ? ' — the property sleeps up to ' . (int)$cfg['max_capacity'] . ' for this window' : '' ?>.</p>
  <?php else: ?>

    <?php if ($cfg['singles']): ?>
    <div class="ap-sec">Available rooms</div>
    <div class="ap-opts">
      <?php foreach ($cfg['singles'] as $o): ?>
      <div class="ap-opt">
        <div>
          <div class="ap-opt__name"><?= e($o['name']) ?></div>
          <div class="ap-opt__meta"><?= !empty($o['capacity']) ? 'Sleeps up to ' . (int)$o['capacity'] . ' · ' : '' ?><?= e($nightsTxt) ?></div>
        </div>
        <div class="ap-opt__price"><?= $priceCell($o, $pct) ?><a class="ap-btn" href="<?= e($reqUrl(['room' => $o['slug']])) ?>">Request to book</a></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($cfg['combos']): ?>
    <div class="ap-sec">For <?= $guests ?> guests we suggest<?= count($cfg['combos']) > 1 ? ' — best fit first' : '' ?></div>
    <div class="ap-opts">
      <?php foreach ($cfg['combos'] as $c): ?>
      <div class="ap-opt">
        <div>
          <div class="ap-opt__name">Combination · sleeps <?= (int)$c['capacity'] ?></div>
          <div><?php foreach ($c['rooms'] as $cr): ?><span class="ap-chip"><?= e($cr['name']) ?><?= (int)$cr['units_used'] > 1 ? ' ×' . (int)$cr['units_used'] : '' ?> · <?= e(format_price((float)($cr['net_total'] ?? $cr['total']), (string)$cr['currency'])) ?></span><?php endforeach; ?></div>
          <div class="ap-opt__meta">Sent as an enquiry — reservations confirm the rooms and price by email.</div>
        </div>
        <div class="ap-opt__price"><?= $priceCell($c, $pct) ?><a class="ap-btn ap-btn--ghost" href="<?= e($reqUrl(['venue' => $v['slug'], 'rooms' => agent_rooms_param($c['rooms'])])) ?>">Request these rooms</a></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($cfg['entire']): ?>
    <div class="ap-sec"><?= ($cfg['singles'] || $cfg['combos']) ? 'Or the whole property' : 'The whole property' ?></div>
    <div class="ap-opts">
      <?php foreach ($cfg['entire'] as $o): ?>
      <div class="ap-opt ap-opt--entire">
        <div>
          <div class="ap-opt__name"><?= e($o['name']) ?> <span class="ap-badge">Whole property</span></div>
          <div class="ap-opt__meta"><?= !empty($o['capacity']) ? 'Sleeps up to ' . (int)$o['capacity'] . ' · ' : '' ?><?= e($nightsTxt) ?></div>
        </div>
        <div class="ap-opt__price"><?= $priceCell($o, $pct) ?><a class="ap-btn" href="<?= e($reqUrl(['room' => $o['slug']])) ?>">Request to book</a></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$results): ?><div class="ap-card">No published properties to check.</div><?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 3: Lint**: `php -l agent/_layout.php && php -l agent/availability.php` → "No syntax errors".

- [ ] **Step 4: Commit**

```bash
git add agent/_layout.php agent/availability.php
git commit -m "feat(agents): portal nav + live availability page at the agent's net rate"
```

---

### Task 8: `agent/request.php` + `agent_send_request_emails()`

**Files:**
- Modify: `includes/agent.php` (append the email helper)
- Create: `agent/request.php`

- [ ] **Step 1: Append the email helper to `includes/agent.php`**

```php
/**
 * After a request is committed: the staff notification — the hold email with the
 * trade rows, or the plain enquiry notification whose stored message already
 * opens with them — and the agent's acknowledgement, addressed to the agent with
 * the net price on record. Best-effort: a mail failure never undoes a saved request.
 */
function agent_send_request_emails(array $agent, array $res): void {
    require_once __DIR__ . '/mail.php';
    $trade = $res['trade'];
    $where = $res['venue']['name'] . ' — ' . $res['rooms_label'];
    try {
        if ($res['mode'] === 'hold' && !empty($res['hold'])) {
            send_hold_notification($res['hold'] + ['trade_agent' => $trade['agent'], 'trade_rate' => $trade['rate']]);
        } else {
            send_notification([
                'id'              => $res['submission_id'],
                'type'            => 'enquiry',
                'room_name'       => $where,
                'guest_name'      => $res['traveller'],
                'guest_email'     => (string)$agent['email'],
                'guest_phone'     => '',
                'message'         => $res['message'],
                'check_in'        => $res['check_in'],
                'check_out'       => $res['check_out'],
                'guests_adults'   => $res['adults'],
                'guests_children' => $res['children'],
                'created_at'      => date('Y-m-d H:i:s'),
                'source_page'     => 'Trade portal',
                'utm_source'      => 'trade-portal',
            ]);
        }
        send_guest_acknowledgement([
            'kind'            => $res['mode'] === 'hold' ? 'hold' : 'enquiry',
            'guest_name'      => (string)$agent['name'],
            'guest_email'     => (string)$agent['email'],
            'agency_name'     => (string)($agent['agency'] ?? ''),
            'room_name'       => $where,
            'check_in'        => $res['check_in'],
            'check_out'       => $res['check_out'],
            'guests_adults'   => $res['adults'],
            'guests_children' => $res['children'],
            'price'           => $trade['rate'],
            'message'         => 'Booking for: ' . $res['traveller'] . ($res['notes'] !== '' ? "\n" . $res['notes'] : ''),
            'hold_id'         => (int)($res['hold_id'] ?? 0),
            'access_code'     => (string)($res['hold']['access_code'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('[agent-request] mail failed: ' . $e->getMessage());
    }
}
```

- [ ] **Step 2: Create `agent/request.php`**

```php
<?php
declare(strict_types=1);
/**
 * Trade portal — "Request to book". GET shows the option (re-quoted and re-checked
 * live), the net price and the traveller form; POST (CSRF) writes the request
 * through agent_submit_request() — the same submission + 24h hold the public
 * widget creates, tagged as a trade booking — then emails and redirects (PRG) to
 * the request list. Nothing from the form is trusted for money: the price is
 * re-derived from the agent row and the room on every request.
 */
require_once __DIR__ . '/../includes/agent.php';

agent_require_login();
$agent = agent_current();

$in  = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$str = fn(string $k): string => trim((string)($in[$k] ?? ''));

$req = [
    'kind'        => ($str('venue') !== '' && $str('rooms') !== '') ? 'combo' : 'room',
    'room_slug'   => $str('room'),
    'venue_slug'  => $str('venue'),
    'rooms'       => agent_parse_rooms_param($str('rooms')),
    'check_in'    => $str('check_in'),
    'check_out'   => $str('check_out'),
    'adults'      => max(1, min(30, (int)($in['adults'] ?? 1))),
    'children'    => max(0, min(20, (int)($in['children'] ?? 0))),
    'guest_name'  => $str('guest_name'),
    'guest_email' => $str('guest_email'),
    'guest_phone' => $str('guest_phone'),
    'notes'       => $str('notes'),
];
$backUrl = '/agent/availability.php?' . http_build_query([
    'check_in' => $req['check_in'], 'check_out' => $req['check_out'],
    'adults' => $req['adults'], 'children' => $req['children'],
]);

// ── Resolve + quote what the link points at (read-only) ─────────────────────
$error = '';
$view  = null;
$stay  = agent_valid_stay($req['check_in'], $req['check_out']);
if ($stay === null) {
    $error = 'Those dates aren’t valid any more — please search again.';
} else {
    [$ci, $co, $nights] = $stay;
    $lines = [];
    $venue = false;
    if ($req['kind'] === 'room') {
        $room  = fetch_room_by_slug($req['room_slug']);
        $venue = ($room && !empty($room['is_published']))
            ? db_query('SELECT id, slug, name FROM venues WHERE id = :id AND is_published = TRUE', [':id' => $room['venue_id']])->fetch()
            : false;
        if ($room && $venue) $lines[] = ['room' => $room, 'units' => 1, 'quote' => agent_stay_quote($room, $agent, $ci, $co)];
    } else {
        $venue = db_query('SELECT id, slug, name FROM venues WHERE slug = :s AND is_published = TRUE', [':s' => $req['venue_slug']])->fetch();
        foreach ($venue ? $req['rooms'] : [] as $pick) {
            $room = fetch_room_by_slug($pick['slug']);
            if (!$room || empty($room['is_published']) || (int)$room['venue_id'] !== (int)$venue['id']) { $lines = []; break; }
            $lines[] = ['room' => $room, 'units' => $pick['units'], 'quote' => agent_stay_quote($room, $agent, $ci, $co)];
        }
    }
    if (!$venue || !$lines) {
        $error = 'That option isn’t available to book — please search again.';
    } else {
        $currency  = (string)$lines[0]['quote']['currency'];
        $published = 0.0;
        $net       = 0.0;
        $available = true;
        foreach ($lines as $l) {
            if ((int)$l['quote']['nights'] === 0 || $l['quote']['currency'] !== $currency) {
                $error = 'That option can’t be priced — please search again.';
                break;
            }
            $published += $l['quote']['published'] * $l['units'];
            $net       += $l['quote']['net']       * $l['units'];
            // Live re-check, so the form never invites a request for dates that just went.
            $free = $req['kind'] === 'room'
                ? (bool) find_available_unit((int)$l['room']['id'], $ci, $co)
                : count_available_units((int)$l['room']['id'], $ci, $co, $l['room']) >= $l['units'];
            if (!$free) $available = false;
        }
        if ($error === '') {
            $view = [
                'venue' => $venue, 'lines' => $lines, 'ci' => $ci, 'co' => $co, 'nights' => $nights,
                'quote' => ['nights' => $nights, 'published' => round($published, 2), 'net' => round($net, 2),
                            'currency' => $currency, 'discount_pct' => agent_discount_pct($agent, (int)$venue['id'])],
                'available' => $available,
                'hold_mode' => $req['kind'] === 'room' && agent_room_form_mode($lines[0]['room']) === 'availability',
            ];
        }
    }
}

// ── POST: write the request, then email, then PRG ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $view !== null) {
    verify_csrf();
    if (!$view['available']) {
        $error = 'Those dates were taken while you were completing the request. Please search again.';
    } else {
        $res = agent_submit_request($agent, $req, [
            'source_page' => site_url('/agent/request.php'),
            'utm_source'  => 'trade-portal',
            'utm_medium'  => 'agent-portal',
            'user_agent'  => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip'          => client_ip(),
        ]);
        if (!$res['ok']) {
            $error = $res['error'];
        } else {
            agent_send_request_emails($agent, $res);   // best-effort, after commit
            header('Location: /agent/requests.php?sent=' . (int)$res['submission_id']);
            exit;
        }
    }
}

$agentPageTitle = 'Request to book';
$agentActive    = 'availability';
include __DIR__ . '/_layout.php';
?>
<h1>Request to book</h1>

<?php if ($error !== '' && $view === null): ?>
  <div class="alert alert-error"><?= e($error) ?></div>
  <p><a href="<?= e($backUrl) ?>">← Back to availability</a></p>
<?php else: $q = $view['quote']; $pct = (float)$q['discount_pct']; ?>
<div class="ap-card">
  <h2><?= e($view['venue']['name']) ?></h2>
  <p class="ap-cardsub"><?= $view['hold_mode']
      ? 'Dates are held for 24 hours while reservations confirm — nothing is charged now.'
      : 'This request is sent as an enquiry — reservations confirm availability and price by email.' ?></p>

  <div class="ap-summary">
    <div><small>Room<?= count($view['lines']) > 1 ? 's' : '' ?></small><span>
      <?php foreach ($view['lines'] as $l): ?><?= e($l['room']['name']) ?><?= $l['units'] > 1 ? ' ×' . (int)$l['units'] : '' ?><br><?php endforeach; ?>
    </span></div>
    <div><small>Dates</small><span><?= e(date('D j M Y', strtotime($view['ci']))) ?> → <?= e(date('D j M Y', strtotime($view['co']))) ?></span></div>
    <div><small>Nights</small><span><?= (int)$view['nights'] ?></span></div>
    <div><small>Guests</small><span><?= (int)$req['adults'] ?> adult<?= $req['adults'] === 1 ? '' : 's' ?><?= $req['children'] ? ', ' . (int)$req['children'] . ' child' . ($req['children'] === 1 ? '' : 'ren') : '' ?></span></div>
    <div><small>Published</small><span><?= e(format_price((float)$q['published'], $q['currency'])) ?></span></div>
    <div><small>Your rate<?= $pct > 0 ? ' · ' . e(agent_pct_label($pct)) . '% off' : '' ?></small><span class="ap-net"><?= e(format_price((float)$q['net'], $q['currency'])) ?></span></div>
  </div>

  <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <?php if (!$view['available']): ?>
    <div class="alert alert-error">Those dates have just been taken. <a href="<?= e($backUrl) ?>">Search again →</a></div>
  <?php else: ?>
  <form method="POST" action="/agent/request.php" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="room"      value="<?= e($req['room_slug']) ?>">
    <input type="hidden" name="venue"     value="<?= e($req['venue_slug']) ?>">
    <input type="hidden" name="rooms"     value="<?= e(agent_rooms_param($req['rooms'])) ?>">
    <input type="hidden" name="check_in"  value="<?= e($view['ci']) ?>">
    <input type="hidden" name="check_out" value="<?= e($view['co']) ?>">
    <input type="hidden" name="adults"    value="<?= (int)$req['adults'] ?>">
    <input type="hidden" name="children"  value="<?= (int)$req['children'] ?>">
    <div class="ap-form" style="margin-bottom:14px">
      <div class="field"><label for="rqName">Travelling guest’s name</label><input type="text" id="rqName" name="guest_name" value="<?= e($req['guest_name']) ?>" required autofocus></div>
      <div class="field"><label for="rqEmail">Guest’s email (optional)</label><input type="email" id="rqEmail" name="guest_email" value="<?= e($req['guest_email']) ?>"></div>
      <div class="field"><label for="rqPhone">Guest’s phone (optional)</label><input type="tel" id="rqPhone" name="guest_phone" value="<?= e($req['guest_phone']) ?>"></div>
    </div>
    <div class="field"><label for="rqNotes">Notes for reservations (optional)</label><textarea id="rqNotes" name="notes" rows="3"><?= e($req['notes']) ?></textarea></div>
    <p class="ap-note">We’ll write to you at <strong><?= e($agent['email']) ?></strong> — you are the contact for this booking; the traveller is not emailed.</p>
    <button type="submit" class="btn btn--auto">Request to book</button>
    <a href="<?= e($backUrl) ?>" style="margin-left:12px">Back to availability</a>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 3: Lint**: `php -l includes/agent.php && php -l agent/request.php`.

- [ ] **Step 4: Commit**

```bash
git add includes/agent.php agent/request.php
git commit -m "feat(agents): request-to-book page — live re-check, net quote, PRG write + trade emails"
```

---

### Task 9: `agent/requests.php` + login/rates copy

**Files:**
- Create: `agent/requests.php`
- Modify: `agent/login.php`, `agent/rates.php`

- [ ] **Step 1: Create `agent/requests.php`**

```php
<?php
declare(strict_types=1);
/**
 * Trade portal — the agent's requests and what became of each: on hold (with the
 * expiry countdown), confirmed, expired, cancelled, or "enquiry sent" for a
 * request that placed no hold. Read from the submission payload
 * (agent_requests()), so it works with or without holds.agent_id.
 */
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/booking.php';   // make_manage_url()

agent_require_login();
$agent = agent_current();

$sent      = (int)($_GET['sent'] ?? 0);
$rows      = [];
$loadError = '';
try {
    $rows = agent_requests($agent);
} catch (Throwable $e) {
    error_log('[agent-requests] ' . $e->getMessage());
    $loadError = 'We could not load your requests right now. Please try again shortly.';
}

$agentPageTitle = 'Your requests';
$agentActive    = 'requests';
include __DIR__ . '/_layout.php';
?>
<h1>Your requests</h1>
<p class="ap-sub">Every request you have sent, and its status. Reservations confirm each one by email to <?= e($agent['email']) ?>.</p>

<?php if ($sent): ?><div class="alert alert-success">Request sent — reservations will confirm by email. Dates on hold are kept for 24 hours.</div><?php endif; ?>
<?php if ($loadError): ?><div class="alert alert-error"><?= e($loadError) ?></div><?php endif; ?>

<div class="ap-card">
<?php if (!$rows && !$loadError): ?>
  <p class="ap-note" style="margin:0">No requests yet. <a href="/agent/availability.php">Check availability →</a></p>
<?php elseif ($rows): ?>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Sent</th><th class="ap-left">Property · room</th><th class="ap-left">Dates</th><th class="ap-left">Traveller</th><th>Your rate</th><th class="ap-left">Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pl = $r['payload']; $st = agent_request_status($r);
          $nights = (int) round((strtotime((string)$r['check_out']) - strtotime((string)$r['check_in'])) / 86400);
          $manage = (!empty($r['hold_id']) && in_array((string)$r['hold_status'], ['pending', 'confirmed'], true)) ? make_manage_url((int)$r['hold_id']) : ''; ?>
      <tr>
        <td><?= e(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
        <td class="ap-left"><strong><?= e((string)($r['venue_name'] ?? ($pl['venue'] ?? ''))) ?></strong><br><span class="ap-note"><?= e((string)($r['room_name'] ?? ($pl['rooms'] ?? ''))) ?></span></td>
        <td class="ap-left"><?= e(date('j M Y', strtotime((string)$r['check_in']))) ?> → <?= e(date('j M Y', strtotime((string)$r['check_out']))) ?><br><span class="ap-note"><?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></span></td>
        <td class="ap-left"><?= e((string)$r['guest_name']) ?></td>
        <td class="ap-net"><?= isset($pl['quoted_total']) ? e(format_price((float)$pl['quoted_total'], (string)($pl['quoted_currency'] ?? 'USD'))) : '—' ?></td>
        <td class="ap-left"><span class="ap-status ap-status--<?= e($st['class']) ?>"><?= e($st['label']) ?></span><?= $st['note'] !== '' ? '<br><span class="ap-note">' . e($st['note']) . '</span>' : '' ?></td>
        <td><?php if ($manage !== ''): ?><a href="<?= e($manage) ?>">Manage</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: `agent/login.php`** — both `header('Location: /agent/rates.php')` → `/agent/availability.php`; sub copy → `Sign in to check live availability, see your agreed rates and request bookings across all Tribal Sand properties.`

- [ ] **Step 3: `agent/rates.php`** — set `$agentActive = 'rates';` beside `$agentPageTitle`, and change the sub copy to `Nightly rates from, across every property, with your agreed discount already applied. <a href="/agent/availability.php">Check live availability for your dates →</a>` (echoed raw — page-authored, no user input).

- [ ] **Step 4: Lint + commit**

```bash
php -l agent/requests.php && php -l agent/login.php && php -l agent/rates.php
git add agent/requests.php agent/login.php agent/rates.php
git commit -m "feat(agents): 'Your requests' page; portal lands on availability"
```

---

### Task 10: Admin surfaces — Trade badge, booking header, copy, source label

**Files:**
- Modify: `admin/holds.php` (~lines 96–120 query, ~209–212 row), `admin/booking.php` (~15–20 query, ~313 header line), `admin/agents.php` (lines 112 + 137), `admin/submissions.php` (`source_label()` ~137)

- [ ] **Step 1: `admin/holds.php`** — replace the `$holdsFrom = …` assignment with:

```php
// Trade bookings: name the agent beside the traveller (only once add_holds_agent.sql has run).
$agOn   = holds_agent_supported();
$agCols = $agOn ? ', ta.name AS agent_name, ta.agency AS agent_agency' : '';
$agJoin = $agOn ? ' LEFT JOIN travel_agents ta ON ta.id = h.agent_id' : '';

$holdsFrom = "FROM holds h
     JOIN units u ON u.id = h.unit_id
     JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "{$agJoin}
     {$where}";
```
and in the page query change `r.venue_id AS venue_id` → `r.venue_id AS venue_id{$agCols}`. In the row, right after the `<a href="mailto:…">` line inside the Guest cell add:

```php
            <?php if (!empty($hold['agent_id'])): ?>
            <div style="margin-top:4px"><span class="badge badge--blue" title="Requested through the trade portal">Trade · <?= e(trim((string)($hold['agent_agency'] ?? '')) ?: (string)($hold['agent_name'] ?? 'agent')) ?></span></div>
            <?php endif; ?>
```

- [ ] **Step 2: `admin/booking.php`** — replace the `$hold = $holdId ? db_query(…)` statement with:

```php
$agOn = holds_agent_supported();   // trade-portal link, only once add_holds_agent.sql has run
$hold = $holdId ? db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.venue_id AS venue_id, v.name AS venue_name"
    . ($agOn ? ", ta.name AS agent_name, ta.agency AS agent_agency, ta.email AS agent_email" : "") . "
     FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=" . hold_room_id_sql('h', 'u') . "
     LEFT JOIN venues v ON v.id=r.venue_id"
    . ($agOn ? " LEFT JOIN travel_agents ta ON ta.id=h.agent_id" : "") . "
     WHERE h.id=:id", [':id'=>$holdId]
)->fetch() : null;
```
Add `require_once __DIR__ . '/../includes/services.php';   // format_price() for the trade net figure` with the other requires. In the `<p class="text-muted" …>` header line, before the closing `</p>` insert:

```php
<?php if (!empty($hold['agent_id'])): ?> · <span class="badge badge--blue">Trade booking</span> via <strong><?= e(trim((string)($hold['agent_agency'] ?? '')) ?: (string)($hold['agent_name'] ?? '')) ?></strong> (<?= e((string)($hold['agent_name'] ?? '')) ?>, <a href="mailto:<?= e((string)($hold['agent_email'] ?? '')) ?>"><?= e((string)($hold['agent_email'] ?? '')) ?></a>)<?php if (!empty($hold['quoted_amount'])): ?> · net <?= e(format_price((float)$hold['quoted_amount'], (string)(($hold['quoted_currency'] ?? '') ?: 'USD'))) ?><?php endif; ?><?php endif; ?>
```

- [ ] **Step 3: `admin/agents.php` copy**
  - line 112: `External trade partners with their own portal — set a discount and they see live net prices, check availability and send booking requests that land here as trade holds.`
  - line 137: `Agents log in separately at <code>/agent/login.php</code> — they see their rates, check live availability and request to book there, never this admin panel. A request creates the same 24-hour hold a guest request does, marked <strong>Trade</strong> in Holds &amp; Bookings.`

- [ ] **Step 4: `admin/submissions.php`** — in `source_label()` add `str_starts_with($page, 'request') => 'Trade portal',` before `default`.

- [ ] **Step 5: Lint + commit**

```bash
php -l admin/holds.php && php -l admin/booking.php && php -l admin/agents.php && php -l admin/submissions.php
git add admin/holds.php admin/booking.php admin/agents.php admin/submissions.php
git commit -m "feat(admin): trade badge on holds + booking header; agents copy; trade-portal source label"
```

---

### Task 11: CLAUDE.md — "Travel-agent portal" section

**Files:**
- Modify: `CLAUDE.md` (new `###` section under Key Conventions, after the Financial reports section; File Map rows for `includes/agent.php`, `agent/*`, `admin/agents.php`)

- [ ] **Step 1: Add the section**

```markdown
### Travel-agent (trade) portal — isolated login, ONE price, agent-tagged holds
External agents sign in at **`/agent/login.php`** (migration `add_travel_agents.sql`; accounts managed in Admin → Travel agents, `require_owner()`). Helpers in **`includes/agent.php`**; every read is pre-migration-safe (`agents_supported()`, `holds_agent_supported()`, `holds_quoted_amount_supported()`). Test: `php tests/agent_portal_logic.php`.
- **Identity isolation is load-bearing.** Agents live in `travel_agents` and their session key is `agent_id`, never `admin_id`, so an agent session satisfies no admin guard. Never model agents as an `admin_users` role.
- **ONE price.** An agent's figure is ALWAYS the published price × (1 − discount) resolved at render time — `rates_from_price()` on the rates page, `room_stay_quote()` / `ts_property_configurations()` on availability (`agent_stay_quote()`, `agent_price_configurations()`). No parallel net-rate is stored. `agent_discount_pct()` = per-venue override (`venue_discounts` JSONB) else flat `discount_pct`, clamped 0..100.
- **Availability + "Request to book" reuse the guest path** (`agent/availability.php` → `agent/request.php`). `agent_submit_request()` writes, in ONE transaction, a `submissions` row (type `enquiry`; `payload_json.agent_id/agency/quoted_total(=net)/source='trade-portal'`) plus — when `agent_room_form_mode()` (the same rule as `api/submit-enquiry.php`) says availability — a 24h hold through the SAME allocators (`find_available_unit()` + `create_hold_with_block()`, or `mi_allocate_and_hold()` for a Maya Ilai composite room). Never add a second allocation path. A room **combination** becomes an enquiry with no hold (guest v1 parity).
- **The hold carries the trade facts:** `holds.agent_id` (migration `add_holds_agent.sql`, after `add_travel_agents`) and the **net price frozen in `holds.quoted_amount`** (migration `add_holds_quoted_amount.sql`), which `bookings_sync_hold()` snapshots at confirm time with `source='agent'` + the agency. Both columns are written only when supported; pre-migration the request still succeeds and the payload still names the agent. **Apply both migrations on production** or the ledger books agent holds as website revenue at the rack rate.
- **Contact of record = the agent.** `guest_email` on the submission and the hold is the agent's login email (all automatic emails, the manage link and admin replies go to the agent); `guest_name` is the traveller; the traveller's own email/phone live in the payload + message. `send_hold_notification()` takes optional `trade_agent`/`trade_rate` rows; `send_guest_acknowledgement()` takes an optional `price` row — absent, both emails are unchanged.
- **"Your requests"** (`agent/requests.php`, `agent_requests()`) reads `submissions WHERE payload_json->>'agent_id'` (expression index in the migration) LEFT JOIN the latest hold — it does not depend on `holds.agent_id`.
- **Known limits:** Maya Ilai is priced off the rate card (villa + studios only; the configurator's band/group/eco-fee pricing and per-bedroom products are not offered to agents — the same as `/search` today); agents cannot cancel from the portal (they use the emailed manage link, like a guest).
```
File Map rows:
```markdown
| `includes/agent.php` | Trade-portal helpers — isolated agent auth, discount/net pricing, `agent_submit_request()` (transactional request writer), request list |
| `agent/availability.php` · `agent/request.php` · `agent/requests.php` | Trade portal pages — live availability at net rate, request-to-book (PRG), request status list |
| `admin/agents.php` | Travel-agent accounts (owner-only) — discount, per-property overrides, activate/reset/delete |
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: travel-agent portal conventions"
```

---

### Task 12: Full test run + browser smoke test

- [ ] **Step 1: All related suites**

```bash
php tests/agent_portal_logic.php && php tests/rates_logic.php && php tests/capacity_search_logic.php && php tests/reports_logic.php && php tests/maya_ilai_inventory.php
```
Expected: each ends with `ALL PASS`.

- [ ] **Step 2: Seed a local test agent** (local DB only; removed in Step 5):

```bash
php -r 'require "includes/db.php"; db_query("INSERT INTO travel_agents (name, agency, email, password_hash, discount_pct) VALUES (:n,:a,:e,:h,15) ON CONFLICT (email) DO NOTHING", [":n"=>"Smoke Agent", ":a"=>"Smoke Travel", ":e"=>"smoke-agent@example.com", ":h"=>password_hash("smoke-pass-123", PASSWORD_DEFAULT)]); echo "ok\n";'
```

- [ ] **Step 3: Drive the portal** with the Browser pane (`preview_start` name `tribal-sand`): sign in at `/agent/login.php` → lands on Availability → search a far-future window for 2 adults → options show published struck + net → "Request to book" a room → summary shows net → submit → redirected to Your requests with "On hold · Expires in 23h …".

- [ ] **Step 4: Check admin**: `/admin/holds.php` shows the hold with the **Trade · Smoke Travel** badge; `/admin/booking.php?hold=<id>` header shows "Trade booking via Smoke Travel … · net …"; `/admin/submission-view.php?id=<sub>` shows "Price at enquiry" with the net figure and the Agent/Agency payload rows. Confirm the hold; `SELECT source, agent, gross_amount FROM bookings WHERE hold_id=<id>` → `agent | Smoke Travel | <net>`.

- [ ] **Step 5: Clean up the local smoke data**

```bash
php -r 'require "includes/db.php"; $a = db_query("SELECT id FROM travel_agents WHERE email = :e", [":e"=>"smoke-agent@example.com"])->fetchColumn(); if ($a) { foreach (db_query("SELECT id FROM holds WHERE agent_id = :a", [":a"=>$a])->fetchAll(PDO::FETCH_COLUMN) as $h) { db_query("DELETE FROM bookings WHERE hold_id = :h", [":h"=>$h]); db_query("DELETE FROM availability_blocks WHERE hold_id = :h", [":h"=>$h]); db_query("DELETE FROM holds WHERE id = :h", [":h"=>$h]); } db_query("DELETE FROM submissions WHERE payload_json->>\x27agent_id\x27 = :a", [":a"=>(string)$a]); db_query("DELETE FROM travel_agents WHERE id = :a", [":a"=>$a]); } echo "cleaned\n";'
```
