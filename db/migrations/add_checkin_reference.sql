-- Legal evidence hardening for the electronic registration / waiver record.
-- Adds a unique transaction/reference ID and the personal link issued to each
-- signed guest record, plus a dedicated append-only signing audit trail that
-- captures every material step (reference, version, timestamp, IP, device,
-- method, personal link). Idempotent — safe to run multiple times.
-- Apply AFTER add_checkin_signature.sql.

-- Per-record controlled identifier shown on the guest-facing document, replacing
-- the internal /admin/ route as the record's public reference.
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_reference   TEXT;
-- The personal check-in link the guest used to reach and sign the record.
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_link TEXT;

-- One reference per record. Partial index: NULLs (unsigned rows) are exempt.
CREATE UNIQUE INDEX IF NOT EXISTS idx_checkin_guests_reference
    ON checkin_guests (waiver_reference)
    WHERE waiver_reference IS NOT NULL;

-- Append-only, server-side signing audit trail. Rows are never updated or
-- deleted by the application — each step is a new row. Supports the legal
-- presumption of integrity and any future certification of the record.
CREATE TABLE IF NOT EXISTS checkin_signing_audit (
    id             BIGSERIAL PRIMARY KEY,
    reference      TEXT,                                   -- transaction/reference ID
    hold_id        INTEGER,
    guest_id       INTEGER,
    step           TEXT NOT NULL,                          -- 'signed' | 'record_viewed' | ...
    waiver_version TEXT,                                   -- exact terms version at the event
    personal_link  TEXT,                                   -- the personal link issued/used
    ip             TEXT,
    user_agent     TEXT,
    method         TEXT,                                   -- 'own_device' | 'reception' | 'admin' | 'guest'
    detail         TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_csa_reference ON checkin_signing_audit (reference);
CREATE INDEX IF NOT EXISTS idx_csa_hold      ON checkin_signing_audit (hold_id, guest_id);
CREATE INDEX IF NOT EXISTS idx_csa_created   ON checkin_signing_audit (created_at DESC);
