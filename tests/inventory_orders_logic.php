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
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
