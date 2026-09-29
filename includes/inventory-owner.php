<?php
declare(strict_types=1);
/**
 * Inventory — Owner-only corrections: undo a move, clear an item at a place to
 * 0, delete an item, delete an area/extra store, and reset everything. Spec:
 * docs/superpowers/specs/2026-09-27-inventory-assets-design.md.
 * Test: php tests/inventory_owner_logic.php
 *
 * These are the OWNER'S deliberate exceptions to "inv_move() is the only
 * writer of inv_moves / inv_balances" (see includes/inventory.php) — used to
 * clean up test data and to correct a mistaken entry, never as a normal stock
 * action. Pages that call these MUST check is_owner() themselves — these
 * functions do not (they take $userId only for parity with the rest of the
 * core; the caller is responsible for the audit trail via audit_log()).
 */

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/inventory-orders.php';   // inv_order_find_receipt_for_move() / inv_order_forget_receipt()

// ── 1. Undo a movement ───────────────────────────────────────────────────────

/**
 * Why a move cannot be undone, or null — PURE. $move carries the raw
 * inv_moves columns (pos_sale_id, count_line_id, asset_id, qty,
 * to_location_id) plus, only for the message, item_name / to_name. $toQty is
 * the CURRENT balance at the move's "to" location (already locked by the
 * caller), or null when there is nothing to check yet (the page's render
 * check passes null and relies on the server to re-check under lock).
 * $allowCountResolution lifts the count-review refusal ONLY — used by
 * inv_delete_location() for a move tied to a count of the place being
 * deleted (that count is being deleted anyway); every other caller (the
 * item page's Undo button) leaves it false.
 */
function inv_undo_refusal(array $move, ?int $toQty, bool $allowCountResolution = false): ?string {
    if (!empty($move['pos_sale_id'])) return 'POS sales and voids are corrected with a void on the till.';
    if (!$allowCountResolution && !empty($move['count_line_id'])) return "This came from a count review — it can't be undone here.";
    if (!empty($move['asset_id']))    return "A serial unit's history can't be undone — delete the item instead, or move the unit.";
    $to = $move['to_location_id'] ?? $move['to'] ?? null;
    if ($to !== null && $toQty !== null && $toQty < (int)($move['qty'] ?? 0)) {
        $item  = (string)($move['item_name'] ?? 'It');
        $place = (string)($move['to_name']   ?? 'that location');
        return "Undoing it would leave {$item} below zero at {$place}.";
    }
    return null;
}

/**
 * Delete one inv_moves row and reverse its effect on inv_balances, as if it
 * never happened. Refused for a POS sale/void, a count-resolution move
 * (unless $allowCountResolution — see inv_undo_refusal()), a serial-unit
 * move, or when the "to" end would go below zero. $userId is accepted for
 * parity but not written anywhere — the row is deleted, not attributed.
 *
 * Race-safe against two concurrent undos of the SAME move: inv_moves rows
 * are immutable (never updated, only deleted), so a first, unlocked read is
 * safe to use ONLY to learn which balance rows to lock. The actual delete is
 * `DELETE … RETURNING`, which returns a row for exactly ONE of two racing
 * callers — the other's DELETE affects 0 rows (its balance locks were
 * acquired after the first committed) and is refused as "no longer exists".
 * Every refusal (including the below-zero check, run on the returned row)
 * is thrown AFTER the DELETE, so the savepoint inv_tx() opened rolls the
 * DELETE back right along with it.
 */
