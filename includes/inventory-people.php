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
