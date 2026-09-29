<?php
declare(strict_types=1);
/**
 * Inventory — the spreadsheet-style list (admin/inventory.php): one row per item,
 * a "place" view (stock / should-have / short at one place and its areas), and
 * bulk actions on ticked rows. Test: php tests/inventory_grid_logic.php
 *
 *   • Quantities change only through inv_move() (bulk move = one inv_transfer()
 *     per item, all in ONE inv_tx(), balance rows pre-locked in the global order).
 *   • Nothing here scopes by venue — the page checks inv_location_visible() /
 *     inv_move_in_scope() on every posted place; item ids are only ever items.
 */

require_once __DIR__ . '/inventory-views.php';
require_once __DIR__ . '/inventory-owner.php';

const INV_GRID_MAX_ROWS = 2000;

/** Posted ids → unique positive ints (at most INV_GRID_MAX_ROWS) — PURE. */
function inv_grid_ids(mixed $posted): array {
    $out = [];
    foreach ((array)$posted as $v) {
        if (is_numeric($v) && (int)$v > 0) $out[(int)$v] = (int)$v;
        if (count($out) >= INV_GRID_MAX_ROWS) break;
    }
    return array_values($out);
}

/**
 * Every active item with its stock across the places the account may see, and —
 * when $placeId > 0 — its stock, should-have (par) and whether it is listed at
 * that place (the place itself or its areas). With a place, only items listed
 * there are returned. $type filters item_type. Rows carry 'breakdown' (visible
 * places holding stock) for the all-places view.
 */
