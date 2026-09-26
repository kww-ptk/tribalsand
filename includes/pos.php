<?php
declare(strict_types=1);
/**
 * Point of Sale — catalogue, totals, permissions, the ONE sale write path, void.
 * Spec: docs/pos/POS-PLAN.md. Migration: db/migrations/add_pos.sql.
 * Test: php tests/pos_logic.php
 *
 * Load-bearing rules:
 *   • The server re-prices every line. The client sends item ids + qty (+ an open
 *     price for an unpriced item); a posted price is never trusted.
 *   • pos_complete_sale() is the only writer of a sale; pos_void_sale() the only
 *     way to reverse one. Room-charge bill rows change through nothing else.
 *   • A sale is single-currency (its outlet's). A room charge is allowed only when
 *     the outlet currency equals the bill currency — never converted silently.
 *   • Stock is a ledger (pos_stock_moves); stock_qty is the cached running total,
 *     updated under SELECT … FOR UPDATE in the same transaction.
 *   • Every read is pre-migration-safe via pos_supported() (a catalog lookup,
 *     never a failing SELECT — these run inside transactions).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pos-support.php';   // pos_supported(), pos_bill_link_supported()
require_once __DIR__ . '/booking.php';     // bill_item_guest_supported(), fetch_bill_items()
require_once __DIR__ . '/frontdesk.php';   // frontdesk_rows(), frontdesk_today_ymd()

const POS_KINDS            = ['experiences' => 'Experiences', 'shop' => 'Shop', 'salon_spa' => 'Salon & Spa', 'kite' => 'Kite school', 'other' => 'Other'];
const POS_ITEM_KINDS       = ['product' => 'Product', 'service' => 'Service'];
const POS_PAYMENT_METHODS  = ['card' => 'Card', 'cash' => 'Cash', 'room_charge' => 'Room charge', 'mobile_money' => 'M-Pesa', 'other' => 'Other'];
const POS_MAX_LINES        = 100;
const POS_MAX_QTY          = 999;
const POS_MAX_OPEN_PRICE   = 1000000.0;
const POS_DEFAULT_CURRENCY = 'KES';   // outlets sell in Kenyan shillings (owner, Sept 2026); editable per outlet

// ── Pure helpers ────────────────────────────────────────────────────────────

/** Postgres/PDO boolean → PHP bool ('t', true, 1, '1', 'true'). */
function pos_bool(mixed $v): bool {
    return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
}

/** Money → integer cents, half-up (all POS arithmetic is done in cents). */
function pos_cents(mixed $amount): int {
    return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
}

/** Integer cents → float with 2 dp. */
function pos_from_cents(int $cents): float {
    return round($cents / 100, 2);
}

/** Integer division rounded half-up, for non-negative numerators — PURE. */
function pos_div_round(int $num, int $den): int {
    return intdiv(2 * $num + $den, 2 * $den);
}

/**
 * Cart totals — PURE, integer-cents math. $lines: each has `line_total`, or
 * `unit_price` + `qty`.
 *
 *   subtotal        Σ lines
 *   service_charge  subtotal × service %                       (half-up to the cent)
 *   vat             inclusive: (subtotal + service) × v/(100+v) — already inside the price
 *                   exclusive: (subtotal + service) × v/100     — added on top
 *   tip             added last; never carries service charge or VAT
 *   total           subtotal + service (+ VAT when exclusive) + tip
 *
 * Returns floats: subtotal, service_charge, vat, vat_inclusive, before_tip, tip, total.
 */
function pos_cart_totals(array $lines, float $servicePct, float $vatPct = 0.0, bool $vatInclusive = true, float $tip = 0.0): array {
    $sub = 0;
    foreach ($lines as $l) {
        $sub += array_key_exists('line_total', $l)
            ? pos_cents($l['line_total'])
            : pos_cents($l['unit_price'] ?? 0) * max(0, (int)($l['qty'] ?? 0));
    }
    $svcBp = (int) round(max(0.0, min(100.0, $servicePct)) * 100);   // 10.00% → 1000
    $svc   = pos_div_round($sub * $svcBp, 10000);
    $base  = $sub + $svc;
    $vatBp = (int) round(max(0.0, min(100.0, $vatPct)) * 100);
    $vat   = $vatBp === 0 ? 0 : ($vatInclusive ? pos_div_round($base * $vatBp, 10000 + $vatBp) : pos_div_round($base * $vatBp, 10000));
    $before = $base + ($vatInclusive ? 0 : $vat);
    $tipC  = max(0, pos_cents($tip));
    return [
        'subtotal'       => pos_from_cents($sub),
        'service_charge' => pos_from_cents($svc),
        'vat'            => pos_from_cents($vat),
        'vat_inclusive'  => $vatInclusive,
        'before_tip'     => pos_from_cents($before),
        'tip'            => pos_from_cents($tipC),
        'total'          => pos_from_cents($before + $tipC),
    ];
}

/**
 * The tip in cents, from a percentage of the pre-tip total or a typed amount — PURE.
 * Returns an error string for nonsense; a tip above the bill itself is refused as a
 * fat-finger (100% is the ceiling).
 */
function pos_tip_cents(int $beforeTipCents, mixed $pct, mixed $amount): int|string {
    if ($pct !== null && $pct !== '') {
        if (!is_numeric($pct) || (float)$pct < 0 || (float)$pct > 100) return 'Pick a tip between 0 and 100%.';
        return pos_div_round($beforeTipCents * (int) round((float)$pct * 100), 10000);
    }
    if ($amount === null || $amount === '') return 0;
    if (!is_numeric($amount) || (float)$amount < 0) return 'The tip must be zero or more.';
    $c = pos_cents($amount);
    if ($c > $beforeTipCents) return 'That tip is more than the bill — check the amount.';
    return $c;
}

/**
 * Resolve one cart line against the live catalogue — PURE.
 * Price precedence: item price → linked tour price → open price entered at sale.
 * $item is a pos_items row (its own `consign_pct`, plus `consignor_commission_pct` =
 * the joined supplier's default); $tour is the linked tours row or null.
 * Returns the line array, or an error string.
 */
function pos_resolve_line(array $item, ?array $tour, int $qty, ?float $openPrice): array|string {
    $name = (string)($item['name'] ?? 'Item');
    if (!pos_bool($item['is_active'] ?? true)) return "{$name} is no longer on sale.";
    if ($qty < 1)            return "Quantity for {$name} must be at least 1.";
    if ($qty > POS_MAX_QTY)  return "Quantity for {$name} is too large.";

    $perPerson = pos_bool($item['per_person'] ?? false);
    if (isset($item['price']) && $item['price'] !== null && $item['price'] !== '') {
        $unit = (float) $item['price'];
    } elseif ($tour !== null && isset($tour['price_amount']) && $tour['price_amount'] !== null && $tour['price_amount'] !== '') {
        $unit      = (float) $tour['price_amount'];
        $perPerson = pos_bool($tour['price_per_person'] ?? false);
    } elseif ($openPrice !== null) {
        if (!is_finite($openPrice) || $openPrice <= 0) return "Enter a price above zero for {$name}.";
        if ($openPrice > POS_MAX_OPEN_PRICE)           return "The price entered for {$name} is too large.";
        $unit = $openPrice;
    } else {
        return "{$name} has no price — enter one at the till.";
    }
    if ($unit < 0) return "{$name} has an invalid price.";

    $unitCents = pos_cents($unit);
    $consignorId = isset($item['consignor_id']) && $item['consignor_id'] !== null ? (int)$item['consignor_id'] : null;
    $cost = ($consignorId && isset($item['consignor_cost']) && $item['consignor_cost'] !== null && $item['consignor_cost'] !== '')
        ? (float)$item['consignor_cost'] : null;
    // Terms, most specific first: a fixed amount per unit we owe; else this item's own
    // commission % (set when the delivery was received); else the supplier's default %.
    $pct = null;
    if ($consignorId && $cost === null) {
        if (isset($item['consign_pct']) && $item['consign_pct'] !== null && $item['consign_pct'] !== '') $pct = (float)$item['consign_pct'];
        elseif (isset($item['consignor_commission_pct']) && $item['consignor_commission_pct'] !== null) $pct = (float)$item['consignor_commission_pct'];
    }

    return [
        'item_id'                  => (int)($item['id'] ?? 0) ?: null,
        'owning_outlet_id'         => (int)($item['outlet_id'] ?? 0),
        'tour_id'                  => isset($item['tour_id']) && $item['tour_id'] !== null ? (int)$item['tour_id'] : null,
        'name'                     => mb_substr($name, 0, 160),
        'kind'                     => in_array($item['kind'] ?? '', ['product','service'], true) ? $item['kind'] : 'product',
        'qty'                      => $qty,
        'unit_price'               => pos_from_cents($unitCents),
        'line_total'               => pos_from_cents($unitCents * $qty),
        'per_person'               => $perPerson,
        'consignor_id'             => $consignorId,
        'consignor_commission_pct' => $pct,
        'consignor_cost'           => $cost,
    ];
}

/** Stock refusal for selling $qty of $item — PURE. null when fine. */
function pos_stock_shortfall(array $item, int $qty): ?string {
    if (!pos_bool($item['track_stock'] ?? false) || pos_bool($item['allow_negative'] ?? false)) return null;
    $have = (int)($item['stock_qty'] ?? 0);
    if ($have - $qty >= 0) return null;
    $name = (string)($item['name'] ?? 'This item');
    return $have <= 0 ? "{$name} is out of stock." : "Only {$have} left of {$name}.";
}

/**
 * Whether a booking can take a room charge from this outlet — PURE.
 * $hold: status, expires_at, check_in, check_out, venue_id, venue_name.
 * $outlet: allow_room_charge, charge_venue_ids (int[]; EMPTY = every property).
 * Currency is NOT a reason any more: a KES outlet posts to a USD bill at the
 * day's rate (pos_fx_rate()). Returns null when OK, else the reason shown under
 * the disabled Room charge button.
 */
