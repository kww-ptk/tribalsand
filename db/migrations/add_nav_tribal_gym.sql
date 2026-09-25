-- Tribal Sand: add "Tribal Gym · Coming Soon" to the Tribal Dunes mega-menu.
--
-- Additive and idempotent — unlike db/seeds/seed_nav_menu.php it never wipes the
-- menu, so edits the owner made in Admin → Site Menu survive. It slots the link
-- into Tribal Dunes → "Dining & Lifestyle" right after Somewhere Café (or at the
-- end of that column when Somewhere Café is gone). Does nothing when:
--   * the nav tables don't exist yet (header.php renders its hardcoded fallback,
--     which already carries the link), or
--   * a link to tribal-gym.php already exists anywhere in the menu, or
--   * the owner renamed/removed that column (add it by hand in Site Menu then).

DO $$
DECLARE
    g_id  INT;
    after INT;
BEGIN
    IF to_regclass('public.nav_links') IS NULL THEN
        RETURN;
    END IF;
    IF EXISTS (SELECT 1 FROM nav_links WHERE href = 'tribal-gym.php') THEN
        RETURN;
    END IF;

    SELECT g.id INTO g_id
      FROM nav_groups g
      JOIN nav_items i ON i.id = g.nav_item_id
     WHERE i.label = 'Tribal Dunes' AND g.label = 'Dining & Lifestyle'
     ORDER BY g.id
     LIMIT 1;
    IF g_id IS NULL THEN
        RETURN;
    END IF;

    SELECT sort_order INTO after
      FROM nav_links
     WHERE nav_group_id = g_id AND href = 'somewhere-cafe.php'
     LIMIT 1;
    IF after IS NULL THEN
        SELECT COALESCE(MAX(sort_order), 0) INTO after
          FROM nav_links WHERE nav_group_id = g_id AND role = 'row';
    END IF;

    -- Make room directly after the anchor row.
    UPDATE nav_links SET sort_order = sort_order + 1
     WHERE nav_group_id = g_id AND sort_order > after;

    INSERT INTO nav_links (nav_group_id, label, href, sublabel, image_key, tag, role, sort_order)
    VALUES (g_id, 'Tribal Gym', 'tribal-gym.php', 'Coming Soon · Kilifi',
            'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg',
            '', 'row', after + 1);
END $$;
