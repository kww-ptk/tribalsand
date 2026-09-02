-- One-off: rebrand stored image URLs from the default CloudFront domain to the
-- custom image subdomain, across the WHOLE database.
--
-- WHEN TO RUN: only AFTER the subdomain is live and serving (custom domain on the
-- CloudFront distribution + DNS resolving) AND the app's S3_PUBLIC_URL/ASSET_URL
-- env has been switched to the new host.
--
-- HOW IT WORKS: instead of a hand-listed set of columns (which missed
-- prod-only tables like page_content), this dynamically walks EVERY text /
-- varchar column of every base table in the public schema and rewrites the old
-- host wherever it literally appears. replace() only touches that exact host
-- substring, so it can never corrupt other data. Idempotent — a second run finds
-- nothing left to change.
--
-- If you chose a different subdomain than images.tribalsand.com, edit new_host
-- below (one place) before running.

DO $$
DECLARE
  old_host text := 'd38di21ab22p6u.cloudfront.net';
  new_host text := 'images.tribalsand.com';
  r record;
BEGIN
  FOR r IN
    SELECT c.table_name, c.column_name
    FROM information_schema.columns c
    JOIN information_schema.tables t
      ON t.table_schema = c.table_schema
     AND t.table_name  = c.table_name
    WHERE c.table_schema = 'public'
      AND t.table_type   = 'BASE TABLE'
      AND c.data_type IN ('text', 'character varying')
  LOOP
    EXECUTE format(
      'UPDATE %I SET %I = replace(%I, $1, $2) WHERE %I LIKE ''%%'' || $1 || ''%%''',
      r.table_name, r.column_name, r.column_name, r.column_name
    ) USING old_host, new_host;
  END LOOP;
END $$;
