<?php
declare(strict_types=1);
/**
 * Inventory — import items from a supplier Excel into the catalogue. ITEMS ONLY:
 * no stock is moved (quantities are added later by receiving or counting).
 * Lines are merged by name (inv_ship_group()); a name that already exists as an
 * active item (same merge key) is left as it is. Test: php tests/inventory_import_logic.php
 */
require_once __DIR__ . '/inventory.php';
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
 * $groups: inv_ship_group($lines). New items get: name = group name, category + kind
 * (operational | spare | serial → item_type spare for 'spare', tracking serial for 'serial') +
 * unit from the group's suggestions, sku = inv_import_sku(). Existing ones (by merge key)
 * are skipped. Returns ['created' => int, 'existing' => int, 'created_ids' => int[]].
 */
function inv_import_items(array $lines, array $groups): array {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    return inv_tx(function () use ($lines, $groups): array {
        $existing = inv_import_existing_items();
        $created    = 0;
        $existingN  = 0;
        $createdIds = [];
        foreach ($groups as $g) {
            $name = mb_substr(inv_ship_text((string)($g['name'] ?? '')), 0, 160);
            if ($name === '') continue;
            $key = inv_ship_key($name);
            if (isset($existing[$key])) { $existingN++; continue; }
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
        }
        return ['created' => $created, 'existing' => $existingN, 'created_ids' => $createdIds];
    });
}
