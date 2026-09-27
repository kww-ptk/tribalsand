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
