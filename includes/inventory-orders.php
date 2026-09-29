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
require_once __DIR__ . '/inventory-owner.php';   // inv_undo_move() — undoing a receipt undoes its movement
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
                             'section' => mb_substr(trim((string)($l['section'] ?? '')), 0, 120), 'hs' => ''];
        }
        $groups[$key]['qty'] += $qty;
        // The customs code: the first non-empty one among the merged master lines.
        if ($groups[$key]['hs'] === '') $groups[$key]['hs'] = mb_substr(trim((string)($l['hs_code'] ?? '')), 0, 20);
    }
    if (!$groups) throw new InvRefusal('There is nothing to order — none of the list lines has an item and a quantity.');
    return inv_tx(function () use ($name, $groups, $filename, $fingerprint, $userId): int {
        db_query('INSERT INTO inv_orders (name, source_filename, fingerprint, created_by) VALUES (:n, :f, :fp, :u)', [
            ':n' => $name, ':f' => $filename !== '' ? mb_substr($filename, 0, 200) : null,
            ':fp' => $fingerprint !== '' ? mb_substr($fingerprint, 0, 40) : null, ':u' => $userId]);
        $orderId = (int) db()->lastInsertId();
        $sort = 0; $withHs = inv_order_packing_supported();   // hs_code arrives with the packing-list part of the migration
        foreach ($groups as $g) {
            $params = [
                ':o' => $orderId, ':s' => $sort++, ':i' => $g['item_id'], ':c' => $g['code'] !== '' ? $g['code'] : null,
                ':d' => $g['description'] !== '' ? $g['description'] : '—', ':sec' => $g['section'] !== '' ? $g['section'] : null,
                ':q' => $g['qty'], ':pl' => $g['place_id'] > 0 ? $g['place_id'] : null];
            if ($withHs) {
                $params[':hs'] = $g['hs'] !== '' ? $g['hs'] : null;
                db_query('INSERT INTO inv_order_lines (order_id, sort_order, item_id, code, description, section, qty_ordered, planned_location_id, hs_code)
                          VALUES (:o, :s, :i, :c, :d, :sec, :q, :pl, :hs)', $params);
            } else {
                db_query('INSERT INTO inv_order_lines (order_id, sort_order, item_id, code, description, section, qty_ordered, planned_location_id)
                          VALUES (:o, :s, :i, :c, :d, :sec, :q, :pl)', $params);
            }
        }
        return $orderId;
    });
}

/**
 * Import a parsed supplier list the way the Import page's confirm does — the ONE
 * implementation (the page and db/seeds/seed_maya_ilai_shipment.php both call it).
 * One inv_tx(): items (inv_import_items), par levels per item-code prefix, then —
 * once per list, when orders are set up and inv_order_open_for() finds none — the
 * ORDER with each line's planned place, its HS code, the packing lists' container hints
 * and every packing-list row (inv_order_packing).
 * $parsed: inv_ship_parse_workbook() output (needs 'lines'); $packing:
 * inv_ship_parse_packing(); $prefixPlace: [prefix => place id]. No stock moves.
 * Returns ['created','existing','pars','order_id' (0 = none made),'order_name',
 * 'pieces' (on the order),'containers' (['matched','unmatched'] or null)].
 */
function inv_import_list(array $parsed, array $packing, array $prefixPlace, string $filename, ?int $userId): array {
    $lines = (array)($parsed['lines'] ?? []);
    return inv_tx(function () use ($lines, $packing, $prefixPlace, $filename, $userId): array {
        $groups = inv_ship_group($lines);
        $res    = inv_import_items($lines, $groups);
        $lineItem = [];
        foreach ($groups as $gkey => $g) {
            $itemId = $res['group_items'][$gkey] ?? null;
            if (!$itemId) continue;
            foreach ($g['lines'] as $i) $lineItem[$i] = $itemId;
        }
        $pars = inv_import_apply_pars(inv_import_par_plan($lines, $lineItem, $prefixPlace));
        // The order: quantities go ON ORDER, no stock moves. One per list — checked here, not trusted from the page.
        $orderId = 0; $orderName = ''; $pieces = 0; $containers = null;
        $fp = inv_import_list_fingerprint($lines);
        if (inv_orders_supported() && inv_order_open_for($fp) === null) {
            $linePlace = [];
            foreach ($lines as $i => $l) $linePlace[$i] = (int)($prefixPlace[inv_ship_prefix((string)($l['code'] ?? ''))] ?? 0);
            $orderName = (string)pathinfo($filename, PATHINFO_FILENAME);
            $orderId   = inv_order_create($orderName, $lines, $lineItem, $linePlace, $filename, $fp, $userId);
            $pieces    = (int) db_query('SELECT COALESCE(SUM(qty_ordered), 0) FROM inv_order_lines WHERE order_id = :o', [':o' => $orderId])->fetchColumn();
            // Packing-list hints (which container each line is in) — never change the quantities.
            if ($packing) $containers = inv_order_attach_containers($orderId, $packing);
        }
        return ['created' => $res['created'], 'existing' => $res['existing'], 'pars' => $pars, 'order_id' => $orderId,
                'order_name' => $orderName, 'pieces' => $pieces, 'containers' => $containers];
    });
}

/** How a line's packing-list total compares with what was ordered — PURE.
 *  null = nothing to flag (or the order has no packing lists); 'not_packed' = on no
 *  packing list; 'less_packed' / 'more_packed' = the lists total fewer / more. */
function inv_order_pack_diff(int $ordered, int $packed, bool $hasContainerData): ?string {
    if (!$hasContainerData) return null;
    if ($packed <= 0) return 'not_packed';
    if ($packed < $ordered) return 'less_packed';
    return $packed > $ordered ? 'more_packed' : null;
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
        $linkMoves = inv_order_receipt_moves_supported();
        foreach ($todo as $lineId => $t) {
            $itemId = (int)$t['line']['item_id'];
            $moveId = null;
            if ($t['line']['tracking'] === 'serial') {
                for ($n = 0; $n < $t['qty']; $n++) inv_asset_create($itemId, $t['to'], ['serial' => '', 'condition' => 'new', 'notes' => $note], $userId);
            } else {
                $moveId = inv_move(['item_id' => $itemId, 'qty' => $t['qty'], 'to' => $t['to'], 'reason' => 'receive',
                                    'unit_value' => null, 'user_id' => $userId, 'note' => $note]);
            }
            // The receipt remembers its move (counted items) so an undo of either keeps the order
            // in step. Serial receipts create one move per unit — move_id stays NULL.
            if ($moveId !== null && $linkMoves) {
                db_query('INSERT INTO inv_order_receipts (line_id, qty, location_id, admin_user_id, move_id) VALUES (:l, :q, :loc, :u, :mv)',
                    [':l' => $lineId, ':q' => $t['qty'], ':loc' => $t['to'], ':u' => $userId, ':mv' => $moveId]);
            } else {
                db_query('INSERT INTO inv_order_receipts (line_id, qty, location_id, admin_user_id) VALUES (:l, :q, :loc, :u)',
                    [':l' => $lineId, ':q' => $t['qty'], ':loc' => $t['to'], ':u' => $userId]);
            }
            db_query('UPDATE inv_order_lines SET qty_received = qty_received + :q WHERE id = :l', [':q' => $t['qty'], ':l' => $lineId]);
            $pieces += $t['qty'];
        }
        $fresh = db_query('SELECT qty_ordered, qty_received FROM inv_order_lines WHERE order_id = :o', [':o' => $orderId])->fetchAll();
        db_query('UPDATE inv_orders SET status = :s WHERE id = :id', [':s' => inv_order_status($fresh), ':id' => $orderId]);
        return ['lines' => count($todo), 'pieces' => $pieces];
    });
}

