<?php
declare(strict_types=1);
// Inventory orders — an import goes ON ORDER, receipts record quantity + place.
// Run: php tests/inventory_orders_logic.php
// Pure checks always run; the DB block runs in ONE rolled-back transaction and
// SKIPs when no DB is reachable or add_inventory_orders.sql is not applied.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-orders.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure ────────────────────────────────────────────────────────────────────
check('status: nothing received → open', inv_order_status([['qty_ordered' => 8, 'qty_received' => 0], ['qty_ordered' => 2, 'qty_received' => 0]]) === 'open');
check('status: something received → partial', inv_order_status([['qty_ordered' => 8, 'qty_received' => 5], ['qty_ordered' => 2, 'qty_received' => 0]]) === 'partial');
check('status: one line full, another untouched → partial', inv_order_status([['qty_ordered' => 8, 'qty_received' => 8], ['qty_ordered' => 2, 'qty_received' => 0]]) === 'partial');
check('status: every line full → received', inv_order_status([['qty_ordered' => 8, 'qty_received' => 8], ['qty_ordered' => 2, 'qty_received' => 2]]) === 'received');
check('status: no lines → open', inv_order_status([]) === 'open');

check('pack diff: no packing data at all → null', inv_order_pack_diff(8, 0, false) === null);
check('pack diff: nothing packed → not_packed', inv_order_pack_diff(8, 0, true) === 'not_packed');
check('pack diff: fewer packed → less_packed', inv_order_pack_diff(8, 2, true) === 'less_packed');
check('pack diff: more packed → more_packed', inv_order_pack_diff(4, 12, true) === 'more_packed');
check('pack diff: equal → null', inv_order_pack_diff(8, 8, true) === null);