function inv_undo_move(int $moveId, ?int $userId, bool $allowCountResolution = false): void {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    inv_tx(function () use ($moveId, $allowCountResolution): void {
        $shape = db_query('SELECT id, item_id, qty, from_location_id, to_location_id, reason, note, created_at FROM inv_moves WHERE id = :id', [':id' => $moveId])->fetch();
        if (!$shape) throw new InvRefusal('That movement no longer exists.');
        $lockItem = (int)$shape['item_id'];
        $lockFrom = $shape['from_location_id'] !== null ? (int)$shape['from_location_id'] : null;
        $lockTo   = $shape['to_location_id']   !== null ? (int)$shape['to_location_id']   : null;

        // A 'receive' move written by inv_order_receive() belongs to an order receipt: undoing the
        // move must take the receipt back off the order too, or the order keeps saying "received".
        // The receipt is found BEFORE the delete (move_id is ON DELETE SET NULL, so it would be
        // unfindable afterwards) and the order + line are locked BEFORE the balances: that is the
        // order inv_order_receive() takes (order → lines → balances), so an undo racing a receive
        // cannot deadlock against it. The lookup is unlocked and only decides what to lock;
        // inv_order_forget_receipt() re-reads the receipt under the lock.
        $receipt = inv_orders_supported() ? inv_order_find_receipt_for_move($shape) : null;
        if ($receipt) {
            $ol = db_query('SELECT l.order_id FROM inv_order_lines l WHERE l.id = :l', [':l' => (int)$receipt['line_id']])->fetch();
            if ($ol) {
                db_query('SELECT id FROM inv_orders WHERE id = :id FOR UPDATE', [':id' => (int)$ol['order_id']])->fetch();
                db_query('SELECT id FROM inv_order_lines WHERE id = :id FOR UPDATE', [':id' => (int)$receipt['line_id']])->fetch();
            }
        }

        $pairs = [];
        if ($lockFrom !== null) $pairs[] = [$lockItem, $lockFrom];
        if ($lockTo   !== null) $pairs[] = [$lockItem, $lockTo];
        inv_lock_balances($pairs);

        $m = db_query(
            'DELETE FROM inv_moves WHERE id = :id
             RETURNING item_id, qty, from_location_id, to_location_id, asset_id, pos_sale_id, count_line_id, reason, note',
            [':id' => $moveId]
        )->fetch();
        if (!$m) throw new InvRefusal('That movement no longer exists.');   // a racing undo won it first

        $itemId = (int)$m['item_id'];
        $from   = $m['from_location_id'] !== null ? (int)$m['from_location_id'] : null;
        $to     = $m['to_location_id']   !== null ? (int)$m['to_location_id']   : null;
        $qty    = (int)$m['qty'];
        $toQty  = $to !== null ? inv_balance($itemId, $to) : null;

        $names = db_query('SELECT i.name AS item_name, lt.name AS to_name FROM inv_items i
                             LEFT JOIN inv_locations lt ON lt.id = :to WHERE i.id = :item',
            [':to' => $to, ':item' => $itemId])->fetch() ?: [];

        $err = inv_undo_refusal($m + $names, $toQty, $allowCountResolution);
        if ($err !== null) throw new InvRefusal($err);   // rolls back the DELETE too (same savepoint)

        if ($from !== null) db_query('UPDATE inv_balances SET qty = qty + :q WHERE item_id = :i AND location_id = :l', [':q' => $qty, ':i' => $itemId, ':l' => $from]);
        if ($to   !== null) db_query('UPDATE inv_balances SET qty = qty - :q WHERE item_id = :i AND location_id = :l', [':q' => $qty, ':i' => $itemId, ':l' => $to]);

        // Stock is back; now the order agrees (any refusal above rolled the DELETE back, so this never runs half-way).
        if ($receipt) inv_order_forget_receipt((int)$receipt['id']);
    });
}

// ── 2. Clear to zero ─────────────────────────────────────────────────────────

/**
 * Set an item's stock at a place to 0 with ONE inv_move(): a positive balance
 * is written off, a negative one is found — both at zero value, noted
 * "Cleared by owner". Refused for a serial-tracked item (its units are
 * removed one by one). Returns the quantity moved (0 when already at 0).
 */
function inv_clear_to_zero(int $itemId, int $locationId, ?int $userId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $item = inv_fetch_item($itemId);
    if (!$item) throw new InvRefusal('That item no longer exists.');
    if ($item['tracking'] === 'serial') throw new InvRefusal('Serial units are removed one by one.');
    return inv_tx(function () use ($itemId, $locationId, $userId): int {
        $qty = inv_balance_lock($itemId, $locationId);
        if ($qty === 0) return 0;
        if ($qty > 0) {
            inv_move(['item_id' => $itemId, 'qty' => $qty, 'from' => $locationId, 'reason' => 'written_off',
                      'user_id' => $userId, 'unit_value' => 0, 'note' => 'Cleared by owner']);
        } else {
            inv_move(['item_id' => $itemId, 'qty' => -$qty, 'to' => $locationId, 'reason' => 'found',
                      'user_id' => $userId, 'unit_value' => 0, 'note' => 'Cleared by owner']);
        }
        return abs($qty);
    });
}

// ── 3. Delete an item ────────────────────────────────────────────────────────

/**
 * Hard-delete an item and everything about it: its moves, count lines,
 * balances and serial units, then the item row itself. Counts left with zero
 * lines because of this delete are removed too; a 'submitted' count left
 * with lines that are now ALL resolved becomes 'resolved' (its gap was this
 * item's — nothing is left to review). Refused when it has a POS sale in its
 * history, or a POS listing still links to it.
 *
 * Both refusal checks and the item's existence are re-verified INSIDE the
 * transaction, after `SELECT … FOR UPDATE` locks the item row — the item
 * page already checked once before calling this, but only the locked
 * re-check closes the window for a POS sale (or a new POS listing) landing
 * between that check and this delete. Every one of the item's balance rows
 * is locked (inv_lock_balances()) before anything is deleted.
 */
