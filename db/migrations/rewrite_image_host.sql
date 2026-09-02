-- One-off: rebrand stored image URLs from the default CloudFront domain to the
-- custom image subdomain.
--
-- WHEN TO RUN: only AFTER the subdomain is live and serving (custom domain added
-- to the CloudFront distribution + DNS record resolving) AND the app's
-- S3_PUBLIC_URL env has been switched to the new host. Running it before the
-- subdomain resolves would point existing images at a dead host.
--
-- Safe to re-run: it only rewrites the exact old-host substring, so a second run
-- finds nothing left to change. Skips any table that doesn't exist yet.
--
-- If you chose a different subdomain than images.tribalsand.com, edit new_host
-- below (one place) before running.

DO $$
DECLARE
  old_host text := 'd38di21ab22p6u.cloudfront.net';
  new_host text := 'images.tribalsand.com';
  targets  text[][] := ARRAY[
    ['venue_images',      'filename'],
    ['room_images',       'filename'],
    ['tour_images',       'filename'],
    ['property_images',   'filename'],
    ['nav_links',         'image_key'],
    ['offers',            'image_key'],
    ['media',             'storage_key'],
    ['guest_board_posts', 'image_filename'],
    ['settings',          'setting_value']
  ];
  t text; c text; i int;
BEGIN
  FOR i IN 1 .. array_length(targets, 1) LOOP
    t := targets[i][1];
    c := targets[i][2];
    IF to_regclass('public.' || t) IS NOT NULL THEN
      EXECUTE format(
        'UPDATE %I SET %I = replace(%I, $1, $2) WHERE %I LIKE ''%%'' || $1 || ''%%''',
        t, c, c, c
      ) USING old_host, new_host;
    END IF;
  END LOOP;
END $$;