// ── DB ──────────────────────────────────────────────────────────────────────
try { db()->query('SELECT 1'); } catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}
if (!inv_orders_supported()) {
    echo "\nSKIP  DB block (add_inventory_orders.sql not applied)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $refused = function (callable $fn): string { try { $fn(); } catch (InvRefusal $e) { return $e->getMessage(); } return ''; };
    $vA   = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Ord A')", [':s' => "zz-ord-a-{$sfx}"]);
    $vB   = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Ord B')", [':s' => "zz-ord-b-{$sfx}"]);
    $locA = inv_property_location_id($vA);
    $locB = inv_property_location_id($vB);
    $cnt  = inv_create_item(['name' => "ZZ Ord glass box {$sfx}", 'item_type' => 'operational', 'sku' => 'V011']);
    $cnt2 = inv_create_item(['name' => "ZZ Ord lamp {$sfx}", 'item_type' => 'operational']);
    $ser  = inv_create_item(['name' => "ZZ Ord fridge {$sfx}", 'item_type' => 'operational', 'tracking' => 'serial']);
    $count = fn(string $sql, array $p = []): int => (int) db_query($sql, $p)->fetchColumn();
    $orderLine = function (int $orderId, int $itemId) {
        return db_query('SELECT * FROM inv_order_lines WHERE order_id = :o AND item_id = :i', [':o' => $orderId, ':i' => $itemId])->fetch();
    };

    $db_first = fn(int $o) => db_query('SELECT * FROM inv_order_lines WHERE order_id = :o ORDER BY id LIMIT 1', [':o' => $o])->fetch();
    $parsed = [
        ['sheet' => 'S', 'row' => 2, 'section' => 'Living', 'code' => 'V011', 'description' => 'Glass Box', 'qty' => 4],
        ['sheet' => 'S', 'row' => 3, 'section' => 'Bedroom', 'code' => 'V011', 'description' => 'Glass Box (bedroom)', 'qty' => 4],
        ['sheet' => 'S', 'row' => 4, 'section' => 'Kitchen', 'code' => 'V020', 'description' => 'Mini Fridge', 'qty' => 2],
        ['sheet' => 'S', 'row' => 5, 'section' => 'Kitchen', 'code' => 'V021', 'description' => 'Unmapped line', 'qty' => 9],
    ];
    $fp = sha1("zz-{$sfx}");
    $oid = inv_order_create("ZZ Order {$sfx}", $parsed, [0 => $cnt, 1 => $cnt, 2 => $ser], [0 => $locA, 1 => $locA, 2 => $locB], 'list.xlsx', $fp, null);
    $lc = $orderLine($oid, $cnt); $ls = $orderLine($oid, $ser);
    check('create: two parsed lines of the same item + place become ONE order line of 8', $lc && (int)$lc['qty_ordered'] === 8 && $count('SELECT COUNT(*) FROM inv_order_lines WHERE order_id = :o AND item_id = :i', [':o' => $oid, ':i' => $cnt]) === 1);
    check('create: description / code / section come from the first line', $lc['description'] === 'Glass Box' && $lc['code'] === 'V011' && $lc['section'] === 'Living');
    check('create: a line with no item is skipped — two order lines in all', $count('SELECT COUNT(*) FROM inv_order_lines WHERE order_id = :o', [':o' => $oid]) === 2
        && $ls && (int)$ls['qty_ordered'] === 2 && (int)$ls['planned_location_id'] === $locB);
    check('create: no stock moved at all', $count('SELECT COUNT(*) FROM inv_moves WHERE item_id IN (:a, :b)', [':a' => $cnt, ':b' => $ser]) === 0);
    check('create: the same item at two places stays two lines', ($o2 = inv_order_create("ZZ Split {$sfx}", $parsed, [0 => $cnt2, 1 => $cnt2], [0 => $locA, 1 => $locB], 'split.xlsx', sha1("split-{$sfx}"), null))
        && $count('SELECT COUNT(*) FROM inv_order_lines WHERE order_id = :o', [':o' => $o2]) === 2);
    check('create: an empty order is refused', $refused(fn() => inv_order_create('Empty', $parsed, [], [], 'x.xlsx', 'x', null)) !== '');
    check('create: starts open', inv_order_fetch($oid)['status'] === 'open');
    check('open_for: finds the order by its fingerprint', ($f = inv_order_open_for($fp)) && (int)$f['id'] === $oid && inv_order_open_for(sha1('nothing')) === null);
    check('on order: the counted item shows all 8', (inv_on_order_by_item()[$cnt] ?? 0) === 8);

    $lines = inv_order_lines($oid, null);
    $lcView = array_values(array_filter($lines, fn($l) => (int)$l['item_id'] === $cnt))[0];
    check('lines: item name, planned place label and still_to_come', str_contains($lcView['item_name'], 'glass box') && $lcView['place_label'] === 'ZZ Ord A' && (int)$lcView['still_to_come'] === 8 && $lcView['receipts'] === []);

    // Scope: a manager of B sees only the line planned for B; a stranger sees nothing.
    $vC = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Ord C')", [':s' => "zz-ord-c-{$sfx}"]);
    inv_property_location_id($vC);
    check('scope: a manager of B sees the order with only B\'s line', count(inv_order_lines($oid, [$vB])) === 1
        && in_array($oid, array_map(fn($o) => (int)$o['id'], inv_orders_list([$vB])), true));
    check('scope: an account with neither place sees no lines and no order', inv_order_lines($oid, [$vC]) === []
        && !in_array($oid, array_map(fn($o) => (int)$o['id'], inv_orders_list([$vC])), true));
    check('scope: the owner sees the order in the list', in_array($oid, array_map(fn($o) => (int)$o['id'], inv_orders_list(null)), true));

    // Receive 5 of 8 into A.
    $r = inv_order_receive($oid, [(int)$lc['id'] => ['qty' => 5, 'location_id' => $locA]], null);
    check('receive: 5 of 8 into A → balance A is 5, one line, 5 pieces', inv_balance($cnt, $locA) === 5 && $r['lines'] === 1 && $r['pieces'] === 5);
    check('receive: the order is partial and the line shows 5 received', inv_order_fetch($oid)['status'] === 'partial' && (int)$orderLine($oid, $cnt)['qty_received'] === 5);
    check('receive: one receipt row (qty, place)', $count('SELECT COUNT(*) FROM inv_order_receipts WHERE line_id = :l AND qty = 5 AND location_id = :p', [':l' => $lc['id'], ':p' => $locA]) === 1);
    check('receive: the move is a "receive" carrying the order note',
        $count("SELECT COUNT(*) FROM inv_moves WHERE item_id = :i AND to_location_id = :l AND reason = 'receive' AND note = :n", [':i' => $cnt, ':l' => $locA, ':n' => "Order #{$oid} ZZ Order {$sfx}"]) === 1);
    check('receive: on order drops to the 3 still to come', (inv_on_order_by_item()[$cnt] ?? 0) === 3);
    check('receive: over-receiving is refused and changes nothing', str_contains($refused(fn() => inv_order_receive($oid, [(int)$lc['id'] => ['qty' => 4, 'location_id' => $locA]], null)), 'only 3 still to come')
        && inv_balance($cnt, $locA) === 5);
    check('receive: a line of another order is refused', $refused(fn() => inv_order_receive($oid, [999999999 => ['qty' => 1, 'location_id' => $locA]], null)) !== '');
    $locClosed = inv_create_area($locA, "ZZ Closed {$sfx}");
    db_query('UPDATE inv_locations SET is_active = FALSE WHERE id = :i', [':i' => $locClosed]);
    check('receive: a closed place is refused', $refused(fn() => inv_order_receive($oid, [(int)$lc['id'] => ['qty' => 1, 'location_id' => $locClosed]], null)) !== ''
        && $refused(fn() => inv_order_receive($oid, [(int)$lc['id'] => ['qty' => 1, 'location_id' => 0]], null)) !== '');
    check('cancel: refused once something was received', str_contains($refused(fn() => inv_order_cancel($oid)), 'already received'));

    // Receive the remaining 3 into B.
    inv_order_receive($oid, [(int)$lc['id'] => ['qty' => 3, 'location_id' => $locB]], null);
    check('receive: the last 3 into B → balance B is 3, A still 5', inv_balance($cnt, $locB) === 3 && inv_balance($cnt, $locA) === 5);
    check('receive: the counted line is full, the serial line untouched → still partial', inv_order_fetch($oid)['status'] === 'partial');
    check('on order: the counted item drops out once received', !isset(inv_on_order_by_item()[$cnt]) && (inv_on_order_by_item()[$ser] ?? 0) === 2);
    $rcv = array_values(array_filter(inv_order_lines($oid, null), fn($l) => (int)$l['item_id'] === $cnt))[0];
    check('lines: both receipts are listed with place and quantity', count($rcv['receipts']) === 2 && $rcv['receipts'][0]['qty'] === 5 && $rcv['receipts'][1]['location_name'] === 'ZZ Ord B' && $rcv['still_to_come'] === 0);

    // Serial line of 2 → 2 units, no serial numbers.
    $r = inv_order_receive($oid, [(int)$ls['id'] => ['qty' => 2, 'location_id' => $locA]], null);
    check('serial: 2 pieces become 2 units at the place, without serials', $r['pieces'] === 2
        && $count("SELECT COUNT(*) FROM inv_assets WHERE item_id = :i AND location_id = :l AND status = 'active' AND serial IS NULL AND condition = 'new'", [':i' => $ser, ':l' => $locA]) === 2
        && inv_balance($ser, $locA) === 2);
    check('serial: every line full → the order is received', inv_order_fetch($oid)['status'] === 'received');
    check('receive: a received order takes nothing more', $refused(fn() => inv_order_receive($oid, [(int)$lc['id'] => ['qty' => 1, 'location_id' => $locA]], null)) !== '');
    check('open_for: a received order still counts as this list\'s order', inv_order_open_for($fp) !== null);

    // Cancel a fresh order.
    check('cancel: a fresh order can be cancelled', ($inv = inv_order_cancel($o2)) === null && inv_order_fetch($o2)['status'] === 'cancelled');
    check('cancel: a cancelled order takes no receipts', $refused(fn() => inv_order_receive($o2, [(int)$db_first($o2)['id'] => ['qty' => 1, 'location_id' => $locA]], null)) !== '');
    check('cancel: its quantities are no longer on order', !isset(inv_on_order_by_item()[$cnt2]));
    $ol = inv_orders_list(null);
    $idx = array_flip(array_map(fn($o) => (int)$o['id'], $ol));
    check('list: the cancelled order sorts after the live ones', $idx[$o2] > $idx[$oid]);
    $fp2 = sha1("again-{$sfx}");
    $o3 = inv_order_create('again', $parsed, [0 => $cnt], [0 => $locA], 'a.xlsx', $fp2, null);
    inv_order_cancel($o3);
    check('open_for: a cancelled order is not "already imported"', inv_order_open_for($fp2) === null);

    // ── Undo keeps the order in step ────────────────────────────────────────
    // (the receipt→move link needs add_inventory_order_receipt_moves.sql; without it the legacy
    //  note match does the same job, so these checks hold either side of the migration.)
    $linked = inv_order_receipt_moves_supported();
    $undoOrder = function (string $tag, int $qty = 8) use ($cnt2, $locA, $sfx): array {
        $oid = inv_order_create("ZZ Undo {$tag} {$sfx}", [['sheet' => 'S', 'row' => 2, 'section' => 'X', 'code' => 'U1', 'description' => 'Lamp', 'qty' => $qty]],
            [0 => $cnt2], [0 => $locA], 'u.xlsx', sha1("undo-{$tag}-{$sfx}"), null);
        return [$oid, (int) db_query('SELECT id FROM inv_order_lines WHERE order_id = :o', [':o' => $oid])->fetchColumn()];
    };
    $lineQty = fn(int $lid): int => (int) db_query('SELECT qty_received FROM inv_order_lines WHERE id = :l', [':l' => $lid])->fetchColumn();
    $receiptsOf = fn(int $lid): int => (int) db_query('SELECT COUNT(*) FROM inv_order_receipts WHERE line_id = :l', [':l' => $lid])->fetchColumn();
    $moveOf = fn(int $oid): int => (int) db_query("SELECT id FROM inv_moves WHERE item_id = :i AND reason = 'receive' AND note LIKE :n ORDER BY id DESC LIMIT 1",
        [':i' => $cnt2, ':n' => "Order #{$oid} %"])->fetchColumn();
    $base = inv_balance($cnt2, $locA);

    // 1. Undo the receive MOVEMENT (the item page's owner Undo) → the order forgets the receipt.
    [$u1, $u1l] = $undoOrder('move');
    inv_order_receive($u1, [$u1l => ['qty' => 5, 'location_id' => $locA]], null);
    $mv1 = $moveOf($u1);
    if ($linked) check('link: the receipt stores its receive move id', (int) db_query('SELECT move_id FROM inv_order_receipts WHERE line_id = :l', [':l' => $u1l])->fetchColumn() === $mv1);
    check('link: a fresh receive leaves the order partial', inv_order_fetch($u1)['status'] === 'partial' && $lineQty($u1l) === 5);
    inv_undo_move($mv1, null);
    check('undo move: stock is back to where it was', inv_balance($cnt2, $locA) === $base);
    check('undo move: the line shows 0 received and the receipt is gone', $lineQty($u1l) === 0 && $receiptsOf($u1l) === 0);
    check('undo move: the order is back to open and 8 are on order again', inv_order_fetch($u1)['status'] === 'open');

    // 2. Receive again, then undo the RECEIPT from the order page.
    inv_order_receive($u1, [$u1l => ['qty' => 5, 'location_id' => $locA]], null);
    $rid1 = (int) db_query('SELECT id FROM inv_order_receipts WHERE line_id = :l', [':l' => $u1l])->fetchColumn();
    inv_order_undo_receipt($rid1, null);
    check('undo receipt: stock out again, receipt gone, line 0, order open',
        inv_balance($cnt2, $locA) === $base && $receiptsOf($u1l) === 0 && $lineQty($u1l) === 0 && inv_order_fetch($u1)['status'] === 'open'
        && $moveOf($u1) === 0);
    check('undo receipt: the same receipt cannot be undone twice', $refused(fn() => inv_order_undo_receipt($rid1, null)) !== '');

    // 3. Legacy receipt (written before the link): no move_id, matched by note + item + place + qty.
    inv_order_receive($u1, [$u1l => ['qty' => 5, 'location_id' => $locA]], null);
    if ($linked) db_query('UPDATE inv_order_receipts SET move_id = NULL WHERE line_id = :l', [':l' => $u1l]);
    inv_undo_move($moveOf($u1), null);
    check('legacy: undoing the move still finds the receipt by its note', $lineQty($u1l) === 0 && $receiptsOf($u1l) === 0
        && inv_balance($cnt2, $locA) === $base && inv_order_fetch($u1)['status'] === 'open');
    inv_order_receive($u1, [$u1l => ['qty' => 5, 'location_id' => $locA]], null);
    if ($linked) db_query('UPDATE inv_order_receipts SET move_id = NULL WHERE line_id = :l', [':l' => $u1l]);
    $ridL = (int) db_query('SELECT id FROM inv_order_receipts WHERE line_id = :l', [':l' => $u1l])->fetchColumn();
    inv_order_undo_receipt($ridL, null);
    check('legacy: undoing the receipt finds its move by the note', $lineQty($u1l) === 0 && $receiptsOf($u1l) === 0 && $moveOf($u1) === 0 && inv_balance($cnt2, $locA) === $base);

    // 4. The move was already undone before the fix existed: the receipt has no move any more.
    $ghost = $ins('INSERT INTO inv_order_receipts (line_id, qty, location_id) VALUES (:l, 5, :p)', [':l' => $u1l, ':p' => $locA]);
    db_query('UPDATE inv_order_lines SET qty_received = 5 WHERE id = :l', [':l' => $u1l]);
    db_query("UPDATE inv_orders SET status = 'partial' WHERE id = :o", [':o' => $u1]);
    inv_order_undo_receipt($ghost, null);
    check('already undone: the receipt is just forgotten, stock untouched, order open',
        $receiptsOf($u1l) === 0 && $lineQty($u1l) === 0 && inv_balance($cnt2, $locA) === $base && inv_order_fetch($u1)['status'] === 'open');

    // 5. Two receipts (5 then 3); undo the first → 3 received, status partial.
    [$u2, $u2l] = $undoOrder('partial');
    inv_order_receive($u2, [$u2l => ['qty' => 5, 'location_id' => $locA]], null);
    inv_order_receive($u2, [$u2l => ['qty' => 3, 'location_id' => $locA]], null);
    check('partial: fully received before the undo', inv_order_fetch($u2)['status'] === 'received' && $lineQty($u2l) === 8);
    $first = (int) db_query('SELECT id FROM inv_order_receipts WHERE line_id = :l AND qty = 5', [':l' => $u2l])->fetchColumn();
    inv_order_undo_receipt($first, null);
    check('partial: undoing the 5 leaves 3 received, one receipt, status partial',
        $lineQty($u2l) === 3 && $receiptsOf($u2l) === 1 && inv_order_fetch($u2)['status'] === 'partial' && inv_balance($cnt2, $locA) === $base + 3);

    // 6. Stock that has moved on blocks the undo, and leaves the order as it was.
    [$u3, $u3l] = $undoOrder('blocked');
    inv_order_receive($u3, [$u3l => ['qty' => 4, 'location_id' => $locA]], null);
    $ridB = (int) db_query('SELECT id FROM inv_order_receipts WHERE line_id = :l', [':l' => $u3l])->fetchColumn();
    $before = inv_balance($cnt2, $locA);
    inv_move(['item_id' => $cnt2, 'qty' => $before, 'from' => $locA, 'to' => $locB, 'reason' => 'transfer', 'user_id' => null, 'note' => 'moved on']);
    $msg = $refused(fn() => inv_order_undo_receipt($ridB, null));
    check('blocked: refused (would go below zero) and the order keeps its receipt',
        str_contains($msg, 'below zero') && $receiptsOf($u3l) === 1 && $lineQty($u3l) === 4 && inv_order_fetch($u3)['status'] === 'partial');

    // 7. A receipt that is already gone is a no-op; a serial receipt is refused.
    check('forget: a receipt that is already gone is a no-op', inv_order_forget_receipt(999999999) === null);
    [$u4, $u4l] = $undoOrder('serialline');
    $serLine = (int) $ins('INSERT INTO inv_order_lines (order_id, item_id, description, qty_ordered, qty_received) VALUES (:o, :i, :d, 2, 2)', [':o' => $u4, ':i' => $ser, ':d' => 'Fridge']);
    $serRc = $ins('INSERT INTO inv_order_receipts (line_id, qty, location_id) VALUES (:l, 2, :p)', [':l' => $serLine, ':p' => $locA]);
    check('serial: a serial receipt is refused with a pointer to the item page', str_contains($refused(fn() => inv_order_undo_receipt($serRc, null)), 'item page') && $receiptsOf($serLine) === 1);
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
