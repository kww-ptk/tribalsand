-- Seed: Zuri's real floor plan (10 tables, 52 seats), as sent by Zuri on
-- 23 Sep 2026. Replaces the placeholder tables T1–T3. Run via /admin/migrate.php
-- AFTER add_restaurant_sync_models.sql. Idempotent (safe to re-run).
--
-- `label` = Zuri's table NUMBER (a string, "1"–"10"), `name` = what staff call
-- it. capacity is what Zuri's /reserve matches a party against, so these must
-- be exact.
--
-- The rows are inserted directly, so no outbox events are queued for them: the
-- go-live requeue (bin/sync-requeue.php) sends every live table. T1–T3 were
-- never sent to Zuri (sync was off), so their pending create events are
-- dropped and the rows are soft-deleted — nothing about them ever reaches Zuri.

ALTER TABLE restaurant_tables ADD COLUMN IF NOT EXISTS name VARCHAR(80);   -- = add_restaurant_table_name.sql

DELETE FROM sync_outbox
 WHERE status = 'pending' AND entity = 'restaurant_table'
   AND sync_uuid IN (SELECT t.sync_uuid FROM restaurant_tables t JOIN venues v ON v.id = t.venue_id
                      WHERE v.slug = 'zuri' AND t.label IN ('T1', 'T2', 'T3'));

UPDATE restaurant_tables t SET is_deleted = TRUE, updated_at = now()
  FROM venues v
 WHERE v.id = t.venue_id AND v.slug = 'zuri' AND t.label IN ('T1', 'T2', 'T3') AND t.is_deleted = FALSE;

INSERT INTO restaurant_tables (venue_id, label, name, section, seats, sort_order, is_active)
SELECT v.id, x.label, x.name, x.section, x.seats, x.sort_order, TRUE
  FROM venues v
  CROSS JOIN (VALUES
      ('1',  'Pool 1',         'Poolside', 2,  1),
      ('2',  'Pool 2',         'Poolside', 2,  2),
      ('3',  'Pool 3',         'Poolside', 4,  3),
      ('4',  'Pool 4',         'Poolside', 4,  4),
      ('5',  'Terrace 1',      'Terrace',  4,  5),
      ('6',  'Terrace 2',      'Terrace',  6,  6),
      ('7',  'Terrace 3',      'Terrace',  6,  7),
      ('8',  'Lounge',         'Lounge',   8,  8),
      ('9',  'Garden',         'Garden',   4,  9),
      ('10', 'Private Dining', 'Private', 12, 10)
  ) AS x(label, name, section, seats, sort_order)
 WHERE v.slug = 'zuri'
   AND NOT EXISTS (SELECT 1 FROM restaurant_tables t
                    WHERE t.venue_id = v.id AND t.label = x.label AND t.is_deleted = FALSE);
