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
require_once __DIR__ . '/hr.php';          // hr_staff_supported()
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
    $rows  = db_query("{$cte} SELECT i.*, COALESCE(t.qty, 0) AS qty
                          FROM inv_items i
                          LEFT JOIN (SELECT item_id, SUM(qty) AS qty FROM vb GROUP BY item_id) t ON t.item_id = i.id
                         WHERE {$where}
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
    $status  = (string)($f['status'] ?? '');
    $reasons = $status === 'sold' ? ['sale'] : INV_LOSS_REASONS;
    $p = [];
    $w = [inv_visible_sql('l', $venueIds, $p), 'm.to_location_id IS NULL'];
    $rp = [];
    foreach ($reasons as $k => $r) { $rp[] = ":r{$k}"; $p[":r{$k}"] = $r; }
    $w[] = 'm.reason IN (' . implode(',', $rp) . ')';
    if ($status === 'sold') $w[] = "(s.id IS NULL OR s.status <> 'voided')";   // a voided sale never leaves stock "sold"
    $w[] = 'm.created_at >= CAST(:dfrom AS date)';        $p[':dfrom'] = $from;
    $w[] = 'm.created_at < CAST(:dto AS date) + 1';       $p[':dto']   = $to;
    if (!empty($f['venue']))    { $w[] = 'l.venue_id = :fv'; $p[':fv'] = (int)$f['venue']; }
    if (!empty($f['location'])) { $w[] = '(l.id = :fl OR l.parent_id = :fl2)'; $p[':fl'] = (int)$f['location']; $p[':fl2'] = (int)$f['location']; }
    if (!empty($f['person']))   { $w[] = 'l.hr_staff_id = :fp'; $p[':fp'] = (int)$f['person']; }
    if (!empty($f['type']) && isset(INV_TYPES[$f['type']])) { $w[] = 'i.item_type = :ft'; $p[':ft'] = (string)$f['type']; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $w[] = "(i.name ILIKE :q1 OR COALESCE(i.sku, '') ILIKE :q2 OR COALESCE(i.category, '') ILIKE :q3)";
        $p[':q1'] = $like; $p[':q2'] = $like; $p[':q3'] = $like;
    }
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
    $totals = inv_sum_by_currency(db_query("SELECT m.currency, SUM(m.value) AS value {$base} GROUP BY m.currency", $p)->fetchAll());
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

/**
 * An item's movement history, newest first — only moves touching a location the
 * account may see. A move that touches ONE end the account may see and one it may
 * not (e.g. a manager's own property receiving from shared Main stock is visible,
 * but a transfer out to another owner's property is not) still shows — the name of
 * the end outside scope is masked as "Another location" so it is never leaked.
 */
function inv_item_history(int $itemId, ?array $venueIds, int $limit = 100): array {
    if (!inv_supported()) return [];
    $p  = [':i' => $itemId];
    $vf = inv_visible_sql('lf', $venueIds, $p, 'vf');
    $vt = inv_visible_sql('lt', $venueIds, $p, 'vt');
    $rows = db_query("SELECT m.*, a.name AS user_name, s.reference AS sale_reference, lf.name AS from_name, lt.name AS to_name,
                             lf.venue_id AS from_venue, lf.kind AS from_kind, lt.venue_id AS to_venue, lt.kind AS to_kind, u.serial
                       FROM inv_moves m
                       LEFT JOIN inv_locations lf ON lf.id = m.from_location_id
                       LEFT JOIN inv_locations lt ON lt.id = m.to_location_id
                       LEFT JOIN admin_users a    ON a.id = m.admin_user_id
                       LEFT JOIN pos_sales s      ON s.id = m.pos_sale_id
                       LEFT JOIN inv_assets u     ON u.id = m.asset_id
                      WHERE m.item_id = :i AND ((lf.id IS NOT NULL AND {$vf}) OR (lt.id IS NOT NULL AND {$vt}))
                      ORDER BY m.created_at DESC, m.id DESC LIMIT " . max(1, min(500, $limit)), $p)->fetchAll();
    foreach ($rows as &$r) {
        if ($r['from_location_id'] !== null && !inv_location_visible(['venue_id' => $r['from_venue'], 'kind' => $r['from_kind']], $venueIds)) {
            $r['from_name'] = 'Another location';
        }
        if ($r['to_location_id'] !== null && !inv_location_visible(['venue_id' => $r['to_venue'], 'kind' => $r['to_kind']], $venueIds)) {
            $r['to_name'] = 'Another location';
        }
    }
    unset($r);
    return $rows;
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
                             i.unit_label, i.is_active, b.qty, b.par_qty
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

/**
 * Active team members the account may assign items to: [hr_staff id => name].
 * A person's inventory location follows their HOME venue only
 * (inv_person_location_id()), never any additional venue hr_staff_venues grants —
 * so this filters on hr_staff.venue_id exactly like inv_move_in_scope(), not the
 * broader hr_staff_in_venue_scope() (home OR additional venue). A manager never
 * sees a venue-less (owner-business) team member.
 */
function inv_assignable_staff(?array $venueIds): array {
    if (!hr_staff_supported()) return [];
    $sql = "SELECT id, full_name FROM hr_staff WHERE status = 'active'";
    $p   = [];
    if ($venueIds !== null) {
        $ph = [];
        foreach (array_values($venueIds) as $i => $vid) { $ph[] = ":v{$i}"; $p[":v{$i}"] = (int)$vid; }
        $sql .= $ph ? (' AND venue_id IN (' . implode(',', $ph) . ')') : ' AND FALSE';
    }
    $out = [];
    foreach (db_query($sql . ' ORDER BY full_name', $p)->fetchAll() as $r) $out[(int)$r['id']] = (string)$r['full_name'];
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
    if (!inv_bool($parent['is_active'])) throw new InvRefusal("{$parent['name']} is closed.");
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
    if ($assignee > 0 && !isset(inv_assignable_users($loc['venue_id'] !== null ? (int)$loc['venue_id'] : null)[$assignee])) {
        throw new InvRefusal('Pick someone who works at this property.');
    }
    $set = ['count_every_days = :e', 'count_assignee_id = :a'];
    $p   = [':e' => $every === '' ? null : (int)$every, ':a' => $assignee > 0 ? $assignee : null, ':id' => $id];
    if (in_array($loc['kind'], ['area', 'store'], true) && array_key_exists('name', $v)) {
        $name = trim((string)$v['name']);
        if ($name === '' || mb_strlen($name) > 120) throw new InvRefusal('Give it a name (up to 120 characters).');
        if ($loc['kind'] === 'area' && $loc['parent_id'] !== null
            && db_query('SELECT 1 FROM inv_locations WHERE parent_id = :p AND is_active = TRUE AND id <> :id AND lower(name) = lower(:n)',
                [':p' => (int)$loc['parent_id'], ':id' => $id, ':n' => $name])->fetchColumn()) {
            throw new InvRefusal("Another area here is already called {$name}.");
        }
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
 * Switching an item off is refused while it still holds stock anywhere (at any
 * location, in or out of scope) — the item would otherwise vanish from view while
 * still carrying real balances. A POS-linked item keeps the name, SKU, photo,
 * currency, alert and tracking its POS listing owns ($posLinked = true skips them).
 * Only the OWNER may change an existing item's replacement value, currency or
 * active flag — the value drives every loss figure site-wide, so a manager
 * ($ownerLevel = false) can describe the item and move its stock, but those three
 * fields are always kept as stored, whatever the form posted.
 */
function inv_update_item(int $id, array $v, bool $posLinked = false, bool $ownerLevel = true): void {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $item = inv_fetch_item($id);
    if (!$item) throw new InvRefusal('That item no longer exists.');
    if (!$posLinked && $v['tracking'] !== $item['tracking'] && inv_item_has_moves($id)) {
        throw new InvRefusal('Tracking can’t change once the item has stock history.');
    }
    if (!$ownerLevel) {
        $v['replacement_value'] = $item['replacement_value'] !== null ? (float)$item['replacement_value'] : null;
        $v['currency']          = (string)$item['currency'];
        $v['is_active']         = inv_bool($item['is_active']);
    }
    if (!$v['is_active'] && inv_bool($item['is_active'])
        && (int) db_query('SELECT COALESCE(SUM(ABS(qty)), 0) FROM inv_balances WHERE item_id = :i', [':i' => $id])->fetchColumn() > 0) {
        throw new InvRefusal('Move or write off its stock before switching it off.');
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
            $moveId = inv_report_loss($itemId, $qty, (int)$from['id'], $reason, $userId, $note, $asset);
            $value = db_query('SELECT value, currency FROM inv_moves WHERE id = :id', [':id' => $moveId])->fetch();
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

// ── Shared page chrome ──────────────────────────────────────────────────────

/** Photo, else the item's emoji, else its initial — a square thumbnail. */
function inv_thumb_html(array $item, int $size = 36): string {
    $s = max(16, $size);
    if (!empty($item['image_key'])) {
        return '<img class="inv-thumb" src="' . e(storage_url((string)$item['image_key'])) . '" alt="" width="' . $s . '" height="' . $s . '" style="width:' . $s . 'px;height:' . $s . 'px" loading="lazy">';
    }
    $glyph = trim((string)($item['icon'] ?? ''));
    if ($glyph === '') $glyph = mb_strtoupper(mb_substr((string)($item['name'] ?? '?'), 0, 1));
    return '<span class="inv-thumb inv-thumb--glyph" style="width:' . $s . 'px;height:' . $s . 'px;font-size:' . (int)round($s * 0.5) . 'px">' . e($glyph) . '</span>';
}

/** CSS shared by every inventory page — echo once per page. */
function inv_shared_css(): string {
    return '<style>
.inv-thumb{border-radius:8px;object-fit:cover;flex:0 0 auto;background:var(--bg)}
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
</style>';
}