function inv_grid_rows(?array $venueIds, int $placeId, string $type = ''): array {
    if (!inv_supported()) return [];
    $p   = [];
    $vis = inv_visible_sql('l', $venueIds, $p, 'gv');
    $here = function (int $n) use ($placeId, &$p): string {
        if ($placeId <= 0) return 'FALSE';
        $p[":gp{$n}a"] = $placeId; $p[":gp{$n}b"] = $placeId;   // a placeholder may not be reused in one statement
        return "(l.id = :gp{$n}a OR l.parent_id = :gp{$n}b)";
    };
    $h1 = $here(1); $h2 = $here(2); $h3 = $here(3);
    $w = ['i.is_active = TRUE'];
    if ($type !== '' && isset(INV_TYPES[$type])) { $w[] = 'i.item_type = :gt'; $p[':gt'] = $type; }
    if ($placeId > 0) $w[] = 'COALESCE(t.rows_here, 0) > 0';
    $rows = db_query("SELECT i.id, i.name, i.sku, i.category, i.item_type, i.tracking, i.unit_label, i.image_key, i.icon,
                             i.replacement_value, i.currency, i.low_stock_at,
                             COALESCE(t.qty_all, 0) AS qty_all, COALESCE(t.qty_here, 0) AS qty_here, t.par_here,
                             COALESCE(t.rows_here, 0) AS rows_here
                        FROM inv_items i
                        LEFT JOIN (SELECT b.item_id, SUM(b.qty) AS qty_all,
                                          SUM(b.qty) FILTER (WHERE {$h1}) AS qty_here,
                                          SUM(b.par_qty) FILTER (WHERE {$h2}) AS par_here,
                                          COUNT(*) FILTER (WHERE {$h3} AND (b.qty <> 0 OR b.par_qty IS NOT NULL)) AS rows_here
                                     FROM inv_balances b JOIN inv_locations l ON l.id = b.location_id
                                    WHERE {$vis}
                                    GROUP BY b.item_id) t ON t.item_id = i.id
                       WHERE " . implode(' AND ', $w) . '
                       ORDER BY i.category NULLS LAST, i.name, i.id
                       LIMIT ' . INV_GRID_MAX_ROWS, $p)->fetchAll();
    if (!$rows) return [];

    $pb = [];
    $vb = inv_visible_sql('l', $venueIds, $pb, 'gb');
    $bd = [];
    foreach (db_query("SELECT b.item_id, l.name, l.kind, b.qty FROM inv_balances b JOIN inv_locations l ON l.id = b.location_id
                        WHERE b.qty <> 0 AND {$vb}", $pb)->fetchAll() as $b) {
        $bd[(int)$b['item_id']][] = $b;
    }
    foreach ($rows as &$r) {
        $list = $bd[(int)$r['id']] ?? [];
        usort($list, fn(array $a, array $b): int =>
            [INV_KIND_ORDER[$a['kind']] ?? 9, -(int)$a['qty'], (string)$a['name']] <=> [INV_KIND_ORDER[$b['kind']] ?? 9, -(int)$b['qty'], (string)$b['name']]);
        $r['breakdown'] = $list;
        $r['short']     = $r['par_here'] !== null ? max(0, (int)$r['par_here'] - (int)$r['qty_here']) : 0;
    }
    unset($r);
    return $rows;
}

/**
 * Move ALL of each item's stock at place $fromId to place $toId — one transaction.
 * Counted items only (serial units move one by one on the item page) and only
 * the stock at $fromId itself (not its areas). Does NO scoping — the caller
 * checks inv_move_in_scope(). Returns ['items' => n moved, 'pieces' => n, 'skipped' => n].
 */
function inv_grid_bulk_move(array $itemIds, int $fromId, int $toId, ?int $userId): array {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    if ($fromId === $toId) throw new InvRefusal('Pick a different place to move to.');
    if (!inv_fetch_location($fromId) || !inv_fetch_location($toId)) throw new InvRefusal('That place no longer exists.');
    $ids = inv_grid_ids($itemIds);
    if (!$ids) throw new InvRefusal('Tick at least one item.');
    return inv_tx(function () use ($ids, $fromId, $toId, $userId): array {
        $pairs = [];
        foreach ($ids as $i) { $pairs[] = [$i, $fromId]; $pairs[] = [$i, $toId]; }
        inv_lock_balances($pairs);
        $moved = 0; $pieces = 0; $skipped = 0;
        foreach ($ids as $i) {
            $item = inv_fetch_item($i);
            $qty  = $item ? inv_balance($i, $fromId) : 0;
            if (!$item || $item['tracking'] !== 'qty' || $qty <= 0) { $skipped++; continue; }
            inv_transfer($i, $qty, $fromId, $toId, $userId, 'Moved from the list');
            $moved++; $pieces += $qty;
        }
        return ['items' => $moved, 'pieces' => $pieces, 'skipped' => $skipped];
    });
}

/** Set the category of the ticked items ('' clears it). Returns the number updated. */
function inv_grid_bulk_category(array $itemIds, string $category): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $ids = inv_grid_ids($itemIds);
    if (!$ids) throw new InvRefusal('Tick at least one item.');
    $category = trim($category);
    if (mb_strlen($category) > 60) throw new InvRefusal('Keep the category under 60 characters.');
    return db_query('UPDATE inv_items SET category = :c, updated_at = now() WHERE id = ANY(CAST(:ids AS int[]))',
        [':c' => $category !== '' ? $category : null, ':ids' => inv_pg_int_array_literal($ids)])->rowCount();
}

/** Delete the ticked items (owner only — the caller checks). All or nothing. Returns the number deleted. */
function inv_grid_bulk_delete(array $itemIds, ?int $userId): int {
    $ids = inv_grid_ids($itemIds);
    if (!$ids) throw new InvRefusal('Tick at least one item.');
    return inv_tx(function () use ($ids, $userId): int {
        foreach ($ids as $i) {
            $item = inv_fetch_item($i);
            if (!$item) continue;
            try { inv_delete_item($i, $userId); }
            catch (InvRefusal $e) { throw new InvRefusal("{$item['name']}: " . $e->getMessage()); }
        }
        return count($ids);
    });
}