function pos_room_charge_eligible(array $hold, string $todayYmd, array $outlet): ?string {
    if (!pos_bool($outlet['allow_room_charge'] ?? true)) return 'Room charge is turned off for this outlet.';
    $status = (string)($hold['status'] ?? '');
    // Same "real booking" rule as the Front Desk: confirmed, or a staff-typed pending
    // (no TTL). A web enquiry always carries a 24h expiry and is not a stay yet.
    $real = $status === 'confirmed' || ($status === 'pending' && empty($hold['expires_at']));
    if (!$real) return 'This booking is not confirmed, so it has no bill to charge.';
    $in  = substr((string)($hold['check_in']  ?? ''), 0, 10);
    $out = substr((string)($hold['check_out'] ?? ''), 0, 10);
    if ($in === '' || $out === '' || $todayYmd < $in || $todayYmd > $out) return 'This guest is not in house today.';
    $allowed = array_map('intval', (array)($outlet['charge_venue_ids'] ?? []));
    if ($allowed && !in_array((int)($hold['venue_id'] ?? 0), $allowed, true)) {
        $v = trim((string)($hold['venue_name'] ?? ''));
        return ($v !== '' ? "Guests of {$v}" : 'Guests of that property') . ' can’t charge to their room here.';
    }
    return null;
}

/**
 * Exchange rate between two currencies from a USD-based rate table — PURE.
 * Returns units of $from per 1 unit of $to (KES→USD at 129 KES/USD → 129.0),
 * 1.0 for the same currency, or null when a rate is missing.
 */
function pos_fx_rate_from(array $rates, string $from, string $to): ?float {
    $from = strtoupper($from); $to = strtoupper($to);
    if ($from === $to) return 1.0;
    $rf = (float)($rates[$from] ?? 0); $rt = (float)($rates[$to] ?? 0);
    if ($rf <= 0 || $rt <= 0) return null;
    return round($rf / $rt, 6);
}

/** Today's rate from the site FX table (Admin → currency settings). */
function pos_fx_rate(string $from, string $to): ?float {
    return pos_fx_rate_from(fx_rates()['rates'] ?? [], $from, $to);
}

/** Convert an amount in the sale currency to the bill currency at $rate — PURE, to the cent. */
function pos_fx_convert(float $amount, float $rate): float {
    if ($rate <= 0) return 0.0;
    return pos_from_cents((int) round(pos_cents($amount) / $rate, 0, PHP_ROUND_HALF_UP));
}

/** A guest signature: a PNG data URL, 8 B – 250 KB, real PNG bytes — PURE. */
function pos_valid_signature(string $s): bool {
    $prefix = 'data:image/png;base64,';
    if (strncmp($s, $prefix, strlen($prefix)) !== 0) return false;
    $bin = base64_decode(substr($s, strlen($prefix)), true);
    if ($bin === false) return false;
    $len = strlen($bin);
    return $len >= 8 && $len <= 250 * 1024 && strncmp($bin, "\x89PNG\r\n\x1a\n", 8) === 0;
}

/** Receipt prefix from an outlet slug: 'salon-spa' → 'SALONSPA' (max 10). */
function pos_ref_prefix(string $slug): string {
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $slug) ?? '');
    return substr($p !== '' ? $p : 'POS', 0, 10);
}

/** A URL-safe slug from a name. */
function pos_slugify(string $name): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-'));
    return substr($s !== '' ? $s : 'outlet', 0, 60);
}

/**
 * Bill line label for a room charge — PURE. "<Outlet (property)>: <items> — <note>
 * (<ref>)". The note carries the original amount + rate for a converted charge.
 * The note and the reference always survive; only the item summary is cut to fit
 * bill_items.label (VARCHAR 200).
 */
function pos_bill_label(string $outletName, array $lines, string $reference, string $note = ''): string {
    $parts = [];
    foreach ($lines as $l) {
        $q = (int)($l['qty'] ?? 1);
        $parts[] = ($q > 1 ? $q . '× ' : '') . (string)($l['name'] ?? '');
    }
    $tail = ($note !== '' ? ' — ' . $note : '') . ' (' . $reference . ')';
    $head = $outletName . ': ' . implode(', ', $parts);
    $room = 200 - mb_strlen($tail);
    if (mb_strlen($head) > $room) $head = rtrim(mb_substr($head, 0, $room - 1)) . '…';
    return $head . $tail;
}

/** What we owe the supplier for one consignment line — PURE. 0 for our own stock. */
function pos_consignor_owed(array $line): float {
    if (empty($line['consignor_id'])) return 0.0;
    $qty = max(0, (int)($line['qty'] ?? 0));
    if (isset($line['consignor_cost']) && $line['consignor_cost'] !== null && $line['consignor_cost'] !== '') {
        return pos_from_cents(pos_cents($line['consignor_cost']) * $qty);
    }
    $pct = (float)($line['consignor_commission_pct'] ?? 0);
    $gross = pos_cents($line['line_total'] ?? 0);
    $ours  = intdiv($gross * (int)round($pct * 100) + 5000, 10000);
    return pos_from_cents($gross - $ours);
}

/** A client idempotency key: 8–64 chars of [A-Za-z0-9-]. */
function pos_valid_uuid(string $u): bool {
    return (bool) preg_match('/^[A-Za-z0-9-]{8,64}$/', $u);
}

// ── Transactions ────────────────────────────────────────────────────────────

/**
 * Run $fn atomically. Opens a transaction when none is open; inside an existing
 * one (tests wrap everything in a rolled-back transaction) it uses a SAVEPOINT,
 * so a refused sale discards its own partial writes without aborting the caller's.
 */
function pos_tx(callable $fn): mixed {
    static $depth = 0;
    $pdo = db();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    $sp = 'pos_sp_' . (++$depth);
    $pdo->exec("SAVEPOINT {$sp}");
    try { $r = $fn(); $pdo->exec("RELEASE SAVEPOINT {$sp}"); $depth--; return $r; }
    catch (Throwable $e) {
        try { $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}"); $pdo->exec("RELEASE SAVEPOINT {$sp}"); } catch (Throwable $ignored) {}
        $depth--;
        throw $e;
    }
}

/** A refusal the caller shows to the user (vs an unexpected error). */
final class PosRefusal extends RuntimeException {}

// ── Catalogue reads ─────────────────────────────────────────────────────────

/** Outlets, optionally limited to $ids (null = all). */
function pos_fetch_outlets(?array $ids = null, bool $activeOnly = true): array {
    if (!pos_supported()) return [];
    if ($ids !== null && !$ids) return [];
    $where = []; $p = [];
    if ($activeOnly) $where[] = 'o.is_active = TRUE';
    if ($ids !== null) {
        $ph = [];
        foreach (array_values($ids) as $i => $v) { $ph[] = ":o{$i}"; $p[":o{$i}"] = (int)$v; }
        $where[] = 'o.id IN (' . implode(',', $ph) . ')';
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return db_query(
        "SELECT o.*, v.name AS venue_name FROM pos_outlets o
           LEFT JOIN venues v ON v.id = o.venue_id
         {$w} ORDER BY o.sort_order, o.name", $p
    )->fetchAll();
}

function pos_fetch_outlet(int $id): array|false {
    if (!pos_supported() || $id <= 0) return false;
    return db_query("SELECT o.*, v.name AS venue_name FROM pos_outlets o LEFT JOIN venues v ON v.id = o.venue_id WHERE o.id = :id", [':id' => $id])->fetch();
}

function pos_fetch_categories(int $outletId): array {
    if (!pos_supported()) return [];
    return db_query("SELECT * FROM pos_categories WHERE outlet_id = :o ORDER BY sort_order, name", [':o' => $outletId])->fetchAll();
}

/** Properties whose guests may room-charge at an outlet. [] = every property (the default). */
function pos_outlet_charge_venue_ids(int $outletId): array {
    if (!pos_v2_supported()) return [];
    return array_map('intval', db_query('SELECT venue_id FROM pos_outlet_charge_venues WHERE outlet_id = :o ORDER BY venue_id', [':o' => $outletId])->fetchAll(PDO::FETCH_COLUMN));
}

/** "All properties" or "Tribal Dunes, Maya Ilai" — for the admin + till. */
function pos_charge_venue_label(array $venueIds): string {
    if (!$venueIds) return 'All properties';
    $ph = []; $p = [];
    foreach (array_values($venueIds) as $i => $v) { $ph[] = ":v{$i}"; $p[":v{$i}"] = (int)$v; }
    return implode(', ', db_query('SELECT name FROM venues WHERE id IN (' . implode(',', $ph) . ') ORDER BY sort_order, name', $p)->fetchAll(PDO::FETCH_COLUMN));
}

/** Outlets whose items $outletId may sell: itself + its "also sells from" sources. */
function pos_sellable_outlet_ids(int $outletId): array {
    if (!pos_supported()) return [];
    $src = db_query("SELECT source_outlet_id FROM pos_outlet_links WHERE outlet_id = :o", [':o' => $outletId])->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_merge([$outletId], array_map('intval', $src))));
}

/** Item columns + the linked tour's price and the consignor's commission, for pricing. */
function pos_item_select_sql(): string {
    return "SELECT i.*, c.name AS category_name, o.name AS outlet_name,
                   t.name AS tour_name, t.price_amount AS tour_price, t.price_per_person AS tour_per_person,
                   t.is_published AS tour_published,
                   cs.name AS consignor_name, cs.commission_pct AS consignor_commission_pct
              FROM pos_items i
              JOIN pos_outlets o      ON o.id = i.outlet_id
              LEFT JOIN pos_categories c  ON c.id = i.category_id
              LEFT JOIN tours t           ON t.id = i.tour_id
              LEFT JOIN pos_consignors cs ON cs.id = i.consignor_id";
}