function inv_delete_item(int $itemId, ?int $userId): void {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $item = inv_fetch_item($itemId);
    if (!$item) throw new InvRefusal('That item no longer exists.');
    $posSaleRefusal    = 'It has POS sales — switch it off instead.';
    $posListingRefusal = 'It is linked to a POS listing — delete that listing first (count it to 0 on the POS Stock page).';
    if ((bool) db_query('SELECT 1 FROM inv_moves WHERE item_id = :i AND pos_sale_id IS NOT NULL LIMIT 1', [':i' => $itemId])->fetchColumn()) {
        throw new InvRefusal($posSaleRefusal);
    }
    if ((bool) db_query('SELECT 1 FROM pos_items WHERE inv_item_id = :i LIMIT 1', [':i' => $itemId])->fetchColumn()) {
        throw new InvRefusal($posListingRefusal);
    }
    inv_tx(function () use ($itemId, $posSaleRefusal, $posListingRefusal): void {
        if (!db_query('SELECT 1 FROM inv_items WHERE id = :i FOR UPDATE', [':i' => $itemId])->fetchColumn()) {
            throw new InvRefusal('That item no longer exists.');
        }
        if ((bool) db_query('SELECT 1 FROM inv_moves WHERE item_id = :i AND pos_sale_id IS NOT NULL LIMIT 1', [':i' => $itemId])->fetchColumn()) {
            throw new InvRefusal($posSaleRefusal);
        }
        if ((bool) db_query('SELECT 1 FROM pos_items WHERE inv_item_id = :i LIMIT 1', [':i' => $itemId])->fetchColumn()) {
            throw new InvRefusal($posListingRefusal);
        }

        $locIds = array_map('intval', db_query('SELECT location_id FROM inv_balances WHERE item_id = :i', [':i' => $itemId])->fetchAll(PDO::FETCH_COLUMN));
        inv_lock_balances(array_map(fn(int $l): array => [$itemId, $l], $locIds));

        $countIds = db_query('SELECT DISTINCT count_id FROM inv_count_lines WHERE item_id = :i', [':i' => $itemId])->fetchAll(PDO::FETCH_COLUMN);
        db_query('DELETE FROM inv_moves WHERE item_id = :i', [':i' => $itemId]);
        db_query('DELETE FROM inv_count_lines WHERE item_id = :i', [':i' => $itemId]);
        db_query('DELETE FROM inv_balances WHERE item_id = :i', [':i' => $itemId]);
        db_query('DELETE FROM inv_assets WHERE item_id = :i', [':i' => $itemId]);
        db_query('DELETE FROM inv_items WHERE id = :i', [':i' => $itemId]);
        if ($countIds) {
            $ids = array_map('intval', $countIds);
            db_query(
                'DELETE FROM inv_counts WHERE id = ANY(CAST(:ids AS int[]))
                   AND NOT EXISTS (SELECT 1 FROM inv_count_lines WHERE count_id = inv_counts.id)',
                [':ids' => inv_pg_int_array_literal($ids)]
            );
            db_query(
                "UPDATE inv_counts SET status = 'resolved' WHERE id = ANY(CAST(:ids AS int[])) AND status = 'submitted'
                   AND NOT EXISTS (SELECT 1 FROM inv_count_lines WHERE count_id = inv_counts.id AND resolution IS NULL)",
                [':ids' => inv_pg_int_array_literal($ids)]
            );
        }
    });
}

// ── 4. Delete a location ─────────────────────────────────────────────────────

/**
 * Delete an area, or an extra store (never Main stock — even before
 * add_inventory_stores.sql, when the single 'store' row has no is_main
 * column to check — never a property / outlet / person, which follow their
 * own record). Refused when it has child locations. Every move touching it
 * is undone first (newest first, same rules as inv_undo_move() — a POS
 * sale, a count review tied to ANOTHER place, or a resulting negative
 * balance aborts the WHOLE delete, its message prefixed with the item's
 * name), then its counts and balance rows are removed, then the location
 * itself.
 *
 * A count-resolution move IS undone (not refused) when the count line it
 * came from belongs to a count of THIS location — that count is deleted a
 * few lines down regardless, so refusing the undo would only block the
 * delete over history that is about to disappear anyway. The move is
 * deleted (inv_undo_move()) before the count lines are, so the FK
 * (inv_moves.count_line_id → inv_count_lines, ON DELETE SET NULL) is never
 * in play either way.
 *
 * A SERIAL-unit move is never liftable this way (a unit's whole history has
 * to move or disappear together with the unit's item) — it always aborts
 * the delete, naming the item: that place can only be deleted once the item
 * itself is deleted (inv_delete_item()), which takes its moves with it.
 */
