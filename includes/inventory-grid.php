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


// ── Shared look + behaviour of the spreadsheet tables (Inventory list, order receiving) ──

/** CSS of the spreadsheet-style tables (.ig …) — echo once per page, after inv_shared_css(). */
function inv_grid_css(): string {
    return '<style>
.ig-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:14px}
.ig-field{display:grid;gap:4px;font-size:12px;color:var(--muted);font-weight:600}
.ig-search{flex:1 1 220px;min-width:0}
.ig-search .inp{width:100%}
.ig-wrap{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:auto;max-height:calc(100vh - 230px)}
.ig{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.ig th,.ig td{padding:7px 10px;border-bottom:1px solid var(--border);border-right:1px solid var(--border);white-space:nowrap;text-align:left;vertical-align:middle}
.ig th:last-child,.ig td:last-child{border-right:0}
.ig thead th{position:sticky;top:0;z-index:2;background:var(--bg);font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);cursor:pointer;user-select:none}
.ig thead th.is-asc::after{content:" ▲";font-size:9px}
.ig thead th.is-desc::after{content:" ▼";font-size:9px}
.ig tbody tr:hover td{background:#f7f5f0}
.ig tbody tr.is-sel td{background:#eef4f3}
.ig .ig-check{width:34px;text-align:center;position:sticky;left:0;z-index:1;background:inherit}
.ig thead .ig-check{z-index:3}
.ig td.ig-check{background:var(--white)}
.ig tbody tr.is-sel td.ig-check{background:#eef4f3}
.ig input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:var(--brand)}
.ig-num{text-align:right!important;font-variant-numeric:tabular-nums}
.ig-mono{font-family:ui-monospace,Menlo,monospace;font-size:12px}
.ig-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef1f6;font-size:12px}
.ig-tag{display:inline-block;padding:0 6px;border-radius:4px;background:var(--bg);font-size:11px;color:var(--muted)}
.ig-where{white-space:normal;min-width:200px;color:var(--muted)}
.ig .inv-name > span:last-child{white-space:normal;min-width:180px}
.ig .ig-tag{margin-left:4px}
.ig-count{font-size:12.5px;margin:8px 2px 90px}
.ig-bulk{position:fixed;left:50%;transform:translateX(-50%);bottom:18px;z-index:40;display:flex;flex-wrap:wrap;gap:10px;align-items:center;background:var(--white);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:10px 14px;max-width:calc(100vw - 32px)}
.ig-bulk[hidden]{display:none}
.ig-bulk__group{display:flex;gap:6px;align-items:center}
.ig-bulk .inp--sm{width:150px}
@media (min-width:769px){.ig-bulk{left:calc(50% + var(--sidebar-w) / 2)}}
</style>';
}

/**
 * JS of the spreadsheet-style tables — echo once per page. Defines
 * InvGrid({table, form, all, bulk, sel, count, clear, hidden(row), numeric[], noun}):
 * row ticking with shift-click ranges (only the rows shown), select-all, the bulk
 * bar (shown while something is ticked), "N of M" count, click-a-header sorting
 * on data-<key> attributes, and only-shown-rows-submit. A row without a checkbox
 * (e.g. fully received) is never selectable. Returns {filter, refresh}; call
 * filter() after the search / filter inputs change. Client-side only — the
 * server re-checks everything posted.
 */
function inv_grid_js(): string {
    return <<<'JS'
<script>
window.InvGrid = function (o) {
  var table = o.table, body = table.tBodies[0], rows = Array.prototype.slice.call(body.rows), last = null;
  function box(r) { return r.querySelector('input[type=checkbox]'); }
  function ticked(r) { var b = box(r); return !!(b && b.checked); }
  function shown() { return rows.filter(function (r) { return !r.hidden; }); }
  function pickable() { return shown().filter(function (r) { return box(r); }); }
  function refresh() {
    var n = rows.filter(ticked).length;
    rows.forEach(function (r) { r.classList.toggle('is-sel', ticked(r)); });
    o.bulk.hidden = n === 0; o.sel.textContent = n + ' selected';
    var vis = pickable(), vn = vis.filter(ticked).length;
    o.all.checked = vis.length > 0 && vn === vis.length; o.all.indeterminate = vn > 0 && vn < vis.length;
    o.count.textContent = shown().length + ' of ' + rows.length + ' ' + (o.noun || 'items');
  }
  function filter() {
    rows.forEach(function (r) { r.hidden = !!o.hidden(r); });
    refresh();
  }
  rows.forEach(function (r, i) {
    var b = box(r); if (!b) return;
    b.addEventListener('click', function (ev) {
      // Shift-click ticks the whole range (only the rows currently shown).
      if (ev.shiftKey && last !== null) {
        var vis = pickable(), a = vis.indexOf(rows[last]), z = vis.indexOf(r);
        if (a > -1 && z > -1) vis.slice(Math.min(a, z), Math.max(a, z) + 1).forEach(function (x) { box(x).checked = b.checked; });
      }
      last = i; refresh();
    });
  });
  o.all.addEventListener('change', function () { pickable().forEach(function (r) { box(r).checked = o.all.checked; }); refresh(); });
  o.clear.addEventListener('click', function () { rows.forEach(function (r) { if (box(r)) box(r).checked = false; }); refresh(); });
  // Only ticked rows that are still shown are submitted.
  o.form.addEventListener('submit', function () { rows.forEach(function (r) { if (r.hidden && box(r)) box(r).checked = false; }); });
  // Click a column title to sort.
  table.querySelectorAll('th[data-sort]').forEach(function (th) {
    th.addEventListener('click', function () {
      var k = th.getAttribute('data-sort'), asc = !th.classList.contains('is-asc');
      table.querySelectorAll('th[data-sort]').forEach(function (x) { x.classList.remove('is-asc', 'is-desc'); });
      th.classList.add(asc ? 'is-asc' : 'is-desc');
      var num = (o.numeric || []).indexOf(k) > -1;
      rows.sort(function (a, b) {
        var x = a.getAttribute('data-' + k) || '', y = b.getAttribute('data-' + k) || '';
        var d = num ? (parseFloat(x) - parseFloat(y)) : x.localeCompare(y, undefined, { numeric: true });
        return asc ? d : -d;
      });
      rows.forEach(function (r) { body.appendChild(r); });
    });
  });
  filter();
  return { filter: filter, refresh: refresh };
};
</script>
JS;
}
