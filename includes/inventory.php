<?php
declare(strict_types=1);
/**
 * Inventory & Assets — ONE inventory database under the POS, the properties, the
 * staff and central stock. Spec: docs/superpowers/specs/2026-09-27-inventory-assets-design.md
 * Migration: db/migrations/add_inventory.sql. Test: php tests/inventory_logic.php
 *
 * Load-bearing rules:
 *   • inv_move() is the ONLY writer of inv_moves and inv_balances. It locks the
 *     balance rows in location-id order, refuses going below zero (except a POS
 *     listing flagged allow_negative), snapshots value, and updates both balances
 *     in one transaction. Compound actions (replace, restock, count resolution)
 *     are several inv_move() calls inside ONE inv_tx().
 *   • Counting never moves stock. A manager's resolution does, and only while the
 *     live balance still equals what the counter was shown.
 *   • Money is never summed across currencies (inv_sum_by_currency()).
 *   • Every surface checks inv_supported() first (catalog lookup — safe in a tx).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-support.php';   // inv_supported(), InvRefusal, inv_tx()

const INV_TYPES = [
    'sellable'    => 'Sellable product',
    'operational' => 'Property asset',
    'employee'    => 'Employee asset',
    'consignment' => 'Consignment product',
    'spare'       => 'Stock / spare',
];
const INV_LOCATION_KINDS = ['store' => 'Main stock', 'property' => 'Property', 'area' => 'Area', 'outlet' => 'Outlet', 'person' => 'Team member'];
const INV_REASONS_IN     = ['receive', 'found', 'opening', 'void'];                   // from = NULL
const INV_REASONS_OUT    = ['sale', 'broken', 'missing', 'stolen', 'written_off'];    // to   = NULL
const INV_REASONS_MOVE   = ['transfer', 'assign', 'return', 'replaced'];              // both ends
const INV_LOSS_REASONS   = ['broken', 'missing', 'stolen', 'written_off'];
const INV_RESOLUTIONS    = ['missing', 'broken', 'stolen', 'found', 'recount', 'accepted'];
const INV_CONDITIONS     = ['new', 'good', 'fair', 'poor'];
const INV_MAX_QTY        = 100000;
const INV_DEFAULT_CURRENCY = 'KES';

// ── Pure rules ──────────────────────────────────────────────────────────────

/** Postgres/PDO boolean → PHP bool ('t', true, 1, '1', 'true'). */
function inv_bool(mixed $v): bool {
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

/** A move request with ids as int|null (0/'' → null), qty as int (bad → 0), value rounded. */
function inv_normalize_move(array $m): array {
    $id  = fn($v) => ($v === null || $v === '' || !is_numeric($v) || (int)$v <= 0) ? null : (int)$v;
    $qty = $m['qty'] ?? 0;
    $qty = is_int($qty) ? $qty : (ctype_digit((string)$qty) ? (int)$qty : 0);
    $uv  = $m['unit_value'] ?? null;
    return [
        'item_id'        => (int)($id($m['item_id'] ?? null) ?? 0),
        'qty'            => $qty,
        'from'           => $id($m['from'] ?? null),
        'to'             => $id($m['to'] ?? null),
        'reason'         => (string)($m['reason'] ?? ''),
        'user_id'        => $id($m['user_id'] ?? null),
        'note'           => mb_substr(trim((string)($m['note'] ?? '')), 0, 500),
        'asset_id'       => $id($m['asset_id'] ?? null),
        'pos_sale_id'    => $id($m['pos_sale_id'] ?? null),
        'count_line_id'  => $id($m['count_line_id'] ?? null),
        'unit_value'     => ($uv !== null && $uv !== '' && is_numeric($uv)) ? round((float)$uv, 2) : null,
        'terms'          => (array)($m['terms'] ?? []),
        'allow_negative' => !empty($m['allow_negative']),
    ];
}

/** Why a (normalized) move is malformed, or null — PURE. Stock levels are checked later, under lock. */
function inv_move_error(array $m): ?string {
    if ((int)($m['item_id'] ?? 0) <= 0) return 'Pick an item.';
    $qty = $m['qty'] ?? 0;
    if (!is_int($qty) || $qty < 1) return 'Enter a quantity of at least 1.';
    if ($qty > INV_MAX_QTY) return 'That quantity is too large.';
    $from = $m['from'] ?? null;
    $to   = $m['to'] ?? null;
    $r    = (string)($m['reason'] ?? '');
    if (in_array($r, INV_REASONS_IN, true)) {
        if ($from !== null || $to === null) return 'Stock coming in needs a destination and no source.';
    } elseif (in_array($r, INV_REASONS_OUT, true)) {
        if ($from === null || $to !== null) return 'Stock leaving needs a source and no destination.';
    } elseif (in_array($r, INV_REASONS_MOVE, true)) {
        if ($from === null || $to === null) return 'Pick where it comes from and where it goes.';
        if ($from === $to) return 'Pick two different locations.';
    } else {
        return 'Unknown movement type.';
    }
    if (($m['asset_id'] ?? null) !== null && $qty !== 1) return 'A serial-tracked unit moves one at a time.';
    if (($m['unit_value'] ?? null) !== null && $m['unit_value'] < 0) return 'Value cannot be negative.';
    return null;
}

/** The reason a location-to-location move is recorded under — PURE. */
function inv_transfer_reason(string $fromKind, string $toKind): string {
    if ($toKind === 'person') return 'assign';
    if ($fromKind === 'person') return 'return';
    return 'transfer';
}

/** The refusal shown when a location does not hold enough — PURE. */
function inv_shortfall_message(string $name, int $have, string $where): string {
    return $have <= 0 ? "No {$name} left at {$where}." : "Only {$have} × {$name} at {$where}.";
}

/**
 * May an account with $venueIds (null = owner, all) make a move between these
 * two location rows (either may be null for in/out moves)? — PURE.
 * Shared locations (venue_id NULL: Main stock, venue-less outlets) are open to a
 * manager only as the OTHER end of a move into/out of one of their own
 * properties; every non-shared end must be theirs.
 */
function inv_move_in_scope(?array $from, ?array $to, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    $owned = false;
    foreach ([$from, $to] as $loc) {
        if ($loc === null) continue;
        $v = isset($loc['venue_id']) && $loc['venue_id'] !== null ? (int)$loc['venue_id'] : null;
        if ($v === null) continue;
        if (!in_array($v, array_map('intval', $venueIds), true)) return false;
        $owned = true;
    }
    return $owned;
}

/**
 * The move a count resolution implies — PURE.
 * Returns ['reason','qty','dir'=>'in'|'out'], null (no move), or an error string.
 */
function inv_resolution_move(string $resolution, int $expected, int $counted): array|string|null {
    if (!in_array($resolution, INV_RESOLUTIONS, true)) return 'Pick what happened.';
    $gap = $counted - $expected;
    if ($resolution === 'recount') return null;
    if ($resolution === 'accepted') return $gap === 0 ? null : 'A gap cannot be accepted as it is — pick what happened, or recount.';
    if ($resolution === 'found') return $gap > 0 ? ['reason' => 'found', 'qty' => $gap, 'dir' => 'in'] : '"Found" only applies when more were counted than expected.';
    return $gap < 0 ? ['reason' => $resolution, 'qty' => -$gap, 'dir' => 'out'] : 'Nothing is short on this line.';
}

/** Nairobi-local date the next count is due, or null (manual only / never counted) — PURE. */
function inv_count_due_ymd(?string $lastCountedAt, ?int $everyDays): ?string {
    if (!$everyDays || $everyDays < 1 || !$lastCountedAt) return null;
    $d = DateTime::createFromFormat('!Y-m-d', substr($lastCountedAt, 0, 10));
    return $d ? $d->modify("+{$everyDays} days")->format('Y-m-d') : null;
}

/** 'manual' | 'ok' | 'due' | 'overdue' for a location's count schedule — PURE. */
function inv_count_status(?string $lastCountedAt, ?int $everyDays, string $todayYmd): string {
    if (!$everyDays) return 'manual';
    if (!$lastCountedAt) return 'due';
    $due = inv_count_due_ymd($lastCountedAt, $everyDays);
    if ($due === null) return 'due';
    return $todayYmd > $due ? 'overdue' : ($todayYmd === $due ? 'due' : 'ok');
}

/** Sum a value column per currency (never across) — PURE. ['KES' => 150.0, 'USD' => 20.0] */
function inv_sum_by_currency(array $rows, string $valueKey = 'value', string $currencyKey = 'currency'): array {
    $out = [];
    foreach ($rows as $r) {
        $v = $r[$valueKey] ?? null;
        if ($v === null || $v === '') continue;
        $c = strtoupper((string)($r[$currencyKey] ?? INV_DEFAULT_CURRENCY));
        $out[$c] = round(($out[$c] ?? 0) + (float)$v, 2);
    }
    ksort($out);
    return $out;
}

/** [item_id => qty needed to reach par] for balance rows (item_id, qty, par_qty) — PURE. */
function inv_restock_plan(array $rows): array {
    $plan = [];
    foreach ($rows as $r) {
        if (!isset($r['par_qty']) || $r['par_qty'] === null || $r['par_qty'] === '') continue;
        $need = (int)$r['par_qty'] - max(0, (int)$r['qty']);
        if ($need > 0) $plan[(int)$r['item_id']] = $need;
    }
    ksort($plan);
    return $plan;
}
