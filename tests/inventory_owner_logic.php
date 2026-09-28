<?php
declare(strict_types=1);
// Owner-only inventory corrections (undo / clear / delete / reset). Run:
// php tests/inventory_owner_logic.php
// Pure rules always run. The DB block runs inside ONE transaction that is
// rolled back, and SKIPs when no database is reachable or add_inventory.sql
// is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-owner.php';
require_once __DIR__ . '/../includes/pos-support.php';   // pos_supported() — light guard only

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure: inv_undo_refusal ───────────────────────────────────────────────────
check('undo refusal: a POS sale/void move', str_contains((string) inv_undo_refusal(['pos_sale_id' => 5, 'qty' => 1, 'to_location_id' => 9], null), 'POS sales'));
check('undo refusal: a count-review move', str_contains((string) inv_undo_refusal(['count_line_id' => 5, 'qty' => 1, 'to_location_id' => 9], null), 'count review'));
check('undo refusal: a serial-unit move', str_contains((string) inv_undo_refusal(['asset_id' => 5, 'qty' => 1, 'to_location_id' => 9], null), 'serial'));
check('undo refusal: destination would go below zero, names the item and place', ($__m = inv_undo_refusal(['qty' => 4, 'to_location_id' => 9, 'item_name' => 'Widget', 'to_name' => 'Kitchen'], 1)) !== null
    && str_contains($__m, 'below zero') && str_contains($__m, 'Widget') && str_contains($__m, 'Kitchen'));
check('undo refusal: an allowed transfer (nothing set, enough at the destination)', inv_undo_refusal(['qty' => 4, 'to_location_id' => 9], 10) === null);
check('undo refusal: an allowed loss/receive undo (no "to" end to check)', inv_undo_refusal(['qty' => 4, 'to_location_id' => null], null) === null);
check('undo refusal: the page render check (toQty null) never trips the negative rule', inv_undo_refusal(['qty' => 4, 'to_location_id' => 9], null) === null);

