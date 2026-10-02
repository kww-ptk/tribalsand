-- Migration: booking-time extras — wellness treatments + airport transfers. Run via
-- /admin/migrate.php, after add_upsells.sql and add_service_options.sql. Idempotent.
--
-- 1. service_options.offer_at_booking — a transfer option ticked here is offered as
--    an add-on while booking (booking pop-up / widget) and in the booking-confirmed
--    email, next to the activities placed on the booking surfaces. Seeded ON for the
--    airport transfers; the owner switches any option on or off in Admin → Service pricing.
-- 2. Three in-house wellness treatments as their own activities (they were only
--    mentioned inside "In-House Wellness Treatments"). Unpriced ("On Request") until
--    the owner sets a price in Admin → Tours. Offered on both booking surfaces.
-- 3. Booking add-ons switched on for every published property (venues.upsell_enabled,
--    per-property in Admin → Properties → Details).

ALTER TABLE service_options ADD COLUMN IF NOT EXISTS offer_at_booking BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE service_options SET offer_at_booking = TRUE
 WHERE service = 'transfer' AND label ILIKE '%airport%' AND is_active = TRUE;

INSERT INTO tours (slug, name, category, tag_label, duration, price, short_desc, sort_order, is_published, location)
VALUES
 ('in-house-wellness-deep-tissue', 'In-House Wellness — Deep Tissue Massage', 'wellness', 'Wellness', '60–90 minutes', 'On Request',
  'A firm, slow-pressure massage that eases deep muscle tension — given by a skilled therapist in the comfort of your room or villa.', 404, TRUE, 'all'),
 ('in-house-wellness-manicure', 'In-House Wellness — Manicure', 'wellness', 'Wellness', 'About 45 minutes', 'On Request',
  'Shaping, cuticle care, a hand massage and polish — a relaxed manicure brought to you at your accommodation.', 405, TRUE, 'all'),
 ('in-house-wellness-pedicure', 'In-House Wellness — Pedicure', 'wellness', 'Wellness', 'About 60 minutes', 'On Request',
  'A warm foot soak, exfoliation, nail care, a foot massage and polish — sand-weary feet sorted without leaving your stay.', 406, TRUE, 'all')
ON CONFLICT (slug) DO NOTHING;

-- The upsell columns come from add_upsells.sql; only touch them when it has run.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'tours' AND column_name = 'upsell_placement') THEN
    UPDATE tours SET upsell_placement = 'both'
     WHERE slug IN ('in-house-wellness-deep-tissue', 'in-house-wellness-manicure', 'in-house-wellness-pedicure')
       AND upsell_placement = 'none';
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'venues' AND column_name = 'upsell_enabled') THEN
    UPDATE venues SET upsell_enabled = TRUE WHERE is_published = TRUE;
  END IF;
END $$;
