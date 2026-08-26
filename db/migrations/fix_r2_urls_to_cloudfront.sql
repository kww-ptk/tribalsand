-- Repoint dead Cloudflare R2 image URLs to the CloudFront (S3) origin.
--
-- Post-AWS-migration the old R2 domain pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev
-- no longer resolves, so every admin-uploaded image stored as a full r2.dev URL
-- rendered blank on the live site. The R2 objects were copied to S3 under the SAME
-- keys, so this is a pure host swap (object keys unchanged) and is fully reversible
-- by swapping the two hosts back.
--
-- The app's storage_url() already rewrites these at render time (includes/db.php), so
-- the site renders correctly without this migration; this cleans the stored values so
-- they match the canonical format new S3 uploads write (<S3_PUBLIC_URL>/<key>).
--
-- Affected columns (verified 2026-08-26): room_images.filename (97),
-- venue_images.filename (30), tour_images.filename (1), guest_board_posts.image_filename (1).

BEGIN;

UPDATE room_images
   SET filename = replace(filename,
        'https://pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev',
        'https://d38di21ab22p6u.cloudfront.net')
 WHERE filename LIKE '%pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev%';

UPDATE venue_images
   SET filename = replace(filename,
        'https://pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev',
        'https://d38di21ab22p6u.cloudfront.net')
 WHERE filename LIKE '%pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev%';

UPDATE tour_images
   SET filename = replace(filename,
        'https://pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev',
        'https://d38di21ab22p6u.cloudfront.net')
 WHERE filename LIKE '%pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev%';

UPDATE guest_board_posts
   SET image_filename = replace(image_filename,
        'https://pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev',
        'https://d38di21ab22p6u.cloudfront.net')
 WHERE image_filename LIKE '%pub-24df819d594a4828ae6b7b0c7ed68952.r2.dev%';

-- Sanity check — should return 0 rows across all four columns:
--   SELECT 'room_images' t, count(*) FROM room_images WHERE filename LIKE '%r2.dev%'
--   UNION ALL SELECT 'venue_images', count(*) FROM venue_images WHERE filename LIKE '%r2.dev%'
--   UNION ALL SELECT 'tour_images', count(*) FROM tour_images WHERE filename LIKE '%r2.dev%'
--   UNION ALL SELECT 'guest_board_posts', count(*) FROM guest_board_posts WHERE image_filename LIKE '%r2.dev%';

COMMIT;
