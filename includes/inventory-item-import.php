<?php
declare(strict_types=1);
/**
 * Inventory — import items from a supplier Excel into the catalogue. ITEMS ONLY:
 * no stock is EVER moved by an import (quantities are added later by receiving
 * or counting). Lines are merged by name (inv_ship_group()); a name that already
 * exists as an active item (same merge key) is left as it is.
 *
 * The preview also maps each item-code PREFIX (inv_ship_prefix()) to an
 * inventory location; the list quantity for that prefix's lines becomes that
 * location's PAR LEVEL — "what it should have" (inv_set_par()), overwritten on
 * a re-import, never a stock move. The prefix → place mapping is remembered in
 * settings key `inv_import_prefix_places` (JSON), seeded from the owner's
 * defaults (INV_IMPORT_DEFAULT_PREFIX_PLACES, by location NAME). Serial-tracked
 * items are skipped — their units are assigned one by one.
 * Test: php tests/inventory_import_logic.php
 */
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/inventory-views.php';   // inv_locations_visible(), inv_location_editable(), inv_location_label(), inv_sort_locations()
require_once __DIR__ . '/inventory-shipment-import.php';

/** Active items by merge key: [inv_ship_key(name) => ['id','name','tracking']] (first by id wins). One query. */
function inv_import_existing_items(): array {
    if (!inv_supported()) return [];
    $rows = db_query("SELECT id, name, tracking FROM inv_items WHERE is_active = TRUE ORDER BY id")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $key = inv_ship_key((string)$r['name']);
        if (!isset($out[$key])) {
            $out[$key] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'tracking' => (string)$r['tracking']];
        }
    }
    return $out;
}

/** The item codes of a group's lines, unique, in list order, joined ", " and cut to 60 chars (the sku column) — PURE. */
function inv_import_sku(array $group, array $lines): string {
    $codes = [];
    foreach ((array)($group['lines'] ?? []) as $i) {
        $code = trim((string)($lines[$i]['code'] ?? ''));
        if ($code !== '' && !in_array($code, $codes, true)) $codes[] = $code;
    }
    return mb_substr(implode(', ', $codes), 0, 60);
}

/**
 * Create the items of a parsed list — one inv_tx(). $lines: inv_ship_parse_workbook()['lines'];
 * $groups: inv_ship_group($lines) — keyed by merge key. New items get: name =
 * group name, category + kind (operational | spare | serial → item_type spare
 * for 'spare', tracking serial for 'serial') + unit from the group's
 * suggestions, sku = inv_import_sku(). Existing ones (by merge key) are
 * skipped. Returns ['created' => int, 'existing' => int, 'created_ids' => int[],
 * 'group_items' => [group key => item id]] — group_items covers EVERY group,
 * created or existing, so a caller can map lines (via a group's 'lines') to
 * their resulting item id (e.g. for inv_import_par_plan()).
 */
function inv_import_items(array $lines, array $groups): array {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    return inv_tx(function () use ($lines, $groups): array {
        $existing = inv_import_existing_items();
        $created     = 0;
        $existingN   = 0;
        $createdIds  = [];
        $groupItems  = [];
        foreach ($groups as $gkey => $g) {
            $name = mb_substr(inv_ship_text((string)($g['name'] ?? '')), 0, 160);
            if ($name === '') continue;
            $key = inv_ship_key($name);
            if (isset($existing[$key])) {
                $existingN++;
                $groupItems[$gkey] = $existing[$key]['id'];
                continue;
            }
            $kind = isset(INV_SHIP_KINDS[$g['kind'] ?? '']) ? (string)$g['kind'] : 'operational';
            $id = inv_create_item([
                'name'        => $name,
                'item_type'   => $kind === 'spare' ? 'spare' : 'operational',
                'tracking'    => $kind === 'serial' ? 'serial' : 'qty',
                'category'    => (string)($g['category'] ?? ''),
                'unit_label'  => (string)($g['unit'] ?? 'pcs'),
                'sku'         => inv_import_sku($g, $lines),
            ]);
            $existing[$key] = ['id' => $id, 'name' => $name, 'tracking' => $kind === 'serial' ? 'serial' : 'qty'];
            $created++;
            $createdIds[] = $id;
            $groupItems[$gkey] = $id;
        }
        return ['created' => $created, 'existing' => $existingN, 'created_ids' => $createdIds, 'group_items' => $groupItems];
    });
}