/** Items owned by $outletId (and, with $withLinked, by the outlets it cross-sells). */
function pos_fetch_items(int $outletId, bool $activeOnly = true, bool $withLinked = false): array {
    if (!pos_supported()) return [];
    $ids = $withLinked ? pos_sellable_outlet_ids($outletId) : [$outletId];
    $ph = []; $p = [];
    foreach ($ids as $i => $v) { $ph[] = ":o{$i}"; $p[":o{$i}"] = (int)$v; }
    $w = 'i.outlet_id IN (' . implode(',', $ph) . ')' . ($activeOnly ? ' AND i.is_active = TRUE' : '');
    return db_query(pos_item_select_sql() . " WHERE {$w} ORDER BY (i.outlet_id <> {$outletId}), c.sort_order NULLS LAST, i.sort_order, i.name", $p)->fetchAll();
}

function pos_fetch_item(int $id): array|false {
    if (!pos_supported() || $id <= 0) return false;
    return db_query(pos_item_select_sql() . ' WHERE i.id = :id', [':id' => $id])->fetch();
}

/** The tour row shape pos_resolve_line() expects, from a joined item row. */
function pos_item_tour(array $item): ?array {
    if (empty($item['tour_id'])) return null;
    return ['price_amount' => $item['tour_price'] ?? null, 'price_per_person' => $item['tour_per_person'] ?? false];
}

/** Display price of an item: its own, else its tour's; null = open price at sale. */
function pos_item_display_price(array $item): ?float {
    if (isset($item['price']) && $item['price'] !== null && $item['price'] !== '') return (float)$item['price'];
    if (!empty($item['tour_id']) && isset($item['tour_price']) && $item['tour_price'] !== null && $item['tour_price'] !== '') return (float)$item['tour_price'];
    return null;
}

function pos_fetch_consignors(bool $activeOnly = false): array {
    if (!pos_supported()) return [];
    return db_query('SELECT * FROM pos_consignors' . ($activeOnly ? ' WHERE is_active = TRUE' : '') . ' ORDER BY name')->fetchAll();
}

/** Money for the till and POS admin — like format_money() but keeps cents when present ($12.50). */
function pos_money(float $amount, string $currency): string {
    $cur = strtoupper(trim($currency));
    $sym = defined('TS_CURRENCIES') ? (TS_CURRENCIES[$cur]['symbol'] ?? ($cur . ' ')) : ($cur . ' ');
    $dp  = abs($amount - round($amount)) < 0.005 ? 0 : 2;
    return $sym . number_format($amount, $dp);
}

// ── Permissions ─────────────────────────────────────────────────────────────

/** admin_users row by id (active only), or false. */
function pos_user(int $userId): array|false {
    if ($userId <= 0) return false;
    $u = db_query('SELECT * FROM admin_users WHERE id = :id', [':id' => $userId])->fetch();
    if (!$u || (array_key_exists('is_active', $u) && !pos_bool($u['is_active']))) return false;
    return $u;
}

/**
 * Outlet ids a user may sell at.
 *   owner   → every active outlet
 *   manager → active outlets at their venues + any they are assigned to
 *   others  → only outlets they are explicitly assigned to
 * Takes the user row (not the session) so a PIN session resolves the same way.
 */