// ── DB-backed ───────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!inv_supported()) {
    echo "\nSKIP  DB block (add_inventory.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx     = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins     = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $count   = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();
    $refused = function (callable $fn): string { try { $fn(); } catch (InvRefusal $e) { return $e->getMessage(); } return ''; };
    $mkUser  = fn(string $role, string $tag) => $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, :r, :n, TRUE)",
        [':e' => "zz-inv-owner-{$tag}-{$sfx}@example.com", ':r' => $role, ':n' => "ZZ {$tag}"]);

    $vA    = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ InvOwner A')", [':s' => "zz-invown-a-{$sfx}"]);
    $store = inv_store_location_id();
    $locA  = inv_property_location_id($vA);

    // ── 1. Undo a movement ──
    $widget = inv_create_item(['name' => "ZZ Owner Widget {$sfx}", 'item_type' => 'operational', 'replacement_value' => 500]);
    $mReceive = inv_move(['item_id' => $widget, 'qty' => 10, 'to' => $locA, 'reason' => 'receive']);
    check('undo: receive 10 at A', inv_balance($widget, $locA) === 10);
    $mTransfer = inv_move(['item_id' => $widget, 'qty' => 4, 'from' => $locA, 'to' => $store, 'reason' => 'transfer']);
    check('undo: transfer leaves A 6, B 4', inv_balance($widget, $locA) === 6 && inv_balance($widget, $store) === 4);
    inv_undo_move($mTransfer, null);
    check('undo: the transfer is reversed exactly — A 10, B 0, the move is gone',
        inv_balance($widget, $locA) === 10 && inv_balance($widget, $store) === 0
        && db_query('SELECT 1 FROM inv_moves WHERE id = :id', [':id' => $mTransfer])->fetchColumn() === false);
    inv_undo_move($mReceive, null);
    check('undo: the receive is reversed too — A back to 0', inv_balance($widget, $locA) === 0);

    $mR2 = inv_move(['item_id' => $widget, 'qty' => 10, 'to' => $locA, 'reason' => 'receive']);
    $mT2 = inv_move(['item_id' => $widget, 'qty' => 4, 'from' => $locA, 'to' => $store, 'reason' => 'transfer']);
    inv_move(['item_id' => $widget, 'qty' => 3, 'from' => $store, 'reason' => 'written_off']);
    check('undo: destination now holds only 1 of the 4 moved', inv_balance($widget, $store) === 1);
    $msg = $refused(fn() => inv_undo_move($mT2, null));
    check('undo: refused — undoing would take the destination below zero', str_contains($msg, 'below zero'));
    check('undo: a refused undo changes nothing', inv_balance($widget, $store) === 1 && inv_balance($widget, $locA) === 6);

    // count-resolution and serial moves, at the DB level
    $area3 = inv_create_area($locA, "ZZ CountArea {$sfx}");
    $cItem = inv_create_item(['name' => "ZZ Owner CountItem {$sfx}", 'item_type' => 'operational', 'replacement_value' => 15]);
    inv_move(['item_id' => $cItem, 'qty' => 10, 'to' => $area3, 'reason' => 'receive']);
    $cUser = $mkUser('owner', 'cntowner');
    $ccid  = inv_count_start($area3, $cUser);
    inv_count_submit($ccid, [$cItem => 7], $cUser);
    $lineId = (int) db_query('SELECT id FROM inv_count_lines WHERE count_id = :c AND item_id = :i', [':c' => $ccid, ':i' => $cItem])->fetchColumn();
    $resolveMoveId = inv_count_resolve_line($lineId, 'missing', $cUser);
    check('undo: refused for a count-resolution move', $resolveMoveId !== null
        && str_contains($refused(fn() => inv_undo_move((int)$resolveMoveId, null)), 'count review'));

    $serialItem = inv_create_item(['name' => "ZZ Owner Serial {$sfx}", 'item_type' => 'employee', 'tracking' => 'serial']);
    $unitId = inv_asset_create($serialItem, $locA, ['serial' => "ZZ-OWN-{$sfx}"], null);
    $serialMoveId = (int) db_query('SELECT id FROM inv_moves WHERE asset_id = :a ORDER BY id DESC LIMIT 1', [':a' => $unitId])->fetchColumn();
    check('undo: refused for a serial-unit move', str_contains($refused(fn() => inv_undo_move($serialMoveId, null)), 'serial'));

    // ── 2. Clear to zero ──
    $moved = inv_clear_to_zero($widget, $locA, null);
    check('clear to zero: the full positive balance moves out', $moved === 6 && inv_balance($widget, $locA) === 0);
    $lastMove = db_query("SELECT reason, value, note FROM inv_moves WHERE item_id = :i AND from_location_id = :l ORDER BY id DESC LIMIT 1", [':i' => $widget, ':l' => $locA])->fetch();
    check('clear to zero: written off at zero value, noted', $lastMove && $lastMove['reason'] === 'written_off' && (float)$lastMove['value'] === 0.0 && $lastMove['note'] === 'Cleared by owner');
    check('clear to zero: nothing to do returns 0 and writes nothing new', inv_clear_to_zero($widget, $locA, null) === 0);

    $negItem = inv_create_item(['name' => "ZZ Owner Neg {$sfx}", 'item_type' => 'spare', 'replacement_value' => 10]);
    inv_balance_lock($negItem, $locA);
    db_query('UPDATE inv_balances SET qty = -4 WHERE item_id = :i AND location_id = :l', [':i' => $negItem, ':l' => $locA]);
    $moved2 = inv_clear_to_zero($negItem, $locA, null);
    $foundReason = db_query("SELECT reason FROM inv_moves WHERE item_id = :i AND to_location_id = :l ORDER BY id DESC LIMIT 1", [':i' => $negItem, ':l' => $locA])->fetchColumn();
    check('clear to zero: a negative balance is found back up to 0', $moved2 === 4 && inv_balance($negItem, $locA) === 0 && $foundReason === 'found');

    $serialClear = inv_create_item(['name' => "ZZ Owner SerialClear {$sfx}", 'item_type' => 'employee', 'tracking' => 'serial']);
    check('clear to zero: refused for a serial-tracked item', str_contains($refused(fn() => inv_clear_to_zero($serialClear, $locA, null)), 'one by one'));

    // ── 3. Delete an item ──
    // Its own area, so the count started below has ONLY this item's line — proving
    // the "counts left with zero lines" cleanup, not just "the line disappears".
    $delArea = inv_create_area($locA, "ZZ DeleteArea {$sfx}");
    $delItem = inv_create_item(['name' => "ZZ Owner Delete {$sfx}", 'item_type' => 'spare', 'replacement_value' => 20]);
    inv_move(['item_id' => $delItem, 'qty' => 5, 'to' => $delArea, 'reason' => 'receive']);
    $delOwner = $mkUser('owner', 'delowner');
    $delCid   = inv_count_start($delArea, $delOwner);
    check('setup: the fresh count has exactly the delete-item line', $count('SELECT COUNT(*) FROM inv_count_lines WHERE count_id = :c', [':c' => $delCid]) === 1);

    inv_delete_item($delItem, $delOwner);
    check('delete item: the item is gone', inv_fetch_item($delItem) === false);
    check('delete item: its moves and balances are gone', $count('SELECT COUNT(*) FROM inv_moves WHERE item_id = :i', [':i' => $delItem]) === 0
        && $count('SELECT COUNT(*) FROM inv_balances WHERE item_id = :i', [':i' => $delItem]) === 0);
    check('delete item: the count left with zero lines is removed too', db_query('SELECT 1 FROM inv_counts WHERE id = :c', [':c' => $delCid])->fetchColumn() === false);

    if (!pos_supported()) {
        echo "SKIP  delete item: POS-linked refusal checks (POS not installed)\n";
    } else {
        $posItem = inv_create_item(['name' => "ZZ Owner PosLinked {$sfx}", 'item_type' => 'sellable', 'replacement_value' => 30]);
        $outlet  = $ins("INSERT INTO pos_outlets (name, slug, kind, venue_id, currency) VALUES (:n, :s, 'shop', :v, 'KES')",
            [':n' => "ZZ Owner Shop {$sfx}", ':s' => "zz-owner-shop-{$sfx}", ':v' => $vA]);
        $posLine = $ins("INSERT INTO pos_items (outlet_id, name, kind, price, track_stock, inv_item_id) VALUES (:o, 'ZZ Owner Listing', 'product', 10, TRUE, :i)",
            [':o' => $outlet, ':i' => $posItem]);
        check('delete item: refused while a POS listing links it', str_contains($refused(fn() => inv_delete_item($posItem, null)), 'POS listing'));

        db_query('UPDATE pos_items SET inv_item_id = NULL WHERE id = :i', [':i' => $posLine]);
        $saleId = $ins("INSERT INTO pos_sales (reference, outlet_id, customer_type, currency, subtotal, total, payment_method, client_uuid)
                         VALUES (:r, :o, 'walkin', 'KES', 10, 10, 'cash', :u)", [':r' => "ZZ-POS-{$sfx}", ':o' => $outlet, ':u' => "zz-owner-cuid-{$sfx}"]);
        inv_move(['item_id' => $posItem, 'qty' => 5, 'to' => $locA, 'reason' => 'receive']);
        inv_move(['item_id' => $posItem, 'qty' => 1, 'from' => $locA, 'reason' => 'sale', 'pos_sale_id' => $saleId]);
        check('delete item: refused once it has a POS sale in its history', str_contains($refused(fn() => inv_delete_item($posItem, null)), 'POS sales'));
        $saleMoveId = (int) db_query('SELECT id FROM inv_moves WHERE pos_sale_id = :s', [':s' => $saleId])->fetchColumn();
        check('undo: refused for a POS sale move', str_contains($refused(fn() => inv_undo_move($saleMoveId, null)), 'POS sales'));
    }

    // ── 4. Delete a location ──
    $area2 = inv_create_area($locA, "ZZ Pantry {$sfx}");
    $itemL = inv_create_item(['name' => "ZZ Owner AreaItem {$sfx}", 'item_type' => 'operational', 'replacement_value' => 50]);
    inv_move(['item_id' => $itemL, 'qty' => 8, 'to' => $area2, 'reason' => 'receive']);
    inv_move(['item_id' => $itemL, 'qty' => 3, 'from' => $area2, 'to' => $locA, 'reason' => 'transfer']);
    check('delete location: setup — area has 5, property has 3', inv_balance($itemL, $area2) === 5 && inv_balance($itemL, $locA) === 3);

    inv_delete_location($area2, null);
    check('delete location: the area is gone', inv_fetch_location($area2) === false);
    check('delete location: both moves were undone — the item nets back to 0 everywhere',
        inv_balance($itemL, $area2) === 0 && inv_balance($itemL, $locA) === 0);

    check('delete location: refused for Main stock', str_contains($refused(fn() => inv_delete_location($store, null)), "can't be deleted"));
    check('delete location: refused for a property', str_contains($refused(fn() => inv_delete_location($locA, null)), 'property / outlet / staff'));

    // ── 5. Reset all inventory ──
    $beforeItems = $count('SELECT COUNT(*) FROM inv_items');
    $beforeMoves = $count('SELECT COUNT(*) FROM inv_moves');
    $beforeLocs  = $count('SELECT COUNT(*) FROM inv_locations');
    $res = inv_reset_all(null);
    check('reset: reports what it removed', $res['items'] === $beforeItems && $res['moves'] === $beforeMoves);
    check('reset: items, moves and balances are all empty', $count('SELECT COUNT(*) FROM inv_items') === 0
        && $count('SELECT COUNT(*) FROM inv_moves') === 0 && $count('SELECT COUNT(*) FROM inv_balances') === 0);
    check('reset: locations are untouched', $count('SELECT COUNT(*) FROM inv_locations') === $beforeLocs);
    if (pos_supported()) {
        check('reset: POS listings lose their inv_item_id link, keep their row', $count('SELECT COUNT(*) FROM pos_items WHERE inv_item_id IS NOT NULL') === 0);
    }

    // ── DB checks (each task inserts its block above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
