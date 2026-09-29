<?php
declare(strict_types=1);
// Inventory spreadsheet list — rows per place + bulk actions. Run: php tests/inventory_grid_logic.php
// Pure checks always run; the DB block runs in ONE rolled-back transaction (SKIP without a DB).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-grid.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

check('ids: unique positive ints', inv_grid_ids(['3', 3, 'x', '-1', 0, '7']) === [3, 7]);
check('ids: not an array → empty', inv_grid_ids(null) === [] && inv_grid_ids('5') === [5]);

try { db()->query('SELECT 1'); } catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}
if (!inv_supported()) { echo "\nSKIP  DB block (add_inventory.sql not applied)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    $sfx = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $refused = function (callable $fn): string { try { $fn(); } catch (InvRefusal $e) { return $e->getMessage(); } return ''; };
    $vA   = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Grid A')", [':s' => "zz-grid-a-{$sfx}"]);
    $vB   = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Grid B')", [':s' => "zz-grid-b-{$sfx}"]);
    $locA = inv_property_location_id($vA);
    $locB = inv_property_location_id($vB);
    $villa = inv_create_area($locA, "ZZ Villa {$sfx}");
    $couch = inv_create_item(['name' => "ZZ Grid couch {$sfx}", 'item_type' => 'operational', 'sku' => 'V001', 'replacement_value' => 100]);
    $lamp  = inv_create_item(['name' => "ZZ Grid lamp {$sfx}", 'item_type' => 'operational']);
    $tv    = inv_create_item(['name' => "ZZ Grid tv {$sfx}", 'item_type' => 'operational', 'tracking' => 'serial']);
    inv_move(['item_id' => $couch, 'qty' => 8, 'to' => $locA, 'reason' => 'receive']);
    inv_move(['item_id' => $lamp,  'qty' => 3, 'to' => $villa, 'reason' => 'receive']);
    inv_set_par($couch, $villa, 1);
    inv_asset_create($tv, $locA, ['serial' => "ZZTV-{$sfx}"], null);

    $pick = fn(array $rows, int $id) => array_values(array_filter($rows, fn($r) => (int)$r['id'] === $id))[0] ?? null;
    $all  = inv_grid_rows(null, 0);
    check('rows: all places — couch 8 in stock with a breakdown', ($r = $pick($all, $couch)) && (int)$r['qty_all'] === 8 && count($r['breakdown']) === 1);
    $here = inv_grid_rows(null, $locA);
    check('rows: a property includes its areas (lamp in the villa, couch par there)', ($l = $pick($here, $lamp)) && (int)$l['qty_here'] === 3
        && ($c = $pick($here, $couch)) && (int)$c['qty_here'] === 8 && (int)$c['par_here'] === 1);
    check('rows: another place lists nothing of these', $pick(inv_grid_rows(null, $locB), $couch) === null);
    check('rows: a manager of B does not see A\'s stock', (int)($pick(inv_grid_rows([$vB], 0), $couch)['qty_all'] ?? -1) === 0);

    $r = inv_grid_bulk_move([$couch, $lamp, $tv], $locA, $locB, null);
    check('move: all of the couch at A goes to B; the lamp (in the villa, not A itself) and the serial tv are skipped',
        $r['items'] === 1 && $r['pieces'] === 8 && $r['skipped'] === 2 && inv_balance($couch, $locB) === 8 && inv_balance($couch, $locA) === 0);
    check('move: same place refused', $refused(fn() => inv_grid_bulk_move([$couch], $locB, $locB, null)) !== '');
    check('move: nothing ticked refused', $refused(fn() => inv_grid_bulk_move([], $locA, $locB, null)) !== '');

    check('category: set on the ticked items', inv_grid_bulk_category([$couch, $lamp], 'ZZ Furniture') === 2
        && inv_fetch_item($couch)['category'] === 'ZZ Furniture');
    inv_grid_bulk_category([$lamp], '');
    check('category: blank clears it', inv_fetch_item($lamp)['category'] === null);

    check('delete: the ticked items are gone', inv_grid_bulk_delete([$couch, $lamp], null) === 2 && !inv_fetch_item($couch) && !inv_fetch_item($lamp));
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