function pos_user_outlet_ids(array $user): array {
    if (!pos_supported()) return [];
    $uid  = (int)($user['id'] ?? 0);
    $role = (string)($user['role'] ?? 'staff');
    if ($role === 'owner') {
        return array_map('intval', db_query('SELECT id FROM pos_outlets WHERE is_active = TRUE ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN));
    }
    $sql = "SELECT o.id FROM pos_outlets o
             WHERE o.is_active = TRUE
               AND (o.id IN (SELECT outlet_id FROM pos_outlet_staff WHERE admin_user_id = :u)";
    if ($role === 'manager') {
        $sql .= " OR o.venue_id IN (SELECT venue_id FROM admin_user_venues WHERE admin_user_id = :u2)";
    }
    $sql .= ") ORDER BY o.sort_order, o.name";
    $p = [':u' => $uid];
    if ($role === 'manager') $p[':u2'] = $uid;
    return array_map('intval', db_query($sql, $p)->fetchAll(PDO::FETCH_COLUMN));
}

/** Owner or manager of this outlet — may void, receive stock, edit the catalogue. */
function pos_user_manages_outlet(array $user, int $outletId): bool {
    $role = (string)($user['role'] ?? '');
    if ($role === 'owner') return true;
    if ($role !== 'manager') return false;
    return in_array($outletId, pos_user_outlet_ids($user), true);
}

/** True when $user may ring $itemId up at $outletId (own item or a linked outlet's). */
function pos_can_sell_item(array $user, int $outletId, int $itemId): bool {
    if (!in_array($outletId, pos_user_outlet_ids($user), true)) return false;
    $item = pos_fetch_item($itemId);
    return $item && in_array((int)$item['outlet_id'], pos_sellable_outlet_ids($outletId), true);
}

// ── Stock ───────────────────────────────────────────────────────────────────

/**
 * Write one stock movement and update the cached total, under a row lock.
 * Refuses (PosRefusal) a move that would take a non-negative item below zero.
 * Returns the new stock_qty.
 */
function pos_stock_move(int $itemId, int $delta, string $reason, ?int $saleId = null, ?float $unitCost = null, string $note = '', ?int $userId = null, array $terms = []): int {
    if (!in_array($reason, ['receive','sale','void','adjust','return'], true)) throw new InvalidArgumentException('bad stock reason');
    return pos_tx(function () use ($itemId, $delta, $reason, $saleId, $unitCost, $note, $userId, $terms): int {
        $it = db_query('SELECT id, name, track_stock, stock_qty, allow_negative FROM pos_items WHERE id = :id FOR UPDATE', [':id' => $itemId])->fetch();
        if (!$it) throw new PosRefusal('That item no longer exists.');
        if (!pos_bool($it['track_stock'])) throw new PosRefusal("{$it['name']} does not track stock.");
        $new = (int)$it['stock_qty'] + $delta;
        if ($new < 0 && !pos_bool($it['allow_negative'])) {
            throw new PosRefusal(pos_stock_shortfall($it, -$delta) ?? "Not enough stock of {$it['name']}.");
        }
        $p = [':i' => $itemId, ':d' => $delta, ':r' => $reason, ':s' => $saleId, ':c' => $unitCost,
              ':n' => $note !== '' ? mb_substr($note, 0, 500) : null, ':u' => $userId ?: null];
        if ($terms && pos_v2_supported()) {
            db_query('INSERT INTO pos_stock_moves (item_id, qty_delta, reason, sale_id, unit_cost, note, admin_user_id, consignor_id, consign_pct, consignor_cost)
                      VALUES (:i, :d, :r, :s, :c, :n, :u, :ci, :cp, :cc)',
                $p + [':ci' => $terms['consignor_id'] ?? null, ':cp' => $terms['consign_pct'] ?? null, ':cc' => $terms['consignor_cost'] ?? null]);
        } else {
            db_query('INSERT INTO pos_stock_moves (item_id, qty_delta, reason, sale_id, unit_cost, note, admin_user_id)
                      VALUES (:i, :d, :r, :s, :c, :n, :u)', $p);
        }
        db_query('UPDATE pos_items SET stock_qty = :q, updated_at = now() WHERE id = :id', [':q' => $new, ':id' => $itemId]);
        return $new;
    });
}

/**
 * Consignment terms for a delivery, from the receive form — PURE.
 *   ['mode' => 'own']                                         our own stock
 *   ['mode' => 'commission', 'consignor_id' => 3, 'value' => 20]   we keep 20%
 *   ['mode' => 'fixed',      'consignor_id' => 3, 'value' => 800]  we owe 800 per unit
 * Returns ['consignor_id','consign_pct','consignor_cost'] or an error string.
 */
function pos_consign_terms(array $in, array $consignorIds): array|string {
    $mode = (string)($in['mode'] ?? 'own');
    if ($mode === 'own' || $mode === '') return ['consignor_id' => null, 'consign_pct' => null, 'consignor_cost' => null];
    if (!in_array($mode, ['commission', 'fixed'], true)) return 'Pick how this supplier is paid.';
    $cid = (int)($in['consignor_id'] ?? 0);
    if (!$cid || !in_array($cid, $consignorIds, true)) return 'Pick the supplier these goods belong to.';
    $v = $in['value'] ?? '';
    if ($v === '' || !is_numeric($v) || (float)$v < 0) return $mode === 'commission' ? 'Enter the commission % we keep.' : 'Enter what we owe per item.';
    if ($mode === 'commission' && (float)$v > 100) return 'Commission must be 100% or less.';
    return $mode === 'commission'
        ? ['consignor_id' => $cid, 'consign_pct' => round((float)$v, 2), 'consignor_cost' => null]
        : ['consignor_id' => $cid, 'consign_pct' => null, 'consignor_cost' => round((float)$v, 2)];
}

/**
 * Receive a delivery. With $terms (pos_consign_terms()), the item's consignment
 * terms are set to what the stock manager chose for THIS delivery, and the ledger
 * row records them. Sales from then on follow the new terms; past sales keep the
 * terms they were sold on (snapshotted on the sale line).
 */
function pos_stock_receive(int $itemId, int $qty, ?float $unitCost, string $note, ?int $userId, ?array $terms = null): int {
    if ($qty < 1) throw new PosRefusal('Enter how many arrived (at least 1).');
    if ($unitCost !== null && $unitCost < 0) throw new PosRefusal('Unit cost cannot be negative.');
    return pos_tx(function () use ($itemId, $qty, $unitCost, $note, $userId, $terms): int {
        if ($terms !== null && pos_v2_supported()) {
            db_query('UPDATE pos_items SET consignor_id = :c, consign_pct = :p, consignor_cost = :k, updated_at = now() WHERE id = :i',
                [':c' => $terms['consignor_id'], ':p' => $terms['consign_pct'], ':k' => $terms['consignor_cost'], ':i' => $itemId]);
        }
        return pos_stock_move($itemId, $qty, 'receive', null, $unitCost, $note, $userId, $terms ?? []);
    });
}

/** Count correction: set the stock to what is physically on the shelf. Reason required. */
function pos_stock_adjust(int $itemId, int $counted, string $note, ?int $userId): int {
    if ($counted < 0) throw new PosRefusal('A counted quantity cannot be negative.');
    if (trim($note) === '') throw new PosRefusal('Say why the count changed (e.g. "stock take", "damaged").');
    return pos_tx(function () use ($itemId, $counted, $note, $userId): int {
        $cur = db_query('SELECT stock_qty FROM pos_items WHERE id = :id FOR UPDATE', [':id' => $itemId])->fetchColumn();
        if ($cur === false) throw new PosRefusal('That item no longer exists.');
        $delta = $counted - (int)$cur;
        if ($delta === 0) return (int)$cur;
        return pos_stock_move($itemId, $delta, 'adjust', null, null, $note, $userId);
    });
}

function pos_stock_moves(int $itemId, int $limit = 100): array {
    if (!pos_supported()) return [];
    return db_query(
        "SELECT m.*, a.name AS user_name, s.reference AS sale_reference" . (pos_v2_supported() ? ", c.name AS consignor_name" : '') . "
           FROM pos_stock_moves m
           LEFT JOIN admin_users a ON a.id = m.admin_user_id
           LEFT JOIN pos_sales s   ON s.id = m.sale_id" . (pos_v2_supported() ? " LEFT JOIN pos_consignors c ON c.id = m.consignor_id" : '') . "
          WHERE m.item_id = :i ORDER BY m.created_at DESC, m.id DESC LIMIT " . max(1, min(500, $limit)),
        [':i' => $itemId]
    )->fetchAll();
}

// ── In-house guests ─────────────────────────────────────────────────────────

/** The booking facts room-charge eligibility needs, by hold id (venue via the booked product). */
function pos_fetch_hold(int $holdId): array|false {
    if ($holdId <= 0) return false;
    return db_query(
        "SELECT h.id, h.status, h.expires_at, h.check_in, h.check_out, h.guest_name, h.access_code,
                r.venue_id, r.name AS room_name, u.name AS unit_name, v.name AS venue_name
           FROM holds h
           JOIN units u ON u.id = h.unit_id
           JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
           LEFT JOIN venues v ON v.id = r.venue_id
          WHERE h.id = :id", [':id' => $holdId]
    )->fetch();
}

/** Adults on a booking (checkin_guests), lead first — the people a charge can be attributed to. */
function pos_hold_guests(int $holdId): array {
    try {
        $child = '';
        $has = db_query("SELECT 1 FROM information_schema.columns WHERE table_name = 'checkin_guests' AND column_name = 'is_child'")->fetchColumn();
        if ($has) $child = ' AND is_child = FALSE';
        return db_query("SELECT id, passport_name, is_lead FROM checkin_guests WHERE hold_id = :h{$child} ORDER BY is_lead DESC, id", [':h' => $holdId])->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * In-house bookings today matching $q (guest name, room/unit, access code), with
 * their adults. $venueIds null = all venues. Wraps frontdesk_rows() — one
 * definition of "a real booking" for the desk and the till.
 */
function pos_inhouse_search(?array $venueIds, string $q, string $todayYmd): array {
    $rows = frontdesk_rows($venueIds, 'h.check_in <= :d AND h.check_out >= :d', [':d' => $todayYmd]);
    $q = mb_strtolower(trim($q));
    $out = [];
    foreach ($rows as $r) {
        if ($q !== '') {
            $hay = mb_strtolower(implode(' ', [$r['guest_name'] ?? '', $r['room_name'] ?? '', $r['unit_name'] ?? '', $r['access_code'] ?? '', $r['venue_name'] ?? '']));
            if (!str_contains($hay, $q)) continue;
        }
        $r['guests'] = pos_hold_guests((int)$r['id']);
        $out[] = $r;
    }
    return $out;
}

// ── The sale write path ─────────────────────────────────────────────────────

function pos_fetch_sale(int $saleId): array|false {
    if (!pos_supported() || $saleId <= 0) return false;
    $extra = pos_v2_supported()
        ? ", gv.name AS guest_venue_name, EXISTS (SELECT 1 FROM pos_sale_signatures ss WHERE ss.sale_id = s.id) AS signed"
        : ", NULL AS guest_venue_name, FALSE AS signed";
    $join  = pos_v2_supported() ? 'LEFT JOIN venues gv ON gv.id = s.guest_venue_id' : '';
    $s = db_query(
        "SELECT s.*, o.name AS outlet_name, a.name AS user_name{$extra}
           FROM pos_sales s JOIN pos_outlets o ON o.id = s.outlet_id
           LEFT JOIN admin_users a ON a.id = s.admin_user_id {$join}
          WHERE s.id = :id", [':id' => $saleId]
    )->fetch();
    if (!$s) return false;
    $s['signed'] = pos_bool($s['signed']);
    $s['lines'] = db_query('SELECT * FROM pos_sale_lines WHERE sale_id = :s ORDER BY id', [':s' => $saleId])->fetchAll();
    return $s;
}

/** The guest's signature for a room charge: ['signature' (PNG data URL), signer_name, signed_at] or null. */
function pos_sale_signature(int $saleId): ?array {
    if (!pos_v2_supported()) return null;
    $r = db_query('SELECT signature, signer_name, signed_at FROM pos_sale_signatures WHERE sale_id = :s', [':s' => $saleId])->fetch();
    return $r ?: null;
}

/** Next receipt number for an outlet, e.g. POS-SHOP-1001. Row-locked, so gap-free under concurrency. */
function pos_next_reference(int $outletId): string {
    $row = db_query('UPDATE pos_outlets SET next_ref = next_ref + 1 WHERE id = :id RETURNING slug, next_ref - 1 AS n', [':id' => $outletId])->fetch();
    if (!$row) throw new PosRefusal('That outlet no longer exists.');
    return 'POS-' . pos_ref_prefix((string)$row['slug']) . '-' . (int)$row['n'];
}

/**
 * Complete a sale — THE one write path.
 *
 * $req = [
 *   'outlet_id'   => int,
 *   'client_uuid' => string,                         // idempotency key from the till
 *   'lines'       => [['item_id'=>int, 'qty'=>int, 'open_price'=>?float], …],
 *   'payment_method' => card|cash|room_charge|mobile_money|other,
 *   'payment_ref'    => ?string, 'cash_tendered' => ?float,
 *   'customer'    => ['type'=>'inhouse','hold_id'=>int,'guest_id'=>?int]
 *                  | ['type'=>'walkin','name'=>?string,'phone'=>?string,'email'=>?string,'pos_customer_id'=>?int],
 * ]
 * Returns ['ok'=>true,'sale'=>array,'duplicate'=>bool] or ['ok'=>false,'error'=>string].
 */
function pos_complete_sale(array $req, int $userId, ?int $terminalId = null): array {
    if (!pos_supported()) return ['ok' => false, 'error' => 'The POS is not enabled yet.'];
    $uuid = trim((string)($req['client_uuid'] ?? ''));
    if (!pos_valid_uuid($uuid)) return ['ok' => false, 'error' => 'Missing sale key — reload the till and try again.'];

    // A retried request returns the sale it already made.
    $existing = pos_sale_by_uuid($uuid);
    if ($existing) return pos_duplicate_result($existing, (int)($req['outlet_id'] ?? 0));

    try {
        $saleId = pos_tx(fn(): int => pos_complete_sale_tx($req, $uuid, $userId, $terminalId));
    } catch (PosRefusal $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (PDOException $e) {
        // Two tablets racing the same uuid: the loser hits the UNIQUE index.
        if ($e->getCode() === '23505' && ($dup = pos_sale_by_uuid($uuid))) return pos_duplicate_result($dup, (int)($req['outlet_id'] ?? 0));
        error_log('[pos] sale failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The sale could not be saved. Nothing was charged — please try again.'];
    }
    $sale = pos_fetch_sale($saleId);
    try { audit_log('pos.sale', 'pos_sale', $saleId, (string)($sale['reference'] ?? '')); } catch (Throwable $e) {}
    return ['ok' => true, 'sale' => $sale, 'duplicate' => false];
}

function pos_sale_by_uuid(string $uuid): array|false {
    $id = db_query('SELECT id FROM pos_sales WHERE client_uuid = :u', [':u' => $uuid])->fetchColumn();
    return $id ? pos_fetch_sale((int)$id) : false;
}

function pos_duplicate_result(array $sale, int $outletId): array {
    if ((int)$sale['outlet_id'] !== $outletId) return ['ok' => false, 'error' => 'That sale key was already used — reload the till.'];
    return ['ok' => true, 'sale' => $sale, 'duplicate' => true];
}

/** Body of pos_complete_sale(), inside the transaction. Throws PosRefusal to refuse. */
function pos_complete_sale_tx(array $req, string $uuid, int $userId, ?int $terminalId): int {
    // 1. Who, and where.
    $user = pos_user($userId);
    if (!$user) throw new PosRefusal('Your account is not active.');
    $outletId = (int)($req['outlet_id'] ?? 0);
    $outlet = db_query('SELECT o.*, v.name AS venue_name FROM pos_outlets o LEFT JOIN venues v ON v.id = o.venue_id WHERE o.id = :id FOR SHARE OF o', [':id' => $outletId])->fetch();
    if (!$outlet || !pos_bool($outlet['is_active'])) throw new PosRefusal('That outlet is not open.');
    if (!in_array($outletId, pos_user_outlet_ids($user), true)) throw new PosRefusal('You are not set up to sell at this outlet.');

    // 2. Lines — ids + qty only; everything else is re-read from the catalogue.
    $reqLines = array_values((array)($req['lines'] ?? []));
    if (!$reqLines) throw new PosRefusal('The order is empty.');
    if (count($reqLines) > POS_MAX_LINES) throw new PosRefusal('Too many lines on one sale.');
    $ids = [];
    foreach ($reqLines as $l) { $ids[] = (int)($l['item_id'] ?? 0); }
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) throw new PosRefusal('The order is empty.');
    sort($ids);   // lock in id order — two tablets never deadlock on the same items
    $ph = []; $p = [];
    foreach ($ids as $i => $v) { $ph[] = ":i{$i}"; $p[":i{$i}"] = $v; }
    db_query('SELECT id FROM pos_items WHERE id IN (' . implode(',', $ph) . ') ORDER BY id FOR UPDATE', $p);
    $items = [];
    foreach (db_query(pos_item_select_sql() . ' WHERE i.id IN (' . implode(',', $ph) . ')', $p)->fetchAll() as $it) {
        $items[(int)$it['id']] = $it;
    }

    $sellable = pos_sellable_outlet_ids($outletId);
    $lines = []; $need = [];
    foreach ($reqLines as $l) {
        $iid = (int)($l['item_id'] ?? 0);
        $it  = $items[$iid] ?? null;
        if (!$it) throw new PosRefusal('An item in this order no longer exists — refresh the till.');
        if (!in_array((int)$it['outlet_id'], $sellable, true)) throw new PosRefusal("{$it['name']} is not sold at this outlet.");
        $open = (isset($l['open_price']) && $l['open_price'] !== '' && $l['open_price'] !== null) ? (float)$l['open_price'] : null;
        $line = pos_resolve_line($it, pos_item_tour($it), (int)($l['qty'] ?? 0), $open);
        if (is_string($line)) throw new PosRefusal($line);
        $lines[] = $line;
        $need[$iid] = ($need[$iid] ?? 0) + $line['qty'];
    }
    // 3. Stock — refuse before writing anything (the same item may sit on two lines).
    foreach ($need as $iid => $qty) {
        if ($why = pos_stock_shortfall($items[$iid], $qty)) throw new PosRefusal($why);
    }

    // 4. Outlet money settings (defaults when add_pos_v2.sql hasn't run yet).
    $v2       = pos_v2_supported();
    $vatPct   = $v2 ? (float)$outlet['vat_pct'] : 0.0;
    $vatIncl  = $v2 ? pos_bool($outlet['vat_inclusive']) : true;
    $tipsOn   = $v2 && pos_bool($outlet['tips_enabled']);
    $cur      = strtoupper((string)$outlet['currency']);

    // 5. Totals — server-side, from the re-priced lines. The tip is resolved against
    //    the pre-tip total, which is exactly what the customer saw at review.
    $pre = pos_cart_totals($lines, (float)$outlet['service_charge_pct'], $vatPct, $vatIncl);
    $tipC = pos_tip_cents(pos_cents($pre['before_tip']), $req['tip_pct'] ?? null, $req['tip_amount'] ?? null);
    if (is_string($tipC)) throw new PosRefusal($tipC);
    if ($tipC > 0 && !$tipsOn) throw new PosRefusal('Tips are turned off for this outlet.');
    $tot = pos_cart_totals($lines, (float)$outlet['service_charge_pct'], $vatPct, $vatIncl, pos_from_cents($tipC));

    // 6. Payment.
    $method = (string)($req['payment_method'] ?? '');
    if (!isset(POS_PAYMENT_METHODS[$method])) throw new PosRefusal('Choose how the customer is paying.');
    $payRef = trim((string)($req['payment_ref'] ?? ''));
    $tender = (isset($req['cash_tendered']) && $req['cash_tendered'] !== '' && $req['cash_tendered'] !== null) ? (float)$req['cash_tendered'] : null;
    if ($method === 'cash' && $tender !== null && pos_cents($tender) < pos_cents($tot['total'])) throw new PosRefusal('Cash tendered is less than the total.');
    if ($method !== 'cash') $tender = null;

    // 7. Customer.
    $cust = (array)($req['customer'] ?? []);
    $type = ($cust['type'] ?? '') === 'inhouse' ? 'inhouse' : 'walkin';
    $holdId = null; $guestId = null; $posCustomerId = null; $custName = ''; $guestVenue = null;
    $hold = null;
    $chargeVenues = pos_outlet_charge_venue_ids($outletId);
    if ($type === 'inhouse') {
        $hold = pos_fetch_hold((int)($cust['hold_id'] ?? 0));
        if (!$hold) throw new PosRefusal('That booking could not be found.');
        $holdId = (int)$hold['id'];
        $guestVenue = $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
        $custName = (string)$hold['guest_name'];
        $gid = (int)($cust['guest_id'] ?? 0);
        if ($gid > 0) {
            $g = db_query('SELECT id, passport_name FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $gid, ':h' => $holdId])->fetch();
            if (!$g) throw new PosRefusal('That guest is not on this booking.');
            $guestId = (int)$g['id'];
            if (trim((string)$g['passport_name']) !== '') $custName = (string)$g['passport_name'];
        }
    } else {
        $pcid = (int)($cust['pos_customer_id'] ?? 0);
        $name = trim((string)($cust['name'] ?? ''));
        if ($pcid > 0) {
            $pc = db_query('SELECT id, name FROM pos_customers WHERE id = :id', [':id' => $pcid])->fetch();
            if (!$pc) throw new PosRefusal('That customer could not be found.');
            $posCustomerId = (int)$pc['id'];
            $custName = (string)$pc['name'];
        } elseif ($name !== '') {
            db_query('INSERT INTO pos_customers (name, phone, email) VALUES (:n, :p, :e)', [
                ':n' => mb_substr($name, 0, 160),
                ':p' => ($ph2 = trim((string)($cust['phone'] ?? ''))) !== '' ? mb_substr($ph2, 0, 40) : null,
                ':e' => ($em = trim((string)($cust['email'] ?? ''))) !== '' ? mb_substr($em, 0, 160) : null,
            ]);
            $posCustomerId = (int) db()->lastInsertId();
            $custName = $name;
        } else {
            $custName = 'Walk-in';
        }
    }

    // 8. Room charge: eligibility (property list), currency conversion, signature.
    $billCur = null; $billAmt = null; $fxRate = null; $signature = null;
    if ($method === 'room_charge') {
        if (!$hold) throw new PosRefusal('Room charge needs an in-house guest.');
        $why = pos_room_charge_eligible($hold, frontdesk_today_ymd(), $outlet + ['charge_venue_ids' => $chargeVenues]);
        if ($why) throw new PosRefusal($why);
        if (!pos_bill_link_supported()) throw new PosRefusal('Room charge is not enabled yet.');
        $billCur = strtoupper(setting('site_currency', 'USD'));
        if ($cur !== $billCur) {
            // A KES outlet onto a USD bill: convert at today's site rate and keep the
            // rate on the sale, so the bill line can always be traced back.
            if (!$v2) throw new PosRefusal("This outlet sells in {$cur} but room bills are in {$billCur}. Run the POS update (add_pos_v2.sql) to allow conversion.");
            $fxRate = pos_fx_rate($cur, $billCur);
            if (!$fxRate) throw new PosRefusal("There is no {$cur}→{$billCur} exchange rate yet — ask a manager to check the currency settings.");
            $billAmt = pos_fx_convert($tot['total'], $fxRate);
        } else {
            $billAmt = $tot['total'];
        }
        if ($v2 && pos_bool($outlet['require_signature'])) {
            $signature = (string)($req['signature'] ?? '');
            if (!pos_valid_signature($signature)) throw new PosRefusal('The guest needs to sign for a room charge.');
        }
    }

    // 9. Write the sale.
    $ref  = pos_next_reference($outletId);
    $cols = ['reference', 'outlet_id', 'terminal_id', 'admin_user_id', 'customer_type', 'hold_id', 'guest_id',
             'pos_customer_id', 'customer_name', 'currency', 'subtotal', 'service_charge', 'total',
             'payment_method', 'payment_ref', 'cash_tendered', 'status', 'client_uuid'];
    $vals = [$ref, $outletId, $terminalId ?: null, $userId, $type, $holdId, $guestId,
             $posCustomerId, mb_substr($custName, 0, 160), $cur, $tot['subtotal'], $tot['service_charge'], $tot['total'],
             $method, $payRef !== '' ? mb_substr($payRef, 0, 80) : null, $tender, 'completed', $uuid];
    if ($v2) {
        array_push($cols, 'service_pct', 'vat_pct', 'vat_inclusive', 'vat_amount', 'tip_amount', 'bill_currency', 'bill_amount', 'fx_rate', 'guest_venue_id');
        array_push($vals, (float)$outlet['service_charge_pct'], $vatPct, $vatIncl ? 'TRUE' : 'FALSE', $tot['vat'], $tot['tip'],
                   $billCur, $billAmt, $fxRate, $guestVenue);
    }
    $ph = []; $p = [];
    foreach ($vals as $i => $v) { $ph[] = ":v{$i}"; $p[":v{$i}"] = $v; }
    db_query('INSERT INTO pos_sales (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', $p);
    $saleId = (int) db()->lastInsertId();

    foreach ($lines as $l) {
        db_query(
            "INSERT INTO pos_sale_lines (sale_id, item_id, owning_outlet_id, tour_id, name, kind, qty, unit_price, line_total,
                                         consignor_id, consignor_commission_pct, consignor_cost)
             VALUES (:s, :i, :oo, :t, :n, :k, :q, :u, :lt, :c, :cp, :cc)",
            [':s' => $saleId, ':i' => $l['item_id'], ':oo' => $l['owning_outlet_id'], ':t' => $l['tour_id'],
             ':n' => $l['name'], ':k' => $l['kind'], ':q' => $l['qty'], ':u' => $l['unit_price'], ':lt' => $l['line_total'],
             ':c' => $l['consignor_id'], ':cp' => $l['consignor_commission_pct'], ':cc' => $l['consignor_cost']]
        );
    }
    foreach ($need as $iid => $qty) {
        if (pos_bool($items[$iid]['track_stock'])) pos_stock_move($iid, -$qty, 'sale', $saleId, null, $ref, $userId);
    }

    // 10. Room charge → exactly one bill line, in the BILL's currency, attributed to
    //     the guest when chosen, labelled with where it was bought.
    if ($method === 'room_charge') {
        $where = (string)$outlet['name'] . (!empty($outlet['venue_name']) ? ' (' . $outlet['venue_name'] . ')' : '');
        $note  = $fxRate && $fxRate !== 1.0 ? pos_money($tot['total'], $cur) . ' @ ' . rtrim(rtrim(number_format($fxRate, 4, '.', ''), '0'), '.') . " {$cur}/{$billCur}" : '';
        $label = pos_bill_label($where, $lines, $ref, $note);
        if (bill_item_guest_supported()) {
            db_query('INSERT INTO bill_items (hold_id, label, amount, guest_id, pos_sale_id) VALUES (:h, :l, :a, :g, :s)',
                [':h' => $holdId, ':l' => $label, ':a' => $billAmt, ':g' => $guestId, ':s' => $saleId]);
        } else {
            db_query('INSERT INTO bill_items (hold_id, label, amount, pos_sale_id) VALUES (:h, :l, :a, :s)',
                [':h' => $holdId, ':l' => $label, ':a' => $billAmt, ':s' => $saleId]);
        }
        if ($signature !== null) {
            db_query('INSERT INTO pos_sale_signatures (sale_id, signature, signer_name, ip, user_agent) VALUES (:s, :sig, :n, :ip, :ua)',
                [':s' => $saleId, ':sig' => $signature, ':n' => mb_substr($custName, 0, 160),
                 ':ip' => mb_substr(client_ip(), 0, 45), ':ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)]);
        }
    }
    return $saleId;
}

/**
 * Void a completed sale — manager/owner of the outlet only, reason required.
 * Restocks tracked items, removes the linked room-charge bill line, marks the sale
 * voided. Returns ['ok'=>true,'sale'=>array] or ['ok'=>false,'error'=>string].
 */
function pos_void_sale(int $saleId, string $reason, int $userId): array {
    if (!pos_supported()) return ['ok' => false, 'error' => 'The POS is not enabled yet.'];
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) return ['ok' => false, 'error' => 'Give a reason for the void.'];
    try {
        pos_tx(function () use ($saleId, $reason, $userId): void {
            $user = pos_user($userId);
            if (!$user) throw new PosRefusal('Your account is not active.');
            $sale = db_query('SELECT * FROM pos_sales WHERE id = :id FOR UPDATE', [':id' => $saleId])->fetch();
            if (!$sale) throw new PosRefusal('That sale does not exist.');
            if (!pos_user_manages_outlet($user, (int)$sale['outlet_id'])) throw new PosRefusal('Only a manager of this outlet can void a sale.');
            if ($sale['status'] !== 'completed') throw new PosRefusal('That sale is already voided.');

            $moves = db_query("SELECT item_id, SUM(qty_delta) AS d FROM pos_stock_moves WHERE sale_id = :s AND reason = 'sale' GROUP BY item_id ORDER BY item_id", [':s' => $saleId])->fetchAll();
            foreach ($moves as $m) {
                // Put back exactly what the sale took, even if tracking was turned off since.
                pos_stock_restore((int)$m['item_id'], -(int)$m['d'], $saleId, (string)$sale['reference'], $userId);
            }
            if (pos_bill_link_supported()) db_query('DELETE FROM bill_items WHERE pos_sale_id = :s', [':s' => $saleId]);
            db_query("UPDATE pos_sales SET status = 'voided', void_reason = :r, voided_by = :u, voided_at = now() WHERE id = :id",
                [':r' => mb_substr($reason, 0, 500), ':u' => $userId, ':id' => $saleId]);
        });
    } catch (PosRefusal $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $sale = pos_fetch_sale($saleId);
    try { audit_log('pos.void', 'pos_sale', $saleId, ($sale['reference'] ?? '') . ' — ' . $reason); } catch (Throwable $e) {}
    return ['ok' => true, 'sale' => $sale];
}

/** Return voided stock to an item regardless of its current tracking flag. */
function pos_stock_restore(int $itemId, int $qty, int $saleId, string $ref, int $userId): void {
    if ($qty <= 0) return;
    $it = db_query('SELECT id FROM pos_items WHERE id = :id FOR UPDATE', [':id' => $itemId])->fetch();
    if (!$it) return;   // item deleted since — nothing to restock
    db_query("INSERT INTO pos_stock_moves (item_id, qty_delta, reason, sale_id, note, admin_user_id) VALUES (:i, :d, 'void', :s, :n, :u)",
        [':i' => $itemId, ':d' => $qty, ':s' => $saleId, ':n' => 'Void ' . $ref, ':u' => $userId]);
    db_query('UPDATE pos_items SET stock_qty = stock_qty + :q, updated_at = now() WHERE id = :id', [':q' => $qty, ':id' => $itemId]);
}

// ── Admin: catalogue management ─────────────────────────────────────────────

/**
 * Outlet ids a user may MANAGE in admin (catalogue, stock). Unlike
 * pos_user_outlet_ids() this includes inactive outlets, so an outlet can be
 * prepared before it opens. owner → all; manager → their venues' + assigned;
 * everyone else → none (selling is not managing).
 */
function pos_manageable_outlet_ids(array $user): array {
    if (!pos_supported()) return [];
    $role = (string)($user['role'] ?? '');
    if ($role === 'owner') return array_map('intval', db_query('SELECT id FROM pos_outlets ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN));
    if ($role !== 'manager') return [];
    $uid = (int)($user['id'] ?? 0);
    return array_map('intval', db_query(
        "SELECT id FROM pos_outlets
          WHERE id IN (SELECT outlet_id FROM pos_outlet_staff WHERE admin_user_id = :u)
             OR venue_id IN (SELECT venue_id FROM admin_user_venues WHERE admin_user_id = :u2)
          ORDER BY sort_order, name", [':u' => $uid, ':u2' => $uid]
    )->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Validate a posted item form — PURE. Returns [clean-values, errors].
 * $categoryIds / $tourIds / $consignorIds are the ids the form may reference
 * (the outlet's categories, linkable tours, suppliers) — anything else is refused.
 */
function pos_item_from_post(array $in, array $categoryIds, array $tourIds, array $consignorIds): array {
    $err = [];
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '')               $err['name'] = 'Give the item a name.';
    elseif (mb_strlen($name) > 160) $err['name'] = 'Keep the name under 160 characters.';

    $kind = ($in['kind'] ?? '') === 'service' ? 'service' : 'product';
    $cat  = (int)($in['category_id'] ?? 0);
    $cat  = in_array($cat, $categoryIds, true) ? $cat : null;
    $tour = (int)($in['tour_id'] ?? 0);
    if ($tour && !in_array($tour, $tourIds, true)) { $err['tour_id'] = 'Pick an activity from the list.'; $tour = 0; }

    $priceRaw = trim((string)($in['price'] ?? ''));
    $price = null;
    if ($priceRaw !== '') {
        if (!is_numeric($priceRaw) || (float)$priceRaw < 0) $err['price'] = 'Enter a price of 0 or more, or leave it blank.';
        else $price = round((float)$priceRaw, 2);
    }
    $track = !empty($in['track_stock']);
    if ($track && $kind === 'service') $err['track_stock'] = 'Services have no stock — switch the item to a product or turn stock off.';
    $low = trim((string)($in['low_stock_at'] ?? ''));
    $lowAt = null;
    if ($low !== '') {
        if (!ctype_digit($low)) $err['low_stock_at'] = 'Low-stock alert must be a whole number.';
        else $lowAt = (int)$low;
    }
    $cons = (int)($in['consignor_id'] ?? 0);
    if ($cons && !in_array($cons, $consignorIds, true)) { $err['consignor_id'] = 'Pick a supplier from the list.'; $cons = 0; }
    // Consignment terms: a fixed amount per unit, or this item's own commission %
    // (blank = the supplier's default). Only one applies — fixed wins when both are typed.
    $costRaw = trim((string)($in['consignor_cost'] ?? ''));
    $cost = null;
    if ($cons && $costRaw !== '') {
        if (!is_numeric($costRaw) || (float)$costRaw < 0) $err['consignor_cost'] = 'What we owe per item must be 0 or more.';
        else $cost = round((float)$costRaw, 2);
    }
    $pctRaw = trim((string)($in['consign_pct'] ?? ''));
    $cpct = null;
    if ($cons && $cost === null && $pctRaw !== '') {
        if (!is_numeric($pctRaw) || (float)$pctRaw < 0 || (float)$pctRaw > 100) $err['consign_pct'] = 'Commission is a percentage from 0 to 100.';
        else $cpct = round((float)$pctRaw, 2);
    }
    $sku = trim((string)($in['sku'] ?? ''));

    return [[
        'name'           => $name,
        'kind'           => $kind,
        'category_id'    => $cat,
        'tour_id'        => $tour ?: null,
        'price'          => $price,
        'per_person'     => !empty($in['per_person']),
        'sku'            => $sku !== '' ? mb_substr($sku, 0, 60) : null,
        'track_stock'    => $track,
        'low_stock_at'   => $track ? $lowAt : null,
        'allow_negative' => $track && !empty($in['allow_negative']),
        'consignor_id'   => $cons ?: null,
        'consignor_cost' => $cost,
        'consign_pct'    => $cpct,
        'is_active'      => !empty($in['is_active']),
    ], $err];
}

/** Resize + store an item photo (same path as nav/venue images); returns the storage key or ''. */
function pos_upload_item_image(array $file): string {
    require_once __DIR__ . '/storage.php';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) throw new PosRefusal('The image did not upload — try again.');
    if ($file['size'] > 5 * 1024 * 1024) throw new PosRefusal('Image too large (max 5MB).');
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new PosRefusal('Use a JPG, PNG or WebP image.');
    $src = match ($mime) { 'image/png' => imagecreatefrompng($file['tmp_name']), 'image/webp' => imagecreatefromwebp($file['tmp_name']), default => imagecreatefromjpeg($file['tmp_name']) };
    if (!$src) throw new PosRefusal('Could not read that image.');
    $w = imagesx($src); $h = imagesy($src);
    if ($w > 600) { $nh = (int) round($h * 600 / $w); $dst = imagecreatetruecolor(600, $nh); imagecopyresampled($dst, $src, 0, 0, 0, 0, 600, $nh, $w, $h); imagedestroy($src); $src = $dst; }
    $filename = seo_filename((string)($file['name'] ?? ''), 'pos');
    $tmp = sys_get_temp_dir() . '/' . $filename;
    imagejpeg($src, $tmp, 86); imagedestroy($src);
    $stored = storage_put($tmp, $filename, 'image/jpeg', 'pos'); @unlink($tmp);
    if ($stored === false) throw new PosRefusal('Could not save the image (storage error).');
    return (string) $stored;
}

/** Published activities (excursions) an item may link to: id => row. */
function pos_linkable_tours(): array {
    $out = [];
    try {
        foreach (db_query("SELECT id, name, price_amount, price_per_person, category FROM tours
                            WHERE is_published = TRUE ORDER BY (category <> 'excursion'), sort_order, name")->fetchAll() as $t) {
            $out[(int)$t['id']] = $t;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** True when the item appears on any sale (then it is hidden, never deleted). */
function pos_item_has_sales(int $itemId): bool {
    return (bool) db_query('SELECT 1 FROM pos_sale_lines WHERE item_id = :i LIMIT 1', [':i' => $itemId])->fetchColumn();
}

// ── Till payloads (JSON for js/pos.js; the same shapes feed the receipt) ────

/** The till's catalogue for one outlet: its categories, its items and cross-sold outlets' items. */
function pos_catalog_payload(array $outlet): array {
    $oid = (int)$outlet['id'];
    $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']], pos_fetch_categories($oid));
    $sources = [];
    foreach (pos_sellable_outlet_ids($oid) as $sid) {
        if ($sid === $oid) continue;
        $so = pos_fetch_outlet($sid);
        if ($so && pos_bool($so['is_active'])) $sources[] = ['id' => $sid, 'name' => (string)$so['name']];
    }
    $srcIds = array_column($sources, 'id');
    $items = [];
    foreach (pos_fetch_items($oid, true, true) as $it) {
        $iOutlet = (int)$it['outlet_id'];
        if ($iOutlet !== $oid && !in_array($iOutlet, $srcIds, true)) continue;   // a closed source outlet sells nothing
        $items[] = [
            'id'             => (int)$it['id'],
            'name'           => (string)$it['name'],
            'outlet_id'      => $iOutlet,
            'outlet_name'    => (string)$it['outlet_name'],
            'category_id'    => $iOutlet === $oid && $it['category_id'] !== null ? (int)$it['category_id'] : null,
            'kind'           => (string)$it['kind'],
            'price'          => pos_item_display_price($it),     // null = open price at sale
            'per_person'     => pos_bool($it['per_person']) || (!empty($it['tour_id']) && $it['price'] === null && pos_bool($it['tour_per_person'])),
            'track_stock'    => pos_bool($it['track_stock']),
            'stock'          => pos_bool($it['track_stock']) ? (int)$it['stock_qty'] : null,
            'allow_negative' => pos_bool($it['allow_negative']),
            'low_at'         => $it['low_stock_at'] !== null ? (int)$it['low_stock_at'] : null,
            'consignor'      => $it['consignor_name'] ?? null,
            'image'          => !empty($it['image_key']) ? storage_url((string)$it['image_key']) : null,
        ];
    }
    $cur     = strtoupper((string)$outlet['currency']);
    $billCur = strtoupper(setting('site_currency', 'USD'));
    $v2      = pos_v2_supported();
    $fx      = $cur === $billCur ? 1.0 : ($v2 ? pos_fx_rate($cur, $billCur) : null);
    $rcNote  = !pos_bool($outlet['allow_room_charge']) ? 'Room charge is turned off for this outlet.'
             : ($fx === null ? "No {$cur}→{$billCur} exchange rate — room charge is unavailable." : null);
    $charge  = pos_outlet_charge_venue_ids($oid);
    return [
        'outlet' => [
            'id' => $oid, 'name' => (string)$outlet['name'], 'kind' => (string)$outlet['kind'], 'currency' => $cur,
            'service_pct'   => (float)$outlet['service_charge_pct'],
            'vat_pct'       => $v2 ? (float)$outlet['vat_pct'] : 0.0,
            'vat_inclusive' => $v2 ? pos_bool($outlet['vat_inclusive']) : true,
            'tips'          => $v2 && pos_bool($outlet['tips_enabled']),
            'signature'     => $v2 && pos_bool($outlet['require_signature']),
            'room_charge'   => $rcNote === null, 'room_charge_note' => $rcNote,
            'bill_currency' => $billCur,
            'fx_rate'       => $fx,                       // units of the outlet currency per 1 unit of the bill currency
            'charge_from'   => pos_charge_venue_label($charge),
            'venue_name'    => $outlet['venue_name'] ?? null,
        ],
        'categories' => $cats,
        'sources'    => $sources,
        'items'      => $items,
    ];
}

/** A sale for the till / receipt. */
function pos_sale_payload(array $s): array {
    return [
        'id' => (int)$s['id'], 'reference' => (string)$s['reference'], 'status' => (string)$s['status'],
        'outlet_id' => (int)$s['outlet_id'], 'outlet' => (string)($s['outlet_name'] ?? ''), 'staff' => (string)($s['user_name'] ?? ''),
        'customer' => (string)$s['customer_name'], 'customer_type' => (string)$s['customer_type'],
        'hold_id' => $s['hold_id'] !== null ? (int)$s['hold_id'] : null,
        'currency' => (string)$s['currency'], 'subtotal' => (float)$s['subtotal'], 'service_charge' => (float)$s['service_charge'], 'total' => (float)$s['total'],
        'service_pct' => (float)($s['service_pct'] ?? 0), 'vat_pct' => (float)($s['vat_pct'] ?? 0),
        'vat_inclusive' => pos_bool($s['vat_inclusive'] ?? true), 'vat' => (float)($s['vat_amount'] ?? 0), 'tip' => (float)($s['tip_amount'] ?? 0),
        'bill_currency' => $s['bill_currency'] ?? null, 'bill_amount' => isset($s['bill_amount']) && $s['bill_amount'] !== null ? (float)$s['bill_amount'] : null,
        'fx_rate' => isset($s['fx_rate']) && $s['fx_rate'] !== null ? (float)$s['fx_rate'] : null,
        'guest_venue' => $s['guest_venue_name'] ?? null, 'signed' => !empty($s['signed']),
        'payment_method' => (string)$s['payment_method'], 'payment_label' => POS_PAYMENT_METHODS[$s['payment_method']] ?? (string)$s['payment_method'],
        'payment_ref' => $s['payment_ref'], 'cash_tendered' => $s['cash_tendered'] !== null ? (float)$s['cash_tendered'] : null,
        'created_at' => date('c', strtotime((string)$s['created_at'])), 'time' => date('H:i', strtotime((string)$s['created_at'])),
        'void_reason' => $s['void_reason'] ?? null,
        'lines' => array_map(fn($l) => [
            'name' => (string)$l['name'], 'qty' => (int)$l['qty'], 'unit_price' => (float)$l['unit_price'], 'line_total' => (float)$l['line_total'],
            'consignment' => !empty($l['consignor_id']), 'owning_outlet_id' => (int)$l['owning_outlet_id'],
        ], $s['lines'] ?? []),
    ];
}

/**
 * Sales list with filters (admin history + till history). $f: outlet_ids (int[],
 * required — pass the caller's scope), from/to (Y-m-d, inclusive), user_id,
 * method, status, q (reference / customer). Newest first.
 * Returns rows + total count + per-currency sums of completed sales.
 */
function pos_sales_query(array $f, int $limit = 200, int $offset = 0): array {
    if (!pos_supported() || empty($f['outlet_ids'])) return ['rows' => [], 'total' => 0, 'sums' => []];
    $w = []; $p = []; $ph = [];
    foreach (array_values($f['outlet_ids']) as $i => $v) { $ph[] = ":o{$i}"; $p[":o{$i}"] = (int)$v; }
    $w[] = 's.outlet_id IN (' . implode(',', $ph) . ')';
    if (!empty($f['from']))    { $w[] = 's.created_at >= :from'; $p[':from'] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))      { $w[] = "s.created_at < (CAST(:to AS date) + INTERVAL '1 day')"; $p[':to'] = $f['to']; }
    if (!empty($f['user_id'])) { $w[] = 's.admin_user_id = :u'; $p[':u'] = (int)$f['user_id']; }
    if (!empty($f['method']) && isset(POS_PAYMENT_METHODS[$f['method']])) { $w[] = 's.payment_method = :m'; $p[':m'] = $f['method']; }
    if (!empty($f['status']) && in_array($f['status'], ['completed', 'voided'], true)) { $w[] = 's.status = :st'; $p[':st'] = $f['status']; }
    if (!empty($f['q'])) { $w[] = '(s.reference ILIKE :q OR s.customer_name ILIKE :q2)'; $p[':q'] = '%' . $f['q'] . '%'; $p[':q2'] = '%' . $f['q'] . '%'; }
    $where = implode(' AND ', $w);
    $total = (int) db_query("SELECT COUNT(*) FROM pos_sales s WHERE {$where}", $p)->fetchColumn();
    // Per currency — money is never summed across currencies. Voided sales excluded.
    $v2cols = pos_v2_supported() ? ', SUM(s.tip_amount) AS tips, SUM(s.vat_amount) AS vat' : ', 0 AS tips, 0 AS vat';
    $sums = db_query("SELECT s.currency, COUNT(*) AS n, SUM(s.total) AS total, SUM(s.service_charge) AS service{$v2cols}
                        FROM pos_sales s WHERE {$where} AND s.status = 'completed' GROUP BY s.currency ORDER BY s.currency", $p)->fetchAll();
    $rows = db_query(
        "SELECT s.*, o.name AS outlet_name, a.name AS user_name
           FROM pos_sales s JOIN pos_outlets o ON o.id = s.outlet_id LEFT JOIN admin_users a ON a.id = s.admin_user_id
          WHERE {$where} ORDER BY s.created_at DESC, s.id DESC LIMIT " . max(1, min(1000, $limit)) . ' OFFSET ' . max(0, $offset), $p
    )->fetchAll();
    return ['rows' => $rows, 'total' => $total, 'sums' => $sums];
}

/**
 * In-house search for the till, with the room-charge verdict per booking.
 * Scope = the properties this outlet accepts room charges from (all by default),
 * so a guest of Maya Ilai shopping at Tribal Dunes is found. Each result says
 * which property the guest is staying at and flags a guest from another property.
 */
function pos_inhouse_payload(array $outlet, string $q): array {
    $charge  = pos_outlet_charge_venue_ids((int)$outlet['id']);
    $today   = frontdesk_today_ymd();
    $out = [];
    foreach (array_slice(pos_inhouse_search($charge ?: null, $q, $today), 0, 12) as $r) {
        $h = pos_fetch_hold((int)$r['id']);
        if (!$h) continue;
        $gv = $r['venue_id'] !== null ? (int)$r['venue_id'] : null;
        $out[] = [
            'hold_id' => (int)$r['id'], 'name' => (string)$r['guest_name'],
            'room'    => trim(($r['venue_name'] ? $r['venue_name'] . ' · ' : '') . ($r['unit_name'] ?: $r['room_name'])),
            'venue'   => (string)($r['venue_name'] ?? ''),
            'other_property' => $outlet['venue_id'] !== null && $gv !== null && $gv !== (int)$outlet['venue_id'],
            'dates'   => date('j M', strtotime((string)$r['check_in'])) . ' – ' . date('j M', strtotime((string)$r['check_out'])),
            'ref'     => (string)($r['access_code'] ?? ''),
            'guests'  => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => trim((string)$g['passport_name']) ?: 'Guest', 'lead' => pos_bool($g['is_lead'])], $r['guests']),
            'room_charge_block' => pos_room_charge_eligible($h, $today, $outlet + ['charge_venue_ids' => $charge]),
        ];
    }
    return $out;
}

// ── Reports ─────────────────────────────────────────────────────────────────

/**
 * Z-report (end-of-day close) for $outletIds on one Nairobi day. Completed sales
 * only; voids are counted separately. Everything is per currency.
 * Returns ['by_method' => [[outlet_id, outlet, payment_method, currency, n, total, service]],
 *          'items'     => [[owning outlet, name, currency, qty, gross]],
 *          'voids'     => [[currency, n, total]], 'staff' => [[name, currency, n, total]]].
 */
function pos_z_report(array $outletIds, string $ymd): array {
    $empty = ['by_method' => [], 'items' => [], 'voids' => [], 'staff' => []];
    if (!pos_supported() || !$outletIds || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return $empty;
    $ph = []; $p = [':d' => $ymd, ':d2' => $ymd];
    foreach (array_values($outletIds) as $i => $v) { $ph[] = ":o{$i}"; $p[":o{$i}"] = (int)$v; }
    $w = 's.outlet_id IN (' . implode(',', $ph) . ") AND s.created_at >= CAST(:d AS date) AND s.created_at < CAST(:d2 AS date) + INTERVAL '1 day'";
    $zx = pos_v2_supported() ? ', SUM(s.tip_amount) AS tips, SUM(s.vat_amount) AS vat' : ', 0 AS tips, 0 AS vat';
    $zt = pos_v2_supported() ? ', SUM(s.tip_amount) AS tips' : ', 0 AS tips';
    return [
        'by_method' => db_query("SELECT s.outlet_id, o.name AS outlet, s.payment_method, s.currency, COUNT(*) AS n, SUM(s.total) AS total, SUM(s.service_charge) AS service{$zx}
                                   FROM pos_sales s JOIN pos_outlets o ON o.id = s.outlet_id
                                  WHERE {$w} AND s.status = 'completed'
                                  GROUP BY s.outlet_id, o.name, o.sort_order, s.payment_method, s.currency
                                  ORDER BY o.sort_order, o.name, s.currency, s.payment_method", $p)->fetchAll(),
        'items'     => db_query("SELECT oo.name AS outlet, l.name, s.currency, SUM(l.qty) AS qty, SUM(l.line_total) AS gross
                                   FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id JOIN pos_outlets oo ON oo.id = l.owning_outlet_id
                                  WHERE {$w} AND s.status = 'completed'
                                  GROUP BY oo.name, l.name, s.currency ORDER BY gross DESC, l.name", $p)->fetchAll(),
        'voids'     => db_query("SELECT s.currency, COUNT(*) AS n, SUM(s.total) AS total FROM pos_sales s
                                  WHERE {$w} AND s.status = 'voided' GROUP BY s.currency ORDER BY s.currency", $p)->fetchAll(),
        'staff'     => db_query("SELECT COALESCE(a.name, a.email, '—') AS name, s.currency, COUNT(*) AS n, SUM(s.total) AS total{$zt}
                                   FROM pos_sales s LEFT JOIN admin_users a ON a.id = s.admin_user_id
                                  WHERE {$w} AND s.status = 'completed'
                                  GROUP BY a.name, a.email, s.currency ORDER BY total DESC", $p)->fetchAll(),
    ];
}

/**
 * Consignment statement for [$from, $to] (inclusive, Nairobi days): per supplier
 * and currency — units sold, gross, what we keep, what we owe, what was paid out
 * for periods overlapping the range, and the balance. Owed is computed per LINE
 * from the terms snapshotted at sale time (pos_consignor_owed()), never from the
 * supplier's current commission.
 */
function pos_consignment_statement(string $from, string $to): array {
    if (!pos_supported()) return [];
    $lines = db_query(
        "SELECT l.consignor_id, c.name AS consignor, s.currency, l.qty, l.line_total, l.consignor_cost, l.consignor_commission_pct
           FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id JOIN pos_consignors c ON c.id = l.consignor_id
          WHERE l.consignor_id IS NOT NULL AND s.status = 'completed'
            AND s.created_at >= CAST(:f AS date) AND s.created_at < CAST(:t AS date) + INTERVAL '1 day'",
        [':f' => $from, ':t' => $to]
    )->fetchAll();
    $out = [];
    foreach ($lines as $l) {
        $k = $l['consignor_id'] . '|' . $l['currency'];
        $out[$k] ??= ['consignor_id' => (int)$l['consignor_id'], 'consignor' => (string)$l['consignor'], 'currency' => (string)$l['currency'],
                      'qty' => 0, 'gross_c' => 0, 'owed_c' => 0, 'paid_c' => 0];
        $out[$k]['qty']     += (int)$l['qty'];
        $out[$k]['gross_c'] += pos_cents($l['line_total']);
        $out[$k]['owed_c']  += pos_cents(pos_consignor_owed($l));
    }
    $pay = db_query("SELECT consignor_id, currency, SUM(amount) AS amt FROM pos_consignor_payouts
                      WHERE period_from <= CAST(:t AS date) AND period_to >= CAST(:f AS date) GROUP BY consignor_id, currency",
        [':f' => $from, ':t' => $to])->fetchAll();
    foreach ($pay as $py) {
        $k = $py['consignor_id'] . '|' . $py['currency'];
        if (!isset($out[$k])) {
            $name = (string) db_query('SELECT name FROM pos_consignors WHERE id = :i', [':i' => (int)$py['consignor_id']])->fetchColumn();
            $out[$k] = ['consignor_id' => (int)$py['consignor_id'], 'consignor' => $name, 'currency' => (string)$py['currency'], 'qty' => 0, 'gross_c' => 0, 'owed_c' => 0, 'paid_c' => 0];
        }
        $out[$k]['paid_c'] += pos_cents($py['amt']);
    }
    $rows = [];
    foreach ($out as $r) {
        $rows[] = [
            'consignor_id' => $r['consignor_id'], 'consignor' => $r['consignor'], 'currency' => $r['currency'], 'qty' => $r['qty'],
            'gross' => pos_from_cents($r['gross_c']), 'kept' => pos_from_cents($r['gross_c'] - $r['owed_c']),
            'owed' => pos_from_cents($r['owed_c']), 'paid' => pos_from_cents($r['paid_c']), 'balance' => pos_from_cents($r['owed_c'] - $r['paid_c']),
        ];
    }
    usort($rows, fn($a, $b) => [$a['consignor'], $a['currency']] <=> [$b['consignor'], $b['currency']]);
    return $rows;
}

/** Record a payout to a supplier for a period. Returns the payout id. */
function pos_record_payout(int $consignorId, string $from, string $to, float $amount, string $currency, string $note, int $userId): int {
    if ($amount <= 0) throw new PosRefusal('Enter the amount paid.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) throw new PosRefusal('Pick a valid period.');
    db_query('INSERT INTO pos_consignor_payouts (consignor_id, period_from, period_to, amount, currency, note, admin_user_id)
              VALUES (:c, :f, :t, :a, :cur, :n, :u)',
        [':c' => $consignorId, ':f' => $from, ':t' => $to, ':a' => round($amount, 2), ':cur' => strtoupper($currency),
         ':n' => trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, ':u' => $userId]);
    return (int) db()->lastInsertId();
}

/** Low-stock items across outlets (tracked, at or below the alert, or out). */
function pos_low_stock(array $outletIds): array {
    if (!pos_supported() || !$outletIds) return [];
    $ph = []; $p = [];
    foreach (array_values($outletIds) as $i => $v) { $ph[] = ":o{$i}"; $p[":o{$i}"] = (int)$v; }
    return db_query("SELECT i.id, i.name, i.stock_qty, i.low_stock_at, i.outlet_id, o.name AS outlet
                       FROM pos_items i JOIN pos_outlets o ON o.id = i.outlet_id
                      WHERE i.outlet_id IN (" . implode(',', $ph) . ") AND i.is_active = TRUE AND i.track_stock = TRUE
                        AND (i.stock_qty <= 0 OR (i.low_stock_at IS NOT NULL AND i.stock_qty <= i.low_stock_at))
                      ORDER BY i.stock_qty, o.sort_order, i.name", $p)->fetchAll();
}