// ── Item-code prefix → place (par levels) ───────────────────────────────────

const INV_IMPORT_PREFIX_PLACES_SETTING = 'inv_import_prefix_places';

/**
 * Active property + area locations this account may edit, sorted for a picker.
 * These are the only kinds of place a par level makes sense on (a par lives on
 * inv_balances, read on the item/location pages — Main stock/other stores and
 * outlets/people are out of scope for "what a property should have").
 */
function inv_import_place_options(?array $venueIds): array {
    if (!inv_supported()) return [];
    $out = [];
    foreach (inv_locations_visible($venueIds, true) as $l) {
        if (!in_array($l['kind'], ['property', 'area'], true)) continue;
        if (!inv_location_editable($l, $venueIds)) continue;
        $out[] = ['id' => (int)$l['id'], 'name' => (string)$l['name'], 'label' => inv_location_label($l)];
    }
    return $out;
}

/**
 * The place each prefix defaults to: [prefix => location id] (0 = none). First
 * the account's own remembered mapping (settings `inv_import_prefix_places`,
 * JSON {prefix: location id}; an entry is honoured even when it maps to
 * "Nowhere" (0) — that is a deliberate earlier choice, not a gap); prefixes with
 * no remembered entry fall back to INV_IMPORT_DEFAULT_PREFIX_PLACES matched
 * against $options by exact, case-insensitive location NAME. Read defensively —
 * a malformed settings value is treated as "nothing saved yet".
 */
function inv_import_default_places(array $prefixes, array $options): array {
    $byId = [];
    $byName = [];
    foreach ($options as $o) {
        $byId[(int)$o['id']] = true;
        $byName[mb_strtolower(trim((string)$o['name']))] = (int)$o['id'];
    }
    $saved = [];
    try {
        $raw = setting(INV_IMPORT_PREFIX_PLACES_SETTING, '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $saved = $decoded;
        }
    } catch (Throwable $e) { $saved = []; }

    $out = [];
    foreach ($prefixes as $prefix) {
        $prefix = (string)$prefix;
        if (array_key_exists($prefix, $saved) && is_numeric($saved[$prefix])) {
            $sid = (int)$saved[$prefix];
            $out[$prefix] = ($sid > 0 && isset($byId[$sid])) ? $sid : 0;
            continue;
        }
        $defName = INV_IMPORT_DEFAULT_PREFIX_PLACES[$prefix] ?? null;
        $out[$prefix] = $defName !== null ? ($byName[mb_strtolower($defName)] ?? 0) : 0;
    }
    return $out;
}

/**
 * Write the par levels a plan (inv_import_par_plan()) describes — OVERWRITES
 * with the imported sum, so a re-import gives the same result, never doubles.
 * A serial-tracked item is skipped (its units are assigned one by one — the
 * location page refuses a par for one too). No stock is moved (inv_set_par()
 * only ever touches par_qty). Returns the number of pars set.
 */
function inv_import_apply_pars(array $plan, ?int $userId = null): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    return inv_tx(function () use ($plan): int {
        $n = 0;
        foreach ($plan as $key => $qty) {
            $parts = explode(':', (string)$key, 2);
            if (count($parts) !== 2) continue;
            [$itemId, $locId] = array_map('intval', $parts);
            if ($itemId <= 0 || $locId <= 0) continue;
            $item = inv_fetch_item($itemId);
            if (!$item || $item['tracking'] !== 'qty') continue;   // serial units are assigned one by one
            inv_set_par($itemId, $locId, (int)$qty);
            $n++;
        }
        return $n;
    });
}
