-- Claris African Experience — add per-property FAQ storage
-- Run: psql $DATABASE_URL -f db/migrations/add_faqs.sql
-- Adds a JSONB column holding an array of {q, a} objects per room.

ALTER TABLE rooms ADD COLUMN IF NOT EXISTS faqs_json JSONB NOT NULL DEFAULT '[]'::jsonb;
