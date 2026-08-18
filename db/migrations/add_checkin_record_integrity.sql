-- Record-integrity hardening for the electronic registration / waiver record.
-- Two independent concerns, shipped together:
--
--  1. IDENTITY SNAPSHOT — the signed consent document (record.php) must be a
--     frozen snapshot of what the guest signed. Previously it rendered the LIVE
--     passport identity columns, so editing a guest's passport number after
--     signing silently mutated the "signed" evidence. These columns capture the
--     identity as it was at the moment of signing; the record renders them and
--     only falls back to the live columns for legacy rows signed before this
--     migration (which are additionally protected by the void-on-edit rule in
--     application code, so their live values cannot change post-signing either).
--
--  2. HASH CHAIN — the append-only signing audit trail (checkin_signing_audit)
--     was tamper-evident only by policy (the app never UPDATEs/DELETEs it). These
--     columns make it tamper-evident by CONSTRUCTION: each new row stores the
--     hash of the previous row plus a hash over its own payload, so any later
--     deletion or edit breaks the chain and is detectable (checkin_audit_verify()).
--     Rows written before this migration have NULL hashes and sit outside the
--     chain — the chain covers everything from here forward.
--
-- Idempotent — safe to run multiple times. Apply AFTER add_checkin_reference.sql.

-- 1. Identity-at-signing snapshot on the guest record.
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_name_snapshot            TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_passport_snapshot        TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_nationality_snapshot     TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_passport_expiry_snapshot TEXT;

-- 2. Tamper-evident hash chain on the audit trail.
ALTER TABLE checkin_signing_audit ADD COLUMN IF NOT EXISTS prev_hash TEXT;   -- row_hash of the previous audit row ('0'*64 genesis)
ALTER TABLE checkin_signing_audit ADD COLUMN IF NOT EXISTS row_hash  TEXT;   -- sha256(prev_hash || payload of this row)

CREATE INDEX IF NOT EXISTS idx_csa_rowhash ON checkin_signing_audit (row_hash);
