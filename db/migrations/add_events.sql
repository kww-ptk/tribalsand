-- Tribal Sand: bookable events on the guest board ("What's on"). Idempotent.
ALTER TABLE guest_board_posts DROP CONSTRAINT IF EXISTS guest_board_posts_category_check;
ALTER TABLE guest_board_posts ADD CONSTRAINT guest_board_posts_category_check
    CHECK (category IN ('update','excursion','promotion','event'));
ALTER TABLE guest_board_posts
    ADD COLUMN IF NOT EXISTS event_date   TIMESTAMP,
    ADD COLUMN IF NOT EXISTS price_amount NUMERIC(10,2);

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other','housekeeping','amenities','maintenance','restaurant','laundry','event'));
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS board_post_id INT REFERENCES guest_board_posts(id) ON DELETE SET NULL;

-- At most one ACTIVE join per event per booking (DB-level backstop for the app dedup).
-- NULL board_post_id (all non-event addons) is exempt; declined/cancelled joins don't count.
CREATE UNIQUE INDEX IF NOT EXISTS uniq_addon_active_event
    ON booking_addons (hold_id, board_post_id)
    WHERE board_post_id IS NOT NULL AND status NOT IN ('declined','cancelled');