function inv_delete_location(int $locationId, ?int $userId): void {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $loc = inv_fetch_location($locationId);
    if (!$loc) throw new InvRefusal('That location no longer exists.');
    if (!in_array($loc['kind'], ['area', 'store'], true)) {
        throw new InvRefusal('Properties, shops and team members follow their property / outlet / staff record.');
    }
    // Before add_inventory_stores.sql there is no is_main column at all — the single
    // 'store' row IS Main stock, so it must be caught the same way either side of
    // that migration. inv_store_location_id() works pre- and post-migration.
    if ($loc['kind'] === 'store' && (inv_bool($loc['is_main'] ?? false) || $locationId === inv_store_location_id())) {
        throw new InvRefusal("Main stock can't be deleted.");
    }
    if ((int) db_query('SELECT COUNT(*) FROM inv_locations WHERE parent_id = :p', [':p' => $locationId])->fetchColumn() > 0) {
        throw new InvRefusal('Move or delete its areas first.');
    }
    inv_tx(function () use ($locationId, $userId): void {
        $ownCountLineIds = array_map('intval', db_query(
            'SELECT cl.id FROM inv_count_lines cl JOIN inv_counts c ON c.id = cl.count_id WHERE c.location_id = :l',
            [':l' => $locationId]
        )->fetchAll(PDO::FETCH_COLUMN));

        $moves = db_query(
            'SELECT m.id, m.item_id, m.from_location_id, m.to_location_id, m.count_line_id, m.asset_id, i.name AS item_name
               FROM inv_moves m JOIN inv_items i ON i.id = m.item_id
              WHERE m.from_location_id = :l1 OR m.to_location_id = :l2 ORDER BY m.id DESC',
            [':l1' => $locationId, ':l2' => $locationId]
        )->fetchAll();
        $pairs = [];
        foreach ($moves as $mv) {
            if ($mv['from_location_id'] !== null) $pairs[] = [(int)$mv['item_id'], (int)$mv['from_location_id']];
            if ($mv['to_location_id']   !== null) $pairs[] = [(int)$mv['item_id'], (int)$mv['to_location_id']];
        }
        inv_lock_balances($pairs);   // the whole set, global order, before undoing any of them
        foreach ($moves as $mv) {
            // A serial unit's history can never be undone (inv_undo_refusal()) — say so in
            // terms of what actually clears it (delete the item), not the generic item-page
            // wording, which talks about moving the unit instead (not offered here).
            if ($mv['asset_id'] !== null) {
                throw new InvRefusal("{$mv['item_name']}: it has serial-unit history here — delete {$mv['item_name']} first.");
            }
            $allow = $mv['count_line_id'] !== null && in_array((int)$mv['count_line_id'], $ownCountLineIds, true);
            try {
                inv_undo_move((int)$mv['id'], $userId, $allow);
            } catch (InvRefusal $e) {
                throw new InvRefusal("{$mv['item_name']}: " . $e->getMessage());
            }
        }

        $countIds = db_query('SELECT id FROM inv_counts WHERE location_id = :l', [':l' => $locationId])->fetchAll(PDO::FETCH_COLUMN);
        if ($countIds) {
            $ids = array_map('intval', $countIds);
            db_query('DELETE FROM inv_count_lines WHERE count_id = ANY(CAST(:ids AS int[]))', [':ids' => inv_pg_int_array_literal($ids)]);
            db_query('DELETE FROM inv_counts WHERE id = ANY(CAST(:ids AS int[]))', [':ids' => inv_pg_int_array_literal($ids)]);
        }
        db_query('DELETE FROM inv_balances WHERE location_id = :l', [':l' => $locationId]);
        db_query('DELETE FROM inv_locations WHERE id = :id', [':id' => $locationId]);
    });
}

// ── 5. Reset all inventory ───────────────────────────────────────────────────

/**
 * Wipe ALL inventory data — items, moves, counts, units, balances — but keep
 * locations (stores, properties, areas, outlets, people): the owner uses this
 * to clear test data before going live. A tracked POS listing keeps its row
 * but loses its inv_item_id link (its stock starts again from 0 once a new
 * item is linked). Never touches pos_sales, locations, stores, venues or
 * settings. Returns ['items' => n, 'moves' => n] removed.
 */
function inv_reset_all(?int $userId): array {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    return inv_tx(function (): array {
        $items = (int) db_query('SELECT COUNT(*) FROM inv_items')->fetchColumn();
        $moves = (int) db_query('SELECT COUNT(*) FROM inv_moves')->fetchColumn();
        db_query('DELETE FROM inv_moves');
        db_query('DELETE FROM inv_count_lines');
        db_query('DELETE FROM inv_counts');
        db_query('DELETE FROM inv_assets');
        db_query('DELETE FROM inv_balances');
        db_query('UPDATE pos_items SET inv_item_id = NULL WHERE inv_item_id IS NOT NULL');
        db_query('DELETE FROM inv_items');
        db_query('UPDATE inv_locations SET last_counted_at = NULL');
        return ['items' => $items, 'moves' => $moves];
    });
}
