<?php
declare(strict_types=1);
/**
 * Inventory — orders. An Excel import creates an ORDER (quantities ON ORDER, no
 * stock); people then record how many arrived and where they were put on
 * admin/inventory-order.php. Test: php tests/inventory_orders_logic.php
 *
 *   • An order never moves stock by itself. Stock enters only in
 *     inv_order_receive(), through inv_move() (counted items) or
 *     inv_asset_create() (serial items — one unit per piece, serial left blank),
 *     all inside ONE inv_tx() with the balance rows pre-locked in the global order.
 *   • One order line per (item, planned place): the import sums the list quantities
 *     that map to the same item and place. Receipts are an append-only trail
 *     (inv_order_receipts) — a partial delivery is simply several receipts.
 *   • Every read is guarded by inv_orders_supported() (pre-migration-safe).
 *   • Nothing here scopes by venue on the WRITE side — the pages check
 *     inv_location_visible() / inv_move_in_scope() on every posted place and that
 *     the line is visible to the account. The READ helpers take $venueIds.
 */

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/inventory-item-import.php';
require_once __DIR__ . '/inventory-shipment-import.php';   // inv_ship_key() / inv_ship_packing_code()

const INV_ORDER_STATUSES = ['open' => 'On order', 'partial' => 'Part received', 'received' => 'Received', 'cancelled' => 'Cancelled'];

/** Order status from its lines' ordered / received quantities — PURE. */
function inv_order_status(array $lines): string {
    if (!$lines) return 'open';
    $any = false; $all = true;
    foreach ($lines as $l) {
        $ord = (int)($l['qty_ordered'] ?? 0); $rec = (int)($l['qty_received'] ?? 0);
        if ($rec > 0) $any = true;
        if ($rec < $ord) $all = false;
    }
    return $all ? 'received' : ($any ? 'partial' : 'open');
}

// ── Writes ──────────────────────────────────────────────────────────────────

/**
 * Create an order from a parsed list — one inv_tx(). $lines: the parsed list lines;
 * $lineItem: [line index => item id]; $linePlace: [line index => planned place id
 * (0 / absent = none)]. Lines that map to the same item AND place become ONE order
 * line of the summed quantity (description / code / section from the first). Lines
 * with no item, or no quantity, are skipped. Returns the order id; an order with no
 * lines is refused.
 */
function inv_order_create(string $name, array $lines, array $lineItem, array $linePlace, string $filename, string $fingerprint, ?int $userId): int {
    if (!inv_orders_supported()) throw new InvRefusal('Orders are not set up yet — run the add_inventory_orders.sql migration.');
    $name = mb_substr(trim($name), 0, 160);
    if ($name === '') $name = 'Order';
    $groups = [];
    foreach ($lines as $i => $l) {
        $itemId = (int)($lineItem[$i] ?? 0);
        $qty    = (int)($l['qty'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) continue;
        $placeId = (int)($linePlace[$i] ?? 0);
        $key = $itemId . ':' . $placeId;
        if (!isset($groups[$key])) {
            $groups[$key] = ['item_id' => $itemId, 'place_id' => $placeId, 'qty' => 0,
                             'code' => mb_substr(trim((string)($l['code'] ?? '')), 0, 40),
                             'description' => trim((string)($l['description'] ?? '')),
                             'section' => mb_substr(trim((string)($l['section'] ?? '')), 0, 120)];
        }
        $groups[$key]['qty'] += $qty;
    }
    if (!$groups) throw new InvRefusal('There is nothing to order — none of the list lines has an item and a quantity.');
    return inv_tx(function () use ($name, $groups, $filename, $fingerprint, $userId): int {
        db_query('INSERT INTO inv_orders (name, source_filename, fingerprint, created_by) VALUES (:n, :f, :fp, :u)', [
            ':n' => $name, ':f' => $filename !== '' ? mb_substr($filename, 0, 200) : null,
            ':fp' => $fingerprint !== '' ? mb_substr($fingerprint, 0, 40) : null, ':u' => $userId]);
        $orderId = (int) db()->lastInsertId();
        $sort = 0;
        foreach ($groups as $g) {
            db_query('INSERT INTO inv_order_lines (order_id, sort_order, item_id, code, description, section, qty_ordered, planned_location_id)
                      VALUES (:o, :s, :i, :c, :d, :sec, :q, :pl)', [
                ':o' => $orderId, ':s' => $sort++, ':i' => $g['item_id'], ':c' => $g['code'] !== '' ? $g['code'] : null,
                ':d' => $g['description'] !== '' ? $g['description'] : '—', ':sec' => $g['section'] !== '' ? $g['section'] : null,
                ':q' => $g['qty'], ':pl' => $g['place_id'] > 0 ? $g['place_id'] : null]);
        }
        return $orderId;
    });
}

