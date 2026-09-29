-- Inventory orders — link each receipt to the stock movement it created, so undoing that
-- movement (item page, owner) or the receipt (order page) keeps the two in step.
-- Run AFTER add_inventory_orders.sql. Idempotent.
--
--   • move_id is the 'receive' inv_moves row inv_order_receive() wrote for a counted item.
--     Serial receipts create one move per unit (each with an asset_id), so their receipt
--     keeps move_id NULL. Receipts written before this column also read NULL; the app
--     matches those by item + place + qty + the "Order #<id> " note prefix
--     (inv_order_find_receipt_for_move()).
--   • ON DELETE SET NULL: the owner undo deletes the move row; the app forgets the receipt in
--     the same transaction, and the SET NULL only guards any other deletion path.

ALTER TABLE inv_order_receipts ADD COLUMN IF NOT EXISTS move_id INT REFERENCES inv_moves(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_inv_order_receipts_move ON inv_order_receipts (move_id);
