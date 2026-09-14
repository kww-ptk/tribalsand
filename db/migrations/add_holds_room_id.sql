-- Record WHICH PRODUCT a hold is for, directly on the hold.
--
-- Until Maya Ilai, a hold's product was always derivable from its unit: every
-- room owned its own units, so holds -> units -> rooms answered "what did the
-- guest book?". Maya Ilai's composite inventory breaks that. Eight products are
-- sold out of one pool of eight villas; six of them own NO units of their own
-- and allocate components against the VILLA's units. For those six the
-- unit -> room join reports "Three-Bedroom Villa" no matter what was sold —
-- including to bookings_sync_hold(), which took the villa's nightly rate and
-- wrote a $150 Private Bunk Room into the revenue ledger at $1,170 a night.
--
-- holds.room_id records the product the guest actually booked. NULL means
-- "fall back to the unit's room" (see hold_room_id_sql() in includes/db.php),
-- which is exactly right for every hold created before this column existed.
--
-- Idempotent — safe to run more than once.

ALTER TABLE holds ADD COLUMN IF NOT EXISTS room_id INTEGER NULL
    REFERENCES rooms(id) ON DELETE SET NULL;

COMMENT ON COLUMN holds.room_id IS
    'The product (room) this hold is for. Not derivable from unit_id any more: '
    'Maya Ilai composite products own no units and allocate against the villa units. '
    'NULL = fall back to the unit''s room (correct for pre-composite holds).';

CREATE INDEX IF NOT EXISTS idx_holds_room_id ON holds (room_id);

-- Backfill. Correct for every pre-existing hold: before composite inventory the
-- unit's room WAS the product, which is precisely what every consumer's
-- holds -> units -> rooms join already returned for these rows.
UPDATE holds
   SET room_id = u.room_id
  FROM units u
 WHERE u.id = holds.unit_id
   AND holds.room_id IS NULL;