/**
 * Record what arrived — $receipts = [order line id => ['qty' => int, 'location_id' => int]].
 * One inv_tx(): locks the order, then its lines in id order; refuses a cancelled
 * order, a line of another order, a quantity outside 1..(ordered − received) and a
 * place that is missing or closed. Counted items get one 'receive' move into the
 * place; serial items get one unit (serial blank, condition new) per piece. Then the
 * order becomes received / partial / open. Does NO scoping — the page checks.
 * Returns ['lines' => n, 'pieces' => n].
 */
function inv_order_receive(int $orderId, array $receipts, ?int $userId): array {
    if (!inv_orders_supported()) throw new InvRefusal('Orders are not set up yet — run the add_inventory_orders.sql migration.');
    return inv_tx(function () use ($orderId, $receipts, $userId): array {
        $order = db_query('SELECT id, name, status FROM inv_orders WHERE id = :id FOR UPDATE', [':id' => $orderId])->fetch();
        if (!$order) throw new InvRefusal('That order no longer exists.');
        if ($order['status'] === 'cancelled') throw new InvRefusal('This order was cancelled.');
        $lines = [];
        foreach (db_query('SELECT l.id, l.item_id, l.qty_ordered, l.qty_received, i.name AS item_name, i.tracking
                             FROM inv_order_lines l JOIN inv_items i ON i.id = l.item_id
                            WHERE l.order_id = :o ORDER BY l.id FOR UPDATE OF l', [':o' => $orderId])->fetchAll() as $r) {
            $lines[(int)$r['id']] = $r;
        }
        // Validate everything before the first write.
        $todo = [];
        ksort($receipts);
        foreach ($receipts as $lineId => $rc) {
            $lineId = (int)$lineId;
            $l = $lines[$lineId] ?? null;
            if (!$l) throw new InvRefusal('That line is not part of this order.');
            $qty = (int)($rc['qty'] ?? 0);
            $left = (int)$l['qty_ordered'] - (int)$l['qty_received'];
            if ($qty < 1) throw new InvRefusal("{$l['item_name']}: enter how many arrived.");
            if ($qty > $left) throw new InvRefusal("{$l['item_name']}: only {$left} still to come.");
            $loc = inv_fetch_location((int)($rc['location_id'] ?? 0));
            if (!$loc || !inv_bool($loc['is_active'])) throw new InvRefusal("{$l['item_name']}: pick an open place to put it.");
            $todo[$lineId] = ['line' => $l, 'qty' => $qty, 'to' => (int)$loc['id']];
        }
        // Pre-lock every balance pair in the global order (serial items lock inside inv_move()).
        $pairs = [];
        foreach ($todo as $t) $pairs[] = [(int)$t['line']['item_id'], $t['to']];
        inv_lock_balances($pairs);

        $note = mb_substr("Order #{$orderId} {$order['name']}", 0, 200);
        $pieces = 0;
        foreach ($todo as $lineId => $t) {
            $itemId = (int)$t['line']['item_id'];
            if ($t['line']['tracking'] === 'serial') {
                for ($n = 0; $n < $t['qty']; $n++) inv_asset_create($itemId, $t['to'], ['serial' => '', 'condition' => 'new', 'notes' => $note], $userId);
            } else {
                inv_move(['item_id' => $itemId, 'qty' => $t['qty'], 'to' => $t['to'], 'reason' => 'receive',
                          'unit_value' => null, 'user_id' => $userId, 'note' => $note]);
            }
            db_query('INSERT INTO inv_order_receipts (line_id, qty, location_id, admin_user_id) VALUES (:l, :q, :loc, :u)',
                [':l' => $lineId, ':q' => $t['qty'], ':loc' => $t['to'], ':u' => $userId]);
            db_query('UPDATE inv_order_lines SET qty_received = qty_received + :q WHERE id = :l', [':q' => $t['qty'], ':l' => $lineId]);
            $pieces += $t['qty'];
        }
        $fresh = db_query('SELECT qty_ordered, qty_received FROM inv_order_lines WHERE order_id = :o', [':o' => $orderId])->fetchAll();
        db_query('UPDATE inv_orders SET status = :s WHERE id = :id', [':s' => inv_order_status($fresh), ':id' => $orderId]);
        return ['lines' => count($todo), 'pieces' => $pieces];
    });
}

/**
 * Attach the packing lists' container hints to an order's lines. $packing is
 * inv_ship_parse_packing()'s output. Each packing line's code is mapped onto the
 * order's line codes (inv_ship_packing_code(): exact, or a bundle suffix removed);
 * when several order lines share the code the one whose description matches wins
 * (same key, else one contains the other, else the first). Quantities are summed
 * per (line, container) and upserted. HINTS ONLY — nothing here touches
 * qty_ordered. Returns ['matched' => packing lines placed, 'unmatched' => packing
 * lines with no order line (rails, brackets, …)]. No-op before the migration.
 */
function inv_order_attach_containers(int $orderId, array $packing): array {
    $res = ['matched' => 0, 'unmatched' => 0];
    if (!$packing || !inv_order_containers_supported()) return $res;
    $byCode = []; $codes = [];   // UPPER code => [[line id, description key]]
    foreach (db_query('SELECT id, code, description FROM inv_order_lines WHERE order_id = :o ORDER BY sort_order, id', [':o' => $orderId])->fetchAll() as $l) {
        $c = trim((string)($l['code'] ?? ''));
        if ($c === '') continue;
        $codes[mb_strtoupper($c)] = $c;
        $byCode[mb_strtoupper($c)][] = [(int)$l['id'], inv_ship_key((string)$l['description'])];
    }
    $sum = []; $seq = [];   // "line|container" => qty; container => sheet position
    foreach (array_values($packing) as $idx => $sheet) {
        $container = mb_substr(trim((string)($sheet['container'] ?? '')), 0, 80);
        if ($container === '') continue;
        $seq[$container] ??= $idx;
        foreach ((array)($sheet['lines'] ?? []) as $pl) {
            $qty  = (int)($pl['qty'] ?? 0);
            $code = $qty > 0 ? inv_ship_packing_code((string)($pl['code'] ?? ''), array_values($codes)) : null;
            if ($code === null) { $res['unmatched']++; continue; }
            $cands = $byCode[mb_strtoupper($code)];
            $pick = null;
            if (count($cands) === 1) $pick = $cands[0][0];
            else {
                $pk = inv_ship_key((string)($pl['description'] ?? ''));
                foreach ($cands as [$id, $k]) if ($pk !== '' && $k === $pk) { $pick = $id; break; }
                if ($pick === null && $pk !== '') foreach ($cands as [$id, $k]) if ($k !== '' && (str_contains($k, $pk) || str_contains($pk, $k))) { $pick = $id; break; }
                $pick ??= $cands[0][0];
            }
            $sum[$pick . '|' . $container] = ($sum[$pick . '|' . $container] ?? 0) + $qty;
            $res['matched']++;
        }
    }
    foreach ($sum as $key => $qty) {
        [$lineId, $container] = explode('|', $key, 2);
        db_query('INSERT INTO inv_order_line_containers (line_id, container, qty, seq) VALUES (:l, :c, :q, :s)
                  ON CONFLICT (line_id, container) DO UPDATE SET qty = EXCLUDED.qty, seq = EXCLUDED.seq',
            [':l' => (int)$lineId, ':c' => $container, ':q' => $qty, ':s' => $seq[$container] ?? 0]);
    }
    return $res;
}

/**
 * Receive what the packing list says is in one container — a SUGGESTION, not the
 * truth: for every line the account can see that has a quantity in this container
 * and still something to come, receive min(container qty, still to come) into the
 * line's planned place, in one inv_order_receive() call. A line with no planned
 * place, or one the account may not move into, is skipped (counted in 'skipped').
 * Returns ['lines' => n, 'pieces' => n, 'skipped' => n].
 */
function inv_order_receive_container(int $orderId, string $container, ?array $venueIds, ?int $userId): array {
    if (!inv_order_containers_supported()) throw new InvRefusal('Containers are not set up yet — run the add_inventory_orders.sql migration.');
    $order = inv_order_fetch($orderId);
    if (!$order) throw new InvRefusal('That order no longer exists.');
    if ($order['status'] === 'cancelled') throw new InvRefusal('This order was cancelled.');
    $receipts = []; $skipped = 0;
    foreach (inv_order_lines($orderId, $venueIds) as $l) {
        $cq = (int)($l['containers'][$container] ?? 0);
        $left = (int)$l['still_to_come'];
        if ($cq < 1 || $left < 1) continue;
        $loc = (int)($l['planned_location_id'] ?? 0) > 0 ? inv_fetch_location((int)$l['planned_location_id']) : false;
        if (!$loc || !inv_bool($loc['is_active']) || $loc['kind'] === 'person' || !inv_move_in_scope(null, $loc, $venueIds)) { $skipped++; continue; }
        $receipts[(int)$l['id']] = ['qty' => min($cq, $left), 'location_id' => (int)$loc['id']];
    }
    if (!$receipts) return ['lines' => 0, 'pieces' => 0, 'skipped' => $skipped];
    $r = inv_order_receive($orderId, $receipts, $userId);
    return ['lines' => $r['lines'], 'pieces' => $r['pieces'], 'skipped' => $skipped];
}

/** Cancel an order — only while nothing has been received. */
function inv_order_cancel(int $orderId): void {
    if (!inv_orders_supported()) throw new InvRefusal('Orders are not set up yet — run the add_inventory_orders.sql migration.');
    inv_tx(function () use ($orderId): void {
        $order = db_query('SELECT id, status FROM inv_orders WHERE id = :id FOR UPDATE', [':id' => $orderId])->fetch();
        if (!$order) throw new InvRefusal('That order no longer exists.');
        if ($order['status'] === 'cancelled') return;
        $got = (int) db_query('SELECT COUNT(*) FROM inv_order_lines l WHERE l.order_id = :o AND (l.qty_received > 0
                                  OR EXISTS (SELECT 1 FROM inv_order_receipts r WHERE r.line_id = l.id))', [':o' => $orderId])->fetchColumn();
        if ($order['status'] !== 'open' || $got > 0) throw new InvRefusal('Something was already received — it can’t be cancelled.');
        db_query("UPDATE inv_orders SET status = 'cancelled' WHERE id = :id", [':id' => $orderId]);
    });
}

// ── Reads ───────────────────────────────────────────────────────────────────

/** A non-cancelled order already created from this list, or null. */
function inv_order_open_for(string $fingerprint): ?array {
    if (!inv_orders_supported() || $fingerprint === '') return null;
    $r = db_query("SELECT id, name, created_at FROM inv_orders WHERE fingerprint = :fp AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", [':fp' => $fingerprint])->fetch();
    return $r ?: null;
}

/**
 * Orders for the list page — non-cancelled first, newest first. Each row carries
 * lines, pieces_ordered, pieces_received (counting only the lines this account
 * may see). A scoped account sees an order only when at least one of its lines
 * has a visible planned place or none at all; $venueIds null = everything.
 */
function inv_orders_list(?array $venueIds): array {
    if (!inv_orders_supported()) return [];
    $p = [];
    $vis = inv_visible_sql('pl', $venueIds, $p, 'ov');
    return db_query("SELECT o.id, o.name, o.status, o.created_at, o.source_filename, COUNT(l.id) AS lines,
                            COALESCE(SUM(l.qty_ordered), 0) AS pieces_ordered, COALESCE(SUM(l.qty_received), 0) AS pieces_received
                       FROM inv_orders o
                       JOIN inv_order_lines l ON l.order_id = o.id
                       LEFT JOIN inv_locations pl ON pl.id = l.planned_location_id
                      WHERE (l.planned_location_id IS NULL OR {$vis})
                      GROUP BY o.id
                      ORDER BY (o.status = 'cancelled'), o.created_at DESC, o.id DESC", $p)->fetchAll();
}

function inv_order_fetch(int $id): array|false {
    if (!inv_orders_supported() || $id <= 0) return false;
    return db_query('SELECT o.*, a.name AS created_by_name FROM inv_orders o LEFT JOIN admin_users a ON a.id = o.created_by WHERE o.id = :id', [':id' => $id])->fetch();
}

/**
 * The lines of an order the account may see, in sheet order, with the item, the
 * planned place label, still_to_come and the receipts so far. Scoped like the list.
 */
function inv_order_lines(int $orderId, ?array $venueIds): array {
    if (!inv_orders_supported() || $orderId <= 0) return [];
    $p = [':o' => $orderId];
    $vis = inv_visible_sql('pl', $venueIds, $p, 'ov');
    $rows = db_query("SELECT l.*, i.name AS item_name, i.sku, i.tracking, i.unit_label, i.image_key, i.icon,
                             pl.kind AS place_kind, pl.name AS place_name, pp.name AS place_parent_name
                        FROM inv_order_lines l
                        JOIN inv_items i ON i.id = l.item_id
                        LEFT JOIN inv_locations pl ON pl.id = l.planned_location_id
                        LEFT JOIN inv_locations pp ON pp.id = pl.parent_id
                       WHERE l.order_id = :o AND (l.planned_location_id IS NULL OR {$vis})
                       ORDER BY l.sort_order, l.id", $p)->fetchAll();
    if (!$rows) return [];
    $byLine = [];
    foreach (db_query("SELECT r.line_id, r.qty, r.created_at, loc.name AS location_name, a.name AS user_name
                         FROM inv_order_receipts r
                         JOIN inv_order_lines l ON l.id = r.line_id
                         JOIN inv_locations loc ON loc.id = r.location_id
                         LEFT JOIN admin_users a ON a.id = r.admin_user_id
                        WHERE l.order_id = :o ORDER BY r.created_at, r.id", [':o' => $orderId])->fetchAll() as $r) {
        $byLine[(int)$r['line_id']][] = ['qty' => (int)$r['qty'], 'location_name' => (string)$r['location_name'],
                                          'created_at' => (string)$r['created_at'], 'user_name' => $r['user_name']];
    }
    $conts = [];
    if (inv_order_containers_supported()) {
        foreach (db_query('SELECT c.line_id, c.container, c.qty FROM inv_order_line_containers c
                             JOIN inv_order_lines l ON l.id = c.line_id
                            WHERE l.order_id = :o ORDER BY c.seq, c.container', [':o' => $orderId])->fetchAll() as $c) {
            $conts[(int)$c['line_id']][(string)$c['container']] = (int)$c['qty'];
        }
    }
    foreach ($rows as &$r) {
        $r['containers']    = $conts[(int)$r['id']] ?? [];
        $r['place_label']   = $r['planned_location_id'] !== null
            ? inv_location_label(['kind' => $r['place_kind'], 'name' => $r['place_name'], 'parent_name' => $r['place_parent_name']]) : '';
        $r['still_to_come'] = max(0, (int)$r['qty_ordered'] - (int)$r['qty_received']);
        $r['receipts']      = $byLine[(int)$r['id']] ?? [];
    }
    unset($r);
    return $rows;
}

/**
 * The containers of an order in packing-list order: [['container','lines','pieces']],
 * counting only the lines this account may see. Empty without packing lists.
 */
function inv_order_containers(int $orderId, ?array $venueIds = null): array {
    if (!inv_order_containers_supported() || $orderId <= 0) return [];
    $seq = [];
    foreach (db_query('SELECT c.container, MIN(c.seq) AS s FROM inv_order_line_containers c JOIN inv_order_lines l ON l.id = c.line_id
                        WHERE l.order_id = :o GROUP BY c.container', [':o' => $orderId])->fetchAll() as $r) $seq[(string)$r['container']] = (int)$r['s'];
    $out = [];
    foreach (inv_order_lines($orderId, $venueIds) as $l) {
        foreach ($l['containers'] as $c => $q) {
            $out[$c] ??= ['container' => (string)$c, 'lines' => 0, 'pieces' => 0];
            $out[$c]['lines']++; $out[$c]['pieces'] += (int)$q;
        }
    }
    uksort($out, fn($a, $b) => [$seq[$a] ?? 0, (string)$a] <=> [$seq[$b] ?? 0, (string)$b]);
    return array_values($out);
}

/** [item id => pieces still to come] across open / part-received orders. */
function inv_on_order_by_item(): array {
    if (!inv_orders_supported()) return [];
    $out = [];
    foreach (db_query("SELECT l.item_id, SUM(l.qty_ordered - l.qty_received) AS n
                         FROM inv_order_lines l JOIN inv_orders o ON o.id = l.order_id
                        WHERE o.status IN ('open','partial')
                        GROUP BY l.item_id HAVING SUM(l.qty_ordered - l.qty_received) > 0")->fetchAll() as $r) {
        $out[(int)$r['item_id']] = (int)$r['n'];
    }
    return $out;
}