/**
 * Attach the packing lists to an order's lines. $packing is inv_ship_parse_packing()'s
 * output. Two things come out of it:
 *   1. Container HINTS (inv_order_line_containers): each 'row' line's code is mapped
 *      onto the order's line codes (inv_ship_packing_code(): exact, or a bundle suffix
 *      removed); when several order lines share the code the one whose description is
 *      most alike wins (inv_ship_desc_score() ≥ 0.6; a tie needs an exact description) —
 *      never a guess: no clear match counts as unmatched. Quantities are summed per
 *      (line, container) and upserted. HINTS ONLY — nothing here touches qty_ordered.
 *   2. The FULL packing lists (inv_order_packing): every parsed row, in sheet order,
 *      with its boxes / dimensions / weight / cubes, linked to the order line it was
 *      matched to (continuation rows inherit their parent row's line; unmatched rows,
 *      notes and totals keep line_id NULL). Re-attaching replaces the order's rows.
 * Returns ['matched' => packing lines placed, 'unmatched' => packing lines with a code
 * and quantity but no order line (rails, brackets, …)]. Each half is a no-op before
 * its part of the migration.
 */
function inv_order_attach_containers(int $orderId, array $packing): array {
    $res = ['matched' => 0, 'unmatched' => 0];
    $hints = inv_order_containers_supported(); $store = inv_order_packing_supported();
    if (!$packing || (!$hints && !$store)) return $res;
    $byCode = []; $codes = [];   // UPPER code => [[line id, description]]
    foreach (db_query('SELECT id, code, description FROM inv_order_lines WHERE order_id = :o ORDER BY sort_order, id', [':o' => $orderId])->fetchAll() as $l) {
        $c = trim((string)($l['code'] ?? ''));
        if ($c === '') continue;
        $codes[mb_strtoupper($c)] = $c;
        $byCode[mb_strtoupper($c)][] = [(int)$l['id'], (string)$l['description']];
    }
    /** The order line one packing row stands for, or null. */
    $match = function (array $pl) use ($byCode, $codes): ?int {
        $code = inv_ship_packing_code((string)($pl['code'] ?? ''), array_values($codes));
        if ($code === null) return null;
        $cands = $byCode[mb_strtoupper($code)];
        if (count($cands) === 1) return $cands[0][0];   // one line under the code: all its pieces land here
        // Several lines share the code: the best-described one, and only when it is clearly the same thing.
        // A tie for best (e.g. "Pot Stand" sits inside two "… pot with stand" lines) is settled by an exact
        // description, else left unmatched — a packing line is never dropped on a guess.
        $pd = (string)($pl['description'] ?? ''); $pk = inv_ship_key($pd);
        $best = 0.0; $tied = [];
        foreach ($cands as [$id, $desc]) {
            $sc = inv_ship_desc_score($pd, $desc);
            if ($sc > $best + 1e-9) { $best = $sc; $tied = [[$id, $desc]]; }
            elseif ($sc > 0 && abs($sc - $best) <= 1e-9) $tied[] = [$id, $desc];
        }
        if ($best < 0.6) return null;
        if (count($tied) === 1) return $tied[0][0];
        foreach ($tied as [$id, $desc]) if ($pk !== '' && inv_ship_key($desc) === $pk) return $id;
        return null;
    };
    $sum = []; $seq = []; $rows = []; $rowNo = [];   // "line|container" => qty; container => sheet position; rows to store; container => running seq
    foreach (array_values($packing) as $idx => $sheet) {
        $container = mb_substr(trim((string)($sheet['container'] ?? '')), 0, 80);
        if ($container === '') continue;
        $seq[$container] ??= $idx;
        $parentLine = null;
        foreach ((array)($sheet['lines'] ?? []) as $pl) {
            $kind = (string)($pl['kind'] ?? 'row');
            $pick = null;
            if ($kind === 'row') {
                $qty = (int)($pl['qty'] ?? 0);
                if ($qty > 0) {
                    $pick = $match($pl);
                    if ($pick === null) $res['unmatched']++;
                    else { $sum[$pick . '|' . $container] = ($sum[$pick . '|' . $container] ?? 0) + $qty; $res['matched']++; }
                }
                $parentLine = $pick;
            }
            $lineId = $kind === 'continuation' ? $parentLine : $pick;
            $rows[] = ['container' => $container, 'seq' => $rowNo[$container] = ($rowNo[$container] ?? 0) + 1, 'pl' => $pl, 'kind' => $kind, 'line_id' => $lineId];
        }
    }
    if ($hints) {
        foreach ($sum as $key => $qty) {
            [$lineId, $container] = explode('|', $key, 2);
            db_query('INSERT INTO inv_order_line_containers (line_id, container, qty, seq) VALUES (:l, :c, :q, :s)
                      ON CONFLICT (line_id, container) DO UPDATE SET qty = EXCLUDED.qty, seq = EXCLUDED.seq',
                [':l' => (int)$lineId, ':c' => $container, ':q' => $qty, ':s' => $seq[$container] ?? 0]);
        }
    }
    if ($store) {
        db_query('DELETE FROM inv_order_packing WHERE order_id = :o', [':o' => $orderId]);
        $num = fn($v, int $d) => $v === null || $v === '' ? null : number_format((float)$v, $d, '.', '');
        foreach ($rows as $r) {
            $pl = $r['pl'];
            db_query('INSERT INTO inv_order_packing (order_id, container, seq, sheet, row_no, code, description, qty, boxes, length_m, width_m, height_m, weight_kg, cubes_m3, kind, line_id)
                      VALUES (:o, :c, :s, :sh, :rn, :code, :d, :q, :b, :l, :w, :h, :kg, :cu, :k, :li)', [
                ':o' => $orderId, ':c' => $r['container'], ':s' => $r['seq'], ':sh' => isset($pl['sheet']) ? mb_substr((string)$pl['sheet'], 0, 80) : null,
                ':rn' => isset($pl['row']) ? (int)$pl['row'] : null,
                ':code' => ($pl['code'] ?? '') !== '' ? mb_substr((string)$pl['code'], 0, 40) : null,
                ':d' => ($pl['description'] ?? '') !== '' ? (string)$pl['description'] : null,
                ':q' => isset($pl['qty']) ? (int)$pl['qty'] : null, ':b' => isset($pl['boxes']) ? (int)$pl['boxes'] : null,
                ':l' => $num($pl['length'] ?? null, 3), ':w' => $num($pl['width'] ?? null, 3), ':h' => $num($pl['height'] ?? null, 3),
                ':kg' => $num($pl['weight'] ?? null, 2), ':cu' => $num($pl['cubes'] ?? null, 4),
                ':k' => $r['kind'], ':li' => $r['line_id']]);
        }
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

/** The note inv_order_receive() puts on its receive moves is "Order #<id> <name>"; this is the id in it, or 0. PURE. */
function inv_order_id_from_note(?string $note): int {
    return ($note !== null && preg_match('/^Order #(\d+) /', $note, $m)) ? (int)$m[1] : 0;
}

/**
 * The receipt a 'receive' move belongs to, or null. $move carries the raw inv_moves columns
 * (id, item_id, qty, to_location_id, reason, note, created_at). Once the receipt→move link
 * exists (inv_order_receipt_moves_supported()) it is looked up by move_id; a receipt written
 * before the link (move_id NULL, or the column not there yet) is matched instead by the
 * same item, the same place, the same quantity and the order id in the move's note, nearest
 * in time, and — where the link exists — not already tied to another move. Never a guess
 * beyond that: anything that is not an order receive returns null.
 */
function inv_order_find_receipt_for_move(array $move): ?array {
    if (!inv_orders_supported()) return null;
    if (($move['reason'] ?? '') !== 'receive') return null;
    $orderId = inv_order_id_from_note(isset($move['note']) ? (string)$move['note'] : null);
    if ($orderId <= 0) return null;
    $linked = inv_order_receipt_moves_supported();
    if ($linked && !empty($move['id'])) {
        $r = db_query('SELECT * FROM inv_order_receipts WHERE move_id = :m', [':m' => (int)$move['id']])->fetch();
        if ($r) return $r;
    }
    $to = $move['to_location_id'] ?? null;
    if ($to === null) return null;
    $r = db_query('SELECT r.* FROM inv_order_receipts r
                     JOIN inv_order_lines l ON l.id = r.line_id
                    WHERE l.order_id = :o AND l.item_id = :i AND r.location_id = :loc AND r.qty = :q'
                  . ($linked ? ' AND r.move_id IS NULL' : '') . '
                    ORDER BY ABS(EXTRACT(EPOCH FROM (r.created_at - CAST(:at AS timestamptz)))), r.id LIMIT 1',
        [':o' => $orderId, ':i' => (int)$move['item_id'], ':loc' => (int)$to, ':q' => (int)$move['qty'],
         ':at' => (string)($move['created_at'] ?? date('c'))])->fetch();
    return $r ?: null;
}

/**
 * The 'receive' move a receipt created, or null when it no longer exists (already undone on
 * the item page) — the mirror of inv_order_find_receipt_for_move(). By move_id when set;
 * otherwise the legacy match on item + place + qty + the "Order #<id> " note, nearest in time,
 * skipping moves another receipt already points to.
 */
function inv_order_find_move_for_receipt(array $receipt): ?array {
    if (!inv_supported()) return null;
    if (!empty($receipt['move_id'])) {
        $m = db_query('SELECT * FROM inv_moves WHERE id = :id', [':id' => (int)$receipt['move_id']])->fetch();
        if ($m) return $m;
    }
    $line = db_query('SELECT order_id, item_id FROM inv_order_lines WHERE id = :l', [':l' => (int)$receipt['line_id']])->fetch();
    if (!$line) return null;
    $prefix = 'Order #' . (int)$line['order_id'] . ' ';
    $linked = inv_order_receipt_moves_supported();
    $m = db_query("SELECT m.* FROM inv_moves m
                    WHERE m.reason = 'receive' AND m.asset_id IS NULL AND m.item_id = :i AND m.to_location_id = :loc AND m.qty = :q
                      AND strpos(COALESCE(m.note, ''), :p) = 1"
                  . ($linked ? ' AND NOT EXISTS (SELECT 1 FROM inv_order_receipts x WHERE x.move_id = m.id)' : '') . '
                    ORDER BY ABS(EXTRACT(EPOCH FROM (m.created_at - CAST(:at AS timestamptz)))), m.id LIMIT 1',
        [':i' => (int)$line['item_id'], ':loc' => (int)$receipt['location_id'], ':q' => (int)$receipt['qty'],
         ':p' => $prefix, ':at' => (string)$receipt['created_at']])->fetch();
    return $m ?: null;
}

/**
 * Take a receipt back off its order — the receipt row goes, the line's qty_received drops by
 * it (never below 0) and the order status is recomputed from its lines (a cancelled order
 * stays cancelled). Touches NO stock: the caller has already reversed (or never made) the
 * move. Locks the order then the line — the same order inv_order_receive() uses. A receipt
 * that is already gone is a no-op, so two racing undos cannot subtract twice.
 */
function inv_order_forget_receipt(int $receiptId): void {
    if (!inv_orders_supported()) return;
    inv_tx(function () use ($receiptId): void {
        $r = db_query('SELECT r.id, r.line_id, r.qty, l.order_id FROM inv_order_receipts r
                         JOIN inv_order_lines l ON l.id = r.line_id WHERE r.id = :id', [':id' => $receiptId])->fetch();
        if (!$r) return;
        $order = db_query('SELECT id, status FROM inv_orders WHERE id = :id FOR UPDATE', [':id' => (int)$r['order_id']])->fetch();
        db_query('SELECT id FROM inv_order_lines WHERE id = :id FOR UPDATE', [':id' => (int)$r['line_id']])->fetch();
        $gone = db_query('DELETE FROM inv_order_receipts WHERE id = :id RETURNING qty', [':id' => $receiptId])->fetch();
        if (!$gone) return;   // a racing undo removed it first
        db_query('UPDATE inv_order_lines SET qty_received = GREATEST(0, qty_received - :q) WHERE id = :l',
            [':q' => (int)$gone['qty'], ':l' => (int)$r['line_id']]);
        if ($order && $order['status'] !== 'cancelled') {
            $fresh = db_query('SELECT qty_ordered, qty_received FROM inv_order_lines WHERE order_id = :o', [':o' => (int)$r['order_id']])->fetchAll();
            db_query('UPDATE inv_orders SET status = :s WHERE id = :id', [':s' => inv_order_status($fresh), ':id' => (int)$r['order_id']]);
        }
    });
}

/**
 * Undo one receipt from the order page. When its stock movement still exists the movement is
 * undone with inv_undo_move() (which forgets the receipt in the same transaction, and refuses
 * — e.g. the stock has since moved on and would go below zero — with a message for the page).
 * When the movement is already gone (undone on the item page before receipts were linked) only
 * the receipt is forgotten. A serial receipt (units, no single movement) is refused. Does NO
 * scoping — the page checks the receipt belongs to a line the account may see.
 */
function inv_order_undo_receipt(int $receiptId, ?int $userId): void {
    if (!inv_orders_supported()) throw new InvRefusal('Orders are not set up yet — run the add_inventory_orders.sql migration.');
    inv_tx(function () use ($receiptId, $userId): void {
        $r = db_query('SELECT r.*, l.order_id, i.tracking FROM inv_order_receipts r
                         JOIN inv_order_lines l ON l.id = r.line_id JOIN inv_items i ON i.id = l.item_id
                        WHERE r.id = :id', [':id' => $receiptId])->fetch();
        if (!$r) throw new InvRefusal('That receipt no longer exists.');
        $move = inv_order_find_move_for_receipt($r);
        if ($move) {
            // Tie the receipt to the move first, so inv_undo_move() forgets THIS receipt (and not an
            // identical one) even for a receipt written before the link existed.
            if (inv_order_receipt_moves_supported() && empty($r['move_id'])) {
                db_query('UPDATE inv_order_receipts SET move_id = :m WHERE id = :id', [':m' => (int)$move['id'], ':id' => $receiptId]);
            }
            inv_undo_move((int)$move['id'], $userId);   // forgets the receipt (inv_undo_move → inv_order_forget_receipt)
            return;
        }
        if ($r['tracking'] === 'serial') throw new InvRefusal('Serial units: remove them on the item page.');
        inv_order_forget_receipt($receiptId);
    });
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
 * planned place label, still_to_come, the receipts so far, and the packing-list
 * comparison (packed_total, pack_diff — see inv_order_pack_diff()). Scoped like the list.
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
    foreach (db_query("SELECT r.id, r.line_id, r.qty, r.created_at, loc.name AS location_name, a.name AS user_name
                         FROM inv_order_receipts r
                         JOIN inv_order_lines l ON l.id = r.line_id
                         JOIN inv_locations loc ON loc.id = r.location_id
                         LEFT JOIN admin_users a ON a.id = r.admin_user_id
                        WHERE l.order_id = :o ORDER BY r.created_at, r.id", [':o' => $orderId])->fetchAll() as $r) {
        $byLine[(int)$r['line_id']][] = ['id' => (int)$r['id'], 'qty' => (int)$r['qty'], 'location_name' => (string)$r['location_name'],
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
    $hasContainers = (bool)$conts;   // any packing data on this order at all
    foreach ($rows as &$r) {
        $r['containers']    = $conts[(int)$r['id']] ?? [];
        $r['packed_total']  = array_sum($r['containers']);
        $r['pack_diff']     = inv_order_pack_diff((int)$r['qty_ordered'], (int)$r['packed_total'], $hasContainers);
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

/**
 * Every stored packing-list row of ONE container, in sheet order. Each row carries
 * the matched order line's item (item_id, item_name; null = not on the master list) —
 * only for lines this account may see ($venueIds); a row matched to a line it may not
 * see comes back with hidden_line = true and no item. Empty before the migration.
 */
function inv_order_packing_rows(int $orderId, string $container, ?array $venueIds = null): array {
    if (!inv_order_packing_supported() || $orderId <= 0 || $container === '') return [];
    $visible = [];
    foreach (inv_order_lines($orderId, $venueIds) as $l) $visible[(int)$l['id']] = $l;
    $rows = db_query('SELECT p.* FROM inv_order_packing p WHERE p.order_id = :o AND p.container = :c ORDER BY p.seq, p.id', [':o' => $orderId, ':c' => $container])->fetchAll();
    foreach ($rows as &$r) {
        $lid = $r['line_id'] !== null ? (int)$r['line_id'] : 0;
        $l = $visible[$lid] ?? null;
        $r['item_id']     = $l ? (int)$l['item_id'] : null;
        $r['item_name']   = $l ? (string)$l['item_name'] : null;
        $r['hidden_line'] = $lid > 0 && !$l;
    }
    unset($r);
    return $rows;
}

/**
 * Each container's packing-list totals, in packing-list order: [['container','rows',
 * 'boxes','weight','cubes','sheet_boxes','sheet_weight','sheet_cubes','unmatched']].
 * boxes / weight / cubes add up the rows (footer 'total' rows excluded); sheet_* are the
 * spreadsheet's own footer figures (null when it has none). Empty before the migration.
 */
function inv_order_packing_summary(int $orderId): array {
    if (!inv_order_packing_supported() || $orderId <= 0) return [];
    $out = [];
    foreach (db_query("SELECT container, MIN(id) AS first_id,
                              COUNT(*) FILTER (WHERE kind <> 'total') AS n,
                              COALESCE(SUM(boxes)     FILTER (WHERE kind <> 'total'), 0) AS boxes,
                              COALESCE(SUM(weight_kg) FILTER (WHERE kind <> 'total'), 0) AS weight,
                              COALESCE(SUM(cubes_m3)  FILTER (WHERE kind <> 'total'), 0) AS cubes,
                              SUM(boxes)     FILTER (WHERE kind = 'total') AS sheet_boxes,
                              SUM(weight_kg) FILTER (WHERE kind = 'total') AS sheet_weight,
                              SUM(cubes_m3)  FILTER (WHERE kind = 'total') AS sheet_cubes,
                              COUNT(*) FILTER (WHERE kind = 'row' AND line_id IS NULL) AS unmatched
                         FROM inv_order_packing WHERE order_id = :o GROUP BY container ORDER BY MIN(id)", [':o' => $orderId])->fetchAll() as $r) {
        $out[] = ['container' => (string)$r['container'], 'rows' => (int)$r['n'], 'boxes' => (int)$r['boxes'],
                  'weight' => (float)$r['weight'], 'cubes' => (float)$r['cubes'],
                  'sheet_boxes' => $r['sheet_boxes'] !== null ? (int)$r['sheet_boxes'] : null,
                  'sheet_weight' => $r['sheet_weight'] !== null ? (float)$r['sheet_weight'] : null,
                  'sheet_cubes' => $r['sheet_cubes'] !== null ? (float)$r['sheet_cubes'] : null,
                  'unmatched' => (int)$r['unmatched']];
    }
    return $out;
}
